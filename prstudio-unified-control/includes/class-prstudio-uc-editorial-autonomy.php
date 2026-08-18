<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Persistent autonomous SEO editorial control-plane.
 *
 * This class intentionally stores decisions and evidence, not generated prose.
 * Generation remains upstream; execution is governed by deterministic campaign,
 * dominant-URL, claim, lease, schema and post-publication state.
 */
final class PRSTUDIO_UC_Editorial_Autonomy {
    public const VERSION = '1.1.0';
    private const OPTION = 'prstudio_uc_editorial_autonomy_v2';
    private const MAX_CAMPAIGNS = 100;
    private const MAX_KEYWORDS = 5000;
    private const MAX_SERP = 2000;
    private const MAX_BRIEFS = 2000;
    private const MAX_CLAIMS = 10000;
    private const MAX_WATCHES = 2000;
    private const MAX_ENTITIES = 10000;
    private const MAX_OUTREACH = 5000;
    private const MAX_CANNIBAL = 3000;
    private const MUTEX_OPTION = 'prstudio_uc_editorial_autonomy_mutex_v1';
    private const MUTEX_TTL_SECONDS = 15;
    private const MUTEX_WAIT_MS = 2500;

    private static function state(): array {
        $state = get_option( self::OPTION, array() );
        if ( ! is_array( $state ) ) { $state = array(); }
        return wp_parse_args( $state, array(
            'campaigns' => array(),
            'keywords' => array(),
            'serp' => array(),
            'briefs' => array(),
            'claims' => array(),
            'watches' => array(),
            'directory' => array(),
            'outreach' => array(),
            'cannibalization' => array(),
        ) );
    }

    private static function save( array $state ): void { update_option( self::OPTION, $state, false ); }

