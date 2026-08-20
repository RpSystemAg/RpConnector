<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
set_error_handler(static function(int $severity,string $message,string $file,int $line): never { throw new ErrorException($message,0,$severity,$file,$line); });
require __DIR__.'/../prstudio-unified-control/includes/class-prstudio-uc-evidence-engine.php';
function check(bool $ok,string $message): void { if(!$ok){throw new RuntimeException($message);} }

$valid=PRSTUDIO_UC_Evidence_Engine::receipt(
    array('id'=>'catalog.update'),
    array('processed'=>5,'changed'=>2,'failed'=>0,'skipped'=>1,'payload'=>array('ok'=>true)),
    array('requested'=>5,'verified'=>1,'memory_reused'=>2,'sources'=>array('db','browser','db'))
);
check($valid['capability']==='catalog.update','valid capability');
check($valid['counts']===array('requested'=>5,'processed'=>5,'changed'=>2,'verified'=>1,'failed'=>0,'skipped'=>1,'memory_reused'=>2),'valid counts');
check($valid['sources']===array('db','browser'),'valid sources');
check($valid['verified']===true,'valid verified');
check($valid['evidence_hash']===hash('sha256',json_encode(array('processed'=>5,'changed'=>2,'failed'=>0,'skipped'=>1,'payload'=>array('ok'=>true)),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'valid hash compatibility');
check((bool)preg_match('/^[0-9a-f]{64}$/',$valid['evidence_hash']),'hash shape');
check(false!==strtotime($valid['created_at']),'created_at');

$coercions=0;
$magic=new class($coercions) { public function __construct(private int &$calls){} public function __toString(): string {$this->calls++; return 'catalog.update';} };
$malformed=PRSTUDIO_UC_Evidence_Engine::receipt(
    array('id'=>$magic),
    array('processed'=>new stdClass(),'changed'=>-7,'failed'=>'2','skipped'=>INF,'memory'=>array('reused_count'=>array())),
    array('requested'=>-1,'verified'=>new stdClass(),'sources'=>array('db','db',7,new stdClass(),'','browser'))
);
check($coercions===0,'must not execute object string coercion');
check($malformed['capability']==='','malformed capability default');
check($malformed['counts']===array('requested'=>1,'processed'=>1,'changed'=>0,'verified'=>0,'failed'=>2,'skipped'=>0,'memory_reused'=>0),'bounded malformed counts');
check($malformed['sources']===array('db','browser'),'malformed sources filtered');
check($malformed['verified']===false,'malformed verification evidence must not become verified');

$verificationForms=array(
    array(true,1,true),
    array(false,0,false),
    array(2,2,true),
    array('2',2,true),
    array('false',0,false),
    array(array('unexpected'),0,false),
);
foreach($verificationForms as [$input,$expectedCount,$expectedFlag]){
    $receipt=PRSTUDIO_UC_Evidence_Engine::receipt(array('id'=>'x'),array(),array('verified'=>$input));
    check($receipt['counts']['verified']===$expectedCount,'verification count normalization');
    check($receipt['verified']===$expectedFlag,'verification flag normalization');
}

$badUtf8=PRSTUDIO_UC_Evidence_Engine::receipt(array('id'=>'x'),array('payload'=>"\xB1\x31"));
check((bool)preg_match('/^[0-9a-f]{64}$/',$badUtf8['evidence_hash']),'bad utf8 hash shape');
check($badUtf8['evidence_hash']!==hash('sha256',''),'bad utf8 must not collapse to false/empty hash');

echo "PASS evidence-engine-single-file\n";
