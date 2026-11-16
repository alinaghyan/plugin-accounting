<?php
/**
 * Bridges to the other MaliDev plugins. Every bridge is optional: when the other plugin is not
 * installed the feature silently disappears.
 *
 * - استودیو فاکتور (Plugin-Factor-woocommerce): print buyer invoice, thermal receipt and shipping
 *   label for orders created or listed in accounting.
 * - پیامک ورود، رهگیری پست (plugin-sms-woocamers-tracking): sends SMS through its configured
 *   Kavenegar account — customer balance reminders and daily cheque due reminders to the managers.
 *   Other SMS providers can hook in with the `mda_send_sms` filter.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Integrations {
	public const CRON = 'mda_cheque_reminders';

	public static function init(): void {
		add_action( 'admin_post_mda_sms_balance_reminder', array( __CLASS__, 'handle_balance_reminder' ) );
		add_action( self::CRON, array( __CLASS__, 'cheque_reminders' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 08:15:00' ), 'daily', self::CRON );
		}
	}

	/* ------------------------------------------------------------------ orders & invoices */

	public static function order_url( int $order_id ): string {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id );
		}
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	public static function invoice_available(): bool {
		return class_exists( '\PersianInvoiceStudio\Admin\PrintController' ) && method_exists( '\PersianInvoiceStudio\Admin\PrintController', 'url' );
	}

	/**
	 * Escaped HTML: print buttons of the invoice plugin for one order ('' when unavailable).
	 */
	public static function print_buttons( int $order_id ): string {
		if ( $order_id < 1 || ! self::invoice_available() || ! current_user_can( 'edit_shop_orders' ) ) {
			return '';
		}
		$types = array(
			'buyer_invoice'   => array( 'فاکتور', 'dashicons-media-text' ),
			'thermal_receipt' => array( 'رسید حرارتی', 'dashicons-tickets-alt' ),
			'shipping_label'  => array( 'برچسب پستی', 'dashicons-tag' ),
		);
		$out = '<span class="mda-print-buttons">';
		foreach ( $types as $type => $meta ) {
			try {
				$url = \PersianInvoiceStudio\Admin\PrintController::url( $order_id, $type );
			} catch ( Throwable $e ) {
				continue;
			}
			$out .= '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( $url ) . '" title="' . esc_attr( $meta[0] ) . '"><span class="dashicons ' . esc_attr( $meta[1] ) . '"></span> ' . esc_html( $meta[0] ) . '</a> ';
		}
		return $out . '</span>';
	}

	/* ------------------------------------------------------------------ SMS */

	public static function sms_available(): bool {
		$main = $GLOBALS['smswt_kavenegar_sms'] ?? null;
		return ( is_object( $main ) && method_exists( $main, 'kavenegar_send' ) ) || has_filter( 'mda_send_sms' );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function send_sms( string $mobile, string $text ) {
		$mobile = preg_replace( '/\D/', '', MDA_Jalali::latin_digits( $mobile ) );
		if ( preg_match( '/^(98|0098)?9\d{9}$/', $mobile ) ) {
			$mobile = '0' . substr( $mobile, -10 );
		}
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			return new WP_Error( 'mda_sms_mobile', 'شماره موبایل معتبر نیست.' );
		}
		$custom = apply_filters( 'mda_send_sms', null, $mobile, $text );
		if ( null !== $custom ) {
			return true === $custom ? true : ( is_wp_error( $custom ) ? $custom : new WP_Error( 'mda_sms', 'ارسال پیامک ناموفق بود.' ) );
		}
		$main = $GLOBALS['smswt_kavenegar_sms'] ?? null;
		if ( ! is_object( $main ) || ! method_exists( $main, 'kavenegar_send' ) ) {
			return new WP_Error( 'mda_sms_missing', 'افزونه پیامک MaliDev فعال نیست یا تنظیم نشده است.' );
		}
		$sender = '';
		if ( class_exists( 'SMSWT_Order_SMS' ) && method_exists( 'SMSWT_Order_SMS', 'settings' ) ) {
			$sender = (string) ( SMSWT_Order_SMS::settings()['sender'] ?? '' );
		}
		$result = $main->kavenegar_send( $mobile, $text, $sender );
		return is_wp_error( $result ) ? $result : true;
	}

	/** Manager mobiles configured in the SMS plugin. */
	public static function manager_mobiles(): array {
		if ( class_exists( 'SMSWT_Order_SMS' ) && method_exists( 'SMSWT_Order_SMS', 'settings' ) && method_exists( 'SMSWT_Order_SMS', 'parse_mobiles' ) ) {
			return SMSWT_Order_SMS::parse_mobiles( (string) ( SMSWT_Order_SMS::settings()['admin_mobiles'] ?? '' ) );
		}
		return array();
	}

	public static function balance_reminder_text( string $name, int $amount ): string {
		$shop = (string) ( MDA_Settings::get( 'seller_name' ) ?: get_bloginfo( 'name' ) );
		return sprintf( '%1$s عزیز، مانده حساب شما نزد %2$s مبلغ %3$s %4$s است. لطفاً جهت تسویه اقدام فرمایید.', $name, $shop, number_format( $amount ), MDA_Settings::currency_label() );
	}

	public static function reminder_url( int $party_id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'mda_sms_balance_reminder', 'party_id' => $party_id ), admin_url( 'admin-post.php' ) ), 'mda_sms_balance_' . $party_id );
	}

	public static function handle_balance_reminder(): void {
		$party = absint( $_GET['party_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified next line.
		check_admin_referer( 'mda_sms_balance_' . $party );
		if ( ! current_user_can( MDA_Capabilities::CREATE_DOCUMENTS ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
		$back = admin_url( 'admin.php?page=mda-ledgers&tab=ar' );
		try {
			$aging = MDA_Ledger_Report_Service::aging( 'customer', current_time( 'Y-m-d' ) );
			$row   = null;
			foreach ( $aging['rows'] as $r ) {
				if ( (int) $r['party_id'] === $party ) {
					$row = $r;
				}
			}
			if ( ! $row || $row['total'] <= 0 ) {
				throw new RuntimeException( 'این طرف‌حساب بدهی باز ندارد.' );
			}
			$sent = self::send_sms( (string) $row['phone'], self::balance_reminder_text( (string) $row['name'], (int) $row['total'] ) );
			if ( is_wp_error( $sent ) ) {
				throw new RuntimeException( $sent->get_error_message() );
			}
			wp_safe_redirect( add_query_arg( 'mda_notice', rawurlencode( 'پیامک یادآوری برای ' . $row['name'] . ' ارسال شد.' ), $back ) );
		} catch ( Throwable $e ) {
			wp_safe_redirect( add_query_arg( 'mda_error', rawurlencode( $e->getMessage() ), $back ) );
		}
		exit;
	}

	/**
	 * Daily: cheques (received and issued) that are still open and due within the configured window → SMS to managers.
	 */
	public static function cheque_reminders(): void {
		$days = (int) MDA_Settings::get( 'cheque_reminder_days' );
		if ( $days < 1 || ! self::sms_available() || ! MDA_Database::is_available() ) {
			return;
		}
		$mobiles = self::manager_mobiles();
		if ( ! $mobiles ) {
			return;
		}
		try {
			$db = MDA_Database::connection();
			$q  = $db->prepare(
				"SELECT c.cheque_type, c.cheque_number, c.amount_minor, c.due_date, p.display_name,
					(SELECT e.status_after FROM mda_cheque_events e WHERE e.cheque_id = c.id ORDER BY e.id DESC LIMIT 1) AS status
				FROM mda_cheques c JOIN mda_parties p ON p.id = c.party_id
				WHERE c.due_date BETWEEN :from AND :to ORDER BY c.due_date"
			);
			$q->execute( array( ':from' => current_time( 'Y-m-d' ), ':to' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . $days . ' days' ) ) ) );
			$lines = array();
			foreach ( $q->fetchAll() as $c ) {
				if ( ! in_array( $c['status'], array( 'registered', 'deposited' ), true ) ) {
					continue;
				}
				$lines[] = ( 'received' === $c['cheque_type'] ? 'دریافتی' : 'پرداختی' ) . ' ' . $c['display_name'] . ' ' . number_format( (int) $c['amount_minor'] ) . ' — ' . MDA_Jalali::format( $c['due_date'] );
			}
			if ( ! $lines ) {
				return;
			}
			$text = 'سررسید چک‌ها (' . count( $lines ) . "):\n" . implode( "\n", array_slice( $lines, 0, 8 ) ) . ( count( $lines ) > 8 ? "\n…" : '' );
			foreach ( $mobiles as $mobile ) {
				self::send_sms( (string) $mobile, $text );
			}
		} catch ( Throwable $e ) {
			error_log( 'MaliDev Accounting cheque reminders: ' . $e->getMessage() ); // phpcs:ignore
		}
	}
}
