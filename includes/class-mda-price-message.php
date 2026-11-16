<?php
/**
 * Storefront side of the «پیام جایگزین قیمت»: a product carrying the message shows a box (title, note,
 * call link) instead of its price, and cannot be bought — add-to-cart, quantity and stock text disappear.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Price_Message {
	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 99, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 99, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 99, 2 );
		add_filter( 'woocommerce_get_stock_html', array( __CLASS__, 'stock_html' ), 99, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 99, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_style' ) );
	}

	private static function msg( $product ): array {
		return $product instanceof WC_Product ? MDA_Product_Grid_Service::message( $product ) : array();
	}

	public static function register_style(): void {
		wp_register_style( 'mda-price-message', MDA_URL . 'assets/css/price-message.css', array(), (string) filemtime( MDA_PATH . 'assets/css/price-message.css' ) );
	}

	/** Markup of the replacement box; safe for any context that prints price HTML. */
	public static function box( array $msg ): string {
		wp_enqueue_style( 'mda-price-message' );
		$o = '<span class="mda-price-msg"><span class="mda-price-msg__title">' . esc_html( $msg['title'] ) . '</span>';
		if ( ! empty( $msg['note'] ) ) {
			$o .= '<span class="mda-price-msg__note">' . esc_html( $msg['note'] ) . '</span>';
		}
		if ( ! empty( $msg['phone'] ) ) {
			$tel = preg_replace( '/[^0-9+]/', '', $msg['phone'] );
			$o  .= '<a class="mda-price-msg__tel" href="' . esc_url( 'tel:' . $tel, array( 'tel' ) ) . '" dir="ltr">☎ ' . esc_html( $msg['phone'] ) . '</a>';
		}
		return $o . '</span>';
	}

	public static function price_html( $html, $product ) {
		$msg = self::msg( $product );
		return $msg ? self::box( $msg ) : $html;
	}

	public static function purchasable( $purchasable, $product ) {
		return self::msg( $product ) ? false : $purchasable;
	}

	public static function stock_html( $html, $product ) {
		return self::msg( $product ) ? '' : $html;
	}

	public static function loop_button( $link, $product ) {
		return self::msg( $product ) ? '' : $link;
	}
}
