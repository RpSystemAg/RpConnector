<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * prstudio_do — one verb in front of the whole surface.
 *
 * Codex does not expose a hundred named operations; it exposes a shell and lets
 * the model describe what it wants. That is not fewer capabilities, it is fewer
 * *names to choose between* — and choosing is where a model with a truncated
 * tool list fails.
 *
 * `prstudio_do` is that front door. Every one of the 111 typed tools, 1,378
 * capabilities and 1,076 catalog actions stays exactly where it was and remains
 * directly callable by name; nothing here removes or hides anything. This adds
 * a route for the common case: the caller states an intent in plain words plus
 * a target, and the router resolves it to the canonical tool with the canonical
 * arguments, then hands it to the normal dispatcher.
 *
 * The router is deliberately literal. It never guesses at a mutation: when the
 * intent is ambiguous it returns the candidates it considered and refuses to
 * pick, because a wrong guess that writes is far more expensive than a question.
 */
final class PRSTUDIO_UC_Do {
    public const VERSION = '17.0.0';

    /**
     * Direct intent synonyms. Order matters only for readability; matching is
     * exact-first, then longest-phrase, so "open tab" cannot be shadowed by
     * "open".
     */
    private static function intent_map(): array {
        return array(
            // Browser navigation and interaction.
            'open'        => array( 'tool' => 'browser_open', 'target_arg' => 'url' ),
            'open_tab'    => array( 'tool' => 'browser_open', 'target_arg' => 'url' ),
            'navigate'    => array( 'tool' => 'browser_navigate', 'target_arg' => 'url' ),
            'goto'        => array( 'tool' => 'browser_navigate', 'target_arg' => 'url' ),
            'visit'       => array( 'tool' => 'browser_navigate', 'target_arg' => 'url' ),
            'reload'      => array( 'tool' => 'browser_reload' ),
            'back'        => array( 'tool' => 'browser_back' ),
            'forward'     => array( 'tool' => 'browser_forward' ),
            'close_tab'   => array( 'tool' => 'browser_close' ),
            'click'       => array( 'tool' => 'browser_click', 'target_arg' => 'selector' ),
            'double_click'=> array( 'tool' => 'browser_double_click', 'target_arg' => 'selector' ),
            'hover'       => array( 'tool' => 'browser_hover', 'target_arg' => 'selector' ),
            'focus'       => array( 'tool' => 'browser_focus', 'target_arg' => 'selector' ),
            'fill'        => array( 'tool' => 'browser_fill', 'target_arg' => 'selector' ),
            'type'        => array( 'tool' => 'browser_type', 'target_arg' => 'selector' ),
            'press'       => array( 'tool' => 'browser_press', 'target_arg' => 'selector' ),
            'select'      => array( 'tool' => 'browser_select', 'target_arg' => 'selector' ),
            'check'       => array( 'tool' => 'browser_check', 'target_arg' => 'selector' ),
            'scroll'      => array( 'tool' => 'browser_scroll' ),
            'screenshot'  => array( 'tool' => 'browser_screenshot' ),
            'snapshot'    => array( 'tool' => 'browser_snapshot' ),
            'extract'     => array( 'tool' => 'browser_extract', 'target_arg' => 'selector' ),
            'wait'        => array( 'tool' => 'browser_wait' ),
            'tabs'        => array( 'tool' => 'browser_tabs' ),
            'adopt_tabs'  => array( 'tool' => 'browser_adopt_tabs' ),
            'console'     => array( 'tool' => 'browser_console' ),
            'network'     => array( 'tool' => 'browser_network' ),
            'page_errors' => array( 'tool' => 'browser_page_errors' ),
            'audit_page'  => array( 'tool' => 'browser_lighthouse' ),
            'vitals'      => array( 'tool' => 'browser_core_web_vitals' ),
            'accessibility' => array( 'tool' => 'browser_accessibility_scan' ),
            'pdf'         => array( 'tool' => 'browser_pdf' ),
            'crawl'       => array( 'tool' => 'browser_link_crawl', 'target_arg' => 'url' ),
            'crawl_sitemap' => array( 'tool' => 'browser_sitemap_crawl', 'target_arg' => 'url' ),
            'responsive'  => array( 'tool' => 'browser_responsive_matrix' ),
            'baseline'    => array( 'tool' => 'browser_capture_baseline' ),
            'compare_baseline' => array( 'tool' => 'browser_compare_baseline' ),

            // Reading and writing site content.
            'read'        => array( 'tool' => 'prstudio_observe' ),
            'observe'     => array( 'tool' => 'prstudio_observe' ),
            'inspect'     => array( 'tool' => 'prstudio_observe' ),
            'edit_content'=> array( 'tool' => 'wordpress_content_transaction' ),
            'replace_text'=> array( 'tool' => 'wordpress_content_transaction', 'defaults' => array( 'operation' => 'replace_exact' ) ),
            'append_text' => array( 'tool' => 'wordpress_content_transaction', 'defaults' => array( 'operation' => 'append_once' ) ),
            'insert_before' => array( 'tool' => 'wordpress_content_transaction', 'defaults' => array( 'operation' => 'insert_before' ) ),
            'insert_after'=> array( 'tool' => 'wordpress_content_transaction', 'defaults' => array( 'operation' => 'insert_after' ) ),

            // Search Console.
            'gsc_performance' => array( 'tool' => 'gsc_search_analytics' ),
            'gsc_inspect' => array( 'tool' => 'gsc_url_inspection', 'target_arg' => 'inspection_url' ),
            'request_indexing' => array( 'tool' => 'gsc_request_indexing', 'target_arg' => 'inspection_url' ),
            'gsc_sitemaps' => array( 'tool' => 'gsc_sitemaps' ),

            // Commerce, SEO, ranking.
            'product_audit' => array( 'tool' => 'commerce_product_audit' ),
            'keywords'    => array( 'tool' => 'serp_keywords' ),
            'rank_status' => array( 'tool' => 'serp_status' ),
            'watch_keyword' => array( 'tool' => 'serp_watch_create' ),

            // Orientation and durable work.
            'backlog'     => array( 'tool' => 'prstudio_backlog' ),
            'todo'        => array( 'tool' => 'prstudio_backlog' ),
            'health'      => array( 'tool' => 'prstudio_health' ),
            'status'      => array( 'tool' => 'prstudio_health' ),
            'job'         => array( 'tool' => 'prstudio_job_get' ),
            'memory'      => array( 'tool' => 'prstudio_memory_search' ),
            'repo_map'    => array( 'tool' => 'engineering_repo_map' ),
            'validate_code' => array( 'tool' => 'engineering_validate' ),
            'mission'     => array( 'tool' => 'agency_submit' ),
        );
    }

