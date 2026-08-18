<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Negotiates the MCP catalog presentation without changing the callable surface.
 *
 * legacy_full_catalog preserves the 0.3.3 tools/list behaviour.
 * compact_dynamic_catalog exposes at most 48 canonical schemas while every
 * registered tool remains directly callable through tools/call.
 */
final class PRSTUDIO_UC_Catalog_Profile {
	public const LEGACY = 'legacy_full_catalog';
	public const COMPACT = 'compact_dynamic_catalog';
	public const COMPACT_MAX_TOOLS = 48;
	public const SESSION_TTL = 43200;

	public const DISCOVERY_TOOLS = array(
		'prstudio_orchestrator_resolve',
		'prstudio_orchestrator_domain_actions',
		'prstudio_orchestrator_execute',
		'prstudio_work_begin',
		'prstudio_work_status',
		'prstudio_anti_crash_requirements',
		'prstudio_anti_crash_run',
		'prstudio_anti_crash_submit',
		'prstudio_work_finalize',
		'prstudio_work_abort',
		'rpconnector_capability_search',
		'rpconnector_route_index',
		'rpconnector_action_call',
	);

	private const COMPACT_CORE_TOOLS = array(
		'bridge_status',
		'verify_private_wordpress_access',
		'enterprise_status',
		'get_audit_log',
	);

	private static function normalize_profile( string $profile ): string {
		return self::COMPACT === $profile ? self::COMPACT : self::LEGACY;
	}

