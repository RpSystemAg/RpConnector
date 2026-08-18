<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PRSTUDIO_UC_Anti_Crash {
	private const PRE_MUTATION_TEST = 'pre_mutation_safety';
	private const CORE_CHECKS = array( 'php_syntax', 'capability_registry', 'bootstrap_dependencies', 'executor_registry', 'database_schema', 'internal_site_health', 'gpt_actions_openapi', 'gsc_dimension_integrity' );
	private const EXTENSION_CHECKS = array( 'extension_manifest', 'extension_syntax', 'extension_policy', 'browser_protocol', 'ocr_smoke' );

	public static function requirements( array $args = array() ): array {
		$work = PRSTUDIO_UC_Work_Session::resolve( (string) ( $args['work_id'] ?? '' ) );
		$scope = array_values( array_unique( array_map( 'sanitize_key', (array) ( $args['scope'] ?? ( $work['scope'] ?? array( 'wordpress' ) ) ) ) ) );
		$internal_checks = self::CORE_CHECKS;
		if ( array_intersect( $scope, array( 'extension', 'suite' ) ) ) { $internal_checks = array_merge( $internal_checks, self::EXTENSION_CHECKS ); }
		return array(
			'question' => 'Esegui il test anti crash pre-modifica?',
			'work_id' => $work['work_id'] ?? null,
			'scope' => $scope,
			'target_sha256' => $work['target_sha256'] ?? '',
			'required_tests' => array( self::PRE_MUTATION_TEST ),
			'internal_checks' => array_values( array_unique( $internal_checks ) ),
			'gate_policy' => 'single_pre_mutation_test',
			'acceptance' => array( 'exit_code' => 0, 'status' => 'passed', 'freshness_seconds' => 7200, 'target_hash_must_match' => true ),
		);
	}

	public static function run_server_tests( array $args = array() ): array {
		$work = PRSTUDIO_UC_Work_Session::resolve( (string) ( $args['work_id'] ?? '' ) );
		if ( ! $work ) { return array( 'error' => 'no_active_work', 'instruction' => 'Avvia prima prstudio_work_begin.' ); }

		$checks = array();
		$add = static function ( string $name, bool $passed, array $details = array() ) use ( &$checks ): void {
			$checks[ $name ] = array( 'status' => $passed ? 'passed' : 'failed', 'exit_code' => $passed ? 0 : 1, 'details' => $details );
		};

		$php_ok = true; $php_errors = array(); $php_files = 0;
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PRSTUDIO_UC_DIR, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$php_files++;
				try { token_get_all( (string) file_get_contents( $file->getPathname() ), TOKEN_PARSE ); }
				catch ( Throwable $e ) { $php_ok = false; $php_errors[] = basename( $file->getPathname() ) . ':' . $e->getMessage(); }
			}
		}
		$add( 'php_syntax', $php_ok, array( 'files_checked' => $php_files, 'errors' => $php_errors ) );

		$registry_consistency = class_exists( 'PRSTUDIO_UC_Capability_Registry' ) ? PRSTUDIO_UC_Capability_Registry::consistency() : array( 'ok' => false );
		$add( 'capability_registry', ! empty( $registry_consistency['ok'] ), $registry_consistency );

		$classes = array( 'PRSTUDIO_Agency', 'PRSTUDIO_UC_Store', 'PRSTUDIO_UC_REST', 'PRSTUDIO_UC_Bridge', 'PRSTUDIO_UC_Orchestrator', 'PRSTUDIO_UC_OpenAPI', 'PRSTUDIO_UC_GPT_REST', 'PRSTUDIO_UC_GSC_Provider' );
		$missing = array_values( array_filter( $classes, static fn( $class ) => ! class_exists( $class ) ) );
		$add( 'bootstrap_dependencies', ! $missing, array( 'missing' => $missing ) );

		$registry = class_exists( 'PRSTUDIO_UC_Capability_Registry' ) ? PRSTUDIO_UC_Capability_Registry::consistency() : array( 'ok' => false );
		$add( 'executor_registry', ! empty( $registry['ok'] ) && empty( $registry['missing_executor'] ), array( 'registry' => $registry ) );

		$db_ok = true; $tables = array();
		if ( class_exists( 'PRSTUDIO_UC_Store' ) && isset( $GLOBALS['wpdb'] ) ) {
			global $wpdb;
			foreach ( array( PRSTUDIO_UC_Store::devices_table(), PRSTUDIO_UC_Store::tasks_table(), PRSTUDIO_UC_Store::events_table() ) as $table ) {
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
				$tables[ $table ] = (string) $exists === $table;
				$db_ok = $db_ok && $tables[ $table ];
			}
		} else { $db_ok = false; }
		$add( 'database_schema', $db_ok, array( 'tables' => $tables ) );

		$internal_home = function_exists( 'home_url' ) && '' !== (string) home_url( '/' );
		$internal_admin = defined( 'ABSPATH' ) && is_dir( trailingslashit( ABSPATH ) . 'wp-admin' );
		$add( 'internal_site_health', $internal_home && $internal_admin, array( 'home_resolved' => $internal_home, 'wp_admin_present' => $internal_admin, 'transport' => 'wordpress_internal_no_network_loopback' ) );

		$ids = class_exists( 'PRSTUDIO_UC_OpenAPI' ) ? PRSTUDIO_UC_OpenAPI::operation_ids() : array();
		$preflight = class_exists( 'PRSTUDIO_UC_OpenAPI' ) ? PRSTUDIO_UC_OpenAPI::preflight() : array( 'ok' => false );
		$add( 'gpt_actions_openapi', ! empty( $preflight['ok'] ) && count( $ids ) === 7 && count( $ids ) === count( array_unique( $ids ) ), array( 'operation_ids' => $ids, 'preflight' => $preflight, 'transport' => 'internal_dispatch_no_loopback' ) );

		$gsc_ok = class_exists( 'PRSTUDIO_UC_GSC_Provider' ) && class_exists( 'PRSTUDIO_UC_Search_Console_Browser' ) && defined( 'PRSTUDIO_UC_VERSION' ) && version_compare( (string) PRSTUDIO_UC_VERSION, '5.0.0', '>=' );
		$add( 'gsc_dimension_integrity', $gsc_ok, array( 'provider' => class_exists( 'PRSTUDIO_UC_GSC_Provider' ), 'browser_fallback' => class_exists( 'PRSTUDIO_UC_Search_Console_Browser' ), 'contract' => 'gsc_dimension_session_v4', 'cross_dimension_browser_join' => false ) );

		$scope = array_values( array_unique( array_map( 'sanitize_key', (array) ( $work['scope'] ?? array( 'wordpress' ) ) ) ) );
		$external_required = array();
		if ( array_intersect( $scope, array( 'extension', 'suite' ) ) ) {
			$external_required = self::EXTENSION_CHECKS;
			foreach ( $external_required as $name ) { $add( $name, false, array( 'external_evidence_required' => true ) ); }
		}

		$failed = array_keys( array_filter( $checks, static fn( $row ) => 'passed' !== (string) ( $row['status'] ?? '' ) ) );
		$details = array( 'checks' => $checks, 'failed_checks' => $failed, 'external_evidence_required' => $external_required, 'policy' => 'single_pre_mutation_test' );
		$payload = wp_json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$evidence = array( array(
			'name' => self::PRE_MUTATION_TEST,
			'status' => empty( $failed ) ? 'passed' : 'failed',
			'exit_code' => empty( $failed ) ? 0 : 1,
			'command' => 'bridge-native:' . self::PRE_MUTATION_TEST,
			'output_sha256' => hash( 'sha256', (string) $payload ),
			'target_sha256' => (string) ( $work['target_sha256'] ?? '' ),
			'finished_gmt' => gmdate( 'c' ),
			'details' => $details,
		) );
		return self::verify_and_store( $evidence, array( 'source' => 'bridge_native', 'policy' => 'single_pre_mutation_test' ), (string) ( $work['work_id'] ?? '' ) );
	}

	public static function submit( array $args ): array {
		$evidence = is_array( $args['tests'] ?? null ) ? $args['tests'] : array();
		return self::verify_and_store( $evidence, array( 'source' => 'external_verified', 'artifact_sha256' => (string) ( $args['artifact_sha256'] ?? '' ), 'policy' => 'single_pre_mutation_test' ), (string) ( $args['work_id'] ?? '' ) );
	}

	private static function verify_and_store( array $evidence, array $meta, string $work_id = '' ): array {
		$work = PRSTUDIO_UC_Work_Session::resolve( $work_id );
		if ( ! $work ) { return array( 'error' => 'no_active_work' ); }
		$requirements = self::requirements( array( 'work_id' => (string) $work['work_id'] ) );
		$previous = is_array( $work['anti_crash']['evidence'] ?? null ) ? $work['anti_crash']['evidence'] : array();
		$by_name = array(); $errors = array();
		foreach ( $previous as $name => $item ) { if ( is_array( $item ) && ! empty( $item['verified'] ) ) { $by_name[ sanitize_key( (string) $name ) ] = $item; } }
		foreach ( $evidence as $key => $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$name = sanitize_key( (string) ( $item['name'] ?? ( is_string( $key ) ? $key : '' ) ) );
			if ( '' === $name ) { continue; }
			$status = (string) ( $item['status'] ?? '' ); $exit = (int) ( $item['exit_code'] ?? 1 ); $finished = strtotime( (string) ( $item['finished_gmt'] ?? '' ) );
			$target = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $item['target_sha256'] ?? '' ) ) );
			$valid = 'passed' === $status && 0 === $exit && $finished && $finished >= time() - 7200;
			if ( ! empty( $work['target_sha256'] ) && ! hash_equals( (string) $work['target_sha256'], $target ) ) { $valid = false; $errors[] = $name . ':target_hash_mismatch'; }
			if ( isset( $item['output'] ) && isset( $item['output_sha256'] ) && ! hash_equals( hash( 'sha256', (string) $item['output'] ), (string) $item['output_sha256'] ) ) { $valid = false; $errors[] = $name . ':output_hash_mismatch'; }
			if ( ! $valid ) { if ( empty( $by_name[ $name ]['verified'] ) ) { $errors[] = $name . ':invalid_evidence'; } continue; }
			$item['verified'] = true; $by_name[ $name ] = $item;
		}
		$missing = array_values( array_filter( $requirements['required_tests'], static fn( $name ) => empty( $by_name[ $name ]['verified'] ) ) );
		$status = ( ! $missing && ! $errors ) ? 'passed' : 'failed';
		$record = array( 'status' => $status, 'verified_gmt' => gmdate( 'c' ), 'required_tests' => $requirements['required_tests'], 'evidence' => $by_name, 'missing' => $missing, 'errors' => array_values( array_unique( $errors ) ), 'meta' => $meta, 'evidence_sha256' => hash( 'sha256', wp_json_encode( $by_name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		PRSTUDIO_UC_Work_Session::set_anti_crash_for( (string) $work['work_id'], $record );
		$attestation = array( 'ok' => false, 'stored' => false );
		if ( 'passed' === $status && class_exists( 'PRSTUDIO_UC_Anti_Crash_Attestation' ) ) { $attestation = PRSTUDIO_UC_Anti_Crash_Attestation::store( $record, $work ); }
		return array( 'status' => $status, 'work_id' => $work['work_id'], 'verified' => 'passed' === $status, 'required_test' => self::PRE_MUTATION_TEST, 'missing' => $missing, 'errors' => $record['errors'], 'evidence_sha256' => $record['evidence_sha256'], 'attestation' => $attestation );
	}

	public static function guard( string $tool_name, array $args ) {
		if ( self::is_exempt( $tool_name, $args ) ) { return true; }
		$work = PRSTUDIO_UC_Work_Session::resolve( (string) ( $args['work_id'] ?? '' ) );
		if ( ! $work || 'passed' !== (string) ( $work['anti_crash']['status'] ?? '' ) ) {
			if ( class_exists( 'PRSTUDIO_UC_Anti_Crash_Attestation' ) ) {
				$reuse = PRSTUDIO_UC_Anti_Crash_Attestation::reusable( $tool_name, $args );
				if ( ! empty( $reuse['reused'] ) ) { return true; }
			}
			return new WP_Error( 'prstudio_anti_crash_required', 'Serve un solo test anti crash immediatamente prima della modifica.', array(
				'status' => 409,
				'work_id' => $work['work_id'] ?? null,
				'requirements' => self::requirements( array( 'work_id' => (string) ( $args['work_id'] ?? '' ) ) ),
				'attestation_reuse_checked' => true,
				'gate_policy' => 'single_pre_mutation_test',
				'next_tools' => array( 'prstudio_work_begin', 'prstudio_anti_crash_run', 'prstudio_anti_crash_submit' ),
			) );
		}
		return true;
	}

	private static function canonical_tool_name( string $tool_name ): string {
		$name = strtolower( trim( $tool_name ) );
		if ( preg_match( '/^legacy\.[a-z0-9_-]+\.(.+)$/', $name, $matches ) ) { $name = (string) $matches[1]; }
		$name = str_replace( array( '.', '-', '/' ), '_', $name );
		$name = preg_replace( '/_+/', '_', $name );
		return trim( (string) $name, '_' );
	}

	private static function is_exempt( string $tool_name, array $args ): bool {
		$name = self::canonical_tool_name( $tool_name );
		$exempt = array(
			'prstudio_work_begin', 'prstudio_work_status', 'prstudio_work_abort', 'prstudio_work_finalize',
			'prstudio_anti_crash_requirements', 'prstudio_anti_crash_run', 'prstudio_anti_crash_submit',
			'prstudio_context_open', 'prstudio_context_status', 'prstudio_context_heartbeat', 'prstudio_context_close',
			'prstudio_orchestrator_resolve', 'prstudio_orchestrator_domain_actions', 'prstudio_orchestrator_execute',
			'prstudio_execute', 'work_lock', 'work_state',
		);
		if ( in_array( $name, $exempt, true ) ) { return true; }

		if ( ! empty( $args['dry_run'] ) || ! empty( $args['preview'] ) ) { return true; }
		$mutation = is_array( $args['mutation'] ?? null ) ? $args['mutation'] : array();
		if ( 'preview' === sanitize_key( (string) ( $mutation['mode'] ?? '' ) ) ) { return true; }

		if ( 'rpconnector_seo_manage_build_keyword_map' === $name ) { return true; }
		if ( 'rpconnector_action_call' === $name ) {
			$action = sanitize_key( (string) ( $args['action'] ?? '' ) );
			if ( $action && class_exists( 'PRSTUDIO_Agency' ) ) {
				$meta = PRSTUDIO_Agency::control_action_by_tool( $action );
				if ( is_array( $meta ) && ! empty( $meta['read_only'] ) ) { return true; }
			}
		}
		return false;
	}

	public static function tool_definitions(): array {
		$sec = array( array( 'type' => 'oauth2', 'scopes' => array( 'wp_ai_bridge.read', 'wp_ai_bridge.write' ) ) );
		$make = static function( $name, $title, $desc, $schema, $read ) use ( $sec ) { return array( 'name' => $name, 'title' => $title, 'description' => $desc, 'inputSchema' => $schema, 'outputSchema' => array( 'type' => 'object', 'additionalProperties' => true ), 'securitySchemes' => $sec, '_meta' => array( 'securitySchemes' => $sec, 'ui' => array( 'visibility' => array( 'model', 'app' ) ) ), 'annotations' => array( 'readOnlyHint' => $read, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ) ); };
		$obj = static fn( $p, $r = array() ) => array_filter( array( 'type' => 'object', 'properties' => $p ?: new stdClass(), 'required' => $r ?: null, 'additionalProperties' => false ), static fn( $v ) => null !== $v );
		return array(
			$make( 'prstudio_work_begin', 'Inizia lavoro controllato', 'Apre una sessione di preparazione. Non richiede anti-crash: il gate scatta solo quando sta per partire una modifica reale.', $obj( array( 'description' => array( 'type' => 'string' ), 'scope' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'wordpress', 'plugin', 'extension', 'browser', 'suite', 'database', 'content' ) ) ), 'target_sha256' => array( 'type' => 'string' ), 'force' => array( 'type' => 'boolean', 'default' => false ) ), array( 'description' ) ), false ),
			$make( 'prstudio_work_status', 'Stato lavoro', 'Legge sessione, gate anti-crash, cambiamenti e backup consolidato.', $obj( array( 'work_id' => array( 'type' => 'string' ) ) ), true ),
			$make( 'prstudio_anti_crash_requirements', 'Requisito anti-crash', 'Restituisce l’unico test composito pre-modifica richiesto dal gate.', $obj( array( 'scope' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'work_id' => array( 'type' => 'string' ) ) ), true ),
			$make( 'prstudio_anti_crash_run', 'Esegui test anti-crash pre-modifica', 'Esegue una sola attestazione composita immediatamente prima della modifica. I controlli tecnici restano interni alla singola attestazione.', $obj( array( 'work_id' => array( 'type' => 'string' ) ) ), true ),
			$make( 'prstudio_anti_crash_submit', 'Consegna attestazione anti-crash', 'Consegna una singola evidenza composita esterna, fresca e vincolata allo SHA-256 del target quando richiesta da estensione o suite.', $obj( array( 'artifact_sha256' => array( 'type' => 'string' ), 'work_id' => array( 'type' => 'string' ), 'tests' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ) ), array( 'tests' ) ), false ),
			$make( 'prstudio_work_finalize', 'Finalizza lavoro e backup unico', 'Chiude il lavoro dopo la modifica e crea un singolo archivio degli originali, senza introdurre un secondo gate anti-crash.', $obj( array() ), false ),
			$make( 'prstudio_work_abort', 'Abbandona lavoro', 'Chiude la sessione senza creare backup finale. Il journal resta disponibile per diagnosi.', $obj( array( 'reason' => array( 'type' => 'string' ) ) ), false ),
		);
	}
}

