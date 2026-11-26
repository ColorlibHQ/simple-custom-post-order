<?php
/**
 * SCPO regression suite — run with: wp eval-file core.php
 * Every DB write happens inside a transaction that is rolled back.
 */

global $wpdb;

class ScpoDie extends Exception {}

$GLOBALS['scpo_results'] = [ 'pass' => 0, 'fail' => 0, 'failed' => [] ];

function t( string $name, bool $ok, string $detail = '' ): void {
	if ( $ok ) {
		$GLOBALS['scpo_results']['pass']++;
		echo "  PASS  $name\n";
	} else {
		$GLOBALS['scpo_results']['fail']++;
		$GLOBALS['scpo_results']['failed'][] = $name;
		echo "  FAIL  $name" . ( $detail ? "  -- $detail" : '' ) . "\n";
	}
}

function section( string $s ): void {
	echo "\n== $s ==\n";
}

function scpo_engine() {
	foreach ( $GLOBALS['wp_filter']['admin_init']->callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof SCPO_Engine ) {
				return $cb['function'][0];
			}
		}
	}
	return null;
}

function scpo_private( string $method, ...$args ) {
	$m = new ReflectionMethod( 'SCPO_Engine', $method );
	if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
	return $m->invoke( scpo_engine(), ...$args );
}

function has_method( string $m ): bool {
	return method_exists( 'SCPO_Engine', $m );
}

function begin(): void {
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
}

function rollback(): void {
	global $wpdb;
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
}

/** Call an AJAX handler in-process; returns [decoded|raw output]. */
function ajax( string $method, array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;
	$doing    = function () { return true; };
	$handler  = function () { return function () { throw new ScpoDie(); }; };
	add_filter( 'wp_doing_ajax', $doing );
	add_filter( 'wp_die_ajax_handler', $handler );
	ob_start();
	try {
		scpo_engine()->$method();
	} catch ( ScpoDie $e ) {
	}
	$out = ob_get_clean();
	remove_filter( 'wp_doing_ajax', $doing );
	remove_filter( 'wp_die_ajax_handler', $handler );
	$_POST = $_REQUEST = [];
	$json  = json_decode( $out, true );
	return null === $json ? $out : $json;
}

function page_order(): array {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status IN ('publish','draft','pending','private','future') ORDER BY menu_order, ID" ) );
}

function menu_orders( array $ids ): array {
	global $wpdb;
	$in = implode( ',', array_map( 'intval', $ids ) );
	return array_map( 'intval', $wpdb->get_results( "SELECT ID, menu_order FROM $wpdb->posts WHERE ID IN ($in)", OBJECT_K ) ? wp_list_pluck( $wpdb->get_results( "SELECT ID, menu_order FROM $wpdb->posts WHERE ID IN ($in)", OBJECT_K ), 'menu_order' ) : [] );
}

wp_set_current_user( (int) ( getenv( 'SCPO_USER' ) ?: 1 ) );
$engine = scpo_engine();
$engine->register_settings(); // admin_init never fires under WP-CLI
echo 'Engine: ' . ( $engine ? 'found' : 'MISSING' ) . ' — version ' . SCPORDER_VERSION . "\n";

/* ------------------------------------------------------------------ */
section( 'Post queries (pre_get_posts)' );

$dw = [];
add_action( 'doing_it_wrong_run', function ( $fn ) use ( &$dw ) { $dw[] = $fn; } );

$posts  = get_posts( [ 'post_type' => 'post', 'orderby' => 'title', 'numberposts' => 10 ] );
$titles = wp_list_pluck( $posts, 'post_title' );
t( 'get_posts orderby=title (no order) stays A→Z (legacy kept)', strcasecmp( (string) reset( $titles ), (string) end( $titles ) ) <= 0 && count( $titles ) === 10, implode( ' | ', array_slice( $titles, 0, 3 ) ) );

$dates = wp_list_pluck( get_posts( [ 'post_type' => 'post', 'orderby' => 'post_date', 'order' => 'DESC', 'numberposts' => 5 ] ), 'post_date' );
$sorted = $dates;
rsort( $sorted );
t( 'get_posts orderby=post_date order=DESC is newest first', $dates === $sorted, implode( ' | ', array_slice( $dates, 0, 2 ) ) );

$recent  = wp_get_recent_posts( [ 'numberposts' => 3, 'post_status' => 'publish' ] );
$newest  = $wpdb->get_var( "SELECT MAX(post_date) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'" );
t( 'wp_get_recent_posts returns the newest post first', isset( $recent[0] ) && $recent[0]['post_date'] === $newest, ( $recent[0]['post_date'] ?? '-' ) . ' vs ' . $newest );

$expected_page_values = array_map( 'intval', $wpdb->get_col( "SELECT menu_order FROM $wpdb->posts WHERE post_type='page' AND post_status='publish' ORDER BY menu_order ASC LIMIT 5" ) );
$pages = get_posts( [ 'post_type' => 'page', 'numberposts' => 5 ] );
$mo    = wp_list_pluck( $pages, 'menu_order' );
t( 'get_posts default on sorted type is menu_order ASC (legacy kept)', $mo === array_values( array_map( 'intval', $mo ) ) && count( $mo ) === 5 && $mo === $expected_page_values, json_encode( $mo ) );

$pages = get_posts( [ 'post_type' => 'page', 'orderby' => 'menu_order', 'numberposts' => 5 ] );
t( 'get_posts orderby=menu_order (no order) stays ASC (legacy kept)', count( $pages ) === 5 && wp_list_pluck( $pages, 'menu_order' ) === $expected_page_values, json_encode( wp_list_pluck( $pages, 'menu_order' ) ) );

$q = new WP_Query( [ 'post_type' => 'page', 'posts_per_page' => 5, 'fields' => 'ids' ] );
t( 'WP_Query default on sorted type is menu_order ASC', false !== strpos( $q->request, 'menu_order ASC' ) );

$q = new WP_Query( [ 'post_type' => 'post', 'orderby' => 'date', 'order' => 'DESC', 'posts_per_page' => 3, 'fields' => 'ids' ] );
t( 'WP_Query explicit orderby=date DESC respected', false === strpos( $q->request, 'menu_order' ) );

$q = new WP_Query( [ 'post_type' => 'portfolio', 's' => 'a', 'fields' => 'ids' ] );
t( 'secondary search query keeps relevance order (not menu_order)', false === strpos( $q->request, 'menu_order' ) );

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$GLOBALS['current_screen'] = WP_Screen::get( 'dashboard' ); // makes is_admin() true
$q = new WP_Query( [ 'post_type' => 'page', 'orderby' => 'menu_order', 'posts_per_page' => 3, 'fields' => 'ids' ] );
t( 'admin-side WP_Query orderby=menu_order (no order) stays ASC', false !== strpos( $q->request, 'menu_order ASC' ), $q->request );
$q = new WP_Query( [ 'post_type' => 'page', 'posts_per_page' => 3, 'fields' => 'ids' ] );
t( 'admin-side WP_Query without orderby gets menu_order ASC', false !== strpos( $q->request, 'menu_order ASC' ) );
unset( $GLOBALS['current_screen'] );

$saved = $GLOBALS['wp_query'];
unset( $GLOBALS['wp_query'] );
$dw = [];
get_posts( [ 'post_type' => 'page', 'numberposts' => 1 ] );
$GLOBALS['wp_query'] = $saved;
t( 'no is_search() "called incorrectly" notice before the main query', ! in_array( 'is_search', $dw, true ), implode( ',', $dw ) );

