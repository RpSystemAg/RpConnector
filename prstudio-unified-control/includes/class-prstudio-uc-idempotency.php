<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Stable request identities used to make retries safe without changing public schemas. */
final class PRSTUDIO_UC_Idempotency {
	public static function explicit_key( array $arguments ): string {
		foreach ( array( '_idempotency_key', 'idempotency_key', 'request_id', 'requestId', 'client_request_id' ) as $key ) {
			$value = $arguments[ $key ] ?? '';
			if ( ! is_string( $value ) ) { continue; }
			$value = trim( $value );
			if ( '' !== $value ) { return $value; }
		}
		return '';
	}

	public static function storage_key( string $scope, string $explicit_key ): string {
		$explicit_key = trim( $explicit_key );
		if ( '' === $explicit_key ) { return ''; }
		return hash( 'sha256', trim( $scope ) . "\n" . $explicit_key );
	}

	public static function plan_hash( string $action, array $arguments ): string {
		$copy = $arguments;
		foreach ( array( '_idempotency_key', 'idempotency_key', 'request_id', 'requestId', 'client_request_id', 'sync_wait_seconds' ) as $key ) { unset( $copy[ $key ] ); }
		return hash( 'sha256', sanitize_key( $action ) . "\n" . self::canonical_json( $copy ) );
	}

	public static function canonical_json( $value ): string {
		$normalized = self::normalize( $value );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : 'null';
	}

	private static function normalize( $value ) {
		if ( is_string( $value ) && 1 !== preg_match( '//u', $value ) ) { return array( '__prstudio_binary_b64' => base64_encode( $value ) ); }
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return array( '__prstudio_nonfinite_float' => is_nan( $value ) ? 'nan' : ( $value > 0 ? 'inf' : '-inf' ) );
		}
		if ( is_resource( $value ) ) { return array( '__prstudio_resource_type' => get_resource_type( $value ) ); }
		if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		if ( ! is_array( $value ) ) { return $value; }
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $item ) { $value[ $key ] = self::normalize( $item ); }
		return $value;
	}
}
