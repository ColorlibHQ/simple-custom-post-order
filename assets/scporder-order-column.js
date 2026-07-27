/**
 * Simple Custom Post Order — optional "Order" column.
 *
 * Type an absolute position into a row's Order field and press Enter (or blur);
 * the item jumps to that position across the whole list, regardless of which
 * paginated page you're on. Posts same-origin to the reorder endpoint.
 *
 * Enabled by the "Order column" setting (off by default).
 *
 * Error handling mirrors the drag-and-drop sorter (scporder-sortablejs.js):
 * tolerate stray output from other plugins, transparently refresh an expired
 * nonce and retry, retry once on a network blip, and report the server's own
 * message instead of one generic string for every failure. Before 2.8.6 this
 * script did none of that — a long-open list screen, a PHP notice printed by
 * another plugin, or a permissions problem all surfaced as the same
 * "Couldn't update the order" alert with no way to tell them apart.
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

	function post( body ) {
		return fetch( endpoint(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		} );
	}

	/**
	 * Fetch a fresh reorder nonce so this save (and later ones) use a valid one.
	 * The endpoint is authenticated and deliberately nonce-less — a stale nonce
	 * is the very reason we're calling it.
	 */
	function refreshNonce( done ) {
		post( 'action=scpo_refresh_nonce' )
			.then( function ( res ) {
				return res.ok ? res.json() : null;
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.nonce ) {
					vars.nonce = json.data.nonce;
					done( true );
				} else {
					done( false );
				}
			} )
			.catch( function () {
				done( false );
			} );
	}

	function fail( input, message ) {
		input.classList.remove( 'is-saving' );
		window.alert( message || vars.error );
	}

	function commit( input, opts ) {
		var id = input.getAttribute( 'data-id' );
		var pos = parseInt( input.value, 10 );
		if ( ! id || ! pos || pos < 1 ) {
			return;
		}
		opts = opts || { netRetries: 1, nonceRefreshed: false };
		input.classList.add( 'is-saving' );

		var body = new URLSearchParams();
		body.set( 'action', 'scpo_set_position' );
		body.set( 'id', id );
		body.set( 'position', pos );
		body.set( 'nonce', vars.nonce );

		post( body.toString() )
			.then( function ( res ) {
				return res.text().then( function ( text ) {
					var json = null;
					try {
						json = JSON.parse( text );
					} catch ( e ) {} // tolerate stray output from other plugins

					// A plugin printing a notice before the JSON used to make this
					// look like a failure even though the order had been saved.
					// Recover the payload from anywhere in the body.
					if ( ! json ) {
						var brace = text.indexOf( '{' );
						if ( brace > -1 ) {
							try {
								json = JSON.parse( text.slice( brace ) );
							} catch ( e2 ) {}
						}
					}

					return {
						ok: !! ( json && json.success ),
						// admin-ajax answers "-1" when the nonce is stale/invalid.
						staleNonce: text.trim() === '-1' || text.slice( -2 ).trim() === '-1',
						message: json && json.data && json.data.message ? json.data.message : '',
					};
				} );
			} )
			.then( function ( r ) {
				if ( r.ok ) {
					// Reload so every row's number + pagination reflect the new order.
					window.location.reload();
					return;
				}
				// Expired nonce (long-open screen / short nonce_life): fetch a fresh
				// one and retry the save exactly once — invisible to the user.
				if ( r.staleNonce && ! opts.nonceRefreshed ) {
					refreshNonce( function ( refreshed ) {
						if ( refreshed ) {
							commit( input, { netRetries: 1, nonceRefreshed: true } );
						} else {
							fail( input, vars.expired );
						}
					} );
					return;
				}
				if ( r.staleNonce ) {
					fail( input, vars.expired );
					return;
				}
				// Genuine rejection — show what the server actually said
				// ("Permission denied.", "Invalid item.") rather than a generic string.
				fail( input, r.message );
			} )
			.catch( function () {
				// Transient/network error (offline, blip): retry once, then give up.
				if ( opts.netRetries > 0 ) {
					setTimeout( function () {
						commit( input, {
							netRetries: opts.netRetries - 1,
							nonceRefreshed: opts.nonceRefreshed,
						} );
					}, 800 );
					return;
				}
				fail( input, vars.network );
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
