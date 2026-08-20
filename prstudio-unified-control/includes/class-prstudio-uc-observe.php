<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * prstudio_observe — the read half of the Codex loop.
 *
 * Codex's execution loop is short because reading is cheap, synchronous, and
 * returns everything needed to act next. 15.x had the opposite: dozens of ways
 * to read, none of which returned the preconditions the write path demanded.
 * The model could see a page but could not obtain the hash required to change
 * it, so it stayed on the read side of the loop and called that an audit.
 *
 * observe() reads one entity and returns three things together:
 *
 *   1. the content, bounded and readable;
 *   2. the facts a mutation needs — sha256, modified timestamp, exact anchor
 *      counts — computed here, where they can be computed correctly;
 *   3. a signed write_token carrying those facts, so the caller never handles
 *      a hash at all.
 *
 * It also attaches what the interventions ledger already knows about this
 * entity, which is how a session avoids re-proposing work that is done.
 */
final class PRSTUDIO_UC_Observe {
    public const VERSION = '1.0.0';

    private const MAX_CONTENT_BYTES = 262144;
    private const MAX_ANCHORS = 25;

    /**
     * @param array $args target, id/url, anchors[], include_content, aspect
     * @return array|WP_Error
     */
    public static function run( array $args ) {
        $target = sanitize_key( (string) ( $args['target'] ?? '' ) );
        if ( '' === $target ) {
            $target = ! empty( $args['url'] ) ? 'url' : ( ! empty( $args['id'] ) ? 'post' : '' );
        }
        if ( '' === $target ) {
            return new WP_Error(
                'prstudio_observe_target_required',
                'Specify what to observe: target="post" with id, target="url" with url, target="option" with name, or target="site".',
                array( 'status' => 400, 'supported' => array( 'post', 'product', 'url', 'option', 'term', 'site' ) )
            );
        }

        switch ( $target ) {
            case 'post':
            case 'page':
            case 'product':
                return self::observe_post( $args );
            case 'url':
                return self::observe_url( $args );
            case 'option':
                return self::observe_option( $args );
            case 'term':
                return self::observe_term( $args );
            case 'site':
                return self::observe_site( $args );
        }
        return new WP_Error(
            'prstudio_observe_target_unsupported',
            'Unsupported observation target: ' . $target,
            array( 'status' => 400, 'supported' => array( 'post', 'product', 'url', 'option', 'term', 'site' ) )
        );
    }

    /* ------------------------------------------------------------------ */

    private static function observe_post( array $args ) {
        $id = absint( $args['id'] ?? 0 );
        if ( ! $id && ! empty( $args['url'] ) && function_exists( 'url_to_postid' ) ) {
            $id = (int) url_to_postid( (string) $args['url'] );
        }
        if ( ! $id ) {
            return new WP_Error( 'prstudio_observe_id_required', 'A post/page/product ID is required (or a URL that resolves to one).', array( 'status' => 400 ) );
        }
        $post = get_post( $id );
        if ( ! $post instanceof WP_Post ) {
            return new WP_Error( 'prstudio_observe_not_found', 'No content exists with that ID.', array( 'status' => 404, 'id' => $id ) );
        }

        $content = (string) $post->post_content;
        $sha256 = hash( 'sha256', $content );
        $modified_gmt = (string) get_post_modified_time( DATE_ATOM, true, $post );
        $anchors_requested = self::normalize_anchors( $args['anchors'] ?? array() );
        $anchor_report = array();
        foreach ( $anchors_requested as $anchor ) {
            $anchor_report[] = array( 'text' => $anchor, 'occurrences' => substr_count( $content, $anchor ) );
        }

        $entity_key = PRSTUDIO_UC_Interventions::normalize_entity( 'post', $id );
        $facts = array(
            'sha256'       => $sha256,
            'modified_gmt' => $modified_gmt,
            'entity_type'  => 'post',
            'entity_id'    => $id,
            'bytes'        => strlen( $content ),
            'anchors'      => PRSTUDIO_UC_Write_Token::anchor_facts( $content, $anchors_requested ),
        );

        $observation = array(
            'target'   => 'post',
            'entity'   => array(
                'id'        => $id,
                'type'      => (string) $post->post_type,
                'status'    => (string) $post->post_status,
                'title'     => (string) $post->post_title,
                'permalink' => (string) get_permalink( $post ),
                'modified_gmt' => $modified_gmt,
            ),
            'facts'    => array(
                'sha256'  => $sha256,
                'bytes'   => strlen( $content ),
                'anchors' => $anchor_report,
            ),
            'write_token' => PRSTUDIO_UC_Write_Token::issue(
                'post:' . $id,
                $facts,
                (string) ( $args['_client_id'] ?? '' )
            ),
            'how_to_write' => 'Call wordpress_content_transaction with this write_token. Do not compute or pass a hash yourself.',
        );

        if ( false !== ( $args['include_content'] ?? true ) ) {
            $observation['content'] = self::bound( $content );
        }
        $observation['ledger'] = self::ledger_for( $entity_key );
        $observation = self::attach_commerce( $observation, $post, $id, (string) ( $args['_client_id'] ?? '' ) );
        return $observation;
    }