/* ------------------------------------------------------------------ */
section( 'Term queries' );

$top   = get_terms( [ 'taxonomy' => 'post_tag', 'orderby' => 'count', 'order' => 'DESC', 'number' => 5, 'hide_empty' => false ] );
$want  = array_map( 'intval', $wpdb->get_col( "SELECT count FROM $wpdb->term_taxonomy WHERE taxonomy='post_tag' ORDER BY count DESC LIMIT 5" ) );
t( 'get_terms orderby=count returns the most-used tags', array_map( 'intval', wp_list_pluck( $top, 'count' ) ) === $want, json_encode( wp_list_pluck( $top, 'count' ) ) . ' want ' . json_encode( $want ) );

$expected_tag_values = array_map( 'intval', $wpdb->get_col( "SELECT t.term_order FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id=tt.term_id WHERE tt.taxonomy='post_tag' ORDER BY t.term_order ASC LIMIT 5" ) );
$def = get_terms( [ 'taxonomy' => 'post_tag', 'number' => 5, 'hide_empty' => false ] );
t( 'get_terms default order follows stored term_order', count( $def ) === 5 && array_map( 'intval', wp_list_pluck( $def, 'term_order' ) ) === $expected_tag_values, json_encode( wp_list_pluck( $def, 'term_order' ) ) );

$ids = array_map( 'intval', $wpdb->get_col( "SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id=tt.term_id WHERE taxonomy='post_tag' ORDER BY t.term_order DESC LIMIT 4" ) );
$inc = get_terms( [ 'taxonomy' => 'post_tag', 'include' => $ids, 'orderby' => 'include', 'hide_empty' => false, 'fields' => 'ids' ] );
t( 'get_terms orderby=include respected', array_map( 'intval', $inc ) === $ids );

$expected_category_values = array_map( 'intval', $wpdb->get_col( "SELECT t.term_order FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id=tt.term_id WHERE tt.taxonomy='category' ORDER BY t.term_order ASC LIMIT 4" ) );
$cats = get_terms( [ 'taxonomy' => 'category', 'orderby' => 'id', 'hide_empty' => false, 'number' => 4 ] );
t( 'get_terms orderby=id (wp_dropdown_categories default) still manual', count( $cats ) === 4 && array_map( 'intval', wp_list_pluck( $cats, 'term_order' ) ) === $expected_category_values, json_encode( wp_list_pluck( $cats, 'term_order' ) ) );

// get_the_terms after a reorder (relationship cache is not cleared by clean_term_cache).
$post_id = (int) $wpdb->get_var( "SELECT tr.object_id FROM $wpdb->term_relationships tr JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tt.taxonomy='post_tag' GROUP BY tr.object_id HAVING COUNT(*) >= 3 LIMIT 1" );
begin();
$before = get_the_terms( $post_id, 'post_tag' ); // primes the relationship cache
$tids   = array_map( 'intval', wp_list_pluck( $before, 'term_id' ) );
$vals   = array_map( 'intval', wp_list_pluck( $before, 'term_order' ) );
$rev    = array_reverse( $tids );
$payload = implode( '&', array_map( function ( $id ) { return 'tag[]=' . $id; }, $rev ) );
$r = ajax( 'update_menu_order_tags', [ 'nonce' => wp_create_nonce( 'scporder_nonce_action' ), 'order' => $payload ] );
$after = array_map( 'intval', wp_list_pluck( get_the_terms( $post_id, 'post_tag' ), 'term_id' ) );
t( 'get_the_terms reflects a term drag without a cache flush', $after === $rev, 'got ' . json_encode( array_slice( $after, 0, 4 ) ) . ' want ' . json_encode( array_slice( $rev, 0, 4 ) ) );
rollback();

