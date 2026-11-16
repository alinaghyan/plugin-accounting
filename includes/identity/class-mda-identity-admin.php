<?php
/**
 * «شاهکار و مؤدیان» page inside the accounting shell.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Identity_Admin {
	public const PAGE = 'mda-identity';

	public static function init(): void {
		MDA_Moodian::init();
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
	}

	public static function menu(): void {
		add_submenu_page( MDA_Admin_Shell::HIDDEN_PARENT, 'سامانه مؤدیان', 'سامانه مؤدیان', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab & notice.
		echo '<div class="wrap mda-wrap" dir="rtl"><h1>سامانه مؤدیان</h1>';
		foreach ( array( 'mda_notice' => 'success', 'mda_error' => 'error' ) as $k => $t ) {
			if ( isset( $_GET[ $k ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $t ) . ' is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ) ) . '</p></div>';
			}
		}
		// phpcs:enable
		$shahkar = function_exists( 'malidev_shahkar_settings_url' ) ? malidev_shahkar_settings_url() : admin_url( 'admin.php?page=malidev-shahkar' );
		echo '<div class="mda-card"><h2>احراز هویت شاهکار</h2><p>شاهکار یک سرویس مشترک بین افزونه‌های MaliDev است (حسابداری، استودیو فاکتور، پیامک و رهگیری) و تنظیماتش یک‌جا در منوی «افزونه اختصاصی» قرار دارد.</p><p><a class="button button-primary" href="' . esc_url( $shahkar ) . '">تنظیمات احراز هویت شاهکار</a></p></div>';
		MDA_Moodian::render_admin();
		echo '</div>';
	}
}
