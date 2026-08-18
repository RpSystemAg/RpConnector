<?php
define('PRSTUDIO_UC_TESTING',true);require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-performance-budget.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}}
$d=PRSTUDIO_UC_Performance_Budget::normalize([]);ok($d===['max_operations'=>1000,'max_duration'=>30,'max_concurrency'=>4,'max_network_requests'=>500,'max_retries'=>3,'max_memory'=>128],'defaults');
set_error_handler(function($s,$m){throw new ErrorException($m,0,$s);});
$b=PRSTUDIO_UC_Performance_Budget::normalize(['max_operations'=>'bad','max_duration'=>new stdClass,'max_concurrency'=>false,'max_network_requests'=>null,'max_retries'=>'x','max_memory'=>[]]);ok($b===$d,'malformed budget uses operational defaults');
restore_error_handler();
$b=PRSTUDIO_UC_Performance_Budget::normalize(['max_operations'=>0,'max_duration'=>999,'max_concurrency'=>99,'max_network_requests'=>-1,'max_retries'=>99,'max_memory'=>1]);ok($b===['max_operations'=>1,'max_duration'=>300,'max_concurrency'=>16,'max_network_requests'=>0,'max_retries'=>10,'max_memory'=>16],'explicit numeric bounds');
$r=PRSTUDIO_UC_Performance_Budget::exceeded($d,['operations'=>1000,'duration'=>30]);ok($r['exceeded']===false&&$r['action']==='continue','equal boundary continues');$r=PRSTUDIO_UC_Performance_Budget::exceeded($d,['operations'=>1001]);ok($r['exceeded']===true&&$r['reasons']===['operations']&&$r['action']==='checkpoint_safe','over boundary checkpoints');
ok(PRSTUDIO_UC_Performance_Budget::adaptive_concurrency(4,['http_429'=>true],8)===2,'degrade halves');ok(PRSTUDIO_UC_Performance_Budget::adaptive_concurrency(4,['latency_ratio'=>1.0,'server_stress'=>0.2],8)===5,'healthy grows');ok(PRSTUDIO_UC_Performance_Budget::adaptive_concurrency(4,['latency_ratio'=>1.2,'server_stress'=>0.7],8)===4,'neutral stable');
set_error_handler(function($s,$m){throw new ErrorException($m,0,$s);});
ok(PRSTUDIO_UC_Performance_Budget::adaptive_concurrency(4,['http_429'=>'false','latency_ratio'=>new stdClass,'server_stress'=>[]],8)===5,'malformed adaptive signals neutral');
$r=PRSTUDIO_UC_Performance_Budget::exceeded([],['operations'=>1]);ok($r['exceeded']===false&&$r['budget']['max_operations']===1000,'incomplete budget normalizes before compare');
restore_error_handler();
echo "PASS performance-budget\n";
