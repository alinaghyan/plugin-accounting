<?php
/**
 * MaliDev client SDK loader.
 *
 * Bundle this whole folder inside each product, then in the main file:
 *   require_once __DIR__ . '/includes/malidev-client/loader.php';
 *   malidev_client_register( array( 'slug' => 'plugin-factor-woocommerce', 'type' => 'plugin', 'file' => __FILE__, 'version' => '1.2.0', 'name' => 'استودیو فاکتور' ) );
 *
 * When several products bundle the SDK, only the newest copy is loaded.
 *
 * @package MaliDev\Client
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['malidev_client_versions']['1.5.2'] = __DIR__ . '/class-malidev-client.php';

if ( ! function_exists( 'malidev_client_register' ) ) {
	/**
	 * Register a product (plugin or theme) with the shared client.
	 *
	 * @param array $args slug, type (plugin|theme), file, version, name, stylesheet (themes).
	 */
	function malidev_client_register( array $args ) {
		$GLOBALS['malidev_client_queue'][] = $args;
		if ( class_exists( 'MaliDev_Client', false ) ) {
			MaliDev_Client::flush_queue();
		}
	}

	/** Load the newest bundled SDK copy. */
	function malidev_client_boot() {
		$versions = $GLOBALS['malidev_client_versions'];
		uksort( $versions, 'version_compare' );
		require_once end( $versions );
		MaliDev_Client::init();
	}
	add_action( 'plugins_loaded', 'malidev_client_boot', -100 );
	if ( did_action( 'plugins_loaded' ) ) { // Loaded from a theme (after plugins).
		malidev_client_boot();
	}
}
