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
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'wp_ajax_mda_prices_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_mda_prices_bulk', array( __CLASS__, 'ajax_bulk' ) );
		add_action( 'wp_ajax_mda_price_history', array( __CLASS__, 'ajax_history' ) );
		add_action( 'admin_post_mda_prices_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_mda_prices_import', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_mda_save_price_levels', array( __CLASS__, 'save_levels' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		// Single product screen.
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'variation_save' ), 10, 2 );
		// Last purchase price follows posted purchase invoices.
		add_action( 'mda_purchase_posted', array( __CLASS__, 'sync_purchase_price' ), 10, 2 );
	}

	public static function menu(): void {
		add_submenu_page( MDA_Admin_Shell::HIDDEN_PARENT, 'مدیریت قیمت‌ها', 'قیمت‌ها', self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script( 'mda-prices', MDA_URL . 'assets/js/prices.js', array( 'jquery', 'mda-admin' ), (string) filemtime( MDA_PATH . 'assets/js/prices.js' ), true );
		wp_localize_script(
			'mda-prices',
			'MDAPrices',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'mda_prices' ),
				'fields' => MDA_Price_Service::fields(),
				'i18n'   => array(
					'saved'    => 'تغییرات ذخیره شد',
					'nothing'  => 'تغییری برای ذخیره وجود ندارد',
					'confirm'  => 'این عملیات روی %s کالا اعمال می‌شود. ادامه می‌دهید؟',
					'leave'    => 'تغییرات ذخیره‌نشده دارید.',
					'progress' => 'در حال اعمال: %1$s از %2$s',
					'error'    => 'خطا: ',
				),
			)
		);
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

	public static function render(): void {
		self::can();
		$f      = self::filters();
		$data   = self::query( $f );
		$rows   = self::rows( $data['products'] );
		$fields = MDA_Price_Service::fields();
		$costs  = MDA_Price_Service::accounting_costs( array_map( static fn( $p ) => $p->get_id(), $rows ) );
		$cats   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ) );
		$label  = MDA_Settings::currency_label();
		echo '<div class="wrap" dir="rtl"><h1>مدیریت قیمت‌ها</h1><p>همه قیمت‌های هر کالا در یک جدول: قیمت خرید، قیمت فروش، قیمت ویژه و سطوح قیمت (عمده، همکار و …). خانه‌ها را مستقیم ویرایش کنید یا چند کالا را انتخاب و تغییر گروهی بدهید. مبالغ به ' . esc_html( $label ) . ' است.</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'mda_notice' => 'success', 'mda_error' => 'error' ) as $k => $t ) {
			if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="notice notice-' . esc_attr( $t ) . ' is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		// Filters.
		echo '<div class="mda-card mda-price-filters"><form method="get" class="mda-filter"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" placeholder="نام یا SKU" class="regular-text"> ';
		echo '<select name="cat"><option value="">همه دسته‌ها</option>';
		if ( is_array( $cats ) ) {
			foreach ( $cats as $c ) {
				echo '<option value="' . esc_attr( $c->slug ) . '"' . selected( $f['cat'], $c->slug, false ) . '>' . esc_html( $c->name ) . '</option>';
			}
		}
		echo '</select> <select name="stock"><option value="">هر وضعیت موجودی</option><option value="instock"' . selected( $f['stock'], 'instock', false ) . '>موجود</option><option value="outofstock"' . selected( $f['stock'], 'outofstock', false ) . '>ناموجود</option><option value="onbackorder"' . selected( $f['stock'], 'onbackorder', false ) . '>پیش‌خرید / سفارشی</option></select> ';
		echo '<select name="missing"><option value="">همه کالاها</option>';
		foreach ( $fields as $key => $name ) {
			if ( in_array( $key, array( 'regular', 'sale' ), true ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $f['missing'], $key, false ) . '>بدون ' . esc_html( $name ) . '</option>';
		}
		echo '</select> <select name="per_page">';
		foreach ( array( 25, 50, 100, 200 ) as $n ) {
			echo '<option value="' . (int) $n . '"' . selected( $f['per_page'], $n, false ) . '>' . (int) $n . ' در صفحه</option>';
		}
		echo '</select> <button class="button">فیلتر</button></form></div>';

		// Bulk toolbar.
		echo '<div class="mda-card mda-bulk-bar" id="mda-bulk"><div class="mda-bulk-bar__row">';
		echo '<strong class="mda-bulk-count">۰ کالا انتخاب شده</strong> <label><input type="checkbox" id="mda-all-matching"> همه ' . esc_html( number_format_i18n( $data['total'] ) ) . ' کالای مطابق فیلتر (همه صفحات)</label></div><div class="mda-bulk-bar__row">';
		echo '<select id="mda-bulk-field">';
		foreach ( $fields as $key => $name ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $name ) . '</option>';
		}
		echo '</select> <select id="mda-bulk-op"><option value="set">برابر با</option><option value="inc_pct">افزایش درصدی</option><option value="dec_pct">کاهش درصدی</option><option value="inc_amount">افزایش مبلغ</option><option value="dec_amount">کاهش مبلغ</option><option value="margin">قیمت خرید + درصد سود</option><option value="copy">از ستون دیگر ± درصد</option><option value="clear">پاک کردن</option></select> ';
		echo '<select id="mda-bulk-source" hidden>';
		foreach ( $fields as $key => $name ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $name ) . '</option>';
		}
		echo '</select> <input id="mda-bulk-value" class="mda-bulk-value" dir="ltr" inputmode="decimal" placeholder="مقدار یا درصد (مثلاً -5)" size="16"> ';
		echo '<select id="mda-bulk-round"><option value="0">بدون گرد کردن</option><option value="1000">گرد به ۱٬۰۰۰</option><option value="10000">گرد به ۱۰٬۰۰۰</option><option value="100000">گرد به ۱۰۰٬۰۰۰</option><option value="1000000">گرد به ۱٬۰۰۰٬۰۰۰</option></select> ';
		echo '<button type="button" class="button" id="mda-bulk-preview">پیش‌نمایش در جدول</button> <button type="button" class="button button-primary" id="mda-bulk-apply">اعمال و ذخیره</button></div></div>';

		// Grid.
		echo '<div class="mda-card mda-price-card"><div class="mda-price-actions"><button type="button" class="button button-primary" id="mda-save" disabled>ذخیره تغییرات <span class="mda-dirty-count"></span></button> <button type="button" class="button" id="mda-undo" disabled>لغو تغییرات</button>';
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'mda_prices_export' ), array_intersect_key( $f, array_flip( array( 's', 'cat', 'stock', 'missing' ) ) ) ), admin_url( 'admin-post.php' ) ), 'mda_prices_export' );
		echo ' <a class="button" href="' . esc_url( $export ) . '">خروجی اکسل (CSV)</a> <button type="button" class="button" data-bs-toggle="collapse" data-bs-target="#mda-import" aria-expanded="false">ورود از CSV</button>';
		echo ' <span class="mda-legend"><i class="is-low"></i> سود کمتر از ۵٪ <i class="is-neg"></i> زیر قیمت خرید</span></div>';
		echo '<div class="collapse" id="mda-import"><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mda-inline">';
		wp_nonce_field( 'mda_prices_import' );
		echo '<input type="hidden" name="action" value="mda_prices_import"><input type="file" name="file" accept=".csv,text/csv" required> <button class="button">بارگذاری و اعمال</button><span class="description">ستون‌ها مثل فایل خروجی: id یا sku و ستون‌های قیمت. خانه خالی = بدون تغییر، «-» = پاک کردن.</span></form></div>';

		echo '<div class="mda-table-wrap"><table class="widefat mda-price-grid" id="mda-price-grid"><thead><tr><th class="check-column"><input type="checkbox" id="mda-check-all" aria-label="انتخاب همه"></th><th>کالا</th><th>SKU</th><th>موجودی</th><th title="میانگین موزون از دفاتر حسابداری">بهای تمام‌شده</th>';
		foreach ( $fields as $key => $name ) {
			echo '<th data-field="' . esc_attr( $key ) . '">' . esc_html( $name ) . '</th>';
		}
		echo '<th>سود</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . (int) ( count( $fields ) + 7 ) . '" class="mda-muted">کالایی پیدا نشد.</td></tr>';
		}
		foreach ( $rows as $p ) {
			$id     = (int) $p->get_id();
			$is_var = $p->is_type( 'variation' );
			$is_par = $p->is_type( 'variable' );
			$name   = $is_var ? '↳ ' . wc_get_formatted_variation( $p, true, false, false ) : $p->get_name();
			$stock  = $p->managing_stock() ? wc_stock_amount( $p->get_stock_quantity() ) : ( 'instock' === $p->get_stock_status() ? 'موجود' : ( 'onbackorder' === $p->get_stock_status() ? 'سفارشی' : 'ناموجود' ) );
			$cost   = $costs[ $id ]['avg'] ?? null;
			echo '<tr data-id="' . (int) $id . '" class="' . ( $is_var ? 'is-variation' : '' ) . ( $is_par ? ' is-parent' : '' ) . '"><th class="check-column"><input type="checkbox" class="mda-row-check" value="' . (int) $id . '"></th>';
			echo '<td class="mda-name"><a href="' . esc_url( get_edit_post_link( $is_var ? $p->get_parent_id() : $id ) ?: '#' ) . '">' . esc_html( $name ) . '</a>' . ( 'publish' !== $p->get_status() && ! $is_var ? ' <span class="mda-chip">' . esc_html( $p->get_status() ) . '</span>' : '' ) . '</td>';
			echo '<td dir="ltr">' . esc_html( $p->get_sku() ) . '</td><td>' . esc_html( (string) $stock ) . '</td><td class="mda-cost" data-cost="' . esc_attr( null === $cost ? '' : (string) $cost ) . '">' . ( null === $cost ? '<span class="mda-muted">—</span>' : esc_html( number_format( $cost ) ) ) . '</td>';
			foreach ( $fields as $key => $label_text ) {
				if ( $is_par && in_array( $key, array( 'regular', 'sale' ), true ) ) {
					echo '<td class="mda-muted" title="قیمت محصول متغیر روی هر تنوع تعیین می‌شود">—</td>';
					continue;
				}
				$value = MDA_Price_Service::get( $p, $key );
				echo '<td><input class="mda-cell" data-field="' . esc_attr( $key ) . '" data-original="' . esc_attr( $value ) . '" value="' . esc_attr( '' === $value ? '' : number_format( (float) $value ) ) . '" inputmode="numeric" aria-label="' . esc_attr( $label_text ) . '"></td>';
			}
			echo '<td class="mda-margin"></td><td><button type="button" class="button-link mda-history" data-id="' . (int) $id . '" title="تاریخچه قیمت"><span class="dashicons dashicons-backup"></span></button></td></tr>';
		}
		echo '</tbody></table></div>';

		// Pagination.
		if ( $data['pages'] > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $f['paged'],
						'total'   => $data['pages'],
					)
				)
			) . '</div></div>';
		}
		echo '</div>';

		self::render_levels_form();
		echo '<div class="mda-history-modal" id="mda-history-modal" hidden><div class="mda-history-modal__box"><button type="button" class="mda-history-close" aria-label="بستن">×</button><h2>تاریخچه قیمت</h2><div class="mda-history-body"></div></div></div>';
		echo '</div>';
	}

	private static function render_levels_form(): void {
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
		check_ajax_referer( 'mda_prices', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	public static function ajax_save(): void {
		self::ajax_guard();
		$changes = isset( $_POST['changes'] ) && is_array( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field by MDA_Price_Service::clean().
		$saved   = array();
		$errors  = array();
		foreach ( array_slice( $changes, 0, 500, true ) as $id => $fields ) {
			$product = wc_get_product( absint( $id ) );
			if ( ! $product || ! is_array( $fields ) || ! current_user_can( 'edit_product', $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() ) ) {
				$errors[ absint( $id ) ] = 'کالا پیدا نشد یا اجازه ویرایش ندارید.';
				continue;
			}
			try {
				MDA_Price_Service::apply( $product, array_map( 'strval', $fields ), 'grid' );
				$saved[ absint( $id ) ] = self::row_values( $product );
			} catch ( Throwable $e ) {
				$errors[ absint( $id ) ] = $e->getMessage();
			}
		}
		wp_send_json_success( array( 'saved' => $saved, 'errors' => $errors ) );
	}

	/**
	 * Server-side bulk operation for "all products matching the filter", processed in batches by the browser.
	 */
	public static function ajax_bulk(): void {
		self::ajax_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in ajax_guard().
		$field  = sanitize_key( wp_unslash( $_POST['field'] ?? '' ) );
		$op     = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
		$value  = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );
		$source = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
		$round  = absint( $_POST['round'] ?? 0 );
		$page   = max( 1, absint( $_POST['batch'] ?? 1 ) );
		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		// phpcs:enable
		if ( ! isset( MDA_Price_Service::fields()[ $field ] ) || ( 'copy' === $op && ! isset( MDA_Price_Service::fields()[ $source ] ) ) ) {
			wp_send_json_error( array( 'message' => 'ستون انتخاب‌شده معتبر نیست.' ), 400 );
		}
		$size = 40;
		if ( $ids ) {
			$targets = array_filter( array_map( 'wc_get_product', array_slice( $ids, ( $page - 1 ) * $size, $size ) ) );
			$total   = count( $ids );
			$pages   = (int) ceil( $total / $size );
		} else {
			$data    = self::query( self::filters(), $page, $size );
			$targets = self::rows( $data['products'] );
			$total   = $data['total'];
			$pages   = $data['pages'];
		}
		$changed = 0;
		$errors  = array();
		foreach ( $targets as $product ) {
			if ( $product->is_type( 'variable' ) && in_array( $field, array( 'regular', 'sale' ), true ) ) {
				continue;
			}
			try {
				$new = MDA_Price_Service::compute( $product, $field, $op, $value, $source, $round );
				if ( null === $new ) {
					continue;
				}
				if ( MDA_Price_Service::apply( $product, array( $field => $new ), 'bulk' ) ) {
					++$changed;
				}
			} catch ( Throwable $e ) {
				$errors[] = $product->get_name() . ': ' . $e->getMessage();
			}
		}
		wp_send_json_success( array( 'batch' => $page, 'pages' => max( 1, $pages ), 'total' => $total, 'changed' => $changed, 'errors' => array_slice( $errors, 0, 20 ) ) );
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
		$back = admin_url( 'admin.php?page=' . self::PAGE );
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
		wp_safe_redirect( add_query_arg( 'mda_notice', rawurlencode( 'سطوح قیمت ذخیره شد.' ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
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
