<?php
/**
 * PR STUDIO AI Bridge — Google Web Stories full provider.
 *
 * Loaded by wp-ai-bridge.php. Does not change the OAuth flow or the external
 * endpoint contract.
 *
 * Two execution paths, one pipeline:
 * - the bridge action catalog declares /web-stories-manage, so WPAIB_REST
 *   registers the authenticated OpenAPI endpoint and MCP tools/call reaches
 *   this module through ADAPTER_HOOK;
 * - if the catalog entry is missing the module registers the route itself and
 *   falls back to WordPress capability checks for logged-in editors.
 *
 * Target plugin: Google Web Stories 1.42.0+
 * REST route:   /rpconnector-admin/v1/web-stories-manage
 * Native proxy: /web-stories/v1/* only
 *
 * @package PRStudio_AI_Bridge
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PRSTUDIO_Web_Stories_Manage', false ) ) {
	final class PRSTUDIO_Web_Stories_Manage {
		private const VERSION          = '1.1.0';
		private const NAMESPACE        = 'rpconnector-admin/v1';
		private const ROUTE            = '/web-stories-manage';
		private const PROVIDER         = 'wordpress_native';
		private const NATIVE_NAMESPACE = '/web-stories/v1';
		private const STORY_POST_TYPE  = 'web-story';
		private const STYLE_OPTION     = 'web_stories_style_presets';

		/** @var string[] */
		private const TAXONOMIES = [ 'web_story_category', 'web_story_tag' ];

		/** @var string[] */
		private const COMMANDS = [
			'capabilities', 'status', 'verify', 'routes', 'rest_proxy',
			'stories_list', 'story_get', 'story_create', 'story_update',
			'story_duplicate', 'story_publish', 'story_unpublish', 'story_schedule',
			'story_trash', 'story_restore', 'story_delete', 'story_validate',
			'revisions_list', 'revision_get', 'revision_restore',
			'autosaves_list', 'autosave_create',
			'lock_get', 'lock_acquire', 'lock_release',
			'story_meta_list', 'story_meta_get', 'story_meta_set', 'story_meta_delete',
			'terms_list', 'term_get', 'term_create', 'term_update', 'term_delete', 'term_assign',
			'media_list', 'media_get', 'media_update', 'media_delete',
			'media_edit', 'media_post_process', 'media_post_process_cancel',
			'media_post_process_status', 'media_poster_status', 'media_generate_poster',
			'media_verify', 'media_remove_background',
			'publisher_logos_list', 'publisher_logo_get', 'publisher_logo_create',
			'publisher_logo_update', 'publisher_logo_delete', 'publisher_logo_set_default',
			'fonts_list', 'font_get', 'font_create', 'font_delete',
			'font_families_list', 'font_check',
			'templates_list', 'template_get', 'page_templates_list', 'page_template_get',
			'settings_get', 'settings_update', 'style_presets_get', 'style_presets_update',
			'features_get', 'products_search', 'status_check',
			'hotlink_validate', 'hotlink_proxy', 'link_parse', 'embed_get',
			'role_caps_get', 'role_caps_update',
		];

		/** @var string[] */
		private const DESTRUCTIVE = [
			'story_delete', 'story_meta_delete', 'term_delete', 'media_delete',
			'publisher_logo_delete', 'font_delete', 'role_caps_update',
		];

		/** @var string[] */
		private const SENSITIVE_ROUTE_PARTS = [
			'/application-passwords', '/plugins', '/settings',
		];

		/** Hook consumed by WPAIB_REST::execute_control_action() for this route family. */
		public const ADAPTER_HOOK = 'rpconnector_admin_execute_web_stories';

		public static function boot(): void {
			add_action( 'rest_api_init', [ __CLASS__, 'register_route' ], 120 );
			add_filter( self::ADAPTER_HOOK, [ __CLASS__, 'bridge_adapter' ], 10, 3 );
		}

		/**
		 * True when the bridge action catalog declares this route: in that case
		 * WPAIB_REST registers the REST endpoint itself, with the bridge OAuth
		 * 2.1 / X-IM-Admin-Key permission callback, and reaches this module
		 * through ADAPTER_HOOK. Registering the same route twice would shadow
		 * the authenticated bridge endpoint, so the module stands down.
		 */
		private static function bridge_owns_route(): bool {
			if ( ! class_exists( 'PRSTUDIO_Agency' ) || ! method_exists( 'PRSTUDIO_Agency', 'control_action_by_route' ) ) {
				return false;
			}
			return null !== PRSTUDIO_Agency::control_action_by_route( self::ROUTE, 'capabilities' );
		}

		/**
		 * Bridge entry point: same validation pipeline as the REST handler, but
		 * it returns raw data or WP_Error so that execute_control_action() can
		 * wrap, redact and audit the payload like every other route family.
		 *
		 * @param mixed $result Result produced by an earlier adapter, if any.
		 * @param array $arguments Normalized OpenAPI/MCP arguments.
		 * @param array $meta Catalog metadata for the action.
		 * @return mixed
		 */
		public static function bridge_adapter( $result, $arguments, $meta = [] ) {
			if ( null !== $result ) {
				return $result;
			}
			if ( ! is_array( $arguments ) ) {
				return null;
			}
			$action = sanitize_key( (string) ( $arguments['action'] ?? '' ) );
			if ( '' === $action || ! in_array( $action, self::COMMANDS, true ) ) {
				return null;
			}
			return self::with_bridge_user(
				static function () use ( $action, $arguments ) {
					return self::execute( $action, self::deep_unslash( $arguments ) );
				}
			);
		}

		/**
		 * OAuth 2.1 tokens and X-IM-Admin-Key requests are authenticated by the
		 * bridge but carry no WordPress user, while the Web Stories REST API and
		 * the per-action capability checks require one. Mirrors the elevation
		 * already used by WPAIB_REST::proxy_internal_rest().
		 *
		 * @param callable $callback Work to run as the bridge administrator.
		 * @return mixed
		 */
		private static function with_bridge_user( callable $callback ) {
			$previous = get_current_user_id();
			if ( $previous > 0 ) {
				return $callback();
			}
			$users = get_users( [ 'role__in' => [ 'administrator' ], 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ] );
			$user  = $users ? (int) $users[0] : 0;
			if ( $user < 1 ) {
				return new WP_Error( 'web_stories_bridge_no_admin', 'Nessun utente amministratore disponibile per eseguire l’azione.', [ 'status' => 409 ] );
			}
			wp_set_current_user( $user );
			try {
				return $callback();
			} finally {
				wp_set_current_user( $previous );
			}
		}

		public static function register_route(): void {
			if ( self::bridge_owns_route() ) {
				return;
			}
			register_rest_route(
				self::NAMESPACE,
				self::ROUTE,
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'handle' ],
					'permission_callback' => [ __CLASS__, 'permission' ],
					'args'                => self::route_args(),
				]
			);
		}

		/**
		 * Preserve the bridge authentication model while allowing Web Stories
		 * editors to use object-scoped commands. Sensitive/global commands are
		 * checked again per action in authorize_action().
		 */
		public static function permission( WP_REST_Request $request ) {
			$decision = apply_filters( 'prstudio_ai_bridge_permission', null, $request, self::ROUTE );
			if ( is_bool( $decision ) ) {
				return $decision;
			}

			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			$post_type = get_post_type_object( self::STORY_POST_TYPE );
			if ( $post_type && isset( $post_type->cap->edit_posts ) && current_user_can( $post_type->cap->edit_posts ) ) {
				return true;
			}

			if ( current_user_can( 'edit_posts' ) ) {
				return true;
			}

			return new WP_Error( 'web_stories_bridge_forbidden', 'Permessi insufficienti per Web Stories.', [ 'status' => 403 ] );
		}

		public static function handle( WP_REST_Request $request ): WP_REST_Response {
			$params = self::request_params( $request );
			$action = sanitize_key( (string) ( $params['action'] ?? 'capabilities' ) );

			if ( ! in_array( $action, self::COMMANDS, true ) ) {
				return self::error( $action, 'unknown_action', 'Azione Web Stories non supportata.', 400, [ 'supported' => self::COMMANDS ] );
			}

			$result = self::execute( $action, $params );
			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
				return self::error( $action, $result->get_error_code(), $result->get_error_message(), $status, $data );
			}

			return self::success( $action, $result, ! empty( $params['dry_run'] ) );
		}

		/**
		 * Shared pipeline for the REST handler and the bridge adapter.
		 *
		 * @param string $action Validated command name.
		 * @param array  $params Unslashed parameters.
		 * @return array|WP_Error
		 */
		private static function execute( string $action, array $params ) {
			if ( ! self::plugin_available() && ! in_array( $action, [ 'capabilities', 'status', 'verify' ], true ) ) {
				return new WP_Error( 'plugin_unavailable', 'Google Web Stories non è attivo o non è stato inizializzato.', [ 'status' => 409 ] );
			}

			$authorization = self::authorize_action( $action, $params );
			if ( is_wp_error( $authorization ) ) {
				return $authorization;
			}

			try {
				$result = self::dispatch( $action, $params );
				if ( is_wp_error( $result ) ) {
					self::audit( $action, $params, false, $result->get_error_message() );
					return $result;
				}
				self::audit( $action, $params, true );
				return is_array( $result ) ? $result : [ 'value' => $result ];
			} catch ( Throwable $e ) {
				self::audit( $action, $params, false, $e->getMessage() );
				return new WP_Error( 'web_stories_bridge_exception', $e->getMessage(), [ 'status' => 500 ] );
			}
		}

		private static function dispatch( string $action, array $p ) {
			switch ( $action ) {
				case 'capabilities': return self::capabilities();
				case 'status':
				case 'verify': return self::status();
				case 'routes': return self::routes();
				case 'rest_proxy': return self::rest_proxy( $p );
				case 'stories_list': return self::stories_list( $p );
				case 'story_get': return self::story_get( $p );
				case 'story_create': return self::story_create( $p );
				case 'story_update': return self::story_update( $p );
				case 'story_duplicate': return self::story_duplicate( $p );
				case 'story_publish': return self::story_publish( $p );
				case 'story_unpublish': return self::story_status_change( $p, 'draft' );
				case 'story_schedule': return self::story_schedule( $p );
				case 'story_trash': return self::story_trash( $p );
				case 'story_restore': return self::story_restore( $p );
				case 'story_delete': return self::story_delete( $p );
				case 'story_validate': return self::story_validate( $p );
				case 'revisions_list': return self::revisions_list( $p );
				case 'revision_get': return self::revision_get( $p );
				case 'revision_restore': return self::revision_restore( $p );
				case 'autosaves_list': return self::autosaves_list( $p );
				case 'autosave_create': return self::autosave_create( $p );
				case 'lock_get': return self::lock_request( $p, 'GET' );
				case 'lock_acquire': return self::lock_request( $p, 'POST' );
				case 'lock_release': return self::lock_request( $p, 'DELETE' );
				case 'story_meta_list': return self::story_meta_list( $p );
				case 'story_meta_get': return self::story_meta_get( $p );
				case 'story_meta_set': return self::story_meta_set( $p );
				case 'story_meta_delete': return self::story_meta_delete( $p );
				case 'terms_list': return self::terms_list( $p );
				case 'term_get': return self::term_get( $p );
				case 'term_create': return self::term_create( $p );
				case 'term_update': return self::term_update( $p );
				case 'term_delete': return self::term_delete( $p );
				case 'term_assign': return self::term_assign( $p );
				case 'media_list': return self::endpoint( '/media', 'GET', $p );
				case 'media_get': return self::endpoint_with_id( '/media', 'GET', $p );
				case 'media_update': return self::endpoint_with_id( '/media', 'POST', $p );
				case 'media_delete': return self::endpoint_with_id( '/media', 'DELETE', $p );
				case 'media_edit': return self::media_subroute( $p, 'edit', 'POST' );
				case 'media_post_process': return self::media_subroute( $p, 'post-process', 'POST' );
				case 'media_post_process_cancel': return self::media_subroute( $p, 'post-process', 'DELETE' );
				case 'media_post_process_status': return self::media_subroute( $p, 'post-process/status', 'GET' );
				case 'media_poster_status': return self::media_subroute( $p, 'poster-generation-status', 'GET' );
				case 'media_generate_poster': return self::media_subroute( $p, 'poster', 'POST' );
				case 'media_verify': return self::media_subroute( $p, 'verify', 'POST' );
				case 'media_remove_background': return self::media_subroute( $p, 'remove-background', 'POST' );
				case 'publisher_logos_list': return self::endpoint( '/publisher-logos', 'GET', $p );
				case 'publisher_logo_get': return self::endpoint_with_id( '/publisher-logos', 'GET', $p );
				case 'publisher_logo_create': return self::endpoint( '/publisher-logos', 'POST', $p );
				case 'publisher_logo_update': return self::endpoint_with_id( '/publisher-logos', 'POST', $p );
				case 'publisher_logo_delete': return self::endpoint_with_id( '/publisher-logos', 'DELETE', $p );
				case 'publisher_logo_set_default': return self::publisher_logo_set_default( $p );
				case 'fonts_list': return self::endpoint( '/fonts', 'GET', $p );
				case 'font_get': return self::endpoint_with_id( '/fonts', 'GET', $p );
				case 'font_create': return self::endpoint( '/fonts', 'POST', $p );
				case 'font_delete': return self::endpoint_with_id( '/fonts', 'DELETE', $p );
				case 'font_families_list': return self::endpoint( '/font-families', 'GET', $p );
				case 'font_check': return self::endpoint( '/fonts/check', 'POST', $p );
				case 'templates_list': return self::endpoint( '/templates', 'GET', $p );
				case 'template_get': return self::endpoint_with_id( '/templates', 'GET', $p );
				case 'page_templates_list': return self::endpoint( '/page-templates', 'GET', $p );
				case 'page_template_get': return self::endpoint_with_id( '/page-templates', 'GET', $p );
				case 'settings_get': return self::settings_get( $p );
				case 'settings_update': return self::endpoint( '/settings', 'POST', $p );
				case 'style_presets_get': return self::style_presets_get();
				case 'style_presets_update': return self::style_presets_update( $p );
				case 'features_get': return self::endpoint( '/features', 'GET', $p );
				case 'products_search': return self::endpoint( '/products', 'GET', $p );
				case 'status_check': return self::endpoint( '/status-check', 'POST', $p );
				case 'hotlink_validate': return self::endpoint( '/hotlink/validate', 'GET', $p );
				case 'hotlink_proxy': return self::endpoint( '/hotlink/proxy', 'GET', $p );
				case 'link_parse': return self::endpoint( '/link', 'GET', $p );
				case 'embed_get': return self::endpoint( '/embed', 'GET', $p );
				case 'role_caps_get': return self::role_caps_get( $p );
				case 'role_caps_update': return self::role_caps_update( $p );
			}
			return new WP_Error( 'unreachable_action', 'Azione non raggiungibile.' );
		}

		private static function capabilities(): array {
			return [
				'module_version'    => self::VERSION,
				'plugin'            => 'web-stories/web-stories.php',
				'plugin_version'    => self::plugin_version(),
				'active'            => self::plugin_available(),
				'route'             => self::ROUTE,
				'native_namespace'  => self::NATIVE_NAMESPACE,
				'story_rest_base'   => self::story_base(),
				'command_count'     => count( self::COMMANDS ),
				'commands'          => self::COMMANDS,
				'bridge_integration' => [
					'catalog_declared' => self::bridge_owns_route(),
					'adapter_hook'     => self::ADAPTER_HOOK,
					'endpoint_owner'   => self::bridge_owns_route() ? 'wpaib_rest_openapi' : 'module_self_registered',
					'auth'             => self::bridge_owns_route() ? 'oauth2_or_x_im_admin_key' : 'wordpress_capabilities',
				],
				'supports'          => [
					'all_web_stories_rest_endpoints', 'future_namespace_routes',
					'story_crud', 'editor_story_data', 'revisions', 'autosaves', 'editing_locks',
					'story_meta', 'categories', 'tags', 'advanced_media', 'video_posters',
					'publisher_logos', 'fonts', 'templates', 'page_templates', 'products',
					'settings', 'style_presets', 'features', 'hotlinking', 'link_metadata',
					'dry_run', 'sensitive_route_guard', 'audit_hooks',
				],
			];
		}

		private static function status(): array {
			$post_type = get_post_type_object( self::STORY_POST_TYPE );
			$counts    = wp_count_posts( self::STORY_POST_TYPE );
			return [
				'active'            => self::plugin_available(),
				'plugin_version'    => self::plugin_version(),
				'post_type_exists'  => post_type_exists( self::STORY_POST_TYPE ),
				'story_rest_base'   => self::story_base(),
				'native_routes'     => count( self::routes() ),
				'post_counts'       => $counts ? get_object_vars( $counts ) : [],
				'taxonomies'        => array_values( array_filter( self::TAXONOMIES, 'taxonomy_exists' ) ),
				'capabilities'      => $post_type && isset( $post_type->cap ) ? get_object_vars( $post_type->cap ) : [],
				'php_classes'       => [
					'story_post_type' => class_exists( '\\Google\\Web_Stories\\Story_Post_Type' ),
					'settings'        => class_exists( '\\Google\\Web_Stories\\Settings' ),
					'rest_controller' => class_exists( '\\Google\\Web_Stories\\REST_API\\Stories_Controller' ),
				],
			];
		}

		private static function routes(): array {
			$routes = rest_get_server()->get_routes();
			$out    = [];
			foreach ( $routes as $route => $handlers ) {
				if ( ! self::is_web_stories_route( $route ) ) {
					continue;
				}
				$methods = [];
				$args    = [];
				foreach ( $handlers as $handler ) {
					if ( isset( $handler['methods'] ) ) {
						$methods = array_merge( $methods, array_keys( array_filter( (array) $handler['methods'] ) ) );
					}
					if ( isset( $handler['args'] ) && is_array( $handler['args'] ) ) {
						$args = array_merge( $args, array_keys( $handler['args'] ) );
					}
				}
				$out[] = [
					'route'   => $route,
					'methods' => array_values( array_unique( $methods ) ),
					'args'    => array_values( array_unique( $args ) ),
				];
			}
			usort( $out, static fn( $a, $b ) => strcmp( $a['route'], $b['route'] ) );
			return $out;
		}

		private static function rest_proxy( array $p ) {
			$route  = '/' . ltrim( (string) ( $p['route'] ?? '' ), '/' );
			$method = strtoupper( (string) ( $p['method'] ?? 'GET' ) );
			if ( ! self::is_web_stories_route( $route ) ) {
				return new WP_Error( 'invalid_native_route', 'La route deve appartenere al namespace /web-stories/v1.', [ 'status' => 400 ] );
			}
			if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
				return new WP_Error( 'invalid_method', 'Metodo REST non consentito.', [ 'status' => 400 ] );
			}
			if ( self::sensitive_route( $route ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'sensitive_route_guard', 'Questa route richiede manage_options.', [ 'status' => 403 ] );
			}
			return self::native_request( $route, $method, (array) ( $p['query'] ?? [] ), self::payload( $p ), ! empty( $p['dry_run'] ) );
		}

		private static function stories_list( array $p ) {
			$query = self::collection_query( $p );
			return self::native_request( self::story_base(), 'GET', $query, [], ! empty( $p['dry_run'] ) );
		}

		private static function story_get( array $p ) {
			$id = self::required_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			return self::native_request( self::story_base() . '/' . $id, 'GET', (array) ( $p['query'] ?? [] ), [], ! empty( $p['dry_run'] ) );
		}

		private static function story_create( array $p ) {
			$body = self::story_payload( $p );
			if ( empty( $body ) ) {
				return new WP_Error( 'missing_story_data', 'Fornire almeno title, content o story_data.', [ 'status' => 400 ] );
			}
			return self::native_request( self::story_base(), 'POST', [], $body, ! empty( $p['dry_run'] ) );
		}

		private static function story_update( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$body = self::story_payload( $p );
			if ( empty( $body ) ) {
				return new WP_Error( 'missing_story_data', 'Nessuna modifica fornita.', [ 'status' => 400 ] );
			}
			return self::native_request( self::story_base() . '/' . $id, 'POST', [], $body, ! empty( $p['dry_run'] ) );
		}

		private static function story_duplicate( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$body                = self::story_payload( $p );
			$body['original_id'] = $id;
			return self::native_request( self::story_base(), 'POST', [], $body, ! empty( $p['dry_run'] ) );
		}

		private static function story_publish( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$publish_route = self::story_base() . '/' . $id . '/publish';
			if ( self::literal_route_exists( $publish_route ) ) {
				return self::native_request( $publish_route, 'POST', [], self::payload( $p ), ! empty( $p['dry_run'] ) );
			}
			return self::story_status_change( $p, 'publish' );
		}

		private static function story_status_change( array $p, string $status ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			return self::native_request( self::story_base() . '/' . $id, 'POST', [], [ 'status' => $status ], ! empty( $p['dry_run'] ) );
		}

		private static function story_schedule( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$date = (string) ( $p['date'] ?? '' );
			if ( '' === $date ) {
				return new WP_Error( 'missing_date', 'Parametro date obbligatorio.', [ 'status' => 400 ] );
			}
			$body = [ 'status' => 'future', 'date' => $date ];
			if ( ! empty( $p['date_gmt'] ) ) { $body['date_gmt'] = (string) $p['date_gmt']; }
			return self::native_request( self::story_base() . '/' . $id, 'POST', [], $body, ! empty( $p['dry_run'] ) );
		}

		private static function story_trash( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			return self::native_request( self::story_base() . '/' . $id, 'DELETE', [ 'force' => false ], [], ! empty( $p['dry_run'] ) );
		}

		private static function story_restore( array $p ) {
			$id = self::required_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$post = get_post( $id );
			if ( ! $post || self::STORY_POST_TYPE !== $post->post_type ) {
				return new WP_Error( 'story_not_found', 'Storia non trovata.', [ 'status' => 404 ] );
			}
			if ( 'trash' !== $post->post_status ) {
				return [ 'id' => $id, 'restored' => false, 'status' => $post->post_status, 'reason' => 'not_in_trash' ];
			}
			if ( ! empty( $p['dry_run'] ) ) { return [ 'id' => $id, 'would_restore' => true ]; }
			$result = wp_untrash_post( $id );
			return $result ? [ 'id' => $id, 'restored' => true, 'status' => get_post_status( $id ) ] : new WP_Error( 'restore_failed', 'Ripristino non riuscito.', [ 'status' => 500 ] );
		}

		private static function story_delete( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			return self::native_request( self::story_base() . '/' . $id, 'DELETE', [ 'force' => true ], [], ! empty( $p['dry_run'] ) );
		}

		private static function story_validate( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$post = get_post( $id );
			$errors = [];
			$warnings = [];
			$data = json_decode( (string) $post->post_content_filtered, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				$errors[] = [ 'code' => 'invalid_story_data_json', 'message' => json_last_error_msg() ];
				$data = [];
			}
			$pages = isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : [];
			if ( '' === trim( wp_strip_all_tags( $post->post_title ) ) ) { $errors[] = [ 'code' => 'missing_title', 'message' => 'Titolo mancante.' ]; }
			if ( empty( $pages ) ) { $errors[] = [ 'code' => 'missing_pages', 'message' => 'La storia non contiene pagine.' ]; }
			foreach ( $pages as $index => $page ) {
				if ( ! is_array( $page ) ) {
					$errors[] = [ 'code' => 'invalid_page', 'page' => $index ];
					continue;
				}
				if ( empty( $page['id'] ) ) { $warnings[] = [ 'code' => 'page_without_id', 'page' => $index ]; }
				if ( empty( $page['elements'] ) || ! is_array( $page['elements'] ) ) { $warnings[] = [ 'code' => 'empty_page', 'page' => $index ]; }
			}
			$poster_meta = get_post_meta( $id, 'web_stories_poster', true );
			$poster_id   = absint( get_post_thumbnail_id( $id ) );
			$has_poster  = $poster_id > 0 || ( is_array( $poster_meta ) && ! empty( $poster_meta['url'] ) ) || ( is_string( $poster_meta ) && '' !== trim( $poster_meta ) );
			$logo        = absint( get_post_meta( $id, 'web_stories_publisher_logo', true ) ?: get_option( 'web_stories_active_publisher_logo', 0 ) );
			if ( ! $has_poster ) { $warnings[] = [ 'code' => 'missing_poster', 'message' => 'Poster non impostato.' ]; }
			if ( ! $logo ) { $warnings[] = [ 'code' => 'missing_publisher_logo', 'message' => 'Logo editore non impostato.' ]; }
			if ( '' === trim( (string) $post->post_content ) ) { $warnings[] = [ 'code' => 'empty_rendered_content', 'message' => 'Contenuto HTML renderizzato vuoto.' ]; }
			return [
				'id' => $id, 'valid' => empty( $errors ), 'errors' => $errors, 'warnings' => $warnings,
				'pages' => count( $pages ), 'poster_id' => $poster_id, 'poster' => $poster_meta, 'publisher_logo_id' => $logo,
				'status' => $post->post_status, 'permalink' => get_permalink( $id ),
			];
		}

		private static function revisions_list( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			return self::native_request( self::story_base() . '/' . $id . '/revisions', 'GET', self::collection_query( $p ), [], ! empty( $p['dry_run'] ) );
		}

		private static function revision_get( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$revision_id = absint( $p['revision_id'] ?? 0 );
			if ( ! $revision_id ) { return new WP_Error( 'missing_revision_id', 'revision_id obbligatorio.', [ 'status' => 400 ] ); }
			return self::native_request( self::story_base() . '/' . $id . '/revisions/' . $revision_id, 'GET', [], [], ! empty( $p['dry_run'] ) );
		}

		private static function revision_restore( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$revision_id = absint( $p['revision_id'] ?? 0 );
			if ( ! $revision_id ) { return new WP_Error( 'missing_revision_id', 'revision_id obbligatorio.', [ 'status' => 400 ] ); }
			$revision = wp_get_post_revision( $revision_id );
			if ( ! $revision || (int) $revision->post_parent !== $id ) {
				return new WP_Error( 'revision_not_found', 'Revisione non trovata per questa storia.', [ 'status' => 404 ] );
			}
			if ( ! empty( $p['dry_run'] ) ) { return [ 'id' => $id, 'revision_id' => $revision_id, 'would_restore' => true ]; }
			$restored = wp_restore_post_revision( $revision_id );
			return $restored ? [ 'id' => $id, 'revision_id' => $revision_id, 'restored' => true ] : new WP_Error( 'revision_restore_failed', 'Ripristino revisione non riuscito.', [ 'status' => 500 ] );
		}

		private static function autosaves_list( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			return self::native_request( self::story_base() . '/' . $id . '/autosaves', 'GET', self::collection_query( $p ), [], ! empty( $p['dry_run'] ) );
		}

		private static function autosave_create( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			return self::native_request( self::story_base() . '/' . $id . '/autosaves', 'POST', [], self::story_payload( $p ), ! empty( $p['dry_run'] ) );
		}

		private static function lock_request( array $p, string $method ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			return self::native_request( self::story_base() . '/' . $id . '/lock', $method, (array) ( $p['query'] ?? [] ), self::payload( $p ), ! empty( $p['dry_run'] ) );
		}

		private static function story_meta_list( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$all = get_post_meta( $id );
			$out = [];
			foreach ( $all as $key => $values ) {
				if ( self::allowed_meta_key( (string) $key ) ) {
					$out[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
				}
			}
			return [ 'id' => $id, 'meta' => $out ];
		}

		private static function story_meta_get( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$key = (string) ( $p['key'] ?? '' );
			if ( ! self::allowed_meta_key( $key ) ) { return new WP_Error( 'invalid_meta_key', 'Sono consentite solo chiavi web_stories_*.', [ 'status' => 400 ] ); }
			return [ 'id' => $id, 'key' => $key, 'exists' => metadata_exists( 'post', $id, $key ), 'value' => get_post_meta( $id, $key, true ) ];
		}

		private static function story_meta_set( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$key = (string) ( $p['key'] ?? '' );
			if ( ! self::allowed_meta_key( $key ) ) { return new WP_Error( 'invalid_meta_key', 'Sono consentite solo chiavi web_stories_*.', [ 'status' => 400 ] ); }
			$value = array_key_exists( 'value', $p ) ? $p['value'] : self::payload( $p );
			$before = get_post_meta( $id, $key, true );
			if ( empty( $p['dry_run'] ) ) { update_post_meta( $id, $key, $value ); clean_post_cache( $id ); }
			return [ 'id' => $id, 'key' => $key, 'before' => $before, 'after' => $value ];
		}

		private static function story_meta_delete( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$key = (string) ( $p['key'] ?? '' );
			if ( ! self::allowed_meta_key( $key ) ) { return new WP_Error( 'invalid_meta_key', 'Sono consentite solo chiavi web_stories_*.', [ 'status' => 400 ] ); }
			$before = get_post_meta( $id, $key, true );
			if ( empty( $p['dry_run'] ) ) { delete_post_meta( $id, $key ); clean_post_cache( $id ); }
			return [ 'id' => $id, 'key' => $key, 'deleted' => true, 'before' => $before ];
		}

		private static function terms_list( array $p ) {
			$taxonomy = self::taxonomy( $p );
			if ( is_wp_error( $taxonomy ) ) { return $taxonomy; }
			$args = [
				'hide_empty' => self::bool_value( $p['hide_empty'] ?? false ),
				'number' => min( 200, max( 0, absint( $p['per_page'] ?? $p['number'] ?? 100 ) ) ),
				'offset' => max( 0, ( max( 1, absint( $p['page'] ?? 1 ) ) - 1 ) * max( 1, absint( $p['per_page'] ?? 100 ) ) ),
			];
			if ( ! empty( $p['search'] ) ) { $args['search'] = sanitize_text_field( (string) $p['search'] ); }
			$terms = get_terms( [ 'taxonomy' => $taxonomy ] + $args );
			if ( is_wp_error( $terms ) ) { return $terms; }
			return [ 'taxonomy' => $taxonomy, 'items' => array_map( [ __CLASS__, 'term_to_array' ], $terms ) ];
		}

		private static function term_get( array $p ) {
			$taxonomy = self::taxonomy( $p );
			if ( is_wp_error( $taxonomy ) ) { return $taxonomy; }
			$term_id = absint( $p['term_id'] ?? $p['id'] ?? 0 );
			$term = $term_id ? get_term( $term_id, $taxonomy ) : null;
			if ( ! $term || is_wp_error( $term ) ) { return new WP_Error( 'term_not_found', 'Termine non trovato.', [ 'status' => 404 ] ); }
			return self::term_to_array( $term );
		}

		private static function term_create( array $p ) {
			$taxonomy = self::taxonomy( $p );
			if ( is_wp_error( $taxonomy ) ) { return $taxonomy; }
			$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
			if ( '' === $name ) { return new WP_Error( 'missing_name', 'Parametro name obbligatorio.', [ 'status' => 400 ] ); }
			$args = [];
			foreach ( [ 'slug', 'description', 'parent' ] as $key ) { if ( isset( $p[ $key ] ) ) { $args[ $key ] = $p[ $key ]; } }
			if ( ! empty( $p['dry_run'] ) ) { return [ 'taxonomy' => $taxonomy, 'name' => $name, 'args' => $args, 'would_create' => true ]; }
			$result = wp_insert_term( $name, $taxonomy, $args );
			return is_wp_error( $result ) ? $result : [ 'taxonomy' => $taxonomy, 'term_id' => (int) $result['term_id'], 'term_taxonomy_id' => (int) $result['term_taxonomy_id'] ];
		}

		private static function term_update( array $p ) {
			$taxonomy = self::taxonomy( $p );
			if ( is_wp_error( $taxonomy ) ) { return $taxonomy; }
			$term_id = absint( $p['term_id'] ?? $p['id'] ?? 0 );
			if ( ! $term_id ) { return new WP_Error( 'missing_term_id', 'term_id obbligatorio.', [ 'status' => 400 ] ); }
			$args = [];
			foreach ( [ 'name', 'slug', 'description', 'parent' ] as $key ) { if ( array_key_exists( $key, $p ) ) { $args[ $key ] = $p[ $key ]; } }
			if ( empty( $args ) ) { return new WP_Error( 'missing_term_changes', 'Nessuna modifica termine fornita.', [ 'status' => 400 ] ); }
			if ( ! empty( $p['dry_run'] ) ) { return [ 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'changes' => $args, 'would_update' => true ]; }
			$result = wp_update_term( $term_id, $taxonomy, $args );
			return is_wp_error( $result ) ? $result : [ 'taxonomy' => $taxonomy, 'term_id' => (int) $result['term_id'], 'updated' => true ];
		}

		private static function term_delete( array $p ) {
			$taxonomy = self::taxonomy( $p );
			if ( is_wp_error( $taxonomy ) ) { return $taxonomy; }
			$term_id = absint( $p['term_id'] ?? $p['id'] ?? 0 );
			if ( ! $term_id ) { return new WP_Error( 'missing_term_id', 'term_id obbligatorio.', [ 'status' => 400 ] ); }
			if ( ! empty( $p['dry_run'] ) ) { return [ 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'would_delete' => true ]; }
			$result = wp_delete_term( $term_id, $taxonomy );
			return is_wp_error( $result ) ? $result : [ 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'deleted' => (bool) $result ];
		}

		private static function term_assign( array $p ) {
			$id = self::required_story_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$taxonomy = self::taxonomy( $p );
			if ( is_wp_error( $taxonomy ) ) { return $taxonomy; }
			$terms = array_values( array_filter( array_map( 'absint', (array) ( $p['term_ids'] ?? $p['ids'] ?? [] ) ) ) );
			$append = self::bool_value( $p['append'] ?? false );
			if ( ! empty( $p['dry_run'] ) ) { return [ 'id' => $id, 'taxonomy' => $taxonomy, 'term_ids' => $terms, 'append' => $append, 'would_assign' => true ]; }
			$result = wp_set_object_terms( $id, $terms, $taxonomy, $append );
			return is_wp_error( $result ) ? $result : [ 'id' => $id, 'taxonomy' => $taxonomy, 'term_taxonomy_ids' => array_map( 'intval', $result ) ];
		}

		private static function endpoint( string $suffix, string $method, array $p ) {
			$route = self::NATIVE_NAMESPACE . '/' . ltrim( $suffix, '/' );
			$query = 'GET' === $method || 'DELETE' === $method ? self::collection_query( $p ) : (array) ( $p['query'] ?? [] );
			$body  = 'GET' === $method ? [] : self::payload_or_top_level( $p );
			return self::native_request( $route, $method, $query, $body, ! empty( $p['dry_run'] ) );
		}

		private static function endpoint_with_id( string $suffix, string $method, array $p ) {
			$id = self::required_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$route = self::NATIVE_NAMESPACE . '/' . trim( $suffix, '/' ) . '/' . $id;
			$query = 'GET' === $method || 'DELETE' === $method ? self::collection_query( $p ) : (array) ( $p['query'] ?? [] );
			$body  = 'GET' === $method ? [] : self::payload_or_top_level( $p );
			return self::native_request( $route, $method, $query, $body, ! empty( $p['dry_run'] ) );
		}

		private static function media_subroute( array $p, string $suffix, string $method ) {
			$id = self::required_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$route = self::NATIVE_NAMESPACE . '/media/' . $id . '/' . trim( $suffix, '/' );
			return self::native_request( $route, $method, (array) ( $p['query'] ?? [] ), self::payload_or_top_level( $p ), ! empty( $p['dry_run'] ) );
		}

		private static function publisher_logo_set_default( array $p ) {
			$id = self::required_id( $p );
			if ( is_wp_error( $id ) ) { return $id; }
			$before = absint( get_option( 'web_stories_active_publisher_logo', 0 ) );
			if ( empty( $p['dry_run'] ) ) { update_option( 'web_stories_active_publisher_logo', $id, false ); }
			return [ 'before' => $before, 'after' => $id ];
		}

		private static function settings_get( array $p ) {
			$result = self::endpoint( '/settings', 'GET', $p );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( empty( $p['include_sensitive'] ) || ! current_user_can( 'manage_options' ) ) {
				$result = self::redact( $result );
			}
			return $result;
		}

		private static function style_presets_get(): array {
			return [ 'option' => self::STYLE_OPTION, 'value' => get_option( self::STYLE_OPTION, [] ) ];
		}

		private static function style_presets_update( array $p ): array {
			$value = array_key_exists( 'value', $p ) ? $p['value'] : ( $p['presets'] ?? self::payload( $p ) );
			$before = get_option( self::STYLE_OPTION, [] );
			if ( empty( $p['dry_run'] ) ) { update_option( self::STYLE_OPTION, $value, false ); }
			return [ 'option' => self::STYLE_OPTION, 'before' => $before, 'after' => $value ];
		}

		private static function role_caps_get( array $p ) {
			$role_name = sanitize_key( (string) ( $p['role'] ?? '' ) );
			$role = $role_name ? get_role( $role_name ) : null;
			if ( ! $role ) { return new WP_Error( 'invalid_role', 'Ruolo WordPress non valido.', [ 'status' => 400 ] ); }
			$caps = [];
			foreach ( $role->capabilities as $cap => $enabled ) {
				if ( self::is_web_stories_cap( $cap ) ) { $caps[ $cap ] = (bool) $enabled; }
			}
			ksort( $caps );
			return [ 'role' => $role_name, 'capabilities' => $caps ];
		}

		private static function role_caps_update( array $p ) {
			$role_name = sanitize_key( (string) ( $p['role'] ?? '' ) );
			$role = $role_name ? get_role( $role_name ) : null;
			if ( ! $role ) { return new WP_Error( 'invalid_role', 'Ruolo WordPress non valido.', [ 'status' => 400 ] ); }
			$capabilities = (array) ( $p['capabilities'] ?? self::payload( $p ) );
			$changes = [];
			foreach ( $capabilities as $cap => $enabled ) {
				$cap = sanitize_key( (string) $cap );
				if ( ! self::is_web_stories_cap( $cap ) ) { return new WP_Error( 'invalid_capability', 'Capability Web Stories non valida: ' . $cap, [ 'status' => 400 ] ); }
				$before = ! empty( $role->capabilities[ $cap ] );
				$after = self::bool_value( $enabled );
				$changes[ $cap ] = [ 'before' => $before, 'after' => $after ];
				if ( empty( $p['dry_run'] ) ) { $after ? $role->add_cap( $cap, true ) : $role->remove_cap( $cap ); }
			}
			return [ 'role' => $role_name, 'changes' => $changes ];
		}

		private static function native_request( string $route, string $method, array $query, array $body, bool $dry_run ) {
			$route  = '/' . ltrim( $route, '/' );
			$method = strtoupper( $method );
			if ( ! self::is_web_stories_route( $route ) ) {
				return new WP_Error( 'invalid_native_route', 'Route esterna al namespace Web Stories.', [ 'status' => 400 ] );
			}
			if ( $dry_run ) {
				return [ 'dry_run' => true, 'route' => $route, 'method' => $method, 'query' => $query, 'body' => $body ];
			}
			$request = new WP_REST_Request( $method, $route );
			foreach ( $query as $key => $value ) { $request->set_param( (string) $key, $value ); }
			if ( ! empty( $body ) ) {
				$request->set_body_params( $body );
				$request->set_header( 'content-type', 'application/json' );
			}
			$response = rest_do_request( $request );
			if ( $response->is_error() ) {
				$error = $response->as_error();
				return $error ?: new WP_Error( 'native_rest_error', 'Errore REST Web Stories.', [ 'status' => $response->get_status() ] );
			}
			return [
				'route' => $route, 'method' => $method, 'status' => $response->get_status(),
				'headers' => $response->get_headers(), 'data' => $response->get_data(),
			];
		}

		private static function authorize_action( string $action, array $p ) {
			$global = [
				'settings_get', 'settings_update', 'style_presets_get', 'style_presets_update',
				'publisher_logo_set_default', 'role_caps_get', 'role_caps_update',
			];
			if ( in_array( $action, $global, true ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'manage_options_required', 'Questa azione richiede manage_options.', [ 'status' => 403 ] );
			}

			$term_actions = [ 'terms_list', 'term_get', 'term_create', 'term_update', 'term_delete', 'term_assign' ];
			if ( in_array( $action, $term_actions, true ) ) {
				$taxonomy = sanitize_key( (string) ( $p['taxonomy'] ?? 'web_story_category' ) );
				$tax_obj  = taxonomy_exists( $taxonomy ) ? get_taxonomy( $taxonomy ) : null;
				$cap_name = in_array( $action, [ 'terms_list', 'term_get' ], true ) ? 'manage_terms' : ( 'term_assign' === $action ? 'assign_terms' : 'edit_terms' );
				$cap      = $tax_obj && isset( $tax_obj->cap->{$cap_name} ) ? $tax_obj->cap->{$cap_name} : 'manage_categories';
				if ( ! current_user_can( $cap ) ) {
					return new WP_Error( 'taxonomy_forbidden', 'Permessi insufficienti per la tassonomia Web Stories.', [ 'status' => 403 ] );
				}
			}

			if ( 'rest_proxy' === $action ) {
				$route = '/' . ltrim( (string) ( $p['route'] ?? '' ), '/' );
				$method = strtoupper( (string) ( $p['method'] ?? 'GET' ) );
				if ( ( 'GET' !== $method || self::sensitive_route( $route ) ) && ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'manage_options_required', 'Il proxy in scrittura o sensibile richiede manage_options.', [ 'status' => 403 ] );
				}
			}

			$story_actions = [
				'story_get', 'story_update', 'story_duplicate', 'story_publish', 'story_unpublish',
				'story_schedule', 'story_trash', 'story_restore', 'story_delete', 'story_validate',
				'revisions_list', 'revision_get', 'revision_restore', 'autosaves_list', 'autosave_create',
				'lock_get', 'lock_acquire', 'lock_release', 'story_meta_list', 'story_meta_get',
				'story_meta_set', 'story_meta_delete', 'term_assign',
			];
			if ( in_array( $action, $story_actions, true ) ) {
				$id = absint( $p['id'] ?? $p['story_id'] ?? $p['parent_id'] ?? 0 );
				if ( $id && ! current_user_can( 'edit_post', $id ) ) {
					return new WP_Error( 'story_edit_forbidden', 'Non puoi modificare questa storia.', [ 'status' => 403 ] );
				}
			}

			return true;
		}

		private static function story_base(): string {
			$post_type = get_post_type_object( self::STORY_POST_TYPE );
			$rest_base = $post_type && ! empty( $post_type->rest_base ) && is_string( $post_type->rest_base ) ? $post_type->rest_base : 'stories';
			return self::NATIVE_NAMESPACE . '/' . trim( $rest_base, '/' );
		}

		private static function story_payload( array $p ): array {
			$body = self::payload( $p );
			$keys = [
				'title', 'content', 'story_data', 'excerpt', 'status', 'slug', 'author',
				'featured_media', 'date', 'date_gmt', 'meta', 'web_story_category', 'web_story_tag',
			];
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $p ) ) { $body[ $key ] = $p[ $key ]; }
			}
			return $body;
		}

		private static function payload_or_top_level( array $p ): array {
			$body = self::payload( $p );
			$excluded = [
				'action', 'method', 'route', 'dry_run', 'request_id',
				'query', 'body', 'params', 'mutation', 'id', 'page', 'per_page',
			];
			foreach ( $p as $key => $value ) {
				if ( ! in_array( $key, $excluded, true ) && ! array_key_exists( $key, $body ) ) { $body[ $key ] = $value; }
			}
			return $body;
		}

		private static function collection_query( array $p ): array {
			$query = (array) ( $p['query'] ?? [] );
			$keys = [
				'page', 'per_page', 'search', 'status', 'author', 'orderby', 'order', 'context',
				'include', 'exclude', 'parent', 'slug', 'before', 'after', 'force', 'url',
				'provider', 'product', 'category', 'tag', 'mime_type',
			];
			foreach ( $keys as $key ) { if ( array_key_exists( $key, $p ) ) { $query[ $key ] = $p[ $key ]; } }
			return $query;
		}

		private static function required_id( array $p ) {
			$id = absint( $p['id'] ?? 0 );
			return $id ?: new WP_Error( 'missing_id', 'Parametro id obbligatorio.', [ 'status' => 400 ] );
		}

		private static function required_story_id( array $p ) {
			$id = absint( $p['id'] ?? $p['story_id'] ?? $p['parent_id'] ?? 0 );
			if ( ! $id ) { return new WP_Error( 'missing_story_id', 'Parametro id/story_id obbligatorio.', [ 'status' => 400 ] ); }
			$post = get_post( $id );
			if ( ! $post || self::STORY_POST_TYPE !== $post->post_type ) { return new WP_Error( 'story_not_found', 'Storia non trovata.', [ 'status' => 404 ] ); }
			return $id;
		}

		private static function taxonomy( array $p ) {
			$taxonomy = sanitize_key( (string) ( $p['taxonomy'] ?? 'web_story_category' ) );
			if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) || ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error( 'invalid_taxonomy', 'Tassonomia Web Stories non valida.', [ 'status' => 400 ] );
			}
			return $taxonomy;
		}

		private static function term_to_array( WP_Term $term ): array {
			return [
				'id' => $term->term_id, 'taxonomy' => $term->taxonomy, 'name' => $term->name,
				'slug' => $term->slug, 'description' => $term->description, 'parent' => $term->parent,
				'count' => $term->count,
			];
		}

		private static function allowed_meta_key( string $key ): bool {
			return ( 0 === strpos( $key, 'web_stories_' ) || 0 === strpos( $key, '_web_stories_' ) )
				&& (bool) preg_match( '/^_?web_stories_[A-Za-z0-9_\-:.]+$/', $key );
		}

		private static function is_web_stories_cap( string $cap ): bool {
			return (bool) preg_match( '/(?:web[-_]stor(?:y|ies)|web_story)/i', $cap );
		}

		private static function is_web_stories_route( string $route ): bool {
			$route = '/' . ltrim( $route, '/' );
			return $route === self::NATIVE_NAMESPACE || 0 === strpos( $route, self::NATIVE_NAMESPACE . '/' );
		}

		private static function literal_route_exists( string $route ): bool {
			$route = '/' . ltrim( $route, '/' );
			return array_key_exists( $route, rest_get_server()->get_routes() );
		}

		private static function sensitive_route( string $route ): bool {
			foreach ( self::SENSITIVE_ROUTE_PARTS as $part ) {
				if ( false !== strpos( $route, $part ) ) { return true; }
			}
			return false;
		}

		private static function plugin_available(): bool {
			return defined( 'WEBSTORIES_VERSION' ) || class_exists( '\\Google\\Web_Stories\\Story_Post_Type' ) || post_type_exists( self::STORY_POST_TYPE );
		}

		private static function plugin_version(): ?string {
			if ( defined( 'WEBSTORIES_VERSION' ) ) { return (string) WEBSTORIES_VERSION; }
			if ( function_exists( 'get_plugin_data' ) && defined( 'WP_PLUGIN_DIR' ) ) {
				$file = WP_PLUGIN_DIR . '/web-stories/web-stories.php';
				if ( is_readable( $file ) ) {
					$data = get_plugin_data( $file, false, false );
					return $data['Version'] ?? null;
				}
			}
			return null;
		}

		private static function payload( array $p ): array {
			foreach ( [ 'body', 'mutation', 'params' ] as $key ) {
				if ( isset( $p[ $key ] ) && is_array( $p[ $key ] ) ) { return $p[ $key ]; }
			}
			return [];
		}

		private static function request_params( WP_REST_Request $request ): array {
			$params = $request->get_params();
			$json   = $request->get_json_params();
			if ( is_array( $json ) ) { $params = array_replace_recursive( $params, $json ); }
			return self::deep_unslash( $params );
		}

		private static function deep_unslash( $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) { $value[ $key ] = self::deep_unslash( $item ); }
				return $value;
			}
			return is_string( $value ) ? wp_unslash( $value ) : $value;
		}

		private static function bool_value( $value ): bool {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}

		private static function redact( $value, string $key = '' ) {
			if ( is_array( $value ) ) {
				$out = [];
				foreach ( $value as $k => $v ) { $out[ $k ] = self::redact( $v, (string) $k ); }
				return $out;
			}
			if ( preg_match( '/(?:token|secret|password|private.?key|authorization|api.?key|client.?secret|shopify_access)/i', $key ) && '' !== (string) $value ) {
				return '[REDACTED]';
			}
			return $value;
		}

		private static function route_args(): array {
			return [
				'action' => [ 'type' => 'string', 'required' => false ],
				'method' => [ 'type' => 'string', 'required' => false ],
				'route' => [ 'type' => 'string', 'required' => false ],
				'dry_run' => [ 'type' => 'boolean', 'required' => false ],
				'request_id' => [ 'type' => 'string', 'required' => false ],
				'query' => [ 'type' => 'object', 'required' => false ],
				'body' => [ 'type' => 'object', 'required' => false ],
				'params' => [ 'type' => 'object', 'required' => false ],
				'mutation' => [ 'type' => 'object', 'required' => false ],
				'id' => [ 'type' => 'integer', 'required' => false ],
				'ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'required' => false ],
				'story_id' => [ 'type' => 'integer', 'required' => false ],
				'parent_id' => [ 'type' => 'integer', 'required' => false ],
				'revision_id' => [ 'type' => 'integer', 'required' => false ],
				'title' => [ 'required' => false ], 'content' => [ 'required' => false ],
				'story_data' => [ 'required' => false ], 'excerpt' => [ 'required' => false ],
				'status' => [ 'type' => 'string', 'required' => false ],
				'slug' => [ 'type' => 'string', 'required' => false ],
				'author' => [ 'type' => 'integer', 'required' => false ],
				'featured_media' => [ 'type' => 'integer', 'required' => false ],
				'meta' => [ 'type' => 'object', 'required' => false ],
				'key' => [ 'type' => 'string', 'required' => false ],
				'value' => [ 'required' => false ],
				'taxonomy' => [ 'type' => 'string', 'required' => false ],
				'term_id' => [ 'type' => 'integer', 'required' => false ],
				'term_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'required' => false ],
				'name' => [ 'type' => 'string', 'required' => false ],
				'description' => [ 'type' => 'string', 'required' => false ],
				'parent' => [ 'type' => 'integer', 'required' => false ],
				'append' => [ 'type' => 'boolean', 'required' => false ],
				'page' => [ 'type' => 'integer', 'required' => false ],
				'per_page' => [ 'type' => 'integer', 'required' => false ],
				'search' => [ 'type' => 'string', 'required' => false ],
				'date' => [ 'type' => 'string', 'required' => false ],
				'date_gmt' => [ 'type' => 'string', 'required' => false ],
				'url' => [ 'type' => 'string', 'required' => false ],
				'role' => [ 'type' => 'string', 'required' => false ],
				'capabilities' => [ 'type' => 'object', 'required' => false ],
				'settings' => [ 'type' => 'object', 'required' => false ],
				'presets' => [ 'required' => false ],
			];
		}

		private static function success( string $action, $result, bool $dry_run ): WP_REST_Response {
			return new WP_REST_Response( [
				'route' => self::ROUTE,
				'action' => $action,
				'tool_name' => 'rpconnector_web_stories_manage_' . $action,
				'execution' => self::PROVIDER,
				'provider' => self::PROVIDER,
				'status' => 'completed',
				'executed' => ! $dry_run,
				'dry_run' => $dry_run,
				'suggested_tools' => [],
				'instruction' => '',
				'result' => $result,
			], 200 );
		}

		private static function error( string $action, string $code, string $message, int $status, $data = null ): WP_REST_Response {
			return new WP_REST_Response( [
				'route' => self::ROUTE,
				'action' => $action,
				'tool_name' => 'rpconnector_web_stories_manage_' . $action,
				'execution' => self::PROVIDER,
				'provider' => self::PROVIDER,
				'status' => 'technical_error',
				'executed' => false,
				'blocking' => true,
				'suggested_tools' => [],
				'instruction' => '',
				'error' => $code,
				'message' => $message,
				'data' => $data,
			], $status );
		}

		private static function audit( string $action, array $params, bool $success, string $message = '' ): void {
			$event = [
				'provider' => 'web_stories', 'route' => self::ROUTE, 'action' => $action,
				'success' => $success, 'message' => $message, 'user_id' => get_current_user_id(),
				'request_id' => sanitize_text_field( (string) ( $params['request_id'] ?? '' ) ),
				'dry_run' => ! empty( $params['dry_run'] ), 'timestamp_gmt' => gmdate( 'c' ),
			];
			do_action( 'prstudio_ai_bridge_audit', $event );
			do_action( 'prstudio_ai_bridge_web_stories_audit', $event, $params );
		}
	}

	PRSTUDIO_Web_Stories_Manage::boot();
}
