<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function gmdate_stub($format): string { return '2026-08-21T00:00:00Z'; }
final class WP_Error {}
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-verification-engine-v3.php';
function check(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL $message\n");exit(1);} echo "PASS $message\n"; }

$read=['id'=>'browser.read','read_only'=>true];
$r=PRSTUDIO_UC_Verification_Engine_V3::verify($read,[],['status'=>'failed','ok'=>false,'error'=>['code'=>'technical_tab_not_controlled']]);
check($r['ok']===false && $r['verifier']==='executor_failure_receipt','read-only failed task is never verified');

$r=PRSTUDIO_UC_Verification_Engine_V3::verify($read,[],['status'=>'completed','ok'=>true,'result'=>['value'=>'x']]);
check($r['ok']===true && $r['verifier']==='read_receipt','successful read receipt remains verified');

$legacy=['id'=>'legacy.browser.write','read_only'=>false];
$r=PRSTUDIO_UC_Verification_Engine_V3::verify($legacy,[],['_control_outcome'=>['executed'=>false,'verified'=>true]]);
check($r['ok']===false,'verified flag cannot override executed=false');

$r=PRSTUDIO_UC_Verification_Engine_V3::verify($legacy,[],['_control_outcome'=>['executed'=>true,'verified'=>true]]);
check($r['ok']===true,'executed verified legacy receipt remains accepted');

echo "PASS verification engine truth regression complete\n";
