<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * OAuth 2.1 / PKCE authentication for the PR STUDIO 17.0 ChatGPT Plugin/App.
 * Separate from Browser Agent pairing and from the legacy 2.x bridge tokens.
 */
final class PRSTUDIO_UC_MCP_Auth_V5 {
    private const CLIENTS_OPTION = 'prstudio_mcp_v5_clients';
    private const TOKENS_OPTION = 'prstudio_mcp_v5_tokens';
    private const GENERATION_OPTION = 'prstudio_mcp_v5_generation';
    private const ACCESS_TTL = 14400; // 4h; ChatGPT refreshes via offline_access.
    private const REFRESH_TTL = 31536000; // 365 days.
    private const CODE_TTL = 300;
    private const MAX_CLIENTS = 100;
    private const CIMD_CACHE_TTL = 300;
    private const LAST_USED_TOUCH_INTERVAL = 300;
    private static string $current_token_id = '';

    private static function b64url( string $raw ): string { return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); }
    private static function random_id( int $bytes = 12 ): string { return bin2hex( random_bytes( $bytes ) ); }
    private static function secret_hash( string $value ): string { return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) . '|prstudio-mcp-v5' ); }
    private static function generation(): int { return max( 1, (int) get_option( self::GENERATION_OPTION, 1 ) ); }
    private static function admin_capability(): string { return is_multisite() ? 'manage_network_options' : 'manage_options'; }
    private static function can_administer(): bool { return current_user_can( self::admin_capability() ) || current_user_can( 'manage_options' ); }

    public static function mcp_url(): string { return rest_url( 'prstudio-unified/v1/mcp' ); }
    public static function issuer(): string { return untrailingslashit( home_url() ); }
    public static function protected_resource_metadata_url(): string { return home_url( '/.well-known/oauth-protected-resource' ); }
    public static function authorization_server_metadata_url(): string { return home_url( '/.well-known/oauth-authorization-server' ); }
    public static function authorization_endpoint(): string { return admin_url( 'admin-post.php?action=prstudio_mcp_v5_authorize' ); }
    public static function token_endpoint(): string { return rest_url( 'prstudio-unified/v1/oauth/token' ); }
    public static function registration_endpoint(): string { return rest_url( 'prstudio-unified/v1/oauth/register' ); }

    public static function protected_resource_metadata(): array {
        return array(
            'resource' => self::mcp_url(),
            'authorization_servers' => array( self::issuer() ),
            'scopes_supported' => array( 'prstudio.read', 'prstudio.write', 'offline_access' ),
            'bearer_methods_supported' => array( 'header' ),
            'resource_documentation' => admin_url( 'tools.php?page=prstudio-unified-browser' ),
        );
    }

    public static function authorization_server_metadata(): array {
        return array(
            'issuer' => self::issuer(),
            'authorization_endpoint' => self::authorization_endpoint(),
            'token_endpoint' => self::token_endpoint(),
            // DCR remains advertised for 2025-era and client compatibility, while
            // MCP 2026 clients can use URL-form Client ID Metadata Documents.
            'registration_endpoint' => self::registration_endpoint(),
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'response_types_supported' => array( 'code' ),
            'grant_types_supported' => array( 'authorization_code', 'refresh_token' ),
            'code_challenge_methods_supported' => array( 'S256' ),
            'token_endpoint_auth_methods_supported' => array( 'none' ),
            'scopes_supported' => array( 'prstudio.read', 'prstudio.write', 'offline_access' ),
            'service_documentation' => admin_url( 'tools.php?page=prstudio-unified-browser' ),
        );
    }

    public static function maybe_serve_well_known(): void {
        $path = untrailingslashit( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
        $protected = untrailingslashit( (string) wp_parse_url( self::protected_resource_metadata_url(), PHP_URL_PATH ) );
        $authorization = untrailingslashit( (string) wp_parse_url( self::authorization_server_metadata_url(), PHP_URL_PATH ) );
        if ( $path !== $protected && $path !== $authorization ) { return; }
        if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) { status_header( 405 ); header( 'Allow: GET' ); exit; }
        nocache_headers();
        header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
        header( 'Access-Control-Allow-Origin: *' );
        $payload = $path === $protected ? self::protected_resource_metadata() : self::authorization_server_metadata();
        echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    private static function normalize_scope( string $scope ): string {
        $requested = preg_split( '/\s+/', trim( $scope ) );
        $allowed = array( 'prstudio.read', 'prstudio.write', 'offline_access' );
        $out = array_values( array_intersect( $allowed, is_array( $requested ) ? $requested : array() ) );
        if ( ! in_array( 'prstudio.read', $out, true ) ) { $out[] = 'prstudio.read'; }
        return implode( ' ', array_values( array_unique( $out ) ) );
    }

    private static function valid_redirect_uri( string $uri ): bool {
        if ( '' === $uri || strlen( $uri ) > 2048 ) { return false; }
        $parts = wp_parse_url( $uri );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return false; }
        $scheme = strtolower( (string) $parts['scheme'] );
        $host = strtolower( (string) $parts['host'] );
        if ( 'http' === $scheme ) { return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ); }
        if ( 'https' !== $scheme ) { return false; }
        if ( $host === 'chatgpt.com' || str_ends_with( $host, '.chatgpt.com' ) || $host === 'openai.com' || str_ends_with( $host, '.openai.com' ) ) { return true; }
        $allowed = (array) apply_filters( 'prstudio_mcp_v5_allowed_redirect_hosts', array() );
        return in_array( $host, array_map( 'strtolower', array_map( 'strval', $allowed ) ), true );
    }

    public static function register_client( array $payload ) {
        $ip = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        $bucket = 'prstudio_mcp_v5_dcr_' . substr( hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) ), 0, 32 );
        $count = (int) get_transient( $bucket );
        if ( $count >= 20 ) { return new WP_Error( 'too_many_requests', 'Troppe registrazioni client.', array( 'status' => 429 ) ); }
        set_transient( $bucket, $count + 1, HOUR_IN_SECONDS );
        $redirects = isset( $payload['redirect_uris'] ) && is_array( $payload['redirect_uris'] ) ? array_slice( $payload['redirect_uris'], 0, 10 ) : array();
        if ( ! $redirects ) { return new WP_Error( 'invalid_client_metadata', 'redirect_uris è obbligatorio.', array( 'status' => 400 ) ); }
        $clean = array();
        foreach ( $redirects as $uri ) {
            $uri = esc_url_raw( (string) $uri );
            if ( ! self::valid_redirect_uri( $uri ) ) { return new WP_Error( 'invalid_redirect_uri', 'Redirect URI non consentito.', array( 'status' => 400 ) ); }
            $clean[] = $uri;
        }
        $clients = get_option( self::CLIENTS_OPTION, array() );
        $clients = is_array( $clients ) ? $clients : array();
        if ( count( $clients ) >= self::MAX_CLIENTS ) { $clients = array_slice( $clients, -80, null, true ); }
        $id = 'prstudio_client_' . self::random_id( 18 );
        $application_type = sanitize_key( (string) ( $payload['application_type'] ?? '' ) );
        if ( ! in_array( $application_type, array( 'native', 'web' ), true ) ) {
            $application_type = 'web';
            foreach ( $clean as $redirect_uri ) {
                $host = strtolower( (string) wp_parse_url( $redirect_uri, PHP_URL_HOST ) );
                if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) { $application_type = 'native'; break; }
            }
        }
        $record = array(
            'client_id' => $id,
            'client_name' => sanitize_text_field( (string) ( $payload['client_name'] ?? 'RP Studio Connector' ) ),
            'redirect_uris' => array_values( array_unique( $clean ) ),
            'grant_types' => array( 'authorization_code', 'refresh_token' ),
            'response_types' => array( 'code' ),
            'token_endpoint_auth_method' => 'none',
            'application_type' => $application_type,
            /* OAuth authorization is protocol-required access control, not a mutation approval gate. */
            'scope' => self::normalize_scope( sanitize_text_field( (string) ( $payload['scope'] ?? 'prstudio.read prstudio.write offline_access' ) ) ),
            'client_id_issued_at' => time(),
        );
        $clients[ $id ] = $record;
        update_option( self::CLIENTS_OPTION, $clients, false );
        self::audit( 'oauth.client_register', array( 'client_id' => $id ) );
        return $record;
    }

    private static function client( string $id ): ?array {
        $clients = get_option( self::CLIENTS_OPTION, array() );
        if ( is_array( $clients ) && isset( $clients[ $id ] ) && is_array( $clients[ $id ] ) ) { return $clients[ $id ]; }
        return self::client_metadata_document( $id );
    }

    /** Resolve an MCP 2026 URL-form client_id without creating server-side client rows. */
    private static function client_metadata_document( string $client_id ): ?array {
        if ( '' === $client_id || strlen( $client_id ) > 2048 ) { return null; }
        $parts = wp_parse_url( $client_id );
        if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return null; }
        $cache_key = 'prstudio_mcp_cimd_' . substr( hash( 'sha256', $client_id ), 0, 32 );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && hash_equals( (string) ( $cached['client_id'] ?? '' ), $client_id ) ) { return $cached; }
        $response = wp_safe_remote_get( $client_id, array(
            'timeout' => 3, 'redirection' => 0, 'limit_response_size' => 65536,
            'headers' => array( 'Accept' => 'application/json' ),
            'user-agent' => 'PR-STUDIO-MCP-CIMD/' . ( defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : '17.0.0' ),
        ) );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return null; }
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! hash_equals( $client_id, (string) ( $body['client_id'] ?? '' ) ) ) { return null; }
        $redirects = array_slice( is_array( $body['redirect_uris'] ?? null ) ? $body['redirect_uris'] : array(), 0, 20 );
        $clean = array();
        foreach ( $redirects as $uri ) {
            $uri = esc_url_raw( (string) $uri );
            if ( ! self::valid_redirect_uri( $uri ) ) { return null; }
            $clean[] = $uri;
        }
        if ( ! $clean ) { return null; }
        $grant_types = array_values( array_filter( array_map( 'sanitize_key', is_array( $body['grant_types'] ?? null ) ? $body['grant_types'] : array( 'authorization_code' ) ) ) );
        $response_types = array_values( array_filter( array_map( 'sanitize_key', is_array( $body['response_types'] ?? null ) ? $body['response_types'] : array( 'code' ) ) ) );
        if ( ! in_array( 'authorization_code', $grant_types, true ) || ! in_array( 'code', $response_types, true ) ) { return null; }
        $token_method = sanitize_text_field( (string) ( $body['token_endpoint_auth_method'] ?? 'none' ) );
        if ( 'none' !== $token_method ) { return null; }
        $record = array(
            'client_id' => $client_id,
            'client_name' => sanitize_text_field( (string) ( $body['client_name'] ?? wp_parse_url( $client_id, PHP_URL_HOST ) ) ),
            'redirect_uris' => array_values( array_unique( $clean ) ),
            'grant_types' => $grant_types,
            'response_types' => $response_types,
            'token_endpoint_auth_method' => 'none',
            'application_type' => in_array( sanitize_key( (string) ( $body['application_type'] ?? '' ) ), array( 'native', 'web' ), true ) ? sanitize_key( (string) $body['application_type'] ) : 'web',
            'scope' => self::normalize_scope( sanitize_text_field( (string) ( $body['scope'] ?? 'prstudio.read prstudio.write offline_access' ) ) ),
            'metadata_document' => true,
        );
        set_transient( $cache_key, $record, self::CIMD_CACHE_TTL );
        self::audit( 'oauth.client_metadata_resolve', array( 'client_id' => $client_id ) );
        return $record;
    }

    public static function handle_authorize(): void {
        $params = wp_unslash( $_REQUEST );
        $client_id = sanitize_text_field( (string) ( $params['client_id'] ?? '' ) );
        $redirect_uri = esc_url_raw( (string) ( $params['redirect_uri'] ?? '' ) );
        $state = sanitize_text_field( (string) ( $params['state'] ?? '' ) );
        $scope = self::normalize_scope( sanitize_text_field( (string) ( $params['scope'] ?? '' ) ) );
        $challenge = sanitize_text_field( (string) ( $params['code_challenge'] ?? '' ) );
        $challenge_method = sanitize_text_field( (string) ( $params['code_challenge_method'] ?? '' ) );
        $resource = esc_url_raw( (string) ( $params['resource'] ?? self::mcp_url() ) );
        $client = self::client( $client_id );
        if ( ! isset( $params['scope'] ) || '' === trim( (string) $params['scope'] ) ) {
            $scope = self::normalize_scope( (string) ( $client['scope'] ?? 'prstudio.read' ) );
        }
        $registered_scopes=preg_split('/\s+/',(string)($client['scope']??'prstudio.read'))?:array();
        $requested_scopes=preg_split('/\s+/',$scope)?:array();
        $scope_allowed=!array_diff($requested_scopes,$registered_scopes);
        $valid = $client && in_array( $redirect_uri, (array) ( $client['redirect_uris'] ?? array() ), true ) &&
            'code' === (string) ( $params['response_type'] ?? '' ) && 'S256' === $challenge_method && 1 === preg_match('/^[A-Za-z0-9_-]{43}$/',$challenge) &&
            $scope_allowed && untrailingslashit( $resource ) === untrailingslashit( self::mcp_url() );
        if ( ! $valid ) { wp_die( 'Richiesta OAuth non valida.', 'PR STUDIO OAuth', array( 'response' => 400 ) ); }
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( add_query_arg( array_filter( $params, 'is_scalar' ), self::authorization_endpoint() ) ) );
            exit;
        }
        if ( ! self::can_administer() ) { wp_die( 'Solo un amministratore WordPress può autorizzare PR STUDIO.', 'Accesso negato', array( 'response' => 403 ) ); }
        if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
            check_admin_referer( 'prstudio_mcp_v5_approve' );
            $decision = sanitize_key( (string) ( $params['decision'] ?? '' ) );
            if ( 'deny' === $decision ) {
                wp_redirect( add_query_arg( array( 'error' => 'access_denied', 'state' => $state, 'iss' => self::issuer() ), $redirect_uri ), 302, 'PR STUDIO OAuth' ); exit;
            }
            if ( 'approve' !== $decision ) { wp_die( 'Decisione non valida.', 'PR STUDIO OAuth', array( 'response' => 400 ) ); }
            $id = self::random_id( 10 );
            $code = 'prstudio_ac_' . $id . '_' . self::b64url( random_bytes( 32 ) );
            set_transient( 'prstudio_mcp_v5_code_' . $id, array(
                'hash' => self::secret_hash( $code ), 'client_id' => $client_id, 'redirect_uri' => $redirect_uri,
                'scope' => $scope, 'code_challenge' => $challenge, 'resource' => $resource,
                'generation' => self::generation(), 'created_at' => time(),
            ), self::CODE_TTL );
            wp_redirect( add_query_arg( array( 'code' => $code, 'state' => $state, 'iss' => self::issuer() ), $redirect_uri ), 302, 'PR STUDIO OAuth' );
            exit;
        }
        $hidden = array( 'client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' );
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Autorizza RP Studio Connector</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;margin:0;padding:40px 20px;color:#1d2327}.box{max-width:680px;margin:auto;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:28px}.scope{font-family:monospace;background:#f6f7f7;padding:10px;border-radius:4px}.buttons{display:flex;gap:12px;margin-top:22px}.button{padding:11px 18px;border-radius:4px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer}.secondary{background:#fff;color:#2c3338;border-color:#8c8f94}</style></head><body><div class="box"><h1>Autorizza RP Studio Connector</h1><p>ChatGPT richiede accesso a PR STUDIO Unified Control 17.0 sul tuo WordPress.</p><p class="scope"><?php echo esc_html( $scope ); ?></p><?php if ( str_contains( $scope, 'prstudio.write' ) ) : ?><p><strong>Lo scope di scrittura consente azioni operative.</strong> Questa schermata autorizza il client OAuth; non introduce approvazioni per le singole operazioni.</p><?php endif; ?><p>Il Browser Agent mantiene una credenziale separata; questa autorizzazione non modifica il pairing dell’estensione.</p><form method="post" action="<?php echo esc_url( self::authorization_endpoint() ); ?>"><?php wp_nonce_field( 'prstudio_mcp_v5_approve' ); foreach ( $hidden as $key ) { if ( isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $params[ $key ] ) . '">'; } } ?><div class="buttons"><button class="button" name="decision" value="approve" type="submit">Autorizza</button><button class="button secondary" name="decision" value="deny" type="submit">Nega</button></div></form></div></body></html><?php
        exit;
    }

    public static function token_exchange( WP_REST_Request $request ) {
        $grant = sanitize_text_field( (string) $request->get_param( 'grant_type' ) );
        $client_id = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
        if ( 'authorization_code' === $grant ) { return self::exchange_code( $request, $client_id ); }
        if ( 'refresh_token' === $grant ) { return self::exchange_refresh( $request, $client_id ); }
        return new WP_Error( 'unsupported_grant_type', 'grant_type non supportato.', array( 'status' => 400 ) );
    }

    private static function exchange_code( WP_REST_Request $request, string $client_id ) {
        $code = (string) $request->get_param( 'code' );
        if ( ! preg_match( '/^prstudio_ac_([^_]+)_(.+)$/', $code, $m ) ) { return new WP_Error( 'invalid_grant', 'Codice non valido.', array( 'status' => 400 ) ); }
        $id = sanitize_key( $m[1] );
        $record = get_transient( 'prstudio_mcp_v5_code_' . $id );
        delete_transient( 'prstudio_mcp_v5_code_' . $id );
        if ( ! is_array( $record ) || ! hash_equals( (string) ( $record['hash'] ?? '' ), self::secret_hash( $code ) ) ) { return new WP_Error( 'invalid_grant', 'Codice scaduto o già utilizzato.', array( 'status' => 400 ) ); }
        $redirect = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );
        if ( $client_id !== (string) $record['client_id'] || $redirect !== (string) $record['redirect_uri'] ) { return new WP_Error( 'invalid_grant', 'Client o redirect URI non corrispondente.', array( 'status' => 400 ) ); }
        $resource = esc_url_raw( (string) $request->get_param( 'resource' ) );
        if ( '' === $resource ) { $resource = self::mcp_url(); }
        if ( untrailingslashit( $resource ) !== untrailingslashit( (string) $record['resource'] ) || untrailingslashit( $resource ) !== untrailingslashit( self::mcp_url() ) ) { return new WP_Error( 'invalid_target', 'Resource OAuth non corrispondente.', array( 'status' => 400 ) ); }
        $verifier = (string) $request->get_param( 'code_verifier' );
        if ( 1 !== preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) ) { return new WP_Error( 'invalid_grant', 'Formato code_verifier PKCE non valido.', array( 'status' => 400 ) ); }
        $expected = self::b64url( hash( 'sha256', $verifier, true ) );
        if ( '' === $verifier || ! hash_equals( (string) $record['code_challenge'], $expected ) ) { return new WP_Error( 'invalid_grant', 'Verifica PKCE fallita.', array( 'status' => 400 ) ); }
        if ( (int) $record['generation'] !== self::generation() ) { return new WP_Error( 'invalid_grant', 'Autorizzazione revocata.', array( 'status' => 400 ) ); }
        return self::issue_tokens( $client_id, (string) $record['scope'], (string) $record['resource'] );
    }

    private static function exchange_refresh( WP_REST_Request $request, string $client_id ) {
        $token = (string) $request->get_param( 'refresh_token' );
        $record = self::verify_refresh_token( $token );
        if ( is_wp_error( $record ) ) { return $record; }
        if ( $client_id !== (string) $record['client_id'] ) { return new WP_Error( 'invalid_grant', 'Client non corrispondente.', array( 'status' => 400 ) ); }
        $resource = esc_url_raw( (string) $request->get_param( 'resource' ) );
        if ( '' !== $resource && untrailingslashit( $resource ) !== untrailingslashit( (string) $record['resource'] ) ) { return new WP_Error( 'invalid_target', 'Resource OAuth non corrispondente.', array( 'status' => 400 ) ); }
        self::delete_token_record( (string) $record['id'] );
        return self::issue_tokens( $client_id, (string) $record['scope'], (string) $record['resource'] );
    }

    private static function issue_tokens( string $client_id, string $scope, string $resource ): array {
        $id = self::random_id( 10 );
        $access = 'prstudio_at_' . $id . '_' . self::b64url( random_bytes( 32 ) );
        $scope = self::normalize_scope( $scope );
        $offline = in_array( 'offline_access', preg_split( '/\s+/', $scope ) ?: array(), true );
        $refresh = $offline ? 'prstudio_rt_' . $id . '_' . self::b64url( random_bytes( 40 ) ) : '';
        $now = time();
        $tokens = get_option( self::TOKENS_OPTION, array() );
        $tokens = is_array( $tokens ) ? $tokens : array();
        foreach ( $tokens as $token_id => $record ) {
            if ( ! is_array( $record ) || (int) ( $record['refresh_exp'] ?? 0 ) < $now ) { unset( $tokens[ $token_id ] ); }
        }
        if ( count( $tokens ) > 200 ) { $tokens = array_slice( $tokens, -150, null, true ); }
        $tokens[ $id ] = array(
            'id' => $id, 'access_hash' => self::secret_hash( $access ), 'refresh_hash' => $offline ? self::secret_hash( $refresh ) : '',
            'access_exp' => $now + self::ACCESS_TTL, 'refresh_exp' => $offline ? $now + self::REFRESH_TTL : $now + self::ACCESS_TTL,
            'client_id' => $client_id, 'scope' => $scope, 'resource' => $resource,
            'generation' => self::generation(), 'created_at' => $now, 'last_used' => 0,
        );
        update_option( self::TOKENS_OPTION, $tokens, false );
        self::audit( 'oauth.token_issue', array( 'client_id' => $client_id, 'scope' => $scope ) );
        $response = array(
            'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL,
            'scope' => $scope, 'resource' => $resource,
        );
        if ( $offline ) { $response['refresh_token'] = $refresh; }
        return $response;
    }

    private static function parse_token( string $token, string $prefix ): ?array {
        if ( ! preg_match( '/^' . preg_quote( $prefix, '/' ) . '_([^_]+)_(.+)$/', $token, $m ) ) { return null; }
        return array( 'id' => sanitize_key( $m[1] ), 'token' => $token );
    }

    private static function verify_refresh_token( string $token ) {
        $parsed = self::parse_token( $token, 'prstudio_rt' );
        if ( ! $parsed ) { return new WP_Error( 'invalid_grant', 'Refresh token non valido.', array( 'status' => 400 ) ); }
        $tokens = get_option( self::TOKENS_OPTION, array() );
        $record = is_array( $tokens ) && isset( $tokens[ $parsed['id'] ] ) ? $tokens[ $parsed['id'] ] : null;
        if ( ! is_array( $record ) || ! hash_equals( (string) ( $record['refresh_hash'] ?? '' ), self::secret_hash( $token ) ) ) { return new WP_Error( 'invalid_grant', 'Refresh token revocato.', array( 'status' => 400 ) ); }
        if ( time() >= (int) ( $record['refresh_exp'] ?? 0 ) ) { self::delete_token_record( (string) $record['id'] ); return new WP_Error( 'invalid_grant', 'Refresh token scaduto.', array( 'status' => 400 ) ); }
        if ( (int) $record['generation'] !== self::generation() ) { return new WP_Error( 'invalid_grant', 'Autorizzazione revocata.', array( 'status' => 400 ) ); }
        return $record;
    }

    private static function delete_token_record( string $id ): void {
        $tokens = get_option( self::TOKENS_OPTION, array() );
        if ( is_array( $tokens ) && isset( $tokens[ $id ] ) ) { unset( $tokens[ $id ] ); update_option( self::TOKENS_OPTION, $tokens, false ); }
    }

    public static function bearer_token_from_request(): string {
        $header = '';
        foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization' ) as $key ) {
            if ( isset( $_SERVER[ $key ] ) && '' !== trim( (string) $_SERVER[ $key ] ) ) { $header = (string) wp_unslash( $_SERVER[ $key ] ); break; }
        }
        if ( '' === $header ) {
            foreach ( array( 'getallheaders', 'apache_request_headers' ) as $fn ) {
                if ( ! function_exists( $fn ) ) { continue; }
                $headers = call_user_func( $fn );
                if ( is_array( $headers ) ) { foreach ( $headers as $name => $value ) { if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) { $header = (string) $value; break 2; } } }
            }
        }
        return preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $m ) ? trim( $m[1] ) : '';
    }

    public static function verify_access_token( string $token, bool $write = false ) {
        if ( false === is_ssl() && ( ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() ) ) { return new WP_Error( 'https_required', 'HTTPS è obbligatorio.', array( 'status' => 403 ) ); }
        $parsed = self::parse_token( $token, 'prstudio_at' );
        if ( ! $parsed ) { return new WP_Error( 'invalid_token', 'Bearer token non valido.', array( 'status' => 401 ) ); }
        $tokens = get_option( self::TOKENS_OPTION, array() );
        $record = is_array( $tokens ) && isset( $tokens[ $parsed['id'] ] ) ? $tokens[ $parsed['id'] ] : null;
        if ( ! is_array( $record ) || ! hash_equals( (string) ( $record['access_hash'] ?? '' ), self::secret_hash( $token ) ) ) { return new WP_Error( 'invalid_token', 'Token revocato.', array( 'status' => 401 ) ); }
        if ( time() >= (int) ( $record['access_exp'] ?? 0 ) ) { return new WP_Error( 'invalid_token', 'Access token scaduto; usa il refresh token.', array( 'status' => 401, 'refresh_supported' => true ) ); }
        if ( (int) $record['generation'] !== self::generation() ) { return new WP_Error( 'invalid_token', 'Autorizzazione revocata.', array( 'status' => 401 ) ); }
        if ( untrailingslashit( (string) ( $record['resource'] ?? '' ) ) !== untrailingslashit( self::mcp_url() ) ) { return new WP_Error( 'invalid_token', 'Audience token non valida.', array( 'status' => 401 ) ); }
        $scopes = preg_split( '/\s+/', (string) ( $record['scope'] ?? '' ) );
        if ( $write && ! in_array( 'prstudio.write', is_array( $scopes ) ? $scopes : array(), true ) ) { return new WP_Error( 'insufficient_scope', 'Scope prstudio.write richiesto.', array( 'status' => 403 ) ); }
        if ( ! self::rate_limit( (string) $record['id'] ) ) { return new WP_Error( 'rate_limited', 'Troppe richieste.', array( 'status' => 429 ) ); }
        self::$current_token_id = (string) $record['id'];
        // Avoid serializing/writing the complete token registry on every MCP call.
        // Persist a durable last-used touch at most once per five-minute window.
        $now = time();
        if ( $now - (int) ( $record['last_used'] ?? 0 ) >= self::LAST_USED_TOUCH_INTERVAL ) {
            $record['last_used'] = $now;
            $tokens[ $record['id'] ] = $record;
            update_option( self::TOKENS_OPTION, $tokens, false );
        }
        return $record;
    }

    public static function permission( bool $write = false ) {
        $token = self::bearer_token_from_request();
        if ( '' === $token ) { return new WP_Error( 'invalid_token', 'Bearer token richiesto.', array( 'status' => 401 ) ); }
        return self::verify_access_token( $token, $write );
    }

    private static function rate_limit( string $id ): bool {
        $limit = (int) apply_filters( 'prstudio_mcp_v5_rate_limit_per_minute', 240 );
        $limit = max( 30, min( 2000, $limit ) );
        $key = 'prstudio_mcp_v5_rl_' . md5( $id . '|' . gmdate( 'YmdHi' ) );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) { return false; }
        set_transient( $key, $count + 1, 90 );
        return true;
    }

    public static function revoke_all(): void {
        update_option( self::GENERATION_OPTION, self::generation() + 1, false );
        delete_option( self::TOKENS_OPTION );
        self::audit( 'oauth.revoke_all', array() );
    }

    public static function status(): array {
        $tokens = get_option( self::TOKENS_OPTION, array() );
        $clients = get_option( self::CLIENTS_OPTION, array() );
        $active = 0; $now = time();
        foreach ( is_array( $tokens ) ? $tokens : array() as $record ) { if ( is_array( $record ) && (int) ( $record['refresh_exp'] ?? 0 ) > $now ) { $active++; } }
        return array(
            'mode' => 'oauth2_pkce_refresh', 'offline_access' => true, 'active_authorizations' => $active,
            'registered_clients' => is_array( $clients ) ? count( $clients ) : 0,
            'access_ttl_seconds' => self::ACCESS_TTL, 'refresh_ttl_seconds' => self::REFRESH_TTL,
            'browser_pairing_independent' => true,
        );
    }

    private static function audit( string $event, array $details ): void {
        if ( class_exists( 'WPAIB_Audit' ) && method_exists( 'WPAIB_Audit', 'log' ) ) { WPAIB_Audit::log( 'prstudio_v5.' . $event, 'success', '', PRSTUDIO_UC_Memory::redact( $details ) ); }
    }
}
