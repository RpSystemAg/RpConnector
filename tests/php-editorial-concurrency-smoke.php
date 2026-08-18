<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);

final class WP_Error {
    private string $code; private string $message; private $data;
    public function __construct(string $code='', string $message='', $data=null){$this->code=$code;$this->message=$message;$this->data=$data;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v):bool{return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_title($v){$v=strtolower(trim((string)$v));$v=preg_replace('/[^a-z0-9]+/','-',$v);return trim($v,'-');}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_parse_args($a,$b=[]){return array_merge($b,is_array($a)?$a:[]);}
function esc_url_raw($v){return trim((string)$v);}

$store=sys_get_temp_dir().'/prstudio-editorial-concurrency-'.getmypid().'.json';
file_put_contents($store,'{}');
$GLOBALS['editorial_store']=$store;
function opt_mut(callable $fn){$p=$GLOBALS['editorial_store'];$fh=fopen($p,'c+');flock($fh,LOCK_EX);rewind($fh);$raw=stream_get_contents($fh);$d=json_decode($raw?:'{}',true);if(!is_array($d))$d=[];$r=$fn($d);ftruncate($fh,0);rewind($fh);fwrite($fh,json_encode($d));fflush($fh);flock($fh,LOCK_UN);fclose($fh);return$r;}
function get_option($k,$d=false){$p=$GLOBALS['editorial_store'];$fh=fopen($p,'c+');flock($fh,LOCK_SH);rewind($fh);$raw=stream_get_contents($fh);$data=json_decode($raw?:'{}',true);flock($fh,LOCK_UN);fclose($fh);return is_array($data)&&array_key_exists($k,$data)?$data[$k]:$d;}
function update_option($k,$v,$autoload=null){return opt_mut(function(&$d)use($k,$v){$d[$k]=$v;return true;});}
function add_option($k,$v='',$deprecated='',$autoload=null){return opt_mut(function(&$d)use($k,$v){if(array_key_exists($k,$d))return false;$d[$k]=$v;return true;});}
function delete_option($k){return opt_mut(function(&$d)use($k){if(!array_key_exists($k,$d))return false;unset($d[$k]);return true;});}

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-editorial-autonomy.php';
function failx($m){fwrite(STDERR,"FAIL $m\n");@unlink($GLOBALS['editorial_store']);exit(1);}function passx($m){fwrite(STDOUT,"PASS $m\n");}
if(!function_exists('pcntl_fork'))failx('pcntl_fork unavailable');
$out=sys_get_temp_dir().'/prstudio-editorial-out-'.getmypid();@mkdir($out,0700,true);
$go=$out.'/go';

// Concurrent distinct campaign upserts validate technical state atomicity without application quotas.
@unlink($go);$pids=[];$m=16;
for($i=0;$i<$m;$i++){
  $pid=pcntl_fork();if($pid===-1)failx('fork failed');
  if($pid===0){while(!file_exists($go))usleep(1000);$r=PRSTUDIO_UC_Editorial_Autonomy::campaign_manager(['operation'=>'upsert','campaign_id'=>'race-'.$i,'primary_keyword'=>'kw-'.$i,'primary_url'=>'/race-'.$i.'/']);file_put_contents($out.'/c-'.$i.'.json',json_encode(is_wp_error($r)?['error'=>$r->get_error_code()]:$r));exit(is_wp_error($r)?2:0);} $pids[]=$pid;
}
usleep(20000);file_put_contents($go,'go');foreach($pids as $pid){$st=0;pcntl_waitpid($pid,$st);if(!pcntl_wifexited($st)||pcntl_wexitstatus($st)!==0)failx('campaign child failed');}
$list=PRSTUDIO_UC_Editorial_Autonomy::campaign_manager(['operation'=>'list']);$ids=array_column($list['campaigns']??[],'id');for($i=0;$i<$m;$i++){if(!in_array('race-'.$i,$ids,true))failx('lost campaign update race-'.$i);}passx("$m concurrent campaign upserts are preserved without lost updates");

// Stale editor mutex should recover after a crashed process.
add_option('prstudio_uc_editorial_autonomy_mutex_v1',['owner'=>'dead','expires_at'=>microtime(true)-1],'',false);$r=PRSTUDIO_UC_Editorial_Autonomy::campaign_manager(['operation'=>'upsert','campaign_id'=>'after-crash','primary_keyword'=>'after crash','primary_url'=>'/after-crash/']);if(is_wp_error($r))failx('stale editorial mutex did not self-heal');passx('stale editorial mutex self-heals');

foreach(glob($out.'/*')?:[] as $f)@unlink($f);@rmdir($out);@unlink($store);fwrite(STDOUT,"OK editorial concurrency smoke\n");
