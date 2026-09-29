<?php
// Save every menu_order / term_order and the plugin's options, so a test run
// that makes real saves (e2e.mjs) can be undone with restore.php.
// Usage: wp eval-file tests/snapshot.php   (SCPO_SNAPSHOT overrides the file path)
global $wpdb;
$file = getenv( 'SCPO_SNAPSHOT' ) ?: sys_get_temp_dir() . '/scpo-snapshot.json';
$s    = [
	'posts' => $wpdb->get_results( "SELECT ID, menu_order FROM $wpdb->posts", ARRAY_N ),
	'terms' => $wpdb->get_results( "SELECT term_id, term_order FROM $wpdb->terms", ARRAY_N ),
	'opts'  => [],
];
foreach ( [ 'scporder_options', 'scporder_install', 'scporder_notice', 'simple-rate-time' ] as $o ) {
	$s['opts'][ $o ] = get_option( $o, null );
}
file_put_contents( $file, wp_json_encode( $s ) );
echo 'Snapshot of ' . count( $s['posts'] ) . ' posts and ' . count( $s['terms'] ) . " terms written to $file\n";
