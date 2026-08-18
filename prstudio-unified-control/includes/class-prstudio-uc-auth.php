<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PRSTUDIO_UC_Auth {
	private const SECRET_OPTION = 'prstudio_uc_secret';
	private const PAIR_PREFIX = 'prstudio_uc_pair_';

	public static function ensure_secret(): void {
		if ( ! get_option( self::SECRET_OPTION ) ) {
			update_option( self::SECRET_OPTION, bin2hex( random_bytes( 32 ) ), false );
		}
	}

	public static function signing_secret(): string {
		self::ensure_secret();
		return (string) get_option( self::SECRET_OPTION, '' );
	}

	public static function create_pairing_code(): string {
		$code = strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 10 ) );
		set_transient(
			self::PAIR_PREFIX . hash( 'sha256', $code ),
			array( 'user_id' => get_current_user_id(), 'created_at' => time() ),
			10 * MINUTE_IN_SECONDS
		);
		return $code;
	}

	public static function pair_rate_limit() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'prstudio_uc_pair_rl_' . hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) );
		$state = get_transient( $key ); $state = is_array( $state ) ? $state : array( 'count' => 0 );
		if ( (int) ( $state['count'] ?? 0 ) >= 20 ) { return new WP_Error( 'prstudio_uc_pair_rate_limited', 'Troppi tentativi di pairing. Riprovare più tardi.', array( 'status' => 429 ) ); }
		$state['count'] = (int) ( $state['count'] ?? 0 ) + 1; set_transient( $key, $state, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	public static function consume_pairing_code( string $code ): bool {
		$key = self::PAIR_PREFIX . hash( 'sha256', strtoupper( trim( $code ) ) );
		$value = get_transient( $key );
		delete_transient( $key );
		return is_array( $value ) && time() - (int) ( $value['created_at'] ?? 0 ) <= 10 * MINUTE_IN_SECONDS;
	}

	public static function bearer_from_request( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		return preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $matches ) ? trim( $matches[1] ) : '';
	}

	public static function device_permission( WP_REST_Request $request ) {
		$device = PRSTUDIO_UC_Store::device_by_token( self::bearer_from_request( $request ) );
		if ( ! $device ) {
			return new WP_Error( 'prstudio_uc_unauthorized', 'Token dispositivo non valido.', array( 'status' => 401 ) );
		}
		if ( PRSTUDIO_UC_Wait_Channel::should_touch_device( (string) $device['device_uuid'] ) ) {
			PRSTUDIO_UC_Store::touch_device( (string) $device['device_uuid'] );
		}
		$request->set_param( '_prstudio_device', $device );
		return true;
	}

	public static function device_or_bridge_permission( WP_REST_Request $request ) {
		$token = self::bearer_from_request( $request );
		if ( '' !== $token ) {
			$device = PRSTUDIO_UC_Store::device_by_token( $token );
			if ( $device ) {
				if ( PRSTUDIO_UC_Wait_Channel::should_touch_device( (string) $device['device_uuid'] ) ) { PRSTUDIO_UC_Store::touch_device( (string) $device['device_uuid'] ); }
				$request->set_param( '_prstudio_device', $device );
				return true;
			}
		}
		return self::bridge_permission( true );
	}

	public static function bridge_permission( bool $write = false ) {
		if ( class_exists( 'WPAIB_Auth' ) && method_exists( 'WPAIB_Auth', 'permission' ) ) {
			return WPAIB_Auth::permission( $write );
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'prstudio_uc_bridge_missing', 'PR STUDIO AI BRIDGE non disponibile.', array( 'status' => 503 ) );
	}

	public static function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}
}
