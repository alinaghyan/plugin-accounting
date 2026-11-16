<?php
/**
 * Multi-price management: purchase (reference) price and named price levels per product/variation.
 *
 * Values live in WooCommerce product meta (fast for the storefront, HPOS-independent) and every
 * change is written to an append-only price history in SQLite (who, when, old → new, source).
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Price_Service {
	public const META_PURCHASE = '_mda_purchase_price';
	public const META_PREFIX   = '_mda_price_';
	private const OPTION       = 'mda_price_levels';

	/**
	 * Configured price levels: key => [label, roles[], min_qty].
	 */
	public static function levels(): array {
		$saved = get_option( self::OPTION );
		if ( ! is_array( $saved ) || ! $saved ) {
			$saved = array(
				'p1' => array( 'label' => 'قیمت ۱ (عمده)', 'roles' => array(), 'min_qty' => 0 ),
				'p2' => array( 'label' => 'قیمت ۲ (همکار)', 'roles' => array(), 'min_qty' => 0 ),
			);
		}
		return $saved;
	}

	public static function save_levels( array $rows ): void {
		$out = array();
		$n   = 0;
		foreach ( $rows as $row ) {
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( ! preg_match( '/^p[0-9]{1,2}$/', $key ) ) {
				$key = 'p' . ( ++$n + count( $out ) );
			}
			while ( isset( $out[ $key ] ) ) {
				$key = 'p' . ( (int) substr( $key, 1 ) + 1 );
			}
			$roles = array_values( array_filter( array_map( 'sanitize_key', (array) ( $row['roles'] ?? array() ) ), static fn( $r ) => '' !== $r && 'administrator' !== $r ) );
			$out[ $key ] = array( 'label' => $label, 'roles' => $roles, 'min_qty' => max( 0, (int) ( $row['min_qty'] ?? 0 ) ) );
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		update_option( self::OPTION, $out, true );
	}

	/**
	 * Editable fields of the grid: WooCommerce prices + purchase price + every level.
	 *
	 * @return array<string,string> field => label
	 */
	public static function fields(): array {
		$fields = array(
			'purchase' => 'قیمت خرید',
			'regular'  => 'قیمت فروش',
			'sale'     => 'قیمت فروش ویژه',
		);
		foreach ( self::levels() as $key => $level ) {
			$fields[ $key ] = $level['label'];
		}
		return $fields;
	}

	public static function get( WC_Product $product, string $field ): string {
		switch ( $field ) {
			case 'regular':
				return (string) $product->get_regular_price( 'edit' );
			case 'sale':
				return (string) $product->get_sale_price( 'edit' );
			case 'purchase':
				return (string) $product->get_meta( self::META_PURCHASE, true );
			default:
				return isset( self::levels()[ $field ] ) ? (string) $product->get_meta( self::META_PREFIX . $field, true ) : '';
		}
	}

	/**
	 * Normalises user input ("۱٬۲۵۰٬۰۰۰", "1,250,000", "") to a WooCommerce price string or ''.
	 */
	public static function clean( $raw ): string {
		$raw = trim( MDA_Jalali::latin_digits( (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		$raw = str_replace( array( ',', '٬', '،', ' ' ), '', $raw );
		$raw = str_replace( '٫', '.', $raw );
		if ( ! preg_match( '/^\d{1,15}(\.\d{1,4})?$/', $raw ) ) {
			throw new InvalidArgumentException( 'مبلغ «' . $raw . '» معتبر نیست.' );
		}
		return wc_format_decimal( $raw );
	}

	/**
	 * Applies a set of changes to one product. Returns the list of [field, old, new] actually changed.
	 *
	 * @param array<string,string> $changes field => new value ('' clears).
	 */
	public static function apply( WC_Product $product, array $changes, string $source = 'single', bool $save = true ): array {
		$allowed = self::fields();
		$done    = array();
		foreach ( $changes as $field => $value ) {
			if ( ! isset( $allowed[ $field ] ) ) {
				continue;
			}
			$new = self::clean( $value );
			$old = self::get( $product, $field );
			if ( (string) $old === (string) $new ) {
				continue;
			}
			switch ( $field ) {
				case 'regular':
					$product->set_regular_price( $new );
					break;
				case 'sale':
					$product->set_sale_price( $new );
					break;
				case 'purchase':
					'' === $new ? $product->delete_meta_data( self::META_PURCHASE ) : $product->update_meta_data( self::META_PURCHASE, $new );
					break;
				default:
					'' === $new ? $product->delete_meta_data( self::META_PREFIX . $field ) : $product->update_meta_data( self::META_PREFIX . $field, $new );
			}
			$done[] = array( $field, $old, $new );
		}
		if ( ! $done ) {
			return array();
		}
		$regular = (float) $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );
		if ( '' !== $sale && $regular > 0 && (float) $sale >= $regular ) {
			throw new InvalidArgumentException( 'قیمت ویژه «' . $product->get_name() . '» باید کمتر از قیمت فروش باشد.' );
		}
		if ( $save ) {
			$product->save();
		}
		self::log_changes( (int) $product->get_id(), $done, $source );
		return $done;
	}

	/**
	 * Computes a bulk operation for one current value; null means "leave unchanged".
	 *
	 * Operations: set, inc_pct, dec_pct, inc_amount, dec_amount, margin (from purchase price), copy (from another field ± pct), clear.
	 */
	public static function compute( WC_Product $product, string $field, string $op, string $value, string $source_field, int $round_to ): ?string {
		$current = (float) self::get( $product, $field );
		$v       = (float) str_replace( ',', '', MDA_Jalali::latin_digits( $value ) );
		switch ( $op ) {
			case 'set':
				$result = $v;
				break;
			case 'inc_pct':
				$result = $current * ( 1 + $v / 100 );
				break;
			case 'dec_pct':
				$result = $current * ( 1 - $v / 100 );
				break;
			case 'inc_amount':
				$result = $current + $v;
				break;
			case 'dec_amount':
				$result = $current - $v;
				break;
			case 'margin':
				$base = (float) self::get( $product, 'purchase' );
				if ( $base <= 0 ) {
					return null;
				}
				$result = $base * ( 1 + $v / 100 );
				break;
			case 'copy':
				$base = (float) self::get( $product, $source_field );
				if ( $base <= 0 ) {
					return null;
				}
				$result = $base * ( 1 + $v / 100 );
				break;
			case 'clear':
				return '';
			default:
				throw new InvalidArgumentException( 'عملیات گروهی معتبر نیست.' );
		}
		if ( in_array( $op, array( 'inc_pct', 'dec_pct', 'inc_amount', 'dec_amount' ), true ) && $current <= 0 ) {
			return null;
		}
		$result = max( 0, $result );
		if ( $round_to > 1 ) {
			$result = round( $result / $round_to ) * $round_to;
		}
		return (string) round( $result, 0 );
	}

	public static function log_changes( int $product_id, array $changes, string $source ): void {
		if ( ! MDA_Database::is_available() ) {
			return;
		}
		try {
			$db = MDA_Database::connection();
			$q  = $db->prepare( 'INSERT INTO mda_price_history (product_id, field_key, old_value, new_value, source, user_id, created_at) VALUES (:p, :f, :o, :n, :s, :u, :now)' );
			foreach ( $changes as $c ) {
				$q->execute( array( ':p' => $product_id, ':f' => $c[0], ':o' => $c[1], ':n' => $c[2], ':s' => $source, ':u' => get_current_user_id() ?: null, ':now' => gmdate( 'Y-m-d H:i:s' ) ) );
			}
		} catch ( Throwable $e ) {
			error_log( 'MaliDev Accounting price history: ' . $e->getMessage() ); // phpcs:ignore
		}
	}

	public static function history( int $product_id, int $limit = 50 ): array {
		$q = MDA_Database::connection()->prepare( 'SELECT * FROM mda_price_history WHERE product_id = :p ORDER BY id DESC LIMIT :l' );
		$q->bindValue( ':p', $product_id, PDO::PARAM_INT );
		$q->bindValue( ':l', max( 1, min( 500, $limit ) ), PDO::PARAM_INT );
		$q->execute();
		return $q->fetchAll();
	}

	/**
	 * Price a customer should pay with a given level (phone sale or storefront role pricing).
	 */
	public static function level_price( WC_Product $product, string $level ): string {
		if ( isset( self::levels()[ $level ] ) ) {
			$price = (string) $product->get_meta( self::META_PREFIX . $level, true );
			if ( '' === $price && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$price  = $parent ? (string) $parent->get_meta( self::META_PREFIX . $level, true ) : '';
			}
			if ( '' !== $price ) {
				return $price;
			}
		}
		return (string) $product->get_price( 'edit' );
	}

	/**
	 * Accounting view of a product: average cost and stock in the main warehouse (read-only in the grid).
	 *
	 * @param int[] $ids
	 * @return array<int,array{avg:int,qty:float}>
	 */
	public static function accounting_costs( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! MDA_Database::is_available() ) {
			return array();
		}
		try {
			$db = MDA_Database::connection();
			$in = implode( ',', array_fill( 0, count( $ids ), '?' ) );
			$q  = $db->prepare( "SELECT CASE WHEN variation_id > 0 THEN variation_id ELSE product_id END AS id, SUM(quantity_scaled) AS q, MAX(quantity_scale) AS s, MAX(average_cost_minor) AS avg
				FROM mda_product_cost_state WHERE (CASE WHEN variation_id > 0 THEN variation_id ELSE product_id END) IN ($in) GROUP BY id" );
			foreach ( $ids as $i => $id ) {
				$q->bindValue( $i + 1, $id, PDO::PARAM_INT ); // Integer binding: the CASE expression has no affinity.
			}
			$q->execute();
			$out = array();
			foreach ( $q->fetchAll() as $r ) {
				$out[ (int) $r['id'] ] = array( 'avg' => (int) $r['avg'], 'qty' => (int) $r['s'] > 0 ? (int) $r['q'] / (int) $r['s'] : 0 );
			}
			return $out;
		} catch ( Throwable $e ) {
			return array();
		}
	}
}
