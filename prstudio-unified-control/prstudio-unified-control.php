<?php
/**
 * Plugin Name: PR STUDIO Unified Control Plane
 * Plugin URI:  https://prstudio.ai/
 * Description: PR STUDIO 17.0 execution agency: Codex-style turn contract, long-poll agent channel, interventions ledger, durable missions, H24 sentinel, operational twin, OAuth MCP and an owned-tab native-input Browser Agent.
 * Version:     1.0.0
 * Author:      PR STUDIO
 * License:     GPL-2.0-or-later
 * Text Domain: pr-studio-unified-control
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Unique unified constants are always safe, including during migration activation.
define( 'PRSTUDIO_UC_VERSION', '1.0.0' );
define( 'PRSTUDIO_UC_FILE', __FILE__ );
define( 'PRSTUDIO_UC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRSTUDIO_UC_URL', plugin_dir_url( __FILE__ ) );
define( 'PRSTUDIO_UC_BASENAME', plugin_basename( __FILE__ ) );

// Legacy external bridge/MCP and local Browser Runtime are OFF by default.
// They may be enabled only deliberately from wp-config.php for migration diagnostics.
if ( ! defined( 'PRSTUDIO_UC_ENABLE_LEGACY_MCP' ) ) { define( 'PRSTUDIO_UC_ENABLE_LEGACY_MCP', false ); }
if ( ! defined( 'PRSTUDIO_UC_ENABLE_LEGACY_BROWSER_RUNTIME' ) ) { define( 'PRSTUDIO_UC_ENABLE_LEGACY_BROWSER_RUNTIME', false ); }

$prstudio_legacy_bridge_loaded = defined( 'WPAIB_DIR' ) || class_exists( 'PRSTUDIO_AI_Bridge_Plugin', false );
$prstudio_legacy_runtime_loaded = defined( 'PRSTUDIO_BROWSER_RUNTIME_DIR' ) || class_exists( 'PRSTUDIO_Browser_Runtime', false );

// Keep legacy constant names so existing connector routes, options and adapters remain compatible.
if ( ! defined( 'WPAIB_VERSION' ) ) define( 'WPAIB_VERSION', '1.0.0' );
if ( ! defined( 'WPAIB_FILE' ) ) define( 'WPAIB_FILE', __FILE__ );
if ( ! defined( 'WPAIB_DIR' ) ) define( 'WPAIB_DIR', PRSTUDIO_UC_DIR );
if ( ! defined( 'WPAIB_URL' ) ) define( 'WPAIB_URL', PRSTUDIO_UC_URL );
if ( ! defined( 'WPAIB_BASENAME' ) ) define( 'WPAIB_BASENAME', PRSTUDIO_UC_BASENAME );
if ( ! defined( 'PRSTUDIO_BRIDGE_NAME' ) ) define( 'PRSTUDIO_BRIDGE_NAME', 'PR STUDIO Unified Control Plane' );
if ( ! defined( 'PRSTUDIO_BROWSER_RUNTIME_VERSION' ) ) define( 'PRSTUDIO_BROWSER_RUNTIME_VERSION', '1.0.0' );
if ( ! defined( 'PRSTUDIO_BROWSER_RUNTIME_FILE' ) ) define( 'PRSTUDIO_BROWSER_RUNTIME_FILE', __FILE__ );
if ( ! defined( 'PRSTUDIO_BROWSER_RUNTIME_DIR' ) ) define( 'PRSTUDIO_BROWSER_RUNTIME_DIR', PRSTUDIO_UC_DIR );
if ( ! defined( 'PRSTUDIO_BROWSER_RUNTIME_BASENAME' ) ) define( 'PRSTUDIO_BROWSER_RUNTIME_BASENAME', PRSTUDIO_UC_BASENAME );


// WooCommerce HPOS compatibility. PR STUDIO order/product operations use the
// WooCommerce CRUD layer rather than wp_posts/wp_postmeta storage assumptions.
add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PRSTUDIO_UC_FILE, true );
    }
} );

/**
 * 17.0 loading strategy.
 *
 * 15.x required 113 class files unconditionally at file scope — about 2 MB of
 * PHP parsed on every single WordPress request, including front-end page views
 * by ordinary visitors who will never touch this plugin. Every one of those
 * files was verified to contain nothing but class declarations: no hooks
 * registered at load time, no side effects. So the requires are replaced by one
 * class-map autoloader and the classes arrive when something actually asks for
 * them.
 *
 * All 123 classes remain present and callable exactly as before; only the
 * moment of loading changed.
 */