if ( taxonomy_exists( 'product_cat' ) && function_exists( 'wc_change_pre_get_terms' ) ) {
	$pc = array_map( 'intval', get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids', 'orderby' => 'term_id' ] ) );
	if ( count( $pc ) >= 2 ) {
		begin();
		$o = get_option( 'scporder_options' );
		$o['tags'][] = 'product_cat';
		update_option( 'scporder_options', $o );
		$n = count( $pc );
		foreach ( $pc as $i => $id ) {
			$wpdb->update( $wpdb->terms, [ 'term_order' => $n - $i ], [ 'term_id' => $id ] );
		}
		wp_cache_flush();
		$got = array_map( 'intval', get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ] ) );
		t( 'WooCommerce product categories follow the manual order (menu_order → meta sort)', $got === array_reverse( $pc ), json_encode( $got ) );
		$limited = array_map( 'intval', get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids', 'number' => 2, 'hierarchical' => false ] ) );
		t( 'limited WooCommerce category query selects the first IDs in manual order', $limited === array_slice( array_reverse( $pc ), 0, 2 ), wp_json_encode( $limited ) );
		// The legacy Product Categories widget ("Category order") asks for the
		// `order` meta directly, without force_menu_order_sort. Give that meta
		// the opposite order: the widget must still show ours.
		foreach ( $pc as $i => $id ) {
			update_term_meta( $id, 'order', $i + 1 );
		}
		wp_cache_flush();
		$widget = array_map( 'intval', get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids', 'orderby' => 'meta_value_num', 'meta_key' => 'order', 'menu_order' => false ] ) );
		t( 'a direct WooCommerce order-meta query (Product Categories widget) follows the manual order', $widget === array_reverse( $pc ), wp_json_encode( $widget ) );
		rollback();
	}
}

/* ------------------------------------------------------------------ */
section( 'Adjacent posts' );

$chain = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status='publish' ORDER BY menu_order, ID" ) );
$GLOBALS['post'] = get_post( $chain[0] );
$walk = [ $chain[0] ];
for ( $i = 0; $i < 400; $i++ ) {
	$n = get_adjacent_post( false, '', false );
	if ( ! $n ) {
		break;
	}
	$walk[]          = (int) $n->ID;
	$GLOBALS['post'] = $n;
}
t( 'next-links walk every published page once, in order', $walk === $chain, count( $walk ) . ' of ' . count( $chain ) );

begin();
// Three posts where two share a menu_order.
$a = $chain[3];
$b = $chain[4];
$c = $chain[5];
$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET menu_order = %d WHERE ID = %d", (int) get_post_field( 'menu_order', $c, 'raw' ), $b ) );
wp_cache_flush();
$GLOBALS['post'] = get_post( $a );
$seen = [];
$p = $GLOBALS['post'];
for ( $i = 0; $i < 3; $i++ ) {
	$p = get_adjacent_post( false, '', false );
	if ( ! $p ) {
		break;
	}
	$seen[]          = (int) $p->ID;
	$GLOBALS['post'] = $p;
}
t( 'next-links do not skip a post sharing the same menu_order', in_array( $b, $seen, true ) && in_array( $c, $seen, true ), json_encode( $seen ) . " (b=$b c=$c)" );
$GLOBALS['post'] = get_post( $c );
$prev = get_adjacent_post( false, '', true );
$GLOBALS['post'] = get_post( $b );
$prev_b = get_adjacent_post( false, '', true );
t( 'previous-links also reach both tied posts', $prev && $prev_b && ( (int) $prev->ID === $b || (int) $prev_b->ID === $c ), ( $prev ? $prev->ID : '-' ) . '/' . ( $prev_b ? $prev_b->ID : '-' ) );
rollback();

// Explicit $post argument (not the global).
$GLOBALS['post'] = get_post( $chain[10] );
$n = get_adjacent_post( false, '', false, 'category' );
$GLOBALS['post'] = get_post( $chain[2] );
t( 'reverse filter flips direction', ( function () use ( $chain ) {
	add_filter( 'scpo_reverse_adjacent_posts', '__return_true' );
	$p = get_adjacent_post( false, '', false );
	remove_filter( 'scpo_reverse_adjacent_posts', '__return_true' );
	return $p && (int) $p->ID === $chain[1];
} )() );

/* ------------------------------------------------------------------ */
section( 'Settings / seeding' );

begin();
$opts   = get_option( 'scporder_options' );
$before = page_order();
$mo_b   = $wpdb->get_col( "SELECT menu_order FROM $wpdb->posts WHERE post_type='page' ORDER BY ID" );
// Make pages non-sequential (a gap), then write the option WITHOUT touching pages.
$wpdb->query( "UPDATE $wpdb->posts SET menu_order = menu_order + 5 WHERE post_type='page' AND menu_order > 100" );
$mo_gap = $wpdb->get_col( "SELECT menu_order FROM $wpdb->posts WHERE post_type='page' ORDER BY ID" );
$o2     = $opts;
$o2['objects'] = array_values( array_diff( $opts['objects'], [ 'portfolio' ] ) );
update_option( 'scporder_options', $o2 );
$mo_after = $wpdb->get_col( "SELECT menu_order FROM $wpdb->posts WHERE post_type='page' ORDER BY ID" );
t( 'writing the option does not renumber already-enabled types', $mo_after === $mo_gap );
rollback();

begin();
// Newly enabled type with an existing order that has ties: ties go by title for pages.
$opts = get_option( 'scporder_options' );
$o2   = $opts;
$o2['objects'] = array_values( array_diff( $opts['objects'], [ 'page' ] ) );
update_option( 'scporder_options', $o2 );
$wpdb->query( "UPDATE $wpdb->posts SET menu_order = 0 WHERE post_type='page'" );
$first3 = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts p WHERE post_type='page' AND post_status='publish' AND post_parent=0 AND NOT EXISTS (SELECT 1 FROM $wpdb->posts c WHERE c.post_parent=p.ID AND c.post_type='page') ORDER BY ID LIMIT 3" ) );
$wpdb->query( "UPDATE $wpdb->posts SET menu_order = 5 WHERE ID IN (" . implode( ',', $first3 ) . ')' );
$wpdb->query( "UPDATE $wpdb->posts SET menu_order = 9 WHERE post_type='page' AND ID NOT IN (" . implode( ',', $first3 ) . ') ORDER BY ID LIMIT 2' );
update_option( 'scporder_options', $opts ); // re-enable pages
$by_title = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE ID IN (" . implode( ',', $first3 ) . ') ORDER BY post_title, ID' ) );
$got      = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE ID IN (" . implode( ',', $first3 ) . ') ORDER BY menu_order' ) );
t( 'enabling Pages breaks Page Attributes ties by title (as WP showed them)', $got === $by_title, json_encode( $got ) . ' want ' . json_encode( $by_title ) );
$zeros_first = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='page' AND post_status IN ('publish','draft') AND menu_order < (SELECT m FROM (SELECT MIN(menu_order) m FROM $wpdb->posts WHERE ID IN (" . implode( ',', $first3 ) . ')) x)' );
// Existing values stay the primary key: no top-level page that was at 0 may end up
// behind the three that were at 5 (the two moved to 9 belong behind them). Counted
// from this site's data; the old "more than 100" only held for one dev site.
$nines = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='page' AND ID NOT IN (" . implode( ',', $first3 ) . ') ORDER BY ID LIMIT 2' ) );
$min_three = (int) $wpdb->get_var( "SELECT MIN(menu_order) FROM $wpdb->posts WHERE ID IN (" . implode( ',', $first3 ) . ')' );
$zero_tops = "FROM $wpdb->posts WHERE post_type='page' AND post_status IN ('publish','draft') AND post_parent=0 AND ID NOT IN (" . implode( ',', array_merge( $first3, $nines ) ) . ')';
$zero_count = (int) $wpdb->get_var( "SELECT COUNT(*) $zero_tops" );
$behind = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $zero_tops AND menu_order > %d", $min_three ) );
t( 'enabling Pages keeps the existing order values as primary key', $zero_count > 0 && 0 === $behind && $zeros_first >= $zero_count, "$behind of $zero_count zero-valued pages sorted behind the 5s" );
rollback();

begin();
$opts = get_option( 'scporder_options' );
$o2   = $opts;
unset( $o2['show_handle'] );
update_option( 'scporder_options', $o2 );
t( 'a programmatic write without show_handle keeps the handle on', '1' === get_option( 'scporder_options' )['show_handle'] );
$o2 = $opts;
$o2['_scpo_form'] = '1';
unset( $o2['show_handle'] );
update_option( 'scporder_options', $o2 );
t( 'the settings form with the box unticked turns the handle off', '0' === get_option( 'scporder_options' )['show_handle'] );
t( 'the form marker is not stored', ! array_key_exists( '_scpo_form', get_option( 'scporder_options' ) ) );
rollback();

register_taxonomy( 'Genre', 'post', [ 'show_ui' => true ] );
$san = $engine->sanitize_options( [ 'tags' => [ 'Genre', 'category', 'nope' ], 'objects' => [ 'page', 'nav_menu_item_bogus' ] ] );
t( 'taxonomy registered with capitals can be enabled', in_array( 'Genre', $san['tags'], true ), json_encode( $san['tags'] ) );
t( 'unknown types/taxonomies are dropped', ! in_array( 'nope', $san['tags'], true ) && ! in_array( 'nav_menu_item_bogus', $san['objects'], true ) );

/* ------------------------------------------------------------------ */
section( 'Drag save handler' );

$nonce = wp_create_nonce( 'scporder_nonce_action' );
begin();
$ids  = array_slice( page_order(), 0, 3 ); // A, B, C
$vals = menu_orders( $ids );
// Move C to the top, with an open Quick Edit row duplicating A.
$payload = "post[]={$ids[2]}&post[]={$ids[0]}&post[]={$ids[1]}&edit[]={$ids[0]}";
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $payload ] );
$after = menu_orders( $ids );
t( 'duplicate ID from an open Quick Edit row is ignored', ! empty( $r['success'] ) && $after[ $ids[2] ] < $after[ $ids[0] ] && $after[ $ids[0] ] < $after[ $ids[1] ] && max( $after ) <= max( $vals ), json_encode( $after ) . ' ' . json_encode( $r ) );
rollback();

begin();
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => 'order=foo=bar' ] );
t( 'empty/garbage payload is rejected, not reported as success', is_array( $r ) && empty( $r['success'] ), json_encode( $r ) );
$ids = array_slice( page_order(), 0, 2 );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => "post[]={$ids[1]}&post[]={$ids[0]}&bulk[]=edit" ] );
t( 'legacy bulk[]=edit entry no longer fails the batch', ! empty( $r['success'] ), json_encode( $r ) );
rollback();

begin();
$ids = array_slice( page_order(), 0, 3 );
$expected = [ $ids[0] => 2, $ids[1] => 1, $ids[2] => 3 ];
foreach ( $expected as $id => $value ) { $wpdb->update( $wpdb->posts, [ 'menu_order' => $value ], [ 'ID' => $id ] ); clean_post_cache( $id ); }
$payload = "post[]={$ids[2]}&post[]={$ids[0]}&post[]={$ids[1]}";
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $payload, 'original_order' => "post[]={$ids[0]}&post[]={$ids[1]}&post[]={$ids[2]}", 'original_values' => wp_json_encode( $expected ) ] );
t( 'value snapshots save an unchanged nonmonotonic displayed list', ! empty( $r['success'] ), wp_json_encode( $r ) );
t( 'successful saves return exact values for the next snapshot', ! empty( $r['data']['order_values'] ) && array_map( 'intval', $r['data']['order_values'] ) == menu_orders( $ids ) );
rollback();

