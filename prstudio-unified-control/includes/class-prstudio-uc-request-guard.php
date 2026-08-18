<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Retry replay cache for explicit request identifiers. Never deduplicates requests heuristically. */
final class PRSTUDIO_UC_Request_Guard {
	private const TTL = HOUR_IN_SECONDS;

	private static function transient_key( string $route, string $action, array $arguments ): string {
		$explicit = PRSTUDIO_UC_Idempotency::explicit_key( $arguments );
		if ( '' === $explicit ) { return ''; }
		$hash = PRSTUDIO_UC_Idempotency::storage_key( 'control:' . trim( $route, '/' ) . ':' . sanitize_key( $action ), $explicit );
		return '' === $hash ? '' : 'prstudio_uc_req_' . substr( $hash, 0, 32 );
	}

	public static function lookup( string $route, string $action, array $arguments ): ?array {
		$key = self::transient_key( $route, $action, $arguments );
		if ( '' === $key ) { return null; }
		$value = get_transient( $key );
		if ( ! is_array( $value ) || empty( $value['_prstudio_idempotent_receipt'] ) ) { return null; }
		$value['idempotent_replay'] = true;
		return $value;
	}

	public static function remember( string $route, string $action, array $arguments, array $response ): void {
		$key = self::transient_key( $route, $action, $arguments );
		if ( '' === $key ) { return; }
		$response['_prstudio_idempotent_receipt'] = true;
		set_transient( $key, $response, self::TTL );
	}
}
