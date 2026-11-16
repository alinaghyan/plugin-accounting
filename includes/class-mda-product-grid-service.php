<?php
/**
 * Applies product edits coming from the product control grid (single cells and bulk operations).
 * Price fields go through MDA_Price_Service (validation + price history); everything else is set
 * with WooCommerce CRUD; the product is saved once.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Product_Grid_Service {
	public const META_MESSAGE = '_mda_price_message';

	/** Fields the grid can write besides price fields. */
	public const FIELDS = array( 'name', 'sku', 'status', 'manage_stock', 'stock_qty', 'stock_status', 'featured', 'sale_from', 'sale_to', 'cats', 'tags', 'message' );

	/**
	 * @param array<string,mixed> $changes
	 * @return string[] Human readable list of changed fields.
	 */
	public static function apply( WC_Product $product, array $changes, string $source = 'grid' ): array {
		$changed = array();
		$is_var  = $product->is_type( 'variation' );
		$prices  = array_intersect_key( $changes, MDA_Price_Service::fields() );

		foreach ( array_intersect_key( $changes, array_flip( self::FIELDS ) ) as $field => $value ) {
			switch ( $field ) {
				case 'name':
					$name = sanitize_text_field( (string) $value );
					if ( $is_var ) {
						break; // Variation names come from attributes.
					}
					if ( '' === $name ) {
						throw new InvalidArgumentException( 'نام محصول نمی‌تواند خالی باشد.' );
					}
					if ( $name !== $product->get_name( 'edit' ) ) {
						$product->set_name( $name );
						$changed[] = 'name';
					}
					break;
				case 'sku':
					$sku = wc_clean( (string) $value );
					if ( $sku !== $product->get_sku( 'edit' ) ) {
						$product->set_sku( $sku ); // Throws WC_Data_Exception on duplicates.
						$changed[] = 'sku';
					}
					break;
				case 'status':
					$status = $is_var ? ( 'publish' === $value ? 'publish' : 'private' ) : ( in_array( $value, array( 'publish', 'draft', 'private', 'pending' ), true ) ? $value : 'draft' );
					if ( $status !== $product->get_status( 'edit' ) ) {
						$product->set_status( $status );
						$changed[] = 'status';
					}
					break;
				case 'manage_stock':
					$on = (bool) $value;
					if ( $on !== $product->get_manage_stock( 'edit' ) ) {
						$product->set_manage_stock( $on );
						$changed[] = 'manage_stock';
					}
					break;
				case 'stock_qty':
					if ( self::accounting_managed( $product ) ) {
						throw new RuntimeException( 'موجودی «' . $product->get_name() . '» از دفاتر انبار حسابداری می‌آید؛ آن را با انبارگردانی یا خرید تغییر دهید.' );
					}
					$qty = wc_stock_amount( MDA_Jalali::latin_digits( (string) $value ) );
					if ( (float) $qty !== (float) $product->get_stock_quantity( 'edit' ) ) {
						$product->set_manage_stock( true );
						$product->set_stock_quantity( $qty );
						$changed[] = 'stock_qty';
					}
					break;
				case 'stock_status':
					if ( in_array( $value, array( 'instock', 'outofstock', 'onbackorder' ), true ) && $value !== $product->get_stock_status( 'edit' ) ) {
						$product->set_stock_status( $value );
						$changed[] = 'stock_status';
					}
					break;
				case 'featured':
					if ( ! $is_var && (bool) $value !== $product->get_featured( 'edit' ) ) {
						$product->set_featured( (bool) $value );
						$changed[] = 'featured';
					}
					break;
				case 'sale_from':
				case 'sale_to':
					$date = '' === (string) $value ? null : MDA_Jalali::normalize_input( (string) $value );
					if ( '' !== (string) $value && null === $date ) {
						throw new InvalidArgumentException( 'تاریخ زمان‌بندی تخفیف معتبر نیست.' );
					}
					$stamp = $date ? $date . ( 'sale_to' === $field ? ' 23:59:59' : ' 00:00:00' ) : '';
					if ( 'sale_from' === $field ) {
						$product->set_date_on_sale_from( $stamp ?: null );
					} else {
						$product->set_date_on_sale_to( $stamp ?: null );
					}
					$changed[] = $field;
					break;
				case 'cats':
				case 'tags':
					if ( $is_var ) {
						break;
					}
					$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
					$tax = 'cats' === $field ? 'product_cat' : 'product_tag';
					$ids = array_values( array_filter( $ids, static fn( $id ) => term_exists( $id, $tax ) ) );
					if ( 'cats' === $field ) {
						$product->set_category_ids( $ids );
					} else {
						$product->set_tag_ids( $ids );
					}
					$changed[] = $field;
					break;
				case 'message':
					$msg = self::clean_message( (array) $value );
					if ( $msg ) {
						$product->update_meta_data( self::META_MESSAGE, $msg );
					} else {
						$product->delete_meta_data( self::META_MESSAGE );
					}
					$changed[] = 'message';
					break;
			}
		}
		if ( $prices ) {
			$done = MDA_Price_Service::apply( $product, array_map( 'strval', $prices ), $source, false );
			foreach ( $done as $d ) {
				$changed[] = $d[0];
			}
		}
		$from = $product->get_date_on_sale_from( 'edit' );
		$to   = $product->get_date_on_sale_to( 'edit' );
		if ( $from && $to && $from->getTimestamp() > $to->getTimestamp() ) {
			throw new InvalidArgumentException( 'تاریخ شروع تخفیف بعد از تاریخ پایان است.' );
		}
		if ( $changed ) {
			$product->save();
		}
		return $changed;
	}

	/**
	 * Price-replacement message: shown on the storefront instead of price and add-to-cart.
	 *
	 * @return array{title:string,note:string,phone:string}|array{}
	 */
	public static function clean_message( array $raw ): array {
		if ( empty( $raw['enabled'] ) ) {
			return array();
		}
		$title = mb_substr( sanitize_text_field( (string) ( $raw['title'] ?? '' ) ), 0, 40 );
		if ( '' === $title ) {
			return array();
		}
		return array(
			'title' => $title,
			'note'  => mb_substr( sanitize_text_field( (string) ( $raw['note'] ?? '' ) ), 0, 40 ),
			'phone' => mb_substr( preg_replace( '/[^0-9+\-\s]/', '', MDA_Jalali::latin_digits( (string) ( $raw['phone'] ?? '' ) ) ), 0, 20 ),
		);
	}

	public static function message( WC_Product $product ): array {
		$source = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$msg    = $source ? $source->get_meta( self::META_MESSAGE, true ) : '';
		return is_array( $msg ) && ! empty( $msg['title'] ) ? $msg : array();
	}

	/** Stock of products that have an accounting cost state is owned by the stock ledger. */
	public static function accounting_managed( WC_Product $product ): bool {
		static $cache = array();
		$id = (int) $product->get_id();
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}
		$cache[ $id ] = false;
		if ( ! MDA_Database::is_available() ) {
			return false;
		}
		try {
			$db = MDA_Database::connection();
			$q  = $db->prepare( 'SELECT 1 FROM mda_product_cost_state WHERE (variation_id = :id) OR (product_id = :id AND variation_id = 0) LIMIT 1' );
			$q->bindValue( ':id', $id, PDO::PARAM_INT );
			$q->execute();
			$cache[ $id ] = (bool) $q->fetchColumn();
		} catch ( Throwable $e ) {
			$cache[ $id ] = false;
		}
		return $cache[ $id ];
	}

	/**
	 * Bulk operation spec → per-product change set (null = nothing to change for this product).
	 *
	 * @param array $spec ['status'?, 'stock_status'?, 'featured'?, 'cats_add'=>[], 'cats_remove'=>[], 'cats_clear'?, 'tags_add'..., 'price'=>[field,op,value,source,round], 'sale_from'?, 'sale_to'?, 'schedule_clear'?, 'message'=>[...]]
	 */
	public static function bulk_changes( WC_Product $product, array $spec ): ?array {
		$c      = array();
		$is_var = $product->is_type( 'variation' );
		if ( isset( $spec['status'] ) && in_array( $spec['status'], array( 'publish', 'draft' ), true ) ) {
			$c['status'] = $spec['status'];
		}
		if ( ! empty( $spec['stock_status'] ) ) {
			$c['stock_status'] = $spec['stock_status'];
		}
		if ( isset( $spec['featured'] ) && '' !== $spec['featured'] ) {
			$c['featured'] = (bool) $spec['featured'];
		}
		if ( ! $is_var ) {
			foreach ( array( 'cats' => 'get_category_ids', 'tags' => 'get_tag_ids' ) as $key => $getter ) {
				$add    = array_map( 'absint', (array) ( $spec[ $key . '_add' ] ?? array() ) );
				$remove = array_map( 'absint', (array) ( $spec[ $key . '_remove' ] ?? array() ) );
				$clear  = ! empty( $spec[ $key . '_clear' ] );
				if ( $add || $remove || $clear ) {
					$ids     = $clear ? array() : array_map( 'absint', $product->$getter( 'edit' ) );
					$c[ $key ] = array_values( array_diff( array_unique( array_merge( $ids, $add ) ), $remove ) );
				}
			}
			if ( isset( $spec['message'] ) && is_array( $spec['message'] ) ) {
				$c['message'] = $spec['message'];
			}
		}
		if ( ! empty( $spec['price'] ) && is_array( $spec['price'] ) ) {
			$p = wp_parse_args( $spec['price'], array( 'field' => '', 'op' => '', 'value' => '', 'source' => '', 'round' => 0 ) );
			if ( isset( MDA_Price_Service::fields()[ $p['field'] ] ) && ! ( $product->is_type( 'variable' ) && in_array( $p['field'], array( 'regular', 'sale' ), true ) ) ) {
				$new = MDA_Price_Service::compute( $product, $p['field'], (string) $p['op'], (string) $p['value'], (string) $p['source'], (int) $p['round'] );
				if ( null !== $new ) {
					$c[ $p['field'] ] = $new;
				}
			}
		}
		if ( ! empty( $spec['schedule_clear'] ) ) {
			$c['sale_from'] = '';
			$c['sale_to']   = '';
		} elseif ( ! empty( $spec['sale_from'] ) || ! empty( $spec['sale_to'] ) ) {
			$c['sale_from'] = (string) ( $spec['sale_from'] ?? '' );
			$c['sale_to']   = (string) ( $spec['sale_to'] ?? '' );
		}
		return $c ? $c : null;
	}
}
