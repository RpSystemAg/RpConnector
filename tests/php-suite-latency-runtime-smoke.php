<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', sys_get_temp_dir().'/prstudio-suite-latency/');
define('WP_CONTENT_DIR', sys_get_temp_dir().'/prstudio-suite-latency-content-'.getmypid());
define('HOUR_IN_SECONDS', 3600);
@mkdir(ABSPATH,0700,true);@mkdir(WP_CONTENT_DIR,0700,true);

final class WP_Error { public function __construct(private string $code='',private string $message='',private $data=null){} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_mkdir_p($d){return is_dir($d)||mkdir($d,0700,true);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
function update_option($k,$v,$autoload=null){$GLOBALS['opts'][$k]=$v;return true;}
function wp_cache_get($k,$g='',$force=false,&$found=null){$id=$g.'|'.$k;$found=array_key_exists($id,$GLOBALS['cache']);return $found?$GLOBALS['cache'][$id]:false;}
function wp_cache_set($k,$v,$g='',$ttl=0){$GLOBALS['cache'][$g.'|'.$k]=$v;return true;}
$GLOBALS['opts']=[];$GLOBALS['cache']=[];

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-wait-channel.php';

function check($ok,$message){if(!$ok){fwrite(STDERR,"FAIL $message\n");exit(1);}fwrite(STDOUT,"PASS $message\n");}

$probes=0;$start=microtime(true);
$out=PRSTUDIO_UC_Wait_Channel::wait_until(1,function()use(&$probes){$probes++;if($probes===1){PRSTUDIO_UC_Wait_Channel::signal_state('state-race');return ['status'=>'RUNNING'];}return ['status'=>'COMPLETED'];},static fn($v)=>is_array($v)&&($v['status']??'')==='COMPLETED');
$elapsed=(microtime(true)-$start)*1000;
check(($out['value']['status']??'')==='COMPLETED','state wait returns terminal value');
check($elapsed<350,'state wait closes lost-wakeup window under 350ms');
check($probes===2,'state wait probes only on initial read plus signal, not SQL-style polling');

final class PRSTUDIO_UC_Playbook_Engine {
    public const VERSION='test';
    public static function build($playbook,$context){$steps=[];for($i=0;$i<5;$i++)$steps[]=['id'=>'s'.$i,'handler'=>'sentinel.scan','arguments'=>[]];return ['hash'=>'plan-hash','steps'=>$steps];}
    public static function describe(){return [];}
}
final class PRSTUDIO_UC_Memory { public static function site_identity(){return ['key'=>'site'];} public static function redact($v){return $v;} }
final class PRSTUDIO_UC_Site_Sentinel { public static function scan($args){return ['ok'=>true];} public static function status(){return [];} public static function record_external_heartbeat($s){} }
final class PRSTUDIO_UC_Store {
    public static array $jobs=[]; private static int $n=0;
    public static function create_job($objective,$domain,$arguments,$plan,$idem,$hash,$options=[]){$id='job-'.(++self::$n);$job=['job_uuid'=>$id,'status'=>'READY','plan'=>$plan,'checkpoint'=>$options['checkpoint']??[],'step_index'=>0,'progress'=>0,'attempts'=>0,'max_attempts'=>5,'lease_token'=>''];self::$jobs[$id]=$job;return $job;}
    public static function get_job($id){return self::$jobs[$id]??null;}
    public static function claim_job($id,$worker){if(!isset(self::$jobs[$id])||!in_array(self::$jobs[$id]['status'],['READY','INTERRUPTED'],true))return null;self::$jobs[$id]['status']='RUNNING';self::$jobs[$id]['attempts']++;self::$jobs[$id]['lease_token']='lease';return self::$jobs[$id];}
    public static function claim_next_job($worker){foreach(array_keys(self::$jobs) as $id){$j=self::claim_job($id,$worker);if($j)return $j;}return null;}
    public static function checkpoint_leased_job($id,$lease,$idx,$checkpoint,$progress=0){self::$jobs[$id]['checkpoint']=$checkpoint;self::$jobs[$id]['step_index']=$idx+1;self::$jobs[$id]['progress']=$progress;return self::$jobs[$id];}
    public static function complete_leased_job($id,$lease,$result,$verification){self::$jobs[$id]['status']='COMPLETED';self::$jobs[$id]['result']=$result;self::$jobs[$id]['verification']=$verification;self::$jobs[$id]['progress']=100;return self::$jobs[$id];}
    public static function release_leased_job($id,$lease,$checkpoint,$idx,$progress,$delay=0){self::$jobs[$id]['status']='READY';self::$jobs[$id]['checkpoint']=$checkpoint;self::$jobs[$id]['step_index']=$idx;return self::$jobs[$id];}
    public static function retry_leased_job($id,$lease,$error){self::$jobs[$id]['status']='READY';return self::$jobs[$id];}
    public static function dead_letter_job($id,$error,$class='',$lease=''){self::$jobs[$id]['status']='DEAD_LETTER';return true;}
    public static function wait_leased_job($id,$lease,$state,$checkpoint){self::$jobs[$id]['status']=$state;self::$jobs[$id]['checkpoint']=$checkpoint;return self::$jobs[$id];}
    public static function schema_ready(){return true;}
    public static function queue_stats(){return [];}
    public static function due_schedules($limit){return [];}
}

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-agency-runtime.php';
$r=PRSTUDIO_UC_Agency_Runtime::submit('five_steps',[],['owner_client_id'=>'client']);
check(!empty($r['started_inline']),'durable agency submission starts immediately');
check(($r['job']['status']??'')==='COMPLETED','five local agency steps complete in one bounded lease without 3-step requeue');
check((int)($r['job']['step_index']??0)===5,'all five local steps executed before return');

$publish=file_get_contents(dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-publish-transaction.php');
$content=file_get_contents(dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-content-transaction.php');
check(str_contains($publish,"'timeout'=>6"),'publish public verification uses bounded 6s request timeout');
check(str_contains($publish,'$budget=5.0'),'publish sitemap verification has a 5s total budget');
check(str_contains($content,"'timeout' => 6"),'content public verification uses bounded 6s request timeout');
check(str_contains($content,'usleep( 75000 )'),'content cache settle fixed delay reduced to 75ms');

fwrite(STDOUT,"OK suite latency runtime smoke\n");
