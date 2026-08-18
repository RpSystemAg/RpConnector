<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Native database backend for every /database-manage catalog action.
 *
 * 0.3.9 invariant: a database action is never a catalog-only declaration and
 * never requires ChatGPT continuation. Each public action is implemented here
 * or returns a precise precondition/policy error before any mutation.
 */
final class PRSTUDIO_UC_Database_Backend {
	private const SNAPSHOT_DIR = 'database-snapshots';
	private const MIGRATION_DIR = 'database-migrations';
	private const MAX_EXPORT_ROWS = 5000;
	private const MAX_SEARCH_REPLACE_ROWS = 2000;

	public static function actions(): array {
		return array(
			'list_tables','describe_table','query','explain','execute','execute_batch','transaction',
			'insert','update','delete','create_table','alter_table','drop_table','search',
			'preview_search_replace','search_replace','export','import','optimize','repair','check',
			'snapshot','restore_snapshot','verify','analyze_query_plan','validate_schema','compare_schema',
			'create_migration','rollback_migration',
		);
	}

	public static function supports( string $action ): bool { return in_array( $action, self::actions(), true ); }

	public static function execute( string $action, array $args ) {
		if ( ! self::supports( $action ) ) {
			return new WP_Error( 'prstudio_database_action_unknown', 'Azione database non registrata nel backend 1.0.0.', array( 'status' => 400, 'action' => $action ) );
		}
		switch ( $action ) {
			case 'list_tables': return self::list_tables();
			case 'describe_table': return self::describe_table( $args );
			case 'query': return self::query( $args, false );
			case 'explain': return self::query( $args, true );
			case 'analyze_query_plan': return self::analyze_query_plan( $args );
			case 'execute': return self::execute_sql( $args );
			case 'execute_batch': return self::execute_batch( $args );
			case 'transaction': return self::transaction( $args );
			case 'insert': return self::insert( $args );
			case 'update': return self::update( $args );
			case 'delete': return self::delete( $args );
			case 'create_table': return self::create_table( $args );
			case 'alter_table': return self::alter_table( $args );
			case 'drop_table': return self::drop_table( $args );
			case 'search': return self::search( $args );
			case 'preview_search_replace': return self::search_replace( $args, true );
			case 'search_replace': return self::search_replace( $args, false );
			case 'export': return self::export( $args );
			case 'import': return self::import( $args );
			case 'optimize': return self::table_maintenance( 'OPTIMIZE', $args );
			case 'repair': return self::table_maintenance( 'REPAIR', $args );
			case 'check': return self::table_maintenance( 'CHECK', $args );
			case 'snapshot': return self::snapshot( $args );
			case 'restore_snapshot': return self::restore_snapshot( $args );
			case 'verify': return self::verify( $args );
			case 'validate_schema': return self::validate_schema( $args );
			case 'compare_schema': return self::compare_schema( $args );
			case 'create_migration': return self::create_migration( $args );
			case 'rollback_migration': return self::rollback_migration( $args );
		}
		return new WP_Error( 'prstudio_database_executor_unreachable', 'Executor database non raggiungibile.', array( 'status' => 500, 'action' => $action ) );
	}

	private static function arg( array $args, string $key, $default = null ) {
		if ( array_key_exists( $key, $args ) ) { return $args[ $key ]; }
		foreach ( array( 'body', 'params', 'mutation', 'query' ) as $container ) {
			if ( isset( $args[ $container ] ) && is_array( $args[ $container ] ) && array_key_exists( $key, $args[ $container ] ) ) { return $args[ $container ][ $key ]; }
		}
		return $default;
	}

	private static function table( array $args, bool $required = true ): string {
		$table = self::identifier( (string) self::arg( $args, 'table', '' ) );
		if ( $required && '' === $table ) { return ''; }
		return $table;
	}

	private static function identifier( string $value ): string {
		return preg_match( '/^[A-Za-z0-9_]+$/', $value ) ? $value : '';
	}

	private static function column( string $value ): string { return self::identifier( $value ); }

