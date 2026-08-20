<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');

function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}

final class PRSTUDIO_UC_State_Machine {
    public const COMPLETED='COMPLETED'; public const FAILED='FAILED'; public const CANCELLED='CANCELLED'; public const EXPIRED='EXPIRED';
}
final class PRSTUDIO_UC_Verifier { public static function browser_result($task,$result){return ['ok'=>true];} }
final class PRSTUDIO_UC_Memory {
    public static function redact($value){
        if(!is_array($value))return $value;
        $out=[];
        foreach($value as $key=>$item){
            $out[$key]=preg_match('/password|secret|token|credential|authorization|cookie|session|oauth|private_key/i',(string)$key)?'[REDACTED]':self::redact($item);
        }
        return $out;
    }
}
final class PRSTUDIO_UC_Idempotency {
    public static function explicit_key($v){return '';} public static function storage_key($a,$b){return '';} public static function plan_hash($a,$b){return '';}
    public static function canonical_json($v){return json_encode($v);}
}
final class PRSTUDIO_UC_Store {
    public static array $tasks=[]; public static array $jobs=[]; public static int $recoverTasks=0; public static int $recoverJobs=0;
    public static function get_task($id){return self::$tasks[$id]??null;}
    public static function get_job($id){return self::$jobs[$id]??null;}
    public static function set_job_state($id,$state,$patch=[]){ if(!isset(self::$jobs[$id]))return null; self::$jobs[$id]=array_merge(self::$jobs[$id],$patch,['status'=>$state]); return self::$jobs[$id]; }
    public static function set_verification($id,$v){return true;}
    public static function complete($id,$lease,$result){ if(!isset(self::$tasks[$id]))return null; self::$tasks[$id]['status']='COMPLETED'; self::$tasks[$id]['result']=$result; return self::$tasks[$id]; }
    public static function fail($id,$lease,$error){ if(!isset(self::$tasks[$id]))return null; self::$tasks[$id]['status']='FAILED'; self::$tasks[$id]['error']=$error; return self::$tasks[$id]; }
    public static function cancel($id){ if(!isset(self::$tasks[$id]))return null; self::$tasks[$id]['status']='CANCELLED'; return self::$tasks[$id]; }
    public static function cancel_for_device($id,$device){$t=self::$tasks[$id]??null;if(!$t||($t['device_uuid']??'')!==$device)return null;self::$tasks[$id]['status']='CANCELLED';return self::$tasks[$id];}
    public static function recover_stale_tasks(){self::$recoverTasks++; return 0;}
    public static function recover_stale_jobs(){self::$recoverJobs++; return 0;}
    public static function terminal_browser_tasks_with_waiting_parents($limit=200){return array_values(array_filter(self::$tasks,fn($t)=>in_array($t['status'],['COMPLETED','FAILED','CANCELLED','EXPIRED'],true)));}
    public static function create_task(){return [];}
}

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-job-engine.php';

final class WP_Error { public function __construct(public string $code='',public string $message='',public array $data=[]){} }
final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params=[]){ }
    public function get_param($key){return $this->params[$key]??null;}
    public function offsetExists(mixed $offset): bool {return array_key_exists((string)$offset,$this->params);}
    public function offsetGet(mixed $offset): mixed {return $this->params[(string)$offset]??null;}
    public function offsetSet(mixed $offset,mixed $value): void {$this->params[(string)$offset]=$value;}
    public function offsetUnset(mixed $offset): void {unset($this->params[(string)$offset]);}
}
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-rest.php';

$fails=[]; $passes=[];
$check=function(bool $condition,string $message) use (&$fails,&$passes){if($condition){$passes[]=$message;echo "PASS $message\n";}else{$fails[]=$message;fwrite(STDERR,"FAIL $message\n");}};
function seed(string $status): void {
    PRSTUDIO_UC_Store::$tasks=['task-1'=>['task_uuid'=>'task-1','job_uuid'=>'job-1','device_uuid'=>'device-1','status'=>$status,'result'=>['done'=>true],'error'=>['code'=>'terminal','retryable'=>false]]];
    PRSTUDIO_UC_Store::$jobs=['job-1'=>['job_uuid'=>'job-1','status'=>'WAITING_FOR_BROWSER','checkpoint'=>['browser_task_id'=>'task-1'],'attempts'=>1,'max_attempts'=>3]];
}

