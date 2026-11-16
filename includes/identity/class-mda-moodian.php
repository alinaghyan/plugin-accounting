<?php
/**
 * سامانه مؤدیان (Iranian tax-authority e-invoicing) — foundation shared by MaliDev plugins.
 *
 * What lives here:
 *  - Taxpayer settings (memory id, economic code, …) in option `malidev_moodian_settings`.
 *  - The 22-character tax id (شماره منحصربه‌فرد مالیاتی) generator: memory id + hex(days) + hex(serial)
 *    + Verhoeff check digit, following the tax organisation's reference SDK.
 *  - Product «شناسه کالا/خدمت» (13 digits) on products and variations.
 *  - Order → invoice payload builder (header + body in the field names of the official API) and a
 *    per-order status record.
 *  - Sending is pluggable: `malidev_moodian_send` filter. The real API needs request signing with the
 *    taxpayer's private key; that key is NEVER stored in the database — it is read from the constant
 *    MALIDEV_MOODIAN_PRIVATE_KEY_FILE (path outside the web root) defined in wp-config.php.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Moodian {
	public const OPTION      = 'malidev_moodian_settings';
	public const SERIAL      = 'malidev_moodian_serial';
	public const META_SSTID  = '_malidev_moodian_sstid';
	public const META_STATUS = '_malidev_moodian';
	public const CAP         = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_field' ), 30, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'variation_save' ), 10, 2 );
		add_action( 'admin_post_mda_moodian_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_mda_moodian_payload', array( __CLASS__, 'handle_payload' ) );
		add_action( 'admin_post_mda_moodian_send', array( __CLASS__, 'handle_send' ) );
	}

	/* ------------------------------------------------------------------ settings */

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled'       => 0,
				'memory_id'     => '',   // شناسه یکتای حافظه مالیاتی (6 chars).
				'economic_code' => '',   // شماره اقتصادی فروشنده (tins).
				'branch'        => '',   // کد شعبه (bid) — optional.
				'invoice_type'  => 2,    // 1 = with buyer info, 2 = without (retail).
				'pattern'       => 1,    // الگوی صورتحساب: 1 = فروش.
				'vat_rate'      => 10,   // Default VAT % when the product has no tax data.
				'default_sstid' => '',   // Fallback شناسه کالا/خدمت.
				'unit_code'     => '164', // Default measurement unit (عدد).
				'currency_rial' => 1,    // Store amounts are in Toman (0) or Rial (1).
			)
		);
	}

	public static function private_key_configured(): bool {
		return defined( 'MALIDEV_MOODIAN_PRIVATE_KEY_FILE' ) && is_readable( (string) MALIDEV_MOODIAN_PRIVATE_KEY_FILE );
	}

	public static function ready(): bool {
		$s = self::settings();
		return ! empty( $s['enabled'] ) && 6 === strlen( (string) $s['memory_id'] ) && '' !== (string) $s['economic_code'];
	}

	/* ------------------------------------------------------------------ tax id */

	/** Verhoeff check digit of a decimal string. */
	public static function verhoeff( string $digits ): int {
		static $d = array(
			array( 0, 1, 2, 3, 4, 5, 6, 7, 8, 9 ), array( 1, 2, 3, 4, 0, 6, 7, 8, 9, 5 ), array( 2, 3, 4, 0, 1, 7, 8, 9, 5, 6 ), array( 3, 4, 0, 1, 2, 8, 9, 5, 6, 7 ), array( 4, 0, 1, 2, 3, 9, 5, 6, 7, 8 ),
			array( 5, 9, 8, 7, 6, 0, 4, 3, 2, 1 ), array( 6, 5, 9, 8, 7, 1, 0, 4, 3, 2 ), array( 7, 6, 5, 9, 8, 2, 1, 0, 4, 3 ), array( 8, 7, 6, 5, 9, 3, 2, 1, 0, 4 ), array( 9, 8, 7, 6, 5, 4, 3, 2, 1, 0 ),
		);
		static $p = array(
			array( 0, 1, 2, 3, 4, 5, 6, 7, 8, 9 ), array( 1, 5, 7, 6, 2, 8, 3, 0, 9, 4 ), array( 5, 8, 0, 3, 7, 9, 6, 1, 4, 2 ), array( 8, 9, 1, 6, 0, 4, 3, 5, 2, 7 ),
			array( 9, 4, 5, 3, 1, 2, 6, 8, 7, 0 ), array( 4, 2, 8, 6, 5, 7, 3, 9, 0, 1 ), array( 2, 7, 9, 3, 8, 0, 6, 4, 1, 5 ), array( 7, 0, 4, 6, 9, 1, 3, 2, 5, 8 ),
		);
		static $inv = array( 0, 4, 3, 2, 1, 5, 6, 7, 8, 9 );
		$c   = 0;
		$rev = strrev( $digits );
		$len = strlen( $rev );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $d[ $c ][ $p[ ( $i + 1 ) % 8 ][ (int) $rev[ $i ] ] ];
		}
		return $inv[ $c ];
	}

	/** True when the last digit is a valid Verhoeff check digit for the rest. */
	public static function verhoeff_valid( string $digits ): bool {
		return '' !== $digits && ctype_digit( $digits ) && self::verhoeff( substr( $digits, 0, -1 ) ) === (int) substr( $digits, -1 );
	}

	/**
	 * 22-char tax id: memoryId(6) + hex(days since epoch, 5) + hex(serial, 10) + check digit.
	 * The check digit is Verhoeff over: memoryId with letters replaced by their ASCII codes,
	 * days zero-padded to 6 and serial zero-padded to 12.
	 */
	public static function tax_id( string $memory_id, int $serial, int $timestamp ): string {
		$memory_id = strtoupper( trim( $memory_id ) );
		if ( ! preg_match( '/^[A-Z0-9]{6}$/', $memory_id ) || $serial < 0 || $serial > 0xFFFFFFFFFF ) {
			throw new InvalidArgumentException( 'شناسه حافظه مالیاتی یا سریال صورتحساب معتبر نیست.' );
		}
		$days    = intdiv( $timestamp, DAY_IN_SECONDS );
		$decimal = '';
		foreach ( str_split( $memory_id ) as $ch ) {
			$decimal .= ctype_digit( $ch ) ? $ch : (string) ord( $ch );
		}
		$decimal .= str_pad( (string) $days, 6, '0', STR_PAD_LEFT ) . str_pad( (string) $serial, 12, '0', STR_PAD_LEFT );
		return strtoupper( $memory_id . str_pad( dechex( $days ), 5, '0', STR_PAD_LEFT ) . str_pad( dechex( $serial ), 10, '0', STR_PAD_LEFT ) ) . self::verhoeff( $decimal );
	}

	/** Next invoice serial (atomic under concurrent requests through the options table row lock). */
	public static function next_serial(): int {
		global $wpdb;
		add_option( self::SERIAL, 0, '', false );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic increment (MySQL); cache cleared below.
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s", self::SERIAL ) );
		$n  = false !== $ok ? (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppress );
		wp_cache_delete( self::SERIAL, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( $n < 1 ) { // SQLite or other drivers without LAST_INSERT_ID(expr).
			$n = (int) get_option( self::SERIAL, 0 ) + 1;
			update_option( self::SERIAL, $n, false );
		}
		return $n;
	}

	/* ------------------------------------------------------------------ product field */

	public static function clean_sstid( string $v ): string {
		return substr( (string) preg_replace( '/\D/', '', MDA_Jalali::latin_digits( $v ) ), 0, 13 );
	}

	public static function sstid( WC_Product $p ): string {
		$v = (string) $p->get_meta( self::META_SSTID );
		if ( '' === $v && $p->is_type( 'variation' ) ) {
			$parent = wc_get_product( $p->get_parent_id() );
			$v      = $parent ? (string) $parent->get_meta( self::META_SSTID ) : '';
		}
		return '' !== $v ? $v : (string) self::settings()['default_sstid'];
	}

	public static function product_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_SSTID,
				'label'             => 'شناسه کالا/خدمت (مؤدیان)',
				'desc_tip'          => true,
				'description'       => 'شناسه ۱۳ رقمی کالا یا خدمت در سامانه مؤدیان. خالی = شناسه پیش‌فرض تنظیمات.',
				'custom_attributes' => array( 'inputmode' => 'numeric', 'maxlength' => '13', 'dir' => 'ltr' ),
			)
		);
	}

	public static function product_save( WC_Product $p ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product form nonce.
		if ( isset( $_POST[ self::META_SSTID ] ) ) {
			$p->update_meta_data( self::META_SSTID, self::clean_sstid( sanitize_text_field( wp_unslash( $_POST[ self::META_SSTID ] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	public static function variation_field( $loop, $data, $variation ): void {
		$v = wc_get_product( $variation->ID );
		woocommerce_wp_text_input(
			array(
				'id'            => self::META_SSTID . '_' . $loop,
				'name'          => self::META_SSTID . '[' . $loop . ']',
				'value'         => $v ? (string) $v->get_meta( self::META_SSTID ) : '',
				'label'         => 'شناسه کالا/خدمت (مؤدیان)',
				'wrapper_class' => 'form-row form-row-full',
				'custom_attributes' => array( 'inputmode' => 'numeric', 'maxlength' => '13', 'dir' => 'ltr' ),
			)
		);
	}

	public static function variation_save( WC_Product $v, int $loop ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the variations nonce.
		if ( isset( $_POST[ self::META_SSTID ][ $loop ] ) ) {
			$v->update_meta_data( self::META_SSTID, self::clean_sstid( sanitize_text_field( wp_unslash( $_POST[ self::META_SSTID ][ $loop ] ) ) ) );
		}
		// phpcs:enable
	}

	/* ------------------------------------------------------------------ payload */

	/** Amounts in Rial, integers. */
	private static function rial( float $amount ): int {
		return (int) round( $amount * ( empty( self::settings()['currency_rial'] ) ? 10 : 1 ) );
	}

	/**
	 * Invoice in the official JSON field names. Tax id/serial are assigned once per order and kept.
	 *
	 * @return array{header:array,body:array,payments:array}
	 */
	public static function payload( WC_Order $order ): array {
		$s      = self::settings();
		$state  = self::status( $order );
		$serial = (int) ( $state['serial'] ?? 0 );
		$time   = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		if ( ! $serial ) {
			$serial = self::next_serial();
			self::set_status( $order, array( 'status' => 'draft', 'serial' => $serial, 'tax_id' => self::tax_id( (string) $s['memory_id'], $serial, $time ) ) );
			$state = self::status( $order );
		}
		$identity = function_exists( 'malidev_identity_for_order' ) ? malidev_identity_for_order( $order ) : array( 'code' => '' );
		$type     = 1 === (int) $s['invoice_type'] && '' !== $identity['code'] ? 1 : 2;
		$body     = array();
		$sum      = array( 'tprdis' => 0, 'tdis' => 0, 'tadis' => 0, 'tvam' => 0, 'todam' => 0, 'tbill' => 0 );
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$qty     = max( 1, (float) $item->get_quantity() );
			$gross   = self::rial( (float) $item->get_subtotal() );
			$net     = self::rial( (float) $item->get_total() );
			$tax     = self::rial( (float) $item->get_total_tax() );
			$dis     = max( 0, $gross - $net );
			$vra     = $net > 0 && $tax > 0 ? round( $tax / $net * 100, 2 ) : ( $tax > 0 ? 0 : (float) $s['vat_rate'] );
			$vam     = $tax > 0 ? $tax : (int) round( $net * $vra / 100 );
			$row     = array(
				'sstid'  => $product ? self::sstid( $product ) : (string) $s['default_sstid'],
				'sstt'   => mb_substr( $item->get_name(), 0, 400 ),
				'mu'     => (string) $s['unit_code'],
				'am'     => $qty,
				'fee'    => (int) round( $gross / $qty ),
				'prdis'  => $gross,
				'dis'    => $dis,
				'adis'   => $net,
				'vra'    => $vra,
				'vam'    => $vam,
				'tsstam' => $net + $vam,
			);
			$body[]          = $row;
			$sum['tprdis']  += $gross;
			$sum['tdis']    += $dis;
			$sum['tadis']   += $net;
			$sum['tvam']    += $vam;
			$sum['tbill']   += $net + $vam;
		}
		$header = array(
			'taxid'   => (string) $state['tax_id'],
			'inno'    => str_pad( dechex( $serial ), 10, '0', STR_PAD_LEFT ),
			'indatim' => $time * 1000,
			'inty'    => $type,
			'inp'     => (int) $s['pattern'],
			'ins'     => 1, // اصلی.
			'tins'    => (string) $s['economic_code'],
			'tob'     => 1 === $type ? 1 : null,
			'bid'     => 1 === $type ? $identity['code'] : null,
			'bpc'     => 1 === $type ? (string) preg_replace( '/\D/', '', $order->get_billing_postcode() ) : null,
			'sbc'     => '' !== (string) $s['branch'] ? (string) $s['branch'] : null,
			'setm'    => 1, // نقد.
			'cap'     => $sum['tbill'],
		) + $sum;
		$payload = array( 'header' => array_filter( $header, static fn( $v ) => null !== $v ), 'body' => $body, 'payments' => array() );
		return (array) apply_filters( 'malidev_moodian_payload', $payload, $order );
	}

	/* ------------------------------------------------------------------ status & send */

	public static function status( WC_Order $order ): array {
		$v = $order->get_meta( self::META_STATUS, true );
		return is_array( $v ) ? $v : array();
	}

	public static function set_status( WC_Order $order, array $changes ): void {
		$order->update_meta_data( self::META_STATUS, array_merge( self::status( $order ), $changes, array( 'updated_at' => gmdate( 'c' ) ) ) );
		$order->save_meta_data();
	}

	/**
	 * Hands the invoice to the configured sender. A sender (gateway plugin or a custom integration)
	 * hooks `malidev_moodian_send` and returns array{reference:string} or WP_Error.
	 */
	public static function send( WC_Order $order ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'mda_moodian_not_ready', 'تنظیمات مؤدیان کامل نیست (شناسه حافظه و شماره اقتصادی).' );
		}
		$payload = self::payload( $order );
		$result  = apply_filters( 'malidev_moodian_send', new WP_Error( 'mda_moodian_no_sender', 'ارسال‌کننده‌ای برای سامانه مؤدیان پیکربندی نشده است.' ), $payload, $order, self::settings() );
		if ( is_wp_error( $result ) ) {
			self::set_status( $order, array( 'status' => 'failed', 'error' => $result->get_error_message() ) );
			return $result;
		}
		self::set_status( $order, array( 'status' => 'sent', 'reference' => sanitize_text_field( (string) ( $result['reference'] ?? '' ) ), 'error' => '' ) );
		$order->add_order_note( 'صورتحساب به سامانه مؤدیان ارسال شد. شماره مالیاتی: ' . ( self::status( $order )['tax_id'] ?? '' ) );
		return $result;
	}

	/* ------------------------------------------------------------------ admin */

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html( 'دسترسی کافی ندارید.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function back( string $msg, bool $ok ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => 'mda-identity', 'tab' => 'moodian', $ok ? 'mda_notice' : 'mda_error' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_save(): void {
		self::guard( 'mda_moodian_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in = isset( $_POST['moodian'] ) && is_array( $_POST['moodian'] ) ? wp_unslash( $_POST['moodian'] ) : array();
		// phpcs:enable
		$s                  = self::settings();
		$s['enabled']       = empty( $in['enabled'] ) ? 0 : 1;
		$s['memory_id']     = strtoupper( substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['memory_id'] ?? '' ) ), 0, 6 ) );
		$s['economic_code'] = substr( (string) preg_replace( '/\D/', '', MDA_Jalali::latin_digits( (string) ( $in['economic_code'] ?? '' ) ) ), 0, 14 );
		$s['branch']        = substr( (string) preg_replace( '/\D/', '', (string) ( $in['branch'] ?? '' ) ), 0, 10 );
		$s['invoice_type']  = 1 === (int) ( $in['invoice_type'] ?? 2 ) ? 1 : 2;
		$s['vat_rate']      = max( 0, min( 100, (float) ( $in['vat_rate'] ?? 10 ) ) );
		$s['default_sstid'] = self::clean_sstid( (string) ( $in['default_sstid'] ?? '' ) );
		$s['unit_code']     = substr( (string) preg_replace( '/\D/', '', (string) ( $in['unit_code'] ?? '164' ) ), 0, 8 ) ?: '164';
		$s['currency_rial'] = empty( $in['currency_rial'] ) ? 0 : 1;
		update_option( self::OPTION, $s, false );
		$warn = '' !== $s['memory_id'] && 6 !== strlen( $s['memory_id'] ) ? ' شناسه حافظه باید ۶ کاراکتر باشد.' : '';
		self::back( 'تنظیمات مؤدیان ذخیره شد.' . $warn, '' === $warn );
	}

	private static function order_from_request(): ?WC_Order {
		$order = wc_get_order( absint( $_REQUEST['order'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- caller verified nonce.
		return $order instanceof WC_Order ? $order : null;
	}

	public static function handle_payload(): void {
		self::guard( 'mda_moodian_payload' );
		$order = self::order_from_request();
		if ( ! $order ) {
			self::back( 'سفارش پیدا نشد.', false );
		}
		if ( ! self::ready() ) {
			self::back( 'ابتدا تنظیمات مؤدیان را کامل کنید.', false );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="moodian-' . (int) $order->get_id() . '.json"' );
		echo wp_json_encode( self::payload( $order ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
		exit;
	}

	public static function handle_send(): void {
		self::guard( 'mda_moodian_send' );
		$order = self::order_from_request();
		if ( ! $order ) {
			self::back( 'سفارش پیدا نشد.', false );
		}
		$r = self::send( $order );
		self::back( is_wp_error( $r ) ? $r->get_error_message() : 'صورتحساب سفارش #' . $order->get_order_number() . ' ارسال شد.', ! is_wp_error( $r ) );
	}

	public static function render_admin(): void {
		$s     = self::settings();
		$ready = self::ready();
		echo '<div class="mda-card"><h2>تنظیمات سامانه مؤدیان</h2><p class="description">این اطلاعات بین افزونه‌های MaliDev مشترک است (حسابداری، استودیو فاکتور). کلید خصوصی امضا در پایگاه داده ذخیره نمی‌شود: مسیر فایل کلید را با ثابت <code dir="ltr">MALIDEV_MOODIAN_PRIVATE_KEY_FILE</code> در <code>wp-config.php</code> و خارج از پوشه عمومی سایت تعریف کنید.</p>';
		echo '<p>' . ( self::private_key_configured() ? '<span class="mda-pill mda-pill--ok">کلید خصوصی پیکربندی شده است</span>' : '<span class="mda-pill mda-pill--warn">کلید خصوصی تعریف نشده</span>' ) . ' ' . ( has_filter( 'malidev_moodian_send' ) ? '<span class="mda-pill mda-pill--ok">ارسال‌کننده فعال است</span>' : '<span class="mda-pill mda-pill--warn">ارسال‌کننده‌ای نصب نیست؛ فعلاً خروجی JSON</span>' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mda_moodian_save' );
		echo '<input type="hidden" name="action" value="mda_moodian_save"><table class="form-table" role="presentation">';
		echo '<tr><th>فعال‌سازی</th><td><label><input type="checkbox" name="moodian[enabled]" value="1"' . checked( ! empty( $s['enabled'] ), true, false ) . '> صدور شماره مالیاتی و آماده‌سازی صورتحساب برای سفارش‌ها</label></td></tr>';
		echo '<tr><th>شناسه یکتای حافظه مالیاتی</th><td><input name="moodian[memory_id]" dir="ltr" maxlength="6" value="' . esc_attr( $s['memory_id'] ) . '"><p class="description">۶ کاراکتر؛ از کارپوشه سامانه مؤدیان.</p></td></tr>';
		echo '<tr><th>شماره اقتصادی فروشنده</th><td><input name="moodian[economic_code]" dir="ltr" maxlength="14" value="' . esc_attr( $s['economic_code'] ) . '"></td></tr>';
		echo '<tr><th>کد شعبه</th><td><input name="moodian[branch]" dir="ltr" maxlength="10" value="' . esc_attr( $s['branch'] ) . '"></td></tr>';
		echo '<tr><th>نوع صورتحساب</th><td><select name="moodian[invoice_type]"><option value="2"' . selected( 2, (int) $s['invoice_type'], false ) . '>نوع دوم (بدون اطلاعات خریدار)</option><option value="1"' . selected( 1, (int) $s['invoice_type'], false ) . '>نوع اول (با کد ملی خریدار، از شاهکار/فاکتور)</option></select></td></tr>';
		echo '<tr><th>نرخ ارزش افزوده پیش‌فرض (٪)</th><td><input type="number" step="0.01" min="0" max="100" name="moodian[vat_rate]" value="' . esc_attr( (string) $s['vat_rate'] ) . '"></td></tr>';
		echo '<tr><th>شناسه کالا/خدمت پیش‌فرض</th><td><input name="moodian[default_sstid]" dir="ltr" maxlength="13" value="' . esc_attr( $s['default_sstid'] ) . '"><p class="description">برای محصولاتی که شناسه اختصاصی ندارند (در صفحه ویرایش محصول قابل تعیین است).</p></td></tr>';
		echo '<tr><th>کد واحد اندازه‌گیری</th><td><input name="moodian[unit_code]" dir="ltr" maxlength="8" value="' . esc_attr( $s['unit_code'] ) . '"></td></tr>';
		echo '<tr><th>واحد مبالغ فروشگاه</th><td><label><input type="checkbox" name="moodian[currency_rial]" value="1"' . checked( ! empty( $s['currency_rial'] ), true, false ) . '> ریال است (اگر خاموش باشد تومان فرض و ×۱۰ می‌شود)</label></td></tr>';
		echo '</table>';
		submit_button( 'ذخیره تنظیمات مؤدیان' );
		echo '</form></div>';

		if ( ! $ready ) {
			return;
		}
		$orders = wc_get_orders( array( 'status' => array( 'wc-completed', 'wc-processing' ), 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );
		echo '<div class="mda-card"><h2>صورتحساب‌های اخیر</h2><table class="widefat striped"><thead><tr><th>سفارش</th><th>تاریخ</th><th>مبلغ</th><th>شماره مالیاتی</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
		$labels = array( 'draft' => 'آماده', 'sent' => 'ارسال شده', 'failed' => 'خطا' );
		foreach ( $orders as $o ) {
			$st = self::status( $o );
			echo '<tr><td>#' . esc_html( $o->get_order_number() ) . '</td><td>' . esc_html( MDA_Jalali::format( $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : '' ) ) . '</td><td>' . wp_kses_post( wc_price( $o->get_total() ) ) . '</td><td dir="ltr"><code>' . esc_html( $st['tax_id'] ?? '—' ) . '</code></td><td>' . esc_html( $labels[ $st['status'] ?? '' ] ?? 'صادر نشده' ) . ( ! empty( $st['error'] ) ? '<br><small class="mda-text-danger">' . esc_html( $st['error'] ) . '</small>' : '' ) . '</td><td>';
			echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mda_moodian_payload&order=' . (int) $o->get_id() ), 'mda_moodian_payload' ) ) . '">JSON</a> ';
			if ( 'sent' !== ( $st['status'] ?? '' ) ) {
				echo '<a class="button button-small button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mda_moodian_send&order=' . (int) $o->get_id() ), 'mda_moodian_send' ) ) . '">ارسال</a>';
			}
			echo '</td></tr>';
		}
		if ( ! $orders ) {
			echo '<tr><td colspan="6">سفارشی نیست.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
