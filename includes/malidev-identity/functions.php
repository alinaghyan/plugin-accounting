<?php
/**
 * Public MaliDev identity & tax API. Other MaliDev plugins (invoice studio, SMS, license panel) call
 * these instead of talking to Shahkar/Moodian themselves. Every function is guarded so a newer copy
 * shipped by another plugin can take precedence.
 *
 * @package MaliDev\Identity
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'malidev_identity_provider' ) ) {
	/** Which class answers Shahkar calls: the SMS plugin's (if active) or the accounting copy. */
	function malidev_identity_provider(): string {
		return MaliDev_Shahkar::legacy_owner() ? 'SMSWT_Shahkar' : 'MaliDev_Shahkar';
	}
}

if ( ! function_exists( 'malidev_national_code_is_valid' ) ) {
	function malidev_national_code_is_valid( string $code ): bool {
		return MaliDev_Shahkar::is_valid_code( MaliDev_Shahkar::normalize_code( $code ) );
	}
}

if ( ! function_exists( 'malidev_shahkar_active' ) ) {
	/** Shahkar enabled and an API key saved. */
	function malidev_shahkar_active(): bool {
		return MaliDev_Shahkar::active();
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

if ( ! function_exists( 'malidev_shahkar_settings_url' ) ) {
	/** Link other plugins show next to their own settings ("manage Shahkar here"). */
	function malidev_shahkar_settings_url(): string {
		return MaliDev_Shahkar::url();
	}
}

if ( ! function_exists( 'malidev_identity_for_order' ) ) {
	/**
	 * Buyer identity recorded on an order.
	 *
	 * @return array{code:string,verified:bool,status:string}
	 */
	function malidev_identity_for_order( WC_Order $order ): array {
		$code   = MaliDev_Shahkar::normalize_code( (string) $order->get_meta( MaliDev_Shahkar::ORDER_META ) );
		$status = (string) $order->get_meta( MaliDev_Shahkar::ORDER_STATUS );
		if ( '' === $code && $order->get_customer_id() ) {
			$s    = MaliDev_Shahkar::settings();
			$key  = sanitize_key( (string) $s['meta_key'] ) ?: 'otino_national_code';
			$code = MaliDev_Shahkar::normalize_code( (string) get_user_meta( $order->get_customer_id(), $key, true ) );
		}
		return array( 'code' => $code, 'verified' => 'match' === $status, 'status' => $status );
	}
}