	private static function capability_value( array $capabilities, array $path ) {
		$value = $capabilities;
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) { return null; }
			$value = $value[ $key ];
		}
		return $value;
	}

	private static function requested_profiles( array $capabilities ): array {
		$candidates = array(
			self::capability_value( $capabilities, array( 'experimental', 'prstudio', 'catalogProfiles' ) ),
			self::capability_value( $capabilities, array( 'experimental', 'prstudio', 'catalog_profiles' ) ),
			self::capability_value( $capabilities, array( 'prstudio', 'catalogProfiles' ) ),
			self::capability_value( $capabilities, array( 'prstudio', 'catalog_profiles' ) ),
			self::capability_value( $capabilities, array( 'tools', 'catalogProfiles' ) ),
		);
		$profiles = array();
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) ) { $candidate = array( $candidate ); }
			if ( ! is_array( $candidate ) ) { continue; }
			foreach ( $candidate as $profile ) {
				$profile = sanitize_key( (string) $profile );
				if ( in_array( $profile, array( self::LEGACY, self::COMPACT ), true ) ) { $profiles[] = $profile; }
			}
		}
		return array_values( array_unique( $profiles ) );
	}

	private static function explicit_profile( array $capabilities ): string {
		$candidates = array(
			self::capability_value( $capabilities, array( 'experimental', 'prstudio', 'catalogProfile' ) ),
			self::capability_value( $capabilities, array( 'experimental', 'prstudio', 'catalog_profile' ) ),
			self::capability_value( $capabilities, array( 'prstudio', 'catalogProfile' ) ),
			self::capability_value( $capabilities, array( 'prstudio', 'catalog_profile' ) ),
		);
		foreach ( $candidates as $candidate ) {
			$profile = sanitize_key( is_scalar( $candidate ) ? (string) $candidate : '' );
			if ( in_array( $profile, array( self::LEGACY, self::COMPACT ), true ) ) { return $profile; }
		}
		return '';
	}

	private static function protocol_signals_compact( string $protocol ): bool {
		/* Catalog presentation must be reproducible even if an intermediary drops
		 * Mcp-Session-Id during an actions refresh. Newer protocol generations use
		 * the compact catalog; older ones preserve the 0.3.3 legacy snapshot. */
		return in_array( $protocol, array( '2026-07-28', '2025-11-25' ), true );
	}

	private static function version_signals_compact( string $protocol, array $client_info ): bool {
		$client_version = sanitize_text_field( (string) ( $client_info['version'] ?? '' ) );
		if ( self::protocol_signals_compact( $protocol ) ) { return true; }
		/* Explicit PR STUDIO semantic versions remain compatible with the compact
		 * profile, but ChatGPT on older MCP versions stays on the legacy snapshot. */
		$client_name = strtolower( sanitize_text_field( (string) ( $client_info['name'] ?? '' ) ) );
		if ( str_contains( $client_name, 'chatgpt' ) || str_contains( $client_name, 'openai' ) ) { return false; }
		return preg_match( '/^\d+\.\d+\.\d+/', $client_version ) && version_compare( $client_version, '0.3.4', '>=' );
	}

	public static function negotiate( string $protocol, array $capabilities, array $client_info ): array {
		$explicit = self::explicit_profile( $capabilities );
		$supported = self::requested_profiles( $capabilities );
		if ( self::LEGACY === $explicit ) {
			$profile = self::LEGACY;
			$reason = 'client_explicit_legacy';
		} elseif ( self::COMPACT === $explicit ) {
			$profile = self::COMPACT;
			$reason = 'client_explicit_compact';
		} elseif ( in_array( self::COMPACT, $supported, true ) && self::version_signals_compact( $protocol, $client_info ) ) {
			$profile = self::COMPACT;
			$reason = 'capability_and_version_match';
		} elseif ( self::version_signals_compact( $protocol, $client_info ) ) {
			$profile = self::COMPACT;
			$reason = 'protocol_version_negotiation';
		} else {
			$profile = self::LEGACY;
			$reason = 'backward_compatible_default';
		}
		return array(
			'profile' => $profile,
			'reason' => $reason,
			'available_profiles' => array( self::LEGACY, self::COMPACT ),
			'compact_max_tools' => self::COMPACT_MAX_TOOLS,
			'client' => array(
				'name' => sanitize_text_field( (string) ( $client_info['name'] ?? '' ) ),
				'version' => sanitize_text_field( (string) ( $client_info['version'] ?? '' ) ),
			),
		);
	}

	private static function transient_key( string $session_id ): string {
		return 'prstudio_uc_mcp_profile_' . hash( 'sha256', $session_id );
	}

	public static function create_session( array $selection ): string {
		$session_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$record = array(
			'profile' => self::normalize_profile( (string) ( $selection['profile'] ?? self::LEGACY ) ),
			'reason' => sanitize_key( (string) ( $selection['reason'] ?? '' ) ),
			'client' => is_array( $selection['client'] ?? null ) ? $selection['client'] : array(),
			'created_gmt' => gmdate( 'c' ),
		);
		set_transient( self::transient_key( $session_id ), $record, self::SESSION_TTL );
		return $session_id;
	}

	public static function session_id_from_request(): string {
		$session_id = sanitize_text_field( (string) ( $_SERVER['HTTP_MCP_SESSION_ID'] ?? '' ) );
		return preg_match( '/^[A-Za-z0-9._:-]{8,160}$/', $session_id ) ? $session_id : '';
	}

	public static function profile_for_request( array $params = array() ): array {
		$session_id = self::session_id_from_request();
		if ( '' !== $session_id ) {
			$record = get_transient( self::transient_key( $session_id ) );
			if ( is_array( $record ) ) {
				return array(
					'profile' => self::normalize_profile( (string) ( $record['profile'] ?? self::LEGACY ) ),
					'reason' => (string) ( $record['reason'] ?? 'session' ),
					'session_id' => $session_id,
				);
			}
		}
		$meta = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array();
		$requested = sanitize_key( (string) ( $meta['prstudioCatalogProfile'] ?? $meta['prstudio_catalog_profile'] ?? '' ) );
		if ( in_array( $requested, array( self::LEGACY, self::COMPACT ), true ) ) {
			return array( 'profile' => $requested, 'reason' => 'request_metadata', 'session_id' => '' );
		}
		$protocol = sanitize_text_field( (string) ( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '' ) );
		if ( self::protocol_signals_compact( $protocol ) ) {
			return array( 'profile' => self::COMPACT, 'reason' => 'stateless_protocol_deterministic', 'session_id' => '' );
		}
		return array( 'profile' => self::LEGACY, 'reason' => 'stateless_backward_compatible_default', 'session_id' => '' );
	}

	private static function route_tool_names(): array {
		$names = array();
		if ( class_exists( 'PRSTUDIO_UC_Contract' ) ) {
			foreach ( (array) ( PRSTUDIO_UC_Contract::data()['domains'] ?? array() ) as $domain ) {
				foreach ( (array) ( $domain['routes'] ?? array() ) as $route ) {
					$slug = str_replace( '-', '_', trim( (string) $route, '/' ) );
					if ( '' !== $slug ) { $names[] = 'rpconnector_' . sanitize_key( $slug ); }
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	public static function compact_tool_names(): array {
		$names = array_values( array_unique( array_merge( self::DISCOVERY_TOOLS, self::route_tool_names(), self::COMPACT_CORE_TOOLS ) ) );
		if ( count( $names ) > self::COMPACT_MAX_TOOLS ) {
			throw new RuntimeException( 'Il catalogo compatto supera il limite di 48 tool.' );
		}
		return $names;
	}

	public static function select_compact_tools( array $tool_map ): array {
		$tools = array();
		foreach ( self::compact_tool_names() as $name ) {
			if ( isset( $tool_map[ $name ] ) && is_array( $tool_map[ $name ] ) ) { $tools[] = $tool_map[ $name ]; }
		}
		return $tools;
	}

	public static function status( string $profile = '' ): array {
		$profile = self::normalize_profile( $profile );
		return array(
			'active_profile' => $profile,
			'available_profiles' => array( self::LEGACY, self::COMPACT ),
			'compact_max_tools' => self::COMPACT_MAX_TOOLS,
			'compact_canonical_tools' => count( self::compact_tool_names() ),
			'callable_tools_unchanged' => class_exists( 'PRSTUDIO_UC_Contract' ) ? PRSTUDIO_UC_Contract::callable_tool_count() : 0,
			'direct_invocation_preserved' => true,
		);
	}
}
