<?php
/**
 * Shahkar (national code ↔ mobile owner) verification — shared MaliDev identity service.
 *
 * Port of the SMS plugin's SMSWT_Shahkar using the SAME option, order meta and user meta keys, so
 * the invoice plugin («استودیو فاکتور») and the SMS plugin read one source of truth. When the SMS
 * plugin is active its own class keeps owning the checkout hooks and this class only provides the
 * API/settings screen; otherwise this class registers the checkout field itself.
 *
 * - Field stored in the user meta key shared with Otino (otino_national_code by default),
 *   so existing customer data keeps working.
 * - Classic checkout and Checkout block are both supported.
 * - The API key lives in the settings screen; it is never printed back or logged.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Shahkar {
	public const OPTION       = 'smswt_shahkar_settings';
	public const ENDPOINT     = 'https://s.api.ir/api/sw1/Shahkar';
	public const FIELD        = 'billing_national_code';
	public const BLOCK_FIELD  = 'smswt/national-code';
	public const ORDER_META   = '_smswt_national_code';
	public const ORDER_STATUS = '_smswt_shahkar';
	public const USER_OK_META = 'smswt_shahkar_ok';

	private const MATCH_TTL      = 30 * DAY_IN_SECONDS;
	private const MISMATCH_TTL   = HOUR_IN_SECONDS;
	private const RATE_LIMIT     = 5;
	private const RATE_WINDOW    = 10 * MINUTE_IN_SECONDS;
	private const CAP            = 'manage_woocommerce';

	/** Result of the check done during validation, reused when the order is saved. */
	private static ?array $verified = null;

	/** True when the SMS plugin owns the checkout hooks (its class is loaded). */
	public static function external(): bool {
		return class_exists( 'SMSWT_Shahkar' );
	}

	public static function init(): void {
		add_action( 'admin_post_mda_shahkar_save', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_mda_shahkar_test', array( self::class, 'handle_test' ) );
		if ( self::external() ) {
			return;
		}
		add_filter( 'woocommerce_checkout_fields', array( self::class, 'add_classic_field' ), 30 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate_classic' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'save_classic' ), 20, 2 );
		add_action( 'woocommerce_init', array( self::class, 'register_block_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'validate_block' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( self::class, 'render_admin_order' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled'   => 0,       // Shahkar verification.
				'api_key'   => '',
				'field'     => 0,       // Show national code field (forced on when Shahkar is enabled).
				'required'  => 0,       // Field required without Shahkar (always required with Shahkar).
				'meta_key'  => 'otino_national_code',
				'on_error'  => 'block', // block|allow when the service is unreachable.
			)
		);
	}

	private static function field_active( array $s ): bool {
		return ! empty( $s['field'] ) || self::active_for( $s );
	}

	public static function active_for( array $s ): bool {
		return ! empty( $s['enabled'] ) && '' !== (string) $s['api_key'];
	}

	public static function active(): bool {
		return self::active_for( self::settings() );
	}

	private static function field_required( array $s ): bool {
		return self::active_for( $s ) || ! empty( $s['required'] );
	}

	private static function meta_key( array $s ): string {
		$key = sanitize_key( (string) $s['meta_key'] );
		return '' !== $key ? $key : 'otino_national_code';
	}

	/* ------------------------------------------------------------------ *
	 * Pure helpers (public for tests)
	 * ------------------------------------------------------------------ */

	public static function normalize_digits( string $value ): string {
		return strtr(
			$value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	public static function normalize_code( string $code ): string {
		return (string) preg_replace( '/\D/', '', self::normalize_digits( $code ) );
	}

	/** Iranian national code checksum. */
	public static function is_valid_code( string $code ): bool {
		if ( ! preg_match( '/^\d{10}$/', $code ) || preg_match( '/^(\d)\1{9}$/', $code ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $code[ $i ] * ( 10 - $i );
		}
		$r = $sum % 11;
		$check = (int) $code[9];
		return $r < 2 ? $check === $r : $check === 11 - $r;
	}

	public static function normalize_mobile( string $phone ): string {
		$phone = (string) preg_replace( '/\D/', '', self::normalize_digits( $phone ) );
		if ( str_starts_with( $phone, '0098' ) ) {
			$phone = '0' . substr( $phone, 4 );
		} elseif ( str_starts_with( $phone, '98' ) && 12 === strlen( $phone ) ) {
			$phone = '0' . substr( $phone, 2 );
		} elseif ( str_starts_with( $phone, '9' ) && 10 === strlen( $phone ) ) {
			$phone = '0' . $phone;
		}
		return preg_match( '/^09\d{9}$/', $phone ) ? $phone : '';
	}

	/**
	 * Map an api.ir response to match / mismatch / error. Only an explicit
	 * success=true + data=false counts as a mismatch; anything unclear is an error.
	 */
	public static function interpret( int $http, $body ): string {
		if ( 200 !== $http || ! is_array( $body ) || true !== ( $body['success'] ?? null ) ) {
			return 'error';
		}
		if ( true === ( $body['data'] ?? null ) ) {
			return 'match';
		}
		return false === ( $body['data'] ?? null ) ? 'mismatch' : 'error';
	}

	/* ------------------------------------------------------------------ *
	 * Verification
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{status:string,message:string,code:string,mobile:string}
	 *         status: ok (no Shahkar needed) | match | mismatch | error | invalid
	 */
	public static function verify( string $raw_code, string $raw_mobile, int $user_id = 0 ): array {
		$s      = self::settings();
		$code   = self::normalize_code( $raw_code );
		$mobile = self::normalize_mobile( $raw_mobile );
		$out    = array( 'status' => 'ok', 'message' => '', 'code' => $code, 'mobile' => $mobile );

		if ( '' === $code ) {
			if ( self::field_required( $s ) ) {
				return array( 'status' => 'invalid', 'message' => 'لطفاً کد ملی را وارد کنید.' ) + $out;
			}
			return $out;
		}
		if ( ! self::is_valid_code( $code ) ) {
			return array( 'status' => 'invalid', 'message' => 'کد ملی وارد شده معتبر نیست.' ) + $out;
		}
		if ( ! self::active_for( $s ) ) {
			return $out;
		}
		if ( '' === $mobile ) {
			return array( 'status' => 'invalid', 'message' => 'برای تطبیق کد ملی، شماره موبایل معتبر لازم است.' ) + $out;
		}

		$hash = hash_hmac( 'sha256', $code . '|' . $mobile, wp_salt( 'auth' ) );
		if ( $user_id && hash_equals( (string) get_user_meta( $user_id, self::USER_OK_META, true ), $hash ) ) {
			return array( 'status' => 'match' ) + $out;
		}
		$cache_key = 'smswt_shk_' . substr( $hash, 0, 40 );
		$cached = get_transient( $cache_key );
		if ( 'match' === $cached || 'mismatch' === $cached ) {
			$result = $cached;
		} else {
			if ( ! self::within_rate_limit() ) {
				return array( 'status' => 'invalid', 'message' => 'تعداد درخواست‌های تطبیق کد ملی زیاد است؛ چند دقیقه دیگر دوباره تلاش کنید.' ) + $out;
			}
			$result = self::call_api( (string) $s['api_key'], $code, $mobile );
			if ( 'match' === $result ) {
				set_transient( $cache_key, 'match', self::MATCH_TTL );
			} elseif ( 'mismatch' === $result ) {
				set_transient( $cache_key, 'mismatch', self::MISMATCH_TTL );
			}
		}

		if ( 'match' === $result ) {
			if ( $user_id ) {
				update_user_meta( $user_id, self::USER_OK_META, $hash );
			}
			return array( 'status' => 'match' ) + $out;
		}
		if ( 'mismatch' === $result ) {
			return array(
				'status'  => 'mismatch',
				'message' => 'این شماره موبایل به نام صاحب این کد ملی ثبت نشده است. شماره موبایلی را وارد کنید که به نام خودتان است.',
			) + $out;
		}
		if ( 'allow' === $s['on_error'] ) {
			return array( 'status' => 'error' ) + $out; // Order goes through, flagged for manual review.
		}
		return array( 'status' => 'invalid', 'message' => 'سرویس تطبیق کد ملی در دسترس نیست؛ لطفاً چند دقیقه دیگر دوباره تلاش کنید.' ) + $out;
	}

	private static function call_api( string $key, string $code, string $mobile ): string {
		$response = wp_safe_remote_post(
			self::ENDPOINT,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'      => (string) wp_json_encode(
					array(
						'nationalCode' => $code,
						'mobile'       => $mobile,
						'isCompany'    => false,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return 'error';
		}
		return self::interpret( (int) wp_remote_retrieve_response_code( $response ), json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
	}

	private static function within_rate_limit(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'smswt_shk_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $hits + 1, self::RATE_WINDOW );
		return true;
	}

	private static function stored_code( int $user_id, array $s ): string {
		return $user_id ? self::normalize_code( (string) get_user_meta( $user_id, self::meta_key( $s ), true ) ) : '';
	}

	private static function user_mobile( int $user_id ): string {
		if ( ! $user_id ) {
			return '';
		}
		foreach ( array( 'billing_phone', 'phone', 'mobile_number' ) as $key ) {
			$mobile = self::normalize_mobile( (string) get_user_meta( $user_id, $key, true ) );
			if ( '' !== $mobile ) {
				return $mobile;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Classic checkout
	 * ------------------------------------------------------------------ */

	public static function add_classic_field( $fields ) {
		$s = self::settings();
		if ( ! self::field_active( $s ) || ! is_array( $fields ) ) {
			return $fields;
		}
		$priority = (int) ( $fields['billing']['billing_phone']['priority'] ?? 100 ) + 1;
		$fields['billing'][ self::FIELD ] = array(
			'type'              => 'text',
			'label'             => 'کد ملی',
			'required'          => self::field_required( $s ),
			'class'             => array( 'form-row-wide' ),
			'priority'          => $priority,
			'default'           => self::stored_code( get_current_user_id(), $s ),
			'custom_attributes' => array(
				'inputmode'    => 'numeric',
				'maxlength'    => '10',
				'autocomplete' => 'off',
				'dir'          => 'ltr',
			),
			'description'       => self::active_for( $s ) ? 'کد ملی صاحب همین شماره موبایل؛ از طریق سامانه شاهکار تطبیق داده می‌شود.' : '',
		);
		return $fields;
	}

	public static function validate_classic( $data, $errors ): void {
		$s = self::settings();
		if ( ! self::field_active( $s ) || ! $errors instanceof WP_Error || $errors->has_errors() ) {
			return; // Don't spend an API call while other fields are still wrong.
		}
		$code   = is_array( $data ) ? (string) ( $data[ self::FIELD ] ?? '' ) : '';
		$mobile = is_array( $data ) ? (string) ( $data['billing_phone'] ?? '' ) : '';
		if ( '' === self::normalize_mobile( $mobile ) ) {
			$mobile = self::user_mobile( get_current_user_id() );
		}
		$result = self::verify( $code, $mobile, get_current_user_id() );
		if ( in_array( $result['status'], array( 'invalid', 'mismatch' ), true ) ) {
			$errors->add( 'mda_national_code', $result['message'] );
			return;
		}
		self::$verified = $result;
	}

	public static function save_classic( $order, $data ): void {
		if ( ! $order instanceof WC_Order || null === self::$verified ) {
			return;
		}
		self::store_on_order( $order, self::$verified );
	}

	/* ------------------------------------------------------------------ *
	 * Checkout block
	 * ------------------------------------------------------------------ */

	public static function register_block_field(): void {
		$s = self::settings();
		if ( ! self::field_active( $s ) || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'         => self::BLOCK_FIELD,
				'label'      => 'کد ملی',
				'location'   => 'contact',
				'type'       => 'text',
				'required'   => self::field_required( $s ),
				'attributes' => array(
					'autocomplete' => 'off',
					'maxLength'    => 10,
					'inputMode'    => 'numeric',
				),
				'sanitize_callback' => array( self::class, 'normalize_code' ),
				'validate_callback' => static function ( $value ) {
					$value = self::normalize_code( (string) $value );
					return '' === $value || self::is_valid_code( $value ) ? null : new WP_Error( 'mda_national_code', 'کد ملی وارد شده معتبر نیست.' );
				},
			)
		);
	}

	public static function validate_block( $order, $request ): void {
		$s = self::settings();
		if ( ! self::field_active( $s ) || ! $order instanceof WC_Order ) {
			return;
		}
		$fields = is_object( $request ) && method_exists( $request, 'get_param' ) ? (array) $request->get_param( 'additional_fields' ) : array();
		$code   = (string) ( $fields[ self::BLOCK_FIELD ] ?? $order->get_meta( '_wc_other/' . self::BLOCK_FIELD ) );
		$mobile = $order->get_billing_phone();
		if ( '' === self::normalize_mobile( $mobile ) ) {
			$mobile = self::user_mobile( (int) $order->get_customer_id() );
		}
		$result = self::verify( $code, $mobile, (int) $order->get_customer_id() );
		if ( in_array( $result['status'], array( 'invalid', 'mismatch' ), true ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'mda_national_code', esc_html( $result['message'] ), 400 );
		}
		self::store_on_order( $order, $result );
	}

	/* ------------------------------------------------------------------ *
	 * Persistence & display
	 * ------------------------------------------------------------------ */

	private static function store_on_order( WC_Order $order, array $result ): void {
		if ( '' === $result['code'] ) {
			return;
		}
		$s = self::settings();
		$order->update_meta_data( self::ORDER_META, $result['code'] );
		if ( 'match' === $result['status'] ) {
			$order->update_meta_data( self::ORDER_STATUS, 'match' );
		} elseif ( 'error' === $result['status'] ) {
			$order->update_meta_data( self::ORDER_STATUS, 'unverified' );
			$order->add_order_note( 'تطبیق شاهکار انجام نشد (سرویس در دسترس نبود)؛ کد ملی و شماره موبایل را دستی بررسی کنید.' );
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::meta_key( $s ), $result['code'] );
		}
	}

	public static function render_admin_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$code = (string) $order->get_meta( self::ORDER_META );
		if ( '' === $code ) {
			return;
		}
		$status = (string) $order->get_meta( self::ORDER_STATUS );
		$labels = array(
			'match'      => 'تطبیق شاهکار: موفق',
			'unverified' => 'تطبیق شاهکار: انجام نشد — بررسی دستی',
		);
		echo '<p><strong>کد ملی:</strong> <span dir="ltr">' . esc_html( $code ) . '</span>';
		if ( isset( $labels[ $status ] ) ) {
			echo '<br><small>' . esc_html( $labels[ $status ] ) . '</small>';
		}
		echo '</p>';
	}

	/* ------------------------------------------------------------------ *
	 * Admin tab
	 * ------------------------------------------------------------------ */

	public static function render_admin_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = self::settings();
		$notice = get_transient( 'mda_shahkar_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'mda_shahkar_notice_' . get_current_user_id() );
			printf( '<div class="notice inline %1$s"><p>%2$s</p></div>', ! empty( $notice['ok'] ) ? 'notice-success' : 'notice-error', esc_html( (string) $notice['message'] ) );
		}
		$has_key = '' !== (string) $s['api_key'];
		if ( self::external() ) {
			echo '<div class="notice notice-info inline"><p>افزونه پیامک و رهگیری فعال است و فیلد کد ملی صفحه پرداخت را همان افزونه مدیریت می‌کند. تنظیمات زیر مشترک است و در هر دو افزونه یکسان اعمال می‌شود.</p></div>';
		}
		?>
		<div class="mda-card">
			<h2>احراز هویت شاهکار و کد ملی</h2>
			<p class="wca-muted">شاهکار سامانه‌ی سازمان تنظیم مقررات و ارتباطات رادیویی است که بررسی می‌کند شماره موبایل به نام صاحب کد ملی ثبت شده باشد. اتصال از طریق وب‌سرویس api.ir انجام می‌شود: در <a href="https://p.api.ir" target="_blank" rel="noopener noreferrer">p.api.ir</a> ثبت‌نام کنید، سرویس «شاهکار» را فعال و شارژ کنید و توکن بخش «وب سرویس» را اینجا وارد کنید. هر استعلام هزینه دارد؛ نتیجه‌ی موفق ۳۰ روز ذخیره می‌شود تا استعلام تکراری انجام نشود.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mda_shahkar_save">
				<?php wp_nonce_field( 'mda_shahkar_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">فعالسازی احراز هویت شاهکار</th><td>
						<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> تکمیل سفارش فقط وقتی کد ملی با شماره موبایل مشتری تطبیق داشته باشد</label>
						<p class="description">پیش‌فرض خاموش است. با فعال شدن، فیلد کد ملی در فرم پرداخت نمایش داده می‌شود و الزامی است.</p>
					</td></tr>
					<tr><th scope="row"><label for="smswt-shahkar-key">کلید API شاهکار (api.ir)</label></th><td>
						<input id="smswt-shahkar-key" class="regular-text" type="password" name="api_key" autocomplete="new-password" dir="ltr" placeholder="<?php echo esc_attr( $has_key ? 'ذخیره شده — برای تغییر، کلید جدید را وارد کنید' : '' ); ?>">
						<?php if ( $has_key ) : ?>
							<label style="display:block;margin-top:.4rem"><input type="checkbox" name="clear_key" value="1"> حذف کلید ذخیره‌شده</label>
						<?php endif; ?>
						<p class="description">کلید فقط در پایگاه داده‌ی همین سایت ذخیره می‌شود و دوباره نمایش داده نمی‌شود.</p>
					</td></tr>
					<tr><th scope="row">افزودن کد ملی به فرم پرداخت ووکامرس</th><td>
						<label><input type="checkbox" name="field" value="1" <?php checked( ! empty( $s['field'] ) ); ?>> نمایش فیلد کد ملی بعد از شماره موبایل (بدون شاهکار، فقط درستی کد ملی بررسی می‌شود)</label><br>
						<label><input type="checkbox" name="required" value="1" <?php checked( ! empty( $s['required'] ) ); ?>> الزامی باشد</label>
					</td></tr>
					<tr><th scope="row"><label for="smswt-shahkar-meta">کلید متای کاربر</label></th><td>
						<input id="smswt-shahkar-meta" type="text" name="meta_key" dir="ltr" value="<?php echo esc_attr( self::meta_key( $s ) ); ?>">
						<p class="description">پیش‌فرض <code>otino_national_code</code> است تا کد ملی‌هایی که قبلاً ثبت شده‌اند (مثلاً با اوتینو) خوانده و پیش‌پر شوند.</p>
					</td></tr>
					<tr><th scope="row"><label for="smswt-shahkar-error">اگر سرویس در دسترس نبود</label></th><td>
						<select id="smswt-shahkar-error" name="on_error">
							<option value="block" <?php selected( $s['on_error'], 'block' ); ?>>سفارش ثبت نشود (امن‌تر)</option>
							<option value="allow" <?php selected( $s['on_error'], 'allow' ); ?>>سفارش ثبت شود و برای بررسی دستی علامت بخورد</option>
						</select>
					</td></tr>
				</table>
				<?php submit_button( 'ذخیره تنظیمات شاهکار' ); ?>
			</form>
		</div>
		<div class="mda-card">
			<h2>تست اتصال شاهکار</h2>
			<p class="wca-muted">یک استعلام واقعی انجام می‌شود و از اعتبار حساب api.ir کم می‌شود. اطلاعات واردشده ذخیره نمی‌شود.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="action" value="mda_shahkar_test">
				<?php wp_nonce_field( 'mda_shahkar_test' ); ?>
				<input type="text" name="code" inputmode="numeric" maxlength="10" dir="ltr" placeholder="کد ملی" required>
				<input type="text" name="mobile" inputmode="tel" maxlength="14" dir="ltr" placeholder="09xxxxxxxxx" required>
				<button type="submit" class="button" <?php disabled( ! $has_key ); ?>>استعلام آزمایشی</button>
			</form>
		</div>
		<?php
	}

	public static function handle_save(): void {
		self::guard( 'mda_shahkar_save' );
		$s = self::settings();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$s['enabled']  = empty( $_POST['enabled'] ) ? 0 : 1;
		$s['field']    = empty( $_POST['field'] ) ? 0 : 1;
		$s['required'] = empty( $_POST['required'] ) ? 0 : 1;
		$s['on_error'] = isset( $_POST['on_error'] ) && 'allow' === $_POST['on_error'] ? 'allow' : 'block';
		$meta          = isset( $_POST['meta_key'] ) ? sanitize_key( wp_unslash( $_POST['meta_key'] ) ) : '';
		$s['meta_key'] = '' !== $meta ? $meta : 'otino_national_code';
		$key           = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		if ( ! empty( $_POST['clear_key'] ) ) {
			$s['api_key'] = '';
		} elseif ( '' !== $key ) {
			$s['api_key'] = $key;
		}
		// phpcs:enable
		update_option( self::OPTION, $s, false );
		$message = 'تنظیمات شاهکار ذخیره شد.';
		if ( ! empty( $s['enabled'] ) && '' === $s['api_key'] ) {
			$message .= ' تا کلید API وارد نشود، تطبیق شاهکار انجام نمی‌شود.';
		}
		self::redirect( $message, true );
	}

	public static function handle_test(): void {
		self::guard( 'mda_shahkar_test' );
		$s = self::settings();
		if ( '' === (string) $s['api_key'] ) {
			self::redirect( 'ابتدا کلید API را ذخیره کنید.', false );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$code   = self::normalize_code( isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '' );
		$mobile = self::normalize_mobile( isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['mobile'] ) ) : '' );
		// phpcs:enable
		if ( ! self::is_valid_code( $code ) || '' === $mobile ) {
			self::redirect( 'کد ملی یا شماره موبایل معتبر نیست.', false );
		}
		$result = self::call_api( (string) $s['api_key'], $code, $mobile );
		$messages = array(
			'match'    => 'اتصال برقرار است: شماره موبایل به نام صاحب این کد ملی است.',
			'mismatch' => 'اتصال برقرار است: شماره موبایل به نام صاحب این کد ملی نیست.',
			'error'    => 'پاسخ معتبری از api.ir دریافت نشد؛ کلید، اعتبار حساب و فعال بودن سرویس شاهکار را بررسی کنید.',
		);
		self::redirect( $messages[ $result ], 'error' !== $result );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html( 'دسترسی کافی ندارید.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect( string $message, bool $ok ): void {
		set_transient( 'mda_shahkar_notice_' . get_current_user_id(), array( 'ok' => $ok, 'message' => $message ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=mda-identity' ) );
		exit;
	}
}
