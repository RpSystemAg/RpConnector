<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

final class PRSTUDIO_UC_Verification_Engine_V3 {
    private static function structured_failure( $result ): ?array {
        if ( ! is_array( $result ) ) { return null; }
        $status = strtolower( (string) ( $result['status'] ?? $result['task']['status'] ?? '' ) );
        $failed_status = in_array( $status, array( 'failed', 'error', 'technical_error', 'cancelled', 'expired', 'dead_letter' ), true );
        $explicit_false = array_key_exists( 'ok', $result ) && false === $result['ok'];
        $not_executed = array_key_exists( 'executed', $result ) && false === $result['executed'];
        $control = is_array( $result['_control_outcome'] ?? null ) ? $result['_control_outcome'] : array();
        $control_not_executed = array_key_exists( 'executed', $control ) && false === $control['executed'];
        $error = is_array( $result['error'] ?? null ) ? $result['error'] : array();
        if ( ! $failed_status && ! $explicit_false && ! $not_executed && ! $control_not_executed && empty( $error ) ) { return null; }
        return array(
            'code'=>(string) ( $error['code'] ?? ( $not_executed || $control_not_executed ? 'execution_not_observed' : 'executor_reported_failure' ) ),
            'status'=>$status,
        );
    }

    public static function verify( array $cap, array $args, $result ): array {
        if ( is_wp_error( $result ) ) {
            return array( 'ok'=>false, 'verifier'=>'pre_execution_error', 'source'=>'executor_error', 'independent'=>false, 'verified_at'=>gmdate( 'c' ) );
        }
        $failure = self::structured_failure( $result );
        if ( null !== $failure ) {
            return array( 'ok'=>false, 'verifier'=>'executor_failure_receipt', 'source'=>'executor_result', 'independent'=>false, 'reason'=>$failure['code'], 'executor_status'=>$failure['status'], 'verified_at'=>gmdate( 'c' ) );
        }

        $id = (string) ( $cap['id'] ?? '' );
        if ( ! empty( $cap['read_only'] ) ) {
            return array( 'ok'=>true, 'verifier'=>'read_receipt', 'source'=>(string) ( $result['source'] ?? $result['provider'] ?? 'executor' ), 'independent'=>false, 'verified_at'=>gmdate( 'c' ) );
        }

        if ( in_array( $id, array( 'commerce.product.update', 'commerce.inventory.update' ), true ) && class_exists( 'PRSTUDIO_UC_Commerce_Engine' ) ) {
            $after = PRSTUDIO_UC_Commerce_Engine::product_read( array( 'id'=>(int) ( $args['id'] ?? 0 ) ) );
            if ( is_wp_error( $after ) ) { return array( 'ok'=>false, 'verifier'=>'woocommerce_readback', 'source'=>'woocommerce_crud', 'independent'=>true, 'verified_at'=>gmdate( 'c' ) ); }
            $ok = true;
            if ( 'commerce.inventory.update' === $id ) {
                if ( array_key_exists( 'stock_quantity', $args ) && $after['stock_quantity'] !== (int) $args['stock_quantity'] ) { $ok = false; }
                if ( array_key_exists( 'stock_status', $args ) && (string) $after['stock_status'] !== (string) $args['stock_status'] ) { $ok = false; }
            }
            if ( 'commerce.product.update' === $id ) {
                $unverifiable = array();
                foreach ( (array) ( $args['changes'] ?? array() ) as $k=>$v ) {
                    if ( 'seo' === $k ) {
                        if ( ! is_array( $v ) || ! function_exists( 'get_post_meta' ) ) { continue; }
                        $seo_after = is_array( $after['seo'] ?? null ) ? $after['seo'] : array();
                        foreach ( $v as $sk=>$sv ) {
                            if ( ! array_key_exists( $sk, $seo_after ) ) { $unverifiable[] = 'seo.' . $sk; continue; }
                            if ( (string) $seo_after[$sk] !== (string) $sv ) { $ok = false; }
                        }
                        continue;
                    }
                    if ( ! array_key_exists( $k, $after ) ) { $unverifiable[] = $k; continue; }
                    if ( is_bool( $v ) || is_bool( $after[$k] ?? null ) ) {
                        if ( (bool) $after[$k] !== (bool) $v ) { $ok = false; }
                        continue;
                    }
                    if ( (string) $after[$k] !== (string) $v ) { $ok = false; }
                }
                if ( $unverifiable ) { $ok = false; }
            }
            $payload = is_array( $result['result'] ?? null ) ? $result['result'] : ( is_array( $result ) ? $result : array() );
            $binding = is_array( $payload['state_binding'] ?? null ) ? $payload['state_binding'] : array();
            $superseded = array_key_exists( 'matched', $binding ) && false === $binding['matched'];
            if ( $superseded ) { $ok = false; }
            return array( 'ok'=>$ok, 'verifier'=>'woocommerce_independent_readback', 'source'=>'woocommerce_crud_plus_wordpress_meta', 'independent'=>true, 'planned_state_bound'=>! empty( $binding['bound'] ), 'planned_state_superseded'=>$superseded, 'evidence_hash'=>hash( 'sha256', json_encode( $after ) ), 'verified_at'=>gmdate( 'c' ) );
        }

        if ( 'content.transaction.patch' === $id ) {
            $payload = is_array( $result['result'] ?? null ) ? $result['result'] : $result;
            $public_requested = ! empty( $args['public_verify'] );
            $ok = ! empty( $payload['db_verified'] ) && ( ! $public_requested || ! empty( $payload['frontend_verified'] ) );
            $verifier = $public_requested ? 'wordpress_db_hash_plus_public_render' : 'wordpress_db_hash_readback';
            $source = $public_requested ? 'wordpress_database_plus_public_http' : 'wordpress_database';
            return array( 'ok'=>$ok, 'verifier'=>$verifier, 'source'=>$source, 'independent'=>true, 'verification_scope'=>$public_requested ? 'database_plus_public' : 'database_only', 'public_effect_verified'=>$public_requested ? ! empty( $payload['frontend_verified'] ) : false, 'evidence_hash'=>hash( 'sha256', json_encode( array( 'after_sha256'=>$payload['after_sha256'] ?? '', 'db_verified'=>$payload['db_verified'] ?? false, 'frontend_verified'=>$payload['frontend_verified'] ?? null, 'public_requested'=>$public_requested ) ) ), 'verified_at'=>gmdate( 'c' ) );
        }

        if ( 'content.publish.transaction' === $id ) {
            $payload = is_array( $result['result'] ?? null ) ? $result['result'] : $result;
            $receipt = is_array( $payload['receipt'] ?? null ) ? $payload['receipt'] : array();
            $ok = ! empty( $payload['fully_verified'] ) && ! empty( $receipt['db_verified'] ) && ! empty( $receipt['render_verified'] ) && ! empty( $receipt['sitemap_verified'] );
            return array( 'ok'=>$ok, 'verifier'=>'wordpress_publish_db_public_sitemap', 'source'=>'wordpress_database_plus_public_http_plus_sitemap', 'independent'=>true, 'verification_scope'=>'published_effect', 'public_effect_verified'=>! empty( $receipt['render_verified'] ), 'evidence_hash'=>hash( 'sha256', json_encode( $receipt ) ), 'verified_at'=>gmdate( 'c' ) );
        }

        if ( in_array( $id, array( 'seo.campaign.manager', 'seo.keyword_url.registry', 'seo.serp_intent.observe', 'content.brief.compile', 'content.claim.ledger', 'seo.cannibalization.resolver', 'seo.post_publish.watcher', 'media.editorial.pipeline', 'directory.entity.engine', 'authority.outreach.engine' ), true ) ) {
            $payload = is_array( $result['result'] ?? null ) ? $result['result'] : $result;
            $ok = ! empty( $payload['ok'] );
            return array( 'ok'=>$ok, 'verifier'=>'persistent_state_readback_receipt', 'source'=>'wordpress_private_state', 'independent'=>false, 'evidence_hash'=>hash( 'sha256', json_encode( $payload ) ), 'verified_at'=>gmdate( 'c' ) );
        }

        if ( 'seo.gsc.request_indexing' === $id ) {
            $payload = is_array( $result['result'] ?? null ) ? $result['result'] : $result;
            $indexing = is_array( $payload['indexingRequest'] ?? null ) ? $payload['indexingRequest'] : ( is_array( $payload['result']['indexingRequest'] ?? null ) ? $payload['result']['indexingRequest'] : array() );
            $ok = ! empty( $indexing['verified'] );
            return array( 'ok'=>$ok, 'verifier'=>'gsc_visible_request_confirmation', 'source'=>'browser_agent_search_console_ui', 'independent'=>true, 'reason'=>$ok ? '' : 'gsc_indexing_confirmation_not_observed', 'verified_at'=>gmdate( 'c' ) );
        }

        if ( str_starts_with( $id, 'database.' ) ) {
            $control = is_array( $result['_control_outcome'] ?? null ) ? $result['_control_outcome'] : array();
            $executed = ! array_key_exists( 'executed', $control ) || true === $control['executed'];
            $ok = $executed && ( ! empty( $result['verified'] ) || ! empty( $control['verified'] ) );
            return array( 'ok'=>$ok, 'verifier'=>'database_backend_receipt', 'source'=>'database_backend', 'independent'=>false, 'verified_at'=>gmdate( 'c' ) );
        }
        if ( 'rollback.job' === $id ) {
            return array( 'ok'=>! empty( $result['verified'] ), 'verifier'=>'rollback_receipt', 'source'=>'snapshot_engine', 'independent'=>true, 'verified_at'=>gmdate( 'c' ) );
        }

        $control = is_array( $result['_control_outcome'] ?? null ) ? $result['_control_outcome'] : array();
        $executed = ! array_key_exists( 'executed', $control ) || true === $control['executed'];
        $ok = $executed && ( ! empty( $control['verified'] ) || ! empty( $result['verified'] ) );
        return array( 'ok'=>$ok, 'verifier'=>'legacy_verification_receipt', 'source'=>'internal_executor', 'independent'=>false, 'verified_at'=>gmdate( 'c' ) );
    }
}
