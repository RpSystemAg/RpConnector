<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * WordPress 6.9+ Abilities API bridge.
 *
 * Deliberately read-only and hidden from wp-abilities REST. MCP remains the
 * authoritative remote execution surface; this bridge lets WordPress-native
 * AI/tooling discover PR STUDIO without creating a parallel mutation path.
 */
final class PRSTUDIO_UC_Abilities {
    public const VERSION = '1.0.0';
    private const CATEGORY = 'pr-studio-observability';

    public static function register_category(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) { return; }
        wp_register_ability_category( self::CATEGORY, array(
            'label' => __( 'PR STUDIO Observability', 'prstudio-unified-control' ),
            'description' => __( 'Read-only PR STUDIO status and capability discovery.', 'prstudio-unified-control' ),
        ) );
    }

    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }
        $meta = array(
            'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            'show_in_rest' => false,
        );
        wp_register_ability( 'pr-studio/status', array(
            'label' => __( 'PR STUDIO status', 'prstudio-unified-control' ),
            'description' => __( 'Returns compact, read-only runtime and registry status.', 'prstudio-unified-control' ),
            'category' => self::CATEGORY,
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'meta' => $meta,
        ) );
        wp_register_ability( 'pr-studio/search-capabilities', array(
            'label' => __( 'Search PR STUDIO capabilities', 'prstudio-unified-control' ),
            'description' => __( 'Searches the compact PR STUDIO capability registry without executing a mutation.', 'prstudio-unified-control' ),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 240 ),
                    'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 25 ),
                ),
                'required' => array( 'query' ),
                'additionalProperties' => false,
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'execute_callback' => array( __CLASS__, 'search_capabilities' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'meta' => $meta,
        ) );
    }

    public static function can_read( $input = null ): bool {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }

    public static function status(): array {
        return array(
            'product' => 'PR STUDIO Unified Control Plane',
            'version' => defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : self::VERSION,
            'registry_hash' => class_exists( 'PRSTUDIO_UC_Capability_Registry' ) ? PRSTUDIO_UC_Capability_Registry::hash() : '',
            'capability_counts' => class_exists( 'PRSTUDIO_UC_Capability_Registry' ) ? PRSTUDIO_UC_Capability_Registry::counts() : array(),
            'mutation_surface' => 'mcp_only',
            'rest_exposed' => false,
        );
    }

    public static function search_capabilities( $input ): array {
        $input = is_array( $input ) ? $input : array();
        $query = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
        $limit = max( 1, min( 25, (int) ( $input['limit'] ?? 12 ) ) );
        return PRSTUDIO_UC_Capability_Registry::search( $query, array( 'limit' => $limit, 'include_legacy' => true ) );
    }
}
