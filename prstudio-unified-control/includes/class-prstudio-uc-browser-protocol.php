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
            return array(
                'compatible' => $runtime_valid && $runtime_count > 0 && $catalog_valid && ! $catalog_exposed,
                'mode' => 'stable_executor_wire',
                'executor_protocol' => $executor,
                'preferred_executor_protocol' => self::EXECUTOR_PROTOCOL,
                'accepted_executor_protocols' => self::ACCEPTED_EXECUTOR_PROTOCOLS,
                'runtime_operation_count' => $runtime_count,
                'wordpress_capability_catalog' => $catalog_exposed,
                'pairing_compatible' => true,
                'repair_required' => false,
                'wire_protocol_stable' => true,
                'upgrade_recommended' => false,
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
