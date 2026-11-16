<?php
/**
 * Jalali (Solar Hijri) ⇄ Gregorian conversion and Persian digits. Pure PHP, no intl dependency.
 * Algorithm: standard 33-year arithmetic (same as jalaali-js), valid for 1 – 3177 AP.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Jalali {
	private const BREAKS = array( -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178 );

	public static function to_jalali( int $gy, int $gm, int $gd ): array {
		$jdn = self::g2d( $gy, $gm, $gd );
		return self::d2j( $jdn );
	}

	public static function to_gregorian( int $jy, int $jm, int $jd ): array {
		return self::d2g( self::j2d( $jy, $jm, $jd ) );
	}

	/** "2026-11-16" → "۱۴۰۵/۰۸/۲۵" (or Latin digits when $persian_digits is false). */
	public static function format( ?string $gregorian, bool $persian_digits = true ): string {
		if ( ! $gregorian || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $gregorian, $m ) ) {
			return (string) $gregorian;
		}
		list( $jy, $jm, $jd ) = self::to_jalali( (int) $m[1], (int) $m[2], (int) $m[3] );
		$out = sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
		return $persian_digits ? self::persian_digits( $out ) : $out;
	}

	/** "1405/08/25" (Persian or Latin digits, / or -) → "2026-11-16"; null when invalid. */
	public static function parse( string $jalali ): ?string {
		$jalali = self::latin_digits( trim( $jalali ) );
		if ( ! preg_match( '#^(\d{4})[/\-](\d{1,2})[/\-](\d{1,2})$#', $jalali, $m ) ) {
			return null;
		}
		list( $jy, $jm, $jd ) = array( (int) $m[1], (int) $m[2], (int) $m[3] );
		if ( $jm < 1 || $jm > 12 || $jd < 1 || $jd > ( $jm <= 6 ? 31 : ( $jm <= 11 ? 30 : ( self::is_leap( $jy ) ? 30 : 29 ) ) ) ) {
			return null;
		}
		list( $gy, $gm, $gd ) = self::to_gregorian( $jy, $jm, $jd );
		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	/** Accepts either a Gregorian Y-m-d or a Jalali date and returns Gregorian Y-m-d or null. */
	public static function normalize_input( string $value ): ?string {
		$value = self::latin_digits( trim( $value ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && (int) substr( $value, 0, 4 ) > 1700 ) {
			$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
			return $d && $d->format( 'Y-m-d' ) === $value ? $value : null;
		}
		return self::parse( $value );
	}

	public static function is_leap( int $jy ): bool {
		return 0 === self::cal( $jy )['leap'];
	}

	public static function persian_digits( string $s ): string {
		return strtr( $s, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}

	public static function latin_digits( string $s ): string {
		return strtr(
			$s,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/** Jalali year (e.g. 1405) → [first Gregorian day, last Gregorian day]. */
	public static function year_range( int $jy ): array {
		return array( self::parse( $jy . '/01/01' ), self::parse( $jy . '/12/' . ( self::is_leap( $jy ) ? 30 : 29 ) ) );
	}

	private static function cal( int $jy ): array {
		$bl    = count( self::BREAKS );
		$gy    = $jy + 621;
		$leapj = -14;
		$jp    = self::BREAKS[0];
		$jump  = 0;
		for ( $i = 1; $i < $bl; $i++ ) {
			$jm   = self::BREAKS[ $i ];
			$jump = $jm - $jp;
			if ( $jy < $jm ) {
				break;
			}
			$leapj += intdiv( $jump, 33 ) * 8 + intdiv( $jump % 33, 4 );
			$jp     = $jm;
		}
		$n      = $jy - $jp;
		$leapj += intdiv( $n, 33 ) * 8 + intdiv( $n % 33 + 3, 4 );
		if ( 4 === $jump % 33 && 4 === $jump - $n ) {
			++$leapj;
		}
		$leapg = intdiv( $gy, 4 ) - intdiv( ( intdiv( $gy, 100 ) + 1 ) * 3, 4 ) - 150;
		$march = 20 + $leapj - $leapg;
		if ( $jump - $n < 6 ) {
			$n = $n - $jump + intdiv( $jump + 4, 33 ) * 33;
		}
		$leap = ( ( ( $n + 1 ) % 33 ) - 1 ) % 4;
		if ( -1 === $leap ) {
			$leap = 4;
		}
		return array( 'leap' => $leap, 'gy' => $gy, 'march' => $march );
	}

	private static function j2d( int $jy, int $jm, int $jd ): int {
		$r = self::cal( $jy );
		return self::g2d( $r['gy'], 3, $r['march'] ) + ( $jm - 1 ) * 31 - intdiv( $jm, 7 ) * ( $jm - 7 ) + $jd - 1;
	}

	private static function d2j( int $jdn ): array {
		$gy = self::d2g( $jdn )[0];
		$jy = $gy - 621;
		$r  = self::cal( $jy );
		$k  = $jdn - self::g2d( $gy, 3, $r['march'] );
		if ( $k >= 0 ) {
			if ( $k <= 185 ) {
				return array( $jy, 1 + intdiv( $k, 31 ), $k % 31 + 1 );
			}
			$k -= 186;
		} else {
			--$jy;
			$k += 179;
			if ( 1 === $r['leap'] ) {
				++$k;
			}
		}
		return array( $jy, 7 + intdiv( $k, 30 ), $k % 30 + 1 );
	}

	private static function g2d( int $gy, int $gm, int $gd ): int {
		$d = intdiv( ( $gy + intdiv( $gm - 8, 6 ) + 100100 ) * 1461, 4 ) + intdiv( 153 * ( ( $gm + 9 ) % 12 ) + 2, 5 ) + $gd - 34840408;
		return $d - intdiv( intdiv( $gy + 100100 + intdiv( $gm - 8, 6 ), 100 ) * 3, 4 ) + 752;
	}

	private static function d2g( int $jdn ): array {
		$j  = 4 * $jdn + 139361631;
		$j  = $j + intdiv( intdiv( 4 * $jdn + 183187720, 146097 ) * 3, 4 ) * 4 - 3908;
		$i  = intdiv( $j % 1461, 4 ) * 5 + 308;
		$gd = intdiv( $i % 153, 5 ) + 1;
		$gm = intdiv( $i, 153 ) % 12 + 1;
		$gy = intdiv( $j, 1461 ) - 100100 + intdiv( 8 - $gm, 6 );
		return array( $gy, $gm, $gd );
	}
}
