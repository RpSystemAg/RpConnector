<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/**
 * Crash-resistant activation/upgrade coordinator.
 * Heavy migrations never execute inside the WordPress activation request.
 */
final class PRSTUDIO_UC_Recovery_Manager {
    public const MIGRATION_HOOK = 'prstudio_uc_deferred_migration';
    private const STATE_OPTION = 'prstudio_uc_migration_state';
    private const FAILURE_OPTION = 'prstudio_uc_migration_failure';
    private const ACTIVATION_OPTION = 'prstudio_uc_activation_state';
    private const BOOT_OPTION = 'prstudio_uc_boot_state';
    private const LOCK = 'prstudio_uc_migration_lock_500';
    private const MAX_ATTEMPTS = 5;

    private static function now(): string { return gmdate('c'); }
    private static function error_record(Throwable $e): array {
        return array(
            'error_class'=>get_class($e),
            'error'=>substr((string)$e->getMessage(),0,300),
            'failed_gmt'=>self::now(),
            'site_kept_online'=>true,
        );
    }
    private static function set_option(string $name,$value): void {
        if(function_exists('update_option')){update_option($name,$value,false);}
    }
    private static function schedule(int $delay=20): bool {
        if(!function_exists('wp_next_scheduled')||!function_exists('wp_schedule_single_event'))return false;
        if(wp_next_scheduled(self::MIGRATION_HOOK))return true;
        return false !== wp_schedule_single_event(time()+max(1,$delay),self::MIGRATION_HOOK);
    }
    private static function mark_retryable(string $reason,int $attempts=0,array $extra=array()): void {
        self::set_option(self::STATE_OPTION,array_merge(array(
            'version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'5.0.0',
            'state'=>'retryable',
            'reason'=>$reason,
            'attempts'=>$attempts,
            'updated_gmt'=>self::now(),
        ),$extra));
    }
    public static function activate(): array {
        $existing_schema=(string)(function_exists('get_option')?get_option('prstudio_uc_schema_version',''):'');
        $fresh_install=''===$existing_schema;
        $result=array(
            'ok'=>true,
            'schema'=>false,
            'schema_existing'=>$existing_schema,
            'fresh_install'=>$fresh_install,
            'secret'=>false,
            'migration_deferred'=>true,
            'site_kept_online'=>true,
        );
        if($fresh_install){
            try {
                // Fresh install only: create the minimum tables once. Upgrades never run dbDelta in the activation request.
                PRSTUDIO_UC_Store::install();
                $result['schema']=true;
            } catch(Throwable $e) {
                $result['ok']=false; $result['schema_error']=self::error_record($e);
            }
        } else {
            // Existing 2.x/3.x/4.x installations keep their current tables untouched until the deferred migration job runs.
            $result['schema']=true;
            $result['schema_upgrade_deferred']=true;
        }
        try { PRSTUDIO_UC_Auth::ensure_secret(); $result['secret']=true; }
        catch(Throwable $e){ $result['ok']=false; $result['secret_error']=self::error_record($e); }
        self::mark_retryable('activation',0,array(
            'schema_ready'=>$result['schema'],
            'schema_existing'=>$existing_schema,
            'schema_upgrade_deferred'=>!$fresh_install,
        ));
        $result['migration_scheduled']=self::schedule(10);
        self::set_option(self::ACTIVATION_OPTION,array_merge($result,array('version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'5.0.0','activated_gmt'=>self::now())));
        return $result;
    }
    private static function acquire_lock(): bool {
		if(!function_exists('add_option'))return true;
		$existing=function_exists('get_option')?get_option(self::LOCK,array()):array();
		if(is_array($existing)&&(int)($existing['created_at']??0)<time()-300&&function_exists('delete_option'))delete_option(self::LOCK);
		return (bool)add_option(self::LOCK,array('token'=>bin2hex(random_bytes(8)),'created_at'=>time()),'',false);
    }
    private static function release_lock(): void { if(function_exists('delete_option'))delete_option(self::LOCK); }
    public static function run_deferred_migration(): array {
        if(!self::acquire_lock())return array('ok'=>false,'state'=>'locked','retryable'=>true);
        $migration_state=function_exists('get_option')?get_option(self::STATE_OPTION,array()):array();
        $attempts=is_array($migration_state)?(int)($migration_state['attempts']??0):0;
        $attempts++;
        try {
            $schema=PRSTUDIO_UC_Store::maybe_upgrade();
            if(!$schema)throw new RuntimeException('schema_not_ready');
            $v3=PRSTUDIO_UC_Migration_V3::run();
            if(empty($v3['completed']))throw new RuntimeException('migration_v3_technical_error:'.(string)($v3['error']??'unknown'));
            $v4=PRSTUDIO_UC_Migration_V4::run();
            if(empty($v4['completed']))throw new RuntimeException('migration_v4_technical_error:'.(string)($v4['error']??'unknown'));
            self::set_option(self::STATE_OPTION,array('version'=>PRSTUDIO_UC_VERSION,'state'=>'completed','attempts'=>$attempts,'completed_gmt'=>self::now()));
            self::set_option(self::FAILURE_OPTION,array());
            try { PRSTUDIO_UC_Log_Orchestrator::log('plugin','plugin.migration.completed',array('version'=>PRSTUDIO_UC_VERSION,'attempts'=>$attempts),'info',__FILE__); } catch(Throwable $ignored) {}
            return array('ok'=>true,'state'=>'completed','attempts'=>$attempts,'v3'=>$v3,'v4'=>$v4);
        } catch(Throwable $e) {
            $failure=array_merge(self::error_record($e),array('version'=>PRSTUDIO_UC_VERSION,'attempts'=>$attempts,'retryable'=>$attempts<self::MAX_ATTEMPTS));
            self::set_option(self::FAILURE_OPTION,$failure);
            if($attempts<self::MAX_ATTEMPTS){ self::mark_retryable('migration_technical_error',$attempts,array('last_error'=>$failure['error'])); } else { self::set_option(self::STATE_OPTION,array('version'=>PRSTUDIO_UC_VERSION,'state'=>'technical_error','attempts'=>$attempts,'last_error'=>$failure['error'],'updated_gmt'=>self::now())); }
            if($attempts<self::MAX_ATTEMPTS)self::schedule(min(1800,60*$attempts));
            return array('ok'=>false,'state'=>$attempts<self::MAX_ATTEMPTS?'retryable':'technical_error','attempts'=>$attempts,'retryable'=>$attempts<self::MAX_ATTEMPTS,'error'=>$failure);
        } finally { self::release_lock(); }
    }
    private static function safe_step(string $name, callable $step, array &$errors) {
        try { return $step(); }
        catch(Throwable $e){ $errors[$name]=self::error_record($e); return null; }
    }
    public static function boot(): array {
        $errors=array();
        $schema_ready=PRSTUDIO_UC_Store::schema_ready();
        $migration_state=function_exists('get_option')?get_option(self::STATE_OPTION,array()):array();
        $migration_done=is_array($migration_state)&&'completed'===(string)($migration_state['state']??'');
        $migration_retryable=!$migration_done && (!is_array($migration_state)||'technical_error'!==(string)($migration_state['state']??''));
        if(!$schema_ready||$migration_retryable)self::schedule(5);
        if($schema_ready){
            self::safe_step('job_recovery',static fn()=>PRSTUDIO_UC_Job_Engine::recover(),$errors);
            self::safe_step('mission_recovery',static fn()=>PRSTUDIO_UC_Mission_Engine::recover(),$errors);
        }
        self::safe_step('change_tracker',static function(){PRSTUDIO_UC_Change_Tracker::register();PRSTUDIO_UC_Change_Tracker::schedule(300);return true;},$errors);
        self::safe_step('browser_adapters',static fn()=>PRSTUDIO_UC_Bridge::register(),$errors);
        self::safe_step('agency_schedulers',static function(){PRSTUDIO_UC_Agency_Runtime::ensure_schedulers();return true;},$errors);
        // Memory context is useful but must never be a bootstrap dependency.
        if($schema_ready&&$migration_done){
            self::safe_step('memory_context',static function(){
                PRSTUDIO_UC_Memory::save_context(array(
                    'suite_version'=>PRSTUDIO_UC_VERSION,
                    'capability_registry_hash'=>PRSTUDIO_UC_Capability_Registry::hash(),
                    'capability_counts'=>PRSTUDIO_UC_Capability_Registry::counts(),
                    'gpt_actions_operation_ids'=>PRSTUDIO_UC_OpenAPI::operation_ids(),
                    'legacy_mcp_required'=>false,
                ));
                return true;
            },$errors);
        }
        $state=array('ok'=>empty($errors),'degraded'=>!empty($errors)||!$schema_ready||!$migration_done,'schema_ready'=>$schema_ready,'migration_completed'=>$migration_done,'legacy_mcp_enabled'=>(bool)PRSTUDIO_UC_ENABLE_LEGACY_MCP,'errors'=>$errors,'booted_gmt'=>self::now(),'site_kept_online'=>true);
        self::set_option(self::BOOT_OPTION,$state);
        return $state;
    }
}
