/**
 * Simple Custom Post Order — SortableJS reorder layer (vanilla JS).
 *
 * A drop-in replacement for the jQuery UI Sortable implementation in
 * assets/scporder.js. No jQuery; native touch support, smooth animation,
 * WP list-table column-width locking, visible save feedback, and full
 * keyboard + screen-reader accessibility.
 *
 * Input paths:
 *   - Mouse / touch : SortableJS, dragging anywhere on a row. An optional grip
 *                     handle (toggled in Settings → SCPOrder) fades in on hover.
 *   - Keyboard      : Tab to a row's handle (revealed on focus), Space/Enter to
 *                     grab, Arrow keys (and Home/End) to move, Space/Enter to
 *                     drop, Escape to cancel. Announced via an aria-live region.
 *
 * Both paths persist through the same AJAX endpoint and save toast.
 *
 * Enabled via the `scpo_use_sortablejs` filter.
 */
( function () {
	'use strict';

	var list = document.querySelector(
		'table.posts #the-list, table.pages #the-list, table.tags #the-list'
	);

	// Bail if there is nothing to sort or the library failed to load.
	if ( ! list || typeof window.Sortable === 'undefined' ) {
		return;
	}

	// Taxonomy term tables save to a different AJAX action than post tables.
	var isTaxonomy = !! document.querySelector( 'table.tags #the-list' );
	var action = isTaxonomy ? 'update-menu-order-tags' : 'update-menu-order';

	var strings = ( window.scporder_vars && scporder_vars.i18n ) || {};
	var toast = createToast();
	var live = createLiveRegion();

	injectHandles();

	// When the "Show drag handle" setting is on, reveal the grip on row hover
	// for mouse users. Either way the handle stays in the DOM + tab order, so
	// keyboard users can always reach it (it's revealed on focus).
	if ( window.scporder_vars && scporder_vars.showHandle ) {
		list.classList.add( 'scpo-handles-visible' );
	}

	/* ---- Mouse / touch: SortableJS ------------------------------------- */

	window.Sortable.create( list, {
		animation: 150,
		draggable: 'tr',                        // whole row is draggable for mouse / touch
		filter: '.no-items, .inline-edit-row',  // never drag the "no items" or quick-edit rows
		preventOnFilter: false,                 // ...but don't preventDefault() on those rows —
		                                        // SortableJS defaults this to true, which would
		                                        // swallow left-click focus on the <input>/<select>
		                                        // fields inside WP Quick Edit / Bulk Edit rows.
		ghostClass: 'scpo-ghost',
		chosenClass: 'scpo-chosen',
		fallbackClass: 'scpo-fallback',
		forceFallback: true,        // consistent, styleable drag image across browsers + touch
		fallbackTolerance: 3,
		delay: 150,                 // press-and-hold to start on touch...
		delayOnTouchOnly: true,     // ...while taps and vertical scrolling still work on mobile

		// Lock cell widths BEFORE the drag clone is created so the floating
		// row keeps its column alignment (WP table rows otherwise collapse).
		onChoose: function ( evt ) {
			lockRowWidths( evt.item );
		},
		onUnchoose: function ( evt ) {
			unlockRowWidths( evt.item );
		},
		onEnd: function ( evt ) {
			if ( evt.oldIndex !== evt.newIndex ) {
				saveOrder();
			}
		},
	} );

	/* ---- Keyboard reordering (ARIA grab / move / drop) ----------------- */

	var grabbed = null;       // the <tr> currently picked up
	var grabbedHandle = null; // its handle button
	var restoreBefore = null; // sibling to re-insert before on cancel
	var movedWhileGrabbed = false;
	var isMoving = false;     // guards focusout while we reorder the DOM

	list.addEventListener( 'keydown', onKeydown );
	list.addEventListener( 'focusout', function ( evt ) {
		// Focus genuinely left a grabbed handle (e.g. mouse click away): commit.
		if ( grabbed && ! isMoving && evt.target === grabbedHandle ) {
			drop( grabbed );
		}
	} );

	function onKeydown( evt ) {
		var handle = evt.target.closest ? evt.target.closest( '.scpo-handle' ) : null;
		if ( ! handle ) {
			return;
		}
		var row = handle.closest( 'tr' );
		if ( ! row ) {
			return;
		}

		var key = evt.key;
		var isActivate = key === 'Enter' || key === ' ' || key === 'Spacebar';

		if ( ! grabbed ) {
			if ( isActivate ) {
				evt.preventDefault();
				grab( row, handle );
			}
			return;
		}

		switch ( key ) {
			case 'ArrowUp':
				evt.preventDefault();
				step( moveUp, row, handle );
				break;
			case 'ArrowDown':
				evt.preventDefault();
				step( moveDown, row, handle );
				break;
			case 'Home':
				evt.preventDefault();
				step( moveTop, row, handle );
				break;
			case 'End':
				evt.preventDefault();
				step( moveBottom, row, handle );
				break;
			case 'Enter':
			case ' ':
			case 'Spacebar':
				evt.preventDefault();
				drop( row );
				break;
			case 'Escape':
				evt.preventDefault();
				cancel( row );
				break;
			case 'Tab':
				drop( row ); // commit, then let focus move naturally
				break;
			default:
				break;
		}
	}

	function grab( row, handle ) {
		grabbed = row;
		grabbedHandle = handle;
		restoreBefore = row.nextElementSibling;
		movedWhileGrabbed = false;
		handle.setAttribute( 'aria-pressed', 'true' );
		row.classList.add( 'scpo-grabbed' );

		var pos = positionOf( row );
		announce(
			format(
				strings.grabbed ||
					'Grabbed %1$s. Row %2$d of %3$d. Use the arrow keys to move, Space to drop, Escape to cancel.',
				rowTitle( row ),
				pos.index + 1,
				pos.total
			)
		);
	}

	function step( mover, row, handle ) {
		isMoving = true;
		var moved = mover( row );
		if ( moved ) {
			movedWhileGrabbed = true;
			handle.focus();
			row.scrollIntoView( { block: 'nearest' } );
			var pos = positionOf( row );
			announce(
				format(
					strings.moved || '%1$s. Row %2$d of %3$d.',
					rowTitle( row ),
					pos.index + 1,
					pos.total
				)
			);
		}
		isMoving = false;
	}

	function drop( row ) {
		var pos = positionOf( row );
		var title = rowTitle( row );
		var changed = movedWhileGrabbed;
		endGrab( row );
		if ( changed ) {
			saveOrder();
		}
		announce(
			format(
				strings.dropped || '%1$s dropped. Row %2$d of %3$d.',
				title,
				pos.index + 1,
				pos.total
			)
		);
	}

	function cancel( row ) {
		isMoving = true;
		list.insertBefore( row, restoreBefore ); // back to where it started
		isMoving = false;
		var pos = positionOf( row );
		var title = rowTitle( row );
		endGrab( row );
		row.querySelector( '.scpo-handle' ).focus();
		announce(
			format(
				strings.cancelled ||
					'Reorder cancelled. %1$s returned to row %2$d of %3$d.',
				title,
				pos.index + 1,
				pos.total
			)
		);
	}

	function endGrab( row ) {
		if ( grabbedHandle ) {
			grabbedHandle.setAttribute( 'aria-pressed', 'false' );
		}
		row.classList.remove( 'scpo-grabbed' );
		grabbed = null;
		grabbedHandle = null;
		restoreBefore = null;
		movedWhileGrabbed = false;
	}

	/* ---- DOM movement helpers ------------------------------------------ */

	function moveUp( row ) {
		var prev = adjacentSortable( row, 'previousElementSibling' );
		if ( prev ) {
			list.insertBefore( row, prev );
			return true;
		}
		return false;
	}

	function moveDown( row ) {
		var next = adjacentSortable( row, 'nextElementSibling' );
		if ( next ) {
			list.insertBefore( row, next.nextElementSibling );
			return true;
		}
		return false;
	}

	function moveTop( row ) {
		var rows = sortableRows();
		if ( rows[ 0 ] && rows[ 0 ] !== row ) {
			list.insertBefore( row, rows[ 0 ] );
			return true;
		}
		return false;
	}

	function moveBottom( row ) {
		var rows = sortableRows();
		var last = rows[ rows.length - 1 ];
		if ( last && last !== row ) {
			list.insertBefore( row, last.nextElementSibling );
			return true;
		}
		return false;
	}

	function adjacentSortable( row, dir ) {
		var sib = row[ dir ];
		while ( sib && ! isSortableRow( sib ) ) {
			sib = sib[ dir ];
		}
		return sib;
	}

	/* ---- Shared helpers ------------------------------------------------ */

	function isSortableRow( row ) {
		return (
			row.nodeName === 'TR' &&
			row.id &&
			! row.classList.contains( 'no-items' ) &&
			! row.classList.contains( 'inline-edit-row' )
		);
	}

	function sortableRows() {
		return Array.prototype.filter.call(
			list.querySelectorAll( 'tr[id]' ),
			isSortableRow
		);
	}

	function positionOf( row ) {
		var rows = sortableRows();
		return { index: rows.indexOf( row ), total: rows.length };
	}

	function rowTitle( row ) {
		var t = row.querySelector( '.row-title' );
		if ( t ) {
			return t.textContent.trim();
		}
		var cell = firstDataCell( row );
		return cell ? cell.textContent.trim() : row.id;
	}

	function firstDataCell( row ) {
		return row.querySelector(
			'td:not(.check-column), th:not(.check-column)'
		);
	}

	function injectHandles() {
		sortableRows().forEach( function ( row ) {
			if ( row.querySelector( '.scpo-handle' ) ) {
				return;
			}
			var titleEl = row.querySelector( '.row-title' );
			var cell = titleEl ? titleEl.closest( 'td, th' ) : firstDataCell( row );
			if ( ! cell ) {
				return;
			}
			// Anchor for the absolutely-positioned grip + its reserved gutter.
			cell.classList.add( 'scpo-handle-cell' );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'scpo-handle';
			btn.setAttribute( 'aria-pressed', 'false' );
			btn.setAttribute(
				'aria-label',
				format( strings.reorderLabel || 'Reorder: %1$s', rowTitle( row ) )
			);
			btn.innerHTML =
				'<svg class="scpo-grip" viewBox="0 0 16 16" aria-hidden="true" focusable="false">' +
				'<circle cx="5" cy="3" r="1.5"></circle><circle cx="11" cy="3" r="1.5"></circle>' +
				'<circle cx="5" cy="8" r="1.5"></circle><circle cx="11" cy="8" r="1.5"></circle>' +
				'<circle cx="5" cy="13" r="1.5"></circle><circle cx="11" cy="13" r="1.5"></circle>' +
				'</svg>';
			cell.insertBefore( btn, cell.firstChild );
		} );
	}

	/* ---- Pixel-width locking for the floating row ---------------------- */

	function lockRowWidths( row ) {
		var cells = row.children;
		for ( var i = 0; i < cells.length; i++ ) {
			cells[ i ].style.width = cells[ i ].offsetWidth + 'px';
		}
	}

	function unlockRowWidths( row ) {
		var cells = row.children;
		for ( var i = 0; i < cells.length; i++ ) {
			cells[ i ].style.width = '';
		}
	}

	/* ---- Persistence --------------------------------------------------- */

	/**
	 * Reproduce jQuery UI's `.sortable('serialize')` output (`key[]=id&...`)
	 * from the current row order, so the existing PHP AJAX handlers — and
	 * their nonce/capability checks — work unchanged.
	 */
	function serializeOrder() {
		var rows = list.querySelectorAll( 'tr[id]' );
		var pairs = [];

		for ( var i = 0; i < rows.length; i++ ) {
			var id = rows[ i ].id;          // e.g. "post-123" or "tag-45"
			var sep = id.lastIndexOf( '-' );
			if ( sep === -1 ) {
				continue;
			}
			var key = id.slice( 0, sep );
			var value = id.slice( sep + 1 );
			if ( ! /^\d+$/.test( value ) ) {
				continue;
			}
			pairs.push(
				encodeURIComponent( key ) + '[]=' + encodeURIComponent( value )
			);
		}

		return pairs.join( '&' );
	}

	var saving = false;      // a save request is currently in flight
	var pendingSave = false; // the list changed again before that request returned

	/**
	 * Resolve the admin-ajax endpoint against the CURRENT page origin.
	 *
	 * PHP already hands us a root-relative path, but we re-resolve and force the
	 * page's own protocol + host so the request can never go cross-origin — even
	 * if a filter or another plugin rewrote `ajax_url` back to an absolute URL on
	 * a different origin. A cross-origin POST would drop the auth cookie and be
	 * blocked by CORS, which is exactly the "looks fine, never saves" failure.
	 */
	function ajaxEndpoint() {
		var raw =
			( window.scporder_vars && scporder_vars.ajax_url ) ||
			'/wp-admin/admin-ajax.php';
		try {
			var u = new URL( raw, window.location.href );
			u.protocol = window.location.protocol;
			u.host = window.location.host;
			return u.toString();
		} catch ( e ) {
			return raw;
		}
	}

	/**
	 * Persist the current order. Single-flight: while a request is in flight,
	 * further moves just flag a trailing save, which fires once the current one
	 * resolves — so rapid drags collapse into one request and the final order
	 * always wins (no out-of-order races).
	 */
	function saveOrder() {
		if ( saving ) {
			pendingSave = true;
			return;
		}
		saving = true;
		showToast( strings.saving || 'Saving order…', 'saving' );
		postOrder( serializeOrder(), { netRetries: 1, nonceRefreshed: false } );
	}

	function postOrder( order, opts ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'order', order );
		body.set( 'nonce', scporder_vars.nonce );

		fetch( ajaxEndpoint(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) {
				return res.text().then( function ( text ) {
					var json = null;
					try {
						json = JSON.parse( text );
					} catch ( e ) {} // tolerate stray output from other plugins
					return {
						ok: !! ( res.ok && json && json.success ),
						// admin-ajax answers "-1" when the nonce is stale/invalid.
						staleNonce: text.trim() === '-1',
					};
				} );
			} )
			.then( function ( r ) {
				if ( r.ok ) {
					finishSave( true );
					return;
				}
				// Expired nonce (long-open screen / short nonce_life): fetch a
				// fresh one and retry the save exactly once — invisible to the user.
				if ( r.staleNonce && ! opts.nonceRefreshed ) {
					refreshNonce( function ( refreshed ) {
						if ( refreshed ) {
							postOrder( order, { netRetries: 1, nonceRefreshed: true } );
						} else {
							finishSave( false );
						}
					} );
					return;
				}
				// Genuine rejection (e.g. permission denied) — retrying won't help.
				finishSave( false );
			} )
			.catch( function () {
				// Transient/network error (offline, blip): retry once, then give up.
				if ( opts.netRetries > 0 ) {
					setTimeout( function () {
						postOrder( order, {
							netRetries: opts.netRetries - 1,
							nonceRefreshed: opts.nonceRefreshed,
						} );
					}, 800 );
					return;
				}
				finishSave( false );
			} );
	}

	/**
	 * Fetch a fresh reorder nonce from the authenticated refresh endpoint and
	 * update it in place, so this save (and later ones) use a valid nonce.
	 */
	function refreshNonce( done ) {
		fetch( ajaxEndpoint(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=scpo_refresh_nonce',
		} )
			.then( function ( res ) {
				return res.ok ? res.json() : null;
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.nonce ) {
					scporder_vars.nonce = json.data.nonce;
					done( true );
				} else {
					done( false );
				}
			} )
			.catch( function () {
				done( false );
			} );
	}

	function finishSave( ok ) {
		if ( ok && pendingSave ) {
			// Order changed again mid-flight — persist the latest, stay in flight.
			pendingSave = false;
			showToast( strings.saving || 'Saving order…', 'saving' );
			postOrder( serializeOrder(), { netRetries: 1, nonceRefreshed: false } );
			return;
		}
		saving = false;
		pendingSave = false;
		showToast(
			ok
				? strings.saved || 'Order saved'
				: strings.error || 'Couldn’t save — please try again',
			ok ? 'saved' : 'error'
		);
	}

	/* ---- Feedback elements --------------------------------------------- */

	function createToast() {
		var el = document.createElement( 'div' );
		el.className = 'scpo-toast';
		el.setAttribute( 'role', 'status' );
		el.setAttribute( 'aria-live', 'polite' );
		document.body.appendChild( el );
		return el;
	}

	var hideTimer;
	function showToast( message, state ) {
		clearTimeout( hideTimer );
		toast.textContent = message;
		toast.className = 'scpo-toast scpo-toast--' + state + ' is-visible';

		if ( state !== 'saving' ) {
			hideTimer = setTimeout(
				function () {
					toast.classList.remove( 'is-visible' );
				},
				state === 'error' ? 6000 : 2000
			);
		}
	}

	function createLiveRegion() {
		var el = document.createElement( 'div' );
		el.className = 'scpo-sr-only';
		el.setAttribute( 'aria-live', 'assertive' );
		el.setAttribute( 'aria-atomic', 'true' );
		document.body.appendChild( el );
		return el;
	}

	function announce( message ) {
		// Clearing first makes screen readers re-read an otherwise identical string.
		live.textContent = '';
		live.textContent = message;
	}

	/* ---- tiny printf for %1$s / %2$d placeholders ---------------------- */

	function format( tpl ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( tpl ).replace( /%(\d+)\$[sd]/g, function ( m, i ) {
			return args[ i - 1 ] !== undefined ? args[ i - 1 ] : m;
		} );
	}
} )();
