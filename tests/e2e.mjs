// Real-browser end-to-end tests against a dev site (headless Chrome via puppeteer-core).
import puppeteer from 'puppeteer-core';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

// Config (environment): SCPO_WP = wp-cli command (default "wp"), SCPO_SITE = site URL,
// SCPO_COOKIE = admin cookie file (see make-cookie.php), SCPO_MU_DIR = the site's
// wp-content/mu-plugins directory, CHROME = path to a Chrome/Chromium binary.
// Needs `npm i puppeteer-core` somewhere on the module path. Makes real saves:
// snapshot the site first (snapshot.php) and restore it afterwards.
const SITE = process.env.SCPO_SITE || 'http://localhost';
const B = SITE + '/wp-admin';
const MU = ( process.env.SCPO_MU_DIR || '' ) + '/scpo-e2e-stray-output.php';
const WP_CMD = ( process.env.SCPO_WP || 'wp' ).split( ' ' );
const COOKIE_FILE = process.env.SCPO_COOKIE || new URL( './.cookie', import.meta.url ).pathname;
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

let pass = 0, fail = 0;
const failed = [];
const ok = ( name, cond, detail = '' ) => {
	if ( cond ) { pass++; console.log( '  PASS  ' + name ); }
	else { fail++; failed.push( name ); console.log( '  FAIL  ' + name + ( detail ? '  -- ' + detail : '' ) ); }
};
const wp = ( code ) => execFileSync( WP_CMD[ 0 ], [ ...WP_CMD.slice( 1 ), 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim().split( '\n' ).pop();
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const cookieStr = fs.readFileSync( COOKIE_FILE, 'utf8' ).trim();
const cookies = cookieStr.split( '; ' ).map( ( kv ) => {
	const i = kv.indexOf( '=' );
	return { name: kv.slice( 0, i ), value: kv.slice( i + 1 ), domain: new URL( SITE ).hostname, path: '/' };
} );

const browser = await puppeteer.launch( {
	executablePath: CHROME,
	headless: true,
	args: [ '--window-size=1400,1000' ],
	defaultViewport: { width: 1400, height: 1000 },
} );
const page = await browser.newPage();
await page.setCookie( ...cookies );

const jsErrors = [];
page.on( 'pageerror', ( e ) => jsErrors.push( e.message ) );
page.on( 'console', ( m ) => { if ( m.type() === 'error' && ! /favicon|net::ERR|Failed to load resource/.test( m.text() ) ) jsErrors.push( m.text() ); } );

const ajax = [];
page.on( 'request', ( r ) => {
	if ( r.url().includes( 'admin-ajax.php' ) && r.method() === 'POST' ) {
		const p = new URLSearchParams( r.postData() || '' );
		ajax.push( { action: p.get( 'action' ), order: p.get( 'order' ), nonce: p.get( 'nonce' ), body: r.postData() } );
	}
} );

const rowIds = () => page.$$eval( '#the-list > tr[id]', ( rs ) => rs.filter( ( r ) => ! r.classList.contains( 'inline-edit-row' ) && r.style.display !== 'none' ).map( ( r ) => r.id ) );
const waitToast = async ( state, timeout = 8000 ) => {
	try {
		await page.waitForSelector( '.scpo-toast--' + state + '.is-visible', { timeout } );
		return true;
	} catch ( e ) { return false; }
};
const idNum = ( id ) => parseInt( id.replace( /\D+/g, '' ), 10 );
const dbMenuOrders = ( ids ) => JSON.parse( wp( `global $wpdb; echo json_encode(array_map("intval", $wpdb->get_col("SELECT menu_order FROM $wpdb->posts WHERE ID IN (${ ids.join( ',' ) }) ORDER BY FIELD(ID,${ ids.join( ',' ) })")));` ) );
const dbTermOrders = ( ids ) => JSON.parse( wp( `global $wpdb; echo json_encode(array_map("intval", $wpdb->get_col("SELECT term_order FROM $wpdb->terms WHERE term_id IN (${ ids.join( ',' ) }) ORDER BY FIELD(term_id,${ ids.join( ',' ) })")));` ) );
const increasing = ( a ) => a.every( ( v, i ) => i === 0 || v > a[ i - 1 ] );

async function keyboardMove( rowId, keys ) {
	await page.focus( `#${ rowId } .scpo-handle` );
	await page.keyboard.press( 'Space' );
	for ( const k of keys ) { await page.keyboard.press( k ); await sleep( 60 ); }
	await page.keyboard.press( 'Space' );
}

async function mouseDrag( fromSel, toSel, above = true ) {
	await page.hover( fromSel );
	const from = await ( await page.$( fromSel ) ).boundingBox();
	const to = await ( await page.$( toSel ) ).boundingBox();
	const sx = from.x + from.width / 2, sy = from.y + from.height / 2;
	const ty = above ? to.y + 3 : to.y + to.height - 3;
	await page.mouse.move( sx, sy );
	await page.mouse.down();
	for ( let i = 1; i <= 15; i++ ) { await page.mouse.move( sx, sy + ( ty - sy ) * i / 15 ); await sleep( 25 ); }
	await sleep( 200 );
	await page.mouse.up();
}

try {
	/* ---------------- Posts: mouse drag ---------------- */
	console.log( '\n== Posts list: mouse drag (Modern engine) ==' );
	await page.goto( B + '/edit.php', { waitUntil: 'networkidle2' } );
	ok( 'handles injected on Posts list', ( await page.$$( '#the-list .scpo-handle' ) ).length >= 20 );
	ok( 'handles are described by the instructions', 'scpo-reorder-instructions' === await page.$eval( '#the-list .scpo-handle', ( b ) => b.getAttribute( 'aria-describedby' ) ) );
	let before = await rowIds();
	ajax.length = 0;
	await mouseDrag( `#${ before[ 3 ] } .scpo-handle`, `#${ before[ 0 ] }`, true );
	ok( 'mouse drag shows "Order saved"', await waitToast( 'saved' ) );
	let after = await rowIds();
	ok( 'dragged row moved to the top', after[ 0 ] === before[ 3 ], after.slice( 0, 4 ).join( ',' ) );
	const saves = ajax.filter( ( a ) => a.action === 'update-menu-order' );
	ok( 'exactly one save request', saves.length === 1, String( saves.length ) );
	const first = after.slice( 0, 6 ).map( idNum );
	ok( 'DB order matches the new DOM order', increasing( dbMenuOrders( first ) ), JSON.stringify( dbMenuOrders( first ) ) );
	const inputs = await page.$$eval( '#the-list > tr[id] .scpo-order-input', ( is ) => is.slice( 0, 6 ).map( ( i ) => +i.value ) );
	ok( 'Order column numbers follow the drag', increasing( inputs ) && JSON.stringify( inputs ) === JSON.stringify( dbMenuOrders( first ) ), JSON.stringify( inputs ) );
	await page.reload( { waitUntil: 'networkidle2' } );
	ok( 'order survives reload', JSON.stringify( ( await rowIds() ).slice( 0, 6 ) ) === JSON.stringify( after.slice( 0, 6 ) ) );

	/* ---------------- Order column input ---------------- */
	console.log( '\n== Order column input ==' );
	before = await rowIds();
	ajax.length = 0;
	const target = before[ 8 ];
	await page.click( `#${ target } .scpo-order-input`, { clickCount: 3 } );
	await page.keyboard.type( '2' );
	const nav = page.waitForNavigation( { waitUntil: 'networkidle2', timeout: 8000 } ).catch( () => null );
	await page.keyboard.press( 'Enter' );
	await page.click( '#wpbody h1, .wp-heading-inline' ).catch( () => {} ); // blur
	await nav;
	ok( 'Enter + blur sends one set_position request', ajax.filter( ( a ) => a.action === 'scpo_set_position' ).length === 1, String( ajax.filter( ( a ) => a.action === 'scpo_set_position' ).length ) );
	ok( 'item is now at position 2', 2 === dbMenuOrders( [ idNum( target ) ] )[ 0 ] );
	ok( 'row shows at position 2 after reload', ( await rowIds() )[ 1 ] === target );
	await page.click( `#${ ( await rowIds() )[ 4 ] } .scpo-order-input`, { clickCount: 3 } );
	ajax.length = 0;
	await page.keyboard.type( '0' );
	await page.keyboard.press( 'Tab' );
	await sleep( 400 );
	ok( 'invalid position 0 is not sent', ajax.filter( ( a ) => a.action === 'scpo_set_position' ).length === 0 );

	/* ---------------- Pages tree: keyboard ---------------- */
	console.log( '\n== Pages tree: keyboard ==' );
	let tree = [], pIdx = -1, pagesUrl = '';
	for ( let pg = 1; pg <= 12 && pIdx < 0; pg++ ) {
		pagesUrl = B + '/edit.php?post_type=page&paged=' + pg;
		await page.goto( pagesUrl, { waitUntil: 'networkidle2' } );
		tree = await page.$$eval( '#the-list > tr[id]', ( rs ) => rs.map( ( r ) => ( { id: r.id, level: +( ( r.className.match( /level-(\d+)/ ) || [ 0, 0 ] )[ 1 ] ) } ) ) );
		// a level-0 parent with children followed (later) by another level-0 sibling
		pIdx = tree.findIndex( ( r, i ) => r.level === 0 && tree[ i + 1 ] && tree[ i + 1 ].level === 1 && tree.slice( i + 1 ).some( ( x ) => x.level === 0 ) );
	}
	console.log( '  (using ' + pagesUrl + ')' );
	if ( pIdx < 0 ) {
		ok( 'found a parent page with children on page 1', false );
	} else {
		const parent = tree[ pIdx ];
		let end = pIdx + 1;
		while ( end < tree.length && tree[ end ].level > 0 ) end++;
		const block = tree.slice( pIdx, end ).map( ( r ) => r.id );
		let sEnd = end + 1;
		while ( sEnd < tree.length && tree[ sEnd ].level > 0 ) sEnd++;
		const sibBlock = tree.slice( end, sEnd ).map( ( r ) => r.id );
		const parentsBefore = JSON.parse( wp( `global $wpdb; echo json_encode($wpdb->get_results("SELECT ID, post_parent FROM $wpdb->posts WHERE post_type='page'", OBJECT_K));` ) );

		// Escape restores exactly.
		const snapshot = await rowIds();
		ajax.length = 0;
		await page.focus( `#${ parent.id } .scpo-handle` );
		await page.keyboard.press( 'Space' );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'Escape' );
		await sleep( 300 );
		ok( 'Escape restores the exact original order', JSON.stringify( await rowIds() ) === JSON.stringify( snapshot ) );
		ok( 'Escape sends no save', ajax.filter( ( a ) => a.action === 'update-menu-order' ).length === 0 );
		ok( 'focus returns to the handle after Escape', await page.evaluate( ( id ) => document.activeElement === document.querySelector( '#' + id + ' .scpo-handle' ), parent.id ) );

		// ArrowDown moves the whole subtree past the next sibling's subtree.
		ajax.length = 0;
		await keyboardMove( parent.id, [ 'ArrowDown' ] );
		ok( 'keyboard move shows "Order saved"', await waitToast( 'saved' ) );
		const now = await rowIds();
		const expect = snapshot.slice( 0, pIdx ).concat( sibBlock, block, snapshot.slice( sEnd ) );
		ok( 'parent + children moved below the next sibling subtree', JSON.stringify( now ) === JSON.stringify( expect ), `block=${ block.length } sib=${ sibBlock.length }` );
		const parentsAfter = JSON.parse( wp( `global $wpdb; echo json_encode($wpdb->get_results("SELECT ID, post_parent FROM $wpdb->posts WHERE post_type='page'", OBJECT_K));` ) );
		ok( 'no post_parent changed', JSON.stringify( parentsAfter ) === JSON.stringify( parentsBefore ) );
		await page.reload( { waitUntil: 'networkidle2' } );
		ok( 'tree renders in the same order after reload', JSON.stringify( await rowIds() ) === JSON.stringify( expect ) );

		// Child at its sibling boundary: no move, no save.
		const kids = await page.$$eval( '#the-list > tr.level-1', ( rs ) => rs.map( ( r ) => r.id ) );
		ajax.length = 0;
		const snap2 = await rowIds();
		const firstChild = ( await page.$$eval( '#the-list > tr[id]', ( rs ) => {
			for ( let i = 1; i < rs.length; i++ ) {
				if ( rs[ i ].classList.contains( 'level-1' ) && rs[ i - 1 ].classList.contains( 'level-0' ) ) return rs[ i ].id;
			}
			return null;
		} ) );
		await keyboardMove( firstChild, [ 'ArrowUp' ] );
		await sleep( 500 );
		ok( 'first child cannot be moved above its parent', JSON.stringify( await rowIds() ) === JSON.stringify( snap2 ) );
		ok( 'boundary move sends no save', ajax.filter( ( a ) => a.action === 'update-menu-order' ).length === 0 );
		await page.focus( `#${ firstChild } .scpo-handle` );
		await page.keyboard.press( 'Space' );
		await page.keyboard.press( 'ArrowUp' );
		const live = await page.$eval( '.scpo-sr-only[aria-live="assertive"]', ( e ) => e.textContent ).catch( () => '' );
		await page.keyboard.press( 'Escape' );
		ok( 'boundary is announced to screen readers', /can’t move up/.test( live ), live );
	}

	/* ---------------- Quick Edit open + drag ---------------- */
	console.log( '\n== Quick Edit open while reordering ==' );
	await page.goto( B + '/edit.php', { waitUntil: 'networkidle2' } );
	before = await rowIds();
	await page.evaluate( ( id ) => document.querySelector( '#' + id + ' button.editinline' ).click(), before[ 1 ] );
	await page.waitForSelector( 'tr.inline-edit-row', { visible: true } );
	ajax.length = 0;
	await keyboardMove( before[ 3 ], [ 'ArrowUp' ] );
	await waitToast( 'saved' );
	const qe = ajax.find( ( a ) => a.action === 'update-menu-order' );
	const ids = qe ? qe.order.split( '&' ).map( ( p ) => p.split( '=' )[ 1 ] ) : [];
	ok( 'payload has no duplicate IDs with Quick Edit open', qe && new Set( ids ).size === ids.length, qe ? qe.order.slice( 0, 80 ) : 'no request' );
	ok( 'payload carries no edit[] entry', qe && ! /edit(%5B|\[)/.test( qe.order ) );
	ok( 'Quick Edit inputs still usable (typing works)', await ( async () => {
		await page.click( 'tr.inline-edit-row input[name="post_title"]', { clickCount: 3 } );
		await page.keyboard.type( 'x' );
		return /x$/.test( await page.$eval( 'tr.inline-edit-row input[name="post_title"]', ( i ) => i.value ) );
	} )() );

	/* ---------------- Stray output from another plugin ---------------- */
	console.log( '\n== Stray PHP output before the JSON ==' );
	fs.writeFileSync( MU, `<?php\nadd_action( 'wp_ajax_update-menu-order', function () { echo "<br />\\n<b>Notice</b>:  Undefined index: foo in /x/other-plugin.php on line 12<br />\\n"; }, 1 );\n` );
	await page.goto( B + '/edit.php', { waitUntil: 'networkidle2' } );
	before = await rowIds();
	await keyboardMove( before[ 2 ], [ 'ArrowUp' ] );
	ok( 'save with stray output still reports "Order saved"', await waitToast( 'saved' ) );
	ok( 'and the order was stored', increasing( dbMenuOrders( ( await rowIds() ).slice( 0, 4 ).map( idNum ) ) ) );
	fs.unlinkSync( MU );

	/* ---------------- Expired nonce ---------------- */
	console.log( '\n== Expired nonce → refresh → retry ==' );
	await page.goto( B + '/edit.php', { waitUntil: 'networkidle2' } );
	await page.setRequestInterception( true );
	let tampered = false;
	const onReq = ( r ) => {
		if ( ! tampered && r.url().includes( 'admin-ajax.php' ) && ( r.postData() || '' ).includes( 'action=update-menu-order' ) ) {
			tampered = true;
			return r.continue( { postData: r.postData().replace( /nonce=[^&]+/, 'nonce=deadbeef00' ) } );
		}
		r.continue();
	};
	page.on( 'request', onReq );
	ajax.length = 0;
	before = await rowIds();
	await keyboardMove( before[ 4 ], [ 'ArrowUp' ] );
	ok( 'stale nonce is refreshed and the save retried', await waitToast( 'saved', 10000 ) );
	ok( 'nonce refresh endpoint was called', ajax.some( ( a ) => a.action === 'scpo_refresh_nonce' ) );
	page.off( 'request', onReq );
	await page.setRequestInterception( false );

	/* ---------------- Failed save → Retry ---------------- */
	console.log( '\n== Failed save shows Retry ==' );
	await page.setRequestInterception( true );
	const failReq = ( r ) => {
		if ( r.url().includes( 'admin-ajax.php' ) && ( r.postData() || '' ).includes( 'action=update-menu-order' ) ) return r.abort( 'failed' );
		r.continue();
	};
	page.on( 'request', failReq );
	before = await rowIds();
	await keyboardMove( before[ 5 ], [ 'ArrowUp' ] );
	ok( 'network failure shows the error toast', await waitToast( 'error', 12000 ) );
	ok( 'error toast offers Retry', !! ( await page.$( '.scpo-toast__retry' ) ) );
	page.off( 'request', failReq );
	await page.setRequestInterception( false );
	const shown = await rowIds();
	await page.click( '.scpo-toast__retry' );
	ok( 'Retry saves the order', await waitToast( 'saved' ) );
	await page.reload( { waitUntil: 'networkidle2' } );
	ok( 'retried order persisted', JSON.stringify( ( await rowIds() ).slice( 0, 8 ) ) === JSON.stringify( shown.slice( 0, 8 ) ) );

	/* ---------------- Tags ---------------- */
	console.log( '\n== Tag list ==' );
	await page.goto( B + '/edit-tags.php?taxonomy=post_tag', { waitUntil: 'networkidle2' } );
	before = await rowIds();
	await mouseDrag( `#${ before[ 2 ] } .scpo-handle`, `#${ before[ 0 ] }`, true );
	ok( 'tag drag saves', await waitToast( 'saved' ) );
	after = await rowIds();
	ok( 'tag moved to top', after[ 0 ] === before[ 2 ] );
	ok( 'term_order matches the new order', increasing( dbTermOrders( after.slice( 0, 5 ).map( idNum ) ) ) );
	await page.reload( { waitUntil: 'networkidle2' } );
	ok( 'tag order survives reload (page 1 = first 20 in manual order)', ( await rowIds() )[ 0 ] === before[ 2 ] );

	/* ---------------- Classic engine ---------------- */
	console.log( '\n== Classic (jQuery UI) engine ==' );
	wp( '$o=get_option("scporder_options"); $o["engine"]="classic"; update_option("scporder_options",$o); echo 1;' );
	await page.goto( B + '/edit.php', { waitUntil: 'networkidle2' } );
	ok( 'classic engine loaded', await page.evaluate( () => !! ( window.jQuery && jQuery( '#the-list' ).hasClass( 'ui-sortable' ) ) ) );
	before = await rowIds();
	ajax.length = 0;
	await mouseDrag( `#${ before[ 3 ] } td.date`, `#${ before[ 0 ] }`, true );
	await sleep( 1500 );
	const cs = ajax.find( ( a ) => a.action === 'update-menu-order' );
	ok( 'classic drag sends a save', !! cs );
	after = await rowIds();
	ok( 'classic drag stored the new order', increasing( dbMenuOrders( after.slice( 0, 5 ).map( idNum ) ) ) && after[ 0 ] !== before[ 0 ], after.slice( 0, 4 ).join( ',' ) );
	wp( '$o=get_option("scporder_options"); $o["engine"]="sortable"; update_option("scporder_options",$o); echo 1;' );

	/* ---------------- Settings form round-trip ---------------- */
	console.log( '\n== Settings form ==' );
	await page.goto( B + '/options-general.php?page=scporder-settings', { waitUntil: 'networkidle2' } );
	const pagesBefore = wp( 'global $wpdb; echo md5(implode(",",$wpdb->get_col("SELECT CONCAT(ID,\':\',menu_order) FROM $wpdb->posts WHERE post_type=\'page\' ORDER BY ID")));' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2' } ), page.click( '#submit' ) ] );
	ok( 'settings save succeeds', /Settings saved/.test( await page.content() ) );
	ok( 'saving settings does not renumber Pages', pagesBefore === wp( 'global $wpdb; echo md5(implode(",",$wpdb->get_col("SELECT CONCAT(ID,\':\',menu_order) FROM $wpdb->posts WHERE post_type=\'page\' ORDER BY ID")));' ) );
	const o = JSON.parse( wp( 'echo json_encode(get_option("scporder_options"));' ) );
	ok( 'options round-trip unchanged', o.show_handle === '1' && o.engine === 'sortable' && o.objects.includes( 'page' ) && o.tags.includes( 'post_tag' ) && o.order_column === '1', JSON.stringify( o ) );

	console.log( '\n== JS errors ==' );
	ok( 'no JS errors on any screen', jsErrors.length === 0, jsErrors.slice( 0, 3 ).join( ' | ' ) );
} catch ( e ) {
	console.log( 'ERROR', e );
	fail++;
} finally {
	if ( fs.existsSync( MU ) ) fs.unlinkSync( MU );
	await browser.close();
}

console.log( `\nE2E RESULT: ${ pass } passed, ${ fail } failed` );
if ( failed.length ) console.log( 'Failed: ' + failed.join( '; ' ) );
