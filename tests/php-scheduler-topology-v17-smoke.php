<?php

declare(strict_types=1);

define('ABSPATH', sys_get_temp_dir() . '/prstudio-scheduler-v17/');
define('HOUR_IN_SECONDS', 3600);
define('PRSTUDIO_UC_ENABLE_LEGACY_MCP', false);
@mkdir(ABSPATH, 0777, true);

$GLOBALS['wp_events'] = [
    'prstudio_uc_agency_worker_tick' => [100, 200],
    'prstudio_agency_cron_tick' => [300],
];
$GLOBALS['as_actions'] = [
    ['id'=>1,'hook'=>'prstudio_uc_agency_action_scheduler_tick','group'=>'prstudio-unified-control','next'=>100],
    ['id'=>2,'hook'=>'prstudio_uc_agency_action_scheduler_tick','group'=>'prstudio-unified-control','next'=>101],
];
$GLOBALS['opts'] = [];
$GLOBALS['actions'] = [];

function add_action($hook,$callback,$priority=10,$accepted_args=1): void { $GLOBALS['actions'][$hook][] = $callback; }
function add_filter($hook,$callback,$priority=10,$accepted_args=1): void {}
function wp_next_scheduled($hook) { return !empty($GLOBALS['wp_events'][$hook]) ? min($GLOBALS['wp_events'][$hook]) : false; }
function wp_schedule_event($timestamp,$recurrence,$hook,$args=[],$wp_error=false) { $GLOBALS['wp_events'][$hook][]=(int)$timestamp; return true; }
function wp_schedule_single_event($timestamp,$hook,$args=[],$wp_error=false) { $GLOBALS['wp_events'][$hook][]=(int)$timestamp; return true; }
function wp_clear_scheduled_hook($hook,$args=[],$wp_error=false) { $n=count($GLOBALS['wp_events'][$hook]??[]); unset($GLOBALS['wp_events'][$hook]); return $n; }
function wp_unschedule_event($timestamp,$hook,$args=[],$wp_error=false) { $rows=$GLOBALS['wp_events'][$hook]??[]; $GLOBALS['wp_events'][$hook]=array_values(array_filter($rows,fn($v)=>(int)$v!==(int)$timestamp)); return true; }
function as_has_scheduled_action($hook,$args=[],$group='') { foreach($GLOBALS['as_actions'] as $a){ if($a['hook']===$hook&&$a['group']===$group)return (int)$a['id']; } return false; }
function as_schedule_recurring_action($timestamp,$interval,$hook,$args=[],$group='',$unique=false,$priority=10) { if($unique && as_has_scheduled_action($hook,$args,$group)) return 0; $id=count($GLOBALS['as_actions'])+1; $GLOBALS['as_actions'][]=['id'=>$id,'hook'=>$hook,'group'=>$group,'next'=>(int)$timestamp]; return $id; }
function as_unschedule_all_actions($hook,$args=[],$group='') { $before=count($GLOBALS['as_actions']); $GLOBALS['as_actions']=array_values(array_filter($GLOBALS['as_actions'],fn($a)=>!($a['hook']===$hook&&$a['group']===$group))); return $before-count($GLOBALS['as_actions']); }
function as_next_scheduled_action($hook,$args=[],$group='') { foreach($GLOBALS['as_actions'] as $a){ if($a['hook']===$hook&&$a['group']===$group)return $a['next']; } return false; }
function as_enqueue_async_action($hook,$args=[],$group='',$unique=false,$priority=10) { return 999; }
function get_option($key,$default=false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key,$value,$autoload=null): bool { $GLOBALS['opts'][$key]=$value; return true; }
function delete_option($key): bool { unset($GLOBALS['opts'][$key]); return true; }
function sanitize_key($v): string { return trim((string)preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)),'-_'); }
function sanitize_text_field($v): string { return trim(strip_tags((string)$v)); }
function current_time($type,$gmt=false): string { return gmdate('Y-m-d H:i:s'); }
function wp_json_encode($v,$flags=0): string { return (string)json_encode($v,$flags); }
function wp_generate_uuid4(): string { return '00000000-0000-4000-a000-000000000001'; }

final class PRSTUDIO_UC_Store {
    public static function schema_ready(): bool { return true; }
    public static function get_schedule($id) { return ['schedule_uuid'=>$id]; }
    public static function upsert_schedule(...$args) { return true; }
    public static function queue_stats(): array { return []; }
    public static function due_schedules($limit): array { return []; }
    public static function claim_next_job($worker) { return null; }
}
final class PRSTUDIO_UC_Serp_Watch { public static int $last_limit=0; public static function tick(int $limit=1): array { self::$last_limit=$limit; return ['ok'=>true,'processed'=>0]; } }
final class PRSTUDIO_UC_Playbook_Engine { public const VERSION='17.0.0'; public static function describe(): array { return []; } }
final class PRSTUDIO_UC_Site_Sentinel { public static function status(): array { return []; } }

require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-agency-runtime.php';
require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-agency.php';

function check_v17(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }

PRSTUDIO_UC_Agency_Runtime::init();
PRSTUDIO_UC_Agency_Runtime::ensure_schedulers();
$scheduled=array_values(array_filter($GLOBALS['as_actions'],fn($a)=>$a['hook']==='prstudio_uc_agency_action_scheduler_tick'&&$a['group']==='prstudio-unified-control'));
check_v17(count($scheduled)===1,'v17 reconciles duplicate Action Scheduler recurring chains to exactly one');
check_v17(!wp_next_scheduled('prstudio_uc_agency_worker_tick'),'Action Scheduler mode removes the parallel PR STUDIO WP-Cron worker');
check_v17(($GLOBALS['opts']['prstudio_uc_scheduler_topology']??'')==='17.0.0-single-runner-v1|action_scheduler','scheduler topology migration is persisted');
PRSTUDIO_UC_Agency_Runtime::ensure_schedulers();
$scheduled=array_values(array_filter($GLOBALS['as_actions'],fn($a)=>$a['hook']==='prstudio_uc_agency_action_scheduler_tick'&&$a['group']==='prstudio-unified-control'));
check_v17(count($scheduled)===1,'repeated ensure_schedulers remains idempotent');
$fastCallbacks=$GLOBALS['actions']['prstudio_uc_agency_fast_tick']??[];
check_v17(count($fastCallbacks)===1 && is_array($fastCallbacks[0]) && $fastCallbacks[0][1]==='fast_tick','fast continuation is isolated from cron maintenance');

PRSTUDIO_Agency::init();
check_v17(!wp_next_scheduled('prstudio_agency_cron_tick'),'disabled legacy Agency removes its old five-minute cron instead of rescheduling it');
PRSTUDIO_UC_Agency_Runtime::cron_tick();
check_v17(PRSTUDIO_UC_Serp_Watch::$last_limit===1,'scheduler maintenance dispatches at most one SERP watch per recurring tick');
$serpSource=(string)file_get_contents(dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-serp-watch.php');
check_v17(str_contains($serpSource,"'http_timeout'=>2") && str_contains($serpSource,"'gsc_sync_wait_seconds'=>0"),'scheduler SERP path uses short upstream timeout and non-blocking GSC evidence collection');

fwrite(STDOUT,"PHP scheduler topology v17 smoke: 8 assertions passed\n");
