<?php
define('PRSTUDIO_UC_TESTING', true);
function sanitize_key($v){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
require $argv[1] ?? __DIR__ . '/../prstudio-unified-control/includes/class-prstudio-uc-idempotency.php';
function ok($cond,$msg){ if(!$cond){fwrite(STDERR,"FAIL $msg\n"); exit(1);} }
set_error_handler(function($severity,$message){throw new ErrorException($message,0,$severity);});
ok(PRSTUDIO_UC_Idempotency::explicit_key(['request_id'=>'  req-123  '])==='req-123','valid explicit string preserved');
try{$x=PRSTUDIO_UC_Idempotency::explicit_key(['request_id'=>new class{public function __toString(){return 'shadow-key';}}]);}catch(Throwable $e){fwrite(STDERR,"FAIL stringable key threw ".get_class($e)."\n");exit(1);}ok($x==='','non-string explicit key must not synthesize identity');
try{$x=PRSTUDIO_UC_Idempotency::explicit_key(['request_id'=>new stdClass]);}catch(Throwable $e){fwrite(STDERR,"FAIL hostile key threw ".get_class($e)."\n");exit(1);}ok($x==='','non-string object key total');
ok(PRSTUDIO_UC_Idempotency::storage_key(' scope ',' key ')===hash('sha256',"scope\nkey"),'storage key deterministic');
ok(PRSTUDIO_UC_Idempotency::canonical_json(['b'=>2,'a'=>1])==='{"a":1,"b":2}','associative order canonical');
ok(PRSTUDIO_UC_Idempotency::plan_hash('Do Thing',['b'=>2,'request_id'=>'r','a'=>1])===PRSTUDIO_UC_Idempotency::plan_hash('Do Thing',['a'=>1,'b'=>2,'idempotency_key'=>'other']),'request identity controls excluded from plan hash');
$u1=PRSTUDIO_UC_Idempotency::canonical_json(['x'=>"\xFF"]);$u2=PRSTUDIO_UC_Idempotency::canonical_json(['x'=>"\xFE"]);ok($u1!==$u2 && $u1!=='null' && $u2!=='null','invalid UTF-8 must not collapse distinct plans to null/same hash');
$n=PRSTUDIO_UC_Idempotency::canonical_json(['x'=>NAN]);$p=PRSTUDIO_UC_Idempotency::canonical_json(['x'=>INF]);$m=PRSTUDIO_UC_Idempotency::canonical_json(['x'=>-INF]);ok(count(array_unique([$n,$p,$m]))===3 && !in_array('null',[$n,$p,$m],true),'non-finite numeric states remain deterministic and distinct');
restore_error_handler(); echo "PASS idempotency\n";
