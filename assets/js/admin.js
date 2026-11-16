/**
 * MaliDev Accounting — small progressive enhancements for every accounting screen.
 * Deliberately does NOT add Bootstrap's .card/.btn classes: the shared skin resets
 * `.wca-bs .card { padding: 0 }`, which collapsed every accounting card.
 */
( () => {
	'use strict';
	document.addEventListener( 'DOMContentLoaded', () => {
		// Cash form: show only the fields that belong to the chosen transaction type.
		const cashType = document.querySelector( 'body.mda-admin [data-mda-cash-type]' );
		if ( cashType ) {
			const income = document.querySelector( '.mda-cash-income' );
			const expense = document.querySelector( '.mda-cash-expense' );
			const destination = document.querySelector( '.mda-cash-destination' );
			const method = document.querySelector( 'select[name=payment_method]' );
			const toggle = ( node, show ) => {
				if ( ! node ) return;
				node.style.display = show ? '' : 'none';
				const field = node.querySelector( 'select' );
				if ( field ) {
					field.required = show;
					field.disabled = ! show;
					if ( ! show ) field.value = '';
				}
			};
			const sync = () => {
				const type = cashType.value;
				toggle( income, type === 'receipt' );
				toggle( expense, type === 'payment' );
				toggle( destination, type === 'transfer' );
				if ( method ) {
					method.disabled = false;
					if ( type === 'transfer' ) method.value = 'transfer';
				}
			};
			cashType.addEventListener( 'change', sync );
			sync();
		}

		// Prevent double submits.
		document.querySelectorAll( 'body.mda-admin #wpbody-content form' ).forEach( ( form ) => {
			form.addEventListener( 'submit', () => {
				const button = form.querySelector( 'button[type=submit],button:not([type]),input[type=submit]' );
				if ( button && ! button.disabled ) {
					setTimeout( () => { button.disabled = true; button.classList.add( 'disabled' ); }, 0 );
				}
			} );
		} );

		// Confirm dangerous actions declared with data-mda-confirm.
		document.querySelectorAll( 'body.mda-admin [data-mda-confirm]' ).forEach( ( el ) => {
			el.addEventListener( 'click', ( e ) => {
				if ( ! window.confirm( el.getAttribute( 'data-mda-confirm' ) ) ) e.preventDefault();
			} );
		} );

		// Wide tables scroll inside a rounded frame instead of breaking the layout.
		document.querySelectorAll( 'body.mda-admin #wpbody-content .wrap table.widefat' ).forEach( ( table ) => {
			if ( table.closest( '.mda-table-wrap, .mdg-card, .mda-help' ) ) return;
			const wrap = document.createElement( 'div' );
			wrap.className = 'mda-table-wrap';
			table.parentNode.insertBefore( wrap, table );
			wrap.appendChild( table );
		} );
	} );
} )();
