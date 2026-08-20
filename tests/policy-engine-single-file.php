<?php
define('PRSTUDIO_UC_TESTING',true);
require $argv[1] ?? __DIR__ . '/../prstudio-unified-control/includes/class-prstudio-uc-risk-engine-v3.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}}
$r=PRSTUDIO_UC_Risk_Engine_V3::evaluate(['risk_level'=>'critical'],['confirm'=>false],['write'=>true],false,[]);
ok(($r['risk_level']??'')==='critical','risk classification preserved as telemetry');
ok(($r['risk_score']??null)===5,'risk score preserved as telemetry');
ok(($r['advisory_only']??false)===true,'risk engine is advisory only');
foreach(['allowed','blocked','requires_confirmation','approval_required','review_required'] as $gate){ok(!array_key_exists($gate,$r),'risk telemetry must not emit execution gate: '.$gate);}
$bad=PRSTUDIO_UC_Risk_Engine_V3::evaluate(['risk_level'=>'nonsense'],[],[],false,[]);ok(($bad['risk_level']??'')==='medium','invalid risk label normalizes only telemetry');
echo "PASS risk-telemetry one-guard\n";
