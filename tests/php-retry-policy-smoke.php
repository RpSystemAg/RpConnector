<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-retry-policy.php';
function ok(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"BAD: {$message}\n");exit(1);}fwrite(STDOUT,"PASS {$message}\n");}

$delays=[];for($attempt=1;$attempt<=6;$attempt++)$delays[]=PRSTUDIO_UC_Retry_Policy::schedule($attempt,['base_ms'=>500,'factor'=>2.0,'cap_ms'=>8000,'jitter'=>'none']);
ok($delays===[500,1000,2000,4000,8000,8000],'no-jitter backoff deterministic and capped');
$seed=42;$s1=[];$s2=[];for($attempt=1;$attempt<=4;$attempt++){$s1[]=PRSTUDIO_UC_Retry_Policy::schedule($attempt,['base_ms'=>500,'factor'=>2.0,'cap_ms'=>8000,'jitter'=>'full'],$seed);$s2[]=PRSTUDIO_UC_Retry_Policy::schedule($attempt,['base_ms'=>500,'factor'=>2.0,'cap_ms'=>8000,'jitter'=>'full'],$seed);}ok($s1===$s2,'seeded full jitter reproducible');
foreach($s1 as $i=>$delay){$scaled=min(8000,500*(2**$i));ok($delay>=0&&$delay<=$scaled,'full jitter bounded');}
$e=PRSTUDIO_UC_Retry_Policy::schedule(3,['base_ms'=>500,'factor'=>2.0,'cap_ms'=>8000,'jitter'=>'equal'],$seed);ok($e>=2000&&$e<=8000,'equal jitter bounded');

foreach(['http_503','timeout','connection_refused','lock_contention','cdp_attach_timeout','cdp_targets_timeout','page_runtime_timeout','browser_task_expired','prstudio_browser_agent_offline'] as $code){ok(PRSTUDIO_UC_Retry_Policy::classify(['code'=>$code])['transient']===true,"{$code} transient");}
foreach(['invalid_arguments','http_404','browser_task_not_found','browser_effect_unverified','new_browser_context_not_created'] as $code){ok(PRSTUDIO_UC_Retry_Policy::classify(['code'=>$code])['transient']===false,"{$code} permanent");}
ok(PRSTUDIO_UC_Retry_Policy::classify(['code'=>'weird','retryable'=>true])['transient']===true,'explicit retryable flag wins when unambiguous');
ok(PRSTUDIO_UC_Retry_Policy::classify(['code'=>'http_503','retryable'=>false])['transient']===false,'explicit non-retryable flag wins');
$conflict=PRSTUDIO_UC_Retry_Policy::classify(['code'=>'gsc_failure','retryable'=>true,'details'=>['retryable'=>false]]);
ok($conflict['transient']===false&&$conflict['reason']==='conflicting_retryable_flags','contradictory retryable flags fail conservative');
ok(PRSTUDIO_UC_Retry_Policy::classify(['code'=>'mystery_code'])['transient']===false,'unknown code conservative');

$n1=PRSTUDIO_UC_Retry_Policy::next_attempt(1,['code'=>'http_503'],['max_attempts'=>5],$seed);ok($n1['retry']===true&&$n1['attempt']===2&&$n1['delay_ms']>0,'transient schedules next attempt');
$n2=PRSTUDIO_UC_Retry_Policy::next_attempt(5,['code'=>'http_503'],['max_attempts'=>5],$seed);ok($n2['retry']===false&&$n2['reason']==='max_attempts_reached','max attempts stops retry');
$n3=PRSTUDIO_UC_Retry_Policy::next_attempt(2,['code'=>'invalid_arguments'],['max_attempts'=>5],$seed);ok($n3['retry']===false&&str_starts_with($n3['reason'],'not_transient'),'permanent failure never retries');
echo "PASS retry policy smoke complete\n";
