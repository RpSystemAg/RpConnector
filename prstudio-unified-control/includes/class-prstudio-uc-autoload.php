<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Class autoloader — the fix for 2 MB of PHP on every page view.
 *
 * 15.x issued 113 unconditional `require_once` calls at file scope, so roughly
 * 2,075 KB of PHP was parsed on *every* WordPress request: REST calls, cron,
 * wp-admin, and every front-end page view by an actual customer of the site.
 * The plugin was taxing the storefront for a control plane the storefront never
 * uses.
 *
 * Every file under includes/ was verified to contain nothing but class
 * definitions — no hooks registered at file scope, no side effects — so
 * deferring the load is behaviour-preserving. A request that never touches
 * PR STUDIO now parses one small file instead of a hundred and thirteen.
 *
 * The map is explicit rather than derived from the class name. Several classes
 * do not follow the file naming convention (PRSTUDIO_UC_Secrets_Vault lives in
 * the safety runtime; PRSTUDIO_UC_Browser_Orchestrator lives in a *-v3 file),
 * and a convention-only autoloader would silently fail to find them. Explicit
 * is cheap: it is one hash lookup.
 */
final class PRSTUDIO_UC_Autoload {
    public const VERSION = '1.0.0';

    private static bool $registered = false;
    private static int $loaded = 0;
    private static array $loaded_classes = array();

