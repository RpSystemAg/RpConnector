<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/**
 * Stable Browser wire protocol. Product/Agent versions may evolve without forcing
 * a re-pair or a synchronized plugin+extension upgrade. 4.0.0 is accepted only
 * as a compatibility bridge for the already-issued broken release.
 */
final class PRSTUDIO_UC_Browser_Protocol {
    public const EXECUTOR_PROTOCOL = '3.0.0';
    public const ACCEPTED_EXECUTOR_PROTOCOLS = array('3.0.0','4.0.0');
    public const REQUIRED_AGENT_PRODUCT_VERSION = '1.0.0';
    public const REQUIRED_GSC_DIMENSION_SESSION_VERSION = '4.0.0';
    public const REQUIRED_CAPABILITY_CONTRACT_SHA256 = '1358fb18e4e3b36cefbd4c0aca4f5a061f07b896f089f8a1962567c07b1157c5';

    private static function nonnegative_int( $value ): ?int {
        if ( is_int( $value ) ) {
            return max( 0, $value );
        }
        if ( is_float( $value ) && is_finite( $value ) && $value >= 0 && floor( $value ) === $value && $value <= PHP_INT_MAX ) {
            return (int) $value;
        }
        if ( is_string( $value ) ) {
            $value = trim( $value );
            $parsed = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
            return false === $parsed ? null : (int) $parsed;
        }
        return null;
    }

    private static function boolean_flag( $value ): ?bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( 0 === $value || '0' === $value ) {
            return false;
        }
        if ( 1 === $value || '1' === $value ) {
            return true;
        }
        if ( is_string( $value ) ) {
            $value = strtolower( trim( $value ) );
            if ( 'false' === $value ) { return false; }
            if ( 'true' === $value ) { return true; }
        }
        return null;
    }

    private static function bound_build_identity( array $capabilities ): array {
        $build = sanitize_text_field( (string) ( $capabilities['agentBuild'] ?? $capabilities['agent_build'] ?? '' ) );
        $timestamp = sanitize_text_field( (string) ( $capabilities['buildTimestamp'] ?? $capabilities['build_timestamp'] ?? '' ) );
        $suite = sanitize_text_field( (string) ( $capabilities['suiteVersion'] ?? $capabilities['suite_version'] ?? $capabilities['componentVersion'] ?? '' ) );
        $build_ok = 1 === preg_match( '/^prstudio-browser-1\.0\.0\+git\.[0-9a-f]{12}$/', $build );
        $timestamp_ok = '' !== $timestamp && 'UNSTAMPED' !== strtoupper( $timestamp ) && false !== strtotime( $timestamp );
        $suite_ok = hash_equals( self::REQUIRED_AGENT_PRODUCT_VERSION, $suite );
        return array(
            'ok' => $build_ok && $timestamp_ok && $suite_ok,
            'build_id' => $build,
            'build_id_bound' => $build_ok,
            'build_timestamp' => $timestamp,
            'build_timestamp_bound' => $timestamp_ok,
            'suite_version' => $suite,
            'suite_version_match' => $suite_ok,
        );
    }

    public static function compatibility( array $capabilities ): array {
        $executor_value = $capabilities['executorProtocolVersion'] ?? $capabilities['executor_protocol_version'] ?? '';
        $executor = is_string( $executor_value ) ? sanitize_text_field( $executor_value ) : '';
        if ( in_array($executor, self::ACCEPTED_EXECUTOR_PROTOCOLS, true) ) {
            $runtime_value = $capabilities['runtimeOperationCount'] ?? $capabilities['runtime_operation_count'] ?? 0;
            $runtime_count = self::nonnegative_int( $runtime_value );
            $runtime_valid = null !== $runtime_count;
            if ( ! $runtime_valid ) { $runtime_count = 0; }
            $catalog_present = array_key_exists( 'wordpressCapabilityCatalog', $capabilities ) || array_key_exists( 'wordpress_capability_catalog', $capabilities );
            $catalog_value = $capabilities['wordpressCapabilityCatalog'] ?? $capabilities['wordpress_capability_catalog'] ?? false;
            $catalog_exposed = self::boolean_flag( $catalog_value );
            $catalog_valid = ! $catalog_present || null !== $catalog_exposed;
            if ( null === $catalog_exposed ) { $catalog_exposed = false; }

            $capability_hash = strtolower( sanitize_text_field( (string) ( $capabilities['capabilityHash'] ?? $capabilities['capability_hash'] ?? '' ) ) );
            $capability_hash_match = '' !== $capability_hash && hash_equals( self::REQUIRED_CAPABILITY_CONTRACT_SHA256, $capability_hash );
            $gsc_session = sanitize_text_field( (string) ( $capabilities['gscDimensionSessionVersion'] ?? $capabilities['gsc_dimension_session_version'] ?? '' ) );
            $gsc_session_match = '' !== $gsc_session && hash_equals( self::REQUIRED_GSC_DIMENSION_SESSION_VERSION, $gsc_session );
            $identity = self::bound_build_identity( $capabilities );

            $compatible = $runtime_valid
                && $runtime_count > 0
                && $catalog_valid
                && ! $catalog_exposed
                && $capability_hash_match
                && $gsc_session_match
                && ! empty( $identity['ok'] );
            return array(
                'compatible' => $compatible,
                'mode' => 'stable_executor_wire',
                'executor_protocol' => $executor,
                'preferred_executor_protocol' => self::EXECUTOR_PROTOCOL,
                'accepted_executor_protocols' => self::ACCEPTED_EXECUTOR_PROTOCOLS,
                'runtime_operation_count' => $runtime_count,
                'wordpress_capability_catalog' => $catalog_exposed,
                'capability_hash_match' => $capability_hash_match,
                'expected_capability_hash' => self::REQUIRED_CAPABILITY_CONTRACT_SHA256,
                'received_capability_hash' => $capability_hash,
                'gsc_dimension_session_match' => $gsc_session_match,
                'expected_gsc_dimension_session_version' => self::REQUIRED_GSC_DIMENSION_SESSION_VERSION,
                'received_gsc_dimension_session_version' => $gsc_session,
                'build_identity' => $identity,
                'pairing_compatible' => $compatible,
                'repair_required' => ! $compatible,
                'wire_protocol_stable' => true,
                'upgrade_recommended' => ! $compatible,
            );
        }
        $legacy = PRSTUDIO_UC_Contract::extension_compatibility( $capabilities );
        $legacy['mode'] = 'legacy_contract_2';
        $legacy['pairing_compatible'] = ! empty( $legacy['compatible'] );
        $legacy['preferred_executor_protocol'] = self::EXECUTOR_PROTOCOL;
        $legacy['accepted_executor_protocols'] = self::ACCEPTED_EXECUTOR_PROTOCOLS;
        $legacy['wire_protocol_stable'] = true;
        return $legacy;
    }

    public static function negotiated_protocol(array $capabilities): string {
        $compat = self::compatibility($capabilities);
        return !empty($compat['compatible']) && in_array((string)($compat['executor_protocol'] ?? ''), self::ACCEPTED_EXECUTOR_PROTOCOLS, true)
            ? (string)$compat['executor_protocol']
            : self::EXECUTOR_PROTOCOL;
    }
}
