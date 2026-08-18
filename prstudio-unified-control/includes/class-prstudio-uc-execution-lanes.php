<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/**
 * Conversation/workstream isolation for MCP clients.
 *
 * A lane is intentionally narrower than an OAuth client: one OAuth client may
 * operate many ChatGPT conversations without sharing jobs, browser tabs or
 * mutating leases between them.
 *
 * State mutations are protected by an atomic WordPress-option mutex. This is
 * required because PHP requests can execute concurrently; a plain
 * get_option() -> update_option() sequence can otherwise grant the same
 * resource lease to two chats in a race.
 */
final class PRSTUDIO_UC_Execution_Lanes {
    public const VERSION = '1.1.0';

    private const OPTION = 'prstudio_uc_execution_lanes_v1';
    private const MUTEX_OPTION = 'prstudio_uc_execution_lanes_mutex_v1';
    private const MAX_LANES = 128;
    private const DEFAULT_TTL = 14400;
    private const MAX_TTL = 43200;
    private const MUTEX_TTL_SECONDS = 15;
    private const MUTEX_WAIT_MS = 10000;

    private static function now(): int { return time(); }

    private static function clean( string $value, int $max = 160 ): string {
        return substr( sanitize_text_field( $value ), 0, $max );
    }

    private static function load(): array {
        $value = get_option( self::OPTION, array() );
        if ( ! is_array( $value ) ) { $value = array(); }
        return wp_parse_args( $value, array( 'lanes' => array(), 'locks' => array() ) );
    }

    private static function save( array $state ): void {
        update_option( self::OPTION, $state, false );
    }

    /**
     * Remove expired lanes/locks and enforce bounded state.
     */
    private static function prune( array &$state ): void {
        $now = self::now();

        foreach ( (array) ( $state['lanes'] ?? array() ) as $id => $lane ) {
            if ( (int) ( $lane['expires_at'] ?? 0 ) <= $now ) {
                unset( $state['lanes'][ $id ] );
            }
        }

        foreach ( (array) ( $state['locks'] ?? array() ) as $resource => $lock ) {
            if (
                (int) ( $lock['expires_at'] ?? 0 ) <= $now
                || empty( $state['lanes'][ (string) ( $lock['lane_id'] ?? '' ) ] )
            ) {
                unset( $state['locks'][ $resource ] );
            }
        }

        uasort(
            $state['lanes'],
            static fn( $a, $b ) => (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 )
        );

        if ( count( $state['lanes'] ) > self::MAX_LANES ) {
            $state['lanes'] = array_slice( $state['lanes'], 0, self::MAX_LANES, true );
        }

        // A bounded lane slice can orphan locks; remove them in the same atomic
        // mutation instead of waiting for the next request to prune again.
        foreach ( (array) ( $state['locks'] ?? array() ) as $resource => $lock ) {
            if ( empty( $state['lanes'][ (string) ( $lock['lane_id'] ?? '' ) ] ) ) {
                unset( $state['locks'][ $resource ] );
            }
        }
    }

    private static function hash_owner( string $owner ): string {
        return '' === $owner ? '' : hash_hmac( 'sha256', $owner, wp_salt( 'auth' ) . '|prstudio-lane' );
    }

    private static function token_hash( string $token ): string {
        return hash_hmac( 'sha256', $token, wp_salt( 'secure_auth' ) . '|prstudio-lane-token' );
    }

    private static function mission_id( string $lane_id ): string {
        return 'mission:lane:' . substr( hash( 'sha256', $lane_id ), 0, 24 );
    }

    private static function deterministic_identity( string $owner, string $chat_key ): array {
        $secret = wp_salt( 'secure_auth' ) . '|prstudio-lane-chat-v1';
        $seed = $owner . '|' . $chat_key;
        return array(
            'lane_id' => 'lane_' . substr( hash_hmac( 'sha256', 'lane|' . $seed, $secret ), 0, 32 ),
            'token'   => hash_hmac( 'sha256', 'token|' . $seed, $secret ),
        );
    }

