<?php
/**
 * Storefront side of the «پیام جایگزین قیمت»: a product carrying the message shows a box (title, note,
 * call link) instead of its price, and cannot be bought — add-to-cart, quantity and stock text disappear.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Price_Message {
	public const OPTION = 'mda_price_messages';
	public const MAX    = 20;

	public static function init(): void {
		add_action( 'admin_post_mda_save_price_messages', array( __CLASS__, 'handle_save' ) );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 99, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 99, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 99, 2 );
		add_filter( 'woocommerce_get_stock_html', array( __CLASS__, 'stock_html' ), 99, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 99, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_style' ) );
	}

	/* ------------------------------------------------------------------ settings */

	/**
	 * Store-specific templates + default phone. Nothing shop-specific is hard-coded: every shop edits
	 * its own texts and number in «تنظیمات و ابزار».
	 *
	 * @return array{phone:string,presets:array<string,array{title:string,note:string,phone:string}>}
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, null );
		if ( ! is_array( $saved ) ) {
			$saved = array(
				'phone'   => '',
				'presets' => array(
					'supply'  => array( 'title' => 'در حال تأمین', 'note' => '', 'phone' => '' ),
					'clear'   => array( 'title' => 'در حال ترخیص', 'note' => '', 'phone' => '' ),
					'call'    => array( 'title' => 'برای استعلام قیمت تماس بگیرید', 'note' => '', 'phone' => '' ),
				),
			);
		}
		$saved['phone']   = (string) ( $saved['phone'] ?? '' );
		$saved['presets'] = is_array( $saved['presets'] ?? null ) ? $saved['presets'] : array();
		return $saved;
	}

	/** Cleans the posted settings form. */
	public static function clean_settings( array $raw ): array {
		$out = array( 'phone' => self::clean_phone( (string) ( $raw['phone'] ?? '' ) ), 'presets' => array() );
		$ids = array();
		foreach ( array_slice( (array) ( $raw['presets'] ?? array() ), 0, self::MAX ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = mb_substr( sanitize_text_field( (string) ( $row['title'] ?? '' ) ), 0, 40 );
			if ( '' === $title ) {
				continue; // Empty row = deleted.
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'm' . substr( md5( $title . count( $ids ) . microtime() ), 0, 8 );
			}
			$ids[ $id ]            = true;
			$out['presets'][ $id ] = array(
				'title' => $title,
				'note'  => mb_substr( sanitize_text_field( (string) ( $row['note'] ?? '' ) ), 0, 40 ),
				'phone' => self::clean_phone( (string) ( $row['phone'] ?? '' ) ),
			);
		}
		return $out;
	}

	public static function clean_phone( string $v ): string {
		return mb_substr( trim( (string) preg_replace( '/[^0-9+\-\s]/', '', MDA_Jalali::latin_digits( $v ) ) ), 0, 20 );
	}

	/**
	 * Final texts for a stored product message: preset values, then per-product overrides, then the
	 * store's default phone.
	 */
	public static function resolve( array $stored ): array {
		$s      = self::settings();
		$preset = $s['presets'][ (string) ( $stored['preset'] ?? '' ) ] ?? array();
		$pick   = static fn( string $k ): string => '' !== (string) ( $stored[ $k ] ?? '' ) ? (string) $stored[ $k ] : (string) ( $preset[ $k ] ?? '' );
		$title  = $pick( 'title' );
		if ( '' === $title && ! $preset ) {
			$title = (string) ( $stored['label'] ?? '' ); // Preset was deleted: keep the product blocked with its last text.
		}
		$out    = array( 'preset' => (string) ( $stored['preset'] ?? '' ), 'title' => $title, 'note' => $pick( 'note' ), 'phone' => $pick( 'phone' ) );
		if ( '' === $out['phone'] ) {
			$out['phone'] = $s['phone'];
		}
		return '' !== $out['title'] ? $out : array();
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-accounting' ), 403 );
		}
		check_admin_referer( 'mda_save_price_messages' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in clean_settings().
		$raw = isset( $_POST['pm'] ) && is_array( $_POST['pm'] ) ? wp_unslash( $_POST['pm'] ) : array();
		update_option( self::OPTION, self::clean_settings( $raw ), true );
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=mda-settings' );
		wp_safe_redirect( add_query_arg( 'mda_notice', rawurlencode( 'پیام‌های جایگزین قیمت ذخیره شد.' ), remove_query_arg( array( 'mda_notice', 'mda_error' ), $back ) ) );
		exit;
	}

	/** Settings card (used on the settings page and inside the product grid settings modal). */
	public static function render_settings_card(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s    = self::settings();
		$rows = $s['presets'];
		$rows['__new'] = array( 'title' => '', 'note' => '', 'phone' => '' );
		echo '<div class="mda-card mda-pm-card"><h2>پیام‌های جایگزین قیمت</h2><p class="description">متن‌هایی که به جای قیمت در فروشگاه نمایش داده می‌شوند (مثل «در حال تأمین» یا «در حال ترخیص»). در جدول محصولات یکی از این پیام‌ها را برای هر کالا انتخاب می‌کنید؛ با ویرایش متن همین‌جا، همه کالاهایی که آن پیام را دارند یک‌جا به‌روز می‌شوند. برای پاک کردن یک پیام، عنوانش را خالی کنید.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mda_save_price_messages' );
		echo '<input type="hidden" name="action" value="mda_save_price_messages">';
		echo '<p><label><b>شماره تماس پیش‌فرض</b> <input name="pm[phone]" dir="ltr" maxlength="20" value="' . esc_attr( $s['phone'] ) . '" placeholder="021-12345678"></label> <span class="description">برای پیام‌هایی که شماره اختصاصی ندارند.</span></p>';
		echo '<table class="widefat striped mda-pm-table"><thead><tr><th>عنوان پیام (حداکثر ۴۰ حرف)</th><th>توضیح زیر عنوان (اختیاری)</th><th>شماره اختصاصی (اختیاری)</th></tr></thead><tbody>';
		$i = 0;
		foreach ( $rows as $id => $row ) {
			echo '<tr><td><input type="hidden" name="pm[presets][' . (int) $i . '][id]" value="' . esc_attr( '__new' === $id ? '' : $id ) . '"><input class="regular-text" name="pm[presets][' . (int) $i . '][title]" maxlength="40" value="' . esc_attr( $row['title'] ) . '" placeholder="' . ( '__new' === $id ? esc_attr( '+ پیام جدید' ) : '' ) . '"></td>';
			echo '<td><input class="regular-text" name="pm[presets][' . (int) $i . '][note]" maxlength="40" value="' . esc_attr( $row['note'] ) . '"></td>';
			echo '<td><input name="pm[presets][' . (int) $i . '][phone]" dir="ltr" maxlength="20" value="' . esc_attr( $row['phone'] ) . '"></td></tr>';
			++$i;
		}
		echo '</tbody></table><p><button type="button" class="button mda-pm-add">+ ردیف جدید</button> <button class="button button-primary">ذخیره پیام‌ها</button></p></form></div>';
		echo '<script>(function(){var b=document.querySelector(".mda-pm-add");if(!b)return;b.addEventListener("click",function(){var t=document.querySelector(".mda-pm-table tbody"),r=t.lastElementChild.cloneNode(true),n=t.children.length;r.querySelectorAll("input").forEach(function(i){i.name=i.name.replace(/\[presets\]\[\d+\]/,"[presets]["+n+"]");i.value="";});t.appendChild(r);});})();</script>';
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
