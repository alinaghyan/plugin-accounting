<?php
/**
 * MaliDev Identity loader (Shahkar national-code verification shared by MaliDev products).
 *
 * Bundle this whole folder inside each product, then in the main plugin file:
 *   require_once __DIR__ . '/includes/malidev-identity/loader.php';
 *
 * Whichever MaliDev plugins are active (accounting, invoice studio, SMS…), only the newest bundled
 * copy is loaded, so there is exactly one checkout field, one settings page and one data set.
 *
 * @package MaliDev\Identity
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['malidev_identity_versions']['1.0.0'] = __DIR__;

if ( ! function_exists( 'malidev_identity_boot' ) ) {
	/** Load the newest bundled copy. */
	function malidev_identity_boot() {
		if ( class_exists( 'MaliDev_Shahkar', false ) ) {
			return;
		}
		$versions = $GLOBALS['malidev_identity_versions'];
		uksort( $versions, 'version_compare' );
		$dir = end( $versions );
		require_once $dir . '/class-malidev-shahkar.php';
		require_once $dir . '/functions.php';
		MaliDev_Shahkar::init();
		do_action( 'malidev_identity_loaded', key( $versions ) );
	}
	add_action( 'plugins_loaded', 'malidev_identity_boot', -90 );
	if ( did_action( 'plugins_loaded' ) ) {
		malidev_identity_boot();
	}
}
