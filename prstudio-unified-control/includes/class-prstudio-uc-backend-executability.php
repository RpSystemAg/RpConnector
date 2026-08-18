<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/* PR STUDIO ONE-GUARD INVARIANT: Anti-Crash is the only mutation guard. Verification/risk/telemetry never block an executable action. */

/**
 * Technical executability resolver for every catalogued control action.
 *
 * A catalog declaration is metadata, not proof of executability. An action is
 * advertised as executable only when this class can prove one of these owners:
 * a concrete WordPress route implementation, the database backend, Web Stories,
 * the Browser Agent contract, or an adapter hook that is actually registered at
 * runtime. Explicit payload plans remain callable through the enterprise gateway,
 * but they do not make a bare catalog action executable by themselves.
 */
final class PRSTUDIO_UC_Backend_Executability {
	public const VERSION = '1.2.0';
	private static ?array $surface = null;

	private static function surface_path(): string {
		$base = defined( 'WPAIB_DIR' ) ? WPAIB_DIR : trailingslashit( dirname( __DIR__ ) );
		return trailingslashit( $base ) . 'contract/concrete-execution-surface.json';
	}

	private static function surface_document(): array {
		if ( is_array( self::$surface ) ) { return self::$surface; }
		$path = self::surface_path();
		$raw = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		$decoded = '' !== $raw ? json_decode( $raw, true ) : array();
		self::$surface = is_array( $decoded ) ? $decoded : array( 'actions' => array(), 'status_counts' => array() );
		return self::$surface;
	}

	private static function surface_entry( string $route, string $action ): array {
		$route = '/' . trim( $route, '/' );
		$key = $route . '::' . sanitize_key( $action );
		$entry = self::surface_document()['actions'][ $key ] ?? null;
		return is_array( $entry ) ? $entry : array( 'route' => $route, 'action' => sanitize_key( $action ), 'status' => 'surface_missing', 'resolver' => '', 'reason' => 'No audited execution-surface entry exists.' );
	}

	private static function adapter_registered( array $meta ): bool {
		$hook = trim( (string) ( $meta['adapter_hook'] ?? '' ) );
		return '' !== $hook && function_exists( 'has_filter' ) && false !== has_filter( $hook );
	}

	public static function provider_for( string $route, string $action, array $meta = array() ): string {
		$entry = self::surface_entry( $route, $action );
		$status = (string) ( $entry['status'] ?? 'surface_missing' );
		if ( self::adapter_registered( $meta ) ) { return 'registered_adapter'; }
		if ( class_exists( 'PRSTUDIO_UC_Complete_Action_Executor' ) && PRSTUDIO_UC_Complete_Action_Executor::supports( $route, $action ) ) { return 'prstudio_complete_native'; }
		return match ( $status ) {
			'browser_agent' => 'browser_agent',
			'complete_native' => 'prstudio_complete_native',
			'database_native' => 'database_native',
			'web_stories_native' => 'web_stories_native',
			'wordpress_native' => 'wordpress_native',
			'catalog_only' => 'catalog_metadata_only',
			default => 'surface_unverified',
		};
	}

