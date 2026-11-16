<?php
/**
 * Plugin Name: MaliDev Accounting & ERP
 * Description: هسته حسابداری و مدیریت عملیات فروشگاه ووکامرس با پایگاه داده اختصاصی SQLite.
 * Version: 0.22.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author URI: https://malidev.ir/shop/
 * Plugin URI: https://malidev.ir/product/plugin-accounting/
 * Update URI: https://malidev.ir/
 * WC requires at least: 8.0
 * WC tested up to: 10.3
 * Author: malidev.ir
 * Text Domain: malidev-accounting
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit;
define( 'MDA_VERSION', '0.22.0' );
define( 'MDA_FILE', __FILE__ );
define( 'MDA_PATH', plugin_dir_path( __FILE__ ) );
define( 'MDA_URL', plugin_dir_url( __FILE__ ) );
// MaliDev license + updates (malidev.ir), shared with every MaliDev product.
require_once MDA_PATH . 'includes/malidev-client/loader.php';
malidev_client_register(
	array(
		'slug'    => 'plugin-accounting',
		'type'    => 'plugin',
		'file'    => __FILE__,
		'version' => MDA_VERSION,
		'name'    => 'حسابداری فروشگاه',
		'icon'    => plugins_url( 'assets/icon/icon-animated.svg', __FILE__ ),
	)
);
require_once MDA_PATH . 'includes/core/class-menu.php';
require_once MDA_PATH . 'includes/class-mda-database.php';
require_once MDA_PATH . 'includes/class-mda-jalali.php';
require_once MDA_PATH . 'includes/class-mda-settings.php';
require_once MDA_PATH . 'includes/class-mda-installer.php';
require_once MDA_PATH . 'includes/class-mda-capabilities.php';
require_once MDA_PATH . 'includes/class-mda-party-service.php';
require_once MDA_PATH . 'includes/class-mda-party-ledger-service.php';
require_once MDA_PATH . 'includes/class-mda-account-service.php';
require_once MDA_PATH . 'includes/class-mda-ledger-service.php';
require_once MDA_PATH . 'includes/class-mda-inventory-service.php';
require_once MDA_PATH . 'includes/class-mda-supply-service.php';
require_once MDA_PATH . 'includes/class-mda-journal-service.php';
require_once MDA_PATH . 'includes/class-mda-ledger-report-service.php';
require_once MDA_PATH . 'includes/class-mda-inventory-adjustment-service.php';
require_once MDA_PATH . 'includes/class-mda-backup-service.php';
require_once MDA_PATH . 'includes/class-mda-price-service.php';
require_once MDA_PATH . 'includes/class-mda-price-frontend.php';
require_once MDA_PATH . 'includes/class-mda-integrations.php';
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
require_once MDA_PATH . 'includes/class-mda-help.php';
require_once MDA_PATH . 'includes/class-mda-order-statuses.php';
require_once MDA_PATH . 'includes/class-mda-warehouse-service.php';
require_once MDA_PATH . 'includes/class-mda-warehouse-admin.php';
require_once MDA_PATH . 'includes/class-mda-supplier-service.php';
require_once MDA_PATH . 'includes/class-mda-manual-orders.php';
require_once MDA_PATH . 'includes/class-mda-dashboard-service.php';
require_once MDA_PATH . 'includes/class-mda-admin-shell.php';
require_once MDA_PATH . 'includes/class-mda-admin.php';
require_once MDA_PATH . 'includes/class-mda-admin-pages.php';
require_once MDA_PATH . 'includes/class-mda-price-admin.php';
require_once MDA_PATH . 'includes/class-mda-product-grid-service.php';
require_once MDA_PATH . 'includes/class-mda-product-grid.php';
require_once MDA_PATH . 'includes/class-mda-price-message.php';
require_once MDA_PATH . 'includes/identity/class-mda-moodian.php';
require_once MDA_PATH . 'includes/identity/class-mda-identity-admin.php';
require_once MDA_PATH . 'includes/identity/functions-moodian.php';
require_once MDA_PATH . 'includes/malidev-identity/loader.php';
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
 MDA_Admin_Shell::init();
 MDA_Order_Statuses::init();
 MDA_Warehouse_Admin::init();
 MDA_Supplier_Service::init();
 MDA_Manual_Orders::init();
 MDA_Admin_Pages::init();
 MDA_Price_Admin::init();
 MDA_Product_Grid::init();
 MDA_Price_Message::init();
 MDA_Identity_Admin::init();
 MDA_Price_Frontend::init();
 MDA_Integrations::init();
 MDA_Backup_Service::init();
 MDA_Plugin::instance()->init();
 MDA_Cheque_Admin::init();
 MDA_Gateway_Admin::init();
});
