<?php
/**
 * Places the accounting app under the shared «افزونه اختصاصی» menu as one item («حسابداری»)
 * and gives every accounting screen the same two-level tab navigation.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Admin_Shell {
	/** Pages registered under an empty parent are reachable but not shown in the sidebar. */
	public const HIDDEN_PARENT = '';
	public const HOME          = 'mda-accounting';

	/**
	 * Navigation map: group => [label, [page slug => label]].
	 */
	public static function groups(): array {
		return array(
			'home'     => array( 'داشبورد', array( 'mda-accounting' => 'نمای کلی' ), 'dashicons-chart-area' ),
			'sales'    => array(
				'فروش',
				array(
					'mda-phone-sales'    => 'ثبت سفارش دستی',
					'mda-sales-receipts' => 'دریافت از مشتری',
					'mda-sales-returns'  => 'مرجوعی فروش',
					'mda-order-statuses' => 'وضعیت‌های سفارش',
					'mda-reconciliation' => 'تطبیق سفارش‌ها',
				),
				'dashicons-cart',
			),
			'products' => array( 'کالا و قیمت', array( 'mda-products' => 'اتاق کنترل محصولات', 'malidev-shahkar' => 'احراز هویت شاهکار', 'mda-identity' => 'سامانه مؤدیان' ), 'dashicons-products' ),
			'purchase' => array(
				'خرید و انبار',
				array(
					'mda-purchases'  => 'خرید',
					'mda-warehouses' => 'انبارها و انتقال',
					'mda-inventory'  => 'شمارش و سفارش‌های معوق',
					'mda-stock-sync' => 'همگام‌سازی موجودی',
				),
				'dashicons-archive',
			),
			'treasury' => array(
				'خزانه',
				array(
					'mda-cashbook'            => 'صندوق، بانک و هزینه',
					'mda-cheques'             => 'دفتر چک',
					'mda-bank-reconciliation' => 'مغایرت‌گیری بانکی',
					'mda-gateway-settlements' => 'تسویه درگاه‌ها',
				),
				'dashicons-bank',
			),
			'ledger'   => array(
				'اسناد و دفاتر',
				array(
					'mda-journal'      => 'سند حسابداری',
					'mda-ledgers'      => 'دفاتر و تراز',
					'mda-parties'      => 'طرف‌حساب‌ها',
					'mda-party-ledger' => 'گردش حساب اشخاص',
					'mda-accounts'     => 'کدینگ حساب‌ها',
				),
				'dashicons-book-alt',
			),
			'reports'  => array( 'گزارش‌ها', array( 'mda-reports' => 'سود و زیان و سودآوری' ), 'dashicons-chart-bar' ),
			'settings' => array( 'تنظیمات و ابزار', array( 'mda-settings' => 'تنظیمات، دوره مالی و پشتیبان' ), 'dashicons-admin-generic' ),
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_home' ), 9 );
		add_filter( 'woocamers_custom_plugins', array( __CLASS__, 'register_card' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
		add_action( 'all_admin_notices', array( __CLASS__, 'render_nav' ), 1 );
	}

	public static function parent(): string {
		return \MDA\Core\Menu::PARENT_SLUG;
	}

	/**
	 * Card on the shared «افزونه اختصاصی» hub (same registry the other MaliDev plugins use).
	 */
	public static function register_card( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		if ( ! current_user_can( MDA_Capabilities::for_page( self::HOME ) ) ) {
			return $plugins;
		}
		$plugins[ self::HOME ] = array(
			'name'        => 'حسابداری فروشگاه',
			'description' => 'حسابداری کامل فروشگاه ووکامرس: خرید و فروش، انبار، قیمت‌ها، خزانه، چک، اسناد و گزارش‌های مالی.',
			'version'     => MDA_VERSION,
			'icon'        => 'dashicons-calculator',
			'url'         => admin_url( 'admin.php?page=' . self::HOME ),
			'malidev'     => 'plugin-accounting',
		);
		return $plugins;
	}

	public static function register_home(): void {
		\MDA\Core\Menu::ensure_parent();
		add_submenu_page( self::parent(), 'حسابداری فروشگاه', 'حسابداری', MDA_Capabilities::for_page( self::HOME ), self::HOME, array( 'MDA_Admin', 'render' ) );
	}

	public static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 0 === strpos( $page, 'mda-' ) ? $page : '';
	}

	public static function parent_file( $file ) {
		return '' !== self::current_page() ? self::parent() : $file;
	}

	public static function submenu_file( $submenu_file, $parent_file ) {
		return '' !== self::current_page() ? self::HOME : $submenu_file;
	}

	public static function url( string $slug ): string {
		return admin_url( 'admin.php?page=' . $slug );
	}

	public static function render_nav(): void {
		$current = self::current_page();
		if ( '' === $current ) {
			return;
		}
		$active_group = 'home';
		foreach ( self::groups() as $group => $data ) {
			if ( isset( $data[1][ $current ] ) ) {
				$active_group = $group;
			}
		}
		$icon  = MDA_URL . 'assets/icon/icon-animated.svg';
		$quick = array(
			array( 'mda-phone-sales', 'dashicons-plus-alt2', 'سفارش دستی' ),
			array( 'mda-purchases', 'dashicons-download', 'خرید جدید' ),
			array( 'mda-products', 'dashicons-screenoptions', 'محصولات' ),
		);
		echo '<div class="mda-shell" dir="rtl"><div class="mda-shell__top"><div class="mda-shell__brand"><img src="' . esc_url( $icon ) . '" width="44" height="44" alt=""><div><strong>' . esc_html__( 'حسابداری فروشگاه', 'malidev-accounting' ) . '</strong><span>MaliDev · ' . esc_html( 'نسخه ' . MDA_VERSION ) . '</span></div></div><div class="mda-shell__quick">';
		foreach ( $quick as $q ) {
			if ( current_user_can( MDA_Capabilities::for_page( $q[0] ) ) ) {
				echo '<a href="' . esc_url( self::url( $q[0] ) ) . '"><span class="dashicons ' . esc_attr( $q[1] ) . '" aria-hidden="true"></span>' . esc_html( $q[2] ) . '</a>';
			}
		}
		if ( current_user_can( 'edit_shop_orders' ) ) {
			echo '<a href="' . esc_url( MDA_Integrations::orders_url() ) . '"><span class="dashicons dashicons-list-view" aria-hidden="true"></span>' . esc_html__( 'سفارش‌های ووکامرس', 'malidev-accounting' ) . '</a>';
		}
		echo '</div></div>';
		echo '<nav class="mda-shell__tabs" aria-label="' . esc_attr__( 'بخش‌های حسابداری', 'malidev-accounting' ) . '">';
		foreach ( self::groups() as $group => $data ) {
			$pages   = array_filter( array_keys( $data[1] ), static fn( $slug ) => current_user_can( MDA_Capabilities::for_page( $slug ) ) );
			$first   = reset( $pages );
			if ( ! $first ) {
				continue;
			}
			$class = $group === $active_group ? ' is-active' : '';
			echo '<a class="mda-shell__tab' . esc_attr( $class ) . '" href="' . esc_url( self::url( $first ) ) . '"><span class="dashicons ' . esc_attr( $data[2] ?? 'dashicons-admin-generic' ) . '" aria-hidden="true"></span>' . esc_html( $data[0] ) . '</a>';
		}
		echo '</nav>';
		$pages = self::groups()[ $active_group ][1];
		if ( count( $pages ) > 1 ) {
			echo '<nav class="mda-shell__sub">';
			foreach ( $pages as $slug => $label ) {
				if ( ! current_user_can( MDA_Capabilities::for_page( $slug ) ) ) {
					continue;
				}
				$class = $slug === $current ? ' is-active' : '';
				echo '<a class="mda-shell__pill' . esc_attr( $class ) . '" href="' . esc_url( self::url( $slug ) ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}
		echo '</div>';
		MDA_Help::render( $current );
	}
}