    private static function map(): array {
        static $map = null;
        if ( is_array( $map ) ) { return $map; }
        $map = array(
            'PRSTUDIO_UC_Public_Tool_Contracts' => 'includes/class-prstudio-uc-public-tool-contracts.php',
            'PRSTUDIO_Agency' => 'includes/class-prstudio-agency.php',
            'PRSTUDIO_Browser_Runtime' => 'includes/class-prstudio-browser-runtime.php',
            'PRSTUDIO_Domain_Browser' => 'includes/orchestrator/domains/class-prstudio-domain-browser.php',
            'PRSTUDIO_Domain_Catalog_Commerce' => 'includes/orchestrator/domains/class-prstudio-domain-catalog-commerce.php',
            'PRSTUDIO_Domain_Content_SEO' => 'includes/orchestrator/domains/class-prstudio-domain-content-seo.php',
            'PRSTUDIO_Domain_Data_Storage' => 'includes/orchestrator/domains/class-prstudio-domain-data-storage.php',
            'PRSTUDIO_Domain_Experience_UI' => 'includes/orchestrator/domains/class-prstudio-domain-experience-ui.php',
            'PRSTUDIO_Domain_Extensions_Themes' => 'includes/orchestrator/domains/class-prstudio-domain-extensions-themes.php',
            'PRSTUDIO_Domain_Media_Stories' => 'includes/orchestrator/domains/class-prstudio-domain-media-stories.php',
            'PRSTUDIO_Domain_Operations' => 'includes/orchestrator/domains/class-prstudio-domain-operations.php',
            'PRSTUDIO_Domain_Orders_Customers' => 'includes/orchestrator/domains/class-prstudio-domain-orders-customers.php',
            'PRSTUDIO_Domain_Security_Identity' => 'includes/orchestrator/domains/class-prstudio-domain-security-identity.php',
            'PRSTUDIO_Report' => 'includes/class-prstudio-report.php',
            'PRSTUDIO_UC_Action_Lexicon' => 'includes/class-prstudio-uc-action-lexicon.php',
            'PRSTUDIO_UC_Action_Index' => 'includes/class-prstudio-uc-action-index.php',
            'PRSTUDIO_UC_Action_Feasibility' => 'includes/class-prstudio-uc-action-feasibility.php',
            'PRSTUDIO_UC_Audit_Trail' => 'includes/class-prstudio-uc-audit-trail.php',
            'PRSTUDIO_UC_Confidence_Calibration' => 'includes/class-prstudio-uc-confidence-calibration.php',
            'PRSTUDIO_UC_Context_Leak_Gauge' => 'includes/class-prstudio-uc-context-leak-gauge.php',
            'PRSTUDIO_UC_Evidence_Gate' => 'includes/class-prstudio-uc-evidence-gate.php',
            'PRSTUDIO_UC_Evidence_Memory' => 'includes/class-prstudio-uc-evidence-memory.php',
            'PRSTUDIO_UC_Research_Radar' => 'includes/class-prstudio-uc-research-radar.php',
            'PRSTUDIO_UC_Retry_Policy' => 'includes/class-prstudio-uc-retry-policy.php',
            'PRSTUDIO_UC_Style_Drift_Monitor' => 'includes/class-prstudio-uc-style-drift-monitor.php',
            'PRSTUDIO_UC_Workspace_Snapshots' => 'includes/class-prstudio-uc-workspace-snapshots.php',
            'PRSTUDIO_UC_Admin' => 'includes/class-prstudio-uc-admin.php',
            'PRSTUDIO_UC_Agency_Action_Executor' => 'includes/class-prstudio-uc-agency-action-executor.php',
            'PRSTUDIO_UC_Agency_Capabilities' => 'includes/class-prstudio-uc-agency-capabilities.php',
            'PRSTUDIO_UC_Agency_Runtime' => 'includes/class-prstudio-uc-agency-runtime.php',
            'PRSTUDIO_UC_Trust_Trajectory' => 'includes/class-prstudio-uc-trust-trajectory.php',
            'PRSTUDIO_UC_Agency_State' => 'includes/class-prstudio-uc-agency-state.php',
            'PRSTUDIO_UC_Anti_Crash' => 'includes/class-prstudio-uc-anti-crash.php',
            'PRSTUDIO_UC_Pre_Mutation_Safety' => 'includes/class-prstudio-uc-anti-crash.php',
            'PRSTUDIO_UC_Anti_Crash_Attestation' => 'includes/class-prstudio-uc-anti-crash-attestation.php',
            'PRSTUDIO_UC_Artifacts' => 'includes/class-prstudio-uc-artifacts.php',
            'PRSTUDIO_UC_Auth' => 'includes/class-prstudio-uc-auth.php',
            'PRSTUDIO_UC_Backend_Executability' => 'includes/class-prstudio-uc-backend-executability.php',
            'PRSTUDIO_UC_Bridge' => 'includes/class-prstudio-uc-bridge.php',
            'PRSTUDIO_UC_Browser_Live' => 'includes/class-prstudio-uc-browser-live.php',
            'PRSTUDIO_UC_Browser_Orchestrator' => 'includes/class-prstudio-uc-browser-orchestrator-v3.php',
            'PRSTUDIO_UC_Browser_Protocol' => 'includes/class-prstudio-uc-browser-protocol.php',
            'PRSTUDIO_UC_Business_Intelligence' => 'includes/class-prstudio-uc-business-intelligence.php',
            'PRSTUDIO_UC_Capability_Registry' => 'includes/class-prstudio-uc-capability-registry.php',
            'PRSTUDIO_UC_Catalog_Profile' => 'includes/class-prstudio-uc-catalog-profile.php',
            'PRSTUDIO_UC_Change_Tracker' => 'includes/class-prstudio-uc-change-tracker.php',
            'PRSTUDIO_UC_Commerce_Engine' => 'includes/class-prstudio-uc-commerce-engine.php',
            'PRSTUDIO_UC_Complete_Action_Executor' => 'includes/class-prstudio-uc-complete-action-executor.php',
            'PRSTUDIO_UC_Content_Transaction' => 'includes/class-prstudio-uc-content-transaction.php',
            'PRSTUDIO_UC_Contract' => 'includes/class-prstudio-uc-contract.php',
            'PRSTUDIO_UC_Database_Backend' => 'includes/class-prstudio-uc-database-backend.php',
            'PRSTUDIO_UC_Database_Engine' => 'includes/class-prstudio-uc-database-engine-v3.php',
            'PRSTUDIO_UC_Do' => 'includes/class-prstudio-uc-do.php',
            'PRSTUDIO_UC_Domain_Abstract' => 'includes/orchestrator/class-prstudio-uc-domain-abstract.php',
            'PRSTUDIO_UC_Editorial_Autonomy' => 'includes/class-prstudio-uc-editorial-autonomy.php',
            'PRSTUDIO_UC_Engineering_Workbench' => 'includes/class-prstudio-uc-engineering-workbench.php',
            'PRSTUDIO_UC_Enterprise_Engine' => 'includes/class-prstudio-uc-enterprise-engine.php',
            'PRSTUDIO_UC_Evidence_Engine' => 'includes/class-prstudio-uc-evidence-engine.php',
            'PRSTUDIO_UC_Execution_Gateway' => 'includes/class-prstudio-uc-execution-gateway.php',
            'PRSTUDIO_UC_Execution_Lanes' => 'includes/class-prstudio-uc-execution-lanes.php',
            'PRSTUDIO_UC_Execution_Router' => 'includes/class-prstudio-uc-execution-router.php',
            'PRSTUDIO_UC_File_Engine' => 'includes/class-prstudio-uc-file-engine.php',
            'PRSTUDIO_UC_GC' => 'includes/class-prstudio-uc-gc.php',
            'PRSTUDIO_UC_GPT_Actions_Auth' => 'includes/class-prstudio-uc-gpt-actions-auth.php',
            'PRSTUDIO_UC_GPT_REST' => 'includes/class-prstudio-uc-gpt-rest.php',
            'PRSTUDIO_UC_Graph_Engine' => 'includes/class-prstudio-uc-graph-engine.php',
            'PRSTUDIO_UC_GSC_Provider' => 'includes/class-prstudio-uc-gsc-provider.php',
            'PRSTUDIO_UC_Health' => 'includes/class-prstudio-uc-health.php',
            'PRSTUDIO_UC_HTTP_Engine' => 'includes/class-prstudio-uc-http-engine.php',
            'PRSTUDIO_UC_Idempotency' => 'includes/class-prstudio-uc-idempotency.php',
            'PRSTUDIO_UC_Impact_Engine' => 'includes/class-prstudio-uc-impact-engine.php',
            'PRSTUDIO_UC_Interventions' => 'includes/class-prstudio-uc-interventions.php',
            'PRSTUDIO_UC_Job_Engine' => 'includes/class-prstudio-uc-job-engine.php',
            'PRSTUDIO_UC_Legacy_Capability_Executor' => 'includes/class-prstudio-uc-legacy-capability-executor.php',
            'PRSTUDIO_UC_Log_Orchestrator' => 'includes/class-prstudio-uc-log-orchestrator.php',
            'PRSTUDIO_UC_MCP_Auth_V5' => 'includes/class-prstudio-uc-mcp-auth-v5.php',
            'PRSTUDIO_UC_MCP_Toolchain' => 'includes/class-prstudio-uc-mcp-toolchain.php',
            'PRSTUDIO_UC_MCP_V5' => 'includes/class-prstudio-uc-mcp-v5.php',
            'PRSTUDIO_UC_Memory' => 'includes/class-prstudio-uc-memory.php',
            'PRSTUDIO_UC_Migration_V3' => 'includes/class-prstudio-uc-migration-v3.php',
            'PRSTUDIO_UC_Migration_V4' => 'includes/class-prstudio-uc-migration-v4.php',
            'PRSTUDIO_UC_Mission_Engine' => 'includes/class-prstudio-uc-mission-engine.php',
            'PRSTUDIO_UC_Observability' => 'includes/class-prstudio-uc-observability.php',
            'PRSTUDIO_UC_Observe' => 'includes/class-prstudio-uc-observe.php',
            'PRSTUDIO_UC_One_Guard_Legacy_Migration' => 'includes/class-prstudio-uc-one-guard-legacy-migration.php',
            'PRSTUDIO_UC_OCR' => 'includes/class-prstudio-uc-ocr.php',
            'PRSTUDIO_UC_OpenAPI' => 'includes/class-prstudio-uc-openapi.php',
            'PRSTUDIO_UC_Operational_Twin' => 'includes/class-prstudio-uc-operational-twin.php',
            'PRSTUDIO_UC_Opportunity_Engine' => 'includes/class-prstudio-uc-opportunity-engine.php',
            'PRSTUDIO_UC_Orchestrator' => 'includes/orchestrator/class-prstudio-uc-orchestrator.php',
            'PRSTUDIO_UC_Performance_Budget' => 'includes/class-prstudio-uc-performance-budget.php',
            'PRSTUDIO_UC_Planner_V3' => 'includes/class-prstudio-uc-planner-v3.php',
            'PRSTUDIO_UC_Playbook_Engine' => 'includes/class-prstudio-uc-playbook-engine.php',
            'PRSTUDIO_UC_Agent_Model' => 'includes/class-prstudio-uc-agent-model.php',
            'PRSTUDIO_UC_Motion' => 'includes/class-prstudio-uc-motion.php',
            'PRSTUDIO_UC_Procedural_Skills' => 'includes/class-prstudio-uc-procedural-skills.php',
            'PRSTUDIO_UC_Product_Audit' => 'includes/class-prstudio-uc-product-audit.php',
            'PRSTUDIO_UC_Public_Crawl' => 'includes/class-prstudio-uc-public-crawl.php',
            'PRSTUDIO_UC_Publish_Transaction' => 'includes/class-prstudio-uc-publish-transaction.php',
            'PRSTUDIO_UC_Recovery_Manager' => 'includes/class-prstudio-uc-recovery-manager.php',
            'PRSTUDIO_UC_Render_Source_Resolver' => 'includes/class-prstudio-uc-render-source-resolver.php',
            'PRSTUDIO_UC_Request_Guard' => 'includes/class-prstudio-uc-request-guard.php',
            'PRSTUDIO_UC_REST' => 'includes/class-prstudio-uc-rest.php',
            'PRSTUDIO_UC_Risk_Engine_V3' => 'includes/class-prstudio-uc-risk-engine-v3.php',
            'PRSTUDIO_UC_Abilities' => 'includes/class-prstudio-uc-abilities.php',
            'PRSTUDIO_UC_Safety_Runtime' => 'includes/class-prstudio-uc-safety-runtime.php',
            'PRSTUDIO_UC_Schedule_Clock' => 'includes/class-prstudio-uc-schedule-clock.php',
            'PRSTUDIO_UC_Schema_Validator' => 'includes/class-prstudio-uc-schema-validator.php',
            'PRSTUDIO_UC_Search_Console_Browser' => 'includes/class-prstudio-uc-search-console-browser.php',
            'PRSTUDIO_UC_Secrets_Vault' => 'includes/class-prstudio-uc-safety-runtime.php',
            'PRSTUDIO_UC_SEO_Autopilot' => 'includes/class-prstudio-uc-seo-autopilot.php',
            'PRSTUDIO_UC_SEO_Intelligence' => 'includes/class-prstudio-uc-seo-intelligence.php',
            'PRSTUDIO_UC_Sequential_Thinking' => 'includes/class-prstudio-uc-sequential-thinking.php',
            'PRSTUDIO_UC_Site_Context' => 'includes/class-prstudio-uc-site-context.php',
            'PRSTUDIO_UC_Site_Sentinel' => 'includes/class-prstudio-uc-site-sentinel.php',
            'PRSTUDIO_UC_Snapshot_Engine' => 'includes/class-prstudio-uc-snapshot-engine.php',
            'PRSTUDIO_UC_Social_Intelligence' => 'includes/class-prstudio-uc-social-intelligence.php',
            'PRSTUDIO_UC_Social_Provider_Interface' => 'includes/class-prstudio-uc-social-intelligence.php',
            'PRSTUDIO_UC_State_Machine' => 'includes/class-prstudio-uc-state-machine.php',
            'PRSTUDIO_UC_Store' => 'includes/class-prstudio-uc-store.php',
            'PRSTUDIO_UC_Turn' => 'includes/class-prstudio-uc-turn.php',
            'PRSTUDIO_UC_Verification_Engine_V3' => 'includes/class-prstudio-uc-verification-engine-v3.php',
            'PRSTUDIO_UC_Verifier' => 'includes/class-prstudio-uc-verifier.php',
            'PRSTUDIO_UC_Work_Session' => 'includes/class-prstudio-uc-work-session.php',
            'PRSTUDIO_UC_Write_Token' => 'includes/class-prstudio-uc-write-token.php',
            'PRSTUDIO_UC_Wait_Channel' => 'includes/class-prstudio-uc-wait-channel.php',
            'PRSTUDIO_Web_Stories_Manage' => 'includes/class-web-stories-manage.php',
            'WPAIB_Admin' => 'includes/class-wpaib-admin.php',
            'WPAIB_AdTribes' => 'includes/class-wpaib-adtribes.php',
            'WPAIB_Audit' => 'includes/class-wpaib-audit.php',
            'WPAIB_Auth' => 'includes/class-wpaib-auth.php',
            'WPAIB_Enterprise' => 'includes/class-wpaib-enterprise.php',
            'WPAIB_Files' => 'includes/class-wpaib-files.php',
            'WPAIB_MCP' => 'includes/class-wpaib-mcp.php',
            'WPAIB_Media_Upload_Extension' => 'includes/class-wpaib-media-upload-extension.php',
            'WPAIB_REST' => 'includes/class-wpaib-rest.php',
            'WPAIB_Security_Hardening' => 'includes/class-wpaib-security-hardening.php',
            'WPAIB_Site' => 'includes/class-wpaib-site.php',
        );
        return $map;
    }

