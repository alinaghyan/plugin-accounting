<?php
/**
 * Manual orders in the WooCommerce orders list: a «ثبت» column that shows «دستی · نام ثبت‌کننده»
 * (orders from the accounting manual-order form and from WooCommerce's own «افزودن سفارش») or «سایت».
 * The creator is also shown on the order screen. Works with HPOS and the legacy posts table.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Manual_Orders {
	public const META_USER = '_mda_created_by_user_id';
	public const META_KIND = '_mda_manual_order';
	public const VIAS      = array( 'mda_phone' => 'تلفنی', 'mda_in_person' => 'حضوری', 'admin' => 'دستی' );

	public static function init(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 20, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 20, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'tag_admin_order' ), 50, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_screen' ) );
		add_action( 'admin_head', array( __CLASS__, 'css' ) );
	}

	public static function columns( $cols ) {
		$out = array();
		foreach ( (array) $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$out['mda_source'] = esc_html__( 'ثبت', 'malidev-accounting' );
			}
		}
		if ( ! isset( $out['mda_source'] ) ) {
			$out['mda_source'] = esc_html__( 'ثبت', 'malidev-accounting' );
		}
		return $out;
	}

	public static function column_hpos( $column, $order ): void {
		if ( 'mda_source' === $column && $order instanceof WC_Order ) {
			echo self::badge( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
		}
	}

	public static function column_legacy( $column, $post_id ): void {
		if ( 'mda_source' === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				echo self::badge( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			}
		}
	}

	/** @return array{manual:bool,kind:string,user:string} */
	public static function info( WC_Order $order ): array {
		$via    = (string) $order->get_created_via();
		$uid    = (int) $order->get_meta( self::META_USER );
		$manual = isset( self::VIAS[ $via ] ) || $uid > 0;
		$user   = $uid ? get_userdata( $uid ) : null;
		return array(
			'manual' => $manual,
			'kind'   => self::VIAS[ $via ] ?? ( $manual ? 'دستی' : '' ),
			'user'   => $user ? $user->display_name : '',
		);
	}

	public static function badge( WC_Order $order ): string {
		$i = self::info( $order );
		if ( ! $i['manual'] ) {
			return '<span class="mda-src mda-src--web">' . esc_html__( 'سایت', 'malidev-accounting' ) . '</span>';
		}
		$label = 'دستی' === $i['kind'] ? 'دستی' : 'دستی · ' . $i['kind'];
		return '<span class="mda-src mda-src--manual" title="' . esc_attr( 'ثبت‌شده توسط ' . ( $i['user'] ?: 'نامشخص' ) ) . '">' . esc_html( $label ) . '</span>' . ( $i['user'] ? '<small class="mda-src__by">' . esc_html( $i['user'] ) . '</small>' : '' );
	}

	/** Orders created in WooCommerce's own «افزودن سفارش» screen get the creator recorded once. */
	public static function tag_admin_order( $order_id, $post = null ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'admin' !== $order->get_created_via() || $order->get_meta( self::META_USER ) ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return;
		}
		$order->update_meta_data( self::META_USER, (int) $user->ID );
		$order->update_meta_data( self::META_KIND, 'admin' );
		$order->save_meta_data();
		/* translators: %s: admin display name */
		$order->add_order_note( sprintf( __( 'سفارش دستی؛ ثبت‌شده توسط %s', 'malidev-accounting' ), $user->display_name ), 0, false );
	}

	public static function order_screen( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$i = self::info( $order );
		if ( ! $i['manual'] ) {
			return;
		}
		echo '<p class="form-field form-field-wide mda-src-line"><strong>' . esc_html__( 'نوع ثبت:', 'malidev-accounting' ) . '</strong> ' . self::badge( $order ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
	}

	public static function css(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( false === strpos( (string) $screen->id, 'wc-orders' ) && 'edit-shop_order' !== $screen->id && 'shop_order' !== $screen->id ) ) {
			return;
		}
		echo '<style>.column-mda_source{width:9em}.mda-src{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.8}.mda-src--manual{background:#fbeef4;color:#8f1748}.mda-src--web{background:#eef2f7;color:#50575e}.mda-src__by{display:block;color:#787c82;font-size:11px;margin-top:2px}.mda-src-line .mda-src__by{display:inline;margin-right:6px}</style>';
	}
}
