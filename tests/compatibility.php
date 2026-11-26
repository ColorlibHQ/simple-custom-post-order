<?php
/** Real engine compatibility checks; scratch tables only. Run via wp eval-file. */
global $wpdb;
$site_db = $wpdb;
$host = getenv( 'SCPO_COMPAT_HOST' );
if ( $host ) {
	$wpdb = new wpdb( 'root', '', 'scpo_compat', $host );
}
$storage_engines = $host ? [ 'InnoDB', 'MyISAM', 'Aria' ] : [ 'InnoDB', 'MyISAM' ];
$plugin = ( new ReflectionClass( 'SCPO_Engine' ) )->newInstanceWithoutConstructor();
$writer = new ReflectionMethod( 'SCPO_Engine', 'write_order_values' );
if ( PHP_VERSION_ID < 80100 ) { $writer->setAccessible( true ); }
$pass = 0; $fail = 0;
$check = static function ( $name, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	$ok ? $pass++ : $fail++;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( ! $ok && $detail ? ' -- ' . $detail : '' ) . "\n";
};
$tables = []; $filter = null; $trigger = null;
$suppressed = $wpdb->suppress_errors( true );
try {
	foreach ( $storage_engines as $storage ) {
		$table = 'scpo_compat_' . bin2hex( random_bytes( 5 ) );
		$tables[] = $table;
		if ( false === $wpdb->query( "CREATE TABLE $table (ID bigint unsigned PRIMARY KEY, menu_order int NULL) ENGINE=$storage" ) ) { throw new RuntimeException( "Could not create $storage fixture: " . $wpdb->last_error ); }
		$read = static function () use ( $wpdb, $table ) {
			return array_map( static function ( $v ) { return null === $v ? null : (int) $v; }, array_column( $wpdb->get_results( "SELECT ID, menu_order FROM $table ORDER BY ID", ARRAY_N ), 1, 0 ) );
		};
		$write = static function ( $values, $current = [] ) use ( $writer, $plugin, $table ) { return $writer->invoke( $plugin, $table, 'ID', 'menu_order', $values, '', $current ); };
		foreach ( array_chunk( range( 1, 2501 ), 500 ) as $ids ) {
			$rows = implode( ',', array_map( static function ( $id ) { return "($id,$id)"; }, $ids ) );
			$wpdb->query( "INSERT INTO $table VALUES $rows" );
		}
		$wpdb->query( "UPDATE $table SET menu_order=NULL WHERE ID=2501" );
		$initial = $read();
		$count = 0;
		$filter = static function ( $sql ) use ( &$count, $table ) { if ( 0 === strpos( $sql, "UPDATE $table" ) ) { $count++; } return $sql; };
		add_filter( 'query', $filter );
		$r = $write( [ 1 => 2, 2 => 1 ], $initial );
		remove_filter( 'query', $filter ); $filter = null;
		$check( "$storage saves using one UPDATE", ! is_wp_error( $r ) && 1 === $count && 2 === $read()[1] && 1 === $read()[2] && null === $read()[2501] );
		$now = $read();
		$noop = $now; unset( $noop[2501] );
		$r = $write( $noop, $now );
		$check( "$storage unchanged values are a no-op", [] === $r );
		$r = $write( [ 1 => 20 ], $initial );
		$check( "$storage stale snapshot cannot overwrite a save", is_wp_error( $r ) && 'scpo_order_conflict' === $r->get_error_code() && $now === $read() );
		$wpdb->query( "DELETE FROM $table WHERE ID=2501" );
		$r = $write( [ 1 => 20 ], $now );
		$check( "$storage missing snapshot row prevents all writes", is_wp_error( $r ) && 2 === $read()[1] );
		$wpdb->query( "INSERT INTO $table VALUES (2501,0)" );
		$r = $write( [ 1 => 20 ], $now );
		$check( "$storage distinguishes NULL from zero", is_wp_error( $r ) && 2 === $read()[1] );
		$wpdb->query( "UPDATE $table SET menu_order=NULL WHERE ID=2501" );
		$before = $read();
		$filter = static function ( $sql ) use ( $table ) { return 0 === strpos( $sql, "UPDATE $table" ) ? '' : $sql; };
		add_filter( 'query', $filter ); $r = $write( [ 1 => 21 ], $before ); remove_filter( 'query', $filter ); $filter = null;
		$check( "$storage failed query reports an error without success", is_wp_error( $r ) && $before === $read() );
		$big = array_combine( range( 1, 2501 ), range( 3001, 5501 ) );
		$count = 0;
		$filter = static function ( $sql ) use ( &$count, $table ) { if ( 0 === strpos( $sql, "UPDATE $table" ) ) { $count++; } return $sql; };
		add_filter( 'query', $filter ); $r = $write( $big, $before ); remove_filter( 'query', $filter ); $filter = null;
		$check( "$storage saves 2501 rows completely", ! is_wp_error( $r ) && $big === $read() && ( 'InnoDB' === $storage ? 3 : 1 ) === $count );

		if ( 'InnoDB' === $storage ) {
			$before = $read(); $calls = 0;
			$next = array_map( static function ( $value ) { return $value + 6000; }, $before );
			$filter = static function ( $sql ) use ( &$calls, $table ) {
				if ( 0 === strpos( $sql, "UPDATE $table" ) && ++$calls === 2 ) { return ''; }
				return $sql;
			};
			add_filter( 'query', $filter ); $r = $write( $next, $before ); remove_filter( 'query', $filter ); $filter = null;
			$check( 'InnoDB second-batch failure is reported', is_wp_error( $r ) && 2 === $calls );
			$check( 'InnoDB earlier batches roll back on a later failure', $before === $read() );
		}

		// A mixed-engine write must never implicitly commit another plugin's work.
		$sentinel = $table . '_transaction'; $tables[] = $sentinel;
		$wpdb->query( "CREATE TABLE $sentinel (ID int PRIMARY KEY) ENGINE=InnoDB" );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( "INSERT INTO $sentinel VALUES (1)" );
		$r = $write( [ 1 => 9000 ], $read() );
		$wpdb->query( 'ROLLBACK' );
		$check( "$storage preserves a caller's InnoDB transaction", ! is_wp_error( $r ) && '0' === (string) $wpdb->get_var( "SELECT COUNT(*) FROM $sentinel" ) );
		$check( "$storage has its documented rollback behavior", ( 'InnoDB' === $storage ? 3001 : 9000 ) === $read()[1] );

		// Metadata may be hidden by a proxy: use the conservative writer on either engine.
		$filter = static function ( $sql ) {
			if ( false !== strpos( $sql, 'SELECT ENGINE FROM information_schema.TABLES' ) ) { return 'SELECT NULL'; }
			if ( 0 === strpos( $sql, 'SHOW TABLE STATUS LIKE' ) ) { return 'SELECT NULL AS Engine WHERE 1=0'; }
			return $sql;
		};
		add_filter( 'query', $filter ); $r = $write( [ 1 => 9100 ], $read() ); remove_filter( 'query', $filter ); $filter = null;
		$check( "$storage saves when engine metadata is unavailable", ! is_wp_error( $r ) && 9100 === $read()[1], wp_json_encode( [ 'result' => is_wp_error( $r ) ? $r->get_error_code() : $r, 'values' => array_slice( $read(), 0, 2, true ) ] ) );

		if ( 'InnoDB' === $storage ) {
			$filter = static function ( $sql ) {
				if ( false !== strpos( $sql, 'SELECT ENGINE FROM information_schema.TABLES' ) ) { return 'SELECT NULL'; }
				if ( 0 === strpos( $sql, 'SHOW TABLE STATUS LIKE' ) ) { return 'SELECT NULL AS Engine WHERE 1=0'; }
				return $sql;
			};
			$wpdb->query( 'START TRANSACTION' );
			$wpdb->query( "INSERT INTO $sentinel VALUES (2)" );
			$stale = $read(); // Establish a repeatable-read snapshot on this connection.
			$peer = new wpdb( $host ? 'root' : DB_USER, $host ? '' : DB_PASSWORD, $host ? 'scpo_compat' : DB_NAME, $host ?: DB_HOST );
			$peer->query( "UPDATE $table SET menu_order=6789 WHERE ID=2" );
			add_filter( 'query', $filter ); $r = $write( [ 1 => 9200 ], $stale ); remove_filter( 'query', $filter ); $filter = null;
			$guard_error = $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' ); $peer->close();
			$check( 'Unknown InnoDB engine refuses a stale transaction snapshot without overwriting', is_wp_error( $r ) && in_array( $r->get_error_code(), [ 'scpo_order_conflict', 'scpo_order_write_failed' ], true ) && 6789 === $read()[2] && 9100 === $read()[1], wp_json_encode( [ 'result' => is_wp_error( $r ) ? $r->get_error_code() : $r, 'values' => array_slice( $read(), 0, 2, true ), 'database_error' => $guard_error ] ) );
			$check( 'Unknown InnoDB engine does not commit the caller transaction', '0' === (string) $wpdb->get_var( "SELECT COUNT(*) FROM $sentinel" ) );
		}

		if ( 'InnoDB' !== $storage ) {
			$before = $read();
			$r = $write( [ 1 => 2147483648 ], $before );
			$check( "$storage refuses overflow before writing", is_wp_error( $r ) && $before === $read() );
			$filter = static function ( $sql ) { return 'SELECT @@max_allowed_packet' === $sql ? 'SELECT 1024' : $sql; };
			add_filter( 'query', $filter ); $r = $write( [ 1 => 42 ], $before ); remove_filter( 'query', $filter ); $filter = null;
			$check( "$storage refuses oversized saves without partial batches", is_wp_error( $r ) && 'scpo_order_too_large' === $r->get_error_code() && $before === $read() );
			$r = $write( [ 1 => 42 ] );
			$check( "$storage direct callers can omit their snapshot", ! is_wp_error( $r ) && 42 === $read()[1] );
			$r = $write( [ 99999 => 1 ] );
			$check( "$storage direct callers cannot write missing rows", is_wp_error( $r ) );
		}

		if ( 'InnoDB' !== $storage ) {
			// Independent server sessions race two copies of the actual guarded SQL.
			// Same host/port/socket parsing wpdb uses, so a TCP DB_HOST such as
			// 127.0.0.1:3307 works as well as a local socket.
			$parsed = $wpdb->parse_db_host( $host ? $host : DB_HOST );
			$db_host = ( $parsed && '' !== (string) $parsed[0] ) ? $parsed[0] : 'localhost';
			$port = ( $parsed && $parsed[1] ) ? (int) $parsed[1] : 0;
			$socket = ( $parsed && $parsed[2] ) ? $parsed[2] : ini_get( 'mysqli.default_socket' );
			$user = $host ? 'root' : DB_USER;
			$password = $host ? '' : DB_PASSWORD;
			$database = $host ? 'scpo_compat' : DB_NAME;
			mysqli_report( MYSQLI_REPORT_OFF );
			$left = mysqli_connect( $db_host, $user, $password, $database, $port, $socket );
			$right = mysqli_connect( $db_host, $user, $password, $database, $port, $socket );
			if ( ! $left || ! $right ) { throw new RuntimeException( 'Could not open independent race connections.' ); }
			try {
				$capture = static function ( $desired, $snapshot ) use ( $write, $table ) {
					$sql = null;
					$intercept = static function ( $query ) use ( &$sql, $table ) {
						if ( 0 === strpos( $query, "UPDATE $table AS scpo_target" ) ) { $sql = $query; return ''; }
						return $query;
					};
					add_filter( 'query', $intercept );
					try { $write( $desired, $snapshot ); } finally { remove_filter( 'query', $intercept ); }
					if ( ! $sql ) { throw new RuntimeException( 'Guarded SQL was not captured.' ); }
					return $sql;
				};
				$races_ok = true;
				for ( $race = 0; $race < 10; $race++ ) {
					$wpdb->query( "UPDATE $table SET menu_order=ID WHERE ID IN (1,2,3)" );
					$before = $read();
					$a = [ 1 => 50001, 2 => 50002 ]; $b = [ 2 => 60002, 3 => 60003 ];
					$sql_a = $capture( $a, $before ); $sql_b = $capture( $b, $before );
					$left->query( $sql_a, MYSQLI_ASYNC ); $right->query( $sql_b, MYSQLI_ASYNC );
					$ok_a = $left->reap_async_query(); $n_a = $left->affected_rows;
					$ok_b = $right->reap_async_query(); $n_b = $right->affected_rows;
					$counts = [ $n_a, $n_b ]; sort( $counts );
					$expected = array_replace( $before, $n_a > 0 ? $a : $b );
					if ( ! $ok_a || ! $ok_b || [ 0, 2 ] !== $counts || $expected !== $read() ) { $races_ok = false; }
				}
				$check( "$storage ten simultaneous saved-snapshot races have one winner and no overwritten loser", $races_ok );

				$wpdb->query( "UPDATE $table SET menu_order=ID WHERE ID IN (1,2,3)" );
				$before = $read();
				$desired = [ 1 => 101, 2 => 102, 3 => 103 ];
				$sql = $capture( $desired, $before );
				$trigger = $table . '_pause'; $ready = $table . '_ready';
				$created = $wpdb->query( "CREATE TRIGGER $trigger BEFORE UPDATE ON $table FOR EACH ROW BEGIN SET @scpo_update_count=COALESCE(@scpo_update_count,0)+1; IF @scpo_update_count=3 THEN DO GET_LOCK('$ready',1); DO SLEEP(3); END IF; END" );
				if ( false === $created ) { throw new RuntimeException( 'Could not install interruption trigger.' ); }
				$left->query( 'SET @scpo_update_count=0' );
				$left->query( $sql, MYSQLI_ASYNC );
				$deadline = microtime( true ) + 2;
				$is_ready = false;
				do {
					$result = $right->query( "SELECT IS_USED_LOCK('$ready')" );
					$is_ready = $result && null !== $result->fetch_row()[0];
					if ( ! $is_ready ) { usleep( 5000 ); }
				} while ( ! $is_ready && microtime( true ) < $deadline );
				if ( $is_ready ) { $right->query( 'KILL QUERY ' . (int) $left->thread_id ); }
				$completed = $left->reap_async_query();
				$after = $read();
				$check( "$storage interrupted statement exposes its native partial-write limit", $is_ready && false === $completed && 2 === count( array_diff_assoc( $after, $before ) ) );
				$left->query( "SELECT RELEASE_LOCK('$ready')" );
				$wpdb->query( "DROP TRIGGER $trigger" ); $trigger = null;
				$r = $write( $desired, $before );
				$check( "$storage retry of an interrupted old snapshot cannot change more rows", is_wp_error( $r ) && 'scpo_order_conflict' === $r->get_error_code() && $after === $read() );
				$wpdb->query( "UPDATE $table SET menu_order=ID WHERE ID IN (1,2,3)" );
			} finally { $left->close(); $right->close(); }
		}

		// Deliberately cause a mid-statement error; MyISAM cannot roll this back.
		$trigger = $table . '_failure';
		$wpdb->query( 'SET @scpo_update_count=0' );
		$created = $wpdb->query( "CREATE TRIGGER $trigger BEFORE UPDATE ON $table FOR EACH ROW BEGIN SET @scpo_update_count=COALESCE(@scpo_update_count,0)+1; IF @scpo_update_count=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SCPO deliberate mid-statement failure'; END IF; END" );
		if ( false === $created ) { throw new RuntimeException( 'Could not install failure-injection trigger: ' . $wpdb->last_error ); }
		$before = $read();
		$r = $write( [ 1 => 101, 2 => 102, 3 => 103 ], $before );
		$wpdb->query( "DROP TRIGGER $trigger" ); $trigger = null;
		$after = $read();
		$check( "$storage mid-statement failure never reports success", is_wp_error( $r ) && 'scpo_order_write_failed' === $r->get_error_code() );
		$check( "$storage failure recovery limits are explicit", 'InnoDB' === $storage ? $after === $before : ( 2 === count( array_diff_assoc( $after, $before ) ) ) );
	}
} catch ( Throwable $error ) {
	$check( $error->getMessage(), false );
} finally {
	if ( $filter ) { remove_filter( 'query', $filter ); }
	$wpdb->query( 'ROLLBACK' );
	if ( $trigger ) { $wpdb->query( "DROP TRIGGER IF EXISTS $trigger" ); }
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS $table" ); }
	$wpdb->suppress_errors( $suppressed );
	if ( $host ) { $wpdb->close(); $wpdb = $site_db; }
}
echo "COMPATIBILITY RESULT: $pass passed, $fail failed\n";
if ( $fail ) { WP_CLI::halt( 1 ); }
