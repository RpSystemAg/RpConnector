<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);

final class WP_Error {
    private string $code; private string $message; private $data;
    public function __construct(string $code='', string $message='', $data=null){$this->code=$code;$this->message=$message;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($v):bool{return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_parse_args($a,$b=[]){return array_merge($b,is_array($a)?$a:[]);}
function wp_generate_uuid4(){return substr(bin2hex(random_bytes(16)),0,8).'-'.substr(bin2hex(random_bytes(2)),0,4).'-4'.substr(bin2hex(random_bytes(2)),1,3).'-a'.substr(bin2hex(random_bytes(2)),1,3).'-'.substr(bin2hex(random_bytes(6)),0,12);}
function wp_salt($scheme='auth'){return 'concurrency-test-'.$scheme;}
function absint($v){return abs((int)$v);}

$store = sys_get_temp_dir().'/prstudio-lane-concurrency-'.getmypid().'.json';
file_put_contents($store, '{}');
$GLOBALS['prstudio_test_store'] = $store;

function option_mutate(callable $fn){
    $path=$GLOBALS['prstudio_test_store'];
    $fh=fopen($path,'c+');
    if(!$fh) throw new RuntimeException('Cannot open option store');
    flock($fh, LOCK_EX);
    rewind($fh); $raw=stream_get_contents($fh); $data=json_decode($raw?:'{}',true); if(!is_array($data))$data=[];
    $result=$fn($data);
    ftruncate($fh,0); rewind($fh); fwrite($fh,json_encode($data)); fflush($fh);
    flock($fh, LOCK_UN); fclose($fh);
    return $result;
}
function get_option($k,$d=false){
    $path=$GLOBALS['prstudio_test_store'];
    $fh=fopen($path,'c+'); flock($fh,LOCK_SH); rewind($fh); $raw=stream_get_contents($fh); $data=json_decode($raw?:'{}',true); flock($fh,LOCK_UN); fclose($fh);
    return is_array($data)&&array_key_exists($k,$data)?$data[$k]:$d;
}
function update_option($k,$v,$autoload=null){return option_mutate(function(&$d)use($k,$v){$d[$k]=$v;return true;});}
function add_option($k,$v='',$deprecated='',$autoload=null){return option_mutate(function(&$d)use($k,$v){if(array_key_exists($k,$d))return false;$d[$k]=$v;return true;});}
function delete_option($k){return option_mutate(function(&$d)use($k){if(!array_key_exists($k,$d))return false;unset($d[$k]);return true;});}
function maybe_serialize($data){return (is_array($data)||is_object($data))?serialize($data):(string)$data;}

final class PRSTUDIO_Test_WPDB {
    public string $options='wp_options';
    public function prepare($query,...$args){return ['query'=>$query,'args'=>$args];}
    public function query($prepared):int{
        if(!is_array($prepared)||count($prepared['args']??[])<2)return 0;
        [$option,$expectedSerialized]=$prepared['args'];
        return (int)option_mutate(function(&$data)use($option,$expectedSerialized){
            if(!array_key_exists($option,$data))return 0;
            if(maybe_serialize($data[$option])!==$expectedSerialized)return 0;
            unset($data[$option]);
            return 1;
        });
    }
}
$GLOBALS['wpdb']=new PRSTUDIO_Test_WPDB();

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-execution-lanes.php';

function fail(string $msg):void{fwrite(STDERR,"FAIL $msg\n");@unlink($GLOBALS['prstudio_test_store']);exit(1);}
function pass(string $msg):void{fwrite(STDOUT,"PASS $msg\n");}
function wait_children(array $pids):void{foreach($pids as $pid){$status=0;pcntl_waitpid($pid,$status);if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0)fail('child process failed');}}

if(!function_exists('pcntl_fork')) fail('pcntl_fork unavailable');


// 0) Retrying context_open with the same caller chat_key must resume the same
// lane instead of creating a second workstream after an ambiguous timeout.
$idem1=PRSTUDIO_UC_Execution_Lanes::open(['label'=>'same chat','chat_key'=>'chat-stable-key'],['client_id'=>'oauth-client']);
$idem2=PRSTUDIO_UC_Execution_Lanes::open(['label'=>'same chat retry','chat_key'=>'chat-stable-key'],['client_id'=>'oauth-client']);
if(is_wp_error($idem1)||is_wp_error($idem2)||$idem1['lane_id']!==$idem2['lane_id']||$idem1['lane_token']!==$idem2['lane_token']||empty($idem2['reused']))fail('same chat_key did not idempotently resume one lane');
pass('context_open is idempotent for a stable per-chat key');
PRSTUDIO_UC_Execution_Lanes::close(['lane_token'=>$idem1['lane_token']],['client_id'=>'oauth-client']);

// 1) Concurrent context creation must not lose lanes through get/update races.
$outDir=sys_get_temp_dir().'/prstudio-lane-out-'.getmypid(); @mkdir($outDir,0700,true);
$pids=[]; $children=20;
for($i=0;$i<$children;$i++){
    $pid=pcntl_fork();
    if($pid===-1) fail('fork failed');
    if($pid===0){
        $r=PRSTUDIO_UC_Execution_Lanes::open(['label'=>'chat-'.$i],['client_id'=>'oauth-client']);
        file_put_contents($outDir.'/open-'.$i.'.json',json_encode(is_wp_error($r)?['error'=>$r->get_error_code()]:$r));
        exit(is_wp_error($r)?2:0);
    }
    $pids[]=$pid;
}
wait_children($pids);
$status=PRSTUDIO_UC_Execution_Lanes::status([],['client_id'=>'oauth-client']);
if((int)($status['count']??0)!==$children) fail('concurrent context opens lost updates: '.json_encode($status));
pass('20 concurrent chat contexts are preserved without lost updates');

$lanes=[];
for($i=0;$i<$children;$i++){
    $r=json_decode((string)file_get_contents($outDir.'/open-'.$i.'.json'),true);
    if(!empty($r['lane_token']))$lanes[]=$r;
}
if(count($lanes)<2)fail('not enough lane tokens');
$l1=$lanes[0];$l2=$lanes[1];
$renewResource='wp:post:heartbeat-renew';
$acq=PRSTUDIO_UC_Execution_Lanes::acquire(['lane_token'=>$l1['lane_token'],'resource'=>$renewResource,'ttl_seconds'=>60],['client_id'=>'oauth-client']);
if(is_wp_error($acq))fail('pre-heartbeat lease acquire failed');
$hb=PRSTUDIO_UC_Execution_Lanes::heartbeat(['lane_token'=>$l1['lane_token'],'ttl_seconds'=>900,'resource_ttl_seconds'=>600],['client_id'=>'oauth-client']);
if(is_wp_error($hb)||($hb['resources_renewed']??0)<1)fail('heartbeat did not renew active resource leases');
pass('lane heartbeat renews active resource leases');
PRSTUDIO_UC_Execution_Lanes::release(['lane_token'=>$l1['lane_token'],'resource'=>$renewResource],['client_id'=>'oauth-client']);

// 2) Race two different chats for the same resource repeatedly. Exactly one
// may acquire each fresh resource; the other must return a technical failure with 409-style
// resource_busy_other_context.
$rounds=60;
for($round=0;$round<$rounds;$round++){
    $resource='wp:post:race-'.$round;
    $barrier=$outDir.'/go-'.$round;
    $resA=$outDir.'/a-'.$round.'.json'; $resB=$outDir.'/b-'.$round.'.json';
    @unlink($barrier);
    $pids=[];
    foreach([['lane'=>$l1,'file'=>$resA],['lane'=>$l2,'file'=>$resB]] as $spec){
        $pid=pcntl_fork();
        if($pid===-1)fail('fork failed in race');
        if($pid===0){
            $deadline=microtime(true)+3;
            while(!file_exists($barrier)&&microtime(true)<$deadline){usleep(1000);}
            $r=PRSTUDIO_UC_Execution_Lanes::acquire(['lane_token'=>$spec['lane']['lane_token'],'resource'=>$resource,'ttl_seconds'=>60],['client_id'=>'oauth-client']);
            $out=is_wp_error($r)?['ok'=>false,'error'=>$r->get_error_code()]:['ok'=>true,'lane_id'=>$r['lane_id']??''];
            file_put_contents($spec['file'],json_encode($out));
            exit(0);
        }
        $pids[]=$pid;
    }
    usleep(20000); file_put_contents($barrier,'go');
    wait_children($pids);
    $a=json_decode((string)file_get_contents($resA),true);$b=json_decode((string)file_get_contents($resB),true);
    $wins=(int)!empty($a['ok'])+(int)!empty($b['ok']);
    if($wins!==1)fail("round $round granted $wins concurrent leases: ".json_encode([$a,$b]));
    $loser=!empty($a['ok'])?$b:$a;
    if(($loser['error']??'')!=='resource_busy_other_context')fail("round $round wrong loser result: ".json_encode($loser));
    PRSTUDIO_UC_Execution_Lanes::release(['lane_token'=>$l1['lane_token'],'resource'=>$resource],['client_id'=>'oauth-client']);
    PRSTUDIO_UC_Execution_Lanes::release(['lane_token'=>$l2['lane_token'],'resource'=>$resource],['client_id'=>'oauth-client']);
}
pass("$rounds simultaneous cross-chat lease races allow exactly one owner");

// 3) A stale mutex must self-heal rather than wedge the connector forever.
// This fixture implements the same serialized compare-and-delete primitive used
// by production WordPress, so the stale-lock path is tested without replacing a
// newer owner's lock.
add_option('prstudio_uc_execution_lanes_mutex_v1',['owner'=>'crashed-worker','expires_at'=>microtime(true)-1],'',false);
$r=PRSTUDIO_UC_Execution_Lanes::heartbeat(['lane_token'=>$l1['lane_token'],'ttl_seconds'=>900],['client_id'=>'oauth-client']);
if(is_wp_error($r))fail('stale mutex did not self-heal: '.$r->get_error_code());
pass('stale cross-request mutex self-heals after crashed worker');

// Cleanup.
foreach($lanes as $lane){PRSTUDIO_UC_Execution_Lanes::close(['lane_token'=>$lane['lane_token']],['client_id'=>'oauth-client']);}
foreach(glob($outDir.'/*')?:[] as $f)@unlink($f);@rmdir($outDir);@unlink($store);

fwrite(STDOUT,"OK execution lane concurrency smoke\n");