    /**
     * Cross-request mutex using add_option() as an atomic insert.
     *
     * WordPress' options table has a unique option_name key, so only one PHP
     * request can create the mutex option. A short expiry prevents a crashed
     * worker from permanently wedging the lane state. Unit-test environments
     * that do not provide add_option/delete_option fall back to direct
     * execution; production WordPress always provides them.
     */
    /**
     * Delete an option only if its stored value still matches what we read.
     *
     * WordPress has no compare-and-delete for options, so this issues the check
     * and the delete as one statement: the WHERE clause carries the expected
     * serialized value, and the row is removed only when nothing replaced it in
     * between. Returns true when this call is the one that removed it.
     */
    private static function delete_option_if_unchanged( string $option, $expected ): bool {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! function_exists( 'maybe_serialize' ) ) { return false; }
        $serialized = maybe_serialize( $expected );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a conditional delete is the point; the options API cannot express compare-and-delete.
        $deleted = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $option,
            $serialized
        ) );
        if ( $deleted > 0 && function_exists( 'wp_cache_delete' ) ) {
            // The options cache would otherwise keep serving the row this
            // statement just removed.
            wp_cache_delete( $option, 'options' );
            wp_cache_delete( 'alloptions', 'options' );
        }
        return $deleted > 0;
    }

    private static function with_mutex( callable $callback ) {
        if ( ! function_exists( 'add_option' ) || ! function_exists( 'delete_option' ) ) {
            return $callback();
        }

        $owner = bin2hex( random_bytes( 16 ) );
        $deadline = microtime( true ) + ( self::MUTEX_WAIT_MS / 1000 );
        $acquired = false;

        do {
            $record = array(
                'owner'      => $owner,
                'expires_at' => microtime( true ) + self::MUTEX_TTL_SECONDS,
            );

            if ( add_option( self::MUTEX_OPTION, $record, '', false ) ) {
                $acquired = true;
                break;
            }

            $existing = get_option( self::MUTEX_OPTION, null );
            if ( is_array( $existing ) && (float) ( $existing['expires_at'] ?? 0 ) <= microtime( true ) ) {
                // Reclaim a stale mutex, but only the exact record this request
                // observed. An unconditional delete_option() here was a lost
                // update: two contenders could both read the same expired lock,
                // the first delete it and a third acquire a fresh one, and then
                // the second -- still acting on its stale read -- delete that new
                // owner's lock. Two lanes could then mutate at once, which is the
                // one thing this mutex exists to prevent.
                //
                // A conditional delete keyed on the serialized value makes the
                // reclaim atomic: it matches only if nobody replaced the row.
                self::delete_option_if_unchanged( self::MUTEX_OPTION, $existing );
            }

            // Keep the retry jitter below a typical database round trip. A
            // 15–45 ms sleep allowed late contenders to starve when dozens of
            // scheduled/browser requests arrived together on Windows/PHP-FPM.
            usleep( random_int( 2000, 8000 ) );
        } while ( microtime( true ) < $deadline );

        if ( ! $acquired ) {
            return new WP_Error(
                'execution_lane_mutex_timeout',
                'Execution lane state is busy. Retry the operation without opening another context.',
                array( 'status' => 503, 'retryable' => true )
            );
        }

        try {
            return $callback();
        } finally {
            $current = get_option( self::MUTEX_OPTION, null );
            if ( is_array( $current ) && hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) ) {
                delete_option( self::MUTEX_OPTION );
            }
        }
    }

    /**
     * Atomic state mutation. All cross-chat lease decisions pass through here.
     */
    private static function mutate( callable $callback ) {
        return self::with_mutex(
            static function() use ( $callback ) {
                $state = self::load();
                self::prune( $state );
                $before = $state;
                $result = $callback( $state );
                if ( is_wp_error( $result ) ) {
                    // Persist pruning only; never commit callback partials when
                    // the semantic mutation itself failed.
                    self::prune( $before );
                    self::save( $before );
                    return $result;
                }
                self::prune( $state );
                self::save( $state );
                return $result;
            }
        );
    }

    public static function open( array $args, array $context = array() ) {
        $owner = self::clean( (string) ( $context['client_id'] ?? $args['_client_id'] ?? '' ), 200 );
        if ( '' === $owner ) {
            return new WP_Error(
                'lane_owner_required',
                'Execution lane requires an authenticated MCP client.',
                array( 'status' => 401 )
            );
        }

        $label = self::clean( (string) ( $args['label'] ?? $args['objective'] ?? 'ChatGPT workstream' ), 160 );
        $chat_key = self::clean( (string) ( $args['chat_key'] ?? '' ), 200 );
        $ttl = max( 900, min( self::MAX_TTL, (int) ( $args['ttl_seconds'] ?? self::DEFAULT_TTL ) ) );
        $identity = '' !== $chat_key ? self::deterministic_identity( $owner, $chat_key ) : array();
        $lane_id = (string) ( $identity['lane_id'] ?? ( 'lane_' . str_replace( '-', '', wp_generate_uuid4() ) ) );
        $token = (string) ( $identity['token'] ?? bin2hex( random_bytes( 24 ) ) );
        $now = self::now();

        $lane = array(
            'lane_id'       => $lane_id,
            'token_hash'    => self::token_hash( $token ),
            'owner_hash'    => self::hash_owner( $owner ),
            'label'         => $label,
            'chat_key_hash' => '' === $chat_key ? '' : hash( 'sha256', $chat_key ),
            'mission_id'    => self::mission_id( $lane_id ),
            'created_at'    => $now,
            'updated_at'    => $now,
            'expires_at'    => $now + $ttl,
            'status'        => 'active',
            'resources'     => array(),
        );

        $reused = false;
        $stored = self::mutate(
            static function( array &$state ) use ( $lane_id, $lane, &$reused ) {
                $existing = $state['lanes'][ $lane_id ] ?? null;
                if ( is_array( $existing ) && hash_equals( (string) ( $existing['owner_hash'] ?? '' ), (string) $lane['owner_hash'] ) ) {
                    $reused = true;
                    $existing['label'] = $lane['label'];
                    $existing['updated_at'] = $lane['updated_at'];
                    $existing['expires_at'] = $lane['expires_at'];
                    $existing['status'] = 'active';
                    $existing['token_hash'] = $lane['token_hash'];
                    $existing['chat_key_hash'] = $lane['chat_key_hash'];
                    $state['lanes'][ $lane_id ] = $existing;
                    return true;
                }
                $state['lanes'][ $lane_id ] = $lane;
                return true;
            }
        );
        if ( is_wp_error( $stored ) ) { return $stored; }

        if ( class_exists( 'PRSTUDIO_UC_Memory' ) ) {
            PRSTUDIO_UC_Memory::mission(
                $lane['mission_id'],
                array(
                    'status'      => 'running',
                    'objective'   => $label,
                    'lane_id'     => $lane_id,
                    $reused ? 'resumed_gmt' : 'created_gmt' => gmdate( 'c' ),
                )
            );
        }

        return array(
            'ok'         => true,
            'version'    => self::VERSION,
            'component'  => 'execution_lanes',
            'component_version' => self::VERSION,
            'suite_version' => defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : '',
            'lane_id'    => $lane_id,
            // Public continuation credential. It is deliberately an opaque,
            // non-secret identifier and is useful only when it is resolved
            // together with the authenticated OAuth client binding.
            'lane_handle'=> $lane_id,
            // Kept for internal/backward-compatible PHP callers. MCP result
            // cleaning continues to redact this secret field.
            'lane_token' => $token,
            'mission_id' => $lane['mission_id'],
            'expires_gmt'=> gmdate( 'c', $lane['expires_at'] ),
            'isolation'  => 'exclusive_mutation_leases_per_resource',
            'reused'      => $reused,
        );
    }

    public static function resolve( string $token, string $owner = '' ) {
        $token = trim( $token );
        if ( '' === $token ) { return null; }

        if ( preg_match( '/^lane_[a-f0-9]{32}$/', $token ) ) {
            return self::resolve_handle( $token, $owner );
        }

        $hash = self::token_hash( $token );
        $owner_hash = self::hash_owner( $owner );
        $state = self::load();
        self::prune( $state );

        foreach ( (array) ( $state['lanes'] ?? array() ) as $lane ) {
            if ( ! hash_equals( (string) ( $lane['token_hash'] ?? '' ), $hash ) ) { continue; }
            if ( '' !== $owner && ! hash_equals( (string) ( $lane['owner_hash'] ?? '' ), $owner_hash ) ) { return null; }
            return $lane;
        }
        return null;
    }

    /**
     * Resolve the public lane handle only inside an authenticated OAuth client
     * binding. A bare lane ID is never an ambient bearer credential.
     */
    public static function resolve_handle( string $handle, string $owner ) {
        $handle = strtolower( trim( $handle ) );
        $owner = trim( $owner );
        if ( '' === $owner || ! preg_match( '/^lane_[a-f0-9]{32}$/', $handle ) ) { return null; }

        $state = self::load();
        self::prune( $state );
        $lane = $state['lanes'][ $handle ] ?? null;
        if ( ! is_array( $lane ) ) { return null; }
        if ( ! hash_equals( (string) ( $lane['owner_hash'] ?? '' ), self::hash_owner( $owner ) ) ) { return null; }
        return $lane;
    }

    private static function credential( array $args ): string {
        $handle = trim( (string) ( $args['lane_handle'] ?? '' ) );
        return '' !== $handle ? $handle : trim( (string) ( $args['lane_token'] ?? '' ) );
    }

    public static function guard( string $token, string $owner = '', string $resource = '', bool $exclusive = false ) {
        $lane = self::resolve( $token, $owner );
        if ( ! $lane ) {
            return new WP_Error(
                'execution_lane_invalid',
                'Execution lane is missing, expired or belongs to another MCP context.',
                array( 'status' => 409, 'retryable' => false, 'next_tool' => 'prstudio_context_open' )
            );
        }
        if ( ! $exclusive || '' === trim( $resource ) ) { return $lane; }

        $acquired = self::acquire(
            array( 'lane_token' => $token, 'resource' => $resource, 'ttl_seconds' => 300 ),
            array( 'client_id' => $owner )
        );
        if ( is_wp_error( $acquired ) ) { return $acquired; }
        return $lane;
    }

    public static function acquire( array $args, array $context = array() ) {
        $token = self::credential( $args );
        $owner = (string) ( $context['client_id'] ?? $args['_client_id'] ?? '' );
        $lane = self::resolve( $token, $owner );
        if ( ! $lane ) {
            return new WP_Error( 'execution_lane_invalid', 'Execution lane is invalid or expired.', array( 'status' => 409 ) );
        }

        $resource = strtolower( trim( (string) ( $args['resource'] ?? '' ) ) );
        if ( '' === $resource ) {
            return new WP_Error( 'lane_resource_required', 'Resource key is required.', array( 'status' => 400 ) );
        }

        $ttl = max( 30, min( 1800, (int) ( $args['ttl_seconds'] ?? 300 ) ) );
        $now = self::now();

        return self::mutate(
            static function( array &$state ) use ( $lane, $resource, $ttl, $now ) {
                if ( empty( $state['lanes'][ $lane['lane_id'] ] ) ) {
                    return new WP_Error( 'execution_lane_invalid', 'Execution lane expired before the lease could be acquired.', array( 'status' => 409 ) );
                }

                $existing = $state['locks'][ $resource ] ?? null;
                if (
                    is_array( $existing )
                    && ( $existing['lane_id'] ?? '' ) !== $lane['lane_id']
                    && (int) ( $existing['expires_at'] ?? 0 ) > $now
                ) {
                    return new WP_Error(
                        'resource_busy_other_context',
                        'Resource is already leased by another ChatGPT workstream.',
                        array(
                            'status'      => 409,
                            'retryable'   => true,
                            'resource'    => $resource,
                            'expires_gmt' => gmdate( 'c', (int) $existing['expires_at'] ),
                        )
                    );
                }

                $state['locks'][ $resource ] = array(
                    'lane_id'    => $lane['lane_id'],
                    'expires_at' => $now + $ttl,
                    'updated_at' => $now,
                );
                $state['lanes'][ $lane['lane_id'] ]['resources'][ $resource ] = $now + $ttl;
                $state['lanes'][ $lane['lane_id'] ]['updated_at'] = $now;

                return array(
                    'ok'          => true,
                    'lane_id'     => $lane['lane_id'],
                    'resource'    => $resource,
                    'expires_gmt' => gmdate( 'c', $now + $ttl ),
                );
            }
        );
    }

    public static function release( array $args, array $context = array() ) {
        $token = self::credential( $args );
        $owner = (string) ( $context['client_id'] ?? $args['_client_id'] ?? '' );
        $lane = self::resolve( $token, $owner );
        if ( ! $lane ) {
            return new WP_Error( 'execution_lane_invalid', 'Execution lane is invalid or expired.', array( 'status' => 409 ) );
        }
        $resource = strtolower( trim( (string) ( $args['resource'] ?? '' ) ) );

        return self::mutate(
            static function( array &$state ) use ( $lane, $resource ) {
                if ( isset( $state['locks'][ $resource ] ) && ( $state['locks'][ $resource ]['lane_id'] ?? '' ) === $lane['lane_id'] ) {
                    unset( $state['locks'][ $resource ] );
                }
                if ( isset( $state['lanes'][ $lane['lane_id'] ]['resources'][ $resource ] ) ) {
                    unset( $state['lanes'][ $lane['lane_id'] ]['resources'][ $resource ] );
                }
                return array( 'ok' => true, 'released' => $resource );
            }
        );
    }

    public static function heartbeat( array $args, array $context = array() ) {
        $token = self::credential( $args );
        $owner = (string) ( $context['client_id'] ?? $args['_client_id'] ?? '' );
        $lane = self::resolve( $token, $owner );
        if ( ! $lane ) {
            return new WP_Error( 'execution_lane_invalid', 'Execution lane is invalid or expired.', array( 'status' => 409 ) );
        }

        $ttl = max( 900, min( self::MAX_TTL, (int) ( $args['ttl_seconds'] ?? self::DEFAULT_TTL ) ) );
        $resource_ttl = max( 60, min( 1800, (int) ( $args['resource_ttl_seconds'] ?? 300 ) ) );
        $now = self::now();

        return self::mutate(
            static function( array &$state ) use ( $lane, $ttl, $resource_ttl, $now ) {
                if ( empty( $state['lanes'][ $lane['lane_id'] ] ) ) {
                    return new WP_Error( 'execution_lane_invalid', 'Execution lane expired before heartbeat renewal.', array( 'status' => 409 ) );
                }
                $state['lanes'][ $lane['lane_id'] ]['expires_at'] = $now + $ttl;
                $state['lanes'][ $lane['lane_id'] ]['updated_at'] = $now;
                $renewed = 0;
                foreach ( (array) ( $state['lanes'][ $lane['lane_id'] ]['resources'] ?? array() ) as $resource => $old_expiry ) {
                    if ( ! isset( $state['locks'][ $resource ] ) || ( $state['locks'][ $resource ]['lane_id'] ?? '' ) !== $lane['lane_id'] ) {
                        unset( $state['lanes'][ $lane['lane_id'] ]['resources'][ $resource ] );
                        continue;
                    }
                    $new_expiry = $now + $resource_ttl;
                    $state['locks'][ $resource ]['expires_at'] = $new_expiry;
                    $state['locks'][ $resource ]['updated_at'] = $now;
                    $state['lanes'][ $lane['lane_id'] ]['resources'][ $resource ] = $new_expiry;
                    $renewed++;
                }
                return array(
                    'ok'                => true,
                    'component'         => 'execution_lanes',
                    'component_version' => self::VERSION,
                    'suite_version'     => defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : '',
                    'lane_id'           => $lane['lane_id'],
                    'lane_handle'       => $lane['lane_id'],
                    'mission_id'        => $lane['mission_id'],
                    'expires_gmt'       => gmdate( 'c', $now + $ttl ),
                    'resources_renewed' => $renewed,
                );
            }
        );
    }

    public static function close( array $args, array $context = array() ) {
        $token = self::credential( $args );
        $owner = (string) ( $context['client_id'] ?? $args['_client_id'] ?? '' );
        $lane = self::resolve( $token, $owner );
        if ( ! $lane ) {
            return new WP_Error( 'execution_lane_invalid', 'Execution lane is invalid or expired.', array( 'status' => 409 ) );
        }

        $closed = self::mutate(
            static function( array &$state ) use ( $lane ) {
                foreach ( (array) ( $state['locks'] ?? array() ) as $resource => $lock ) {
                    if ( ( $lock['lane_id'] ?? '' ) === $lane['lane_id'] ) {
                        unset( $state['locks'][ $resource ] );
                    }
                }
                unset( $state['lanes'][ $lane['lane_id'] ] );
                return true;
            }
        );
        if ( is_wp_error( $closed ) ) { return $closed; }

        if ( class_exists( 'PRSTUDIO_UC_Memory' ) ) {
            PRSTUDIO_UC_Memory::mission(
                $lane['mission_id'],
                array(
                    'status'     => 'closed',
                    'lane_id'    => $lane['lane_id'],
                    'closed_gmt' => gmdate( 'c' ),
                )
            );
        }

        return array( 'ok' => true, 'closed' => true, 'lane_id' => $lane['lane_id'], 'lane_handle' => $lane['lane_id'], 'mission_id' => $lane['mission_id'], 'component'=>'execution_lanes', 'component_version'=>self::VERSION, 'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'' );
    }

    public static function status( array $args = array(), array $context = array() ) {
        $owner = (string) ( $context['client_id'] ?? $args['_client_id'] ?? '' );
        $owner_hash = self::hash_owner( $owner );
        $state = self::load();
        self::prune( $state );

        $rows = array();
        foreach ( (array) ( $state['lanes'] ?? array() ) as $lane ) {
            if ( '' !== $owner && ! hash_equals( (string) $lane['owner_hash'], $owner_hash ) ) { continue; }
            $rows[] = array(
                'lane_id'        => $lane['lane_id'],
                'lane_handle'    => $lane['lane_id'],
                'label'          => $lane['label'],
                'mission_id'     => $lane['mission_id'],
                'status'         => $lane['status'],
                'resource_count' => count( (array) $lane['resources'] ),
                'expires_gmt'    => gmdate( 'c', (int) $lane['expires_at'] ),
            );
        }

        return array(
            'ok'      => true,
            'version' => self::VERSION,
            'component' => 'execution_lanes',
            'component_version' => self::VERSION,
            'suite_version' => defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : '',
            'lanes'   => $rows,
            'count'   => count( $rows ),
            'locks'   => count( (array) ( $state['locks'] ?? array() ) ),
        );
    }

    public static function resource_key( string $cap_id, array $args ): string {
        if ( isset( $args['id'] ) ) { return 'wp:post:' . absint( $args['id'] ); }
        if ( isset( $args['tab_id'] ) ) { return 'browser:tab:' . absint( $args['tab_id'] ); }
        if ( isset( $args['inspection_url'] ) ) { return 'gsc:url:' . strtolower( (string) $args['inspection_url'] ); }
        if ( isset( $args['target_url'] ) ) { return 'url:' . strtolower( (string) $args['target_url'] ); }
        if ( isset( $args['url'] ) ) { return 'url:' . strtolower( (string) $args['url'] ); }
        if ( isset( $args['path'] ) ) { return 'file:' . strtolower( (string) $args['path'] ); }
        if ( isset( $args['keyword'] ) ) { return 'seo:keyword:' . strtolower( trim( (string) $args['keyword'] ) ); }
        if ( isset( $args['watch_id'] ) ) { return 'seo:watch:' . strtolower( trim( (string) $args['watch_id'] ) ); }
        return '';
    }
}
