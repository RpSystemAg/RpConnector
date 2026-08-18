<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Universal execution routing metadata.
 *
 * This class is deliberately not a guard. It only chooses the shortest executor
 * lane for work that has already been understood by the caller/model. The
 * existing anti-crash authority remains the only blocking pre-mutation gate.
 */
final class PRSTUDIO_UC_Execution_Router {
    public const VERSION = '1.0.0';
    private const CLASSES = array( 'read_fast', 'write_fast', 'bulk', 'agentic', 'deferred' );
    private const EXECUTORS = array( 'mysql', 'wordpress', 'php', 'filesystem', 'cli', 'browser', 'external' );

    private static function s( $value ): string { return strtolower( trim( (string) $value ) ); }

    private static function agentic_id( string $id, array $cap ): bool {
        $source = is_array( $cap['source'] ?? null ) ? $cap['source'] : array();
        $executor = self::s( $cap['executor'] ?? '' );
        if ( str_starts_with( $id, 'agency.playbook' ) || str_starts_with( $id, 'agency.run' ) || str_starts_with( $id, 'mission.' ) ) { return true; }
        if ( str_contains( $executor, 'agency_runtime::submit' ) || str_contains( $executor, 'mission_engine::create' ) ) { return true; }
        if ( 'agentic' === self::s( $source['execution_class'] ?? '' ) ) { return true; }
        return false;
    }

    private static function deferred_id( string $id, array $cap ): bool {
        $source = is_array( $cap['source'] ?? null ) ? $cap['source'] : array();
        $executor = self::s( $cap['executor'] ?? '' );
        if ( 'deferred' === self::s( $source['execution_class'] ?? '' ) ) { return true; }
        if ( str_contains( $executor, '::enqueue' ) || str_contains( $executor, '::queue' ) ) { return true; }
        // Creating/updating a schedule is an immediate operation; only executors
        // whose result itself is deferred are routed here.
        if ( str_contains( $id, '.deferred.execute' ) || str_ends_with( $id, '.enqueue' ) ) { return true; }
        return false;
    }

    public static function preferred_executor( array $cap ): string {
        $id = self::s( $cap['id'] ?? '' );
        $domain = self::s( $cap['domain'] ?? '' );
        $executor = self::s( $cap['executor'] ?? '' );
        $source = is_array( $cap['source'] ?? null ) ? $cap['source'] : array();
        $route = self::s( $source['route'] ?? '' );
        $tool = self::s( $source['tool_name'] ?? '' );

        if ( ! empty( $cap['browser_required'] ) || 'browser' === $domain || '/frontend-manage' === $route || str_contains( $executor, 'browser' ) ) { return 'browser'; }
        if ( 'database' === $domain || '/database-manage' === $route || str_contains( $executor, 'database_backend' ) || str_contains( $executor, 'database_engine' ) || str_starts_with( $id, 'database.' ) ) { return 'mysql'; }
        if ( 'files' === $domain || '/files-manage' === $route || str_contains( $executor, 'file_engine' ) || str_contains( $executor, 'wpaib_files' ) || preg_match( '/(^|[._-])(file|filesystem)([._-]|$)/', $id ) ) { return 'filesystem'; }
        if ( str_contains( $executor, 'wpcli' ) || str_contains( $tool, 'wpcli' ) || str_contains( $executor, 'pandoc' ) || str_contains( $executor, 'sidecar' ) ) { return 'cli'; }
        if ( ! empty( $cap['gsc_required'] ) || in_array( $domain, array( 'analytics', 'social' ), true ) && ( ! empty( $cap['browser_required'] ) || str_contains( $executor, 'provider' ) ) ) { return 'external'; }
        if ( in_array( $domain, array( 'content_seo', 'catalog_commerce', 'orders_customers', 'experience_ui', 'extensions_themes', 'media_stories', 'security_identity', 'commerce', 'content', 'seo' ), true ) ) { return 'wordpress'; }
        if ( str_contains( $executor, 'wpaib_site' ) || str_contains( $executor, 'wpaib_enterprise' ) || str_contains( $executor, 'commerce_engine' ) ) { return 'wordpress'; }
        return 'php';
    }

