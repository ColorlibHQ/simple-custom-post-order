<?php
/**
 * Real MySQL/InnoDB checks against the installed plugin via wp eval-file.
 * Creates a uniquely named scratch table and drops it in finally; no content
 * tables are modified. Do not run on production.
 */
global $wpdb;
$engine = ( new ReflectionClass( 'SCPO_Engine' ) )->newInstanceWithoutConstructor();
$writer = new ReflectionMethod( 'SCPO_Engine', 'write_order_values' );
if ( PHP_VERSION_ID < 80100 ) { $writer->setAccessible( true ); }
$table = $wpdb->prefix . 'scpo_test_' . bin2hex( random_bytes( 6 ) );
$pass = 0;
$fail = 0;
$check = static function ( $name, $ok ) use ( &$pass, &$fail ) {
	if ( $ok ) { $pass++; } else { $fail++; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
};
$read = static function () use ( $wpdb, $table ) {
	return array_map( 'intval', array_column( $wpdb->get_results( "SELECT ID, menu_order FROM $table ORDER BY ID", ARRAY_N ), 1, 0 ) );
};
$write = static function ( $values, $current ) use ( $writer, $engine, $wpdb, $table ) {
	return $writer->invoke( $engine, $table, 'ID', 'menu_order', $values, '', $current );
};
$filter = null;
try {
	if ( false === $wpdb->query( "CREATE TABLE $table (ID bigint unsigned PRIMARY KEY, menu_order int NOT NULL) ENGINE=InnoDB" ) ) {
		throw new RuntimeException( 'Could not create isolated transaction fixture.' );
	}
	foreach ( array_chunk( range( 1, 1100 ), 500 ) as $ids ) {
		$values = implode( ',', array_map( static function ( $id ) { return "($id,$id)"; }, $ids ) );
		$wpdb->query( "INSERT INTO $table VALUES $values" );
	}
	$original = $read();
	$changed = array_map( static function ( $value ) { return $value + 2000; }, $original );
	$calls = 0;
	$filter = static function ( $sql ) use ( &$calls, $table ) {
		if ( 0 === strpos( $sql, "UPDATE $table SET" ) && ++$calls === 2 ) { return ''; }
		return $sql;
	};
	add_filter( 'query', $filter );
	$result = $write( $changed, $original );
	remove_filter( 'query', $filter );
	$check( 'second-batch failure is reported', is_wp_error( $result ) && 2 === $calls );
	$check( 'first batch was rolled back too', $original === $read() );

	$result = $write( $changed, $original );
	$check( 'all batches commit on success', ! is_wp_error( $result ) && $changed === $read() );

	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( "INSERT INTO $table VALUES (1101,1101)" );
	$current = $read();
	$result = $write( [ 1 => 9000 ], $current );
	$check( 'write succeeds inside caller transaction', ! is_wp_error( $result ) && 9000 === $read()[1] );
	$wpdb->query( 'ROLLBACK' );
	$check( 'caller rollback undoes its insert and the plugin write', $changed === $read() );

	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( "INSERT INTO $table VALUES (1101,1101)" );
	$current = $read();
	$calls = 0;
	add_filter( 'query', $filter );
	$result = $write( $original, $current );
	remove_filter( 'query', $filter );
	$check( 'failed nested write rolls back only its own batches', is_wp_error( $result ) && $current === $read() );
	$wpdb->query( 'ROLLBACK' );
	$check( 'outer transaction remains rollbackable after failure', $changed === $read() );

	// A separate connection changes the row after this request read its order.
	$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$other->query( "UPDATE $table SET menu_order=8000 WHERE ID=1" );
	$result = $write( $original, $changed );
	$check( 'concurrent connection is detected before overwriting', is_wp_error( $result ) && 'scpo_order_conflict' === $result->get_error_code() && 8000 === $read()[1] );
	$other->close();

	$before = $read();
	$filter = static function ( $sql ) { return 'COMMIT' === $sql ? '' : $sql; };
	add_filter( 'query', $filter );
	$result = $write( $original, $before );
	remove_filter( 'query', $filter );
	$check( 'failed commit does not report success', is_wp_error( $result ) && $before === $read() );

	$wpdb->query( "ALTER TABLE $table ENGINE=MyISAM" );
	$result = $write( $original, $before );
	$check( 'nontransactional engine is rejected without data changes', is_wp_error( $result ) && $before === $read() );
} catch ( Throwable $error ) {
	$check( $error->getMessage(), false );
} finally {
	if ( $filter ) { remove_filter( 'query', $filter ); }
	$wpdb->query( 'ROLLBACK' );
	$wpdb->query( "DROP TABLE IF EXISTS $table" );
}
echo "TRANSACTION RESULT: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