$debug = $engine->order_debug_information( [] );
t( 'Site Health reports the installed plugin version', SCPORDER_VERSION === $debug['simple-custom-post-order']['fields']['version']['value'] );
t( 'Site Health identifies both order tables', false !== strpos( $debug['simple-custom-post-order']['fields']['posts']['value'], $wpdb->posts ) && false !== strpos( $debug['simple-custom-post-order']['fields']['terms']['value'], $wpdb->terms ) );

if ( has_method( 'parse_order_ids' ) ) {
	$big = implode( '&', array_map( function ( $i ) { return 'post[]=' . $i; }, range( 1, 1500 ) ) );
	t( 'payload past max_input_vars (1500 rows) parses completely', 1500 === count( scpo_private( 'parse_order_ids', $big ) ) );
} else {
	$big = implode( '&', array_map( function ( $i ) { return 'post[]=' . $i; }, range( 1, 1500 ) ) );
	parse_str( $big, $d );
	t( 'payload past max_input_vars (1500 rows) parses completely', 1500 === count( $d['post'] ?? [] ), 'parse_str kept ' . count( $d['post'] ?? [] ) );
}

begin();
// Tied pair still moves (2.8.7 regression guard).
$ids = array_slice( page_order(), 0, 2 );
$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET menu_order = 7 WHERE ID IN (%d,%d)", $ids[0], $ids[1] ) );
wp_cache_flush();
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => "post[]={$ids[1]}&post[]={$ids[0]}" ] );
$after = menu_orders( $ids );
t( 'dragging two tied rows actually reorders them', $after[ $ids[1] ] < $after[ $ids[0] ], json_encode( $after ) );
rollback();

