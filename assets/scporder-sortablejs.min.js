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
 *                     In a page/category tree, Up/Down stay among the row's
 *                     siblings (see moveBlock()); Right/Left nest the row under
 *                     the sibling above it / move it back out (see nest()).
 *
 * Changing the parent (hierarchical lists only, when `allowReparent` is set):
 *   - Mouse / touch : drag a row sideways while dragging it. Each INDENT px
 *                     is one level; the row's dashes preview where it will
 *                     land, and only positions that keep the tree valid are
 *                     offered (see parentChain()).
 *   - Keyboard      : Right / Left Arrow while a row is grabbed.
 * The new parent travels with the order in the same request (`parents[id]`).
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

	// refresh() numbers the list in the order WordPress renders it, just before
	// it renders, so the saved values rise down the page. When they don't, a
	// theme or another plugin sorted this list itself (e.g. a pre_get_posts hook
	// forcing date order): saving would make that order the custom order, and
	// the list would still look unchanged after a reload.
	if ( ! shownInSavedOrder( sortableRows() ) ) {
		showOrderNotice( strings.notInOrder || 'Drag and drop is turned off here: this list is not shown in its custom order, so your theme or another plugin is sorting it. Saving would replace the custom order with that order. Remove the code that sorts this admin list (for example a pre_get_posts or get_terms filter) to reorder again.' );
		return;
	}

	var REQUEST_TIMEOUT = 20000; // ms before a hung save counts as a network failure

	var draggedKids = [];   // subtree travelling with the row currently being dragged
	var dragStartKey = '';  // row order when the mouse drag began
	var isDragging = false; // a SortableJS drag is in progress

	// Changing the parent. `allowReparent` is decided by PHP: the setting is on
	// and this is a hierarchical list (pages, categories, ...).
	var allowReparent = !! vars.allowReparent;
	var INDENT = 28;        // px of sideways drag per nesting level
	var isRtl = document.documentElement.dir === 'rtl' || document.body.classList.contains( 'rtl' );

	var pendingMoves = {};  // row id -> new parent row id (null = top level), not yet confirmed saved
	var inFlightMoves = {}; // the moves the request currently in flight carries
	var downX = 0;          // pointer x when the press began
	var pointerX = 0;       // latest pointer x
	var dragRow = null;     // the row being dragged with the mouse / finger
	var dragOrigin = null;  // { parent, level } of that row when the drag began
	var previewQueued = false;

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
	 * its next drag. The map is kept true afterwards by commitParent(), the one
	 * place a row's parent changes. Keyed by id, not element, so a row whose
	 * HTML Quick Edit replaces keeps its place in the tree.
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

	/* ---- Changing the parent ------------------------------------------- */

	/** "post-12" / "tag-45" -> "12" / "45". */
	function numericId( id ) {
		return id.slice( id.lastIndexOf( '-' ) + 1 );
	}

	function rowById( id ) {
		var rows = sortableRows();
		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].id === id ) {
				return rows[ i ];
			}
		}
		return null;
	}

	/** Nesting level the tree records for a row id (0 when unknown). */
	function levelById( id ) {
		var node = tree[ id ];
		return node && null !== node.level ? node.level : 0;
	}

	function levelOf( row ) {
		parentOf( row ); // registers a row the tree has not seen yet
		return levelById( row.id );
	}

	/**
	 * Show a row at `level`: its `level-N` class and the "— " dashes WordPress
	 * prints in front of the title. DOM only — the tree is updated by
	 * commitParent(), so a mouse drag can preview a level and take it back.
	 */
	function paintLevel( row, level ) {
		var from = rowLevel( row );
		if ( null === from ) {
			from = 0;
		}
		if ( from === level ) {
			return;
		}
		if ( /(?:^|\s)level-\d+(?:\s|$)/.test( row.className ) ) {
			row.className = row.className.replace( /(^|\s)level-\d+(?=\s|$)/, '$1level-' + level );
		} else {
			row.classList.add( 'level-' + level );
		}
		var title = row.querySelector( '.row-title' );
		var text = title ? title.firstChild : null;
		if ( text && 3 === text.nodeType ) {
			var rest = text.nodeValue.replace( new RegExp( '^(?:\u2014\\s){0,' + from + '}' ), '' );
			text.nodeValue = new Array( level + 1 ).join( '\u2014 ' ) + rest;
		}
	}

	/** Keep the hidden Quick Edit data in step, so its Parent field is right. */
	function setDeclaredParent( row, parentId ) {
		var el = row.querySelector( '.hidden .post_parent, .hidden .parent' );
		if ( el ) {
			el.textContent = parentId ? numericId( parentId ) : '0';
		}
	}

	/**
	 * Give `row` a new parent at `level`: the tree, the row's own markup and
	 * every row nested under it (they keep their parent and move with it).
	 */
	function commitParent( row, parentId, level ) {
		parentOf( row ); // make sure the tree knows the row
		var node = tree[ row.id ];
		var delta = level - ( null === node.level ? 0 : node.level );

		node.parent = parentId;
		node.level = level;
		paintLevel( row, level );
		setDeclaredParent( row, parentId );

		if ( delta ) {
			descendantsOf( row ).forEach( function ( kid ) {
				var kidNode = tree[ kid.id ];
				if ( kidNode ) {
					kidNode.level = ( null === kidNode.level ? 0 : kidNode.level ) + delta;
					paintLevel( kid, kidNode.level );
				}
			} );
		}
	}

	/**
	 * Every parent `row` (with its subtree) may have at its current position in
	 * the list, deepest first; `null` stands for the top level.
	 *
	 * The list is one flat, depth-first run of rows, so the tree stays valid only
	 * if the block lands under the row above it or under one of that row's
	 * ancestors — and not above the row below's own parent, or that row would
	 * silently become the block's child. That chain is what is offered.
	 */
	function parentChain( row ) {
		var block = {};
		block[ row.id ] = true;
		descendantsOf( row ).forEach( function ( kid ) {
			block[ kid.id ] = true;
		} );

		var rows = sortableRows();
		var index = rows.indexOf( row );
		var prev = null;
		var next = null;
		var i;
		for ( i = index - 1; i >= 0; i-- ) {
			if ( ! block[ rows[ i ].id ] ) {
				prev = rows[ i ];
				break;
			}
		}
		for ( i = index + 1; i < rows.length; i++ ) {
			if ( ! block[ rows[ i ].id ] ) {
				next = rows[ i ];
				break;
			}
		}

		var stopAt = next ? parentOf( next ) : null;
		var chain = [];
		var cur = prev ? prev.id : null;
		var guard = 0;
		while ( guard++ < 100 ) {
			chain.push( cur );
			if ( cur === stopAt || null === cur ) {
				break;
			}
			cur = tree[ cur ] ? tree[ cur ].parent : null;
		}
		return chain;
	}

	/**
	 * Where the dragged row would land right now: its parent and nesting level.
	 *
	 * Sideways travel since the press picks the level (the starting level plus
	 * one per INDENT px), clamped to what parentChain() allows. With no real
	 * sideways travel the row keeps its parent whenever that is still possible,
	 * so an ordinary up/down reorder never re-parents anything by accident.
	 */
	function targetFor( row ) {
		var chain = parentChain( row );
		var steps = Math.round( ( pointerX - downX ) * ( isRtl ? -1 : 1 ) / INDENT );
		var best = chain[ 0 ];

		if ( 0 === steps && chain.indexOf( dragOrigin.parent ) !== -1 ) {
			best = dragOrigin.parent;
		} else {
			var want = dragOrigin.level + steps;
			var bestDiff = Infinity;
			for ( var i = 0; i < chain.length; i++ ) {
				var level = null === chain[ i ] ? 0 : levelById( chain[ i ] ) + 1;
				if ( Math.abs( level - want ) < bestDiff ) {
					bestDiff = Math.abs( level - want );
					best = chain[ i ];
				}
			}
		}

		return { parent: best, level: null === best ? 0 : levelById( best ) + 1 };
	}

	function scheduleDepthPreview() {
		if ( ! allowReparent || ! isDragging || previewQueued ) {
			return;
		}
		previewQueued = true;
		if ( typeof window.requestAnimationFrame === 'function' ) {
			window.requestAnimationFrame( previewDepth );
		} else {
			setTimeout( previewDepth, 16 );
		}
	}

	/** Show the level the dragged row would get, on the row left in the list and on the one under the pointer. */
	function previewDepth() {
		previewQueued = false;
		if ( ! isDragging || ! dragRow || ! dragOrigin ) {
			return;
		}
		var target = targetFor( dragRow );
		paintLevel( dragRow, target.level );
		var clone = list.querySelector( '.scpo-fallback' );
		if ( clone ) {
			paintLevel( clone, target.level );
		}
		dragRow.classList.toggle( 'scpo-depth-changed', target.parent !== dragOrigin.parent );
	}

	function pointX( evt ) {
		var point = evt.touches && evt.touches[ 0 ] ? evt.touches[ 0 ] : evt;
		return typeof point.clientX === 'number' ? point.clientX : null;
	}

	if ( allowReparent ) {
		[ 'pointerdown', 'mousedown', 'touchstart' ].forEach( function ( name ) {
			document.addEventListener(
				name,
				function ( evt ) {
					var x = pointX( evt );
					if ( null !== x ) {
						downX = x;
						pointerX = x;
					}
				},
				{ capture: true, passive: true }
			);
		} );
		[ 'pointermove', 'mousemove', 'touchmove' ].forEach( function ( name ) {
			document.addEventListener(
				name,
				function ( evt ) {
					var x = pointX( evt );
					if ( null !== x ) {
						pointerX = x;
						scheduleDepthPreview();
					}
				},
				{ capture: true, passive: true }
			);
		} );
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
					// The row under the pointer shows a previewed level that the
					// tree doesn't have (yet) — don't read that back as a change.
					if ( row === dragRow ) {
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
				// Quick Edit and newly added terms refresh the clean list's baseline.
				if ( ! unsavedOrder && ! saving && ! pendingSave && ! isDragging && ! grabbed ) {
					savedOrder = serializeOrder();
					savedValues = readOrderValues();
				}
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
			if ( allowReparent ) {
				dragRow = evt.item;
				dragOrigin = { parent: parentOf( evt.item ), level: levelOf( evt.item ) };
			}
		},
		onUnchoose: function ( evt ) {
			unlockRowWidths( evt.item );
			if ( ! isDragging ) {
				// Pressed but never dragged.
				dragRow = null;
				dragOrigin = null;
			}
			isDragging = false; // belt and braces: never leave saves deferred forever
		},
		onStart: function () {
			isDragging = true;
		},
		onChange: function () {
			scheduleDepthPreview(); // the row moved to a new spot: its allowed levels changed
		},
		onEnd: function ( evt ) {
			isDragging = false;
			var row = evt.item;

			// Where the row landed, and under which parent — worked out before
			// the children are brought along (it only looks at rows outside
			// the dragged block, so the result is the same either way).
			var target = dragOrigin ? targetFor( row ) : null;

			// Bring the children along before reading the order back out of
			// the DOM, so the request matches what the user is looking at.
			reattach( row, draggedKids );
			draggedKids = [];

			var reparented = false;
			if ( target ) {
				row.classList.remove( 'scpo-depth-changed' );
				if ( target.parent !== dragOrigin.parent ) {
					commitParent( row, target.parent, target.level );
					pendingMoves[ row.id ] = target.parent;
					reparented = true;
				} else {
					paintLevel( row, levelOf( row ) ); // take the preview back
				}
			}
			dragRow = null;
			dragOrigin = null;

			// Compare the whole order, not old/new index: dropping a parent
			// inside its own subtree snaps back to where it started.
			if ( reparented || orderKey() !== dragStartKey ) {
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
	var grabOrigin = null;    // { parent, level } of the grabbed row at grab time

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
			case 'ArrowRight':
			case 'ArrowLeft':
				if ( allowReparent ) {
					evt.preventDefault();
					// "Deeper" is toward the end of the line: right in LTR, left in RTL.
					nest( ( 'ArrowRight' === key ) !== isRtl ? 'in' : 'out', row, handle );
				}
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
		grabOrigin = allowReparent ? { parent: parentOf( row ), level: levelOf( row ) } : null;
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

	/**
	 * Right / Left Arrow on a grabbed row: nest it under the sibling above it
	 * (`in`), or move it back out to sit after its parent (`out`).
	 */
	function nest( dir, row, handle ) {
		isMoving = true;
		var ok = 'in' === dir ? indentBlock( row ) : outdentBlock( row );
		if ( ok ) {
			handle.focus();
			if ( row.scrollIntoView ) {
				row.scrollIntoView( { block: 'nearest' } );
			}
		}
		isMoving = false;

		if ( ! ok ) {
			announce(
				format(
					( 'in' === dir ? strings.cantNest : strings.cantUnnest ) ||
						( 'in' === dir ? '%1$s has no item above it to nest under.' : '%1$s is already at the top level.' ),
					rowTitle( row )
				)
			);
			return;
		}

		var parentId = parentOf( row );
		var parentRow = parentId ? rowById( parentId ) : null;
		announce(
			parentRow
				? format( strings.movedUnder || '%1$s is now a child of %2$s.', rowTitle( row ), rowTitle( parentRow ) )
				: format( strings.movedTop || '%1$s is now at the top level.', rowTitle( row ) )
		);
	}

	function siblingsOf( row ) {
		var parent = parentOf( row );
		return sortableRows().filter( function ( r ) {
			return parentOf( r ) === parent;
		} );
	}

	/** Become the last child of the sibling directly above. False when there is none. */
	function indentBlock( row ) {
		var sibs = siblingsOf( row );
		var newParent = sibs[ sibs.indexOf( row ) - 1 ];
		if ( ! newParent ) {
			return false;
		}
		// Normally already in place (the row follows its sibling's subtree);
		// this just guarantees it.
		insertBlock( row, afterBlock( newParent ) );
		commitParent( row, newParent.id, levelOf( newParent ) + 1 );
		return true;
	}

	/** Become the next sibling of the current parent, after the parent's whole subtree. */
	function outdentBlock( row ) {
		var parentId = parentOf( row );
		var parentRow = parentId ? rowById( parentId ) : null;
		if ( ! parentRow ) {
			return false;
		}
		var grandparent = parentOf( parentRow );
		insertBlock( row, afterBlock( parentRow ) );
		commitParent( row, grandparent, levelOf( parentRow ) );
		return true;
	}

	function drop( row ) {
		var pos = positionOf( row );
		var title = rowTitle( row );
		// Up-then-down is not a change; only save if the order really differs.
		var changed = orderKey() !== grabKey;
		if ( grabOrigin && parentOf( row ) !== grabOrigin.parent ) {
			pendingMoves[ row.id ] = parentOf( row );
			changed = true;
		}
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
		if ( grabOrigin ) {
			commitParent( row, grabOrigin.parent, grabOrigin.level ); // and take any nesting back
		}
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
		grabOrigin = null;
	}

	/* ---- DOM movement helpers ------------------------------------------ */

	/**
	 * Move `row` and its whole subtree one sibling up/down, or to the first/last
	 * sibling. Returns false (and touches nothing) at the boundary.
	 *
	 * Up/Down are constrained to the row's siblings — rows with the same
	 * parent — so they never change the hierarchy by accident; changing the
	 * parent is the explicit Right / Left Arrow (see nest()). Every row is a
	 * sibling on a flat list, so there this is the plain one-row step.
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

	/** Whether the rows' saved values rise in display order; true when unknown. */
	function shownInSavedOrder( rows ) {
		var previous = null;
		for ( var i = 0; i < rows.length; i++ ) {
			var marker = rows[ i ].querySelector( '.scpo-order-snapshot' );
			var raw = marker && marker.getAttribute( 'data-value' );
			if ( ! /^-?\d+$/.test( raw || '' ) ) {
				return true;
			}
			if ( null !== previous && Number( raw ) < previous ) {
				return false;
			}
			previous = Number( raw );
		}
		return true;
	}

	function showOrderNotice( message ) {
		var table = list.parentNode;
		var notice = document.createElement( 'div' );
		notice.className = 'notice notice-warning inline scpo-order-notice';
		var p = document.createElement( 'p' );
		p.textContent = message;
		notice.appendChild( p );
		table.parentNode.insertBefore( notice, table );
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
			return plainTitle( t.textContent );
		}
		var cell = firstDataCell( row );
		return cell ? plainTitle( cell.textContent ) : row.id;
	}

	/** A title without the "— — " nesting dashes WordPress puts in front of it. */
	function plainTitle( text ) {
		return text.trim().replace( /^(?:\u2014\s*)+/, '' );
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

	// A value snapshot is independent of how a theme/plugin arranged the rows.
	// Older server markup keeps using the legacy order-only protocol.
	function readOrderValues() {
		var values = {};
		var rows = sortableRows();
		for ( var i = 0; i < rows.length; i++ ) {
			var marker = rows[ i ].querySelector( '.scpo-order-snapshot' );
			if ( ! marker ) { return null; }
			var raw = marker.getAttribute( 'data-value' );
			if ( raw !== 'null' && ! /^-?\d+$/.test( raw || '' ) ) { return null; }
			values[ rows[ i ].id.replace( /^.*-/, '' ) ] = raw === 'null' ? null : Number( raw );
		}
		return rows.length ? values : null;
	}

	function acceptOrderValues( values ) {
		savedValues = values && typeof values === 'object' ? values : null;
		if ( ! savedValues ) { return; }
		sortableRows().forEach( function ( row ) {
			var id = row.id.replace( /^.*-/, '' );
			var marker = row.querySelector( '.scpo-order-snapshot' );
			if ( marker && Object.prototype.hasOwnProperty.call( savedValues, id ) ) {
				marker.setAttribute( 'data-value', String( savedValues[ id ] ) );
			}
		} );
	}

	var savedOrder = serializeOrder();
	var savedValues = readOrderValues();
	var unsavedOrder = false;
	function warnBeforeLeaving( event ) {
		event.preventDefault();
		event.returnValue = '';
	}
	function markUnsaved( dirty ) {
		if ( dirty === unsavedOrder ) { return; }
		unsavedOrder = dirty;
		if ( dirty ) {
			window.addEventListener( 'beforeunload', warnBeforeLeaving );
		} else {
			window.removeEventListener( 'beforeunload', warnBeforeLeaving );
		}
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
		markUnsaved( true );
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
		inFlightMoves = {};
		Object.keys( pendingMoves ).forEach( function ( id ) {
			inFlightMoves[ id ] = pendingMoves[ id ];
		} );
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
			body.set( 'original_order', savedOrder );
			if ( savedValues ) { body.set( 'original_values', JSON.stringify( savedValues ) ); }
			body.set( 'nonce', vars.nonce || '' );
			// New parents ride along with the order: parents[<id>]=<parent id|0>.
			Object.keys( inFlightMoves ).forEach( function ( id ) {
				var parentId = inFlightMoves[ id ];
				body.set( 'parents[' + numericId( id ) + ']', parentId ? numericId( parentId ) : '0' );
			} );
			body = body.toString();
		} catch ( e ) {
			finishSave( false ); // never leave `saving` stuck
			return;
		}

		request( body )
			.then( function ( res ) {
				var r = parseReply( res.text );
				var data = r.json && r.json.data && 'object' === typeof r.json.data ? r.json.data : {};
				return {
					ok: !! ( res.ok && r.json && r.json.success ),
					values: r.json && r.json.data && r.json.data.order_values,
					staleNonce: r.staleNonce,
					reload: !! ( r.json && r.json.data && r.json.data.reload ),
					message: r.json && r.json.data && typeof r.json.data.message === 'string' ? r.json.data.message : '',
					code: 'string' === typeof data.code ? data.code : '',
				};
			} )
			.then(
				function ( r ) {
					if ( r.ok ) {
						savedOrder = order;
						acceptOrderValues( r.values );
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
					// Surface the server's recovery instructions without exposing HTML.
					finishSave( false, r.message, r.reload, r.code );
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

	function finishSave( ok, message, reload, code ) {
		saving = false;
		if ( ok ) {
			// Moves the server has applied (and that weren't changed again while
			// the request was in flight) no longer need sending.
			Object.keys( inFlightMoves ).forEach( function ( id ) {
				if ( pendingMoves[ id ] === inFlightMoves[ id ] ) {
					delete pendingMoves[ id ];
				}
			} );
		}
		inFlightMoves = {};
		if ( ok && pendingSave ) {
			// Order changed again mid-flight — persist the latest (or wait for
			// the drag / grab in progress to end first).
			saveOrder();
			return;
		}
		pendingSave = false;
		if ( ok ) {
			markUnsaved( false );
			syncOrderInputs();
			showToast( strings.saved || 'Order saved', 'saved' );
		} else if ( 'parent' === code || reload ) {
			// The server refused the new parent (e.g. a category of that name
			// already exists there), or says the list is stale. The list now shows
			// a hierarchy or order that isn't stored, and sending the same request
			// again can't help: forget the pending moves and offer a reload.
			pendingMoves = {};
			showToast( message || ( 'parent' === code ? strings.parentFailed : strings.error ) || 'Couldn’t save — please reload the list', 'error', true, true );
		} else {
			// The list now shows an order the server doesn't have. Keep the error
			// up with a Retry: every save sends the whole visible order, so one
			// successful retry (or the next drag) persists everything.
			showToast( message || strings.error || 'Couldn’t save — please try again', 'error', true, reload );
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
	function showToast( message, state, withRetry, reload ) {
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
				toastButton( reload ? ( strings.reload || 'Reload list' ) : ( strings.retry || 'Retry' ), 'scpo-toast__retry', function () {
					if ( reload ) { markUnsaved( false ); window.location.reload(); return; }
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
			( strings.instructions ||
				'Press Space or Enter to grab. Use the arrow keys, Home and End to move, Space or Enter to drop, Escape to cancel.' ) +
			( allowReparent ? ' ' + ( strings.instructionsNest || 'Use the Left and Right arrow keys to nest or un-nest the item.' ) : '' );
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
