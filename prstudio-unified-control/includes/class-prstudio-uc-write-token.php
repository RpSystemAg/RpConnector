<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Write tokens — the 16.0 answer to "it only ever does audits".
 *
 * 15.x asked callers for `expected_before_sha256` and `expected_occurrences`
 * before it would mutate anything. Those preconditions are correct engineering
 * and they stay. The problem was who had to produce them: a language model
 * cannot compute SHA-256 and cannot reliably count substring occurrences, and
 * no read tool returned either value. The only route to a valid hash was to
 * call the write, collect a 409, and read `current_sha256` out of the error —
 * so the model learned that writing fails and fell back to reading and
 * reporting, which always succeeds.
 *
 * A write token inverts that. The read side computes the preconditions (it is
 * the side that can) and hands back one opaque signed string. The write side
 * verifies the signature, unpacks the same facts, and enforces exactly the
 * optimistic lock it enforced before. The model copies a string; it never
 * handles a hash. Same safety, no dead end.
 *
 * The token is signed, not encrypted: its contents are facts the caller already
 * observed, so there is nothing to hide. The signature is what matters — it
 * proves the facts came from this site's read path and were not invented, and
 * it binds them to one entity, one OAuth client and one short expiry.
 */
final class PRSTUDIO_UC_Write_Token {
    public const VERSION = '1.0.0';

    private const PREFIX = 'wt1';
    private const DEFAULT_TTL = 900;
    private const MAX_TTL = 3600;

