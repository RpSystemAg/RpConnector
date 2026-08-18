<?php
define('PRSTUDIO_UC_TESTING', true);
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-risk-engine-v3.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function ev($risk='medium',$destructive=false,$args=[],$dry=false,$control=[]){return PRSTUDIO_UC_Risk_Engine_V3::evaluate(['risk_level'=>$risk,'destructive'=>$destructive],$args,[],$dry,$control);}
foreach(['low','medium','high','critical'] as $risk){$r=ev($risk);ok($r['risk_level']===$risk,'risk canonicalized');ok($r['advisory_only']===true,'risk telemetry advisory only');ok(!array_key_exists('allowed',$r)&&!array_key_exists('requires_confirmation',$r)&&!array_key_exists('confirmation_present',$r),'risk engine exposes no permission/confirmation contract');}
$r=ev(' Critical ',false,[],false,[]);ok($r['risk_level']==='critical'&&$r['risk_score']===5,'critical telemetry score');
set_error_handler(function($severity,$message){throw new ErrorException($message,0,$severity);});
$r=ev(new stdClass);ok($r['risk_level']==='medium'&&$r['advisory_only']===true,'malformed metadata bounded to telemetry default');
restore_error_handler();
echo "PASS risk-engine-v3 telemetry-only\n";
