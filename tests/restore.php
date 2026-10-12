<?php
// Restore menu_order / term_order / plugin options from the snapshot written by snapshot.php.
// Usage: wp eval-file tests/restore.php   (SCPO_SNAPSHOT overrides the file path)
global $wpdb;
$s = json_decode( file_get_contents( getenv( 'SCPO_SNAPSHOT' ) ?: sys_get_temp_dir() . '/scpo-snapshot.json' ), true );
if ( ! is_array( $s ) || ! isset( $s['posts'], $s['terms'], $s['opts'] ) ) {
	WP_CLI::error( 'Invalid snapshot; restoration was not attempted.' );
}
$n = 0;
foreach ( array_chunk( $s['posts'], 500 ) as $chunk ) {
	$case = ''; $ids = [];
	foreach ( $chunk as $r ) { $case .= sprintf( ' WHEN %d THEN %d', $r[0], $r[1] ); $ids[] = (int) $r[0]; }
	$n += $wpdb->query( "UPDATE $wpdb->posts SET menu_order = CASE ID$case END WHERE ID IN (" . implode( ',', $ids ) . ')' );
}
foreach ( array_chunk( $s['terms'], 500 ) as $chunk ) {
	$case = ''; $ids = [];
	foreach ( $chunk as $r ) { $case .= sprintf( ' WHEN %d THEN %s', $r[0], null === $r[1] ? 'NULL' : (int) $r[1] ); $ids[] = (int) $r[0]; }
	$n += $wpdb->query( "UPDATE $wpdb->terms SET term_order = CASE term_id$case END WHERE term_id IN (" . implode( ',', $ids ) . ')' );
}
foreach ( $s['opts'] as $k => $v ) {
	if ( null === $v ) { delete_option( $k ); } else { remove_all_filters( "sanitize_option_$k" ); update_option( $k, $v ); }
}
wp_cache_flush();
// Verify identifiers as well as values to catch leaked fixtures or removed content.
$actual = [
	'posts' => $wpdb->get_results( "SELECT ID, menu_order FROM $wpdb->posts", ARRAY_N ),
	'terms' => $wpdb->get_results( "SELECT term_id, term_order FROM $wpdb->terms", ARRAY_N ),
];
foreach ( $actual as $kind => $rows ) {
	$expected_orders = array_column( $s[ $kind ], 1, 0 );
	$actual_orders   = array_column( $rows, 1, 0 );
	ksort( $expected_orders );
	ksort( $actual_orders );
	if ( $expected_orders !== $actual_orders ) {
		WP_CLI::error( "Restored $kind do not match the snapshot; inspect the site for changed or leaked fixtures." );
	}
}
foreach ( $s['opts'] as $key => $value ) {
	if ( get_option( $key, null ) !== $value ) {
		WP_CLI::error( "Restored option $key does not match the snapshot." );
	}
}
echo "restored rows changed: $n\n";
WP_CLI::success( 'Post/term identifiers, orders, and plugin options match the snapshot.' );
