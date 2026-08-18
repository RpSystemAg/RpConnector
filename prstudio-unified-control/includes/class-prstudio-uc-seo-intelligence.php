<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only semantic SEO executors. The class deliberately refuses to invent
 * keyword intent: a mapping is emitted only when it is backed by explicit
 * WordPress metadata, an explicit request mapping, or Search Console rows.
 */
final class PRSTUDIO_UC_SEO_Intelligence {
    private const MAX_OBJECTS = 500;
    private const MAX_GSC_ROWS = 5000;

    private static function normalize_url( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) { return ''; }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return ''; }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        $host = strtolower( (string) $parts['host'] );
        $path = (string) ( $parts['path'] ?? '/' );
        $path = '/' . ltrim( $path, '/' );
        if ( '/' !== $path ) { $path = untrailingslashit( $path ); }
        return $scheme . '://' . $host . $path;
    }

    private static function inventory( array $args ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['ids'] ?? array() ) ) ) ) );
        $single = absint( $args['id'] ?? $args['object_id'] ?? 0 );
        if ( $single ) { array_unshift( $ids, $single ); }
        $limit = max( 1, min( self::MAX_OBJECTS, absint( $args['limit'] ?? self::MAX_OBJECTS ) ) );
        if ( ! $ids ) {
            $types = get_post_types( array( 'public' => true ), 'names' );
            $types = array_values( array_diff( array_map( 'sanitize_key', (array) $types ), array( 'attachment' ) ) );
            $ids = get_posts( array(
                'post_type' => $types ?: array( 'post', 'page', 'product' ),
                'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => $limit,
                'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false,
            ) );
        }
        $rows = array();
        foreach ( array_slice( $ids, 0, $limit ) as $id ) {
            $post = get_post( (int) $id );
            if ( ! $post || 'publish' !== (string) $post->post_status ) { continue; }
            $url = self::normalize_url( (string) get_permalink( (int) $id ) );
            if ( '' === $url ) { continue; }
            $rows[] = array(
                'id' => (int) $id,
                'type' => (string) $post->post_type,
                'title' => (string) get_the_title( (int) $id ),
                'url' => $url,
                'focus_keyword' => trim( (string) get_post_meta( (int) $id, 'rank_math_focus_keyword', true ) ),
            );
        }
        return $rows;
    }

    private static function flatten_gsc_rows( array $args ): array {
        $candidates = array( $args['google_data'] ?? array(), $args['research_bundle'] ?? array() );
        $rows = array();
        $walk = static function ( $value ) use ( &$rows, &$walk ): void {
            if ( count( $rows ) >= self::MAX_GSC_ROWS || ! is_array( $value ) ) { return; }
            $has_scalar_dimension = ( isset( $value['query'] ) && is_scalar( $value['query'] ) ) || ( isset( $value['page'] ) && is_scalar( $value['page'] ) ) || ( isset( $value['url'] ) && is_scalar( $value['url'] ) );
            $has_keys = isset( $value['keys'] ) && is_array( $value['keys'] ) && ! array_filter( $value['keys'], static fn( $x ) => ! is_scalar( $x ) && null !== $x );
            if ( $has_scalar_dimension || $has_keys ) { $rows[] = $value; return; }
            foreach ( $value as $child ) { if ( is_array( $child ) ) { $walk( $child ); } if ( count( $rows ) >= self::MAX_GSC_ROWS ) { break; } }
        };
        foreach ( $candidates as $candidate ) { if ( is_array( $candidate ) ) { $walk( $candidate ); } }
        return $rows;
    }

    private static function gsc_evidence( array $args ): array {
        $mapped = array(); $unassigned = array(); $consumed = 0;
        foreach ( self::flatten_gsc_rows( $args ) as $row ) {
            $keys = is_array( $row['keys'] ?? null ) ? array_values( $row['keys'] ) : array();
            // A key position is used only when the row declares the matching dimensions.
            // Query-only Browser sets therefore never become a synthetic query->page pair.
            $declared = array_values( array_map( 'strval', (array) ( $row['_dimensions'] ?? $row['dimensions'] ?? array() ) ) );
            $query = trim( (string) ( $row['query'] ?? '' ) );
            $page_raw = (string) ( $row['page'] ?? $row['url'] ?? '' );
            if ( '' === $query && $declared && false !== array_search( 'query', $declared, true ) ) {
                $idx = array_search( 'query', $declared, true ); if ( isset( $keys[ $idx ] ) ) { $query = trim( (string) $keys[ $idx ] ); }
            }
            if ( '' === $page_raw && $declared && false !== array_search( 'page', $declared, true ) ) {
                $idx = array_search( 'page', $declared, true ); if ( isset( $keys[ $idx ] ) ) { $page_raw = (string) $keys[ $idx ]; }
            }
            // Official API normalizer already emits query/page named fields. For legacy
            // evidence with exactly two keys, accept positional mapping only when an
            // explicit integrity marker says the dimensions are exact.
            if ( '' === $query && '' === $page_raw && 2 === count( $keys ) && 'verified' === (string) ( $row['dimension_integrity']['status'] ?? '' ) ) {
                $query = trim( (string) $keys[0] ); $page_raw = (string) $keys[1];
            }
            if ( '' === $query ) { continue; }
            $consumed++;
            $signal = array(
                'keyword' => $query,
                'clicks' => (float) ( $row['clicks'] ?? 0 ),
                'impressions' => (float) ( $row['impressions'] ?? 0 ),
                'ctr' => (float) ( $row['ctr'] ?? 0 ),
                'position' => isset( $row['position'] ) ? (float) $row['position'] : null,
                'source' => 'gsc_query',
            );
            $page = self::normalize_url( $page_raw );
            if ( '' !== $page ) { $mapped[ $page ][] = $signal + array( 'ownership_evidence'=>'gsc_exact_query_page' ); }
            else { $unassigned[] = $signal + array( 'ownership_evidence'=>'none', 'reason'=>'query_observed_without_verified_page_dimension' ); }
        }
        foreach ( $mapped as &$list ) {
            usort( $list, static function ( array $a, array $b ): int { $cmp=$b['impressions']<=>$a['impressions']; return 0!==$cmp?$cmp:($b['clicks']<=>$a['clicks']); } );
        }
        unset( $list );
        usort( $unassigned, static fn( array $a, array $b ): int => ($b['impressions']<=>$a['impressions']) ?: ($b['clicks']<=>$a['clicks']) );
        return array( 'mapped'=>$mapped, 'unassigned'=>$unassigned, 'consumed'=>$consumed );
    }

    private static function gsc_index( array $args ): array { return self::gsc_evidence( $args )['mapped']; }

    public static function build_keyword_map( array $args ) {
        $inventory = self::inventory( $args );
        $gsc_evidence = self::gsc_evidence( $args );
        $gsc = $gsc_evidence['mapped'];
        $explicit_keywords = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $args['keywords'] ?? array() ) ) ) ) );
        $mapped = array(); $unmapped = array();
        foreach ( $inventory as $item ) {
            $url = $item['url']; $signals = array();
            if ( '' !== $item['focus_keyword'] ) {
                foreach ( preg_split( '/\s*,\s*/', $item['focus_keyword'] ) ?: array() as $keyword ) {
                    $keyword = trim( (string) $keyword );
                    if ( '' !== $keyword ) { $signals[] = array( 'keyword'=>$keyword, 'source'=>'rank_math_focus_keyword', 'verified'=>true ); }
                }
            }
            foreach ( (array) ( $gsc[ $url ] ?? array() ) as $signal ) { $signals[] = $signal + array( 'verified'=>true ); }
            if ( $signals ) {
                $mapped[] = array( 'id'=>$item['id'], 'type'=>$item['type'], 'title'=>$item['title'], 'url'=>$url, 'keywords'=>$signals, 'verified'=>true );
            } else {
                $unmapped[] = array( 'id'=>$item['id'], 'type'=>$item['type'], 'title'=>$item['title'], 'url'=>$url, 'reason'=>'no_explicit_keyword_or_gsc_query' );
            }
        }
        return array(
            'provider' => 'prstudio_wordpress_gsc_keyword_map_v1',
            'mode' => 'evidence_only_no_semantic_inference',
            'inventory_count' => count( $inventory ), 'mapped_count' => count( $mapped ), 'unmapped_count' => count( $unmapped ),
            'mapped' => $mapped, 'unmapped' => $unmapped,
            'unassigned_explicit_keywords' => $explicit_keywords,
            'gsc_rows_consumed' => (int) $gsc_evidence['consumed'],
            'unassigned_observed_demand' => $gsc_evidence['unassigned'],
            'write_performed' => false, 'verified' => true, 'bounded' => true,
            'note' => 'Le keyword non associate a una URL da metadati espliciti o GSC restano non mappate: il runtime non inventa la semantica.',
        );
    }

    private static function product_ids( array $args ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['ids'] ?? array() ) ) ) ) );
        $single = absint( $args['id'] ?? $args['object_id'] ?? 0 ); if ( $single ) { array_unshift( $ids, $single ); }
        if ( $ids ) { return array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_OBJECTS ); }
        return array_map( 'intval', (array) get_posts( array( 'post_type'=>'product', 'post_status'=>'publish', 'fields'=>'ids', 'posts_per_page'=>self::MAX_OBJECTS, 'orderby'=>'ID', 'order'=>'ASC', 'suppress_filters'=>false ) ) );
    }

    public static function audit_product_seo( array $args ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return new WP_Error( 'prstudio_woocommerce_unavailable', 'WooCommerce non disponibile: audit prodotto non eseguibile.', array( 'status'=>503 ) );
        }
        $items = array(); $totals = array( 'products'=>0, 'issues'=>0, 'missing_title'=>0, 'missing_description'=>0, 'missing_image'=>0, 'missing_image_alt'=>0, 'missing_focus_keyword'=>0 );
        $frontend_samples = array(); $frontend_budget = 10;
        $force_reanalysis = ! empty( $args['force_reanalysis'] );
        $product_ids = self::product_ids( $args );
        $product_map = array();
        if ( $product_ids && function_exists( 'wc_get_products' ) ) {
            $loaded = wc_get_products( array( 'include'=>$product_ids, 'limit'=>count( $product_ids ), 'return'=>'objects' ) );
            foreach ( (array) $loaded as $product ) { if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) { $product_map[ (int) $product->get_id() ] = $product; } }
        } elseif ( $product_ids ) {
            if ( function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $product_ids, false, true ); }
        }
        $image_ids = array(); foreach ( $product_map as $product ) { $image_id = (int) $product->get_image_id(); if ( $image_id ) { $image_ids[] = $image_id; } }
        if ( $image_ids && function_exists( 'update_postmeta_cache' ) ) { update_postmeta_cache( array_values( array_unique( $image_ids ) ) ); }
        foreach ( $product_ids as $id ) {
            if ( ! $force_reanalysis && class_exists( 'PRSTUDIO_UC_Memory' ) && PRSTUDIO_UC_Memory::can_reuse( 'seo_product_audit', (string) $id ) ) {
                $cached = PRSTUDIO_UC_Memory::lookup( 'seo_product_audit', (string) $id );
                $cached_item = is_array( $cached['summary']['item'] ?? null ) ? $cached['summary']['item'] : array();
                if ( $cached_item ) {
                    $cached_item['memory_reused'] = true;
                    $cached_item['memory_updated_gmt'] = (string) ( $cached['updated_gmt'] ?? '' );
                    $items[] = $cached_item;
                    $totals['products']++;
                    foreach ( (array) ( $cached_item['issues'] ?? array() ) as $issue ) {
                        $totals['issues']++;
                        if ( array_key_exists( (string) $issue, $totals ) ) { $totals[ (string) $issue ]++; }
                    }
                    if ( isset( $cached_item['frontend_sample'] ) && null !== $cached_item['frontend_sample'] ) {
                        $frontend_samples[] = array( 'id'=>$id, 'url'=>(string)($cached_item['url']??''), 'result'=>$cached_item['frontend_sample'], 'memory_reused'=>true );
                    }
                    PRSTUDIO_UC_Memory::movement( 'memory.reused', array( 'resource'=>(string)$id, 'method'=>'seo_product_audit', 'outcome'=>'skipped_duplicate_analysis' ) );
                    continue;
                }
            }
            $product = $product_map[ $id ] ?? wc_get_product( $id ); if ( ! $product ) { continue; }
            $title = trim( (string) $product->get_name() );
            $description = trim( wp_strip_all_tags( (string) $product->get_description() ) );
            $short = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
            $image_id = (int) $product->get_image_id();
            $alt = $image_id ? trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) : '';
            $focus = trim( (string) get_post_meta( $id, 'rank_math_focus_keyword', true ) );
            $issues = array();
            if ( '' === $title ) { $issues[]='missing_title'; $totals['missing_title']++; }
            if ( '' === $description && '' === $short ) { $issues[]='missing_description'; $totals['missing_description']++; }
            if ( ! $image_id ) { $issues[]='missing_image'; $totals['missing_image']++; }
            elseif ( '' === $alt ) { $issues[]='missing_image_alt'; $totals['missing_image_alt']++; }
            if ( '' === $focus ) { $issues[]='missing_focus_keyword'; $totals['missing_focus_keyword']++; }
            $totals['products']++; $totals['issues'] += count( $issues );
            $frontend = null;
            if ( $frontend_budget > 0 && class_exists( 'WPAIB_Site' ) && method_exists( 'WPAIB_Site', 'fetch_page' ) ) {
                $frontend_budget--; $page = WPAIB_Site::fetch_page( (string) get_permalink( $id ) );
                if ( is_wp_error( $page ) ) {
                    $frontend = array( 'verified'=>false, 'error'=>$page->get_error_code() );
                } else {
                    $html=(string)($page['html']??''); $status=(int)($page['status']??0);
                    preg_match('/<link\b[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)/i',$html,$canonical);
                    $has_product_schema=(bool)preg_match('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?["\']@type["\']\s*:\s*["\']Product["\']/is',$html);
                    $frontend = array( 'verified'=>$status>=200&&$status<400, 'http_status'=>$status, 'canonical'=>(string)($canonical[1]??''), 'product_schema'=>$has_product_schema, 'html_bytes'=>strlen($html) );
                }
                $frontend_samples[] = array( 'id'=>$id, 'url'=>(string)get_permalink($id), 'result'=>$frontend );
            }
            $audit_item = array(
                'id'=>$id, 'sku'=>(string)$product->get_sku(), 'name'=>$title, 'url'=>(string)get_permalink($id),
                'status'=>(string)$product->get_status(), 'type'=>(string)$product->get_type(),
                'seo'=>array(
                    'rank_math_title'=>(string)get_post_meta($id,'rank_math_title',true),
                    'rank_math_description'=>(string)get_post_meta($id,'rank_math_description',true),
                    'rank_math_canonical_url'=>(string)get_post_meta($id,'rank_math_canonical_url',true),
                    'focus_keyword'=>$focus,
                ),
                'content'=>array('description_present'=>''!==$description,'short_description_present'=>''!==$short,'image_id'=>$image_id,'image_alt_present'=>''!==$alt),
                'issues'=>$issues, 'issue_count'=>count($issues), 'verified_from'=>'woocommerce_crud_plus_wordpress_meta', 'frontend_sample'=>$frontend,
                'memory_reused'=>false,
            );
            $items[] = $audit_item;
            if ( class_exists( 'PRSTUDIO_UC_Memory' ) ) {
                $fingerprint = PRSTUDIO_UC_Memory::fingerprint( array(
                    'id'=>$id, 'modified'=>function_exists('get_post_modified_time') ? (string)get_post_modified_time('U',true,$id) : '',
                    'sku'=>(string)$product->get_sku(), 'status'=>(string)$product->get_status(), 'title'=>$title,
                    'focus'=>$focus, 'image_id'=>$image_id, 'image_alt'=>$alt,
                    'rank_math_title'=>$audit_item['seo']['rank_math_title'], 'rank_math_description'=>$audit_item['seo']['rank_math_description'],
                    'rank_math_canonical_url'=>$audit_item['seo']['rank_math_canonical_url'],
                ) );
                PRSTUDIO_UC_Memory::remember( 'seo_product_audit', (string)$id, $fingerprint, array( 'item'=>$audit_item, 'status'=>'verified', 'method'=>'woocommerce_crud_plus_wordpress_meta' ), 21600 );
                PRSTUDIO_UC_Memory::movement( 'seo.product.analyzed', array( 'resource'=>(string)$id, 'method'=>'woocommerce_crud_plus_wordpress_meta', 'outcome'=>'verified', 'issues'=>count($issues) ) );
            }
        }
        return array(
            'provider'=>'prstudio_native_woocommerce_product_seo_audit_v1', 'read_only'=>true, 'apply_fixes'=>false,
            'totals'=>$totals, 'items'=>$items, 'bounded'=>true, 'verified'=>true,
            'frontend_verification'=>'bounded_priority_sample', 'frontend_samples'=>$frontend_samples, 'frontend_sample_count'=>count($frontend_samples),
            'memory'=>array( 'enabled'=>class_exists('PRSTUDIO_UC_Memory'), 'reused_count'=>count(array_filter($items,static fn($x)=>!empty($x['memory_reused']))), 'fresh_count'=>count(array_filter($items,static fn($x)=>empty($x['memory_reused']))) ),
            'note'=>'Nessun parametro semantico viene inventato; gli audit verificati vengono riusati fino a scadenza o invalidazione per evitare analisi duplicate.',
        );
    }

    /** 3.0 evidence-only keyword map. Observed facts are never conflated with deterministic candidates. */
    public static function build_keyword_map_v3( array $args ) {
        $inventory = self::inventory( $args );
        $gsc_evidence = self::gsc_evidence( $args );
        $gsc = $gsc_evidence['mapped'];
        $include_derived = ! array_key_exists( 'include_derived_candidates', $args ) || ! empty( $args['include_derived_candidates'] );
        $items = array(); $observed_count = 0; $derived_count = 0; $recommended_count = 0;
        $stop = array_flip( array( 'the','and','con','per','del','della','delle','degli','dei','di','da','in','a','un','una','il','lo','la','i','gli','le','product','prodotto' ) );
        foreach ( $inventory as $item ) {
            $observed = array(); $derived = array(); $recommendation = null; $url = (string) $item['url'];
            if ( '' !== (string) $item['focus_keyword'] ) {
                foreach ( preg_split( '/\s*,\s*/', (string) $item['focus_keyword'] ) ?: array() as $keyword ) {
                    $keyword = trim( (string) $keyword ); if ( '' === $keyword ) { continue; }
                    $observed[] = array( 'type'=>'observed_keyword', 'keyword'=>$keyword, 'confidence'=>1.0, 'evidence'=>array( 'source'=>'rank_math_focus_keyword', 'object_id'=>$item['id'] ) );
                }
            }
            foreach ( (array) ( $gsc[ $url ] ?? array() ) as $row ) {
                $confidence = min( 1.0, 0.55 + min( 0.45, log( 1 + max( 0, (float) ( $row['impressions'] ?? 0 ) ) ) / 20 ) );
                $observed[] = array( 'type'=>'observed_keyword', 'keyword'=>(string)$row['keyword'], 'confidence'=>round($confidence,3), 'evidence'=>array( 'source'=>'gsc', 'clicks'=>$row['clicks'], 'impressions'=>$row['impressions'], 'ctr'=>$row['ctr'], 'position'=>$row['position'] ) );
            }
            if ( $include_derived ) {
                $terms = array();
                foreach ( array( 'product_cat','product_brand','pwb-brand','category','post_tag' ) as $taxonomy ) {
                    if ( function_exists( 'get_the_terms' ) ) {
                        $found = get_the_terms( (int) $item['id'], $taxonomy );
                        if ( is_array( $found ) ) { foreach ( $found as $term ) { if ( is_object( $term ) && ! empty( $term->name ) ) { $terms[] = (string) $term->name; } } }
                    }
                }
                $title_tokens = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( (string) $item['title'] ) ) ?: array();
                $title_tokens = array_values( array_filter( $title_tokens, static fn( $t ) => strlen( (string) $t ) >= 3 && ! isset( $stop[ (string) $t ] ) ) );
                foreach ( array_values( array_unique( array_merge( $terms, array_slice( $title_tokens, 0, 8 ) ) ) ) as $candidate ) {
                    $candidate = trim( (string) $candidate ); if ( '' === $candidate ) { continue; }
                    $already = false; foreach ( $observed as $obs ) { if ( 0 === strcasecmp( (string) $obs['keyword'], $candidate ) ) { $already=true; break; } }
                    if ( $already ) { continue; }
                    $derived[] = array( 'type'=>'derived_candidate', 'keyword'=>$candidate, 'confidence'=>in_array($candidate,$terms,true)?0.68:0.52, 'evidence'=>array( 'source'=>in_array($candidate,$terms,true)?'wordpress_taxonomy':'title_token', 'object_id'=>$item['id'] ), 'fact'=>false );
                }
            }
            usort( $observed, static fn( $a, $b ) => (float) $b['confidence'] <=> (float) $a['confidence'] );
            usort( $derived, static fn( $a, $b ) => (float) $b['confidence'] <=> (float) $a['confidence'] );
            if ( $observed ) {
                $best = $observed[0]; $recommendation = array( 'type'=>'recommended_keyword', 'keyword'=>$best['keyword'], 'confidence'=>$best['confidence'], 'basis'=>'strongest_observed_signal', 'evidence'=>$best['evidence'], 'fact'=>false );
            } elseif ( $derived ) {
                $best = $derived[0]; $recommendation = array( 'type'=>'recommended_keyword', 'keyword'=>$best['keyword'], 'confidence'=>round((float)$best['confidence']*0.8,3), 'basis'=>'deterministic_candidate_no_observed_query', 'evidence'=>$best['evidence'], 'fact'=>false );
            }
            $observed_count += count($observed); $derived_count += count($derived); if($recommendation){$recommended_count++;}
            $items[] = array( 'id'=>$item['id'], 'type'=>$item['type'], 'title'=>$item['title'], 'url'=>$url, 'observed_keyword'=>$observed, 'derived_candidate'=>$derived, 'recommended_keyword'=>$recommendation, 'write_performed'=>false );
        }
        return array( 'provider'=>'prstudio_keyword_map_v4', 'mode'=>'evidence_only', 'items'=>$items, 'inventory_count'=>count($items), 'observed_count'=>$observed_count, 'derived_candidate_count'=>$derived_count, 'recommended_count'=>$recommended_count, 'gsc_rows_consumed'=>(int)$gsc_evidence['consumed'], 'unassigned_observed_demand'=>$gsc_evidence['unassigned'], 'cross_dimension_join_inferred'=>false, 'write_performed'=>false, 'verified'=>true, 'note'=>'observed_keyword is factual evidence; query demand without verified page ownership remains unassigned; derived_candidate and recommended_keyword are explicitly non-factual recommendations.' );
    }

}
