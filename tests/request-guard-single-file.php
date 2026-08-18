<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');define('HOUR_IN_SECONDS',3600);
$GLOBALS['transients']=[];$GLOBALS['set_calls']=[];
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['set_calls'][]=[$k,$ttl];$GLOBALS['transients'][$k]=$v;return true;}
require __DIR__.'/../prstudio-unified-control/includes/class-prstudio-uc-idempotency.php';
require __DIR__.'/../prstudio-unified-control/includes/class-prstudio-uc-request-guard.php';
function check(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
$base=['request_id'=>'req-1'];
check(PRSTUDIO_UC_Request_Guard::lookup('/products-manage','update',[])===null,'no-key lookup');
PRSTUDIO_UC_Request_Guard::remember('/products-manage','update',[],['status'=>'completed','executed'=>true,'verified'=>true]);check(count($GLOBALS['set_calls'])===0,'no-key remember');
$response=['status'=>'completed','accepted'=>true,'executed'=>true,'mutated'=>true,'verified'=>true,'result'=>['updated'=>1]];
PRSTUDIO_UC_Request_Guard::remember('/products-manage','update',$base,$response);check(count($GLOBALS['set_calls'])===1&&$GLOBALS['set_calls'][0][1]===3600,'explicit receipt cached');check(!array_key_exists('_prstudio_idempotent_receipt',$response),'input response not mutated');
$replay=PRSTUDIO_UC_Request_Guard::lookup('/products-manage','update',$base);check(is_array($replay)&&$replay['idempotent_replay']===true&&$replay['result']['updated']===1,'completed replay');
check(PRSTUDIO_UC_Request_Guard::lookup('/products-manage','delete',$base)===null,'action scoped');check(PRSTUDIO_UC_Request_Guard::lookup('/themes-manage','update',$base)===null,'route scoped');
$technical=['request_id'=>'technical-1'];$before=count($GLOBALS['set_calls']);PRSTUDIO_UC_Request_Guard::remember('/products-manage','update',$technical,['status'=>'technical_error','executed'=>false,'verified'=>false,'retryable'=>true,'reason'=>'network_timeout']);check(count($GLOBALS['set_calls'])===$before+1,'explicit technical receipt cached deterministically');check(PRSTUDIO_UC_Request_Guard::lookup('/products-manage','update',$technical)['retryable']===true,'technical retry metadata preserved');
$amb=['request_id'=>'ambiguous-1'];$before=count($GLOBALS['set_calls']);PRSTUDIO_UC_Request_Guard::remember('/products-manage','update',$amb,['status'=>'persisted_unverified','executed'=>true,'mutated'=>true,'verified'=>false,'degraded'=>true,'blocking'=>false,'reason'=>'effect_unverified']);check(count($GLOBALS['set_calls'])===$before+1,'executed unverified receipt cached to prevent duplicate mutation');$cached=PRSTUDIO_UC_Request_Guard::lookup('/products-manage','update',$amb);check($cached['idempotent_replay']===true&&$cached['executed']===true&&$cached['degraded']===true,'executed unverified replay preserved');
$nochange=['request_id'=>'verified-no-change'];PRSTUDIO_UC_Request_Guard::remember('/settings-manage','set',$nochange,['status'=>'completed','executed'=>true,'mutated'=>false,'verified'=>true]);check(PRSTUDIO_UC_Request_Guard::lookup('/settings-manage','set',$nochange)['verified']===true,'verified no-change cached');
echo "PASS request-guard one-guard\n";