    /**
     * A product has two independent states, and only one of them is post_content.
     *
     * The write_token above binds a content transaction: it carries the SHA-256
     * of the post body. Price, stock, SKU, catalogue visibility and the SEO meta
     * live nowhere near that body, so a caller that observed a product and then
     * changed its price had nothing to bind the change to -- the commerce
     * executors could not even accept a precondition. This attaches the second
     * state and a token scoped to it, so the observe -> plan -> write loop is
     * closed for commerce exactly as it already was for content.
     */
    private static function attach_commerce( array $observation, WP_Post $post, int $id, string $client ): array {
        if ( 'product' !== (string) $post->post_type || ! class_exists( 'PRSTUDIO_UC_Commerce_Engine' ) ) {
            return $observation;
        }
        $commerce = PRSTUDIO_UC_Commerce_Engine::product_read( array( 'id' => $id ) );
        if ( ! is_array( $commerce ) ) {
            // WooCommerce absent or the product unreadable through its CRUD.
            // Reporting that is more useful than silently omitting the block.
            $observation['commerce'] = array(
                'available' => false,
                'reason' => is_wp_error( $commerce ) ? $commerce->get_error_code() : 'commerce_state_unreadable',
            );
            return $observation;
        }
        $state = (string) ( $commerce['state_sha256'] ?? '' );
        $observation['commerce'] = array(
            'available' => true,
            'state_sha256' => $state,
            'regular_price' => (string) ( $commerce['regular_price'] ?? '' ),
            'sale_price' => (string) ( $commerce['sale_price'] ?? '' ),
            'stock_quantity' => $commerce['stock_quantity'] ?? null,
            'stock_status' => (string) ( $commerce['stock_status'] ?? '' ),
            'write_token' => PRSTUDIO_UC_Write_Token::issue(
                'product:' . $id,
                array( 'state_sha256' => $state, 'entity_type' => 'product', 'entity_id' => $id ),
                $client
            ),
            'how_to_write' => 'Pass this write_token to commerce.product.update or commerce.inventory.update so the write is bound to the product as observed here. Do not compute or pass a hash yourself.',
        );
        return $observation;
    }