	/**
	 * Return an audited concrete binding. Generic dispatcher existence alone is
	 * deliberately insufficient: the generated surface must prove the action.
	 */
	public static function binding_for( string $route, string $action, array $meta = array() ): array {
		$route = '/' . trim( $route, '/' );
		$action = sanitize_key( $action );
		$entry = self::surface_entry( $route, $action );
		$status = (string) ( $entry['status'] ?? 'surface_missing' );
		$binding = array(
			'registered' => false,
			'implemented' => false,
			'metadata_only' => false,
			'provider' => self::provider_for( $route, $action, $meta ),
			'route' => $route,
			'action' => $action,
			'surface_status' => $status,
			'resolver' => (string) ( $entry['resolver'] ?? '' ),
			'reason' => (string) ( $entry['reason'] ?? '' ),
			'auto_hydration' => true,
			'client_continuation' => false,
			'adapter_hook' => (string) ( $meta['adapter_hook'] ?? '' ),
			'adapter_hook_registered' => self::adapter_registered( $meta ),
		);

		if ( $binding['adapter_hook_registered'] ) {
			$binding['registered'] = true;
			$binding['implemented'] = true;
			$binding['provider'] = 'registered_adapter';
			$binding['resolver'] = 'filter:' . $binding['adapter_hook'];
			$binding['reason'] = 'A concrete runtime adapter is registered for the catalog action.';
			return $binding;
		}

		if ( class_exists( 'PRSTUDIO_UC_Complete_Action_Executor' ) && PRSTUDIO_UC_Complete_Action_Executor::supports( $route, $action ) ) {
			$binding['registered'] = true;
			$binding['implemented'] = true;
			$binding['metadata_only'] = false;
			$binding['provider'] = 'prstudio_complete_native';
			$binding['resolver'] = 'PRSTUDIO_UC_Complete_Action_Executor::execute';
			$binding['reason'] = 'Concrete bounded 1.0.0 executor with explicit route/action semantics.';
			return $binding;
		}

		if ( 'catalog_only' === $status || 'surface_missing' === $status ) {
			$binding['metadata_only'] = true;
			$binding['provider'] = 'catalog_metadata_only';
			$binding['resolver'] = '';
			$binding['reason'] = 'Definition retained for compatibility/discovery metadata, but no concrete executor is proven.';
			return $binding;
		}

		switch ( $status ) {
			case 'browser_agent':
				$binding['registered'] = class_exists( 'PRSTUDIO_UC_Bridge' ) && is_callable( array( 'PRSTUDIO_UC_Bridge', 'dispatch' ) );
				$binding['implemented'] = $binding['registered'];
				$binding['resolver'] = 'PRSTUDIO_UC_Bridge::dispatch';
				$binding['runtime_registry'] = 'browser_agent_contract_plus_runtime_capabilities';
				break;
			case 'database_native':
				$actions = class_exists( 'PRSTUDIO_UC_Database_Backend' ) ? PRSTUDIO_UC_Database_Backend::actions() : array();
				$binding['registered'] = in_array( $action, $actions, true ) && is_callable( array( 'PRSTUDIO_UC_Database_Backend', 'execute' ) );
				$binding['implemented'] = $binding['registered'];
				$binding['resolver'] = 'PRSTUDIO_UC_Database_Backend::execute';
				break;
			case 'web_stories_native':
				$binding['registered'] = class_exists( 'PRSTUDIO_Web_Stories_Manage' ) && is_callable( array( 'PRSTUDIO_Web_Stories_Manage', 'bridge_adapter' ) );
				$binding['implemented'] = $binding['registered'];
				$binding['resolver'] = 'PRSTUDIO_Web_Stories_Manage::bridge_adapter';
				break;
			case 'wordpress_native':
				// The static release surface proves a concrete branch in the route
				// handler, therefore the generic entry dispatcher is safe here.
				$binding['registered'] = class_exists( 'WPAIB_REST' ) && is_callable( array( 'WPAIB_REST', 'execute_control_action' ) );
				$binding['implemented'] = $binding['registered'];
				$binding['resolver'] = (string) ( $entry['resolver'] ?? 'WPAIB_REST::execute_control_action' );
				break;
		}
		return $binding;
	}

	/**
	 * Explicit fallback execution. There is intentionally no objective/name based
	 * orchestrator synthesis: a high-level action needs an explicit bounded plan.
	 */
	public static function execute_fallback( string $route, string $action, array $arguments, array $meta ) {
		$route = '/' . trim( $route, '/' );
		$action = sanitize_key( $action );
		$binding = self::binding_for( $route, $action, $meta );

		if ( 'prstudio_complete_native' === $binding['provider'] && class_exists( 'PRSTUDIO_UC_Complete_Action_Executor' ) ) {
			return PRSTUDIO_UC_Complete_Action_Executor::execute( $route, $action, $arguments, $meta );
		}

		if ( 'browser_agent' === $binding['provider'] ) {
			$contract = class_exists( 'PRSTUDIO_UC_Contract' ) ? PRSTUDIO_UC_Contract::by_action( $route, $action ) : null;
			$browser_meta = array_replace( is_array( $contract ) ? $contract : array(), $meta, array( 'action' => $action, 'route' => $route ) );
			if ( class_exists( 'PRSTUDIO_UC_Bridge' ) ) {
				$result = PRSTUDIO_UC_Bridge::dispatch( null, $arguments, $browser_meta );
				if ( null !== $result ) { return $result; }
			}
			if ( class_exists( 'PRSTUDIO_Browser_Runtime' ) ) {
				$result = PRSTUDIO_Browser_Runtime::instance()->dispatch_adapter( null, $arguments, $browser_meta );
				if ( null !== $result ) { return $result; }
			}
			return new WP_Error( 'prstudio_browser_backend_unavailable', 'L’azione Browser è implementata ma nessun Browser Agent/runtime compatibile è online.', array( 'status' => 503, 'route' => $route, 'action' => $action, 'provider' => 'browser_agent', 'catalog_only' => false ) );
		}

		if ( 'database_native' === $binding['provider'] ) {
			if ( ! class_exists( 'PRSTUDIO_UC_Database_Backend' ) ) { return new WP_Error( 'prstudio_database_backend_unavailable', 'Backend database PR STUDIO non disponibile.', array( 'status' => 503 ) ); }
			return PRSTUDIO_UC_Database_Backend::execute( $action, $arguments );
		}

		if ( 'web_stories_native' === $binding['provider'] && class_exists( 'PRSTUDIO_Web_Stories_Manage' ) ) {
			$result = PRSTUDIO_Web_Stories_Manage::bridge_adapter( null, array_replace( $arguments, array( 'action' => $action ) ), $meta );
			if ( null !== $result ) { return $result; }
		}

		if ( ! empty( $binding['adapter_hook_registered'] ) ) {
			$result = apply_filters( (string) $binding['adapter_hook'], null, $arguments, $meta );
			if ( null !== $result ) { return $result; }
		}

		// A catalog/high-level capability may execute a caller-supplied, bounded
		// plan. The presence of that plan is checked per request and never inferred
		// from an action title or description.
		if ( class_exists( 'PRSTUDIO_Agency' ) && PRSTUDIO_Agency::payload_has_executable_plan( $arguments ) ) {
			$plan = PRSTUDIO_Agency::execute_backend_plan( $action, $arguments, $meta );
			if ( null !== $plan ) { return $plan; }
		}

		return new WP_Error(
			'prstudio_action_binding_contract_violation',
			'Azione registrata senza executor concreto risolto: errore tecnico del contratto della release.',
			array( 'status' => 500, 'route' => $route, 'action' => $action, 'binding' => $binding, 'client_continuation' => false, 'category'=>'technical_error' )
		);
	}

