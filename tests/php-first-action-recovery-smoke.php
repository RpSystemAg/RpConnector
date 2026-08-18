<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
final class WP_Error { private string $code; private string $message; private $data; public function __construct(string $c='',string $m='',$d=null){$this->code=$c;$this->message=$m;$this->data=$d;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
function is_wp_error($v):bool{return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_parse_args($a,$b=[]){return array_merge($b,is_array($a)?$a:[]);}
function wp_generate_uuid4(){return '12345678-1234-4123-a123-123456789abc';}
function wp_salt($scheme='auth'){return 'first-action-'.$scheme;}
function absint($v){return abs((int)$v);}
$GLOBALS['opts']=[];
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$d;}
function update_option($k,$v,$autoload=null){$GLOBALS['opts'][$k]=$v;return true;}
function add_option($k,$v='',$deprecated='',$autoload=null){if(array_key_exists($k,$GLOBALS['opts']))return false;$GLOBALS['opts'][$k]=$v;return true;}
function delete_option($k){if(!array_key_exists($k,$GLOBALS['opts']))return false;unset($GLOBALS['opts'][$k]);return true;}
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-execution-lanes.php';
function fail($m){fwrite(STDERR,"FAIL $m\n");exit(1);} function pass($m){fwrite(STDOUT,"PASS $m\n");}
$owner=['client_id'=>'oauth-client'];$args=['label'=>'first action','chat_key'=>'stable-chat-key'];
$times=[];$lane='';$token='';
for($i=0;$i<100;$i++){$t=hrtime(true);$r=PRSTUDIO_UC_Execution_Lanes::open($args,$owner);$times[]=(hrtime(true)-$t)/1e6;if(is_wp_error($r))fail('context_open returned '.$r->get_error_code());if($i===0){$lane=$r['lane_id'];$token=$r['lane_token'];}if($r['lane_id']!==$lane||$r['lane_token']!==$token)fail('ambiguous retry created a second context');if($i>0&&empty($r['reused']))fail('retry did not report reused=true');}
sort($times);$p95=$times[(int)floor((count($times)-1)*0.95)];pass('100 ambiguous context_open retries reuse one lane; p95_ms='.number_format($p95,3,'.',''));
// Simulate a crashed mutex from a prior first action. It must self-heal locally.
$GLOBALS['opts']['prstudio_uc_execution_lanes_mutex_v1']=['owner'=>'dead-worker','expires_at'=>microtime(true)-10];
$r=PRSTUDIO_UC_Execution_Lanes::open($args,$owner);if(is_wp_error($r)||$r['lane_id']!==$lane)fail('stale first-action mutex did not self-heal');pass('stale first-action mutex self-heals without a human or policy gate');
// A retryable technical condition is not a semantic blocked outcome.
if(isset($r['blocked'])&&$r['blocked'])fail('context_open emitted blocked=true');pass('context_open never emits semantic blocked=true');
echo json_encode(['ok'=>true,'iterations'=>100,'p95_ms'=>$p95,'lane_id'=>$lane,'reused'=>true,'evidence_class'=>'MEASURED_LOCAL'],JSON_UNESCAPED_SLASHES)."\n";
