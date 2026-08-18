<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PRSTUDIO_UC_Health {
	private static function integrity_status(): array {
		$manifest_path = PRSTUDIO_UC_DIR . 'FILE-INTEGRITY.json';
		if ( ! is_readable( $manifest_path ) ) { return array( 'present'=>false, 'verified'=>false, 'reason'=>'manifest_unreadable' ); }
		$manifest_raw = file_get_contents( $manifest_path );
		$manifest = json_decode( (string) $manifest_raw, true );
		if ( ! is_array( $manifest ) || 'SHA-256' !== (string) ( $manifest['algorithm'] ?? '' ) || ! is_array( $manifest['files'] ?? null ) ) {
			return array( 'present'=>true, 'verified'=>false, 'reason'=>'manifest_invalid' );
		}
		$manifest_sha = hash( 'sha256', (string) $manifest_raw );
		$missing = array(); $mismatched = array(); $checked = 0; $records = array();
		foreach ( $manifest['files'] as $entry ) {
			$relative = str_replace( '\\\\', '/', ltrim( (string) ( $entry['path'] ?? '' ), '/\\\\' ) );
			if ( '' === $relative || str_contains( $relative, "\0" ) || preg_match( '#(^|/)\\.\\.(/|$)#', $relative ) ) { $mismatched[] = $relative ?: '[invalid-path]'; continue; }
			$path = PRSTUDIO_UC_DIR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ! is_file( $path ) ) { $missing[] = $relative; continue; }
			$actual_bytes = filesize( $path ); $actual_sha = hash_file( 'sha256', $path ); $checked++;
			if ( false === $actual_sha || (int) ( $entry['bytes'] ?? -1 ) !== (int) $actual_bytes || ! hash_equals( strtolower( (string) ( $entry['sha256'] ?? '' ) ), strtolower( (string) $actual_sha ) ) ) $mismatched[] = $relative;
			$records[] = $relative . "\0" . (int) $actual_bytes . "\0" . (string) $actual_sha . "\n";
		}
		$tree_digest = hash( 'sha256', implode( '', $records ) );
		$expected_tree = strtolower( (string) ( $manifest['tree_digest'] ?? '' ) );
		$verified = ! $missing && ! $mismatched && count( $manifest['files'] ) === $checked && ( '' === $expected_tree || hash_equals( $expected_tree, $tree_digest ) );
		$status = array(
			'present'=>true, 'verified'=>$verified, 'files_checked'=>$checked,
			'expected_files'=>count( $manifest['files'] ), 'missing'=>count( $missing ), 'mismatched'=>count( $mismatched ),
			'missing_preview'=>array_slice( $missing, 0, 10 ), 'mismatched_preview'=>array_slice( $mismatched, 0, 10 ),
			'manifest_sha256'=>$manifest_sha, 'tree_digest'=>$tree_digest, 'expected_tree_digest'=>$expected_tree,
		);
		return $status;
	}

	private static function build_identity( array $integrity ): array {
		$tree = strtolower( (string) ( $integrity['expected_tree_digest'] ?? $integrity['tree_digest'] ?? '' ) );
		return array(
			'suite_version'=>PRSTUDIO_UC_VERSION,
			'control_build_id'=>'prstudio-control-' . PRSTUDIO_UC_VERSION . ( $tree ? '+' . substr( $tree, 0, 12 ) : '+unverified' ),
			'integrity_tree_sha256'=>$tree,
			'integrity_verified'=>! empty( $integrity['verified'] ),
		);
	}
	private static function compact_job( array $job ): array {
		$error = is_array( $job['error'] ?? null ) ? $job['error'] : array();
		$verification = is_array( $job['verification'] ?? null ) ? $job['verification'] : array();
		return array(
			'job_uuid' => sanitize_text_field( (string) ( $job['job_uuid'] ?? '' ) ),
			'capability' => sanitize_text_field( (string) ( $job['capability'] ?? '' ) ),
			'objective' => substr( sanitize_text_field( (string) ( $job['objective'] ?? '' ) ), 0, 180 ),
			'status' => sanitize_key( (string) ( $job['status'] ?? '' ) ),
			'progress' => (int) ( $job['progress'] ?? 0 ),
			'attempts' => (int) ( $job['attempts'] ?? 0 ),
			'error_code' => sanitize_key( (string) ( $error['code'] ?? '' ) ),
			'verified' => ! empty( $verification['ok'] ) || ! empty( $job['evidence']['verified'] ),
			'created_gmt' => sanitize_text_field( (string) ( $job['created_gmt'] ?? '' ) ),
			'completed_gmt' => sanitize_text_field( (string) ( $job['completed_gmt'] ?? '' ) ),
		);
	}

	private static function compact_task( array $task ): array {
		$error = is_array( $task['error'] ?? null ) ? $task['error'] : array();
		$verification = is_array( $task['verification'] ?? null ) ? $task['verification'] : array();
		return array(
			'task_uuid' => sanitize_text_field( (string) ( $task['task_uuid'] ?? '' ) ),
			'device_uuid' => sanitize_text_field( (string) ( $task['device_uuid'] ?? '' ) ),
			'action' => sanitize_key( (string) ( $task['action'] ?? '' ) ),
			'status' => sanitize_key( (string) ( $task['status'] ?? '' ) ),
			'attempt_count' => (int) ( $task['attempt_count'] ?? 0 ),
			'recovery_count' => (int) ( $task['recovery_count'] ?? 0 ),
			'error_code' => sanitize_key( (string) ( $error['code'] ?? '' ) ),
			'verified' => ! empty( $verification['ok'] ),
			'created_gmt' => sanitize_text_field( (string) ( $task['created_gmt'] ?? '' ) ),
			'completed_gmt' => sanitize_text_field( (string) ( $task['completed_gmt'] ?? '' ) ),
		);
	}

	public static function snapshot( array $args = array() ): array {
		global $wpdb;
		$detail = 'full' === sanitize_key( (string) ( $args['detail'] ?? 'compact' ) ) ? 'full' : 'compact';
		$recent_limit = max( 1, min( 20, (int) ( $args['recent_limit'] ?? 5 ) ) );
		$devices = PRSTUDIO_UC_Store::list_devices();
		$online = count( array_filter( $devices, static fn( $device ) => ! empty( $device['online'] ) ) );
		$active = count( array_filter( $devices, static fn( $device ) => 'active' === (string) ( $device['status'] ?? '' ) ) );
		$revoked = count( array_filter( $devices, static fn( $device ) => 'revoked' === (string) ( $device['status'] ?? '' ) ) );
		$tables = array();
		foreach ( array( PRSTUDIO_UC_Store::devices_table(), PRSTUDIO_UC_Store::tasks_table(), PRSTUDIO_UC_Store::jobs_table(), PRSTUDIO_UC_Store::events_table(), PRSTUDIO_UC_Store::schedules_table(), PRSTUDIO_UC_Store::dead_letters_table() ) as $table ) {
			$tables[ $table ] = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		$artifact_status = class_exists( 'PRSTUDIO_UC_Artifacts' ) ? PRSTUDIO_UC_Artifacts::status() : array( 'ok'=>false, 'reason'=>'artifact_class_missing' );
		$integrity_status = self::integrity_status();
		$checks = array(
			'contract' => ! empty( PRSTUDIO_UC_Contract::data() ),
			'database_tables' => ! in_array( false, $tables, true ),
			'integrity_manifest' => ! empty( $integrity_status['verified'] ),
			'private_storage' => defined( 'WP_CONTENT_DIR' ) ? is_writable( WP_CONTENT_DIR ) : true,
			'screenshot_storage' => ! empty( $artifact_status['ok'] ),
			'durable_schema_v4' => PRSTUDIO_UC_Store::schema_ready(),
			// The collector is a health signal in its own right: a site whose
			// cron stopped will silently go back to growing without bound, and
			// that failure is invisible until the database is already full.
			'garbage_collector_scheduled' => function_exists( 'wp_next_scheduled' ) && (bool) wp_next_scheduled( 'prstudio_uc_gc' ),
		);
		$jobs = PRSTUDIO_UC_Store::recent_jobs( $recent_limit );
		$tasks = PRSTUDIO_UC_Store::recent_tasks( $recent_limit );
		if ( 'compact' === $detail ) {
			$jobs = array_map( array( __CLASS__, 'compact_job' ), $jobs );
			$tasks = array_map( array( __CLASS__, 'compact_task' ), $tasks );
		}
		$browser_actions = class_exists( 'PRSTUDIO_UC_Contract' ) ? array_values( array_filter( PRSTUDIO_UC_Contract::domain_actions( 'browser' ), static fn( $meta ) => 'browser_agent' === (string) ( $meta['executor'] ?? '' ) ) ) : array();
		return array(
			'ok' => ! in_array( false, $checks, true ),
			'version' => PRSTUDIO_UC_VERSION,
			'detail' => $detail,
			'protocol_version' => PRSTUDIO_UC_Contract::protocol_version(),
			'contract_hash' => PRSTUDIO_UC_Contract::hash(),
			'checks' => $checks,
			'integrity' => $integrity_status,
			'build_identity' => self::build_identity( $integrity_status ),
			'tables' => $tables,
			'devices' => array( 'total'=>count( $devices ), 'active'=>$active, 'online'=>$online, 'revoked'=>$revoked ),
			'recent_jobs' => $jobs,
			'recent_browser_tasks' => $tasks,
			'screenshot_storage' => $artifact_status,
			'surface' => array(
				'capabilities' => class_exists( 'PRSTUDIO_UC_Capability_Registry' ) ? PRSTUDIO_UC_Capability_Registry::counts() : array(),
				'browser_executable_actions' => count( $browser_actions ),
				'mcp_tools' => class_exists( 'PRSTUDIO_UC_MCP_V5' ) ? count( PRSTUDIO_UC_MCP_V5::tools() ) : null,
				'puppeteer_compatibility' => 'normalized_to_playwright_cdp_runtime',
			),
			// 16.0 execution surface. These four answer the questions the
			// operator actually asks when something feels wrong: is it allowed
			// to act, is the database being drained, is dispatch cheap, and
			// does it remember what it already did.
			'garbage_collection' => class_exists( 'PRSTUDIO_UC_GC' ) ? array(
				'scheduled' => function_exists( 'wp_next_scheduled' ) && (bool) wp_next_scheduled( 'prstudio_uc_gc' ),
				'next_run_gmt' => function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( 'prstudio_uc_gc' ) ? gmdate( 'c', (int) wp_next_scheduled( 'prstudio_uc_gc' ) ) : null,
				'last_run' => PRSTUDIO_UC_GC::last_run(),
				'retention' => PRSTUDIO_UC_GC::retention(),
			) : array(),
			'dispatch_channel' => class_exists( 'PRSTUDIO_UC_Wait_Channel' ) ? PRSTUDIO_UC_Wait_Channel::describe() : array(),
			'interventions' => class_exists( 'PRSTUDIO_UC_Interventions' ) ? PRSTUDIO_UC_Interventions::stats() : array(),
			'loading' => class_exists( 'PRSTUDIO_UC_Autoload' ) ? PRSTUDIO_UC_Autoload::stats() : array(),
			'memory' => class_exists( 'PRSTUDIO_UC_Memory' ) ? PRSTUDIO_UC_Memory::snapshot() : array(),
			'observability' => class_exists( 'PRSTUDIO_UC_Observability' ) ? PRSTUDIO_UC_Observability::snapshot() : array(),
			'enterprise' => class_exists( 'PRSTUDIO_UC_Enterprise_Engine' ) ? PRSTUDIO_UC_Enterprise_Engine::registry() : array(),
			'agency_runtime' => class_exists( 'PRSTUDIO_UC_Agency_Runtime' ) ? PRSTUDIO_UC_Agency_Runtime::status() : array(),
			'operational_twin' => class_exists( 'PRSTUDIO_UC_Operational_Twin' ) ? PRSTUDIO_UC_Operational_Twin::snapshot() : array(),
			'social_intelligence' => class_exists( 'PRSTUDIO_UC_Social_Intelligence' ) ? PRSTUDIO_UC_Social_Intelligence::snapshot() : array(),
			'site_sentinel' => class_exists( 'PRSTUDIO_UC_Site_Sentinel' ) ? array( 'status'=>PRSTUDIO_UC_Site_Sentinel::status(), 'last_scan'=>PRSTUDIO_UC_Site_Sentinel::snapshot() ) : array(),
			'generated_gmt' => gmdate( 'c' ),
		);
	}
}
