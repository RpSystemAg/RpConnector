<?php

declare(strict_types=1);

define('ABSPATH', sys_get_temp_dir() . '/prstudio-agency-state/');
define('HOUR_IN_SECONDS', 3600);
define('PRSTUDIO_UC_ENABLE_LEGACY_MCP', true);
@mkdir(ABSPATH, 0777, true);

final class WP_Error {
    public function __construct(private string $code, private string $message='', private array $data=[]) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function sanitize_key($v): string { return trim((string)preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$v)), '-_'); }
function sanitize_text_field($v): string { return trim(strip_tags((string)$v)); }
function sanitize_textarea_field($v): string { return trim(strip_tags((string)$v)); }
function wp_json_encode($v,$flags=0): string { return (string)json_encode($v,$flags); }
function wp_generate_password($length=12,$special=true,$extra=false): string { return str_repeat('x', max(1,(int)$length)); }
function current_time($type,$gmt=false): string { return gmdate('Y-m-d H:i:s'); }
function get_option($key,$default=false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key,$value,$autoload=null): bool { $GLOBALS['opts'][$key]=$value; return true; }
function apply_filters($hook,$value,...$args) { return $value; }
function trailingslashit($v): string { return rtrim((string)$v,'/\\').'/'; }

final class WPAIB_Auth { public static function settings(): array { return ['report_email'=>'']; } }
final class WPAIB_Audit { public static array $events=[]; public static function log($event,$status,$ref='',$data=[]): void { self::$events[]=compact('event','status','ref','data'); } public static function recent($n=25): array { return ['items'=>array_slice(self::$events,-$n)]; } }
final class PRSTUDIO_Report { public static array $changes=[]; public static function record_change($scope,$key,$before,$after): void { self::$changes[]=compact('scope','key','before','after'); } public static function flush(): void {} }

require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-agency.php';

function ok(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
function code_of($result): string { if(is_wp_error($result)) return $result->get_error_code(); return (string)($result['error']['code'] ?? ''); }
function job_output(array $result): array { return is_array($result['job']['result']['output'] ?? null) ? $result['job']['result']['output'] : []; }

$GLOBALS['opts']=[];
$assertions=0;
$check=function(bool $c,string $m) use (&$assertions): void { ok($c,$m);$assertions++; };

// 1) Feature flags are persisted telemetry only and cannot veto an executable action.
$r=PRSTUDIO_Agency::execute('feature_flag_manage',['execution_mode'=>'run','payload'=>['operation'=>'upsert','flag'=>'agency_action_product_roadmap_track','enabled'=>false,'rollout_percent'=>0]]);
$check(!is_wp_error($r) && !empty($r['executed']),'feature flag telemetry write executes');
$check(PRSTUDIO_Agency::feature_flag_observed_enabled('agency_action_product_roadmap_track',true)===false,'feature flag telemetry evaluates false');
$r=PRSTUDIO_Agency::execute('product_roadmap_track',['execution_mode'=>'run','payload'=>['operation'=>'upsert','item_id'=>'direct','item'=>['title'=>'Direct execution']]]);
$check(!is_wp_error($r) && !empty($r['executed']) && (job_output($r)['item']['id']??'')==='direct','disabled feature flag cannot veto target action');

// 2) Agent roles are affinity metadata only and do not constrain routing or execution.
$r=PRSTUDIO_Agency::execute('agent_role_define',['execution_mode'=>'run','payload'=>['operation'=>'upsert','role'=>'seo_affinity','affinity_actions'=>['feature_flag_manage']]]);
$check(!empty($r['executed']) && !empty(job_output($r)['affinity_only']),'agent role persists as affinity-only metadata');
$r=PRSTUDIO_Agency::execute('task_assign_route',['execution_mode'=>'run','payload'=>['target_action'=>'product_roadmap_track','target_role'=>'seo_affinity','payload'=>['operation'=>'upsert','item_id'=>'routed','item'=>['title'=>'Routed']]]]);
$check(!is_wp_error($r) && !empty(job_output($r)['routed']),'role affinity never vetoes task routing');
$routedJobId=(string)(job_output($r)['target_job']['job']['id']??'');
PRSTUDIO_Agency::cron_tick();
$check(($GLOBALS['opts']['prstudio_agency_jobs'][$routedJobId]['status']??'')==='completed','queued job executes even when role affinity does not list target action');

// 3) Org chart still rejects structurally impossible cycles: technical data correctness, not governance.
$r=PRSTUDIO_Agency::execute('org_chart_config',['execution_mode'=>'run','payload'=>['operation'=>'set','nodes'=>[['id'=>'a','parent_id'=>'b'],['id'=>'b','parent_id'=>'a']]]]);
$check(code_of($r)==='prstudio_org_cycle','org chart cycle is a technical validation error');
$r=PRSTUDIO_Agency::execute('org_chart_config',['execution_mode'=>'run','payload'=>['operation'=>'set','nodes'=>[['id'=>'director'],['id'=>'seo','parent_id'=>'director']]]]);
$check(!empty($r['executed']) && (job_output($r)['acyclic']??false)===true,'valid org chart executes');

// 4) Budget allocation is business data integrity, not an execution budget/policy veto.
$r=PRSTUDIO_Agency::execute('budget_allocate_across_divisions',['execution_mode'=>'run','payload'=>['operation'=>'set','total_budget'=>100,'allocations'=>['SEO'=>80,'Ads'=>40]]]);
$check(code_of($r)==='prstudio_budget_overallocated','business accounting rejects internally invalid totals');
$r=PRSTUDIO_Agency::execute('budget_allocate_across_divisions',['execution_mode'=>'run','payload'=>['operation'=>'set','total_budget'=>100,'allocations'=>['SEO'=>60,'Ads'=>40]]]);
$check(!empty($r['executed']) && (float)(job_output($r)['remaining']??-1)===0.0,'balanced business budget persists');

// 5) Roadmap and experiment mutations execute directly.
$r=PRSTUDIO_Agency::execute('product_roadmap_track',['execution_mode'=>'run','payload'=>['operation'=>'upsert','item_id'=>'roadmap-1','item'=>['title'=>'Launch','status'=>'planned']]]);
$check(!empty($r['executed']) && (job_output($r)['item']['id']??'')==='roadmap-1','roadmap upsert executes');
$r=PRSTUDIO_Agency::execute('experiment_pipeline_manage',['execution_mode'=>'run','payload'=>['operation'=>'upsert','item_id'=>'exp-1','item'=>['hypothesis'=>'A beats B','status'=>'draft']]]);
$check(!empty($r['executed']) && (job_output($r)['item']['id']??'')==='exp-1','experiment upsert executes');

// 6) Approval-chain action is physically absent; no dead compatibility operation survives.
$actions=PRSTUDIO_Agency::actions();
$check(!array_key_exists('cross_department_approval_chain',$actions),'approval-chain capability is absent from Agency surface');
$r=PRSTUDIO_Agency::execute('cross_department_approval_chain',['execution_mode'=>'run','payload'=>['operation'=>'upsert']]);
$check(is_wp_error($r) && code_of($r)==='prstudio_action_unknown','removed approval-chain action cannot execute as dead code');

fwrite(STDOUT,"PHP agency ONE-GUARD runtime smoke: {$assertions} assertions passed\n");
