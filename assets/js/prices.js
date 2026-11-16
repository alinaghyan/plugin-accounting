/**
 * MaliDev Accounting — price manager grid (jQuery).
 * Inline editing with dirty tracking, live margin, row selection, client-side preview of bulk
 * operations, batched server-side bulk for "all matching products", and price history modal.
 */
( function ( $ ) {
	'use strict';
	const C = window.MDAPrices || {};
	const $grid = $( '#mda-price-grid' );
	if ( ! $grid.length ) return;

	const latin = ( s ) => String( s ).replace( /[۰-۹]/g, ( d ) => '۰۱۲۳۴۵۶۷۸۹'.indexOf( d ) ).replace( /[٠-٩]/g, ( d ) => '٠١٢٣٤٥٦٧٨٩'.indexOf( d ) );
	const num = ( v ) => {
		const s = latin( v ).replace( /[^\d.]/g, '' );
		return s === '' ? null : parseFloat( s );
	};
	const signed = ( v ) => {
		const s = latin( v ).replace( /[−–]/g, '-' ).replace( /[^\d.\-]/g, '' );
		const n = parseFloat( s );
		return isNaN( n ) ? 0 : n;
	};
	const fmt = ( n ) => ( n === null || isNaN( n ) ? '' : Math.round( n ).toLocaleString( 'en-US' ) );
	const fa = ( n ) => Number( n ).toLocaleString( 'fa-IR' );
	const raw = ( $i ) => {
		const n = num( $i.val() );
		return n === null ? '' : String( Math.round( n ) );
	};

	/* ---------- dirty tracking + margin ---------- */
	function rowMargin( $tr ) {
		const $cell = $tr.find( '.mda-margin' );
		const buy = num( $tr.find( '[data-field=purchase]' ).val() ) || num( $tr.find( '.mda-cost' ).data( 'cost' ) );
		const sale = num( $tr.find( '[data-field=sale]' ).val() );
		const reg = num( $tr.find( '[data-field=regular]' ).val() );
		const price = sale || reg;
		$cell.removeClass( 'is-low is-neg' ).text( '' );
		if ( ! buy || ! price ) return;
		const pct = ( ( price - buy ) / price ) * 100;
		$cell.text( fa( pct.toFixed( 1 ) ) + '٪' );
		if ( pct < 0 ) $cell.addClass( 'is-neg' );
		else if ( pct < 5 ) $cell.addClass( 'is-low' );
	}
	function refreshDirty() {
		const n = $grid.find( '.mda-cell.is-dirty' ).length;
		$( '#mda-save' ).prop( 'disabled', ! n ).find( '.mda-dirty-count' ).text( n ? '(' + fa( n ) + ')' : '' );
		$( '#mda-undo' ).prop( 'disabled', ! n );
	}
	function markDirty( $i ) {
		const orig = String( $i.data( 'original' ) ?? '' );
		const now = raw( $i );
		const o = orig === '' ? '' : String( Math.round( parseFloat( orig ) ) );
		$i.toggleClass( 'is-dirty', o !== now );
	}
	$grid.on( 'input', '.mda-cell', function () {
		const $i = $( this );
		const n = num( $i.val() );
		const pos = this.selectionStart, before = $i.val().length;
		$i.val( n === null ? '' : fmt( n ) );
		try { this.setSelectionRange( pos + ( $i.val().length - before ), pos + ( $i.val().length - before ) ); } catch ( e ) {}
		markDirty( $i );
		rowMargin( $i.closest( 'tr' ) );
		refreshDirty();
	} );
	// Keyboard: Enter / arrows move like a spreadsheet.
	$grid.on( 'keydown', '.mda-cell', function ( e ) {
		const $td = $( this ).closest( 'td' );
		const col = $td.index();
		let $target = null;
		if ( e.key === 'Enter' || e.key === 'ArrowDown' ) $target = $td.closest( 'tr' ).nextAll( 'tr' ).find( 'td:eq(' + ( col - 1 ) + ') .mda-cell' ).first();
		if ( e.key === 'ArrowUp' ) $target = $td.closest( 'tr' ).prevAll( 'tr' ).find( 'td:eq(' + ( col - 1 ) + ') .mda-cell' ).first();
		if ( $target && $target.length ) { e.preventDefault(); $target.trigger( 'focus' ).trigger( 'select' ); }
	} );
	$grid.find( 'tbody tr' ).each( function () { rowMargin( $( this ) ); } );

	$( '#mda-undo' ).on( 'click', () => {
		$grid.find( '.mda-cell.is-dirty' ).each( function () {
			const $i = $( this ), o = String( $i.data( 'original' ) ?? '' );
			$i.val( o === '' ? '' : fmt( parseFloat( o ) ) ).removeClass( 'is-dirty is-preview' );
			rowMargin( $i.closest( 'tr' ) );
		} );
		refreshDirty();
	} );

	/* ---------- save ---------- */
	function toast( text, ok ) {
		const $t = $( '<div class="mda-toast"/>' ).toggleClass( 'is-err', ! ok ).text( text ).appendTo( 'body' );
		setTimeout( () => $t.addClass( 'is-in' ), 10 );
		setTimeout( () => $t.removeClass( 'is-in' ), 3800 );
		setTimeout( () => $t.remove(), 4300 );
	}
	function save() {
		const changes = {};
		$grid.find( '.mda-cell.is-dirty' ).each( function () {
			const $i = $( this ), id = $i.closest( 'tr' ).data( 'id' );
			( changes[ id ] = changes[ id ] || {} )[ $i.data( 'field' ) ] = raw( $i );
		} );
		if ( ! Object.keys( changes ).length ) { toast( C.i18n.nothing, true ); return $.Deferred().resolve(); }
		const $btn = $( '#mda-save' ).prop( 'disabled', true ).addClass( 'is-busy' );
		return $.post( C.ajax, { action: 'mda_prices_save', nonce: C.nonce, changes } ).done( ( r ) => {
			if ( ! r || ! r.success ) { toast( C.i18n.error + ( r && r.data && r.data.message ? r.data.message : '' ), false ); return; }
			$.each( r.data.saved, ( id, values ) => {
				const $tr = $grid.find( 'tr[data-id=' + id + ']' );
				$.each( values, ( field, v ) => {
					const $i = $tr.find( '.mda-cell[data-field=' + field + ']' );
					$i.data( 'original', v ).attr( 'data-original', v ).val( v === '' ? '' : fmt( parseFloat( v ) ) ).removeClass( 'is-dirty is-preview' );
				} );
				$tr.removeClass( 'has-error' ).addClass( 'mda-flash' );
				setTimeout( () => $tr.removeClass( 'mda-flash' ), 1700 );
				rowMargin( $tr );
			} );
			const errs = Object.entries( r.data.errors || {} );
			errs.forEach( ( [ id, msg ] ) => $grid.find( 'tr[data-id=' + id + ']' ).addClass( 'has-error' ).attr( 'title', msg ) );
			toast( errs.length ? C.i18n.error + errs[ 0 ][ 1 ] : C.i18n.saved, ! errs.length );
		} ).fail( ( x ) => toast( C.i18n.error + ( x.status || '' ), false ) ).always( () => { $btn.removeClass( 'is-busy' ); refreshDirty(); } );
	}
	$( '#mda-save' ).on( 'click', save );
	$( document ).on( 'keydown', ( e ) => { if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) { e.preventDefault(); save(); } } );
	$( window ).on( 'beforeunload', () => ( $grid.find( '.mda-cell.is-dirty' ).length ? C.i18n.leave : undefined ) );

	/* ---------- selection ---------- */
	function selected() { return $grid.find( '.mda-row-check:checked' ).map( function () { return this.value; } ).get(); }
	function refreshCount() {
		const all = $( '#mda-all-matching' ).is( ':checked' );
		$( '.mda-bulk-count' ).text( all ? 'همه کالاهای مطابق فیلتر' : fa( selected().length ) + ' کالا انتخاب شده' );
		$( '#mda-bulk' ).toggleClass( 'is-active', all || selected().length > 0 );
	}
	$( '#mda-check-all' ).on( 'change', function () { $grid.find( '.mda-row-check' ).prop( 'checked', this.checked ); refreshCount(); } );
	$grid.on( 'change', '.mda-row-check', refreshCount );
	$( '#mda-all-matching' ).on( 'change', refreshCount );
	$( '#mda-bulk-op' ).on( 'change', function () {
		$( '#mda-bulk-source' ).prop( 'hidden', this.value !== 'copy' );
		$( '#mda-bulk-value' ).prop( 'disabled', this.value === 'clear' );
	} );

	/* ---------- bulk ---------- */
	function compute( $tr, field, op, value, source, round ) {
		const cur = num( $tr.find( '.mda-cell[data-field=' + field + ']' ).val() );
		let r;
		switch ( op ) {
			case 'set': r = value; break;
			case 'inc_pct': if ( ! cur ) return null; r = cur * ( 1 + value / 100 ); break;
			case 'dec_pct': if ( ! cur ) return null; r = cur * ( 1 - value / 100 ); break;
			case 'inc_amount': if ( ! cur ) return null; r = cur + value; break;
			case 'dec_amount': if ( ! cur ) return null; r = cur - value; break;
			case 'margin': { const b = num( $tr.find( '.mda-cell[data-field=purchase]' ).val() ); if ( ! b ) return null; r = b * ( 1 + value / 100 ); break; }
			case 'copy': { const b = num( $tr.find( '.mda-cell[data-field=' + source + ']' ).val() ); if ( ! b ) return null; r = b * ( 1 + value / 100 ); break; }
			case 'clear': return '';
		}
		r = Math.max( 0, r );
		if ( round > 1 ) r = Math.round( r / round ) * round;
		return Math.round( r );
	}
	function params() {
		return {
			field: $( '#mda-bulk-field' ).val(), op: $( '#mda-bulk-op' ).val(), source: $( '#mda-bulk-source' ).val(),
			value: signed( $( '#mda-bulk-value' ).val() ), round: parseInt( $( '#mda-bulk-round' ).val(), 10 ) || 0,
		};
	}
	function preview() {
		const p = params();
		let n = 0;
		selected().forEach( ( id ) => {
			const $tr = $grid.find( 'tr[data-id=' + id + ']' );
			const $i = $tr.find( '.mda-cell[data-field=' + p.field + ']' );
			if ( ! $i.length ) return;
			const v = compute( $tr, p.field, p.op, p.value, p.source, p.round );
			if ( v === null ) return;
			$i.val( v === '' ? '' : fmt( v ) ).addClass( 'is-preview' );
			markDirty( $i );
			rowMargin( $tr );
			n++;
		} );
		refreshDirty();
		return n;
	}
	$( '#mda-bulk-preview' ).on( 'click', () => {
		if ( $( '#mda-all-matching' ).is( ':checked' ) ) { toast( 'پیش‌نمایش فقط برای کالاهای همین صفحه ممکن است.', false ); return; }
		toast( fa( preview() ) + ' خانه تغییر کرد؛ برای ثبت «ذخیره تغییرات» را بزنید.', true );
	} );
	$( '#mda-bulk-apply' ).on( 'click', () => {
		const p = params();
		const all = $( '#mda-all-matching' ).is( ':checked' );
		if ( ! all ) {
			if ( ! selected().length ) { toast( 'ابتدا کالاها را انتخاب کنید.', false ); return; }
			preview();
			save();
			return;
		}
		if ( ! window.confirm( C.i18n.confirm.replace( '%s', 'همه' ) ) ) return;
		const filters = {};
		new URLSearchParams( window.location.search ).forEach( ( v, k ) => { filters[ k ] = v; } );
		const $btn = $( '#mda-bulk-apply' ).prop( 'disabled', true );
		let changed = 0;
		const run = ( batch ) => $.post( C.ajax, Object.assign( { action: 'mda_prices_bulk', nonce: C.nonce, batch, filters }, p ) ).done( ( r ) => {
			if ( ! r || ! r.success ) { toast( C.i18n.error + ( r && r.data ? r.data.message : '' ), false ); $btn.prop( 'disabled', false ); return; }
			changed += r.data.changed;
			$( '.mda-bulk-count' ).text( C.i18n.progress.replace( '%1$s', fa( Math.min( r.data.batch * 40, r.data.total ) ) ).replace( '%2$s', fa( r.data.total ) ) );
			if ( r.data.batch < r.data.pages ) run( r.data.batch + 1 );
			else { toast( fa( changed ) + ' کالا به‌روز شد.', true ); setTimeout( () => window.location.reload(), 900 ); }
		} ).fail( () => { toast( C.i18n.error, false ); $btn.prop( 'disabled', false ); } );
		run( 1 );
	} );

	/* ---------- history ---------- */
	const $modal = $( '#mda-history-modal' );
	$grid.on( 'click', '.mda-history', function () {
		$modal.prop( 'hidden', false ).addClass( 'is-open' ).find( '.mda-history-body' ).html( '<p class="mda-muted">در حال بارگذاری…</p>' );
		$.post( C.ajax, { action: 'mda_price_history', nonce: C.nonce, id: $( this ).data( 'id' ) } ).done( ( r ) => {
			const $b = $modal.find( '.mda-history-body' ).empty();
			if ( ! r.success || ! r.data.length ) { $b.append( '<p class="mda-muted">تغییری ثبت نشده است.</p>' ); return; }
			const $t = $( '<table class="widefat striped"><thead><tr><th>زمان</th><th>قیمت</th><th>قبلی</th><th>جدید</th><th>کاربر</th><th>منبع</th></tr></thead><tbody></tbody></table>' );
			r.data.forEach( ( h ) => $t.find( 'tbody' ).append( $( '<tr/>' ).append( [ h.when, h.field, h.old, h.new, h.who, h.src ].map( ( x ) => $( '<td/>' ).text( x ) ) ) ) );
			$b.append( $t );
		} );
	} );
	$modal.on( 'click', ( e ) => { if ( e.target === $modal[ 0 ] || $( e.target ).is( '.mda-history-close' ) ) $modal.removeClass( 'is-open' ).prop( 'hidden', true ); } );
	$( document ).on( 'keydown', ( e ) => { if ( e.key === 'Escape' ) $modal.removeClass( 'is-open' ).prop( 'hidden', true ); } );
}( jQuery ) );
