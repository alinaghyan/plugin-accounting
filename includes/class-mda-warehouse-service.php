<?php
/**
 * Multiple warehouses: create/edit/activate, stock per warehouse and transfers.
 *
 * A transfer issues the goods from the source warehouse at its average cost and receives them in the
 * destination at that cost (weighted into the destination's average). Total inventory value does not
 * change, so no journal entry is needed; both movements and an append-only transfer record are kept.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Warehouse_Service {
	public const SCALE = 1000000;

	public static function all( bool $active_only = false ): array {
		$sql = 'SELECT id, warehouse_code, warehouse_name, is_active, address, created_at FROM mda_warehouses' . ( $active_only ? ' WHERE is_active = 1' : '' ) . ' ORDER BY id';
		return MDA_Database::connection()->query( $sql )->fetchAll();
	}

	public static function save( array $in, int $id = 0 ): int {
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $in['code'] ?? '' ) ) );
		$name = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 80 );
		$addr = mb_substr( sanitize_textarea_field( (string) ( $in['address'] ?? '' ) ), 0, 300 );
		if ( '' === $name ) {
			throw new InvalidArgumentException( 'نام انبار را وارد کنید.' );
		}
		$db = MDA_Database::connection();
		if ( $id > 0 ) {
			$active = empty( $in['is_active'] ) ? 0 : 1;
			if ( ! $active && $id === MDA_Settings::online_warehouse_id() ) {
				throw new InvalidArgumentException( 'انبار فروش آنلاین را نمی‌توان غیرفعال کرد؛ اول انبار دیگری را برای فروش آنلاین انتخاب کنید.' );
			}
			$q = $db->prepare( 'UPDATE mda_warehouses SET warehouse_name = :n, address = :a, is_active = :act WHERE id = :id' );
			$q->execute( array( ':n' => $name, ':a' => $addr, ':act' => $active, ':id' => $id ) );
			return $id;
		}
		if ( strlen( $code ) < 2 || strlen( $code ) > 20 ) {
			throw new InvalidArgumentException( 'کد انبار باید ۲ تا ۲۰ حرف یا عدد انگلیسی باشد (مثلاً SHOP).' );
		}
		$dup = $db->prepare( 'SELECT 1 FROM mda_warehouses WHERE warehouse_code = :c' );
		$dup->execute( array( ':c' => $code ) );
		if ( $dup->fetchColumn() ) {
			throw new InvalidArgumentException( 'این کد انبار قبلاً استفاده شده است.' );
		}
		$q = $db->prepare( 'INSERT INTO mda_warehouses (warehouse_code, warehouse_name, address, is_active, created_at) VALUES (:c, :n, :a, 1, :now)' );
		$q->execute( array( ':c' => $code, ':n' => $name, ':a' => $addr, ':now' => gmdate( 'Y-m-d H:i:s' ) ) );
		return (int) $db->lastInsertId();
	}

	/**
	 * Stock per product and warehouse from the cost state (one row per product/variation).
	 *
	 * @return array{warehouses:array,rows:array}
	 */
	public static function stock_matrix( string $search = '', int $limit = 200 ): array {
		$db         = MDA_Database::connection();
		$warehouses = self::all();
		$q          = $db->query( 'SELECT product_id, variation_id, warehouse_id, quantity_scaled, quantity_scale, average_cost_minor FROM mda_product_cost_state ORDER BY product_id, variation_id' );
		$rows       = array();
		foreach ( $q as $r ) {
			$k = (int) $r['product_id'] . ':' . (int) $r['variation_id'];
			if ( ! isset( $rows[ $k ] ) ) {
				$rows[ $k ] = array( 'product_id' => (int) $r['product_id'], 'variation_id' => (int) $r['variation_id'], 'qty' => array(), 'value' => 0 );
			}
			$qty                                         = (int) $r['quantity_scaled'] / max( 1, (int) $r['quantity_scale'] );
			$rows[ $k ]['qty'][ (int) $r['warehouse_id'] ] = $qty;
			$rows[ $k ]['value']                        += (int) round( $qty * (int) $r['average_cost_minor'] );
		}
		$out    = array();
		$search = mb_strtolower( trim( $search ) );
		foreach ( $rows as $row ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $row['variation_id'] ?: $row['product_id'] ) : null;
			if ( ! $p ) {
				continue;
			}
			$row['name'] = wp_strip_all_tags( $p->get_formatted_name() );
			$row['sku']  = (string) $p->get_sku();
			if ( '' !== $search && false === mb_strpos( mb_strtolower( $row['name'] . ' ' . $row['sku'] ), $search ) ) {
				continue;
			}
			$out[] = $row;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return array( 'warehouses' => $warehouses, 'rows' => $out );
	}

	/**
	 * Moves stock between warehouses. Idempotent per $key.
	 *
	 * @return array{transfer_id:int,duplicate:bool}
	 */
	public static function transfer( int $product_id, int $variation_id, int $from, int $to, string $quantity, string $date, string $note, string $key ): array {
		$qty = self::scale_quantity( $quantity );
		if ( $product_id < 1 || $variation_id < 0 || $from < 1 || $to < 1 || $from === $to ) {
			throw new InvalidArgumentException( 'کالا، انبار مبدأ و انبار مقصد (متفاوت) را انتخاب کنید.' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new InvalidArgumentException( 'تاریخ انتقال معتبر نیست.' );
		}
		if ( strlen( $key ) < 12 || strlen( $key ) > 100 ) {
			throw new InvalidArgumentException( 'کلید یکتای درخواست معتبر نیست.' );
		}
		$note   = mb_substr( sanitize_text_field( $note ), 0, 200 );
		$result = MDA_Database::transaction(
			static function ( PDO $db ) use ( $product_id, $variation_id, $from, $to, $qty, $date, $note, $key ): array {
				$dup = $db->prepare( 'SELECT id FROM mda_warehouse_transfers WHERE idempotency_key = :k' );
				$dup->execute( array( ':k' => $key ) );
				if ( $id = (int) $dup->fetchColumn() ) {
					return array( 'transfer_id' => $id, 'duplicate' => true );
				}
				$wh = $db->prepare( 'SELECT COUNT(*) FROM mda_warehouses WHERE id IN (:a, :b) AND is_active = 1' );
				$wh->execute( array( ':a' => $from, ':b' => $to ) );
				if ( 2 !== (int) $wh->fetchColumn() ) {
					throw new InvalidArgumentException( 'انبار مبدأ یا مقصد وجود ندارد یا غیرفعال است.' );
				}
				$state = $db->prepare( 'SELECT quantity_scaled, quantity_scale, average_cost_minor, currency_code FROM mda_product_cost_state WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w' );
				$ledger = $db->prepare( 'SELECT COALESCE(SUM(quantity_scaled),0) FROM mda_inventory_movements WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w AND quantity_scale = :s' );
				$state->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $from ) );
				$src = $state->fetch();
				if ( ! $src || (int) $src['quantity_scale'] !== self::SCALE ) {
					throw new RuntimeException( 'این کالا در انبار مبدأ موجودی ثبت‌شده ندارد.' );
				}
				$ledger->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $from, ':s' => self::SCALE ) );
				$src_qty = (int) $ledger->fetchColumn();
				if ( $src_qty !== (int) $src['quantity_scaled'] ) {
					throw new RuntimeException( 'کاردکس و بهای تمام‌شده انبار مبدأ با هم سازگار نیستند؛ ابتدا انبارگردانی کنید.' );
				}
				if ( $qty > $src_qty ) {
					throw new InvalidArgumentException( 'موجودی انبار مبدأ کافی نیست (موجودی: ' . self::format_qty( $src_qty ) . ').' );
				}
				$cost     = (int) $src['average_cost_minor'];
				$currency = (string) $src['currency_code'];
				$state->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $to ) );
				$dst = $state->fetch();
				$ledger->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $to, ':s' => self::SCALE ) );
				$dst_qty = (int) $ledger->fetchColumn();
				if ( $dst ) {
					if ( (int) $dst['quantity_scale'] !== self::SCALE || (string) $dst['currency_code'] !== $currency || (int) $dst['quantity_scaled'] !== $dst_qty ) {
						throw new RuntimeException( 'کاردکس و بهای تمام‌شده انبار مقصد با هم سازگار نیستند؛ ابتدا انبارگردانی کنید.' );
					}
				} elseif ( 0 !== $dst_qty ) {
					throw new RuntimeException( 'برای موجودی قبلی انبار مقصد بهای افتتاحیه ثبت نشده است.' );
				}
				$old_qty  = $dst ? (int) $dst['quantity_scaled'] : 0;
				$old_cost = $dst ? (int) $dst['average_cost_minor'] : 0;
				$new_qty  = $old_qty + $qty;
				$new_cost = $new_qty > 0 ? (int) round( ( $old_qty * $old_cost + $qty * $cost ) / $new_qty ) : $cost;
				$now      = gmdate( 'Y-m-d H:i:s' );
				$user     = get_current_user_id() ?: null;

				$db->prepare(
					'INSERT INTO mda_warehouse_transfers (product_id, variation_id, from_warehouse_id, to_warehouse_id, quantity_scaled, quantity_scale, unit_cost_minor, currency_code, transfer_date, note, idempotency_key, created_by, created_at)
					VALUES (:p, :v, :f, :t, :q, :s, :c, :cur, :d, :n, :k, :u, :now)'
				)->execute( array( ':p' => $product_id, ':v' => $variation_id, ':f' => $from, ':t' => $to, ':q' => $qty, ':s' => self::SCALE, ':c' => $cost, ':cur' => $currency, ':d' => $date, ':n' => $note, ':k' => $key, ':u' => $user, ':now' => $now ) );
				$tid  = (int) $db->lastInsertId();
				$move = $db->prepare(
					"INSERT INTO mda_inventory_movements (product_id, variation_id, warehouse_id, movement_type, quantity_scaled, quantity_scale, unit_cost_minor, currency_code, source_type, source_id, idempotency_key, occurred_at, created_by)
					VALUES (:p, :v, :w, :t, :q, :s, :c, :cur, 'warehouse_transfer', :src, :k, :now, :u)"
				);
				$move->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $from, ':t' => 'transfer_out', ':q' => -$qty, ':s' => self::SCALE, ':c' => $cost, ':cur' => $currency, ':src' => (string) $tid, ':k' => 'transfer-out-' . $tid, ':now' => $now, ':u' => $user ) );
				$move->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $to, ':t' => 'transfer_in', ':q' => $qty, ':s' => self::SCALE, ':c' => $cost, ':cur' => $currency, ':src' => (string) $tid, ':k' => 'transfer-in-' . $tid, ':now' => $now, ':u' => $user ) );
				$db->prepare( 'UPDATE mda_product_cost_state SET quantity_scaled = quantity_scaled - :q, updated_at = :now WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w' )
					->execute( array( ':q' => $qty, ':now' => $now, ':p' => $product_id, ':v' => $variation_id, ':w' => $from ) );
				$db->prepare(
					'INSERT INTO mda_product_cost_state (product_id, variation_id, warehouse_id, quantity_scaled, quantity_scale, average_cost_minor, currency_code, updated_at)
					VALUES (:p, :v, :w, :q, :s, :c, :cur, :now)
					ON CONFLICT(product_id, variation_id, warehouse_id) DO UPDATE SET quantity_scaled = excluded.quantity_scaled, average_cost_minor = excluded.average_cost_minor, updated_at = excluded.updated_at'
				)->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $to, ':q' => $new_qty, ':s' => self::SCALE, ':c' => $new_cost, ':cur' => $currency, ':now' => $now ) );
				MDA_Supply_Service::fulfil( $db, $product_id, $variation_id, $to, $date, 'warehouse_transfer', (string) $tid );
				return array( 'transfer_id' => $tid, 'duplicate' => false );
			}
		);
		$online = MDA_Settings::online_warehouse_id();
		if ( ! $result['duplicate'] && ( $from === $online || $to === $online ) && function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 5, 'mda_woocommerce_stock_sync_continue', array( $online ) );
		}
		return $result;
	}

	public static function recent_transfers( int $limit = 30 ): array {
		$q = MDA_Database::connection()->prepare(
			'SELECT t.*, f.warehouse_name AS from_name, w.warehouse_name AS to_name FROM mda_warehouse_transfers t
			JOIN mda_warehouses f ON f.id = t.from_warehouse_id JOIN mda_warehouses w ON w.id = t.to_warehouse_id ORDER BY t.id DESC LIMIT :l'
		);
		$q->bindValue( ':l', $limit, PDO::PARAM_INT );
		$q->execute();
		return $q->fetchAll();
	}

	public static function scale_quantity( string $quantity ): int {
		$q = strtr( trim( MDA_Jalali::latin_digits( $quantity ) ), array( '٫' => '.', ',' => '.' ) );
		if ( ! preg_match( '/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $q ) ) {
			throw new InvalidArgumentException( 'تعداد معتبر نیست.' );
		}
		$parts = explode( '.', $q );
		$n     = (int) $parts[0] * self::SCALE + (int) str_pad( $parts[1] ?? '', 6, '0' );
		if ( $n < 1 ) {
			throw new InvalidArgumentException( 'تعداد باید بیشتر از صفر باشد.' );
		}
		return $n;
	}

	public static function format_qty( int $scaled ): string {
		$v = $scaled / self::SCALE;
		return number_format_i18n( $v, floor( $v ) == $v ? 0 : 2 ); // phpcs:ignore Universal.Operators.StrictComparisons -- float compare intended.
	}
}
