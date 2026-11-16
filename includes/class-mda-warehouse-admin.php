<?php
/**
 * «انبارها و انتقال»: warehouses list/edit, online-sales warehouse, transfer form, stock per warehouse.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Warehouse_Admin {
	public const PAGE = 'mda-warehouses';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_mda_wh_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_mda_wh_online', array( __CLASS__, 'handle_online' ) );
		add_action( 'admin_post_mda_wh_transfer', array( __CLASS__, 'handle_transfer' ) );
		add_action(
			'admin_enqueue_scripts',
			static function ( $hook ) {
				if ( false !== strpos( (string) $hook, self::PAGE ) && wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
					wp_enqueue_script( 'wc-enhanced-select' );
					wp_enqueue_style( 'woocommerce_admin_styles' );
				}
			},
			20
		);
	}

	public static function menu(): void {
		add_submenu_page( MDA_Admin_Shell::HIDDEN_PARENT, 'انبارها و انتقال', 'انبارها و انتقال', MDA_Capabilities::for_page( self::PAGE ), self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function guard( string $nonce ): void {
		if ( ! MDA_Capabilities::user_can_action() ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این بخش را ندارید.', 'malidev-accounting' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	private static function back( string $msg, bool $ok, array $extra = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE, $ok ? 'mda_notice' : 'mda_error' => rawurlencode( $msg ) ), $extra ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_save(): void {
		self::guard( 'mda_wh_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in the service.
		$in = isset( $_POST['wh'] ) && is_array( $_POST['wh'] ) ? wp_unslash( $_POST['wh'] ) : array();
		$id = absint( $in['id'] ?? 0 );
		try {
			MDA_Warehouse_Service::save( $in, $id );
			self::back( $id ? 'انبار به‌روز شد.' : 'انبار جدید ساخته شد.', true );
		} catch ( Throwable $e ) {
			self::back( $e->getMessage(), false, $id ? array( 'edit' => $id ) : array() );
		}
	}

	public static function handle_online(): void {
		self::guard( 'mda_wh_online' );
		if ( ! current_user_can( MDA_Capabilities::MANAGE ) ) {
			self::back( 'فقط مدیر حسابداری می‌تواند انبار فروش آنلاین را تغییر دهد.', false );
		}
		$id = absint( $_POST['online_warehouse_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$ok = false;
		foreach ( MDA_Warehouse_Service::all( true ) as $w ) {
			$ok = $ok || (int) $w['id'] === $id;
		}
		if ( ! $ok ) {
			self::back( 'انبار انتخاب‌شده فعال نیست.', false );
		}
		MDA_Settings::set( 'online_warehouse_id', $id );
		wp_schedule_single_event( time() + 5, 'mda_woocommerce_stock_sync_continue', array( $id ) );
		self::back( 'انبار فروش آنلاین تغییر کرد؛ موجودی ووکامرس با این انبار همگام می‌شود.', true );
	}

	public static function handle_transfer(): void {
		self::guard( 'mda_wh_transfer' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$item = absint( $_POST['item'] ?? 0 );
		$p    = $item ? wc_get_product( $item ) : null;
		try {
			if ( ! $p ) {
				throw new InvalidArgumentException( 'کالا را انتخاب کنید.' );
			}
			if ( $p->is_type( 'variable' ) ) {
				throw new InvalidArgumentException( 'برای کالای متغیر، تنوع مشخص (مثلاً رنگ یا سایز) را انتخاب کنید.' );
			}
			$product_id   = $p->is_type( 'variation' ) ? (int) $p->get_parent_id() : (int) $p->get_id();
			$variation_id = $p->is_type( 'variation' ) ? (int) $p->get_id() : 0;
			$date         = MDA_Jalali::normalize_input( sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ) ) ?: current_time( 'Y-m-d' );
			$r            = MDA_Warehouse_Service::transfer(
				$product_id,
				$variation_id,
				absint( $_POST['from'] ?? 0 ),
				absint( $_POST['to'] ?? 0 ),
				sanitize_text_field( wp_unslash( $_POST['quantity'] ?? '' ) ),
				$date,
				sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
				sanitize_text_field( wp_unslash( $_POST['request_key'] ?? '' ) )
			);
			self::back( $r['duplicate'] ? 'این انتقال قبلاً ثبت شده بود.' : 'انتقال «' . $p->get_name() . '» ثبت شد.', true );
		} catch ( Throwable $e ) {
			self::back( $e->getMessage(), false );
		}
		// phpcs:enable
	}

	public static function render(): void {
		if ( ! MDA_Capabilities::user_can_action() ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این بخش را ندارید.', 'malidev-accounting' ), 403 );
		}
		echo '<div class="wrap" dir="rtl"><h1>انبارها و انتقال بین انبارها</h1>';
		if ( ! MDA_Database::is_available() ) {
			echo '<div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen parameters.
		foreach ( array( 'mda_notice' => 'success', 'mda_error' => 'error' ) as $k => $t ) {
			if ( isset( $_GET[ $k ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $t ) . ' is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ) ) . '</p></div>';
			}
		}
		$edit   = absint( $_GET['edit'] ?? 0 );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:enable
		$all    = MDA_Warehouse_Service::all();
		$online = MDA_Settings::online_warehouse_id();
		$matrix = MDA_Warehouse_Service::stock_matrix( $search );
		$value  = array();
		$count  = array();
		foreach ( $matrix['rows'] as $r ) {
			foreach ( $r['qty'] as $wid => $q ) {
				$count[ $wid ] = ( $count[ $wid ] ?? 0 ) + ( $q > 0 ? 1 : 0 );
			}
		}
		$cur = MDA_Settings::currency_label();

		// Warehouse cards.
		echo '<div class="mda-grid mda-grid-3">';
		foreach ( $all as $w ) {
			$id     = (int) $w['id'];
			$is_on  = $id === $online;
			$active = (int) $w['is_active'];
			echo '<div class="mda-card mda-kpi ' . ( $is_on ? 'is-teal' : ( $active ? 'is-info' : '' ) ) . '"><span class="mda-kpi__icon" aria-hidden="true"><span class="dashicons ' . ( $is_on ? 'dashicons-store' : 'dashicons-archive' ) . '"></span></span><div class="mda-kpi__body">';
			echo '<span class="mda-kpi__label">' . esc_html( $w['warehouse_code'] ) . ( $is_on ? ' · <span class="mda-pill mda-pill--ok">فروش آنلاین</span>' : '' ) . ( $active ? '' : ' · <span class="mda-pill mda-pill--warn">غیرفعال</span>' ) . '</span>';
			echo '<span class="mda-kpi__value" style="font-size:17px">' . esc_html( $w['warehouse_name'] ) . '</span>';
			echo '<span class="mda-kpi__sub">' . esc_html( number_format_i18n( (int) ( $count[ $id ] ?? 0 ) ) ) . ' کالای موجود' . ( $w['address'] ? ' · ' . esc_html( $w['address'] ) : '' ) . '</span>';
			echo '<p style="margin:10px 0 0"><a class="button button-small" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'edit' => $id ), admin_url( 'admin.php' ) ) ) . '#mda-wh-form">ویرایش</a></p></div></div>';
		}
		echo '</div>';

		// Forms row.
		$e = array( 'id' => 0, 'warehouse_code' => '', 'warehouse_name' => '', 'address' => '', 'is_active' => 1 );
		foreach ( $all as $w ) {
			if ( (int) $w['id'] === $edit ) {
				$e = $w;
			}
		}
		echo '<div class="mda-grid-2">';
		echo '<div class="mda-card" id="mda-wh-form"><h2>' . ( $edit ? 'ویرایش انبار' : 'افزودن انبار' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mda_wh_save' );
		echo '<input type="hidden" name="action" value="mda_wh_save"><input type="hidden" name="wh[id]" value="' . (int) $e['id'] . '"><div class="mda-fields">';
		echo '<label>کد انبار<input name="wh[code]" dir="ltr" maxlength="20" value="' . esc_attr( $e['warehouse_code'] ) . '"' . ( $edit ? ' disabled' : ' required placeholder="SHOP"' ) . '><small>کوتاه و انگلیسی؛ بعداً قابل تغییر نیست.</small></label>';
		echo '<label>نام انبار<input name="wh[name]" maxlength="80" required value="' . esc_attr( $e['warehouse_name'] ) . '" placeholder="مثلاً: فروشگاه حضوری"></label>';
		echo '<label class="is-wide">نشانی (اختیاری)<input name="wh[address]" maxlength="300" value="' . esc_attr( (string) $e['address'] ) . '"></label>';
		if ( $edit ) {
			echo '<label class="mda-check"><input type="checkbox" name="wh[is_active]" value="1"' . checked( (int) $e['is_active'], 1, false ) . '> فعال</label>';
		}
		echo '</div><p><button class="button button-primary">' . ( $edit ? 'ذخیره تغییرات' : 'افزودن انبار' ) . '</button>' . ( $edit ? ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">انصراف</a>' : '' ) . '</p></form>';
		if ( current_user_can( MDA_Capabilities::MANAGE ) ) {
			echo '<hr style="border:0;border-top:1px dashed var(--mda-line-2);margin:20px 0"><h3>انبار فروش آنلاین</h3><p class="description">سفارش‌های سایت از این انبار کم می‌شوند و موجودی ووکامرس با همین انبار یکی می‌شود.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mda_wh_online' );
			echo '<input type="hidden" name="action" value="mda_wh_online"><p><label>انبار<select name="online_warehouse_id">';
			foreach ( $all as $w ) {
				if ( (int) $w['is_active'] ) {
					echo '<option value="' . (int) $w['id'] . '"' . selected( (int) $w['id'], $online, false ) . '>' . esc_html( $w['warehouse_name'] ) . '</option>';
				}
			}
			echo '</select></label><button class="button">ذخیره</button></p></form>';
		}
		echo '</div>';

		$active = array_filter( $all, static fn( $w ) => (int) $w['is_active'] );
		echo '<div class="mda-card"><h2>انتقال کالا بین انبارها</h2>';
		if ( count( $active ) < 2 ) {
			echo '<div class="mda-empty"><span class="dashicons dashicons-randomize" aria-hidden="true"></span>برای انتقال، دست‌کم دو انبار فعال لازم است. از فرم کنار، انبار دوم را اضافه کنید.</div>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mda_wh_transfer' );
			echo '<input type="hidden" name="action" value="mda_wh_transfer"><input type="hidden" name="request_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><div class="mda-fields">';
			echo '<label class="is-wide">کالا<select class="wc-product-search" name="item" style="width:100%" data-placeholder="نام کالا یا SKU…" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true" required></select></label>';
			$opts = static function ( int $sel ) use ( $active ): string {
				$h = '';
				foreach ( $active as $w ) {
					$h .= '<option value="' . (int) $w['id'] . '"' . selected( (int) $w['id'], $sel, false ) . '>' . esc_html( $w['warehouse_name'] ) . '</option>';
				}
				return $h;
			};
			$first_other = 0;
			foreach ( $active as $w ) {
				if ( (int) $w['id'] !== $online ) {
					$first_other = (int) $w['id'];
					break;
				}
			}
			echo '<label>از انبار<select name="from" required>' . $opts( $online ) . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $opts.
			echo '<label>به انبار<select name="to" required>' . $opts( $first_other ) . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<label>تعداد<input name="quantity" dir="ltr" inputmode="decimal" required placeholder="مثلاً 5"></label>';
			echo '<label>تاریخ<input class="mda-jdate" name="date" value="' . esc_attr( MDA_Jalali::format( current_time( 'Y-m-d' ), false ) ) . '"></label>';
			echo '<label class="is-wide">توضیح (اختیاری)<input name="note" maxlength="200" placeholder="مثلاً: ارسال برای فروشگاه حضوری"></label>';
			echo '</div><p><button class="button button-primary"><span class="dashicons dashicons-randomize" aria-hidden="true"></span>ثبت انتقال</button></p></form>';
		}
		echo '</div></div>';

		// Stock matrix.
		echo '<div class="mda-card"><div class="mda-card__head"><h2>موجودی هر انبار</h2><form method="get" class="mda-row"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="جستجوی نام یا SKU…"><button class="button">جستجو</button></form></div>';
		echo '<table class="widefat"><thead><tr><th>کالا</th><th>SKU</th>';
		foreach ( $matrix['warehouses'] as $w ) {
			echo '<th>' . esc_html( $w['warehouse_name'] ) . '</th>';
		}
		echo '<th>جمع</th><th>ارزش (' . esc_html( $cur ) . ')</th></tr></thead><tbody>';
		foreach ( $matrix['rows'] as $r ) {
			$sum = array_sum( $r['qty'] );
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td dir="ltr">' . esc_html( $r['sku'] ) . '</td>';
			foreach ( $matrix['warehouses'] as $w ) {
				$q = (float) ( $r['qty'][ (int) $w['id'] ] ?? 0 );
				echo '<td><span class="mda-stock-num' . ( $q < 0 ? ' is-neg' : ( 0.0 === $q ? ' is-zero' : '' ) ) . '">' . esc_html( number_format_i18n( $q, floor( $q ) === $q ? 0 : 2 ) ) . '</span></td>';
			}
			echo '<td><strong>' . esc_html( number_format_i18n( $sum, floor( $sum ) === (float) $sum ? 0 : 2 ) ) . '</strong></td><td>' . esc_html( number_format_i18n( $r['value'] ) ) . '</td></tr>';
		}
		if ( ! $matrix['rows'] ) {
			echo '<tr><td colspan="' . ( count( $matrix['warehouses'] ) + 4 ) . '"><div class="mda-empty">هنوز کالایی در دفاتر انبار ثبت نشده است؛ با خرید یا موجودی افتتاحیه شروع کنید.</div></td></tr>';
		}
		echo '</tbody></table></div>';

		// Transfers.
		$transfers = MDA_Warehouse_Service::recent_transfers( 20 );
		echo '<h2>آخرین انتقال‌ها</h2><table class="widefat"><thead><tr><th>تاریخ</th><th>کالا</th><th>از</th><th>به</th><th>تعداد</th><th>بهای واحد</th><th>ثبت‌کننده</th><th>توضیح</th></tr></thead><tbody>';
		foreach ( $transfers as $t ) {
			$p    = wc_get_product( (int) $t['variation_id'] ?: (int) $t['product_id'] );
			$user = $t['created_by'] ? get_userdata( (int) $t['created_by'] ) : null;
			echo '<tr><td>' . esc_html( MDA_Jalali::format( $t['transfer_date'] ) ) . '</td><td>' . esc_html( $p ? wp_strip_all_tags( $p->get_formatted_name() ) : '#' . $t['product_id'] ) . '</td><td>' . esc_html( $t['from_name'] ) . '</td><td>' . esc_html( $t['to_name'] ) . '</td><td>' . esc_html( MDA_Warehouse_Service::format_qty( (int) $t['quantity_scaled'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $t['unit_cost_minor'] ) ) . '</td><td>' . esc_html( $user ? $user->display_name : '—' ) . '</td><td>' . esc_html( (string) $t['note'] ) . '</td></tr>';
		}
		if ( ! $transfers ) {
			echo '<tr><td colspan="8"><div class="mda-empty">هنوز انتقالی ثبت نشده است.</div></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
