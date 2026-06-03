/**
 * Simple Custom Post Order — optional "Order" column.
 *
 * Type an absolute position into a row's Order field and press Enter (or blur);
 * the item jumps to that position across the whole list, regardless of which
 * paginated page you're on. Posts same-origin to the reorder endpoint.
 *
 * Enabled by the "Order column" setting (off by default).
 */
( function () {
	'use strict';

	var vars = window.scpoOrderCol;
	if ( ! vars ) {
		return;
	}

	// Always resolve to the current page's origin (proxies / ports / https).
	function endpoint() {
		try {
			var u = new URL( vars.ajax_url, window.location.href );
			u.protocol = window.location.protocol;
			u.host = window.location.host;
			return u.toString();
		} catch ( e ) {
			return vars.ajax_url;
		}
	}

	function commit( input ) {
		var id = input.getAttribute( 'data-id' );
		var pos = parseInt( input.value, 10 );
		if ( ! id || ! pos || pos < 1 ) {
			return;
		}
		input.classList.add( 'is-saving' );

		var body = new URLSearchParams();
		body.set( 'action', 'scpo_set_position' );
		body.set( 'id', id );
		body.set( 'position', pos );
		body.set( 'nonce', vars.nonce );

		fetch( endpoint(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) {
				return res.ok ? res.json() : null;
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					// Reload so every row's number + pagination reflect the new order.
					window.location.reload();
				} else {
					input.classList.remove( 'is-saving' );
					window.alert( vars.error );
				}
			} )
			.catch( function () {
				input.classList.remove( 'is-saving' );
				window.alert( vars.error );
			} );
	}

	// `change` covers blur and Enter on number inputs.
	document.addEventListener( 'change', function ( e ) {
		var input = e.target;
		if ( input && input.classList && input.classList.contains( 'scpo-order-input' ) ) {
			commit( input );
		}
	} );

	// Enter shouldn't submit any surrounding form; commit instead.
	document.addEventListener( 'keydown', function ( e ) {
		var input = e.target;
		if ( e.key === 'Enter' && input && input.classList && input.classList.contains( 'scpo-order-input' ) ) {
			e.preventDefault();
			commit( input );
		}
	} );
} )();
