<?php
/**
 * Public MaliDev identity & tax API. Other MaliDev plugins (invoice studio, SMS, license panel) call
 * these instead of talking to Shahkar/Moodian themselves. Every function is guarded so a newer copy
 * shipped by another plugin can take precedence.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'malidev_identity_provider' ) ) {
	/** Which class answers Shahkar calls: the SMS plugin's (if active) or the accounting copy. */
	function malidev_identity_provider(): string {
		return class_exists( 'SMSWT_Shahkar' ) ? 'SMSWT_Shahkar' : 'MDA_Shahkar';
	}
}

if ( ! function_exists( 'malidev_national_code_is_valid' ) ) {
	function malidev_national_code_is_valid( string $code ): bool {
		return MDA_Shahkar::is_valid_code( MDA_Shahkar::normalize_code( $code ) );
	}
}

if ( ! function_exists( 'malidev_shahkar_active' ) ) {
	/** Shahkar enabled and an API key saved. */
	function malidev_shahkar_active(): bool {
		return MDA_Shahkar::active();
	}
}

if ( ! function_exists( 'malidev_shahkar_verify' ) ) {
	/**
	 * National code ↔ mobile owner check (cached, rate limited).
	 *
	 * @return array{status:string,message:string,code:string,mobile:string} status: ok|match|mismatch|error|invalid
	 */
	function malidev_shahkar_verify( string $national_code, string $mobile, int $user_id = 0 ): array {
		$provider = malidev_identity_provider();
		$result   = $provider::verify( $national_code, $mobile, $user_id );
		return (array) apply_filters( 'malidev_shahkar_result', $result, $national_code, $mobile, $user_id );
	}
}

if ( ! function_exists( 'malidev_identity_for_order' ) ) {
	/**
	 * Buyer identity recorded on an order.
	 *
	 * @return array{code:string,verified:bool,status:string}
	 */
	function malidev_identity_for_order( WC_Order $order ): array {
		$code   = MDA_Shahkar::normalize_code( (string) $order->get_meta( MDA_Shahkar::ORDER_META ) );
		$status = (string) $order->get_meta( MDA_Shahkar::ORDER_STATUS );
		if ( '' === $code && $order->get_customer_id() ) {
			$s    = MDA_Shahkar::settings();
			$key  = sanitize_key( (string) $s['meta_key'] ) ?: 'otino_national_code';
			$code = MDA_Shahkar::normalize_code( (string) get_user_meta( $order->get_customer_id(), $key, true ) );
		}
		return array( 'code' => $code, 'verified' => 'match' === $status, 'status' => $status );
	}
}

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