begin();
// Forged ID the user cannot edit → whole batch refused.
wp_set_current_user( 0 );
$sub = wp_insert_user( [ 'user_login' => 'scpo_author_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
wp_set_current_user( $sub );
$ids = array_slice( page_order(), 0, 2 );
$r   = ajax( 'update_menu_order', [ 'nonce' => wp_create_nonce( 'scporder_nonce_action' ), 'order' => "post[]={$ids[1]}&post[]={$ids[0]}" ] );
t( 'author cannot reorder pages they cannot edit (IDOR guard)', is_array( $r ) && empty( $r['success'] ) );
wp_set_current_user( (int) ( getenv( 'SCPO_USER' ) ?: 1 ) );
rollback();

/* ------------------------------------------------------------------ */
section( 'Order column (scpo_set_position)' );

begin();
$posts = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status IN ('publish','draft','future','pending','private') ORDER BY menu_order, ID" ) );
$move  = $posts[20];
$updates = 0;
$cb = function ( $q ) use ( &$updates ) {
	if ( 0 === stripos( ltrim( $q ), 'UPDATE' ) ) {
		$updates++;
	}
	return $q;
};
add_filter( 'query', $cb );
$r = ajax( 'scpo_ajax_set_position', [ 'nonce' => $nonce, 'id' => $move, 'position' => 3 ] );
remove_filter( 'query', $cb );
$pos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT menu_order FROM $wpdb->posts WHERE ID = %d", $move ) );
t( 'set_position moves the item to position 3', ! empty( $r['success'] ) && 3 === $pos, "menu_order=$pos" );
t( 'set_position uses a batched write (≤ 3 UPDATE queries)', $updates <= 3, "$updates UPDATE queries" );
$dups = (int) $wpdb->get_var( "SELECT COUNT(*) - COUNT(DISTINCT menu_order) FROM $wpdb->posts WHERE post_type='post' AND post_status IN ('publish','draft','future','pending','private')" );
t( 'set_position leaves no duplicates', 0 === $dups );
$r = ajax( 'scpo_ajax_set_position', [ 'nonce' => $nonce, 'id' => $move, 'position' => 0 ] );
t( 'set_position rejects position 0', is_array( $r ) && empty( $r['success'] ), json_encode( $r ) );
rollback();

/* ------------------------------------------------------------------ */
section( 'refresh() / cache invalidation' );

if ( has_method( 'normalize_post_type' ) ) {
	begin();
	scpo_private( 'normalize_post_type', 'page' ); // bring the fixture into tree order first
	scpo_private( 'normalize_post_type', 'post' );
	$ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status IN ('publish','draft','pending','private','future') ORDER BY menu_order" ) );
	// One duplicate: only ~1-2 rows should need rewriting.
	$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET menu_order = %d WHERE ID = %d", (int) get_post_field( 'menu_order', $ids[50], 'raw' ), $ids[51] ) );
	$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET menu_order = menu_order WHERE ID = %d", $ids[51] ) );
	wp_cache_flush();
	$cleaned = 0;
	$cb = function () use ( &$cleaned ) { $cleaned++; };
	add_action( 'clean_post_cache', $cb );
	scpo_private( 'normalize_post_type', 'post' );
	remove_action( 'clean_post_cache', $cb );
	$dups = (int) $wpdb->get_var( "SELECT COUNT(*) - COUNT(DISTINCT menu_order) FROM $wpdb->posts WHERE post_type='post' AND post_status IN ('publish','draft','future','pending','private')" );
	t( 'refresh repairs a duplicate', 0 === $dups );
	t( 'refresh rewrites only the rows that change', $cleaned > 0 && $cleaned < 10, "$cleaned rows invalidated" );
	rollback();

	begin();
	$wpdb->query( "UPDATE $wpdb->posts SET menu_order = menu_order + 1 WHERE post_type='page'" );
	$ids = page_order();
	foreach ( $ids as $id ) {
		get_post( $id ); // prime cache with stale values
	}
	add_filter( 'scpo_cache_flush_group_threshold', function () { return 5; } );
	$cleaned = 0;
	$cb = function () use ( &$cleaned ) { $cleaned++; };
	add_action( 'clean_post_cache', $cb );
	scpo_private( 'normalize_post_type', 'page' );
	remove_action( 'clean_post_cache', $cb );
	$stale = 0;
	foreach ( $ids as $i => $id ) {
		if ( false !== wp_cache_get( $id, 'posts' ) ) {
			$stale++;
		}
	}
	t( 'bulk invalidation (above threshold) drops every renumbered row', 0 === $stale, "$stale rows still cached" );
	t( 'bulk invalidation does not call clean_post_cache per row', 0 === $cleaned, "$cleaned calls" );
	// Pages renumber in tree order, which only matches page_order()'s flat
	// (menu_order, ID) order on an already-normalised tree, so check every row.
	$db_values = menu_orders( $ids );
	$fresh_mismatch = 0;
	foreach ( $ids as $id ) {
		if ( (int) get_post( $id )->menu_order !== (int) $db_values[ $id ] ) {
			$fresh_mismatch++;
		}
	}
	$numbers = array_map( 'intval', array_values( $db_values ) );
	sort( $numbers );
	t( 'fresh read after bulk renumber sees 1..N', 0 === $fresh_mismatch && $numbers === range( 1, count( $ids ) ), "$fresh_mismatch stale reads" );
	rollback();
} else {
	t( 'refresh rewrites only the rows that change', false, 'normalize_post_type() missing' );
}

if ( has_method( 'tree_order' ) ) {
	begin();
	// Scramble: a global order that interleaves children of different parents.
	$wpdb->query( "UPDATE $wpdb->posts SET menu_order = (ID * 7919) % 1000 WHERE post_type='page'" );
	wp_cache_flush();
	$sib_before = [];
	foreach ( $wpdb->get_results( "SELECT ID, post_parent FROM $wpdb->posts WHERE post_type='page' AND post_status IN ('publish','draft','pending','private','future') ORDER BY menu_order, post_title, ID" ) as $r ) {
		$sib_before[ (int) $r->post_parent ][] = (int) $r->ID;
	}
	scpo_private( 'normalize_post_type', 'page' );
	$rows = $wpdb->get_results( "SELECT ID, post_parent, menu_order FROM $wpdb->posts WHERE post_type='page' AND post_status IN ('publish','draft','pending','private','future') ORDER BY menu_order" );
	$pos  = []; $parent = [];
	foreach ( $rows as $i => $r ) { $pos[ (int) $r->ID ] = $i; $parent[ (int) $r->ID ] = (int) $r->post_parent; }
	$ok_parent = true; $ok_contig = true;
	foreach ( $parent as $id => $par ) {
		if ( $par && isset( $pos[ $par ] ) && $pos[ $par ] > $pos[ $id ] ) { $ok_parent = false; }
	}
	// every subtree occupies a contiguous run: walking in order, a row's parent must be on the current ancestor stack
	$stack = [];
	foreach ( $rows as $r ) {
		$par = isset( $pos[ (int) $r->post_parent ] ) ? (int) $r->post_parent : 0;
		while ( $stack && end( $stack ) !== $par ) { array_pop( $stack ); }
		if ( $par && ! $stack ) { $ok_contig = false; }
		$stack[] = (int) $r->ID;
	}
	$sib_after = [];
	foreach ( $rows as $r ) { $sib_after[ (int) $r->post_parent ][] = (int) $r->ID; }
	$mo = array_map( 'intval', wp_list_pluck( $rows, 'menu_order' ) );
	t( 'pages are numbered in tree order (parent before children)', $ok_parent );
	t( 'each page subtree is a contiguous run of numbers', $ok_contig );
	t( 'sibling order is unchanged by tree numbering', $sib_after == $sib_before );
	t( 'tree numbering is gapless 1..N', $mo === range( 1, count( $mo ) ) );
	$writes = 0;
	$cb = function ( $q ) use ( &$writes ) { if ( 0 === stripos( ltrim( $q ), 'UPDATE' ) ) { $writes++; } return $q; };
	add_filter( 'query', $cb );
	scpo_private( 'normalize_post_type', 'page' );
	remove_filter( 'query', $cb );
	t( 'a tree already in order is not rewritten', 0 === $writes, "$writes UPDATEs" );
	rollback();
}

if ( has_method( 'tree_order' ) ) {
	// A root(1), O orphan(parent 999), B root(3), C child of A, D child of O, E orphan(parent 998), F child of D
	$got = scpo_private( 'tree_order', [ [ 1, 1, 0 ], [ 2, 2, 999 ], [ 3, 3, 0 ], [ 4, 4, 1 ], [ 5, 5, 2 ], [ 6, 6, 998 ], [ 7, 7, 5 ] ] );
	t( 'orphans are numbered where the list table shows them (after all trees, flat)', [ 1, 4, 3, 2, 5, 6, 7 ] === $got, json_encode( $got ) );
}

if ( has_method( 'normalize_taxonomy' ) ) {
	// Terms of a hierarchical taxonomy must be numbered in tree order, the order the
	// terms list shows: the drag handler refuses a save whose screen order is not
	// ascending. Fixtures use a private taxonomy: category hooks can run DDL (WPML
	// CMS Navigation truncates a cache table), which commits the transaction.
	register_taxonomy( 'scpo_tree', 'post', [ 'hierarchical' => true, 'show_ui' => true ] );
	register_taxonomy( 'scpo_flat', 'post', [ 'show_ui' => true ] );
	$sortable = []; // taxonomies the filter below adds to the enabled ones
	$enable   = function ( $o ) use ( &$sortable ) {
		$o         = is_array( $o ) ? $o : [];
		$o['tags'] = array_merge( isset( $o['tags'] ) && is_array( $o['tags'] ) ? $o['tags'] : [], $sortable );
		return $o;
	};
	add_filter( 'option_scporder_options', $enable );
	try {
		$term_tree = function ( string $taxonomy ) use ( $wpdb ): array {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.term_order, tt.parent FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY t.term_order, t.name, t.term_id", $taxonomy ) );
			$kids = [];
			foreach ( $rows as $r ) { $kids[ (int) $r->parent ][] = (int) $r->term_id; }
			$order = [];
			$walk  = function ( int $parent ) use ( &$walk, &$order, $kids ) {
				foreach ( $kids[ $parent ] ?? [] as $id ) { $order[] = $id; $walk( $id ); }
			};
			$walk( 0 );
			return [ $order, array_map( 'intval', wp_list_pluck( $rows, 'term_order', 'term_id' ) ) ];
		};
		// Places where term_order drops in screen order.
		$tree_breaks = function ( string $taxonomy ) use ( $term_tree ): int {
			list( $order, $value ) = $term_tree( $taxonomy );
			$breaks = 0;
			for ( $i = 1; $i < count( $order ); $i++ ) {
				if ( $value[ $order[ $i ] ] < $value[ $order[ $i - 1 ] ] ) { $breaks++; }
			}
			return $breaks;
		};
		$term = function ( string $name, int $parent = 0, string $taxonomy = 'scpo_tree' ): int {
			$r = wp_insert_term( $name . ' ' . wp_rand(), $taxonomy, [ 'parent' => $parent ] );
			if ( is_wp_error( $r ) ) {
				throw new RuntimeException( 'Fixture term not created: ' . $r->get_error_message() );
			}
			return (int) $r['term_id'];
		};
		// Inserts the way the quick-add form does: inside an add-tag AJAX request.
		$quick_add = function ( string $name, int $parent = 0, string $taxonomy = 'scpo_tree' ) use ( $term ): int {
			$_POST = $_REQUEST = [ 'action' => 'add-tag' ];
			add_filter( 'wp_doing_ajax', '__return_true' );
			try {
				return $term( $name, $parent, $taxonomy );
			} finally {
				remove_filter( 'wp_doing_ajax', '__return_true' );
				$_POST = $_REQUEST = [];
			}
		};
		$order_writes = function ( callable $fn ) use ( $wpdb ): int {
			$writes = 0;
			$cb     = function ( $q ) use ( &$writes, $wpdb ) {
				if ( 0 === stripos( ltrim( $q ), "UPDATE $wpdb->terms SET term_order" ) ) { $writes++; }
				return $q;
			};
			add_filter( 'query', $cb );
			try { $fn(); } finally { remove_filter( 'query', $cb ); }
			return $writes;
		};
		$tag_payload = function ( array $ids ): string {
			return implode( '&', array_map( function ( $id ) { return 'tag[]=' . $id; }, $ids ) );
		};

		begin();
		$a = $term( 'SCPO tree A' );
		$term( 'SCPO tree B' );
		scpo_private( 'normalize_taxonomy', 'scpo_tree' );
		$term( 'SCPO tree A1', $a ); // term_order 0, the column default
		scpo_private( 'normalize_taxonomy', 'scpo_tree' );
		t( 'a new child term is numbered under its parent (term tree order)', 0 === $tree_breaks( 'scpo_tree' ) );
		$writes = $order_writes( function () { scpo_private( 'normalize_taxonomy', 'scpo_tree' ); } );
		t( 'a term tree already in order is not rewritten', 0 === $writes, "$writes UPDATEs" );
		rollback();

		// A < B < C by name, so flat and tree numbering agree until C moves.
		begin();
		$a = $term( 'SCPO tree A' );
		$b = $term( 'SCPO tree B' );
		$c = $term( 'SCPO tree C', $b );
		scpo_private( 'normalize_taxonomy', 'scpo_tree' );
		wp_update_term( $c, 'scpo_tree', [ 'parent' => $a ] ); // keeps its term_order
		scpo_private( 'normalize_taxonomy', 'scpo_tree' );
		t( 'a term moved to another parent is renumbered into tree order', 0 === $tree_breaks( 'scpo_tree' ) );
		rollback();

		$sortable = [ 'scpo_tree' ];
		begin();
		$a = $term( 'SCPO tree A' );
		$term( 'SCPO tree B' );
		scpo_private( 'normalize_taxonomy', 'scpo_tree' ); // list screen opened
		$a1 = $quick_add( 'SCPO tree A1', $a );
		list( , $value ) = $term_tree( 'scpo_tree' );
		t( 'a term added with quick-add is in tree order before any reload', 0 === $tree_breaks( 'scpo_tree' ) && $value[ $a1 ] === $value[ $a ] + 1, json_encode( $value ) );
		rollback();

		begin();
		$a = $term( 'SCPO tree A' );
		scpo_private( 'normalize_taxonomy', 'scpo_tree' );
		$writes = $order_writes( function () use ( $term, $a ) { $term( 'SCPO tree A1', $a ); } );
		t( 'a term inserted outside quick-add waits for refresh() (no renumber per insert)', 0 === $writes, "$writes UPDATEs" );
		rollback();

		begin();
		$a = $term( 'SCPO tree A' );
		$b = $term( 'SCPO tree B' );
		scpo_private( 'normalize_taxonomy', 'scpo_tree' );
		// Another request changes B between the read and the locked compare.
		$raced = false;
		$race  = function ( $q ) use ( &$raced, $wpdb, $b ) {
			if ( ! $raced && false !== stripos( $q, 'FOR UPDATE' ) && false !== strpos( $q, $wpdb->terms ) ) {
				$raced = true;
				$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->terms SET term_order = term_order + 100 WHERE term_id = %d", $b ) );
			}
			return $q;
		};
		add_filter( 'query', $race );
		try {
			$a1 = $quick_add( 'SCPO tree A1', $a );
		} finally {
			remove_filter( 'query', $race );
		}
		list( , $value ) = $term_tree( 'scpo_tree' );
		t( 'quick-add reads again after a concurrent change, leaving no term at 0', $raced && 0 === $tree_breaks( 'scpo_tree' ) && $value[ $a1 ] === $value[ $a ] + 1, json_encode( $value ) );
		rollback();

		$sortable = [ 'scpo_flat' ];
		begin();
		$term( 'SCPO flat A', 0, 'scpo_flat' );
		scpo_private( 'normalize_taxonomy', 'scpo_flat' );
		$writes = $order_writes( function () use ( $quick_add ) { $quick_add( 'SCPO flat B', 0, 'scpo_flat' ); } );
		t( 'quick-add in a flat taxonomy does not renumber it', 0 === $writes, "$writes UPDATEs" );
		rollback();

		$sortable = [];
		begin();
		$a = $term( 'SCPO tree A' );
		scpo_private( 'normalize_taxonomy', 'scpo_tree' );
		$writes = $order_writes( function () use ( $quick_add, $a ) { $quick_add( 'SCPO tree A1', $a ); } );
		t( 'quick-add in a taxonomy that is not sorted does not renumber it', 0 === $writes, "$writes UPDATEs" );
		rollback();

		$sortable = [ 'scpo_tree' ];
		begin();
		$a = $term( 'SCPO tree A' );
		$b = $term( 'SCPO tree B' );
		$c = $term( 'SCPO tree C', $b );
		wp_update_term( $c, 'scpo_tree', [ 'parent' => $a ] );
		scpo_private( 'normalize_taxonomy', 'scpo_tree' ); // refresh() on list load
		// On screen: A, C, B. Drag B above A.
		$r = ajax( 'update_menu_order_tags', [ 'nonce' => wp_create_nonce( 'scporder_nonce_action' ), 'order' => $tag_payload( [ $b, $a, $c ] ), 'original_order' => $tag_payload( [ $a, $c, $b ] ) ] );
		t( 'a drag after a term changed parent is saved, not refused as a conflict', ! empty( $r['success'] ), json_encode( $r ) );
		rollback();

		t( 'term tree fixtures were rolled back', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy IN ('scpo_tree', 'scpo_flat')" ) );
	} finally {
		rollback();
		remove_filter( 'option_scporder_options', $enable );
		unregister_taxonomy( 'scpo_tree' );
		unregister_taxonomy( 'scpo_flat' );
	}
}

begin();
update_option( 'scporder_install', 1 ); // flag written by an older version
delete_option( 'scporder_term_order_owner' );
delete_transient( 'scporder_install_failed' );
$engine->scporder_install();
t( 'upgrade re-verifies the column and records it (flag 2)', 2 === (int) get_option( 'scporder_install' ) );
t( 'upgrade does not mark our own column as foreign', false === get_option( 'scporder_term_order_owner', false ) );
rollback();

/* ------------------------------------------------------------------ */
section( 'Reset Order' );

begin();
$r = ajax( 'scpo_ajax_reset_order', [ 'scpo_security' => wp_create_nonce( 'scpo-reset-order' ), 'items' => [ 'nav_menu_item' ], 'action' => 'scpo_reset_order' ] );
t( 'reset refuses types the form does not offer', is_array( $r ) && empty( $r['success'] ), json_encode( $r ) );
rollback();

begin();
$pid = page_order()[5];
get_post( $pid ); // cache it
$r = ajax( 'scpo_ajax_reset_order', [ 'scpo_security' => wp_create_nonce( 'scpo-reset-order' ), 'items' => [ 'portfolio' ], 'action' => 'scpo_reset_order' ] );
$pf = (int) $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type='portfolio' AND post_status='publish' LIMIT 1" );
t( 'reset writes 0 and leaves no stale cached value', ! empty( $r['success'] ) && 0 === (int) get_post( $pf )->menu_order );
$after = get_option( 'scporder_options' );
t( 'reset keeps other settings (show_handle) intact', '1' === $after['show_handle'] && ! in_array( 'portfolio', $after['objects'], true ) && in_array( 'page', $after['objects'], true ) );
rollback();

/* ------------------------------------------------------------------ */
section( 'Misc' );

t( 'review notice class is prefixed', class_exists( 'SCPO_Review_Notice' ) && ! class_exists( 'Simple_Review' ) );
$hdr = get_file_data( SCPORDER_DIR . 'simple-custom-post-order.php', [ 'ra' => 'Requires at least' ] );
t( 'plugin header declares Requires at least', '6.2' === $hdr['ra'] );

begin();
update_option( 'scporder_install', 0 );
$q = new WP_Term_Query( [ 'taxonomy' => 'post_tag', 'number' => 3, 'hide_empty' => false ] );
t( 'term queries skip term_order while the column is not confirmed', false === strpos( (string) $q->request, 'term_order' ) );
rollback();

wp_set_current_user( 0 );
$sub = get_users( [ 'role' => 'subscriber', 'number' => 1, 'fields' => 'ids' ] );
if ( $sub ) {
	begin();
	wp_set_current_user( (int) $sub[0] );
	delete_option( 'scporder_notice' );
	$_POST = $_REQUEST = [ 'scporder_nonce' => wp_create_nonce( 'scporder_dismiss_notice' ), 'action' => 'scporder_dismiss_notices' ];
	add_filter( 'wp_doing_ajax', '__return_true' );
	$h = function () { return function () { throw new ScpoDie(); }; };
	add_filter( 'wp_die_ajax_handler', $h );
	add_filter( 'wp_die_handler', $h );
	ob_start();
	try { $engine->dismiss_notices(); } catch ( ScpoDie $e ) {}
	ob_end_clean();
	t( 'a subscriber cannot dismiss the site-wide setup notice', ! get_option( 'scporder_notice' ) );
	remove_all_filters( 'wp_doing_ajax' );
	rollback();
	wp_set_current_user( (int) ( getenv( 'SCPO_USER' ) ?: 1 ) );
}

/* Failure injection: exercise the real wpdb boundary without persisting writes. */
section( 'Parent changes (drag sideways)' );
$user_before = get_current_user_id();
wp_set_current_user( (int) ( getenv( 'SCPO_USER' ) ?: 1 ) ); // earlier sections leave a non-admin user
$nonce     = wp_create_nonce( 'scporder_nonce_action' );
$why = static function ( $r ): string { return is_array( $r ) ? (string) ( $r['data']['code'] ?? $r['data']['message'] ?? '' ) : 'raw'; };
$refused_as_parent = static function ( $r ): bool { return is_array( $r ) && empty( $r['success'] ) && 'parent' === ( $r['data']['code'] ?? '' ); };
$parent_of = static function ( int $id ): int { clean_post_cache( $id ); return (int) get_post_field( 'post_parent', $id ); };
$leaf_tops = static function ( int $n ) use ( $wpdb ): array {
	return array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts p WHERE post_type='page' AND post_status='publish' AND post_parent=0 AND NOT EXISTS (SELECT 1 FROM $wpdb->posts c WHERE c.post_parent=p.ID AND c.post_type='page' AND c.post_status<>'trash') ORDER BY menu_order, ID LIMIT $n" ) );
};
$pages_payload = static function ( array $ids ): string { return implode( '&', array_map( static function ( $id ) { return 'post[]=' . $id; }, $ids ) ); };
$tops = $leaf_tops( 3 ); // A, B, C: top-level pages without children, in list order
[ $pa, $pb, $pc ] = $tops;
$reparent_on = static function () { return true; };

begin();
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $tops ), 'parents' => [ $pb => $pa ] ] );
t( 'a parent change is refused while the setting is off', $refused_as_parent( $r ) && false !== stripos( $r['data']['message'], 'turned off' ) && 0 === $parent_of( $pb ), json_encode( $r ) );
rollback();

