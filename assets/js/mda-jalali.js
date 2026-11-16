/**
 * MaliDev Accounting — Jalali dates, amount inputs and journal totals on accounting screens.
 * The server always receives Gregorian Y-m-d (for legacy date fields) or a Jalali string it parses itself.
 */
( function () {
	'use strict';
	const settings = window.mdaSettings || { jalali: 1 };
	const BREAKS = [ -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178 ];
	const div = ( a, b ) => Math.trunc( a / b );
	const mod = ( a, b ) => a - Math.trunc( a / b ) * b;

	function cal( jy ) {
		let gy = jy + 621, leapJ = -14, jp = BREAKS[ 0 ], jump = 0, jm;
		for ( let i = 1; i < BREAKS.length; i++ ) {
			jm = BREAKS[ i ]; jump = jm - jp;
			if ( jy < jm ) break;
			leapJ += div( jump, 33 ) * 8 + div( mod( jump, 33 ), 4 ); jp = jm;
		}
		let n = jy - jp;
		leapJ += div( n, 33 ) * 8 + div( mod( n, 33 ) + 3, 4 );
		if ( mod( jump, 33 ) === 4 && jump - n === 4 ) leapJ += 1;
		const leapG = div( gy, 4 ) - div( ( div( gy, 100 ) + 1 ) * 3, 4 ) - 150;
		const march = 20 + leapJ - leapG;
		if ( jump - n < 6 ) n = n - jump + div( jump + 4, 33 ) * 33;
		let leap = mod( mod( n + 1, 33 ) - 1, 4 );
		if ( leap === -1 ) leap = 4;
		return { leap, gy, march };
	}
	function g2d( gy, gm, gd ) {
		const d = div( ( gy + div( gm - 8, 6 ) + 100100 ) * 1461, 4 ) + div( 153 * mod( gm + 9, 12 ) + 2, 5 ) + gd - 34840408;
		return d - div( div( gy + 100100 + div( gm - 8, 6 ), 100 ) * 3, 4 ) + 752;
	}
	function d2g( jdn ) {
		let j = 4 * jdn + 139361631;
		j = j + div( div( 4 * jdn + 183187720, 146097 ) * 3, 4 ) * 4 - 3908;
		const i = div( mod( j, 1461 ), 4 ) * 5 + 308;
		const gd = div( mod( i, 153 ), 5 ) + 1, gm = mod( div( i, 153 ), 12 ) + 1;
		return [ div( j, 1461 ) - 100100 + div( 8 - gm, 6 ), gm, gd ];
	}
	function toJalali( gy, gm, gd ) {
		const jdn = g2d( gy, gm, gd ), g = d2g( jdn )[ 0 ];
		let jy = g - 621; const r = cal( jy ); let k = jdn - g2d( g, 3, r.march );
		if ( k >= 0 ) { if ( k <= 185 ) return [ jy, 1 + div( k, 31 ), mod( k, 31 ) + 1 ]; k -= 186; }
		else { jy -= 1; k += 179; if ( r.leap === 1 ) k += 1; }
		return [ jy, 7 + div( k, 30 ), mod( k, 30 ) + 1 ];
	}
	function toGregorian( jy, jm, jd ) {
		const r = cal( jy );
		return d2g( g2d( r.gy, 3, r.march ) + ( jm - 1 ) * 31 - div( jm, 7 ) * ( jm - 7 ) + jd - 1 );
	}
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	const fa = ( s ) => String( s ).replace( /\d/g, ( d ) => '۰۱۲۳۴۵۶۷۸۹'[ d ] );
	const latin = ( s ) => String( s ).replace( /[۰-۹]/g, ( d ) => '۰۱۲۳۴۵۶۷۸۹'.indexOf( d ) ).replace( /[٠-٩]/g, ( d ) => '٠١٢٣٤٥٦٧٨٩'.indexOf( d ) );
	function gregToJalaliText( ymd, persian ) {
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( ymd || '' );
		if ( ! m ) return '';
		const j = toJalali( +m[ 1 ], +m[ 2 ], +m[ 3 ] );
		const out = j[ 0 ] + '/' + pad( j[ 1 ] ) + '/' + pad( j[ 2 ] );
		return persian ? fa( out ) : out;
	}
	function jalaliTextToGreg( text ) {
		const m = /^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/.exec( latin( text ).trim() );
		if ( ! m ) return null;
		const jy = +m[ 1 ], jm = +m[ 2 ], jd = +m[ 3 ];
		const max = jm <= 6 ? 31 : jm <= 11 ? 30 : ( cal( jy ).leap === 0 ? 30 : 29 );
		if ( jm < 1 || jm > 12 || jd < 1 || jd > max ) return null;
		const g = toGregorian( jy, jm, jd );
		return g[ 0 ] + '-' + pad( g[ 1 ] ) + '-' + pad( g[ 2 ] );
	}
	window.mdaJalali = { toJalali, toGregorian, gregToJalaliText, jalaliTextToGreg };

	document.addEventListener( 'DOMContentLoaded', () => {
		const root = document.querySelector( 'body.mda-admin #wpbody-content' );
		if ( ! root ) return;

		if ( settings.jalali ) {
			// Legacy <input type=date>: show a Jalali text box, keep the Gregorian value in the original field.
			root.querySelectorAll( 'input[type=date]' ).forEach( ( input ) => {
				const text = document.createElement( 'input' );
				text.type = 'text';
				text.className = 'mda-jdate';
				text.size = 11;
				text.placeholder = '۱۴۰۵/۰۱/۰۱';
				text.required = input.required;
				text.value = gregToJalaliText( input.value, false );
				input.type = 'hidden';
				input.after( text );
				const sync = () => {
					const g = jalaliTextToGreg( text.value );
					input.value = g || '';
					text.setCustomValidity( text.value && ! g ? 'تاریخ شمسی معتبر نیست (مثال: ۱۴۰۵/۰۸/۲۵)' : '' );
				};
				text.addEventListener( 'input', sync );
				text.addEventListener( 'change', sync );
			} );

			// Gregorian dates printed in tables → Jalali (original kept in the title attribute).
			const re = /\b((?:19|20)\d{2})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2})?)?\b/g;
			root.querySelectorAll( 'td, .mda-date' ).forEach( ( cell ) => {
				if ( cell.querySelector( 'input,select,textarea,form' ) ) return;
				const walker = document.createTreeWalker( cell, NodeFilter.SHOW_TEXT );
				const nodes = [];
				while ( walker.nextNode() ) nodes.push( walker.currentNode );
				nodes.forEach( ( node ) => {
					if ( ! re.test( node.nodeValue ) ) return;
					re.lastIndex = 0;
					const original = node.nodeValue;
					node.nodeValue = original.replace( re, ( all, y, m, d, time ) => gregToJalaliText( `${ y }-${ m }-${ d }`, true ) + ( time ? ' ' + fa( time ) : '' ) );
					if ( node.parentElement && ! node.parentElement.title ) node.parentElement.title = original.trim();
				} );
			} );
		}

		// Amount fields: accept Persian digits, show thousands separators.
		const parseAmount = ( v ) => parseInt( latin( v ).replace( /[^\d]/g, '' ) || '0', 10 );
		root.querySelectorAll( 'input.mda-amount' ).forEach( ( input ) => {
			input.addEventListener( 'input', () => {
				const n = parseAmount( input.value );
				input.value = n ? n.toLocaleString( 'en-US' ) : '';
			} );
		} );

		// Journal voucher totals.
		const form = document.getElementById( 'mda-journal-form' );
		if ( form ) {
			const update = () => {
				let d = 0, c = 0;
				form.querySelectorAll( 'input[name$="[debit]"]' ).forEach( ( i ) => ( d += parseAmount( i.value ) ) );
				form.querySelectorAll( 'input[name$="[credit]"]' ).forEach( ( i ) => ( c += parseAmount( i.value ) ) );
				form.querySelector( '.mda-sum-debit' ).textContent = d.toLocaleString( 'fa-IR' );
				form.querySelector( '.mda-sum-credit' ).textContent = c.toLocaleString( 'fa-IR' );
				const diff = form.querySelector( '.mda-sum-diff' );
				diff.textContent = d === c ? ( d ? 'تراز است ✓' : '' ) : 'اختلاف: ' + Math.abs( d - c ).toLocaleString( 'fa-IR' );
				diff.className = 'mda-sum-diff ' + ( d === c ? 'mda-ok' : 'mda-bad' );
			};
			form.addEventListener( 'input', update );
			update();
		}
	} );
}() );