require_once PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-autoload.php';
PRSTUDIO_UC_Autoload::register();

if ( ! $prstudio_legacy_bridge_loaded ) {
    final class PRSTUDIO_AI_Bridge_Plugin {
        private static ?self $instance = null;
        public static function instance(): self { return self::$instance ??= new self(); }
        private function __construct() {
            add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
            add_action( 'parse_request', array( 'WPAIB_REST', 'maybe_serve_well_known' ), 0 );
            add_action( 'rest_api_init', array( 'WPAIB_REST', 'register_routes' ) );
            add_action( 'admin_menu', array( 'WPAIB_Admin', 'register_menu' ) );
            add_action( 'admin_init', array( 'WPAIB_Admin', 'handle_actions' ) );
            add_action( 'admin_post_wpaib_oauth_authorize', array( 'WPAIB_Auth', 'handle_authorize' ) );
            add_action( 'prstudio_bridge_report_retry', array( 'PRSTUDIO_Report', 'retry_outbox' ) );
            PRSTUDIO_Agency::init();
            PRSTUDIO_Report::init();
            PRSTUDIO_UC_Complete_Action_Executor::boot();
            PRSTUDIO_UC_Agency_Action_Executor::boot();
        }
        public function load_textdomain(): void { load_plugin_textdomain( 'pr-studio-unified-control', false, dirname( PRSTUDIO_UC_BASENAME ) . '/languages' ); }
        public static function activate(): void {
            WPAIB_Audit::install();
            $defaults = array(
                'max_file_bytes'=>8*1024*1024, 'rate_limit_per_min'=>600, 'generation'=>1,
                'report_enabled'=>true, 'market_country'=>'IT',
                'allowed_origins'=>array('https://chatgpt.com','https://chat.openai.com','https://platform.openai.com'),
            );
            $current = get_option( 'wpaib_settings', array() );
            update_option( 'wpaib_settings', wp_parse_args( is_array($current)?$current:array(), $defaults ), false );
            WPAIB_Files::ensure_backup_directory();
            if ( ! wp_next_scheduled( 'prstudio_bridge_report_retry' ) ) wp_schedule_event( time()+300, 'hourly', 'prstudio_bridge_report_retry' );
            flush_rewrite_rules( false );
        }
        public static function deactivate(): void {
            $timestamp=wp_next_scheduled('prstudio_bridge_report_retry');
            if($timestamp) wp_unschedule_event($timestamp,'prstudio_bridge_report_retry');
            PRSTUDIO_Agency::deactivate();
        }
    }
    if ( PRSTUDIO_UC_ENABLE_LEGACY_MCP ) { PRSTUDIO_AI_Bridge_Plugin::instance(); }
}

if ( ! $prstudio_legacy_runtime_loaded && PRSTUDIO_UC_ENABLE_LEGACY_BROWSER_RUNTIME ) {
    PRSTUDIO_Browser_Runtime::instance();
}

