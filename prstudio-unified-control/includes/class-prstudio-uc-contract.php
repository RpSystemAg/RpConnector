<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical capability contract shared by connector, WordPress and Chrome.
 * The JSON is generated once at build time and indexed once per PHP request.
 */
final class PRSTUDIO_UC_Contract {
	private static ?array $data = null;
	private static ?array $tool_index = null;
	private static ?array $action_index = null;
	private static ?array $domain_index = null;

	/** Additive Browser operations. The immutable compatibility catalog remains intact. */
	private static function overlay_actions(): array {
		return array_values( (array) ( self::browser_action_overlay()['actions'] ?? array() ) );
	}

	private static function normalize_action_meta( array $meta ): array {
		$key = '/' . trim( (string) ( $meta['route'] ?? '' ), '/' ) . '|' . sanitize_key( (string) ( $meta['action'] ?? '' ) );
		if ( in_array( $key, array( '/commerce-settings-manage|get_settings', '/settings-manage|get_settings' ), true ) ) {
			$meta['read_only'] = true;
			$meta['destructive'] = false;
			$meta['idempotent'] = true;
		}
		return $meta;
	}

	private static function actions(): array {
		$actions = array_merge( (array) ( self::data()['actions'] ?? array() ), self::overlay_actions() );
		return array_values( array_map( static fn( $meta ) => is_array( $meta ) ? self::normalize_action_meta( $meta ) : array(), $actions ) );
	}
	private static ?array $browser_overlay = null;

	public static function data(): array {
		if ( null !== self::$data ) { return self::$data; }
		$path = trailingslashit( PRSTUDIO_UC_DIR ) . 'contract/capability-contract.json';
		$decoded = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		self::$data = is_array( $decoded ) ? $decoded : array();
		return self::$data;
	}

	/** Additive actions intentionally live outside the immutable 5.x contract hash. */
	public static function browser_action_overlay(): array {
		if ( null !== self::$browser_overlay ) { return self::$browser_overlay; }
		$path = trailingslashit( PRSTUDIO_UC_DIR ) . 'contract/browser-action-overlay.json';
		$decoded = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		self::$browser_overlay = is_array( $decoded ) ? $decoded : array( 'actions'=>array() );
		return self::$browser_overlay;
	}

	private static function build_indexes(): void {
		if ( null !== self::$tool_index ) { return; }
		self::$tool_index = array();
		self::$action_index = array();
		self::$domain_index = array();
		$data = self::data();
		foreach ( self::actions() as $meta ) {
			if ( ! is_array( $meta ) ) { continue; }
			$tool = (string) ( $meta['tool_name'] ?? '' );
			$route = '/' . trim( (string) ( $meta['route'] ?? '' ), '/' );
			$action = sanitize_key( (string) ( $meta['action'] ?? '' ) );
			$domain = sanitize_key( (string) ( $meta['domain'] ?? 'operations' ) );
			if ( '' !== $tool ) { self::$tool_index[ $tool ] = $meta; }
			if ( '/' !== $route && '' !== $action ) { self::$action_index[ $route . '|' . $action ] = $meta; }
			self::$domain_index[ $domain ][] = $meta;
		}
		foreach ( (array) ( $data['direct_tools'] ?? array() ) as $meta ) {
			if ( ! is_array( $meta ) ) { continue; }
			$tool = (string) ( $meta['tool_name'] ?? '' );
			if ( '' !== $tool && ! isset( self::$tool_index[ $tool ] ) ) { self::$tool_index[ $tool ] = $meta; }
		}
	}