	/** Build-time/runtime audit used by the release gate and status endpoint. */
	public static function audit_catalog(): array {
		$path = defined( 'WPAIB_DIR' ) ? trailingslashit( WPAIB_DIR ) . 'connector/action-catalog.json' : '';
		$decoded = $path && is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		$actions = is_array( $decoded ) && is_array( $decoded['actions'] ?? null ) ? $decoded['actions'] : array();
		$invalid = array(); $providers = array(); $bindings = array(); $metadata_only = array(); $db_catalog = array(); $executable = 0;
		foreach ( $actions as $meta ) {
			if ( ! is_array( $meta ) ) { continue; }
			$route = '/' . trim( (string) ( $meta['route'] ?? '' ), '/' );
			$action = sanitize_key( (string) ( $meta['action'] ?? '' ) );
			$tool = (string) ( $meta['tool_name'] ?? '' );
			if ( '' === $action || '/' === $route || '' === $tool ) { $invalid[] = array( 'tool_name' => $tool, 'route' => $route, 'action' => $action, 'reason' => 'invalid_catalog_identity' ); continue; }
			$binding = self::binding_for( $route, $action, $meta );
			$provider = (string) $binding['provider'];
			$providers[ $provider ] = ( $providers[ $provider ] ?? 0 ) + 1;
			$resolver = (string) ( $binding['resolver'] ?: 'none' );
			$bindings[ $resolver ] = ( $bindings[ $resolver ] ?? 0 ) + 1;
			if ( ! empty( $binding['registered'] ) ) { $executable++; }
			elseif ( ! empty( $binding['metadata_only'] ) ) { $metadata_only[] = array( 'tool_name' => $tool, 'route' => $route, 'action' => $action ); }
			else { $invalid[] = array( 'tool_name' => $tool, 'route' => $route, 'action' => $action, 'reason' => 'audited_binding_missing', 'binding' => $binding ); }
			if ( '/database-manage' === $route ) { $db_catalog[] = $action; }
		}
		if ( class_exists( 'PRSTUDIO_UC_Database_Backend' ) ) {
			$db_backend = PRSTUDIO_UC_Database_Backend::actions(); sort( $db_catalog ); sort( $db_backend );
			if ( $db_catalog !== $db_backend ) { $invalid[] = array( 'route' => '/database-manage', 'reason' => 'database_catalog_backend_mismatch', 'catalog' => $db_catalog, 'backend' => $db_backend ); }
		}
		return array(
			'ok' => empty( $invalid ) && empty( $metadata_only ) && $executable === count( $actions ), 'version' => self::VERSION, 'action_count' => count( $actions ), 'executable_action_count' => $executable,
			'metadata_only_count' => count( $metadata_only ), 'providers' => $providers, 'binding_resolvers' => $bindings,
			'invalid_bindings' => $invalid, 'metadata_only_actions' => $metadata_only,
			'client_continuation_executor' => false, 'precondition_as_public_executor' => false, 'generic_dispatcher_counts_as_implementation' => false,
		);
	}
}
