<?php
/**
 * License client for malidev.ir.
 *
 * Contract with the license server (filterable base URL, JSON over HTTPS):
 *   POST {base}/activate   license_key, product, domain, site_url, version, core_version
 *   POST {base}/deactivate license_key, product, domain
 *   POST {base}/check      license_key, product, domain, version
 * Response: { "success": bool, "status": "active|expired|invalid|domain_mismatch|disabled", "expires_at": "Y-m-d"|null, "message": string }
 *
 * Network failures never lock a site out: the last "active" result stays valid for a 7-day grace period.
 * An invalid license disables updates and shows a notice; it never blocks access to the shop's own data.
 *
 * @package MaliDev_Core
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'MaliDev_Core_License' ) ) {
	return;
}

final class MaliDev_Core_License {
	private const GRACE_DAYS = 7;
	private const CRON       = 'malidev_core_license_check';

	public static function boot(): void {
		add_action( 'admin_post_malidev_license_activate', array( __CLASS__, 'handle_activate' ) );
		add_action( 'admin_post_malidev_license_deactivate', array( __CLASS__, 'handle_deactivate' ) );
		add_action( self::CRON, array( __CLASS__, 'check_all' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function server(): string {
		return untrailingslashit( (string) apply_filters( 'malidev_core_license_server', 'https://malidev.ir/wp-json/malidev-license/v1' ) );
	}

	private static function option( string $slug ): string {
		return 'malidev_license_' . sanitize_key( $slug );
	}

	public static function state( string $slug ): array {
		$state = get_option( self::option( $slug ), array() );
		$state = wp_parse_args(
			is_array( $state ) ? $state : array(),
			array( 'key' => '', 'status' => 'missing', 'expires_at' => null, 'checked_at' => 0, 'valid_until' => 0, 'message' => '' )
		);
		if ( 'active' === $state['status'] && $state['valid_until'] && time() > (int) $state['valid_until'] ) {
			$state['status'] = 'unverified';
		}
		return $state;
	}

	public static function is_active( string $slug ): bool {
		return 'active' === self::state( $slug )['status'];
	}

	public static function label( string $status ): string {
		$labels = array(
			'active'          => 'لایسنس فعال',
			'missing'         => 'لایسنس وارد نشده',
			'expired'         => 'لایسنس منقضی شده',
			'invalid'         => 'لایسنس نامعتبر',
			'domain_mismatch' => 'لایسنس برای دامنه دیگری صادر شده',
			'disabled'        => 'لایسنس غیرفعال شده',
			'unverified'      => 'عدم دسترسی به سرور لایسنس',
		);
		return $labels[ $status ] ?? $status;
	}

	public static function domain(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	private static function request( string $endpoint, array $body ): array {
		$response = wp_remote_post(
			self::server() . '/' . $endpoint,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'network_error' => $response->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array( 'network_error' => 'پاسخ سرور لایسنس قابل خواندن نیست (HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ').' );
		}
		return $data;
	}

	private static function apply( string $slug, string $key, array $data ): array {
		$state = self::state( $slug );
		$state['key'] = $key;
		if ( isset( $data['network_error'] ) ) {
			$state['message'] = (string) $data['network_error'];
			if ( 'active' !== $state['status'] ) {
				$state['status'] = 'unverified';
			}
		} else {
			$allowed           = array( 'active', 'expired', 'invalid', 'domain_mismatch', 'disabled' );
			$status            = sanitize_key( (string) ( $data['status'] ?? '' ) );
			$state['status']   = in_array( $status, $allowed, true ) ? $status : ( ! empty( $data['success'] ) ? 'active' : 'invalid' );
			$state['expires_at'] = isset( $data['expires_at'] ) ? sanitize_text_field( (string) $data['expires_at'] ) : null;
			$state['message']    = sanitize_text_field( (string) ( $data['message'] ?? '' ) );
			$state['checked_at'] = time();
			$state['valid_until'] = 'active' === $state['status'] ? time() + self::GRACE_DAYS * DAY_IN_SECONDS : 0;
		}
		update_option( self::option( $slug ), $state, false );
		return $state;
	}

	public static function activate( string $slug, string $key ): array {
		$product = MaliDev_Core::product( $slug );
		if ( ! $product ) {
			throw new InvalidArgumentException( 'محصول ناشناخته است.' );
		}
		$data = self::request(
			'activate',
			array(
				'license_key'  => $key,
				'product'      => $product['license_product'],
				'domain'       => self::domain(),
				'site_url'     => home_url(),
				'version'      => $product['version'],
				'core_version' => MaliDev_Core::VERSION,
			)
		);
		return self::apply( $slug, $key, $data );
	}

	public static function check( string $slug ): array {
		$state   = self::state( $slug );
		$product = MaliDev_Core::product( $slug );
		if ( ! $product || '' === $state['key'] ) {
			return $state;
		}
		$data = self::request(
			'check',
			array(
				'license_key' => $state['key'],
				'product'     => $product['license_product'],
				'domain'      => self::domain(),
				'version'     => $product['version'],
			)
		);
		return self::apply( $slug, $state['key'], $data );
	}

	public static function check_all(): void {
		foreach ( array_keys( MaliDev_Core::products() ) as $slug ) {
			self::check( $slug );
		}
	}

	public static function handle_activate(): void {
		$slug = isset( $_POST['product'] ) ? sanitize_key( wp_unslash( $_POST['product'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-core' ) );
		}
		check_admin_referer( 'malidev_license_' . $slug );
		$key = isset( $_POST['license_key'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) wp_unslash( $_POST['license_key'] ) ) : '';
		try {
			$state = self::activate( $slug, (string) $key );
			$msg   = self::label( $state['status'] ) . ( $state['message'] ? ' — ' . $state['message'] : '' );
		} catch ( Throwable $e ) {
			$msg = $e->getMessage();
		}
		wp_safe_redirect( add_query_arg( array( 'page' => MaliDev_Core::parent_slug(), 'malidev_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_deactivate(): void {
		$slug = isset( $_POST['product'] ) ? sanitize_key( wp_unslash( $_POST['product'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-core' ) );
		}
		check_admin_referer( 'malidev_license_' . $slug );
		$state   = self::state( $slug );
		$product = MaliDev_Core::product( $slug );
		if ( $product && $state['key'] ) {
			self::request( 'deactivate', array( 'license_key' => $state['key'], 'product' => $product['license_product'], 'domain' => self::domain() ) );
		}
		delete_option( self::option( $slug ) );
		wp_safe_redirect( add_query_arg( array( 'page' => MaliDev_Core::parent_slug(), 'malidev_msg' => rawurlencode( 'لایسنس از این سایت جدا شد.' ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_form( string $slug ): void {
		$state  = self::state( $slug );
		$masked = $state['key'] ? substr( $state['key'], 0, 4 ) . str_repeat( '•', 8 ) . substr( $state['key'], -4 ) : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="malidev-license-form">';
		wp_nonce_field( 'malidev_license_' . $slug );
		echo '<input type="hidden" name="product" value="' . esc_attr( $slug ) . '">';
		if ( $state['key'] && 'missing' !== $state['status'] ) {
			echo '<p><code dir="ltr">' . esc_html( $masked ) . '</code>';
			if ( $state['expires_at'] ) {
				echo ' — ' . esc_html__( 'اعتبار تا', 'malidev-core' ) . ' ' . esc_html( (string) $state['expires_at'] );
			}
			echo '</p><input type="hidden" name="action" value="malidev_license_deactivate"><button class="button">' . esc_html__( 'جدا کردن لایسنس از این سایت', 'malidev-core' ) . '</button>';
		} else {
			echo '<input type="hidden" name="action" value="malidev_license_activate">';
			echo '<input type="text" name="license_key" class="regular-text" dir="ltr" autocomplete="off" required placeholder="XXXX-XXXX-XXXX-XXXX"> ';
			echo '<button class="button button-primary">' . esc_html__( 'فعال‌سازی', 'malidev-core' ) . '</button>';
		}
		echo '</form>';
	}

	public static function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message after redirect.
		if ( isset( $_GET['malidev_msg'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['malidev_msg'] ) ) ) ) . '</p></div>';
		}
	}

	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// Only on the dashboard, plugins and updates screens: never inside the products' own work screens.
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'update-core' ), true ) ) {
			return;
		}
		foreach ( MaliDev_Core::products() as $slug => $product ) {
			$state = self::state( $slug );
			if ( in_array( $state['status'], array( 'active', 'unverified' ), true ) ) {
				continue;
			}
			$url = admin_url( 'admin.php?page=' . MaliDev_Core::parent_slug() );
			echo '<div class="notice notice-warning"><p>' . esc_html( $product['name'] ) . ': ' . esc_html( self::label( $state['status'] ) ) . ' — <a href="' . esc_url( $url ) . '">' . esc_html__( 'ثبت لایسنس', 'malidev-core' ) . '</a> ' . esc_html__( '(به‌روزرسانی خودکار فقط با لایسنس فعال انجام می‌شود)', 'malidev-core' ) . '</p></div>';
		}
	}
}
