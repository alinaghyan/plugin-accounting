<?php
/**
 * Public MaliDev Moodian API (accounting plugin).
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'malidev_moodian_settings' ) ) {
	function malidev_moodian_settings(): array {
		return MDA_Moodian::settings();
	}
}

if ( ! function_exists( 'malidev_moodian_tax_id' ) ) {
	function malidev_moodian_tax_id( WC_Order $order ): string {
		$st = MDA_Moodian::status( $order );
		if ( empty( $st['tax_id'] ) && MDA_Moodian::ready() ) {
			MDA_Moodian::payload( $order ); // Assigns serial + tax id.
			$st = MDA_Moodian::status( $order );
		}
		return (string) ( $st['tax_id'] ?? '' );
	}
}

if ( ! function_exists( 'malidev_moodian_invoice' ) ) {
	/** Official-format invoice payload for an order. */
	function malidev_moodian_invoice( WC_Order $order ): array {
		return MDA_Moodian::payload( $order );
	}
}

if ( ! function_exists( 'malidev_moodian_status' ) ) {
	/** array{status?:string,serial?:int,tax_id?:string,reference?:string,error?:string} */
	function malidev_moodian_status( WC_Order $order ): array {
		return MDA_Moodian::status( $order );
	}
}

if ( ! function_exists( 'malidev_product_sstid' ) ) {
	function malidev_product_sstid( WC_Product $product ): string {
		return MDA_Moodian::sstid( $product );
	}
}