begin();
$o = get_option( 'scporder_options' ); $o['allow_reparent'] = '1'; update_option( 'scporder_options', $o );
add_filter( 'scpo_allow_reparent', '__return_false' );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $tops ), 'parents' => [ $pb => $pa ] ] );
remove_filter( 'scpo_allow_reparent', '__return_false' );
t( 'the scpo_allow_reparent filter can switch the setting off', $refused_as_parent( $r ) && 0 === $parent_of( $pb ), json_encode( $r ) );
rollback();

add_filter( 'scpo_allow_reparent', $reparent_on );

begin();
$saves = 0;
$count = static function () use ( &$saves ) { $saves++; };
add_action( 'save_post_page', $count );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $tops ), 'parents' => [ $pb => $pa ] ] );
remove_action( 'save_post_page', $count );
t( 'nesting a page under the row above makes it a child', ! empty( $r['success'] ) && $pa === $parent_of( $pb ), json_encode( $r ) );
t( 'the parent change goes through wp_update_post (save hooks fire once)', 1 === $saves, "$saves save_post_page calls" );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $tops ), 'parents' => [ $pb => 0 ] ] );
t( 'moving it back out (parent 0) makes it top-level again', ! empty( $r['success'] ) && 0 === $parent_of( $pb ), json_encode( $r ) );
rollback();

