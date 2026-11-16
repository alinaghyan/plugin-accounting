<?php
/**
 * Price manager screen («قیمت‌ها»): one grid for purchase price, sale prices and price levels of every
 * product and variation, with inline (jQuery) editing, bulk operations, CSV import/export and history.
 * Also adds the same fields to the single product edit screen.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Price_Admin {
	public const PAGE = 'mda-prices';
	public const CAP  = 'edit_products';
	private const PER_PAGE_MAX = 200;

	public static function init(): void {
		add_action( 'wp_ajax_mda_price_history', array( __CLASS__, 'ajax_history' ) );
		add_action( 'admin_post_mda_prices_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_mda_prices_import', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_mda_save_price_levels', array( __CLASS__, 'save_levels' ) );
		// Single product screen.
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'variation_save' ), 10, 2 );
		// Last purchase price follows posted purchase invoices.
		add_action( 'mda_purchase_posted', array( __CLASS__, 'sync_purchase_price' ), 10, 2 );
	}

	private static function can(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
	}

	/* ------------------------------------------------------------------ query */

	private static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$src = $_GET;
		if ( wp_doing_ajax() ) {
			$src = isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		}
		// phpcs:enable
		return array(
			's'        => sanitize_text_field( wp_unslash( (string) ( $src['s'] ?? '' ) ) ),
			'cat'      => sanitize_title( wp_unslash( (string) ( $src['cat'] ?? '' ) ) ),
			'stock'    => in_array( $src['stock'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ), true ) ? (string) $src['stock'] : '',
			'missing'  => sanitize_key( (string) ( $src['missing'] ?? '' ) ),
			'per_page' => max( 10, min( self::PER_PAGE_MAX, absint( $src['per_page'] ?? 50 ) ?: 50 ) ),
			'paged'    => max( 1, absint( $src['paged'] ?? 1 ) ),
		);
	}

	/**
	 * @return array{products:WC_Product[],total:int,pages:int}
	 */
	private static function query( array $f, ?int $page = null, ?int $per_page = null ): array {
		$args = array(
			'limit'    => $per_page ?? $f['per_page'],
			'page'     => $page ?? $f['paged'],
			'paginate' => true,
			'status'   => array( 'publish', 'private', 'draft', 'pending' ),
			'orderby'  => 'title',
			'order'    => 'ASC',
			'type'     => array( 'simple', 'variable', 'external' ),
		);
		if ( '' !== $f['s'] ) {
			$args['s'] = $f['s'];
		}
		if ( '' !== $f['cat'] ) {
			$args['category'] = array( $f['cat'] );
		}
		if ( '' !== $f['stock'] ) {
			$args['stock_status'] = $f['stock'];
		}
		if ( preg_match( '/^(purchase|p\d{1,2})$/', $f['missing'] ) ) {
			$key                  = 'purchase' === $f['missing'] ? MDA_Price_Service::META_PURCHASE : MDA_Price_Service::META_PREFIX . $f['missing'];
			$args['meta_query'][] = array( 'key' => $key, 'compare' => 'NOT EXISTS' ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		// SKU search as well as title.
		if ( '' !== $f['s'] && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$by_sku = wc_get_product_id_by_sku( $f['s'] );
			if ( $by_sku ) {
				$product = wc_get_product( $by_sku );
				if ( $product && $product->is_type( 'variation' ) ) {
					$by_sku = $product->get_parent_id();
				}
				unset( $args['s'] );
				$args['include'] = array( $by_sku );
			}
		}
		$result = wc_get_products( $args );
		return array( 'products' => $result->products, 'total' => (int) $result->total, 'pages' => (int) $result->max_num_pages );
	}

	/** Parent product followed by its variations. */
	private static function rows( array $products ): array {
		$rows = array();
		foreach ( $products as $product ) {
			$rows[] = $product;
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( $child ) {
						$rows[] = $child;
					}
				}
			}
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ screen */

	public static function render_levels_form(): void {
		if ( ! current_user_can( MDA_Capabilities::MANAGE ) ) {
			return;
		}
		$roles = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		unset( $roles['administrator'] );
		echo '<div class="mda-card"><h2>سطوح قیمت</h2><p class="description">برای هر سطح، نقش‌های کاربری (مثلاً «همکار» یا «عمده‌فروش») را انتخاب کنید تا همان قیمت در فروشگاه به آن‌ها نمایش داده شود. «حداقل تعداد» برای تخفیف پلکانی است: اگر خریدار به این تعداد از یک کالا بخرد، این قیمت در سبد اعمال می‌شود (در صورت انتخاب نقش، فقط برای آن نقش‌ها). پایین‌ترین قیمت قابل اعمال انتخاب می‌شود.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mda_save_price_levels' );
		echo '<input type="hidden" name="action" value="mda_save_price_levels"><table class="widefat"><thead><tr><th>کلید</th><th>نام سطح</th><th>نقش‌های کاربری</th><th>حداقل تعداد</th></tr></thead><tbody>';
		$levels = MDA_Price_Service::levels();
		$i      = 0;
		foreach ( $levels + array( '' => array( 'label' => '', 'roles' => array(), 'min_qty' => 0 ) ) as $key => $level ) {
			echo '<tr><td><code>' . esc_html( $key ?: 'جدید' ) . '</code><input type="hidden" name="levels[' . (int) $i . '][key]" value="' . esc_attr( $key ) . '"></td>';
			echo '<td><input name="levels[' . (int) $i . '][label]" value="' . esc_attr( $level['label'] ) . '" placeholder="مثلاً: قیمت ۳ (نماینده)"></td><td><select name="levels[' . (int) $i . '][roles][]" multiple size="3">';
			foreach ( $roles as $role => $role_name ) {
				echo '<option value="' . esc_attr( $role ) . '"' . ( in_array( $role, $level['roles'], true ) ? ' selected' : '' ) . '>' . esc_html( translate_user_role( $role_name ) ) . '</option>';
			}
			echo '</select></td><td><input type="number" min="0" name="levels[' . (int) $i . '][min_qty]" value="' . (int) $level['min_qty'] . '" style="width:80px"></td></tr>';
			++$i;
		}
		echo '</tbody></table><p class="description">برای حذف یک سطح، نام آن را خالی کنید. حداکثر ۱۰ سطح.</p><p><button class="button button-primary">ذخیره سطوح قیمت</button></p></form></div>';
	}

	/* ------------------------------------------------------------------ ajax */

	private static function ajax_guard(): void {
		check_ajax_referer( 'mda_grid', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	public static function ajax_history(): void {
		self::ajax_guard();
		$id   = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$rows = array();
		foreach ( MDA_Price_Service::history( $id, 60 ) as $r ) {
			$rows[] = array(
				'field' => MDA_Price_Service::fields()[ $r['field_key'] ] ?? $r['field_key'],
				'old'   => '' === (string) $r['old_value'] ? '—' : number_format( (float) $r['old_value'] ),
				'new'   => '' === (string) $r['new_value'] ? '—' : number_format( (float) $r['new_value'] ),
				'who'   => $r['user_id'] ? ( get_userdata( (int) $r['user_id'] )->display_name ?? '' ) : 'سیستم',
				'when'  => MDA_Jalali::format( substr( (string) $r['created_at'], 0, 10 ) ) . ' ' . substr( (string) $r['created_at'], 11, 5 ),
				'src'   => array( 'grid' => 'جدول', 'bulk' => 'گروهی', 'csv' => 'CSV', 'purchase' => 'فاکتور خرید', 'product_edit' => 'صفحه محصول', 'single' => 'تکی' )[ $r['source'] ] ?? $r['source'],
			);
		}
		wp_send_json_success( $rows );
	}

	private static function row_values( WC_Product $product ): array {
		$out = array();
		foreach ( array_keys( MDA_Price_Service::fields() ) as $key ) {
			$out[ $key ] = MDA_Price_Service::get( $product, $key );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ CSV */

	public static function export(): void {
		self::can();
		check_admin_referer( 'mda_prices_export' );
		$f      = self::filters();
		$fields = MDA_Price_Service::fields();
		$rows   = array();
		$page   = 1;
		do {
			$data = self::query( $f, $page, 100 );
			foreach ( self::rows( $data['products'] ) as $p ) {
				$row = array( $p->get_id(), $p->get_sku(), $p->is_type( 'variation' ) ? wc_get_formatted_variation( $p, true, false, false ) : $p->get_name() );
				foreach ( array_keys( $fields ) as $key ) {
					$row[] = MDA_Price_Service::get( $p, $key );
				}
				$rows[] = $row;
			}
			++$page;
		} while ( $page <= $data['pages'] && $page <= 200 );
		MDA_Ledger_Report_Service::stream_csv( 'prices-' . gmdate( 'Ymd' ) . '.csv', array_merge( array( 'id', 'sku', 'name' ), array_keys( $fields ) ), $rows );
	}

	public static function import(): void {
		self::can();
		check_admin_referer( 'mda_prices_import' );
		$back = admin_url( 'admin.php?page=mda-products' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file upload array, validated below.
		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 5 * MB_IN_BYTES || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'mda_error', rawurlencode( 'فایل CSV معتبر نیست (حداکثر ۵ مگابایت).' ), $back ) );
			exit;
		}
		$handle = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$header = $handle ? fgetcsv( $handle ) : false;
		if ( ! $header ) {
			wp_safe_redirect( add_query_arg( 'mda_error', rawurlencode( 'فایل خالی است.' ), $back ) );
			exit;
		}
		$header = array_map( static fn( $h ) => strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $header );
		$fields = array_intersect( $header, array_keys( MDA_Price_Service::fields() ) );
		$ok     = 0;
		$errors = array();
		$line   = 1;
		while ( ( $cols = fgetcsv( $handle ) ) !== false && $line < 20000 ) {
			++$line;
			$row = array_combine( $header, array_pad( array_slice( $cols, 0, count( $header ) ), count( $header ), '' ) );
			$id  = absint( $row['id'] ?? 0 );
			if ( ! $id && ! empty( $row['sku'] ) ) {
				$id = (int) wc_get_product_id_by_sku( sanitize_text_field( $row['sku'] ) );
			}
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				$errors[] = 'سطر ' . $line . ': کالا پیدا نشد';
				continue;
			}
			$changes = array();
			foreach ( $fields as $key ) {
				$v = trim( (string) $row[ $key ] );
				if ( '' === $v ) {
					continue;
				}
				$changes[ $key ] = '-' === $v ? '' : $v;
			}
			try {
				if ( MDA_Price_Service::apply( $product, $changes, 'csv' ) ) {
					++$ok;
				}
			} catch ( Throwable $e ) {
				$errors[] = 'سطر ' . $line . ': ' . $e->getMessage();
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$msg = $ok . ' کالا به‌روز شد.' . ( $errors ? ' ' . count( $errors ) . ' خطا: ' . implode( '؛ ', array_slice( $errors, 0, 5 ) ) : '' );
		wp_safe_redirect( add_query_arg( $errors && ! $ok ? 'mda_error' : 'mda_notice', rawurlencode( $msg ), $back ) );
		exit;
	}

	public static function save_levels(): void {
		if ( ! current_user_can( MDA_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
		check_admin_referer( 'mda_save_price_levels' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save_levels().
		MDA_Price_Service::save_levels( isset( $_POST['levels'] ) && is_array( $_POST['levels'] ) ? wp_unslash( $_POST['levels'] ) : array() );
		wp_safe_redirect( add_query_arg( 'mda_notice', rawurlencode( 'سطوح قیمت ذخیره شد.' ), admin_url( 'admin.php?page=mda-products' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ product screen */

	public static function product_fields(): void {
		global $product_object;
		if ( ! $product_object instanceof WC_Product ) {
			return;
		}
		echo '<div class="options_group mda-price-fields">';
		wp_nonce_field( 'mda_product_prices', 'mda_product_prices_nonce' );
		self::field_inputs( $product_object, 'mda_prices', '' );
		echo '</div>';
	}

	public static function variation_fields( $loop, $variation_data, $variation ): void {
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}
		echo '<div class="mda-variation-prices">';
		self::field_inputs( $product, 'mda_var_prices[' . (int) $loop . ']', 'form-row form-row-first' );
		echo '</div>';
	}

	private static function field_inputs( WC_Product $product, string $name, string $wrapper ): void {
		$label = MDA_Settings::currency_label();
		foreach ( MDA_Price_Service::fields() as $key => $text ) {
			if ( in_array( $key, array( 'regular', 'sale' ), true ) ) {
				continue;
			}
			woocommerce_wp_text_input(
				array(
					'id'            => $name . '_' . $key . '_' . $product->get_id(),
					'name'          => $name . '[' . $key . ']',
					'label'         => $text . ' (' . $label . ')',
					'value'         => MDA_Price_Service::get( $product, $key ),
					'data_type'     => 'price',
					'wrapper_class' => $wrapper,
					'desc_tip'      => 'purchase' === $key,
					'description'   => 'purchase' === $key ? 'آخرین قیمت خرید؛ با ثبت فاکتور خرید در حسابداری خودکار به‌روز می‌شود.' : '',
				)
			);
		}
	}

	public static function product_save( WC_Product $product ): void {
		if ( ! isset( $_POST['mda_product_prices_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mda_product_prices_nonce'] ) ), 'mda_product_prices' ) ) {
			return;
		}
		$values = isset( $_POST['mda_prices'] ) && is_array( $_POST['mda_prices'] ) ? wp_unslash( $_POST['mda_prices'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::set_meta_only( $product, $values );
	}

	public static function variation_save( WC_Product $variation, int $loop ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the variations nonce before this hook.
		$all    = isset( $_POST['mda_var_prices'] ) && is_array( $_POST['mda_var_prices'] ) ? wp_unslash( $_POST['mda_var_prices'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$values = isset( $all[ $loop ] ) && is_array( $all[ $loop ] ) ? $all[ $loop ] : array();
		self::set_meta_only( $variation, $values );
	}

	/** WooCommerce saves the object itself after these hooks; only meta is set and history logged. */
	private static function set_meta_only( WC_Product $product, array $values ): void {
		$changes = array();
		foreach ( MDA_Price_Service::fields() as $key => $text ) {
			if ( in_array( $key, array( 'regular', 'sale' ), true ) || ! array_key_exists( $key, $values ) ) {
				continue;
			}
			try {
				$new = MDA_Price_Service::clean( $values[ $key ] );
			} catch ( Throwable $e ) {
				continue;
			}
			$old = MDA_Price_Service::get( $product, $key );
			if ( (string) $old === $new ) {
				continue;
			}
			$meta = 'purchase' === $key ? MDA_Price_Service::META_PURCHASE : MDA_Price_Service::META_PREFIX . $key;
			'' === $new ? $product->delete_meta_data( $meta ) : $product->update_meta_data( $meta, $new );
			$changes[] = array( $key, $old, $new );
		}
		if ( $changes ) {
			MDA_Price_Service::log_changes( (int) $product->get_id(), $changes, 'product_edit' );
		}
	}

	/**
	 * After a purchase invoice is posted, its unit cost becomes the product's last purchase price.
	 */
	public static function sync_purchase_price( int $purchase_id, array $lines ): void {
		foreach ( $lines as $line ) {
			$product = wc_get_product( (int) ( $line['variation_id'] ?: $line['product_id'] ) );
			if ( ! $product ) {
				continue;
			}
			try {
				MDA_Price_Service::apply( $product, array( 'purchase' => (string) (int) $line['unit_cost_minor'] ), 'purchase' );
			} catch ( Throwable $e ) {
				error_log( 'MaliDev Accounting purchase price sync: ' . $e->getMessage() ); // phpcs:ignore
			}
		}
	}
}
