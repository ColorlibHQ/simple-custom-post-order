// Isolated Chrome checks: no WordPress server, cookies, or site writes.
// Install puppeteer-core as described in tests/README.md; CHROME overrides Chrome.
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
const { default: puppeteer } = await import( process.env.SCPO_PUPPETEER || 'puppeteer-core' );
const asset = ( name ) => fileURLToPath( new URL( '../assets/' + name, import.meta.url ) );
const browser = await puppeteer.launch( {
	executablePath: process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
	headless: true,
} );
let passed = 0;
try {
	for ( const suffix of [ '', '.min' ] ) {
		const page = await browser.newPage();
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		await page.setContent( '<table class="posts"><tbody id="the-list"><tr id="post-1"><td><a class="row-title">One</a></td></tr><tr id="post-2"><td><a class="row-title">Two</a></td></tr></tbody></table>' );
		await page.evaluate( () => {
			window.scporder_vars = { nonce: 'test', ajax_url: '/wp-admin/admin-ajax.php' };
			window.requests = [];
			window.fetch = async ( url, init ) => {
				window.requests.push( init.body );
				return { ok: false, text: async () => JSON.stringify( { success: false, data: { message: 'Reload first. <b>This is plain text.</b>' } } ) };
			};
		} );
		await page.addScriptTag( { path: asset( 'vendor/Sortable.min.js' ) } );
		await page.addScriptTag( { path: asset( 'scporder-sortablejs' + suffix + '.js' ) } );
		await page.focus( '#post-1 .scpo-handle' );
		await page.keyboard.press( 'Space' );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'Space' );
		await page.waitForSelector( '.scpo-toast--error' );
		assert.equal( await page.$eval( '.scpo-toast__message', ( el ) => el.textContent ), 'Reload first. <b>This is plain text.</b>' );
		assert.equal( await page.$( '.scpo-toast b' ), null );
		assert.equal( await page.evaluate( () => ! window.dispatchEvent( new Event( 'beforeunload', { cancelable: true } ) ) ), true );
		assert.equal( await page.evaluate( () => window.requests.length ), 1 );
		passed++;
		await page.evaluate( () => {
			window.fetch = async ( url, init ) => {
				window.requests.push( init.body );
				return { ok: true, text: async () => JSON.stringify( { success: true } ) };
			};
		} );
		await page.click( '.scpo-toast__retry' );
		await page.waitForSelector( '.scpo-toast--saved' );
		assert.equal( await page.evaluate( () => window.requests.length ), 2 );
		assert.deepEqual( errors, [] );
		assert.equal( await page.evaluate( () => ! window.dispatchEvent( new Event( 'beforeunload', { cancelable: true } ) ) ), false );
		passed++;
		// Simulate a term added through AJAX; its row must join the saved baseline.
		await page.evaluate( () => document.querySelector( '#the-list' ).insertAdjacentHTML( 'afterbegin', '<tr id="post-3"><td><a class="row-title">Three</a></td></tr>' ) );
		await page.waitForSelector( '#post-3 .scpo-handle' );
		await page.focus( '#post-3 .scpo-handle' );
		await page.keyboard.press( 'Space' );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'Space' );
		await page.waitForFunction( () => window.requests.length === 3 );
		assert.equal( await page.evaluate( () => new URLSearchParams( window.requests[2] ).get( 'original_order' ) ), 'post[]=3&post[]=2&post[]=1' );
		passed++;
		await page.close();

		const column = await browser.newPage();
		await column.setContent( '<input type="number" class="scpo-order-input" data-id="2" min="1" step="1" value="2">' );
		await column.evaluate( () => {
			window.scpoOrderCol = { nonce: 'test', ajax_url: '/wp-admin/admin-ajax.php', error: 'Save failed.' };
			window.alerts = [];
			window.alert = ( message ) => window.alerts.push( message );
			// An error HTTP status must win even if a proxy returns success JSON.
			window.fetch = async () => ( { ok: false, text: async () => '{"success":true}' } );
		} );
		await column.addScriptTag( { path: asset( 'scporder-order-column' + suffix + '.js' ) } );
		await column.$eval( 'input', ( el ) => { el.value = '1'; el.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } );
		await column.waitForFunction( () => window.alerts && window.alerts.length === 1 );
		assert.equal( await column.evaluate( () => window.alerts[ 0 ] ), 'Save failed.' );
		assert.equal( await column.$eval( 'input', ( el ) => el.classList.contains( 'is-saving' ) ), false );
		passed++;
		await column.close();
	}
	console.log( `BROWSER RELIABILITY RESULT: ${ passed } passed` );
} finally {
	await browser.close();
}