begin();
$before    = menu_orders( $tops );
$blog_post = (int) $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' LIMIT 1" );
$reordered = [ $pc, $pa, $pb ];
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $reordered ), 'parents' => [ $pb => $blog_post ] ] );
t( 'a post of another type cannot become the parent', $refused_as_parent( $r ) && 0 === $parent_of( $pb ), json_encode( $r ) );
t( 'a refused parent leaves the order unwritten too', $before == menu_orders( $tops ) );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $tops ), 'parents' => [ $pb => 999999999 ] ] );
t( 'a missing parent is refused', $refused_as_parent( $r ) && 0 === $parent_of( $pb ), $why( $r ) );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $tops ), 'parents' => [ $pb => $pb ] ] );
t( 'a page cannot be its own parent', $refused_as_parent( $r ) && 0 === $parent_of( $pb ), $why( $r ) );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $tops ), 'parents' => [ 999999998 => 0 ] ] );
t( 'a row outside the submitted order cannot be moved', is_array( $r ) && empty( $r['success'] ) && 'Permission denied.' === ( $r['data']['message'] ?? '' ), $why( $r ) );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $tops ), 'parents' => [ $pb => '1 OR 1=1' ] ] );
t( 'a non-numeric parent value is ignored, not cast', ! empty( $r['success'] ) && 0 === $parent_of( $pb ), json_encode( $r ) );
rollback();

begin();
$wpdb->update( $wpdb->posts, [ 'post_parent' => $pb ], [ 'ID' => $pa ] ); clean_post_cache( $pa ); // A is now B's child
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( [ $pb, $pa, $pc ] ), 'parents' => [ $pb => $pa ] ] );
t( 'a page cannot move under its own child (no loops)', $refused_as_parent( $r ) && 0 === $parent_of( $pb ) && $pb === $parent_of( $pa ), json_encode( $r ) );
rollback();

begin();
wp_trash_post( $pa );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( [ $pb, $pc ] ), 'parents' => [ $pb => $pa ] ] );
t( 'a trashed page cannot become the parent', $refused_as_parent( $r ) && 0 === $parent_of( $pb ), $why( $r ) );
rollback();

