<?php
/**
 * WooCommerce product fields: supply mode (in stock / bought from supplier after sale) and lead time.
 *
 * The mode is stored in SQLite (source of truth for accounting) and mirrored to product meta so the
 * storefront never needs to open the accounting database.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Product_Fields {
	public const META_MODE = '_mda_supply_mode';
	public const META_LEAD = '_mda_lead_time_days';

	public static function init(): void {
		add_action( 'woocommerce_product_options_stock_status', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'availability_text' ), 20, 2 );
	}

	public static function render(): void {
		global $product_object;
		if ( ! $product_object instanceof WC_Product ) {
			return;
		}
		$mode = (string) $product_object->get_meta( self::META_MODE ) ?: MDA_Supply_Service::MODE_STOCK;
		$lead = $product_object->get_meta( self::META_LEAD );
		echo '<div class="options_group mda-supply-fields">';
		wp_nonce_field( 'mda_product_supply', 'mda_product_supply_nonce' );
		woocommerce_wp_select(
			array(
				'id'          => 'mda_supply_mode',
				'label'       => esc_html__( 'نحوه تأمین کالا', 'malidev-accounting' ),
				'value'       => $mode,
				'options'     => array(
					MDA_Supply_Service::MODE_STOCK     => esc_html__( 'از موجودی انبار', 'malidev-accounting' ),
					MDA_Supply_Service::MODE_ON_DEMAND => esc_html__( 'سفارشی؛ پس از فروش از تأمین‌کننده خرید می‌شود', 'malidev-accounting' ),
				),
				'desc_tip'    => true,
				'description' => esc_html__( 'کالای سفارشی بدون موجودی هم قابل فروش است، در فروشگاه «تأمین از تأمین‌کننده» نمایش داده می‌شود و بهای تمام‌شده آن هنگام ثبت خرید محاسبه می‌شود.', 'malidev-accounting' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => 'mda_lead_time_days',
				'label'             => esc_html__( 'زمان تأمین (روز کاری)', 'malidev-accounting' ),
				'value'             => '' === $lead ? '' : (string) absint( $lead ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0', 'max' => '365', 'step' => '1' ),
			)
		);
		echo '</div>';
	}

	public static function save( WC_Product $product ): void {
		if ( ! isset( $_POST['mda_product_supply_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mda_product_supply_nonce'] ) ), 'mda_product_supply' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$mode = isset( $_POST['mda_supply_mode'] ) ? sanitize_key( wp_unslash( $_POST['mda_supply_mode'] ) ) : MDA_Supply_Service::MODE_STOCK;
		if ( ! in_array( $mode, array( MDA_Supply_Service::MODE_STOCK, MDA_Supply_Service::MODE_ON_DEMAND ), true ) ) {
			$mode = MDA_Supply_Service::MODE_STOCK;
		}
		$lead_raw = isset( $_POST['mda_lead_time_days'] ) ? sanitize_text_field( wp_unslash( $_POST['mda_lead_time_days'] ) ) : '';
		$lead     = '' === $lead_raw ? null : min( 365, absint( $lead_raw ) );

		$product->update_meta_data( self::META_MODE, $mode );
		if ( null === $lead ) {
			$product->delete_meta_data( self::META_LEAD );
		} else {
			$product->update_meta_data( self::META_LEAD, $lead );
		}
		if ( MDA_Supply_Service::MODE_ON_DEMAND === $mode && 'no' === $product->get_backorders() ) {
			// Sellable at zero stock, but shown honestly as "on backorder", never as "in stock".
			$product->set_backorders( 'notify' );
		}

		if ( MDA_Database::is_available() ) {
			try {
				MDA_Supply_Service::save_settings( (int) $product->get_id(), 0, $mode, $lead, null );
			} catch ( Throwable $e ) {
				if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
					WC_Admin_Meta_Boxes::add_error( esc_html( 'ذخیره نحوه تأمین در حسابداری انجام نشد: ' . $e->getMessage() ) );
				}
			}
		}
	}

	/**
	 * Storefront text for on-demand products that are out of stock.
	 */
	public static function availability_text( string $text, $product ): string {
		if ( ! $product instanceof WC_Product ) {
			return $text;
		}
		$source = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $source || MDA_Supply_Service::MODE_ON_DEMAND !== $source->get_meta( self::META_MODE ) ) {
			return $text;
		}
		if ( $product->managing_stock() && $product->get_stock_quantity() > 0 ) {
			return $text;
		}
		$lead = $source->get_meta( self::META_LEAD );
		if ( '' !== $lead && null !== $lead ) {
			/* translators: %s: number of working days */
			return sprintf( esc_html__( 'تأمین از تأمین‌کننده؛ ارسال حدود %s روز کاری پس از ثبت سفارش', 'malidev-accounting' ), number_format_i18n( absint( $lead ) ) );
		}
		return esc_html__( 'تأمین از تأمین‌کننده پس از ثبت سفارش', 'malidev-accounting' );
	}
}