    /** Words that carry no routing information and only add noise to matching. */
    private static function noise(): array {
        return array(
            'the', 'a', 'an', 'to', 'on', 'in', 'of', 'for', 'this', 'that', 'please', 'now',
            'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'una', 'di', 'da', 'per', 'su', 'con', 'del', 'della',
            'mi', 'ti', 'si', 'e', 'ed', 'o', 'che', 'al', 'allo', 'alla',
        );
    }

    private static function normalize( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( function_exists( 'remove_accents' ) ) { $value = remove_accents( $value ); }
        $value = preg_replace( '/[^a-z0-9\s_]+/', ' ', $value );
        $value = preg_replace( '/\s+/', ' ', (string) $value );
        return trim( (string) $value );
    }

    /**
     * Resolves an intent to a concrete { tool, arguments } call.
     *
     * @return array|WP_Error
     */
    public static function resolve( array $args ) {
        $raw_intent = (string) ( $args['intent'] ?? '' );
        if ( '' === trim( $raw_intent ) ) {
            return new WP_Error(
                'prstudio_do_intent_required',
                'Describe what to do, e.g. intent="screenshot", intent="replace_text", intent="backlog".',
                array( 'status' => 400, 'examples' => array_slice( array_keys( self::intent_map() ), 0, 20 ) )
            );
        }

        $map = self::intent_map();
        $normalized = self::normalize( $raw_intent );
        $underscored = str_replace( ' ', '_', $normalized );

        $match = null;
        if ( isset( $map[ $underscored ] ) ) {
            $match = array( 'key' => $underscored, 'spec' => $map[ $underscored ], 'confidence' => 'exact' );
        }
        if ( ! $match ) {
            $tokens = array_values( array_diff( explode( ' ', $normalized ), self::noise() ) );
            $scored = array();
            foreach ( $map as $key => $spec ) {
                $key_tokens = explode( '_', $key );
                $overlap = count( array_intersect( $key_tokens, $tokens ) );
                if ( $overlap < 1 ) { continue; }
                // Longer intent keys that match fully rank above single-word hits.
                $scored[ $key ] = ( $overlap * 10 ) + ( $overlap === count( $key_tokens ) ? 5 : 0 ) - count( $key_tokens );
            }
            arsort( $scored, SORT_NUMERIC );
            $best = array_key_first( $scored );
            if ( null !== $best ) {
                $top_score = $scored[ $best ];
                $tied = array_keys( array_filter( $scored, static fn( $s ): bool => $s === $top_score ) );
                if ( count( $tied ) > 1 ) {
                    return self::ambiguous( $raw_intent, $tied, $map );
                }
                $match = array( 'key' => $best, 'spec' => $map[ $best ], 'confidence' => 'inferred' );
            }
        }

        if ( ! $match ) {
            // Falling through to capability search is better than failing: the
            // capability registry knows about operations this map does not.
            return array(
                'tool'      => 'prstudio_capability_search',
                'arguments' => array( 'query' => $raw_intent, 'limit' => 10 ),
                'routing'   => array(
                    'confidence' => 'fallback',
                    'note'       => 'No direct intent match. Searching the capability registry; run prstudio_execute with the chosen capability.',
                ),
            );
        }

        $spec = $match['spec'];
        $call_args = is_array( $args['params'] ?? null ) ? $args['params'] : array();
        if ( isset( $spec['defaults'] ) && is_array( $spec['defaults'] ) ) {
            $call_args = array_merge( $spec['defaults'], $call_args );
        }
        // A bare `target` is placed on the argument the chosen tool actually
        // expects, so a caller never has to know each tool's parameter names.
        $target = $args['target'] ?? null;
        if ( null !== $target && '' !== $target ) {
            $target_arg = (string) ( $spec['target_arg'] ?? '' );
            if ( '' !== $target_arg && ! isset( $call_args[ $target_arg ] ) ) {
                $call_args[ $target_arg ] = $target;
            } elseif ( '' === $target_arg && ! isset( $call_args['id'] ) && is_numeric( $target ) ) {
                $call_args['id'] = (int) $target;
            }
        }
        foreach ( array( 'lane_handle', 'lane_token', 'write_token', 'dry_run', 'tab_id', 'device_id' ) as $passthrough ) {
            if ( isset( $args[ $passthrough ] ) && ! isset( $call_args[ $passthrough ] ) ) {
                $call_args[ $passthrough ] = $args[ $passthrough ];
            }
        }

        return array(
            'tool'      => (string) $spec['tool'],
            'arguments' => $call_args,
            'routing'   => array(
                'intent'     => $raw_intent,
                'matched'    => $match['key'],
                'confidence' => $match['confidence'],
            ),
        );
    }

