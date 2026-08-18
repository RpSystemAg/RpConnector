<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPAIB_Audit {
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpaib_audit_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			action_name varchar(191) NOT NULL,
			status varchar(32) NOT NULL,
			target text NULL,
			details longtext NULL,
			token_id varchar(64) NULL,
			ip_address varchar(64) NULL,
			PRIMARY KEY  (id),
			KEY action_name (action_name),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );
	}

	private static function redact( $value, string $key = '' ) {
		if ( preg_match( '/password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|authorization|cookie|iban|card/i', $key ) ) {
			return '[REDACTED]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) { $out[ $k ] = self::redact( $v, (string) $k ); }
			return $out;
		}
		if ( is_object( $value ) ) { return self::redact( get_object_vars( $value ), $key ); }
		if ( is_string( $value ) && strlen( $value ) > 20000 ) { return substr( $value, 0, 20000 ) . '…'; }
		return $value;
	}

	public static function log_fast( string $action, string $status, string $target = '', array $details = array(), string $token_id = '' ): void {
		global $wpdb;
		if ( '' === $token_id && class_exists( 'WPAIB_Auth', false ) ) { $token_id = WPAIB_Auth::current_token_id(); }
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'action_name' => substr( sanitize_text_field( $action ), 0, 191 ),
				'status' => substr( sanitize_key( $status ), 0, 32 ),
				'target' => sanitize_text_field( $target ),
				'details' => wp_json_encode( self::redact( $details ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'token_id' => substr( sanitize_text_field( $token_id ), 0, 64 ),
				'ip_address' => substr( $ip, 0, 64 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function log( string $action, string $status, string $target = '', array $details = array(), string $token_id = '' ): void {
		global $wpdb;
		if ( '' === $token_id && class_exists( 'WPAIB_Auth' ) ) { $token_id = WPAIB_Auth::current_token_id(); }
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'action_name' => substr( sanitize_text_field( $action ), 0, 191 ),
				'status' => substr( sanitize_key( $status ), 0, 32 ),
				'target' => sanitize_text_field( $target ),
				'details' => wp_json_encode( self::redact( $details ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'token_id' => substr( sanitize_text_field( $token_id ), 0, 64 ),
				'ip_address' => substr( $ip, 0, 64 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( class_exists( 'PRSTUDIO_UC_Log_Orchestrator' ) ) {
			PRSTUDIO_UC_Log_Orchestrator::log( 'connector', $action, array( 'status'=>$status, 'target'=>$target, 'details'=>$details ), 'error' === $status ? 'error' : 'info', __FILE__ );
		}
	}

	public static function purge_older_than( string $gmt ): int {
		global $wpdb;
		$timestamp = strtotime( $gmt );
		if ( ! $timestamp ) { return 0; }
		$cutoff = gmdate( 'Y-m-d H:i:s', $timestamp );
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s', $cutoff ) );
		return false === $result ? 0 : (int) $result;
	}

	public static function recent( int $limit = 50, string $prefix = '' ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$table = self::table_name();
		if ( '' !== $prefix ) {
			$sql = $wpdb->prepare( "SELECT id,created_at,action_name,status,target,details FROM {$table} WHERE action_name LIKE %s ORDER BY id DESC LIMIT %d", $wpdb->esc_like( $prefix ) . '%', $limit );
		} else {
			$sql = $wpdb->prepare( "SELECT id,created_at,action_name,status,target,details FROM {$table} ORDER BY id DESC LIMIT %d", $limit );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		foreach ( $rows as &$row ) { $row['details'] = json_decode( (string) $row['details'], true ); }
		$rows = is_array( $rows ) ? $rows : array();
		return array( 'items' => $rows, 'count' => count( $rows ) );
	}
}
