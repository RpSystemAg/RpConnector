<?php
define('PRSTUDIO_UC_TESTING',true); require $argv[1] ?? __DIR__ . '/../prstudio-unified-control/includes/class-prstudio-uc-impact-engine.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}}
set_error_handler(function($s,$m){throw new ErrorException($m,0,$s);});
$r=PRSTUDIO_UC_Impact_Engine::analyze(['domain'=>'content','write'=>false,'read_only'=>true,'risk_level'=>'low'],['id'=>7]);
ok($r['entities']===1&&$r['advisory_only']===true&&$r['blocking']===false,'read-only impact is advisory');
$r=PRSTUDIO_UC_Impact_Engine::analyze(['domain'=>'content','write'=>true,'read_only'=>false,'risk_level'=>'medium'],['ids'=>range(1,11)]);
ok($r['entities']===11&&$r['cache_invalidation']===true&&$r['advisory_only']===true&&$r['blocking']===false,'write impact is telemetry only');
foreach(['requires_canary','requires_snapshot','canary_size','execution_budget_hint'] as $k){ok(!array_key_exists($k,$r),"impact must not expose gate key $k");}
$r=PRSTUDIO_UC_Impact_Engine::analyze(['domain'=>'content','write'=>true,'read_only'=>false,'risk_level'=>' Critical '],[]);
ok($r['risk_level']==='critical'&&$r['advisory_only']===true&&$r['blocking']===false,'critical risk is telemetry, never veto');
try{$r=PRSTUDIO_UC_Impact_Engine::analyze(['domain'=>'content','write'=>true,'read_only'=>false,'risk_level'=>'medium'],['ids'=>[new stdClass,'ok'],'limit'=>new stdClass]);}catch(Throwable $e){fwrite(STDERR,"FAIL malformed args threw ".get_class($e)."\n");exit(1);}
ok($r['entities']===1&&$r['known_resources']===['ids:ok'],'malformed resource/limit ignored without widening');
restore_error_handler();echo "PASS impact-engine\n";
