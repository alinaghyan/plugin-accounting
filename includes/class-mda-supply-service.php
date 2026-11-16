<?php
/**
 * Supply modes and backorders.
 *
 * A product in "on_demand" mode may be sold with zero accounting stock: the sale is posted
 * (revenue + receivable) and the missing quantity becomes a backorder line. When a purchase
 * for that product is posted, the backorder is fulfilled first (FIFO): stock is issued and
 * COGS is posted at the then-current weighted average cost.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Supply_Service {
	public const MODE_STOCK     = 'stock';
	public const MODE_ON_DEMAND = 'on_demand';

	public static function mode( PDO $db, int $product_id, int $variation_id = 0 ): string {
		$q = $db->prepare( 'SELECT product_id, variation_id, supply_mode FROM mda_product_settings WHERE product_id = :p AND variation_id IN (:v, 0) ORDER BY variation_id DESC LIMIT 1' );
		$q->execute( array( ':p' => $product_id, ':v' => $variation_id ) );
		$row  = $q->fetch();
		$mode = $row ? (string) $row['supply_mode'] : self::MODE_STOCK;
		return (string) apply_filters( 'mda_supply_mode', $mode, $product_id, $variation_id );
	}

	public static function settings( int $product_id, int $variation_id = 0 ): array {
		$q = MDA_Database::connection()->prepare( 'SELECT * FROM mda_product_settings WHERE product_id = :p AND variation_id = :v' );
		$q->execute( array( ':p' => $product_id, ':v' => $variation_id ) );
		$row = $q->fetch();
		return $row ? $row : array(
			'product_id'                  => $product_id,
			'variation_id'                => $variation_id,
			'supply_mode'                 => self::MODE_STOCK,
			'lead_time_days'              => null,
			'preferred_supplier_party_id' => null,
		);
	}

	public static function save_settings( int $product_id, int $variation_id, string $mode, ?int $lead_time_days, ?int $supplier_id ): void {
		if ( $product_id < 1 || $variation_id < 0 || ! in_array( $mode, array( self::MODE_STOCK, self::MODE_ON_DEMAND ), true ) ) {
			throw new InvalidArgumentException( 'تنظیمات تأمین کالا معتبر نیست.' );
		}
		if ( null !== $lead_time_days && ( $lead_time_days < 0 || $lead_time_days > 365 ) ) {
			throw new InvalidArgumentException( 'زمان تأمین باید بین ۰ تا ۳۶۵ روز باشد.' );
		}
		MDA_Database::transaction(
			static function ( PDO $db ) use ( $product_id, $variation_id, $mode, $lead_time_days, $supplier_id ): void {
				if ( $supplier_id ) {
					$s = $db->prepare( "SELECT 1 FROM mda_party_roles WHERE party_id = :id AND role_key = 'supplier'" );
					$s->execute( array( ':id' => $supplier_id ) );
					if ( ! $s->fetchColumn() ) {
						throw new InvalidArgumentException( 'تأمین‌کننده انتخاب‌شده معتبر نیست.' );
					}
				}
				$q = $db->prepare(
					'INSERT INTO mda_product_settings (product_id, variation_id, supply_mode, lead_time_days, preferred_supplier_party_id, updated_at)
					VALUES (:p, :v, :m, :l, :s, :now)
					ON CONFLICT(product_id, variation_id) DO UPDATE SET supply_mode = excluded.supply_mode, lead_time_days = excluded.lead_time_days,
					preferred_supplier_party_id = excluded.preferred_supplier_party_id, updated_at = excluded.updated_at'
				);
				$q->execute(
					array(
						':p'   => $product_id,
						':v'   => $variation_id,
						':m'   => $mode,
						':l'   => $lead_time_days,
						':s'   => $supplier_id ?: null,
						':now' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
			}
		);
	}

	public static function record_backorder( PDO $db, int $sale_id, int $sales_line_id, int $order_id, int $product_id, int $variation_id, int $warehouse_id, int $qty, int $scale, string $now ): int {
		$q = $db->prepare(
			'INSERT INTO mda_backorder_lines (sales_document_id, sales_line_id, order_id, product_id, variation_id, warehouse_id, quantity_scaled, quantity_scale, created_at)
			VALUES (:sale, :line, :order, :p, :v, :w, :q, :s, :now)'
		);
		$q->execute(
			array(
				':sale'  => $sale_id,
				':line'  => $sales_line_id,
				':order' => $order_id,
				':p'     => $product_id,
				':v'     => $variation_id,
				':w'     => $warehouse_id,
				':q'     => $qty,
				':s'     => $scale,
				':now'   => $now,
			)
		);
		return (int) $db->lastInsertId();
	}

	/**
	 * Issues stock to open backorders of one product after new stock arrived. Must run inside a transaction.
	 *
	 * @return array{fulfilled_scaled:int,cogs_minor:int,document_id:?int}
	 */
	public static function fulfil( PDO $db, int $product_id, int $variation_id, int $warehouse_id, string $date, string $source_type, string $source_id ): array {
		$result = array( 'fulfilled_scaled' => 0, 'cogs_minor' => 0, 'document_id' => null );
		if ( ! $db->inTransaction() ) {
			throw new LogicException( 'Backorder fulfilment must run inside a transaction.' );
		}

		$state = $db->prepare( 'SELECT quantity_scaled, quantity_scale, average_cost_minor, currency_code FROM mda_product_cost_state WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w' );
		$state->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id ) );
		$cost = $state->fetch();
		if ( ! $cost || (int) $cost['quantity_scaled'] <= 0 ) {
			return $result;
		}
		$available = (int) $cost['quantity_scaled'];
		$scale     = (int) $cost['quantity_scale'];
		$unit_cost = (int) $cost['average_cost_minor'];
		$currency  = (string) $cost['currency_code'];

		$open = $db->prepare( 'SELECT id, order_id, quantity_scale, open_scaled FROM mda_backorder_open WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w AND open_scaled > 0 ORDER BY id' );
		$open->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id ) );
		$plan = array();
		foreach ( $open->fetchAll() as $row ) {
			if ( $available <= 0 ) {
				break;
			}
			if ( (int) $row['quantity_scale'] !== $scale ) {
				continue; // Different unit scale: left open for manual review.
			}
			$take      = min( $available, (int) $row['open_scaled'] );
			$cogs      = self::mul_div_round( $take, $unit_cost, $scale );
			$plan[]    = array( 'row' => $row, 'take' => $take, 'cogs' => $cogs );
			$available -= $take;
			$result['fulfilled_scaled'] += $take;
			$result['cogs_minor']       += $cogs;
		}
		if ( ! $plan ) {
			return $result;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$key = 'backorder-cogs-' . $source_type . '-' . $source_id . '-' . $product_id . '-' . $variation_id . '-' . $warehouse_id;
		if ( $result['cogs_minor'] > 0 ) {
			$posted = MDA_Ledger_Service::post_document(
				array(
					'document_type'   => 'backorder_cogs',
					'idempotency_key' => $key,
					'document_date'   => $date,
					'currency_code'   => $currency,
					'source_type'     => $source_type,
					'source_id'       => $source_id,
					'description'     => 'بهای تمام‌شده فروش سفارشی (تأمین پس از فروش) کالای ' . $product_id,
				),
				array(
					array( 'account_id' => self::account( $db, '5100' ), 'debit_minor' => $result['cogs_minor'], 'credit_minor' => 0, 'memo' => 'بهای تمام‌شده کالای فروش‌رفته سفارشی' ),
					array( 'account_id' => self::account( $db, '1300' ), 'debit_minor' => 0, 'credit_minor' => $result['cogs_minor'], 'memo' => 'خروج کالا برای تحویل سفارش معوق' ),
				)
			);
			$result['document_id'] = (int) $posted['document_id'];
		}

		$movement = $db->prepare(
			"INSERT INTO mda_inventory_movements (product_id, variation_id, warehouse_id, movement_type, quantity_scaled, quantity_scale, unit_cost_minor, currency_code, source_type, source_id, idempotency_key, occurred_at, created_by)
			VALUES (:p, :v, :w, 'sale_issue', :q, :s, :c, :cur, 'backorder', :src, :key, :now, :user)"
		);
		$fulfil = $db->prepare(
			'INSERT INTO mda_backorder_fulfilments (backorder_line_id, source_type, source_id, quantity_scaled, unit_cost_minor, cogs_minor, accounting_document_id, idempotency_key, created_at)
			VALUES (:line, :st, :sid, :q, :c, :cogs, :doc, :key, :now)'
		);
		foreach ( $plan as $item ) {
			$line_key = 'backorder-issue-' . $item['row']['id'] . '-' . $source_type . '-' . $source_id;
			$movement->execute(
				array(
					':p'    => $product_id,
					':v'    => $variation_id,
					':w'    => $warehouse_id,
					':q'    => -$item['take'],
					':s'    => $scale,
					':c'    => $unit_cost,
					':cur'  => $currency,
					':src'  => (string) $item['row']['order_id'],
					':key'  => $line_key,
					':now'  => $now,
					':user' => get_current_user_id() ?: null,
				)
			);
			$fulfil->execute(
				array(
					':line' => $item['row']['id'],
					':st'   => $source_type,
					':sid'  => $source_id,
					':q'    => $item['take'],
					':c'    => $unit_cost,
					':cogs' => $item['cogs'],
					':doc'  => $result['document_id'],
					':key'  => $line_key,
					':now'  => $now,
				)
			);
		}
		$update = $db->prepare( 'UPDATE mda_product_cost_state SET quantity_scaled = quantity_scaled - :q, updated_at = :now WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w AND quantity_scaled >= :q' );
		$update->execute( array( ':q' => $result['fulfilled_scaled'], ':now' => $now, ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id ) );
		if ( 1 !== $update->rowCount() ) {
			throw new RuntimeException( 'به‌روزرسانی موجودی هنگام تحویل سفارش معوق انجام نشد.' );
		}
		return $result;
	}

	/**
	 * Open backorders for the admin list.
	 */
	public static function open_backorders( int $limit = 200 ): array {
		$q = MDA_Database::connection()->prepare(
			'SELECT b.*, l.product_name_snapshot, l.sku_snapshot FROM mda_backorder_open b JOIN mda_sales_lines l ON l.id = b.sales_line_id
			WHERE b.open_scaled > 0 ORDER BY b.id LIMIT :l'
		);
		$q->bindValue( ':l', max( 1, min( 1000, $limit ) ), PDO::PARAM_INT );
		$q->execute();
		return $q->fetchAll();
	}

	/**
	 * Quantity of open backorders per product, used to keep WooCommerce stock honest.
	 */
	public static function open_quantity( PDO $db, int $product_id, int $variation_id, int $warehouse_id ): int {
		$q = $db->prepare( 'SELECT COALESCE(SUM(open_scaled),0) FROM mda_backorder_open WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w' );
		$q->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id ) );
		return (int) $q->fetchColumn();
	}

	private static function account( PDO $db, string $code ): int {
		$q = $db->prepare( 'SELECT id FROM mda_accounts WHERE account_code = :c AND is_active = 1 AND archived_at IS NULL' );
		$q->execute( array( ':c' => $code ) );
		$id = (int) $q->fetchColumn();
		if ( $id < 1 ) {
			throw new RuntimeException( 'حساب سیستمی ' . $code . ' پیدا نشد.' );
		}
		return $id;
	}

	private static function mul_div_round( int $a, int $b, int $d ): int {
		if ( $a < 0 || $b < 0 || $d < 1 || ( $b > 0 && $a > intdiv( PHP_INT_MAX, $b ) ) ) {
			throw new OverflowException( 'محاسبه بهای تمام‌شده خارج از ظرفیت عدد صحیح است.' );
		}
		$p = $a * $b;
		$q = intdiv( $p, $d );
		if ( $p % $d >= intdiv( $d, 2 ) + ( $d % 2 ) ) {
			++$q;
		}
		return $q;
	}
}