begin();
// A reorder whose snapshot (relative order) no longer matches the database (another tab saved in
// between). Submitting the stored order unchanged would be a harmless replay.
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( [ $pc, $pa, $pb ] ), 'original_values' => wp_json_encode( [ $pa => 9003, $pb => 9002, $pc => 9001 ] ), 'parents' => [ $pb => $pa ] ] );
t( 'a stale list refuses the parent change before anything is written', is_array( $r ) && empty( $r['success'] ) && ! empty( $r['data']['reload'] ) && 'parent' !== ( $r['data']['code'] ?? '' ) && 0 === $parent_of( $pb ), json_encode( $r ) );
rollback();

begin();
$blog = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' ORDER BY menu_order LIMIT 2" ) );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( $blog ), 'parents' => [ $blog[1] => $blog[0] ] ] );
t( 'a non-hierarchical post type cannot be nested', $refused_as_parent( $r ) && 0 === $parent_of( $blog[1] ), json_encode( $r ) );
rollback();

begin();
$editor = wp_insert_user( [ 'user_login' => 'scpo_editor_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ] );
$author = wp_insert_user( [ 'user_login' => 'scpo_author_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
$admin  = get_current_user_id();
wp_set_current_user( $author );
$r = ajax( 'update_menu_order', [ 'nonce' => wp_create_nonce( 'scporder_nonce_action' ), 'order' => $pages_payload( $tops ), 'parents' => [ $pb => $pa ] ] );
t( 'an author, who cannot edit pages, cannot re-parent them', is_array( $r ) && empty( $r['success'] ) && 'Permission denied.' === ( $r['data']['message'] ?? '' ) && 0 === $parent_of( $pb ), $why( $r ) );
wp_set_current_user( $editor );
$r = ajax( 'update_menu_order', [ 'nonce' => wp_create_nonce( 'scporder_nonce_action' ), 'order' => $pages_payload( $tops ), 'parents' => [ $pb => $pa ] ] );
t( 'an editor can re-parent pages', ! empty( $r['success'] ) && $pa === $parent_of( $pb ), json_encode( $r ) );
wp_set_current_user( $admin );
rollback();

begin();
// Same slug under the new parent: WordPress keeps sibling slugs unique, as in Quick Edit.
$wpdb->update( $wpdb->posts, [ 'post_name' => 'scpo-same-slug' ], [ 'ID' => $pb ] );
$twin = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Twin', 'post_name' => 'scpo-same-slug', 'post_parent' => $pa ] );
clean_post_cache( $pb );
$r = ajax( 'update_menu_order', [ 'nonce' => $nonce, 'order' => $pages_payload( [ $pa, $twin, $pb, $pc ] ), 'parents' => [ $pb => $pa ] ] );
clean_post_cache( $pb );
t( 'a moved page whose slug a new sibling already uses gets a unique slug', ! empty( $r['success'] ) && $pa === $parent_of( $pb ) && 'scpo-same-slug' !== get_post( $pb )->post_name, get_post( $pb )->post_name );
rollback();

begin();
$cats = array_map( 'intval', $wpdb->get_col( "SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id=tt.term_id WHERE tt.taxonomy='category' AND tt.parent=0 AND NOT EXISTS (SELECT 1 FROM $wpdb->term_taxonomy c WHERE c.parent=t.term_id) ORDER BY t.term_order, t.term_id LIMIT 3" ) );
$tag_payload_rp = static function ( array $ids ): string { return implode( '&', array_map( static function ( $id ) { return 'tag[]=' . $id; }, $ids ) ); };
$term_parent = static function ( int $id ): int { clean_term_cache( $id ); return (int) get_term( $id )->parent; };
$r = ajax( 'update_menu_order_tags', [ 'nonce' => $nonce, 'order' => $tag_payload_rp( $cats ), 'parents' => [ $cats[1] => $cats[0] ] ] );
t( 'nesting a category under the row above makes it a child', ! empty( $r['success'] ) && $cats[0] === $term_parent( $cats[1] ), json_encode( $r ) );
$r = ajax( 'update_menu_order_tags', [ 'nonce' => $nonce, 'order' => $tag_payload_rp( $cats ), 'parents' => [ $cats[0] => $cats[1] ] ] );
t( 'a category cannot move under its own child', $refused_as_parent( $r ) && 0 === $term_parent( $cats[0] ), json_encode( $r ) );
$tag = (int) $wpdb->get_var( "SELECT term_id FROM $wpdb->term_taxonomy WHERE taxonomy='post_tag' LIMIT 1" );
$r = ajax( 'update_menu_order_tags', [ 'nonce' => $nonce, 'order' => $tag_payload_rp( $cats ), 'parents' => [ $cats[2] => $tag ] ] );
t( 'a term from another taxonomy cannot become the parent', $refused_as_parent( $r ) && 0 === $term_parent( $cats[2] ), json_encode( $r ) );
$tags = array_map( 'intval', $wpdb->get_col( "SELECT term_id FROM $wpdb->term_taxonomy WHERE taxonomy='post_tag' LIMIT 2" ) );
$r = ajax( 'update_menu_order_tags', [ 'nonce' => $nonce, 'order' => $tag_payload_rp( $tags ), 'parents' => [ $tags[1] => $tags[0] ] ] );
t( 'a non-hierarchical taxonomy cannot be nested', $refused_as_parent( $r ) && 0 === $term_parent( $tags[1] ), json_encode( $r ) );
rollback();

remove_filter( 'scpo_allow_reparent', $reparent_on );
wp_set_current_user( $user_before );

/* ------------------------------------------------------------------ */
section( 'Database failures and query opt-out' );
$nonce = wp_create_nonce( 'scporder_nonce_action' );
foreach ( [ 'update_menu_order', 'scpo_ajax_set_position', 'scpo_ajax_reset_order' ] as $method ) {
	begin();
	$before_options = get_option( 'scporder_options' );
	$before_hooks = did_action( 'scp_update_menu_order' );
	$ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' ORDER BY menu_order LIMIT 2" );
	$reject_update = function ( $sql ) use ( $wpdb ) {
		return 0 === strpos( $sql, "UPDATE $wpdb->posts SET" ) ? '' : $sql;
	};
	add_filter( 'query', $reject_update );
	try {
		$r = ajax( $method, [ 'nonce' => $nonce, 'scpo_security' => wp_create_nonce( 'scpo-reset-order' ), 'order' => "post[]={$ids[1]}&post[]={$ids[0]}", 'id' => $ids[1], 'position' => 1, 'items' => [ 'post' ] ] );
	} finally {
		remove_filter( 'query', $reject_update );
	}
	t( "$method reports a failed database write", is_array( $r ) && empty( $r['success'] ) );
	t( "$method does not emit a success action after failure", $before_hooks === did_action( 'scp_update_menu_order' ) );
	t( "$method preserves enabled types after failure", $before_options === get_option( 'scporder_options' ) );
	rollback();
}
$q = new WP_Query( [ 'post_type' => 'post', 'orderby' => 'title', 'order' => 'DESC', 'suppress_filters' => false, 'scpo_ignore_custom_order' => true, 'fields' => 'ids' ] );
t( 'query opt-out keeps explicit descending sort with suppress_filters present', false !== strpos( $q->request, 'post_title DESC' ) );

echo "\nRESULT: {$GLOBALS['scpo_results']['pass']} passed, {$GLOBALS['scpo_results']['fail']} failed\n";
if ( $GLOBALS['scpo_results']['failed'] ) {
	echo 'Failed: ' . implode( '; ', $GLOBALS['scpo_results']['failed'] ) . "\n";
}

exit( $GLOBALS['scpo_results']['fail'] > 0 ? 1 : 0 );
