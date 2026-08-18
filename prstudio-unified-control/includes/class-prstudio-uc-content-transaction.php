<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Deterministic, idempotent WordPress content mutation transaction.
 *
 * This is intentionally narrower than arbitrary post editing. It mutates one
 * existing post using an exact textual anchor and verifies the persisted DB
 * value independently. Public rendering is a second verification channel and
 * never turns a stale cache into a second mutation attempt.
 */
final class PRSTUDIO_UC_Content_Transaction {
    public const VERSION = '1.0.0';
    private const MAX_CONTENT_BYTES = 5242880; // 5 MiB.
    /**
     * How long a writer waits for the per-post advisory lock before giving up.
     * Short on purpose: a caller that waits is told to retry rather than holding
     * a PHP worker, and the critical section is a handful of queries.
     */
    private const ENTITY_LOCK_TIMEOUT_SECONDS = 5;

    private static function error( string $code, string $message, int $status = 400, array $data = array() ) {
        $data['status'] = $status;
        return new WP_Error( $code, $message, $data );
    }

    private static function snapshot( WP_Post $post ): array {
        return array(
            'id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'content' => (string) $post->post_content,
            'sha256' => hash( 'sha256', (string) $post->post_content ),
            'modified_gmt' => (string) get_post_modified_time( DATE_ATOM, true, $post ),
            'permalink' => (string) get_permalink( $post ),
        );
    }

