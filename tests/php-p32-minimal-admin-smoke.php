<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'PRSTUDIO_UC_VERSION', 'test' );

$GLOBALS['p32_options'] = array();
$GLOBALS['p32_transients'] = array( 'prstudio_uc_pairing_display_7' => 'PAIR-1234' );
$GLOBALS['p32_gc_force'] = null;

function current_user_can( $capability ) { return 'manage_options' === $capability; }
function add_management_page() { return 'tools_page_prstudio-unified-browser'; }
function get_option( $key, $default = false ) { return $GLOBALS['p32_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['p32_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['p32_options'][ $key ] ); return true; }
function get_transient( $key ) { return $GLOBALS['p32_transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['p32_transients'][ $key ] ); return true; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['p32_transients'][ $key ] = $value; return true; }
function get_current_user_id() { return 7; }
function check_admin_referer() { return true; }
function wp_safe_redirect() { return true; }
function wp_die( $message ) { throw new RuntimeException( (string) $message ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return (string) $value; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function submit_button( $text, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }

final class PRSTUDIO_UC_Auth {
    public static function create_pairing_code(): string { return 'PAIR-NEW'; }
}

final class PRSTUDIO_UC_MCP_Auth_V5 {
    public static function mcp_url(): string { return 'https://example.test/wp-json/prstudio-unified/v1/mcp'; }
}

final class PRSTUDIO_UC_Store {
    public static function schema_ready(): bool { return true; }
    public static function devices_table(): string { return 'wp_prstudio_uc_devices'; }
}

final class PRSTUDIO_UC_GC {
    public const OPTION = 'prstudio_uc_retention';
    public const LAST_RUN_OPTION = 'prstudio_uc_gc_last_run';
    public static function retention(): array {
        return array(
            'events_hours' => 72,
            'tasks_hours' => 24,
            'jobs_days' => 7,
            'dead_letters_days' => 30,
            'audit_days' => 30,
            'schedules_days' => 90,
            'transients_hours' => 24,
            'revisions_keep' => 5,
            'work_sessions_days' => 14,
            'revoked_devices_days' => 30,
        );
    }
    public static function run( bool $force = false ): array {
        $GLOBALS['p32_gc_force'] = $force;
        return array( 'total_removed' => 12 );
    }
}

final class P32_WPDB {
    public array $deletes = array();
    public function delete( $table, $where, $where_format = null ) {
        $this->deletes[] = array( $table, $where, $where_format );
        return 3;
    }
}
$GLOBALS['wpdb'] = new P32_WPDB();

require_once dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-admin.php';

function p32_assert( $condition, string $message ): void {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$migration = PRSTUDIO_UC_Admin::run_history_migration_once();
p32_assert( true === $migration['migrated'], 'P32 migration should run once when the store is ready' );
p32_assert( 3 === $migration['revoked_devices_removed'], 'revoked device history should be removed immediately' );
p32_assert( true === $GLOBALS['p32_gc_force'], 'P32 migration should run the internal GC after compacting retention' );
p32_assert( 1 === count( $GLOBALS['wpdb']->deletes ), 'migration should issue one direct row-set cleanup' );
p32_assert( 'wp_prstudio_uc_devices' === $GLOBALS['wpdb']->deletes[0][0], 'cleanup must target only the device table directly' );
p32_assert( array( 'status' => 'revoked' ) === $GLOBALS['wpdb']->deletes[0][1], 'cleanup must delete revoked devices only' );

$retention = $GLOBALS['p32_options'][ PRSTUDIO_UC_GC::OPTION ] ?? array();
foreach ( array( 'events_hours', 'tasks_hours', 'jobs_days', 'dead_letters_days', 'transients_hours', 'revoked_devices_days' ) as $key ) {
    p32_assert( 1 === (int) ( $retention[ $key ] ?? 0 ), "compact retention missing for {$key}" );
}
p32_assert( isset( $GLOBALS['p32_options']['prstudio_uc_p32_minimal_dashboard_migrated'] ), 'migration completion marker missing' );

$again = PRSTUDIO_UC_Admin::run_history_migration_once();
p32_assert( false === $again['migrated'] && 'already_done' === $again['reason'], 'migration must be idempotent' );
p32_assert( 1 === count( $GLOBALS['wpdb']->deletes ), 'idempotent rerun must not delete again' );

ob_start();
PRSTUDIO_UC_Admin::render();
$html = ob_get_clean();

foreach ( array(
    'PR STUDIO — Collegamenti',
    'Collega ChatGPT',
    'Collega Chrome',
    'https://example.test/wp-json/prstudio-unified/v1/mcp',
    'PAIR-1234',
    'Genera un nuovo codice',
    'Copia indirizzo',
    'Copia codice',
) as $needle ) {
    p32_assert( false !== strpos( $html, $needle ), "minimal dashboard missing {$needle}" );
}

foreach ( array(
    'Browser collegati',
    'Attività recenti',
    'Cronologia dispositivi revocati',
    'Manutenzione runtime',
    'Pulizia automatica del database',
    'Compatibilità GPT Actions',
    'Automazione continua',
) as $needle ) {
    p32_assert( false === strpos( $html, $needle ), "legacy dashboard surface still visible: {$needle}" );
}

$source = file_get_contents( dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-admin.php' );
foreach ( array(
    'PRSTUDIO_UC_Store::list_devices',
    'PRSTUDIO_UC_Store::recent_tasks',
    'PRSTUDIO_UC_OCR::',
    'PRSTUDIO_UC_Capability_Registry::',
    'PRSTUDIO_UC_Agency_Runtime::',
    'PRSTUDIO_UC_Memory::',
    'PRSTUDIO_UC_Operational_Twin::',
    'PRSTUDIO_UC_Procedural_Skills::',
) as $needle ) {
    p32_assert( false === strpos( $source, $needle ), "admin should not load or mutate internal suite surface: {$needle}" );
}

fwrite( STDOUT, "OK P32 minimal pairing dashboard and history migration\n" );