    private static function with_mutex( callable $callback ) {
        if ( ! function_exists( 'add_option' ) || ! function_exists( 'delete_option' ) ) { return $callback(); }
        $owner = bin2hex( random_bytes( 16 ) );
        $deadline = microtime( true ) + ( self::MUTEX_WAIT_MS / 1000 );
        $acquired = false;
        do {
            $record = array( 'owner'=>$owner, 'expires_at'=>microtime( true ) + self::MUTEX_TTL_SECONDS );
            if ( add_option( self::MUTEX_OPTION, $record, '', false ) ) { $acquired = true; break; }
            $existing = get_option( self::MUTEX_OPTION, null );
            if ( is_array( $existing ) && (float) ( $existing['expires_at'] ?? 0 ) <= microtime( true ) ) { delete_option( self::MUTEX_OPTION ); }
            usleep( random_int( 15000, 45000 ) );
        } while ( microtime( true ) < $deadline );
        if ( ! $acquired ) {
            return new WP_Error( 'editorial_state_mutex_timeout', 'Editorial state is busy. Retry without creating a second mission.', array( 'status'=>503, 'retryable'=>true ) );
        }
        try { return $callback(); }
        finally {
            $current = get_option( self::MUTEX_OPTION, null );
            if ( is_array( $current ) && hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) ) { delete_option( self::MUTEX_OPTION ); }
        }
    }

    private static function mutate( callable $callback ) {
        return self::with_mutex( static function() use ( $callback ) {
            $state = self::state();
            $result = $callback( $state );
            if ( ! is_wp_error( $result ) ) { self::save( $state ); }
            return $result;
        } );
    }

    private static function key( string $value, int $max = 180 ): string {
        return substr( sanitize_title( $value ), 0, $max );
    }

    private static function text( string $value, int $max = 500 ): string {
        return substr( sanitize_text_field( $value ), 0, $max );
    }

    private static function lower( string $value ): string {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    private static function text_list( array $values, int $max_items = 300, int $max_len = 500 ): array {
        $out = array();
        foreach ( array_slice( $values, 0, $max_items ) as $value ) {
            $clean = self::text( (string) $value, $max_len );
            if ( '' !== $clean && ! in_array( $clean, $out, true ) ) { $out[] = $clean; }
        }
        return $out;
    }

    private static function normalize_url( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) { return ''; }
        if ( str_starts_with( $url, '/' ) ) { return $url; }
        return esc_url_raw( $url );
    }

    /** Server scheduler may run without MCP lane. Authenticated MCP writes may not. */
    private static function lane( array $args, string $resource, bool $exclusive = true ) {
        if ( ! class_exists( 'PRSTUDIO_UC_Execution_Lanes' ) ) { return true; }
        $token = trim( (string) ( $args['lane_token'] ?? '' ) );
        $client = trim( (string) ( $args['_client_id'] ?? '' ) );
        if ( '' === $token && '' === $client ) { return true; }
        if ( '' === $token ) {
            return new WP_Error( 'execution_lane_required', 'Authenticated editorial mutation requires a lane token.', array( 'status'=>409, 'next_tool'=>'prstudio_context_open' ) );
        }
        return PRSTUDIO_UC_Execution_Lanes::guard( $token, $client, $resource, $exclusive );
    }

    private static function campaign_defaults(): array {
        return array(
            'vino' => array( 'primary_keyword'=>'vino', 'primary_url'=>'/vino/', 'target_position'=>10 ),
            'ricette' => array( 'primary_keyword'=>'ricette', 'primary_url'=>'/ricette/', 'target_position'=>10 ),
            'ristoranti' => array( 'primary_keyword'=>'ristoranti', 'primary_url'=>'/ristoranti/', 'target_position'=>10 ),
            'sicilia' => array( 'primary_keyword'=>'sicilia', 'primary_url'=>'/sicilia/', 'target_position'=>10 ),
            'bar' => array( 'primary_keyword'=>'bar', 'primary_url'=>'/bar/', 'target_position'=>10 ),
        );
    }

    public static function campaign_manager( array $args ) {
        $op = sanitize_key( (string) ( $args['operation'] ?? 'list' ) );
        if ( 'bootstrap' === $op ) {
            $guard = self::lane( $args, 'seo:campaign:bootstrap', true );
            if ( is_wp_error( $guard ) ) { return $guard; }
            return self::mutate( static function( array &$state ) {
                foreach ( self::campaign_defaults() as $id => $row ) {
                    if ( isset( $state['campaigns'][ $id ] ) ) { continue; }
                    $state['campaigns'][ $id ] = array_merge( $row, array(
                        'id'=>$id,
                        'status'=>'active',
                        'satellites'=>array(),
                        'metrics'=>array(),
                        'next_best_action'=>array(),
                        'do_not_do'=>array( 'create_second_primary', 'change_primary_url_without_migration' ),
                        'created_gmt'=>gmdate( 'c' ),
                        'updated_gmt'=>gmdate( 'c' ),
                    ) );
                }
                return array( 'ok'=>true, 'campaigns'=>array_values( $state['campaigns'] ) );
            } );
        }
        if ( 'list' === $op ) { return array( 'ok'=>true, 'campaigns'=>array_values( self::state()['campaigns'] ) ); }

        $id = self::key( (string) ( $args['campaign_id'] ?? $args['primary_keyword'] ?? '' ) );
        if ( '' === $id ) { return new WP_Error( 'campaign_id_required', 'campaign_id is required.', array( 'status'=>400 ) ); }
        if ( 'get' === $op ) {
            $row = self::state()['campaigns'][ $id ] ?? null;
            return $row ? array( 'ok'=>true, 'campaign'=>$row ) : new WP_Error( 'campaign_not_found', 'Campaign not found.', array( 'status'=>404 ) );
        }
        $guard = self::lane( $args, 'seo:campaign:' . $id, true );
        if ( is_wp_error( $guard ) ) { return $guard; }

        if ( 'upsert' === $op ) {
            return self::mutate( static function( array &$state ) use ( $args, $id ) {
                $old = $state['campaigns'][ $id ] ?? array();
                $next = $args['next_best_action'] ?? ( $old['next_best_action'] ?? array() );
                if ( ! is_array( $next ) ) { $next = array( 'action'=>self::text( (string) $next, 300 ) ); }
                $row = array_merge( $old, array(
                    'id'=>$id,
                    'primary_keyword'=>self::text( (string) ( $args['primary_keyword'] ?? $old['primary_keyword'] ?? $id ), 160 ),
                    'primary_url'=>self::normalize_url( (string) ( $args['primary_url'] ?? $old['primary_url'] ?? '' ) ),
                    'target_position'=>max( 1, min( 100, (int) ( $args['target_position'] ?? $old['target_position'] ?? 10 ) ) ),
                    'status'=>sanitize_key( (string) ( $args['status'] ?? $old['status'] ?? 'active' ) ),
                    'satellites'=>self::text_list( (array) ( $args['satellites'] ?? $old['satellites'] ?? array() ), 500, 300 ),
                    'metrics'=>is_array( $args['metrics'] ?? null ) ? $args['metrics'] : ( $old['metrics'] ?? array() ),
                    'next_best_action'=>$next,
                    'do_not_do'=>self::text_list( (array) ( $args['do_not_do'] ?? $old['do_not_do'] ?? array() ), 100, 300 ),
                    'updated_gmt'=>gmdate( 'c' ),
                    'created_gmt'=>$old['created_gmt'] ?? gmdate( 'c' ),
                ) );
                $state['campaigns'][ $id ] = $row;
                if ( count( $state['campaigns'] ) > self::MAX_CAMPAIGNS ) { $state['campaigns'] = array_slice( $state['campaigns'], -self::MAX_CAMPAIGNS, null, true ); }
                return array( 'ok'=>true, 'campaign'=>$row );
            } );
        }
        if ( in_array( $op, array( 'progress', 'update_metrics', 'set_next_action' ), true ) ) {
            return self::mutate( static function( array &$state ) use ( $args, $id, $op ) {
                if ( ! isset( $state['campaigns'][ $id ] ) ) { return new WP_Error( 'campaign_not_found', 'Campaign not found.', array( 'status'=>404 ) ); }
                if ( in_array( $op, array( 'progress', 'update_metrics' ), true ) && is_array( $args['metrics'] ?? null ) ) {
                    $state['campaigns'][ $id ]['metrics'] = array_merge( (array) ( $state['campaigns'][ $id ]['metrics'] ?? array() ), $args['metrics'] );
                }
                if ( in_array( $op, array( 'progress', 'set_next_action' ), true ) && isset( $args['next_best_action'] ) ) {
                    $state['campaigns'][ $id ]['next_best_action'] = is_array( $args['next_best_action'] ) ? $args['next_best_action'] : array( 'action'=>self::text( (string) $args['next_best_action'], 300 ) );
                }
                $state['campaigns'][ $id ]['updated_gmt'] = gmdate( 'c' );
                return array( 'ok'=>true, 'campaign'=>$state['campaigns'][ $id ] );
            } );
        }
        return new WP_Error( 'campaign_operation_invalid', 'Unsupported campaign operation.', array( 'status'=>400 ) );
    }

    public static function keyword_url_registry( array $args ) {
        $op = sanitize_key( (string) ( $args['operation'] ?? 'lookup' ) );
        $state = self::state();
        if ( 'list' === $op ) { return array( 'ok'=>true, 'items'=>array_values( $state['keywords'] ) ); }
        $keyword = self::lower( trim( (string) ( $args['keyword'] ?? '' ) ) );
        if ( '' === $keyword ) { return new WP_Error( 'keyword_required', 'keyword is required.', array( 'status'=>400 ) ); }
        $intent = self::key( (string) ( $args['intent'] ?? 'default' ) );
        $key = hash( 'sha256', $keyword . '|' . $intent );
        if ( 'lookup' === $op ) {
            $row = $state['keywords'][ $key ] ?? null;
            return array( 'ok'=>true, 'found'=>is_array( $row ), 'item'=>$row );
        }
        if ( 'bind' === $op ) { $op = 'assign'; }
        $guard = self::lane( $args, 'seo:keyword:' . $key, true );
        if ( is_wp_error( $guard ) ) { return $guard; }
        if ( in_array( $op, array( 'assign', 'migrate' ), true ) ) {
            return self::mutate( static function( array &$state ) use ( $args, $keyword, $intent, $key, $op ) {
                $url = self::normalize_url( (string) ( $args['url'] ?? $args['target_url'] ?? '' ) );
                if ( '' === $url ) { return new WP_Error( 'keyword_url_required', 'Dominant URL is required.', array( 'status'=>400 ) ); }
                $existing = $state['keywords'][ $key ] ?? null;
                $force = ! empty( $args['force'] ) || 'migrate' === $op;
                if ( $existing && $existing['url'] !== $url && ! empty( $existing['locked'] ) && ! $force ) {
                    return new WP_Error( 'keyword_url_locked', 'Keyword already has a locked dominant URL. Use an explicit migration.', array( 'status'=>409, 'existing'=>$existing ) );
                }
                $row = array(
                    'keyword'=>$keyword,
                    'intent'=>$intent,
                    'url'=>$url,
                    'campaign_id'=>self::key( (string) ( $args['campaign_id'] ?? $existing['campaign_id'] ?? '' ) ),
                    'locked'=>array_key_exists( 'locked', $args ) ? (bool) $args['locked'] : true,
                    'migration_from'=>$existing && $existing['url'] !== $url ? $existing['url'] : '',
                    'assigned_gmt'=>$existing['assigned_gmt'] ?? gmdate( 'c' ),
                    'updated_gmt'=>gmdate( 'c' ),
                );
                $state['keywords'][ $key ] = $row;
                if ( count( $state['keywords'] ) > self::MAX_KEYWORDS ) { $state['keywords'] = array_slice( $state['keywords'], -self::MAX_KEYWORDS, null, true ); }
                return array( 'ok'=>true, 'item'=>$row, 'changed'=>! $existing || $existing['url'] !== $url, 'migration'=>'migrate' === $op );
            } );
        }
        return new WP_Error( 'keyword_operation_invalid', 'Unsupported keyword registry operation.', array( 'status'=>400 ) );
    }

    private static function classify_serp( array $rows ): array {
        $types = array( 'directory'=>0, 'ecommerce'=>0, 'editorial'=>0, 'local'=>0, 'recipe'=>0, 'forum'=>0, 'other'=>0 );
        $domains = array();
        foreach ( array_slice( $rows, 0, 20 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $url = strtolower( (string) ( $row['url'] ?? '' ) );
            $title = strtolower( (string) ( $row['title'] ?? '' ) );
            $host = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?? '' );
            if ( $host ) { $domains[ $host ] = ( $domains[ $host ] ?? 0 ) + 1; }
            $hay = $url . ' ' . $title;
            if ( preg_match( '/thefork|tripadvisor|michelin|directory|ristoranti\//', $hay ) ) { $types['directory']++; }
            elseif ( preg_match( '/ricett|recipe|giallozafferano|cookist/', $hay ) ) { $types['recipe']++; }
            elseif ( preg_match( '/shop|prodot|categoria|wine|vino|amazon|tannico|bernabei|callmewine/', $hay ) ) { $types['ecommerce']++; }
            elseif ( preg_match( '/maps|local|near|vicino|comune|provincia/', $hay ) ) { $types['local']++; }
            elseif ( preg_match( '/forum|reddit|quora/', $hay ) ) { $types['forum']++; }
            elseif ( $url ) { $types['editorial']++; }
            else { $types['other']++; }
        }
        arsort( $types );
        arsort( $domains );
        return array( 'primary_intent'=>(string) array_key_first( $types ), 'content_types'=>$types, 'top_domains'=>array_slice( array_keys( $domains ), 0, 20 ) );
    }

    public static function serp_intent_observer( array $args ) {
        $query = self::lower( trim( (string) ( $args['query'] ?? '' ) ) );
        if ( '' === $query ) { return new WP_Error( 'serp_query_required', 'query is required.', array( 'status'=>400 ) ); }
        $country = strtolower( self::text( (string) ( $args['country'] ?? 'IT' ), 10 ) );
        $language = strtolower( self::text( (string) ( $args['language'] ?? 'it' ), 10 ) );
        $key = hash( 'sha256', $query . '|' . $country . '|' . $language );
        $state = self::state();
        $existing = $state['serp'][ $key ] ?? null;
        $ttl = max( 3600, min( 2592000, (int) ( $args['ttl_seconds'] ?? 604800 ) ) );
        if ( empty( $args['force'] ) && $existing && strtotime( (string) $existing['expires_gmt'] ) > time() ) {
            return array( 'ok'=>true, 'cached'=>true, 'snapshot'=>$existing );
        }
        $rows = array_values( array_filter( (array) ( $args['results'] ?? array() ), 'is_array' ) );
        if ( ! $rows ) { return new WP_Error( 'serp_results_required', 'Provide observed SERP rows; the server never fabricates a live SERP.', array( 'status'=>400 ) ); }
        $classified = self::classify_serp( $rows );
        $hash_input = array();
        foreach ( array_slice( $rows, 0, 20 ) as $row ) { $hash_input[] = array( 'url'=>$row['url'] ?? '', 'title'=>$row['title'] ?? '', 'type'=>$row['type'] ?? '' ); }
        $intent_hash = hash( 'sha256', wp_json_encode( $hash_input ) );
        $snapshot = array_merge( $classified, array(
            'query'=>$query,
            'country'=>$country,
            'language'=>$language,
            'intent_hash'=>$intent_hash,
            'change_score'=>$existing && ! hash_equals( (string) ( $existing['intent_hash'] ?? '' ), $intent_hash ) ? 1.0 : 0.0,
            'observed_gmt'=>gmdate( 'c' ),
            'expires_gmt'=>gmdate( 'c', time() + $ttl ),
            'source'=>self::text( (string) ( $args['source'] ?? 'browser_serp_observation' ), 100 ),
            'row_count'=>count( $rows ),
        ) );
        self::mutate( static function( array &$state ) use ( $key, $snapshot ) {
            $state['serp'][ $key ] = $snapshot;
            if ( count( $state['serp'] ) > self::MAX_SERP ) { $state['serp'] = array_slice( $state['serp'], -self::MAX_SERP, null, true ); }
            return true;
        } );
        return array( 'ok'=>true, 'cached'=>false, 'snapshot'=>$snapshot );
    }

    public static function brief_compiler( array $args ) {
        $keyword = self::lower( trim( (string) ( $args['keyword'] ?? '' ) ) );
        if ( '' === $keyword ) { return new WP_Error( 'brief_keyword_required', 'keyword is required.', array( 'status'=>400 ) ); }
        $intent = self::key( (string) ( $args['intent'] ?? 'default' ) );
        $lookup = self::keyword_url_registry( array( 'operation'=>'lookup', 'keyword'=>$keyword, 'intent'=>$intent ) );
        $item = is_array( $lookup ) ? ( $lookup['item'] ?? null ) : null;
        $campaign_id = self::key( (string) ( $args['campaign_id'] ?? ( $item['campaign_id'] ?? '' ) ) );
        $state = self::state();
        $campaign = $campaign_id ? ( $state['campaigns'][ $campaign_id ] ?? null ) : null;
        $serp = null;
        foreach ( $state['serp'] as $row ) { if ( ( $row['query'] ?? '' ) === $keyword ) { $serp = $row; break; } }
        $brief = array(
            'brief_id'=>'brief_' . substr( hash( 'sha256', $keyword . '|' . microtime( true ) ), 0, 20 ),
            'primary_keyword'=>$keyword,
            'secondary_queries'=>self::text_list( (array) ( $args['secondary_queries'] ?? array() ), 200, 300 ),
            'intent'=>$serp['primary_intent'] ?? self::text( (string) ( $args['search_intent'] ?? '' ), 100 ),
            'intent_hash'=>$serp['intent_hash'] ?? '',
            'target_url'=>$item['url'] ?? self::normalize_url( (string) ( $args['target_url'] ?? '' ) ),
            'campaign'=>$campaign,
            'required_entities'=>self::text_list( (array) ( $args['required_entities'] ?? array() ), 200, 300 ),
            'required_sections'=>self::text_list( (array) ( $args['required_sections'] ?? array() ), 200, 500 ),
            'products_verified'=>array_values( array_slice( (array) ( $args['products_verified'] ?? array() ), 0, 200 ) ),
            'internal_links_in'=>array_values( array_slice( (array) ( $args['internal_links_in'] ?? array() ), 0, 200 ) ),
            'internal_links_out'=>array_values( array_slice( (array) ( $args['internal_links_out'] ?? array() ), 0, 200 ) ),
            'competitor_topics_missing'=>self::text_list( (array) ( $args['competitor_topics_missing'] ?? array() ), 200, 500 ),
            'original_information_required'=>self::text_list( (array) ( $args['original_information_required'] ?? array() ), 100, 500 ),
            'schema_type'=>self::text( (string) ( $args['schema_type'] ?? 'Article' ), 80 ),
            'image_requirements'=>array_values( array_slice( (array) ( $args['image_requirements'] ?? array() ), 0, 100 ) ),
            'forbidden_overlap'=>self::text_list( (array) ( $args['forbidden_overlap'] ?? array() ), 200, 500 ),
            'compiled_gmt'=>gmdate( 'c' ),
        );
        $brief['brief_hash'] = hash( 'sha256', wp_json_encode( $brief ) );
        self::mutate( static function( array &$state ) use ( $brief ) {
            $state['briefs'][ $brief['brief_id'] ] = $brief;
            if ( count( $state['briefs'] ) > self::MAX_BRIEFS ) { $state['briefs'] = array_slice( $state['briefs'], -self::MAX_BRIEFS, null, true ); }
            return true;
        } );
        return array( 'ok'=>true, 'brief'=>$brief );
    }

    public static function claim_ledger( array $args ) {
        $op = sanitize_key( (string) ( $args['operation'] ?? 'list' ) );
        $state = self::state();
        if ( 'list' === $op ) {
            $rows = array_values( $state['claims'] );
            if ( ! empty( $args['status'] ) ) { $rows = array_values( array_filter( $rows, static fn( $row ) => ( $row['status'] ?? '' ) === $args['status'] ) ); }
            return array( 'ok'=>true, 'claims'=>array_slice( $rows, 0, 500 ) );
        }
        $claim = trim( (string) ( $args['claim'] ?? '' ) );
        if ( '' === $claim ) { return new WP_Error( 'claim_required', 'claim is required.', array( 'status'=>400 ) ); }
        $id = hash( 'sha256', self::lower( $claim ) );
        if ( 'check' === $op ) {
            $row = $state['claims'][ $id ] ?? null;
            if ( ! $row ) { return array( 'ok'=>true, 'found'=>false, 'verified'=>false ); }
            $expired = ! empty( $row['expires_gmt'] ) && strtotime( (string) $row['expires_gmt'] ) <= time();
            return array( 'ok'=>true, 'found'=>true, 'verified'=>! $expired && 'verified' === $row['status'], 'expired'=>$expired, 'claim'=>$row );
        }
        $guard = self::lane( $args, 'claim:' . $id, true );
        if ( is_wp_error( $guard ) ) { return $guard; }
        if ( 'invalidate' === $op ) {
            return self::mutate( static function( array &$state ) use ( $id ) {
                if ( isset( $state['claims'][ $id ] ) ) { $state['claims'][ $id ]['status'] = 'stale'; $state['claims'][ $id ]['updated_gmt'] = gmdate( 'c' ); }
                return array( 'ok'=>true, 'invalidated'=>isset( $state['claims'][ $id ] ) );
            } );
        }
        if ( 'upsert' === $op ) {
            return self::mutate( static function( array &$state ) use ( $args, $id, $claim ) {
                $ttl = max( 60, min( 31536000, (int) ( $args['ttl_seconds'] ?? 2592000 ) ) );
                $row = array(
                    'id'=>$id,
                    'claim'=>wp_strip_all_tags( $claim ),
                    'source_url'=>esc_url_raw( (string) ( $args['source_url'] ?? '' ) ),
                    'authority'=>self::text( (string) ( $args['authority'] ?? 'verified_source' ), 200 ),
                    'confidence'=>max( 0, min( 1, (float) ( $args['confidence'] ?? 1 ) ) ),
                    'status'=>sanitize_key( (string) ( $args['status'] ?? 'verified' ) ),
                    'checked_gmt'=>gmdate( 'c' ),
                    'expires_gmt'=>gmdate( 'c', time() + $ttl ),
                    'entity'=>self::text( (string) ( $args['entity'] ?? '' ), 300 ),
                );
                $state['claims'][ $id ] = $row;
                if ( count( $state['claims'] ) > self::MAX_CLAIMS ) { $state['claims'] = array_slice( $state['claims'], -self::MAX_CLAIMS, null, true ); }
                return array( 'ok'=>true, 'claim'=>$row );
            } );
        }
        return new WP_Error( 'claim_operation_invalid', 'Unsupported claim operation.', array( 'status'=>400 ) );
    }

    public static function internal_link_graph( array $args ) {
        $limit = max( 10, min( 1000, (int) ( $args['limit'] ?? 300 ) ) );
        $posts = get_posts( array( 'post_type'=>array( 'post','page','product' ), 'post_status'=>'publish', 'posts_per_page'=>$limit, 'orderby'=>'modified', 'order'=>'DESC' ) );
        $host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $nodes = array(); $incoming = array(); $outgoing = array(); $anchors = array();
        foreach ( $posts as $post ) {
            $url = (string) get_permalink( $post );
            $links = array();
            if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', (string) $post->post_content, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $href = esc_url_raw( html_entity_decode( (string) $match[1] ) );
                    $link_host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
                    if ( ! $href || ( $link_host && $link_host !== $host ) ) { continue; }
                    if ( str_starts_with( $href, '/' ) ) { $href = home_url( $href ); }
                    $links[] = $href;
                    $anchors[ $href ][] = self::text( wp_strip_all_tags( (string) $match[2] ), 200 );
                }
            }
            $links = array_values( array_unique( $links ) );
            $outgoing[ $url ] = $links;
            $nodes[ $url ] = array( 'post_id'=>(int) $post->ID, 'title'=>get_the_title( $post ), 'url'=>$url, 'outgoing'=>count( $links ) );
            foreach ( $links as $to ) { $incoming[ $to ] = (int) ( $incoming[ $to ] ?? 0 ) + 1; }
        }
        $pillars = array_map( array( __CLASS__, 'normalize_url' ), (array) ( $args['pillar_urls'] ?? array() ) );
        $rows = array();
        foreach ( $nodes as $url => $node ) {
            $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
            $supports = 0;
            foreach ( (array) ( $outgoing[ $url ] ?? array() ) as $to ) { if ( in_array( $to, $pillars, true ) || in_array( (string) wp_parse_url( $to, PHP_URL_PATH ), $pillars, true ) ) { $supports++; } }
            $rows[] = array_merge( $node, array(
                'incoming'=>(int) ( $incoming[ $url ] ?? 0 ),
                'orphan_score'=>0 === (int) ( $incoming[ $url ] ?? 0 ) ? 1 : 0,
                'depth_proxy'=>'' === $path ? 0 : substr_count( $path, '/' ) + 1,
                'pillar_support_score'=>$supports,
                'anchor_distribution'=>array_values( array_unique( $anchors[ $url ] ?? array() ) ),
            ) );
        }
        usort( $rows, static fn( $a, $b ) => ( $b['orphan_score'] <=> $a['orphan_score'] ) ?: ( $a['incoming'] <=> $b['incoming'] ) );
        return array( 'ok'=>true, 'nodes'=>count( $nodes ), 'edges'=>array_sum( array_map( 'count', $outgoing ) ), 'pages'=>array_slice( $rows, 0, 500 ), 'goal'=>'pillar_support_and_orphan_reduction' );
    }

    public static function cannibalization_resolver( array $args ) {
        $op = sanitize_key( (string) ( $args['operation'] ?? 'analyze' ) );
        $state = self::state();
        if ( 'list' === $op ) { return array( 'ok'=>true, 'decisions'=>array_values( $state['cannibalization'] ) ); }
        $keyword = self::lower( trim( (string) ( $args['keyword'] ?? '' ) ) );
        $intent = self::key( (string) ( $args['intent'] ?? 'default' ) );
        if ( '' === $keyword ) { return new WP_Error( 'cannibalization_keyword_required', 'keyword is required.', array( 'status'=>400 ) ); }
        $id = hash( 'sha256', $keyword . '|' . $intent );
        $candidates = array_values( array_filter( (array) ( $args['candidates'] ?? $args['urls'] ?? array() ), static function( $row ) { return is_array( $row ) || is_string( $row ); } ) );
        $normalized = array();
        foreach ( array_slice( $candidates, 0, 50 ) as $row ) {
            if ( is_string( $row ) ) { $row = array( 'url'=>$row, 'score'=>0.5 ); }
            $normalized[] = array( 'url'=>self::normalize_url( (string) ( $row['url'] ?? '' ) ), 'score'=>max( 0, min( 1, (float) ( $row['score'] ?? 0.5 ) ) ), 'intent_similarity'=>max( 0, min( 1, (float) ( $row['intent_similarity'] ?? 1 ) ) ) );
        }
        if ( 'analyze' === $op ) {
            if ( count( $normalized ) < 2 ) { return new WP_Error( 'cannibalization_candidates_required', 'At least two candidates are required.', array( 'status'=>400 ) ); }
            usort( $normalized, static fn( $a, $b ) => ( $b['score'] <=> $a['score'] ) ?: ( $b['intent_similarity'] <=> $a['intent_similarity'] ) );
            $winner = $normalized[0]; $plan = array();
            foreach ( $normalized as $index => $row ) { $plan[] = array( 'url'=>$row['url'], 'action'=>0 === $index ? 'KEEP' : 'RETARGET_OR_MERGE', 'score'=>$row['score'], 'intent_similarity'=>$row['intent_similarity'] ); }
            return array( 'ok'=>true, 'keyword'=>$keyword, 'intent'=>$intent, 'dominant_url'=>$winner['url'], 'resolution_plan'=>$plan, 'destructive_changes_applied'=>false );
        }
        if ( 'decide' === $op ) {
            $guard = self::lane( $args, 'seo:cannibal:' . $id, true ); if ( is_wp_error( $guard ) ) { return $guard; }
            $decision = strtoupper( sanitize_key( (string) ( $args['decision'] ?? '' ) ) );
            $allowed = array( 'KEEP','REFRESH','MERGE','REDIRECT','NOINDEX','RETARGET','DIFFERENTIATE' );
            if ( ! in_array( $decision, $allowed, true ) ) { return new WP_Error( 'cannibalization_decision_invalid', 'Unsupported decision.', array( 'status'=>400 ) ); }
            return self::mutate( static function( array &$state ) use ( $args, $id, $keyword, $intent, $decision, $normalized ) {
                $row = array( 'id'=>$id, 'keyword'=>$keyword, 'intent'=>$intent, 'decision'=>$decision, 'target_url'=>self::normalize_url( (string) ( $args['target_url'] ?? '' ) ), 'candidates'=>$normalized, 'evidence'=>is_array( $args['evidence'] ?? null ) ? $args['evidence'] : array(), 'updated_gmt'=>gmdate( 'c' ) );
                $state['cannibalization'][ $id ] = $row;
                if ( count( $state['cannibalization'] ) > self::MAX_CANNIBAL ) { $state['cannibalization'] = array_slice( $state['cannibalization'], -self::MAX_CANNIBAL, null, true ); }
                return array( 'ok'=>true, 'decision'=>$row, 'destructive_changes_applied'=>false );
            } );
        }
        return new WP_Error( 'cannibalization_operation_invalid', 'Unsupported operation.', array( 'status'=>400 ) );
    }

    private static function canonical_from_html( string $html ): string {
        if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)/i', $html, $match ) || preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $html, $match ) ) { return esc_url_raw( $match[1] ); }
        return '';
    }

    public static function post_publish_watcher( array $args ) {
        $op = sanitize_key( (string) ( $args['operation'] ?? 'list' ) );
        $state = self::state();
        if ( 'list' === $op ) { return array( 'ok'=>true, 'watches'=>array_values( $state['watches'] ) ); }
        $url = self::normalize_url( (string) ( $args['url'] ?? '' ) );
        $watch_id = (string) ( $args['watch_id'] ?? ( $url ? hash( 'sha256', strtolower( $url ) ) : '' ) );
        if ( in_array( $op, array( 'get','cancel' ), true ) && '' === $watch_id ) { return new WP_Error( 'watch_id_required', 'watch_id or url is required.', array( 'status'=>400 ) ); }
        if ( 'get' === $op ) {
            $row = $state['watches'][ $watch_id ] ?? null;
            return $row ? array( 'ok'=>true, 'watch'=>$row ) : new WP_Error( 'watch_not_found', 'Watcher not found.', array( 'status'=>404 ) );
        }
        if ( 'cancel' === $op ) {
            $guard = self::lane( $args, 'watch:' . $watch_id, true ); if ( is_wp_error( $guard ) ) { return $guard; }
            return self::mutate( static function( array &$state ) use ( $watch_id ) {
                if ( ! isset( $state['watches'][ $watch_id ] ) ) { return new WP_Error( 'watch_not_found', 'Watcher not found.', array( 'status'=>404 ) ); }
                $state['watches'][ $watch_id ]['status'] = 'cancelled'; $state['watches'][ $watch_id ]['updated_gmt'] = gmdate( 'c' );
                return array( 'ok'=>true, 'watch'=>$state['watches'][ $watch_id ] );
            } );
        }
        if ( '' === $url ) { return new WP_Error( 'watch_url_required', 'url is required.', array( 'status'=>400 ) ); }
        if ( 'create' === $op ) {
            $guard = self::lane( $args, 'watch:' . $watch_id, true ); if ( is_wp_error( $guard ) ) { return $guard; }
            return self::mutate( static function( array &$state ) use ( $args, $url, $watch_id ) {
                $row = $state['watches'][ $watch_id ] ?? array( 'id'=>$watch_id, 'url'=>$url, 'created_gmt'=>gmdate( 'c' ), 'history'=>array() );
                $row['campaign_id'] = self::key( (string) ( $args['campaign_id'] ?? $row['campaign_id'] ?? '' ) );
                $row['keyword'] = self::text( (string) ( $args['keyword'] ?? $row['keyword'] ?? '' ), 300 );
                $row['status'] = 'watching'; $row['updated_gmt'] = gmdate( 'c' );
                $state['watches'][ $watch_id ] = $row;
                if ( count( $state['watches'] ) > self::MAX_WATCHES ) { $state['watches'] = array_slice( $state['watches'], -self::MAX_WATCHES, null, true ); }
                return array( 'ok'=>true, 'watch'=>$row );
            } );
        }
        if ( in_array( $op, array( 'run','check' ), true ) ) {
            $guard = self::lane( $args, 'watch:' . $watch_id, false ); if ( is_wp_error( $guard ) ) { return $guard; }
            $response = wp_safe_remote_get( $url, array( 'timeout'=>15, 'redirection'=>3, 'headers'=>array( 'User-Agent'=>'PRSTUDIO-SEO-Watcher/' . self::VERSION ) ) );
            if ( is_wp_error( $response ) ) { return $response; }
            $code = (int) wp_remote_retrieve_response_code( $response ); $html = (string) wp_remote_retrieve_body( $response ); $robots = '';
            if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)/i', $html, $match ) ) { $robots = strtolower( $match[1] ); }
            $gsc = is_array( $args['gsc'] ?? null ) ? $args['gsc'] : array(); $serp = is_array( $args['serp'] ?? null ) ? $args['serp'] : array();
            $impressions = (float) ( $gsc['impressions'] ?? 0 ); $position = (float) ( $serp['position'] ?? $gsc['position'] ?? 0 );
            $milestones = array( 'first_impression'=>$impressions > 0, 'top100'=>$position > 0 && $position <= 100, 'top50'=>$position > 0 && $position <= 50, 'top20'=>$position > 0 && $position <= 20, 'top10'=>$position > 0 && $position <= 10, 'top3'=>$position > 0 && $position <= 3, 'number1'=>$position > 0 && $position <= 1 );
            $obs = array( 'observed_gmt'=>gmdate( 'c' ), 'http_status'=>$code, 'canonical'=>self::canonical_from_html( $html ), 'indexable'=>200 === $code && ! str_contains( $robots, 'noindex' ), 'robots'=>$robots, 'gsc'=>$gsc, 'serp'=>$serp, 'milestones'=>$milestones );
            return self::mutate( static function( array &$state ) use ( $watch_id, $url, $obs ) {
                $row = $state['watches'][ $watch_id ] ?? array( 'id'=>$watch_id, 'url'=>$url, 'created_gmt'=>gmdate( 'c' ), 'history'=>array(), 'status'=>'watching' );
                $row['history'][] = $obs; $row['history'] = array_slice( $row['history'], -180 ); $row['last'] = $obs; $row['updated_gmt'] = gmdate( 'c' );
                $age_days = max( 0, ( time() - strtotime( (string) $row['created_gmt'] ) ) / DAY_IN_SECONDS );
                $row['next_action'] = $age_days >= 14 && empty( $obs['milestones']['first_impression'] ) ? 'diagnose_indexability_links_intent_authority' : ( ! empty( $obs['milestones']['top10'] ) ? 'protect_and_expand' : 'continue_watch' );
                $state['watches'][ $watch_id ] = $row;
                return array( 'ok'=>true, 'watch'=>$row );
            } );
        }
        return new WP_Error( 'watch_operation_invalid', 'Unsupported watcher operation.', array( 'status'=>400 ) );
    }

    public static function refresh_prioritizer( array $args ) {
        $rows = array_values( array_filter( (array) ( $args['pages'] ?? array() ), 'is_array' ) ); $out = array();
        foreach ( array_slice( $rows, 0, 1000 ) as $row ) {
            $position = max( 1, (float) ( $row['position'] ?? 100 ) ); $impressions = max( 0, (float) ( $row['impressions'] ?? 0 ) );
            $business = max( 0, min( 1, (float) ( $row['business_importance'] ?? 0.5 ) ) ); $campaign = max( 0, min( 1, (float) ( $row['campaign_importance'] ?? 0.5 ) ) );
            $risk = max( .05, min( 1, (float) ( $row['modification_risk'] ?? 0.25 ) ) );
            $ranking = $position <= 20 ? 1.0 : ( $position <= 50 ? .55 : .2 );
            $score = round( 100 * ( $ranking * .35 + $business * .2 + $campaign * .2 + min( 1, log10( $impressions + 1 ) / 5 ) * .25 ) * ( 1 - $risk * .35 ), 2 );
            $out[] = array_merge( $row, array( 'priority_score'=>$score, 'recommended_action'=>$position <= 15 ? 'refresh_for_top10' : ( $position <= 50 ? 'expand_and_link' : 'diagnose_before_refresh' ) ) );
        }
        usort( $out, static fn( $a, $b ) => $b['priority_score'] <=> $a['priority_score'] );
        return array( 'ok'=>true, 'pages'=>$out );
    }

    public static function schema_editorial_compiler( array $args ) {
        $type = (string) ( $args['type'] ?? 'Article' ); $data = is_array( $args['data'] ?? null ) ? $args['data'] : array();
        $schema = array( '@context'=>'https://schema.org', '@type'=>$type ); $required = array();
        if ( 'Recipe' === $type ) {
            $required = array( 'name','recipeIngredient','recipeInstructions' );
            foreach ( array( 'name','description','image','author','datePublished','prepTime','cookTime','totalTime','recipeYield','recipeIngredient','recipeInstructions' ) as $key ) { if ( isset( $data[ $key ] ) ) { $schema[ $key ] = $data[ $key ]; } }
        } elseif ( 'BreadcrumbList' === $type ) {
            $required = array( 'itemListElement' ); $schema['itemListElement'] = $data['itemListElement'] ?? array();
        } else {
            $required = array( 'headline' );
            foreach ( array( 'headline','description','image','author','datePublished','dateModified','mainEntityOfPage' ) as $key ) { if ( isset( $data[ $key ] ) ) { $schema[ $key ] = $data[ $key ]; } }
        }
        $missing = array_values( array_filter( $required, static fn( $key ) => empty( $schema[ $key ] ) ) );
        return array( 'ok'=>! $missing, 'schema'=>$schema, 'missing_required'=>$missing, 'json'=>wp_json_encode( $schema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE ) );
    }

    public static function media_editorial_pipeline( array $args ) {
        $ids = array_values( array_unique( array_map( 'absint', (array) ( $args['attachment_ids'] ?? array() ) ) ) ); $rows = array();
        foreach ( array_slice( $ids, 0, 50 ) as $id ) {
            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) { $rows[] = array( 'id'=>$id, 'ok'=>false, 'reason'=>'not_attachment' ); continue; }
            $file = get_attached_file( $id ); $meta = wp_get_attachment_metadata( $id ); $alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
            $row = array( 'id'=>$id, 'ok'=>true, 'mime'=>get_post_mime_type( $id ), 'file'=>$file ? wp_basename( $file ) : '', 'bytes'=>$file && is_file( $file ) ? filesize( $file ) : 0, 'width'=>(int) ( $meta['width'] ?? 0 ), 'height'=>(int) ( $meta['height'] ?? 0 ), 'alt'=>$alt, 'alt_present'=>'' !== trim( $alt ) );
            if ( ! empty( $args['set_alt'] ) && '' === trim( $alt ) ) { $new_alt = self::text( (string) $args['set_alt'], 300 ); update_post_meta( $id, '_wp_attachment_image_alt', $new_alt ); $row['alt'] = $new_alt; $row['alt_present'] = true; $row['changed'] = true; }
            $rows[] = $row;
        }
        return array( 'ok'=>true, 'assets'=>$rows, 'all_alt_present'=>! array_filter( $rows, static fn( $row ) => empty( $row['alt_present'] ) ) );
    }

    public static function directory_entity_engine( array $args ) {
        $op = sanitize_key( (string) ( $args['operation'] ?? 'search' ) ); $state = self::state();
        if ( in_array( $op, array( 'search','list' ), true ) ) {
            $query = self::lower( trim( (string) ( $args['query'] ?? '' ) ) ); $rows = array_values( $state['directory'] );
            if ( $query ) { $rows = array_values( array_filter( $rows, static fn( $row ) => str_contains( self::lower( ( $row['name'] ?? '' ) . ' ' . ( $row['municipality'] ?? '' ) . ' ' . ( $row['type'] ?? '' ) ), $query ) ) ); }
            return array( 'ok'=>true, 'entities'=>array_slice( $rows, 0, 500 ) );
        }
        $name = self::text( (string) ( $args['name'] ?? '' ), 300 ); $municipality = self::text( (string) ( $args['municipality'] ?? '' ), 150 ); $type = self::key( (string) ( $args['type'] ?? 'local' ) );
        if ( '' === $name ) { return new WP_Error( 'directory_name_required', 'name is required.', array( 'status'=>400 ) ); }
        $dedupe = hash( 'sha256', self::lower( $name . '|' . $municipality . '|' . $type ) );
        if ( 'get' === $op ) { $row = $state['directory'][ $dedupe ] ?? null; return $row ? array( 'ok'=>true, 'entity'=>$row ) : new WP_Error( 'directory_entity_not_found', 'Entity not found.', array( 'status'=>404 ) ); }
        $guard = self::lane( $args, 'directory:' . $dedupe, true ); if ( is_wp_error( $guard ) ) { return $guard; }
        if ( 'upsert' === $op ) {
            return self::mutate( static function( array &$state ) use ( $args, $dedupe, $name, $municipality, $type ) {
                $old = $state['directory'][ $dedupe ] ?? array();
                $row = array_merge( $old, array(
                    'id'=>$dedupe, 'name'=>$name, 'type'=>$type, 'municipality'=>$municipality,
                    'province'=>self::text( (string) ( $args['province'] ?? $old['province'] ?? '' ), 150 ),
                    'address'=>self::text( (string) ( $args['address'] ?? $old['address'] ?? '' ), 500 ),
                    'lat'=>isset( $args['lat'] ) ? (float) $args['lat'] : ( $old['lat'] ?? null ), 'lon'=>isset( $args['lon'] ) ? (float) $args['lon'] : ( $old['lon'] ?? null ),
                    'website'=>esc_url_raw( (string) ( $args['website'] ?? $old['website'] ?? '' ) ), 'social'=>self::text_list( (array) ( $args['social'] ?? $old['social'] ?? array() ), 30, 2000 ),
                    'phone'=>self::text( (string) ( $args['phone'] ?? $old['phone'] ?? '' ), 100 ), 'cuisine'=>self::text_list( (array) ( $args['cuisine'] ?? $old['cuisine'] ?? array() ), 50, 200 ),
                    'source'=>esc_url_raw( (string) ( $args['source'] ?? $old['source'] ?? '' ) ), 'last_verified_gmt'=>gmdate( 'c' ),
                ) );
                $state['directory'][ $dedupe ] = $row;
                if ( count( $state['directory'] ) > self::MAX_ENTITIES ) { $state['directory'] = array_slice( $state['directory'], -self::MAX_ENTITIES, null, true ); }
                return array( 'ok'=>true, 'entity'=>$row, 'deduplicated'=>! empty( $old ) );
            } );
        }
        return new WP_Error( 'directory_operation_invalid', 'Unsupported directory operation.', array( 'status'=>400 ) );
    }

    public static function authority_outreach_engine( array $args ) {
        $op = sanitize_key( (string) ( $args['operation'] ?? 'list' ) ); $state = self::state();
        if ( 'list' === $op ) { return array( 'ok'=>true, 'records'=>array_values( $state['outreach'] ) ); }
        $domain = strtolower( trim( (string) ( $args['domain'] ?? '' ) ) ); if ( '' === $domain ) { return new WP_Error( 'outreach_domain_required', 'domain is required.', array( 'status'=>400 ) ); }
        $id = hash( 'sha256', $domain . '|' . self::lower( (string) ( $args['entity'] ?? '' ) ) );
        $guard = self::lane( $args, 'outreach:' . $id, true ); if ( is_wp_error( $guard ) ) { return $guard; }
        if ( 'upsert' === $op ) {
            return self::mutate( static function( array &$state ) use ( $args, $id, $domain ) {
                $old = $state['outreach'][ $id ] ?? array();
                $row = array_merge( $old, array(
                    'id'=>$id, 'domain'=>$domain, 'entity'=>self::text( (string) ( $args['entity'] ?? $old['entity'] ?? '' ), 300 ),
                    'contact'=>self::text( (string) ( $args['contact'] ?? $old['contact'] ?? '' ), 300 ), 'relationship'=>self::text( (string) ( $args['relationship'] ?? $old['relationship'] ?? '' ), 200 ),
                    'relevance'=>max( 0, min( 1, (float) ( $args['relevance'] ?? $old['relevance'] ?? 0.5 ) ) ), 'authority'=>max( 0, min( 1, (float) ( $args['authority'] ?? $old['authority'] ?? 0.5 ) ) ),
                    'link_status'=>sanitize_key( (string) ( $args['link_status'] ?? $old['link_status'] ?? 'prospect' ) ), 'last_contact_gmt'=>self::text( (string) ( $args['last_contact_gmt'] ?? $old['last_contact_gmt'] ?? '' ), 80 ),
                    'next_action'=>self::text( (string) ( $args['next_action'] ?? $old['next_action'] ?? '' ), 1000 ), 'updated_gmt'=>gmdate( 'c' ),
                ) );
                $state['outreach'][ $id ] = $row;
                if ( count( $state['outreach'] ) > self::MAX_OUTREACH ) { $state['outreach'] = array_slice( $state['outreach'], -self::MAX_OUTREACH, null, true ); }
                return array( 'ok'=>true, 'record'=>$row );
            } );
        }
        return new WP_Error( 'outreach_operation_invalid', 'Unsupported outreach operation.', array( 'status'=>400 ) );
    }

    public static function first_party_publisher( array $args ) {
        if ( ! function_exists( 'wc_get_orders' ) ) { return new WP_Error( 'woocommerce_required', 'WooCommerce is required for first-party commerce insights.', array( 'status'=>503 ) ); }
        $days = max( 7, min( 730, (int) ( $args['days'] ?? 90 ) ) ); $threshold = max( 3, min( 100, (int) ( $args['privacy_threshold'] ?? 5 ) ) );
        $after = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
        $orders = wc_get_orders( array( 'limit'=>5000, 'status'=>array( 'wc-completed','wc-processing' ), 'date_created'=>'>=' . $after, 'return'=>'objects' ) );
        $products = array(); $categories = array(); $order_count = 0;
        foreach ( $orders as $order ) {
            $order_count++;
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $product_id = (int) $item->get_product_id(); $qty = (float) $item->get_quantity(); if ( ! $product_id ) { continue; }
                $products[ $product_id ] = ( $products[ $product_id ] ?? 0 ) + $qty;
                foreach ( (array) wp_get_post_terms( $product_id, 'product_cat', array( 'fields'=>'names' ) ) as $category ) { $categories[ $category ] = ( $categories[ $category ] ?? 0 ) + $qty; }
            }
        }
        arsort( $products ); arsort( $categories ); $top_products = array(); $top_categories = array();
        foreach ( array_slice( $products, 0, 50, true ) as $product_id => $qty ) { if ( $qty >= $threshold ) { $top_products[] = array( 'product_id'=>$product_id, 'name'=>get_the_title( $product_id ), 'units'=>$qty ); } }
        foreach ( array_slice( $categories, 0, 30, true ) as $name => $qty ) { if ( $qty >= $threshold ) { $top_categories[] = array( 'category'=>$name, 'units'=>$qty ); } }
        return array(
            'ok'=>true, 'period_days'=>$days, 'orders_aggregated'=>$order_count, 'privacy_threshold'=>$threshold, 'contains_personal_data'=>false,
            'top_products'=>$top_products, 'top_categories'=>$top_categories,
            'suggested_headlines'=>array( 'I prodotti più richiesti negli ultimi ' . $days . ' giorni', 'Categorie Ho.Re.Ca. in crescita: dati aggregati Ideal Market' ),
            'generated_gmt'=>gmdate( 'c' ),
        );
    }
}
