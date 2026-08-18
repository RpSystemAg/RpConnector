<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPAIB_REST {
	private const NS = 'wp-ai-bridge/v1';
	private const OPENAPI_NS = 'rpconnector-admin/v1';

	public static function maybe_serve_well_known(): void {
		$uri = untrailingslashit( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
		$protected = array_filter( array( untrailingslashit( (string) wp_parse_url( WPAIB_Auth::protected_resource_well_known_url(), PHP_URL_PATH ) ), untrailingslashit( (string) wp_parse_url( home_url( '/.well-known/oauth-protected-resource/wp-ai-bridge' ), PHP_URL_PATH ) ) ) );
		$authorization = array_filter( array( untrailingslashit( (string) wp_parse_url( WPAIB_Auth::authorization_server_metadata_url(), PHP_URL_PATH ) ), untrailingslashit( (string) wp_parse_url( home_url( '/.well-known/oauth-authorization-server/wp-ai-bridge-oauth' ), PHP_URL_PATH ) ) ) );
		$is_protected = in_array( $uri, $protected, true );
		$is_authorization = in_array( $uri, $authorization, true );
		if ( ! $is_protected && ! $is_authorization ) { return; }
		if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) { status_header( 405 ); header( 'Allow: GET' ); exit; }
		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $is_protected ? self::protected_resource_data() : self::authorization_server_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function register_routes(): void {
		// 3.0: legacy MCP is disabled by default. It remains opt-in only for migration diagnostics.
		if ( defined( 'PRSTUDIO_UC_ENABLE_LEGACY_MCP' ) && PRSTUDIO_UC_ENABLE_LEGACY_MCP ) {
		register_rest_route( self::NS, '/mcp', array(
			array( 'methods' => 'POST', 'callback' => array( 'WPAIB_MCP', 'handle' ), 'permission_callback' => '__return_true' ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'mcp_get' ), 'permission_callback' => '__return_true' ),
		) );
		}
		register_rest_route( self::NS, '/oauth/protected-resource', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'protected_resource_metadata' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/oauth/.well-known/oauth-authorization-server', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'authorization_server_metadata' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/oauth/register', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'oauth_register' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/oauth/token', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'oauth_token' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/oauth/diagnostics', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'oauth_diagnostics' ), 'permission_callback' => static fn() => WPAIB_Auth::api_or_oauth_permission( false ) ) );
		self::read_route( '/status', array( __CLASS__, 'status' ) );
		self::read_route( '/files/manifest', array( __CLASS__, 'manifest' ) );
		self::read_route( '/files/list', array( __CLASS__, 'list_directory' ) );
		self::read_route( '/files/read', array( __CLASS__, 'read_file' ) );
		self::read_route( '/files/search', array( __CLASS__, 'search_files' ), 'POST' );
		self::write_route( '/files/write', array( __CLASS__, 'write_file' ) );
		self::write_route( '/files/delete', array( __CLASS__, 'delete_file' ) );
		self::write_route( '/files/restore', array( __CLASS__, 'restore_file' ) );
		self::read_route( '/plugins', array( __CLASS__, 'plugins' ) );
		self::write_route( '/plugins/state', array( __CLASS__, 'plugin_state' ) );
		self::read_route( '/themes', array( __CLASS__, 'themes' ) );
		self::write_route( '/themes/switch', array( __CLASS__, 'theme_switch' ) );
		self::read_route( '/content', array( __CLASS__, 'content' ) );
		self::read_route( '/content/(?P<id>\d+)', array( __CLASS__, 'content_item' ) );
		self::write_route( '/content/update', array( __CLASS__, 'content_update' ) );
		self::read_route( '/page', array( __CLASS__, 'page_html' ) );
		self::register_openapi_routes();
	}

	private static function register_openapi_routes(): void {
		foreach ( PRSTUDIO_Agency::control_routes() as $route_meta ) {
			$route = (string) ( $route_meta['path'] ?? '' );
			if ( ! preg_match( '#^/[a-z0-9-]+$#', $route ) ) { continue; }
			register_rest_route( self::OPENAPI_NS, $route, array(
				'methods' => 'POST',
				'callback' => static function( WP_REST_Request $request ) use ( $route ) { return WPAIB_REST::control_route( $request, $route ); },
				'permission_callback' => static function( WP_REST_Request $request ) use ( $route ) { return WPAIB_REST::control_permission( $request, $route ); },
			) );
		}
	}

	private static function read_route( string $route, callable $callback, string $methods = 'GET' ): void { register_rest_route( self::NS, $route, array( 'methods' => $methods, 'callback' => $callback, 'permission_callback' => array( __CLASS__, 'read_permission' ) ) ); }
	private static function write_route( string $route, callable $callback ): void { register_rest_route( self::NS, $route, array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => array( __CLASS__, 'write_permission' ) ) ); }
	public static function read_permission() { return WPAIB_Auth::permission( false ); }
	public static function write_permission() { return WPAIB_Auth::permission( true ); }

	public static function control_permission( WP_REST_Request $request, string $route ) {
		$payload = $request->get_json_params();
		$action = is_array( $payload ) ? sanitize_text_field( (string) ( $payload['action'] ?? '' ) ) : '';
		$meta = PRSTUDIO_Agency::control_action_by_route( $route, $action );
		if ( ! is_array( $meta ) ) { return new WP_Error( 'wpaib_control_action_invalid', 'Azione non dichiarata per questa route OpenAPI.', array( 'status' => 400 ) ); }
		return WPAIB_Auth::api_or_oauth_permission( empty( $meta['read_only'] ) );
	}

	public static function control_route( WP_REST_Request $request, string $route ): WP_REST_Response {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) { return self::control_error_response( new WP_Error( 'wpaib_control_json_invalid', 'JSON non valido.', array( 'status' => 400 ) ) ); }
		$action = sanitize_text_field( (string) ( $payload['action'] ?? '' ) );
		$result = self::execute_control_action( $route, $action, $payload, 'openapi' );
		if ( is_wp_error( $result ) ) { return self::control_error_response( $result ); }
		$response = new WP_REST_Response( array( 'success' => true, 'status' => 200, 'data' => self::redact( $result ), 'audit' => array( 'request_id' => (string) ( $payload['request_id'] ?? '' ), 'route' => $route, 'action' => $action, 'executed_at' => current_time( 'mysql', true ) ), 'warnings' => array() ), 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	private static function control_error_response( WP_Error $error ): WP_REST_Response {
		$data = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		$response = new WP_REST_Response( array( 'success' => false, 'status' => $status, 'data' => is_array( $data ) ? self::redact( $data ) : null, 'warnings' => array(), 'error' => $error->get_error_code() . ': ' . $error->get_error_message() ), $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public static function execute_control_action( string $route, string $action, array $arguments, $source = 'openapi' ) {
		// Backward compatible normalization. Older/internal callers may supply the
		// structured capability source array; normalize it instead of crashing on a
		// scalar type declaration. The structured value is retained as metadata.
		$source_meta = is_array( $source ) ? $source : array();
		if ( is_array( $source ) ) {
			$source = (string) ( $source['kind'] ?? $source['tool_name'] ?? 'capability_gateway' );
		}
		$source = sanitize_key( (string) $source );
		if ( '' === $source ) { $source = 'internal'; }
		if ( $source_meta && ! isset( $arguments['_source_meta'] ) ) { $arguments['_source_meta'] = $source_meta; }
		$route = '/' . trim( $route, '/' );
		// Same guarantee as PRSTUDIO_UC_Execution_Gateway's arbitrary-script
		// denylist: no route into the system -- MCP capability gateway, GPT
		// Actions legacy REST, or a direct internal call -- may execute
		// caller-supplied script in the browser. Keeps browser_arbitrary_js_exposed=false true regardless of entry point.
		if ( '/frontend-manage' === $route && 'playwright_evaluate' === $action ) {
			return new WP_Error( 'wpaib_arbitrary_script_disabled', 'This action would execute caller-supplied script in the browser; it is disabled.', array( 'status' => 403, 'route' => $route, 'action' => $action ) );
		}
		$meta = PRSTUDIO_Agency::control_action_by_route( $route, $action );
		if ( ! is_array( $meta ) ) { return new WP_Error( 'wpaib_control_action_invalid', 'Azione non dichiarata per questa route.', array( 'status' => 400, 'route' => $route, 'action' => $action ) ); }
		$execution_contract = class_exists( 'PRSTUDIO_UC_Execution_Router' )
			? PRSTUDIO_UC_Execution_Router::tool_contract( (string) ( $meta['tool_name'] ?? ( trim( $route, '/' ) . '_' . $action ) ), array( 'readOnlyHint' => ! empty( $meta['read_only'] ) ) )
			: array( 'can_execute_inline' => false, 'execution_class' => 'agentic', 'preferred_executor' => 'php' );
		$fast_path = ! empty( $execution_contract['can_execute_inline'] )
			&& empty( $arguments['agentic'] ) && empty( $arguments['deferred'] ) && empty( $arguments['async'] ) && empty( $arguments['durable'] );
		$arguments['action'] = $action;
		$arguments['_route'] = $route;
		$arguments['_source'] = $source;
		if ( empty( $meta['read_only'] ) && class_exists( 'PRSTUDIO_UC_Request_Guard' ) ) {
			$replay = PRSTUDIO_UC_Request_Guard::lookup( $route, $action, $arguments );
			if ( is_array( $replay ) ) { return $replay; }
		}
		$mutation = self::value( $arguments, 'mutation', array() );
		if ( is_array( $mutation ) && 'preview' === (string) ( $mutation['mode'] ?? '' ) ) {
			return self::preview_control_action( $route, $action, $arguments, $meta );
		}
		if ( empty( $meta['read_only'] ) && class_exists( 'PRSTUDIO_UC_Pre_Mutation_Safety' ) ) {
			$gate_args = $arguments;
			$gate_args['route'] = $route;
			$gate_args['action'] = $action;
			$scope = PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_legacy_route( $route, $action, $meta );
			$gate = PRSTUDIO_UC_Pre_Mutation_Safety::before_commit( $scope, (string) ( $meta['tool_name'] ?? 'rpconnector_action_call' ), $gate_args );
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		$provider = class_exists( 'PRSTUDIO_UC_Backend_Executability' ) ? PRSTUDIO_UC_Backend_Executability::provider_for( $route, $action, $meta ) : 'wordpress_native';
		$result = null;
		$hook = (string) ( $meta['adapter_hook'] ?? '' );
		$hook_used = false;
		/* Authoritative native executors must run before adapter hooks. In 0.3.9 an
		 * adapter could turn an executable database/SEO operation into a handoff. */
		$native_first = $fast_path || in_array( $route, array( '/database-manage', '/seo-manage' ), true );
		if ( $native_first ) {
			$result = self::native_control_action( $route, $action, $arguments );
		}
		if ( null === $result && '' !== $hook && has_filter( $hook ) ) {
			$hook_used = true;
			$result = apply_filters( $hook, null, $arguments, $meta );
			if ( null !== $result ) { $provider = 'wordpress_extension_hook'; }
		}
		if ( null === $result ) {
			$proxy_route = self::value( $arguments, 'route', '' );
			$proxy_method = self::value( $arguments, 'method', '' );
			if ( is_string( $proxy_route ) && '' !== $proxy_route && is_string( $proxy_method ) && '' !== $proxy_method ) {
				$result = self::proxy_internal_rest( $proxy_method, $proxy_route, $arguments );
				$provider = 'wordpress_internal_rest';
			} elseif ( ! $native_first ) {
				$result = self::native_control_action( $route, $action, $arguments );
			}
		}
		/* Complete 1.0.0 executor: every catalog action that historically had only
		 * metadata now resolves to a concrete bounded implementation. Existing native
		 * branches and registered adapters keep precedence to preserve compatibility. */
		if ( null === $result && class_exists( 'PRSTUDIO_UC_Complete_Action_Executor' ) && PRSTUDIO_UC_Complete_Action_Executor::supports( $route, $action ) ) {
			$result = PRSTUDIO_UC_Complete_Action_Executor::execute( $route, $action, $arguments, $meta );
			$provider = 'prstudio_complete_native';
		}
		if ( null === $result ) {
			$result = ( '' !== $hook && ! $hook_used ) ? apply_filters( $hook, null, $arguments, $meta ) : null;
			if ( null === $result ) { $result = apply_filters( 'rpconnector_admin_execute_action', null, $route, $action, $arguments, $meta ); }
			if ( null !== $result ) { $provider = 'wordpress_extension_hook'; }
		}
		if ( null === $result ) {
			$result = class_exists( 'PRSTUDIO_UC_Backend_Executability' )
				? PRSTUDIO_UC_Backend_Executability::execute_fallback( $route, $action, $arguments, $meta )
				: PRSTUDIO_Agency::execute_control_fallback( $route, $action, $arguments, $meta );
			$provider = class_exists( 'PRSTUDIO_UC_Backend_Executability' ) ? PRSTUDIO_UC_Backend_Executability::provider_for( $route, $action, $meta ) : 'wordpress_backend_plan';
		}
		if ( is_wp_error( $result ) ) { return $result; }
		$outcome = self::control_outcome( $meta, $result, $arguments );
		if ( $fast_path ) {
			// The primitive result/control_outcome is the verification contract on the
			// hot path. Do not re-run policy/verification frameworks for an effect the
			// executor already read back or reported via affected_rows.
			$verification_receipt = array(
				'ok' => ! empty( $outcome['verified'] ) || ! empty( $meta['read_only'] ),
				'strategy' => (string) ( $execution_contract['minimal_verification'] ?? 'executor_result' ),
				'local' => true,
			);
		} else {
			$verification_receipt = class_exists( 'PRSTUDIO_UC_Verifier' ) ? PRSTUDIO_UC_Verifier::control_receipt( $route, $action, $outcome, $result ) : array( 'ok'=>true, 'blocking'=>false );
			if ( ! empty( $verification_receipt['executed'] ) && empty( $verification_receipt['verified'] ) ) {
				$verification_receipt['degraded']=true; $verification_receipt['blocking']=false; $verification_receipt['evidence_state']='executed_unverified';
			}
		}
		$audit_status = ! empty( $outcome['verified'] ) ? 'success' : (string) $outcome['status'];
		$audit_details = array( 'source' => $source, 'provider' => $provider, 'read_only' => ! empty( $meta['read_only'] ), 'outcome' => $outcome, 'execution_class'=>(string)($execution_contract['execution_class']??''), 'route'=>$fast_path?'fast_inline':'complex' );
		if ( $fast_path && method_exists( 'WPAIB_Audit', 'log_fast' ) ) {
			WPAIB_Audit::log_fast( 'control.' . trim( str_replace( '-', '_', $route ), '/' ) . '.' . sanitize_key( $action ), $audit_status, (string) ( $arguments['request_id'] ?? '' ), $audit_details );
		} else {
			WPAIB_Audit::log( 'control.' . trim( str_replace( '-', '_', $route ), '/' ) . '.' . sanitize_key( $action ), $audit_status, (string) ( $arguments['request_id'] ?? '' ), array_merge( $audit_details, array( 'verification_receipt'=>$verification_receipt ) ) );
		}
		$response_payload = array(
			'route'     => $route,
			'action'    => $action,
			'tool_name' => $meta['tool_name'] ?? '',
			'execution' => $provider,
			'provider'  => $provider,
			'status'    => (string) $outcome['status'],
			'accepted'  => true,
			'executed'  => (bool) $outcome['executed'],
			'mutated'   => (bool) $outcome['mutated'],
			'verified'  => (bool) $outcome['verified'],
			'reason'    => (string) ( $outcome['reason'] ?? '' ),
			'suggested_tools' => array(),
			'instruction' => '',
			'client_continuation' => false,
			'result'    => self::redact( $result ),
			'verification_receipt' => $verification_receipt,
			'execution_contract' => array(
				'route' => $fast_path ? 'fast_inline' : 'complex',
				'execution_class' => (string) ( $execution_contract['execution_class'] ?? '' ),
				'preferred_executor' => (string) ( $execution_contract['preferred_executor'] ?? '' ),
				'queue_bypassed' => $fast_path,
				'agency_bypassed' => $fast_path,
			),
		);
		if ( empty( $meta['read_only'] ) && class_exists( 'PRSTUDIO_UC_Request_Guard' ) ) { PRSTUDIO_UC_Request_Guard::remember( $route, $action, $arguments, $response_payload ); }
		return $response_payload;
	}

	public static function preview_control_action( string $route, string $action, array $arguments, array $meta = array() ): array {
		$route = '/' . trim( $route, '/' );
		if ( '/products-manage' === $route && 'manage_attributes' === $action && class_exists( 'WPAIB_Enterprise' ) ) {
			$detail = WPAIB_Enterprise::preview_product_attributes( $arguments );
			$detail['route'] = $route; $detail['action'] = $action; $detail['executor_binding'] = 'woocommerce_crud';
			return $detail;
		}
		$schema = (array) ( $meta['input_schema'] ?? array() );
		$required = array_values( array_filter( (array) ( $schema['required'] ?? array() ), 'is_string' ) );
		$missing = array(); foreach ( $required as $field ) { if ( ! array_key_exists( $field, $arguments ) ) { $missing[] = $field; } }
		$provider = class_exists( 'PRSTUDIO_UC_Backend_Executability' ) ? PRSTUDIO_UC_Backend_Executability::provider_for( $route, $action, $meta ) : self::control_provider_hint( $route, $action );
		$binding = class_exists( 'PRSTUDIO_UC_Backend_Executability' ) ? PRSTUDIO_UC_Backend_Executability::binding_for( $route, $action, $meta ) : array( 'registered' => true, 'provider' => $provider );
		return array(
			'preview' => true, 'contract_version' => '1.0.0', 'route' => $route, 'action' => $action,
			'read_only' => ! empty( $meta['read_only'] ), 'destructive' => ! empty( $meta['destructive'] ),
			'executable' => ! empty( $binding['registered'] ), 'provider' => $provider, 'executor_binding' => $binding,
			'input_contract' => array( 'required' => $required, 'missing' => $missing, 'auto_hydration' => true, 'schema_properties' => array_keys( (array) ( $schema['properties'] ?? array() ) ) ),
			'execution_plan' => array( 'external_http_calls' => 'bounded_by_origin_policy', 'internal_rest_batching' => true, 'client_continuation' => false, 'creates_job' => false, 'persistence_verification' => 'fresh_read_or_explicit_effect_contract' ),
			'normalized_arguments' => self::redact( $arguments ),
		);
	}

	private static function control_outcome( array $meta, $result, array $arguments = array() ): array {
		if ( ! empty( $meta['read_only'] ) ) { return array( 'status' => 'completed', 'executed' => true, 'mutated' => false, 'verified' => true, 'degraded'=>false, 'blocking'=>false ); }
		if ( is_array( $result ) && is_array( $result['_control_outcome'] ?? null ) ) {
			return self::normalize_control_outcome( $result['_control_outcome'] );
		}
		$signals = self::control_signals( $result );
		if ( $signals['positive'] && $signals['verified'] ) { return self::normalize_control_outcome( array( 'status' => 'completed', 'executed' => true, 'mutated' => true, 'verified' => true ) ); }
		if ( $signals['positive'] ) { return self::normalize_control_outcome( array( 'status' => 'degraded', 'executed' => true, 'mutated' => true, 'verified' => false, 'degraded'=>true, 'blocking'=>false, 'reason' => 'mutation_not_independently_verified' ) ); }
		if ( $signals['verified'] && ! $signals['negative'] ) { return self::normalize_control_outcome( array( 'status' => 'completed', 'executed' => true, 'mutated' => false, 'verified' => true, 'reason' => 'verified_no_change' ) ); }
		return self::normalize_control_outcome( array( 'status' => 'degraded', 'executed' => true, 'mutated' => false, 'verified' => false, 'degraded'=>true, 'blocking'=>false, 'reason' => 'execution_result_unverified' ) );
	}

	private static function normalize_control_outcome( array $outcome ): array {
		$outcome = array_replace( array( 'status' => 'degraded', 'executed' => false, 'mutated' => false, 'verified' => false, 'degraded'=>false, 'blocking'=>false ), $outcome );
		$executed=!empty($outcome['executed']); $mutated=!empty($outcome['mutated']); $verified=!empty($outcome['verified']);
		// PR STUDIO ONE-GUARD INVARIANT: execution results cannot introduce a second mutation veto.
		if($verified&&$executed){$outcome['status']='completed';$outcome['degraded']=false;$outcome['blocking']=false;}
		elseif($executed){$outcome['status']='degraded';$outcome['degraded']=true;$outcome['blocking']=false;}
		else{$outcome['status']='technical_error';$outcome['degraded']=false;$outcome['blocking']=true;}
		$outcome['executed']=$executed;$outcome['mutated']=$mutated;$outcome['verified']=$verified;
		return $outcome;
	}

	private static function control_signals( $value ): array {
		$signals = array( 'positive' => false, 'negative' => false, 'verified' => false );
		if ( ! is_array( $value ) ) { return $signals; }
		$positive_keys = array( 'created','updated','deleted','restored','scheduled','cleared','flushed','purged','regenerated','saved','activated','deactivated','installed','uninstalled','attached','detached','assigned','ran','spawned','deployed','handler_executed','external_cdn_purged','requested_effect_verified' );
		if ( array_key_exists( 'before', $value ) && array_key_exists( 'after', $value ) ) { $signals['positive'] = $value['before'] !== $value['after']; if ( $signals['positive'] ) { $signals['verified'] = true; } }
		if ( ! empty( $value['after_sha256'] ) || ! empty( $value['verified'] ) ) { $signals['verified'] = true; }
		if ( isset( $value['affected_rows'] ) && (int) $value['affected_rows'] > 0 ) { $signals['positive'] = true; }
		if ( isset( $value['succeeded'] ) && (int) $value['succeeded'] > 0 ) { $signals['positive'] = true; if ( isset( $value['failed'] ) && 0 === (int) $value['failed'] ) { $signals['verified'] = true; } }
		foreach ( $positive_keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) { continue; }
			if ( true === $value[ $key ] || ( is_numeric( $value[ $key ] ) && (int) $value[ $key ] > 0 ) ) { $signals['positive'] = true; if ( in_array( $key, array( 'deleted','restored','scheduled','cleared','flushed','purged','regenerated','saved','activated','deactivated','installed','uninstalled','attached','detached','assigned','requested_effect_verified' ), true ) ) { $signals['verified'] = true; } }
			if ( false === $value[ $key ] ) { $signals['negative'] = true; }
		}
		foreach ( $value as $child ) {
			if ( ! is_array( $child ) ) { continue; }
			$nested = self::control_signals( $child );
			$signals['positive'] = $signals['positive'] || $nested['positive'];
			$signals['negative'] = $signals['negative'] || $nested['negative'];
			$signals['verified'] = $signals['verified'] || $nested['verified'];
		}
		return $signals;
	}

	private static function native_control_action( string $route, string $action, array $args ) {
		switch ( $route ) {
			case '/system-manage': return self::control_system( $action, $args );
			case '/global-search': return self::control_search( $action, $args );
			case '/backup-manage': return self::control_backup( $action, $args );
			case '/cache-manage': return self::control_cache( $action, $args );
			case '/cron-manage': return self::control_cron( $action, $args );
			case '/logs-manage': return self::control_logs( $action, $args );
			case '/content-manage': return self::control_content( $action, $args );
			case '/taxonomy-manage': return self::control_taxonomy( $action, $args );
			case '/media-manage': return self::control_media( $action, $args );
			case '/comments-manage': return self::control_comments( $action, $args );
			case '/users-manage': return self::control_users( $action, $args );
			case '/settings-manage': return self::control_settings( $action, $args );
			case '/plugins-manage': return self::control_plugins( $action, $args );
			case '/themes-manage': return self::control_themes( $action, $args );
			case '/products-manage': return self::control_products( $action, $args );
			case '/inventory-manage': return self::control_inventory( $action, $args );
			case '/orders-manage': return self::control_orders( $action, $args );
			case '/seo-manage': return self::control_seo( $action, $args );
			case '/files-manage': return self::control_files( $action, $args );
			case '/database-manage': return self::control_database( $action, $args );
			case '/maintenance-manage': return self::control_maintenance( $action, $args );
			case '/frontend-manage': return self::control_frontend( $action, $args );
			case '/security-manage': return self::control_security( $action, $args );
			case '/menus-manage': return self::control_menus( $action, $args );
			case '/widgets-manage': return self::control_widgets( $action, $args );
			case '/templates-manage': return self::control_templates( $action, $args );
			case '/styles-manage': return self::control_styles( $action, $args );
			case '/customers-manage': return self::control_customers( $action, $args );
			case '/coupons-manage': return self::control_coupons( $action, $args );
			case '/commerce-settings-manage': return self::control_commerce_settings( $action, $args );
		}
		return null;
	}

	private static function value( array $args, string $key, $default = null ) {
		if ( array_key_exists( $key, $args ) ) { return $args[ $key ]; }
		foreach ( array( 'params', 'body', 'query' ) as $container ) {
			if ( isset( $args[ $container ] ) && is_array( $args[ $container ] ) && array_key_exists( $key, $args[ $container ] ) ) { return $args[ $container ][ $key ]; }
		}
		return $default;
	}

	private static function control_provider_hint( string $route, string $action ): string {
		$meta = PRSTUDIO_Agency::control_action_by_route( $route, $action );
		if ( class_exists( 'PRSTUDIO_UC_Backend_Executability' ) ) { return PRSTUDIO_UC_Backend_Executability::provider_for( $route, $action, is_array( $meta ) ? $meta : array() ); }
		return 'wordpress_backend';
	}

	private static function bridge_user(): int {
		$users = get_users( array( 'role__in' => array( 'administrator' ), 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
		return $users ? (int) $users[0] : 0;
	}

	private static function proxy_internal_rest( string $method, string $route, array $args ) {
		$method = strtoupper( sanitize_text_field( $method ) );
		$route = '/' . ltrim( $route, '/' );
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) { return new WP_Error( 'wpaib_proxy_method_invalid', 'Metodo proxy non valido.', array( 'status' => 400 ) ); }
		if ( 0 === strpos( $route, '/wp-ai-bridge/v1/oauth' ) || 0 === strpos( $route, '/rpconnector-admin/v1' ) || 0 === strpos( $route, '/wp-ai-bridge/v1/mcp' ) ) { return new WP_Error( 'wpaib_proxy_route_forbidden', 'Route proxy non consentita per il trasporto.', array( 'status' => 403 ) ); }
		$previous = get_current_user_id();
		$user = self::bridge_user();
		if ( $user ) { wp_set_current_user( $user ); }
		try {
			$request = new WP_REST_Request( $method, $route );
			$query = self::value( $args, 'query', array() );
			$body = self::value( $args, 'body', array() );
			if ( is_array( $query ) ) { $request->set_query_params( $query ); }
			if ( is_array( $body ) ) { $request->set_body_params( $body ); $request->set_header( 'content-type', 'application/json' ); }
			$response = rest_do_request( $request );
		} finally {
			wp_set_current_user( $previous );
		}
		if ( is_wp_error( $response ) ) { return $response; }
		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
		$data = method_exists( $response, 'get_data' ) ? $response->get_data() : null;
		if ( $status >= 400 ) { return new WP_Error( 'wpaib_proxy_failed', 'La route REST interna ha restituito un errore.', array( 'status' => $status, 'response' => self::redact( $data ) ) ); }
		return array( 'proxy' => true, 'method' => $method, 'route' => $route, 'status' => $status, 'data' => self::redact( $data ) );
	}

	private static function control_system( string $action, array $args ) {
		if ( in_array( $action, array( 'status', 'capabilities', 'verify', 'reload_bridge_capabilities' ), true ) ) { return array( 'site' => WPAIB_Site::status(), 'enterprise' => WPAIB_Enterprise::status(), 'registry' => PRSTUDIO_Agency::control_registry_info(), 'browser_extension' => class_exists( 'PRSTUDIO_UC_REST' ) ? PRSTUDIO_UC_REST::browser_extension_summary() : array( 'aware'=>false ), 'integration_chain' => class_exists( 'PRSTUDIO_UC_REST' ) ? PRSTUDIO_UC_REST::integration_capabilities() : array() ); }
		if ( 'health' === $action ) { $loopback = wp_remote_get( home_url( '/wp-json/' ), array( 'timeout' => 10, 'redirection' => 2 ) ); return array( 'site' => WPAIB_Site::status(), 'loopback' => is_wp_error( $loopback ) ? array( 'ok' => false, 'error' => $loopback->get_error_message() ) : array( 'ok' => true, 'status' => wp_remote_retrieve_response_code( $loopback ) ) ); }
		if ( 'environment' === $action ) { return array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'environment_type' => wp_get_environment_type(), 'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG, 'memory_limit' => ini_get( 'memory_limit' ), 'max_execution_time' => ini_get( 'max_execution_time' ), 'timezone' => wp_timezone_string() ); }
		if ( 'php_info' === $action ) { return array( 'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'extensions' => get_loaded_extensions(), 'limits' => array( 'memory_limit' => ini_get( 'memory_limit' ), 'post_max_size' => ini_get( 'post_max_size' ), 'upload_max_filesize' => ini_get( 'upload_max_filesize' ), 'max_execution_time' => ini_get( 'max_execution_time' ) ) ); }
		if ( 'rest_routes' === $action ) { return array( 'routes' => array_keys( rest_get_server()->get_routes() ) ); }
		if ( 'get_debug_config' === $action ) { return array( 'WP_DEBUG' => defined( 'WP_DEBUG' ) && WP_DEBUG, 'WP_DEBUG_LOG' => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : null, 'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : null, 'SCRIPT_DEBUG' => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ); }
		if ( 'get_runtime_config' === $action || 'get_feature_flags' === $action ) { return array( 'settings' => WPAIB_Auth::settings(), 'runtime_overrides' => get_option( 'wpaib_runtime_overrides', array() ) ); }
		if ( 'get_environment_variables_masked' === $action ) { $keys = array( 'HTTP_HOST', 'SERVER_SOFTWARE', 'SERVER_PROTOCOL', 'HTTPS', 'PHP_SELF' ); $out = array(); foreach ( $keys as $key ) { $out[ $key ] = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) : null; } return $out; }
		if ( in_array( $action, array( 'set_debug_config', 'set_runtime_config', 'set_runtime_limits', 'set_feature_flags' ), true ) ) { $value = self::value( $args, 'body', self::value( $args, 'params', array() ) ); $value = is_array( $value ) ? self::redact( $value ) : array(); update_option( 'wpaib_runtime_overrides', $value, false ); foreach ( array( 'memory_limit', 'max_execution_time' ) as $key ) { if ( isset( $value[ $key ] ) ) { @ini_set( $key, (string) $value[ $key ] ); } } return array( 'stored' => true, 'runtime_overrides' => $value, 'note' => 'Costanti definite in wp-config.php non vengono riscritte automaticamente.' ); }
		if ( in_array( $action, array( 'enable_maintenance', 'disable_maintenance' ), true ) ) { $file = trailingslashit( ABSPATH ) . '.maintenance'; if ( 'enable_maintenance' === $action ) { $ok = false !== file_put_contents( $file, '<?php $upgrading = ' . time() . ';' ); } else { $ok = ! file_exists( $file ) || unlink( $file ); } return $ok ? array( 'maintenance' => 'enable_maintenance' === $action ) : new WP_Error( 'wpaib_maintenance_failed', 'Impossibile modificare lo stato manutenzione.', array( 'status' => 500 ) ); }
		if ( 'run_health_fix' === $action ) { flush_rewrite_rules( false ); wp_cache_flush(); return array( 'rewrite_rules_flushed' => true, 'object_cache_flushed' => true ); }
		return null;
	}

	private static function control_search( string $action, array $args ) {
		$term = (string) self::value( $args, 'search', self::value( $args, 'query_text', self::value( $args, 'query', '' ) ) );
		if ( is_array( $term ) ) { $term = (string) ( $term['search'] ?? '' ); }
		$limit = max( 1, min( 500, (int) self::value( $args, 'limit', 100 ) ) );
		if ( in_array( $action, array( 'search', 'search_files' ), true ) ) { return WPAIB_Files::search( $term, (string) self::value( $args, 'path', '' ), (array) self::value( $args, 'extensions', array() ), 0, $limit ); }
		if ( 'search_content' === $action ) { return WPAIB_Site::list_content( array( 'post_type' => (string) self::value( $args, 'post_type', 'any' ), 'status' => 'any', 'page' => 1, 'per_page' => min( 100, $limit ), 'search' => $term ) ); }
		if ( 'search_users' === $action ) { $users = get_users( array( 'search' => '*' . esc_attr( $term ) . '*', 'number' => $limit, 'fields' => array( 'ID', 'user_login', 'user_email', 'display_name' ) ) ); return array( 'items' => array_map( static function( $u ) { return array( 'id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'display_name' => $u->display_name ); }, $users ) ); }
		if ( 'search_comments' === $action ) { $comments = get_comments( array( 'search' => $term, 'number' => $limit, 'status' => 'all' ) ); return array( 'items' => array_map( static function( $c ) { return array( 'id' => $c->comment_ID, 'post_id' => $c->comment_post_ID, 'author' => $c->comment_author, 'content' => $c->comment_content, 'approved' => $c->comment_approved ); }, $comments ) ); }
		if ( 'search_options' === $action ) { global $wpdb; $like = '%' . $wpdb->esc_like( $term ) . '%'; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT %d", $like, $limit ), ARRAY_A ); return array( 'items' => $rows ); }
		if ( 'search_taxonomies' === $action ) { $terms = get_terms( array( 'taxonomy' => array_keys( get_taxonomies() ), 'hide_empty' => false, 'search' => $term, 'number' => $limit ) ); return is_wp_error( $terms ) ? $terms : array( 'items' => array_map( static function( $t ) { return array( 'id' => $t->term_id, 'taxonomy' => $t->taxonomy, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count ); }, $terms ) ); }
		if ( 'search_plugins' === $action ) { $items = array_filter( WPAIB_Site::plugins()['items'] ?? array(), static function( $p ) use ( $term ) { return false !== stripos( wp_json_encode( $p ), $term ); } ); return array( 'items' => array_values( array_slice( $items, 0, $limit ) ) ); }
		if ( 'search_themes' === $action ) { $items = array_filter( WPAIB_Site::themes()['items'] ?? array(), static function( $p ) use ( $term ) { return false !== stripos( wp_json_encode( $p ), $term ); } ); return array( 'items' => array_values( array_slice( $items, 0, $limit ) ) ); }
		if ( 'search_products' === $action ) { return WPAIB_Enterprise::list_products( array( 'search' => $term, 'per_page' => min( 100, $limit ) ) ); }
		if ( 'search_orders' === $action ) { return WPAIB_Enterprise::list_orders( array( 'per_page' => min( 100, $limit ) ) ); }
		return null;
	}

	private static function control_backup( string $action, array $args ) {
		$root = WPAIB_Files::backup_root();
		if ( in_array( $action, array( 'capabilities', 'status' ), true ) ) { return array( 'root' => str_replace( ABSPATH, '', $root ), 'writable' => is_dir( $root ) && is_writable( $root ), 'supports' => array( 'file_resource_backups', 'file_restore' ) ); }
		if ( in_array( $action, array( 'list', 'export_manifest' ), true ) ) { WPAIB_Files::ensure_backup_directory(); $items = array(); foreach ( glob( trailingslashit( $root ) . '*' ) ?: array() as $dir ) { $meta = is_file( trailingslashit( $dir ) . 'meta.json' ) ? json_decode( (string) file_get_contents( trailingslashit( $dir ) . 'meta.json' ), true ) : null; if ( is_array( $meta ) ) { $items[] = $meta; } } return array( 'items' => $items, 'count' => count( $items ) ); }
		if ( 'get' === $action || 'verify' === $action ) { $id = sanitize_text_field( (string) self::value( $args, 'backup_id', '' ) ); $file = trailingslashit( $root ) . $id . '/meta.json'; if ( ! is_file( $file ) ) { return new WP_Error( 'wpaib_backup_missing', 'Backup non trovato.', array( 'status' => 404 ) ); } $meta = json_decode( (string) file_get_contents( $file ), true ); return array( 'meta' => $meta, 'data_exists' => is_file( trailingslashit( dirname( $file ) ) . 'data.bin' ) ); }
		if ( 'restore' === $action ) { return WPAIB_Files::restore( (string) self::value( $args, 'backup_id', '' ), self::value( $args, 'expected_sha256', null ) ); }
		if ( 'delete' === $action ) { $id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) self::value( $args, 'backup_id', '' ) ); $dir = trailingslashit( $root ) . $id; if ( ! is_dir( $dir ) ) { return new WP_Error( 'wpaib_backup_missing', 'Backup non trovato.', array( 'status' => 404 ) ); } foreach ( array_reverse( glob( $dir . '/*' ) ?: array() ) as $file ) { is_dir( $file ) ? rmdir( $file ) : unlink( $file ); } return array( 'deleted' => rmdir( $dir ), 'backup_id' => $id ); }
		return null;
	}

	private static function control_cache( string $action, array $args ) {
		$key = sanitize_key( (string) self::value( $args, 'key', '' ) );
		if ( 'status' === $action ) { return array( 'external_object_cache' => wp_using_ext_object_cache(), 'cache_type' => wp_using_ext_object_cache() ? 'external' : 'runtime', 'transients_table' => true ); }
		if ( 'list_transients' === $action ) { global $wpdb; $rows = $wpdb->get_results( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' ORDER BY option_id DESC LIMIT 500", ARRAY_A ); return array( 'items' => $rows ); }
		if ( 'get_transient' === $action ) { return array( 'key' => $key, 'value' => get_transient( $key ) ); }
		if ( 'set_transient' === $action ) { return array( 'stored' => set_transient( $key, self::value( $args, 'value', null ), max( 0, (int) self::value( $args, 'expiration', 0 ) ) ) ); }
		if ( 'delete_transient' === $action ) { return array( 'deleted' => delete_transient( $key ) ); }
		if ( 'flush_object_cache' === $action ) { return array( 'object_cache_flushed' => (bool) wp_cache_flush(), 'providers' => array( 'wordpress_object_cache' ) ); }
		if ( in_array( $action, array( 'flush_page_cache', 'flush_cdn_cache', 'flush_all' ), true ) ) { return self::flush_caches_native( $action, $args ); }
		if ( 'get_rewrite_rules' === $action ) { return array( 'rules' => get_option( 'rewrite_rules', array() ) ); }
		if ( 'flush_rewrite_rules' === $action ) { flush_rewrite_rules( false ); return array( 'flushed' => true ); }
		if ( 'verify' === $action ) { return array( 'object_cache' => wp_using_ext_object_cache(), 'rewrite_rules_count' => count( (array) get_option( 'rewrite_rules', array() ) ) ); }
		return null;
	}

	private static function flush_caches_native( string $action, array $args ): array {
		$providers = array();
		$urls = self::value( $args, 'urls', array() );
		$urls = is_array( $urls ) ? array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) ) : array();
		$object_flushed = false;

		// Keep cache invalidation scoped to what the caller requested. A page/CDN
		// purge must not evict the entire WordPress object cache as collateral work.
		if ( 'flush_all' === $action ) { $object_flushed = (bool) wp_cache_flush(); $providers[] = 'wordpress_object_cache'; }

		if ( in_array( $action, array( 'flush_all', 'flush_page_cache' ), true ) ) {
			if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); $providers[] = 'wp_rocket'; }
			if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); $providers[] = 'w3_total_cache'; }
			if ( class_exists( '\\LiteSpeed\\Purge' ) && method_exists( '\\LiteSpeed\\Purge', 'purge_all' ) ) { \LiteSpeed\Purge::purge_all(); $providers[] = 'litespeed'; }
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); $providers[] = 'siteground'; }
			foreach ( array( 'breeze_clear_all_cache' => 'breeze', 'litespeed_purge_all' => 'litespeed_hook', 'wpaib_flush_page_cache' => 'custom_page_cache' ) as $hook => $provider ) {
				if ( function_exists( 'has_action' ) && has_action( $hook ) ) { do_action( $hook, $urls ); $providers[] = $provider; }
			}
			foreach ( $urls as $url ) {
				if ( function_exists( 'rocket_clean_files' ) ) { rocket_clean_files( array( $url ) ); }
				if ( function_exists( 'has_action' ) && has_action( 'litespeed_purge_url' ) ) { do_action( 'litespeed_purge_url', $url ); }
				if ( function_exists( 'has_action' ) && has_action( 'wpaib_cache_purge_url' ) ) { do_action( 'wpaib_cache_purge_url', $url ); }
			}
		}
		if ( in_array( $action, array( 'flush_all', 'flush_cdn_cache' ), true ) ) {
			foreach ( array( 'cloudflare_purge_cache' => 'cloudflare', 'wpaib_flush_cdn_cache' => 'custom_cdn' ) as $hook => $provider ) {
				if ( function_exists( 'has_action' ) && has_action( $hook ) ) { do_action( $hook, $urls ); $providers[] = $provider; }
			}
		}

		$providers = array_values( array_unique( $providers ) );
		$external_cdn_purged = (bool) array_intersect( $providers, array( 'cloudflare', 'custom_cdn' ) );
		$requested_effect_verified = 'flush_cdn_cache' !== $action || $external_cdn_purged;
		return array(
			'handler_executed'     => true,
			'flushed'              => $requested_effect_verified,
			'requested_effect_verified' => $requested_effect_verified,
			'requested_scope'     => $action,
			'object_cache_flushed'=> $object_flushed,
			'urls'                => $urls,
			'providers_executed'  => $providers,
			'external_cdn_purged' => $external_cdn_purged,
			'note'                => 'Le cache WordPress e Rank Math vengono invalidate nativamente; external_cdn_purged indica se era collegato anche un provider CDN.',
		);
	}

	private static function control_cron( string $action, array $args ) {
		$hook = sanitize_key( (string) self::value( $args, 'hook', '' ) );
		if ( 'list' === $action || 'get' === $action ) { $cron = _get_cron_array(); $items = array(); foreach ( $cron as $timestamp => $hooks ) { foreach ( $hooks as $name => $events ) { if ( $hook && $name !== $hook ) { continue; } foreach ( $events as $key => $event ) { $items[] = array( 'timestamp' => (int) $timestamp, 'hook' => $name, 'schedule' => $event['schedule'] ?? false, 'args' => $event['args'] ?? array(), 'key' => $key ); } } } return array( 'items' => $items, 'count' => count( $items ) ); }
		if ( 'run' === $action ) { if ( ! $hook ) { return new WP_Error( 'wpaib_cron_hook_required', 'hook obbligatorio.', array( 'status' => 400 ) ); } $callbacks = function_exists( 'has_action' ) ? (int) has_action( $hook ) : 0; if ( $callbacks < 1 ) { return new WP_Error( 'wpaib_cron_hook_unhandled', 'Nessun callback WordPress registrato per questo hook.', array( 'status' => 409, 'hook' => $hook ) ); } do_action_ref_array( $hook, (array) self::value( $args, 'args', array() ) ); return array( 'ran' => true, 'hook' => $hook, 'callbacks_registered' => $callbacks ); }
		if ( 'run_due' === $action ) { spawn_cron(); return array( 'spawned' => true ); }
		if ( 'schedule_single' === $action ) { $r = wp_schedule_single_event( (int) self::value( $args, 'timestamp', time() + 60 ), $hook, (array) self::value( $args, 'args', array() ), true ); return is_wp_error( $r ) ? $r : array( 'scheduled' => (bool) $r ); }
		if ( 'schedule_recurring' === $action || 'reschedule' === $action ) { $r = wp_schedule_event( (int) self::value( $args, 'timestamp', time() + 60 ), sanitize_key( (string) self::value( $args, 'recurrence', 'hourly' ) ), $hook, (array) self::value( $args, 'args', array() ), true ); return is_wp_error( $r ) ? $r : array( 'scheduled' => (bool) $r ); }
		if ( in_array( $action, array( 'unschedule', 'delete' ), true ) ) { return array( 'cleared' => wp_clear_scheduled_hook( $hook, (array) self::value( $args, 'args', array() ), true ) ); }
		if ( 'verify' === $action ) { return array( 'next_scheduled' => $hook ? wp_next_scheduled( $hook, (array) self::value( $args, 'args', array() ) ) : null ); }
		return null;
	}

	private static function control_logs( string $action, array $args ) {
		$path = (string) self::value( $args, 'path', 'wp-content/debug.log' );
		if ( 'list' === $action ) { $items = array(); foreach ( array( 'wp-content/debug.log', 'wp-content/woocommerce/logs' ) as $candidate ) { $result = WPAIB_Files::list_directory( $candidate ); if ( ! is_wp_error( $result ) ) { $items[] = $result; } } return array( 'items' => $items ); }
		if ( in_array( $action, array( 'read', 'tail', 'download' ), true ) ) { $length = max( 1, min( 1048576, (int) self::value( $args, 'length', 131072 ) ) ); return WPAIB_Files::read_file( $path, max( 0, (int) self::value( $args, 'offset', 0 ) ), $length ); }
		if ( 'search' === $action ) { return WPAIB_Files::search( (string) self::value( $args, 'search', '' ), dirname( $path ), array( 'log', 'txt' ), 0, max( 1, min( 300, (int) self::value( $args, 'limit', 100 ) ) ) ); }
		if ( in_array( $action, array( 'truncate', 'clear' ), true ) ) { $current = WPAIB_Files::read_file( $path, 0, 1 ); if ( is_wp_error( $current ) ) { return $current; } return WPAIB_Files::write_raw( $path, '', (string) ( $current['sha256'] ?? '' ) ); }
		if ( 'delete' === $action ) { $current = WPAIB_Files::read_file( $path, 0, 1 ); if ( is_wp_error( $current ) ) { return $current; } return WPAIB_Files::delete_file( $path, (string) ( $current['sha256'] ?? '' ) ); }
		if ( 'verify' === $action ) { return WPAIB_Files::read_file( $path, 0, 1 ); }
		return null;
	}

	private static function content_args( array $args ): array {
		$out = array(); foreach ( array( 'id', 'post_type', 'title', 'content', 'excerpt', 'slug', 'status', 'parent_id', 'menu_order', 'author_id', 'comment_status', 'ping_status', 'date', 'date_gmt', 'template', 'expected_modified_gmt' ) as $key ) { $value = self::value( $args, $key, null ); if ( null !== $value ) { $out[ $key ] = $value; } } return $out;
	}
	private static function control_content( string $action, array $args ) {
		$id = absint( self::value( $args, 'id', 0 ) );
		if ( in_array( $action, array( 'list', 'search' ), true ) ) { return WPAIB_Site::list_content( array( 'post_type' => (string) self::value( $args, 'post_type', 'page' ), 'status' => (string) self::value( $args, 'status', 'any' ), 'page' => max( 1, (int) self::value( $args, 'page', 1 ) ), 'per_page' => max( 1, min( 100, (int) self::value( $args, 'per_page', 20 ) ) ), 'search' => (string) self::value( $args, 'search', '' ) ) ); }
		if ( 'get' === $action || 'verify' === $action ) { return WPAIB_Site::get_content( $id ); }
		if ( in_array( $action, array( 'create', 'update', 'patch', 'autosave', 'set_excerpt', 'set_slug', 'set_parent', 'set_template', 'set_menu_order', 'set_comment_status', 'set_ping_status', 'set_author', 'set_publish_date', 'schedule_publish' ), true ) ) {
			$data = self::content_args( $args ); if ( $id ) { $data['id'] = $id; }
			if ( 'set_parent' === $action ) { $data['parent_id'] = absint( self::value( $args, 'parent_id', self::value( $args, 'parent', 0 ) ) ); }
			if ( 'set_template' === $action ) { $data['template'] = (string) self::value( $args, 'template', '' ); }
			if ( 'set_menu_order' === $action ) { $data['menu_order'] = absint( self::value( $args, 'menu_order', self::value( $args, 'position', 0 ) ) ); }
			if ( 'set_comment_status' === $action ) { $data['comment_status'] = (string) self::value( $args, 'comment_status', 'closed' ); }
			if ( 'set_ping_status' === $action ) { $data['ping_status'] = (string) self::value( $args, 'ping_status', 'closed' ); }
			if ( 'set_author' === $action ) { $data['author_id'] = absint( self::value( $args, 'author_id', self::value( $args, 'author', 0 ) ) ); }
			if ( in_array( $action, array( 'set_publish_date', 'schedule_publish' ), true ) ) { $data['date'] = (string) self::value( $args, 'date', self::value( $args, 'publish_date', '' ) ); if ( 'schedule_publish' === $action ) { $data['status'] = 'future'; } }
			return WPAIB_Site::update_content( $data );
		}
		if ( in_array( $action, array( 'publish', 'unpublish', 'trash', 'restore' ), true ) ) { $status = array( 'publish' => 'publish', 'unpublish' => 'draft', 'trash' => 'trash', 'restore' => 'draft' )[ $action ]; return WPAIB_Site::update_content( array( 'id' => $id, 'status' => $status ) ); }
		if ( 'delete' === $action ) { $deleted = wp_delete_post( $id, (bool) self::value( $args, 'force', false ) ); return $deleted ? array( 'deleted' => true, 'id' => $id ) : new WP_Error( 'wpaib_content_delete_failed', 'Eliminazione contenuto non riuscita.', array( 'status' => 500 ) ); }
		if ( 'duplicate' === $action || 'clone_to_draft' === $action ) { $post = get_post( $id ); if ( ! $post ) { return new WP_Error( 'wpaib_content_missing', 'Contenuto non trovato.', array( 'status' => 404 ) ); } return WPAIB_Site::update_content( array( 'post_type' => $post->post_type, 'title' => $post->post_title . ' (copia)', 'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'status' => 'draft' ) ); }
		if ( 'list_post_types' === $action ) { return array( 'items' => get_post_types( array(), 'objects' ) ); }
		if ( 'list_post_statuses' === $action ) { return array( 'items' => get_post_stati( array(), 'objects' ) ); }
		if ( 'list_revisions' === $action ) { return array( 'items' => array_values( wp_get_post_revisions( $id ) ) ); }
		if ( 'restore_revision' === $action ) { return array( 'post_id' => wp_restore_post_revision( absint( self::value( $args, 'revision_id', 0 ) ) ) ); }
		return null;
	}

	private static function control_taxonomy( string $action, array $args ) {
		$taxonomy = sanitize_key( (string) self::value( $args, 'taxonomy', '' ) );
		if ( 'list_taxonomies' === $action ) { return WPAIB_Enterprise::taxonomies( array( 'object_type' => (string) self::value( $args, 'object_type', '' ) ) ); }
		if ( in_array( $action, array( 'list', 'search' ), true ) ) { return WPAIB_Enterprise::terms( array( 'taxonomy' => $taxonomy, 'page' => max( 1, (int) self::value( $args, 'page', 1 ) ), 'per_page' => max( 1, min( 100, (int) self::value( $args, 'per_page', 50 ) ) ), 'search' => (string) self::value( $args, 'search', '' ), 'hide_empty' => (bool) self::value( $args, 'hide_empty', false ) ) ); }
		if ( 'get' === $action || 'verify' === $action ) { $term = get_term( absint( self::value( $args, 'id', 0 ) ), $taxonomy ); return is_wp_error( $term ) || ! $term ? new WP_Error( 'wpaib_term_missing', 'Termine non trovato.', array( 'status' => 404 ) ) : $term->to_array(); }
		if ( in_array( $action, array( 'create', 'update', 'patch', 'set_term_parent', 'set_term_slug', 'set_term_description', 'set_term_order' ), true ) ) { $data = array( 'taxonomy' => $taxonomy ); foreach ( array( 'id', 'name', 'slug', 'description', 'parent' ) as $key ) { $v = self::value( $args, $key, null ); if ( null !== $v ) { $data[ $key ] = $v; } } return WPAIB_Enterprise::upsert_term( $data ); }
		if ( in_array( $action, array( 'assign', 'unassign', 'replace_term_assignments' ), true ) ) { return WPAIB_Enterprise::assign_terms( array( 'object_id' => absint( self::value( $args, 'object_id', 0 ) ), 'taxonomy' => $taxonomy, 'term_ids' => 'unassign' === $action ? array() : array_map( 'absint', (array) self::value( $args, 'term_ids', array() ) ), 'append' => 'assign' === $action && (bool) self::value( $args, 'append', false ) ) ); }
		if ( in_array( $action, array( 'list_term_meta', 'get_term_meta' ), true ) ) { return WPAIB_Enterprise::get_object_meta( array( 'object_type' => 'term', 'object_id' => absint( self::value( $args, 'id', 0 ) ), 'keys' => (array) self::value( $args, 'keys', array() ) ) ); }
		if ( in_array( $action, array( 'set_term_meta', 'delete_term_meta' ), true ) ) { return WPAIB_Enterprise::update_object_meta( array( 'object_type' => 'term', 'object_id' => absint( self::value( $args, 'id', 0 ) ), 'key' => (string) self::value( $args, 'key', '' ), 'action' => 'delete_term_meta' === $action ? 'delete' : 'set', 'value' => self::value( $args, 'value', '' ), 'expected_before' => self::value( $args, 'expected_before', null ) ) ); }
		if ( 'delete' === $action ) { return wp_delete_term( absint( self::value( $args, 'id', 0 ) ), $taxonomy ); }
		return null;
	}

	private static function control_media( string $action, array $args ) {
		$id = absint( self::value( $args, 'id', self::value( $args, 'attachment_id', 0 ) ) );
		if ( 'list' === $action ) { return WPAIB_Enterprise::list_media( array( 'page' => max( 1, (int) self::value( $args, 'page', 1 ) ), 'per_page' => max( 1, min( 100, (int) self::value( $args, 'per_page', 50 ) ) ), 'search' => (string) self::value( $args, 'search', '' ), 'mime_type' => (string) self::value( $args, 'mime_type', 'image' ) ) ); }
		if ( 'get' === $action || 'verify' === $action ) { return WPAIB_Enterprise::get_media( array( 'id' => $id ) ); }
		if ( in_array( $action, array( 'update', 'set_alt_text' ), true ) ) { return WPAIB_Enterprise::update_media( array( 'id' => $id, 'title' => self::value( $args, 'title', null ), 'alt' => self::value( $args, 'alt', self::value( $args, 'alt_text', null ) ), 'caption' => self::value( $args, 'caption', null ), 'description' => self::value( $args, 'description', null ), 'expected_modified_gmt' => self::value( $args, 'expected_modified_gmt', null ) ) ); }
		if ( 'attach' === $action || 'detach' === $action ) { $r = wp_update_post( array( 'ID' => $id, 'post_parent' => 'attach' === $action ? absint( self::value( $args, 'post_id', 0 ) ) : 0 ), true ); return is_wp_error( $r ) ? $r : array( 'id' => $id, 'parent' => 'attach' === $action ? absint( self::value( $args, 'post_id', 0 ) ) : 0 ); }
		if ( in_array( $action, array( 'trash', 'restore' ), true ) ) { $r = 'trash' === $action ? wp_trash_post( $id ) : wp_untrash_post( $id ); return $r ? array( 'id' => $id, 'status' => get_post_status( $id ) ) : new WP_Error( 'wpaib_media_state_failed', 'Modifica stato media non riuscita.', array( 'status' => 500 ) ); }
		if ( 'delete' === $action ) { return wp_delete_attachment( $id, (bool) self::value( $args, 'force', true ) ) ? array( 'deleted' => true, 'id' => $id ) : new WP_Error( 'wpaib_media_delete_failed', 'Eliminazione media non riuscita.', array( 'status' => 500 ) ); }
		if ( 'regenerate_thumbnails' === $action || 'regenerate_metadata' === $action ) { return self::regenerate_thumbnails( $args ); }
		return null;
	}

	private static function control_comments( string $action, array $args ) {
		$id = absint( self::value( $args, 'id', 0 ) );
		if ( in_array( $action, array( 'list', 'search' ), true ) ) { $items = get_comments( array( 'number' => max( 1, min( 100, (int) self::value( $args, 'per_page', 50 ) ) ), 'status' => (string) self::value( $args, 'status', 'all' ), 'search' => (string) self::value( $args, 'search', '' ), 'post_id' => absint( self::value( $args, 'post_id', 0 ) ) ?: '' ) ); return array( 'items' => array_map( static function( $c ) { return get_comment( $c, ARRAY_A ); }, $items ) ); }
		if ( 'get' === $action || 'verify' === $action ) { $c = get_comment( $id, ARRAY_A ); return $c ?: new WP_Error( 'wpaib_comment_missing', 'Commento non trovato.', array( 'status' => 404 ) ); }
		if ( in_array( $action, array( 'create', 'reply' ), true ) ) { $data = (array) self::value( $args, 'body', array() ); $data['comment_post_ID'] = absint( self::value( $args, 'post_id', $data['comment_post_ID'] ?? 0 ) ); if ( 'reply' === $action ) { $data['comment_parent'] = $id; } $new = wp_insert_comment( wp_slash( $data ) ); return $new ? array( 'id' => $new ) : new WP_Error( 'wpaib_comment_create_failed', 'Creazione commento non riuscita.', array( 'status' => 500 ) ); }
		if ( 'update' === $action ) { $data = (array) self::value( $args, 'body', array() ); $data['comment_ID'] = $id; $r = wp_update_comment( wp_slash( $data ), true ); return is_wp_error( $r ) ? $r : array( 'updated' => (bool) $r ); }
		$status_map = array( 'approve' => 'approve', 'hold' => 'hold', 'spam' => 'spam', 'unspam' => 'approve', 'trash' => 'trash', 'restore' => 'approve' ); if ( isset( $status_map[ $action ] ) ) { return array( 'updated' => wp_set_comment_status( $id, $status_map[ $action ], true ) ); }
		if ( 'delete' === $action ) { return array( 'deleted' => wp_delete_comment( $id, (bool) self::value( $args, 'force', false ) ) ); }
		return null;
	}

	private static function control_users( string $action, array $args ) {
		$id = absint( self::value( $args, 'id', 0 ) );
		if ( in_array( $action, array( 'list', 'search' ), true ) ) { $users = get_users( array( 'number' => max( 1, min( 100, (int) self::value( $args, 'per_page', 50 ) ) ), 'search' => (string) self::value( $args, 'search', '' ) ? '*' . esc_attr( (string) self::value( $args, 'search', '' ) ) . '*' : '' ) ); return array( 'items' => array_map( static function( $u ) { return array( 'id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'display_name' => $u->display_name, 'roles' => $u->roles ); }, $users ) ); }
		if ( 'get' === $action || 'verify' === $action ) { $u = get_user_by( 'id', $id ); return $u ? array( 'id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'display_name' => $u->display_name, 'roles' => $u->roles ) : new WP_Error( 'wpaib_user_missing', 'Utente non trovato.', array( 'status' => 404 ) ); }
		if ( 'create' === $action ) { $new = wp_insert_user( array( 'user_login' => (string) self::value( $args, 'username', '' ), 'user_email' => (string) self::value( $args, 'email', '' ), 'user_pass' => (string) self::value( $args, 'password', wp_generate_password( 24, true, true ) ), 'display_name' => (string) self::value( $args, 'display_name', '' ), 'role' => (string) self::value( $args, 'role', get_option( 'default_role' ) ) ) ); return is_wp_error( $new ) ? $new : array( 'id' => $new ); }
		if ( 'update' === $action ) { $data = array( 'ID' => $id ); foreach ( array( 'user_email', 'display_name', 'first_name', 'last_name' ) as $field ) { $v = self::value( $args, $field, null ); if ( null !== $v ) { $data[ $field ] = $v; } } $r = wp_update_user( $data ); return is_wp_error( $r ) ? $r : array( 'id' => $r ); }
		if ( 'set_roles' === $action ) { $u = get_user_by( 'id', $id ); if ( ! $u ) { return new WP_Error( 'wpaib_user_missing', 'Utente non trovato.', array( 'status' => 404 ) ); } $roles = array_map( 'sanitize_key', (array) self::value( $args, 'roles', array() ) ); foreach ( $u->roles as $role ) { $u->remove_role( $role ); } foreach ( $roles as $role ) { $u->add_role( $role ); } return array( 'id' => $id, 'roles' => $u->roles ); }
		if ( 'reset_password' === $action ) { $password = (string) self::value( $args, 'password', '' ); if ( '' === $password ) { return new WP_Error( 'wpaib_password_required', 'password obbligatoria.', array( 'status' => 400 ) ); } wp_set_password( $password, $id ); return array( 'reset' => true, 'id' => $id ); }
		if ( 'list_sessions' === $action ) { return array( 'items' => WP_Session_Tokens::get_instance( $id )->get_all() ); }
		if ( 'revoke_sessions' === $action ) { WP_Session_Tokens::get_instance( $id )->destroy_all(); return array( 'revoked' => true ); }
		if ( 'delete' === $action || 'reassign_and_delete' === $action ) { require_once ABSPATH . 'wp-admin/includes/user.php'; return array( 'deleted' => wp_delete_user( $id, absint( self::value( $args, 'reassign_to', 0 ) ) ?: null ) ); }
		return null;
	}

	private static function control_settings( string $action, array $args ) {
		$option = sanitize_key( (string) self::value( $args, 'option', self::value( $args, 'key', '' ) ) );
		if ( 'list_registered' === $action ) { global $wp_registered_settings; return array( 'items' => array_keys( is_array( $wp_registered_settings ) ? $wp_registered_settings : array() ) ); }
		if ( 'get_settings' === $action ) { return array( 'site_title' => get_option( 'blogname' ), 'description' => get_option( 'blogdescription' ), 'timezone' => get_option( 'timezone_string' ), 'date_format' => get_option( 'date_format' ), 'permalink_structure' => get_option( 'permalink_structure' ) ); }
		if ( 'update_settings' === $action ) { $values = (array) self::value( $args, 'values', self::value( $args, 'body', array() ) ); $allowed = array( 'blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format', 'start_of_week', 'permalink_structure' ); $updated = array(); foreach ( $allowed as $key ) { if ( array_key_exists( $key, $values ) ) { update_option( $key, $values[ $key ] ); $updated[ $key ] = get_option( $key ); } } return array( 'updated' => $updated ); }
		if ( 'get_option' === $action ) { return array( 'option' => $option, 'value' => self::redact( get_option( $option, null ) ) ); }
		if ( in_array( $action, array( 'update_option', 'add_option' ), true ) ) {
			if ( self::sensitive_key( $option ) ) { return new WP_Error( 'wpaib_option_sensitive', 'Opzione sensibile non consentita.', array( 'status' => 403 ) ); }
			$value = self::value( $args, 'value', null );
			$before = get_option( $option, null );
			$changed = 'add_option' === $action
				? add_option( $option, $value, '', (bool) self::value( $args, 'autoload', false ) )
				: update_option( $option, $value, (bool) self::value( $args, 'autoload', false ) );
			// WordPress updates the option cache as part of the write. This readback is
			// therefore normally cache-local and proves the requested effect without
			// launching a second workflow. update_option() legitimately returns false
			// when the value is already identical, so do not misclassify a no-op as a
			// failed write.
			$after = get_option( $option, null );
			$verified = wp_json_encode( $after ) === wp_json_encode( $value );
			return array(
				'stored' => $verified, 'changed' => (bool) $changed, 'verified' => $verified,
				'option' => $option, 'before' => self::redact( $before ), 'after' => self::redact( $after ),
			);
		}
		if ( 'delete_option' === $action ) { if ( self::sensitive_key( $option ) ) { return new WP_Error( 'wpaib_option_sensitive', 'Opzione sensibile non consentita.', array( 'status' => 403 ) ); } $changed = delete_option( $option ); return array( 'deleted' => $changed, 'verified' => null === get_option( $option, null ) ); }
		if ( 'bulk_get' === $action ) {
			$keys = array();
			foreach ( (array) self::value( $args, 'options', array() ) as $key ) { $key = sanitize_key( (string) $key ); if ( '' !== $key && ! self::sensitive_key( $key ) ) { $keys[] = $key; } }
			$keys = array_values( array_unique( $keys ) );
			// Since WordPress 6.4 this primes all missing option values in one SQL
			// query. The subsequent get_option() calls are cache reads, not N queries.
			if ( $keys && function_exists( 'wp_prime_option_caches' ) ) { wp_prime_option_caches( $keys ); }
			$out = array(); foreach ( $keys as $key ) { $out[ $key ] = self::redact( get_option( $key, null ) ); }
			return array( 'values' => $out, 'count' => count( $out ), 'cache_primed' => $keys && function_exists( 'wp_prime_option_caches' ) );
		}
		if ( 'bulk_update' === $action ) {
			$values = (array) self::value( $args, 'values', array() ); $keys = array();
			foreach ( array_keys( $values ) as $key ) { $key = sanitize_key( (string) $key ); if ( '' !== $key && ! self::sensitive_key( $key ) ) { $keys[] = $key; } }
			$keys = array_values( array_unique( $keys ) );
			if ( $keys && function_exists( 'wp_prime_option_caches' ) ) { wp_prime_option_caches( $keys ); }
			$out = array();
			foreach ( $keys as $key ) { $requested = $values[ $key ] ?? null; $before = get_option( $key, null ); $changed = update_option( $key, $requested, false ); $after = get_option( $key, null ); $out[ $key ] = array( 'changed' => (bool) $changed, 'verified' => wp_json_encode( $after ) === wp_json_encode( $requested ), 'before' => self::redact( $before ), 'after' => self::redact( $after ) ); }
			$verified = ! array_filter( $out, static fn( $row ) => empty( $row['verified'] ) );
			return array( 'updated' => $out, 'count' => count( $out ), 'verified' => $verified );
		}
		if ( 'verify' === $action ) { return array( 'option' => $option, 'exists' => false !== get_option( $option, false ) ); }
		return null;
	}

	private static function control_plugins( string $action, array $args ) {
		$plugin = (string) self::value( $args, 'plugin', '' );
		if ( 'list' === $action ) { return WPAIB_Site::plugins(); }
		if ( 'get' === $action || 'verify' === $action ) { $items = WPAIB_Site::plugins()['items'] ?? array(); foreach ( $items as $item ) { if ( ( $item['plugin'] ?? '' ) === $plugin || ( $item['slug'] ?? '' ) === self::value( $args, 'slug', '' ) ) { return $item; } } return new WP_Error( 'wpaib_plugin_missing', 'Plugin non trovato.', array( 'status' => 404 ) ); }
		if ( in_array( $action, array( 'activate', 'deactivate' ), true ) ) { return WPAIB_Site::set_plugin_state( $plugin, $action ); }
		if ( 'read_plugin_file' === $action ) { return WPAIB_Files::read_file( 'wp-content/plugins/' . ltrim( (string) self::value( $args, 'path', '' ), '/' ), 0, (int) self::value( $args, 'length', 1048576 ) ); }
		if ( in_array( $action, array( 'create_plugin_file', 'write_plugin_file' ), true ) ) { $path = 'wp-content/plugins/' . ltrim( (string) self::value( $args, 'path', '' ), '/' ); $content = (string) self::value( $args, 'content', '' ); return WPAIB_Files::write_raw( $path, $content, self::value( $args, 'expected_sha256', null ) ); }
		if ( 'delete_plugin_file' === $action ) { return WPAIB_Files::delete_file( 'wp-content/plugins/' . ltrim( (string) self::value( $args, 'path', '' ), '/' ), (string) self::value( $args, 'expected_sha256', '' ) ); }
		return null;
	}

	private static function control_themes( string $action, array $args ) {
		if ( 'list' === $action ) { return WPAIB_Site::themes(); }
		if ( 'activate' === $action ) { return WPAIB_Site::switch_theme( (string) self::value( $args, 'stylesheet', self::value( $args, 'slug', '' ) ) ); }
		if ( 'get' === $action || 'verify' === $action ) { $stylesheet = (string) self::value( $args, 'stylesheet', self::value( $args, 'slug', '' ) ); $theme = wp_get_theme( $stylesheet ); return $theme->exists() ? array( 'stylesheet' => $theme->get_stylesheet(), 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'active' => get_stylesheet() === $theme->get_stylesheet() ) : new WP_Error( 'wpaib_theme_missing', 'Tema non trovato.', array( 'status' => 404 ) ); }
		if ( 'read_theme_file' === $action ) { return WPAIB_Files::read_file( 'wp-content/themes/' . ltrim( (string) self::value( $args, 'path', '' ), '/' ), 0, (int) self::value( $args, 'length', 1048576 ) ); }
		if ( in_array( $action, array( 'create_theme_file', 'write_theme_file' ), true ) ) { $path = 'wp-content/themes/' . ltrim( (string) self::value( $args, 'path', '' ), '/' ); return WPAIB_Files::write_raw( $path, (string) self::value( $args, 'content', '' ), self::value( $args, 'expected_sha256', null ) ); }
		if ( 'delete_theme_file' === $action ) { return WPAIB_Files::delete_file( 'wp-content/themes/' . ltrim( (string) self::value( $args, 'path', '' ), '/' ), (string) self::value( $args, 'expected_sha256', '' ) ); }
		return null;
	}

	private static function product_payload( array $args ): array { $out = array( 'id' => absint( self::value( $args, 'id', self::value( $args, 'product_id', 0 ) ) ) ); foreach ( array( 'name', 'slug', 'status', 'catalog_visibility', 'description', 'short_description', 'sku', 'global_unique_id', 'regular_price', 'sale_price', 'stock_status', 'backorders', 'weight', 'length', 'width', 'height', 'tax_status', 'tax_class' ) as $key ) { $v = self::value( $args, $key, null ); if ( null !== $v ) { $out[ $key ] = $v; } } foreach ( array( 'featured', 'manage_stock', 'sold_individually' ) as $key ) { $v = self::value( $args, $key, null ); if ( null !== $v ) { $out[ $key ] = (bool) $v; } } foreach ( array( 'stock_quantity', 'shipping_class_id', 'menu_order', 'image_id' ) as $key ) { $v = self::value( $args, $key, null ); if ( null !== $v ) { $out[ $key ] = $v; } } foreach ( array( 'category_ids', 'tag_ids', 'gallery_image_ids', 'attributes' ) as $key ) { $v = self::value( $args, $key, null ); if ( is_array( $v ) ) { $out[ $key ] = $v; } } return $out; }
	private static function control_products( string $action, array $args ) {
		$id = absint( self::value( $args, 'id', self::value( $args, 'product_id', 0 ) ) );
		if ( 'generate_product_feed' === $action ) {
			if ( ! class_exists( 'WPAIB_AdTribes' ) || ! is_callable( array( 'WPAIB_AdTribes', 'refresh_native' ) ) ) {
				return new WP_Error( 'wpaib_adtribes_bridge_unavailable', 'AdTribes bridge non disponibile.', array( 'status' => 503 ) );
			}
			return WPAIB_AdTribes::refresh_native( $args );
		}
		if ( 'list' === $action ) { return WPAIB_Enterprise::list_products( array( 'page' => max( 1, (int) self::value( $args, 'page', 1 ) ), 'per_page' => max( 1, min( 100, (int) self::value( $args, 'per_page', 50 ) ) ), 'search' => (string) self::value( $args, 'search', '' ), 'status' => (string) self::value( $args, 'status', '' ), 'sku' => (string) self::value( $args, 'sku', '' ) ) ); }
		if ( 'get' === $action || 'verify' === $action ) { $ids = (array) self::value( $args, 'ids', self::value( $args, 'product_ids', array() ) ); return $ids ? WPAIB_Enterprise::get_products_batch( array( 'ids' => $ids ) ) : WPAIB_Enterprise::get_product( array( 'id' => $id ) ); }
		if ( 'manage_attributes' === $action ) { return WPAIB_Enterprise::manage_product_attributes( $args ); }
		if ( in_array( $action, array( 'update', 'patch', 'publish', 'unpublish', 'trash', 'restore', 'set_product_seo', 'set_product_brand', 'set_product_schema' ), true ) ) { $payload = self::product_payload( $args ); if ( 'publish' === $action ) { $payload['status'] = 'publish'; } if ( 'unpublish' === $action ) { $payload['status'] = 'draft'; } if ( 'trash' === $action ) { $payload['status'] = 'trash'; } if ( 'restore' === $action ) { $payload['status'] = 'draft'; } return WPAIB_Enterprise::update_product( $payload ); }
		if ( 'create' === $action ) { if ( ! function_exists( 'wc_get_product_object' ) ) { return new WP_Error( 'wpaib_woocommerce_unavailable', 'WooCommerce non disponibile.', array( 'status' => 503 ) ); } $product = wc_get_product_object( 'simple' ); $payload = self::product_payload( $args ); foreach ( array( 'name' => 'set_name', 'status' => 'set_status', 'description' => 'set_description', 'short_description' => 'set_short_description', 'sku' => 'set_sku', 'regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price' ) as $key => $method ) { if ( isset( $payload[ $key ] ) ) { $product->{$method}( (string) $payload[ $key ] ); } } $new_id = $product->save(); return array( 'id' => $new_id ); }
		if ( 'delete' === $action ) { $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null; if ( ! $product ) { return new WP_Error( 'wpaib_product_missing', 'Prodotto non trovato.', array( 'status' => 404 ) ); } return array( 'deleted' => (bool) $product->delete( (bool) self::value( $args, 'force', false ) ) ); }
		return null;
	}

	private static function control_inventory( string $action, array $args ) {
		$id = absint( self::value( $args, 'product_id', self::value( $args, 'id', 0 ) ) );
		if ( 'get' === $action || 'verify' === $action ) { return WPAIB_Enterprise::get_product( array( 'id' => $id ) ); }
		if ( in_array( $action, array( 'set_stock', 'adjust_stock', 'set_stock_status', 'set_price', 'set_sale', 'clear_sale' ), true ) ) { $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null; if ( ! $product ) { return new WP_Error( 'wpaib_product_missing', 'Prodotto non trovato.', array( 'status' => 404 ) ); } if ( 'set_stock' === $action ) { $product->set_manage_stock( true ); $product->set_stock_quantity( (int) self::value( $args, 'quantity', 0 ) ); } if ( 'adjust_stock' === $action ) { $product->set_manage_stock( true ); $product->set_stock_quantity( (int) $product->get_stock_quantity() + (int) self::value( $args, 'adjustment', 0 ) ); } if ( 'set_stock_status' === $action ) { $product->set_stock_status( (string) self::value( $args, 'stock_status', 'instock' ) ); } if ( 'set_price' === $action ) { $product->set_regular_price( (string) self::value( $args, 'regular_price', '' ) ); } if ( 'set_sale' === $action ) { $product->set_sale_price( (string) self::value( $args, 'sale_price', '' ) ); } if ( 'clear_sale' === $action ) { $product->set_sale_price( '' ); } $product->save(); return WPAIB_Enterprise::get_product( array( 'id' => $id ) ); }
		return null;
	}

	private static function control_orders( string $action, array $args ) {
		$id = absint( self::value( $args, 'order_id', self::value( $args, 'id', 0 ) ) );
		if ( 'list' === $action ) { return WPAIB_Enterprise::list_orders( array( 'page' => max( 1, (int) self::value( $args, 'page', 1 ) ), 'per_page' => max( 1, min( 100, (int) self::value( $args, 'per_page', 50 ) ) ), 'status' => self::value( $args, 'status', '' ) ) ); }
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		if ( in_array( $action, array( 'get', 'verify' ), true ) ) { return $order ? array( 'id' => $order->get_id(), 'status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency(), 'items' => array_values( $order->get_items() ) ) : new WP_Error( 'wpaib_order_missing', 'Ordine non trovato.', array( 'status' => 404 ) ); }
		if ( ! $order ) { return null; }
		if ( 'set_status' === $action || 'update' === $action ) { $order->update_status( (string) self::value( $args, 'status', $order->get_status() ), (string) self::value( $args, 'note', '' ), true ); return array( 'id' => $id, 'status' => $order->get_status() ); }
		if ( 'add_note' === $action ) { return array( 'note_id' => $order->add_order_note( (string) self::value( $args, 'note', '' ), (bool) self::value( $args, 'customer_note', false ), true ) ); }
		if ( 'trash' === $action ) { return array( 'trashed' => wp_trash_post( $id ) !== false ); }
		if ( 'restore' === $action ) { return array( 'restored' => wp_untrash_post( $id ) !== false ); }
		if ( 'delete' === $action ) { return array( 'deleted' => (bool) $order->delete( (bool) self::value( $args, 'force', false ) ) ); }
		return null;
	}

	private static function control_seo( string $action, array $args ) {
		$id = absint( self::value( $args, 'object_id', self::value( $args, 'id', 0 ) ) );
		$type = sanitize_key( (string) self::value( $args, 'object_type', 'post' ) );
		if ( 'capabilities' === $action ) { $enterprise = WPAIB_Enterprise::status(); return array( 'rank_math' => defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\Helper' ), 'search_console_provider' => $enterprise['features']['search_console_provider'] ?? 'prstudio-browser-agent-same-profile', 'google_search_console_connected' => $enterprise['features']['google_search_console_connected'] ?? false, 'google_login_managed_by_user' => true, 'search_console_status' => WPAIB_Enterprise::search_console_status(), 'supported_meta_prefixes' => array( 'rank_math_', '_yoast_wpseo_', '_aioseo_' ) ); }
		if ( in_array( $action, array( 'get_metadata', 'verify' ), true ) ) { return WPAIB_Enterprise::get_object_meta( array( 'object_type' => $type, 'object_id' => $id, 'keys' => (array) self::value( $args, 'keys', array() ) ) ); }
		$map = array( 'set_title' => 'rank_math_title', 'set_description' => 'rank_math_description', 'set_canonical' => 'rank_math_canonical_url', 'set_robots' => 'rank_math_robots', 'set_open_graph' => 'rank_math_facebook_title', 'set_twitter_cards' => 'rank_math_twitter_title', 'set_schema' => 'rank_math_schema', 'set_product_schema' => 'rank_math_rich_snippet', 'assign_primary_keyword' => 'rank_math_focus_keyword' );
		if ( isset( $map[ $action ] ) ) { $value_key = array( 'set_title' => 'title', 'set_description' => 'description', 'set_canonical' => 'canonical', 'set_robots' => 'robots', 'set_schema' => 'schema', 'set_product_schema' => 'schema', 'assign_primary_keyword' => 'keyword' )[ $action ] ?? 'value'; return WPAIB_Enterprise::update_object_meta( array( 'object_type' => $type, 'object_id' => $id, 'key' => $map[ $action ], 'action' => 'set', 'value' => self::value( $args, $value_key, self::value( $args, 'value', '' ) ), 'expected_before' => self::value( $args, 'expected_before', null ) ) ); }
		if ( 'set_image_alt' === $action ) { return WPAIB_Enterprise::update_object_meta( array( 'object_type' => 'post', 'object_id' => $id, 'key' => '_wp_attachment_image_alt', 'action' => 'set', 'value' => (string) self::value( $args, 'alt', '' ) ) ); }
		if ( 'set_taxonomy_metadata' === $action ) { return WPAIB_Enterprise::update_object_meta( array( 'object_type' => 'term', 'object_id' => $id, 'key' => (string) self::value( $args, 'key', 'rank_math_title' ), 'action' => 'set', 'value' => self::value( $args, 'value', '' ) ) ); }
		if ( 'get_redirects' === $action ) { $args['action'] = 'list'; return WPAIB_Enterprise::rank_math_redirects( $args ); }
		if ( 'create_redirect' === $action ) { $args['action'] = 'create'; return WPAIB_Enterprise::rank_math_redirects( $args ); }
		if ( 'update_redirect' === $action ) { $args['action'] = 'update'; return WPAIB_Enterprise::rank_math_redirects( $args ); }
		if ( 'delete_redirect' === $action ) { $args['action'] = 'delete'; return WPAIB_Enterprise::rank_math_redirects( $args ); }
		if ( 'regenerate_sitemap' === $action ) {
			if ( $id ) { return WPAIB_Enterprise::rank_math_sitemap_invalidate( array( 'object_type' => $type, 'object_id' => $id ) ); }
			do_action( 'rank_math/sitemap/invalidate_storage' );
			if ( class_exists( '\\RankMath\\Sitemap\\Cache_Watcher' ) ) { \RankMath\Sitemap\Cache_Watcher::clear(); \RankMath\Sitemap\Cache_Watcher::clear_queued(); }
			return array( 'invalidated' => true, 'provider' => 'rank_math', 'scope' => 'all' );
		}
		if ( 'build_keyword_map' === $action && class_exists( 'PRSTUDIO_UC_SEO_Intelligence' ) ) { return PRSTUDIO_UC_SEO_Intelligence::build_keyword_map( $args ); }
		if ( 'audit_product_seo' === $action && class_exists( 'PRSTUDIO_UC_SEO_Intelligence' ) ) { return PRSTUDIO_UC_SEO_Intelligence::audit_product_seo( $args ); }
		if ( 'audit_url' === $action ) { $url = (string) self::value( $args, 'url', '/' ); $page = WPAIB_Site::fetch_page( $url ); if ( is_wp_error( $page ) ) { return $page; } $html = (string) ( $page['html'] ?? '' ); preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $title ); preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)/is', $html, $description ); preg_match_all( '/<h1\b[^>]*>/i', $html, $h1 ); preg_match_all( '/<img\b(?![^>]*\balt=)[^>]*>/i', $html, $missing_alt ); return array( 'url' => $page['url'], 'http_status' => $page['status'], 'audit_scope' => 'single_url_basic_html', 'checks' => array( 'title' => isset( $title[1] ) ? wp_strip_all_tags( $title[1] ) : '', 'description' => $description[1] ?? '', 'h1_count' => count( $h1[0] ), 'images_missing_alt' => count( $missing_alt[0] ), 'html_bytes' => strlen( $html ) ), 'apply_fixes' => false ); }
		if ( 'audit_orphan_pages' === $action && class_exists( 'PRSTUDIO_UC_Public_Crawl' ) ) { return PRSTUDIO_UC_Public_Crawl::audit_orphan_pages( $args ); }
		if ( 'audit_sitemap_coverage' === $action && class_exists( 'PRSTUDIO_UC_Public_Crawl' ) ) { return PRSTUDIO_UC_Public_Crawl::audit_sitemap_coverage( $args ); }
		if ( 'build_internal_link_graph' === $action && class_exists( 'PRSTUDIO_UC_Public_Crawl' ) ) { return PRSTUDIO_UC_Public_Crawl::build_internal_link_graph( $args ); }
		if ( 'audit_http_statuses' === $action && class_exists( 'PRSTUDIO_UC_Public_Crawl' ) ) { return PRSTUDIO_UC_Public_Crawl::audit_http_statuses( $args ); }
		if ( 'audit_broken_internal_links' === $action && class_exists( 'PRSTUDIO_UC_Public_Crawl' ) ) { return PRSTUDIO_UC_Public_Crawl::audit_broken_internal_links( $args ); }
		if ( 'get_sitemap' === $action && class_exists( 'PRSTUDIO_UC_Public_Crawl' ) ) { return PRSTUDIO_UC_Public_Crawl::sitemap( $args ); }
		return null;
	}

	private static function control_files( string $action, array $args ) {
		$path = (string) self::value( $args, 'path', '' );
		if ( 'capabilities' === $action ) { return array( 'max_file_bytes' => WPAIB_Auth::settings()['max_file_bytes'], 'backup_root' => str_replace( ABSPATH, '', WPAIB_Files::backup_root() ), 'write_enabled' => true ); }
		if ( in_array( $action, array( 'list', 'tree' ), true ) ) { return 'tree' === $action ? WPAIB_Files::manifest( $path, (int) self::value( $args, 'cursor', 0 ), (int) self::value( $args, 'limit', 300 ), true ) : WPAIB_Files::list_directory( $path ); }
		if ( in_array( $action, array( 'read', 'read_range', 'download', 'stat', 'hash', 'verify' ), true ) ) { return WPAIB_Files::read_file( $path, (int) self::value( $args, 'offset', 0 ), (int) self::value( $args, 'length', 1048576 ) ); }
		if ( in_array( $action, array( 'search_content', 'search_name' ), true ) ) { return WPAIB_Files::search( (string) self::value( $args, 'search', '' ), $path, (array) self::value( $args, 'extensions', array() ), (int) self::value( $args, 'cursor', 0 ), (int) self::value( $args, 'limit', 100 ) ); }
		if ( in_array( $action, array( 'create_file', 'write_file', 'upload_base64' ), true ) ) { $b64 = 'upload_base64' === $action ? (string) self::value( $args, 'content_b64', '' ) : base64_encode( (string) self::value( $args, 'content', '' ) ); return 'upload_base64' === $action ? WPAIB_Files::write_file( $path, $b64, self::value( $args, 'expected_sha256', null ) ) : WPAIB_Files::write_raw( $path, (string) self::value( $args, 'content', '' ), self::value( $args, 'expected_sha256', null ) ); }
		if ( in_array( $action, array( 'replace_text', 'patch_file' ), true ) ) { return WPAIB_Files::patch_exact( $path, (string) self::value( $args, 'expected_sha256', '' ), (string) self::value( $args, 'search', '' ), (string) self::value( $args, 'replace', '' ), (int) self::value( $args, 'expected_replacements', 1 ), (string) self::value( $args, 'search_sha256', '' ), (array) self::value( $args, 'health_checks', array() ) ); }
		if ( 'append_file' === $action ) { return WPAIB_Files::append_file( $path, (string) self::value( $args, 'content', '' ), (string) self::value( $args, 'expected_sha256', '' ) ); }
		if ( 'truncate_file' === $action ) { return WPAIB_Files::truncate_file( $path, (string) self::value( $args, 'expected_sha256', '' ) ); }
		if ( 'delete' === $action ) { return WPAIB_Files::delete_file( $path, (string) self::value( $args, 'expected_sha256', '' ) ); }
		if ( 'validate_php_syntax' === $action ) { return WPAIB_Files::validate_file( $path, 'php' ); }
		if ( 'validate_json_files' === $action ) { return WPAIB_Files::validate_file( $path, 'json' ); }
		if ( 'hash_tree' === $action ) { return WPAIB_Files::manifest( $path, (int) self::value( $args, 'cursor', 0 ), (int) self::value( $args, 'limit', 300 ), true ); }
		return null;
	}

	private static function control_database( string $action, array $args ) {
		if ( ! class_exists( 'PRSTUDIO_UC_Database_Backend' ) ) {
			return new WP_Error(
				'prstudio_database_backend_unavailable',
				'Backend database PR STUDIO non caricato: l’azione non viene inoltrata a continuation o simulata.',
				array( 'status' => 503, 'action' => $action )
			);
		}
		return PRSTUDIO_UC_Database_Backend::execute( $action, $args );
	}

	private static function control_maintenance( string $action, array $args ) {
		if ( 'status' === $action ) { return array( 'maintenance_file' => file_exists( trailingslashit( ABSPATH ) . '.maintenance' ), 'updates' => array( 'core' => get_site_transient( 'update_core' ), 'plugins' => get_site_transient( 'update_plugins' ), 'themes' => get_site_transient( 'update_themes' ) ) ); }
		if ( 'list_updates' === $action ) { wp_version_check(); wp_update_plugins(); wp_update_themes(); return self::control_maintenance( 'status', $args ); }
		if ( 'enable_maintenance' === $action || 'disable_maintenance' === $action ) { return self::control_system( $action, $args ); }
		if ( 'flush_rewrites' === $action ) { flush_rewrite_rules( false ); return array( 'flushed' => true ); }
		if ( 'optimize_database' === $action ) { return self::control_database( 'optimize', $args ); }
		if ( 'repair_database' === $action ) { return self::control_database( 'repair', $args ); }
		if ( 'regenerate_thumbnails' === $action ) { return self::regenerate_thumbnails( $args ); }
		if ( in_array( $action, array( 'run_integrity_check', 'verify', 'run_preflight_checks', 'run_postflight_checks', 'run_smoke_tests' ), true ) ) { return array( 'site' => WPAIB_Site::status(), 'enterprise' => WPAIB_Enterprise::status(), 'mcp' => WPAIB_MCP::schema_diagnostics() ); }
		return null;
	}

	private static function regenerate_thumbnails( array $args ) {
		$ids = array_filter( array_map( 'absint', (array) self::value( $args, 'ids', array() ) ) );
		$single = absint( self::value( $args, 'id', self::value( $args, 'attachment_id', 0 ) ) );
		if ( $single ) { $ids[] = $single; }
		$ids = array_values( array_unique( $ids ) );
		$page = max( 1, (int) self::value( $args, 'page', 1 ) );
		$per_page = max( 1, min( 100, (int) self::value( $args, 'per_page', 25 ) ) );
		if ( ! $ids ) {
			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'fields'         => 'ids',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			) );
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
		$results = array();
		foreach ( $ids as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! is_file( $file ) ) {
				$results[] = array( 'id' => $attachment_id, 'regenerated' => false, 'error' => 'attachment_file_missing' );
				continue;
			}
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( ! is_array( $metadata ) || ! $metadata ) {
				$results[] = array( 'id' => $attachment_id, 'regenerated' => false, 'error' => 'metadata_generation_failed' );
				continue;
			}
			wp_update_attachment_metadata( $attachment_id, $metadata );
			$stored = wp_get_attachment_metadata( $attachment_id );
			$results[] = array( 'id' => $attachment_id, 'regenerated' => is_array( $stored ) && ! empty( $stored ), 'sizes' => array_keys( (array) ( $metadata['sizes'] ?? array() ) ) );
		}
		$processed = count( $results );
		$succeeded = count( array_filter( $results, static function( $item ) { return ! empty( $item['regenerated'] ); } ) );
		return array( 'provider' => 'wordpress_core', 'processed' => $processed, 'succeeded' => $succeeded, 'failed' => $processed - $succeeded, 'page' => $page, 'per_page' => $per_page, 'next_page' => $processed === $per_page ? $page + 1 : null, 'items' => $results );
	}

	private static function control_frontend( string $action, array $args ) {
		$url = (string) self::value( $args, 'url', '/' );
		if ( in_array( $action, array( 'fetch', 'inspect', 'headers', 'dom_snapshot', 'verify_url' ), true ) ) { $page = WPAIB_Site::fetch_page( $url ); if ( is_wp_error( $page ) ) { return $page; } if ( 'headers' === $action ) { return array( 'url' => $page['url'], 'status' => $page['status'], 'headers' => $page['headers'] ?? array() ); } return $page; }
		if ( 'html_diff' === $action ) { $page = WPAIB_Site::fetch_page( $url ); if ( is_wp_error( $page ) ) { return $page; } $current = (string) ( $page['html'] ?? '' ); $expected = (string) self::value( $args, 'expected_html', '' ); $expected_hash = (string) self::value( $args, 'expected_sha256', '' ); return array( 'url' => $page['url'], 'current_sha256' => hash( 'sha256', $current ), 'expected_sha256' => $expected_hash ?: ( $expected ? hash( 'sha256', $expected ) : null ), 'matches' => $expected_hash ? hash_equals( $expected_hash, hash( 'sha256', $current ) ) : ( $expected ? $expected === $current : null ), 'current_bytes' => strlen( $current ), 'expected_bytes' => $expected ? strlen( $expected ) : null ); }
		if ( in_array( $action, array( 'set_custom_css', 'append_custom_css' ), true ) ) { $css = (string) self::value( $args, 'css', '' ); if ( 'append_custom_css' === $action ) { $css = wp_get_custom_css() . "\n" . $css; } $r = wp_update_custom_css_post( $css ); return is_wp_error( $r ) ? $r : array( 'post_id' => $r->ID, 'sha256' => hash( 'sha256', $css ) ); }
		if ( 'purge_cache' === $action ) { return self::control_cache( 'flush_all', $args ); }
		if ( 0 === strpos( $action, 'playwright_' ) || in_array( $action, array( 'screenshot', 'create_visual_baseline', 'visual_diff', 'accessibility_tree', 'network_log', 'console_log' ), true ) ) { return null; }
		return null;
	}

	private static function control_security( string $action, array $args ) {
		if ( 'audit' === $action || 'verify' === $action ) { return array( 'admins' => self::control_security( 'list_admins', $args ), 'sessions' => self::control_security( 'list_sessions', $args ), 'file_scan' => PRSTUDIO_Agency::dispatch( 'security_scan', array( 'payload' => array( 'path' => (string) self::value( $args, 'path', 'wp-content' ) ), 'execution_mode' => 'run' ) ) ); }
		if ( 'list_admins' === $action ) { $users = get_users( array( 'role' => 'administrator' ) ); return array( 'items' => array_map( static function( $u ) { return array( 'id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email ); }, $users ) ); }
		if ( 'list_sessions' === $action ) { $items = array(); foreach ( get_users( array( 'fields' => 'ID' ) ) as $id ) { $tokens = WP_Session_Tokens::get_instance( (int) $id )->get_all(); if ( $tokens ) { $items[ $id ] = $tokens; } } return array( 'items' => $items ); }
		if ( 'revoke_sessions' === $action ) { $id = absint( self::value( $args, 'user_id', 0 ) ); WP_Session_Tokens::get_instance( $id )->destroy_all(); return array( 'revoked' => true, 'user_id' => $id ); }
		if ( 'rotate_bridge_key' === $action ) { return new WP_Error( 'wpaib_admin_ui_required', 'La rotazione della chiave deve essere eseguita dal pannello WordPress, perché il bridge non restituisce segreti tramite API o MCP.', array( 'status' => 403 ) ); }
		if ( 'check_permissions' === $action ) { $path = ABSPATH . ltrim( (string) self::value( $args, 'path', '' ), '/' ); return file_exists( $path ) ? array( 'path' => str_replace( ABSPATH, '', $path ), 'permissions' => substr( sprintf( '%o', fileperms( $path ) ), -4 ), 'writable' => is_writable( $path ) ) : new WP_Error( 'wpaib_path_missing', 'Percorso non trovato.', array( 'status' => 404 ) ); }
		if ( 'scan_malware' === $action || 'check_file_integrity' === $action ) { return PRSTUDIO_Agency::dispatch( 'security_scan', array( 'payload' => array( 'path' => (string) self::value( $args, 'path', 'wp-content' ) ), 'execution_mode' => 'run' ) ); }
		if ( 'get_hardening' === $action ) { return array( 'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT, 'force_ssl_admin' => force_ssl_admin(), 'xmlrpc_enabled' => apply_filters( 'xmlrpc_enabled', true ) ); }
		if ( 'verify_backup_recoverability' === $action ) { return self::control_backup( 'list', $args ); }
		return null;
	}

	private static function internal_rest( string $method, string $route, array $args, array $body = array() ) {
		$query = self::value( $args, 'query', array() );
		$provided_body = self::value( $args, 'body', array() );
		if ( is_array( $provided_body ) ) { $body = array_replace_recursive( $provided_body, $body ); }
		return self::proxy_internal_rest(
			$method,
			$route,
			array(
				'query' => is_array( $query ) ? $query : array(),
				'body'  => $body,
			)
		);
	}

	private static function control_menus( string $action, array $args ) {
		$menu_id = absint( self::value( $args, 'menu_id', self::value( $args, 'id', 0 ) ) );
		$item_id = absint( self::value( $args, 'item_id', 0 ) );
		if ( 'list_menus' === $action ) { return array( 'items' => array_map( 'get_object_vars', wp_get_nav_menus() ) ); }
		if ( in_array( $action, array( 'get_menu', 'verify' ), true ) ) {
			$menu = wp_get_nav_menu_object( $menu_id ?: (string) self::value( $args, 'slug', self::value( $args, 'name', '' ) ) );
			return $menu ? array( 'menu' => get_object_vars( $menu ), 'items' => wp_get_nav_menu_items( $menu->term_id ) ?: array() ) : new WP_Error( 'wpaib_menu_missing', 'Menu non trovato.', array( 'status' => 404 ) );
		}
		if ( 'create_menu' === $action ) {
			$name = sanitize_text_field( (string) self::value( $args, 'name', '' ) );
			if ( '' === $name ) { return new WP_Error( 'wpaib_menu_name_required', 'Nome menu obbligatorio.', array( 'status' => 400 ) ); }
			$id = wp_create_nav_menu( $name );
			return is_wp_error( $id ) ? $id : self::control_menus( 'get_menu', array( 'menu_id' => $id ) );
		}
		if ( 'update_menu' === $action ) {
			$result = wp_update_nav_menu_object( $menu_id, array( 'menu-name' => sanitize_text_field( (string) self::value( $args, 'name', '' ) ), 'description' => sanitize_text_field( (string) self::value( $args, 'description', '' ) ) ) );
			return is_wp_error( $result ) ? $result : self::control_menus( 'get_menu', array( 'menu_id' => $result ) );
		}
		if ( 'delete_menu' === $action ) { return array( 'menu_id' => $menu_id, 'deleted' => (bool) wp_delete_nav_menu( $menu_id ) ); }
		if ( 'list_items' === $action ) { return array( 'menu_id' => $menu_id, 'items' => wp_get_nav_menu_items( $menu_id ) ?: array() ); }
		if ( 'get_item' === $action ) { $item = get_post( $item_id ); return $item && 'nav_menu_item' === $item->post_type ? get_object_vars( $item ) : new WP_Error( 'wpaib_menu_item_missing', 'Elemento menu non trovato.', array( 'status' => 404 ) ); }
		if ( in_array( $action, array( 'create_item', 'update_item', 'move_item' ), true ) ) {
			$fields = array(
				'menu-item-title'       => (string) self::value( $args, 'title', '' ),
				'menu-item-url'         => (string) self::value( $args, 'url', '' ),
				'menu-item-description' => (string) self::value( $args, 'description', '' ),
				'menu-item-attr-title'  => (string) self::value( $args, 'attr_title', '' ),
				'menu-item-target'      => (string) self::value( $args, 'target', '' ),
				'menu-item-classes'     => (array) self::value( $args, 'classes', array() ),
				'menu-item-xfn'         => (string) self::value( $args, 'xfn', '' ),
				'menu-item-object'      => (string) self::value( $args, 'object', 'custom' ),
				'menu-item-object-id'   => absint( self::value( $args, 'object_id', 0 ) ),
				'menu-item-type'        => (string) self::value( $args, 'type', 'custom' ),
				'menu-item-status'      => (string) self::value( $args, 'status', 'publish' ),
				'menu-item-parent-id'   => absint( self::value( $args, 'parent_id', 0 ) ),
				'menu-item-position'    => absint( self::value( $args, 'position', 0 ) ),
			);
			$id = wp_update_nav_menu_item( $menu_id, 'create_item' === $action ? 0 : $item_id, $fields );
			return is_wp_error( $id ) ? $id : array( 'menu_id' => $menu_id, 'item_id' => $id, 'item' => get_post( $id ) );
		}
		if ( 'reorder_items' === $action ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) self::value( $args, 'item_ids', array() ) ) ) );
			foreach ( $ids as $position => $id ) { wp_update_post( array( 'ID' => $id, 'menu_order' => $position + 1 ) ); }
			return self::control_menus( 'list_items', array( 'menu_id' => $menu_id ) );
		}
		if ( 'delete_item' === $action ) { return array( 'item_id' => $item_id, 'deleted' => (bool) wp_delete_post( $item_id, true ) ); }
		if ( 'list_locations' === $action ) { return array( 'registered' => get_registered_nav_menus(), 'assigned' => get_nav_menu_locations() ); }
		if ( 'assign_location' === $action ) {
			$location = sanitize_key( (string) self::value( $args, 'location', '' ) );
			$locations = get_nav_menu_locations();
			$locations[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
			return self::control_menus( 'list_locations', array() );
		}
		return null;
	}

	private static function control_widgets( string $action, array $args ) {
		$id = rawurlencode( (string) self::value( $args, 'widget_id', self::value( $args, 'id', '' ) ) );
		$sidebar = rawurlencode( (string) self::value( $args, 'sidebar_id', self::value( $args, 'sidebar', '' ) ) );
		if ( 'list_sidebars' === $action ) { return self::internal_rest( 'GET', '/wp/v2/sidebars', $args ); }
		if ( 'get_sidebar' === $action ) { return self::internal_rest( 'GET', '/wp/v2/sidebars/' . $sidebar, $args ); }
		if ( 'list_widget_types' === $action ) { return self::internal_rest( 'GET', '/wp/v2/widget-types', $args ); }
		if ( 'list' === $action ) { return self::internal_rest( 'GET', '/wp/v2/widgets', $args ); }
		if ( in_array( $action, array( 'get', 'verify' ), true ) ) { return self::internal_rest( 'GET', '/wp/v2/widgets/' . $id, $args ); }
		if ( 'create' === $action ) { return self::internal_rest( 'POST', '/wp/v2/widgets', $args ); }
		if ( in_array( $action, array( 'update', 'move' ), true ) ) {
			$body = array();
			if ( 'move' === $action ) { $body = array( 'sidebar' => rawurldecode( $sidebar ), 'position' => absint( self::value( $args, 'position', 0 ) ) ); }
			return self::internal_rest( 'PUT', '/wp/v2/widgets/' . $id, $args, $body );
		}
		if ( 'reorder' === $action ) {
			$results = array();
			foreach ( array_values( (array) self::value( $args, 'widget_ids', array() ) ) as $position => $widget_id ) {
				$results[] = self::internal_rest( 'PUT', '/wp/v2/widgets/' . rawurlencode( (string) $widget_id ), $args, array( 'sidebar' => rawurldecode( $sidebar ), 'position' => $position ) );
			}
			return array( 'reordered' => count( $results ), 'results' => $results );
		}
		if ( 'deactivate' === $action ) { return self::internal_rest( 'PUT', '/wp/v2/widgets/' . $id, $args, array( 'sidebar' => 'wp_inactive_widgets' ) ); }
		if ( 'delete' === $action ) { return self::internal_rest( 'DELETE', '/wp/v2/widgets/' . $id, $args, array( 'force' => true ) ); }
		return null;
	}

	private static function control_templates( string $action, array $args ) {
		if ( in_array( $action, array( 'get_global_styles', 'set_global_styles' ), true ) ) {
			return self::control_styles( $action, $args );
		}
		if ( in_array( $action, array( 'list_patterns', 'create_pattern', 'update_pattern', 'delete_pattern' ), true ) ) {
			$id = absint( self::value( $args, 'id', self::value( $args, 'pattern_id', 0 ) ) );
			if ( 'list_patterns' === $action ) { return array( 'items' => get_posts( array( 'post_type' => 'wp_block', 'post_status' => 'any', 'numberposts' => min( 500, max( 1, (int) self::value( $args, 'limit', 100 ) ) ) ) ) ); }
			if ( 'delete_pattern' === $action ) { return array( 'id' => $id, 'deleted' => (bool) wp_delete_post( $id, true ) ); }
			$data = array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => sanitize_text_field( (string) self::value( $args, 'title', self::value( $args, 'name', '' ) ) ), 'post_content' => wp_kses_post( (string) self::value( $args, 'content', '' ) ) );
			if ( $id ) { $data['ID'] = $id; }
			$saved = $id ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
			return is_wp_error( $saved ) ? $saved : array( 'id' => $saved, 'pattern' => get_post( $saved ) );
		}
		$part = false !== strpos( $action, 'template_part' );
		$collection = $part ? 'template-parts' : 'templates';
		$id = rawurlencode( (string) self::value( $args, 'template_id', self::value( $args, 'id', '' ) ) );
		if ( in_array( $action, array( 'list_templates', 'list_block_templates', 'list_template_parts', 'sync_template_library' ), true ) ) { return self::internal_rest( 'GET', '/wp/v2/' . $collection, $args ); }
		if ( in_array( $action, array( 'get_template', 'get_block_template', 'get_template_part', 'verify', 'export_template', 'export_template_part' ), true ) ) { return self::internal_rest( 'GET', '/wp/v2/' . $collection . '/' . $id, $args ); }
		if ( in_array( $action, array( 'create_template', 'create_block_template', 'create_template_part', 'import_template', 'import_template_part' ), true ) ) { return self::internal_rest( 'POST', '/wp/v2/' . $collection, $args ); }
		if ( in_array( $action, array( 'update_template', 'patch_template', 'update_block_template', 'update_template_part', 'assign_template_part_area' ), true ) ) { return self::internal_rest( 'PUT', '/wp/v2/' . $collection . '/' . $id, $args ); }
		if ( in_array( $action, array( 'delete_template', 'delete_block_template', 'delete_template_part' ), true ) ) { return self::internal_rest( 'DELETE', '/wp/v2/' . $collection . '/' . $id, $args, array( 'force' => true ) ); }
		if ( in_array( $action, array( 'validate_template', 'lint_template_blocks', 'validate_template_part' ), true ) ) {
			$content = (string) self::value( $args, 'content', '' );
			$blocks = parse_blocks( $content );
			return array( 'valid' => serialize_blocks( $blocks ) === $content || ! empty( $blocks ), 'block_count' => count( $blocks ), 'blocks' => array_map( static function( $block ) { return $block['blockName'] ?? null; }, $blocks ) );
		}
		if ( 'list_template_part_areas' === $action ) { return array( 'items' => function_exists( 'get_block_template_folders' ) ? get_block_template_folders( get_stylesheet() ) : array() ); }
		if ( in_array( $action, array( 'duplicate_template', 'duplicate_template_part' ), true ) ) {
			$current = self::internal_rest( 'GET', '/wp/v2/' . $collection . '/' . $id, $args );
			if ( is_wp_error( $current ) ) { return $current; }
			$data = (array) ( $current['data'] ?? array() );
			unset( $data['id'], $data['wp_id'], $data['_links'] );
			$data['slug'] = sanitize_title( (string) ( $data['slug'] ?? 'template' ) . '-copy-' . time() );
			return self::internal_rest( 'POST', '/wp/v2/' . $collection, array( 'body' => $data ) );
		}
		return null;
	}

	private static function global_styles_get( array $args ) {
		return self::internal_rest( 'GET', '/wp/v2/global-styles/themes/' . rawurlencode( get_stylesheet() ), $args );
	}

	private static function control_styles( string $action, array $args ) {
		$css = wp_get_custom_css();
		if ( 'get_custom_css' === $action ) { return array( 'css' => $css, 'sha256' => hash( 'sha256', $css ) ); }
		if ( in_array( $action, array( 'set_custom_css', 'append_custom_css', 'patch_custom_css', 'delete_custom_css', 'remove_unused_css', 'minify_custom_css', 'format_custom_css' ), true ) ) {
			$next = (string) self::value( $args, 'css', '' );
			if ( 'append_custom_css' === $action ) { $next = $css . "\n" . $next; }
			if ( 'delete_custom_css' === $action ) { $next = ''; }
			if ( 'patch_custom_css' === $action ) {
				$search = (string) self::value( $args, 'search', '' );
				if ( '' === $search ) { return new WP_Error( 'wpaib_css_search_required', 'search obbligatorio.', array( 'status' => 400 ) ); }
				$next = str_replace( $search, (string) self::value( $args, 'replace', '' ), $css, $count );
				$expected = (int) self::value( $args, 'expected_replacements', 1 );
				if ( $count !== $expected ) { return new WP_Error( 'wpaib_css_patch_count', 'Numero di sostituzioni CSS inatteso.', array( 'status' => 409, 'expected' => $expected, 'actual' => $count ) ); }
			}
			if ( 'minify_custom_css' === $action || 'remove_unused_css' === $action ) { $next = trim( preg_replace( array( '#/\*.*?\*/#s', '/\s+/' ), array( '', ' ' ), $css ) ); }
			if ( 'format_custom_css' === $action ) { $next = preg_replace( '/}\s*/', "}\n", $css ); }
			$post = wp_update_custom_css_post( $next );
			return is_wp_error( $post ) ? $post : array( 'post_id' => $post->ID, 'before_sha256' => hash( 'sha256', $css ), 'after_sha256' => hash( 'sha256', $next ), 'verified' => wp_get_custom_css() === $next );
		}
		if ( in_array( $action, array( 'get_theme_json', 'get_global_styles', 'verify', 'validate_theme_json', 'normalize_theme_json' ), true ) ) {
			$raw = class_exists( 'WP_Theme_JSON_Resolver' ) ? WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data() : array();
			if ( 'get_global_styles' === $action || 'verify' === $action ) { return array( 'theme_json' => $raw, 'global_styles' => self::global_styles_get( $args ) ); }
			return array( 'theme_json' => $raw, 'valid' => is_array( $raw ) );
		}
		if ( in_array( $action, array( 'set_theme_json', 'patch_theme_json', 'set_global_styles', 'merge_theme_json', 'set_color_palette', 'set_gradient_palette', 'set_duotone_palette', 'set_typography_scale', 'set_spacing_scale', 'set_layout_settings', 'set_block_styles', 'set_element_styles', 'set_custom_properties', 'set_font_families', 'set_font_faces' ), true ) ) {
			$current = self::global_styles_get( $args );
			$id = absint( $current['data']['id'] ?? 0 );
			if ( ! $id ) { return new WP_Error( 'wpaib_global_styles_missing', 'Record Global Styles non trovato.', array( 'status' => 404 ) ); }
			return self::internal_rest( 'PUT', '/wp/v2/global-styles/' . $id, $args );
		}
		if ( 'list_style_variations' === $action ) {
			$items = class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'get_style_variations' ) ? WP_Theme_JSON_Resolver::get_style_variations() : array();
			return array( 'items' => $items );
		}
		if ( 'get_style_variation' === $action ) {
			$slug = sanitize_title( (string) self::value( $args, 'slug', '' ) );
			foreach ( (array) ( self::control_styles( 'list_style_variations', array() )['items'] ?? array() ) as $item ) { if ( sanitize_title( (string) ( $item['title'] ?? '' ) ) === $slug ) { return $item; } }
			return new WP_Error( 'wpaib_style_variation_missing', 'Variazione stile non trovata.', array( 'status' => 404 ) );
		}
		if ( in_array( $action, array( 'create_style_variation', 'update_style_variation', 'import_style_variation', 'delete_style_variation', 'export_style_variation' ), true ) ) {
			$slug = sanitize_title( (string) self::value( $args, 'slug', self::value( $args, 'name', '' ) ) );
			$path = 'wp-content/themes/' . get_stylesheet() . '/styles/' . $slug . '.json';
			if ( 'delete_style_variation' === $action ) { return WPAIB_Files::delete_file( $path, (string) self::value( $args, 'expected_sha256', '' ) ); }
			if ( 'export_style_variation' === $action ) { return WPAIB_Files::read_file( $path, 0, null ); }
			$data = self::value( $args, 'data', self::value( $args, 'theme_json', array() ) );
			$content = is_string( $data ) ? $data : wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			json_decode( $content, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) { return new WP_Error( 'wpaib_style_json_invalid', json_last_error_msg(), array( 'status' => 400 ) ); }
			return WPAIB_Files::write_raw( $path, $content . "\n", self::value( $args, 'expected_sha256', null ) );
		}
		return null;
	}

	private static function customer_item( $customer ): array {
		return array( 'id' => $customer->get_id(), 'email' => $customer->get_email(), 'first_name' => $customer->get_first_name(), 'last_name' => $customer->get_last_name(), 'display_name' => $customer->get_display_name(), 'username' => $customer->get_username(), 'date_created' => $customer->get_date_created() ? $customer->get_date_created()->date( DATE_ATOM ) : null, 'date_modified' => $customer->get_date_modified() ? $customer->get_date_modified()->date( DATE_ATOM ) : null );
	}

	private static function control_customers( string $action, array $args ) {
		if ( ! class_exists( 'WC_Customer' ) ) { return new WP_Error( 'wpaib_woocommerce_unavailable', 'WooCommerce non disponibile.', array( 'status' => 503 ) ); }
		$id = absint( self::value( $args, 'customer_id', self::value( $args, 'id', 0 ) ) );
		if ( in_array( $action, array( 'list', 'search', 'export' ), true ) ) {
			$query = new WC_Customer_Query( array( 'limit' => min( 100, max( 1, (int) self::value( $args, 'limit', 50 ) ) ), 'paged' => max( 1, (int) self::value( $args, 'page', 1 ) ), 'search' => (string) self::value( $args, 'search', '' ), 'return' => 'objects' ) );
			$items = array_map( array( __CLASS__, 'customer_item' ), $query->get_customers() );
			return array( 'items' => $items, 'count' => count( $items ) );
		}
		$customer = $id ? new WC_Customer( $id ) : new WC_Customer();
		if ( $id && ! $customer->get_id() ) { return new WP_Error( 'wpaib_customer_missing', 'Cliente non trovato.', array( 'status' => 404 ) ); }
		if ( in_array( $action, array( 'get', 'verify' ), true ) ) { return self::customer_item( $customer ); }
		if ( in_array( $action, array( 'create', 'update' ), true ) ) {
			foreach ( array( 'email', 'first_name', 'last_name', 'display_name', 'username' ) as $field ) { $value = self::value( $args, $field, null ); $method = 'set_' . $field; if ( null !== $value && method_exists( $customer, $method ) ) { $customer->{$method}( (string) $value ); } }
			if ( 'create' === $action && ! self::value( $args, 'email', '' ) ) { return new WP_Error( 'wpaib_customer_email_required', 'Email cliente obbligatoria.', array( 'status' => 400 ) ); }
			$customer->save();
			return self::customer_item( $customer );
		}
		if ( 'reset_password' === $action ) { $password = (string) self::value( $args, 'new_password', '' ); if ( '' === $password ) { return new WP_Error( 'wpaib_password_required', 'Nuova password obbligatoria.', array( 'status' => 400 ) ); } wp_set_password( $password, $id ); return array( 'customer_id' => $id, 'password_reset' => true ); }
		if ( 'anonymize' === $action ) { $customer->set_email( 'deleted+' . $id . '@example.invalid' ); $customer->set_first_name( '' ); $customer->set_last_name( '' ); $customer->set_display_name( 'Cliente anonimizzato ' . $id ); $customer->save(); return self::customer_item( $customer ); }
		if ( 'delete' === $action ) { require_once ABSPATH . 'wp-admin/includes/user.php'; return array( 'customer_id' => $id, 'deleted' => (bool) wp_delete_user( $id, absint( self::value( $args, 'reassign_to', 0 ) ) ?: null ) ); }
		if ( 'merge' === $action ) {
			$target = absint( self::value( $args, 'target_customer_id', 0 ) );
			if ( ! $target || $target === $id ) { return new WP_Error( 'wpaib_customer_merge_target_invalid', 'Cliente destinazione non valido.', array( 'status' => 400 ) ); }
			$orders = wc_get_orders( array( 'customer_id' => $id, 'limit' => 500, 'return' => 'objects' ) );
			foreach ( $orders as $order ) { $order->set_customer_id( $target ); $order->save(); }
			require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $id, $target );
			return array( 'source_customer_id' => $id, 'target_customer_id' => $target, 'orders_reassigned' => count( $orders ), 'source_deleted' => true );
		}
		if ( 'bulk_update' === $action ) {
			$results = array();
			foreach ( array_slice( (array) self::value( $args, 'items', array() ), 0, 100 ) as $item ) { if ( is_array( $item ) ) { $results[] = self::control_customers( 'update', $item ); } }
			return array( 'processed' => count( $results ), 'results' => $results );
		}
		return null;
	}

	private static function coupon_item( $coupon ): array { return array( 'id' => $coupon->get_id(), 'code' => $coupon->get_code(), 'status' => $coupon->get_status(), 'amount' => $coupon->get_amount(), 'discount_type' => $coupon->get_discount_type(), 'date_expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( DATE_ATOM ) : null, 'usage_count' => $coupon->get_usage_count(), 'usage_limit' => $coupon->get_usage_limit(), 'product_ids' => $coupon->get_product_ids(), 'excluded_product_ids' => $coupon->get_excluded_product_ids() ); }

	private static function control_coupons( string $action, array $args ) {
		if ( ! class_exists( 'WC_Coupon' ) ) { return new WP_Error( 'wpaib_woocommerce_unavailable', 'WooCommerce non disponibile.', array( 'status' => 503 ) ); }
		$id = absint( self::value( $args, 'coupon_id', self::value( $args, 'id', 0 ) ) );
		$code = wc_format_coupon_code( (string) self::value( $args, 'code', '' ) );
		if ( ! $id && $code ) { $id = wc_get_coupon_id_by_code( $code ); }
		if ( 'list' === $action ) {
			$posts = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'numberposts' => min( 100, max( 1, (int) self::value( $args, 'limit', 50 ) ) ), 's' => (string) self::value( $args, 'search', '' ) ) );
			$items = array(); foreach ( $posts as $post ) { $items[] = self::coupon_item( new WC_Coupon( $post->ID ) ); }
			return array( 'items' => $items, 'count' => count( $items ) );
		}
		$coupon = $id ? new WC_Coupon( $id ) : new WC_Coupon();
		if ( $id && ! $coupon->get_id() ) { return new WP_Error( 'wpaib_coupon_missing', 'Coupon non trovato.', array( 'status' => 404 ) ); }
		if ( in_array( $action, array( 'get', 'verify' ), true ) ) { return self::coupon_item( $coupon ); }
		if ( in_array( $action, array( 'create', 'update', 'duplicate', 'enable', 'disable' ), true ) ) {
			if ( 'duplicate' === $action ) { $coupon = new WC_Coupon(); $code = wc_format_coupon_code( (string) self::value( $args, 'new_code', $code . '-copy' ) ); }
			if ( $code ) { $coupon->set_code( $code ); }
			foreach ( array( 'amount', 'discount_type', 'description', 'date_expires', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'minimum_amount', 'maximum_amount', 'free_shipping', 'individual_use', 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'email_restrictions' ) as $field ) { $value = self::value( $args, $field, null ); $method = 'set_' . $field; if ( null !== $value && method_exists( $coupon, $method ) ) { $coupon->{$method}( $value ); } }
			if ( 'enable' === $action ) { $coupon->set_status( 'publish' ); }
			if ( 'disable' === $action ) { $coupon->set_status( 'draft' ); }
			if ( 'create' === $action && ! $code ) { return new WP_Error( 'wpaib_coupon_code_required', 'Codice coupon obbligatorio.', array( 'status' => 400 ) ); }
			$coupon->save();
			return self::coupon_item( $coupon );
		}
		if ( 'delete' === $action ) { return array( 'coupon_id' => $id, 'deleted' => (bool) $coupon->delete( (bool) self::value( $args, 'force', true ) ) ); }
		if ( 'bulk_update' === $action ) { $results = array(); foreach ( array_slice( (array) self::value( $args, 'items', array() ), 0, 100 ) as $item ) { if ( is_array( $item ) ) { $results[] = self::control_coupons( 'update', $item ); } } return array( 'processed' => count( $results ), 'results' => $results ); }
		return null;
	}

	private static function control_commerce_settings( string $action, array $args ) {
		if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) { return new WP_Error( 'wpaib_woocommerce_unavailable', 'WooCommerce non disponibile.', array( 'status' => 503 ) ); }
		$group = sanitize_key( (string) self::value( $args, 'group', self::value( $args, 'group_id', '' ) ) );
		$raw_id = sanitize_text_field( (string) self::value( $args, 'id', self::value( $args, 'setting_id', self::value( $args, 'tool_id', '' ) ) ) );
		$id = rawurlencode( $raw_id );
		$routes = array(
			'manage_shipping_zones'   => '/wc/v3/settings/shipping/zones',
			'manage_shipping_methods' => '/wc/v3/settings/shipping',
			'manage_tax_rates'        => '/wc/v3/taxes',
			'manage_tax_classes'      => '/wc/v3/taxes/classes',
			'manage_gateways'         => '/wc/v3/payment_gateways',
			'manage_webhooks'         => '/wc/v3/webhooks',
			'get_reports'             => '/wc/v3/reports',
			'get_system_status'       => '/wc/v3/system_status',
		);
		if ( 'list_groups' === $action ) { return self::internal_rest( 'GET', '/wc/v3/settings', $args ); }
		if ( 'get_settings' === $action ) {
			if ( '' === $group ) { return new WP_Error( 'wpaib_wc_setting_group_required', 'group/group_id obbligatorio.', array( 'status' => 400 ) ); }
			$route = '/wc/v3/settings/' . rawurlencode( $group ) . ( $id ? '/' . $id : '' );
			return self::internal_rest( 'GET', $route, $args );
		}
		if ( 'update_settings' === $action || 'import' === $action ) {
			if ( '' === $group ) { return new WP_Error( 'wpaib_wc_setting_group_required', 'group/group_id obbligatorio.', array( 'status' => 400 ) ); }
			$items = self::value( $args, 'settings', self::value( $args, 'items', array() ) );
			if ( ! $id && is_array( $items ) && $items ) {
				$update = array();
				foreach ( array_slice( $items, 0, 100 ) as $item ) {
					if ( ! is_array( $item ) || empty( $item['id'] ) || ! array_key_exists( 'value', $item ) ) { continue; }
					$update[] = array( 'id' => sanitize_text_field( (string) $item['id'] ), 'value' => $item['value'] );
				}
				if ( ! $update ) { return new WP_Error( 'wpaib_wc_settings_batch_empty', 'Nessuna impostazione valida da aggiornare.', array( 'status' => 400 ) ); }
				$result = self::internal_rest( 'POST', '/wc/v3/settings/' . rawurlencode( $group ) . '/batch', $args, array( 'update' => $update ) );
				if ( is_wp_error( $result ) ) { return $result; }
				$verified = true; $readbacks = array();
				foreach ( $update as $item ) {
					$read = self::internal_rest( 'GET', '/wc/v3/settings/' . rawurlencode( $group ) . '/' . rawurlencode( $item['id'] ), array() );
					$actual = is_array( $read ) ? ( $read['data']['value'] ?? null ) : null;
					$ok = ! is_wp_error( $read ) && self::values_equivalent( $actual, $item['value'] );
					$verified = $verified && $ok;
					$readbacks[] = array( 'id' => $item['id'], 'verified' => $ok );
				}
				$result['readback'] = $readbacks;
				$result['_control_outcome'] = array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => true, 'verified' => $verified, 'degraded' => ! $verified, 'blocking' => false );
				return $result;
			}
			if ( ! $id ) { return new WP_Error( 'wpaib_wc_setting_id_required', 'id/setting_id obbligatorio per update_settings.', array( 'status' => 400 ) ); }
			$value = self::value( $args, 'value', null );
			$body = self::value( $args, 'body', array() );
			$body = is_array( $body ) ? $body : array();
			if ( ! array_key_exists( 'value', $body ) && null !== $value ) { $body['value'] = $value; }
			if ( ! array_key_exists( 'value', $body ) ) { return new WP_Error( 'wpaib_wc_setting_value_required', 'value obbligatorio per update_settings.', array( 'status' => 400 ) ); }
			$route = '/wc/v3/settings/' . rawurlencode( $group ) . '/' . $id;
			$result = self::internal_rest( 'PUT', $route, array(), $body );
			if ( is_wp_error( $result ) ) { return $result; }
			$read = self::internal_rest( 'GET', $route, array() );
			$actual = is_array( $read ) ? ( $read['data']['value'] ?? null ) : null;
			$verified = ! is_wp_error( $read ) && self::values_equivalent( $actual, $body['value'] );
			$result['readback'] = array( 'group' => $group, 'id' => $raw_id, 'verified' => $verified );
			$result['_control_outcome'] = array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => true, 'verified' => $verified, 'degraded' => ! $verified, 'blocking' => false );
			return $result;
		}
		if ( 'export' === $action ) { return array( 'settings' => self::internal_rest( 'GET', '/wc/v3/settings', $args ), 'system_status' => self::internal_rest( 'GET', '/wc/v3/system_status', $args ) ); }
		if ( 'run_status_tool' === $action ) { return self::internal_rest( 'PUT', '/wc/v3/system_status/tools/' . $id, $args ); }
		if ( 'verify' === $action ) { return self::internal_rest( 'GET', '/wc/v3/system_status', $args ); }
		if ( isset( $routes[ $action ] ) ) {
			$method = strtoupper( sanitize_text_field( (string) self::value( $args, 'operation_method', self::value( $args, 'http_method', 'GET' ) ) ) );
			if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE' ), true ) ) { $method = 'GET'; }
			$route = $routes[ $action ] . ( $id ? '/' . $id : '' );
			return self::internal_rest( $method, $route, $args );
		}
		return null;
	}

	private static function values_equivalent( $actual, $expected ): bool {
		if ( is_bool( $actual ) || is_bool( $expected ) ) { return (bool) $actual === (bool) $expected; }
		if ( is_array( $actual ) || is_array( $expected ) ) { return wp_json_encode( $actual ) === wp_json_encode( $expected ); }
		return (string) $actual === (string) $expected;
	}

	private static function sensitive_key( string $key ): bool { return (bool) preg_match( '/(?:password|passwd|secret|token|private|credential|client[_-]?secret|api[_-]?key|cookie|authorization|card|iban)/i', $key ); }
	private static function redact( $value, string $key = '', int $depth = 0 ) {
		if ( $depth > 12 ) { return '[DEPTH_LIMIT]'; }
		if ( self::sensitive_key( $key ) ) { return '[REDACTED]'; }
		if ( is_array( $value ) ) { $out = array(); foreach ( $value as $k => $v ) { $out[ $k ] = self::redact( $v, (string) $k, $depth + 1 ); } return $out; }
		if ( is_object( $value ) ) { return self::redact( get_object_vars( $value ), $key, $depth + 1 ); }
		return $value;
	}

	public static function mcp_get(): WP_REST_Response {
		$token = WPAIB_Auth::bearer_token_from_request();
		if ( '' === $token ) {
			WPAIB_MCP::record_trace( 'GET', 'oauth_challenge' );
			$response = new WP_REST_Response( array( 'error' => 'unauthorized', 'error_description' => 'OAuth 2.1 authentication is required.' ), 401 );
			$response->header( 'WWW-Authenticate', self::www_authenticate_challenge( 'invalid_token', 'OAuth authorization required.' ) );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		$auth = WPAIB_Auth::verify_access_token( $token, false );
		if ( is_wp_error( $auth ) ) {
			WPAIB_MCP::record_trace( 'GET', 'auth_error', array( 'error_code' => $auth->get_error_code() ) );
			$response = new WP_REST_Response( array( 'error' => $auth->get_error_code(), 'error_description' => $auth->get_error_message() ), (int) ( $auth->get_error_data()['status'] ?? 401 ) );
			$response->header( 'WWW-Authenticate', self::www_authenticate_challenge( 'invalid_token', $auth->get_error_message() ) );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		$accept = strtolower( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
		if ( false !== strpos( $accept, 'text/event-stream' ) ) {
			$registry = PRSTUDIO_Agency::control_registry_info();
			$hash = (string) ( $registry['registry_hash'] ?? '' );
			$last = sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_LAST_EVENT_ID'] ?? '' ) ) );
			nocache_headers();
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'X-Accel-Buffering: no' );
			if ( '' === $last || ! hash_equals( $hash, $last ) ) {
				$notification = array( 'jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => array( 'registry' => $registry ) );
				echo 'id: ' . str_replace( array( "\r", "\n" ), '', $hash ) . "\n";
				echo "event: message\n";
				echo 'data: ' . wp_json_encode( $notification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n";
			} else {
				echo ": registry unchanged\n\n";
			}
			if ( function_exists( 'fastcgi_finish_request' ) ) { fastcgi_finish_request(); } else { flush(); }
			exit;
		}
		WPAIB_MCP::record_trace( 'GET', 'sse_accept_required' );
		$response = new WP_REST_Response( array( 'error' => 'method_not_allowed', 'error_description' => 'Use POST for JSON-RPC or GET with Accept: text/event-stream for registry-change notifications.' ), 405 );
		$response->header( 'Allow', 'POST, GET' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function www_authenticate_challenge( string $error = 'invalid_token', string $description = 'Authentication required.' ): string { return 'Bearer resource_metadata="' . esc_url_raw( WPAIB_Auth::protected_resource_metadata_url() ) . '", scope="wp_ai_bridge.read wp_ai_bridge.write", error="' . sanitize_key( $error ) . '", error_description="' . str_replace( array( '"', "\r", "\n" ), '', $description ) . '"'; }
	private static function protected_resource_data(): array { return array( 'resource' => WPAIB_Auth::mcp_url(), 'authorization_servers' => array( WPAIB_Auth::authorization_server_issuer() ), 'scopes_supported' => array( 'wp_ai_bridge.read','wp_ai_bridge.write' ), 'bearer_methods_supported' => array( 'header' ), 'resource_documentation' => admin_url( 'tools.php?page=wp-ai-bridge' ) ); }
	private static function authorization_server_data(): array { return array( 'issuer' => WPAIB_Auth::authorization_server_issuer(), 'authorization_endpoint' => WPAIB_Auth::authorization_endpoint(), 'token_endpoint' => WPAIB_Auth::token_endpoint(), 'registration_endpoint' => WPAIB_Auth::registration_endpoint(), 'response_types_supported' => array( 'code' ), 'grant_types_supported' => array( 'authorization_code','refresh_token' ), 'code_challenge_methods_supported' => array( 'S256' ), 'token_endpoint_auth_methods_supported' => array( 'none' ), 'scopes_supported' => array( 'wp_ai_bridge.read','wp_ai_bridge.write','offline_access' ), 'service_documentation' => admin_url( 'tools.php?page=wp-ai-bridge' ) ); }
	public static function protected_resource_metadata(): WP_REST_Response { return new WP_REST_Response( self::protected_resource_data(), 200 ); }
	public static function authorization_server_metadata(): WP_REST_Response { return new WP_REST_Response( self::authorization_server_data(), 200 ); }
	public static function oauth_register( WP_REST_Request $request ) { $payload = $request->get_json_params(); if ( ! is_array( $payload ) ) { $payload = $request->get_params(); } $result = WPAIB_Auth::register_client( $payload ); return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 ); }
	public static function oauth_token( WP_REST_Request $request ): WP_REST_Response { $result = WPAIB_Auth::token_exchange( $request ); if ( is_wp_error( $result ) ) { $data = $result->get_error_data(); $response = new WP_REST_Response( array( 'error' => $result->get_error_code(), 'error_description' => $result->get_error_message() ), is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400 ); } else { $response = new WP_REST_Response( $result, 200 ); } $response->header( 'Cache-Control', 'no-store' ); $response->header( 'Pragma', 'no-cache' ); return $response; }
	public static function oauth_diagnostics(): WP_REST_Response { $schema = WPAIB_MCP::schema_diagnostics(); $trace = get_option( 'wpaib_last_mcp_trace', array() ); if ( is_array( $trace ) && ! empty( $trace['time'] ) ) { $trace['time_iso8601'] = gmdate( DATE_ATOM, (int) $trace['time'] ); } $registry = PRSTUDIO_Agency::control_registry_info(); $response = new WP_REST_Response( array( 'ok' => true, 'plugin_name' => 'PR STUDIO AI BRIDGE', 'plugin_version' => WPAIB_VERSION, 'mcp_schema_ok' => $schema['ok'], 'base_tool_count' => $schema['tool_count'], 'enterprise_tool_count' => count( PRSTUDIO_Agency::tools() ), 'openapi_tool_count' => $registry['count'], 'total_tool_count' => count( WPAIB_MCP::all_tools() ), 'tool_registry' => $registry, 'mcp_schema_errors' => $schema['errors'], 'last_mcp_request' => $trace, 'mcp_url' => WPAIB_Auth::mcp_url(), 'openapi_base_url' => rest_url( self::OPENAPI_NS ), 'protected_resource_metadata' => WPAIB_Auth::protected_resource_metadata_url(), 'protected_resource_well_known' => WPAIB_Auth::protected_resource_well_known_url(), 'authorization_server_metadata' => WPAIB_Auth::authorization_server_metadata_url(), 'authorization_endpoint' => WPAIB_Auth::authorization_endpoint(), 'token_endpoint' => WPAIB_Auth::token_endpoint(), 'registration_endpoint' => WPAIB_Auth::registration_endpoint(), 'access_mode_unchanged' => true ), 200 ); $response->header( 'Cache-Control', 'no-store' ); return $response; }

	public static function status(): array { return WPAIB_Site::status(); }
	public static function manifest( WP_REST_Request $r ) { return WPAIB_Files::manifest( (string) $r->get_param( 'path' ), (int) $r->get_param( 'cursor' ), (int) ( $r->get_param( 'limit' ) ?: 300 ), rest_sanitize_boolean( $r->get_param( 'hashes' ) ) ); }
	public static function list_directory( WP_REST_Request $r ) { return WPAIB_Files::list_directory( (string) $r->get_param( 'path' ) ); }
	public static function read_file( WP_REST_Request $r ) { return WPAIB_Files::read_file( (string) $r->get_param( 'path' ), (int) $r->get_param( 'offset' ), null !== $r->get_param( 'length' ) ? (int) $r->get_param( 'length' ) : null ); }
	public static function search_files( WP_REST_Request $r ) { return WPAIB_Files::search( (string) $r->get_param( 'query' ), (string) $r->get_param( 'path' ), is_array( $r->get_param( 'extensions' ) ) ? $r->get_param( 'extensions' ) : array(), (int) $r->get_param( 'cursor' ), (int) ( $r->get_param( 'max_results' ) ?: 100 ) ); }
	public static function write_file( WP_REST_Request $r ) { return WPAIB_Files::write_file( (string) $r->get_param( 'path' ), (string) $r->get_param( 'content_b64' ), null !== $r->get_param( 'expected_sha256' ) ? (string) $r->get_param( 'expected_sha256' ) : null ); }
	public static function delete_file( WP_REST_Request $r ) { return WPAIB_Files::delete_file( (string) $r->get_param( 'path' ), (string) $r->get_param( 'expected_sha256' ) ); }
	public static function restore_file( WP_REST_Request $r ) { return WPAIB_Files::restore( (string) $r->get_param( 'backup_id' ), null !== $r->get_param( 'expected_current_sha256' ) ? (string) $r->get_param( 'expected_current_sha256' ) : null ); }
	public static function plugins(): array { return WPAIB_Site::plugins(); }
	public static function plugin_state( WP_REST_Request $r ) { return WPAIB_Site::set_plugin_state( (string) $r->get_param( 'plugin' ), (string) $r->get_param( 'action' ) ); }
	public static function themes(): array { return WPAIB_Site::themes(); }
	public static function theme_switch( WP_REST_Request $r ) { return WPAIB_Site::switch_theme( (string) $r->get_param( 'stylesheet' ) ); }
	public static function content( WP_REST_Request $r ) { return WPAIB_Site::list_content( $r->get_params() ); }
	public static function content_item( WP_REST_Request $r ) { return WPAIB_Site::get_content( (int) $r['id'] ); }
	public static function content_update( WP_REST_Request $r ) { $data = $r->get_json_params(); return WPAIB_Site::update_content( is_array( $data ) ? $data : $r->get_params() ); }
	public static function page_html( WP_REST_Request $r ) { return WPAIB_Site::fetch_page( (string) $r->get_param( 'url_or_path' ) ); }

	private static function nonblocking_verification_result( $code, $message = '', $data = array() ) { return array( 'ok'=>true, 'status'=>'degraded', 'executed'=>true, 'verified'=>false, 'degraded'=>true, 'blocking'=>false, 'warning'=>(string)$code, 'message'=>(string)$message, 'details'=>(array)$data ); }
}