/**
 * The single authority that may invoke Anti_Crash::guard().
 *
 * readOnlyHint is an MCP transport/auth annotation, not a WordPress-mutation
 * classifier. Browser state, durable agent state, queues, watches and external
 * systems can all be non-read-only without touching the protected site. Every
 * caller therefore supplies an explicit mutation scope and this class decides
 * whether the site pre-mutation attestation is relevant.
 */
final class PRSTUDIO_UC_Pre_Mutation_Safety {
    private const GUARDED_SCOPES = array( 'wordpress', 'content', 'commerce', 'seo', 'database', 'filesystem', 'files', 'plugin', 'plugins', 'theme', 'themes', 'suite', 'server' );
    private static int $flow_depth = 0;
    private static bool $flow_gate_granted = false;
    private static string $flow_scope = 'none';

    private static function scope( string $scope ): string {
        $scope = strtolower( trim( $scope ) );
        $scope = str_replace( array( '.', '-', '/' ), '_', $scope );
        $scope = preg_replace( '/_+/', '_', $scope );
        return trim( (string) $scope, '_' );
    }

    public static function is_site_scope( string $scope ): bool {
        return in_array( self::scope( $scope ), self::GUARDED_SCOPES, true );
    }

    /**
     * Begin one deterministic server-side flow. This does not add a guard: it
     * makes the existing anti-crash authority run at most once for the whole
     * flow, so inner capability executors cannot pay for the same gate again.
     */
    public static function begin_flow( string $scope, string $operation, array $args = array() ) {
        self::$flow_depth++;
        if ( self::$flow_depth > 1 ) { return true; }
        self::$flow_scope = self::scope( $scope );
        self::$flow_gate_granted = false;
        if ( ! self::is_site_scope( self::$flow_scope ) ) { return true; }
        return self::before_commit( self::$flow_scope, $operation, $args );
    }

