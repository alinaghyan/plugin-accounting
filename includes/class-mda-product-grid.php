<?php
/**
 * «اتاق کنترل محصولات»: one grid for every product and variation — image, type, status, stock,
 * featured, prices (with discount / profit badges), sale schedule, price levels, categories, tags and
 * SKU — with spreadsheet-like inline editing, quick filters with counts, sorting and a bulk-edit panel.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Product_Grid {
	public const PAGE = 'mda-products';
	public const CAP  = 'edit_products';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'wp_ajax_mda_grid_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_mda_grid_bulk', array( __CLASS__, 'ajax_bulk' ) );
		add_action( 'wp_ajax_mda_grid_terms', array( __CLASS__, 'ajax_terms' ) );
		add_action( 'wp_ajax_mda_grid_add_term', array( __CLASS__, 'ajax_add_term' ) );
		add_action( 'wp_ajax_mda_grid_variations', array( __CLASS__, 'ajax_variations' ) );
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'meta_query_var' ), 10, 2 );
	}

	/** wc_get_products() ignores raw meta_query; our private query var is translated here. */
	public static function meta_query_var( array $query, array $vars ): array {
		if ( ! empty( $vars['mda_meta_query'] ) && is_array( $vars['mda_meta_query'] ) ) {
			$query['meta_query'] = array_merge( $query['meta_query'] ?? array(), $vars['mda_meta_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return $query;
	}

	public static function menu(): void {
		add_submenu_page( MDA_Admin_Shell::HIDDEN_PARENT, 'اتاق کنترل محصولات', 'محصولات و قیمت‌ها', self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
		// Old slug of the price manager (bookmarks, links in notices) forwards to the grid.
		$old = add_submenu_page( MDA_Admin_Shell::HIDDEN_PARENT, 'اتاق کنترل محصولات', '', self::CAP, 'mda-prices', '__return_null' );
		if ( $old ) {
			add_action(
				'load-' . $old,
				static function (): void {
					wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
					exit;
				}
			);
		}
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'mda-product-grid', MDA_URL . 'assets/css/product-grid.css', array( 'mda-admin' ), (string) filemtime( MDA_PATH . 'assets/css/product-grid.css' ) );
		wp_enqueue_script( 'mda-product-grid', MDA_URL . 'assets/js/product-grid.js', array( 'jquery', 'mda-admin', 'mda-jalali' ), (string) filemtime( MDA_PATH . 'assets/js/product-grid.js' ), true );
		wp_localize_script(
			'mda-product-grid',
			'MDAGrid',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'mda_grid' ),
				'fields'   => MDA_Price_Service::fields(),
				'currency' => MDA_Settings::currency_label(),
				'canTerms' => current_user_can( 'manage_product_terms' ),
			)
		);
	}

	/* ------------------------------------------------------------------ query */

	public static function filters( ?array $src = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$src     = $src ?? wp_unslash( $_GET );
		$quick   = sanitize_key( (string) ( $src['quick'] ?? '' ) );
		$orderby = sanitize_key( (string) ( $src['orderby'] ?? 'date' ) );
		return array(
			's'        => sanitize_text_field( (string) ( $src['s'] ?? '' ) ),
			'cat'      => sanitize_title( (string) ( $src['cat'] ?? '' ) ),
			'quick'    => in_array( $quick, array( 'instock', 'outofstock', 'active', 'inactive', 'onsale', 'nosale', 'message', 'nopurchase' ), true ) ? $quick : '',
			'orderby'  => in_array( $orderby, array( 'title', 'id', 'date', 'modified', 'sku', 'price' ), true ) ? $orderby : 'date',
			'order'    => 'asc' === strtolower( (string) ( $src['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC',
			'per_page' => max( 10, min( 200, absint( $src['per_page'] ?? 30 ) ?: 30 ) ),
			'paged'    => max( 1, absint( $src['paged'] ?? 1 ) ),
		);
	}

	private static function args( array $f ): array {
		$args = array(
			'status'   => array( 'publish', 'private', 'draft', 'pending' ),
			'type'     => array( 'simple', 'variable', 'external', 'grouped' ),
			'paginate' => true,
			'limit'    => $f['per_page'],
			'page'     => $f['paged'],
		);
		$map  = array( 'title' => 'title', 'id' => 'ID', 'date' => 'date', 'modified' => 'modified' );
		if ( isset( $map[ $f['orderby'] ] ) ) {
			$args['orderby'] = $map[ $f['orderby'] ];
			$args['order']   = $f['order'];
		} else {
			$args['orderby']  = 'meta_value' . ( 'price' === $f['orderby'] ? '_num' : '' );
			$args['meta_key'] = 'price' === $f['orderby'] ? '_price' : '_sku'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['order']    = $f['order'];
		}
		if ( '' !== $f['s'] ) {
			if ( ctype_digit( $f['s'] ) && wc_get_product( (int) $f['s'] ) ) {
				$p               = wc_get_product( (int) $f['s'] );
				$args['include'] = array( $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id() );
			} elseif ( $by_sku = wc_get_product_id_by_sku( $f['s'] ) ) {
				$p               = wc_get_product( $by_sku );
				$args['include'] = array( $p && $p->is_type( 'variation' ) ? $p->get_parent_id() : $by_sku );
			} else {
				$args['s'] = $f['s'];
			}
		}
		if ( '' !== $f['cat'] ) {
			$args['category'] = array( $f['cat'] );
		}
		switch ( $f['quick'] ) {
			case 'instock':
			case 'outofstock':
				$args['stock_status'] = $f['quick'];
				break;
			case 'active':
				$args['status'] = array( 'publish' );
				break;
			case 'inactive':
				$args['status'] = array( 'draft', 'private', 'pending' );
				break;
			case 'onsale':
			case 'nosale':
				$sale = array_map( 'intval', wc_get_product_ids_on_sale() );
				$parents = array();
				foreach ( $sale as $id ) {
					$p = wc_get_product( $id );
					$parents[] = $p && $p->is_type( 'variation' ) ? $p->get_parent_id() : $id;
				}
				$parents = array_values( array_unique( $parents ) ) ?: array( 0 );
				$args[ 'onsale' === $f['quick'] ? 'include' : 'exclude' ] = $parents;
				break;
			case 'message':
				$args['mda_meta_query'][] = array( 'key' => MDA_Product_Grid_Service::META_MESSAGE, 'compare' => 'EXISTS' );
				break;
			case 'nopurchase':
				$args['mda_meta_query'][] = array( 'key' => MDA_Price_Service::META_PURCHASE, 'compare' => 'NOT EXISTS' );
				break;
		}
		return $args;
	}

	public static function query( array $f ): array {
		$r = wc_get_products( self::args( $f ) );
		return array( 'products' => $r->products, 'total' => (int) $r->total, 'pages' => (int) $r->max_num_pages );
	}

	/** Counts for the quick-filter chips (cached for 5 minutes). */
	private static function counts(): array {
		$cached = get_transient( 'mda_grid_counts' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$count = static function ( array $extra ): int {
			$r = wc_get_products(
				array_merge(
					array( 'status' => array( 'publish', 'private', 'draft', 'pending' ), 'type' => array( 'simple', 'variable', 'external', 'grouped' ), 'limit' => 1, 'paginate' => true, 'return' => 'ids' ),
					$extra
				)
			);
			return (int) $r->total;
		};
		$all     = $count( array() );
		$onsale  = count( self::args( array_merge( self::filters( array() ), array( 'quick' => 'onsale' ) ) )['include'] ?? array() );
		$counts  = array(
			'instock'    => $count( array( 'stock_status' => 'instock' ) ),
			'outofstock' => $count( array( 'stock_status' => 'outofstock' ) ),
			'active'     => $count( array( 'status' => array( 'publish' ) ) ),
			'inactive'   => $count( array( 'status' => array( 'draft', 'private', 'pending' ) ) ),
			'onsale'     => $onsale,
			'nosale'     => max( 0, $all - $onsale ),
			'message'    => $count( array( 'mda_meta_query' => array( array( 'key' => MDA_Product_Grid_Service::META_MESSAGE, 'compare' => 'EXISTS' ) ) ) ),
			'all'        => $all,
		);
		set_transient( 'mda_grid_counts', $counts, 5 * MINUTE_IN_SECONDS );
		return $counts;
	}

	/* ------------------------------------------------------------------ render */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
		$f      = self::filters();
		$data   = self::query( $f );
		$counts = self::counts();
		$fields = MDA_Price_Service::fields();
		$cats   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ) );
		$base   = array_filter( array( 'page' => self::PAGE, 's' => $f['s'], 'cat' => $f['cat'], 'per_page' => 30 !== $f['per_page'] ? $f['per_page'] : null ) );
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'mda_prices_export' ), array_intersect_key( $f, array_flip( array( 's', 'cat' ) ) ) ), admin_url( 'admin-post.php' ) ), 'mda_prices_export' );

		echo '<div class="wrap mda-grid-wrap" dir="rtl"><h1 class="screen-reader-text">اتاق کنترل محصولات</h1>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'mda_notice' => 'success', 'mda_error' => 'error' ) as $k => $t ) {
			if ( isset( $_GET[ $k ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $t ) . ' is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ) ) . '</p></div>';
			}
		}
		// phpcs:enable

		// Top bar.
		echo '<div class="mdg-topbar"><span class="mdg-brand"><i></i>اتاق کنترل محصولات</span>';
		echo '<form method="get" class="mdg-search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><span class="dashicons dashicons-search"></span><input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" placeholder="جستجوی نام محصول، SKU یا ID…">';
		echo '<select name="cat" onchange="this.form.submit()"><option value="">همه دسته‌ها</option>';
		if ( is_array( $cats ) ) {
			foreach ( $cats as $c ) {
				echo '<option value="' . esc_attr( $c->slug ) . '"' . selected( $f['cat'], $c->slug, false ) . '>' . esc_html( $c->name ) . '</option>';
			}
		}
		echo '</select></form>';
		echo '<div class="mdg-top-actions"><a class="mdg-btn" href="' . esc_url( admin_url( 'post-new.php?post_type=product' ) ) . '"><span class="dashicons dashicons-plus-alt2"></span> افزودن محصول</a>';
		echo '<div class="mdg-dropdown"><button type="button" class="mdg-btn" data-mdg-toggle><span class="dashicons dashicons-media-spreadsheet"></span> CSV <span class="dashicons dashicons-arrow-down-alt2"></span></button><div class="mdg-menu"><a href="' . esc_url( $export ) . '">خروجی CSV (اکسل)</a><a href="#" data-mdg-open="#mdg-import">ورود از CSV</a></div></div>';
		echo '<button type="button" class="mdg-btn" id="mdg-refresh" title="بروزرسانی"><span class="dashicons dashicons-update"></span> بروزرسانی</button>';
		echo '<button type="button" class="mdg-btn mdg-btn--primary" id="mdg-save" disabled>ذخیره تغییرات <b class="mdg-dirty"></b></button></div></div>';

		// Quick chips + tools.
		echo '<div class="mdg-toolbar"><div class="mdg-chips"><span class="mdg-selected" hidden><button type="button" class="mdg-clear-sel" title="لغو انتخاب">×</button> انتخاب شده: <b>0</b></span>';
		$chips = array( 'instock' => array( 'موجود', 'ok' ), 'outofstock' => array( 'ناموجود', 'bad' ), 'active' => array( 'فعال', 'ok' ), 'inactive' => array( 'غیرفعال', 'warn' ), 'onsale' => array( 'باتخفیف', 'info' ), 'nosale' => array( 'بدون‌تخفیف', 'muted' ), 'message' => array( 'پیام جایگزین', 'pink' ) );
		foreach ( $chips as $key => $chip ) {
			$url   = add_query_arg( array_merge( $base, $key === $f['quick'] ? array() : array( 'quick' => $key ) ), admin_url( 'admin.php' ) );
			echo '<a class="mdg-chip mdg-chip--' . esc_attr( $chip[1] ) . ( $key === $f['quick'] ? ' is-on' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $chip[0] ) . ': <b>' . esc_html( number_format_i18n( $counts[ $key ] ) ) . '</b></a>';
		}
		echo '</div><div class="mdg-tools"><button type="button" class="mdg-tool" data-mdg-open="#mdg-bulk" data-bulk-tab="price"><span class="dashicons dashicons-money-alt"></span> تغییر قیمت</button><button type="button" class="mdg-tool" data-mdg-open="#mdg-bulk" data-bulk-tab="general"><span class="dashicons dashicons-edit"></span> تغییرات گروهی</button><button type="button" class="mdg-tool" data-mdg-open="#mdg-columns"><span class="dashicons dashicons-admin-settings"></span> تنظیمات</button></div></div>';

		// Grid.
		$sort = static function ( string $key, string $label ) use ( $f, $base ): string {
			$active = $key === $f['orderby'];
			$next   = $active && 'ASC' === $f['order'] ? 'desc' : 'asc';
			$url    = add_query_arg( array_merge( $base, array( 'quick' => $f['quick'] ?: null, 'orderby' => $key, 'order' => $next ) ), admin_url( 'admin.php' ) );
			return '<a class="mdg-sort' . ( $active ? ' is-' . strtolower( $f['order'] ) : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		};
		echo '<div class="mdg-card"><div class="mdg-scroll"><table class="mdg-grid" id="mdg-grid"><thead><tr>';
		echo '<th class="mdg-c-check"><input type="checkbox" id="mdg-check-all" aria-label="انتخاب همه"></th><th class="mdg-c-id">' . $sort( 'id', '#' ) . '</th><th class="mdg-c-name">' . $sort( 'title', 'نام محصول' ) . '</th><th>تصویر</th><th>نوع</th><th>وضعیت</th><th title="مدیریت موجودی">م.م</th><th>موجودی</th><th>انبار</th><th title="محصول ویژه">ویژه</th>';
		foreach ( $fields as $key => $label ) {
			echo '<th data-col="' . esc_attr( $key ) . '">' . ( 'regular' === $key ? $sort( 'price', $label ) : esc_html( $label ) ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $sort() escapes.
			if ( 'sale' === $key ) {
				echo '<th data-col="schedule">زمان اعمال تخفیف</th>';
			}
		}
		echo '<th data-col="cats">دسته‌بندی</th><th data-col="tags">برچسب</th><th data-col="sku">' . $sort( 'sku', 'SKU' ) . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! $data['products'] ) {
			echo '<tr><td colspan="30" class="mdg-empty">محصولی با این فیلتر پیدا نشد.</td></tr>';
		}
		$costs = MDA_Price_Service::accounting_costs( array_map( static fn( $p ) => $p->get_id(), $data['products'] ) );
		foreach ( $data['products'] as $product ) {
			echo self::row( $product, $costs ); // phpcs:ignore WordPress.Security.EscapeOutput -- row() escapes every value.
		}
		echo '</tbody></table></div>';
		echo '<div class="mdg-foot"><span>' . esc_html( number_format_i18n( $data['total'] ) ) . ' محصول</span>';
		if ( $data['pages'] > 1 ) {
			echo '<div class="mdg-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $f['paged'],
						'total'     => $data['pages'],
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			) . '</div>';
		}
		echo '<form method="get" class="mdg-perpage"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="s" value="' . esc_attr( $f['s'] ) . '"><input type="hidden" name="quick" value="' . esc_attr( $f['quick'] ) . '"><select name="per_page" onchange="this.form.submit()">';
		foreach ( array( 30, 50, 100, 200 ) as $n ) {
			echo '<option value="' . (int) $n . '"' . selected( $f['per_page'], $n, false ) . '>' . (int) $n . ' در صفحه</option>';
		}
		echo '</select></form></div></div>';

		self::render_bulk_modal( $fields );
		self::render_import_modal();
		self::render_settings_modal();
		echo '<div class="mdg-pop" id="mdg-terms-pop" hidden></div><div class="mdg-pop mdg-cal" id="mdg-cal" hidden></div><div class="mdg-pop" id="mdg-msg-pop" hidden></div>';
		echo '</div>';
	}

	/**
	 * One product row (and, for variable products, a toggle; variations load on demand).
	 */
	public static function row( WC_Product $p, array $costs = array(), bool $is_child = false ): string {
		$id      = (int) $p->get_id();
		$is_var  = $p->is_type( 'variation' );
		$is_par  = $p->is_type( 'variable' );
		$fields  = MDA_Price_Service::fields();
		$managed = MDA_Product_Grid_Service::accounting_managed( $p );
		$msg     = MDA_Product_Grid_Service::message( $p );
		$edit    = get_edit_post_link( $is_var ? $p->get_parent_id() : $id, 'raw' ) ?: '#';
		$cls     = array( 'mdg-row' );
		if ( $is_var ) {
			$cls[] = 'is-child';
		}
		if ( 'publish' !== $p->get_status() && ! $is_var ) {
			$cls[] = 'is-inactive';
		}
		$o  = '<tr class="' . esc_attr( implode( ' ', $cls ) ) . '" data-id="' . $id . '"' . ( $is_var ? ' data-parent="' . (int) $p->get_parent_id() . '"' : '' ) . '>';
		$o .= '<td class="mdg-c-check"><input type="checkbox" class="mdg-check" value="' . $id . '"></td>';
		$o .= '<td class="mdg-c-id"><span class="mdg-id">' . esc_html( number_format_i18n( $id ) ) . '</span><span class="mdg-row-icons">';
		$o .= '<a href="' . esc_url( $edit ) . '" title="ویرایش کامل"><span class="dashicons dashicons-edit"></span></a>';
		$o .= '<a href="' . esc_url( get_permalink( $is_var ? $p->get_parent_id() : $id ) ?: '#' ) . '" target="_blank" rel="noopener" title="مشاهده"><span class="dashicons dashicons-external"></span></a>';
		if ( ! $is_var && current_user_can( 'delete_post', $id ) ) {
			$o .= '<a href="' . esc_url( get_delete_post_link( $id ) ?: '#' ) . '" class="mdg-trash" title="انتقال به زباله‌دان"><span class="dashicons dashicons-trash"></span></a>';
		}
		$o .= '</span></td>';

		// Name.
		$name = $is_var ? wc_get_formatted_variation( $p, true, true, false ) : $p->get_name();
		$o   .= '<td class="mdg-c-name">' . ( $is_var ? '<span class="mdg-branch">↳</span> <a class="mdg-var-name" href="' . esc_url( $edit ) . '">' . esc_html( wp_strip_all_tags( $name ) ) . '</a>' : '<input class="mdg-in mdg-in--text" data-f="name" data-o="' . esc_attr( $name ) . '" value="' . esc_attr( $name ) . '" aria-label="نام محصول">' ) . '</td>';

		// Image.
		$img_id = $p->get_image_id() ?: ( $is_var ? wc_get_product( $p->get_parent_id() )->get_image_id() : 0 );
		$o     .= '<td class="mdg-c-img">' . ( $img_id ? wp_get_attachment_image( $img_id, array( 48, 48 ), false, array( 'class' => 'mdg-thumb', 'loading' => 'lazy', 'alt' => '' ) ) : '<span class="mdg-noimg dashicons dashicons-format-image"></span>' ) . '</td>';

		// Type.
		$types = array();
		if ( $is_par ) {
			$types[] = '<button type="button" class="mdg-badge mdg-badge--var mdg-expand" data-id="' . $id . '" aria-expanded="false"><b>+</b> متغیر</button>';
		} elseif ( $is_var ) {
			$types[] = '<span class="mdg-badge mdg-badge--ok">با تنوع</span>';
		} elseif ( $p->is_type( 'external' ) ) {
			$types[] = '<span class="mdg-badge mdg-badge--muted">خارجی</span>';
		} else {
			$types[] = '<span class="mdg-badge mdg-badge--info">ساده</span>';
		}
		if ( $p->is_virtual() ) {
			$types[] = '<span class="mdg-badge mdg-badge--soft">مجازی</span>';
		}
		if ( $p->is_downloadable() ) {
			$types[] = '<span class="mdg-badge mdg-badge--soft">دانلودی</span>';
		}
		if ( MDA_Supply_Service::MODE_ON_DEMAND === (string) ( $is_var ? wc_get_product( $p->get_parent_id() )->get_meta( MDA_Product_Fields::META_MODE ) : $p->get_meta( MDA_Product_Fields::META_MODE ) ) ) {
			$types[] = '<span class="mdg-badge mdg-badge--pink">سفارشی</span>';
		}
		$o .= '<td class="mdg-c-type">' . implode( '', $types ) . '</td>';

		// Status (+ price message).
		$active = 'publish' === $p->get_status();
		$o     .= '<td class="mdg-c-status"><button type="button" class="mdg-badge mdg-toggle-status ' . ( $active ? 'mdg-badge--ok' : 'mdg-badge--warn' ) . '" data-f="status" data-v="' . ( $active ? 'publish' : ( $is_var ? 'private' : 'draft' ) ) . '">' . ( $active ? 'فعال' : 'غیرفعال' ) . '</button>';
		if ( ! $is_var ) {
			$o .= '<button type="button" class="mdg-badge mdg-msg-btn' . ( $msg ? ' mdg-badge--pink' : ' mdg-badge--ghost' ) . '" data-msg="' . esc_attr( wp_json_encode( $msg ?: new stdClass() ) ) . '" title="پیام جایگزین قیمت">' . ( $msg ? esc_html( $msg['title'] ) : '+ پیام' ) . '</button>';
		}
		$o .= '</td>';

		// Stock.
		$manage = $p->get_manage_stock() && 'parent' !== $p->get_manage_stock();
		$o     .= '<td class="mdg-c-manage">' . ( $is_par ? '<span class="mdg-dim">—</span>' : '<input type="checkbox" class="mdg-in mdg-in--check" data-f="manage_stock" data-o="' . ( $manage ? '1' : '' ) . '"' . checked( $manage, true, false ) . ( $managed ? ' disabled title="موجودی از حسابداری"' : '' ) . '>' ) . '</td>';
		if ( $is_par ) {
			$o .= '<td><span class="mdg-pill">' . esc_html( number_format_i18n( (float) array_sum( array_map( static fn( $c ) => (float) ( wc_get_product( $c ) ? wc_get_product( $c )->get_stock_quantity() : 0 ), $p->get_children() ) ) ) ) . '</span></td>';
		} elseif ( $manage ) {
			$qty = (string) wc_stock_amount( $p->get_stock_quantity() );
			$o  .= '<td class="mdg-c-qty">' . ( $managed ? '<span class="mdg-pill mdg-pill--lock" title="از دفاتر انبار حسابداری؛ با خرید یا انبارگردانی تغییر دهید">' . esc_html( number_format_i18n( (float) $qty ) ) . ' <span class="dashicons dashicons-lock"></span></span>' : '<input class="mdg-in mdg-in--num mdg-in--qty" data-f="stock_qty" data-o="' . esc_attr( $qty ) . '" value="' . esc_attr( $qty ) . '" inputmode="numeric">' ) . '</td>';
		} else {
			$o .= '<td><span class="mdg-dim">—</span></td>';
		}
		$ss     = $p->get_stock_status();
		$labels = array( 'instock' => 'موجود', 'outofstock' => 'ناموجود', 'onbackorder' => 'پیش‌خرید' );
		$o     .= '<td class="mdg-c-ss"><button type="button" class="mdg-ss mdg-ss--' . esc_attr( $ss ) . '" data-f="stock_status" data-v="' . esc_attr( $ss ) . '"' . ( $manage || $is_par ? ' disabled' : '' ) . '>' . esc_html( $labels[ $ss ] ?? $ss ) . '</button></td>';
		$o     .= '<td class="mdg-c-star">' . ( $is_var ? '' : '<button type="button" class="mdg-star' . ( $p->get_featured() ? ' is-on' : '' ) . '" data-f="featured" data-v="' . ( $p->get_featured() ? '1' : '0' ) . '" aria-label="محصول ویژه">★</button>' ) . '</td>';

		// Prices.
		$regular = (float) $p->get_regular_price( 'edit' );
		$cost    = $costs[ $id ]['avg'] ?? null;
		foreach ( $fields as $key => $label ) {
			if ( $is_par && in_array( $key, array( 'regular', 'sale', 'purchase' ), true ) ) {
				$range = self::range( $p, $key );
				$o    .= '<td class="mdg-c-range">' . ( $range ? '<span class="mdg-range">از ' . esc_html( number_format_i18n( $range[0] ) ) . '<br>تا ' . esc_html( number_format_i18n( $range[1] ) ) . '</span>' : '<span class="mdg-dim">—</span>' ) . '</td>';
				if ( 'sale' === $key ) {
					$o .= '<td class="mdg-c-sched">' . self::schedule_html( $p ) . '</td>';
				}
				continue;
			}
			$v     = MDA_Price_Service::get( $p, $key );
			$badge = '';
			if ( 'sale' === $key && '' !== $v && $regular > 0 ) {
				$badge = '<span class="mdg-tag mdg-tag--' . ( ( 1 - (float) $v / $regular ) * 100 >= 20 ? 'hot' : 'off' ) . '" data-badge="discount">تخفیف ' . esc_html( number_format_i18n( round( ( 1 - (float) $v / $regular ) * 100 ) ) ) . '٪</span>';
			}
			if ( 'purchase' === $key ) {
				$buy   = '' !== $v ? (float) $v : ( null !== $cost ? (float) $cost : 0 );
				$sell  = (float) ( $p->get_sale_price( 'edit' ) ?: $regular );
				$badge = $buy > 0 && $sell > 0 ? '<span class="mdg-tag mdg-tag--' . ( $sell < $buy ? 'neg' : ( ( $sell - $buy ) / $sell < .05 ? 'low' : 'ok' ) ) . '" data-badge="profit">سود ' . esc_html( number_format_i18n( round( ( $sell - $buy ) / $sell * 100 ) ) ) . '٪</span>' : '';
			}
			$o .= '<td class="mdg-c-price"><input class="mdg-in mdg-in--num" data-f="' . esc_attr( $key ) . '" data-o="' . esc_attr( $v ) . '" value="' . esc_attr( '' === $v ? '' : number_format( (float) $v ) ) . '" inputmode="numeric" aria-label="' . esc_attr( $label ) . '"' . ( 'purchase' === $key && null !== $cost ? ' title="بهای تمام‌شده حسابداری: ' . esc_attr( number_format( $cost ) ) . '"' : '' ) . '>' . $badge . '</td>';
			if ( 'sale' === $key ) {
				$o .= '<td class="mdg-c-sched">' . self::schedule_html( $p ) . '</td>';
			}
		}

		// Terms + SKU.
		if ( $is_var ) {
			$o .= '<td><span class="mdg-dim">—</span></td><td><span class="mdg-dim">—</span></td>';
		} else {
			foreach ( array( 'cats' => 'product_cat', 'tags' => 'product_tag' ) as $f => $tax ) {
				$terms = get_the_terms( $id, $tax );
				$terms = is_array( $terms ) ? $terms : array();
				$names = wp_list_pluck( $terms, 'name' );
				$o    .= '<td class="mdg-c-terms"><button type="button" class="mdg-terms" data-f="' . esc_attr( $f ) . '" data-tax="' . esc_attr( $tax ) . '" data-ids="' . esc_attr( implode( ',', wp_list_pluck( $terms, 'term_id' ) ) ) . '">' . ( $names ? esc_html( implode( '، ', $names ) ) : '<span class="mdg-dim">' . ( 'cats' === $f ? 'بدون دسته‌بندی' : '—' ) . '</span>' ) . '</button></td>';
			}
		}
		$o .= '<td class="mdg-c-sku"><input class="mdg-in mdg-in--sku" dir="ltr" data-f="sku" data-o="' . esc_attr( $p->get_sku( 'edit' ) ) . '" value="' . esc_attr( $p->get_sku( 'edit' ) ) . '" aria-label="SKU"></td>';
		return $o . '</tr>';
	}

	private static function schedule_html( WC_Product $p ): string {
		if ( $p->is_type( 'variable' ) ) {
			$from = null;
			$to   = null;
			foreach ( $p->get_children() as $cid ) {
				$c = wc_get_product( $cid );
				if ( $c && $c->get_date_on_sale_from() ) {
					$from = $c->get_date_on_sale_from();
					$to   = $c->get_date_on_sale_to();
					break;
				}
			}
			return $from ? '<span class="mdg-sched-text">از ' . esc_html( MDA_Jalali::format( $from->date( 'Y-m-d' ) ) ) . ( $to ? '<br>تا ' . esc_html( MDA_Jalali::format( $to->date( 'Y-m-d' ) ) ) : '' ) . '</span>' : '<span class="mdg-dim">—</span>';
		}
		$from = $p->get_date_on_sale_from( 'edit' );
		$to   = $p->get_date_on_sale_to( 'edit' );
		$f    = $from ? $from->date( 'Y-m-d' ) : '';
		$t    = $to ? $to->date( 'Y-m-d' ) : '';
		return '<button type="button" class="mdg-sched" data-from="' . esc_attr( $f ) . '" data-to="' . esc_attr( $t ) . '" data-of="' . esc_attr( $f ) . '" data-ot="' . esc_attr( $t ) . '">' . ( $f || $t ? ( $f ? 'از ' . esc_html( MDA_Jalali::format( $f ) ) : '' ) . ( $t ? '<br>تا ' . esc_html( MDA_Jalali::format( $t ) ) : '' ) : '<span class="mdg-dim">—</span>' ) . '</button>';
	}

	/** Min/max of a price field over a variable product's variations. */
	private static function range( WC_Product $p, string $key ): ?array {
		$vals = array();
		foreach ( $p->get_children() as $cid ) {
			$c = wc_get_product( $cid );
			$v = $c ? MDA_Price_Service::get( $c, $key ) : '';
			if ( '' !== $v ) {
				$vals[] = (float) $v;
			}
		}
		return $vals ? array( min( $vals ), max( $vals ) ) : null;
	}

	private static function render_bulk_modal( array $fields ): void {
		echo '<div class="mdg-modal" id="mdg-bulk" hidden><div class="mdg-modal__box"><div class="mdg-modal__head"><span class="mdg-modal__icon dashicons dashicons-edit"></span><div><h2>تغییرات گروهی</h2><p class="mdg-bulk-target">۰ محصول انتخاب شده</p></div><button type="button" class="mdg-x" data-mdg-close>×</button></div>';
		echo '<div class="mdg-tabs"><button type="button" class="is-on" data-tab="general">عمومی</button><button type="button" data-tab="price">قیمت</button><button type="button" data-tab="terms">دسته و برچسب</button><button type="button" data-tab="message">پیام جایگزین قیمت</button></div><div class="mdg-modal__body">';
		echo '<section data-pane="general"><div class="mdg-seg-group"><label>وضعیت انتشار</label><div class="mdg-seg" data-name="status"><button type="button" data-v="">بدون تغییر</button><button type="button" data-v="publish" class="ok">فعال</button><button type="button" data-v="draft" class="warn">غیرفعال</button></div></div>';
		echo '<div class="mdg-seg-group"><label>وضعیت موجودی</label><div class="mdg-seg" data-name="stock_status"><button type="button" data-v="">بدون تغییر</button><button type="button" data-v="instock" class="ok">موجود</button><button type="button" data-v="outofstock" class="bad">ناموجود</button><button type="button" data-v="onbackorder" class="info">پیش‌خرید</button></div><small>برای محصولاتی که موجودی آن‌ها مدیریت می‌شود، وضعیت از تعداد موجودی تعیین می‌شود.</small></div>';
		echo '<div class="mdg-seg-group"><label>محصول ویژه</label><div class="mdg-seg" data-name="featured"><button type="button" data-v="">بدون تغییر</button><button type="button" data-v="1" class="ok">★ ویژه</button><button type="button" data-v="0">عادی</button></div></div>';
		echo '<div class="mdg-seg-group"><label>زمان اعمال تخفیف</label><div class="mdg-inline"><button type="button" class="mdg-btn mdg-bulk-sched">انتخاب بازه</button> <span class="mdg-bulk-sched-text mdg-dim">بدون تغییر</span> <label class="mdg-check-label"><input type="checkbox" name="schedule_clear"> حذف زمان‌بندی</label></div></div></section>';
		echo '<section data-pane="price" hidden><div class="mdg-inline"><select name="price_field">';
		foreach ( $fields as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select><select name="price_op"><option value="">بدون تغییر قیمت</option><option value="set">برابر با</option><option value="inc_pct">افزایش درصدی</option><option value="dec_pct">کاهش درصدی</option><option value="inc_amount">افزایش مبلغ</option><option value="dec_amount">کاهش مبلغ</option><option value="margin">قیمت خرید + درصد سود</option><option value="copy">از ستون دیگر ± درصد</option><option value="clear">پاک کردن</option></select><select name="price_source" hidden>';
		foreach ( $fields as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select><input name="price_value" dir="ltr" inputmode="decimal" placeholder="مقدار یا درصد (مثلاً -5)"><select name="price_round"><option value="0">بدون گرد کردن</option><option value="1000">گرد به ۱٬۰۰۰</option><option value="10000">گرد به ۱۰٬۰۰۰</option><option value="100000">گرد به ۱۰۰٬۰۰۰</option><option value="1000000">گرد به ۱٬۰۰۰٬۰۰۰</option></select></div><p class="mdg-hint">برای انتخاب‌های همین صفحه می‌توانید ابتدا «پیش‌نمایش» بگیرید؛ تغییرات تا ذخیره نهایی اعمال نمی‌شوند.</p></section>';
		echo '<section data-pane="terms" hidden><div class="mdg-grid-2">';
		foreach ( array( 'cats' => array( 'دسته‌بندی', 'product_cat' ), 'tags' => array( 'برچسب‌ها', 'product_tag' ) ) as $key => $meta ) {
			echo '<div class="mdg-term-box" data-key="' . esc_attr( $key ) . '" data-tax="' . esc_attr( $meta[1] ) . '"><h3>' . esc_html( $meta[0] ) . '</h3><div class="mdg-term-actions"><button type="button" class="mdg-btn mdg-btn--soft" data-term-mode="add"><span class="dashicons dashicons-plus"></span> افزودن</button><button type="button" class="mdg-btn mdg-btn--soft" data-term-mode="remove"><span class="dashicons dashicons-minus"></span> حذف انتخابی</button><button type="button" class="mdg-btn mdg-btn--danger" data-term-mode="clear"><span class="dashicons dashicons-trash"></span> حذف همه</button></div><div class="mdg-term-summary mdg-dim">بدون تغییر</div></div>';
		}
		echo '</div></section>';
		echo '<section data-pane="message" hidden><div class="mdg-seg-group"><label>پیام جایگزین قیمت</label><div class="mdg-seg" data-name="message_mode"><button type="button" data-v="" class="is-on">بدون تغییر</button><button type="button" data-v="on" class="pink">فعال کردن</button><button type="button" data-v="off">غیرفعال کردن</button></div></div><div class="mdg-msg-fields">' . self::message_fields_html() . '</div></section>';
		echo '</div><div class="mdg-modal__foot"><label class="mdg-check-label"><input type="checkbox" id="mdg-all-matching"> اعمال روی همه محصولات مطابق فیلتر (همه صفحات)</label><span class="mdg-progress" hidden></span><button type="button" class="mdg-btn" data-mdg-close>انصراف</button><button type="button" class="mdg-btn" id="mdg-bulk-preview">پیش‌نمایش قیمت</button><button type="button" class="mdg-btn mdg-btn--primary" id="mdg-bulk-apply">اعمال تغییرات</button></div></div></div>';
	}

	public static function message_fields_html(): string {
		return '<label>پیام جایگزین قیمت<input name="msg_title" maxlength="40" placeholder="مثلاً: در حال تأمین"></label><label>توضیح اختیاری (زیر عنوان) <small class="mdg-count">0/40</small><input name="msg_note" maxlength="40" placeholder="مثلاً: در حال ترخیص"></label><label>شماره تلفن (اختیاری)<input name="msg_phone" dir="ltr" maxlength="20" placeholder="021-12345678"></label><p class="mdg-hint"><b>راهنما:</b> این پیام در فروشگاه دقیقاً <b>به جای قیمت</b> نمایش داده می‌شود؛ دکمه خرید، موجودی و فیلد تعداد هم خودکار حذف می‌شوند.</p>';
	}

	private static function render_import_modal(): void {
		echo '<div class="mdg-modal" id="mdg-import" hidden><div class="mdg-modal__box mdg-modal__box--sm"><div class="mdg-modal__head"><span class="mdg-modal__icon dashicons dashicons-upload"></span><div><h2>ورود قیمت‌ها از CSV</h2><p>همان ستون‌های فایل خروجی: id یا sku و ستون‌های قیمت. خانه خالی = بدون تغییر، «-» = پاک کردن.</p></div><button type="button" class="mdg-x" data-mdg-close>×</button></div><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mdg-modal__body">';
		wp_nonce_field( 'mda_prices_import' );
		echo '<input type="hidden" name="action" value="mda_prices_import"><input type="file" name="file" accept=".csv,text/csv" required><div class="mdg-modal__foot"><button type="button" class="mdg-btn" data-mdg-close>انصراف</button><button class="mdg-btn mdg-btn--primary">بارگذاری و اعمال</button></div></form></div></div>';
	}

	private static function render_settings_modal(): void {
		echo '<div class="mdg-modal" id="mdg-columns" hidden><div class="mdg-modal__box"><div class="mdg-modal__head"><span class="mdg-modal__icon dashicons dashicons-admin-settings"></span><div><h2>تنظیمات جدول</h2><p>ستون‌های قابل نمایش و سطوح قیمت.</p></div><button type="button" class="mdg-x" data-mdg-close>×</button></div><div class="mdg-modal__body"><h3>ستون‌ها</h3><div class="mdg-col-toggles"></div>';
		ob_start();
		MDA_Price_Admin::render_levels_form();
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '</div></div></div>';
	}

	/* ------------------------------------------------------------------ ajax */

	private static function guard(): void {
		check_ajax_referer( 'mda_grid', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	private static function editable( int $id ): ?WC_Product {
		$p = wc_get_product( $id );
		if ( ! $p || ! current_user_can( 'edit_product', $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id() ) ) {
			return null;
		}
		return $p;
	}

	public static function ajax_save(): void {
		self::guard();
		$changes = isset( $_POST['changes'] ) && is_array( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in MDA_Product_Grid_Service.
		$rows    = array();
		$errors  = array();
		foreach ( array_slice( $changes, 0, 300, true ) as $id => $fields ) {
			$p = self::editable( absint( $id ) );
			if ( ! $p || ! is_array( $fields ) ) {
				$errors[ absint( $id ) ] = 'محصول پیدا نشد یا اجازه ویرایش ندارید.';
				continue;
			}
			try {
				if ( isset( $fields['cats'] ) && ! is_array( $fields['cats'] ) ) {
					$fields['cats'] = '' === $fields['cats'] ? array() : explode( ',', (string) $fields['cats'] );
				}
				if ( isset( $fields['tags'] ) && ! is_array( $fields['tags'] ) ) {
					$fields['tags'] = '' === $fields['tags'] ? array() : explode( ',', (string) $fields['tags'] );
				}
				MDA_Product_Grid_Service::apply( $p, $fields, 'grid' );
				$fresh                = wc_get_product( $p->get_id() );
				$rows[ absint( $id ) ] = self::row( $fresh, MDA_Price_Service::accounting_costs( array( $fresh->get_id() ) ) );
			} catch ( Throwable $e ) {
				$errors[ absint( $id ) ] = $e->getMessage();
			}
		}
		delete_transient( 'mda_grid_counts' );
		wp_send_json_success( array( 'rows' => $rows, 'errors' => $errors ) );
	}

	public static function ajax_bulk(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); values sanitized by the grid service.
		$spec  = isset( $_POST['spec'] ) && is_array( $_POST['spec'] ) ? wp_unslash( $_POST['spec'] ) : array();
		$ids   = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$batch = max( 1, absint( $_POST['batch'] ?? 1 ) );
		$flt   = isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array();
		// phpcs:enable
		$size = 25;
		if ( $ids ) {
			$targets = array_filter( array_map( 'wc_get_product', array_slice( $ids, ( $batch - 1 ) * $size, $size ) ) );
			$total   = count( $ids );
			$pages   = (int) ceil( $total / $size );
		} else {
			$f       = array_merge( self::filters( $flt ), array( 'per_page' => $size, 'paged' => $batch ) );
			$data    = self::query( $f );
			$targets = array();
			foreach ( $data['products'] as $p ) {
				$targets[] = $p;
				if ( $p->is_type( 'variable' ) && ( ! empty( $spec['price'] ) || ! empty( $spec['stock_status'] ) || ! empty( $spec['sale_from'] ) || ! empty( $spec['schedule_clear'] ) ) ) {
					foreach ( $p->get_children() as $cid ) {
						$c = wc_get_product( $cid );
						if ( $c ) {
							$targets[] = $c;
						}
					}
				}
			}
			$total = $data['total'];
			$pages = $data['pages'];
		}
		if ( isset( $spec['message'] ) && is_array( $spec['message'] ) ) {
			$spec['message']['enabled'] = 'on' === ( $spec['message']['mode'] ?? '' );
		}
		$changed = 0;
		$errors  = array();
		foreach ( $targets as $p ) {
			if ( ! self::editable( (int) $p->get_id() ) ) {
				continue;
			}
			try {
				$c = MDA_Product_Grid_Service::bulk_changes( $p, $spec );
				if ( $c && MDA_Product_Grid_Service::apply( $p, $c, 'bulk' ) ) {
					++$changed;
				}
			} catch ( Throwable $e ) {
				$errors[] = $p->get_name() . ': ' . $e->getMessage();
			}
		}
		delete_transient( 'mda_grid_counts' );
		wp_send_json_success( array( 'batch' => $batch, 'pages' => max( 1, $pages ), 'total' => $total, 'changed' => $changed, 'errors' => array_slice( $errors, 0, 10 ) ) );
	}

	public static function ajax_terms(): void {
		self::guard();
		$tax = 'product_tag' === ( $_POST['tax'] ?? '' ) ? 'product_tag' : 'product_cat'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$q   = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 200, 'search' => $q, 'orderby' => 'name' ) );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'parent' => (int) $t->parent, 'count' => (int) $t->count );
		}
		wp_send_json_success( $out );
	}

	public static function ajax_add_term(): void {
		self::guard();
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			wp_send_json_error( array( 'message' => 'اجازه ساخت دسته یا برچسب ندارید.' ), 403 );
		}
		$tax  = 'product_tag' === ( $_POST['tax'] ?? '' ) ? 'product_tag' : 'product_cat'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => 'نام خالی است.' ), 400 );
		}
		$r = wp_insert_term( $name, $tax );
		if ( is_wp_error( $r ) ) {
			$existing = term_exists( $name, $tax );
			if ( $existing ) {
				wp_send_json_success( array( 'id' => (int) $existing['term_id'], 'name' => $name ) );
			}
			wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'id' => (int) $r['term_id'], 'name' => $name ) );
	}

	public static function ajax_variations(): void {
		self::guard();
		$p = self::editable( absint( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $p || ! $p->is_type( 'variable' ) ) {
			wp_send_json_error( array( 'message' => 'محصول متغیر پیدا نشد.' ), 404 );
		}
		$children = array_filter( array_map( 'wc_get_product', $p->get_children() ) );
		$costs    = MDA_Price_Service::accounting_costs( array_map( static fn( $c ) => $c->get_id(), $children ) );
		$html     = '';
		foreach ( $children as $c ) {
			$html .= self::row( $c, $costs, true );
		}
		wp_send_json_success( array( 'html' => $html ) );
	}
}