final class PRSTUDIO_Unified_Control_Plane {
    private static ?self $instance=null;
    public static function instance(): self { return self::$instance ??= new self(); }
    private function __construct() {
        add_action( 'plugins_loaded', array($this,'boot'), 35 );
        add_action( 'rest_api_init', array('PRSTUDIO_UC_REST','register_routes') );
        add_action( 'rest_api_init', array('PRSTUDIO_UC_GPT_REST','register_routes'), 20 );
        add_action( 'parse_request', array('PRSTUDIO_UC_MCP_Auth_V5','maybe_serve_well_known'), 0 );
        add_action( 'rest_api_init', array('PRSTUDIO_UC_MCP_V5','register_routes'), 5 );
        // WordPress 6.9+ Abilities API: read-only, lazy and not REST-exposed.
        // Older WordPress versions simply never fire these hooks.
        add_action( 'wp_abilities_api_categories_init', array('PRSTUDIO_UC_Abilities','register_category') );
        add_action( 'wp_abilities_api_init', array('PRSTUDIO_UC_Abilities','register') );
        // Browser adapters are required by browser tools but registration itself
        // is deterministic and much cheaper than the full recovery boot.
        add_action( 'rest_api_init', array('PRSTUDIO_UC_Bridge','register'), 6 );
        add_action( 'admin_post_prstudio_mcp_v5_authorize', array('PRSTUDIO_UC_MCP_Auth_V5','handle_authorize') );
        add_action( 'admin_post_nopriv_prstudio_mcp_v5_authorize', array('PRSTUDIO_UC_MCP_Auth_V5','handle_authorize') );
        add_action( 'admin_menu', array('PRSTUDIO_UC_Admin','register_menu') );
        add_action( 'admin_post_prstudio_uc_pairing_code', array('PRSTUDIO_UC_Admin','pairing_code_action') );
        add_action( 'admin_post_prstudio_uc_revoke_device', array('PRSTUDIO_UC_Admin','revoke_device_action') );
        add_action( 'admin_post_prstudio_uc_actions_key', array('PRSTUDIO_UC_Admin','actions_key_action') );
        add_action( 'admin_post_prstudio_uc_maintenance', array('PRSTUDIO_UC_Admin','maintenance_action') );
        add_filter( 'plugin_action_links_' . PRSTUDIO_UC_BASENAME, array($this,'action_links') );
        add_action( 'prstudio_uc_artifact_cleanup', array('PRSTUDIO_UC_Artifacts','cleanup') );
        // Hook names are written as literals rather than class constants so that
        // registering them does not itself autoload the class on every request.
        add_action( 'prstudio_uc_gc', array('PRSTUDIO_UC_GC','run') );
        add_action( 'prstudio_uc_deferred_migration', array('PRSTUDIO_UC_Recovery_Manager','run_deferred_migration') );
        // Agency/scheduler hooks are only needed in background/admin contexts.
        // Interactive MCP/REST requests must not initialize the durable worker.
        if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) { PRSTUDIO_UC_Agency_Runtime::init(); }
    }
    public static function activate(): void {
        // Activation is intentionally minimal. Migrations/integrity scans are deferred.
        PRSTUDIO_UC_Recovery_Manager::activate();
        PRSTUDIO_UC_One_Guard_Legacy_Migration::run();
        PRSTUDIO_UC_Agency_Runtime::activate();
        PRSTUDIO_UC_Interventions::install();
        PRSTUDIO_UC_SEO_Autopilot::install();
        PRSTUDIO_UC_GC::activate();
        if ( function_exists('wp_next_scheduled') && ! wp_next_scheduled( 'prstudio_uc_artifact_cleanup' ) ) {
            wp_schedule_event( time()+HOUR_IN_SECONDS, 'hourly', 'prstudio_uc_artifact_cleanup' );
        }
    }
    public static function deactivate(): void {
        // Never revoke Browser pairing, MCP OAuth grants/refresh generations or GPT Actions compatibility keys during plugin update/deactivation.
        if ( PRSTUDIO_UC_ENABLE_LEGACY_BROWSER_RUNTIME && class_exists('PRSTUDIO_Browser_Runtime') ) { PRSTUDIO_Browser_Runtime::deactivate(); }
        if ( PRSTUDIO_UC_ENABLE_LEGACY_MCP && class_exists('PRSTUDIO_AI_Bridge_Plugin') ) { PRSTUDIO_AI_Bridge_Plugin::deactivate(); }
        $timestamp=function_exists('wp_next_scheduled')?wp_next_scheduled('prstudio_uc_artifact_cleanup'):false;
        if($timestamp && function_exists('wp_unschedule_event')) wp_unschedule_event($timestamp,'prstudio_uc_artifact_cleanup');
        PRSTUDIO_UC_GC::deactivate();
        PRSTUDIO_UC_Agency_Runtime::deactivate();
    }
    public function boot(): void {
        // Recovery, durable-job reconciliation, twin/memory context and scheduler
        // maintenance are background/admin concerns. A normal interactive REST
        // or MCP request starts on the fast lane and loads none of them.
        $maintenance_context = ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) );
        if ( ! $maintenance_context ) { return; }
        PRSTUDIO_UC_Recovery_Manager::boot();
        PRSTUDIO_UC_One_Guard_Legacy_Migration::run();
        PRSTUDIO_UC_GC::activate();
        PRSTUDIO_UC_Interventions::install();
        PRSTUDIO_UC_SEO_Autopilot::install();
    }
    public function action_links(array $links): array { array_unshift($links,'<a href="'.esc_url(admin_url('tools.php?page=prstudio-unified-browser')).'">Configura</a>'); return $links; }
}
register_activation_hook(__FILE__,array('PRSTUDIO_Unified_Control_Plane','activate'));
register_deactivation_hook(__FILE__,array('PRSTUDIO_Unified_Control_Plane','deactivate'));
PRSTUDIO_Unified_Control_Plane::instance();
