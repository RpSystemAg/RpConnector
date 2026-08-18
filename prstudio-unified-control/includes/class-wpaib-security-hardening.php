<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Security defaults for the unified control plane. */
final class WPAIB_Security_Hardening {
    private const ADMIN_NS = '/rpconnector-admin/v1';
    private const LOGIN_WINDOW = 900;
    private const LOGIN_MAX = 8;
    private const LOGIN_BLOCK = 600;

    public static function boot(): void {
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_guard' ), -1000, 3 );
        add_filter( 'rest_endpoints', array( __CLASS__, 'hide_sensitive_routes' ), PHP_INT_MAX );
        add_action( 'template_redirect', array( __CLASS__, 'block_author_enumeration' ), -1000 );
        add_filter( 'redirect_canonical', array( __CLASS__, 'disable_author_redirect' ), -1000, 2 );
        add_filter( 'xmlrpc_methods', array( __CLASS__, 'disable_pingbacks' ), PHP_INT_MAX );
        add_filter( 'wp_headers', array( __CLASS__, 'filter_headers' ), PHP_INT_MAX );
        add_filter( 'authenticate', array( __CLASS__, 'rate_limit_login' ), 5, 3 );
        add_action( 'wp_login_failed', array( __CLASS__, 'record_login_failure' ), 10, 1 );
        add_action( 'wp_login', array( __CLASS__, 'clear_login_failure' ), 10, 1 );
        remove_action( 'wp_head', 'wp_generator' );
        remove_action( 'wp_head', 'rsd_link' );
        add_filter( 'the_generator', '__return_empty_string', PHP_INT_MAX );
    }

    public static function rest_guard( $result, WP_REST_Server $server, WP_REST_Request $request ) {
        unset( $server );
        if ( null !== $result ) { return $result; }
        $route = '/' . ltrim( $request->get_route(), '/' );
        if ( self::ADMIN_NS === $route || str_starts_with( $route, self::ADMIN_NS . '/' ) ) {
            if ( is_user_logged_in() ) {
                return current_user_can( 'manage_options' ) ? null : new WP_Error( 'prstudio_forbidden', 'Administrative capability required.', array( 'status' => 403 ) );
            }
            if ( class_exists( 'WPAIB_Auth' ) && is_callable( array( 'WPAIB_Auth', 'api_or_oauth_permission' ) ) ) {
                $method = strtoupper( $request->get_method() );
                $permission = WPAIB_Auth::api_or_oauth_permission( ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) );
                return is_wp_error( $permission ) ? $permission : null;
            }
            return new WP_Error( 'prstudio_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
        }
        if ( preg_match( '#^/wp/v2/users(?:/|$)#', $route ) && ! is_user_logged_in() ) {
            return new WP_Error( 'prstudio_users_private', 'Authentication required.', array( 'status' => 401 ) );
        }
        return null;
    }

    public static function hide_sensitive_routes( array $endpoints ): array {
        foreach ( $endpoints as $route => &$handlers ) {
            if ( str_starts_with( (string) $route, self::ADMIN_NS ) || str_starts_with( (string) $route, '/wp/v2/users' ) ) {
                foreach ( (array) $handlers as &$handler ) {
                    if ( is_array( $handler ) ) { $handler['show_in_index'] = false; $handler['allow_batch'] = false; }
                }
            }
        }
        return $endpoints;
    }

    public static function block_author_enumeration(): void {
        if ( ! is_user_logged_in() && isset( $_GET['author'] ) && preg_match( '/^\\d+$/', (string) wp_unslash( $_GET['author'] ) ) ) {
            status_header( 404 ); nocache_headers(); exit;
        }
    }
    public static function disable_author_redirect( $redirect, $requested ) {
        unset( $requested );
        return ( ! is_user_logged_in() && isset( $_GET['author'] ) ) ? false : $redirect;
    }
    public static function disable_pingbacks( array $methods ): array {
        unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] ); return $methods;
    }
    public static function filter_headers( array $headers ): array { unset( $headers['X-Pingback'], $headers['x-pingback'] ); return $headers; }

    private static function login_key( string $username ): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        return 'prstudio_login_' . hash_hmac( 'sha256', strtolower( trim( $username ) ) . '|' . $ip, wp_salt( 'auth' ) );
    }
    public static function rate_limit_login( $user, $username, $password ) {
        unset( $password );
        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) { return $user; }
        $state = get_transient( self::login_key( (string) $username ) );
        if ( is_array( $state ) && (int) ( $state['blocked_until'] ?? 0 ) > time() ) {
            return new WP_Error( 'prstudio_login_rate_limited', 'Too many login attempts. Try again later.', array( 'status' => 429 ) );
        }
        return $user;
    }
    public static function record_login_failure( $username ): void {
        $key = self::login_key( (string) $username );
        $state = get_transient( $key );
        if ( ! is_array( $state ) || time() - (int) ( $state['started'] ?? 0 ) > self::LOGIN_WINDOW ) { $state = array( 'started' => time(), 'attempts' => 0, 'blocked_until' => 0 ); }
        $state['attempts']++;
        if ( $state['attempts'] >= self::LOGIN_MAX ) { $state['blocked_until'] = time() + self::LOGIN_BLOCK; }
        set_transient( $key, $state, self::LOGIN_WINDOW + self::LOGIN_BLOCK );
    }
    public static function clear_login_failure( $username ): void { delete_transient( self::login_key( (string) $username ) ); }
}
WPAIB_Security_Hardening::boot();
