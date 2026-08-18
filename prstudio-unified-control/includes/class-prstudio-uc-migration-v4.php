<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/**
 * 4.0 migration is deliberately additive. Existing Browser device rows, pairing
 * tokens, GPT Actions keys, GSC OAuth state, jobs, memory and logs stay in place.
 */
final class PRSTUDIO_UC_Migration_V4 {
    public const VERSION = '4.0.1';
    private const OPTION = 'prstudio_uc_migration_v4';
    private const ROLLBACK_OPTION = 'prstudio_uc_migration_v4_rollback';

    private static function state(): array {
        $v = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        return is_array($v) ? $v : array();
    }
    private static function write_state(array $state): void {
        if ( function_exists('update_option') ) { update_option(self::OPTION, $state, false); }
    }
    private static function snapshot_options(): array {
        $names = array(
            'wpaib_settings',
            'prstudio_uc_actions_keys_v3', // intentionally retained to preserve existing ChatGPT Actions keys.
            'prstudio_uc_secret',
            'prstudio_uc_schema_version',
            'prstudio_uc_migration_v3',
            'wpaib_google_oauth',
            'wpaib_google_tokens',
        );
        $out = array();
        foreach ($names as $name) {
            if ( function_exists('get_option') ) { $out[$name] = get_option($name, null); }
        }
        return $out;
    }
    private static function write_rollback_snapshot(): void {
        if ( ! function_exists('update_option') ) { return; }
        update_option(self::ROLLBACK_OPTION, array(
            'created_at' => gmdate('c'),
            'suite_from' => defined('PRSTUDIO_UC_VERSION') ? PRSTUDIO_UC_VERSION : self::VERSION,
            'options' => self::snapshot_options(),
            'browser_pairing' => 'database_in_place_no_token_rotation',
            'memory' => 'namespace_in_place_no_destructive_move',
            'jobs' => 'database_in_place',
            'logs' => 'in_place',
        ), false);
    }
    public static function run(): array {
        $state = self::state();
        if ( ($state['version'] ?? '') === self::VERSION && ! empty($state['completed']) ) { return $state; }
        self::write_rollback_snapshot();
        try {
            $registry = PRSTUDIO_UC_Capability_Registry::consistency();
            if ( empty($registry['ok']) ) { throw new RuntimeException('capability_registry_inconsistent'); }
            $memory = PRSTUDIO_UC_Memory::site_identity();
            $state = array(
                'version' => self::VERSION,
                'completed' => true,
                'completed_gmt' => gmdate('c'),
                'from_v3_supported' => true,
                'direct_v2_supported_via_v3_migration' => true,
                'wordpress_installation_contract' => 'same_folder_same_bootstrap_standard_plugin_zip',
                'browser_installation_contract' => 'same_folder_same_manifest_permissions_same_storage_keys',
                'browser_pairing_preserved' => true,
                'browser_repair_required' => false,
                'gpt_actions_keys_preserved' => true,
                'configuration_preserved' => true,
                'gsc_oauth_preserved' => true,
                'jobs_preserved' => true,
                'logs_preserved' => true,
                'memory_namespace' => (string)($memory['key'] ?? ''),
                'legacy_mcp_required' => false,
                'capability_registry' => array('count'=>$registry['count'], 'hash'=>$registry['registry_hash']),
                'rollback_snapshot' => self::ROLLBACK_OPTION,
            );
            self::write_state($state);
            return $state;
        } catch (Throwable $e) {
            $state = array(
                'version'=>self::VERSION,
                'completed'=>false,
                'failed_gmt'=>gmdate('c'),
                'error'=>substr($e->getMessage(),0,240),
                'rollback_safe'=>true,
                'rollback_snapshot'=>self::ROLLBACK_OPTION,
            );
            self::write_state($state);
            return $state;
        }
    }
}
