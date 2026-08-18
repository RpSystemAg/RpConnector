<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Deterministic WordPress create/publish path for autonomous editorial missions. */
final class PRSTUDIO_UC_Publish_Transaction {
    public const VERSION = '2.0.0';
    private const IDEMP_META = '_prstudio_publish_idempotency';
    private const RECEIPT_META = '_prstudio_publish_receipt';

    // PR STUDIO ONE-GUARD INVARIANT:
    // Anti-Crash is the only mutation guard. Verification/risk/telemetry must
    // never block an executable action or roll back a persisted mutation.

    private static function error( string $code, string $message, int $status = 400, array $details = array() ) {
        return new WP_Error( $code, $message, array( 'status'=>$status, 'details'=>$details, 'category'=>'technical_error' ) );
    }

    private static function public_verify( string $url, string $needle = '' ): array {
        $resp = wp_remote_get( $url, array( 'timeout'=>6, 'redirection'=>3, 'headers'=>array( 'Cache-Control'=>'no-cache', 'User-Agent'=>'PRSTUDIO-PublishVerifier/2.0' ) ) );
        if ( is_wp_error( $resp ) ) { return array( 'verified'=>false, 'error'=>$resp->get_error_code() ); }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = (string) wp_remote_retrieve_body( $resp );
        $contains = '' === $needle ? true : false !== strpos( $body, $needle );
        $canonical = '';
        if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)/i', $body, $m ) || preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $body, $m ) ) { $canonical = esc_url_raw( $m[1] ); }
        $robots = '';
        if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)/i', $body, $m ) ) { $robots = strtolower( $m[1] ); }
        return array( 'verified'=>200 === $code && $contains && ! str_contains( $robots, 'noindex' ), 'http_status'=>$code, 'contains'=>$contains, 'canonical'=>$canonical, 'robots'=>$robots, 'body_sha256'=>hash( 'sha256', $body ) );
    }

    private static function sitemap_verify( int $post_id, string $url ): array {
        $post = get_post( $post_id );
        if ( ! $post ) { return array( 'verified'=>false, 'reason'=>'post_missing' ); }
        $base = home_url( '/wp-sitemap-posts-' . $post->post_type . '-' );
        $started = microtime( true );
        for ( $i = 1; $i <= 5; $i++ ) {
            $remaining = 5.0 - ( microtime( true ) - $started );
            if ( $remaining <= 0.0 ) { return array( 'verified'=>false, 'reason'=>'sitemap_observation_timeout', 'checked'=>$i - 1 ); }
            $sitemap = $base . $i . '.xml';
            $resp = wp_remote_get( $sitemap, array( 'timeout'=>max( 1, min( 3, (int) ceil( $remaining ) ) ), 'redirection'=>2 ) );
            if ( is_wp_error( $resp ) ) { continue; }
            if ( 404 === (int) wp_remote_retrieve_response_code( $resp ) ) { break; }
            $body = (string) wp_remote_retrieve_body( $resp );
            if ( false !== strpos( $body, esc_url( $url ) ) || false !== strpos( $body, $url ) ) { return array( 'verified'=>true, 'sitemap'=>$sitemap, 'checked'=>$i ); }
        }
        return array( 'verified'=>false, 'reason'=>'url_not_observed_in_first_5_post_sitemaps' );
    }

    private static function existing_by_key( string $key ): ?WP_Post {
        $q = get_posts( array( 'post_type'=>'any', 'post_status'=>array( 'publish','draft','future','pending','private' ), 'meta_key'=>self::IDEMP_META, 'meta_value'=>$key, 'posts_per_page'=>1, 'orderby'=>'ID', 'order'=>'DESC' ) );
        return $q && $q[0] instanceof WP_Post ? $q[0] : null;
    }

    private static function collect_wp_error( array &$errors, string $stage, $value ): void {
        if ( is_wp_error( $value ) ) {
            $errors[] = array( 'stage'=>$stage, 'code'=>$value->get_error_code(), 'message'=>$value->get_error_message() );
        }
    }

    public static function create_publish( array $args ) {
        $lane_token = (string) ( $args['lane_token'] ?? '' );
        $client = (string) ( $args['_client_id'] ?? '' );
        $slug = sanitize_title( (string) ( $args['slug'] ?? '' ) );
        $resource = 'publish:' . ( $slug ?: hash( 'sha256', (string) ( $args['title'] ?? '' ) ) );
        if ( class_exists( 'PRSTUDIO_UC_Execution_Lanes' ) && '' !== $lane_token ) {
            $lane = PRSTUDIO_UC_Execution_Lanes::guard( $lane_token, $client, $resource, true );
            if ( is_wp_error( $lane ) ) { return $lane; } // technical concurrency/credential correctness.
        }

        $title = wp_strip_all_tags( (string) ( $args['title'] ?? '' ) );
        $content = (string) ( $args['content'] ?? '' );
        $type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
        if ( '' === $title || '' === trim( $content ) ) { return self::error( 'publish_content_required', 'title and content are required.' ); }
        if ( strlen( $content ) > 8 * 1024 * 1024 ) { return self::error( 'publish_content_too_large', 'Content exceeds the executable request size.', 413 ); }
        if ( ! post_type_exists( $type ) ) { return self::error( 'publish_post_type_invalid', 'Requested post type does not exist.', 400, array( 'post_type'=>$type ) ); }

        $status = sanitize_key( (string) ( $args['status'] ?? 'publish' ) );
        if ( ! in_array( $status, array( 'publish','draft','pending','private','future' ), true ) ) { return self::error( 'publish_status_invalid', 'Unsupported WordPress post status.', 400, array( 'status'=>$status ) ); }

        $canonical = esc_url_raw( (string) ( $args['canonical'] ?? '' ) );
        if ( '' !== (string) ( $args['canonical'] ?? '' ) && '' === $canonical ) { return self::error( 'canonical_url_invalid', 'Canonical must be a valid URL.' ); }
        if ( $canonical ) {
            $scheme = strtolower( (string) wp_parse_url( $canonical, PHP_URL_SCHEME ) );
            if ( ! in_array( $scheme, array( 'http','https' ), true ) ) { return self::error( 'canonical_scheme_invalid', 'Canonical URL must use HTTP or HTTPS.' ); }
        }

        if ( ! empty( $args['featured_image'] ) && ! wp_attachment_is_image( absint( $args['featured_image'] ) ) ) { return self::error( 'featured_image_invalid', 'featured_image must identify an image attachment.' ); }
        if ( ! empty( $args['schema_json'] ) ) {
            try { json_decode( (string) $args['schema_json'], true, 512, JSON_THROW_ON_ERROR ); }
            catch ( Throwable $e ) { return self::error( 'schema_json_invalid', 'schema_json is not valid JSON.', 400, array( 'error'=>$e->getMessage() ) ); }
        }

        $idempotency = trim( (string) ( $args['idempotency_key'] ?? '' ) );
        if ( '' === $idempotency ) { $idempotency = hash( 'sha256', $type . '|' . $slug . '|' . $title . '|' . hash( 'sha256', $content ) ); }
        $existing = self::existing_by_key( $idempotency );
        if ( $existing ) {
            $url = get_permalink( $existing );
            $verify = self::public_verify( $url, (string) ( $args['verify_contains'] ?? $title ) );
            return array( 'ok'=>true, 'transaction_version'=>self::VERSION, 'idempotent_reuse'=>true, 'executed'=>true, 'post_id'=>(int) $existing->ID, 'url'=>$url, 'status'=>$existing->post_status, 'verified'=>! empty( $verify['verified'] ), 'degraded'=>empty( $verify['verified'] ), 'blocking'=>false, 'public_verification'=>$verify );
        }
        if ( '' !== $slug ) {
            $collision = get_page_by_path( $slug, OBJECT, $type );
            if ( $collision ) { return self::error( 'publish_slug_collision', 'Slug already exists for this post type.', 409, array( 'existing_post_id'=>(int) $collision->ID ) ); }
        }

        $claim_warnings = array();
        if ( class_exists( 'PRSTUDIO_UC_Editorial_Autonomy' ) ) {
            foreach ( (array) ( $args['required_claims'] ?? array() ) as $claim ) {
                $check = PRSTUDIO_UC_Editorial_Autonomy::claim_ledger( array( 'operation'=>'check', 'claim'=>(string) $claim ) );
                if ( empty( $check['verified'] ) ) { $claim_warnings[] = array( 'claim'=>(string) $claim, 'warning'=>'claim_unverified', 'blocking'=>false ); }
            }
        }

        $postarr = array(
            'post_type'=>$type,
            'post_title'=>$title,
            'post_name'=>$slug,
            'post_content'=>wp_kses_post( $content ),
            'post_excerpt'=>sanitize_textarea_field( (string) ( $args['excerpt'] ?? '' ) ),
            'post_status'=>$status,
            'post_author'=>absint( $args['author'] ?? get_current_user_id() ),
        );
        $post_date = (string) ( $args['publish_date'] ?? '' );
        if ( $post_date ) { $postarr['post_date'] = $post_date; }

        if ( class_exists( 'PRSTUDIO_UC_Pre_Mutation_Safety' ) ) {
            $anti_crash = PRSTUDIO_UC_Pre_Mutation_Safety::before_commit( 'content', 'wordpress_publish_transaction', $args );
            if ( is_wp_error( $anti_crash ) ) { return $anti_crash; }
        }

        $post_id = wp_insert_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $post_id ) ) { return $post_id; }
        $post_id = (int) $post_id;
        update_post_meta( $post_id, self::IDEMP_META, $idempotency );

        $technical_errors = array();
        if ( ! empty( $args['categories'] ) ) { self::collect_wp_error( $technical_errors, 'categories', wp_set_post_terms( $post_id, array_map( 'intval', (array) $args['categories'] ), 'category', false ) ); }
        if ( ! empty( $args['tags'] ) ) { self::collect_wp_error( $technical_errors, 'tags', wp_set_post_terms( $post_id, array_map( 'sanitize_text_field', (array) $args['tags'] ), 'post_tag', false ) ); }
        foreach ( (array) ( $args['taxonomies'] ?? array() ) as $taxonomy=>$terms ) {
            $taxonomy = sanitize_key( $taxonomy );
            if ( ! taxonomy_exists( $taxonomy ) ) { $technical_errors[] = array( 'stage'=>'taxonomy', 'code'=>'taxonomy_missing', 'message'=>$taxonomy ); continue; }
            self::collect_wp_error( $technical_errors, 'taxonomy:' . $taxonomy, wp_set_post_terms( $post_id, (array) $terms, $taxonomy, false ) );
        }
        if ( ! empty( $args['featured_image'] ) && ! set_post_thumbnail( $post_id, absint( $args['featured_image'] ) ) ) { $technical_errors[] = array( 'stage'=>'featured_image', 'code'=>'thumbnail_write_failed', 'message'=>'set_post_thumbnail returned false' ); }
        if ( isset( $args['seo_title'] ) ) { update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( (string) $args['seo_title'] ) ); }
        if ( isset( $args['seo_description'] ) ) { update_post_meta( $post_id, 'rank_math_description', sanitize_textarea_field( (string) $args['seo_description'] ) ); }
        if ( $canonical ) { update_post_meta( $post_id, 'rank_math_canonical_url', $canonical ); }
        if ( ! empty( $args['schema_json'] ) ) { update_post_meta( $post_id, '_prstudio_editorial_schema', (string) $args['schema_json'] ); }

        clean_post_cache( $post_id );
        $persisted = get_post( $post_id );
        $expected_hash = hash( 'sha256', wp_kses_post( $content ) );
        $observed_hash = $persisted instanceof WP_Post ? hash( 'sha256', (string) $persisted->post_content ) : '';
        $db_verified = $persisted instanceof WP_Post && hash_equals( $expected_hash, $observed_hash );
        $effective_status = $persisted instanceof WP_Post ? (string) $persisted->post_status : $status;
        $url = $persisted instanceof WP_Post ? (string) get_permalink( $post_id ) : '';
        $public = ( 'publish' === $effective_status && $url ) ? self::public_verify( $url, (string) ( $args['verify_contains'] ?? $title ) ) : array( 'verified'=>true, 'skipped'=>'not_public_status' );
        $sitemap = ( 'publish' === $effective_status && $url ) ? self::sitemap_verify( $post_id, $url ) : array( 'verified'=>true, 'skipped'=>'not_public_status' );
        $verified = $db_verified && ! empty( $public['verified'] );
        $degraded = ! $verified || ! empty( $claim_warnings ) || ! empty( $technical_errors );

        $receipt = array(
            'post_id'=>$post_id,
            'url'=>$url,
            'status'=>$effective_status,
            'expected_content_hash'=>$expected_hash,
            'observed_content_hash'=>$observed_hash,
            'seo_hash'=>hash( 'sha256', wp_json_encode( array( 'title'=>$args['seo_title'] ?? '', 'description'=>$args['seo_description'] ?? '', 'canonical'=>$canonical ) ) ),
            'schema_hash'=>hash( 'sha256', (string) ( $args['schema_json'] ?? '' ) ),
            'db_verified'=>$db_verified,
            'render_verified'=>! empty( $public['verified'] ),
            'sitemap_verified'=>! empty( $sitemap['verified'] ),
            'public_verification'=>$public,
            'sitemap_verification'=>$sitemap,
            'idempotency_key'=>$idempotency,
            'observed_gmt'=>gmdate( 'c' ),
        );
        update_post_meta( $post_id, self::RECEIPT_META, wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

        if ( class_exists( 'PRSTUDIO_UC_Editorial_Autonomy' ) ) {
            PRSTUDIO_UC_Editorial_Autonomy::post_publish_watcher( array( 'operation'=>'create', 'url'=>$url, 'keyword'=>(string) ( $args['primary_keyword'] ?? '' ), 'campaign_id'=>(string) ( $args['campaign_id'] ?? '' ), 'lane_token'=>$lane_token, '_client_id'=>$client ) );
        }

        return array(
            'ok'=>empty( $technical_errors ),
            'transaction_version'=>self::VERSION,
            'state'=>! empty( $technical_errors ) ? 'TECHNICAL_ERROR' : ( $verified ? 'SUCCESS' : 'PUBLIC_PERSISTED_UNVERIFIED' ),
            'executed'=>true,
            'verified'=>$verified,
            'degraded'=>$degraded,
            'blocking'=>false,
            'post_id'=>$post_id,
            'url'=>$url,
            'receipt'=>$receipt,
            'technical_errors'=>$technical_errors,
            'warnings'=>$claim_warnings,
        );
    }
}
