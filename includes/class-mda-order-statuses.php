<?php
/**
 * Custom WooCommerce order statuses managed from the accounting settings, e.g. «مرجوع شده» or
 * «لغو و برگشت وجه», each with an accounting behaviour:
 *  - none:   label only;
 *  - sale:   posts the sale like processing/completed (revenue, COGS, stock issue);
 *  - return: reverses the whole sale like cancelled (stock back, customer credited) and restocks
 *            WooCommerce stock through WooCommerce's own guarded restock.
 * Core WooCommerce statuses cannot be removed, but their labels can be renamed.
 *
 * Stored in a WordPress option (needed on every request before the accounting database opens).
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Order_Statuses {
	public const OPTION     = 'mda_order_statuses';
	public const PAGE       = 'mda-order-statuses';
	public const BEHAVIOURS = array( 'none', 'sale', 'return' );
	public const MAX        = 15;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 9 );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'wc_statuses' ), 20 );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'report_statuses' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ), 30 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_actions' ), 30 );
		add_action( 'admin_head', array( __CLASS__, 'colors_css' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_mda_save_order_statuses', array( __CLASS__, 'handle_save' ) );
		foreach ( self::custom() as $key => $s ) {
			if ( 'sale' === $s['behaviour'] ) {
				add_action( 'woocommerce_order_status_' . $key, array( 'MDA_WooCommerce', 'queue_order' ), 20, 1 );
			} elseif ( 'return' === $s['behaviour'] ) {
				add_action( 'woocommerce_order_status_' . $key, array( __CLASS__, 'on_return' ), 20, 1 );
			}
		}
	}

	/* ------------------------------------------------------------------ data */

	public static function defaults(): array {
		return array(
			'custom' => array(
				'mda-returned'   => array( 'label' => 'مرجوع شده', 'color' => '#c0262d', 'behaviour' => 'return', 'paid' => 0 ),
				'mda-cancel-ref' => array( 'label' => 'لغو و برگشت وجه', 'color' => '#b45309', 'behaviour' => 'return', 'paid' => 0 ),
			),
			'rename' => array(),
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, null );
		if ( ! is_array( $saved ) ) {
			return self::defaults();
		}
		return array(
			'custom' => is_array( $saved['custom'] ?? null ) ? $saved['custom'] : array(),
			'rename' => is_array( $saved['rename'] ?? null ) ? $saved['rename'] : array(),
		);
	}

	/** @return array<string,array{label:string,color:string,behaviour:string,paid:int}> keyed without the wc- prefix. */
	public static function custom(): array {
		return self::settings()['custom'];
	}

	/** Statuses (without wc-) that post a sale: WooCommerce's own plus custom "sale" ones. */
	public static function sale_statuses(): array {
		$out = array( 'processing', 'completed' );
		foreach ( self::custom() as $key => $s ) {
			if ( 'sale' === $s['behaviour'] ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	public static function clean( array $raw, array $existing ): array {
		$out = array( 'custom' => array(), 'rename' => array() );
		foreach ( array_slice( (array) ( $raw['custom'] ?? array() ), 0, self::MAX ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = mb_substr( sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 0, 40 );
			$key   = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' === $label ) {
				if ( '' !== $key && isset( $existing[ $key ] ) && ! empty( $row['in_use'] ) ) {
					$label = $existing[ $key ]['label']; // Cannot drop a status that still holds orders.
				} else {
					continue;
				}
			}
			if ( '' === $key || ! isset( $existing[ $key ] ) ) {
				$key = 'mda-' . substr( md5( $label . microtime() . wp_rand() ), 0, 8 );
			}
			$color = sanitize_hex_color( (string) ( $row['color'] ?? '' ) ) ?: '#6b6880';
			$beh   = in_array( $row['behaviour'] ?? '', self::BEHAVIOURS, true ) ? $row['behaviour'] : 'none';
			$out['custom'][ $key ] = array(
				'label'     => $label,
				'color'     => $color,
				'behaviour' => $beh,
				'paid'      => 'sale' === $beh && ! empty( $row['paid'] ) ? 1 : 0,
			);
		}
		$core = self::core_statuses();
		foreach ( (array) ( $raw['rename'] ?? array() ) as $key => $label ) {
			$key   = sanitize_key( (string) $key );
			$label = mb_substr( sanitize_text_field( (string) $label ), 0, 40 );
			if ( isset( $core[ 'wc-' . $key ] ) && '' !== $label && $label !== $core[ 'wc-' . $key ] ) {
				$out['rename'][ $key ] = $label;
			}
		}
		return $out;
	}

	/** WooCommerce's built-in statuses with their original labels. */
	public static function core_statuses(): array {
		return array(
			'wc-pending'    => _x( 'Pending payment', 'Order status', 'woocommerce' ),
			'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
			'wc-on-hold'    => _x( 'On hold', 'Order status', 'woocommerce' ),
			'wc-completed'  => _x( 'Completed', 'Order status', 'woocommerce' ),
			'wc-cancelled'  => _x( 'Cancelled', 'Order status', 'woocommerce' ),
			'wc-refunded'   => _x( 'Refunded', 'Order status', 'woocommerce' ),
			'wc-failed'     => _x( 'Failed', 'Order status', 'woocommerce' ),
		);
	}

	public static function count_orders( string $key ): int {
		return function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( $key ) : 0;
	}

	/* ------------------------------------------------------------------ WooCommerce wiring */

	public static function register(): void {
		foreach ( self::custom() as $key => $s ) {
			register_post_status(
				'wc-' . $key,
				array(
					'label'                     => $s['label'],
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of orders */
					'label_count'               => _n_noop( $s['label'] . ' <span class="count">(%s)</span>', $s['label'] . ' <span class="count">(%s)</span>', 'malidev-accounting' ),
				)
			);
		}
	}

	public static function wc_statuses( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : array();
		foreach ( self::settings()['rename'] as $key => $label ) {
			if ( isset( $statuses[ 'wc-' . $key ] ) ) {
				$statuses[ 'wc-' . $key ] = $label;
			}
		}
		$out = array();
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-completed' === $k ) {
				foreach ( self::custom() as $key => $s ) {
					$out[ 'wc-' . $key ] = $s['label'];
				}
			}
		}
		foreach ( self::custom() as $key => $s ) {
			$out[ 'wc-' . $key ] = $out[ 'wc-' . $key ] ?? $s['label'];
		}
		return $out;
	}

	public static function paid_statuses( $statuses ) {
		foreach ( self::custom() as $key => $s ) {
			if ( ! empty( $s['paid'] ) ) {
				$statuses[] = $key;
			}
		}
		return array_values( array_unique( (array) $statuses ) );
	}

	public static function report_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		foreach ( self::custom() as $key => $s ) {
			if ( 'sale' === $s['behaviour'] ) {
				$statuses[] = $key;
			}
		}
		return array_values( array_unique( $statuses ) );
	}

	public static function bulk_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		foreach ( self::custom() as $key => $s ) {
			/* translators: %s: status label */
			$actions[ 'mark_' . $key ] = sprintf( __( 'تغییر وضعیت به «%s»', 'malidev-accounting' ), $s['label'] );
		}
		return $actions;
	}

	/** Whole-order reversal: WooCommerce restock (guarded by its own reduced-stock flag) + accounting return. */
	public static function on_return( $order_id ): void {
		if ( function_exists( 'wc_maybe_increase_stock_levels' ) ) {
			wc_maybe_increase_stock_levels( (int) $order_id );
		}
		MDA_WooCommerce::queue_cancel( $order_id );
	}

	public static function colors_css(): void {
		$css = '';
		foreach ( self::custom() as $key => $s ) {
			$hex  = sanitize_hex_color( $s['color'] ) ?: '#6b6880';
			$css .= '.order-status.status-' . sanitize_html_class( $key ) . '{background:' . $hex . '1f;color:' . $hex . ';}';
		}
		if ( $css ) {
			echo '<style id="mda-order-status-colors">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from sanitized keys/hex colors.
		}
	}

	/* ------------------------------------------------------------------ admin page */

	public static function menu(): void {
		add_submenu_page( MDA_Admin_Shell::HIDDEN_PARENT, 'وضعیت‌های سفارش', 'وضعیت‌های سفارش', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
		check_admin_referer( 'mda_save_order_statuses' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in clean().
		$raw      = isset( $_POST['st'] ) && is_array( $_POST['st'] ) ? wp_unslash( $_POST['st'] ) : array();
		$existing = self::custom();
		// Server-side guard: a status that still holds orders is never dropped.
		foreach ( (array) ( $raw['custom'] ?? array() ) as $i => $row ) {
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' !== $key && isset( $existing[ $key ] ) ) {
				$raw['custom'][ $i ]['in_use'] = self::count_orders( $key ) > 0 ? 1 : 0;
			}
		}
		$clean   = self::clean( $raw, $existing );
		$dropped = array_diff_key( $existing, $clean['custom'] );
		update_option( self::OPTION, $clean, true );
		$msg = 'وضعیت‌های سفارش ذخیره شد.' . ( $dropped ? ' حذف شد: ' . implode( '، ', wp_list_pluck( $dropped, 'label' ) ) : '' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'mda_notice' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
		$s    = self::settings();
		$rows = $s['custom'];
		$beh  = array(
			'none'   => 'بدون اثر حسابداری (فقط برچسب)',
			'sale'   => 'فروش — مثل «تکمیل‌شده» ثبت شود',
			'return' => 'مرجوعی کامل — مثل «لغو‌شده» برگشت بخورد',
		);
		echo '<div class="wrap" dir="rtl"><h1>وضعیت‌های سفارش</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice text only.
		if ( isset( $_GET['mda_notice'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['mda_notice'] ) ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mda_save_order_statuses' );
		echo '<input type="hidden" name="action" value="mda_save_order_statuses">';
		echo '<div class="mda-card"><div class="mda-card__head"><h2>وضعیت‌های اختصاصی</h2><button type="button" class="button mda-st-add"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>وضعیت جدید</button></div>';
		echo '<p class="description">برای حذف یک وضعیت، نام آن را خالی کنید. وضعیتی که هنوز سفارشی در آن هست حذف نمی‌شود؛ اول سفارش‌ها را به وضعیت دیگری ببرید.</p>';
		echo '<table class="widefat mda-st-table"><thead><tr><th>نام وضعیت</th><th>رنگ</th><th>اثر در حسابداری</th><th>پرداخت‌شده حساب شود</th><th>سفارش‌ها</th></tr></thead><tbody>';
		$i            = 0;
		$rows['__new'] = array( 'label' => '', 'color' => '#2563eb', 'behaviour' => 'none', 'paid' => 0 );
		foreach ( $rows as $key => $r ) {
			$is_new = '__new' === $key;
			$count  = $is_new ? 0 : self::count_orders( $key );
			echo '<tr' . ( $is_new ? ' class="mda-st-template"' : '' ) . '><td><input type="hidden" name="st[custom][' . (int) $i . '][key]" value="' . esc_attr( $is_new ? '' : $key ) . '"><input class="regular-text" name="st[custom][' . (int) $i . '][label]" maxlength="40" value="' . esc_attr( $r['label'] ) . '" placeholder="' . esc_attr( $is_new ? 'مثلاً: ارسال شده' : '' ) . '"></td>';
			echo '<td><input type="color" name="st[custom][' . (int) $i . '][color]" value="' . esc_attr( $r['color'] ) . '" style="width:56px;padding:4px;min-height:40px"></td>';
			echo '<td><select name="st[custom][' . (int) $i . '][behaviour]">';
			foreach ( $beh as $bk => $bl ) {
				echo '<option value="' . esc_attr( $bk ) . '"' . selected( $r['behaviour'], $bk, false ) . '>' . esc_html( $bl ) . '</option>';
			}
			echo '</select></td><td><label class="mda-check"><input type="checkbox" name="st[custom][' . (int) $i . '][paid]" value="1"' . checked( ! empty( $r['paid'] ), true, false ) . '> بله</label></td>';
			echo '<td>' . ( $is_new ? '<span class="mda-muted">—</span>' : '<span class="order-status status-' . esc_attr( $key ) . '" style="padding:2px 10px;border-radius:6px"><span>' . esc_html( number_format_i18n( $count ) ) . '</span></span>' ) . '</td></tr>';
			++$i;
		}
		echo '</tbody></table></div>';

		echo '<div class="mda-card"><h2>تغییر نام وضعیت‌های ووکامرس</h2><p class="description">وضعیت‌های اصلی ووکامرس حذف‌شدنی نیستند، ولی می‌توانید نامشان را عوض کنید. خالی = نام پیش‌فرض.</p><div class="mda-fields">';
		foreach ( self::core_statuses() as $k => $label ) {
			$key = substr( $k, 3 );
			echo '<label>' . esc_html( $label ) . '<input name="st[rename][' . esc_attr( $key ) . ']" maxlength="40" value="' . esc_attr( $s['rename'][ $key ] ?? '' ) . '" placeholder="' . esc_attr( $label ) . '"></label>';
		}
		echo '</div></div><p><button class="button button-primary">ذخیره وضعیت‌ها</button></p></form></div>';
		echo '<script>(function(){var b=document.querySelector(".mda-st-add");if(!b)return;b.addEventListener("click",function(){var t=document.querySelector(".mda-st-table tbody"),tpl=t.querySelector(".mda-st-template"),r=tpl.cloneNode(true),n=t.children.length;r.classList.remove("mda-st-template");r.querySelectorAll("input,select").forEach(function(i){i.name=i.name.replace(/\\[custom\\]\\[\\d+\\]/,"[custom]["+n+"]");if(i.type==="text"||!i.type)i.value="";});t.insertBefore(r,tpl);r.querySelector("input.regular-text").focus();});})();</script>';
	}
}
