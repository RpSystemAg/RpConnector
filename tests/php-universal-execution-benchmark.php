<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', sys_get_temp_dir().'/prstudio-bench/');
$root=rtrim((string)(getenv('PRSTUDIO_BENCH_ROOT')?:dirname(__DIR__)),'/\\');
$control=$root.'/prstudio-unified-control';
$mode=is_file($control.'/includes/class-prstudio-uc-execution-router.php')?'after':'before';
$GLOBALS['bench_counts']=[];$GLOBALS['bench_jobs']=[];$GLOBALS['bench_cap']=[];
function bc(string $k,int $n=1):void{$GLOBALS['bench_counts'][$k]=($GLOBALS['bench_counts'][$k]??0)+$n;}
final class WP_Error{public function __construct(private string $c,private string $m='',private array $d=[]){ }public function get_error_code(){return $this->c;}public function get_error_message(){return $this->m;}public function get_error_data(){return $this->d;}}
function is_wp_error($v):bool{return $v instanceof WP_Error;}
function sanitize_text_field($v):string{return trim(strip_tags((string)$v));}
function sanitize_key($v):string{return trim((string)preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)),'-_');}
function wp_generate_uuid4():string{return '00000000-0000-4000-8000-'.str_pad((string)random_int(1,999999999999),12,'0',STR_PAD_LEFT);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function current_time($t,$gmt=false){return gmdate('Y-m-d H:i:s');}
function gmdate_stub(){return gmdate('c');}
$GLOBALS['wpdb']=(object)['num_queries'=>0,'queries'=>[]];
final class PRSTUDIO_UC_Capability_Registry{public static function get($id){bc('registry');return $GLOBALS['bench_cap'][$id]??null;}}
final class PRSTUDIO_UC_Schema_Validator{public static function validate($a,$s){bc('schema');return [];}}
final class PRSTUDIO_UC_Impact_Engine{public static function analyze($c,$a){bc('impact');return ['scope'=>'fixture'];}}
final class PRSTUDIO_UC_Risk_Engine_V3{public static function evaluate($c,$a,$i,$d,$x){bc('risk');return ['allowed'=>true];}}
final class PRSTUDIO_UC_Planner_V3{public static function plan($c,$a,$i){bc('planner');return ['steps'=>[['id'=>'primitive']],'hash'=>hash('sha256',(string)$c['id'])];}}
final class PRSTUDIO_UC_Memory{public static function site_identity(){bc('memory_identity');return ['key'=>'bench'];}public static function movement(...$a){bc('memory_movement');}public static function mission(...$a){bc('mission_memory');return [];}}
final class PRSTUDIO_UC_Idempotency{public static function canonical_json($v){bc('idempotency');return json_encode($v);}}
final class PRSTUDIO_UC_Store{
 public static function create_job($o,$d,$c,$p,$k,$h,$m){bc('job_create');$id='job-'.count($GLOBALS['bench_jobs']);$j=['job_uuid'=>$id,'status'=>'READY','attempts'=>0,'progress'=>0];$GLOBALS['bench_jobs'][$id]=$j;return $j;}
 public static function set_job_context($id,$v){bc('job_context');return true;} public static function get_job($id){bc('job_get');return $GLOBALS['bench_jobs'][$id]??null;}
 public static function terminal_job_state($s){return in_array($s,['COMPLETED','TECHNICAL_ERROR','ANTI_CRASH'],true);} public static function set_job_state($id,$state,$fields=[]){bc('job_state');$GLOBALS['bench_jobs'][$id]=array_merge($GLOBALS['bench_jobs'][$id]??['job_uuid'=>$id],$fields,['status'=>$state]);return true;}
}
final class PRSTUDIO_UC_Performance_Budget{public static function normalize($v){bc('budget_normalize');return [];}public static function exceeded($b,$m){bc('budget_check');return ['exceeded'=>false];}}
final class PRSTUDIO_UC_Pre_Mutation_Safety{public static function scope_for_capability($c){return empty($c['read_only'])?'wordpress':'none';}public static function before_commit($s,$o,$a){bc('anti_crash');return ['ok'=>true];}}
final class PRSTUDIO_UC_Verification_Engine_V3{public static function verify($c,$a,$r){bc('verification_framework');return ['ok'=>true,'verifier'=>'fixture','source'=>'fixture'];}}
final class PRSTUDIO_UC_Evidence_Engine{public static function receipt($c,$r,$v){bc('evidence');return ['verified'=>true,'evidence_hash'=>'fixture'];}}
final class PRSTUDIO_UC_Procedural_Skills{public static function learn_verified_capability(...$a){bc('procedural_learning');return ['ok'=>true,'learned'=>true];}public static function observe_failure(...$a){bc('procedural_failure');}}
final class BenchExecutor{public static function run(array $args,array $cap=[]){bc('primitive');$x=hash('sha256',json_encode($args));return ['verified'=>true,'value'=>$x,'affected_rows'=>empty($cap['read_only'])?1:0];}}
if($mode==='after')require $control.'/includes/class-prstudio-uc-execution-router.php';
require $control.'/includes/class-prstudio-uc-execution-gateway.php';
function pct(array $v,float $p):float{sort($v,SORT_NUMERIC);$i=max(0,min(count($v)-1,(int)ceil(count($v)*$p)-1));return $v[$i];}
$cases=[
 ['content_read','content','low',true],['content_write','content','low',false],['option_read','content','low',true],['option_write','content','low',false],
 ['metadata','content','low',false],['taxonomy','content','low',false],['product_read','commerce','low',true],['product_write','commerce','low',false],
 ['order_query','commerce','low',true],['db_select','database','low',true],['db_update','database','low',false],['db_bulk_cleanup','database','high',false],
 ['db_optimize','database','high',false],['cache_clear','operations','low',false],['filesystem_read','files','low',true],['filesystem_patch','files','low',false],
 ['php_lint','operations','low',true],['theme_modification','files','low',false],['plugin_modification','files','low',false],['media_metadata','content','low',false],
 ['seo_operation','seo','low',false],['browser_flow','browser','medium',false],['health_status','operations','low',true],['capability_discovery','intelligence','low',true],
 ['multi_capability_flow','operations','medium',false],['agentic_task','operations','high',false],
];
$out=[];
foreach($cases as [$name,$domain,$cost,$read]){
 $id=$name==='agentic_task'?'agency.playbook.run':str_replace('_','.',$name);
 $cap=['id'=>$id,'domain'=>$domain,'estimated_cost'=>$cost,'read_only'=>$read,'browser_required'=>$domain==='browser','executor'=>'BenchExecutor::run','input_schema'=>[],'snapshot_policy'=>'none','verification_policy'=>'minimal'];
 $GLOBALS['bench_cap'][$id]=$cap;$times=[];$aggregate=[];$success=0;
 for($i=0;$i<20;$i++){
  $GLOBALS['bench_counts']=[];$GLOBALS['bench_jobs']=[];$t=hrtime(true);$r=PRSTUDIO_UC_Execution_Gateway::execute(['capability'=>$id,'arguments'=>['id'=>123,'iteration'=>$i],'request_id'=>$name.'-'.$i]);$ms=(hrtime(true)-$t)/1e6;$times[]=$ms;if(!is_wp_error($r))$success++;
  foreach($GLOBALS['bench_counts'] as $k=>$v)$aggregate[$k]=($aggregate[$k]??0)+$v;
 }
 foreach($aggregate as $k=>$v)$aggregate[$k]=$v/20;
 $out[$name]=['p50_ms'=>round(pct($times,.50),4),'p90_ms'=>round(pct($times,.90),4),'p95_ms'=>round(pct($times,.95),4),'max_ms'=>round(max($times),4),'success_rate'=>$success/20,'avg_internal_counts'=>$aggregate,'route'=>($name==='agentic_task'||$mode==='before')?'complex':'fast_inline'];
}
echo json_encode(['mode'=>$mode,'root'=>$root,'runs_per_case'=>20,'cases'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
