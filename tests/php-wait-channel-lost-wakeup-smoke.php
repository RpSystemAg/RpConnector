<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
define('WP_CONTENT_DIR', sys_get_temp_dir().'/prstudio-wait-channel-test-'.getmypid());
@mkdir(WP_CONTENT_DIR, 0700, true);
$GLOBALS['prstudio_cache']=[];
$GLOBALS['prstudio_options']=[];
function wp_cache_get($key,$group='',$force=false,&$found=null){$k=$group.'|'.$key;$found=array_key_exists($k,$GLOBALS['prstudio_cache']);return $found?$GLOBALS['prstudio_cache'][$k]:false;}
function wp_cache_set($key,$value,$group='',$ttl=0){$GLOBALS['prstudio_cache'][$group.'|'.$key]=$value;return true;}
function get_option($key,$default=false){return $GLOBALS['prstudio_options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['prstudio_options'][$key]=$value;return true;}
function wp_mkdir_p($dir){return is_dir($dir)||mkdir($dir,0700,true);}
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-wait-channel.php';
$claims=0;
$start=microtime(true);
$out=PRSTUDIO_UC_Wait_Channel::wait_for_work('device-test',1,function()use(&$claims){
    $claims++;
    if($claims===1){PRSTUDIO_UC_Wait_Channel::signal('lost_wakeup_test');return null;}
    return ['task_uuid'=>'task-fast'];
});
$elapsed=(microtime(true)-$start)*1000;
if(($out['task']['task_uuid']??'')!=='task-fast'){fwrite(STDERR,"FAIL task was not delivered\n");exit(1);}
if($elapsed>350){fwrite(STDERR,"FAIL lost wakeup added excessive latency: {$elapsed}ms\n");exit(1);}
if(!in_array($out['mode']??'', ['signalled','immediate'], true)){fwrite(STDERR,"FAIL unexpected mode\n");exit(1);}
fwrite(STDOUT,"PASS lost-wakeup race closes in ".round($elapsed,1)."ms with claims={$claims}\n");
