<?php
/**
 * Opening stock and stock counts (انبارگردانی) with matching accounting entries.
 *
 * Opening:  Dr Inventory (1300) / Cr Opening balance (3900) at the given unit cost.
 * Count:    surplus  → Dr 1300 / Cr 5280 at average cost;
 *           shortage → Dr 5280 / Cr 1300 at average cost.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Inventory_Adjustment_Service {
	public const SCALE = 1000000;

	/**
	 * @param string $counted Counted quantity in units, e.g. "12" or "2.5".
	 */
	public static function adjust( string $type, int $product_id, int $variation_id, int $warehouse_id, string $counted, ?int $unit_cost, string $date, string $reason, string $key ): array {
		if ( ! in_array( $type, array( 'opening', 'count' ), true ) || $product_id < 1 || $variation_id < 0 || $warehouse_id < 1 ) {
			throw new InvalidArgumentException( 'اطلاعات تعدیل انبار معتبر نیست.' );
		}
		if ( ! preg_match( '/^\d+(\.\d{1,6})?$/', $counted ) ) {
			throw new InvalidArgumentException( 'مقدار شمارش باید عدد نامنفی باشد.' );
		}
		if ( null !== $unit_cost && $unit_cost < 0 ) {
			throw new InvalidArgumentException( 'بهای واحد نمی‌تواند منفی باشد.' );
		}
		if ( strlen( $key ) < 8 ) {
			throw new InvalidArgumentException( 'کلید یکتای عملیات معتبر نیست.' );
		}
		$target = self::scale( $counted );

		return MDA_Database::transaction(
			static function ( PDO $db ) use ( $type, $product_id, $variation_id, $warehouse_id, $target, $unit_cost, $date, $reason, $key ): array {
				$dup = $db->prepare( 'SELECT * FROM mda_inventory_adjustments WHERE idempotency_key = :k' );
				$dup->execute( array( ':k' => $key ) );
				if ( $found = $dup->fetch() ) {
					return array( 'adjustment_id' => (int) $found['id'], 'duplicate' => true );
				}
				$wh = $db->prepare( 'SELECT 1 FROM mda_warehouses WHERE id = :id AND is_active = 1' );
				$wh->execute( array( ':id' => $warehouse_id ) );
				if ( ! $wh->fetchColumn() ) {
					throw new InvalidArgumentException( 'انبار فعال نیست.' );
				}
				$state = $db->prepare( 'SELECT quantity_scaled, quantity_scale, average_cost_minor, currency_code FROM mda_product_cost_state WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w' );
				$state->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id ) );
				$cost   = $state->fetch();
				$ledger = $db->prepare( 'SELECT COALESCE(SUM(quantity_scaled),0) q, COUNT(*) n FROM mda_inventory_movements WHERE product_id = :p AND variation_id = :v AND warehouse_id = :w' );
				$ledger->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id ) );
				$ledger_row = $ledger->fetch();

				if ( 'opening' === $type ) {
					if ( $cost || (int) $ledger_row['n'] > 0 ) {
						throw new RuntimeException( 'برای این کالا قبلاً گردش انبار ثبت شده است؛ از «شمارش انبار» استفاده کنید.' );
					}
					if ( null === $unit_cost ) {
						throw new InvalidArgumentException( 'برای موجودی افتتاحیه، بهای واحد الزامی است.' );
					}
					$before  = 0;
					$average = $unit_cost;
				} else {
					if ( $cost ) {
						if ( (int) $cost['quantity_scale'] !== self::SCALE || (int) $cost['quantity_scaled'] !== (int) $ledger_row['q'] ) {
							throw new RuntimeException( 'کاردکس و بهای تمام‌شده این کالا ناسازگار است؛ ابتدا بررسی شود.' );
						}
						$before  = (int) $cost['quantity_scaled'];
						$average = (int) $cost['average_cost_minor'];
					} else {
						if ( 0 !== (int) $ledger_row['q'] ) {
							throw new RuntimeException( 'برای این کالا وضعیت بهای تمام‌شده وجود ندارد.' );
						}
						if ( null === $unit_cost ) {
							throw new InvalidArgumentException( 'این کالا هنوز بهای تمام‌شده ندارد؛ بهای واحد را وارد کنید.' );
						}
						$before  = 0;
						$average = $unit_cost;
					}
				}

				$currency = $cost ? (string) $cost['currency_code'] : MDA_Settings::currency();
				$diff     = $target - $before;
				if ( 0 === $diff ) {
					throw new RuntimeException( 'مقدار شمارش با موجودی دفتری برابر است؛ تعدیلی لازم نیست.' );
				}
				$value = self::mul_div_round( abs( $diff ), $average, self::SCALE );
				$doc   = null;
				if ( $value > 0 ) {
					$inv     = self::account( $db, '1300' );
					$contra  = self::account( $db, 'opening' === $type ? '3900' : '5280' );
					$lines   = $diff > 0
						? array(
							array( 'account_id' => $inv, 'debit_minor' => $value, 'credit_minor' => 0, 'memo' => 'opening' === $type ? 'موجودی اول دوره' : 'اضافی انبارگردانی' ),
							array( 'account_id' => $contra, 'debit_minor' => 0, 'credit_minor' => $value, 'memo' => 'opening' === $type ? 'تراز افتتاحیه' : 'اضافی انبارگردانی' ),
						)
						: array(
							array( 'account_id' => $contra, 'debit_minor' => $value, 'credit_minor' => 0, 'memo' => 'کسری انبارگردانی' ),
							array( 'account_id' => $inv, 'debit_minor' => 0, 'credit_minor' => $value, 'memo' => 'کسری انبارگردانی' ),
						);
					$doc = MDA_Ledger_Service::post_document(
						array(
							'document_type'   => 'opening' === $type ? 'opening_stock' : 'stock_count',
							'idempotency_key' => 'inventory-adjust-' . $key,
							'document_date'   => $date,
							'currency_code'   => $currency,
							'source_type'     => 'inventory_adjustment',
							'source_id'       => $product_id . ':' . $variation_id,
							'description'     => ( 'opening' === $type ? 'موجودی افتتاحیه' : 'انبارگردانی' ) . ' کالای ' . $product_id . ( $reason ? ' — ' . $reason : '' ),
						),
						$lines
					);
				}
				$now      = gmdate( 'Y-m-d H:i:s' );
				$movement = 'opening' === $type ? 'opening' : ( $diff > 0 ? 'adjustment_in' : 'adjustment_out' );
				$db->prepare(
					"INSERT INTO mda_inventory_movements (product_id, variation_id, warehouse_id, movement_type, quantity_scaled, quantity_scale, unit_cost_minor, currency_code, source_type, source_id, idempotency_key, occurred_at, created_by)
					VALUES (:p, :v, :w, :t, :q, :s, :c, :cur, 'inventory_adjustment', :src, :k, :now, :u)"
				)->execute(
					array( ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id, ':t' => $movement, ':q' => $diff, ':s' => self::SCALE, ':c' => $average, ':cur' => $currency, ':src' => $key, ':k' => 'inventory-adjust-move-' . $key, ':now' => $now, ':u' => get_current_user_id() ?: null )
				);
				$db->prepare(
					"INSERT INTO mda_product_cost_state (product_id, variation_id, warehouse_id, quantity_scaled, quantity_scale, average_cost_minor, currency_code, updated_at)
					VALUES (:p, :v, :w, :q, :s, :c, :cur, :now)
					ON CONFLICT(product_id, variation_id, warehouse_id) DO UPDATE SET quantity_scaled = excluded.quantity_scaled, average_cost_minor = excluded.average_cost_minor, updated_at = excluded.updated_at"
				)->execute( array( ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id, ':q' => $target, ':s' => self::SCALE, ':c' => $average, ':cur' => $currency, ':now' => $now ) );
				$db->prepare(
					'INSERT INTO mda_inventory_adjustments (adjustment_type, product_id, variation_id, warehouse_id, quantity_before_scaled, quantity_after_scaled, quantity_scale, unit_cost_minor, value_minor, accounting_document_id, reason, idempotency_key, created_by, created_at)
					VALUES (:t, :p, :v, :w, :b, :a, :s, :c, :val, :doc, :r, :k, :u, :now)'
				)->execute(
					array( ':t' => $type, ':p' => $product_id, ':v' => $variation_id, ':w' => $warehouse_id, ':b' => $before, ':a' => $target, ':s' => self::SCALE, ':c' => $average, ':val' => $value, ':doc' => $doc['document_id'] ?? null, ':r' => sanitize_text_field( $reason ), ':k' => $key, ':u' => get_current_user_id() ?: null, ':now' => $now )
				);
				$id = (int) $db->lastInsertId();
				if ( $diff > 0 ) {
					MDA_Supply_Service::fulfil( $db, $product_id, $variation_id, $warehouse_id, $date, 'inventory_adjustment', (string) $id );
				}
				return array( 'adjustment_id' => $id, 'difference_scaled' => $diff, 'value_minor' => $value, 'document_id' => $doc['document_id'] ?? null, 'duplicate' => false );
			}
		);
	}

	public static function recent( int $limit = 100 ): array {
		$q = MDA_Database::connection()->prepare( 'SELECT * FROM mda_inventory_adjustments ORDER BY id DESC LIMIT :l' );
		$q->bindValue( ':l', max( 1, min( 500, $limit ) ), PDO::PARAM_INT );
		$q->execute();
		return $q->fetchAll();
	}

	public static function scale( string $units ): int {
		$parts = explode( '.', $units, 2 );
		$whole = (int) $parts[0];
		$frac  = isset( $parts[1] ) ? (int) str_pad( substr( $parts[1], 0, 6 ), 6, '0' ) : 0;
		if ( $whole > intdiv( PHP_INT_MAX - $frac, self::SCALE ) ) {
			throw new OverflowException( 'مقدار خیلی بزرگ است.' );
		}
		return $whole * self::SCALE + $frac;
	}

	private static function account( PDO $db, string $code ): int {
		$q = $db->prepare( 'SELECT id FROM mda_accounts WHERE account_code = :c AND is_active = 1' );
		$q->execute( array( ':c' => $code ) );
		$id = (int) $q->fetchColumn();
		if ( $id < 1 ) {
			throw new RuntimeException( 'حساب سیستمی ' . $code . ' پیدا نشد.' );
		}
		return $id;
	}

	private static function mul_div_round( int $a, int $b, int $d ): int {
		if ( $b > 0 && $a > intdiv( PHP_INT_MAX, $b ) ) {
			throw new OverflowException( 'محاسبه ارزش تعدیل خارج از ظرفیت است.' );
		}
		$p = $a * $b;
		$q = intdiv( $p, $d );
		if ( $p % $d >= intdiv( $d, 2 ) + ( $d % 2 ) ) {
			++$q;
		}
		return $q;
	}
}
