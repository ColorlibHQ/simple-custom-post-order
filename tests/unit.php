<?php
/**
 * Isolated failure-injection tests. Run with `php tests/unit.php`.
 * WordPress/database boundaries are doubles; the actual plugin is loaded below.
 * SCPO_PLUGIN_FILE can select an older release to verify regression detection.
 */
if ( defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'Run this suite directly, outside WordPress.' );
}
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private $message;
	private $code_name;
	function __construct( $code, $message ) { $this->message = $message; $this->code_name = $code; }
	function get_error_code() { return $this->code_name; }
	function get_error_message() { return $this->message; }
}
class WP_Post {
	public $ID;
	public $post_type = 'post';
	function __construct( $id ) { $this->ID = $id; }
}
class WP_Term {
	public $taxonomy = 'post_tag';
}
class WP_Query {
	public $query;
	function __construct( $args ) { $this->query = $args; }
	function get( $key ) { return $this->query[ $key ] ?? ''; }
	function set( $key, $value ) { $this->query[ $key ] = $value; }
	function is_search() { return false; }
}
class ScpoUnitResponse extends RuntimeException {
	public $success;
	public $status;
	public $data;
	function __construct( $success, $data, $status ) {
		$this->data = $data;
		parent::__construct( $data['message'] ?? '' );
		$this->success = $success;
		$this->status = $status;
	}
}
class ScpoUnitDB {
	public $posts = 'wp_posts';
	public $terms = 'wp_terms';
	public $last_error = '';
	public $rows = [ [ 1, 1 ], [ 2, 2 ] ];
	public $read_failure = false;
	public $fail_batch = 0;
	public $zero_rows = false;
	public $writes = 0;
	public $engine = 'InnoDB';
	public $packet = 67108864;
	public $queries = [];
	public $status_engine = null;
	public $in_transaction = false;
	public $commits = 0;
	public $rollbacks = 0;
	public $lock_rows = null;
	function get_var( $sql ) { return 'SELECT @@max_allowed_packet' === $sql ? $this->packet : $this->engine; }
	function get_row( $sql, $output = null ) { return null === $this->status_engine ? null : [ 'Engine' => $this->status_engine ]; }
	function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	function suppress_errors( $suppress ) { return false; }
	function prepare( $query, ...$args ) { return $query; }
	function get_results( ...$args ) {
		$this->last_error = $this->read_failure ? 'Simulated private database error' : '';
		return $this->read_failure ? [] : ( false !== strpos( $args[0] ?? '', 'FOR UPDATE' ) && null !== $this->lock_rows ? $this->lock_rows : $this->rows );
	}
	function get_col( ...$args ) { return array_column( $this->get_results(), 0 ); }
	function query( $sql ) {
		$this->queries[] = $sql;
		if ( 0 === strpos( $sql, 'SAVEPOINT' ) || 0 === strpos( $sql, 'RELEASE SAVEPOINT' ) ) { return 0; }
		if ( 0 === strpos( $sql, 'ROLLBACK TO SAVEPOINT' ) ) { return $this->in_transaction ? 0 : false; }
		if ( 'START TRANSACTION' === $sql ) { $this->in_transaction = true; return 0; }
		if ( 'COMMIT' === $sql ) { $this->commits++; $this->in_transaction = false; return 0; }
		if ( 'ROLLBACK' === $sql ) { $this->rollbacks++; $this->in_transaction = false; return 0; }
		$this->writes++;
		$this->last_error = $this->writes === $this->fail_batch ? 'Simulated private database error' : '';
		return $this->last_error ? false : ( $this->zero_rows ? 0 : 1 );
	}
}

