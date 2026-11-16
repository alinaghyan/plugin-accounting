<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Plugin {
	private static ?MDA_Plugin $instance = null;

	public static function instance(): MDA_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		load_plugin_textdomain( 'malidev-accounting', false, dirname( plugin_basename( MDA_FILE ) ) . '/languages' );
		MDA_Admin::init();
		MDA_Phone_Sales_Admin::init();
		MDA_WooCommerce::init();

		/**
		 * نقطه توسعه عمومی افزونه؛ تا زمان تثبیت قرارداد API، هوک‌های مالی منتشر نمی‌شوند.
		 */
		do_action( 'mda_loaded', MDA_VERSION );
	}
}
