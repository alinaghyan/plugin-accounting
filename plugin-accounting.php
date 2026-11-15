<?php
/**
 * Plugin Name: MaliDev Accounting & ERP
 * Plugin URI: https://malidev.ir/
 * Description: هسته حسابداری و مدیریت عملیات فروشگاه ووکامرس با پایگاه داده اختصاصی SQLite.
 * Version: 0.4.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: MaliDev
 * Text Domain: malidev-accounting
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit;
define( 'MDA_VERSION', '0.4.0' );
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
require_once MDA_PATH . 'includes/class-mda-admin.php';
require_once MDA_PATH . 'includes/class-mda-plugin.php';
register_activation_hook( __FILE__, array( 'MDA_Installer', 'activate' ) );
add_action('plugins_loaded',static function(): void {
 try { MDA_Installer::migrate(); } catch(Throwable $exception) { error_log('MaliDev Accounting migration error: '.$exception->getMessage()); }
 MDA_Plugin::instance()->init();
});