function plugins_url() { return ''; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( $file ); }
function add_action() {}
function add_filter() {}
function register_uninstall_hook() {}
function apply_filters( $name, $value ) { return $value; }
function get_option( $name, $default = false ) { return $GLOBALS['scpo_options'][ $name ] ?? $default; }
function update_option( $name, $value ) {
	if ( $GLOBALS['scpo_option_failure'] ) { return false; }
	$GLOBALS['scpo_options'][ $name ] = $value;
	return true;
}
function current_user_can() { return $GLOBALS['scpo_can_edit']; }
function check_ajax_referer() {}
function wp_unslash( $value ) { return $value; }
function _prime_post_caches() {}
function _prime_term_caches() {}
function get_post( $id ) { return new WP_Post( $id ); }
function get_term( $id ) { return new WP_Term(); }
function get_taxonomy() { return (object) [ 'cap' => (object) [ 'manage_terms' => 'manage_categories' ] ]; }
function clean_post_cache( $id ) { $GLOBALS['scpo_invalidated'][] = $id; }
function clean_term_cache( $ids, $taxonomy ) { $GLOBALS['scpo_invalidated'] = array_merge( $GLOBALS['scpo_invalidated'], $ids ); }
function wp_cache_delete_multiple( $ids, $group ) { $GLOBALS['scpo_invalidated'] = array_merge( $GLOBALS['scpo_invalidated'], $ids ); }
function wp_cache_set_posts_last_changed() {}
function wp_cache_set_terms_last_changed() {}
function __() { return func_get_arg( 0 ); }
function do_action( $name ) { $GLOBALS['scpo_actions'][] = $name; }
function wp_send_json_success( $data, $status = 200 ) { throw new ScpoUnitResponse( true, $data, $status ); }
function wp_send_json_error( $data, $status = 200 ) { throw new ScpoUnitResponse( false, $data, $status ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_post_type_hierarchical() { return false; }
function get_post_stati() { return [ 'publish' ]; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return $value; }
function get_post_types() { return [ 'post' ]; }

function scpo_unit_reset() {
	$GLOBALS['wpdb'] = new ScpoUnitDB();
	$GLOBALS['scpo_options'] = [ 'scporder_install' => 2, 'scporder_options' => [ 'objects' => [ 'post' ], 'tags' => [ 'post_tag' ] ] ];
	$GLOBALS['scpo_actions'] = $GLOBALS['scpo_invalidated'] = [];
	$GLOBALS['scpo_option_failure'] = false;
	$GLOBALS['scpo_can_edit'] = true;
	$_POST = [];
}
function scpo_unit_ajax( $method, $post ) {
	$_POST = $post;
	try { $GLOBALS['scporder']->$method(); }
	catch ( ScpoUnitResponse $response ) { return $response; }
	throw new RuntimeException( 'Handler did not return a response.' );
}
function scpo_unit_check( $name, $condition ) {
	$GLOBALS['scpo_checks']++;
	if ( ! $condition ) { $GLOBALS['scpo_failures']++; }
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}

scpo_unit_reset();
$GLOBALS['scpo_checks'] = $GLOBALS['scpo_failures'] = 0;
require getenv( 'SCPO_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/simple-custom-post-order.php';

foreach ( [ 'update_menu_order', 'update_menu_order_tags', 'scpo_ajax_set_position' ] as $method ) {
	foreach ( [ 'write failure', 'read failure', 'success', 'zero affected rows', 'permission denied', 'missing row' ] as $scenario ) {
		scpo_unit_reset();
		$wpdb->fail_batch = 'write failure' === $scenario ? 1 : 0;
		$wpdb->read_failure = 'read failure' === $scenario;
		$wpdb->zero_rows = 'zero affected rows' === $scenario;
		$GLOBALS['scpo_can_edit'] = 'permission denied' !== $scenario;
		if ( 'missing row' === $scenario ) { $wpdb->rows = [ [ 1, 1 ] ]; }
		$r = scpo_unit_ajax( $method, [ 'order' => 'post[]=2&post[]=1', 'id' => 2, 'position' => 1 ] );
		$expect_success = in_array( $scenario, [ 'success', 'zero affected rows' ], true );
		scpo_unit_check( "$method: $scenario response", $r->success === $expect_success );
		scpo_unit_check( "$method: $scenario success hooks", $expect_success === ! empty( $GLOBALS['scpo_actions'] ) );
		if ( in_array( $scenario, [ 'read failure', 'missing row', 'permission denied' ], true ) ) {
			scpo_unit_check( "$method: $scenario performs no writes", 0 === $wpdb->writes );
		}
		if ( in_array( $scenario, [ 'read failure', 'write failure' ], true ) ) {
			scpo_unit_check( "$method: $scenario has HTTP 500 without private SQL details", 500 === $r->status && false === strpos( $r->getMessage(), 'private database' ) );
		}
	}
}

scpo_unit_reset();
$writer = new ReflectionMethod( 'SCPO_Engine', 'write_order_values' );
if ( PHP_VERSION_ID < 80100 ) { $writer->setAccessible( true ); }
$values = array_combine( range( 1, 2501 ), range( 1, 2501 ) );
$wpdb->fail_batch = 2;
$r = $writer->invoke( $scporder, $wpdb->posts, 'ID', 'menu_order', $values );
scpo_unit_check( 'partial failure rolls back and stops before batch three', is_wp_error( $r ) && 2 === $wpdb->writes && 1 === $wpdb->rollbacks && 0 === $wpdb->commits );
scpo_unit_check( 'partial failure invalidates saved and attempted rows only', $GLOBALS['scpo_invalidated'] === range( 1, 2000 ) );
scpo_unit_reset();
$r = $writer->invoke( $scporder, $wpdb->posts, 'ID', 'menu_order', [ 1 => 5 ], '', [ 1 => 5 ] );
scpo_unit_check( 'unchanged values need no writes or invalidation', [] === $r && 0 === $wpdb->writes && [] === $GLOBALS['scpo_invalidated'] );

scpo_unit_reset();
$wpdb->lock_rows = [ [ 1, 2 ], [ 2, 1 ] ];
$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=2&post[]=1' ] );
scpo_unit_check( 'race after initial read returns conflict without writes', ! $r->success && 409 === $r->status && 0 === $wpdb->writes && 1 === $wpdb->rollbacks );
scpo_unit_reset();
$wpdb->engine = 'MyISAM';
$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=2&post[]=1' ] );
scpo_unit_check( 'MyISAM saves with one guarded statement and no transaction', $r->success && 1 === $wpdb->writes && 0 === $wpdb->commits );
scpo_unit_reset();
$wpdb->engine = null;
$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=2&post[]=1' ] );
scpo_unit_check( 'unknown metadata uses the guarded path without assuming transactions', $r->success && 1 === $wpdb->writes && 0 === $wpdb->commits );
scpo_unit_reset();
$wpdb->engine = null;
$wpdb->status_engine = 'MyISAM';
$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=2&post[]=1' ] );
scpo_unit_check( 'SHOW TABLE STATUS fallback enables MyISAM compatibility', $r->success && 1 === $wpdb->writes );
foreach ( [ 'MyISAM', 'Aria', null ] as $storage ) {
	foreach ( [ 'success', 'write failure', 'zero rows', 'packet limit' ] as $scenario ) {
		scpo_unit_reset();
		$wpdb->engine = $storage;
		$wpdb->fail_batch = 'write failure' === $scenario ? 1 : 0;
		$wpdb->zero_rows = 'zero rows' === $scenario;
		$wpdb->packet = 'packet limit' === $scenario ? 1024 : 67108864;
		$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=2&post[]=1' ] );
		scpo_unit_check( "$storage guarded writer: $scenario", ( 'success' === $scenario ) === $r->success && 0 === $wpdb->commits && 0 === $wpdb->rollbacks );
		scpo_unit_check( "$storage guarded writer never starts a transaction or locks tables explicitly: $scenario", ! array_filter( $wpdb->queries, static function ( $q ) { return preg_match( '/^(START TRANSACTION|LOCK TABLES|UNLOCK TABLES|SAVEPOINT)/', $q ); } ) );
		if ( 'write failure' === $scenario || 'zero rows' === $scenario ) {
			scpo_unit_check( "$storage uncertain/conflicting save invalidates its snapshot", $GLOBALS['scpo_invalidated'] === [ 1, 2 ] );
		}
		if ( 'packet limit' === $scenario ) { scpo_unit_check( "$storage oversized saves fail before any write", 0 === $wpdb->writes ); }
	}
}
scpo_unit_reset();
$wpdb->engine = 'MyISAM';
$r = $writer->invoke( $scporder, $wpdb->posts, 'ID', 'menu_order', [ 1 => 2147483648 ], '', [ 1 => 1 ] );
scpo_unit_check( 'guarded writer refuses integer overflow before writing', is_wp_error( $r ) && 0 === $wpdb->writes );
scpo_unit_reset();
$wpdb->engine = 'CSV';
$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=2&post[]=1' ] );
scpo_unit_check( 'unsupported storage still refuses writes', ! $r->success && 0 === $wpdb->writes );

scpo_unit_reset();
$wpdb->rows = [ [ 1, 2 ], [ 2, 1 ], [ 3, 3 ] ];
$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=3&post[]=1&post[]=2', 'original_order' => 'post[]=1&post[]=2&post[]=3' ] );
scpo_unit_check( 'stale browser order cannot overwrite another save', ! $r->success && 409 === $r->status && 0 === $wpdb->writes );
$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=2&post[]=1&post[]=3', 'original_order' => 'post[]=1&post[]=2&post[]=3' ] );
scpo_unit_check( 'retry after a lost success response is idempotent', $r->success && 0 === $wpdb->writes );
foreach ( [ 'update_menu_order', 'update_menu_order_tags' ] as $method ) {
	scpo_unit_reset();
	$wpdb->rows = [ [ 1, 2 ], [ 2, 1 ], [ 3, 3 ] ];
	$r = scpo_unit_ajax( $method, [ 'order' => 'item[]=3&item[]=1&item[]=2', 'original_order' => 'item[]=1&item[]=2&item[]=3', 'original_values' => '{"1":2,"2":1,"3":3}' ] );
	scpo_unit_check( "$method: unchanged nonmonotonic display order can be saved", $r->success && 1 === $wpdb->writes );
	scpo_unit_reset();
	$wpdb->rows = [ [ 1, 11 ], [ 2, 12 ], [ 3, 13 ] ];
	$r = scpo_unit_ajax( $method, [ 'order' => 'item[]=3&item[]=1&item[]=2', 'original_order' => 'item[]=1&item[]=2&item[]=3', 'original_values' => '{"1":1,"2":2,"3":3}' ] );
	scpo_unit_check( "$method: harmless renumbering preserves the snapshot order", $r->success && 1 === $wpdb->writes );
	scpo_unit_reset();
	$wpdb->rows = [ [ 1, 2 ], [ 2, 1 ], [ 3, 3 ] ];
	$r = scpo_unit_ajax( $method, [ 'order' => 'item[]=3&item[]=1&item[]=2', 'original_values' => '{"1":1,"2":2,"3":3}' ] );
	scpo_unit_check( "$method: an actual order reversal conflicts without writes", ! $r->success && 409 === $r->status && 0 === $wpdb->writes );
	scpo_unit_reset();
	$wpdb->rows = [ [ 1, 1 ], [ 2, 3 ], [ 3, 2 ] ];
	$r = scpo_unit_ajax( $method, [ 'order' => 'item[]=2&item[]=1&item[]=3', 'original_values' => '{"1":1,"2":2,"3":2}' ] );
	scpo_unit_check( "$method: quick-add normalization may split a tied snapshot group", $r->success && 1 === $wpdb->writes );
	foreach ( [ 'bad json', '{"1":1}', '{"1":1,"2":2,"3":3,"4":4}', '{"1":1,"2":"2","3":3}', '[]' ] as $bad ) {
		scpo_unit_reset();
		$r = scpo_unit_ajax( $method, [ 'order' => 'item[]=2&item[]=1', 'original_values' => $bad ] );
		scpo_unit_check( "$method: malformed/incomplete snapshot is refused: $bad", ! $r->success && 0 === $wpdb->writes );
	}
	scpo_unit_reset();
	$wpdb->rows = [ [ 1, 2 ], [ 2, 1 ], [ 3, 3 ] ];
	$r = scpo_unit_ajax( $method, [ 'order' => 'item[]=2&item[]=1&item[]=3', 'original_values' => '{"1":1,"2":2,"3":3}' ] );
	scpo_unit_check( "$method: snapshot retry after lost success is idempotent", $r->success && 0 === $wpdb->writes );
}

scpo_unit_reset();
$wpdb->rows = [ [ 1, 1 ], [ 2, 2 ], [ 3, 3 ] ];
$r = scpo_unit_ajax( 'scpo_ajax_set_position', [ 'id' => 2, 'position' => 1, 'original_position' => '3' ] );
scpo_unit_check( 'stale Order column is rejected', ! $r->success && 409 === $r->status && 0 === $wpdb->writes );
scpo_unit_reset();
$wpdb->in_transaction = true;
$r = scpo_unit_ajax( 'update_menu_order', [ 'order' => 'post[]=2&post[]=1' ] );
scpo_unit_check( 'caller transaction is never committed', $r->success && $wpdb->in_transaction && 0 === $wpdb->commits );

foreach ( [ 'write failure', 'read failure', 'option failure', 'success', 'zero affected rows' ] as $scenario ) {
	scpo_unit_reset();
	$wpdb->fail_batch = 'write failure' === $scenario ? 1 : 0;
	$wpdb->read_failure = 'read failure' === $scenario;
	$wpdb->zero_rows = 'zero affected rows' === $scenario;
	$GLOBALS['scpo_option_failure'] = 'option failure' === $scenario;
	$r = scpo_unit_ajax( 'scpo_ajax_reset_order', [ 'items' => [ 'post' ] ] );
	$expect_success = in_array( $scenario, [ 'success', 'zero affected rows' ], true );
	scpo_unit_check( "reset: $scenario response", $expect_success === $r->success );
	scpo_unit_check( "reset: $scenario preserves settings unless successful", $expect_success === empty( get_option( 'scporder_options' )['objects'] ) );
	if ( 'read failure' === $scenario ) { scpo_unit_check( 'reset read failure performs no writes', 0 === $wpdb->writes ); }
}

if ( method_exists( 'SCPO_Engine', 'order_snapshot_matches' ) ) {
	$matcher = new ReflectionMethod( 'SCPO_Engine', 'order_snapshot_matches' );
	if ( PHP_VERSION_ID < 80100 ) { $matcher->setAccessible( true ); }
	$profiles = [];
	foreach ( [ -1, 0, 1, null ] as $a ) {
		foreach ( [ -1, 0, 1, null ] as $b ) {
			foreach ( [ -1, 0, 1, null ] as $c ) { $profiles[] = [ 1 => $a, 2 => $b, 3 => $c ]; }
		}
	}
	$correct = true;
	foreach ( $profiles as $expected ) {
		foreach ( $profiles as $actual ) {
			$inversion = false;
			foreach ( $expected as $i => $left ) {
				foreach ( $expected as $j => $right ) {
					if ( (int) $left < (int) $right && (int) $actual[$i] > (int) $actual[$j] ) { $inversion = true; }
				}
			}
			if ( $matcher->invoke( $scporder, $expected, $actual ) !== ! $inversion ) { $correct = false; }
		}
	}
	scpo_unit_check( '4096 snapshots with ties, negatives, zero, and NULL agree with pairwise order reversals', $correct );
}

scpo_unit_reset();
foreach ( [ 'title', 'date', 'menu_order' ] as $sort ) {
	$args = [ 'post_type' => 'post', 'orderby' => $sort, 'order' => 'DESC', 'suppress_filters' => false, 'scpo_ignore_custom_order' => true ];
	$q = new WP_Query( $args );
	$scporder->scporder_pre_get_posts( $q );
	scpo_unit_check( "query opt-out preserves explicit $sort DESC", $args === $q->query );
}
$q = new WP_Query( [ 'post_type' => 'post', 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true ] );
$scporder->scporder_pre_get_posts( $q );
scpo_unit_check( 'legacy get_posts defaults still get manual ascending order', 'menu_order' === $q->get( 'orderby' ) && 'ASC' === $q->get( 'order' ) );

printf( "\nUNIT RESULT: %d passed, %d failed\n", $GLOBALS['scpo_checks'] - $GLOBALS['scpo_failures'], $GLOBALS['scpo_failures'] );
exit( $GLOBALS['scpo_failures'] ? 1 : 0 );
