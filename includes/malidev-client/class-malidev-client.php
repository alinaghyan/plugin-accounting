<?php
/**
 * MaliDev client: license activation, offline-verified signed tokens, heartbeat, updates.
 * Talks only to malidev.ir — no source-host/author/repo data lives here.
 *
 * @package MaliDev\Client
 */

defined( 'ABSPATH' ) || exit;

// Must come before the guard below: PHP declares this file's class at compile time
// (early binding), so class_exists() is already true there and the guard returns.
// Anything placed after it never runs. The store file has its own guard.
require_once __DIR__ . '/class-malidev-client-store.php';

if ( class_exists( 'MaliDev_Client', false ) ) {
	return;
}

final class MaliDev_Client {
	const VERSION = '1.5.3';
	const SERVER  = 'https://malidev.ir';
	// Ed25519 public key (base64) from malidev.ir → لایسنس MaliDev → تنظیمات.
	const PUBLIC_KEY = 'jNwGZS+JJCxRU7py5V1QM6L8+9LWC4OWYDB9Zwa81cg=';
	const STATE      = 'malidev_client_state';
	const UPD_CACHE  = 'malidev_client_updates';
	const PAGE       = 'malidev-license';
	const TD         = 'malidev-client';

	private static $products = array();
	private static $booted   = false;
	private static $hook     = '';

