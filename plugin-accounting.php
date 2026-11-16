<?php
/**
 * Plugin Name: MaliDev Accounting & ERP
 * Description: هسته حسابداری و مدیریت عملیات فروشگاه ووکامرس با پایگاه داده اختصاصی SQLite.
 * Version: 0.17.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.3
 * Author: MaliDev
 * Text Domain: malidev-accounting
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit;
define( 'MDA_VERSION', '0.17.0' );
define( 'MDA_FILE', __FILE__ );
define( 'MDA_PATH', plugin_dir_path( __FILE__ ) );
define( 'MDA_URL', plugin_dir_url( __FILE__ ) );
require_once MDA_PATH . 'includes/class-mda-database.php';
require_once MDA_PATH . 'includes/class-mda-installer.php';
require_once MDA_PATH . 'includes/class-mda-capabilities.php';
require_once MDA_PATH . 'includes/class-mda-party-service.php';
require_once MDA_PATH . 'includes/class-mda-party-ledger-service.php';
require_once MDA_PATH . 'includes/class-mda-account-service.php';
require_once MDA_PATH . 'includes/class-mda-ledger-service.php';
require_once MDA_PATH . 'includes/class-mda-inventory-service.php';
require_once MDA_PATH . 'includes/class-mda-supply-service.php';
require_once MDA_PATH . 'includes/class-mda-purchase-service.php';
require_once MDA_PATH . 'includes/class-mda-purchase-settlement-service.php';
require_once MDA_PATH . 'includes/class-mda-purchase-return-service.php';
require_once MDA_PATH . 'includes/class-mda-sales-service.php';
require_once MDA_PATH . 'includes/class-mda-sales-receipt-service.php';
require_once MDA_PATH . 'includes/class-mda-sales-return-service.php';
require_once MDA_PATH . 'includes/class-mda-outbox-service.php';
require_once MDA_PATH . 'includes/class-mda-reconciliation-service.php';
require_once MDA_PATH . 'includes/class-mda-stock-sync-service.php';
require_once MDA_PATH . 'includes/class-mda-report-service.php';
require_once MDA_PATH . 'includes/class-mda-cash-service.php';
require_once MDA_PATH . 'includes/class-mda-bank-reconciliation-service.php';
require_once MDA_PATH . 'includes/class-mda-cheque-service.php';
require_once MDA_PATH . 'includes/class-mda-cheque-admin.php';
require_once MDA_PATH . 'includes/class-mda-gateway-settlement-service.php';
require_once MDA_PATH . 'includes/class-mda-gateway-admin.php';
require_once MDA_PATH . 'includes/class-mda-order-adjustment-service.php';
require_once MDA_PATH . 'includes/class-mda-product-fields.php';
require_once MDA_PATH . 'includes/class-mda-woocommerce.php';
require_once MDA_PATH . 'includes/class-mda-phone-sales-admin.php';
require_once MDA_PATH . 'includes/class-mda-admin.php';
require_once MDA_PATH . 'includes/class-mda-plugin.php';
register_activation_hook( __FILE__, array( 'MDA_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MDA_Installer', 'deactivate' ) );
add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );
add_action('plugins_loaded',static function(): void {
 MDA_Installer::maybe_migrate();
 MDA_Plugin::instance()->init();
 MDA_Cheque_Admin::init();
 MDA_Gateway_Admin::init();
});
