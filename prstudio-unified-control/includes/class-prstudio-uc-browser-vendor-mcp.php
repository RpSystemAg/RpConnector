<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Vendor browser MCP surface.
 *
 * Keeps the existing PR STUDIO OAuth/pairing/task wire while replacing the
 * ChatGPT-visible browser surface with provider wrappers whose JSON Schemas live
 * in contract/browser-vendor-mcp-tools.json.
 */
final class PRSTUDIO_UC_Browser_Vendor_MCP {
    private const ROUTE = '/prstudio-unified/v1/mcp';
    private const SCHEMA_FILE = 'contract/browser-vendor-mcp-tools.json';
    private static bool $registered = false;
    private static ?array $schema_cache = null;

    public static function register(): void {
        if ( self::$registered ) { return; }
        self::$registered = true;
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rewrite_tool_call' ), 4, 3 );
        add_filter( 'rest_post_dispatch', array( __CLASS__, 'rewrite_tools_list' ), 20, 3 );

        // ChatGPT/MCP compatibility:
        // - keep Dynamic Client Registration as the advertised registration path;
        // - answer HEAD probes on the MCP endpoint with the same OAuth challenge
        //   semantics as an unauthenticated GET instead of WordPress rest_no_route.
        // The underlying OAuth class retains CIMD resolution support for clients
        // that explicitly use a URL-form client_id, but we do not advertise CIMD
        // until it has been verified end-to-end with the production ChatGPT client.
        add_action( 'parse_request', array( __CLASS__, 'serve_oauth_authorization_metadata' ), -100 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_mcp_head_route' ), 4 );
    }

    /**
     * Serve OAuth authorization-server metadata before the generic auth handler.
     *
     * MCP 2026 prefers Client ID Metadata Documents (CIMD), but DCR remains a
     * supported compatibility path. Advertising CIMD makes modern clients skip
     * /oauth/register entirely; if their metadata document cannot be resolved by
     * WordPress, authorization fails later with a generic invalid-client request.
     * PR STUDIO therefore advertises the already-implemented DCR endpoint and
     * leaves CIMD as a non-advertised compatibility capability for now.
     */
    public static function serve_oauth_authorization_metadata(): void {
        $request_path = untrailingslashit( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
        $metadata_path = untrailingslashit( (string) wp_parse_url( home_url( '/.well-known/oauth-authorization-server' ), PHP_URL_PATH ) );
        if ( $request_path !== $metadata_path ) { return; }

        $method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
        if ( 'GET' !== $method && 'HEAD' !== $method ) { return; }
        if ( ! class_exists( 'PRSTUDIO_UC_MCP_Auth_V5' ) ) { return; }

        $metadata = PRSTUDIO_UC_MCP_Auth_V5::authorization_server_metadata();
        if ( ! is_array( $metadata ) ) { return; }

        // Presence=true instructs MCP 2026 clients to skip DCR. Do not advertise
        // it until production ChatGPT CIMD retrieval has been certified.
        unset( $metadata['client_id_metadata_document_supported'] );

        nocache_headers();
        status_header( 200 );
        header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
        header( 'Access-Control-Allow-Origin: *' );
        if ( 'HEAD' !== $method ) {
            echo wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }
        exit;
    }

    /** Add HEAD without changing the existing GET/POST/DELETE MCP route. */
    public static function register_mcp_head_route(): void {
        register_rest_route( 'prstudio-unified/v1', '/mcp', array(
            'methods' => 'HEAD',
            'callback' => array( __CLASS__, 'handle_mcp_head' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * HEAD is used by some remote clients as a reachability/auth probe. Return a
     * standards-shaped OAuth challenge rather than asking the normal JSON-RPC
     * handler to parse a body that a HEAD request does not have.
     */
    public static function handle_mcp_head( WP_REST_Request $request ): WP_REST_Response {
        unset( $request );
        $auth = PRSTUDIO_UC_MCP_Auth_V5::permission( false );
        if ( is_wp_error( $auth ) ) {
            $data = (array) $auth->get_error_data();
            $status = max( 400, (int) ( $data['status'] ?? 401 ) );
            $response = new WP_REST_Response( null, $status );
            if ( 401 === $status ) {
                $response->header(
                    'WWW-Authenticate',
                    'Bearer resource_metadata="' . esc_url_raw( PRSTUDIO_UC_MCP_Auth_V5::protected_resource_metadata_url() ) . '", scope="prstudio.read prstudio.write offline_access"'
                );
            }
        } else {
            $response = new WP_REST_Response( null, 200 );
        }
        $response->header( 'Cache-Control', 'no-store' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }

    private static function is_mcp_request( WP_REST_Request $request ): bool {
        return self::ROUTE === (string) $request->get_route();
    }

    private static function schema(): array {
        if ( null !== self::$schema_cache ) { return self::$schema_cache; }
        $path = PRSTUDIO_UC_DIR . self::SCHEMA_FILE;
        $decoded = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
        self::$schema_cache = is_array( $decoded ) ? $decoded : array( 'tools'=>array() );
        return self::$schema_cache;
    }

    private static function tool_map(): array {
        $out = array();
        foreach ( (array) ( self::schema()['tools'] ?? array() ) as $tool ) {
            if ( ! is_array( $tool ) ) { continue; }
            $name = sanitize_key( (string) ( $tool['name'] ?? '' ) );
            if ( '' !== $name ) { $out[ $name ] = $tool; }
        }
        return $out;
    }

    public static function rewrite_tool_call( $result, WP_REST_Server $server, WP_REST_Request $request ) {
        unset( $server );
        if ( null !== $result || ! self::is_mcp_request( $request ) || 'POST' !== strtoupper( (string) $request->get_method() ) ) {
            return $result;
        }

        // Read the raw body instead of get_json_params(): WordPress caches parsed
        // JSON, and we must replace the body before the MCP route parses it.
        $payload = json_decode( (string) $request->get_body(), true );
        if ( ! is_array( $payload ) || self::is_list( $payload ) || 'tools/call' !== (string) ( $payload['method'] ?? '' ) ) {
            return $result;
        }
        $params = is_array( $payload['params'] ?? null ) ? $payload['params'] : array();
        $requested_name = sanitize_key( (string) ( $params['name'] ?? '' ) );
        $map = self::tool_map();
        if ( ! isset( $map[ $requested_name ] ) ) { return $result; }

        $definition = $map[ $requested_name ];
        $arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();
        $operation = (string) ( $definition['operation'] ?? 'tools/call' );
        $bridge_arguments = array(
            'provider' => sanitize_key( (string) ( $definition['provider'] ?? '' ) ),
            'operation' => $operation,
            'requested_tool' => $requested_name,
        );
        if ( 'tools/call' === $operation ) {
            $bridge_arguments['tool'] = sanitize_text_field( (string) ( $arguments['tool'] ?? '' ) );
            $bridge_arguments['arguments'] = is_array( $arguments['arguments'] ?? null ) ? $arguments['arguments'] : array();
        }

        $payload['params']['name'] = 'browser_action';
        $payload['params']['arguments'] = array(
            'action' => 'mcp_bridge_call',
            'arguments' => $bridge_arguments,
        );

        // MCP 2026-07-28 validates the Mcp-Name header against params.name.
        $request->set_header( 'mcp-name', 'browser_action' );
        $request->set_body( wp_json_encode( $payload ) );
        return $result;
    }

    public static function rewrite_tools_list( $response, WP_REST_Server $server, WP_REST_Request $request ) {
        unset( $server );
        if ( ! self::is_mcp_request( $request ) || ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
            return $response;
        }
        $payload = json_decode( (string) $request->get_body(), true );
        if ( ! is_array( $payload ) || 'tools/list' !== (string) ( $payload['method'] ?? '' ) ) { return $response; }

        $data = $response->get_data();
        if ( ! is_array( $data ) || ! is_array( $data['result']['tools'] ?? null ) ) { return $response; }

        $kept = array();
        foreach ( $data['result']['tools'] as $tool ) {
            $name = sanitize_key( (string) ( is_array( $tool ) ? ( $tool['name'] ?? '' ) : '' ) );
            if ( 'browser_status' === $name || ( ! str_starts_with( $name, 'browser_' ) && 'local_studio' !== $name ) ) {
                $kept[] = $tool;
            }
        }
        foreach ( self::tool_map() as $tool ) { $kept[] = self::mcp_tool( $tool ); }

        $data['result']['tools'] = $kept;
        $data['result']['_prstudio_browser_backend'] = array(
            'id' => 'official_mcp_bridge',
            'checked_at' => (string) ( self::schema()['checked_at'] ?? '2026-08-21' ),
            'custom_browser_surface_hidden' => true,
            'schema' => self::SCHEMA_FILE,
            'providers' => array( 'chrome_devtools', 'chrome_webmcp', 'puppeteer', 'selenium' ),
        );
        if ( is_array( $data['result']['_prstudio_surface'] ?? null ) ) {
            $data['result']['_prstudio_surface']['advertised'] = count( $kept );
            $data['result']['_prstudio_surface']['browser_backend'] = 'official_mcp_bridge';
        }
        $response->set_data( $data );
        return $response;
    }

    private static function mcp_tool( array $tool ): array {
        $it = trim( (string) ( $tool['description_it'] ?? '' ) );
        $en = trim( (string) ( $tool['description_en'] ?? '' ) );
        $title_it = trim( (string) ( $tool['title_it'] ?? '' ) );
        $title_en = trim( (string) ( $tool['title_en'] ?? '' ) );
        return array(
            'name' => sanitize_key( (string) ( $tool['name'] ?? '' ) ),
            'title' => trim( $title_it . ( $title_it && $title_en ? ' / ' : '' ) . $title_en ),
            'description' => 'IT: ' . $it . ' EN: ' . $en,
            'inputSchema' => is_array( $tool['inputSchema'] ?? null )
                ? $tool['inputSchema']
                : array( 'type'=>'object', 'properties'=>new stdClass(), 'additionalProperties'=>false ),
            'annotations' => array(
                'readOnlyHint' => ! empty( $tool['readOnlyHint'] ),
                'destructiveHint' => false,
                'idempotentHint' => ! empty( $tool['readOnlyHint'] ),
                'openWorldHint' => true,
            ),
            '_meta' => array(
                'prstudio/provider' => sanitize_key( (string) ( $tool['provider'] ?? '' ) ),
                'prstudio/operation' => (string) ( $tool['operation'] ?? '' ),
                'prstudio/description_it' => $it,
                'prstudio/description_en' => $en,
            ),
        );
    }

    private static function is_list( array $value ): bool {
        return function_exists( 'array_is_list' ) ? array_is_list( $value ) : array_keys( $value ) === range( 0, count( $value ) - 1 );
    }
}
