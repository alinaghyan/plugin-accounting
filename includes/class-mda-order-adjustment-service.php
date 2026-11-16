<?php
/**
 * Cancelled and refunded WooCommerce orders → accounting sales returns.
 *
 * - Cancel / full "refunded" status: every unreturned quantity of the posted sale is returned.
 * - Partial refund with line quantities: those quantities are returned.
 * - Amount-only refund (no quantities): recorded as "needs_review" for the accountant, because
 *   the plugin cannot know whether it is a discount, shipping refund or goods return.
 * Money paid back to the customer is recorded separately (sales return → refund), so the
 * customer's balance shows a credit until the actual payment is registered.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Order_Adjustment_Service {

	/**
	 * @param WC_Order $order
	 */
	public static function enqueue( $order, string $type, int $refund_id = 0 ): void {
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! in_array( $type, array( 'cancel', 'refund' ), true ) ) {
			return;
		}
		$order_id = (int) $order->get_id();
		$items    = array();
		$amount   = 0;
		if ( 'refund' === $type && $refund_id > 0 && function_exists( 'wc_get_order' ) ) {
			$refund = wc_get_order( $refund_id );
			if ( $refund ) {
				$amount = (int) round( abs( (float) $refund->get_amount() ) );
				foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
					$original = (int) $refund_item->get_meta( '_refunded_item_id', true );
					$qty      = abs( (float) $refund_item->get_quantity() );
					if ( $original > 0 && $qty > 0 ) {
						$items[] = array( 'order_item_id' => $original, 'quantity' => (string) $qty );
					}
				}
			}
		}
		$payload = array(
			'order_id'  => $order_id,
			'type'      => $type,
			'refund_id' => $refund_id,
			'items'     => $items,
			'amount'    => $amount,
			'date'      => current_time( 'Y-m-d' ),
		);
		$key = 'wc-adjust-' . $type . '-' . $order_id . '-' . $refund_id;
		$db  = MDA_Database::connection();
		$now = gmdate( 'Y-m-d H:i:s' );
		$q   = $db->prepare(
			"INSERT OR IGNORE INTO mda_integration_outbox (event_key,event_type,aggregate_type,aggregate_id,payload_json,status,attempts,available_at,created_at)
			VALUES (:key,'order.adjust','woocommerce_order',:id,:payload,'pending',0,:now,:now)"
		);
		$q->execute( array( ':key' => $key, ':id' => (string) $order_id, ':payload' => wp_json_encode( $payload ), ':now' => $now ) );
		if ( ! wp_next_scheduled( 'mda_process_outbox_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'mda_process_outbox_batch' );
		}
	}

	/**
	 * Called by the outbox worker. Throws to make the worker retry (e.g. sale not posted yet).
	 */
	public static function process( array $payload ): array {
		$db        = MDA_Database::connection();
		$order_id  = (int) ( $payload['order_id'] ?? 0 );
		$type      = (string) ( $payload['type'] ?? '' );
		$refund_id = (int) ( $payload['refund_id'] ?? 0 );
		if ( $order_id < 1 || ! in_array( $type, array( 'cancel', 'refund' ), true ) ) {
			throw new InvalidArgumentException( 'رویداد اصلاح سفارش معتبر نیست.' );
		}
		$done = $db->prepare( 'SELECT status FROM mda_order_adjustments WHERE order_id = :o AND adjustment_type = :t AND woo_refund_id = :r' );
		$done->execute( array( ':o' => $order_id, ':t' => $type, ':r' => $refund_id ) );
		if ( false !== $done->fetchColumn() ) {
			return array( 'status' => 'duplicate' );
		}

		$sale = $db->prepare( "SELECT id FROM mda_sales_documents WHERE order_id = :o AND status = 'posted'" );
		$sale->execute( array( ':o' => $order_id ) );
		$sale_id = (int) $sale->fetchColumn();
		if ( $sale_id < 1 ) {
			$pending = $db->prepare( "SELECT 1 FROM mda_integration_outbox WHERE event_type = 'sale.post' AND aggregate_id = :o AND status IN ('pending','processing','failed')" );
			$pending->execute( array( ':o' => (string) $order_id ) );
			if ( $pending->fetchColumn() ) {
				throw new RuntimeException( 'فروش این سفارش هنوز ثبت حسابداری نشده است؛ اصلاح پس از ثبت فروش انجام می‌شود.' );
			}
			return self::record( $db, $order_id, $type, $refund_id, null, 'skipped', 'فروشی برای این سفارش در حسابداری ثبت نشده بود؛ اصلاحی لازم نیست.' );
		}

		$lines = self::return_lines( $db, $sale_id, $type, (array) ( $payload['items'] ?? array() ) );
		if ( ! $lines ) {
			$message = 'cancel' === $type
				? 'همه اقلام این سفارش قبلاً مرجوع شده‌اند.'
				: 'استرداد مبلغی بدون تعیین تعداد کالا (' . (int) ( $payload['amount'] ?? 0 ) . ')؛ نوع آن (تخفیف، هزینه ارسال یا مرجوعی) را حسابدار بررسی و ثبت کند.';
			return self::record( $db, $order_id, $type, $refund_id, null, 'cancel' === $type ? 'skipped' : 'needs_review', $message );
		}

		$date   = (string) ( $payload['date'] ?? gmdate( 'Y-m-d' ) );
		$return = MDA_Sales_Return_Service::post( $sale_id, $lines, 'wc-' . $type . '-' . $order_id . '-' . $refund_id, $date );
		return self::record( $db, $order_id, $type, $refund_id, (int) $return['return_id'], 'applied', 'مرجوعی ثبت شد؛ استرداد وجه به مشتری را از صفحه مرجوعی فروش ثبت کنید.' );
	}

	private static function return_lines( PDO $db, int $sale_id, string $type, array $items ): array {
		$q = $db->prepare(
			'SELECT l.id, l.order_item_id, l.quantity_scaled, l.quantity_scale,
				COALESCE((SELECT SUM(r.quantity_scaled) FROM mda_sales_return_lines r WHERE r.sales_line_id = l.id),0) AS returned
			FROM mda_sales_lines l WHERE l.sales_document_id = :s ORDER BY l.id'
		);
		$q->execute( array( ':s' => $sale_id ) );
		$by_item = array();
		foreach ( $q->fetchAll() as $row ) {
			$by_item[ (int) $row['order_item_id'] ] = $row;
		}
		$out = array();
		if ( 'cancel' === $type ) {
			foreach ( $by_item as $row ) {
				$left = (int) $row['quantity_scaled'] - (int) $row['returned'];
				if ( $left > 0 ) {
					$out[] = array( 'sales_line_id' => (int) $row['id'], 'quantity_scaled' => $left );
				}
			}
			return $out;
		}
		foreach ( $items as $item ) {
			$row = $by_item[ (int) ( $item['order_item_id'] ?? 0 ) ] ?? null;
			if ( ! $row ) {
				continue;
			}
			$scaled = (int) round( (float) $item['quantity'] * (int) $row['quantity_scale'] );
			$left   = (int) $row['quantity_scaled'] - (int) $row['returned'];
			$qty    = min( $scaled, $left );
			if ( $qty > 0 ) {
				$out[] = array( 'sales_line_id' => (int) $row['id'], 'quantity_scaled' => $qty );
			}
		}
		return $out;
	}

	private static function record( PDO $db, int $order_id, string $type, int $refund_id, ?int $return_id, string $status, string $message ): array {
		$db->prepare(
			'INSERT OR IGNORE INTO mda_order_adjustments (order_id, adjustment_type, woo_refund_id, sales_return_id, status, message, created_at)
			VALUES (:o, :t, :r, :ret, :s, :m, :now)'
		)->execute(
			array(
				':o'   => $order_id,
				':t'   => $type,
				':r'   => $refund_id,
				':ret' => $return_id,
				':s'   => $status,
				':m'   => $message,
				':now' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return array( 'status' => $status, 'return_id' => $return_id, 'message' => $message );
	}

	public static function needing_review( int $limit = 100 ): array {
		$q = MDA_Database::connection()->prepare( "SELECT * FROM mda_order_adjustments WHERE status = 'needs_review' ORDER BY id DESC LIMIT :l" );
		$q->bindValue( ':l', max( 1, min( 500, $limit ) ), PDO::PARAM_INT );
		$q->execute();
		return $q->fetchAll();
	}
}
