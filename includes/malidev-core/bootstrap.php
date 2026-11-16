<?php
/**
 * MaliDev Core loader. Every MaliDev plugin requires this file; the newest bundled core wins.
 *
 * Usage in a plugin:
 *   require_once __DIR__ . '/includes/malidev-core/bootstrap.php';
 *   malidev_core_register_product( array( 'slug' => 'accounting', 'name' => '…', 'version' => '1.0.0', 'file' => __FILE__ ) );
 *
 * @package MaliDev_Core
 */

defined( 'ABSPATH' ) || exit;

$malidev_core_version = '1.0.0';
$GLOBALS['malidev_core_candidates'] = $GLOBALS['malidev_core_candidates'] ?? array();
if ( ! isset( $GLOBALS['malidev_core_candidates'][ $malidev_core_version ] ) ) {
	$GLOBALS['malidev_core_candidates'][ $malidev_core_version ] = __DIR__;
}
$GLOBALS['malidev_core_queue'] = $GLOBALS['malidev_core_queue'] ?? array();

if ( ! function_exists( 'malidev_core_register_product' ) ) {
	/**
	 * Registers a product now or as soon as the core is loaded.
	 */
	function malidev_core_register_product( array $product ): void {
		if ( class_exists( 'MaliDev_Core' ) ) {
			MaliDev_Core::register_product( $product );
			return;
		}
		$GLOBALS['malidev_core_queue'][] = $product;
	}

	/**
	 * Loads the newest bundled core once all plugins had the chance to announce theirs.
	 */
	function malidev_core_load(): void {
		if ( class_exists( 'MaliDev_Core' ) || empty( $GLOBALS['malidev_core_candidates'] ) ) {
			return;
		}
		$versions = array_keys( $GLOBALS['malidev_core_candidates'] );
		usort( $versions, 'version_compare' );
		$dir = $GLOBALS['malidev_core_candidates'][ end( $versions ) ];
		require_once $dir . '/class-malidev-core-license.php';
		require_once $dir . '/class-malidev-core-updater.php';
		require_once $dir . '/class-malidev-core.php';
		MaliDev_Core::boot();
		foreach ( $GLOBALS['malidev_core_queue'] as $product ) {
			MaliDev_Core::register_product( $product );
		}
		$GLOBALS['malidev_core_queue'] = array();
		do_action( 'malidev_core_loaded', MaliDev_Core::VERSION );
	}
	add_action( 'plugins_loaded', 'malidev_core_load', 1 );
}