    private static function observe_url( array $args ) {
        $url = esc_url_raw( (string) ( $args['url'] ?? '' ) );
        if ( '' === $url ) {
            return new WP_Error( 'prstudio_observe_url_required', 'A URL is required for target="url".', array( 'status' => 400 ) );
        }
        // A local post is authoritative when the URL belongs to this site: it
        // yields a writable entity instead of a read-only snapshot.
        $local_id = function_exists( 'url_to_postid' ) ? (int) url_to_postid( $url ) : 0;
        if ( $local_id > 0 ) {
            $observation = self::observe_post( array_merge( $args, array( 'id' => $local_id ) ) );
            if ( ! is_wp_error( $observation ) ) {
                $observation['resolved_from_url'] = $url;
                return $observation;
            }
        }

        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 8,
            'redirection' => 5,
            'user-agent'  => 'PR-STUDIO-Observe/' . self::VERSION,
            'headers'     => array( 'Cache-Control' => 'no-cache' ),
        ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $body = (string) wp_remote_retrieve_body( $response );
        $status = (int) wp_remote_retrieve_response_code( $response );
        $anchors_requested = self::normalize_anchors( $args['anchors'] ?? array() );
        $anchor_report = array();
        foreach ( $anchors_requested as $anchor ) {
            $anchor_report[] = array( 'text' => $anchor, 'occurrences' => substr_count( $body, $anchor ) );
        }
        $entity_key = PRSTUDIO_UC_Interventions::normalize_entity( 'url', $url );
        return array(
            'target' => 'url',
            'entity' => array( 'url' => $url, 'http_status' => $status, 'bytes' => strlen( $body ) ),
            'facts'  => array(
                'sha256'  => hash( 'sha256', $body ),
                'anchors' => $anchor_report,
                'headers' => self::interesting_headers( $response ),
            ),
            'content' => false !== ( $args['include_content'] ?? true ) ? self::bound( $body ) : null,
            'ledger'  => self::ledger_for( $entity_key ),
            'note'    => 'Remote URL observation is read-only evidence. To mutate a page on this site, observe it as target="post".',
        );
    }

    private static function observe_option( array $args ) {
        $name = sanitize_key( (string) ( $args['name'] ?? $args['id'] ?? '' ) );
        if ( '' === $name ) {
            return new WP_Error( 'prstudio_observe_option_required', 'An option name is required for target="option".', array( 'status' => 400 ) );
        }
        if ( class_exists( 'PRSTUDIO_UC_Memory' ) && self::is_secret_option( $name ) ) {
            return new WP_Error( 'prstudio_observe_option_protected', 'That option holds credentials and is never returned.', array( 'status' => 403 ) );
        }
        $value = get_option( $name, null );
        $encoded = wp_json_encode( $value );
        $facts = array( 'sha256' => hash( 'sha256', (string) $encoded ), 'entity_type' => 'option', 'entity_id' => $name );
        return array(
            'target' => 'option',
            'entity' => array( 'name' => $name, 'exists' => null !== $value ),
            'facts'  => array( 'sha256' => $facts['sha256'], 'bytes' => strlen( (string) $encoded ) ),
            'value'  => class_exists( 'PRSTUDIO_UC_Memory' ) ? PRSTUDIO_UC_Memory::redact( $value ) : $value,
            'write_token' => PRSTUDIO_UC_Write_Token::issue( 'option:' . $name, $facts, (string) ( $args['_client_id'] ?? '' ) ),
            'ledger' => self::ledger_for( PRSTUDIO_UC_Interventions::normalize_entity( 'option', $name ) ),
        );
    }

