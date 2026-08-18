<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPAIB_Auth {
	private static string $current_token_id = '';
	private static string $authorization_header_source = '';
	private const TOKEN_TTL = HOUR_IN_SECONDS;
	private const CODE_TTL = 300;

	public static function settings(): array {
		$defaults = array(
			'max_file_bytes' => 8 * 1024 * 1024,
			'rate_limit_per_min' => 600,
			'generation' => 1,
			'report_email' => 'russobang@gmail.com',
			'report_enabled' => true,
			'market_country' => 'IT',
			'market_region' => 'Sicilia',
			'market_province' => 'Agrigento',
			'allowed_origins' => array( 'https://chatgpt.com', 'https://chat.openai.com', 'https://platform.openai.com' ),
		);
		$value = get_option( 'wpaib_settings', array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), $defaults );
	}

	public static function update_settings( array $new ): void {
		$current = self::settings();
		$allowed = array(
			'max_file_bytes','rate_limit_per_min','report_email','report_enabled',
			'market_country','market_region','market_province','allowed_origins',
		);
		foreach ( $allowed as $key ) { if ( array_key_exists( $key, $new ) ) { $current[ $key ] = $new[ $key ]; } }
		$current['report_email'] = sanitize_email( (string) $current['report_email'] );
		$current['allowed_origins'] = array_values( array_filter( array_map( 'esc_url_raw', is_array( $current['allowed_origins'] ?? null ) ? $current['allowed_origins'] : array() ) ) );
		$current['max_file_bytes'] = max( 65536, min( 32 * 1024 * 1024, (int) $current['max_file_bytes'] ) );
		$current['rate_limit_per_min'] = max( 10, min( 5000, (int) $current['rate_limit_per_min'] ) );
		update_option( 'wpaib_settings', $current, false );
	}

	public static function admin_capability(): string { return is_multisite() ? 'manage_network_options' : 'manage_options'; }
	public static function can_administer(): bool { return current_user_can( self::admin_capability() ) || current_user_can( 'manage_options' ); }
	private static function b64url( string $raw ): string { return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); }
	private static function secret_hash( string $value ): string { return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) ); }
	private static function random_id( int $bytes = 9 ): string { return bin2hex( random_bytes( $bytes ) ); }

	public static function rotate_pairing_key(): string {
		$id = self::random_id( 8 );
		$key = 'wpaib_pk_' . $id . '_' . self::b64url( random_bytes( 32 ) );
		$settings = self::settings();
		$settings['generation'] = (int) $settings['generation'] + 1;
		update_option( 'wpaib_settings', $settings, false );
		update_option( 'wpaib_pairing_key', array( 'id' => $id, 'hash' => self::secret_hash( $key ), 'created_at' => time(), 'generation' => (int) $settings['generation'] ), false );
		delete_option( 'wpaib_oauth_tokens' );
		WPAIB_Audit::log( 'pairing_key.rotate', 'success', '', array(), $id );
		return $key;
	}

	public static function revoke_all( bool $remove_pairing_key = false ): void {
		$settings = self::settings();
		$settings['generation'] = (int) $settings['generation'] + 1;
		update_option( 'wpaib_settings', $settings, false );
		delete_option( 'wpaib_oauth_tokens' );
		if ( $remove_pairing_key ) { delete_option( 'wpaib_pairing_key' ); }
		WPAIB_Audit::log( 'auth.revoke_all', 'success' );
	}

	public static function pairing_key_info(): array {
		$value = get_option( 'wpaib_pairing_key', array() );
		if ( ! is_array( $value ) ) { return array(); }
		unset( $value['hash'] );
		return $value;
	}

	public static function verify_pairing_key( string $key ): bool {
		$record = get_option( 'wpaib_pairing_key', array() );
		if ( ! is_array( $record ) || empty( $record['hash'] ) ) { return false; }
		if ( (int) ( $record['generation'] ?? 0 ) !== (int) self::settings()['generation'] ) { return false; }
		return hash_equals( (string) $record['hash'], self::secret_hash( trim( $key ) ) );
	}


	public static function api_key_from_request(): string {
		$header = isset( $_SERVER['HTTP_X_IM_ADMIN_KEY'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_IM_ADMIN_KEY'] ) : '';
		if ( '' === trim( $header ) && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( 0 === strcasecmp( (string) $name, 'X-IM-Admin-Key' ) ) { $header = (string) $value; break; }
				}
			}
		}
		return trim( $header );
	}

	public static function api_key_permission( bool $write = false ) {
		$settings = self::settings();
		if ( false === is_ssl() && 'local' !== wp_get_environment_type() ) { return new WP_Error( 'wpaib_https_required', 'HTTPS è obbligatorio.', array( 'status' => 403 ) ); }
		$key = self::api_key_from_request();
		if ( '' === $key || ! self::verify_pairing_key( $key ) ) { return new WP_Error( 'wpaib_api_key_invalid', 'X-IM-Admin-Key non valida.', array( 'status' => 401 ) ); }
		$id = 'api-key:' . (string) ( self::pairing_key_info()['id'] ?? 'unknown' );
		if ( ! self::rate_limit( $id ) ) { return new WP_Error( 'wpaib_rate_limited', 'Troppe richieste.', array( 'status' => 429 ) ); }
		self::$current_token_id = $id;
		return array( 'id' => $id, 'scope' => $write ? 'wp_ai_bridge.read wp_ai_bridge.write' : 'wp_ai_bridge.read', 'auth_type' => 'api_key' );
	}

	public static function api_or_oauth_permission( bool $write = false ) {
		if ( '' !== self::api_key_from_request() ) { return self::api_key_permission( $write ); }
		return self::permission( $write );
	}

	public static function mcp_url(): string { return rest_url( 'wp-ai-bridge/v1/mcp' ); }
	public static function protected_resource_metadata_url(): string { return rest_url( 'wp-ai-bridge/v1/oauth/protected-resource' ); }
	public static function protected_resource_well_known_url(): string { return home_url( '/.well-known/oauth-protected-resource' ); }
	public static function authorization_server_issuer(): string { return untrailingslashit( home_url() ); }
	public static function authorization_server_metadata_url(): string { return home_url( '/.well-known/oauth-authorization-server' ); }
	public static function authorization_endpoint(): string { return admin_url( 'admin-post.php?action=wpaib_oauth_authorize' ); }
	public static function token_endpoint(): string { return rest_url( 'wp-ai-bridge/v1/oauth/token' ); }
	public static function registration_endpoint(): string { return rest_url( 'wp-ai-bridge/v1/oauth/register' ); }

	private static function valid_redirect_uri( string $uri ): bool {
		if ( '' === $uri || strlen( $uri ) > 2048 ) { return false; }
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return false; }
		$scheme = strtolower( (string) $parts['scheme'] ); $host = strtolower( (string) $parts['host'] );
		if ( 'http' === $scheme ) { return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ); }
		if ( 'https' !== $scheme ) { return false; }
		$port = isset( $parts['port'] ) ? absint( $parts['port'] ) : 443;
		$settings = self::settings();
		foreach ( (array) ( $settings['allowed_origins'] ?? array() ) as $allowed ) {
			$allowed_parts = wp_parse_url( (string) $allowed );
			$allowed_port = is_array( $allowed_parts ) && isset( $allowed_parts['port'] ) ? absint( $allowed_parts['port'] ) : 443;
			if ( is_array( $allowed_parts ) && strtolower( (string) ( $allowed_parts['scheme'] ?? '' ) ) === 'https' && strtolower( (string) ( $allowed_parts['host'] ?? '' ) ) === $host && $allowed_port === $port ) { return true; }
		}
		return false;
	}

	public static function register_client( array $payload ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$bucket = 'wpaib_dcr_' . hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) . '|' . gmdate( 'YmdH' ) );
		$count = (int) get_transient( $bucket );
		if ( $count >= 20 ) { return new WP_Error( 'too_many_requests', 'Troppe registrazioni client.', array( 'status' => 429 ) ); }
		set_transient( $bucket, $count + 1, HOUR_IN_SECONDS + 60 );
		$redirects = isset( $payload['redirect_uris'] ) && is_array( $payload['redirect_uris'] ) ? array_slice( $payload['redirect_uris'], 0, 10 ) : array();
		if ( empty( $redirects ) ) { return new WP_Error( 'wpaib_invalid_client', 'redirect_uris è obbligatorio.', array( 'status' => 400 ) ); }
		$clean = array();
		foreach ( $redirects as $uri ) {
			$uri = esc_url_raw( (string) $uri );
			if ( ! self::valid_redirect_uri( $uri ) ) { return new WP_Error( 'wpaib_invalid_redirect_uri', 'Redirect URI non consentito.', array( 'status' => 400 ) ); }
			$clean[] = $uri;
		}
		$client_id = 'wpaib_client_' . self::random_id( 18 );
		$clients = get_option( 'wpaib_oauth_clients', array() );
		$clients = is_array( $clients ) ? $clients : array();
		$clients[ $client_id ] = array(
			'client_id' => $client_id,
			'client_name' => sanitize_text_field( (string) ( $payload['client_name'] ?? 'PR STUDIO MCP Client' ) ),
			'redirect_uris' => array_values( array_unique( $clean ) ),
			'grant_types' => array( 'authorization_code', 'refresh_token' ),
			'response_types' => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'created_at' => time(),
			'client_id_issued_at' => time(),
		);
		update_option( 'wpaib_oauth_clients', $clients, false );
		WPAIB_Audit::log( 'oauth.client_register', 'success', $client_id );
		return $clients[ $client_id ];
	}

	private static function get_client( string $client_id ): ?array {
		$clients = get_option( 'wpaib_oauth_clients', array() );
		return is_array( $clients ) && isset( $clients[ $client_id ] ) && is_array( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
	}
	private static function redirect_uri_allowed( array $client, string $redirect_uri ): bool { return in_array( $redirect_uri, $client['redirect_uris'] ?? array(), true ); }
	private static function normalize_scope( string $scope ): string {
		$requested = preg_split( '/\s+/', trim( $scope ) );
		$result = array_values( array_intersect( array( 'wp_ai_bridge.read', 'wp_ai_bridge.write', 'offline_access' ), is_array( $requested ) ? $requested : array() ) );
		return implode( ' ', array_unique( empty( $result ) ? array( 'wp_ai_bridge.read' ) : $result ) );
	}

	public static function handle_authorize(): void {
		$params = wp_unslash( $_REQUEST );
		$client_id = sanitize_text_field( (string) ( $params['client_id'] ?? '' ) );
		$redirect_uri = esc_url_raw( (string) ( $params['redirect_uri'] ?? '' ) );
		$state = sanitize_text_field( (string) ( $params['state'] ?? '' ) );
		$scope = sanitize_text_field( (string) ( $params['scope'] ?? 'wp_ai_bridge.read wp_ai_bridge.write' ) );
		$challenge = sanitize_text_field( (string) ( $params['code_challenge'] ?? '' ) );
		$challenge_method = sanitize_text_field( (string) ( $params['code_challenge_method'] ?? '' ) );
		$resource = esc_url_raw( (string) ( $params['resource'] ?? self::mcp_url() ) );
		$client = self::get_client( $client_id );
		if ( ! $client || ! self::redirect_uri_allowed( $client, $redirect_uri ) || 'code' !== (string) ( $params['response_type'] ?? '' ) || 'S256' !== $challenge_method || '' === $challenge || untrailingslashit( $resource ) !== untrailingslashit( self::mcp_url() ) ) {
			wp_die( esc_html__( 'Richiesta OAuth non valida.', 'pr-studio-ai-bridge' ), 'OAuth non valido', array( 'response' => 400 ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( array_filter( $params, 'is_scalar' ), self::authorization_endpoint() ) ) );
			exit;
		}
		if ( ! self::can_administer() ) { wp_die( 'Solo un amministratore può autorizzare il bridge.', 'Accesso negato', array( 'response' => 403 ) ); }
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			check_admin_referer( 'wpaib_oauth_approve' );
			if ( 'deny' === sanitize_text_field( (string) ( $_POST['decision'] ?? '' ) ) ) {
				wp_redirect( add_query_arg( array( 'error' => 'access_denied', 'error_description' => 'Autorizzazione negata.', 'state' => $state, 'iss' => self::authorization_server_issuer() ), $redirect_uri ) );
				exit;
			}
			$key = sanitize_text_field( (string) ( $_POST['pairing_key'] ?? '' ) );
			if ( ! self::verify_pairing_key( $key ) ) { self::render_authorize_page( $params, 'Chiave di collegamento non valida.' ); exit; }
			$code_id = self::random_id( 10 );
			$code = 'wpaib_ac_' . $code_id . '_' . self::b64url( random_bytes( 32 ) );
			set_transient( 'wpaib_oauth_code_' . $code_id, array(
				'hash' => self::secret_hash( $code ), 'client_id' => $client_id, 'redirect_uri' => $redirect_uri,
				'code_challenge' => $challenge, 'scope' => self::normalize_scope( $scope ), 'resource' => $resource,
				'generation' => (int) self::settings()['generation'],
			), self::CODE_TTL );
			WPAIB_Audit::log( 'oauth.authorize', 'success', $client_id );
			wp_redirect( add_query_arg( array( 'code' => $code, 'state' => $state, 'iss' => self::authorization_server_issuer() ), $redirect_uri ) );
			exit;
		}
		self::render_authorize_page( $params );
		exit;
	}

	private static function render_authorize_page( array $params, string $error = '' ): void {
		$client = self::get_client( sanitize_text_field( (string) ( $params['client_id'] ?? '' ) ) );
		$client_name = $client['client_name'] ?? 'PR STUDIO MCP Client';
		$scope = self::normalize_scope( (string) ( $params['scope'] ?? 'wp_ai_bridge.read wp_ai_bridge.write' ) );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Autorizza PR STUDIO AI BRIDGE</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;margin:0;padding:40px 20px;color:#1d2327}.box{max-width:660px;margin:auto;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.06)}input[type=text]{width:100%;padding:12px;box-sizing:border-box}.buttons{display:flex;gap:12px;margin-top:20px}.button{padding:10px 18px;border-radius:4px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer}.secondary{background:#fff;color:#2c3338;border-color:#8c8f94}.error{background:#fcf0f1;border-left:4px solid #d63638;padding:12px}.scope{font-family:monospace;background:#f6f7f7;padding:8px;border-radius:4px}</style></head><body><div class="box"><h1>Autorizza PR STUDIO AI BRIDGE</h1><p><?php echo esc_html( $client_name ); ?> richiede accesso al sito.</p><p class="scope"><?php echo esc_html( $scope ); ?></p><?php if ( $error ) : ?><p class="error"><?php echo esc_html( $error ); ?></p><?php endif; ?><form method="post" action="<?php echo esc_url( self::authorization_endpoint() ); ?>"><?php wp_nonce_field( 'wpaib_oauth_approve' ); foreach ( $params as $key => $value ) { if ( is_scalar( $value ) && ! in_array( $key, array( 'pairing_key','decision','_wpnonce','_wp_http_referer' ), true ) ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">'; } } ?><label for="pairing_key"><strong>Chiave generata nel pannello del plugin</strong></label><input id="pairing_key" name="pairing_key" type="text" autocomplete="off" required><div class="buttons"><button class="button" type="submit" name="decision" value="approve">Autorizza</button><button class="button secondary" type="submit" name="decision" value="deny">Nega</button></div></form></div></body></html><?php
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
		if ( ! preg_match( '/^wpaib_ac_([^_]+)_(.+)$/', $code, $matches ) ) { return new WP_Error( 'invalid_grant', 'Codice non valido.', array( 'status' => 400 ) ); }
		$id = sanitize_key( $matches[1] );
		$record = get_transient( 'wpaib_oauth_code_' . $id );
		delete_transient( 'wpaib_oauth_code_' . $id );
		if ( ! is_array( $record ) || ! hash_equals( (string) $record['hash'], self::secret_hash( $code ) ) ) { return new WP_Error( 'invalid_grant', 'Codice scaduto o già utilizzato.', array( 'status' => 400 ) ); }
		$redirect = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );
		if ( $client_id !== (string) $record['client_id'] || $redirect !== (string) $record['redirect_uri'] ) { return new WP_Error( 'invalid_grant', 'Client o redirect URI non corrispondente.', array( 'status' => 400 ) ); }
		$resource = esc_url_raw( (string) $request->get_param( 'resource' ) );
		if ( untrailingslashit( $resource ) !== untrailingslashit( (string) $record['resource'] ) || untrailingslashit( $resource ) !== untrailingslashit( self::mcp_url() ) ) { return new WP_Error( 'invalid_target', 'Resource OAuth non corrispondente.', array( 'status' => 400 ) ); }
		$verifier = (string) $request->get_param( 'code_verifier' );
		if ( ! hash_equals( (string) $record['code_challenge'], self::b64url( hash( 'sha256', $verifier, true ) ) ) ) { return new WP_Error( 'invalid_grant', 'Verifica PKCE fallita.', array( 'status' => 400 ) ); }
		if ( (int) $record['generation'] !== (int) self::settings()['generation'] ) { return new WP_Error( 'invalid_grant', 'La chiave è stata ruotata.', array( 'status' => 400 ) ); }
		return self::issue_tokens( $client_id, (string) $record['scope'], (string) $record['resource'] );
	}

	private static function exchange_refresh( WP_REST_Request $request, string $client_id ) {
		$record = self::verify_refresh_token( (string) $request->get_param( 'refresh_token' ) );
		if ( is_wp_error( $record ) ) { return $record; }
		if ( $client_id !== (string) $record['client_id'] ) { return new WP_Error( 'invalid_grant', 'Client non corrispondente.', array( 'status' => 400 ) ); }
		$resource = esc_url_raw( (string) $request->get_param( 'resource' ) );
		if ( '' !== $resource && untrailingslashit( $resource ) !== untrailingslashit( (string) $record['resource'] ) ) { return new WP_Error( 'invalid_target', 'Resource OAuth non corrispondente.', array( 'status' => 400 ) ); }
		self::delete_token_record( (string) $record['id'] );
		return self::issue_tokens( $client_id, (string) $record['scope'], (string) $record['resource'] );
	}

	private static function issue_tokens( string $client_id, string $scope, string $resource ): array {
		$id = self::random_id( 10 );
		$access = 'wpaib_at_' . $id . '_' . self::b64url( random_bytes( 32 ) );
		$refresh = 'wpaib_rt_' . $id . '_' . self::b64url( random_bytes( 40 ) );
		$tokens = get_option( 'wpaib_oauth_tokens', array() );
		$tokens = is_array( $tokens ) ? $tokens : array();
		if ( count( $tokens ) > 300 ) { $tokens = array_slice( $tokens, -200, null, true ); }
		$tokens[ $id ] = array( 'id'=>$id,'access_hash'=>self::secret_hash($access),'refresh_hash'=>self::secret_hash($refresh),'access_exp'=>time()+self::TOKEN_TTL,'client_id'=>$client_id,'scope'=>self::normalize_scope($scope),'resource'=>$resource,'generation'=>(int)self::settings()['generation'],'created_at'=>time() );
		update_option( 'wpaib_oauth_tokens', $tokens, false );
		WPAIB_Audit::log( 'oauth.token_issue', 'success', $client_id, array( 'scope' => $scope ), $id );
		return array( 'access_token'=>$access,'token_type'=>'Bearer','expires_in'=>self::TOKEN_TTL,'refresh_token'=>$refresh,'scope'=>self::normalize_scope($scope),'resource'=>$resource );
	}

	private static function parse_token( string $token, string $prefix ): ?array {
		if ( ! preg_match( '/^' . preg_quote( $prefix, '/' ) . '_([^_]+)_(.+)$/', $token, $m ) ) { return null; }
		return array( 'id' => sanitize_key( $m[1] ), 'token' => $token );
	}
	private static function verify_refresh_token( string $token ) {
		$parsed = self::parse_token( $token, 'wpaib_rt' );
		if ( ! $parsed ) { return new WP_Error( 'invalid_grant', 'Refresh token non valido.', array( 'status' => 400 ) ); }
		$tokens = get_option( 'wpaib_oauth_tokens', array() );
		$record = is_array( $tokens ) && isset( $tokens[ $parsed['id'] ] ) ? $tokens[ $parsed['id'] ] : null;
		if ( ! is_array( $record ) || ! hash_equals( (string) $record['refresh_hash'], self::secret_hash( $token ) ) ) { return new WP_Error( 'invalid_grant', 'Refresh token revocato.', array( 'status' => 400 ) ); }
		if ( (int) $record['generation'] !== (int) self::settings()['generation'] ) { return new WP_Error( 'invalid_grant', 'La chiave è stata ruotata.', array( 'status' => 400 ) ); }
		return $record;
	}
	private static function delete_token_record( string $id ): void {
		$tokens = get_option( 'wpaib_oauth_tokens', array() );
		if ( is_array( $tokens ) && isset( $tokens[ $id ] ) ) { unset( $tokens[ $id ] ); update_option( 'wpaib_oauth_tokens', $tokens, false ); }
	}

	public static function bearer_token_from_request(): string {
		$header = '';
		self::$authorization_header_source = '';
		foreach ( array( 'HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION','Authorization' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) && '' !== trim( (string) $_SERVER[ $key ] ) ) { $header = (string) wp_unslash( $_SERVER[ $key ] ); self::$authorization_header_source = 'server:' . $key; break; }
		}
		if ( '' === $header ) {
			foreach ( array( 'HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION' ) as $key ) { $value = getenv( $key ); if ( false !== $value && '' !== trim( (string) $value ) ) { $header = (string) $value; self::$authorization_header_source = 'env:' . $key; break; } }
		}
		foreach ( array( 'getallheaders','apache_request_headers' ) as $function ) {
			if ( '' !== $header || ! function_exists( $function ) ) { continue; }
			$headers = call_user_func( $function );
			if ( is_array( $headers ) ) { foreach ( $headers as $name => $value ) { if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) { $header = (string) $value; self::$authorization_header_source = 'function:' . $function; break; } } }
		}
		return preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $m ) ? trim( $m[1] ) : '';
	}
	public static function authorization_header_source(): string { return self::$authorization_header_source; }

	public static function verify_access_token( string $token, bool $write = false ) {
		$settings = self::settings();
		if ( false === is_ssl() && 'local' !== wp_get_environment_type() ) { return new WP_Error( 'wpaib_https_required', 'HTTPS è obbligatorio.', array( 'status' => 403 ) ); }
		$parsed = self::parse_token( $token, 'wpaib_at' );
		if ( ! $parsed ) { return new WP_Error( 'wpaib_unauthorized', 'Bearer token non valido.', array( 'status' => 401 ) ); }
		$tokens = get_option( 'wpaib_oauth_tokens', array() );
		$record = is_array( $tokens ) && isset( $tokens[ $parsed['id'] ] ) ? $tokens[ $parsed['id'] ] : null;
		if ( ! is_array( $record ) || ! hash_equals( (string) $record['access_hash'], self::secret_hash( $token ) ) ) { return new WP_Error( 'wpaib_unauthorized', 'Token revocato.', array( 'status' => 401 ) ); }
		if ( time() >= (int) $record['access_exp'] ) { return new WP_Error( 'wpaib_token_expired', 'Access token scaduto.', array( 'status' => 401 ) ); }
		if ( (int) $record['generation'] !== (int) $settings['generation'] ) { return new WP_Error( 'wpaib_unauthorized', 'La chiave è stata ruotata.', array( 'status' => 401 ) ); }
		if ( untrailingslashit( (string) ( $record['resource'] ?? '' ) ) !== untrailingslashit( self::mcp_url() ) ) { return new WP_Error( 'wpaib_invalid_audience', 'Token emesso per una risorsa diversa.', array( 'status' => 401 ) ); }
		$scope = preg_split( '/\s+/', (string) $record['scope'] );
		if ( $write && ! in_array( 'wp_ai_bridge.write', is_array( $scope ) ? $scope : array(), true ) ) { return new WP_Error( 'wpaib_forbidden', 'Il token non ha lo scope di scrittura.', array( 'status' => 403 ) ); }
		self::$current_token_id = (string) $record['id'];
		if ( ! self::rate_limit( (string) $record['id'] ) ) { return new WP_Error( 'wpaib_rate_limited', 'Troppe richieste.', array( 'status' => 429 ) ); }
		return $record;
	}
	private static function rate_limit( string $id ): bool {
		$limit = max( 10, min( 5000, (int) self::settings()['rate_limit_per_min'] ) );
		$key = 'wpaib_rl_' . md5( $id . '|' . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) { return false; }
		set_transient( $key, $count + 1, 90 );
		return true;
	}
	public static function current_token_id(): string { return self::$current_token_id; }
	public static function permission( bool $write = false ) {
		$token = self::bearer_token_from_request();
		if ( '' === $token ) { return new WP_Error( 'wpaib_unauthorized', 'Bearer token richiesto.', array( 'status' => 401 ) ); }
		return self::verify_access_token( $token, $write );
	}

	private static function google_record(): array {
		$value = get_option( 'wpaib_google_oauth', array() );
		return is_array( $value ) ? $value : array();
	}

	private static function google_crypto_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|wpaib-google-search-console-v1', true );
	}

	private static function b64url_decode( string $encoded ) {
		$padding = strlen( $encoded ) % 4;
		if ( $padding ) { $encoded .= str_repeat( '=', 4 - $padding ); }
		return base64_decode( strtr( $encoded, '-_', '+/' ), true );
	}

	private static function google_seal( string $plain ) {
		if ( '' === $plain ) { return ''; }
		$key = self::google_crypto_key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . self::b64url( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv = random_bytes( 12 );
			$tag = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $cipher ) { return 'g1:' . self::b64url( $iv . $tag . $cipher ); }
		}
		return new WP_Error( 'wpaib_google_crypto_unavailable', 'Cifratura sicura non disponibile.', array( 'status' => 500 ) );
	}

	private static function google_open( string $sealed ) {
		if ( '' === $sealed ) { return ''; }
		$parts = explode( ':', $sealed, 2 );
		if ( 2 !== count( $parts ) ) { return new WP_Error( 'wpaib_google_secret_invalid', 'Credenziale cifrata non valida.', array( 'status' => 500 ) ); }
		$raw = self::b64url_decode( $parts[1] );
		if ( false === $raw ) { return new WP_Error( 'wpaib_google_secret_invalid', 'Credenziale cifrata non valida.', array( 'status' => 500 ) ); }
		$key = self::google_crypto_key();
		if ( 's1' === $parts[0] && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
			return false === $plain ? new WP_Error( 'wpaib_google_secret_invalid', 'Decifratura credenziale non riuscita.', array( 'status' => 500 ) ) : $plain;
		}
		if ( 'g1' === $parts[0] && function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 28 ) {
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? new WP_Error( 'wpaib_google_secret_invalid', 'Decifratura credenziale non riuscita.', array( 'status' => 500 ) ) : $plain;
		}
		return new WP_Error( 'wpaib_google_secret_invalid', 'Formato di cifratura non supportato.', array( 'status' => 500 ) );
	}

	public static function google_oauth_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=wpaib_google_oauth_callback' );
	}

	public static function google_oauth_start_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=wpaib_google_oauth_start' ), 'wpaib_google_oauth_start' );
	}

	public static function google_oauth_status(): array {
		$record = self::google_record();
		return array(
			'configured'   => ! empty( $record['client_id'] ) && ! empty( $record['client_secret'] ),
			'connected'    => ! empty( $record['refresh_token'] ),
			'client_id'    => sanitize_text_field( (string) ( $record['client_id'] ?? '' ) ),
			'redirect_uri' => self::google_oauth_redirect_uri(),
			'scope'        => sanitize_text_field( (string) ( $record['scope'] ?? 'https://www.googleapis.com/auth/webmasters.readonly' ) ),
			'expires_at'   => absint( $record['expires_at'] ?? 0 ),
			'connected_at' => absint( $record['connected_at'] ?? 0 ),
			'last_refresh' => absint( $record['last_refresh'] ?? 0 ),
			'last_error'   => sanitize_key( (string) ( $record['last_error'] ?? '' ) ),
			'encryption'   => function_exists( 'sodium_crypto_secretbox' ) ? 'sodium-secretbox' : ( function_exists( 'openssl_encrypt' ) ? 'aes-256-gcm' : 'unavailable' ),
		);
	}

	public static function save_google_oauth_credentials( string $client_id, string $client_secret ) {
		if ( ! self::can_administer() ) { return new WP_Error( 'wpaib_google_forbidden', 'Solo un amministratore può configurare Google OAuth.', array( 'status' => 403 ) ); }
		$client_id = trim( sanitize_text_field( $client_id ) );
		$client_secret = trim( sanitize_text_field( $client_secret ) );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $client_id ) ) {
			return new WP_Error( 'wpaib_google_client_id_invalid', 'OAuth Client ID Google non valido.', array( 'status' => 400 ) );
		}
		$record = self::google_record();
		if ( '' === $client_secret && empty( $record['client_secret'] ) ) {
			return new WP_Error( 'wpaib_google_client_secret_required', 'OAuth Client Secret obbligatorio.', array( 'status' => 400 ) );
		}
		if ( '' !== $client_secret ) {
			$sealed = self::google_seal( $client_secret );
			if ( is_wp_error( $sealed ) ) { return $sealed; }
			$record['client_secret'] = $sealed;
		}
		if ( ! empty( $record['client_id'] ) && ! hash_equals( (string) $record['client_id'], $client_id ) ) {
			unset( $record['access_token'], $record['refresh_token'], $record['expires_at'], $record['scope'], $record['connected_at'], $record['last_refresh'] );
		}
		$record['client_id'] = $client_id;
		$record['last_error'] = '';
		$record['updated_at'] = time();
		update_option( 'wpaib_google_oauth', $record, false );
		WPAIB_Audit::log( 'google.oauth.credentials_save', 'success', 'search-console' );
		return self::google_oauth_status();
	}

	private static function google_credentials() {
		$record = self::google_record();
		if ( empty( $record['client_id'] ) || empty( $record['client_secret'] ) ) {
			return new WP_Error( 'wpaib_google_not_configured', 'Credenziali Google OAuth non configurate.', array( 'status' => 503 ) );
		}
		$secret = self::google_open( (string) $record['client_secret'] );
		if ( is_wp_error( $secret ) ) { return $secret; }
		return array( 'client_id' => (string) $record['client_id'], 'client_secret' => $secret );
	}

	private static function google_redirect_notice( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'prstudio_notice', sanitize_key( $notice ), admin_url( 'tools.php?page=wp-ai-bridge' ) ) );
		exit;
	}

	public static function handle_google_oauth_start(): void {
		if ( ! is_user_logged_in() || ! self::can_administer() ) { wp_die( 'Accesso negato.', 'Google OAuth', array( 'response' => 403 ) ); }
		check_admin_referer( 'wpaib_google_oauth_start' );
		$credentials = self::google_credentials();
		if ( is_wp_error( $credentials ) ) { self::google_redirect_notice( 'google_not_configured' ); }
		$state = self::b64url( random_bytes( 32 ) );
		$verifier = self::b64url( random_bytes( 64 ) );
		$sealed_verifier = self::google_seal( $verifier );
		if ( is_wp_error( $sealed_verifier ) ) { self::google_redirect_notice( 'google_crypto_error' ); }
		set_transient(
			'wpaib_google_oauth_state_' . hash( 'sha256', $state ),
			array( 'user_id' => get_current_user_id(), 'verifier' => $sealed_verifier, 'created_at' => time() ),
			10 * MINUTE_IN_SECONDS
		);
		$url = add_query_arg(
			array(
				'client_id' => $credentials['client_id'],
				'redirect_uri' => self::google_oauth_redirect_uri(),
				'response_type' => 'code',
				'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
				'access_type' => 'offline',
				'include_granted_scopes' => 'true',
				'prompt' => 'consent',
				'state' => $state,
				'code_challenge' => self::b64url( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
		WPAIB_Audit::log( 'google.oauth.start', 'success', 'search-console', array( 'user_id' => get_current_user_id() ) );
		wp_redirect( $url );
		exit;
	}

	public static function handle_google_oauth_callback(): void {
		if ( ! is_user_logged_in() || ! self::can_administer() ) { wp_die( 'Accesso negato.', 'Google OAuth', array( 'response' => 403 ) ); }
		$state = sanitize_text_field( (string) ( $_GET['state'] ?? '' ) );
		$key = 'wpaib_google_oauth_state_' . hash( 'sha256', $state );
		$flow = get_transient( $key );
		delete_transient( $key );
		if ( '' === $state || ! is_array( $flow ) || (int) $flow['user_id'] !== get_current_user_id() ) {
			WPAIB_Audit::log( 'google.oauth.callback', 'error', 'state_invalid' );
			self::google_redirect_notice( 'google_state_invalid' );
		}
		if ( ! empty( $_GET['error'] ) ) {
			WPAIB_Audit::log( 'google.oauth.callback', 'error', sanitize_key( (string) $_GET['error'] ) );
			self::google_redirect_notice( 'google_access_denied' );
		}
		$code = sanitize_text_field( (string) ( $_GET['code'] ?? '' ) );
		$verifier = self::google_open( (string) $flow['verifier'] );
		$credentials = self::google_credentials();
		if ( '' === $code || is_wp_error( $verifier ) || is_wp_error( $credentials ) ) { self::google_redirect_notice( 'google_callback_invalid' ); }
		$response = wp_safe_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body' => array(
					'client_id' => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'code' => $code,
					'code_verifier' => $verifier,
					'grant_type' => 'authorization_code',
					'redirect_uri' => self::google_oauth_redirect_uri(),
				),
			)
		);
		if ( is_wp_error( $response ) ) { self::google_set_error( 'token_transport' ); self::google_redirect_notice( 'google_token_error' ); }
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 400 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			self::google_set_error( sanitize_key( (string) ( $data['error'] ?? 'token_exchange' ) ) );
			self::google_redirect_notice( 'google_token_error' );
		}
		$record = self::google_record();
		$access = self::google_seal( (string) $data['access_token'] );
		$refresh = ! empty( $data['refresh_token'] ) ? self::google_seal( (string) $data['refresh_token'] ) : ( $record['refresh_token'] ?? '' );
		if ( is_wp_error( $access ) || is_wp_error( $refresh ) || empty( $refresh ) ) { self::google_set_error( 'refresh_token_missing' ); self::google_redirect_notice( 'google_refresh_missing' ); }
		$record['access_token'] = $access;
		$record['refresh_token'] = $refresh;
		$record['expires_at'] = time() + max( 60, (int) ( $data['expires_in'] ?? 3600 ) ) - 30;
		$record['scope'] = sanitize_text_field( (string) ( $data['scope'] ?? 'https://www.googleapis.com/auth/webmasters.readonly' ) );
		$record['connected_at'] = time();
		$record['last_refresh'] = time();
		$record['last_error'] = '';
		update_option( 'wpaib_google_oauth', $record, false );
		WPAIB_Audit::log( 'google.oauth.callback', 'success', 'search-console', array( 'scope' => $record['scope'] ) );
		self::google_redirect_notice( 'google_connected' );
	}

	private static function google_set_error( string $code ): void {
		$record = self::google_record();
		$record['last_error'] = sanitize_key( $code );
		$record['error_at'] = time();
		update_option( 'wpaib_google_oauth', $record, false );
		WPAIB_Audit::log( 'google.oauth.error', 'error', sanitize_key( $code ) );
	}

	public static function google_access_token( bool $force_refresh = false ) {
		$record = self::google_record();
		if ( ! $force_refresh && ! empty( $record['access_token'] ) && time() + 60 < (int) ( $record['expires_at'] ?? 0 ) ) {
			return self::google_open( (string) $record['access_token'] );
		}
		if ( empty( $record['refresh_token'] ) ) { return new WP_Error( 'wpaib_google_not_connected', 'Account Google non collegato.', array( 'status' => 401 ) ); }
		$refresh = self::google_open( (string) $record['refresh_token'] );
		$credentials = self::google_credentials();
		if ( is_wp_error( $refresh ) ) { return $refresh; }
		if ( is_wp_error( $credentials ) ) { return $credentials; }
		$response = wp_safe_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body' => array( 'client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret'], 'refresh_token' => $refresh, 'grant_type' => 'refresh_token' ),
			)
		);
		if ( is_wp_error( $response ) ) { self::google_set_error( 'refresh_transport' ); return new WP_Error( 'wpaib_google_refresh_failed', 'Aggiornamento token Google non riuscito.', array( 'status' => 502 ) ); }
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 400 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = sanitize_key( (string) ( $data['error'] ?? 'refresh_failed' ) );
			self::google_set_error( $error );
			return new WP_Error( 'wpaib_google_refresh_failed', 'Aggiornamento token Google non riuscito.', array( 'status' => 401, 'google_error' => $error ) );
		}
		$sealed = self::google_seal( (string) $data['access_token'] );
		if ( is_wp_error( $sealed ) ) { return $sealed; }
		$record['access_token'] = $sealed;
		$record['expires_at'] = time() + max( 60, (int) ( $data['expires_in'] ?? 3600 ) ) - 30;
		$record['last_refresh'] = time();
		$record['last_error'] = '';
		if ( ! empty( $data['scope'] ) ) { $record['scope'] = sanitize_text_field( (string) $data['scope'] ); }
		update_option( 'wpaib_google_oauth', $record, false );
		WPAIB_Audit::log( 'google.oauth.refresh', 'success', 'search-console' );
		return (string) $data['access_token'];
	}

	public static function google_api_request( string $method, string $url, ?array $body = null, bool $retried = false ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! in_array( $host, array( 'www.googleapis.com', 'searchconsole.googleapis.com' ), true ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'wpaib_google_endpoint_invalid', 'Endpoint Google non consentito.', array( 'status' => 400 ) );
		}
		$token = self::google_access_token( $retried );
		if ( is_wp_error( $token ) ) { return $token; }
		$args = array( 'method' => strtoupper( $method ), 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) );
		if ( null !== $body ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); }
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'wpaib_google_api_transport', 'Connessione alle API Google non riuscita.', array( 'status' => 502 ) ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 401 === $status && ! $retried ) { return self::google_api_request( $method, $url, $body, true ); }
		if ( $status >= 400 ) {
			$error = is_array( $data['error'] ?? null ) ? $data['error'] : array();
			return new WP_Error(
				'wpaib_google_api_failed',
				sanitize_text_field( (string) ( $error['message'] ?? 'Richiesta Search Console non riuscita.' ) ),
				array( 'status' => $status, 'google_code' => sanitize_text_field( (string) ( $error['status'] ?? $error['code'] ?? '' ) ) )
			);
		}
		return is_array( $data ) ? $data : array();
	}

	public static function google_oauth_disconnect() {
		if ( ! self::can_administer() ) { return new WP_Error( 'wpaib_google_forbidden', 'Solo un amministratore può disconnettere Google.', array( 'status' => 403 ) ); }
		$record = self::google_record();
		$token = '';
		if ( ! empty( $record['refresh_token'] ) ) { $opened = self::google_open( (string) $record['refresh_token'] ); if ( ! is_wp_error( $opened ) ) { $token = $opened; } }
		if ( '' === $token && ! empty( $record['access_token'] ) ) { $opened = self::google_open( (string) $record['access_token'] ); if ( ! is_wp_error( $opened ) ) { $token = $opened; } }
		$revoked = null;
		if ( '' !== $token ) {
			$response = wp_safe_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 15, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => array( 'token' => $token ) ) );
			$revoked = ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), array( 200, 400 ), true );
		}
		unset( $record['access_token'], $record['refresh_token'], $record['expires_at'], $record['scope'], $record['connected_at'], $record['last_refresh'] );
		$record['last_error'] = '';
		update_option( 'wpaib_google_oauth', $record, false );
		WPAIB_Audit::log( 'google.oauth.disconnect', 'success', 'search-console', array( 'revocation_requested' => null !== $revoked, 'revoked_or_already_invalid' => $revoked ) );
		return array( 'disconnected' => true, 'revocation_requested' => null !== $revoked, 'revoked_or_already_invalid' => $revoked );
	}
}