	/* ---------- bootstrap ---------- */

	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		MaliDev_Client_Store::init();
		self::flush_queue();
		add_action( 'after_setup_theme', array( __CLASS__, 'flush_queue' ), 99 );
		add_action( 'init', array( __CLASS__, 'adopt' ), 0 );
		add_action( 'admin_head-plugins.php', array( __CLASS__, 'plugin_icons_css' ) );
		add_action( 'wp_ajax_malidev_client_update', array( __CLASS__, 'ajax_update' ) );
		add_action( 'admin_footer', array( __CLASS__, 'hub_script' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_post_malidev_client', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'dismiss' ) );
		add_action( 'malidev_client_heartbeat', array( __CLASS__, 'heartbeat' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'malidev_client_run_update', array( __CLASS__, 'run_update' ), 10, 4 );
		if ( ! wp_next_scheduled( 'malidev_client_heartbeat' ) ) {
			wp_schedule_event( time() + 600, 'twicedaily', 'malidev_client_heartbeat' );
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_plugins' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'inject_themes' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 3 );
	}

	/**
	 * Known MaliDev products by Text Domain (and name fragments for builds without one).
	 * Older installs that predate this SDK are adopted, so they show up on «لایسنس و اتصال»
	 * and receive the update that brings their own SDK.
	 */
	const KNOWN_TD = array(
		'persian-invoice-studio' => 'plugin-factor-woocommerce',
		'wclp'                   => 'plugin-location',
		'rasad'                  => 'plugin-monitoring',
		'plugin-recommendation'  => 'plugin-recommendation',
		'ktp'                    => 'plugin-tracking-post',
		'smswt'                  => 'plugin-sms-woocamers-tracking',
		'apple-shop'             => 'themplate-apple-shop',
	);
	const KNOWN_NAME = array(
		'رهگیری سفارش، لوکیشن و پشتیبانی' => 'plugin-sms-woocamers-tracking',
		'کد رهگیری پستی'                  => 'plugin-tracking-post',
		'Persian Invoice Studio'          => 'plugin-factor-woocommerce',
		'استودیو فاکتور'                  => 'plugin-factor-woocommerce',
		'Location Picker'                 => 'plugin-location',
		'لوکیشن سفارشات'                  => 'plugin-location',
		'Rasad'                           => 'plugin-monitoring',
		'پیشنهادگر محصولات'               => 'plugin-recommendation',
		'قالب فروشگاهی سیب'               => 'themplate-apple-shop',
	);

	private static function known_slug( $td, $name ) {
		if ( $td && isset( self::KNOWN_TD[ $td ] ) ) {
			return self::KNOWN_TD[ $td ];
		}
		foreach ( self::KNOWN_NAME as $frag => $slug ) {
			if ( false !== mb_stripos( (string) $name, $frag ) ) {
				return $slug;
			}
		}
		return '';
	}

	/** Register installed MaliDev products that don't bundle the SDK yet (cached list of headers). */
	public static function adopt() {
		$cache = get_site_transient( 'malidev_client_found' );
		if ( ! is_array( $cache ) || ( $cache['sig'] ?? '' ) !== md5( implode( ',', (array) get_option( 'active_plugins', array() ) ) . get_stylesheet() ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$found = array();
			foreach ( get_plugins() as $file => $h ) {
				$slug = self::known_slug( $h['TextDomain'] ?? '', $h['Name'] ?? '' );
				if ( $slug ) {
					$found[] = array( 'slug' => $slug, 'type' => 'plugin', 'file' => WP_PLUGIN_DIR . '/' . $file, 'version' => (string) $h['Version'], 'name' => (string) $h['Name'], 'active' => is_plugin_active( $file ) );
				}
			}
			foreach ( wp_get_themes() as $ss => $th ) {
				$slug = self::known_slug( (string) $th->get( 'TextDomain' ), (string) $th->get( 'Name' ) );
				if ( $slug ) {
					$found[] = array( 'slug' => $slug, 'type' => 'theme', 'stylesheet' => $ss, 'version' => (string) $th->get( 'Version' ), 'name' => (string) $th->get( 'Name' ), 'active' => in_array( $ss, array( get_stylesheet(), get_template() ), true ) );
				}
			}
			$cache = array(
				'sig'   => md5( implode( ',', (array) get_option( 'active_plugins', array() ) ) . get_stylesheet() ),
				'items' => $found,
			);
			set_site_transient( 'malidev_client_found', $cache, DAY_IN_SECONDS );
		}
		foreach ( (array) $cache['items'] as $f ) {
			if ( isset( self::$products[ $f['slug'] ] ) || ( 'plugin' === $f['type'] && ! file_exists( $f['file'] ) ) ) {
				continue; // Already registered by its own (newer) SDK.
			}
			$f['adopted'] = true;
			self::register( $f );
		}
	}

	/** Animated product icons in the plugins list (icons come from the cached update-check, no extra HTTP). */
	public static function plugin_icons_css() {
		$css = '';
		$upd = self::cached_updates();
		foreach ( self::$products as $slug => $p ) {
			$url = self::icon_url( $slug, (array) ( $upd[ $slug ] ?? array() ) );
			if ( 'plugin' !== $p['type'] || ! $url ) {
				continue;
			}
			$sel  = 'tr[data-plugin="' . esc_attr( $p['basename'] ) . '"] .plugin-title';
			$css .= $sel . '{position:relative;padding-inline-start:58px!important}' . $sel . '::before{content:"";position:absolute;inset-inline-start:10px;top:10px;width:40px;height:40px;border-radius:10px;background:url("' . esc_url( $url ) . '") center/contain no-repeat;box-shadow:0 4px 12px rgba(0,0,0,.08)}';
		}
		if ( $css ) {
			echo '<style id="malidev-plugin-icons">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr/esc_url.
		}
	}

	/** Product rows for the hub/license page script: version, update, icon. */
	private static function ui_products() {
		$upd = self::cached_updates();
		$out = array();
		foreach ( self::$products as $slug => $p ) {
			$u     = (array) ( $upd[ $slug ] ?? array() );
			$new   = (string) ( $u['version'] ?? '' );
			$out[] = array(
				'slug'    => $slug,
				'name'    => (string) $p['name'],
				'type'    => $p['type'],
				'version' => (string) $p['version'],
				'update'  => ( $new && version_compare( $new, (string) $p['version'], '>' ) ) ? $new : '',
				'icon'    => self::icon_url( $slug, $u ),
				'license' => self::status( $slug ),
			);
		}
		return $out;
	}

	/**
	 * «افزونه اختصاصی» hub + «لایسنس و اتصال»: animated product icons and one-click AJAX updates.
	 * Works with every build of the shared hub (cards are matched by data-md-slug, or by name on older builds).
	 */
	public static function hub_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$parent = apply_filters( 'malidev_client_parent_menu', 'woocamers-custom-plugin' );
		if ( ! $screen || ( 'toplevel_page_' . $parent !== $screen->id && false === strpos( (string) $screen->id, self::PAGE ) ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$cfg = array(
			'url'      => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'malidev_client_update' ),
			'items'    => self::ui_products(),
			'license'  => self::page_url(),
			't_up'     => __( 'به‌روزرسانی به %s', 'malidev-client' ),
			't_run'    => __( 'در حال به‌روزرسانی…', 'malidev-client' ),
			't_ok'     => __( '✅ با موفقیت به نسخه‌ی %s به‌روزرسانی شد.', 'malidev-client' ),
			't_err'    => __( '❌ به‌روزرسانی انجام نشد', 'malidev-client' ),
			't_lic'    => __( 'برای دریافت به‌روزرسانی ابتدا لایسنس را فعال کنید', 'malidev-client' ),
			't_net'    => __( 'ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', 'malidev-client' ),
			't_latest' => __( 'آخرین نسخه', 'malidev-client' ),
		);
		?>
<style>
.mdc-ic{width:48px;height:48px;border-radius:14px;flex:none;object-fit:contain;filter:drop-shadow(0 6px 12px rgba(0,0,0,.12))}
.mdc-up{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:12px;padding:8px 14px;background:linear-gradient(135deg,#16a34a,#059669);color:#fff;font:inherit;font-weight:700;cursor:pointer;box-shadow:0 6px 16px rgba(5,150,105,.3);transition:transform .15s,box-shadow .15s}
.mdc-up:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(5,150,105,.35)}
.mdc-up[disabled]{opacity:.75;cursor:wait;transform:none}
.mdc-up svg{width:18px;height:18px}
.mdc-up:not([disabled]) svg .mdc-arrow{animation:mdcBounce 1.4s ease-in-out infinite}
.mdc-up[disabled] svg{animation:mdcSpin 1s linear infinite}
.mdc-up.is-ok{background:linear-gradient(135deg,#0ea5e9,#2563eb);cursor:default}
.mdc-up.is-lic{background:linear-gradient(135deg,#f59e0b,#d97706)}
@keyframes mdcBounce{0%,100%{transform:translateY(-2px)}50%{transform:translateY(2px)}}
@keyframes mdcSpin{to{transform:rotate(360deg)}}
.mdc-msg{flex-basis:100%;margin:6px 0 0;padding:8px 12px;border-radius:10px;font-size:13px}
.mdc-msg.is-ok{background:#dcfce7;color:#166534}.mdc-msg.is-err{background:#fee2e2;color:#991b1b}
.mdc-pulse{position:relative}.mdc-pulse::after{content:"";position:absolute;top:-3px;inset-inline-end:-3px;width:10px;height:10px;border-radius:50%;background:#ef4444;box-shadow:0 0 0 0 rgba(239,68,68,.6);animation:mdcPing 1.5s infinite}
@keyframes mdcPing{80%,100%{box-shadow:0 0 0 8px rgba(239,68,68,0)}}
@media (prefers-reduced-motion:reduce){.mdc-up svg,.mdc-up svg .mdc-arrow,.mdc-pulse::after{animation:none!important}}
</style>
<script>
(function(){
	var C=<?php echo wp_json_encode( $cfg ); ?>;
	var svg='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/><g class="mdc-arrow"><path d="M12 8v7"/><path d="M9 12l3 3 3-3"/></g></svg>';
	function f(t,v){return t.replace('%s',v);}
	function norm(s){return String(s||'').replace(/[‌\s|()\-–،,]+/g,' ').trim().toLowerCase();}
	function find(card){
		var sl=card.getAttribute('data-md-slug'),nm=norm(card.getAttribute('data-md-name')||(card.querySelector('h2,h3')||{}).textContent);
		return C.items.filter(function(p){return sl?p.slug===sl:(nm&&(norm(p.name).indexOf(nm)===0||nm.indexOf(norm(p.name))===0||norm(p.name).split(' ').slice(0,2).join(' ')===nm.split(' ').slice(0,2).join(' ')));})[0];
	}
	function button(p){
		var b=document.createElement('button');b.type='button';b.className='mdc-up';b.dataset.slug=p.slug;b.dataset.v=p.update;
		if(p.license!=='active'){b.classList.add('is-lic');b.title=C.t_lic;}
		b.innerHTML=svg+'<span>'+f(C.t_up,p.update)+'</span>';return b;
	}
	function msg(host,text,ok){var m=host.querySelector('.mdc-msg');if(!m){m=document.createElement('p');m.className='mdc-msg';host.appendChild(m);}m.className='mdc-msg '+(ok?'is-ok':'is-err');m.textContent=text;}
	// Hub cards.
	document.querySelectorAll('.wca-plugin-card').forEach(function(card){
		var p=find(card);if(!p){return;}
		var head=card.querySelector('.wca-plugin-card__head .dashicons');
		if(p.icon&&head){var i=document.createElement('img');i.src=p.icon;i.alt='';i.className='mdc-ic';head.replaceWith(i);}
		var foot=card.querySelector('.wca-plugin-card__foot');if(!foot){return;}
		var warn=foot.querySelector('.wca-badge--warning');
		if(p.update){if(warn){warn.classList.add('mdc-pulse');}foot.insertBefore(button(p),foot.querySelector('a.button'));}
		else if(warn){warn.remove();}
		if(!foot.querySelector('.wca-badge')&&p.version){var bd=document.createElement('span');bd.className='wca-badge';bd.textContent='نسخه '+p.version;foot.prepend(bd);}
	});
	// «لایسنس و اتصال» table: AJAX update buttons instead of a page reload.
	document.querySelectorAll('a.button-primary[href*="upgrade-plugin"],a.button-primary[href$="update-core.php"]').forEach(function(a){
		var tr=a.closest('tr');if(!tr){return;}
		var name=norm((tr.querySelector('strong')||{}).textContent),p=C.items.filter(function(x){return x.update&&norm(x.name)===name;})[0];
		if(p){a.replaceWith(button(p));}
	});
	document.addEventListener('click',function(e){
		var b=e.target.closest('.mdc-up');if(!b||b.disabled||b.classList.contains('is-ok')){return;}
		if(b.classList.contains('is-lic')){location.href=C.license;return;}
		var host=b.closest('.wca-plugin-card__foot')||b.closest('td');
		b.disabled=true;b.querySelector('span').textContent=C.t_run;
		fetch(C.url,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'malidev_client_update',_ajax_nonce:C.nonce,slug:b.dataset.slug})})
		.then(function(r){return r.json();})
		.then(function(j){
			b.disabled=false;
			if(j&&j.success){
				b.classList.add('is-ok');b.querySelector('span').textContent=C.t_latest+' '+j.data.version;msg(host,f(C.t_ok,j.data.version),true);
				var card=b.closest('.wca-plugin-card');if(card){var w=card.querySelector('.wca-badge--warning');if(w){w.remove();}var v=card.querySelector('.wca-badge:not(.wca-badge--warning)');if(v){v.textContent='نسخه '+j.data.version;}}
			}else{b.querySelector('span').textContent=f(C.t_up,b.dataset.v);msg(host,C.t_err+': '+((j&&j.data&&j.data.msg)||C.t_net),false);}
		}).catch(function(){b.disabled=false;b.querySelector('span').textContent=f(C.t_up,b.dataset.v);msg(host,C.t_err+': '+C.t_net,false);});
	});
})();
</script>
		<?php
	}

	/** AJAX: install the latest MaliDev release of one product (standard WP upgrader, checksum-verified). */
	public static function ajax_update() {
		check_ajax_referer( 'malidev_client_update' );
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$p    = self::$products[ $slug ] ?? null;
		if ( ! $p ) {
			wp_send_json_error( array( 'msg' => __( 'محصول پیدا نشد.', 'malidev-client' ) ) );
		}
		$theme = 'theme' === $p['type'];
		if ( ! current_user_can( $theme ? 'update_themes' : 'update_plugins' ) ) {
			wp_send_json_error( array( 'msg' => __( 'اجازه‌ی به‌روزرسانی ندارید.', 'malidev-client' ) ), 403 );
		}
		if ( 'active' !== self::status( $slug ) ) {
			wp_send_json_error( array( 'msg' => __( 'لایسنس این محصول فعال نیست؛ از «لایسنس و اتصال» فعالش کنید.', 'malidev-client' ) ) );
		}
		$u = (array) ( self::updates( true )[ $slug ] ?? array() );
		if ( empty( $u['version'] ) || ! version_compare( $u['version'], $p['version'], '>' ) ) {
			wp_send_json_success( array( 'version' => $p['version'], 'msg' => __( 'آخرین نسخه نصب است.', 'malidev-client' ) ) );
		}
		if ( empty( $u['package'] ) ) {
			wp_send_json_error( array( 'msg' => __( 'سرور بسته‌ی دانلود را برای این لایسنس ارائه نکرد.', 'malidev-client' ) ) );
		}
		// Put a fresh entry into the core transient so the upgrader picks our package (malidev:// → one-time URL).
		$key = $theme ? 'update_themes' : 'update_plugins';
		$t   = get_site_transient( $key );
		$t   = is_object( $t ) ? $t : new \stdClass();
		$t->checked = (array) ( $t->checked ?? array() ) + array( $p['basename'] => $p['version'] );
		$t   = $theme ? self::inject_themes( $t ) : self::inject_plugins( $t );
		set_site_transient( $key, $t );

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$was      = ! $theme && is_plugin_active( $p['basename'] );
		$upgrader = $theme ? new \Theme_Upgrader( $skin ) : new \Plugin_Upgrader( $skin );
		$r        = $upgrader->upgrade( $p['basename'] );
		if ( is_wp_error( $r ) || ! $r || $skin->get_errors()->has_errors() ) {
			$err = is_wp_error( $r ) ? $r->get_error_message() : $skin->get_error_messages();
			wp_send_json_error( array( 'msg' => $err ? wp_strip_all_tags( $err ) : __( 'نصب بسته ناموفق بود (دسترسی نوشتن فایل‌ها یا دانلود را بررسی کنید).', 'malidev-client' ) ) );
		}
		if ( $was && ! is_plugin_active( $p['basename'] ) ) {
			activate_plugin( $p['basename'], '', false, true );
		}
		wp_clean_plugins_cache();
		$ver = $theme ? (string) wp_get_theme( $p['basename'] )->get( 'Version' ) : (string) get_plugin_data( WP_PLUGIN_DIR . '/' . $p['basename'], false, false )['Version'];
		delete_site_transient( 'malidev_client_found' );
		wp_send_json_success( array( 'version' => $ver ? $ver : $u['version'] ) );
	}

	/** Plugins list: product name → its landing page on malidev.ir. */
	public static function row_meta( $links, $file ) {
		foreach ( self::$products as $p ) {
			if ( 'plugin' === $p['type'] && $p['basename'] === $file ) {
				$name    = function_exists( 'get_plugin_data' ) && $p['file'] ? get_plugin_data( $p['file'], false, true )['Name'] : $p['name'];
				$links[] = sprintf( '<a href="%s" target="_blank" rel="noopener"><strong>%s</strong></a>', esc_url( self::SERVER . '/products/' . rawurlencode( $p['slug'] ) . '/' ), esc_html( sprintf( /* translators: %s: product name */ __( 'صفحه‌ی %s', 'malidev-client' ), $name ) ) );
				$links[] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( self::SERVER . '/?post_type=product' ), esc_html__( 'محصولات دیگر MaliDev', 'malidev-client' ) );
			}
		}
		return $links;
	}

	public static function flush_queue() {
		$queue                           = isset( $GLOBALS['malidev_client_queue'] ) ? (array) $GLOBALS['malidev_client_queue'] : array();
		$GLOBALS['malidev_client_queue'] = array();
		foreach ( $queue as $a ) {
			self::register( $a );
		}
	}

	public static function register( array $a ) {
		$a         = wp_parse_args(
			$a,
			array(
				'slug'    => '',
				'type'    => 'plugin',
				'file'    => '',
				'version' => '0',
				'name'    => '',
				// true = premium features need an active license; false = everything works, only updates/support stop.
				'premium_requires_license' => false,
				// Optional local icon (URL inside this site's wp-content), used until the server sends one.
				'icon'    => '',
			)
		);
		$a['slug'] = sanitize_key( $a['slug'] );
		if ( ! $a['slug'] ) {
			return;
		}
		$a['basename'] = 'theme' === $a['type'] ? ( $a['stylesheet'] ?? $a['slug'] ) : plugin_basename( $a['file'] );
		$a['name']     = $a['name'] ? $a['name'] : $a['slug'];
		$a['icon']     = self::local_icon( (string) $a['icon'] );

		self::$products[ $a['slug'] ] = $a;
	}

	/* ---------- public helpers for products ---------- */

	/** active | expired | revoked | suspended | invalid | missing */
	public static function status( $slug ) {
		$s = self::state( $slug );
		if ( empty( $s['key'] ) ) {
			return 'missing';
		}
		$d = self::payload( $s );
		if ( ! $d || ( $d['product'] ?? '' ) !== $slug || ( $d['domain'] ?? '' ) !== self::domain() ) {
			return 'invalid';
		}
		if ( 'active' !== ( $d['status'] ?? '' ) ) {
			return (string) $d['status'];
		}
		if ( ! empty( $d['expires_at'] ) && (int) $d['expires_at'] < time() ) {
			return 'expired';
		}
		return 'active';
	}

	/** Decoded token, verified once at accept() time; no crypto on page loads. */
	private static function payload( array $s ) {
		if ( empty( $s['token'] ) || empty( $s['payload'] ) || ( $s['tok_hash'] ?? '' ) !== md5( $s['token'] ) ) {
			return null;
		}
		return $s['payload'];
	}

	public static function is_active( $slug ) {
		return 'active' === self::status( $slug );
	}

	/** Verified license data (expires_at, status, domain…) or null. */
	public static function license_data( $slug ) {
		return self::payload( self::state( $slug ) );
	}

	/** Use in products to gate premium features: if ( MaliDev_Client::premium_enabled( 'slug' ) ) { ... } */
	public static function premium_enabled( $slug ) {
		$p  = self::$products[ $slug ] ?? null;
		$on = ! $p || empty( $p['premium_requires_license'] ) || self::is_active( $slug );
		return (bool) apply_filters( 'malidev_client_premium_enabled', $on, $slug );
	}

	/* ---------- state & crypto ---------- */

	private static function all_state() {
		$s = get_option( self::STATE, array() ); // Autoloaded: served from memory.
		return is_array( $s ) ? $s : array();
	}

	private static function state( $slug ) {
		$all = self::all_state();
		return $all[ $slug ] ?? array();
	}

	private static function save_state( $slug, array $data ) {
		$all          = self::all_state();
		$all[ $slug ] = array_merge( $all[ $slug ] ?? array(), $data );
		update_option( self::STATE, $all, true );
	}

	public static function server_url() {
		return self::server();
	}

	private static function server() {
		return untrailingslashit( defined( 'MALIDEV_CLIENT_SERVER' ) ? MALIDEV_CLIENT_SERVER : self::SERVER );
	}

	private static function b64d( $s ) {
		return base64_decode( strtr( (string) $s, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	public static function verify_token( $token ) {
		$pk = base64_decode( self::PUBLIC_KEY, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$p  = explode( '.', (string) $token );
		if ( ! $pk || 32 !== strlen( $pk ) || 2 !== count( $p ) ) {
			return null;
		}
		$sig = self::b64d( $p[1] );
		if ( ! $sig || 64 !== strlen( $sig ) || ! sodium_crypto_sign_verify_detached( $sig, $p[0], $pk ) ) {
			return null;
		}
		$d = json_decode( (string) self::b64d( $p[0] ), true );
		return is_array( $d ) ? $d : null;
	}

	/** Same normalization as the server. */
	public static function domain() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( preg_match( '/[^\x20-\x7e]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$host = strtolower( (string) idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) );
		}
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** Per-site Ed25519 keypair + install hash (private key never leaves this site). */
	private static function site_keys() {
		$k = get_option( 'malidev_client_site', array() );
		if ( empty( $k['pk'] ) ) {
			$kp = sodium_crypto_sign_keypair();
			$k  = array(
				'sk'   => base64_encode( sodium_crypto_sign_secretkey( $kp ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'pk'   => base64_encode( sodium_crypto_sign_publickey( $kp ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'hash' => bin2hex( random_bytes( 32 ) ),
			);
			update_option( 'malidev_client_site', $k, false );
		}
		return $k;
	}

	/* ---------- server API ---------- */

	private static function api( $route, array $body ) {
		$r = wp_remote_post(
			self::server() . '/wp-json/malidev/v1/' . $route,
			array(
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new WP_Error( $data['code'] ?? 'http_' . $code, $data['message'] ?? 'HTTP ' . $code, array( 'status' => $code ) );
		}
		return $data;
	}

	private static function body( $slug ) {
		global $wp_version;
		$keys = self::site_keys();
		return array(
			'license_key'     => self::state( $slug )['key'] ?? '',
			'product_slug'    => $slug,
			'site_url'        => home_url(),
			'site_hash'       => $keys['hash'],
			'site_public_key' => $keys['pk'],
			'plugin_version'  => self::$products[ $slug ]['version'],
			'wp_version'      => $wp_version,
			'php_version'     => PHP_VERSION,
			'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'locale'          => get_locale(),
			'remote_updates'  => self::remote_allowed() ? 1 : 0,
			'multisite'       => is_multisite() ? 1 : 0,
		);
	}

	/** Store a successful activate/check response. */
	private static function accept( $slug, array $r ) {
		$payload = self::verify_token( $r['token'] ?? '' );
		if ( ! $payload ) {
			return new WP_Error( 'bad_signature', __( 'امضای پاسخ سرور معتبر نیست.', 'malidev-client' ) );
		}
		self::save_state(
			$slug,
			array(
				'token'      => $r['token'],
				'payload'    => $payload,
				'tok_hash'   => md5( $r['token'] ),
				'max_sites'  => (int) ( $r['max_sites'] ?? 0 ),
				'sites_used' => (int) ( $r['sites_used'] ?? 0 ),
				'checked_at' => time(),
				'error'      => '',
				'fails'      => 0,
				'next_try'   => 0,
			)
		);
		return true;
	}

	public static function activate( $slug, $key ) {
		self::save_state( $slug, array( 'key' => strtoupper( trim( $key ) ) ) );
		$r = self::api( 'activate', self::body( $slug ) );
		if ( is_wp_error( $r ) ) {
			self::save_state(
				$slug,
				array(
					'token' => '',
					'error' => $r->get_error_message(),
				)
			);
			return $r;
		}
		delete_site_transient( self::UPD_CACHE );
		return self::accept( $slug, $r );
	}

	/**
	 * Activate a product that was just installed (not loaded in this request yet).
	 * Used by the «اتصال MaliDev» connector after download + install.
	 *
	 * @param string $target Plugin basename (dir/file.php) or theme stylesheet.
	 */
	public static function install_activate( $slug, $type, $key, $version, $target ) {
		$slug = sanitize_key( $slug );
		if ( empty( self::$products[ $slug ] ) ) {
			self::register(
				'theme' === $type
					? array( 'slug' => $slug, 'type' => 'theme', 'version' => $version, 'stylesheet' => $target )
					: array( 'slug' => $slug, 'type' => 'plugin', 'version' => $version, 'file' => WP_PLUGIN_DIR . '/' . $target )
			);
		}
		return self::activate( $slug, $key );
	}

	/** Set/clear the domain-ownership challenge (connector uses the same callback route). */
	public static function set_challenge( $c ) {
		if ( $c ) {
			set_transient( 'malidev_client_challenge', $c, 5 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( 'malidev_client_challenge' );
		}
	}

	/** Domain-ownership challenge: only the hash is public; it exists for 5 minutes after the admin clicks. */
	public static function rest() {
		register_rest_route(
			'malidev-client/v1',
			'/challenge',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$c = get_transient( 'malidev_client_challenge' );
					return array( 'hash' => $c ? hash( 'sha256', $c ) : '' );
				},
			)
		);
		register_rest_route(
			'malidev-client/v1',
			'/command',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // Authenticated by the server's Ed25519 signature.
				'callback'            => static function ( WP_REST_Request $req ) {
					$reply = self::handle_message( (string) $req->get_param( 'message' ) );
					return $reply ? array( 'reply' => $reply ) : new WP_Error( 'malidev_rejected', 'Rejected.', array( 'status' => 403 ) );
				},
			)
		);
	}

	public static function remote_allowed() {
		return '0' !== get_option( 'malidev_client_remote_updates', '1' );
	}

	private static function b64u( $s ) {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** License key sealed by the server to this site's key (X25519 from our Ed25519 pair). */
	private static function open_key( $sealed ) {
		if ( '' === $sealed || ! function_exists( 'sodium_crypto_box_seal_open' ) ) {
			return '';
		}
		$k  = self::site_keys();
		$sk = base64_decode( $k['sk'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$pk = base64_decode( $k['pk'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$c  = self::b64d( $sealed );
		if ( ! $sk || ! $pk || ! $c ) {
			return '';
		}
		try {
			$kp    = sodium_crypto_box_keypair_from_secretkey_and_publickey( sodium_crypto_sign_ed25519_sk_to_curve25519( $sk ), sodium_crypto_sign_ed25519_pk_to_curve25519( $pk ) );
			$plain = sodium_crypto_box_seal_open( $c, $kp );
		} catch ( \SodiumException $e ) {
			return '';
		}
		$plain = strtoupper( trim( (string) $plain ) );
		return preg_match( '/^MLD(-[A-Z0-9]{5}){4}$/', $plain ) ? $plain : '';
	}

	/** Reply signed with this site's private key. */
	private static function sign_reply( $nonce, $status, array $result = array() ) {
		$sk   = base64_decode( self::site_keys()['sk'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$body = self::b64u(
			wp_json_encode(
				array(
					'nonce'     => $nonce,
					'status'    => $status,
					'result'    => $result,
					'issued_at' => time(),
				)
			)
		);
		return $body . '.' . self::b64u( sodium_crypto_sign_detached( $body, $sk ) );
	}

	/**
	 * Verify a server command (signature, domain, ±5 min, one-time nonce, our activation, whitelist)
	 * and execute it. Anything invalid is silently rejected.
	 *
	 * @return string|null Signed reply.
	 */
	public static function handle_message( $token ) {
		$d   = self::verify_token( $token );
		$now = time();
		if ( ! $d || ! in_array( $d['command'] ?? '', array( 'ping', 'report', 'update' ), true ) || ( $d['domain'] ?? '' ) !== self::domain()
			|| abs( $now - (int) ( $d['issued_at'] ?? 0 ) ) > 300 || (int) ( $d['expires_at'] ?? 0 ) < $now || ! preg_match( '/^[a-f0-9]{32}$/', (string) ( $d['nonce'] ?? '' ) ) ) {
			return null;
		}
		$slug = '';
		foreach ( array_keys( self::$products ) as $s ) {
			$p = self::payload( self::state( $s ) );
			if ( $p && (int) $p['activation_id'] === (int) ( $d['activation_id'] ?? 0 ) ) {
				$slug = $s;
			}
		}
		// «تست اتصال» from malidev.ir: a fresh server-signed license token (and the key, sealed to
		// this site's own key) for this exact domain + activation. Lets the server repair a site
		// whose local license state is missing, expired or stale. Nothing is executed.
		$sync = null;
		$pl   = (array) ( $d['payload'] ?? array() );
		if ( 'ping' === $d['command'] && ! empty( $pl['token'] ) ) {
			$t = self::verify_token( (string) $pl['token'] );
			if ( $t && ( $t['domain'] ?? '' ) === self::domain() && (int) ( $t['activation_id'] ?? 0 ) === (int) ( $d['activation_id'] ?? 0 )
				&& ( $t['product'] ?? '' ) === ( $pl['slug'] ?? '' ) && abs( $now - (int) ( $t['issued_at'] ?? 0 ) ) <= 300 ) {
				$sync = $t;
				if ( ! $slug ) {
					$slug = (string) $t['product'];
				}
			}
		}
		$seen = get_option( 'malidev_client_nonces', array() );
		$seen = array_filter( is_array( $seen ) ? $seen : array(), static fn( $exp ) => $exp > $now );
		if ( ! $slug || isset( $seen[ $d['nonce'] ] ) ) {
			return null;
		}
		$seen[ $d['nonce'] ] = $now + 600;
		update_option( 'malidev_client_nonces', array_slice( $seen, -200, null, true ), false );

		if ( 'update' !== $d['command'] ) {
			global $wp_version;
			$versions = array();
			foreach ( self::$products as $s => $p ) {
				$versions[ $s ] = $p['version'];
			}
			$extra = array();
			if ( $sync ) {
				if ( empty( self::$products[ $slug ] ) ) {
					$extra['product_missing'] = true; // Signed for this site, but the product is not loaded (inactive/removed).
				} else {
					$before = self::status( $slug );
					$key    = self::open_key( (string) ( $pl['key'] ?? '' ) );
					if ( $key && ( self::state( $slug )['key'] ?? '' ) !== $key ) {
						self::save_state( $slug, array( 'key' => $key ) );
						$extra['key_restored'] = true;
					}
					if ( ! empty( self::state( $slug )['key'] ) ) {
						$ok = self::accept(
							$slug,
							array(
								'token'      => (string) $pl['token'],
								'max_sites'  => (int) ( $pl['max_sites'] ?? 0 ),
								'sites_used' => (int) ( $pl['sites_used'] ?? 0 ),
							)
						);
						$extra['synced'] = true === $ok;
						delete_site_transient( self::UPD_CACHE );
					}
					$extra['license_before'] = $before;
				}
			}
			return self::sign_reply(
				$d['nonce'],
				'done',
				array(
					'versions'       => $versions,
					'wp'             => $wp_version,
					'php'            => PHP_VERSION,
					'license'        => isset( self::$products[ $slug ] ) ? self::status( $slug ) : 'missing',
					'sdk'            => self::VERSION,
					'cron_ok'        => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && (bool) wp_next_scheduled( 'malidev_client_heartbeat' ),
					'remote_updates' => self::remote_allowed(),
				) + $extra
			);
		}
		$pl = (array) ( $d['payload'] ?? array() );
		if ( ! self::remote_allowed() ) {
			return self::sign_reply( $d['nonce'], 'denied', array( 'remote_updates' => false ) );
		}
		if ( ( $pl['slug'] ?? '' ) !== $slug || ! preg_match( '/^[0-9A-Za-z.+-]{1,30}$/', (string) ( $pl['version'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $pl['checksum'] ?? '' ) ) ) {
			return self::sign_reply( $d['nonce'], 'failed', array( 'error' => 'bad_payload' ) );
		}
		if ( ! empty( $pl['downgrade'] ) ? false : version_compare( $pl['version'], self::$products[ $slug ]['version'], '<' ) ) {
			return self::sign_reply( $d['nonce'], 'failed', array( 'error' => 'downgrade_not_allowed' ) );
		}
		// Never inside the REST request: run in background cron.
		wp_schedule_single_event( time(), 'malidev_client_run_update', array( $slug, $d['nonce'], $pl['version'], $pl['checksum'] ) );
		return self::sign_reply( $d['nonce'], 'accepted', array( 'remote_updates' => true ) );
	}

	/** Background update: fetch via official endpoint, verify SHA-256, backup, install, health check, rollback. */
	public static function run_update( $slug, $nonce, $version, $checksum ) {
		$p      = self::$products[ $slug ] ?? null;
		$report = static function ( $status, array $result ) use ( $slug, $nonce ) {
			self::api( 'report', self::body( $slug ) + array( 'replies' => array( self::sign_reply( $nonce, $status, $result ) ) ) );
		};
		if ( ! $p || ! self::remote_allowed() ) {
			return $report( 'denied', array() );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$r = self::api( 'command-download', self::body( $slug ) + array( 'nonce' => $nonce ) );
		if ( is_wp_error( $r ) || empty( $r['package'] ) || ! hash_equals( $checksum, strtolower( (string) ( $r['checksum'] ?? '' ) ) ) ) {
			return $report( 'failed', array( 'error' => is_wp_error( $r ) ? $r->get_error_message() : 'download_unavailable' ) );
		}
		$tmp = download_url( $r['package'], 300 );
		if ( is_wp_error( $tmp ) || ! hash_equals( $checksum, hash_file( 'sha256', $tmp ) ) ) {
			if ( ! is_wp_error( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $report( 'failed', array( 'error' => 'checksum' ) );
		}
		if ( ! WP_Filesystem() || 'direct' !== $GLOBALS['wp_filesystem']->method ) {
			wp_delete_file( $tmp );
			return $report( 'failed', array( 'error' => 'filesystem_not_direct' ) );
		}
		$is_plugin = 'plugin' === $p['type'];
		$folder    = $is_plugin ? dirname( $p['basename'] ) : $p['basename'];
		$root      = $is_plugin ? WP_PLUGIN_DIR : get_theme_root();
		$dir       = $root . '/' . $folder;
		if ( $folder !== $slug ) {
			wp_delete_file( $tmp );
			return $report( 'failed', array( 'error' => 'folder_name_mismatch' ) );
		}
		$backup = WP_CONTENT_DIR . '/upgrade/malidev-backup-' . $slug . '-' . time();
		wp_mkdir_p( $backup );
		if ( is_wp_error( copy_dir( $dir, $backup ) ) ) {
			wp_delete_file( $tmp );
			return $report( 'failed', array( 'error' => 'backup_failed' ) );
		}
		$skin = new Automatic_Upgrader_Skin();
		$up   = $is_plugin ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );
		$res  = $up->run(
			array(
				'package'           => $tmp,
				'destination'       => $root,
				'clear_destination' => true,
				'clear_working'     => true,
				'hook_extra'        => $is_plugin ? array( 'plugin' => $p['basename'], 'type' => 'plugin', 'action' => 'update' ) : array( 'theme' => $p['basename'], 'type' => 'theme', 'action' => 'update' ),
			)
		);
		$health = wp_remote_get(
			home_url( '/?malidev_health=' . time() ),
			array(
				'timeout'   => 20,
				'sslverify' => false, // Loopback only.
			)
		);
		$code   = (int) wp_remote_retrieve_response_code( $health );
		$broken = ! $res || is_wp_error( $res ) || is_wp_error( $health ) || $code >= 500 || false !== stripos( (string) wp_remote_retrieve_body( $health ), 'critical error' );
		global $wp_filesystem;
		if ( $broken ) {
			$wp_filesystem->delete( $dir, true );
			wp_mkdir_p( $dir );
			copy_dir( $backup, $dir );
		}
		$wp_filesystem->delete( $backup, true );
		delete_site_transient( self::UPD_CACHE );
		$report(
			$broken ? 'rolled_back' : 'done',
			array(
				'version' => $broken ? self::$products[ $slug ]['version'] : $version,
				'http'    => $code,
				'error'   => is_wp_error( $res ) ? $res->get_error_message() : ( is_wp_error( $health ) ? $health->get_error_message() : '' ),
			)
		);
	}

	/** Activate using the domain entered at purchase (no key typing). */
	public static function auto_activate( $slug ) {
		$c = bin2hex( random_bytes( 16 ) );
		set_transient( 'malidev_client_challenge', $c, 5 * MINUTE_IN_SECONDS );
		$b = self::body( $slug );
		unset( $b['license_key'] );
		$b['challenge'] = $c;
		$r              = self::api( 'auto-activate', $b );
		delete_transient( 'malidev_client_challenge' );
		if ( ! is_wp_error( $r ) && empty( $r['license_key'] ) ) {
			$r = new WP_Error( 'malidev_bad_response', __( 'پاسخ سرور نامعتبر است.', 'malidev-client' ) );
		}
		if ( is_wp_error( $r ) ) {
			self::save_state( $slug, array( 'error' => $r->get_error_message() ) );
			return $r;
		}
		self::save_state( $slug, array( 'key' => strtoupper( sanitize_text_field( $r['license_key'] ) ) ) );
		delete_site_transient( self::UPD_CACHE );
		return self::accept( $slug, $r );
	}

	public static function deactivate( $slug ) {
		if ( ! empty( self::state( $slug )['key'] ) ) {
			self::api( 'deactivate', self::body( $slug ) );
		}
		$all = self::all_state();
		unset( $all[ $slug ] );
		update_option( self::STATE, $all, true );
		delete_site_transient( self::UPD_CACHE );
	}

	/** Heartbeat for one product. Offline/5xx keeps the signed token (works until expiry). */
	public static function check( $slug ) {
		$s = self::state( $slug );
		if ( empty( $s['key'] ) ) {
			return;
		}
		$r = self::api( 'check', self::body( $slug ) );
		if ( ! is_wp_error( $r ) ) {
			$ok = self::accept( $slug, $r );
			if ( is_wp_error( $ok ) ) {
				self::save_state( $slug, array( 'error' => $ok->get_error_message() ) );
				return;
			}
			$replies = array();
			foreach ( array_slice( (array) ( $r['commands'] ?? array() ), 0, 10 ) as $msg ) { // Pull fallback.
				$reply = self::handle_message( (string) $msg );
				if ( $reply ) {
					$replies[] = $reply;
				}
			}
			if ( $replies ) {
				self::api( 'report', self::body( $slug ) + array( 'replies' => $replies ) );
			}
			return;
		}
		$code = (int) ( $r->get_error_data()['status'] ?? 0 );
		if ( $code >= 400 && $code < 500 && 429 !== $code ) { // Server answered: no longer valid here.
			self::save_state(
				$slug,
				array(
					'token'      => '',
					'error'      => $r->get_error_message(),
					'checked_at' => time(),
				)
			);
			return;
		}
		$fails = (int) ( $s['fails'] ?? 0 ) + 1;
		self::save_state(
			$slug,
			array(
				'fails'    => $fails,
				'error'    => $r->get_error_message(),
				'next_try' => time() + min( 12 * HOUR_IN_SECONDS, 15 * MINUTE_IN_SECONDS * ( 2 ** min( $fails, 6 ) ) ),
			)
		);
	}

	public static function heartbeat() {
		foreach ( array_keys( self::$products ) as $slug ) {
			$s = self::state( $slug );
			if ( ! empty( $s['key'] ) && (int) ( $s['next_try'] ?? 0 ) <= time() ) {
				self::check( $slug );
			}
		}
		self::updates( true );
	}

	/* ---------- updates ---------- */

	/** Cached update info only — safe on any page load (no HTTP). */
	public static function cached_updates() {
		$c = get_site_transient( self::UPD_CACHE );
		return is_array( $c ) ? $c : array();
	}

	public static function updates( $force = false ) {
		$c = get_site_transient( self::UPD_CACHE );
		if ( ! $force && is_array( $c ) ) {
			$missing = array_diff( array_keys( self::$products ), array_keys( $c ) );
			if ( ! $missing || get_site_transient( 'malidev_client_upd_retry' ) ) {
				return $c;
			}
			set_site_transient( 'malidev_client_upd_retry', 1, HOUR_IN_SECONDS ); // A newly found product: ask once.
		}
		$items = array();
		foreach ( self::$products as $slug => $p ) {
			$items[] = array(
				'slug'        => $slug,
				'version'     => $p['version'],
				'license_key' => self::state( $slug )['key'] ?? '',
			);
		}
		if ( ! $items ) {
			return array();
		}
		$r   = self::api(
			'update-check',
			array(
				'site_url' => home_url(),
				'items'    => $items,
			)
		);
		$out = is_wp_error( $r ) ? array() : (array) ( $r['items'] ?? array() );
		set_site_transient( self::UPD_CACHE, $out, is_wp_error( $r ) ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/** Bundled icon given at registration: only URLs under this site's wp-content are accepted. */
	private static function local_icon( $url ) {
		$url = esc_url_raw( $url );
		return ( $url && 0 === strpos( set_url_scheme( $url ), set_url_scheme( content_url( '/' ) ) ) ) ? $url : '';
	}

	/** Icon to show for a product: server icon (cached update-check) first, then the bundled one. */
	private static function icon_url( $slug, $u = null ) {
		$ic  = self::icons( null === $u ? (array) ( self::cached_updates()[ $slug ] ?? array() ) : (array) $u );
		$url = $ic['svg'] ?? ( $ic['2x'] ?? ( $ic['1x'] ?? '' ) );
		return $url ? $url : (string) ( self::$products[ $slug ]['icon'] ?? '' );
	}

	/** Product icons from the server (only URLs on the server's own host are accepted). */
	private static function icons( $u ) {
		$host = wp_parse_url( self::server(), PHP_URL_HOST );
		$out  = array();
		foreach ( (array) ( $u['icons'] ?? array() ) as $k => $url ) {
			if ( in_array( $k, array( 'svg', '1x', '2x' ), true ) && is_string( $url ) && 0 === strpos( $url, 'https://' ) && wp_parse_url( $url, PHP_URL_HOST ) === $host ) {
				$out[ $k ] = esc_url_raw( $url );
			}
		}
		return $out;
	}

	private static function update_item( $p, array $u ) {
		return array(
			'slug'         => $p['slug'],
			'new_version'  => $u['version'],
			'url'          => self::server(),
			// Real one-time URL is fetched at install time (pre_download).
			'package'      => empty( $u['package'] ) ? '' : 'malidev://' . $p['slug'],
			'tested'       => $u['tested'] ?? '',
			'requires'     => $u['requires'] ?? '',
			'requires_php' => $u['requires_php'] ?? '',
		);
	}

	public static function inject_plugins( $t ) {
		if ( ! is_object( $t ) || empty( $t->checked ) ) {
			return $t;
		}
		foreach ( self::updates() as $slug => $u ) {
			$p = self::$products[ $slug ] ?? null;
			if ( ! $p || 'plugin' !== $p['type'] ) {
				continue;
			}
			$item = (object) ( self::update_item( $p, $u ) + array(
				'id'     => self::server() . '/' . $slug,
				'plugin' => $p['basename'],
				'icons'  => self::icons( $u ),
			) );
			if ( version_compare( $u['version'], $p['version'], '>' ) ) {
				$t->response[ $p['basename'] ] = $item;
			} else {
				unset( $t->response[ $p['basename'] ] );
				$t->no_update[ $p['basename'] ] = $item;
			}
		}
		return $t;
	}

	public static function inject_themes( $t ) {
		if ( ! is_object( $t ) || empty( $t->checked ) ) {
			return $t;
		}
		foreach ( self::updates() as $slug => $u ) {
			$p = self::$products[ $slug ] ?? null;
			if ( $p && 'theme' === $p['type'] && version_compare( $u['version'], $p['version'], '>' ) ) {
				$t->response[ $p['basename'] ] = array( 'theme' => $p['basename'] ) + self::update_item( $p, $u );
			}
		}
		return $t;
	}

	public static function plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || ! isset( self::$products[ $args->slug ] ) ) {
			return $res;
		}
		$key  = 'malidev_client_info_' . $args->slug;
		$info = get_site_transient( $key );
		if ( ! is_array( $info ) ) {
			$r    = wp_remote_get( self::server() . '/wp-json/malidev/v1/info/' . rawurlencode( $args->slug ), array( 'timeout' => 10 ) );
			$info = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( is_wp_error( $r ) || empty( $info['version'] ) ) {
				return $res;
			}
			set_site_transient( $key, $info, 12 * HOUR_IN_SECONDS );
		}
		return (object) array(
			'name'          => $info['name'],
			'slug'          => $args->slug,
			'version'       => $info['version'],
			'author'        => 'MaliDev',
			'homepage'      => $info['homepage'] ?? self::server(),
			'requires'      => $info['requires'] ?? '',
			'requires_php'  => $info['requires_php'] ?? '',
			'tested'        => $info['tested'] ?? '',
			'last_updated'  => $info['last_updated'] ?? '',
			'sections'      => array( 'changelog' => wp_kses_post( $info['changelog'] ?? '' ) ),
			'icons'         => self::icons( $info ),
			'download_link' => '',
		);
	}

	/** Fetch a fresh one-time URL, download, verify SHA-256. */
	public static function pre_download( $reply, $package, $upgrader ) {
		if ( ! is_string( $package ) || 0 !== strpos( $package, 'malidev://' ) ) {
			return $reply;
		}
		$slug = sanitize_key( substr( $package, 10 ) );
		$u    = self::updates( true )[ $slug ] ?? array();
		if ( empty( $u['package'] ) || empty( $u['checksum'] ) ) {
			return new WP_Error( 'malidev_no_license', __( 'برای به‌روزرسانی، لایسنس معتبر و فعال لازم است.', 'malidev-client' ) );
		}
		$tmp = download_url( $u['package'], 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! hash_equals( strtolower( $u['checksum'] ), hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'malidev_checksum', __( 'checksum بسته‌ی دانلودی معتبر نیست.', 'malidev-client' ) );
		}
		return $tmp;
	}

	/* ---------- connection test ---------- */

	public static function test_connection() {
		$t0  = microtime( true );
		$r   = wp_remote_get(
			self::server() . '/wp-json/malidev/v1/',
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$out = array(
			'ok'      => false,
			'ms'      => $ms,
			'lines'   => array(),
			'at'      => time(),
			'warning' => false,
		);
		if ( is_wp_error( $r ) ) {
			$m = $r->get_error_message();
			if ( false !== stripos( $m, 'SSL' ) || false !== stripos( $m, 'certificate' ) ) {
				$out['lines'][] = __( 'خطای گواهی SSL: ساعت سرور یا بسته‌ی CA هاست را بررسی کنید.', 'malidev-client' );
			} elseif ( false !== stripos( $m, 'resolve' ) ) {
				$out['lines'][] = __( 'دامنه‌ی malidev.ir پیدا نشد (مشکل DNS هاست).', 'malidev-client' );
			} elseif ( false !== stripos( $m, 'timed out' ) || false !== stripos( $m, 'error 28' ) ) {
				$out['lines'][] = __( 'سرور در ۱۰ ثانیه پاسخ نداد؛ احتمالاً فایروال هاست درخواست‌های خروجی را بسته است.', 'malidev-client' );
			} else {
				$out['lines'][] = __( 'اتصال برقرار نشد:', 'malidev-client' ) . ' ' . $m;
			}
			return $out;
		}
		$code       = (int) wp_remote_retrieve_response_code( $r );
		$out['ok']  = 200 === $code;
		$out['lines'][] = $out['ok']
			/* translators: %d: ms */
			? sprintf( __( 'سرور در دسترس است و SSL معتبر است (%d میلی‌ثانیه).', 'malidev-client' ), $ms )
			/* translators: %d: HTTP code */
			: sprintf( __( 'سرور پاسخ غیرمنتظره داد (HTTP %d).', 'malidev-client' ), $code );
		$date = wp_remote_retrieve_header( $r, 'date' );
		if ( $date && ( $ts = strtotime( $date ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			$skew = abs( time() - $ts );
			if ( $skew > 120 ) {
				$out['warning'] = true;
				/* translators: %d: seconds */
				$out['lines'][] = sprintf( __( 'ساعت این سرور %d ثانیه با malidev.ir اختلاف دارد؛ ساعت هاست را تنظیم کنید.', 'malidev-client' ), $skew );
			}
		}
		if ( $out['ok'] ) {
			$nonce = bin2hex( random_bytes( 16 ) );
			$first = array_key_first( self::$products );
			$pr    = self::api(
				'ping',
				array(
					'nonce'       => $nonce,
					'license_key' => $first ? ( self::state( $first )['key'] ?? '' ) : '',
				)
			);
			$pd    = is_wp_error( $pr ) ? null : self::verify_token( $pr['reply'] ?? '' );
			if ( $pd && hash_equals( $nonce, (string) ( $pd['nonce'] ?? '' ) ) ) {
				$out['lines'][] = __( 'امضای پاسخ سرور معتبر است (Ed25519).', 'malidev-client' );
				if ( ! empty( $pd['status'] ) ) {
					$labels         = self::labels();
					$out['lines'][] = __( 'وضعیت لایسنس روی سرور:', 'malidev-client' ) . ' ' . ( $labels[ $pd['status'] ] ?? $pd['status'] );
				}
			} else {
				$out['ok']      = false;
				$out['lines'][] = __( 'امضای پاسخ سرور نامعتبر است یا پاسخ نرسید.', 'malidev-client' );
			}
		}
		if ( ! base64_decode( self::PUBLIC_KEY, true ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$out['warning'] = true;
			$out['lines'][] = __( 'کلید عمومی در SDK تنظیم نشده است.', 'malidev-client' );
		}
		return $out;
	}

	/* ---------- admin UI ---------- */

	private static function labels() {
		return array(
			'active'    => __( 'فعال', 'malidev-client' ),
			'expired'   => __( 'منقضی', 'malidev-client' ),
			'revoked'   => __( 'باطل‌شده', 'malidev-client' ),
			'suspended' => __( 'معلق', 'malidev-client' ),
			'invalid'   => __( 'نامعتبر', 'malidev-client' ),
			'missing'   => __( 'بدون لایسنس', 'malidev-client' ),
		);
	}

	public static function menu() {
		global $admin_page_hooks;
		$parent = apply_filters( 'malidev_client_parent_menu', 'woocamers-custom-plugin' );
		if ( empty( $admin_page_hooks[ $parent ] ) ) {
			add_menu_page( __( 'افزونه اختصاصی', 'malidev-client' ), __( 'افزونه اختصاصی', 'malidev-client' ), 'manage_options', $parent, array( __CLASS__, 'page' ), 'dashicons-admin-plugins', 58 );
			self::$hook = add_submenu_page( $parent, __( 'لایسنس و اتصال', 'malidev-client' ), __( 'لایسنس و اتصال', 'malidev-client' ), 'manage_options', $parent, array( __CLASS__, 'page' ) );
		} else {
			self::$hook = add_submenu_page( $parent, __( 'لایسنس و اتصال', 'malidev-client' ), __( 'لایسنس و اتصال', 'malidev-client' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
		}
	}

	public static function page_link() {
		return self::page_url();
	}

	private static function page_url() {
		global $admin_page_hooks;
		$parent = apply_filters( 'malidev_client_parent_menu', 'woocamers-custom-plugin' );
		$slug   = ( isset( $admin_page_hooks[ $parent ] ) && self::$hook && false !== strpos( self::$hook, '_page_' . $parent ) ) ? $parent : self::PAGE;
		return admin_url( 'admin.php?page=' . $slug );
	}

	private static function format_date( $ts ) {
		$ts = (int) $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		if ( 0 !== strpos( get_user_locale(), 'fa' ) ) {
			return gmdate( 'Y-m-d', $ts );
		}
		list( $gy, $gm, $gd ) = array( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
		$gdm  = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2  = $gm > 2 ? $gy + 1 : $gy;
		$days = 355666 + 365 * $gy + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $gdm[ $gm - 1 ];
		$jy   = -1595 + 33 * intdiv( $days, 12053 );
		$days %= 12053;
		$jy  += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		$jm = $days < 186 ? 1 + intdiv( $days, 31 ) : 7 + intdiv( $days - 186, 30 );
		$jd = $days < 186 ? 1 + $days % 31 : 1 + ( $days - 186 ) % 30;
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	private static function button( $do, $slug, $label, $class = 'button' ) {
		return sprintf(
			'<a class="%s" href="%s">%s</a> ',
			esc_attr( $class ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=malidev_client&do=' . $do . '&slug=' . $slug ), 'malidev_client' ) ),
			esc_html( $label )
		);
	}

	public static function page() {
		$labels  = self::labels();
		$updates = self::updates();
		$test    = get_transient( 'malidev_client_test' );
		echo '<div class="wrap"><h1>' . esc_html__( 'لایسنس و اتصال', 'malidev-client' ) . '</h1>';

		$flash = get_transient( 'malidev_client_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'malidev_client_flash_' . get_current_user_id() );
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $flash[1] ), esc_html( $flash[0] ) );
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'محصول', 'نسخه', 'کلید لایسنس', 'وضعیت', 'انقضا', 'سایت‌ها', 'آخرین بررسی', 'عملیات' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( self::$products as $slug => $p ) {
			$s      = self::state( $slug );
			$status = self::status( $slug );
			$tok    = self::payload( $s );
			$latest = $updates[ $slug ]['version'] ?? '';
			$has_up = $latest && version_compare( $latest, $p['version'], '>' );
			$icon = self::icon_url( $slug );
			$note = '';
			if ( ! empty( $p['adopted'] ) ) {
				$note = '<br><small style="color:#b26200">' . esc_html( empty( $p['active'] ) ? __( 'غیرفعال در سایت · نسخه‌ی قدیمی؛ به‌روزرسانی کنید', 'malidev-client' ) : __( 'نسخه‌ی قدیمی؛ برای اتصال کامل به‌روزرسانی کنید', 'malidev-client' ) ) . '</small>';
			}
			echo '<tr><td><span style="display:flex;align-items:center;gap:10px">' . ( $icon ? '<img src="' . esc_url( $icon ) . '" width="40" height="40" alt="" style="border-radius:10px;flex:none" loading="lazy">' : '' ) . '<span><strong>' . esc_html( $p['name'] ) . '</strong> <small style="color:#646970">' . esc_html( 'theme' === $p['type'] ? __( 'قالب', 'malidev-client' ) : __( 'افزونه', 'malidev-client' ) ) . '</small>' . $note . '</span></span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td dir="ltr">' . esc_html( $p['version'] ) . ( $has_up ? ' → <strong>' . esc_html( $latest ) . '</strong>' : '' ) . '</td><td>';
			if ( empty( $s['key'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:4px">';
				wp_nonce_field( 'malidev_client' );
				echo '<input type="hidden" name="action" value="malidev_client"><input type="hidden" name="do" value="activate"><input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">';
				echo '<input name="key" dir="ltr" required placeholder="MLD-XXXXX-XXXXX-XXXXX-XXXXX" style="width:250px"><button class="button button-primary">' . esc_html__( 'فعال‌سازی', 'malidev-client' ) . '</button></form>';
				echo '<p style="margin:4px 0 0">' . self::button( 'auto', $slug, __( 'فعال‌سازی خودکار با دامنه', 'malidev-client' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo '<code dir="ltr">' . esc_html( substr( $s['key'], 0, 3 ) . '-•••••-' . substr( $s['key'], -4 ) ) . '</code>';
			}
			echo '</td><td>' . esc_html( $labels[ $status ] ?? $status );
			if ( ! empty( $s['error'] ) ) {
				echo '<br><small style="color:#b32d2e">' . esc_html( $s['error'] ) . '</small>';
			}
			echo '</td><td>' . ( $tok ? ( empty( $tok['expires_at'] ) ? esc_html__( 'مادام‌العمر', 'malidev-client' ) : esc_html( self::format_date( $tok['expires_at'] ) ) ) : '—' ) . '</td>';
			echo '<td>' . ( $tok ? esc_html( (int) ( $s['sites_used'] ?? 0 ) . ' / ' . ( ! empty( $s['max_sites'] ) ? (int) $s['max_sites'] : '∞' ) ) : '—' ) . '</td>';
			echo '<td>' . ( ! empty( $s['checked_at'] ) ? esc_html( human_time_diff( (int) $s['checked_at'] ) . ' ' . __( 'پیش', 'malidev-client' ) ) : '—' ) . '</td><td>';
			if ( ! empty( $s['key'] ) ) {
				echo self::button( 'check', $slug, __( 'بررسی مجدد', 'malidev-client' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::button( 'deactivate', $slug, __( 'غیرفعال‌سازی', 'malidev-client' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $has_up && 'plugin' === $p['type'] ) {
				$url = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $p['basename'] ) ), 'upgrade-plugin_' . $p['basename'] );
				echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'به‌روزرسانی', 'malidev-client' ) . '</a>';
			} elseif ( $has_up ) {
				echo '<a class="button button-primary" href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'به‌روزرسانی', 'malidev-client' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! self::$products ) {
			echo '<p class="description">' . esc_html__( 'هنوز محصول MaliDev فعالی روی این سایت نیست؛ از لیست پایین نصب کنید.', 'malidev-client' ) . '</p>';
		}

		MaliDev_Client_Store::render();

		echo '<h2>' . esc_html__( 'اتصال به malidev.ir', 'malidev-client' ) . '</h2><p>' . self::button( 'test', '', __( 'تست اتصال به malidev.ir', 'malidev-client' ), 'button button-secondary' ) . self::button( 'auto', '', __( 'فعال‌سازی خودکار همه با دامنه‌ی خرید', 'malidev-client' ), 'button button-primary' ) . self::button( 'refresh', '', __( 'بررسی به‌روزرسانی‌ها', 'malidev-client' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( is_array( $test ) ) {
			$cls = $test['ok'] ? ( $test['warning'] ? 'warning' : 'success' ) : 'error';
			echo '<div class="notice notice-' . esc_attr( $cls ) . ' inline"><ul>';
			foreach ( $test['lines'] as $l ) {
				echo '<li>' . esc_html( $l ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'malidev_client' );
		echo '<input type="hidden" name="action" value="malidev_client"><input type="hidden" name="do" value="settings">';
		echo '<p><label><input type="checkbox" name="remote_updates" value="1" ' . checked( '1', get_option( 'malidev_client_remote_updates', '1' ), false ) . '> ' . esc_html__( 'اجازه‌ی به‌روزرسانی ریموت از طرف malidev.ir', 'malidev-client' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'فقط نسخه‌ها، WP/PHP/WC و زبان سایت گزارش می‌شود؛ هیچ داده‌ی شخصی یا محتوایی ارسال نمی‌شود.', 'malidev-client' ) . '</p>';
		submit_button( __( 'ذخیره', 'malidev-client' ), 'secondary' );
		echo '</form></div>';
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'malidev-client' ), 403 );
		}
		check_admin_referer( 'malidev_client' );
		// phpcs:disable WordPress.Security.NonceVerification -- verified above.
		$do   = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		$slug = isset( $_REQUEST['slug'] ) ? sanitize_key( $_REQUEST['slug'] ) : '';
		$msg  = array( __( 'انجام شد.', 'malidev-client' ), 'success' );
		if ( $slug && ! isset( self::$products[ $slug ] ) ) {
			$do = '';
		}
		switch ( $do ) {
			case 'activate':
				$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
				$r   = self::activate( $slug, $key );
				$msg = is_wp_error( $r ) ? array( $r->get_error_message(), 'error' ) : array( __( 'لایسنس فعال شد.', 'malidev-client' ), 'success' );
				break;
			case 'auto':
				$ok   = 0;
				$errs = array();
				foreach ( $slug ? array( $slug ) : array_keys( self::$products ) as $s ) {
					if ( ! empty( self::state( $s )['key'] ) ) {
						continue;
					}
					$r = self::auto_activate( $s );
					if ( is_wp_error( $r ) ) {
						$errs[] = self::$products[ $s ]['name'] . ': ' . $r->get_error_message();
					} else {
						++$ok;
					}
				}
				/* translators: %d: count */
				$msg = $errs ? array( implode( ' | ', $errs ), $ok ? 'warning' : 'error' ) : array( sprintf( __( '%d محصول به‌صورت خودکار فعال شد.', 'malidev-client' ), $ok ), 'success' );
				break;
			case 'deactivate':
				self::deactivate( $slug );
				$msg = array( __( 'لایسنس از این سایت جدا شد.', 'malidev-client' ), 'success' );
				break;
			case 'check':
				self::save_state( $slug, array( 'next_try' => 0 ) );
				self::check( $slug );
				$err = self::state( $slug )['error'] ?? '';
				$msg = $err ? array( $err, 'error' ) : array( __( 'لایسنس بررسی شد.', 'malidev-client' ), 'success' );
				break;
			case 'test':
				set_transient( 'malidev_client_test', self::test_connection(), HOUR_IN_SECONDS );
				$msg = null;
				break;
			case 'refresh':
				self::updates( true );
				delete_site_transient( 'update_plugins' );
				delete_site_transient( 'update_themes' );
				break;
			case 'settings':
				update_option( 'malidev_client_remote_updates', empty( $_POST['remote_updates'] ) ? '0' : '1', false );
				break;
		}
		// phpcs:enable
		if ( $msg ) {
			set_transient( 'malidev_client_flash_' . get_current_user_id(), $msg, 60 );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/* ---------- notices ---------- */

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) || ( self::$hook && get_current_screen() && get_current_screen()->id === self::$hook ) ) {
			return;
		}
		$labels = self::labels();
		foreach ( self::$products as $slug => $p ) {
			$st = self::status( $slug );
			if ( 'active' === $st || (int) get_user_meta( get_current_user_id(), 'malidev_client_dismiss_' . $slug, true ) > time() ) {
				continue;
			}
			$dismiss = wp_nonce_url( add_query_arg( 'malidev_client_dismiss', $slug ), 'malidev_client_dismiss' );
			printf(
				'<div class="notice notice-warning"><p>%1$s — %2$s. %3$s <a href="%4$s">%5$s</a> | <a href="%6$s">%7$s</a></p></div>',
				esc_html( $p['name'] ),
				esc_html( $labels[ $st ] ?? $st ),
				empty( $p['premium_requires_license'] ) ? esc_html__( 'افزونه کار می‌کند، اما به‌روزرسانی و پشتیبانی تا فعال‌سازی لایسنس متوقف است.', 'malidev-client' ) : esc_html__( 'امکانات پریمیوم، به‌روزرسانی و پشتیبانی تا فعال‌سازی لایسنس غیرفعال است.', 'malidev-client' ),
				esc_url( self::page_url() ),
				esc_html__( 'لایسنس و اتصال', 'malidev-client' ),
				esc_url( $dismiss ),
				esc_html__( 'بستن (۷ روز)', 'malidev-client' )
			);
		}
	}

	public static function dismiss() {
		if ( empty( $_GET['malidev_client_dismiss'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'malidev_client_dismiss' );
		update_user_meta( get_current_user_id(), 'malidev_client_dismiss_' . sanitize_key( $_GET['malidev_client_dismiss'] ), time() + 7 * DAY_IN_SECONDS );
		wp_safe_redirect( remove_query_arg( array( 'malidev_client_dismiss', '_wpnonce' ) ) );
		exit;
	}
}
