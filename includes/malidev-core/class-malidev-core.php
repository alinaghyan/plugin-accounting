<?php
/**
 * MaliDev Core — shared layer bundled inside every MaliDev plugin.
 *
 * Several plugins may bundle different copies of this file. Each copy registers its version;
 * only the newest copy is loaded (see bootstrap.php), so all plugins share one menu, one
 * license client and one updater.
 *
 * Responsibilities:
 * - the shared top-level admin menu «افزونه اختصاصی» (merges with an existing one of the same title)
 * - product registry (each plugin registers name, version, file, repository, license product id)
 * - a hub page listing installed MaliDev products with version and license status
 * - license activation/check against malidev.ir and update delivery through the license server
 *
 * @package MaliDev_Core
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'MaliDev_Core' ) ) {
	return;
}

final class MaliDev_Core {
	public const VERSION     = '1.0.0';
	public const PARENT_SLUG = 'malidev-plugins';
	public const MENU_TITLE  = 'افزونه اختصاصی';
	public const CAPABILITY  = 'read';

	/** @var array<string,array> */
	private static array $products = array();
	private static bool $booted    = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_parent' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'merge_duplicate_parents' ), 9999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		MaliDev_Core_License::boot();
		MaliDev_Core_Updater::boot();
	}

	/**
	 * @param array{slug:string,name:string,version:string,file:string,license_product?:string,icon?:string,settings_url?:string,repository?:string} $product
	 */
	public static function register_product( array $product ): void {
		$slug = sanitize_key( $product['slug'] ?? '' );
		if ( '' === $slug || empty( $product['file'] ) ) {
			return;
		}
		self::$products[ $slug ] = wp_parse_args(
			$product,
			array(
				'slug'            => $slug,
				'name'            => $slug,
				'version'         => '0.0.0',
				'license_product' => $slug,
				'icon'            => '',
				'settings_url'    => '',
				'repository'      => '',
				'basename'        => plugin_basename( (string) $product['file'] ),
			)
		);
	}

	public static function products(): array {
		return self::$products;
	}

	public static function product( string $slug ): ?array {
		return self::$products[ $slug ] ?? null;
	}

	public static function parent_slug(): string {
		return (string) apply_filters( 'malidev_core_parent_slug', self::PARENT_SLUG );
	}

	public static function register_parent(): void {
		global $admin_page_hooks;
		$slug = self::parent_slug();
		if ( isset( $admin_page_hooks[ $slug ] ) ) {
			return;
		}
		add_menu_page(
			self::MENU_TITLE,
			self::MENU_TITLE,
			self::CAPABILITY,
			$slug,
			array( __CLASS__, 'render_hub' ),
			self::menu_icon(),
			55.7
		);
		add_submenu_page( $slug, 'محصولات MaliDev', 'محصولات و لایسنس', 'manage_options', $slug, array( __CLASS__, 'render_hub' ) );
	}

	/**
	 * If another plugin (an older build of a MaliDev plugin) created its own «افزونه اختصاصی» menu with a
	 * different slug, move our items under it so the user sees a single menu.
	 */
	public static function merge_duplicate_parents(): void {
		global $menu, $submenu;
		if ( ! is_array( $menu ) ) {
			return;
		}
		$ours  = self::parent_slug();
		$other = null;
		$mine  = null;
		foreach ( $menu as $index => $item ) {
			$title = trim( wp_strip_all_tags( (string) ( $item[0] ?? '' ) ) );
			if ( self::MENU_TITLE !== $title ) {
				continue;
			}
			if ( $ours === ( $item[2] ?? '' ) ) {
				$mine = $index;
			} elseif ( null === $other ) {
				$other = (string) $item[2];
			}
		}
		if ( null === $other || null === $mine ) {
			return;
		}
		foreach ( (array) ( $submenu[ $ours ] ?? array() ) as $entry ) {
			$submenu[ $other ][] = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		unset( $submenu[ $ours ], $menu[ $mine ] );
	}

	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M3 3.5A1.5 1.5 0 0 1 4.5 2h11A1.5 1.5 0 0 1 17 3.5v13a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 3 16.5v-13Zm3 2.2v8.6h1.8V9.1l2.2 3.2 2.2-3.2v5.2H14V5.7h-1.7L10 9.2 7.7 5.7H6Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::parent_slug() ) && false === strpos( $hook, 'malidev' ) ) {
			return;
		}
		wp_enqueue_style( 'malidev-core', plugins_url( 'assets/core.css', __FILE__ ), array(), self::VERSION );
	}

	public static function render_hub(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز', 'malidev-core' ) );
		}
		echo '<div class="wrap malidev-hub" dir="rtl"><h1>' . esc_html( self::MENU_TITLE ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'افزونه‌های MaliDev نصب‌شده روی این سایت، نسخه و وضعیت لایسنس آن‌ها.', 'malidev-core' ) . '</p>';
		MaliDev_Core_License::render_notices();
		echo '<div class="malidev-grid">';
		foreach ( self::$products as $product ) {
			$license = MaliDev_Core_License::state( $product['slug'] );
			echo '<div class="malidev-card">';
			if ( $product['icon'] ) {
				echo '<img class="malidev-card__icon" src="' . esc_url( $product['icon'] ) . '" alt="" width="56" height="56">';
			}
			echo '<h2>' . esc_html( $product['name'] ) . '</h2>';
			echo '<p>' . esc_html__( 'نسخه', 'malidev-core' ) . ' <code>' . esc_html( $product['version'] ) . '</code></p>';
			echo '<p class="malidev-badge malidev-badge--' . esc_attr( $license['status'] ) . '">' . esc_html( MaliDev_Core_License::label( $license['status'] ) ) . '</p>';
			MaliDev_Core_License::render_form( $product['slug'] );
			if ( $product['settings_url'] ) {
				echo '<p><a class="button" href="' . esc_url( $product['settings_url'] ) . '">' . esc_html__( 'ورود به افزونه', 'malidev-core' ) . '</a></p>';
			}
			echo '</div>';
		}
		echo '</div></div>';
	}
}
