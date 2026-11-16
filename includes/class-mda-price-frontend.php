<?php
/**
 * Storefront use of price levels.
 *
 * - Role pricing: a logged-in customer whose role is attached to a level sees that level's price
 *   (catalogue, product page, cart). The regular price stays visible as the struck-through price.
 * - Quantity tiers: a level with "minimum quantity" applies in the cart once that many units of a line
 *   are bought (restricted to the level's roles when roles are set).
 * The lowest applicable price wins; products without a level price keep their normal price.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Price_Frontend {
	/** @var array<int,array<string,array>> Levels per user id (cached per request). */
	private static array $user_levels = array();

	public static function init(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'price' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'price' ), 50, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'sale_price' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'sale_price' ), 50, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'price' ), 50, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'sale_price' ), 50, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'prices_hash' ), 50, 1 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'quantity_tiers' ), 50 );
	}

	/** Levels that apply to the current user by role (quantity-only levels excluded). */
	private static function role_levels(): array {
		if ( ! did_action( 'set_current_user' ) && ! did_action( 'init' ) ) {
			return array();
		}
		$uid = get_current_user_id();
		if ( isset( self::$user_levels[ $uid ] ) ) {
			return self::$user_levels[ $uid ];
		}
		$found = array();
		$user  = $uid ? wp_get_current_user() : null;
		if ( $user && $user->exists() ) {
			foreach ( MDA_Price_Service::levels() as $key => $level ) {
				if ( ! empty( $level['roles'] ) && empty( $level['min_qty'] ) && array_intersect( (array) $user->roles, (array) $level['roles'] ) ) {
					$found[ $key ] = $level;
				}
			}
		}
		return self::$user_levels[ $uid ] = $found;
	}

	private static function best_level_price( $product, array $levels ): ?float {
		$best = null;
		foreach ( array_keys( $levels ) as $key ) {
			$raw = (string) $product->get_meta( MDA_Price_Service::META_PREFIX . $key, true );
			if ( '' === $raw && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$raw    = $parent ? (string) $parent->get_meta( MDA_Price_Service::META_PREFIX . $key, true ) : '';
			}
			if ( '' !== $raw && is_numeric( $raw ) && ( null === $best || (float) $raw < $best ) ) {
				$best = (float) $raw;
			}
		}
		return $best;
	}

	public static function price( $price, $product ) {
		$levels = self::role_levels();
		if ( ! $levels || ! $product instanceof WC_Product ) {
			return $price;
		}
		$level = self::best_level_price( $product, $levels );
		if ( null === $level ) {
			return $price;
		}
		return ( '' === $price || (float) $price > $level ) ? wc_format_decimal( $level ) : $price;
	}

	public static function sale_price( $price, $product ) {
		$levels = self::role_levels();
		if ( ! $levels || ! $product instanceof WC_Product ) {
			return $price;
		}
		$level = self::best_level_price( $product, $levels );
		if ( null === $level ) {
			return $price;
		}
		$regular = (float) $product->get_regular_price( 'edit' );
		if ( $level >= $regular ) {
			return $price;
		}
		return ( '' === $price || (float) $price > $level ) ? wc_format_decimal( $level ) : $price;
	}

	public static function prices_hash( $hash ) {
		$hash[] = 'mda:' . implode( ',', array_keys( self::role_levels() ) ) . ':' . md5( wp_json_encode( MDA_Price_Service::levels() ) );
		return $hash;
	}

	/**
	 * @param WC_Cart $cart
	 */
	public static function quantity_tiers( $cart ): void {
		if ( ! $cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		$tiers = array();
		$user  = wp_get_current_user();
		$roles = $user && $user->exists() ? (array) $user->roles : array();
		foreach ( MDA_Price_Service::levels() as $key => $level ) {
			if ( empty( $level['min_qty'] ) ) {
				continue;
			}
			if ( ! empty( $level['roles'] ) && ! array_intersect( $roles, (array) $level['roles'] ) ) {
				continue;
			}
			$tiers[ $key ] = $level;
		}
		if ( ! $tiers ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$qty        = (float) $item['quantity'];
			$applicable = array_filter( $tiers, static fn( $l ) => $qty >= (float) $l['min_qty'] );
			if ( ! $applicable ) {
				continue;
			}
			$tier = self::best_level_price( $product, $applicable );
			if ( null !== $tier && $tier < (float) $product->get_price() ) {
				$product->set_price( wc_format_decimal( $tier ) );
			}
		}
	}
}
