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
		MDA_Shahkar::init();
		MDA_Moodian::init();
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
	}

	public static function menu(): void {
		add_submenu_page( MDA_Admin_Shell::HIDDEN_PARENT, 'شاهکار و مؤدیان', 'شاهکار و مؤدیان', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab & notice.
		$tab = isset( $_GET['tab'] ) && 'moodian' === $_GET['tab'] ? 'moodian' : 'shahkar';
		echo '<div class="wrap mda-wrap" dir="rtl"><h1>شاهکار و سامانه مؤدیان</h1>';
		foreach ( array( 'mda_notice' => 'success', 'mda_error' => 'error' ) as $k => $t ) {
			if ( isset( $_GET[ $k ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $t ) . ' is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ) ) . '</p></div>';
			}
		}
		// phpcs:enable
		echo '<p class="description">سرویس مشترک هویت و مالیات MaliDev: افزونه‌های فاکتور و پیامک همین تنظیمات و نتایج را می‌خوانند (توابع <code dir="ltr">malidev_shahkar_verify()</code>، <code dir="ltr">malidev_identity_for_order()</code>، <code dir="ltr">malidev_moodian_invoice()</code>).</p>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( array( 'shahkar' => 'احراز هویت شاهکار', 'moodian' => 'سامانه مؤدیان' ) as $k => $l ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'tab' => $k ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav>';
		'moodian' === $tab ? MDA_Moodian::render_admin() : MDA_Shahkar::render_admin_page();
		echo '</div>';
	}
}