    public static function execution_class( array $cap ): string {
        $id = self::s( $cap['id'] ?? '' );
        if ( self::agentic_id( $id, $cap ) ) { return 'agentic'; }
        if ( self::deferred_id( $id, $cap ) ) { return 'deferred'; }
        if ( ! empty( $cap['read_only'] ) ) { return 'read_fast'; }

        $cost = self::s( $cap['estimated_cost'] ?? 'low' );
        $executor = self::preferred_executor( $cap );
        $source = is_array( $cap['source'] ?? null ) ? $cap['source'] : array();
        if ( 'bulk' === self::s( $source['execution_class'] ?? '' ) ) { return 'bulk'; }
        if ( in_array( $cost, array( 'high', 'very_high' ), true ) && in_array( $executor, array( 'mysql', 'filesystem', 'wordpress', 'cli' ), true ) ) { return 'bulk'; }
        if ( preg_match( '/(?:bulk|batch|cleanup|optimi[sz]e|import|export|search_replace|reindex|migrate)/', $id ) ) { return 'bulk'; }
        return 'write_fast';
    }

    public static function verification_strategy( array $cap ): string {
        if ( ! empty( $cap['read_only'] ) ) { return 'none'; }
        $executor = self::preferred_executor( $cap );
        if ( 'mysql' === $executor ) { return 'affected_rows_or_readback'; }
        if ( 'filesystem' === $executor ) { return 'executor_readback_or_lint'; }
        if ( 'browser' === $executor ) { return 'executor_state'; }
        if ( 'wordpress' === $executor ) { return 'executor_readback'; }
        return 'executor_result';
    }

    public static function annotate_capability( array $cap ): array {
        $class = self::execution_class( $cap );
        $preferred = self::preferred_executor( $cap );
        $cap['execution_class'] = in_array( $class, self::CLASSES, true ) ? $class : ( ! empty( $cap['read_only'] ) ? 'read_fast' : 'write_fast' );
        $cap['preferred_executor'] = in_array( $preferred, self::EXECUTORS, true ) ? $preferred : 'php';
        $cap['estimated_work'] = self::s( $cap['estimated_cost'] ?? 'low' ) ?: 'low';
        $cap['supports_flow'] = ! in_array( $cap['execution_class'], array( 'agentic', 'deferred' ), true );
        $cap['can_execute_inline'] = $cap['supports_flow'];
        $cap['minimal_verification'] = self::verification_strategy( $cap );
        return $cap;
    }

    public static function can_inline_capability( array $cap ): bool {
        $cap = self::annotate_capability( $cap );
        return ! empty( $cap['can_execute_inline'] );
    }

    public static function tool_contract( string $name, array $annotations = array() ): array {
        $name = self::s( $name );
        $read = ! empty( $annotations['readOnlyHint'] );
        $preferred = 'php';
        if ( str_starts_with( $name, 'browser_' ) || 'local_studio' === $name || str_contains( $name, 'playwright' ) ) { $preferred = 'browser'; }
        elseif ( str_contains( $name, 'database' ) || str_starts_with( $name, 'db_' ) ) { $preferred = 'mysql'; }
        elseif ( preg_match( '/(?:file|filesystem|theme|plugin)/', $name ) ) { $preferred = 'filesystem'; }
        elseif ( preg_match( '/(?:wpcli|pandoc|sidecar|lint)/', $name ) ) { $preferred = 'cli'; }
        elseif ( preg_match( '/(?:gsc|search_console|analytics|social)/', $name ) ) { $preferred = 'external'; }
        elseif ( preg_match( '/(?:content|post|option|meta|term|taxonomy|product|order|media|seo|cache|cron)/', $name ) ) { $preferred = 'wordpress'; }

        $class = $read ? 'read_fast' : 'write_fast';
        if ( preg_match( '/(?:bulk|batch|cleanup|optimi[sz]e|import|export|search_replace|migrate)/', $name ) ) { $class = 'bulk'; }
        if ( preg_match( '/(?:agency_run|mission_create|playbook_run)/', $name ) ) { $class = 'agentic'; }
        return array(
            'execution_class' => $class,
            'preferred_executor' => $preferred,
            'estimated_work' => preg_match( '/(?:bulk|batch|cleanup|optimi[sz]e|import|export|migrate)/', $name ) ? 'high' : 'low',
            'supports_flow' => ! in_array( $class, array( 'agentic', 'deferred' ), true ),
            'can_execute_inline' => ! in_array( $class, array( 'agentic', 'deferred' ), true ),
            'minimal_verification' => $read ? 'none' : ( 'mysql' === $preferred ? 'affected_rows_or_readback' : 'executor_result' ),
        );
    }

    public static function legacy_tool_can_inline( string $name, bool $read_only = false ): bool {
        $contract = self::tool_contract( $name, array( 'readOnlyHint' => $read_only ) );
        return ! empty( $contract['can_execute_inline'] );
    }
}
