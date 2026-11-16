/**
 * MaliDev Accounting — product control grid (jQuery).
 * Inline editing with a pending-changes map, live discount/profit badges, toggles, Jalali range picker,
 * category/tag picker, price-replacement message editor, variation rows, selection, bulk panel,
 * column visibility and keyboard navigation.
 */
( function ( $ ) {
	'use strict';
	const G = window.MDAGrid || {};
	const J = window.mdaJalali;
	const $grid = $( '#mdg-grid' );
	if ( ! $grid.length ) return;

	/* ------------------------------------------------------------ helpers */
	const latin = ( s ) => String( s ?? '' ).replace( /[۰-۹]/g, ( d ) => '۰۱۲۳۴۵۶۷۸۹'.indexOf( d ) ).replace( /[٠-٩]/g, ( d ) => '٠١٢٣٤٥٦٧٨٩'.indexOf( d ) );
	const num = ( v ) => { const s = latin( v ).replace( /[^\d.]/g, '' ); return s === '' ? null : parseFloat( s ); };
	const signed = ( v ) => { const n = parseFloat( latin( v ).replace( /[−–]/g, '-' ).replace( /[^\d.\-]/g, '' ) ); return isNaN( n ) ? 0 : n; };
	const fmt = ( n ) => ( n === null || n === '' || isNaN( n ) ? '' : Math.round( n ).toLocaleString( 'en-US' ) );
	const fa = ( n ) => Number( n ).toLocaleString( 'fa-IR' );
	const esc = ( s ) => $( '<div/>' ).text( String( s ?? '' ) ).html();
	const jd = ( g ) => ( g ? J.gregToJalaliText( g, true ) : '' );
	const post = ( action, data ) => $.post( G.ajax, Object.assign( { action, nonce: G.nonce }, data ) );

	function toast( text, ok = true ) {
		const $t = $( '<div class="mda-toast"/>' ).toggleClass( 'is-err', ! ok ).text( text ).appendTo( 'body' );
		setTimeout( () => $t.addClass( 'is-in' ), 10 );
		setTimeout( () => $t.removeClass( 'is-in' ), 4200 );
		setTimeout( () => $t.remove(), 4700 );
	}

	/* ------------------------------------------------------------ pending changes */
	const pending = {};
	function setPending( id, field, value, isDirty ) {
		pending[ id ] = pending[ id ] || {};
		if ( isDirty ) pending[ id ][ field ] = value;
		else delete pending[ id ][ field ];
		if ( ! Object.keys( pending[ id ] ).length ) delete pending[ id ];
		const n = Object.values( pending ).reduce( ( a, o ) => a + Object.keys( o ).length, 0 );
		$( '#mdg-save' ).prop( 'disabled', ! n ).find( '.mdg-dirty' ).text( n ? '(' + fa( n ) + ')' : '' );
		$grid.find( 'tr[data-id=' + id + ']' ).toggleClass( 'is-dirty', !! pending[ id ] );
	}
	const rowId = ( el ) => $( el ).closest( 'tr' ).data( 'id' );

	/* ------------------------------------------------------------ inputs */
	function badges( $tr ) {
		const v = ( f ) => num( $tr.find( '.mdg-in[data-f=' + f + ']' ).val() );
		const reg = v( 'regular' ), sale = v( 'sale' ), buy = v( 'purchase' );
		const $sale = $tr.find( '.mdg-in[data-f=sale]' ).closest( 'td' );
		$sale.find( '[data-badge=discount]' ).remove();
		if ( sale && reg ) {
			const pct = Math.round( ( 1 - sale / reg ) * 100 );
			$sale.append( '<span class="mdg-tag mdg-tag--' + ( pct >= 20 ? 'hot' : 'off' ) + '" data-badge="discount">تخفیف ' + fa( pct ) + '٪</span>' );
		}
		const $buy = $tr.find( '.mdg-in[data-f=purchase]' ).closest( 'td' );
		$buy.find( '[data-badge=profit]' ).remove();
		const sell = sale || reg;
		if ( buy && sell ) {
			const pct = Math.round( ( sell - buy ) / sell * 100 );
			$buy.append( '<span class="mdg-tag mdg-tag--' + ( sell < buy ? 'neg' : pct < 5 ? 'low' : 'ok' ) + '" data-badge="profit">سود ' + fa( pct ) + '٪</span>' );
		}
	}
	$grid.on( 'input', '.mdg-in--num', function () {
		const $i = $( this );
		if ( ! $i.hasClass( 'mdg-in--qty' ) ) {
			const n = num( $i.val() );
			$i.val( n === null ? '' : fmt( n ) );
		}
		const now = $i.hasClass( 'mdg-in--qty' ) ? latin( $i.val() ).trim() : ( num( $i.val() ) === null ? '' : String( Math.round( num( $i.val() ) ) ) );
		const o = String( $i.data( 'o' ) ?? '' );
		const orig = o === '' || $i.hasClass( 'mdg-in--qty' ) ? o : String( Math.round( parseFloat( o ) ) );
		$i.toggleClass( 'is-dirty', now !== orig );
		setPending( rowId( this ), $i.data( 'f' ), now, now !== orig );
		badges( $i.closest( 'tr' ) );
	} );
	$grid.on( 'input', '.mdg-in--text, .mdg-in--sku', function () {
		const $i = $( this ), d = $i.val() !== String( $i.data( 'o' ) ?? '' );
		$i.toggleClass( 'is-dirty', d );
		setPending( rowId( this ), $i.data( 'f' ), $i.val(), d );
	} );
	$grid.on( 'change', '.mdg-in--check', function () {
		const $i = $( this ), now = this.checked ? '1' : '', d = now !== String( $i.data( 'o' ) ?? '' );
		$i.closest( 'td' ).toggleClass( 'is-dirty', d );
		setPending( rowId( this ), 'manage_stock', now, d );
	} );
	// Spreadsheet keys.
	$grid.on( 'keydown', '.mdg-in', function ( e ) {
		const $td = $( this ).closest( 'td' ), col = $td.index();
		let $t = null;
		if ( e.key === 'Enter' || e.key === 'ArrowDown' ) $t = $td.closest( 'tr' ).nextAll( 'tr:visible' ).children( 'td' ).eq( col ).find( '.mdg-in' );
		if ( e.key === 'ArrowUp' ) $t = $td.closest( 'tr' ).prevAll( 'tr:visible' ).children( 'td' ).eq( col ).find( '.mdg-in' );
		if ( $t && $t.length ) { e.preventDefault(); $t.first().trigger( 'focus' ).trigger( 'select' ); }
	} );

	/* ------------------------------------------------------------ toggles */
	function toggleBtn( $b, value, label, cls ) {
		const orig = $b.attr( 'data-ov' ) ?? $b.attr( 'data-v' );
		$b.attr( 'data-ov', orig ).attr( 'data-v', value ).text( label ).removeClass( 'mdg-badge--ok mdg-badge--warn mdg-ss--instock mdg-ss--outofstock mdg-ss--onbackorder' ).addClass( cls );
		$b.toggleClass( 'is-dirty', value !== orig );
		setPending( rowId( $b ), $b.data( 'f' ), value, value !== orig );
	}
	$grid.on( 'click', '.mdg-toggle-status', function () {
		const $b = $( this ), child = $b.closest( 'tr' ).hasClass( 'is-child' );
		const on = $b.attr( 'data-v' ) === 'publish';
		toggleBtn( $b, on ? ( child ? 'private' : 'draft' ) : 'publish', on ? 'غیرفعال' : 'فعال', on ? 'mdg-badge--warn' : 'mdg-badge--ok' );
	} );
	const ssNext = { instock: [ 'outofstock', 'ناموجود' ], outofstock: [ 'onbackorder', 'پیش‌خرید' ], onbackorder: [ 'instock', 'موجود' ] };
	$grid.on( 'click', '.mdg-ss', function () {
		const $b = $( this ), n = ssNext[ $b.attr( 'data-v' ) ];
		toggleBtn( $b, n[ 0 ], n[ 1 ], 'mdg-ss--' + n[ 0 ] );
	} );
	$grid.on( 'click', '.mdg-star', function () {
		const $b = $( this ), orig = $b.attr( 'data-ov' ) ?? $b.attr( 'data-v' ), now = $b.attr( 'data-v' ) === '1' ? '0' : '1';
		$b.attr( 'data-ov', orig ).attr( 'data-v', now ).toggleClass( 'is-on', now === '1' ).toggleClass( 'is-dirty', now !== orig );
		setPending( rowId( this ), 'featured', now, now !== orig );
	} );
	$grid.on( 'click', '.mdg-trash', ( e ) => { if ( ! window.confirm( 'محصول به زباله‌دان منتقل شود؟ (قابل بازگردانی است)' ) ) e.preventDefault(); } );

	/* ------------------------------------------------------------ popovers */
	function place( $pop, anchor ) {
		const r = anchor.getBoundingClientRect(), w = $pop.outerWidth(), h = $pop.outerHeight();
		let left = Math.max( 8, Math.min( window.innerWidth - w - 8, r.right - w + window.scrollX ) );
		let top = r.bottom + 6 + window.scrollY;
		if ( r.bottom + h + 12 > window.innerHeight ) top = Math.max( window.scrollY + 8, r.top - h - 6 + window.scrollY );
		$pop.css( { left, top } );
	}
	function closePops() { $( '.mdg-pop' ).prop( 'hidden', true ).removeClass( 'is-open' ); }
	$( document ).on( 'mousedown', ( e ) => { if ( ! $( e.target ).closest( '.mdg-pop, .mdg-sched, .mdg-terms, .mdg-msg-btn, .mdg-bulk-sched, [data-term-mode]' ).length ) closePops(); } );

	/* ---- Jalali range calendar ---- */
	const MONTHS = [ 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' ];
	const cal = { $el: $( '#mdg-cal' ), from: '', to: '', y: 0, m: 0, done: null };
	const g2j = ( g ) => { const p = g.split( '-' ).map( Number ); return J.toJalali( p[ 0 ], p[ 1 ], p[ 2 ] ); };
	const j2g = ( y, m, d ) => { const g = J.toGregorian( y, m, d ); return g[ 0 ] + '-' + String( g[ 1 ] ).padStart( 2, '0' ) + '-' + String( g[ 2 ] ).padStart( 2, '0' ); };
	const daysIn = ( y, m ) => ( m <= 6 ? 31 : m <= 11 ? 30 : ( ( () => { const g = J.toGregorian( y, 12, 30 ), b = J.toJalali( g[ 0 ], g[ 1 ], g[ 2 ] ); return b[ 0 ] === y && b[ 1 ] === 12; } )() ? 30 : 29 ) );
	function drawCal() {
		const first = j2g( cal.y, cal.m, 1 ), wd = ( new Date( first + 'T12:00:00Z' ).getUTCDay() + 1 ) % 7; // Saturday = 0.
		const today = ( () => { const t = new Date(); return t.toISOString().slice( 0, 10 ); } )();
		let h = '<div class="mdg-cal__head"><button type="button" data-cal="prev">‹</button><b>' + MONTHS[ cal.m - 1 ] + ' ' + fa( cal.y ).replace( /٬/g, '' ) + '</b><button type="button" data-cal="next">›</button></div><div class="mdg-cal__grid">';
		[ 'ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج' ].forEach( ( d ) => ( h += '<span class="mdg-cal__wd">' + d + '</span>' ) );
		for ( let i = 0; i < wd; i++ ) h += '<span></span>';
		for ( let d = 1; d <= daysIn( cal.y, cal.m ); d++ ) {
			const g = j2g( cal.y, cal.m, d );
			const cls = [ g === cal.from || g === cal.to ? 'is-edge' : '', cal.from && cal.to && g > cal.from && g < cal.to ? 'is-in' : '', g === today ? 'is-today' : '', ( ( wd + d - 1 ) % 7 ) === 6 ? 'is-fri' : '' ].join( ' ' );
			h += '<button type="button" class="mdg-cal__d ' + cls + '" data-g="' + g + '">' + fa( d ) + '</button>';
		}
		const len = cal.from && cal.to ? Math.round( ( new Date( cal.to ) - new Date( cal.from ) ) / 864e5 ) + 1 : 0;
		h += '</div><div class="mdg-cal__range"><span><i class="s"></i>شروع: ' + ( cal.from ? jd( cal.from ) : '—' ) + '</span><span><i class="e"></i>پایان: ' + ( cal.to ? jd( cal.to ) : '—' ) + '</span></div>' + ( len ? '<div class="mdg-cal__len">مدت: ' + fa( len ) + ' روز</div>' : '' );
		h += '<div class="mdg-cal__foot"><button type="button" class="mdg-btn" data-cal="cancel">انصراف</button><button type="button" class="mdg-btn" data-cal="clear">پاک کردن</button><button type="button" class="mdg-btn mdg-btn--primary" data-cal="ok">تایید</button></div>';
		cal.$el.html( h );
	}
	function openCal( anchor, from, to, done ) {
		closePops();
		Object.assign( cal, { from: from || '', to: to || '', done } );
		const j = g2j( from || new Date().toISOString().slice( 0, 10 ) );
		cal.y = j[ 0 ]; cal.m = j[ 1 ];
		drawCal();
		cal.$el.prop( 'hidden', false ).addClass( 'is-open' );
		place( cal.$el, anchor );
	}
	cal.$el.on( 'click', '[data-cal]', function () {
		const a = $( this ).data( 'cal' );
		if ( a === 'prev' ) { cal.m--; if ( cal.m < 1 ) { cal.m = 12; cal.y--; } drawCal(); }
		if ( a === 'next' ) { cal.m++; if ( cal.m > 12 ) { cal.m = 1; cal.y++; } drawCal(); }
		if ( a === 'cancel' ) closePops();
		if ( a === 'clear' ) { cal.from = cal.to = ''; drawCal(); }
		if ( a === 'ok' ) { cal.done( cal.from, cal.to ); closePops(); }
	} ).on( 'click', '.mdg-cal__d', function () {
		const g = $( this ).data( 'g' );
		if ( ! cal.from || cal.to || g < cal.from ) { cal.from = g; cal.to = ''; } else cal.to = g;
		drawCal();
	} );
	const schedText = ( f, t ) => ( f || t ? ( f ? 'از ' + jd( f ) : '' ) + ( t ? '<br>تا ' + jd( t ) : '' ) : '<span class="mdg-dim">—</span>' );
	$grid.on( 'click', '.mdg-sched', function () {
		const $b = $( this );
		openCal( this, $b.attr( 'data-from' ), $b.attr( 'data-to' ), ( f, t ) => {
			$b.attr( 'data-from', f ).attr( 'data-to', t ).html( schedText( f, t ) );
			const id = rowId( $b ), df = f !== $b.attr( 'data-of' ), dt = t !== $b.attr( 'data-ot' );
			setPending( id, 'sale_from', f, df || dt );
			setPending( id, 'sale_to', t, df || dt );
			$b.toggleClass( 'is-dirty', df || dt );
		} );
	} );

	/* ---- terms picker ---- */
	const $tp = $( '#mdg-terms-pop' );
	function openTerms( anchor, tax, selected, title, done ) {
		closePops();
		const sel = new Set( selected.map( Number ) );
		const label = {};
		$tp.html( '<div class="mdg-tp__head"><b>' + esc( title ) + '</b><span class="mdg-tp__count"></span></div><div class="mdg-tp__search"><span class="dashicons dashicons-search"></span><input placeholder="جستجو' + ( G.canTerms ? ' یا افزودن جدید (Enter)' : '' ) + '…"></div><div class="mdg-tp__list"><p class="mdg-dim">در حال بارگذاری…</p></div><div class="mdg-tp__sel"></div><div class="mdg-tp__foot"><button type="button" class="mdg-btn" data-tp="cancel">انصراف</button><button type="button" class="mdg-btn mdg-btn--primary" data-tp="ok">تایید</button></div>' ).prop( 'hidden', false ).addClass( 'is-open' );
		place( $tp, anchor );
		const count = () => { $tp.find( '.mdg-tp__count' ).text( fa( sel.size ) + ' انتخاب' ); $tp.find( '.mdg-tp__sel' ).html( '<i></i> انتخاب‌شده: <b>' + fa( sel.size ) + '</b>' ); };
		const load = ( q ) => post( 'mda_grid_terms', { tax, q } ).done( ( r ) => {
			const $l = $tp.find( '.mdg-tp__list' ).empty();
			( r.data || [] ).forEach( ( t ) => {
				label[ t.id ] = t.name;
				$l.append( '<label class="mdg-tp__item' + ( sel.has( t.id ) ? ' is-on' : '' ) + '"><input type="checkbox" value="' + t.id + '"' + ( sel.has( t.id ) ? ' checked' : '' ) + '> ' + ( t.parent ? '— ' : '' ) + esc( t.name ) + '</label>' );
			} );
			if ( ! ( r.data || [] ).length ) $l.append( '<p class="mdg-dim">موردی پیدا نشد.</p>' );
		} );
		let timer;
		$tp.find( 'input:not([type=checkbox])' ).first().on( 'input', function () { clearTimeout( timer ); timer = setTimeout( () => load( this.value ), 250 ); } ).on( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' || ! this.value.trim() || ! G.canTerms ) return;
			e.preventDefault();
			post( 'mda_grid_add_term', { tax, name: this.value.trim() } ).done( ( r ) => {
				if ( ! r.success ) { toast( r.data.message, false ); return; }
				sel.add( r.data.id ); label[ r.data.id ] = r.data.name; this.value = ''; load( '' ); count();
			} );
		} ).trigger( 'focus' );
		$tp.off( 'change' ).on( 'change', '.mdg-tp__item input', function () {
			this.checked ? sel.add( Number( this.value ) ) : sel.delete( Number( this.value ) );
			$( this ).closest( 'label' ).toggleClass( 'is-on', this.checked );
			count();
		} );
		$tp.off( 'click' ).on( 'click', '[data-tp]', function () {
			if ( $( this ).data( 'tp' ) === 'ok' ) done( Array.from( sel ), Array.from( sel ).map( ( id ) => label[ id ] || '#' + id ) );
			closePops();
		} );
		load( '' ); count();
	}
	$grid.on( 'click', '.mdg-terms', function () {
		const $b = $( this ), ids = String( $b.attr( 'data-ids' ) || '' ).split( ',' ).filter( Boolean );
		const orig = $b.attr( 'data-oids' ) ?? $b.attr( 'data-ids' );
		$b.attr( 'data-oids', orig );
		openTerms( this, $b.data( 'tax' ), ids, $b.data( 'f' ) === 'cats' ? 'انتخاب دسته‌بندی' : 'انتخاب برچسب', ( sel, names ) => {
			const now = sel.sort( ( a, b ) => a - b ).join( ',' ), o = String( orig || '' ).split( ',' ).filter( Boolean ).map( Number ).sort( ( a, b ) => a - b ).join( ',' );
			$b.attr( 'data-ids', now ).html( names.length ? esc( names.join( '، ' ) ) : '<span class="mdg-dim">—</span>' ).toggleClass( 'is-dirty', now !== o );
			setPending( rowId( $b ), $b.data( 'f' ), now, now !== o );
		} );
	} );

	/* ---- price-replacement message (per row) ---- */
	const $mp = $( '#mdg-msg-pop' );
	$grid.on( 'click', '.mdg-msg-btn', function () {
		const $b = $( this ), msg = JSON.parse( $b.attr( 'data-msg' ) || '{}' );
		closePops();
		$mp.html( '<div class="mdg-msg-head"><span class="dashicons dashicons-warning"></span><div><b>ویرایش وضعیت غیرفعال‌سازی</b><small>پیام به جای قیمت در فروشگاه</small></div></div><div class="mdg-msg-fields">' + $( '#mdg-bulk .mdg-msg-fields' ).html() + '</div><div class="mdg-tp__foot"><button type="button" class="mdg-btn" data-m="cancel">انصراف</button>' + ( msg.title ? '<button type="button" class="mdg-btn mdg-btn--ok" data-m="off">فعال کردن فروش</button>' : '' ) + '<button type="button" class="mdg-btn mdg-btn--warn" data-m="save">ذخیره پیام</button></div>' ).prop( 'hidden', false ).addClass( 'is-open' );
		$mp.find( '[name=msg_title]' ).val( msg.title || 'در حال تأمین' );
		$mp.find( '[name=msg_note]' ).val( msg.note || '' ).trigger( 'input' );
		$mp.find( '[name=msg_phone]' ).val( msg.phone || '' );
		place( $mp, this );
		$mp.off( 'click' ).on( 'click', '[data-m]', function () {
			const a = $( this ).data( 'm' );
			if ( a !== 'cancel' ) {
				const value = a === 'off' ? { enabled: '' } : { enabled: '1', title: $mp.find( '[name=msg_title]' ).val(), note: $mp.find( '[name=msg_note]' ).val(), phone: $mp.find( '[name=msg_phone]' ).val() };
				setPending( rowId( $b ), 'message', value, true );
				$b.addClass( 'is-dirty' ).toggleClass( 'mdg-badge--pink', a !== 'off' ).toggleClass( 'mdg-badge--ghost', a === 'off' ).text( a === 'off' ? '+ پیام' : value.title );
			}
			closePops();
		} );
	} );
	$( document ).on( 'input', '[name=msg_note]', function () { $( this ).closest( 'label' ).find( '.mdg-count' ).text( fa( this.value.length ) + '/۴۰' ); } );

	/* ------------------------------------------------------------ variations */
	$grid.on( 'click', '.mdg-expand', function () {
		const $b = $( this ), id = $b.data( 'id' ), open = $b.attr( 'aria-expanded' ) === 'true';
		const $kids = $grid.find( 'tr[data-parent=' + id + ']' );
		if ( open ) { $kids.hide(); $b.attr( 'aria-expanded', 'false' ).find( 'b' ).text( '+' ); return; }
		$b.attr( 'aria-expanded', 'true' ).find( 'b' ).text( '−' );
		if ( $kids.length ) { $kids.show(); return; }
		$b.addClass( 'is-busy' );
		post( 'mda_grid_variations', { id } ).done( ( r ) => {
			if ( r.success ) $( r.data.html ).insertAfter( $b.closest( 'tr' ) ).addClass( 'mdg-enter' );
			applyColumns();
		} ).always( () => $b.removeClass( 'is-busy' ) );
	} );

	/* ------------------------------------------------------------ save */
	function save() {
		if ( ! Object.keys( pending ).length ) return $.Deferred().resolve();
		const $btn = $( '#mdg-save' ).prop( 'disabled', true ).addClass( 'is-busy' );
		return post( 'mda_grid_save', { changes: pending } ).done( ( r ) => {
			if ( ! r || ! r.success ) { toast( 'خطا در ذخیره', false ); return; }
			$.each( r.data.rows, ( id, html ) => {
				const $old = $grid.find( 'tr[data-id=' + id + ']' ), $new = $( html );
				const wasChecked = $old.find( '.mdg-check' ).prop( 'checked' ), hidden = $old.is( ':hidden' );
				$old.replaceWith( $new );
				$new.find( '.mdg-check' ).prop( 'checked', wasChecked );
				if ( hidden ) $new.hide();
				$new.addClass( 'mdg-flash' );
				setTimeout( () => $new.removeClass( 'mdg-flash' ), 1600 );
				delete pending[ id ];
			} );
			const errs = Object.entries( r.data.errors || {} );
			errs.forEach( ( [ id, msg ] ) => $grid.find( 'tr[data-id=' + id + ']' ).addClass( 'has-error' ).attr( 'title', msg ) );
			applyColumns();
			setPending( 0, '', '', false );
			toast( errs.length ? errs[ 0 ][ 1 ] : fa( Object.keys( r.data.rows ).length ) + ' محصول ذخیره شد', ! errs.length );
		} ).fail( () => toast( 'خطای ارتباط با سرور', false ) ).always( () => $btn.removeClass( 'is-busy' ) );
	}
	$( '#mdg-save' ).on( 'click', save );
	$( document ).on( 'keydown', ( e ) => { if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 's' ) { e.preventDefault(); save(); } if ( e.key === 'Escape' ) { closePops(); closeModals(); } } );
	$( window ).on( 'beforeunload', () => ( Object.keys( pending ).length ? 'تغییرات ذخیره‌نشده دارید.' : undefined ) );
	$( '#mdg-refresh' ).on( 'click', () => window.location.reload() );

	/* ------------------------------------------------------------ selection */
	const selected = () => $grid.find( '.mdg-check:checked' ).map( function () { return Number( this.value ); } ).get();
	function selCount() {
		const n = selected().length;
		$( '.mdg-selected' ).prop( 'hidden', ! n ).find( 'b' ).text( fa( n ) );
		$grid.find( 'tr' ).each( function () { $( this ).toggleClass( 'is-selected', $( this ).find( '.mdg-check' ).prop( 'checked' ) ); } );
		$( '.mdg-bulk-target' ).text( $( '#mdg-all-matching' ).is( ':checked' ) ? 'همه محصولات مطابق فیلتر' : fa( n ) + ' محصول انتخاب شده' );
	}
	$( '#mdg-check-all' ).on( 'change', function () { $grid.find( '.mdg-check:visible' ).prop( 'checked', this.checked ); selCount(); } );
	$grid.on( 'change', '.mdg-check', selCount );
	$( '.mdg-clear-sel' ).on( 'click', () => { $grid.find( '.mdg-check, #mdg-check-all' ).prop( 'checked', false ); selCount(); } );
	$( '#mdg-all-matching' ).on( 'change', selCount );

	/* ------------------------------------------------------------ modals & menus */
	function closeModals() { $( '.mdg-modal' ).removeClass( 'is-open' ).prop( 'hidden', true ); }
	$( document ).on( 'click', '[data-mdg-open]', function ( e ) {
		e.preventDefault();
		const $m = $( $( this ).data( 'mdg-open' ) );
		$( '.mdg-menu' ).removeClass( 'is-open' );
		$m.prop( 'hidden', false );
		setTimeout( () => $m.addClass( 'is-open' ), 10 );
		if ( $( this ).data( 'bulk-tab' ) ) $m.find( '.mdg-tabs [data-tab=' + $( this ).data( 'bulk-tab' ) + ']' ).trigger( 'click' );
		selCount();
	} ).on( 'click', '[data-mdg-close]', closeModals ).on( 'click', '.mdg-modal', function ( e ) { if ( e.target === this ) closeModals(); } );
	$( document ).on( 'click', '[data-mdg-toggle]', function ( e ) { e.stopPropagation(); $( this ).next( '.mdg-menu' ).toggleClass( 'is-open' ); } );
	$( document ).on( 'click', () => $( '.mdg-menu' ).removeClass( 'is-open' ) );
	$( '#mdg-bulk' ).on( 'click', '.mdg-tabs button', function () {
		$( this ).addClass( 'is-on' ).siblings().removeClass( 'is-on' );
		$( '#mdg-bulk [data-pane]' ).prop( 'hidden', true ).filter( '[data-pane=' + $( this ).data( 'tab' ) + ']' ).prop( 'hidden', false );
	} ).on( 'click', '.mdg-seg button', function () { $( this ).addClass( 'is-on' ).siblings().removeClass( 'is-on' ); } )
		.on( 'change', '[name=price_op]', function () { $( '#mdg-bulk [name=price_source]' ).prop( 'hidden', this.value !== 'copy' ); } );

	/* ------------------------------------------------------------ bulk */
	const bulk = { from: '', to: '', cats: {}, tags: {} };
	$( '.mdg-bulk-sched' ).on( 'click', function () {
		openCal( this, bulk.from, bulk.to, ( f, t ) => { bulk.from = f; bulk.to = t; $( '.mdg-bulk-sched-text' ).html( f || t ? schedText( f, t ).replace( '<br>', ' ' ) : 'بدون تغییر' ); } );
	} );
	$( '#mdg-bulk' ).on( 'click', '[data-term-mode]', function () {
		const $box = $( this ).closest( '.mdg-term-box' ), key = $box.data( 'key' ), mode = $( this ).data( 'term-mode' );
		const summary = () => {
			const s = bulk[ key ];
			$box.find( '.mdg-term-summary' ).html( s.mode ? ( s.mode === 'clear' ? '<b class="bad">حذف همه</b>' : ( s.mode === 'add' ? 'افزودن: ' : 'حذف: ' ) + esc( ( s.names || [] ).join( '، ' ) ) ) : 'بدون تغییر' );
		};
		if ( mode === 'clear' ) { bulk[ key ] = bulk[ key ].mode === 'clear' ? {} : { mode: 'clear' }; summary(); return; }
		openTerms( this, $box.data( 'tax' ), ( bulk[ key ].mode === mode ? bulk[ key ].ids : [] ) || [], mode === 'add' ? 'افزودن ' + $box.find( 'h3' ).text() : 'حذف ' + $box.find( 'h3' ).text(), ( ids, names ) => {
			bulk[ key ] = ids.length ? { mode, ids, names } : {};
			summary();
		} );
	} );
	function spec() {
		const $m = $( '#mdg-bulk' ), seg = ( n ) => $m.find( '.mdg-seg[data-name=' + n + '] .is-on' ).data( 'v' );
		const s = {};
		if ( seg( 'status' ) ) s.status = seg( 'status' );
		if ( seg( 'stock_status' ) ) s.stock_status = seg( 'stock_status' );
		if ( seg( 'featured' ) !== undefined && seg( 'featured' ) !== '' ) s.featured = String( seg( 'featured' ) );
		[ 'cats', 'tags' ].forEach( ( k ) => {
			const b = bulk[ k ];
			if ( b.mode === 'clear' ) s[ k + '_clear' ] = 1;
			else if ( b.mode ) s[ k + '_' + b.mode ] = b.ids;
		} );
		const op = $m.find( '[name=price_op]' ).val();
		if ( op ) s.price = { field: $m.find( '[name=price_field]' ).val(), op, value: String( signed( $m.find( '[name=price_value]' ).val() ) ), source: $m.find( '[name=price_source]' ).val(), round: $m.find( '[name=price_round]' ).val() };
		if ( $m.find( '[name=schedule_clear]' ).is( ':checked' ) ) s.schedule_clear = 1;
		else if ( bulk.from || bulk.to ) { s.sale_from = bulk.from; s.sale_to = bulk.to; }
		const mm = seg( 'message_mode' );
		if ( mm ) s.message = { mode: mm, title: $m.find( '[name=msg_title]' ).val(), note: $m.find( '[name=msg_note]' ).val(), phone: $m.find( '[name=msg_phone]' ).val() };
		return s;
	}
	function computePrice( $tr, p ) {
		const cur = num( $tr.find( '.mdg-in[data-f=' + p.field + ']' ).val() ), v = Number( p.value ), base = ( f ) => num( $tr.find( '.mdg-in[data-f=' + f + ']' ).val() );
		let r;
		switch ( p.op ) {
			case 'set': r = v; break;
			case 'inc_pct': if ( ! cur ) return null; r = cur * ( 1 + v / 100 ); break;
			case 'dec_pct': if ( ! cur ) return null; r = cur * ( 1 - v / 100 ); break;
			case 'inc_amount': if ( ! cur ) return null; r = cur + v; break;
			case 'dec_amount': if ( ! cur ) return null; r = cur - v; break;
			case 'margin': if ( ! base( 'purchase' ) ) return null; r = base( 'purchase' ) * ( 1 + v / 100 ); break;
			case 'copy': if ( ! base( p.source ) ) return null; r = base( p.source ) * ( 1 + v / 100 ); break;
			case 'clear': return '';
			default: return null;
		}
		r = Math.max( 0, r );
		if ( Number( p.round ) > 1 ) r = Math.round( r / p.round ) * p.round;
		return Math.round( r );
	}
	$( '#mdg-bulk-preview' ).on( 'click', () => {
		const s = spec();
		if ( ! s.price ) { toast( 'در تب «قیمت» عملیات را انتخاب کنید.', false ); return; }
		let n = 0;
		selected().forEach( ( id ) => {
			const $tr = $grid.find( 'tr[data-id=' + id + ']' ), $i = $tr.find( '.mdg-in[data-f=' + s.price.field + ']' );
			if ( ! $i.length ) return;
			const v = computePrice( $tr, s.price );
			if ( v === null ) return;
			$i.val( v === '' ? '' : fmt( v ) ).addClass( 'is-preview' ).trigger( 'input' );
			n++;
		} );
		closeModals();
		toast( fa( n ) + ' قیمت در جدول تغییر کرد؛ برای ثبت «ذخیره تغییرات» را بزنید.' );
	} );
	$( '#mdg-bulk-apply' ).on( 'click', () => {
		const s = spec(), all = $( '#mdg-all-matching' ).is( ':checked' ), ids = selected();
		if ( ! Object.keys( s ).length ) { toast( 'هیچ تغییری انتخاب نشده است.', false ); return; }
		if ( ! all && ! ids.length ) { toast( 'ابتدا محصولات را انتخاب کنید یا «همه محصولات مطابق فیلتر» را بزنید.', false ); return; }
		if ( all && ! window.confirm( 'تغییرات روی همه محصولات مطابق فیلتر اعمال شود؟' ) ) return;
		if ( Object.keys( pending ).length && ! window.confirm( 'تغییرات ذخیره‌نشده جدول از بین می‌روند. ادامه؟' ) ) return;
		const filters = {};
		new URLSearchParams( window.location.search ).forEach( ( v, k ) => { filters[ k ] = v; } );
		const $p = $( '#mdg-bulk .mdg-progress' ).prop( 'hidden', false ), $btn = $( '#mdg-bulk-apply' ).prop( 'disabled', true );
		let changed = 0;
		const run = ( batch ) => post( 'mda_grid_bulk', { spec: s, batch, ids: all ? [] : ids, filters } ).done( ( r ) => {
			if ( ! r.success ) { toast( r.data.message || 'خطا', false ); $btn.prop( 'disabled', false ); return; }
			changed += r.data.changed;
			$p.html( '<i style="width:' + Math.round( r.data.batch / r.data.pages * 100 ) + '%"></i><span>' + fa( Math.min( r.data.batch * 25, r.data.total ) ) + ' از ' + fa( r.data.total ) + '</span>' );
			if ( r.data.errors.length ) toast( r.data.errors[ 0 ], false );
			if ( r.data.batch < r.data.pages ) run( r.data.batch + 1 );
			else { toast( fa( changed ) + ' محصول به‌روز شد.' ); Object.keys( pending ).forEach( ( k ) => delete pending[ k ] ); setTimeout( () => window.location.reload(), 900 ); }
		} ).fail( () => { toast( 'خطای ارتباط با سرور', false ); $btn.prop( 'disabled', false ); } );
		run( 1 );
	} );

	/* ------------------------------------------------------------ columns */
	const COLKEY = 'mdaGridHidden';
	let hidden = [];
	try { hidden = JSON.parse( window.localStorage.getItem( COLKEY ) || '[]' ); } catch ( e ) { hidden = []; }
	function applyColumns() {
		$grid.find( 'thead th' ).each( function ( i ) {
			const off = hidden.includes( i );
			$grid.find( 'tr' ).each( function () { $( this ).children().eq( i ).toggleClass( 'mdg-col-off', off ); } );
		} );
	}
	const $cols = $( '.mdg-col-toggles' );
	$grid.find( 'thead th' ).each( function ( i ) {
		if ( i < 3 ) return;
		const t = $( this ).text().trim() || $( this ).attr( 'title' );
		$cols.append( '<label><input type="checkbox" value="' + i + '"' + ( hidden.includes( i ) ? '' : ' checked' ) + '> ' + esc( t ) + '</label>' );
	} );
	$cols.on( 'change', 'input', function () {
		const i = Number( this.value );
		hidden = this.checked ? hidden.filter( ( x ) => x !== i ) : hidden.concat( i );
		try { window.localStorage.setItem( COLKEY, JSON.stringify( hidden ) ); } catch ( e ) {}
		applyColumns();
	} );
	applyColumns();
	$grid.find( 'tbody tr' ).each( function ( i ) { $( this ).css( 'animation-delay', Math.min( i * 18, 400 ) + 'ms' ).addClass( 'mdg-enter' ); } );
}( jQuery ) );
