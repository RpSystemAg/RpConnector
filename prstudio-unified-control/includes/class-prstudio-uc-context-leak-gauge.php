<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Gauge comportamentale di context-leakage sulle risposte MCP.
 *
 * Misura segnali di fuga di contesto nelle risposte: token OAuth,
 * lane_token interni, client_secret, code_verifier e qualunque valore che
 * corrisponda a un segreto noto del sistema.
 *
 * L'invariante e' fail-closed SUL PAYLOAD CHE STA PER USCIRE. Un chiamante puo'
 * prima redigere un segreto e poi passare il payload al blocking verdict: la
 * sentinella letterale [REDACTED] non e' una fuga. Questo consente a risposte
 * come context_open di mantenere il lane_handle pubblico senza essere scartate
 * solo perche' il campo interno lane_token e' gia' stato sanificato.
 */
final class PRSTUDIO_UC_Context_Leak_Gauge {
    public const VERSION = '1.1.0';
    private const REDACTED_SENTINEL = '[REDACTED]';

    /** Pattern di segreto generici (Bearer, query token, chiavi esadecimali). */
    private const SECRET_PATTERNS = array(
        '/\bBearer\s+[A-Za-z0-9._~+\/-]{16,}/i',
        '/([?&](?:access_token|refresh_token|id_token|code_verifier|client_secret|api[_-]?key|password)=)[^&#\s]{8,}/i',
        '/\b(?:eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})/',
        '/\b(?:sk|pk|ghp|gho|ghs|glpat|AKIA|ASIA)[A-Za-z0-9_-]{16,}/',
    );

    /** Chiavi che non dovrebbero mai apparire con un valore reale in una risposta MCP. */
    private const FORBIDDEN_KEYS = array(
        'lane_token', 'access_token', 'refresh_token', 'client_secret',
        'client_secret_expires_at', 'code_verifier', 'code_challenge',
        'token_endpoint_auth_method', 'pairing_key', 'pairing_token',
    );

    private static function is_safely_redacted( $value ): bool {
        return is_string( $value ) && self::REDACTED_SENTINEL === trim( $value );
    }

    /**
     * Scansione completa di un payload.
     *
     * @param array<string,mixed> $payload
     * @param array{known_secrets?:array<int,string>,forbidden_keys?:array<int,string>} $context
     * @return array{leak:bool,findings:array<int,array{code:string,path:string}>,gauges:array<string,bool>}
     */
    public static function scan( array $payload, array $context = array() ): array {
        $known = array();
        foreach ( (array) ( $context['known_secrets'] ?? array() ) as $secret ) {
            if ( is_string( $secret ) && strlen( $secret ) >= 8 ) { $known[ $secret ] = true; }
        }
        $forbidden = array_merge( self::FORBIDDEN_KEYS, array_map( 'strval', (array) ( $context['forbidden_keys'] ?? array() ) ) );
        $forbidden = array_map( 'strtolower', $forbidden );
        $findings = array();
        $gauges = array(
            'gauge_known_secret' => false,
            'gauge_forbidden_key' => false,
            'gauge_secret_pattern' => false,
        );

        $walk = static function ( $value, string $path ) use ( &$walk, &$findings, &$gauges, $known, $forbidden ): void {
            if ( is_array( $value ) ) {
                foreach ( $value as $key => $child ) {
                    $child_path = '' === $path ? (string) $key : $path . '.' . (string) $key;
                    if (
                        is_string( $key )
                        && in_array( strtolower( $key ), $forbidden, true )
                        && ! is_array( $child )
                        && null !== $child
                        && ! self::is_safely_redacted( $child )
                    ) {
                        $findings[] = array( 'code' => 'forbidden_key_present', 'path' => $child_path );
                        $gauges['gauge_forbidden_key'] = true;
                    }
                    $walk( $child, $child_path );
                }
                return;
            }
            if ( ! is_string( $value ) || '' === $value || self::is_safely_redacted( $value ) ) { return; }
            if ( isset( $known[ $value ] ) ) {
                $findings[] = array( 'code' => 'known_secret_exact_match', 'path' => $path );
                $gauges['gauge_known_secret'] = true;
                return;
            }
            foreach ( self::SECRET_PATTERNS as $pattern ) {
                if ( preg_match( $pattern, $value ) ) {
                    $findings[] = array( 'code' => 'secret_pattern', 'path' => $path );
                    $gauges['gauge_secret_pattern'] = true;
                    return;
                }
            }
        };
        $walk( $payload, '' );

        return array(
            'leak' => ! empty( $findings ),
            'findings' => $findings,
            'gauges' => $gauges,
        );
    }