    private static function ambiguous( string $intent, array $candidates, array $map ) {
        $options = array();
        foreach ( array_slice( $candidates, 0, 6 ) as $key ) {
            $options[] = array( 'intent' => $key, 'tool' => (string) ( $map[ $key ]['tool'] ?? '' ) );
        }
        return new WP_Error(
            'prstudio_do_intent_ambiguous',
            'That intent matches several operations equally well. Pick one rather than letting the router guess at a mutation.',
            array(
                'status'     => 409,
                'intent'     => $intent,
                'candidates' => $options,
                'remedy'     => 'Repeat prstudio_do with one of the listed intent values, or call the tool directly by name.',
            )
        );
    }

    /** Machine-readable catalogue of what `prstudio_do` understands. */
    public static function catalogue(): array {
        $map = self::intent_map();
        $by_tool = array();
        foreach ( $map as $intent => $spec ) {
            $tool = (string) $spec['tool'];
            if ( ! isset( $by_tool[ $tool ] ) ) { $by_tool[ $tool ] = array(); }
            $by_tool[ $tool ][] = $intent;
        }
        return array(
            'intent_count' => count( $map ),
            'intents'      => array_keys( $map ),
            'by_tool'      => $by_tool,
            'note'         => 'Every typed tool remains directly callable by name. prstudio_do is an additional route, not a replacement.',
        );
    }
}