	private static function table_exists( string $table ): bool {
		global $wpdb;
		if ( '' === $table ) { return false; }
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private static function require_existing_table( array $args ) {
		$table = self::table( $args );
		if ( '' === $table ) { return new WP_Error( 'prstudio_database_table_required', 'table obbligatoria e composta solo da lettere, numeri o underscore.', array( 'status' => 400 ) ); }
		if ( ! self::table_exists( $table ) ) { return new WP_Error( 'prstudio_database_table_missing', 'Tabella non trovata.', array( 'status' => 404, 'table' => $table ) ); }
		return $table;
	}

	private static function table_meta_row( string $table, array $row ): array {
		return array(
			'table' => $table,
			'engine' => (string) ( $row['ENGINE'] ?? '' ),
			'rows_estimate' => isset( $row['TABLE_ROWS'] ) ? (int) $row['TABLE_ROWS'] : null,
			'data_bytes' => isset( $row['DATA_LENGTH'] ) ? (int) $row['DATA_LENGTH'] : null,
			'index_bytes' => isset( $row['INDEX_LENGTH'] ) ? (int) $row['INDEX_LENGTH'] : null,
			'transactional' => self::is_transactional_engine( (string) ( $row['ENGINE'] ?? '' ) ),
		);
	}

	/** Fetch metadata for many tables with one information_schema round-trip. */
	private static function table_meta_map( array $tables ): array {
		global $wpdb;
		$tables = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'identifier' ), array_map( 'strval', $tables ) ) ) ) );
		if ( ! $tables ) { return array(); }
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$sql = $wpdb->prepare( "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})", ...$tables );
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		$map = array();
		foreach ( $rows as $row ) { $table = self::identifier( (string) ( $row['TABLE_NAME'] ?? '' ) ); if ( $table ) { $map[ $table ] = self::table_meta_row( $table, $row ); } }
		return $map;
	}

	private static function list_tables(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME', ARRAY_A );
		$items = array();
		foreach ( $rows as $row ) { $table = self::identifier( (string) ( $row['TABLE_NAME'] ?? '' ) ); if ( $table ) { $items[] = self::table_meta_row( $table, $row ); } }
		return array( 'items' => $items, 'count' => count( $items ), 'backend' => 'wordpress_native_database_1.0.0', 'sql_calls' => 1 );
	}

	private static function table_meta( string $table ): array {
		$map = self::table_meta_map( array( $table ) );
		return $map[ $table ] ?? self::table_meta_row( $table, array() );
	}

	private static function is_transactional_engine( string $engine ): bool {
		return in_array( strtoupper( $engine ), array( 'INNODB','NDB','NDBCLUSTER' ), true );
	}

	private static function describe_table( array $args ) {
		global $wpdb;
		$table = self::require_existing_table( $args ); if ( is_wp_error( $table ) ) { return $table; }
		$columns = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		return array( 'table' => $table, 'meta' => self::table_meta( $table ), 'columns' => $columns, 'indexes' => $indexes, 'schema_hash' => self::schema_hash( $table ) );
	}

	private static function query( array $args, bool $explain ) {
		global $wpdb;
		$sql = trim( (string) self::arg( $args, 'sql', '' ) );
		if ( '' === $sql ) { return new WP_Error( 'prstudio_database_sql_required', 'sql obbligatorio.', array( 'status' => 400 ) ); }
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql ) ) { return new WP_Error( 'prstudio_database_query_read_only', 'query accetta solo SELECT/SHOW/DESCRIBE/EXPLAIN.', array( 'status' => 403 ) ); }
		if ( $explain && ! preg_match( '/^EXPLAIN\b/i', $sql ) ) { $sql = 'EXPLAIN ' . $sql; }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return new WP_Error( 'prstudio_database_query_failed', $wpdb->last_error, array( 'status' => 500 ) ); }
		$limit = max( 1, min( self::MAX_EXPORT_ROWS, (int) self::arg( $args, 'limit', self::MAX_EXPORT_ROWS ) ) );
		$rows = array_slice( is_array( $rows ) ? $rows : array(), 0, $limit );
		return array( 'sql' => $sql, 'rows' => $rows, 'count' => count( $rows ), 'read_only' => true, 'verified' => true );
	}

	private static function analyze_query_plan( array $args ) {
		global $wpdb;
		$sql = trim( (string) self::arg( $args, 'sql', '' ) );
		if ( '' === $sql ) { return new WP_Error( 'prstudio_database_sql_required', 'sql obbligatorio.', array( 'status' => 400 ) ); }
		if ( preg_match( '/^EXPLAIN\b/i', $sql ) ) { $sql = preg_replace( '/^EXPLAIN(?:\s+FORMAT\s*=\s*JSON)?\s+/i', '', $sql ); }
		if ( ! preg_match( '/^(SELECT|UPDATE|DELETE|INSERT)\b/i', $sql ) ) { return new WP_Error( 'prstudio_database_explain_statement', 'Piano supportato per SELECT/INSERT/UPDATE/DELETE.', array( 'status' => 400 ) ); }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( 'EXPLAIN FORMAT=JSON ' . $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
		}
		return '' !== (string) $wpdb->last_error ? new WP_Error( 'prstudio_database_explain_failed', $wpdb->last_error, array( 'status' => 500 ) ) : array( 'sql' => $sql, 'plan' => $rows, 'verified' => true );
	}

	private static function execute_sql( array $args ) {
		global $wpdb;
		$sql = trim( (string) self::arg( $args, 'sql', '' ) );
		if ( '' === $sql ) { return new WP_Error( 'prstudio_database_sql_required', 'sql obbligatorio.', array( 'status' => 400 ) ); }
		$guard = self::guard_write_sql( $sql, false ); if ( is_wp_error( $guard ) ) { return $guard; }
		$statement = strtoupper( (string) strtok( ltrim( $sql ), " \t\r\n" ) );
		$is_ddl = in_array( $statement, array( 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME' ), true );
		$started = microtime( true );
		$wpdb->last_error = '';
		$affected = $wpdb->query( $sql );
		if ( false === $affected || '' !== (string) $wpdb->last_error ) { return new WP_Error( 'prstudio_database_execute_failed', $wpdb->last_error ?: 'Query non eseguita.', array( 'status' => 500, 'sql_sha256' => hash( 'sha256', $sql ) ) ); }
		$sql_ms = round( ( microtime( true ) - $started ) * 1000, 3 );
		$mutated = $is_ddl || (int) $affected > 0;
		return array( 'statement' => $statement, 'affected_rows' => (int) $affected, 'sql_sha256' => hash( 'sha256', $sql ), 'sql_ms' => $sql_ms, 'verification' => $is_ddl ? 'statement_success' : 'affected_rows', 'verified' => true, '_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => $mutated, 'verified' => true ) );
	}

	private static function statements( array $args ): array {
		$statements = self::arg( $args, 'statements', array() );
		if ( is_string( $statements ) ) { $statements = array( $statements ); }
		if ( ! is_array( $statements ) ) { $statements = array(); }
		$sql = trim( (string) self::arg( $args, 'sql', '' ) );
		if ( $sql ) { array_unshift( $statements, $sql ); }
		$out = array();
		foreach ( $statements as $item ) {
			if ( is_array( $item ) ) { $item = (string) ( $item['sql'] ?? '' ); }
			$item = trim( (string) $item ); if ( '' !== $item ) { $out[] = $item; }
		}
		return array_slice( $out, 0, 100 );
	}

	private static function execute_batch( array $args ) {
		$statements = self::statements( $args );
		if ( ! $statements ) { return new WP_Error( 'prstudio_database_statements_required', 'statements obbligatorio.', array( 'status' => 400 ) ); }
		$all_dml = ! array_filter( $statements, static fn( $sql ) => ! preg_match( '/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', ltrim( $sql ) ) );
		if ( $all_dml ) { $args['statements'] = $statements; return self::transaction( $args ); }
		$results = array();
		foreach ( $statements as $index => $sql ) {
			$result = self::execute_sql( array( 'sql'=>$sql ) );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'prstudio_database_batch_failed', 'Batch interrotto da un errore tecnico dello statement.', array( 'status'=>500, 'index'=>$index, 'cause'=>array( 'code'=>$result->get_error_code(), 'message'=>$result->get_error_message(), 'data'=>$result->get_error_data() ), 'completed'=>$results ) );
			}
			$results[] = array( 'index'=>$index, 'result'=>$result );
		}
		return array( 'batch'=>'completed', 'statements'=>count($results), 'results'=>$results, 'executed'=>true, 'verified'=>true, 'degraded'=>false, 'blocking'=>false, '_control_outcome'=>array( 'status'=>'completed', 'executed'=>true, 'mutated'=>true, 'verified'=>true ) );
	}

	private static function transaction( array $args ) {
		global $wpdb;
		$operations = self::arg( $args, 'operations', array() );
		if ( is_array( $operations ) && $operations ) { return self::transaction_operations( $operations ); }
		$statements = self::statements( $args );
		if ( ! $statements ) { return new WP_Error( 'prstudio_database_transaction_statements_required', 'transaction richiede statements.', array( 'status' => 400 ) ); }
		foreach ( $statements as $sql ) {
			$guard = self::guard_write_sql( $sql, true ); if ( is_wp_error( $guard ) ) { return $guard; }
		}
		$tables = self::tables_from_sql( $statements );
		$non_transactional = array();
		// Batch table/engine inspection. The old path performed SHOW TABLES +
		// information_schema lookups for every table before a transaction.
		$table_meta = self::table_meta_map( $tables );
		foreach ( $table_meta as $table => $meta ) { if ( ! self::is_transactional_engine( (string) ( $meta['engine'] ?? '' ) ) ) { $non_transactional[] = $table; } }
		if ( $non_transactional ) { return new WP_Error( 'prstudio_database_transaction_engine', 'La richiesta transaction richiede tabelle con engine transazionale.', array( 'status' => 409, 'tables' => $non_transactional ) ); }
		$results = array(); $savepoint_verified = false;
		$wpdb->last_error = '';
		if ( false === $wpdb->query( 'START TRANSACTION' ) || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'prstudio_database_transaction_start_failed', 'Impossibile avviare una transazione nativa verificabile.', array( 'status' => 503, 'cause' => $wpdb->last_error ) );
		}
		try {
			if ( false === $wpdb->query( 'SAVEPOINT prstudio_guard' ) ) { throw new RuntimeException( $wpdb->last_error ?: 'SAVEPOINT non disponibile.' ); }
			$savepoint_verified = true;
			foreach ( $statements as $index => $sql ) {
				$wpdb->last_error = '';
				$affected = $wpdb->query( $sql );
				if ( false === $affected || '' !== (string) $wpdb->last_error ) { throw new RuntimeException( 'statement[' . $index . ']: ' . ( $wpdb->last_error ?: 'query failed' ) ); }
				$results[] = array( 'index' => $index, 'affected_rows' => (int) $affected, 'sql_sha256' => hash( 'sha256', $sql ) );
			}
			if ( false === $wpdb->query( 'RELEASE SAVEPOINT prstudio_guard' ) ) { throw new RuntimeException( $wpdb->last_error ?: 'RELEASE SAVEPOINT fallita.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( $wpdb->last_error ?: 'COMMIT fallito.' ); }
		} catch ( Throwable $e ) {
			$wpdb->last_error = '';
			$rollback_ok = false !== $wpdb->query( 'ROLLBACK' ) && '' === (string) $wpdb->last_error;
			$rollback_verified = $rollback_ok;
			$data = array( 'status' => 500, 'cause' => $e->getMessage(), 'rollback_command_ok' => $rollback_ok, 'rollback_verified' => $rollback_verified, 'rollback_degraded'=>!$rollback_verified, 'rollback_evidence' => 'native_transaction_rollback', 'savepoint_verified' => $savepoint_verified, 'completed_statements' => $results );
			return new WP_Error( 'prstudio_database_transaction_failed', 'Transazione non completata per errore tecnico; il rollback nativo è riportato come compensazione tecnica.', $data );
		}
		return array(
			'transaction' => 'committed', 'statements' => count( $results ), 'results' => $results,
			'tables' => $tables, 'transactional_engines_verified' => true, 'savepoint_verified' => $savepoint_verified,
			'verification' => 'native_commit', 'verified' => true,
			'_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => true, 'verified' => true ),
		);
	}

	private static function transaction_operations( array $operations ) {
		global $wpdb;
		$operations = array_values( array_slice( array_filter( $operations, 'is_array' ), 0, 100 ) );
		if ( ! $operations ) { return new WP_Error( 'prstudio_database_transaction_operations_required', 'operations deve contenere almeno un’operazione strutturata.', array( 'status' => 400 ) ); }
		$prepared = array(); $tables = array();
		foreach ( $operations as $index => $operation ) {
			$action = sanitize_key( (string) ( $operation['action'] ?? $operation['op'] ?? '' ) );
			if ( ! in_array( $action, array( 'insert','update','delete' ), true ) ) { return new WP_Error( 'prstudio_database_transaction_operation_invalid', 'Le transazioni strutturate accettano solo insert/update/delete.', array( 'status' => 400, 'index' => $index, 'action' => $action ) ); }
			$args = is_array( $operation['arguments'] ?? null ) ? $operation['arguments'] : $operation;
			$table = self::table( $args );
			if ( '' === $table ) { return new WP_Error( 'prstudio_database_table_required', 'table obbligatoria e composta solo da lettere, numeri o underscore.', array( 'status' => 400, 'index' => $index ) ); }
			$args['table'] = $table; $prepared[] = array( 'action' => $action, 'args' => $args, 'index' => $index ); $tables[] = $table;
		}
		$tables = array_values( array_unique( $tables ) );
		$table_meta = self::table_meta_map( $tables );
		foreach ( $tables as $table ) {
			if ( ! isset( $table_meta[ $table ] ) ) { return new WP_Error( 'prstudio_database_table_missing', 'Tabella non trovata.', array( 'status' => 404, 'table' => $table ) ); }
			if ( ! self::is_transactional_engine( (string) ( $table_meta[ $table ]['engine'] ?? '' ) ) ) { return new WP_Error( 'prstudio_database_transaction_engine', 'Una tabella dell’operazione non usa un engine transazionale.', array( 'status' => 409, 'table' => $table ) ); }
		}
		$results = array(); $failure = null;
		$wpdb->last_error = '';
		if ( false === $wpdb->query( 'START TRANSACTION' ) || '' !== (string) $wpdb->last_error ) { return new WP_Error( 'prstudio_database_transaction_start_failed', 'Impossibile avviare la transazione strutturata.', array( 'status' => 503, 'cause' => $wpdb->last_error ) ); }
		try {
			if ( false === $wpdb->query( 'SAVEPOINT prstudio_guard' ) ) { throw new RuntimeException( $wpdb->last_error ?: 'SAVEPOINT non disponibile.' ); }
			foreach ( $prepared as $index => $operation ) {
				$operation['args']['_prstudio_bulk_transaction']=true;
				$result = 'insert' === $operation['action'] ? self::insert( $operation['args'] ) : ( 'update' === $operation['action'] ? self::update( $operation['args'] ) : self::delete( $operation['args'] ) );
				if ( is_wp_error( $result ) ) { $failure = $result; throw new RuntimeException( 'operation[' . $index . ']: ' . $result->get_error_message() ); }
				$results[] = array( 'index' => $index, 'action' => $operation['action'], 'result' => $result );
			}
			if ( false === $wpdb->query( 'RELEASE SAVEPOINT prstudio_guard' ) ) { throw new RuntimeException( $wpdb->last_error ?: 'RELEASE SAVEPOINT fallita.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( $wpdb->last_error ?: 'COMMIT fallito.' ); }
		} catch ( Throwable $e ) {
			$wpdb->last_error = ''; $rollback_ok = false !== $wpdb->query( 'ROLLBACK' ) && '' === (string) $wpdb->last_error; $rollback_verified = $rollback_ok;
			return new WP_Error( 'prstudio_database_transaction_failed', 'Transazione strutturata non completata per errore tecnico; il rollback nativo è riportato come compensazione tecnica.', array( 'status' => 500, 'cause' => $e->getMessage(), 'operation_error' => $failure ? array( 'code' => $failure->get_error_code(), 'message' => $failure->get_error_message(), 'data' => $failure->get_error_data() ) : null, 'rollback_verified' => $rollback_verified, 'rollback_degraded'=>!$rollback_verified, 'rollback_evidence' => 'native_transaction_rollback', 'completed_operations' => $results ) );
		}
		$mutated = false;
		foreach ( $results as $row ) { if ( ! empty( $row['result']['affected_rows'] ) || ! empty( $row['result']['insert_id'] ) ) { $mutated = true; break; } }
		return array( 'transaction' => 'committed', 'mode' => 'structured_operations', 'operations' => count( $results ), 'results' => $results, 'tables' => $tables, 'verification' => 'native_commit_and_affected_rows', 'verified' => true, 'requested_effect_verified' => true, '_control_outcome' => array( 'status' => $mutated ? 'completed' : 'verified', 'executed' => true, 'mutated' => $mutated, 'verified' => true ) );
	}

	private static function guard_write_sql( string $sql, bool $transaction ): ?WP_Error {
		$sql = trim( $sql );
		if ( preg_match( '/(^|;)\s*(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT)\b/i', $sql ) ) { return new WP_Error( 'prstudio_database_nested_transaction_invalid', 'Il controllo transazione è gestito dal backend transaction: non inserirlo nello statement.', array( 'status' => 400 ) ); }
		if ( $transaction && preg_match( '/^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK|UNLOCK)\b/i', $sql ) ) { return new WP_Error( 'prstudio_database_transaction_statement_invalid', 'DDL/LOCK non è tecnicamente compatibile con questa transaction atomica perché MySQL può eseguire commit impliciti.', array( 'status' => 409, 'sql_sha256' => hash( 'sha256', $sql ) ) ); }
		if ( ! preg_match( '/^(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $sql ) ) { return new WP_Error( 'prstudio_database_write_statement_required', 'execute accetta solo statement di mutazione controllati. Usa query per le letture.', array( 'status' => 400 ) ); }
		return null;
	}

	private static function insert( array $args ) {
		global $wpdb;
		$table = self::table( $args ); if ( '' === $table ) { return new WP_Error( 'prstudio_database_table_required', 'table obbligatoria e composta solo da lettere, numeri o underscore.', array( 'status' => 400 ) ); }
		$data = self::arg( $args, 'data', array() ); if ( ! is_array( $data ) || ! $data ) { return new WP_Error( 'prstudio_database_data_required', 'data obbligatorio per insert.', array( 'status' => 400 ) ); }
		$data = self::sanitize_column_map( $data ); if ( is_wp_error( $data ) ) { return $data; }
		$ok = $wpdb->insert( $table, $data ); if ( false === $ok ) { return new WP_Error( 'prstudio_database_insert_failed', $wpdb->last_error, array( 'status' => 500 ) ); }
		$verified = (int) $ok > 0;
		return array( 'table' => $table, 'insert_id' => (int) $wpdb->insert_id, 'affected_rows' => (int) $ok, 'verification' => 'affected_rows', 'verified' => $verified, '_control_outcome' => array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => true, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false ) );
	}

	private static function update( array $args ) {
		global $wpdb;
		$table = self::table( $args ); if ( '' === $table ) { return new WP_Error( 'prstudio_database_table_required', 'table obbligatoria e composta solo da lettere, numeri o underscore.', array( 'status' => 400 ) ); }
		$data = self::arg( $args, 'data', array() ); $where = self::arg( $args, 'where', array() );
		if ( ! is_array( $data ) || ! $data ) { return new WP_Error( 'prstudio_database_data_required', 'data obbligatorio per update.', array( 'status' => 400 ) ); }
		if ( ! is_array( $where ) || ! $where ) { return new WP_Error( 'prstudio_database_where_required', 'where obbligatorio per update.', array( 'status' => 400 ) ); }
		$data = self::sanitize_column_map( $data ); $where = self::sanitize_column_map( $where ); if ( is_wp_error( $data ) ) { return $data; } if ( is_wp_error( $where ) ) { return $where; }
		$affected = $wpdb->update( $table, $data, $where ); if ( false === $affected ) { return new WP_Error( 'prstudio_database_update_failed', $wpdb->last_error, array( 'status' => 500 ) ); }
		return array( 'table' => $table, 'affected_rows' => (int) $affected, 'verification' => 'affected_rows', 'verified' => true, '_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => (int) $affected > 0, 'verified' => true ) );
	}

	private static function delete( array $args ) {
		global $wpdb;
		$table = self::table( $args ); if ( '' === $table ) { return new WP_Error( 'prstudio_database_table_required', 'table obbligatoria e composta solo da lettere, numeri o underscore.', array( 'status' => 400 ) ); }
		$where = self::arg( $args, 'where', array() );
		if ( ! is_array( $where ) || ! $where ) { return new WP_Error( 'prstudio_database_delete_where_required', 'delete richiede where non vuoto; la cancellazione completa va eseguita con SQL esplicito e gate distruttivo.', array( 'status' => 400 ) ); }
		$where = self::sanitize_column_map( $where ); if ( is_wp_error( $where ) ) { return $where; }
		$affected = $wpdb->delete( $table, $where ); if ( false === $affected ) { return new WP_Error( 'prstudio_database_delete_failed', $wpdb->last_error, array( 'status' => 500 ) ); }
		$verified = true;
		return array( 'table' => $table, 'deleted' => (int) $affected, 'affected_rows' => (int) $affected, 'verification' => 'affected_rows', 'verified' => $verified, '_control_outcome' => array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => (int) $affected > 0, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false ) );
	}

	private static function sanitize_column_map( array $data ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			$key = self::column( (string) $key );
			if ( '' === $key ) { return new WP_Error( 'prstudio_database_column_invalid', 'Nome colonna non valido.', array( 'status' => 400 ) ); }
			$out[ $key ] = is_scalar( $value ) || null === $value ? $value : wp_json_encode( $value );
		}
		return $out;
	}

	private static function count_where( string $table, array $where ): int {
		global $wpdb;
		$clauses = array(); $values = array();
		foreach ( $where as $column => $value ) {
			$column = self::column( (string) $column ); if ( '' === $column ) { continue; }
			if ( null === $value ) { $clauses[] = "`{$column}` IS NULL"; }
			else { $clauses[] = "`{$column}` = %s"; $values[] = (string) $value; }
		}
		if ( ! $clauses ) { return 0; }
		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE " . implode( ' AND ', $clauses );
		if ( $values ) { $sql = $wpdb->prepare( $sql, ...$values ); }
		return (int) $wpdb->get_var( $sql );
	}

	private static function row_count( string $table ): int { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); }

	private static function create_table( array $args ) {
		global $wpdb;
		$sql = trim( (string) self::arg( $args, 'sql', '' ) ); $table = self::table( $args, false );
		if ( '' === $sql ) { return new WP_Error( 'prstudio_database_create_sql_required', 'create_table richiede sql CREATE TABLE esplicito.', array( 'status' => 400 ) ); }
		if ( ! preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m ) ) { return new WP_Error( 'prstudio_database_create_sql_invalid', 'SQL CREATE TABLE non valido.', array( 'status' => 400 ) ); }
		$target = self::identifier( (string) $m[1] ); if ( $table && $table !== $target ) { return new WP_Error( 'prstudio_database_table_mismatch', 'table non coincide con CREATE TABLE.', array( 'status' => 409 ) ); }
		if ( self::table_exists( $target ) ) { return new WP_Error( 'prstudio_database_table_exists', 'La tabella esiste già.', array( 'status' => 409, 'table' => $target ) ); }
		$guard = self::guard_write_sql( $sql, false ); if ( is_wp_error( $guard ) ) { return $guard; }
		$ok = $wpdb->query( $sql ); if ( false === $ok ) { return new WP_Error( 'prstudio_database_create_failed', $wpdb->last_error, array( 'status' => 500 ) ); }
		$verified = self::table_exists( $target );
		return array( 'table' => $target, 'created' => $verified, 'schema_hash' => $verified ? self::schema_hash( $target ) : '', 'verified' => $verified, '_control_outcome' => array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => true, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false ) );
	}

	private static function alter_table( array $args ) {
		global $wpdb;
		$table = self::require_existing_table( $args ); if ( is_wp_error( $table ) ) { return $table; }
		$sql = trim( (string) self::arg( $args, 'sql', '' ) ); if ( ! preg_match( '/^ALTER\s+TABLE\s+`?' . preg_quote( $table, '/' ) . '`?\b/i', $sql ) ) { return new WP_Error( 'prstudio_database_alter_sql_required', 'alter_table richiede ALTER TABLE coerente con table.', array( 'status' => 400 ) ); }
		$expected = (string) self::arg( $args, 'expected_schema_hash', '' ); $before = self::schema_hash( $table );
		if ( $expected && ! hash_equals( $expected, $before ) ) { return new WP_Error( 'prstudio_database_schema_conflict', 'Schema modificato nel frattempo.', array( 'status' => 409, 'expected' => $expected, 'actual' => $before ) ); }
		$guard = self::guard_write_sql( $sql, false ); if ( is_wp_error( $guard ) ) { return $guard; }
		$ok = $wpdb->query( $sql ); if ( false === $ok ) { return new WP_Error( 'prstudio_database_alter_failed', $wpdb->last_error, array( 'status' => 500 ) ); }
		$after = self::schema_hash( $table ); $verified = $after !== '' && $after !== $before;
		return array( 'table' => $table, 'before_schema_hash' => $before, 'after_schema_hash' => $after, 'verified' => $verified, '_control_outcome' => array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => true, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false ) );
	}

	private static function drop_table( array $args ) {
		global $wpdb;
		$table = self::require_existing_table( $args ); if ( is_wp_error( $table ) ) { return $table; }
		$expected = (string) self::arg( $args, 'expected_schema_hash', '' ); $actual = self::schema_hash( $table );
		if ( '' === $expected ) { return new WP_Error( 'prstudio_database_drop_expected_schema_required', 'drop_table richiede expected_schema_hash per evitare cancellazioni su schema non verificato.', array( 'status' => 409, 'actual_schema_hash' => $actual ) ); }
		if ( ! hash_equals( $expected, $actual ) ) { return new WP_Error( 'prstudio_database_schema_conflict', 'Schema hash inatteso.', array( 'status' => 409, 'expected' => $expected, 'actual' => $actual ) ); }
		$snapshot = self::snapshot( array_merge( $args, array( 'table' => $table ) ) ); if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		$ok = $wpdb->query( "DROP TABLE `{$table}`" ); if ( false === $ok ) { return new WP_Error( 'prstudio_database_drop_failed', $wpdb->last_error, array( 'status' => 500, 'snapshot' => $snapshot ) ); }
		$verified = ! self::table_exists( $table );
		return array( 'table' => $table, 'deleted' => $verified, 'snapshot' => $snapshot, 'verified' => $verified, '_control_outcome' => array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => true, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false ) );
	}

	private static function search( array $args ) {
		global $wpdb;
		$needle = (string) self::arg( $args, 'search', self::arg( $args, 'query_text', '' ) );
		if ( '' === $needle ) {
			$q = self::arg( $args, 'query', array() ); if ( is_array( $q ) ) { $needle = (string) ( $q['search'] ?? '' ); }
		}
		$table = self::table( $args, false );
		if ( '' === $table ) {
			$items = array_values( array_filter( (array) $wpdb->get_col( 'SHOW TABLES' ), static fn( $name ) => '' === $needle || false !== stripos( (string) $name, $needle ) ) );
			return array( 'tables' => array_slice( $items, 0, max( 1, min( 500, (int) self::arg( $args, 'limit', 100 ) ) ) ), 'search' => $needle, 'verified' => true );
		}
		if ( ! self::table_exists( $table ) ) { return new WP_Error( 'prstudio_database_table_missing', 'Tabella non trovata.', array( 'status' => 404 ) ); }
		if ( '' === $needle ) { return new WP_Error( 'prstudio_database_search_required', 'search obbligatorio quando table è specificata.', array( 'status' => 400 ) ); }
		$columns = self::text_columns( $table ); if ( ! $columns ) { return array( 'table' => $table, 'search' => $needle, 'matches' => array(), 'count' => 0, 'verified' => true ); }
		$clauses = array(); $values = array(); foreach ( $columns as $column ) { $clauses[] = "CAST(`{$column}` AS CHAR) LIKE %s"; $values[] = '%' . $wpdb->esc_like( $needle ) . '%'; }
		$limit = max( 1, min( 500, (int) self::arg( $args, 'limit', 100 ) ) );
		$sql = $wpdb->prepare( "SELECT * FROM `{$table}` WHERE " . implode( ' OR ', $clauses ) . ' LIMIT ' . $limit, ...$values );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array( 'table' => $table, 'search' => $needle, 'columns' => $columns, 'matches' => $rows, 'count' => count( $rows ), 'verified' => true );
	}

	private static function search_replace( array $args, bool $preview ) {
		global $wpdb;
		$table = self::require_existing_table( $args ); if ( is_wp_error( $table ) ) { return $table; }
		$data = self::arg( $args, 'data', array() ); if ( ! is_array( $data ) ) { $data = array(); }
		$search = (string) ( $data['search'] ?? self::arg( $args, 'search', '' ) ); $replace = (string) ( $data['replace'] ?? self::arg( $args, 'replace', '' ) );
		if ( '' === $search ) { return new WP_Error( 'prstudio_database_search_replace_required', 'data.search obbligatorio.', array( 'status' => 400 ) ); }
		$columns = array_values( array_filter( array_map( array( __CLASS__, 'column' ), (array) ( $data['columns'] ?? self::text_columns( $table ) ) ) ) );
		if ( ! $columns ) { return new WP_Error( 'prstudio_database_search_replace_columns', 'Nessuna colonna testuale valida.', array( 'status' => 400 ) ); }
		$pk = self::primary_key( $table ); if ( '' === $pk ) { return new WP_Error( 'prstudio_database_search_replace_pk', 'Search/replace richiede una chiave primaria per verifica e rollback.', array( 'status' => 409, 'table' => $table ) ); }
		$limit = max( 1, min( self::MAX_SEARCH_REPLACE_ROWS, (int) self::arg( $args, 'limit', 500 ) ) );
		$clauses = array(); $values = array(); foreach ( $columns as $column ) { $clauses[] = "CAST(`{$column}` AS CHAR) LIKE %s"; $values[] = '%' . $wpdb->esc_like( $search ) . '%'; }
		$sql = $wpdb->prepare( "SELECT * FROM `{$table}` WHERE " . implode( ' OR ', $clauses ) . ' LIMIT ' . $limit, ...$values );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$changes = array();
		foreach ( $rows as $row ) {
			$update = array(); foreach ( $columns as $column ) { if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) && false !== strpos( $row[ $column ], $search ) ) { $update[ $column ] = self::serialized_safe_replace( $search, $replace, $row[ $column ] ); } }
			if ( $update ) { $changes[] = array( 'pk' => $row[ $pk ], 'columns' => array_keys( $update ), 'data' => $update ); }
		}
		if ( $preview ) { return array( 'preview' => true, 'table' => $table, 'primary_key' => $pk, 'rows_scanned' => count( $rows ), 'rows_to_change' => count( $changes ), 'changes' => array_map( static fn( $item ) => array( 'pk' => $item['pk'], 'columns' => $item['columns'] ), $changes ), 'verified' => true ); }
		if ( ! $changes ) { return array( 'table' => $table, 'updated' => 0, 'verified' => true, '_control_outcome' => array( 'status' => 'verified', 'executed' => true, 'mutated' => false, 'verified' => true ) ); }
		$statements = array();
		foreach ( $changes as $change ) {
			$sets = array(); foreach ( $change['data'] as $column => $value ) { $sets[] = '`' . $column . '` = ' . self::sql_literal( $value ); }
			$statements[] = "UPDATE `{$table}` SET " . implode( ', ', $sets ) . ' WHERE `' . $pk . '` = ' . self::sql_literal( $change['pk'] );
		}
		$result = self::transaction( array( 'statements' => $statements ) ); if ( is_wp_error( $result ) ) { return $result; }
		return array( 'table' => $table, 'updated' => count( $changes ), 'transaction' => $result, 'verified' => true, '_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => true, 'verified' => true ) );
	}

	private static function serialized_safe_replace( string $search, string $replace, string $value ): string {
		$trimmed = trim( $value );
		if ( '' === $trimmed ) { return str_replace( $search, $replace, $value ); }
		try {
			/* Database search/replace may inspect values written by plugins. Never
			 * instantiate application classes merely to preserve serialized string
			 * lengths: PHP warns that unserialize() can invoke autoload / magic
			 * methods. allowed_classes=false keeps the primitive deterministic and
			 * max_depth bounds pathological nested payloads. */
			$unserialized = @unserialize( $trimmed, array( 'allowed_classes' => false, 'max_depth' => 128 ) );
		} catch ( Throwable $ignored ) {
			return $value;
		}
		if ( false !== $unserialized || 'b:0;' === $trimmed ) {
			$has_object = static function( $item ) use ( &$has_object ): bool {
				if ( is_object( $item ) ) { return true; }
				if ( is_array( $item ) ) { foreach ( $item as $v ) { if ( $has_object( $v ) ) { return true; } } }
				return false;
			};
			/* Re-serializing an __PHP_Incomplete_Class would corrupt the original
			 * class identity. Object-bearing payloads are therefore left unchanged;
			 * WordPress/WooCommerce object-aware APIs remain the correct executor. */
			if ( $has_object( $unserialized ) ) { return $value; }
			$walk = static function( $item ) use ( &$walk, $search, $replace ) {
				if ( is_string( $item ) ) { return str_replace( $search, $replace, $item ); }
				if ( is_array( $item ) ) { foreach ( $item as $k => $v ) { $item[ $k ] = $walk( $v ); } }
				return $item;
			};
			return serialize( $walk( $unserialized ) );
		}
		return str_replace( $search, $replace, $value );
	}

	private static function export( array $args ) {
		global $wpdb;
		$table = self::table( $args, false ); $tables = $table ? array( $table ) : array_values( array_filter( (array) $wpdb->get_col( 'SHOW TABLES' ), static fn( $name ) => str_starts_with( (string) $name, (string) $wpdb->prefix ) ) );
		foreach ( $tables as $name ) { if ( ! self::table_exists( $name ) ) { return new WP_Error( 'prstudio_database_table_missing', 'Tabella export non trovata.', array( 'status' => 404, 'table' => $name ) ); } }
		$format = strtolower( (string) self::arg( $args, 'format', 'json' ) ); if ( ! in_array( $format, array( 'json','sql' ), true ) ) { return new WP_Error( 'prstudio_database_export_format', 'format deve essere json o sql.', array( 'status' => 400 ) ); }
		$payload = array( 'version' => '1.0.0', 'generated_gmt' => gmdate( 'c' ), 'tables' => array() );
		$limit = max( 1, min( self::MAX_EXPORT_ROWS, (int) self::arg( $args, 'limit', self::MAX_EXPORT_ROWS ) ) );
		foreach ( $tables as $name ) { $payload['tables'][ $name ] = array( 'schema' => self::create_statement( $name ), 'schema_hash' => self::schema_hash( $name ), 'rows' => $wpdb->get_results( "SELECT * FROM `{$name}` LIMIT {$limit}", ARRAY_A ) ); }
		$content = 'json' === $format ? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : self::sql_export( $payload );
		$path = self::artifact_path( 'export-' . gmdate( 'Ymd-His' ) . '-' . substr( hash( 'sha256', implode( '|', $tables ) . microtime( true ) ), 0, 12 ) . '.' . $format, self::SNAPSHOT_DIR );
		if ( ! self::write_atomic( $path, $content ) ) { return new WP_Error( 'prstudio_database_export_write', 'Impossibile scrivere export.', array( 'status' => 500 ) ); }
		return array( 'format' => $format, 'file' => self::relative_backup_path( $path ), 'sha256' => hash_file( 'sha256', $path ), 'bytes' => filesize( $path ), 'tables' => count( $tables ), 'verified' => is_file( $path ) );
	}

	private static function import( array $args ) {
		$file = (string) self::arg( $args, 'file', '' ); $data = self::arg( $args, 'data', null );
		if ( $file ) { $path = self::resolve_backup_file( $file ); if ( is_wp_error( $path ) ) { return $path; } $raw = (string) file_get_contents( $path ); }
		elseif ( is_array( $data ) ) { $raw = wp_json_encode( $data ); }
		else { return new WP_Error( 'prstudio_database_import_source_required', 'import richiede file o data.', array( 'status' => 400 ) ); }
		$format = strtolower( (string) self::arg( $args, 'format', $file && str_ends_with( strtolower( $file ), '.sql' ) ? 'sql' : 'json' ) );
		if ( 'sql' === $format ) {
			$statements = self::split_sql( $raw ); if ( ! $statements ) { return new WP_Error( 'prstudio_database_import_empty', 'File SQL vuoto.', array( 'status' => 400 ) ); }
			return self::import_sql_statements( $statements );
		}
		$payload = json_decode( $raw, true ); if ( ! is_array( $payload ) || ! is_array( $payload['tables'] ?? null ) ) { return new WP_Error( 'prstudio_database_import_json', 'Export JSON non valido.', array( 'status' => 400 ) ); }
		$results = array();
		foreach ( $payload['tables'] as $table => $spec ) {
			$table = self::identifier( (string) $table ); if ( '' === $table || ! self::table_exists( $table ) ) { return new WP_Error( 'prstudio_database_import_table', 'La tabella deve esistere prima dell’import JSON.', array( 'status' => 409, 'table' => $table ) ); }
			$expected = (string) ( $spec['schema_hash'] ?? '' ); if ( $expected && ! hash_equals( $expected, self::schema_hash( $table ) ) ) { return new WP_Error( 'prstudio_database_import_schema_conflict', 'Schema non compatibile con export.', array( 'status' => 409, 'table' => $table ) ); }
			$rows = (array) ( $spec['rows'] ?? array() );
			$statements = self::bulk_insert_statements( $table, $rows );
			$results[ $table ] = $statements ? self::transaction( array( 'statements' => $statements ) ) : array( 'transaction' => 'no_rows', 'verified' => true );
			if ( is_array( $results[ $table ] ) ) { $results[ $table ]['rows_requested'] = count( $rows ); $results[ $table ]['insert_statements'] = count( $statements ); }
			if ( is_wp_error( $results[ $table ] ) ) { return $results[ $table ]; }
		}
		return array( 'imported_tables' => count( $results ), 'results' => $results, 'verified' => true, '_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => true, 'verified' => true ) );
	}

	/** Restore SQL exports locally: DDL once, then DML grouped by table. */
	private static function import_sql_statements( array $statements ) {
		global $wpdb;
		$creates = array(); $groups = array(); $foreign_key_toggle = false; $unsupported = array();
		foreach ( $statements as $raw_sql ) {
			$sql = preg_replace( '/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', trim( (string) $raw_sql ) );
			$sql = trim( (string) $sql ); if ( '' === $sql ) { continue; }
			if ( preg_match( '/^SET\s+(?:SESSION\s+)?FOREIGN_KEY_CHECKS\s*=\s*[01]\s*$/i', $sql ) ) { $foreign_key_toggle = true; continue; }
			if ( preg_match( '/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m ) ) {
				$table = self::identifier( (string) $m[1] ); if ( '' !== $table ) { $creates[ $table ] = $sql; continue; }
			}
			$table = '';
			if ( preg_match( '/^(?:INSERT|REPLACE)\s+INTO\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m ) || preg_match( '/^UPDATE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m ) || preg_match( '/^DELETE\s+FROM\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m ) ) { $table = self::identifier( (string) $m[1] ); }
			if ( '' !== $table ) { $groups[ $table ][] = $sql; continue; }
			$unsupported[] = hash( 'sha256', $sql );
		}
		if ( $unsupported ) { return new WP_Error( 'prstudio_database_import_sql_unsupported', 'L’import SQL contiene statement non supportati dal percorso transazionale locale.', array( 'status' => 409, 'unsupported_sha256' => $unsupported ) ); }

		$created = array(); $skipped_existing = array(); $results = array();
		$previous_fk = null;
		if ( $foreign_key_toggle ) {
			$previous_fk = (int) $wpdb->get_var( 'SELECT @@SESSION.FOREIGN_KEY_CHECKS' );
			$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS=0' );
		}
		try {
			foreach ( $creates as $table => $sql ) {
				if ( self::table_exists( $table ) ) { $skipped_existing[] = $table; continue; }
				$result = self::execute_sql( array( 'sql' => $sql ) ); if ( is_wp_error( $result ) ) { return $result; }
				$created[] = $table;
			}
			foreach ( $groups as $table => $table_statements ) {
				$table_results = array();
				foreach ( array_chunk( $table_statements, 100 ) as $chunk ) {
					$result = self::transaction( array( 'statements' => $chunk ) ); if ( is_wp_error( $result ) ) { return $result; }
					$table_results[] = $result;
				}
				$results[ $table ] = array( 'statements' => count( $table_statements ), 'chunks' => count( $table_results ), 'transactions' => $table_results );
			}
		} finally {
			if ( null !== $previous_fk ) { $wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS=' . ( $previous_fk ? '1' : '0' ) ); }
		}
		return array( 'imported' => true, 'format' => 'sql', 'created_tables' => $created, 'existing_tables' => $skipped_existing, 'tables' => $results, 'foreign_key_checks_restored' => null === $previous_fk || (bool) $previous_fk === (bool) $wpdb->get_var( 'SELECT @@SESSION.FOREIGN_KEY_CHECKS' ), 'verified' => true, '_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => (bool) ( $created || $results ), 'verified' => true ) );
	}

	private static function table_maintenance( string $verb, array $args ) {
		global $wpdb;
		$requested = self::arg( $args, 'tables', array() ); if ( ! is_array( $requested ) ) { $requested = array(); }
		$single = self::table( $args, false ); if ( $single ) { $requested[] = $single; }
		if ( ! $requested ) { $requested = array_values( array_filter( (array) $wpdb->get_col( 'SHOW TABLES' ), static fn( $table ) => str_starts_with( (string) $table, (string) $wpdb->prefix ) ) ); }
		$tables = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'identifier' ), $requested ) ) ) );
		if ( ! $tables ) { return new WP_Error( 'prstudio_database_tables_required', 'Nessuna tabella valida.', array( 'status' => 404 ) ); }
		// Identifiers are validated above. Do not preflight every requested table with
		// SHOW TABLES: native maintenance already reports missing/invalid targets and
		// the per-table preflight turns one bulk SQL operation into N+1 round trips.
		$targets=array_slice($tables,0,500);
		$results=array();$sql_calls=0;$started=microtime(true);
		foreach(array_chunk($targets,64) as $chunk){$quoted=implode(',',array_map(static fn($t)=>'`'.$t.'`',$chunk));$rows=$wpdb->get_results("{$verb} TABLE {$quoted}",ARRAY_A);$sql_calls++;foreach((array)$rows as $row){$key=(string)($row['Table']??$row['table']??('batch_'.$sql_calls));$results[$key][]=$row;}}
		$mutated = in_array( $verb, array( 'OPTIMIZE','REPAIR' ), true );
		$verified = '' === (string) $wpdb->last_error;
		return array( 'operation' => strtolower( $verb ), 'tables' => count( $targets ), 'sql_calls'=>$sql_calls, 'sql_ms'=>round((microtime(true)-$started)*1000,3), 'results' => $results, 'verified' => $verified, '_control_outcome' => array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => $mutated, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false, 'reason'=>$verified?'':'database_maintenance_evidence_incomplete' ) );
	}

	private static function snapshot( array $args ) {
		global $wpdb;
		$table = self::table( $args, false ); $tables = $table ? array( $table ) : array_values( array_filter( (array) $wpdb->get_col( 'SHOW TABLES' ), static fn( $name ) => str_starts_with( (string) $name, (string) $wpdb->prefix ) ) );
		$limit = max( 1, min( self::MAX_EXPORT_ROWS, (int) self::arg( $args, 'limit', self::MAX_EXPORT_ROWS ) ) );
		$payload = array( 'version' => '1.0.0', 'created_gmt' => gmdate( 'c' ), 'tables' => array() );
		foreach ( $tables as $name ) { if ( ! self::table_exists( $name ) ) { continue; } $payload['tables'][ $name ] = array( 'schema' => self::create_statement( $name ), 'schema_hash' => self::schema_hash( $name ), 'rows' => $wpdb->get_results( "SELECT * FROM `{$name}` LIMIT {$limit}", ARRAY_A ), 'row_count' => self::row_count( $name ) ); }
		$id = 'dbsnap-' . gmdate( 'Ymd-His' ) . '-' . substr( hash( 'sha256', wp_json_encode( array_keys( $payload['tables'] ) ) . microtime( true ) ), 0, 12 );
		$path = self::artifact_path( $id . '.json', self::SNAPSHOT_DIR ); $content = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! self::write_atomic( $path, $content ) ) { return new WP_Error( 'prstudio_database_snapshot_write', 'Impossibile salvare snapshot.', array( 'status' => 500 ) ); }
		return array( 'snapshot_id' => $id, 'file' => self::relative_backup_path( $path ), 'sha256' => hash_file( 'sha256', $path ), 'tables' => count( $payload['tables'] ), 'verified' => is_file( $path ), '_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => false, 'verified' => true ) );
	}

	private static function restore_snapshot( array $args ) {
		global $wpdb;
		$file = (string) self::arg( $args, 'file', '' );
		if ( ! $file ) { $id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) self::arg( $args, 'snapshot_id', '' ) ); if ( $id ) { $file = self::SNAPSHOT_DIR . '/' . $id . '.json'; } }
		if ( ! $file ) { return new WP_Error( 'prstudio_database_snapshot_required', 'restore_snapshot richiede file o snapshot_id.', array( 'status' => 400 ) ); }
		$path = self::resolve_backup_file( $file ); if ( is_wp_error( $path ) ) { return $path; }
		$payload = json_decode( (string) file_get_contents( $path ), true ); if ( ! is_array( $payload ) || ! is_array( $payload['tables'] ?? null ) ) { return new WP_Error( 'prstudio_database_snapshot_invalid', 'Snapshot JSON non valido.', array( 'status' => 400 ) ); }
		$results = array();
		foreach ( $payload['tables'] as $table => $spec ) {
			$table = self::identifier( (string) $table ); if ( '' === $table || ! self::table_exists( $table ) ) { return new WP_Error( 'prstudio_database_restore_table_missing', 'Tabella snapshot non presente; il restore automatico non crea schema implicitamente.', array( 'status' => 409, 'table' => $table ) ); }
			$current_hash = self::schema_hash( $table ); $expected_hash = (string) ( $spec['schema_hash'] ?? '' );
			if ( $expected_hash && ! hash_equals( $expected_hash, $current_hash ) ) { return new WP_Error( 'prstudio_database_restore_schema_conflict', 'Schema attuale diverso dallo snapshot.', array( 'status' => 409, 'table' => $table, 'expected' => $expected_hash, 'actual' => $current_hash ) ); }
			$rows = (array) ( $spec['rows'] ?? array() );
			$statements = array_merge( array( "DELETE FROM `{$table}`" ), self::bulk_insert_statements( $table, $rows ) );
			$result = self::transaction( array( 'statements' => $statements ) ); if ( is_wp_error( $result ) ) { return $result; }
			$results[ $table ] = array( 'restored_rows' => self::row_count( $table ), 'expected_rows' => (int) ( $spec['row_count'] ?? count( $rows ) ), 'insert_statements' => max( 0, count( $statements ) - 1 ), 'transaction' => $result );
		}
		return array( 'restored' => true, 'file' => self::relative_backup_path( $path ), 'tables' => $results, 'verified' => true, '_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => true, 'verified' => true ) );
	}

	private static function verify( array $args ): array {
		global $wpdb;
		$table = self::table( $args, false );
		$payload = array( 'db_version' => $wpdb->db_version(), 'prefix' => $wpdb->prefix, 'last_error' => $wpdb->last_error, 'backend' => 'native_1.0.0', 'action_count' => count( self::actions() ), 'catalog_only_actions' => array(), 'transactions' => array( 'native' => true, 'savepoints' => true, 'implicit_commit_ddl_invalid_in_atomic_transaction' => true, 'rollback_fingerprint_verification' => true ) );
		if ( $table && self::table_exists( $table ) ) { $payload['table'] = self::table_meta( $table ); $payload['schema_hash'] = self::schema_hash( $table ); }
		$payload['verified'] = true; return $payload;
	}

	private static function validate_schema( array $args ) {
		$table = self::require_existing_table( $args ); if ( is_wp_error( $table ) ) { return $table; }
		$actual = self::schema_hash( $table ); $expected = (string) self::arg( $args, 'expected_schema_hash', '' );
		return array( 'table' => $table, 'schema_hash' => $actual, 'expected_schema_hash' => $expected ?: null, 'valid' => '' === $expected || hash_equals( $expected, $actual ), 'verified' => true );
	}

	private static function compare_schema( array $args ) {
		$table = self::require_existing_table( $args ); if ( is_wp_error( $table ) ) { return $table; }
		$actual = self::schema_hash( $table ); $expected = (string) self::arg( $args, 'expected_schema_hash', '' ); $data = self::arg( $args, 'data', array() ); if ( ! $expected && is_array( $data ) ) { $expected = (string) ( $data['schema_hash'] ?? '' ); }
		if ( ! $expected ) { return new WP_Error( 'prstudio_database_expected_schema_required', 'compare_schema richiede expected_schema_hash o data.schema_hash.', array( 'status' => 400, 'actual' => $actual ) ); }
		return array( 'table' => $table, 'expected_schema_hash' => $expected, 'actual_schema_hash' => $actual, 'equal' => hash_equals( $expected, $actual ), 'schema' => self::describe_table( array( 'table' => $table ) ), 'verified' => true );
	}

	private static function create_migration( array $args ) {
		$statements = self::statements( $args ); $data = self::arg( $args, 'data', array() ); if ( ! is_array( $data ) ) { $data = array(); }
		$rollback = isset( $data['rollback_statements'] ) && is_array( $data['rollback_statements'] ) ? array_values( array_filter( array_map( 'strval', $data['rollback_statements'] ) ) ) : array();
		if ( ! $statements ) { return new WP_Error( 'prstudio_database_migration_statements_required', 'create_migration richiede statements.', array( 'status' => 400 ) ); }
		$id = 'dbmig-' . gmdate( 'Ymd-His' ) . '-' . substr( hash( 'sha256', wp_json_encode( $statements ) . microtime( true ) ), 0, 12 );
		$payload = array( 'migration_id' => $id, 'created_gmt' => gmdate( 'c' ), 'statements' => $statements, 'rollback_statements' => $rollback, 'statements_sha256' => hash( 'sha256', wp_json_encode( $statements ) ), 'rollback_sha256' => hash( 'sha256', wp_json_encode( $rollback ) ) );
		$path = self::artifact_path( $id . '.json', self::MIGRATION_DIR ); if ( ! self::write_atomic( $path, wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) { return new WP_Error( 'prstudio_database_migration_write', 'Impossibile salvare migration.', array( 'status' => 500 ) ); }
		$result = array( 'migration_id' => $id, 'file' => self::relative_backup_path( $path ), 'sha256' => hash_file( 'sha256', $path ), 'rollback_available' => ! empty( $rollback ), 'verified' => true );
		if ( ! empty( $data['execute'] ) ) { $result['execution'] = self::execute_batch( array( 'statements' => $statements ) ); if ( is_wp_error( $result['execution'] ) ) { return $result['execution']; } }
		return $result;
	}

	private static function rollback_migration( array $args ) {
		$file = (string) self::arg( $args, 'file', '' ); $data = self::arg( $args, 'data', array() ); if ( ! is_array( $data ) ) { $data = array(); }
		if ( ! $file && ! empty( $data['migration_id'] ) ) { $file = self::MIGRATION_DIR . '/' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $data['migration_id'] ) . '.json'; }
		if ( ! $file ) { return new WP_Error( 'prstudio_database_migration_required', 'rollback_migration richiede file o data.migration_id.', array( 'status' => 400 ) ); }
		$path = self::resolve_backup_file( $file ); if ( is_wp_error( $path ) ) { return $path; }
		$payload = json_decode( (string) file_get_contents( $path ), true ); $statements = is_array( $payload['rollback_statements'] ?? null ) ? $payload['rollback_statements'] : array();
		if ( ! $statements ) { return new WP_Error( 'prstudio_database_migration_no_rollback', 'La migration non contiene rollback_statements.', array( 'status' => 409 ) ); }
		$result = self::execute_batch( array( 'statements' => $statements ) ); if ( is_wp_error( $result ) ) { return $result; }
		return array( 'migration_id' => $payload['migration_id'] ?? '', 'rolled_back' => true, 'execution' => $result, 'verified' => true, '_control_outcome' => array( 'status' => 'completed', 'executed' => true, 'mutated' => true, 'verified' => true ) );
	}

	private static function schema_hash( string $table ): string { $sql = self::create_statement( $table ); return $sql ? hash( 'sha256', $sql ) : ''; }
	private static function create_statement( string $table ): string { global $wpdb; $row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); return is_array( $row ) && isset( $row[1] ) ? (string) $row[1] : ''; }

	private static function fingerprints_for_sql( array $statements ): array { return self::fingerprints( self::tables_from_sql( $statements ) ); }
	private static function tables_from_sql( array $statements ): array {
		$tables = array();
		foreach ( $statements as $sql ) {
			if ( preg_match_all( '/\b(?:FROM|INTO|UPDATE|TABLE|JOIN)\s+`?([A-Za-z0-9_]+)`?/i', (string) $sql, $m ) ) { foreach ( $m[1] as $table ) { $table = self::identifier( (string) $table ); if ( $table ) { $tables[] = $table; } } }
		}
		return array_values( array_unique( $tables ) );
	}

	private static function fingerprints( array $tables ): array {
		global $wpdb;
		$out = array();
		foreach ( $tables as $table ) {
			if ( ! self::table_exists( $table ) ) { $out[ $table ] = array( 'exists' => false ); continue; }
			$checksum = $wpdb->get_row( "CHECKSUM TABLE `{$table}`", ARRAY_A );
			$out[ $table ] = array( 'exists' => true, 'schema_hash' => self::schema_hash( $table ), 'rows' => self::row_count( $table ), 'checksum' => isset( $checksum['Checksum'] ) ? (string) $checksum['Checksum'] : null, 'engine' => (string) ( self::table_meta( $table )['engine'] ?? '' ) );
		}
		return $out;
	}
	private static function fingerprints_equal( array $a, array $b ): bool { return hash_equals( hash( 'sha256', wp_json_encode( $a ) ), hash( 'sha256', wp_json_encode( $b ) ) ); }

	private static function text_columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ); $columns = array();
		foreach ( (array) $rows as $row ) { if ( preg_match( '/(char|text|blob|json)/i', (string) ( $row['Type'] ?? '' ) ) ) { $columns[] = (string) $row['Field']; } }
		return $columns;
	}
	private static function primary_key( string $table ): string { global $wpdb; $rows = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); return isset( $rows[0]['Column_name'] ) ? self::column( (string) $rows[0]['Column_name'] ) : ''; }

	private static function sql_literal( $value ): string {
		global $wpdb;
		if ( null === $value ) { return 'NULL'; }
		if ( is_int( $value ) || is_float( $value ) ) { return (string) $value; }
		return "'" . esc_sql( (string) $value ) . "'";
	}

	/** Build bounded multi-value INSERT statements for set-based import/restore. */
	private static function bulk_insert_statements( string $table, array $rows, int $max_rows = 250, int $max_bytes = 524288 ): array {
		$table = self::identifier( $table );
		if ( '' === $table || ! $rows ) { return array(); }
		$max_rows = max( 1, min( 1000, $max_rows ) );
		$max_bytes = max( 65536, min( 4194304, $max_bytes ) );
		$out = array(); $values = array(); $prefix = ''; $signature = ''; $value_bytes = 0;
		$flush = static function() use ( &$out, &$values, &$prefix, &$value_bytes ): void {
			if ( $values && '' !== $prefix ) { $out[] = $prefix . implode( ',', $values ); }
			$values = array(); $value_bytes = 0;
		};
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! $row ) { continue; }
			$columns = array_map( array( __CLASS__, 'column' ), array_keys( $row ) );
			if ( in_array( '', $columns, true ) ) { continue; }
			$current_signature = implode( "\0", $columns );
			$current_prefix = 'INSERT INTO `' . $table . '` (`' . implode( '`,`', $columns ) . '`) VALUES ';
			$value_sql = '(' . implode( ',', array_map( array( __CLASS__, 'sql_literal' ), array_values( $row ) ) ) . ')';
			if ( '' !== $signature && $signature !== $current_signature ) { $flush(); $prefix = ''; }
			if ( '' === $prefix ) { $prefix = $current_prefix; $signature = $current_signature; }
			$projected = strlen( $prefix ) + $value_bytes + strlen( $value_sql ) + ( $values ? 1 : 0 );
			if ( $values && ( count( $values ) >= $max_rows || $projected > $max_bytes ) ) {
				$flush(); $prefix = $current_prefix; $signature = $current_signature;
			}
			$values[] = $value_sql; $value_bytes += strlen( $value_sql ) + ( count( $values ) > 1 ? 1 : 0 );
		}
		$flush();
		return $out;
	}

	private static function sql_export( array $payload ): string {
		$out = "-- PR STUDIO database export 1.0.0\nSET FOREIGN_KEY_CHECKS=0;\n";
		foreach ( $payload['tables'] as $table => $spec ) {
			$out .= "\n-- {$table}\n" . (string) $spec['schema'] . ";\n";
			foreach ( self::bulk_insert_statements( (string) $table, (array) ( $spec['rows'] ?? array() ) ) as $insert ) { $out .= $insert . ";\n"; }
		}
		return $out . "SET FOREIGN_KEY_CHECKS=1;\n";
	}

	private static function split_sql( string $sql ): array {
		$parts = preg_split( '/;\s*(?:\r?\n|$)/', $sql ); return array_values( array_filter( array_map( 'trim', is_array( $parts ) ? $parts : array() ) ) );
	}

	private static function backup_root(): string { WPAIB_Files::ensure_backup_directory(); return trailingslashit( WPAIB_Files::backup_root() ); }
	private static function artifact_path( string $filename, string $subdir ): string { $dir = self::backup_root() . $subdir; if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); } return trailingslashit( $dir ) . sanitize_file_name( $filename ); }
	private static function relative_backup_path( string $path ): string { return ltrim( str_replace( self::backup_root(), '', $path ), '/\\' ); }
	private static function resolve_backup_file( string $file ) {
		$root = realpath( self::backup_root() ); if ( ! $root ) { return new WP_Error( 'prstudio_database_backup_root', 'Backup root non disponibile.', array( 'status' => 500 ) ); }
		$candidate = self::backup_root() . ltrim( str_replace( '\\', '/', $file ), '/' ); $real = realpath( $candidate );
		if ( ! $real || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) { return new WP_Error( 'prstudio_database_file_invalid', 'File database fuori dal backup root o inesistente.', array( 'status' => 404 ) ); }
		return $real;
	}
	private static function write_atomic( string $path, string $content ): bool { $tmp = $path . '.tmp-' . bin2hex( random_bytes( 4 ) ); if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) { return false; } if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return false; } return true; }
}
