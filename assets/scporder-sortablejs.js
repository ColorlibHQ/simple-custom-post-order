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
 *                     In a page/category tree, keyboard moves stay among the
 *                     row's siblings (see moveBlock()).
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

	// An asset optimizer can strip the inline `scporder_vars` block. Keep going
	// on defaults rather than throwing on the first save and leaving the toast
	// stuck on "Saving…": the endpoint falls back to WP's own `ajaxurl`, and a
	// missing nonce is fetched through the refresh endpoint (see postOrder()).
	var vars = window.scporder_vars || {};
	var strings = vars.i18n || {};

	var REQUEST_TIMEOUT = 20000; // ms before a hung save counts as a network failure

	var draggedKids = [];   // subtree travelling with the row currently being dragged
	var dragStartKey = '';  // row order when the mouse drag began
	var isDragging = false; // a SortableJS drag is in progress

	var toast = createToast();
	var live = createLiveRegion();
	var instructionsId = createInstructions();

	/* ---- Hierarchical lists: the real tree ------------------------------ */

	/**
	 * Row id → { parent: row id | null, level: n | null }.
	 *
	 * Derived ONCE from the `level-N` classes WordPress renders, which are only
	 * guaranteed to describe the tree at load: after a drop the DOM is just a
	 * flat run of rows, and reading "the deeper rows that follow" from it made
	 * a row dropped between a parent and its first child adopt that child on
	 * its next drag. Saving never changes post_parent, so the load-time tree
	 * stays true for the life of the page. Keyed by id, not element, so a row
	 * whose HTML Quick Edit replaces keeps its place in the tree.
	 */
	var tree = {};

	buildTree();
	injectHandles();

	// When the "Show drag handle" setting is on, reveal the grip on row hover
	// for mouse users. Either way the handle stays in the DOM + tab order, so
	// keyboard users can always reach it (it's revealed on focus).
	if ( vars.showHandle ) {
		list.classList.add( 'scpo-handles-visible' );
	}

	/**
	 * Nesting depth of a row, read from WordPress's `level-N` row class.
	 * Returns null for a flat list (posts, non-hierarchical CPTs and
	 * taxonomies), where every tree helper below is a no-op.
	 */
	function rowLevel( row ) {
		if ( ! row || 'TR' !== row.nodeName ) {
			return null;
		}
		var match = /(?:^|\s)level-(\d+)(?:\s|$)/.exec( row.className );
		return match ? parseInt( match[ 1 ], 10 ) : null;
	}

	/**
	 * The row's real parent, from the hidden Quick Edit data WordPress prints in
	 * every row (`.post_parent` for posts, `.parent` for terms): the parent's row
	 * id, null for a top-level row or one whose parent isn't listed, or
	 * undefined when the row carries no such data.
	 *
	 * The `level-N` class alone can't tell: WordPress lists orphans — pages whose
	 * parent is trashed or filtered out — at the end of the list, still marked
	 * with their ancestor depth, so a level-based guess hands them to whichever
	 * row happens to precede them.
	 */
	function declaredParent( row ) {
		var el = row.querySelector( '.hidden .post_parent, .hidden .parent' );
		if ( ! el ) {
			return undefined;
		}
		var id = parseInt( el.textContent, 10 );
		if ( ! id ) {
			return null;
		}
		var parentRow = document.getElementById( row.id.replace( /\d+$/, '' ) + id );
		return parentRow && isSortableRow( parentRow ) ? parentRow.id : null;
	}

	/** One linear pass with an ancestor stack — only valid on the load-time DOM. */
	function buildTree() {
		var stack = [];
		sortableRows().forEach( function ( row ) {
			var level = rowLevel( row );
			if ( null === level ) {
				tree[ row.id ] = { parent: null, level: null };
				return;
			}
			while ( stack.length && stack[ stack.length - 1 ].level >= level ) {
				stack.pop();
			}
			var declared = declaredParent( row );
			tree[ row.id ] = {
				parent: undefined !== declared ? declared : ( stack.length ? stack[ stack.length - 1 ].id : null ),
				level: level,
			};
			stack.push( { id: row.id, level: level } );
		} );
	}

	/**
	 * Place a row that appeared after load (a term added via AJAX, or a row
	 * whose level changed through Quick Edit's Parent field). WordPress inserts
	 * it where the tree says it belongs, so the nearest shallower row above it
	 * is its parent.
	 */
	function registerRow( row ) {
		var level = rowLevel( row );
		var parent = null;
		if ( null !== level && level > 0 ) {
			var prev = row.previousElementSibling;
			while ( prev ) {
				var prevLevel = isSortableRow( prev ) ? rowLevel( prev ) : null;
				if ( null !== prevLevel && prevLevel < level ) {
					parent = prev.id;
					break;
				}
				prev = prev.previousElementSibling;
			}
		}
		var declared = declaredParent( row );
		tree[ row.id ] = { parent: undefined !== declared ? declared : parent, level: level };
	}

	function parentOf( row ) {
		var node = tree[ row.id ];
		if ( ! node ) {
			registerRow( row );
			node = tree[ row.id ];
		}
		return node.parent;
	}

	function isDescendant( id, ancestorId ) {
		var node = tree[ id ];
		var guard = 0;
		while ( node && node.parent && guard++ < 100 ) {
			if ( node.parent === ancestorId ) {
				return true;
			}
			node = tree[ node.parent ];
		}
		return false;
	}

	/**
	 * The rows nested under `row`, in their current on-screen order.
	 *
	 * WordPress renders a page tree as one flat run of <tr>s, so moving a
	 * parent moved only its own row and its children stayed where they were
	 * until the page was reloaded (reported by @jamieburchell). What was
	 * *saved* was always right — the list table re-nests children under their
	 * parent on the next render, and post_parent is never touched — but the
	 * screen disagreed with it in the meantime. Carrying the subtree along
	 * makes what you see match what was stored.
	 */
	function descendantsOf( row ) {
		if ( null === rowLevel( row ) ) {
			return [];
		}
		return sortableRows().filter( function ( r ) {
			return r !== row && isDescendant( r.id, row.id );
		} );
	}

	/** Re-insert `kids` directly after `row`, keeping their relative order. */
	function reattach( row, kids ) {
		var anchor = row;
		for ( var i = 0; i < kids.length; i++ ) {
			if ( anchor.parentNode ) {
				anchor.parentNode.insertBefore( kids[ i ], anchor.nextElementSibling );
			}
			anchor = kids[ i ];
		}
	}

	/* ---- Rows added or replaced after load ----------------------------- */

	// Quick Edit swaps a saved row's HTML for a fresh <tr>, and edit-tags.php
	// prepends a new term's row — neither went through injectHandles(), so they
	// had no keyboard handle. Watch the list and catch them up.
	if ( typeof window.MutationObserver === 'function' ) {
		new window.MutationObserver( function ( mutations ) {
			var added = false;
			for ( var m = 0; m < mutations.length; m++ ) {
				var nodes = mutations[ m ].addedNodes;
				for ( var n = 0; n < nodes.length; n++ ) {
					var row = nodes[ n ];
					if ( 1 !== row.nodeType || ! isSortableRow( row ) ) {
						continue;
					}
					// Our own moves re-add known rows with handles — skip those.
					var node = tree[ row.id ];
					if ( ! node || node.level !== rowLevel( row ) ) {
						registerRow( row );
					}
					if ( ! row.querySelector( '.scpo-handle' ) ) {
						added = true;
					}
				}
			}
			if ( added ) {
				injectHandles();
			}
		} ).observe( list, { childList: true } );
	}

	/* ---- Mouse / touch: SortableJS ------------------------------------- */

	var reduceMotion =
		typeof window.matchMedia === 'function' &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	window.Sortable.create( list, {
		animation: reduceMotion ? 0 : 150,
		draggable: 'tr',                        // whole row is draggable for mouse / touch
		// Never drag the "no items" or quick-edit rows, and never start a drag
		// from a form control — whole-row drag otherwise swallowed the mousedown
		// that places the caret in the Order column's number field. The grip
		// handle is a <button> too, but it IS a drag handle, so it's exempt.
		filter:
			'.no-items, .inline-edit-row, input, textarea, select, ' +
			'button:not(.scpo-handle), [contenteditable]:not([contenteditable="false"])',
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
			// Mouse drag is deliberately NOT limited to siblings (that is #58);
			// it just carries the row's true subtree.
			draggedKids = descendantsOf( evt.item );
			dragStartKey = orderKey();
		},
		onUnchoose: function ( evt ) {
			unlockRowWidths( evt.item );
			isDragging = false; // belt and braces: never leave saves deferred forever
		},
		onStart: function () {
			isDragging = true;
		},
		onEnd: function ( evt ) {
			isDragging = false;
			// Bring the children along before reading the order back out of
			// the DOM, so the request matches what the user is looking at.
			reattach( evt.item, draggedKids );
			draggedKids = [];
			// Compare the whole order, not old/new index: dropping a parent
			// inside its own subtree snaps back to where it started.
			if ( orderKey() !== dragStartKey ) {
				saveOrder();
			} else {
				flushDeferredSave();
			}
		},
	} );

	/* ---- Keyboard reordering (ARIA grab / move / drop) ----------------- */

	var grabbed = null;       // the <tr> currently picked up
	var grabbedHandle = null; // its handle button
	var grabSnapshot = null;  // every row of #the-list, in order, at grab time
	var grabKey = '';         // sortable-row order at grab time
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
				step( 'up', row, handle );
				break;
			case 'ArrowDown':
				evt.preventDefault();
				step( 'down', row, handle );
				break;
			case 'Home':
				evt.preventDefault();
				step( 'first', row, handle );
				break;
			case 'End':
				evt.preventDefault();
				step( 'last', row, handle );
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
		grabSnapshot = Array.prototype.slice.call( list.children );
		grabKey = orderKey();
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

	function step( dir, row, handle ) {
		isMoving = true;
		var moved = moveBlock( row, dir );
		if ( moved ) {
			handle.focus();
			if ( row.scrollIntoView ) {
				row.scrollIntoView( { block: 'nearest' } );
			}
		}
		isMoving = false;

		if ( ! moved ) {
			// At the edge (of the list, or of the row's siblings): say so rather
			// than staying silent, and don't count it as a move.
			var up = dir === 'up' || dir === 'first';
			announce(
				format(
					( up ? strings.atTop : strings.atBottom ) ||
						( up ? '%1$s can’t move up any further.' : '%1$s can’t move down any further.' ),
					rowTitle( row )
				)
			);
			return;
		}

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

	function drop( row ) {
		var pos = positionOf( row );
		var title = rowTitle( row );
		// Up-then-down is not a change; only save if the order really differs.
		var changed = orderKey() !== grabKey;
		endGrab( row );
		if ( changed ) {
			saveOrder();
		} else {
			flushDeferredSave();
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
		// Put every row back exactly where it was at grab time — including any
		// subtree the moves swapped past — instead of just re-inserting one row.
		isMoving = true;
		for ( var i = 0; i < grabSnapshot.length; i++ ) {
			if ( grabSnapshot[ i ].parentNode === list ) {
				list.appendChild( grabSnapshot[ i ] );
			}
		}
		isMoving = false;
		var pos = positionOf( row );
		var title = rowTitle( row );
		var handle = grabbedHandle;
		endGrab( row );
		if ( handle ) {
			handle.focus();
		}
		flushDeferredSave();
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
		grabSnapshot = null;
		grabKey = '';
	}

	/* ---- DOM movement helpers ------------------------------------------ */

	/**
	 * Move `row` and its whole subtree one sibling up/down, or to the first/last
	 * sibling. Returns false (and touches nothing) at the boundary.
	 *
	 * Keyboard moves are constrained to the row's siblings — rows with the same
	 * parent. Saving never changes post_parent, so moving a row under another
	 * parent would be a lie that the list table undoes on the next load. Every
	 * row is a sibling on a flat list, so there this is the plain one-row step.
	 */
	function moveBlock( row, dir ) {
		var parent = parentOf( row );
		var sibs = sortableRows().filter( function ( r ) {
			return parentOf( r ) === parent;
		} );
		var i = sibs.indexOf( row );
		var target;

		if ( dir === 'up' || dir === 'first' ) {
			target = dir === 'up' ? sibs[ i - 1 ] : sibs[ 0 ];
			if ( ! target || target === row ) {
				return false;
			}
			insertBlock( row, target );
			return true;
		}

		target = dir === 'down' ? sibs[ i + 1 ] : sibs[ sibs.length - 1 ];
		if ( ! target || target === row ) {
			return false;
		}
		insertBlock( row, afterBlock( target ) );
		return true;
	}

	/** Insert `row` + its subtree before `ref` (null = end of list). */
	function insertBlock( row, ref ) {
		var block = [ row ].concat( descendantsOf( row ) );
		while ( ref && block.indexOf( ref ) !== -1 ) {
			ref = ref.nextElementSibling;
		}
		for ( var i = 0; i < block.length; i++ ) {
			list.insertBefore( block[ i ], ref );
		}
	}

	/**
	 * The element after `row` and the subtree rows directly beneath it, also
	 * skipping an open Quick Edit row and WP's zebra-striping spacer so a moved
	 * block never lands inside them.
	 */
	function afterBlock( row ) {
		var next = row.nextElementSibling;
		while ( next ) {
			if ( isSortableRow( next ) ? ! isDescendant( next.id, row.id ) : ! isAttachedRow( next ) ) {
				break;
			}
			next = next.nextElementSibling;
		}
		return next;
	}

	/** Rows WordPress slips in after a row being Quick Edited. */
	function isAttachedRow( el ) {
		return el.classList.contains( 'inline-edit-row' ) || ( el.classList.contains( 'hidden' ) && ! el.id );
	}

	/* ---- Shared helpers ------------------------------------------------ */

	/**
	 * A real, orderable item row. Excludes the "no items" row, an open Quick
	 * Edit / Bulk Edit row (a clone with id `edit-<n>` / `bulk-edit`), and
	 * SortableJS's floating drag clone, which is appended to the list and keeps
	 * the dragged row's id — any of these used to end up in the saved order.
	 */
	function isSortableRow( row ) {
		return (
			row.nodeName === 'TR' &&
			row.parentNode === list &&
			/^[\w-]+-\d+$/.test( row.id ) &&
			row.id.indexOf( 'edit-' ) !== 0 &&
			row.id !== 'bulk-edit' &&
			! row.classList.contains( 'no-items' ) &&
			! row.classList.contains( 'inline-edit-row' ) &&
			! row.classList.contains( 'inline-editor' ) &&
			! row.classList.contains( 'scpo-fallback' ) &&
			! row.classList.contains( 'sortable-fallback' ) &&
			! row.classList.contains( 'sortable-drag' )
		);
	}

	function sortableRows() {
		return Array.prototype.filter.call( list.children, isSortableRow );
	}

	/** Cheap fingerprint of the current row order, for "did anything change?". */
	function orderKey() {
		return sortableRows()
			.map( function ( r ) {
				return r.id;
			} )
			.join( ',' );
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
			// Announce how to operate it on focus, before the first grab.
			btn.setAttribute( 'aria-describedby', instructionsId );
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
	 * their nonce/capability checks — work unchanged. Only real item rows,
	 * each id once: a duplicate or foreign id throws off the server's
	 * positional re-deal of the page's order values.
	 */
	function serializeOrder() {
		var rows = sortableRows();
		var pairs = [];
		var seen = {};

		for ( var i = 0; i < rows.length; i++ ) {
			var id = rows[ i ].id;          // e.g. "post-123" or "tag-45"
			if ( seen[ id ] ) {
				continue;
			}
			seen[ id ] = true;
			var sep = id.lastIndexOf( '-' );
			pairs.push(
				encodeURIComponent( id.slice( 0, sep ) ) + '[]=' + encodeURIComponent( id.slice( sep + 1 ) )
			);
		}

		return pairs.join( '&' );
	}

	var saving = false;      // a save request is currently in flight
	var pendingSave = false; // the list changed again before that request returned,
	                         // or a save is waiting for a drag / keyboard grab to end

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
		var raw = vars.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';
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
		// Mid-drag the list holds SortableJS's clone and a half-moved row;
		// mid-grab it holds an order the user may still cancel. Wait for either
		// to finish (flushDeferredSave()) rather than saving that snapshot.
		if ( isDragging || grabbed ) {
			pendingSave = true;
			return;
		}
		saving = true;
		pendingSave = false;
		showToast( strings.saving || 'Saving order…', 'saving' );
		postOrder( serializeOrder(), { netRetries: 1, nonceRefreshed: false } );
	}

	function flushDeferredSave() {
		if ( pendingSave && ! saving ) {
			saveOrder();
		}
	}

	/**
	 * POST to admin-ajax and resolve with { ok, text }. Rejects on a network
	 * error or when no response arrives within REQUEST_TIMEOUT — a hung request
	 * used to leave the toast on "Saving…" forever. The race works even where
	 * AbortController is missing; where it exists, the request is cancelled too.
	 */
	function request( body ) {
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
			req = fetch( ajaxEndpoint(), init );
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
	 * Read an admin-ajax reply that may be wrapped in stray output — a PHP
	 * notice or an echo from another plugin before the JSON made a save that
	 * succeeded look failed. Same approach as scporder-order-column.js.
	 */
	function parseReply( text ) {
		var json = null;
		try {
			json = JSON.parse( text );
		} catch ( e ) {
			// Try the wp_send_json() payload first, then any brace.
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
			// admin-ajax answers "-1" when the nonce is stale/invalid, possibly
			// after someone else's output.
			staleNonce: ! json && /-1\s*$/.test( text ),
		};
	}

	function postOrder( order, opts ) {
		// No nonce at all (the localized block was stripped): the refresh
		// endpoint doesn't need one, so fetch it just like an expired one.
		if ( ! vars.nonce && ! opts.nonceRefreshed ) {
			refreshNonce( function ( refreshed ) {
				if ( refreshed ) {
					postOrder( order, { netRetries: opts.netRetries, nonceRefreshed: true } );
				} else {
					finishSave( false );
				}
			} );
			return;
		}

		var body;
		try {
			body = new URLSearchParams();
			body.set( 'action', action );
			body.set( 'order', order );
			body.set( 'nonce', vars.nonce || '' );
			body = body.toString();
		} catch ( e ) {
			finishSave( false ); // never leave `saving` stuck
			return;
		}

		request( body )
			.then( function ( res ) {
				var r = parseReply( res.text );
				return {
					ok: !! ( res.ok && r.json && r.json.success ),
					staleNonce: r.staleNonce,
				};
			} )
			.then(
				function ( r ) {
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
				},
				function () {
					// Transient/network error or timeout: retry once, then give up.
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
				}
			)
			.catch( function () {
				// A throw inside the handlers above must not wedge the queue.
				if ( saving ) {
					finishSave( false );
				}
			} );
	}

	/**
	 * Fetch a fresh reorder nonce from the authenticated refresh endpoint and
	 * update it in place, so this save (and later ones) use a valid nonce.
	 */
	function refreshNonce( done ) {
		request( 'action=scpo_refresh_nonce' )
			.then( function ( res ) {
				var json = res.ok ? parseReply( res.text ).json : null;
				if ( json && json.success && json.data && json.data.nonce ) {
					vars.nonce = json.data.nonce;
					return true;
				}
				return false;
			} )
			.then(
				function ( ok ) {
					done( ok );
				},
				function () {
					done( false );
				}
			);
	}

	function finishSave( ok ) {
		saving = false;
		if ( ok && pendingSave ) {
			// Order changed again mid-flight — persist the latest (or wait for
			// the drag / grab in progress to end first).
			saveOrder();
			return;
		}
		pendingSave = false;
		if ( ok ) {
			syncOrderInputs();
			showToast( strings.saved || 'Order saved', 'saved' );
		} else {
			// The list now shows an order the server doesn't have. Keep the error
			// up with a Retry: every save sends the whole visible order, so one
			// successful retry (or the next drag) persists everything.
			showToast( strings.error || 'Couldn’t save — please try again', 'error', true );
		}
	}

	/**
	 * After a drag saves, the optional Order column still shows the old numbers.
	 * The server re-deals the page's existing set of values in the new row order
	 * (forcing duplicates to strictly increase), so do the same on screen. Both
	 * value and defaultValue move, so the column script sees no pending edit.
	 */
	function syncOrderInputs() {
		var cells = [];
		sortableRows().forEach( function ( row ) {
			var cell = row.querySelector( '.scpo-order-input, .scpo-order-static' );
			if ( cell ) {
				cells.push( cell );
			}
		} );
		if ( ! cells.length ) {
			return;
		}
		var values = cells.map( function ( cell ) {
			return parseInt( 'INPUT' === cell.nodeName ? cell.defaultValue : cell.textContent, 10 );
		} );
		for ( var i = 0; i < values.length; i++ ) {
			if ( isNaN( values[ i ] ) ) {
				return; // something else owns this column's content — leave it be
			}
		}
		values.sort( function ( a, b ) {
			return a - b;
		} );
		for ( var j = 1; j < values.length; j++ ) {
			if ( values[ j ] <= values[ j - 1 ] ) {
				values[ j ] = values[ j - 1 ] + 1;
			}
		}
		cells.forEach( function ( cell, k ) {
			var v = String( values[ k ] );
			if ( 'INPUT' === cell.nodeName ) {
				cell.defaultValue = v;
				cell.value = v;
			} else {
				cell.textContent = v;
			}
		} );
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
	function showToast( message, state, withRetry ) {
		clearTimeout( hideTimer );
		toast.textContent = '';
		var msg = document.createElement( 'span' );
		msg.className = 'scpo-toast__message';
		msg.textContent = message;
		toast.appendChild( msg );
		toast.className = 'scpo-toast scpo-toast--' + state + ' is-visible';

		if ( withRetry ) {
			// Stays up until the user retries, dismisses, or the next save runs.
			// Either button removes itself from the page, so focus goes back to
			// where the user was (usually the row's handle) rather than being
			// stranded on a removed or hidden button.
			var returnFocus = document.activeElement;
			var refocus = function () {
				if ( returnFocus && returnFocus !== document.body && document.body.contains( returnFocus ) && returnFocus.focus ) {
					returnFocus.focus();
				}
			};
			toast.appendChild(
				toastButton( strings.retry || 'Retry', 'scpo-toast__retry', function () {
					saveOrder();
					refocus();
				} )
			);
			var close = toastButton( '×', 'scpo-toast__dismiss', function () {
				toast.classList.remove( 'is-visible' );
				toast.textContent = '';
				refocus();
			} );
			close.setAttribute( 'aria-label', strings.dismiss || 'Dismiss' );
			toast.appendChild( close );
			return;
		}

		if ( state !== 'saving' ) {
			hideTimer = setTimeout( function () {
				toast.classList.remove( 'is-visible' );
			}, 2000 );
		}
	}

	function toastButton( label, className, onClick ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = className;
		btn.textContent = label;
		btn.addEventListener( 'click', onClick );
		return btn;
	}

	function createLiveRegion() {
		var el = document.createElement( 'div' );
		el.className = 'scpo-sr-only';
		el.setAttribute( 'aria-live', 'assertive' );
		el.setAttribute( 'aria-atomic', 'true' );
		document.body.appendChild( el );
		return el;
	}

	/** Visually hidden "how to use the handle" text, referenced by every handle. */
	function createInstructions() {
		var el = document.createElement( 'div' );
		el.id = 'scpo-reorder-instructions';
		el.className = 'scpo-sr-only';
		el.textContent =
			strings.instructions ||
			'Press Space or Enter to grab. Use the arrow keys, Home and End to move, Space or Enter to drop, Escape to cancel.';
		document.body.appendChild( el );
		return el.id;
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
