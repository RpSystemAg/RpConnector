<?php
define('PRSTUDIO_UC_TESTING',true);require $argv[1];
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}}
$S='PRSTUDIO_UC_State_Machine';
ok($S::can_transition($S::QUEUED,$S::LEASED),'queue can lease');
ok($S::can_transition($S::LEASED,$S::RUNNING),'leased can run');
ok($S::can_transition($S::LEASED,$S::QUEUED),'expired/pre-action lease can requeue');
ok($S::can_transition($S::RUNNING,$S::COMPLETED),'running can complete');
ok($S::can_transition($S::RUNNING,$S::FAILED),'technical execution failure can terminate');
ok(!$S::can_transition($S::RUNNING,$S::QUEUED),'running cannot blindly replay a possibly-applied mutation');
ok($S::recover_status($S::LEASED,true)===$S::QUEUED,'expired leased pre-action requeues');
ok($S::recover_status($S::RUNNING,true)===$S::RUNNING,'expired running remains locally recoverable without human state');
ok($S::recover_status($S::RUNNING,false)===$S::RUNNING,'nonexpired status unchanged');
foreach([$S::COMPLETED,$S::FAILED,$S::CANCELLED,$S::EXPIRED] as $x){ok($S::is_terminal($x),'terminal '.$x);ok(!$S::can_transition($x,$S::QUEUED),'terminal cannot replay '.$x);}
$ref=new ReflectionClass($S);foreach(['HUMAN_TAKEOVER','RESUMING'] as $legacy){ok(!$ref->hasConstant($legacy),'legacy human orchestration state removed: '.$legacy);}
$threw=false;try{$S::assert_transition($S::COMPLETED,$S::QUEUED);}catch(LogicException $e){$threw=true;}ok($threw,'invalid transition assertion throws');
$c=$S::next_checkpoint(['prior'=>1],3,['ok'=>true]);ok($c['prior']===1&&$c['last_completed_step']===3&&$c['last_result']===['ok'=>true]&&is_int($c['updated_at']),'checkpoint progress preserved');
echo "PASS state-machine one-guard\n";