seed('COMPLETED');
PRSTUDIO_UC_Store::$tasks['task-1']['result']['access_token']='child-secret';
$r=PRSTUDIO_UC_Job_Engine::complete_browser_task('task-1','stale-lease',['done'=>true],'device-1');
$check(($r['idempotent_replay']??false)===true,'completed child replay is accepted idempotently');
$check((PRSTUDIO_UC_Store::$jobs['job-1']['status']??'')==='READY','completed child replay reconciles stranded WAITING parent');
$check((PRSTUDIO_UC_Store::$jobs['job-1']['checkpoint']['browser_result']['result']['access_token']??'')==='[REDACTED]','completed replay redacts secrets in parent browser handoff');
$check(($r['result']['access_token']??'')==='child-secret','completed replay keeps child task result unchanged');

seed('RUNNING');
$completed=PRSTUDIO_UC_Job_Engine::complete_browser_task('task-1','lease',['done'=>true,'authorization'=>'Bearer child-secret'],'device-1');
$check((PRSTUDIO_UC_Store::$jobs['job-1']['checkpoint']['browser_result']['result']['authorization']??'')==='[REDACTED]','new Browser completion redacts credentials in parent checkpoint');
$check(($completed['result']['authorization']??'')==='Bearer child-secret','new Browser completion keeps operational child result unchanged');

seed('RUNNING');
PRSTUDIO_UC_Job_Engine::fail_browser_task('task-1','lease',['code'=>'browser_failed','refresh_token'=>'failure-secret','retryable'=>false]);
$check((PRSTUDIO_UC_Store::$jobs['job-1']['checkpoint']['browser_error']['refresh_token']??'')==='[REDACTED]','Browser failure redacts secrets in parent checkpoint');
$check((PRSTUDIO_UC_Store::$jobs['job-1']['error']['refresh_token']??'')==='[REDACTED]','Browser failure redacts persisted parent error');

seed('RUNNING');
if(method_exists('PRSTUDIO_UC_Job_Engine','cancel_browser_task')){
    PRSTUDIO_UC_Job_Engine::cancel_browser_task('task-1','device-1',['code'=>'cancelled','message'=>'manual close','retryable'=>false]);
}
$check(method_exists('PRSTUDIO_UC_Job_Engine','cancel_browser_task'),'Job Engine exposes parent-aware Browser cancellation');
$check((PRSTUDIO_UC_Store::$jobs['job-1']['status']??'')!=='WAITING_FOR_BROWSER','Browser child cancellation reconciles parent immediately');

seed('RUNNING');
$rest=PRSTUDIO_UC_REST::cancel_task(new WP_REST_Request(['task'=>'task-1','_prstudio_device'=>['device_uuid'=>'device-1'],'reason'=>'owned_tab_closed_by_user']));
$check(is_array($rest)&&($rest['status']??'')==='CANCELLED','REST Browser cancellation uses parent-aware Job Engine path');
$check((PRSTUDIO_UC_Store::$jobs['job-1']['status']??'')==='CANCELLED','REST cancellation cannot strand WAITING parent');

foreach(['FAILED','CANCELLED','EXPIRED','COMPLETED'] as $status){
    seed($status);
    PRSTUDIO_UC_Job_Engine::recover();
    $check((PRSTUDIO_UC_Store::$jobs['job-1']['status']??'')!=='WAITING_FOR_BROWSER',"recovery reconciles stranded terminal child $status");
}

if($fails){fwrite(STDERR,"SUMMARY ".count($passes)." passed, ".count($fails)." failed\n");exit(1);} echo "SUMMARY ".count($passes)." passed, 0 failed\n";
