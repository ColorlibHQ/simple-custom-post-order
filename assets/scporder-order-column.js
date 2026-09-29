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

	var REQUEST_TIMEOUT = 20000; // ms before a hung request counts as a network failure

	// Resolves with the fetch Response; rejects on a network error or timeout
	// (a hung request used to leave the field disabled for good).
	function post( body ) {
		var ctrl = typeof window.AbortController === 'function' ? new window.AbortController() : null;
		var timer;
		var timeout = new Promise( function ( resolve, reject ) {
			timer = setTimeout( function () {
				if ( ctrl ) {
					ctrl.abort();
				}
				reject( new Error( 'timeout' ) );
			}, REQUEST_TIMEOUT );
		} );
		var init = {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		};
		if ( ctrl ) {
			init.signal = ctrl.signal;
		}
		var req;
		try {
			req = fetch( endpoint(), init );
		} catch ( e ) {
			req = Promise.reject( e ); // a synchronous throw must still reach the retry/fail path
		}
		req = req.then( function ( res ) {
			return res.text().then( function ( text ) {
				return { ok: res.ok, text: text };
			} );
		} );
		return Promise.race( [ req, timeout ] ).then(
			function ( r ) {
				clearTimeout( timer );
				return r;
			},
			function ( err ) {
				clearTimeout( timer );
				throw err;
			}
		);
	}

	/**
	 * Recover the JSON payload even when a plugin printed a notice before it —
	 * that used to make a save that had succeeded look like a failure.
	 */
	function parseReply( text ) {
		var json = null;
		try {
			json = JSON.parse( text );
		} catch ( e ) {
			var starts = [ text.indexOf( '{"success"' ), text.indexOf( '{' ) ];
			for ( var i = 0; i < starts.length && ! json; i++ ) {
				if ( starts[ i ] > -1 ) {
					try {
						json = JSON.parse( text.slice( starts[ i ], text.lastIndexOf( '}' ) + 1 ) );
					} catch ( e2 ) {}
				}
			}
		}
		if ( json && typeof json !== 'object' ) {
			json = null; // a bare "-1" / "0" parses as a number
		}
		return {
			json: json,
			// admin-ajax answers "-1" when the nonce is stale/invalid.
			staleNonce: ! json && /-1\s*$/.test( text ),
		};
	}

	/**
	 * Fetch a fresh reorder nonce so this save (and later ones) use a valid one.
	 * The endpoint is authenticated and deliberately nonce-less — a stale nonce
	 * is the very reason we're calling it.
	 */
	function refreshNonce( done ) {
		post( 'action=scpo_refresh_nonce' )
			.then( function ( res ) {
				return res.ok ? parseReply( res.text ).json : null;
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

	/**
	 * Invalid entry ("0", "-3", "2.9", junk): put the last saved number back
	 * instead of silently leaving it on screen, and say why. The message is the
	 * browser's own (localized) validity text for min="1" step="1". The bubble
	 * is only shown for Enter: reporting from a blur would pull focus back.
	 */
	function reject( input, explicit ) {
		var message = input.validationMessage || vars.error;
		input.value = input.defaultValue;
		if ( explicit && input.setCustomValidity && input.reportValidity ) {
			input.setCustomValidity( message );
			input.reportValidity();
			input.addEventListener( 'input', clearValidity );
			input.addEventListener( 'blur', clearValidity );
		}
	}

	function clearValidity( e ) {
		e.target.setCustomValidity( '' );
		e.target.removeEventListener( 'input', clearValidity );
		e.target.removeEventListener( 'blur', clearValidity );
	}

	/**
	 * `explicit` is true for Enter. Pressing Enter commits, and the blur that
	 * follows fires `change` for the same value — which used to send a second
	 * request (and, on failure, a second alert). `change` therefore skips a value
	 * that was already sent; Enter may resend it, which is how a failed save is
	 * retried.
	 */
	function commit( input, explicit, opts ) {
		var id = input.getAttribute( 'data-id' );
		if ( ! id || ( ! opts && input.classList.contains( 'is-saving' ) ) ) {
			return;
		}
		if ( ! opts ) {
			if ( '' === input.value.trim() && ! ( input.validity && input.validity.badInput ) ) {
				input.value = input.defaultValue; // cleared and left: nothing to save
				return;
			}
			// valueAsNumber reads "1e3" as 1000 and "2.9" as 2.9 — parseInt made
			// those 1 and 2 and saved the wrong position.
			var num = typeof input.valueAsNumber === 'number' && ! isNaN( input.valueAsNumber )
				? input.valueAsNumber
				: Number( input.value );
			if ( ! Number.isInteger( num ) || num < 1 ) {
				reject( input, explicit );
				return;
			}
			if ( String( num ) === input.defaultValue ) {
				return; // unchanged
			}
			if ( ! explicit && String( num ) === input.getAttribute( 'data-scpo-sent' ) ) {
				return; // Enter already sent this one
			}
			input.setAttribute( 'data-scpo-sent', String( num ) );
		}
		var pos = input.getAttribute( 'data-scpo-sent' );
		opts = opts || { netRetries: 1, nonceRefreshed: false };
		input.classList.add( 'is-saving' );

		var body = new URLSearchParams();
		body.set( 'action', 'scpo_set_position' );
		body.set( 'id', id );
		body.set( 'position', pos );
		body.set( 'nonce', vars.nonce );

		post( body.toString() )
			.then( function ( res ) {
				var r = parseReply( res.text );
				var json = r.json;
				return {
					ok: !! ( json && json.success ),
					staleNonce: r.staleNonce,
					message: json && json.data && json.data.message ? json.data.message : '',
				};
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
							commit( input, true, { netRetries: 1, nonceRefreshed: true } );
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
						commit( input, true, {
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
			commit( input, false );
		}
	} );

	// Enter shouldn't submit any surrounding form; commit instead.
	document.addEventListener( 'keydown', function ( e ) {
		var input = e.target;
		if ( e.key === 'Enter' && input && input.classList && input.classList.contains( 'scpo-order-input' ) ) {
			e.preventDefault();
			commit( input, true );
		}
	} );
} )();
