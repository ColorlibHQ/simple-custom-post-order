<?php
// Restore menu_order / term_order / plugin options from the snapshot written by snapshot.php.
// Usage: wp eval-file tests/restore.php   (SCPO_SNAPSHOT overrides the file path)
global $wpdb;
$s = json_decode( file_get_contents( getenv( 'SCPO_SNAPSHOT' ) ?: sys_get_temp_dir() . '/scpo-snapshot.json' ), true );
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
echo "restored rows changed: $n\n";
