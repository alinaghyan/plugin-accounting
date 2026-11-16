<?php
/**
 * «محصولات خریداری‌شده» section of the shared «لایسنس و اتصال» page:
 * lists what was bought for this domain on malidev.ir, confirms/fixes the domain,
 * then downloads (checksum-verified), installs or updates, activates, and activates the license.
 *
 * @package MaliDev\Client
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'MaliDev_Client_Store', false ) ) {
	return;
}

final class MaliDev_Client_Store {
	const SESSION = 'malidev_store_session';
	const KEYS    = 'malidev_store_keys';

	public static function init() {
		add_action( 'wp_ajax_malidev_store', array( __CLASS__, 'ajax' ) );
	}

	/* ---------- server ---------- */

	private static function api( $route, array $body, $timeout = 20 ) {
		$r = wp_remote_post(
			MaliDev_Client::server_url() . '/wp-json/malidev/v1/connector/' . $route,
			array(
				'timeout'   => $timeout,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $body + array( 'site_url' => home_url() ) ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'malidev_http', __( 'اتصال به malidev.ir برقرار نشد. فایروال هاست ممکن است درخواست‌های خروجی را بسته باشد.', 'malidev-client' ) . ' (' . $r->get_error_message() . ')' );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 404 === $code && is_array( $data ) && 'rest_no_route' === ( $data['code'] ?? '' ) ) {
			return new WP_Error( 'malidev_old_server', __( 'این امکان هنوز روی malidev.ir فعال نشده است؛ کمی بعد دوباره امتحان کنید.', 'malidev-client' ) );
		}
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new WP_Error( is_array( $data ) && ! empty( $data['code'] ) ? $data['code'] : 'http_' . $code, is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code, is_array( $data ) ? ( $data['data'] ?? array() ) : array() );
		}
		return $data;
	}

	/** Domain-proof session (cached ~25 min). */
	private static function session( $renew = false ) {
		$s = $renew ? '' : (string) get_transient( self::SESSION );
		if ( $s ) {
			return $s;
		}
		$c = bin2hex( random_bytes( 16 ) );
		MaliDev_Client::set_challenge( $c );
		$r = self::api( 'session', array( 'challenge' => $c ) );
		MaliDev_Client::set_challenge( '' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		set_transient( self::SESSION, (string) $r['session'], 25 * MINUTE_IN_SECONDS );
		return (string) $r['session'];
	}

	private static function keys() {
		$k = get_option( self::KEYS, array() );
		return is_array( $k ) ? $k : array();
	}

	/** Credential for one license: the key if the admin entered it, else the domain session. */
	private static function cred( $license_id ) {
		$k = self::keys();
		if ( ! empty( $k[ $license_id ] ) ) {
			return array( 'license_key' => $k[ $license_id ] );
		}
		$s = self::session();
		return is_wp_error( $s ) ? $s : array(
			'session'    => $s,
			'license_id' => (int) $license_id,
		);
	}

	private static function call( $route, $license_id ) {
		$t = 'package' === $route ? 90 : 20;
		$c = self::cred( $license_id );
		$r = is_wp_error( $c ) ? $c : self::api( $route, $c, $t );
		if ( is_wp_error( $r ) && 'malidev_session' === $r->get_error_code() ) {
			delete_transient( self::SESSION );
			$c = self::cred( $license_id );
			$r = is_wp_error( $c ) ? $c : self::api( $route, $c, $t );
		}
		return $r;
	}

	/* ---------- local state ---------- */

	/** Installed version ('' = not installed). */
	private static function installed_version( $slug, $type ) {
		if ( 'theme' === $type ) {
			$t = wp_get_theme( $slug );
			return $t->exists() ? (string) $t->get( 'Version' ) : '';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $f => $d ) {
			if ( 0 === strpos( $f, $slug . '/' ) ) {
				return (string) ( $d['Version'] ?? '0' );
			}
		}
		return '';
	}

	private static function decorate( array $it ) {
		$it['installed'] = $it['slug'] ? self::installed_version( $it['slug'], $it['type'] ) : '';
		$it['update']    = '' !== $it['installed'] && '' !== (string) $it['version'] && version_compare( $it['version'], $it['installed'], '>' );
		return $it;
	}

	/* ---------- AJAX ---------- */

	public static function ajax() {
		check_ajax_referer( 'malidev_store' );
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'malidev-client' ) ), 403 );
		}
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$lid = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		switch ( $do ) {
			case 'list':
				self::send( self::list_items() );
				break;
			case 'add_key':
				$key = isset( $_POST['key'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
				$r   = self::api( 'license', array( 'license_key' => $key ) );
				if ( ! is_wp_error( $r ) ) {
					$k                                   = self::keys();
					$k[ (int) $r['item']['license_id'] ] = $key;
					update_option( self::KEYS, $k, false );
					$r = array( 'item' => self::decorate( $r['item'] ) );
				}
				self::send( $r );
				break;
			case 'domain':
				$r = self::call( 'domain', $lid );
				self::send( is_wp_error( $r ) ? $r : array( 'item' => self::decorate( $r['item'] ) ) );
				break;
			case 'link':
				$r = self::call( 'package', $lid );
				self::send( is_wp_error( $r ) ? $r : array( 'url' => $r['package'] ) );
				break;
			case 'install':
				self::send( self::install( $lid, ! empty( $_POST['activate_theme'] ) ) );
				break;
			default:
				wp_send_json_error( array( 'message' => 'Bad request.' ), 400 );
		}
	}

	private static function send( $r ) {
		if ( is_wp_error( $r ) ) {
			wp_send_json_error(
				array(
					'code'    => $r->get_error_code(),
					'message' => $r->get_error_message(),
				)
			);
		}
		wp_send_json_success( $r );
	}

	private static function list_items() {
		$items = array();
		$note  = '';
		$s     = self::session();
		$r     = is_wp_error( $s ) ? $s : self::api( 'list', array( 'session' => $s ) );
		if ( is_wp_error( $r ) && 'malidev_session' === $r->get_error_code() ) {
			$s = self::session( true );
			$r = is_wp_error( $s ) ? $s : self::api( 'list', array( 'session' => $s ) );
		}
		if ( is_wp_error( $r ) ) {
			$note = $r->get_error_message();
		} else {
			foreach ( (array) $r['items'] as $it ) {
				$items[ (int) $it['license_id'] ] = self::decorate( $it );
			}
		}
		foreach ( self::keys() as $lid => $key ) { // Added by license key.
			if ( ! isset( $items[ (int) $lid ] ) ) {
				$x = self::api( 'license', array( 'license_key' => $key ) );
				if ( ! is_wp_error( $x ) ) {
					$items[ (int) $lid ] = self::decorate( $x['item'] );
				}
			}
		}
		return array(
			'items' => array_values( $items ),
			'note'  => $note,
		);
	}

	/** Download (checksum) → install/update (overwrite) → activate → license activation. */
	private static function install( $lid, $activate_theme ) {
		$r = self::call( 'package', $lid );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$type = 'theme' === $r['type'] ? 'theme' : 'plugin';
		if ( ! current_user_can( 'theme' === $type ? 'install_themes' : 'install_plugins' ) ) {
			return new WP_Error( 'malidev_cap', __( 'دسترسی نصب ندارید.', 'malidev-client' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$tmp = download_url( $r['package'], 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! hash_equals( strtolower( (string) $r['checksum'] ), hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'malidev_checksum', __( 'فایل دانلودشده سالم نیست (checksum نامعتبر). دوباره تلاش کنید.', 'malidev-client' ) );
		}
		$zip = $tmp . '.zip';
		rename( $tmp, $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

		$was  = 'plugin' === $type ? self::installed_version( $r['slug'], 'plugin' ) : '';
		$skin = new WP_Ajax_Upgrader_Skin();
		$up   = 'theme' === $type ? new Theme_Upgrader( $skin ) : new Plugin_Upgrader( $skin );
		$res  = $up->install( $zip, array( 'overwrite_package' => true ) );
		wp_delete_file( $zip );
		if ( is_wp_error( $res ) || ! $res ) {
			$e = is_wp_error( $res ) ? $res : $skin->get_errors();
			$m = ( $e instanceof WP_Error && $e->has_errors() ) ? $e->get_error_message() : __( 'نصب ناموفق بود (احتمالاً دسترسی نوشتن روی پوشه‌ها نیست).', 'malidev-client' );
			return new WP_Error( 'malidev_install', $m );
		}

		$msg = array();
		if ( 'theme' === $type ) {
			$theme  = $up->theme_info();
			$target = $theme ? $theme->get_stylesheet() : $r['slug'];
			$msg[]  = __( 'قالب نصب شد.', 'malidev-client' );
			if ( $activate_theme && current_user_can( 'switch_themes' ) && get_stylesheet() !== $target ) {
				switch_theme( $target );
				$msg[] = __( 'قالب فعال شد.', 'malidev-client' );
			}
		} else {
			$target = $up->plugin_info();
			$msg[]  = $was ? __( 'افزونه به‌روزرسانی شد.', 'malidev-client' ) : __( 'افزونه نصب شد.', 'malidev-client' );
			if ( $target && ! is_plugin_active( $target ) && current_user_can( 'activate_plugins' ) ) {
				$a     = activate_plugin( $target );
				$msg[] = is_wp_error( $a ) ? sprintf( /* translators: %s: error */ __( 'فعال‌سازی افزونه ناموفق: %s', 'malidev-client' ), $a->get_error_message() ) : __( 'افزونه فعال شد.', 'malidev-client' );
			}
		}
		if ( $target && ! empty( $r['license_key'] ) && ! MaliDev_Client::is_active( $r['slug'] ) ) {
			$l     = MaliDev_Client::install_activate( $r['slug'], $type, $r['license_key'], $r['version'], $target );
			$msg[] = is_wp_error( $l ) ? sprintf( /* translators: %s: error */ __( 'فعال‌سازی لایسنس انجام نشد: %s', 'malidev-client' ), $l->get_error_message() ) : __( 'لایسنس فعال شد ✓', 'malidev-client' );
		}
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		return array( 'message' => implode( ' ', $msg ) );
	}

	/* ---------- UI (printed inside «لایسنس و اتصال») ---------- */

	public static function render() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		$cfg = array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'malidev_store' ),
			'domain' => MaliDev_Client::domain(),
			'i18n'   => array(
				'loading' => __( 'در حال دریافت لیست محصولات خریداری‌شده…', 'malidev-client' ),
				'empty'   => __( 'برای این دامنه خریدی پیدا نشد. اگر هنگام خرید دامنه‌ی دیگری وارد کرده‌اید یا دامنه وارد نکرده‌اید، کد لایسنس را در کادر پایین وارد کنید.', 'malidev-client' ),
				'plugin'  => __( 'افزونه', 'malidev-client' ),
				'theme'   => __( 'قالب', 'malidev-client' ),
				'install' => __( 'دانلود و نصب', 'malidev-client' ),
				'update'  => __( 'به‌روزرسانی', 'malidev-client' ),
				'reinst'  => __( 'نصب دوباره', 'malidev-client' ),
				'inst'    => __( 'نصب‌شده', 'malidev-client' ),
				'expired' => __( 'منقضی شده', 'malidev-client' ),
				'working' => __( 'در حال دانلود و نصب… لطفاً صفحه را نبندید.', 'malidev-client' ),
				'err'     => __( 'خطا در ارتباط.', 'malidev-client' ),
				'manual'  => __( 'می‌توانید فایل zip را دستی دانلود و نصب کنید.', 'malidev-client' ),
				'guide'   => __( 'راهنما', 'malidev-client' ),
			),
		);
		?>
		<h2 style="margin-top:28px"><?php esc_html_e( 'محصولات خریداری‌شده برای این سایت', 'malidev-client' ); ?></h2>
		<p><?php esc_html_e( 'دامنه‌ی این سایت:', 'malidev-client' ); ?> <b dir="ltr"><?php echo esc_html( MaliDev_Client::domain() ); ?></b></p>
		<div id="mds-note" class="notice notice-warning inline" hidden><p></p></div>
		<div id="mds-list" class="mds-list"></div>
		<form id="mds-key" class="mds-key">
			<label for="mds-key-in"><?php esc_html_e( 'محصولتان در لیست نیست؟ کد لایسنس:', 'malidev-client' ); ?></label>
			<input id="mds-key-in" dir="ltr" placeholder="MLD-XXXXX-XXXXX-XXXXX-XXXXX" required>
			<button class="button"><?php esc_html_e( 'افزودن', 'malidev-client' ); ?></button>
		</form>
		<dialog id="mds-dlg" class="mds-dlg">
			<form method="dialog">
				<h2 data-f="title"></h2>
				<p><?php esc_html_e( 'این محصول روی این دامنه نصب و لایسنس آن فعال می‌شود:', 'malidev-client' ); ?> <b dir="ltr" data-f="domain"></b></p>
				<div class="mds-warn" data-f="mismatch" hidden>
					<p><?php esc_html_e( 'دامنه‌ای که هنگام خرید ثبت شده:', 'malidev-client' ); ?> <b dir="ltr" data-f="reserved"></b></p>
					<p><?php esc_html_e( 'اگر اشتباه وارد کرده‌اید یا می‌خواهید روی این سایت نصب کنید، دامنه را اصلاح کنید.', 'malidev-client' ); ?> <small><?php esc_html_e( 'تغییر باقی‌مانده:', 'malidev-client' ); ?> <span data-f="left"></span></small></p>
					<button type="button" class="button" data-act="fix"><?php esc_html_e( 'اصلاح دامنه به این سایت', 'malidev-client' ); ?></button>
				</div>
				<label class="mds-theme" data-f="themebox" hidden><input type="checkbox" data-f="activate_theme" checked> <?php esc_html_e( 'قالب بعد از نصب فعال شود (ظاهر سایت عوض می‌شود)', 'malidev-client' ); ?></label>
				<p class="mds-msg" data-f="msg"></p>
				<div class="mds-actions">
					<button type="button" class="button button-primary" data-act="go"><?php esc_html_e( 'تأیید، دانلود و نصب', 'malidev-client' ); ?></button>
					<button type="button" class="button" data-act="manual"><?php esc_html_e( 'دانلود دستی فایل zip', 'malidev-client' ); ?></button>
					<button value="cancel" class="button-link"><?php esc_html_e( 'بستن', 'malidev-client' ); ?></button>
				</div>
			</form>
		</dialog>
		<style>
		.mds-list{display:grid;gap:10px;margin:12px 0;max-width:900px}.mds-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-radius:10px}.mds-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;color:#50575e;font-size:12px;align-items:center}.mds-b{padding:1px 8px;border-radius:20px;background:#f0f0f1}.mds-b.ok{background:#e7f6ec;color:#116329}.mds-b.up{background:#fff4e5;color:#8a4b00}.mds-b.err{background:#fcf0f1;color:#8a2424}.mds-empty{padding:12px;background:#fff;border:1px dashed #c3c4c7;border-radius:10px}.mds-key{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.mds-key input{min-width:300px}.mds-dlg{border:0;border-radius:12px;padding:22px 24px;max-width:520px;width:calc(100% - 32px);box-shadow:0 20px 60px rgba(0,0,0,.25)}.mds-dlg::backdrop{background:rgba(0,0,0,.4)}.mds-dlg h2{margin-top:0}.mds-warn{padding:10px 14px;background:#fcf9e8;border:1px solid #dba617;border-radius:8px;margin:10px 0}.mds-warn p{margin:4px 0}.mds-theme{display:block;margin:10px 0}.mds-msg{min-height:20px;font-weight:600}.mds-msg.ok{color:#116329}.mds-msg.err{color:#b32d2e}.mds-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
		</style>
		<script>
		(function(){var C=<?php echo wp_json_encode( $cfg ); ?>,T=C.i18n,list=document.getElementById('mds-list'),note=document.getElementById('mds-note'),dlg=document.getElementById('mds-dlg'),items={},cur=null;
		function post(d){var fd=new FormData();fd.append('action','malidev_store');fd.append('_ajax_nonce',C.nonce);Object.keys(d).forEach(function(k){fd.append(k,d[k]);});return fetch(C.ajax,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(j){if(!j||!j.success){throw new Error((j&&j.data&&j.data.message)||T.err);}return j.data;});}
		function el(t,c,x){var e=document.createElement(t);if(c){e.className=c;}if(x!==undefined){e.textContent=x;}return e;}
		function render(){list.textContent='';var ids=Object.keys(items);if(!ids.length){list.appendChild(el('p','mds-empty',T.empty));return;}
		ids.forEach(function(id){var it=items[id],row=el('div','mds-row'),main=el('div'),meta=el('div','mds-meta');main.appendChild(el('strong','',it.name));
		meta.appendChild(el('span','mds-b',it.type==='theme'?T.theme:T.plugin));if(it.version){var v=el('span','','v'+it.version);v.dir='ltr';meta.appendChild(v);}
		if(it.update){meta.appendChild(el('span','mds-b up',T.update+' '+it.installed+' → '+it.version));}else if(it.installed){meta.appendChild(el('span','mds-b ok',T.inst));}
		if(it.status!=='active'){meta.appendChild(el('span','mds-b err',T.expired));}
		if(it.guide&&it.guide.url){var g=el('a','',T.guide);g.href=it.guide.url;g.target='_blank';g.rel='noopener';meta.appendChild(g);}
		main.appendChild(meta);row.appendChild(main);var b=el('button','button '+(it.installed&&!it.update?'':'button-primary'),it.update?T.update:(it.installed?T.reinst:T.install));b.type='button';b.disabled=it.status!=='active';b.addEventListener('click',function(){open(it);});row.appendChild(b);list.appendChild(row);});}
		function f(n){return dlg.querySelector('[data-f="'+n+'"]');}
		function msg(t,ok){var m=f('msg');m.textContent=t||'';m.className='mds-msg '+(ok?'ok':'err');}
		function fill(it){cur=it;f('title').textContent=it.name;f('domain').textContent=C.domain;f('mismatch').hidden=!!it.on_this_domain;f('reserved').textContent=it.reserved_domain||'—';f('left').textContent=it.changes_left;f('themebox').hidden=it.type!=='theme';busy(false);}
		function busy(on){dlg.querySelectorAll('button').forEach(function(b){var need=b.dataset.act==='go'||b.dataset.act==='manual';b.disabled=on||(need&&!cur.on_this_domain);});}
		function open(it){fill(it);msg('');dlg.showModal();}
		dlg.addEventListener('click',function(e){var b=e.target.closest('[data-act]');if(!b||!cur){return;}var a=b.dataset.act;
		if(a==='fix'){busy(true);msg('…',true);post({do:'domain',license_id:cur.license_id}).then(function(d){items[d.item.license_id]=d.item;fill(d.item);msg('✓',true);render();}).catch(function(x){msg(x.message);busy(false);});}
		else if(a==='go'){busy(true);msg(T.working,true);post({do:'install',license_id:cur.license_id,activate_theme:f('activate_theme').checked?1:''}).then(function(d){msg(d.message,true);return post({do:'list'}).then(function(l){items={};(l.items||[]).forEach(function(i){items[i.license_id]=i;});render();});}).catch(function(x){msg(x.message+' — '+T.manual);}).then(function(){busy(false);});}
		else if(a==='manual'){busy(true);post({do:'link',license_id:cur.license_id}).then(function(d){window.location.href=d.url;}).catch(function(x){msg(x.message);}).then(function(){busy(false);});}});
		document.getElementById('mds-key').addEventListener('submit',function(e){e.preventDefault();var i=document.getElementById('mds-key-in'),btn=e.target.querySelector('button');btn.disabled=true;post({do:'add_key',key:i.value.trim()}).then(function(d){items[d.item.license_id]=d.item;i.value='';render();open(d.item);}).catch(function(x){window.alert(x.message);}).then(function(){btn.disabled=false;});});
		list.appendChild(el('p','mds-empty',T.loading));
		post({do:'list'}).then(function(d){(d.items||[]).forEach(function(i){items[i.license_id]=i;});if(d.note){note.hidden=false;note.querySelector('p').textContent=d.note;}render();}).catch(function(x){list.textContent='';list.appendChild(el('p','mds-empty',x.message));});
		}());
		</script>
		<?php
	}
}