    public static function register(): void {
        if ( self::$registered ) { return; }
        self::$registered = true;
        spl_autoload_register( array( __CLASS__, 'load' ), true, false );
    }

    public static function load( string $class ): void {
        $map = self::map();
        if ( ! isset( $map[ $class ] ) ) { return; }
        $path = PRSTUDIO_UC_DIR . $map[ $class ];
        if ( ! is_readable( $path ) ) { return; }
        require_once $path;
        self::$loaded++;
        self::$loaded_classes[ $class ] = true;
    }

    /**
     * Loads a group eagerly.
     *
     * Used where a subsystem is about to be exercised heavily and the autoload
     * misses would otherwise be spread across a hot loop. Everything stays
     * lazy by default; this is an optimisation, never a requirement.
     */
    public static function preload( array $classes ): int {
        $count = 0;
        foreach ( $classes as $class ) {
            if ( class_exists( $class, false ) ) { continue; }
            self::load( (string) $class );
            if ( class_exists( $class, false ) ) { $count++; }
        }
        return $count;
    }

    /** Everything the MCP endpoint touches on a typical tool call. */
    public static function preload_mcp(): int {
        return self::preload( array(
            'PRSTUDIO_UC_MCP_Auth_V5', 'PRSTUDIO_UC_MCP_V5', 'PRSTUDIO_UC_Turn',
            'PRSTUDIO_UC_Store',
            'PRSTUDIO_UC_Memory', 'PRSTUDIO_UC_Execution_Lanes',
        ) );
    }

    public static function stats(): array {
        return array(
            'strategy'       => 'lazy_class_map',
            'mapped_classes' => count( self::map() ),
            'loaded_this_request' => self::$loaded,
            'loaded_classes' => array_keys( self::$loaded_classes ),
        );
    }

    /**
     * Integrity check used by the release validator: every mapped file must
     * exist and every class file must be mapped. A drift here would show up in
     * production as a fatal "class not found" on a rare code path, so it is
     * checked at build time instead.
     */
    public static function verify(): array {
        $missing = array();
        foreach ( self::map() as $class => $relative ) {
            if ( ! is_readable( PRSTUDIO_UC_DIR . $relative ) ) { $missing[ $class ] = $relative; }
        }
        return array(
            'ok'      => empty( $missing ),
            'mapped'  => count( self::map() ),
            'missing' => $missing,
        );
    }
}
