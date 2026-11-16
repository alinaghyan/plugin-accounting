<?php
/**
 * The shared top-level "افزونه اختصاصی" menu.
 *
 * Every product ships this class. Whichever product loads first registers the
 * parent menu; the others only add their own sub-page under it.
 *
 * @package MDA\Core
 */

namespace MDA\Core;

defined( 'ABSPATH' ) || exit;

final class Menu {
	public const PARENT_SLUG     = 'woocamers-custom-plugin';
	public const PARENT_TITLE    = 'افزونه اختصاصی';
	public const REGISTRY_FILTER = 'woocamers_custom_plugins';

	public static function ensure_parent(): void {
		global $admin_page_hooks;
		if ( ! empty( $admin_page_hooks[ self::PARENT_SLUG ] ) ) {
			return;
		}
		add_menu_page(
			self::PARENT_TITLE,
			self::PARENT_TITLE,
			'manage_options',
			self::PARENT_SLUG,
			array( self::class, 'render_dashboard' ),
			'dashicons-screenoptions',
			3
		);
		add_submenu_page( self::PARENT_SLUG, self::PARENT_TITLE, 'همه افزونه‌ها', 'manage_options', self::PARENT_SLUG, array( self::class, 'render_dashboard' ) );
	}

	public static function is_dashboard( string $hook ): bool {
		return 'toplevel_page_' . self::PARENT_SLUG === $hook;
	}

	/**
	 * Every product under the shared menu: the ones that describe themselves via
	 * the registry filter, plus any other sub-page registered under the parent
	 * (older builds or products that do not ship the core), so nothing is hidden.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function installed_plugins(): array {
		global $submenu;

		$plugins = array();
		foreach ( (array) apply_filters( self::REGISTRY_FILTER, array() ) as $slug => $plugin ) {
			if ( ! is_array( $plugin ) || empty( $plugin['name'] ) ) {
				continue;
			}
			$plugins[ (string) $slug ] = array(
				'name'        => (string) $plugin['name'],
				'description' => (string) ( $plugin['description'] ?? '' ),
				'version'     => (string) ( $plugin['version'] ?? '' ),
				'icon'        => (string) ( $plugin['icon'] ?? 'dashicons-admin-plugins' ),
				'url'         => (string) ( $plugin['url'] ?? add_query_arg( 'page', (string) $slug, admin_url( 'admin.php' ) ) ),
				'update'      => (string) ( $plugin['update'] ?? '' ),
				'malidev'     => (string) ( $plugin['malidev'] ?? '' ),
			);
		}

		$known_urls = wp_list_pluck( $plugins, 'url' );
		foreach ( (array) ( $submenu[ self::PARENT_SLUG ] ?? array() ) as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( '' === $slug || self::PARENT_SLUG === $slug || isset( $plugins[ $slug ] ) ) {
				continue;
			}
			if ( ! empty( $item[1] ) && ! current_user_can( (string) $item[1] ) ) {
				continue;
			}
			$url = add_query_arg( 'page', $slug, admin_url( 'admin.php' ) );
			if ( in_array( $url, $known_urls, true ) ) {
				continue;
			}
			// Menu titles may carry count bubbles (<span class="awaiting-mod">3</span>); drop them.
			$name = trim( wp_strip_all_tags( (string) preg_replace( '#<span[^>]*>.*?</span>#s', '', (string) ( $item[3] ?? $item[0] ?? $slug ) ) ) );
			$plugins[ $slug ] = array(
				'name'        => '' !== $name ? $name : $slug,
				'description' => '',
				'version'     => '',
				'icon'        => 'dashicons-admin-plugins',
				'url'         => $url,
				'update'      => '',
				'malidev'     => '',
			);
		}

		uasort( $plugins, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
		return $plugins;
	}

	public static function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$plugins = self::installed_plugins();
		?>
		<div class="wrap wca-wrap" dir="rtl">
			<header class="wca-header">
				<span class="wca-header__icon dashicons dashicons-screenoptions" aria-hidden="true"></span>
				<div class="wca-header__text">
					<h1><?php echo esc_html( self::PARENT_TITLE ); ?></h1>
					<p>افزونه‌های اختصاصی نصب‌شده روی این سایت؛ هر افزونه تنظیمات و بروزرسانی جداگانه دارد.</p>
				</div>
			</header>
			<hr class="wp-header-end">
			<?php if ( empty( $plugins ) ) : ?>
				<div class="wca-card"><p>هنوز افزونه‌ای ثبت نشده است.</p></div>
			<?php else : ?>
				<div class="wca-grid wca-grid--plugins">
					<?php foreach ( $plugins as $plugin ) : ?>
						<article class="wca-card wca-plugin-card" data-md-slug="<?php echo esc_attr( $plugin['malidev'] ); ?>" data-md-name="<?php echo esc_attr( $plugin['name'] ); ?>">
							<div class="wca-plugin-card__head">
								<span class="dashicons <?php echo esc_attr( $plugin['icon'] ); ?>" aria-hidden="true"></span>
								<h2><?php echo esc_html( $plugin['name'] ); ?></h2>
							</div>
							<?php if ( ! empty( $plugin['description'] ) ) : ?>
								<p class="wca-muted"><?php echo esc_html( $plugin['description'] ); ?></p>
							<?php endif; ?>
							<div class="wca-plugin-card__foot">
								<?php if ( '' !== $plugin['version'] ) : ?>
									<span class="wca-badge"><?php echo esc_html( 'نسخه ' . $plugin['version'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $plugin['update'] ) ) : ?>
									<span class="wca-badge wca-badge--warning"><?php echo esc_html( 'نسخه ' . $plugin['update'] . ' آماده است' ); ?></span>
								<?php endif; ?>
								<a class="button button-primary" href="<?php echo esc_url( $plugin['url'] ); ?>">تنظیمات</a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