    private static function secret(): string {
        // wp_salt() is per-install and already rotated with the security keys.
        return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) . '|prstudio-write-token-v1' : 'prstudio-write-token-v1';
    }

    private static function b64url_encode( string $raw ): string {
        return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
    }

    private static function b64url_decode( string $value ): string {
        $padded = strtr( $value, '-_', '+/' );
        $remainder = strlen( $padded ) % 4;
        if ( $remainder ) { $padded .= str_repeat( '=', 4 - $remainder ); }
        $decoded = base64_decode( $padded, true );
        return is_string( $decoded ) ? $decoded : '';
    }

    /**
     * Issues a token for one observed entity.
     *
     * @param string $scope  Stable entity scope, e.g. "post:412" or "option:blogname".
     * @param array  $facts  Observed preconditions: sha256, modified_gmt, occurrences, ...
     * @param string $client OAuth client id the token is bound to (may be empty offline).
     */
    public static function issue( string $scope, array $facts, string $client = '', int $ttl = 0 ): string {
        $ttl = $ttl > 0 ? min( self::MAX_TTL, max( 60, $ttl ) ) : self::DEFAULT_TTL;
        $payload = array(
            'v'     => 1,
            'scope' => $scope,
            'facts' => self::compact_facts( $facts ),
            'cli'   => '' !== $client ? substr( hash_hmac( 'sha256', $client, self::secret() ), 0, 16 ) : '',
            'iat'   => time(),
            'exp'   => time() + $ttl,
        );
        $json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
        $body = self::b64url_encode( (string) $json );
        $signature = self::b64url_encode( hash_hmac( 'sha256', self::PREFIX . '.' . $body, self::secret(), true ) );
        return self::PREFIX . '.' . $body . '.' . $signature;
    }

    /**
     * Verifies signature, expiry, scope and client binding.
     *
     * Returns the observed facts on success so the caller can enforce the very
     * same optimistic lock it would have enforced from explicit arguments.
     *
     * @return array|WP_Error
     */
    public static function verify( string $token, string $expected_scope = '', string $client = '' ) {
        $token = trim( $token );
        if ( '' === $token ) {
            return self::error( 'prstudio_write_token_missing', 'A write token is required for this mutation.', 400 );
        }
        $parts = explode( '.', $token );
        if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] ) {
            return self::error( 'prstudio_write_token_malformed', 'The write token is not in the expected format. Re-read the entity to obtain a fresh one.', 400 );
        }
        $expected_signature = self::b64url_encode( hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], self::secret(), true ) );
        if ( ! hash_equals( $expected_signature, $parts[2] ) ) {
            return self::error( 'prstudio_write_token_signature', 'The write token signature is invalid. It was not issued by this site.', 403 );
        }
        $payload = json_decode( self::b64url_decode( $parts[1] ), true );
        if ( ! is_array( $payload ) ) {
            return self::error( 'prstudio_write_token_payload', 'The write token payload could not be read. Re-read the entity.', 400 );
        }
        if ( (int) ( $payload['exp'] ?? 0 ) <= time() ) {
            return self::error(
                'prstudio_write_token_expired',
                'The write token expired. Read the entity again and use the new token; the content may have changed meanwhile.',
                409,
                array( 'issued_at' => (int) ( $payload['iat'] ?? 0 ), 'expired_at' => (int) ( $payload['exp'] ?? 0 ) )
            );
        }
        if ( '' !== $expected_scope && (string) ( $payload['scope'] ?? '' ) !== $expected_scope ) {
            return self::error(
                'prstudio_write_token_scope',
                'This write token belongs to a different entity than the one being written.',
                409,
                array( 'token_scope' => (string) ( $payload['scope'] ?? '' ), 'requested_scope' => $expected_scope )
            );
        }
        $bound = (string) ( $payload['cli'] ?? '' );
        if ( '' !== $bound && '' !== $client ) {
            $current = substr( hash_hmac( 'sha256', $client, self::secret() ), 0, 16 );
            if ( ! hash_equals( $bound, $current ) ) {
                return self::error( 'prstudio_write_token_client', 'This write token was issued to a different connector session.', 403 );
            }
        }
        return array(
            'scope' => (string) ( $payload['scope'] ?? '' ),
            'facts' => is_array( $payload['facts'] ?? null ) ? $payload['facts'] : array(),
            'issued_at' => (int) ( $payload['iat'] ?? 0 ),
            'expires_at' => (int) ( $payload['exp'] ?? 0 ),
        );
    }

    /**
     * Merges token facts into a legacy argument array.
     *
     * This is the compatibility bridge: 15.x callers that already pass explicit
     * `expected_before_sha256` keep working untouched, and a caller that passes
     * a token gets the same fields filled in for it. Explicit arguments always
     * win, so a token can never silently override something a caller stated.
     *
     * @return array|WP_Error
     */
    public static function apply_to_args( array $args, string $scope, string $client = '' ) {
        $token = trim( (string) ( $args['write_token'] ?? '' ) );
        if ( '' === $token ) { return $args; }
        $verified = self::verify( $token, $scope, $client );
        if ( is_wp_error( $verified ) ) { return $verified; }
        $facts = (array) $verified['facts'];
        $map = array(
            'sha256'        => 'expected_before_sha256',
            'modified_gmt'  => 'expected_modified_gmt',
            'occurrences'   => 'expected_occurrences',
        );
        foreach ( $map as $fact_key => $arg_key ) {
            if ( isset( $facts[ $fact_key ] ) && ! isset( $args[ $arg_key ] ) && '' !== (string) $facts[ $fact_key ] ) {
                $args[ $arg_key ] = $facts[ $fact_key ];
            }
        }
        // Anchor-specific counts: the read side recorded how many times each
        // anchor it was asked about actually occurs.
        $anchors = is_array( $facts['anchors'] ?? null ) ? $facts['anchors'] : array();
        $search = (string) ( $args['search'] ?? '' );
        if ( '' !== $search && ! isset( $args['expected_occurrences'] ) ) {
            $key = hash( 'sha256', $search );
            if ( isset( $anchors[ $key ] ) ) { $args['expected_occurrences'] = (int) $anchors[ $key ]; }
        }
        $args['_prstudio_write_token_verified'] = true;
        return $args;
    }

    /** Records anchor counts so a later write can declare them without counting. */
    public static function anchor_facts( string $content, array $anchors ): array {
        $counts = array();
        foreach ( $anchors as $anchor ) {
            if ( ! is_string( $anchor ) || '' === $anchor ) { continue; }
            $counts[ hash( 'sha256', $anchor ) ] = substr_count( $content, $anchor );
        }
        return $counts;
    }

    private static function compact_facts( array $facts ): array {
        // `state_sha256` is the commerce equivalent of `sha256`: the hash of a
        // product's mutable field surface rather than of a post body. It is a
        // separate key on purpose, so a token that binds a product's commerce
        // state can never be mistaken for one that binds its post content.
        $allowed = array( 'sha256', 'state_sha256', 'modified_gmt', 'occurrences', 'anchors', 'bytes', 'entity_type', 'entity_id', 'revision_id' );
        $compact = array();
        foreach ( $allowed as $key ) {
            if ( array_key_exists( $key, $facts ) ) { $compact[ $key ] = $facts[ $key ]; }
        }
        return $compact;
    }

    private static function error( string $code, string $message, int $status, array $details = array() ) {
        $details['status'] = $status;
        $details['remedy'] = 'Call prstudio_observe on this entity to obtain a fresh write_token, then repeat the write with it.';
        return new WP_Error( $code, $message, $details );
    }
}
