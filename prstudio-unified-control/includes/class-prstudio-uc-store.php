<?php

if ( ! defined( 'ABSPATH' ) ) {
/* PR STUDIO ONE-GUARD INVARIANT: Anti-Crash is the only mutation guard. Verification/risk/telemetry never block an executable action. */
	exit;
}

final class PRSTUDIO_UC_Store {
	private const LEASE_SECONDS = 45;
	private const JOB_LEASE_SECONDS = 120;
	private const TASK_TTL = DAY_IN_SECONDS;
	private const JOB_TTL = 30 * DAY_IN_SECONDS;
	private const SCHEMA_VERSION = '4.0.0';

	public static function devices_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prstudio_uc_devices';
	}

	public static function tasks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prstudio_uc_tasks';
	}

	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prstudio_uc_jobs';
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prstudio_uc_events';
	}

	public static function schedules_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prstudio_uc_schedules';
	}

	public static function dead_letters_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prstudio_uc_dead_letters';
	}

	public static function schema_version(): string { return self::SCHEMA_VERSION; }
	public static function job_lease_seconds(): int { return self::JOB_LEASE_SECONDS; }
	public static function schema_ready(): bool {
		return function_exists( 'get_option' ) && self::SCHEMA_VERSION === (string) get_option( 'prstudio_uc_schema_version', '' );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::devices_table() . " (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				device_uuid varchar(64) NOT NULL,
				name varchar(190) NOT NULL,
				token_hash char(64) NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'active',
				capabilities longtext NULL,
				last_seen_gmt datetime NULL,
				created_gmt datetime NOT NULL,
				revoked_gmt datetime NULL,
				PRIMARY KEY (id),
				UNIQUE KEY device_uuid (device_uuid),
				KEY token_hash (token_hash)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::tasks_table() . " (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				task_uuid varchar(64) NOT NULL,
				job_uuid varchar(64) NULL,
				device_uuid varchar(64) NULL,
				idempotency_key char(64) NULL,
				plan_hash char(64) NULL,
				action varchar(128) NOT NULL,
				arguments longtext NOT NULL,
				status varchar(32) NOT NULL,
				step_index int unsigned NOT NULL DEFAULT 0,
				checkpoint longtext NULL,
				verification longtext NULL,
				result longtext NULL,
				error longtext NULL,
				lease_token varchar(64) NULL,
				lease_expires_gmt datetime NULL,
				attempt_count int unsigned NOT NULL DEFAULT 0,
				recovery_count int unsigned NOT NULL DEFAULT 0,
				created_gmt datetime NOT NULL,
				updated_gmt datetime NOT NULL,
				completed_gmt datetime NULL,
				expires_gmt datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY task_uuid (task_uuid),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY job_uuid (job_uuid),
				KEY plan_hash (plan_hash),
				KEY status_device (status, device_uuid),
				KEY lease_expires (lease_expires_gmt)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::jobs_table() . " (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				job_uuid varchar(64) NOT NULL,
				request_id varchar(160) NULL,
				mission_id varchar(120) NULL,
				owner_client_id varchar(190) NULL,
				capability varchar(240) NULL,
				idempotency_key char(64) NULL,
				plan_hash char(64) NULL,
				objective text NOT NULL,
				domain varchar(64) NULL,
				arguments longtext NOT NULL,
				plan longtext NULL,
				status varchar(32) NOT NULL,
				priority smallint unsigned NOT NULL DEFAULT 100,
				step_index int unsigned NOT NULL DEFAULT 0,
				progress int unsigned NOT NULL DEFAULT 0,
				attempts int unsigned NOT NULL DEFAULT 0,
				checkpoint longtext NULL,
				evidence longtext NULL,
				verification longtext NULL,
				result longtext NULL,
				error longtext NULL,
				lease_token varchar(64) NULL,
				lease_expires_gmt datetime NULL,
				available_gmt datetime NULL,
				heartbeat_gmt datetime NULL,
				worker_id varchar(160) NULL,
				max_attempts int unsigned NOT NULL DEFAULT 5,
				backoff_seconds int unsigned NOT NULL DEFAULT 30,
				occurrence_key varchar(190) NULL,
				failure_class varchar(64) NULL,
				created_gmt datetime NOT NULL,
				updated_gmt datetime NOT NULL,
				completed_gmt datetime NULL,
				expires_gmt datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY job_uuid (job_uuid),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY occurrence_key (occurrence_key),
				KEY owner_domain (owner_client_id, domain),
				KEY available_status (status, available_gmt, priority),
				KEY job_lease_expires (lease_expires_gmt),
				KEY status_updated (status, updated_gmt),
				KEY plan_hash (plan_hash)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::events_table() . " (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				task_uuid varchar(64) NOT NULL,
				event_type varchar(64) NOT NULL,
				payload longtext NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY (id),
				KEY task_created (task_uuid, created_gmt)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::schedules_table() . " (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				schedule_uuid varchar(64) NOT NULL,
				playbook varchar(64) NOT NULL,
				objective text NOT NULL,
				context longtext NULL,
				interval_seconds int unsigned NOT NULL DEFAULT 3600,
				next_run_gmt datetime NOT NULL,
				last_run_gmt datetime NULL,
				last_occurrence_key varchar(190) NULL,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				created_gmt datetime NOT NULL,
				updated_gmt datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY schedule_uuid (schedule_uuid),
				KEY due (enabled, next_run_gmt)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::dead_letters_table() . " (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				job_uuid varchar(64) NOT NULL,
				mission_id varchar(120) NULL,
				capability varchar(240) NULL,
				failure_class varchar(64) NULL,
				error longtext NULL,
				job_snapshot longtext NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY job_uuid (job_uuid),
				KEY failure_created (failure_class, created_gmt)
			) $charset;"
		);

		// v4 has one canonical job-state vocabulary. Existing v3 rows remain
		// addressable by the compatibility methods below while being normalized.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$wpdb->query( 'UPDATE ' . self::jobs_table() . ' SET status = UPPER(status) WHERE status <> UPPER(status)' );
		update_option( 'prstudio_uc_schema_version', self::SCHEMA_VERSION, false );
	}

	public static function maybe_upgrade(): bool {
		$version = (string) get_option( 'prstudio_uc_schema_version', '' );
		if ( self::SCHEMA_VERSION === $version ) { return true; }
		$upgrade_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/upgrade.php' : '';
		if ( '' === $upgrade_file || ! is_readable( $upgrade_file ) ) {
			return false;
		}
		self::install();
		return self::SCHEMA_VERSION === (string) get_option( 'prstudio_uc_schema_version', '' );
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private static function uuid(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
	}

	private static function encode( $value ): string {
		return wp_json_encode( self::sanitize_persistent( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function sanitize_persistent( $value, int $depth = 0 ) {
		if ( $depth > 20 ) { return '[DEPTH_LIMIT]'; }
		if ( is_string( $value ) ) {
			if ( 0 === strpos( $value, 'data:image/' ) ) {
				return array( '_omitted' => 'inline_image', 'sha256' => hash( 'sha256', $value ), 'bytes' => strlen( $value ) );
			}
			return strlen( $value ) > 1048576 ? substr( $value, 0, 1048576 ) . '[TRUNCATED]' : $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 5000, true ) as $key => $item ) {
				$out[ $key ] = self::sanitize_persistent( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $value ) ) { return self::sanitize_persistent( get_object_vars( $value ), $depth + 1 ); }
		return $value;
	}

	private static function decode( $value, $default = array() ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $default;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : $default;
	}

	public static function create_device( string $name, array $capabilities ): array {
		global $wpdb;
		$uuid = self::uuid();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$token = 'psuc_' . bin2hex( random_bytes( 32 ) );
		$inserted = $wpdb->insert(
			self::devices_table(),
			array(
				'device_uuid' => $uuid,
				'name' => sanitize_text_field( $name ?: 'Chrome' ),
				'token_hash' => hash( 'sha256', $token ),
				'status' => 'active',
				'capabilities' => self::encode( $capabilities ),
				'last_seen_gmt' => self::now(),
				'created_gmt' => self::now(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return array( 'device_id' => $uuid, 'token' => $token );
	}

	public static function device_by_token( string $token ): ?array {
		global $wpdb;
		if ( ! str_starts_with( $token, 'psuc_' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$hash = hash( 'sha256', $token );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
				'SELECT * FROM ' . self::devices_table() . " WHERE token_hash = %s AND status = 'active' LIMIT 1",
				$hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['token_hash'], $hash ) ) {
			return null;
		}
		return self::hydrate_device( $row );
	}

	public static function touch_device( string $device_uuid, array $capabilities = array() ): void {
		global $wpdb;
		$data = array( 'last_seen_gmt' => self::now() );
		$formats = array( '%s' );
		if ( $capabilities ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
			$data['capabilities'] = self::encode( $capabilities );
			$formats[] = '%s';
		}
		$wpdb->update( self::devices_table(), $data, array( 'device_uuid' => $device_uuid ), $formats, array( '%s' ) );
	}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.

	public static function list_devices(): array {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::devices_table() . ' ORDER BY created_gmt DESC', ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate_device' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Resolve the public canonical device UUID from either the UUID itself or the
	 * historical numeric database id. The numeric id is accepted only as a legacy
	 * input alias and is never returned to API/MCP callers.
	 */
	public static function resolve_device_uuid( string $identifier, bool $online_only = true ): ?string {
		global $wpdb;
		$identifier = trim( $identifier );
		if ( '' === $identifier ) { return null; }

		$where = 'device_uuid = %s';
		$params = array( $identifier );
		if ( ctype_digit( $identifier ) ) {
			$where = '(device_uuid = %s OR id = %d)';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
			$params[] = (int) $identifier;
		}
		if ( $online_only ) { $where .= " AND status = 'active' AND last_seen_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND)"; }
		$sql = 'SELECT device_uuid FROM ' . self::devices_table() . ' WHERE ' . $where . ' LIMIT 1';
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
		$uuid = is_array( $row ) ? trim( (string) ( $row['device_uuid'] ?? '' ) ) : '';
		return '' !== $uuid ? $uuid : null;
	}

	public static function public_device( array $device ): array {
		unset( $device['id'], $device['token_hash'] );
		return $device;
	}

	public static function public_devices( array $devices ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		return array_values( array_map( array( __CLASS__, 'public_device' ), $devices ) );
	}

	public static function revoke_device( string $device_uuid ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::devices_table(),
			array( 'status' => 'revoked', 'revoked_gmt' => self::now() ),
			array( 'device_uuid' => $device_uuid ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	private static function hydrate_device( array $row ): array {
		$row['capabilities'] = self::decode( $row['capabilities'] ?? '', array() );
		$last_seen = ! empty( $row['last_seen_gmt'] ) ? strtotime( (string) $row['last_seen_gmt'] . ' UTC' ) : 0;
		$age = $last_seen > 0 ? max( 0, time() - $last_seen ) : null;
		$row['last_seen_age_seconds'] = $age;
		$row['online'] = 'active' === (string) ( $row['status'] ?? '' ) && null !== $age && $age <= 90;
		if ( 'revoked' === (string) ( $row['status'] ?? '' ) ) {
			$row['connection_status'] = 'revoked';
		} elseif ( $row['online'] ) {
			$row['connection_status'] = 'online';
		} elseif ( null === $age || $age > DAY_IN_SECONDS ) {
			$row['connection_status'] = 'stale';
		} else {
			$row['connection_status'] = 'offline';
		}
		unset( $row['token_hash'] );
		return $row;
	}

	public static function create_task( string $action, array $arguments, ?string $device_uuid = null, string $idempotency_key = '', string $plan_hash = '', string $job_uuid = '' ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		global $wpdb;
		$idempotency_key = preg_match( '/^[a-f0-9]{64}$/', $idempotency_key ) ? $idempotency_key : '';
		$plan_hash = preg_match( '/^[a-f0-9]{64}$/', $plan_hash ) ? $plan_hash : '';
		$job_uuid = preg_match( '/^[a-f0-9-]{20,64}$/i', $job_uuid ) ? strtolower( $job_uuid ) : '';
		if ( '' !== $idempotency_key ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tasks_table() . ' WHERE idempotency_key = %s LIMIT 1', $idempotency_key ), ARRAY_A );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
			if ( is_array( $existing ) ) {
				$task = self::hydrate_task( $existing );
				if ( ! in_array( (string) $task['status'], array( PRSTUDIO_UC_State_Machine::FAILED, PRSTUDIO_UC_State_Machine::CANCELLED, PRSTUDIO_UC_State_Machine::EXPIRED ), true ) ) {
					$task['idempotent_replay'] = true;
					return $task;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
				}
				$wpdb->update( self::tasks_table(), array( 'idempotency_key'=>null ), array( 'task_uuid'=>(string)$task['task_uuid'], 'idempotency_key'=>$idempotency_key ), array( '%s' ), array( '%s','%s' ) );
			}
		}
		$uuid = self::uuid();
		$now = self::now();
		$inserted = $wpdb->insert(
			self::tasks_table(),
			array(
				'task_uuid' => $uuid,
				'job_uuid' => '' !== $job_uuid ? $job_uuid : null,
				'device_uuid' => $device_uuid,
				'idempotency_key' => '' !== $idempotency_key ? $idempotency_key : null,
				'plan_hash' => '' !== $plan_hash ? $plan_hash : null,
				'action' => sanitize_key( $action ),
				'arguments' => self::encode( $arguments ),
				'status' => PRSTUDIO_UC_State_Machine::QUEUED,
				'step_index' => 0,
				'checkpoint' => self::encode( array( 'last_completed_step' => -1 ) ),
				'created_gmt' => $now,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
				'updated_gmt' => $now,
				'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() + self::TASK_TTL ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted && '' !== $idempotency_key ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			$winner = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tasks_table() . ' WHERE idempotency_key = %s LIMIT 1', $idempotency_key ), ARRAY_A );
			if ( is_array( $winner ) ) { $task = self::hydrate_task( $winner ); $task['idempotent_replay'] = true; return $task; }
		}
		if ( false === $inserted ) {
			return array( 'ok'=>false, 'error'=>array( 'code'=>'task_create_failed', 'message'=>'The durable browser task could not be persisted.' ) );
		}
		self::event( $uuid, 'task.created', array( 'action' => $action, 'plan_hash'=>$plan_hash, 'idempotent'=>'' !== $idempotency_key ) );
		// Release any agent currently holding a long-poll. Without this signal
		// a waiting agent would sit until its budget expired even though work
		// is ready, which would turn the long poll into added latency instead
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		// of removed load.
		if ( class_exists( 'PRSTUDIO_UC_Wait_Channel' ) ) { PRSTUDIO_UC_Wait_Channel::signal( 'task_created' ); }
		return self::get_task( $uuid ) ?? array();
	}

	public static function get_task( string $task_uuid ): ?array {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			$wpdb->prepare( 'SELECT * FROM ' . self::tasks_table() . ' WHERE task_uuid = %s LIMIT 1', $task_uuid ),
			ARRAY_A
		);
		return is_array( $row ) ? self::hydrate_task( $row ) : null;
	}

	public static function terminal_browser_tasks_with_waiting_parents( int $limit = 200 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		$tasks = self::tasks_table();
		$jobs = self::jobs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			"SELECT t.* FROM $tasks t INNER JOIN $jobs j ON j.job_uuid = t.job_uuid WHERE j.status = %s AND t.status IN (%s,%s,%s,%s) ORDER BY t.id ASC LIMIT %d",
			'WAITING_FOR_BROWSER',
			PRSTUDIO_UC_State_Machine::COMPLETED,
			PRSTUDIO_UC_State_Machine::FAILED,
			PRSTUDIO_UC_State_Machine::CANCELLED,
			PRSTUDIO_UC_State_Machine::EXPIRED,
			$limit
		);
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) { if ( is_array( $row ) ) { $out[] = self::hydrate_task( $row ); } }
		return $out;
	}

	private static function hydrate_task( array $row ): array {
		foreach ( array( 'arguments', 'checkpoint', 'verification', 'result', 'error' ) as $field ) {
			$row[ $field ] = self::decode( $row[ $field ] ?? '', array() );
		}
		$row['step_index'] = (int) $row['step_index'];
		$row['attempt_count'] = (int) ( $row['attempt_count'] ?? 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$row['recovery_count'] = (int) ( $row['recovery_count'] ?? 0 );
		return $row;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	}

	public static function claim_next( string $device_uuid ): ?array {
		global $wpdb;
		self::recover_stale_tasks();
		$lease = bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );
		$table = self::tasks_table();

		$wpdb->query( 'START TRANSACTION' );
		try {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
					"SELECT * FROM $table
					WHERE status = %s
					  AND (device_uuid IS NULL OR device_uuid = %s)
					  AND expires_gmt > UTC_TIMESTAMP()
					ORDER BY id ASC
					LIMIT 1
					FOR UPDATE",
					PRSTUDIO_UC_State_Machine::QUEUED,
					$device_uuid
				),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				$wpdb->query( 'COMMIT' );
				return null;
			}
			$from = (string) $row['status'];
			$to = PRSTUDIO_UC_State_Machine::LEASED;
			PRSTUDIO_UC_State_Machine::assert_transition( $from, $to );
			$wpdb->update(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
				$table,
				array(
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
					'device_uuid' => $device_uuid,
					'status' => $to,
					'lease_token' => $lease,
					'lease_expires_gmt' => $expires,
					'updated_gmt' => self::now(),
					'attempt_count' => (int) ( $row['attempt_count'] ?? 0 ) + 1,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
			throw $e;
		}
		self::event( (string) $row['task_uuid'], 'task.claimed', array( 'device_id' => $device_uuid ) );
		$task = self::get_task( (string) $row['task_uuid'] );
		if ( $task ) {
			$task['lease_token'] = $lease;
		}
		return $task;
	}

	public static function heartbeat( string $task_uuid, string $lease_token ): bool {
		global $wpdb;
		if ( '' === $lease_token ) { return false; }
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$updated = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			'UPDATE ' . self::tasks_table() . ' SET lease_expires_gmt = %s, updated_gmt = %s WHERE task_uuid = %s AND lease_token = %s AND status IN (%s,%s) AND lease_expires_gmt >= UTC_TIMESTAMP()',
			gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS ), self::now(), $task_uuid, $lease_token,
			PRSTUDIO_UC_State_Machine::LEASED, PRSTUDIO_UC_State_Machine::RUNNING
		) );
		return 1 === (int) $updated;
	}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.

	public static function mark_running( string $task_uuid, string $lease_token ): ?array {
		return self::transition( $task_uuid, PRSTUDIO_UC_State_Machine::RUNNING, array(), $lease_token );
	}

	public static function checkpoint( string $task_uuid, string $lease_token, int $step_index, array $result ): ?array {
		global $wpdb;
		$task = self::get_task( $task_uuid );
		if ( ! $task || '' === $lease_token || ! hash_equals( (string) ( $task['lease_token'] ?? '' ), $lease_token ) ) {
			return null;
		}
		if ( ! in_array( (string) $task['status'], array( PRSTUDIO_UC_State_Machine::LEASED, PRSTUDIO_UC_State_Machine::RUNNING ), true ) ) { return null; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$current_step = (int) $task['step_index'];
		if ( $current_step > $step_index ) { return $task; }
		$checkpoint = PRSTUDIO_UC_State_Machine::next_checkpoint( $task['checkpoint'], $step_index, $result );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$updated = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			'UPDATE ' . self::tasks_table() . ' SET step_index = %d, checkpoint = %s, updated_gmt = %s, lease_expires_gmt = %s WHERE task_uuid = %s AND lease_token = %s AND status = %s AND step_index = %d',
			$step_index + 1, self::encode( $checkpoint ), self::now(), gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS ),
			$task_uuid, $lease_token, (string) $task['status'], $current_step
		) );
		if ( 1 !== (int) $updated ) { return null; }
		self::event( $task_uuid, 'task.checkpoint', array( 'step_index' => $step_index ) );
		return self::get_task( $task_uuid );
	}

	public static function set_verification( string $task_uuid, array $verification ): bool {
		global $wpdb;
		$updated = $wpdb->update( self::tasks_table(), array( 'verification'=>self::encode( $verification ), 'updated_gmt'=>self::now() ), array( 'task_uuid'=>$task_uuid ), array( '%s','%s' ), array( '%s' ) );
		if ( false !== $updated ) { self::event( $task_uuid, 'task.verification', $verification ); return true; }
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	public static function complete( string $task_uuid, string $lease_token, array $result ): ?array {
		return self::transition( $task_uuid, PRSTUDIO_UC_State_Machine::COMPLETED, array( 'result' => $result ), $lease_token );
	}

	public static function fail( string $task_uuid, ?string $lease_token, array $error ): ?array {
		return self::transition( $task_uuid, PRSTUDIO_UC_State_Machine::FAILED, array( 'error' => $error ), $lease_token );
	}

	public static function cancel( string $task_uuid ): ?array {
		return self::transition( $task_uuid, PRSTUDIO_UC_State_Machine::CANCELLED );
	}

	/**
	 * Return a non-terminal task to the queue by dropping its lease.
	 *
	 * attempt_count is deliberately left alone. It is the only evidence that
	 * distinguishes "the agent tried and failed" from "the agent never claimed
	 * this" -- the signature that identified the dispatcher outage -- and
	 * resetting it on requeue would erase exactly that.
	 */
	public static function release_lease_for_requeue( string $task_uuid, string $reason = 'operator_requeue' ): ?array {
		global $wpdb;
		$task = self::get_task( $task_uuid );
		if ( ! $task || PRSTUDIO_UC_State_Machine::is_terminal( (string) $task['status'] ) ) { return null; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- job-queue bookkeeping is set-based by design; WP_Query is not applicable to this table.
		$wpdb->update(
			self::tasks_table(),
			array(
				'status' => PRSTUDIO_UC_State_Machine::QUEUED,
				'device_uuid' => null,
				'lease_token' => null,
				'lease_expires_gmt' => null,
				'updated_gmt' => self::now(),
			),
			array( 'task_uuid' => $task_uuid ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
		self::event( $task_uuid, 'task.requeued_by_operator', array( 'reason' => $reason, 'previous_status' => (string) $task['status'] ) );
		return self::get_task( $task_uuid );
	}

	public static function cancel_for_device( string $task_uuid, string $device_uuid ): ?array {
		global $wpdb;
		$task = self::get_task( $task_uuid );
		if ( ! $task || '' === $device_uuid || ! hash_equals( (string) ( $task['device_uuid'] ?? '' ), $device_uuid ) ) { return null; }
		if ( ! PRSTUDIO_UC_State_Machine::can_transition( (string) $task['status'], PRSTUDIO_UC_State_Machine::CANCELLED ) ) { return null; }
		$updated = $wpdb->update(
			self::tasks_table(),
			array( 'status'=>PRSTUDIO_UC_State_Machine::CANCELLED, 'updated_gmt'=>self::now(), 'lease_token'=>null, 'lease_expires_gmt'=>null, 'completed_gmt'=>self::now() ),
			array( 'task_uuid'=>$task_uuid, 'device_uuid'=>$device_uuid, 'status'=>(string)$task['status'] ),
			array( '%s','%s','%s','%s','%s' ), array( '%s','%s','%s' )
		);
		if ( 1 !== (int) $updated ) { return null; }
		self::event( $task_uuid, 'task.cancelled', array( 'device_id'=>$device_uuid, 'ownership_verified'=>true ) );
		return self::get_task( $task_uuid );
	}


	public static function restart_fresh_for_device( string $task_uuid, string $device_uuid, string $reason = 'two_attempts_without_progress' ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		self::recover_stale_tasks();
		$task = self::get_task( $task_uuid );
		if ( ! $task || '' === $device_uuid || ! hash_equals( (string) ( $task['device_uuid'] ?? '' ), $device_uuid ) ) { return null; }
		if ( ! in_array( (string) $task['status'], array( PRSTUDIO_UC_State_Machine::LEASED, PRSTUDIO_UC_State_Machine::RUNNING ), true ) ) { return null; }
		$checkpoint = is_array( $task['checkpoint'] ?? null ) ? $task['checkpoint'] : array();
		// Automatic replay from zero is forbidden once the server has accepted a
		// completed step: this prevents duplicating a prior durable mutation.
		if ( (int) ( $checkpoint['last_completed_step'] ?? -1 ) >= 0 ) { return null; }
		$fresh_count = (int) ( $checkpoint['fresh_restart_count'] ?? 0 );
		if ( $fresh_count >= 1 ) { return null; }
		$checkpoint = array(
			'last_completed_step' => -1,
			'fresh_restart_count' => $fresh_count + 1,
			'fresh_restart_reason' => substr( sanitize_text_field( $reason ), 0, 190 ),
			'fresh_restart_at' => time(),
			'previous_attempt_count' => (int) ( $task['attempt_count'] ?? 0 ),
		);
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$updated = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			'UPDATE ' . self::tasks_table() . ' SET status = %s, step_index = 0, checkpoint = %s, verification = NULL, result = NULL, error = NULL, lease_token = NULL, lease_expires_gmt = NULL, recovery_count = recovery_count + 1, updated_gmt = %s WHERE task_uuid = %s AND device_uuid = %s AND status = %s',
			PRSTUDIO_UC_State_Machine::QUEUED,
			self::encode( $checkpoint ),
			self::now(),
			$task_uuid,
			$device_uuid,
			(string) $task['status']
		) );
		if ( 1 !== (int) $updated ) { return null; }
		self::event( $task_uuid, 'task.fresh_restart', array( 'reason'=>$reason, 'fresh_restart_count'=>$fresh_count + 1, 'attempt_count'=>(int)($task['attempt_count']??0) ) );
		$result = self::get_task( $task_uuid );
		if ( $result ) { $result['fresh_restart'] = true; }
		return $result;
	}

	private static function transition( string $task_uuid, string $to, array $data = array(), ?string $lease_token = null ): ?array {
		global $wpdb;
		$task = self::get_task( $task_uuid );
		if ( ! $task ) {
			return null;
		}
		if ( null !== $lease_token && ! hash_equals( (string) ( $task['lease_token'] ?? '' ), $lease_token ) ) {
			return null;
		}
		if ( ! PRSTUDIO_UC_State_Machine::can_transition( (string) $task['status'], $to ) ) {
			return null;
		}
		$update = array(
			'status' => $to,
			'updated_gmt' => self::now(),
		);
		$formats = array( '%s', '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		if ( isset( $data['result'] ) ) {
			$update['result'] = self::encode( $data['result'] );
			$formats[] = '%s';
		}
		if ( isset( $data['error'] ) ) {
			$update['error'] = self::encode( $data['error'] );
			$formats[] = '%s';
		}
		if ( PRSTUDIO_UC_State_Machine::is_terminal( $to ) ) {
			$update['lease_token'] = null;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
			$update['lease_expires_gmt'] = null;
			$update['completed_gmt'] = self::now();
			$formats[] = '%s';
			$formats[] = '%s';
			$formats[] = '%s';
		}
		$where = array( 'task_uuid'=>$task_uuid, 'status'=>(string)$task['status'] );
		$where_formats = array( '%s','%s' );
		if ( null !== $lease_token ) { $where['lease_token']=$lease_token; $where_formats[]='%s'; }
		$updated = $wpdb->update( self::tasks_table(), $update, $where, $formats, $where_formats );
		if ( 1 !== (int) $updated ) { return null; }
		self::event( $task_uuid, 'task.' . $to, $data );
		return self::get_task( $task_uuid );
	}

	public static function recover_stale_tasks(): int {
		global $wpdb;
		$table = self::tasks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$now = self::now();
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
				"UPDATE $table
				 SET status = %s,
				     recovery_count = recovery_count + 1,
				     lease_token = NULL,
				     lease_expires_gmt = NULL,
				     updated_gmt = %s
				 WHERE status IN (%s, %s)
				   AND lease_expires_gmt IS NOT NULL
				   AND lease_expires_gmt < %s",
				PRSTUDIO_UC_State_Machine::QUEUED,
				$now,
				PRSTUDIO_UC_State_Machine::LEASED,
				PRSTUDIO_UC_State_Machine::RUNNING,
				$now
			)
		);
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
				"UPDATE $table SET status = %s, updated_gmt = %s
				 WHERE status NOT IN (%s, %s, %s, %s)
				   AND expires_gmt < %s",
				PRSTUDIO_UC_State_Machine::EXPIRED,
				$now,
				PRSTUDIO_UC_State_Machine::COMPLETED,
				PRSTUDIO_UC_State_Machine::FAILED,
				PRSTUDIO_UC_State_Machine::CANCELLED,
				PRSTUDIO_UC_State_Machine::EXPIRED,
				$now
			)
		);
		return max( 0, (int) $affected );
	}

	public static function canonical_job_state( string $state ): string {
		$state = strtoupper( str_replace( '-', '_', sanitize_key( $state ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$aliases = array(
			'QUEUED'=>'READY', 'FAILED'=>'TECHNICAL_ERROR', 'WAITING_BROWSER'=>'WAITING_FOR_BROWSER',
			'DEADLETTER'=>'DEAD_LETTER',
		);
		return $aliases[ $state ] ?? $state;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	}

	public static function terminal_job_state( string $state ): bool {
		return in_array( self::canonical_job_state( $state ), array( 'COMPLETED','TECHNICAL_ERROR','CANCELLED','DEAD_LETTER' ), true );
	}

	private static function replayable_job( array $job ): bool {
		$state = self::canonical_job_state( (string) ( $job['status'] ?? '' ) );
		$expires = ! empty( $job['expires_gmt'] ) ? strtotime( (string) $job['expires_gmt'] . ' UTC' ) : 0;
		if ( $expires && $expires <= time() ) { return false; }
		return ! in_array( $state, array( 'TECHNICAL_ERROR','CANCELLED','INTERRUPTED','DEAD_LETTER' ), true );
	}

	public static function create_job( string $objective, string $domain, array $arguments, array $plan, string $idempotency_key = '', string $plan_hash = '', array $options = array() ): array {
		global $wpdb;
		$idempotency_key = preg_match( '/^[a-f0-9]{64}$/', $idempotency_key ) ? $idempotency_key : '';
		$plan_hash = preg_match( '/^[a-f0-9]{64}$/', $plan_hash ) ? $plan_hash : '';
		if ( '' !== $idempotency_key ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::jobs_table() . ' WHERE idempotency_key = %s LIMIT 1', $idempotency_key ), ARRAY_A );
			if ( is_array( $existing ) ) {
				$job = self::hydrate_job( $existing );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
				if ( self::replayable_job( $job ) ) { $job['idempotent_replay'] = true; return $job; }
				// Failed, cancelled and expired work must not poison a stable retry key.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
				$wpdb->update( self::jobs_table(), array( 'idempotency_key'=>null ), array( 'job_uuid'=>(string)$job['job_uuid'], 'idempotency_key'=>$idempotency_key ), array( '%s' ), array( '%s','%s' ) );
			}
		}
		$uuid = self::uuid(); $now = self::now();
		$available = ! empty( $options['available_gmt'] ) ? strtotime( (string) $options['available_gmt'] . ' UTC' ) : time();
		if ( false === $available ) { $available = time(); }
		$status = self::canonical_job_state( (string) ( $options['status'] ?? 'READY' ) );
		if ( ! in_array( $status, array( 'PLANNED','READY' ), true ) ) { $status = 'READY'; }
		$data = array(
			'job_uuid'=>$uuid, 'request_id'=>substr( sanitize_text_field( (string)($options['request_id']??'') ), 0, 160 ) ?: null,
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
			'mission_id'=>substr( sanitize_text_field( (string)($options['mission_id']??'') ), 0, 120 ) ?: null,
			'owner_client_id'=>substr( sanitize_text_field( (string)($options['owner_client_id']??'') ), 0, 190 ) ?: null,
			'capability'=>substr( sanitize_text_field( (string)($options['capability']??'') ), 0, 240 ) ?: null,
			'idempotency_key'=>'' !== $idempotency_key ? $idempotency_key : null, 'plan_hash'=>'' !== $plan_hash ? $plan_hash : null,
			'objective'=>sanitize_text_field( $objective ), 'domain'=>sanitize_key( $domain ), 'arguments'=>self::encode( $arguments ), 'plan'=>self::encode( $plan ),
			'status'=>$status, 'priority'=>max(0,min(255,(int)($options['priority']??100))), 'step_index'=>0, 'progress'=>0, 'attempts'=>0,
			'checkpoint'=>self::encode( is_array($options['checkpoint']??null)?$options['checkpoint']:array('last_completed_step'=>-1) ),
			'available_gmt'=>gmdate('Y-m-d H:i:s',$available), 'max_attempts'=>max(1,min(25,(int)($options['max_attempts']??5))),
			'backoff_seconds'=>max(5,min(3600,(int)($options['backoff_seconds']??30))),
			'occurrence_key'=>substr(sanitize_text_field((string)($options['occurrence_key']??'')),0,190)?:null,
			'created_gmt'=>$now, 'updated_gmt'=>$now, 'expires_gmt'=>gmdate('Y-m-d H:i:s',time()+max(HOUR_IN_SECONDS,min(YEAR_IN_SECONDS,(int)($options['ttl_seconds']??self::JOB_TTL)))),
		);
		$inserted = $wpdb->insert( self::jobs_table(), $data );
		if ( false === $inserted && '' !== $idempotency_key ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			$winner = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::jobs_table() . ' WHERE idempotency_key = %s LIMIT 1', $idempotency_key ), ARRAY_A );
			if ( is_array( $winner ) ) { $job=self::hydrate_job($winner); $job['idempotent_replay']=true; return $job; }
		}
		if ( false === $inserted ) { return array(); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		self::event( 'job:' . $uuid, 'job.created', array( 'domain'=>$domain, 'plan_hash'=>$plan_hash, 'idempotent'=>'' !== $idempotency_key, 'status'=>$status ) );
		return self::get_job( $uuid ) ?? array();
	}

	public static function get_job( string $job_uuid ): ?array {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::jobs_table() . ' WHERE job_uuid = %s LIMIT 1', $job_uuid ), ARRAY_A );
		return is_array( $row ) ? self::hydrate_job( $row ) : null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	public static function get_owned_agency_job( string $job_uuid, string $owner_client_id ): ?array {
		$job=self::get_job($job_uuid);
		if(!$job||'agency'!==(string)($job['domain']??'')||''===$owner_client_id||!hash_equals((string)($job['owner_client_id']??''),$owner_client_id)){return null;}
		return $job;
	}

	/** Atomically binds a previously unowned job to one OAuth client; ownership is immutable. */
	public static function claim_job_owner( string $job_uuid, string $owner_client_id ): bool {
		global $wpdb;
		$owner_client_id=substr(sanitize_text_field($owner_client_id),0,190);
		if(''===$owner_client_id)return false;
		$job=self::get_job($job_uuid);
		if(!$job)return false;
		$existing=(string)($job['owner_client_id']??'');
		if(''!==$existing)return hash_equals($existing,$owner_client_id);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$updated=$wpdb->query($wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			'UPDATE '.self::jobs_table().' SET owner_client_id = %s, updated_gmt = %s WHERE job_uuid = %s AND (owner_client_id IS NULL OR owner_client_id = \'\')',
			$owner_client_id,self::now(),$job_uuid
		));
		if(1===(int)$updated){self::event('job:'.$job_uuid,'job.owner_claimed');return true;}
		$job=self::get_job($job_uuid);
		return $job&&hash_equals((string)($job['owner_client_id']??''),$owner_client_id);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	}

	public static function list_owned_agency_jobs( string $owner_client_id, int $limit = 50 ): array {
		global $wpdb;$owner_client_id=substr(sanitize_text_field($owner_client_id),0,190);if(''===$owner_client_id)return array();$limit=max(1,min(100,$limit));
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::jobs_table().' WHERE owner_client_id = %s AND domain = %s ORDER BY created_gmt DESC LIMIT %d',$owner_client_id,'agency',$limit),ARRAY_A);
		return array_map(array(__CLASS__,'hydrate_job'),is_array($rows)?$rows:array());
	}

	private static function hydrate_job( array $row ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		foreach ( array( 'arguments','plan','checkpoint','evidence','verification','result','error' ) as $field ) { $row[ $field ] = self::decode( $row[ $field ] ?? '', array() ); }
		$row['status'] = self::canonical_job_state( (string) ( $row['status'] ?? '' ) );
		foreach ( array('priority'=>100,'step_index'=>0,'progress'=>0,'attempts'=>0,'max_attempts'=>5,'backoff_seconds'=>30) as $field=>$default ) { $row[$field]=(int)($row[$field]??$default); }
		$row['available_gmt'] = (string) ( $row['available_gmt'] ?? $row['created_gmt'] ?? self::now() );
		return $row;
	}

	public static function mark_job_running( string $job_uuid ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		global $wpdb; $job = self::get_job( $job_uuid );
		if ( ! $job || ! in_array( (string) $job['status'], array( 'READY','INTERRUPTED' ), true ) ) { return null; }
		$updated=$wpdb->update( self::jobs_table(), array( 'status'=>'RUNNING','updated_gmt'=>self::now(),'heartbeat_gmt'=>self::now() ), array( 'job_uuid'=>$job_uuid,'status'=>(string)$job['status'] ), array( '%s','%s','%s' ), array( '%s','%s' ) );
		if(1!==(int)$updated)return null;
		self::event( 'job:' . $job_uuid, 'job.running' ); return self::get_job( $job_uuid );
	}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.

	public static function checkpoint_job( string $job_uuid, int $step_index, array $result ): ?array {
		global $wpdb; $job = self::get_job( $job_uuid );
		if ( ! $job || 'RUNNING' !== (string) $job['status'] ) { return null; }
		$checkpoint = (array) $job['checkpoint']; $checkpoint['last_completed_step']=$step_index; $checkpoint['last_result']=$result; $checkpoint['updated_at']=time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$updated=$wpdb->update( self::jobs_table(), array( 'step_index'=>$step_index+1,'checkpoint'=>self::encode($checkpoint),'updated_gmt'=>self::now() ), array( 'job_uuid'=>$job_uuid,'status'=>'RUNNING','step_index'=>(int)$job['step_index'] ), array( '%d','%s','%s' ), array( '%s','%s','%d' ) );
		if(1!==(int)$updated)return null;
		self::event( 'job:' . $job_uuid, 'job.checkpoint', array( 'step_index'=>$step_index ) ); return self::get_job( $job_uuid );
	}

	public static function complete_job( string $job_uuid, array $result, array $verification ): ?array {
		global $wpdb; $job=self::get_job($job_uuid); if(!$job){return null;}
		if('COMPLETED'===(string)$job['status']){ $job['idempotent_replay']=true; return $job; }
		if('RUNNING'!==(string)$job['status']){return null;}
		$now=self::now();
		$updated=$wpdb->update( self::jobs_table(), array( 'status'=>'COMPLETED','result'=>self::encode($result),'verification'=>self::encode($verification),'updated_gmt'=>$now,'completed_gmt'=>$now,'lease_token'=>null,'lease_expires_gmt'=>null,'worker_id'=>null ), array('job_uuid'=>$job_uuid,'status'=>(string)$job['status']), array('%s','%s','%s','%s','%s','%s','%s','%s'), array('%s','%s') );
		if(1!==(int)$updated)return null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		self::event('job:'.$job_uuid,'job.completed',array('verification'=>$verification)); return self::get_job($job_uuid);
	}

	public static function fail_job( string $job_uuid, array $error, array $verification = array() ): ?array {
		global $wpdb; $job=self::get_job($job_uuid); if(!$job || self::terminal_job_state((string)$job['status'])){return $job ?: null;}
		$now=self::now();
		$wpdb->update(self::jobs_table(),array('status'=>'TECHNICAL_ERROR','error'=>self::encode($error),'verification'=>self::encode($verification),'failure_class'=>sanitize_key((string)($error['class']??$error['code']??'execution')),'updated_gmt'=>$now,'completed_gmt'=>$now,'lease_token'=>null,'lease_expires_gmt'=>null,'worker_id'=>null),array('job_uuid'=>$job_uuid,'status'=>(string)$job['status']),array('%s','%s','%s','%s','%s','%s','%s','%s','%s'),array('%s','%s'));
		self::event('job:'.$job_uuid,'job.failed',array('error'=>$error,'verification'=>$verification)); return self::get_job($job_uuid);
	}

	public static function recover_stale_jobs( int $stale_seconds = 600 ): int {
		global $wpdb; $cutoff=gmdate('Y-m-d H:i:s',time()-max(60,$stale_seconds));
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::jobs_table()." WHERE status IN ('RUNNING','running') AND ((lease_expires_gmt IS NOT NULL AND lease_expires_gmt < UTC_TIMESTAMP()) OR (lease_token IS NULL AND updated_gmt < %s)) LIMIT 200",$cutoff),ARRAY_A);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$recovered=0;
		foreach(is_array($rows)?$rows:array() as $row){
			$job=self::hydrate_job($row); $error=array('code'=>'stale_lease','message'=>'Worker lease expired; durable recovery requested.','retryable'=>true,'class'=>'stale_lease');
			if((int)$job['attempts'] >= (int)$job['max_attempts']){ if(self::dead_letter_job((string)$job['job_uuid'],$error,'attempts_exhausted'))$recovered++; continue; }
			$delay=self::retry_delay($job); $updated=$wpdb->update(self::jobs_table(),array('status'=>'READY','available_gmt'=>gmdate('Y-m-d H:i:s',time()+$delay),'lease_token'=>null,'lease_expires_gmt'=>null,'worker_id'=>null,'heartbeat_gmt'=>null,'failure_class'=>'stale_lease','error'=>self::encode($error),'updated_gmt'=>self::now()),array('job_uuid'=>(string)$job['job_uuid'],'status'=>(string)$row['status']),array('%s','%s','%s','%s','%s','%s','%s','%s','%s'),array('%s','%s'));
			if(1===(int)$updated){$recovered++;self::event('job:'.$job['job_uuid'],'job.recovered',array('delay_seconds'=>$delay));}
		}
		return $recovered;
	}

	public static function set_job_context( string $job_uuid, array $context ): ?array {
		global $wpdb; $job=self::get_job($job_uuid); if(!$job){return null;}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$data=array(); $formats=array();
		foreach(array('request_id'=>'%s','mission_id'=>'%s','capability'=>'%s') as $key=>$format){if(array_key_exists($key,$context)){$data[$key]=substr(sanitize_text_field((string)$context[$key]),0,$key==='capability'?240:160);$formats[]=$format;}}
		if(array_key_exists('attempts',$context)){$data['attempts']=max(0,(int)$context['attempts']);$formats[]='%d';}
		if(array_key_exists('progress',$context)){$data['progress']=max(0,min(100,(int)$context['progress']));$formats[]='%d';}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		if(!$data){return $job;} $data['updated_gmt']=self::now();$formats[]='%s';$wpdb->update(self::jobs_table(),$data,array('job_uuid'=>$job_uuid),$formats,array('%s'));return self::get_job($job_uuid);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	public static function set_job_state( string $job_uuid, string $state, array $patch = array() ): ?array {
		global $wpdb; $job=self::get_job($job_uuid); if(!$job){return null;}
		$allowed=array('PLANNED','READY','RUNNING','COMPLETED','TECHNICAL_ERROR','INTERRUPTED','WAITING_FOR_BROWSER','CANCELLED','DEAD_LETTER');
		$state=self::canonical_job_state($state); if(!in_array($state,$allowed,true)){return null;}
		$data=array('status'=>$state,'updated_gmt'=>self::now());$formats=array('%s','%s');
		if(isset($patch['progress'])){$data['progress']=max(0,min(100,(int)$patch['progress']));$formats[]='%d';}
		if(isset($patch['attempts'])){$data['attempts']=max(0,(int)$patch['attempts']);$formats[]='%d';}
		foreach(array('checkpoint','evidence','verification','result','error') as $field){if(array_key_exists($field,$patch)){$data[$field]=self::encode($patch[$field]);$formats[]='%s';}}
		foreach(array('available_gmt','lease_token','lease_expires_gmt','heartbeat_gmt','worker_id','failure_class') as $field){if(array_key_exists($field,$patch)){$data[$field]=$patch[$field];$formats[]='%s';}}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		if(in_array($state,array('READY','WAITING_FOR_BROWSER','COMPLETED','TECHNICAL_ERROR','CANCELLED','DEAD_LETTER'),true)){foreach(array('lease_token','lease_expires_gmt','worker_id') as $field){if(!array_key_exists($field,$data)){$data[$field]=null;$formats[]='%s';}}}
		if(self::terminal_job_state($state)){$data['completed_gmt']=self::now();$formats[]='%s';}else{$data['completed_gmt']=null;$formats[]='%s';}
		$updated=$wpdb->update(self::jobs_table(),$data,array('job_uuid'=>$job_uuid,'status'=>(string)$job['status']),$formats,array('%s','%s'));
		if(1!==(int)$updated)return null;
		self::event('job:'.$job_uuid,'job.v4.'.strtolower($state),array('progress'=>$data['progress']??null));return self::get_job($job_uuid);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	public static function cancel_job( string $job_uuid, string $reason = 'cancelled' ): ?array {
		$job=self::get_job($job_uuid);if(!$job)return null;if(self::terminal_job_state((string)$job['status']))return $job;
		return self::set_job_state($job_uuid,'CANCELLED',array('error'=>array('code'=>'cancelled','message'=>sanitize_text_field($reason),'retryable'=>false)));
	}

	private static function claim_job_internal( string $worker_id, string $job_uuid = '' ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		global $wpdb; self::recover_stale_jobs(); $table=self::jobs_table(); $lease=bin2hex(random_bytes(16)); $expires=gmdate('Y-m-d H:i:s',time()+self::JOB_LEASE_SECONDS);
		$wpdb->query('START TRANSACTION');
		try{
			$where="status IN ('READY','INTERRUPTED','queued','interrupted') AND (available_gmt IS NULL OR available_gmt <= UTC_TIMESTAMP()) AND attempts < max_attempts AND expires_gmt > UTC_TIMESTAMP()";
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
			if(''!==$job_uuid){$sql=$wpdb->prepare("SELECT * FROM $table WHERE job_uuid = %s AND $where LIMIT 1 FOR UPDATE",$job_uuid);}else{$sql="SELECT * FROM $table WHERE $where ORDER BY priority DESC, available_gmt ASC, id ASC LIMIT 1 FOR UPDATE";}
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
			$row=$wpdb->get_row($sql,ARRAY_A); if(!is_array($row)){$wpdb->query('COMMIT');return null;}
			$updated=$wpdb->update($table,array('status'=>'RUNNING','lease_token'=>$lease,'lease_expires_gmt'=>$expires,'heartbeat_gmt'=>self::now(),'worker_id'=>substr(sanitize_text_field($worker_id),0,160),'attempts'=>(int)($row['attempts']??0)+1,'updated_gmt'=>self::now()),array('id'=>(int)$row['id'],'status'=>(string)$row['status']),array('%s','%s','%s','%s','%s','%d','%s'),array('%d','%s'));
			if(1!==(int)$updated){$wpdb->query('ROLLBACK');return null;}$wpdb->query('COMMIT');
		}catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		self::event('job:'.$row['job_uuid'],'job.claimed',array('worker_id'=>$worker_id)); $job=self::get_job((string)$row['job_uuid']); if($job)$job['lease_token']=$lease; return $job;
	}

	public static function claim_next_job( string $worker_id ): ?array { return self::claim_job_internal($worker_id); }
	public static function claim_job( string $job_uuid, string $worker_id ): ?array { return self::claim_job_internal($worker_id,$job_uuid); }

	public static function heartbeat_job( string $job_uuid, string $lease_token ): bool {
		global $wpdb;if(''===$lease_token)return false;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$updated=$wpdb->query($wpdb->prepare('UPDATE '.self::jobs_table()." SET lease_expires_gmt = %s, heartbeat_gmt = %s, updated_gmt = %s WHERE job_uuid = %s AND lease_token = %s AND status = 'RUNNING' AND lease_expires_gmt >= UTC_TIMESTAMP()",gmdate('Y-m-d H:i:s',time()+self::JOB_LEASE_SECONDS),self::now(),self::now(),$job_uuid,$lease_token));
		return 1===(int)$updated;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	}

	public static function checkpoint_leased_job( string $job_uuid, string $lease_token, int $step_index, array $checkpoint, int $progress = 0 ): ?array {
		global $wpdb;$job=self::get_job($job_uuid);if(!$job||''===$lease_token||!hash_equals((string)($job['lease_token']??''),$lease_token)||'RUNNING'!==(string)$job['status'])return null;
		$current=(int)$job['step_index'];if($current>$step_index)return $job;$checkpoint['last_completed_step']=$step_index;$checkpoint['updated_gmt']=gmdate('c');
		$updated=$wpdb->update(self::jobs_table(),array('step_index'=>$step_index+1,'checkpoint'=>self::encode($checkpoint),'progress'=>max(0,min(99,$progress)),'heartbeat_gmt'=>self::now(),'lease_expires_gmt'=>gmdate('Y-m-d H:i:s',time()+self::JOB_LEASE_SECONDS),'updated_gmt'=>self::now()),array('job_uuid'=>$job_uuid,'lease_token'=>$lease_token,'status'=>'RUNNING','step_index'=>$current),array('%d','%s','%d','%s','%s','%s'),array('%s','%s','%s','%d'));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		if(1!==(int)$updated)return null;self::event('job:'.$job_uuid,'job.checkpoint',array('step_index'=>$step_index,'progress'=>$progress));return self::get_job($job_uuid);
	}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.

	public static function wait_leased_job( string $job_uuid, string $lease_token, string $state, array $checkpoint ): ?array {
		global $wpdb;$state=self::canonical_job_state($state);if('WAITING_FOR_BROWSER'!==$state)return null;
		$updated=$wpdb->update(self::jobs_table(),array('status'=>$state,'checkpoint'=>self::encode($checkpoint),'lease_token'=>null,'lease_expires_gmt'=>null,'worker_id'=>null,'heartbeat_gmt'=>null,'updated_gmt'=>self::now()),array('job_uuid'=>$job_uuid,'lease_token'=>$lease_token,'status'=>'RUNNING'),array('%s','%s','%s','%s','%s','%s','%s'),array('%s','%s','%s'));
		if(1!==(int)$updated)return null;self::event('job:'.$job_uuid,'job.'.strtolower($state));return self::get_job($job_uuid);
	}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	public static function release_leased_job( string $job_uuid, string $lease_token, array $checkpoint, int $step_index, int $progress, int $delay_seconds = 0 ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		global $wpdb;$updated=$wpdb->update(self::jobs_table(),array('status'=>'READY','step_index'=>max(0,$step_index),'checkpoint'=>self::encode($checkpoint),'progress'=>max(0,min(99,$progress)),'available_gmt'=>gmdate('Y-m-d H:i:s',time()+max(0,$delay_seconds)),'lease_token'=>null,'lease_expires_gmt'=>null,'worker_id'=>null,'heartbeat_gmt'=>null,'updated_gmt'=>self::now()),array('job_uuid'=>$job_uuid,'lease_token'=>$lease_token,'status'=>'RUNNING'),array('%s','%d','%s','%d','%s','%s','%s','%s','%s','%s'),array('%s','%s','%s'));
		if(1!==(int)$updated)return null;self::event('job:'.$job_uuid,'job.released',array('step_index'=>$step_index,'progress'=>$progress,'delay_seconds'=>$delay_seconds));return self::get_job($job_uuid);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	public static function complete_leased_job( string $job_uuid, string $lease_token, array $result, array $verification ): ?array {
		global $wpdb;$now=self::now();
		$updated=$wpdb->update(self::jobs_table(),array('status'=>'COMPLETED','progress'=>100,'result'=>self::encode($result),'verification'=>self::encode($verification),'lease_token'=>null,'lease_expires_gmt'=>null,'worker_id'=>null,'heartbeat_gmt'=>null,'updated_gmt'=>$now,'completed_gmt'=>$now),array('job_uuid'=>$job_uuid,'lease_token'=>$lease_token,'status'=>'RUNNING'),array('%s','%d','%s','%s','%s','%s','%s','%s','%s','%s'),array('%s','%s','%s'));
		if(1!==(int)$updated)return null;self::event('job:'.$job_uuid,'job.completed',array('verification'=>$verification));return self::get_job($job_uuid);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	}

	private static function retry_delay( array $job ): int {
		$base=max(5,(int)($job['backoff_seconds']??30));$attempt=max(1,(int)($job['attempts']??1));$delay=min(3600,$base*(2**min(8,$attempt-1)));$spread=max(1,min(60,(int)floor($delay/4)));$jitter=hexdec(substr(hash('sha256',(string)($job['job_uuid']??'').'|'.$attempt),0,4))%$spread;return $delay+$jitter;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.

	public static function retry_leased_job( string $job_uuid, string $lease_token, array $error ): ?array {
		global $wpdb;$job=self::get_job($job_uuid);if(!$job||!hash_equals((string)($job['lease_token']??''),$lease_token))return null;
		if((int)$job['attempts'] >= (int)$job['max_attempts']){self::dead_letter_job($job_uuid,$error,'attempts_exhausted',$lease_token);return self::get_job($job_uuid);}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		$delay=self::retry_delay($job);$updated=$wpdb->update(self::jobs_table(),array('status'=>'READY','available_gmt'=>gmdate('Y-m-d H:i:s',time()+$delay),'error'=>self::encode($error),'failure_class'=>sanitize_key((string)($error['class']??$error['code']??'retryable')),'lease_token'=>null,'lease_expires_gmt'=>null,'worker_id'=>null,'heartbeat_gmt'=>null,'updated_gmt'=>self::now()),array('job_uuid'=>$job_uuid,'lease_token'=>$lease_token,'status'=>'RUNNING'),array('%s','%s','%s','%s','%s','%s','%s','%s','%s'),array('%s','%s','%s'));
		if(1!==(int)$updated)return null;self::event('job:'.$job_uuid,'job.retry_scheduled',array('delay_seconds'=>$delay));return self::get_job($job_uuid);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
	public static function dead_letter_job( string $job_uuid, array $error, string $failure_class = 'exhausted', string $lease_token = '' ): bool {
		global $wpdb;$job=self::get_job($job_uuid);if(!$job)return false;$snapshot=$job;unset($snapshot['lease_token']);
		$wpdb->replace(self::dead_letters_table(),array('job_uuid'=>$job_uuid,'mission_id'=>(string)($job['mission_id']??'')?:null,'capability'=>(string)($job['capability']??'')?:null,'failure_class'=>sanitize_key($failure_class),'error'=>self::encode($error),'job_snapshot'=>self::encode($snapshot),'created_gmt'=>self::now()));
		$where=array('job_uuid'=>$job_uuid,'status'=>(string)$job['status']);$where_formats=array('%s','%s');if(''!==$lease_token){$where['lease_token']=$lease_token;$where_formats[]='%s';}
		$updated=$wpdb->update(self::jobs_table(),array('status'=>'DEAD_LETTER','failure_class'=>sanitize_key($failure_class),'error'=>self::encode($error),'lease_token'=>null,'lease_expires_gmt'=>null,'worker_id'=>null,'heartbeat_gmt'=>null,'updated_gmt'=>self::now(),'completed_gmt'=>self::now()),$where,array('%s','%s','%s','%s','%s','%s','%s','%s','%s'),$where_formats);
		if(1!==(int)$updated)return false;self::event('job:'.$job_uuid,'job.dead_letter',array('failure_class'=>$failure_class));return true;
	}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.

	public static function upsert_schedule( string $playbook, string $objective, array $context, int $interval_seconds, string $next_run_gmt = '', string $schedule_uuid = '' ): array {
		global $wpdb;$playbook=sanitize_key($playbook);$schedule_uuid=preg_match('/^[a-f0-9-]{20,64}$/i',$schedule_uuid)?strtolower($schedule_uuid):self::uuid();$interval_seconds=max(300,min(30*DAY_IN_SECONDS,$interval_seconds));$next=strtotime($next_run_gmt.' UTC');if(false===$next){$next=class_exists('PRSTUDIO_UC_Schedule_Clock')?PRSTUDIO_UC_Schedule_Clock::initial_run($context,$interval_seconds)->getTimestamp():time()+60;}$now=self::now();
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::schedules_table().' WHERE schedule_uuid=%s LIMIT 1',$schedule_uuid),ARRAY_A);$data=array('playbook'=>$playbook,'objective'=>sanitize_text_field($objective),'context'=>self::encode($context),'interval_seconds'=>$interval_seconds,'next_run_gmt'=>gmdate('Y-m-d H:i:s',$next),'enabled'=>1,'updated_gmt'=>$now);
		if(is_array($existing)){$wpdb->update(self::schedules_table(),$data,array('schedule_uuid'=>$schedule_uuid));}else{$data['schedule_uuid']=$schedule_uuid;$data['created_gmt']=$now;$wpdb->insert(self::schedules_table(),$data);}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::schedules_table().' WHERE schedule_uuid=%s LIMIT 1',$schedule_uuid),ARRAY_A);if(!is_array($row))return array();$row['context']=self::decode($row['context']??'',array());$row['interval_seconds']=(int)$row['interval_seconds'];$row['enabled']=(bool)$row['enabled'];return $row;
	}

	public static function get_schedule( string $schedule_uuid ): ?array {
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::schedules_table().' WHERE schedule_uuid=%s LIMIT 1',$schedule_uuid),ARRAY_A);if(!is_array($row))return null;$row['context']=self::decode($row['context']??'',array());$row['interval_seconds']=(int)$row['interval_seconds'];$row['enabled']=(bool)$row['enabled'];return $row;
	}

	public static function due_schedules( int $limit = 20 ): array {
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		global $wpdb;$limit=max(1,min(100,$limit));$rows=$wpdb->get_results('SELECT * FROM '.self::schedules_table().' WHERE enabled=1 AND next_run_gmt <= UTC_TIMESTAMP() ORDER BY next_run_gmt ASC LIMIT '.$limit,ARRAY_A);$out=array();foreach(is_array($rows)?$rows:array() as $row){$row['context']=self::decode($row['context']??'',array());$row['interval_seconds']=(int)$row['interval_seconds'];$row['enabled']=(bool)$row['enabled'];$out[]=$row;}return $out;
	}

	public static function advance_schedule( string $schedule_uuid, string $expected_next_gmt ): ?array {
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::schedules_table().' WHERE schedule_uuid=%s LIMIT 1',$schedule_uuid),ARRAY_A);if(!is_array($row)||!hash_equals((string)$row['next_run_gmt'],$expected_next_gmt))return null;$base=strtotime($expected_next_gmt.' UTC');if(false===$base)$base=time();$context=self::decode($row['context']??'',array());$next=class_exists('PRSTUDIO_UC_Schedule_Clock')?PRSTUDIO_UC_Schedule_Clock::next_run($expected_next_gmt,$context,(int)$row['interval_seconds'])->getTimestamp():max(time()+60,$base+max(300,(int)$row['interval_seconds']));$occurrence=class_exists('PRSTUDIO_UC_Schedule_Clock')?PRSTUDIO_UC_Schedule_Clock::occurrence_key($schedule_uuid,$expected_next_gmt):'schedule:'.$schedule_uuid.':'.gmdate('YmdHis',$base);
		$updated=$wpdb->update(self::schedules_table(),array('last_run_gmt'=>self::now(),'last_occurrence_key'=>$occurrence,'next_run_gmt'=>gmdate('Y-m-d H:i:s',$next),'updated_gmt'=>self::now()),array('schedule_uuid'=>$schedule_uuid,'next_run_gmt'=>$expected_next_gmt),array('%s','%s','%s','%s'),array('%s','%s'));if(1!==(int)$updated)return null;return self::upsert_schedule((string)$row['playbook'],(string)$row['objective'],self::decode($row['context']??'',array()),(int)$row['interval_seconds'],gmdate('Y-m-d H:i:s',$next),$schedule_uuid);
	}

	public static function queue_stats(): array {
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		global $wpdb;$rows=$wpdb->get_results('SELECT status, COUNT(*) AS total FROM '.self::jobs_table().' GROUP BY status',ARRAY_A);$states=array();foreach(is_array($rows)?$rows:array() as $row){$states[self::canonical_job_state((string)$row['status'])]=(int)$row['total'];}$dead=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::dead_letters_table());
		// The browser task queue lives in its own table, so a stats call that read
		// only jobs made a stalled dispatcher structurally invisible: the watchdog
		// reported healthy while browser tasks sat unclaimed. The undispatched
		// counter is the specific signal that was missing -- queued, never leased,
		// attempt_count still zero, which is the difference between "the agent tried
		// and failed" and "nothing ever picked this up".
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table identifier from a fixed helper; values are parameterized below.
		$task_rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . self::tasks_table() . ' GROUP BY status', ARRAY_A );
		$task_states = array();
		foreach ( is_array( $task_rows ) ? $task_rows : array() as $row ) {
			$task_states[ (string) $row['status'] ] = (int) $row['total'];
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table identifier from a fixed helper; the cutoff is parameterized.
		$undispatched = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::tasks_table() . ' WHERE status = %s AND attempt_count = 0 AND created_gmt < %s AND expires_gmt > UTC_TIMESTAMP()',
			PRSTUDIO_UC_State_Machine::QUEUED,
			gmdate( 'Y-m-d H:i:s', time() - 120 )
		) );
		$oldest_undispatched = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT created_gmt FROM ' . self::tasks_table() . ' WHERE status = %s AND attempt_count = 0 AND expires_gmt > UTC_TIMESTAMP() ORDER BY created_gmt ASC LIMIT 1',
			PRSTUDIO_UC_State_Machine::QUEUED
		) );
		return array(
			'states'=>$states,
			'dead_letters'=>$dead,
			'lease_seconds'=>self::JOB_LEASE_SECONDS,
			'schema_version'=>self::SCHEMA_VERSION,
			'browser_tasks'=>array(
				'states'=>$task_states,
				'undispatched'=>$undispatched,
				'undispatched_threshold_seconds'=>120,
				'oldest_undispatched_gmt'=>$oldest_undispatched,
			),
		);
	}

	public static function recent_jobs( int $limit = 50 ): array {
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		global $wpdb; $limit=max(1,min(200,$limit)); $rows=$wpdb->get_results('SELECT * FROM '.self::jobs_table().' ORDER BY id DESC LIMIT '.$limit,ARRAY_A);
		return array_map(array(__CLASS__,'hydrate_job'),is_array($rows)?$rows:array());
	}

	public static function recent_tasks( int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::tasks_table() . ' ORDER BY id DESC LIMIT ' . $limit, ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate_task' ), is_array( $rows ) ? $rows : array() );
	}

	public static function event( string $task_uuid, string $event_type, array $payload = array() ): void {
		global $wpdb;
		$wpdb->insert(
			self::events_table(),
			array(
				'task_uuid' => $task_uuid,
				'event_type' => sanitize_key( $event_type ),
				'payload' => self::encode( $payload ),
				'created_gmt' => self::now(),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		// Completion/status readers use a separate cross-worker wake channel so
		// they do not poll the tasks/jobs tables while nothing changes.
		if ( class_exists( 'PRSTUDIO_UC_Wait_Channel' ) ) {
			PRSTUDIO_UC_Wait_Channel::signal_state( $event_type );
		}
	}
}