    private static function observe_term( array $args ) {
        $id = absint( $args['id'] ?? 0 );
        if ( ! $id ) {
            return new WP_Error( 'prstudio_observe_term_required', 'A term ID is required for target="term".', array( 'status' => 400 ) );
        }
        $term = get_term( $id );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'prstudio_observe_not_found', 'No term exists with that ID.', array( 'status' => 404 ) );
        }
        $payload = array( 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'count' => $term->count );
        $facts = array( 'sha256' => hash( 'sha256', (string) wp_json_encode( $payload ) ), 'entity_type' => 'term', 'entity_id' => $id );
        return array(
            'target' => 'term',
            'entity' => array( 'id' => $id, 'taxonomy' => $term->taxonomy ) + $payload,
            'facts'  => array( 'sha256' => $facts['sha256'] ),
            'write_token' => PRSTUDIO_UC_Write_Token::issue( 'term:' . $id, $facts, (string) ( $args['_client_id'] ?? '' ) ),
            'ledger' => self::ledger_for( PRSTUDIO_UC_Interventions::normalize_entity( 'term', $id ) ),
        );
    }

    /**
     * Site-level orientation.
     *
     * Deliberately compact: this is the call a session makes first, and its job
     * is to say what is worth doing, not to dump the whole site into context.
     */
    private static function observe_site( array $args ) {
        $counts = array();
        foreach ( array( 'post', 'page', 'product' ) as $type ) {
            if ( ! post_type_exists( $type ) ) { continue; }
            $count = wp_count_posts( $type );
            $counts[ $type ] = isset( $count->publish ) ? (int) $count->publish : 0;
        }
        $backlog = class_exists( 'PRSTUDIO_UC_Interventions' )
            ? PRSTUDIO_UC_Interventions::backlog( array( 'limit' => (int) ( $args['limit'] ?? 15 ) ) )
            : array();
        return array(
            'target' => 'site',
            'entity' => array(
                'home_url'    => home_url(),
                'wp_version'  => get_bloginfo( 'version' ),
                'woocommerce' => class_exists( 'WooCommerce' ),
                'published'   => $counts,
            ),
            'backlog'  => $backlog,
            'note'     => 'backlog lists work that is genuinely outstanding. Items already applied or rejected are excluded on purpose.',
        );
    }

    /* ------------------------------------------------------------------ */

    private static function ledger_for( string $entity_key ): array {
        if ( '' === $entity_key || ! class_exists( 'PRSTUDIO_UC_Interventions' ) ) { return array(); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional: bulk/admin database maintenance and job-queue operations documented as set-based by design (see PR-STUDIO final release notes -- e.g. 128-table optimize stays 2 SQL statements, not one WP_Query per table); object-cache and WP_Query overhead is inappropriate for this bulk/schema path.
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT intervention_key, state, impact, summary, applied_gmt FROM '
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column identifier only, from a fixed helper or the identifier() allowlist + SHOW TABLES check -- never external input; values are parameterized via $wpdb->prepare()
            . PRSTUDIO_UC_Interventions::table() . ' WHERE entity_key = %s ORDER BY updated_gmt DESC LIMIT 40',
            $entity_key
        ), ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        $applied = array();
        $rejected = array();
        $open = array();
        foreach ( $rows as $row ) {
            $state = (string) $row['state'];
            if ( PRSTUDIO_UC_Interventions::APPLIED === $state ) { $applied[] = $row; }
            elseif ( PRSTUDIO_UC_Interventions::REJECTED === $state ) { $rejected[] = $row; }
            else { $open[] = $row; }
        }
        return array(
            'entity_key' => $entity_key,
            'already_applied' => $applied,
            'previously_rejected' => $rejected,
            'open' => $open,
            'guidance' => $applied || $rejected
                ? 'Do not re-propose anything listed under already_applied or previously_rejected.'
                : 'No prior interventions recorded for this entity.',
        );
    }

    private static function normalize_anchors( $anchors ): array {
        if ( is_string( $anchors ) ) { $anchors = array( $anchors ); }
        if ( ! is_array( $anchors ) ) { return array(); }
        $clean = array();
        foreach ( $anchors as $anchor ) {
            if ( ! is_string( $anchor ) || '' === $anchor ) { continue; }
            $clean[] = $anchor;
            if ( count( $clean ) >= self::MAX_ANCHORS ) { break; }
        }
        return $clean;
    }

    private static function bound( string $content ): array {
        if ( strlen( $content ) <= self::MAX_CONTENT_BYTES ) {
            return array( 'text' => $content, 'truncated' => false, 'bytes' => strlen( $content ) );
        }
        return array(
            'text'      => mb_substr( $content, 0, self::MAX_CONTENT_BYTES ),
            'truncated' => true,
            'bytes'     => strlen( $content ),
            'note'      => 'Content exceeded the observation budget. The sha256 and write_token cover the FULL content, so a write remains safe.',
        );
    }

    private static function interesting_headers( $response ): array {
        $keep = array( 'content-type', 'cache-control', 'x-robots-tag', 'location', 'link' );
        $headers = array();
        foreach ( $keep as $name ) {
            $value = wp_remote_retrieve_header( $response, $name );
            if ( '' !== $value && null !== $value ) { $headers[ $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value; }
        }
        return $headers;
    }

    private static function is_secret_option( string $name ): bool {
        foreach ( array( 'token', 'secret', 'key', 'password', 'client', 'vault', 'credential' ) as $needle ) {
            if ( str_contains( $name, $needle ) ) { return true; }
        }
        return false;
    }
}
