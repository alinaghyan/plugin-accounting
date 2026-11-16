<?php
/**
 * One-way, auditable stock synchronization from the SQLite stock ledger to WooCommerce.
 *
 * Target WooCommerce stock = SQLite stock − quantities WooCommerce already deducted for orders that are
 * not posted to accounting yet (on-hold / processing orders still in the queue). The change is applied
 * as a relative increase/decrease so an order placed during the sync is never overwritten.
 * Products with an unprocessed accounting event are skipped (not the whole shop) and retried next run.
 * On-demand (supplier-sourced) products are never synchronized: WooCommerce keeps them on backorder.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Stock_Sync_Service {
	private const BATCH_SIZE     = 100;
	private const RESERVE_DAYS   = 60;
	private const RESERVE_STATES = array( 'on-hold', 'processing', 'completed' );

	public static function run_batch( int $warehouse_id, int $limit = 100 ): array {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			throw new RuntimeException( 'WooCommerce برای همگام‌سازی موجودی فعال نیست.' );
		}
		if ( ! MDA_Database::is_available() ) {
			throw new RuntimeException( 'PDO_SQLite برای همگام‌سازی موجودی فعال نیست.' );
		}
		if ( $warehouse_id < 1 ) {
			throw new InvalidArgumentException( 'انبار انتخاب‌شده معتبر نیست.' );
		}
		$limit = max( 1, min( self::BATCH_SIZE, $limit ) );
		$db    = MDA_Database::connection();

		$wh = $db->prepare( 'SELECT warehouse_name FROM mda_warehouses WHERE id = :id AND is_active = 1' );
		$wh->execute( array( ':id' => $warehouse_id ) );
		$warehouse_name = $wh->fetchColumn();
		if ( ! $warehouse_name ) {
			throw new InvalidArgumentException( 'انبار وجود ندارد یا غیرفعال است.' );
		}

		$started    = gmdate( 'Y-m-d H:i:s' );
		$counts     = array( 'scanned' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0 );
		$cursor_key = 'wc_stock_sync_cursor_' . $warehouse_id;
		$cursor     = self::cursor( $db, $cursor_key );
		$pending    = self::pending_products( $db );
		$reserved   = self::unposted_reservations( $db );
		$item_logs  = array();

		$q = $db->prepare(
			'SELECT c.product_id, c.variation_id, c.quantity_scaled, c.quantity_scale, COALESCE(SUM(m.quantity_scaled),0) AS ledger_quantity,
				COUNT(DISTINCT m.quantity_scale) AS scale_count
			FROM mda_product_cost_state c
			LEFT JOIN mda_inventory_movements m ON m.product_id = c.product_id AND m.variation_id = c.variation_id AND m.warehouse_id = c.warehouse_id
			WHERE c.warehouse_id = :warehouse AND (c.product_id > :product OR (c.product_id = :product AND c.variation_id > :variation))
			GROUP BY c.product_id, c.variation_id ORDER BY c.product_id, c.variation_id LIMIT :limit'
		);
		$q->bindValue( ':warehouse', $warehouse_id, PDO::PARAM_INT );
		$q->bindValue( ':product', (int) $cursor['product_id'], PDO::PARAM_INT );
		$q->bindValue( ':variation', (int) $cursor['variation_id'], PDO::PARAM_INT );
		$q->bindValue( ':limit', $limit, PDO::PARAM_INT );
		$q->execute();
		$rows = $q->fetchAll();

		foreach ( $rows as $row ) {
			++$counts['scanned'];
			$log = self::sync_one( $db, $row, $warehouse_id, $pending, $reserved );
			++$counts[ 'synced' === $log['status'] ? 'synced' : ( 'skipped' === $log['status'] ? 'skipped' : 'failed' ) ];
			$item_logs[] = $log;
		}

		$has_more    = count( $rows ) === $limit;
		$last        = end( $rows );
		$next_cursor = $has_more ? array( 'product_id' => (int) $last['product_id'], 'variation_id' => (int) $last['variation_id'] ) : array( 'product_id' => 0, 'variation_id' => -1 );
		$db->prepare( 'INSERT OR REPLACE INTO mda_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :now)' )
			->execute( array( ':key' => $cursor_key, ':value' => wp_json_encode( $next_cursor ), ':now' => gmdate( 'Y-m-d H:i:s' ) ) );

		$status  = ( $counts['failed'] > 0 || $has_more ) ? 'partial' : 'completed';
		$message = $has_more ? 'دسته بعدی برای ادامه همگام‌سازی برنامه‌ریزی می‌شود.' : 'این دسته از انبار انتخاب‌شده همگام شد.';
		if ( $counts['skipped'] > 0 ) {
			$message .= ' ' . $counts['skipped'] . ' کالا به دلیل رویداد حسابداری در صف یا تنظیمات ووکامرس رد شد و در اجرای بعدی بررسی می‌شود.';
		}

		$run_id = self::save_run( $db, $warehouse_id, $started, $counts, count( $pending ), $cursor, $status, $message, $item_logs );
		if ( $has_more && ! wp_next_scheduled( 'mda_woocommerce_stock_sync_continue' ) ) {
			wp_schedule_single_event( time() + 30, 'mda_woocommerce_stock_sync_continue', array( $warehouse_id ) );
		}
		return array_merge(
			$counts,
			array(
				'run_id'         => $run_id,
				'warehouse'      => $warehouse_name,
				'status'         => $status,
				'message'        => $message,
				'pending_events' => count( $pending ),
			)
		);
	}

	private static function sync_one( PDO $db, array $row, int $warehouse_id, array $pending, array $reserved ): array {
		$product_id   = (int) $row['product_id'];
		$variation_id = (int) $row['variation_id'];
		$qty          = (int) $row['quantity_scaled'];
		$scale        = (int) $row['quantity_scale'];
		$log          = array(
			'product_id'          => $product_id,
			'variation_id'        => $variation_id,
			'quantity_scaled'     => $qty,
			'quantity_scale'      => $scale,
			'sqlite_quantity'     => (string) $qty,
			'woo_quantity_before' => null,
			'woo_quantity_after'  => null,
			'status'              => 'failed',
			'message'             => '',
			'created_at'          => gmdate( 'Y-m-d H:i:s' ),
		);
		$key = $product_id . ':' . $variation_id;
		try {
			if ( $scale < 1 || ! self::is_power_of_ten( $scale ) || 1 !== (int) $row['scale_count'] || (int) $row['ledger_quantity'] !== $qty ) {
				throw new RuntimeException( 'کاردکس و وضعیت بهای تمام‌شده با هم سازگار نیستند یا مقیاس مقدار پشتیبانی نمی‌شود.' );
			}
			$log['sqlite_quantity'] = self::format_quantity( $qty, $scale );
			if ( isset( $pending[ $key ] ) || isset( $pending[ $product_id . ':*' ] ) ) {
				$log['status']  = 'skipped';
				$log['message'] = 'برای این کالا رویداد فروش ثبت‌نشده در صف حسابداری وجود دارد؛ پس از پردازش صف همگام می‌شود.';
				return $log;
			}
			if ( MDA_Supply_Service::MODE_ON_DEMAND === MDA_Supply_Service::mode( $db, $product_id, $variation_id ) ) {
				$log['status']  = 'skipped';
				$log['message'] = 'کالای سفارشی از تأمین‌کننده؛ موجودی ووکامرس آن به صورت پیش‌خرید (backorder) مدیریت می‌شود.';
				return $log;
			}
			if ( $qty < 0 ) {
				throw new RuntimeException( 'موجودی SQLite منفی است؛ همگام‌سازی خودکار مجاز نیست.' );
			}
			$product = wc_get_product( $variation_id ?: $product_id );
			if ( ! $product || ! is_object( $product ) ) {
				throw new RuntimeException( 'محصول/تنوع در WooCommerce پیدا نشد.' );
			}
			$manages = method_exists( $product, 'managing_stock' ) ? $product->managing_stock() : ( method_exists( $product, 'get_manage_stock' ) ? (bool) $product->get_manage_stock() : false );
			if ( ! $manages ) {
				$log['status']  = 'skipped';
				$log['message'] = 'مدیریت موجودی برای این محصول در WooCommerce خاموش است.';
				return $log;
			}
			if ( method_exists( $product, 'get_manage_stock' ) && 'parent' === $product->get_manage_stock() ) {
				$log['status']  = 'skipped';
				$log['message'] = 'موجودی این تنوع از محصول والد به ارث می‌رسد؛ تفکیک آن در کاردکس SQLite پشتیبانی نشده است.';
				return $log;
			}

			$reserved_units = (float) ( $reserved[ $key ] ?? 0 );
			$target         = (float) self::format_quantity( $qty, $scale ) - $reserved_units;
			$before         = (float) $product->get_stock_quantity();
			$diff           = round( $target - $before, 6 );
			$log['woo_quantity_before'] = (string) $before;
			if ( 0.0 === $diff ) {
				$log['woo_quantity_after'] = (string) $before;
				$log['status']             = 'synced';
				$log['message']            = 'موجودی ووکامرس با دفتر انبار یکسان است.';
				return $log;
			}
			if ( function_exists( 'wc_update_product_stock' ) ) {
				$after = wc_update_product_stock( $product, abs( $diff ), $diff > 0 ? 'increase' : 'decrease' );
			} else {
				$product->set_stock_quantity( $before + $diff );
				$product->save();
				$after = $before + $diff;
			}
			$log['woo_quantity_after'] = (string) $after;
			$log['status']             = 'synced';
			$log['message']            = 'موجودی ووکامرس بر اساس دفتر انبار' . ( $reserved_units > 0 ? ' و ' . $reserved_units . ' عدد رزرو سفارش‌های ثبت‌نشده' : '' ) . ' اصلاح شد (' . ( $diff > 0 ? '+' : '' ) . $diff . ').';
		} catch ( Throwable $e ) {
			$log['status']  = 'failed';
			$log['message'] = substr( $e->getMessage(), 0, 500 );
		}
		return $log;
	}

	/**
	 * Products that appear in unprocessed accounting events (sale.post / order adjustments).
	 *
	 * @return array<string,bool> "product:variation" keys.
	 */
	private static function pending_products( PDO $db ): array {
		$out = array();
		$q   = $db->query( "SELECT payload_json FROM mda_integration_outbox WHERE status IN ('pending','processing','failed') ORDER BY id LIMIT 2000" );
		foreach ( $q->fetchAll( PDO::FETCH_COLUMN ) as $json ) {
			$payload = json_decode( (string) $json, true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
				$out[ (int) ( $item['product_id'] ?? 0 ) . ':' . (int) ( $item['variation_id'] ?? 0 ) ] = true;
			}
			if ( isset( $payload['order_id'] ) && empty( $payload['items'] ) ) {
				foreach ( self::order_product_keys( (int) $payload['order_id'] ) as $key ) {
					$out[ $key ] = true;
				}
			}
		}
		return $out;
	}

	private static function order_product_keys( int $order_id ): array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}
		$keys = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$keys[] = (int) $item->get_product_id() . ':' . (int) $item->get_variation_id();
		}
		return $keys;
	}

	/**
	 * Quantities WooCommerce already deducted for recent orders that accounting has not posted yet.
	 *
	 * @return array<string,float>
	 */
	private static function unposted_reservations( PDO $db ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$posted = array_flip( array_map( 'intval', $db->query( "SELECT order_id FROM mda_sales_documents WHERE source_type IN ('woocommerce','phone','in_person') AND order_id IS NOT NULL" )->fetchAll( PDO::FETCH_COLUMN ) ) );
		$out    = array();
		$page   = 1;
		do {
			$result = wc_get_orders(
				array(
					'status'       => array_values( array_unique( array_merge( self::RESERVE_STATES, class_exists( 'MDA_Order_Statuses' ) ? MDA_Order_Statuses::sale_statuses() : array() ) ) ),
					'limit'        => 200,
					'page'         => $page,
					'paginate'     => true,
					'date_created' => '>' . gmdate( 'Y-m-d', time() - self::RESERVE_DAYS * DAY_IN_SECONDS ),
					'return'       => 'objects',
				)
			);
			$orders = is_object( $result ) ? (array) $result->orders : (array) $result;
			foreach ( $orders as $order ) {
				if ( isset( $posted[ (int) $order->get_id() ] ) ) {
					continue;
				}
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$reduced = method_exists( $item, 'get_meta' ) ? (float) $item->get_meta( '_reduced_stock', true ) : 0.0;
					if ( $reduced <= 0 ) {
						continue;
					}
					$key         = (int) $item->get_product_id() . ':' . (int) $item->get_variation_id();
					$out[ $key ] = ( $out[ $key ] ?? 0 ) + $reduced;
				}
			}
			$max = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
			++$page;
		} while ( $page <= $max && $page <= 50 );
		return $out;
	}

	private static function cursor( PDO $db, string $key ): array {
		$q = $db->prepare( 'SELECT setting_value FROM mda_settings WHERE setting_key = :key' );
		$q->execute( array( ':key' => $key ) );
		$cursor = json_decode( (string) $q->fetchColumn(), true );
		return is_array( $cursor ) ? $cursor : array( 'product_id' => 0, 'variation_id' => -1 );
	}

	private static function save_run( PDO $db, int $warehouse_id, string $started, array $counts, int $pending, array $cursor, string $status, string $message, array $items ): int {
		$finished = gmdate( 'Y-m-d H:i:s' );
		$db->prepare(
			'INSERT INTO mda_stock_sync_runs (warehouse_id, started_at, finished_at, scanned_count, synced_count, skipped_count, failed_count, pending_events_count, next_product_id, next_variation_id, status, message, created_at)
			VALUES (:warehouse, :started, :finished, :scanned, :synced, :skipped, :failed, :pending, :product, :variation, :status, :message, :created)'
		)->execute(
			array(
				':warehouse' => $warehouse_id,
				':started'   => $started,
				':finished'  => $finished,
				':scanned'   => $counts['scanned'],
				':synced'    => $counts['synced'],
				':skipped'   => $counts['skipped'],
				':failed'    => $counts['failed'],
				':pending'   => $pending,
				':product'   => (int) $cursor['product_id'],
				':variation' => (int) $cursor['variation_id'],
				':status'    => $status,
				':message'   => $message,
				':created'   => $finished,
			)
		);
		$run_id = (int) $db->lastInsertId();
		$insert = $db->prepare(
			'INSERT INTO mda_stock_sync_items (run_id, warehouse_id, product_id, variation_id, quantity_scaled, quantity_scale, sqlite_quantity, woo_quantity_before, woo_quantity_after, status, message, created_at)
			VALUES (:run, :warehouse, :product, :variation, :qty, :scale, :sqlite, :before, :after, :status, :message, :now)'
		);
		foreach ( $items as $item ) {
			$insert->execute(
				array(
					':run'       => $run_id,
					':warehouse' => $warehouse_id,
					':product'   => $item['product_id'],
					':variation' => $item['variation_id'],
					':qty'       => $item['quantity_scaled'],
					':scale'     => $item['quantity_scale'],
					':sqlite'    => $item['sqlite_quantity'],
					':before'    => $item['woo_quantity_before'],
					':after'     => $item['woo_quantity_after'],
					':status'    => $item['status'],
					':message'   => $item['message'],
					':now'       => $item['created_at'],
				)
			);
		}
		return $run_id;
	}

	public static function warehouses(): array {
		return MDA_Inventory_Service::warehouses();
	}

	public static function runs( int $limit = 30 ): array {
		return MDA_Database::connection()->query( 'SELECT r.*, w.warehouse_name FROM mda_stock_sync_runs r JOIN mda_warehouses w ON w.id = r.warehouse_id ORDER BY r.id DESC LIMIT ' . max( 1, min( 100, $limit ) ) )->fetchAll();
	}

	public static function items( int $run_id ): array {
		$q = MDA_Database::connection()->prepare( 'SELECT * FROM mda_stock_sync_items WHERE run_id = :id ORDER BY id' );
		$q->execute( array( ':id' => $run_id ) );
		return $q->fetchAll();
	}

	private static function is_power_of_ten( int $scale ): bool {
		if ( $scale < 1 ) {
			return false;
		}
		while ( $scale > 1 && 0 === $scale % 10 ) {
			$scale = intdiv( $scale, 10 );
		}
		return 1 === $scale;
	}

	private static function format_quantity( int $quantity, int $scale ): string {
		if ( $scale < 1 || ! self::is_power_of_ten( $scale ) ) {
			throw new InvalidArgumentException( 'مقیاس تعداد باید توان ده باشد.' );
		}
		$digits = strlen( (string) $scale ) - 1;
		if ( 0 === $digits ) {
			return (string) $quantity;
		}
		$whole     = intdiv( $quantity, $scale );
		$remainder = $quantity % $scale;
		if ( 0 === $remainder ) {
			return (string) $whole;
		}
		return $whole . '.' . rtrim( str_pad( (string) abs( $remainder ), $digits, '0', STR_PAD_LEFT ), '0' );
	}
}
