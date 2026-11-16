<?php
/**
 * Accounting settings stored inside the SQLite database (they travel with the ledger and its backups).
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Settings {
	private const KEY = 'app_settings';

	private static ?array $cache = null;

	public static function defaults(): array {
		return array(
			'backup_enabled'        => 1,
			'backup_keep'           => 14,
			'jalali_dates'          => 1,
			'moodian_enabled'       => 0,
			'seller_name'           => '',
			'seller_economic_code'  => '',
			'seller_national_id'    => '',
			'seller_postal_code'    => '',
			'moodian_memory_id'     => '',
			'default_lead_time'     => 3,
		);
	}

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved = array();
		try {
			$q = MDA_Database::connection()->prepare( 'SELECT setting_value FROM mda_settings WHERE setting_key = :k' );
			$q->execute( array( ':k' => self::KEY ) );
			$saved = json_decode( (string) $q->fetchColumn(), true );
		} catch ( Throwable $e ) {
			$saved = array();
		}
		self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		return self::$cache;
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function save( array $input ): void {
		$clean = self::defaults();
		foreach ( array( 'backup_enabled', 'jalali_dates', 'moodian_enabled' ) as $flag ) {
			$clean[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}
		$clean['backup_keep']       = max( 3, min( 90, (int) ( $input['backup_keep'] ?? 14 ) ) );
		$clean['default_lead_time'] = max( 0, min( 365, (int) ( $input['default_lead_time'] ?? 3 ) ) );
		foreach ( array( 'seller_name', 'moodian_memory_id' ) as $text ) {
			$clean[ $text ] = sanitize_text_field( (string) ( $input[ $text ] ?? '' ) );
		}
		foreach ( array( 'seller_economic_code', 'seller_national_id', 'seller_postal_code' ) as $digits ) {
			$clean[ $digits ] = preg_replace( '/\D/', '', MDA_Jalali::latin_digits( (string) ( $input[ $digits ] ?? '' ) ) );
		}
		MDA_Database::connection()->prepare( 'INSERT OR REPLACE INTO mda_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :now)' )
			->execute( array( ':k' => self::KEY, ':v' => wp_json_encode( $clean ), ':now' => gmdate( 'Y-m-d H:i:s' ) ) );
		self::$cache = $clean;
	}

	/**
	 * Accounting currency follows WooCommerce (IRR or IRT); anything else falls back to IRR.
	 */
	public static function currency(): string {
		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'IRR';
		return in_array( $currency, array( 'IRR', 'IRT' ), true ) ? $currency : 'IRR';
	}

	public static function currency_label(): string {
		return 'IRT' === self::currency() ? 'تومان' : 'ریال';
	}
}