    /**
     * Redige il payload sostituendo i segreti rilevati.
     *
     * @return array{redacted:array<string,mixed>,findings:array<int,array{code:string,path:string}>}
     */
    public static function redact( array $payload, array $context = array() ): array {
        $known = array();
        foreach ( (array) ( $context['known_secrets'] ?? array() ) as $secret ) {
            if ( is_string( $secret ) && strlen( $secret ) >= 8 ) { $known[ $secret ] = true; }
        }
        $forbidden = array_merge( self::FORBIDDEN_KEYS, array_map( 'strval', (array) ( $context['forbidden_keys'] ?? array() ) ) );
        $forbidden = array_map( 'strtolower', $forbidden );
        $findings = array();

        $walk = static function ( $value, string $path ) use ( &$walk, &$findings, $known, $forbidden ) {
            if ( is_array( $value ) ) {
                $out = array();
                foreach ( $value as $key => $child ) {
                    $child_path = '' === $path ? (string) $key : $path . '.' . (string) $key;
                    if ( is_string( $key ) && in_array( strtolower( $key ), $forbidden, true ) && ! is_array( $child ) && null !== $child ) {
                        if ( ! self::is_safely_redacted( $child ) ) {
                            $findings[] = array( 'code' => 'forbidden_key_present', 'path' => $child_path );
                        }
                        $out[ $key ] = self::REDACTED_SENTINEL;
                        continue;
                    }
                    if ( is_array( $child ) ) {
                        $out[ $key ] = $walk( $child, $child_path );
                        continue;
                    }
                    if ( is_string( $child ) && '' !== $child && ! self::is_safely_redacted( $child ) ) {
                        if ( isset( $known[ $child ] ) ) {
                            $findings[] = array( 'code' => 'known_secret_exact_match', 'path' => $child_path );
                            $out[ $key ] = self::REDACTED_SENTINEL;
                            continue;
                        }
                        $redacted_value = null;
                        foreach ( self::SECRET_PATTERNS as $pattern ) {
                            if ( preg_match( $pattern, $child ) ) {
                                $findings[] = array( 'code' => 'secret_pattern', 'path' => $child_path );
                                $redacted_value = (string) preg_replace( $pattern, self::REDACTED_SENTINEL, $child );
                                break;
                            }
                        }
                        if ( null !== $redacted_value ) {
                            $out[ $key ] = $redacted_value;
                            continue;
                        }
                    }
                    $out[ $key ] = $child;
                }
                return $out;
            }
            return $value;
        };

        return array( 'redacted' => $walk( $payload, '' ), 'findings' => $findings );
    }

    /**
     * Verdetto bloccante sul payload finale: un segreto reale blocca, una
     * sentinella gia' redatta no.
     *
     * @return array{blocked:bool,code:string,findings:array<int,array{code:string,path:string}>}
     */
    public static function blocking_verdict( array $payload, array $context = array() ): array {
        $scan = self::scan( $payload, $context );
        return array(
            'blocked' => $scan['leak'],
            'code' => $scan['leak'] ? 'context_leak_blocked' : '',
            'findings' => $scan['findings'],
        );
    }

    /** @return array{version:string,gauges:array<string,string>} */
    public static function status(): array {
        return array(
            'version' => self::VERSION,
            'gauges' => array(
                'gauge_known_secret' => 'blocking',
                'gauge_forbidden_key' => 'blocking',
                'gauge_secret_pattern' => 'blocking',
            ),
        );
    }
}