    private static function public_verify( string $url, string $needle ): array {
        if ( '' === $url || '' === $needle ) {
            return array( 'requested' => false, 'verified' => null, 'reason' => 'verification_not_requested' );
        }
        $attempts = array();
        for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
            $response = wp_safe_remote_get( $url, array(
                'timeout' => 6,
                'redirection' => 5,
                'headers' => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ),
                'user-agent' => 'PR-STUDIO-Content-Transaction/' . self::VERSION,
            ) );
            if ( is_wp_error( $response ) ) {
                $attempts[] = array( 'attempt' => $attempt, 'error' => $response->get_error_code() );
            } else {
                $body = (string) wp_remote_retrieve_body( $response );
                $status = (int) wp_remote_retrieve_response_code( $response );
                $found = false !== strpos( $body, $needle );
                $attempts[] = array( 'attempt' => $attempt, 'status' => $status, 'bytes' => strlen( $body ), 'contains' => $found );
                if ( $status >= 200 && $status < 400 && $found ) {
                    return array( 'requested' => true, 'verified' => true, 'attempts' => $attempts );
                }
            }
            if ( 1 === $attempt ) {
                // Targeted invalidation only. 15.x called wp_cache_flush() here,
                // which empties the *entire* site object cache — on a Redis or
                // Memcached install that evicts every other plugin's warm data
                // and every session, once per unverified content check. The post
                // whose rendering we are verifying is the only thing that needs
                // clearing.
                $post_id = function_exists( 'url_to_postid' ) ? (int) url_to_postid( $url ) : 0;
                if ( $post_id > 0 && function_exists( 'clean_post_cache' ) ) { clean_post_cache( $post_id ); }
                if ( function_exists( 'has_action' ) && has_action( 'wpaib_cache_purge_url' ) ) { do_action( 'wpaib_cache_purge_url', $url ); }
                if ( function_exists( 'has_action' ) && has_action( 'litespeed_purge_url' ) ) { do_action( 'litespeed_purge_url', $url ); }
                // Common page-cache plugins expose a single-URL purge; use it
                // rather than a global flush when one is present.
                if ( $post_id > 0 && function_exists( 'wp_cache_post_change' ) ) { wp_cache_post_change( $post_id ); }
                if ( function_exists( 'rocket_clean_post' ) && $post_id > 0 ) { rocket_clean_post( $post_id ); }
                usleep( 75000 );
            }
        }
        return array( 'requested' => true, 'verified' => false, 'attempts' => $attempts, 'retry_mutation' => false );
    }

    /**
     * Saves at most one revision per post per work session.
     *
     * The marker lives in the object cache with a transient fallback and a
     * short window, so a genuinely new editing session still gets its own
     * revision while a burst of retries does not.
     */
    private static function maybe_save_revision( int $post_id ): int {
        if ( ! function_exists( 'wp_save_post_revision' ) ) { return 0; }
        $session = '';
        if ( class_exists( 'PRSTUDIO_UC_Work_Session' ) ) {
            $active = PRSTUDIO_UC_Work_Session::active();
            $session = is_array( $active ) ? (string) ( $active['work_id'] ?? '' ) : '';
        }
        if ( '' === $session ) { $session = 'window:' . floor( time() / 900 ); }
        $key = 'rev_' . substr( hash( 'sha256', $session . '|' . $post_id ), 0, 24 );

        if ( function_exists( 'wp_cache_get' ) ) {
            $found = false;
            $seen = wp_cache_get( $key, 'prstudio_uc_revisions', false, $found );
            if ( $found && $seen ) { return (int) $seen; }
        } elseif ( function_exists( 'get_transient' ) ) {
            $seen = get_transient( 'prstudio_' . $key );
            if ( false !== $seen ) { return (int) $seen; }
        }

        $revision_id = (int) wp_save_post_revision( $post_id );
        if ( function_exists( 'wp_cache_set' ) ) { wp_cache_set( $key, $revision_id, 'prstudio_uc_revisions', HOUR_IN_SECONDS ); }
        if ( function_exists( 'set_transient' ) ) { set_transient( 'prstudio_' . $key, $revision_id, HOUR_IN_SECONDS ); }
        return $revision_id;
    }

    public static function patch( array $args ) {

        $id = absint( $args['id'] ?? 0 );
        // prstudio_do documents target as "URL or ID", and a URL is what a caller
        // naturally has when it is looking at a page. Resolve it here rather than
        // rejecting: the alternative forced an extra lookup turn to convert a URL
        // the site itself can map, and made the documented contract untrue.
        if ( ! $id && function_exists( 'url_to_postid' ) ) {
            foreach ( array( 'url', 'permalink', 'target', 'post_url' ) as $key ) {
                $candidate = trim( (string) ( $args[ $key ] ?? '' ) );
                if ( '' === $candidate || ! preg_match( '#^https?://#i', $candidate ) ) { continue; }
                $resolved = absint( url_to_postid( $candidate ) );
                if ( $resolved > 0 ) { $id = $resolved; break; }
            }
        }
        if ( ! $id ) {
            return self::error(
                'prstudio_content_id_required',
                'A valid existing post ID is required. A URL is also accepted when it resolves to a post on this site; the URL supplied did not resolve, so pass id explicitly.'
            );
        }
        if ( class_exists( 'PRSTUDIO_UC_Execution_Lanes' ) && ! empty( $args['lane_token'] ) ) {
            $lane = PRSTUDIO_UC_Execution_Lanes::guard(
                (string) $args['lane_token'],
                (string) ( $args['_client_id'] ?? '' ),
                'wp:post:' . $id,
                true
            );
            if ( is_wp_error( $lane ) ) { return $lane; }
        }
        // Serialize concurrent writers on this one post before reading it.
        // Everything below -- read, precondition check, write -- has to happen as
        // a unit. The hash comparison proves the content had not changed when it
        // was read; it says nothing about the window between that check and
        // wp_update_post(). Two callers could both read the same valid hash, both
        // pass the optimistic-lock check, and the second silently overwrite the
        // first with content derived from a stale base. Nothing upstream closes
        // that window either: a lane is explicitly not a prerequisite for
        // deterministic WordPress mutations.
        $lock = self::acquire_entity_lock( $id );
        if ( 'busy' === $lock['state'] ) {
            return self::error(
                'prstudio_content_locked',
                'Another write to this content is in progress. Retry in a moment.',
                409,
                array( 'retryable' => true, 'id' => $id )
            );
        }
        try {
            return self::patch_locked( $id, $args, $lock );
        } finally {
            self::release_entity_lock( $lock );
        }
    }

    /**
     * Acquire a per-post advisory lock for the read-verify-write critical section.
     *
     * GET_LOCK is atomic, connection-scoped and released automatically if the
     * request dies, so a crashed writer cannot strand the post. The database name
     * is folded into the lock name because GET_LOCK's namespace is server-wide
     * and several sites may share one MySQL instance.
     *
     * @return array{state:string,name:string}
     */
    private static function acquire_entity_lock( int $post_id ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return array( 'state' => 'unavailable', 'name' => '' );
        }
        $scope = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . (string) $wpdb->prefix;
        $name = 'prstudio_content_' . substr( hash( 'sha256', $scope . '|' . $post_id ), 0, 40 );
        $result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::ENTITY_LOCK_TIMEOUT_SECONDS ) );
        if ( null === $result ) {
            // The server does not support advisory locks here. Proceeding
            // unserialized is worse than refusing outright would be for
            // availability, so continue and record that the window is open.
            return array( 'state' => 'unavailable', 'name' => '' );
        }
        return '1' === (string) $result
            ? array( 'state' => 'held', 'name' => $name )
            : array( 'state' => 'busy', 'name' => $name );
    }

    private static function release_entity_lock( array $lock ): void {
        global $wpdb;
        if ( 'held' !== ( $lock['state'] ?? '' ) || '' === (string) ( $lock['name'] ?? '' ) ) { return; }
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) { return; }
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $lock['name'] ) );
    }

    /**
     * The critical section of patch(): read, verify preconditions, write.
     * Runs under the per-post advisory lock acquired by patch().
     */
    private static function patch_locked( int $id, array $args, array $lock ) {
        $post = get_post( $id );
        if ( ! $post instanceof WP_Post ) { return self::error( 'prstudio_content_missing', 'Content not found.', 404 ); }

        $before = self::snapshot( $post );
        $expected_hash = strtolower( trim( (string) ( $args['expected_before_sha256'] ?? '' ) ) );
        $expected_modified = trim( (string) ( $args['expected_modified_gmt'] ?? '' ) );
        if ( '' === $expected_hash && '' === $expected_modified ) {
            // Deterministic fast path: bind this mutation to the state we just read locally.
            // No model round-trip is required merely to echo the current hash back to us.
            $expected_hash = strtolower( (string) $before['sha256'] );
        }
        if ( '' !== $expected_hash && ! hash_equals( $before['sha256'], $expected_hash ) ) {
            return self::error( 'prstudio_content_hash_conflict', 'Content changed since it was read.', 409, array( 'current_sha256' => $before['sha256'], 'expected_sha256' => $expected_hash ) );
        }
        if ( '' !== $expected_modified && $expected_modified !== $before['modified_gmt'] ) {
            return self::error( 'prstudio_content_modified_conflict', 'Content modified timestamp changed since it was read.', 409, array( 'current_modified_gmt' => $before['modified_gmt'], 'expected_modified_gmt' => $expected_modified ) );
        }

        $operation = sanitize_key( (string) ( $args['operation'] ?? 'replace_exact' ) );
        if ( ! in_array( $operation, array( 'replace_exact', 'insert_before', 'insert_after', 'append_once' ), true ) ) {
            return self::error( 'prstudio_content_operation_invalid', 'Unsupported content transaction operation.' );
        }
        $search = (string) ( $args['search'] ?? '' );
        $replacement_raw = (string) ( $args['replacement'] ?? '' );
        $replacement = function_exists( 'wp_kses_post' ) ? wp_kses_post( $replacement_raw ) : $replacement_raw;
        $marker = trim( (string) ( $args['idempotency_marker'] ?? '' ) );
        $expected_occurrences = max( 1, min( 100, (int) ( $args['expected_occurrences'] ?? 1 ) ) );

        if ( '' !== $marker && false !== strpos( $before['content'], $marker ) ) {
            $public = ! empty( $args['public_verify'] ) ? self::public_verify( $before['permalink'], (string) ( $args['verify_contains'] ?? $marker ) ) : array( 'requested' => false, 'verified' => null );
            return array(
                'transaction_version' => self::VERSION,
                'id' => $id,
                'changed' => false,
                'idempotent_reuse' => true,
                'reason' => 'marker_already_present',
                'db_verified' => true,
                'frontend_verification' => $public,
                'before_sha256' => $before['sha256'],
                'after_sha256' => $before['sha256'],
                'permalink' => $before['permalink'],
            );
        }

        $content = $before['content'];
        if ( 'append_once' === $operation ) {
            $after_content = rtrim( $content ) . "\n\n" . $replacement . "\n";
            $actual_occurrences = null;
        } else {
            if ( '' === $search ) { return self::error( 'prstudio_content_search_required', 'An exact search anchor is required for this operation.' ); }
            $actual_occurrences = substr_count( $content, $search );
            if ( $actual_occurrences !== $expected_occurrences ) {
                return self::error(
                    'prstudio_content_anchor_count_mismatch',
                    'The anchor occurs a different number of times than declared, so the write was not attempted.',
                    409,
                    array(
                        'expected_occurrences' => $expected_occurrences,
                        'actual_occurrences'   => $actual_occurrences,
                        'preferred_remedy'     => 0 === $actual_occurrences
                            ? 'The anchor text is not present at all. Re-read the post with prstudio_observe and pick an anchor that exists.'
                            : 'Pass this anchor in prstudio_observe anchors[] so the count is measured for you, then retry with the returned write_token.',
                        'retryable'            => true,
                    )
                );
            }
            if ( 'replace_exact' === $operation ) {
                $after_content = str_replace( $search, $replacement, $content, $count );
            } elseif ( 'insert_before' === $operation ) {
                $after_content = str_replace( $search, $replacement . $search, $content, $count );
            } else {
                $after_content = str_replace( $search, $search . $replacement, $content, $count );
            }
            if ( (int) $count !== $expected_occurrences ) {
                return self::error( 'prstudio_content_replace_count_mismatch', 'Mutation count did not match the declared precondition.', 409, array( 'expected_occurrences' => $expected_occurrences, 'actual_replacements' => (int) $count ) );
            }
        }

        if ( strlen( $after_content ) > self::MAX_CONTENT_BYTES ) { return self::error( 'prstudio_content_too_large', 'Resulting content exceeds the bounded transaction size.', 413 ); }
        if ( $after_content === $content ) {
            return array( 'transaction_version' => self::VERSION, 'id' => $id, 'changed' => false, 'idempotent_reuse' => true, 'reason' => 'no_change', 'db_verified' => true, 'before_sha256' => $before['sha256'], 'after_sha256' => $before['sha256'] );
        }

        $target_hash = hash( 'sha256', $after_content );

        // All optimistic-lock, anchor-count, idempotency and size preconditions
        // have succeeded. This is the actual pre-commit boundary: only now is a
        // protected WordPress mutation possible.
        if ( class_exists( 'PRSTUDIO_UC_Pre_Mutation_Safety' ) ) {
            $gate = PRSTUDIO_UC_Pre_Mutation_Safety::before_commit( 'content', 'wordpress_content_transaction', $args );
            if ( is_wp_error( $gate ) ) { return $gate; }
        }

        // One revision per post per work session, not one per call. 15.x saved a
        // revision on every attempt, so ten tries against one page deposited ten
        // rows in wp_posts; a busy afternoon left thousands. The first touch in a
        // session still captures the pre-change state, which is what a revision
        // is actually for.
        $revision_id = self::maybe_save_revision( $id );
        $updated = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $after_content ) ), true );
        if ( is_wp_error( $updated ) ) { return $updated; }

        $persisted = get_post( $id );
        $persisted_content = $persisted instanceof WP_Post ? (string) $persisted->post_content : '';
        $persisted_hash = hash( 'sha256', $persisted_content );
        if ( ! hash_equals( $target_hash, $persisted_hash ) ) {
            // PR STUDIO ONE-GUARD INVARIANT: a successful mutation call is never
            // undone merely because readback evidence is inconclusive/different.
            // Observe, report degraded evidence, and leave the persisted state as-is.
            return array(
                'transaction_version' => self::VERSION,
                'id' => $id,
                'changed' => true,
                'executed' => true,
                'operation' => $operation,
                'revision_id' => $revision_id,
                'before_sha256' => $before['sha256'],
                'expected_after_sha256' => $target_hash,
                'observed_sha256' => $persisted_hash,
                'db_verified' => false,
                'verified' => false,
                'degraded' => true,
                'blocking' => false,
                'state' => 'PERSISTED_UNVERIFIED',
                'technical_error' => $persisted instanceof WP_Post ? null : array( 'code'=>'post_readback_unavailable', 'retryable'=>true ),
                'retry_mutation' => false,
            );
        }

        $after = self::snapshot( $persisted );
        $needle = (string) ( $args['verify_contains'] ?? ( '' !== $marker ? $marker : $replacement ) );
        $public = ! empty( $args['public_verify'] ) ? self::public_verify( $after['permalink'], $needle ) : array( 'requested' => false, 'verified' => null );
        $frontend_verified = true === ( $public['verified'] ?? null );

        if ( class_exists( 'WPAIB_Audit' ) ) {
            WPAIB_Audit::log( 'site.content.transaction', 'success', (string) $id, array(
                'operation' => $operation,
                'before_sha256' => $before['sha256'],
                'after_sha256' => $after['sha256'],
                'db_verified' => true,
                'frontend_verified' => $frontend_verified,
                'revision_id' => $revision_id,
            ) );
        }
        if ( class_exists( 'PRSTUDIO_Report' ) ) {
            PRSTUDIO_Report::record_change( 'Content transaction', (string) $id, array( 'sha256' => $before['sha256'] ), array( 'sha256' => $after['sha256'] ), array( 'url' => $after['permalink'] ) );
        }
        // Record the applied change so a later audit does not rediscover and
        // re-propose it. A caller that knows the semantic name of what it just
        // did should pass intervention_key; otherwise the marker or anchor is a
        // stable enough identity to prevent an exact repeat.
        if ( class_exists( 'PRSTUDIO_UC_Interventions' ) ) {
            $intervention_key = (string) ( $args['intervention_key'] ?? '' );
            if ( '' === $intervention_key ) {
                $seed = '' !== $marker ? $marker : ( '' !== $search ? $search : $operation );
                $intervention_key = 'content_' . substr( hash( 'sha256', $seed ), 0, 16 );
            }
            PRSTUDIO_UC_Interventions::record(
                PRSTUDIO_UC_Interventions::normalize_entity( 'post', $id ),
                $intervention_key,
                PRSTUDIO_UC_Interventions::APPLIED,
                array(
                    'summary'        => sprintf( '%s applied to post %d', $operation, $id ),
                    'impact'         => (string) ( $args['impact'] ?? 'medium' ),
                    'evidence_ref'   => 'sha256:' . substr( $after['sha256'], 0, 16 ),
                    'correlation_id' => (string) ( $args['_prstudio_correlation_id'] ?? '' ),
                    'detail'         => array( 'revision_id' => $revision_id, 'permalink' => $after['permalink'] ),
                )
            );
        }

        return array(
            'transaction_version' => self::VERSION,
            'id' => $id,
            'changed' => true,
            'operation' => $operation,
            'actual_occurrences' => $actual_occurrences,
            'revision_id' => $revision_id,
            'before_sha256' => $before['sha256'],
            'after_sha256' => $after['sha256'],
            'db_verified' => true,
            'frontend_verification' => $public,
            'frontend_verified' => $frontend_verified,
            'fully_verified' => true === $frontend_verified,
            'verification_scope' => ! empty( $args['public_verify'] ) ? 'database_plus_public' : 'database_only',
            'database_only_verified' => empty( $args['public_verify'] ),
            'retry_mutation' => false,
            'permalink' => $after['permalink'],
            'modified_gmt' => $after['modified_gmt'],
        );
    }
}