    public static function end_flow(): void {
        if ( self::$flow_depth > 0 ) { self::$flow_depth--; }
        if ( 0 === self::$flow_depth ) { self::$flow_gate_granted = false; self::$flow_scope = 'none'; }
    }

    public static function flow_active(): bool { return self::$flow_depth > 0; }

    public static function flow_scope_for( array $scopes ): string {
        $guarded = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'scope' ), $scopes ), array( __CLASS__, 'is_site_scope' ) ) ) );
        if ( ! $guarded ) { return 'none'; }
        if ( 1 === count( $guarded ) ) { return $guarded[0]; }
        return 'suite';
    }

    /** The only raw Anti_Crash::guard() invocation in the suite. */
    public static function before_commit( string $scope, string $operation, array $args = array() ) {
        $scope = self::scope( $scope );
        if ( ! self::is_site_scope( $scope ) ) { return true; }
        if ( self::$flow_depth > 0 && self::$flow_gate_granted ) { return true; }
        $args['_prstudio_mutation_scope'] = $scope;
        $result = PRSTUDIO_UC_Anti_Crash::guard( $operation, $args );
        if ( self::$flow_depth > 0 && ! is_wp_error( $result ) ) { self::$flow_gate_granted = true; }
        return $result;
    }

    public static function scope_for_legacy_route( string $route, string $action = '', array $meta = array() ): string {
        if ( ! empty( $meta['read_only'] ) ) { return 'none'; }
        $route = '/' . trim( strtolower( $route ), '/' );
        if ( '/frontend-manage' === $route ) { return 'browser'; }
        if ( '/database-manage' === $route ) { return 'database'; }
        if ( '/files-manage' === $route ) { return 'filesystem'; }
        if ( '/plugins-manage' === $route ) { return 'plugin'; }
        if ( '/themes-manage' === $route ) { return 'theme'; }
        if ( '/global-search' === $route || '/logs-manage' === $route ) { return 'internal'; }
        return 'wordpress';
    }

    public static function scope_for_capability( array $cap ): string {
        if ( ! empty( $cap['read_only'] ) ) { return 'none'; }
        $id = strtolower( (string) ( $cap['id'] ?? '' ) );
        $domain = strtolower( (string) ( $cap['domain'] ?? '' ) );
        $source = is_array( $cap['source'] ?? null ) ? $cap['source'] : array();
        $kind = strtolower( (string) ( $source['kind'] ?? '' ) );
        $route = strtolower( (string) ( $source['route'] ?? '' ) );

        // Legacy executors own their actual pre-commit point. Guarding here too
        // would duplicate the same attestation before the executor has evaluated
        // its own preconditions.
        if ( in_array( $kind, array( 'legacy_action', 'legacy_direct_tool' ), true ) ) { return 'deferred'; }
        if ( ! empty( $cap['browser_required'] ) || 'browser' === $domain || '/frontend-manage' === $route ) { return 'browser'; }
        if ( 'seo.gsc.request_indexing' === $id ) { return 'browser'; }
        if ( 'content.transaction.patch' === $id ) { return 'deferred'; }
        if ( 'database' === $domain || str_starts_with( $id, 'database.' ) ) { return 'database'; }
        if ( 'files' === $domain || str_starts_with( $id, 'files.' ) ) { return 'filesystem'; }
        if ( 'commerce' === $domain || str_starts_with( $id, 'commerce.' ) ) { return 'commerce'; }
        if ( 'content_seo' === $domain || str_starts_with( $id, 'content.' ) || str_starts_with( $id, 'seo.' ) ) { return 'content'; }
        if ( 'rollback.job' === $id ) { return 'suite'; }
        return 'internal';
    }

    public static function scope_for_direct_tool( string $name, array $args = array() ): string {
        $name = sanitize_key( $name );
        if ( '' === $name ) { return 'none'; }
        if ( str_starts_with( $name, 'rpconnector_' ) || 'rpconnector_action_call' === $name ) { return 'deferred'; }
        if ( in_array( $name, array( 'wordpress_content_transaction', 'prstudio_execute', 'prstudio_do' ), true ) ) { return 'deferred'; }
        if ( in_array( $name, array( 'write_file', 'append_file', 'truncate_file', 'delete_file', 'restore_file', 'patch_file' ), true ) ) { return 'filesystem'; }
        if ( 'set_plugin_state' === $name ) { return 'plugin'; }
        if ( 'switch_theme' === $name ) { return 'theme'; }
        if ( in_array( $name, array( 'update_content', 'upsert_term', 'assign_terms', 'update_object_meta', 'update_media', 'purge_cache' ), true ) ) { return 'wordpress'; }
        if ( in_array( $name, array( 'rank_math_redirect_upsert', 'rank_math_redirect_delete', 'rank_math_sitemap_invalidate' ), true ) ) { return 'seo'; }
        if ( 'search_console_request_indexing' === $name ) { return 'browser'; }
        if ( str_starts_with( $name, 'browser_' ) || str_contains( $name, 'playwright' ) ) { return 'browser'; }
        if ( str_contains( $name, 'database' ) || str_starts_with( $name, 'db_' ) ) { return 'database'; }
        if ( str_contains( $name, 'filesystem' ) || preg_match( '/(^|_)file(s)?(_|$)/', $name ) ) { return 'filesystem'; }
        if ( preg_match( '/(^|_)plugin(s)?(_|$)/', $name ) ) { return 'plugin'; }
        if ( preg_match( '/(^|_)theme(s)?(_|$)/', $name ) ) { return 'theme'; }
        if ( preg_match( '/(?:product|order|commerce|inventory|catalog|woocommerce)/', $name ) ) { return 'commerce'; }
        if ( preg_match( '/(?:content|post|option|meta|term|taxonomy|media|seo|cache|cron)/', $name ) ) { return 'wordpress'; }

        // Enterprise action tools are normalized into legacy.direct.<action>
        // capabilities. Reuse that runtime metadata instead of guessing from
        // readOnlyHint.
        if ( class_exists( 'PRSTUDIO_UC_Capability_Registry' ) ) {
            $cap_id = 'legacy.direct.' . str_replace( '_', '-', $name );
            try { $cap = PRSTUDIO_UC_Capability_Registry::get( $cap_id ); } catch ( Throwable $ignored ) { $cap = null; }
            if ( is_array( $cap ) ) {
                $domain = strtolower( (string) ( $cap['domain'] ?? '' ) );
                if ( ! empty( $cap['read_only'] ) ) { return 'none'; }
                if ( 'browser' === $domain ) { return 'external'; }
                if ( 'data_storage' === $domain ) {
                    return preg_match( '/file|path|directory|asset/', $name ) ? 'filesystem' : 'database';
                }
                if ( in_array( $domain, array( 'content_seo', 'catalog_commerce', 'orders_customers', 'experience_ui', 'extensions_themes', 'media_stories', 'security_identity' ), true ) ) { return 'wordpress'; }
                if ( 'operations' === $domain ) {
                    return preg_match( '/cache|database|file|plugin|theme|deploy|rollback|restore|backup|maintenance|cron|sitemap|cdn/', $name ) ? 'suite' : 'internal';
                }
            }
        }
        return 'internal';
    }
}