	public static function suite_version(): string { return (string) ( self::data()['suite_version'] ?? self::data()['contract_version'] ?? '' ); }
	public static function protocol_version(): string { return (string) ( self::data()['protocol_version'] ?? '' ); }
	public static function catalog_protocol_version(): string { return (string) ( self::data()['catalog_protocol_version'] ?? self::suite_version() ); }
	public static function compatible_protocol_versions(): array {
		$versions = array_values( array_filter( array_map( 'strval', (array) ( self::data()['compatible_protocol_versions'] ?? array( self::protocol_version() ) ) ) ) );
		return $versions ?: array( self::protocol_version() );
	}
	public static function hash(): string {
		$base = (string) ( self::data()['contract_hash'] ?? '' );
		return hash( 'sha256', $base . '|' . wp_json_encode( self::overlay_actions(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
	public static function action_count(): int { return count( self::actions() ); }
	public static function domain_count(): int { return count( (array) ( self::data()['domains'] ?? array() ) ); }
	public static function direct_tool_count(): int { return count( (array) ( self::data()['direct_tools'] ?? array() ) ); }
	public static function callable_tool_count(): int { return self::action_count() + self::direct_tool_count(); }
	public static function browser_overlay_actions(): array { return array_values( (array) ( self::browser_action_overlay()['actions'] ?? array() ) ); }
	public static function browser_overlay_hash(): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( self::browser_action_overlay() ) : json_encode( self::browser_action_overlay() );
		return hash( 'sha256', (string) $json );
	}

	public static function by_tool( string $tool_name ): ?array {
		self::build_indexes();
		return is_array( self::$tool_index[ $tool_name ] ?? null ) ? self::$tool_index[ $tool_name ] : null;
	}

	public static function by_action( string $route, string $action ): ?array {
		self::build_indexes();
		$key = '/' . trim( $route, '/' ) . '|' . sanitize_key( $action );
		return is_array( self::$action_index[ $key ] ?? null ) ? self::$action_index[ $key ] : null;
	}

	public static function domain_actions( string $domain ): array {
		self::build_indexes();
		return array_values( (array) ( self::$domain_index[ sanitize_key( $domain ) ] ?? array() ) );
	}

	public static function domain_for_route( string $route ): string {
		$route = '/' . trim( $route, '/' );
		foreach ( (array) ( self::data()['domains'] ?? array() ) as $domain ) {
			if ( in_array( $route, (array) ( $domain['routes'] ?? array() ), true ) ) { return sanitize_key( (string) ( $domain['id'] ?? 'operations' ) ); }
		}
		return 'operations';
	}

	public static function status(): array {
		$data = self::data();
		return array(
			'available' => ! empty( $data ),
			'suite_version' => self::suite_version(),
			'protocol_version' => self::protocol_version(),
			'catalog_protocol_version' => self::catalog_protocol_version(),
			'compatible_protocol_versions' => self::compatible_protocol_versions(),
			'contract_hash' => self::hash(),
			'action_count' => self::action_count(),
			'domain_count' => self::domain_count(),
			'direct_tool_count' => self::direct_tool_count(),
			'callable_tool_count' => self::callable_tool_count(),
			'browser_overlay_action_count' => count( self::browser_overlay_actions() ),
			'browser_overlay_hash' => self::browser_overlay_hash(),
			'catalog_profiles' => (array) ( $data['catalog_profiles'] ?? array() ),
			'rules' => (array) ( $data['rules'] ?? array() ),
		);
	}

	public static function extension_compatibility( array $capabilities ): array {
		$remote_hash = sanitize_text_field( (string) ( $capabilities['contractHash'] ?? $capabilities['contract_hash'] ?? '' ) );
		$remote_protocol = sanitize_text_field( (string) ( $capabilities['protocolVersion'] ?? $capabilities['protocol_version'] ?? $capabilities['version'] ?? '' ) );
		$remote_actions = (int) ( $capabilities['contractActionCount'] ?? $capabilities['contract_action_count'] ?? 0 );
		$remote_direct = (int) ( $capabilities['contractDirectToolCount'] ?? $capabilities['contract_direct_tool_count'] ?? 0 );
		$remote_callable = (int) ( $capabilities['contractCallableToolCount'] ?? $capabilities['contract_callable_tool_count'] ?? 0 );
		$hash_ok = '' !== $remote_hash && hash_equals( self::hash(), $remote_hash );
		$protocol_ok = '' !== $remote_protocol && in_array( $remote_protocol, self::compatible_protocol_versions(), true );
		$count_ok = self::action_count() === $remote_actions && self::direct_tool_count() === $remote_direct && self::callable_tool_count() === $remote_callable;
		return array(
			'compatible' => $hash_ok && $protocol_ok && $count_ok,
			'hash_match' => $hash_ok,
			'protocol_match' => $protocol_ok,
			'count_match' => $count_ok,
			'expected_hash' => self::hash(),
			'received_hash' => $remote_hash,
			'expected_protocol' => self::protocol_version(),
			'accepted_protocols' => self::compatible_protocol_versions(),
			'received_protocol' => $remote_protocol,
			'expected_actions' => self::action_count(),
			'received_actions' => $remote_actions,
			'expected_direct_tools' => self::direct_tool_count(),
			'received_direct_tools' => $remote_direct,
			'expected_callable_tools' => self::callable_tool_count(),
			'received_callable_tools' => $remote_callable,
		);
	}
}
