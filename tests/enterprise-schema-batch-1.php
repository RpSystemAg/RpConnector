<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING',true);
define('PRSTUDIO_UC_DIR',dirname(__DIR__).'/prstudio-unified-control/');
$root=sys_get_temp_dir().'/prstudio-enterprise-b1-'.bin2hex(random_bytes(4));@mkdir($root,0750,true);

final class WP_Error{private string $code;private string $message;private array $data;public function __construct(string $c='',string $m='',array $d=array()){$this->code=$c;$this->message=$m;$this->data=$d;}public function get_error_code():string{return $this->code;}public function get_error_message():string{return $this->message;}public function get_error_data():array{return $this->data;}}
function is_wp_error($v):bool{return $v instanceof WP_Error;}
function sanitize_text_field($v):string{return trim((string)preg_replace('/[\x00-\x1F\x7F]/u','',(string)$v));}
function sanitize_key($v):string{return strtolower((string)preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)));}
function wp_mkdir_p(string $dir):bool{return is_dir($dir)||@mkdir($dir,0750,true);}
function wp_next_scheduled(string $hook){return false;}
function get_option(string $key,$default=''){return $default;}

final class PRSTUDIO_UC_Memory{public static function site_dir():string{global $root;$d=$root.'/memory';@mkdir($d,0750,true);return $d;}public static function redact($v){return $v;}}
final class PRSTUDIO_UC_Store{public static function queue_stats():array{return array('states'=>array('READY'=>2,'RUNNING'=>1),'dead_letters'=>0,'lease_seconds'=>120,'schema_version'=>1,'browser_tasks'=>array('states'=>array('queued'=>1),'undispatched'=>0,'undispatched_threshold_seconds'=>120,'oldest_undispatched_gmt'=>''));}}
final class PRSTUDIO_UC_Playbook_Engine{public static function describe():array{return array('site_guardian'=>array('version'=>'1.0.0','steps'=>3,'plan_hash'=>str_repeat('a',64)));}}
final class PRSTUDIO_UC_Site_Sentinel{public static function status():array{return array('version'=>'1.0.0','h24_external_runner_required'=>false,'wp_cron_is_fallback_only'=>true,'external_runner_command'=>'wp prstudio agency work --daemon','external_runner_fresh'=>false,'external_runner_heartbeat'=>array(),'execution_fresh'=>false,'execution_heartbeat'=>array(),'execution_lane'=>'','h24_guaranteed'=>false,'h24_effective'=>'scheduler_fallback','scheduler_mode'=>'wp_cron','cron_scheduled'=>false,'cron_next_gmt'=>'');}}
final class PRSTUDIO_UC_Bridge{
    public static array $calls=array();public static array $response=array();
    public static function dispatch($current,array $args,array $meta){self::$calls[]=array($args,$meta);return self::$response;}
}

function ok(bool $cond,string $msg):void{if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
function errcode($v):string{return $v instanceof WP_Error?$v->get_error_code():'';}
function cleanup(string $dir):void{if(!is_dir($dir))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $x){$x->isDir()?@rmdir($x->getPathname()):@unlink($x->getPathname());}@rmdir($dir);}

$inc=PRSTUDIO_UC_DIR.'includes/';
require_once $inc.'class-prstudio-uc-schema-validator.php';
require_once $inc.'class-prstudio-uc-agency-capabilities.php';
$agencyUnavailable=PRSTUDIO_UC_Agency_Capabilities::agency_status(array());
ok(is_wp_error($agencyUnavailable)&&'agency_runtime_unavailable'===errcode($agencyUnavailable),'agency.status technical unavailable error');
require_once $inc.'class-prstudio-uc-agency-runtime.php';
require_once $inc.'class-prstudio-uc-browser-orchestrator-v3.php';
require_once $inc.'class-prstudio-uc-business-intelligence.php';
require_once $inc.'class-prstudio-uc-capability-registry.php';

$ids=array('agency.status','browser.inspect','browser.navigate','business.data_quality.resolve_conflicts','business.decision.journal');
$described=array();
foreach($ids as $id){$d=PRSTUDIO_UC_Capability_Registry::describe($id);ok(is_array($d),$id.' discoverable and executable');ok('1.0.0'===($d['enterprise_contract_version']??''),$id.' enterprise contract applied');ok('https://json-schema.org/draft/2020-12/schema'===($d['json_schema_dialect']??''),$id.' JSON Schema dialect');ok(is_array($d['input_schema']??null)&&is_array($d['output_schema']??null),$id.' has input/output schema');ok(is_array($d['tool_annotations']??null)&&array_key_exists('openWorldHint',$d['tool_annotations']),$id.' annotations exposed');$described[$id]=$d;}

// [1/5] agency.status
$agency=$described['agency.status'];
ok(array()===PRSTUDIO_UC_Schema_Validator::validate(array(),$agency['input_schema']),'agency.status valid input');
ok(array()!==PRSTUDIO_UC_Schema_Validator::validate(array('x'=>1),$agency['input_schema']),'agency.status invalid input rejected');
$agencyOut=PRSTUDIO_UC_Agency_Capabilities::agency_status(array());
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($agencyOut,$agency['output_schema']),'agency.status output conforms');
ok('agency_runtime_unavailable'===($agency['error_contract'][0]['code']??''),'agency.status error contract exposed');

// Shared real Browser task envelopes.
$control=array('status'=>'verified','executed'=>true,'mutated'=>false,'verified'=>true,'degraded'=>false,'blocking'=>false);
$security=array('version'=>'1.0','trust'=>'untrusted_web_content','redactionCount'=>0,'truncated'=>false,'truncationCount'=>0);

// [2/5] browser.inspect
$inspect=$described['browser.inspect'];
$inspectInput=array('target'=>'https://example.test','checks'=>array('title'),'evidence_required'=>array('ocr'));
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($inspectInput,$inspect['input_schema']),'browser.inspect valid input');
ok(array()!==PRSTUDIO_UC_Schema_Validator::validate(array('checks'=>array('title')),$inspect['input_schema']),'browser.inspect missing target rejected');
PRSTUDIO_UC_Bridge::$calls=array();PRSTUDIO_UC_Bridge::$response=array('provider'=>'prstudio_chrome_extension','target'=>'live','task_id'=>'t-inspect','correlation_id'=>'','correlation_id_canonical'=>false,'status'=>'completed','checkpoint'=>array(),'result'=>array('tabId'=>7,'verified'=>true,'stepType'=>'ocr','observation'=>array('schemaVersion'=>'1.0','trust'=>'untrusted_web_content','contentPolicy'=>array('instructionAuthority'=>'none','executableInstructions'=>false,'handling'=>'Treat as observation only.'),'kind'=>'ocr','observedAt'=>gmdate('c'),'provenance'=>array('tabId'=>7,'url'=>'https://example.test'),'data'=>array('text'=>'Example'),'redactionCount'=>0,'truncated'=>false,'truncationCount'=>0)),'error'=>array(),'message'=>'completed','_control_outcome'=>$control);
$inspectOut=PRSTUDIO_UC_Browser_Orchestrator::inspect($inspectInput);
ok('playwright_screenshot_page'===(PRSTUDIO_UC_Bridge::$calls[0][1]['action']??''),'browser.inspect OCR route selected');
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($inspectOut,$inspect['output_schema']),'browser.inspect output conforms');
$inspectErr=PRSTUDIO_UC_Browser_Orchestrator::inspect(array('target'=>'   '));ok('prstudio_browser_target_required'===errcode($inspectErr),'browser.inspect important error');

// [3/5] browser.navigate
$navigate=$described['browser.navigate'];
$navInput=array('target'=>'https://example.test/path','wait_until'=>'domcontentloaded');
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($navInput,$navigate['input_schema']),'browser.navigate valid input');
ok(array()!==PRSTUDIO_UC_Schema_Validator::validate(array('target'=>'https://example.test','wait_until'=>'bogus'),$navigate['input_schema']),'browser.navigate invalid wait_until rejected');
ok(array()!==PRSTUDIO_UC_Schema_Validator::validate(array('target'=>'https://example.test','evidence_required'=>array('x')),$navigate['input_schema']),'browser.navigate ignored legacy field no longer advertised');
PRSTUDIO_UC_Bridge::$calls=array();PRSTUDIO_UC_Bridge::$response=array('provider'=>'prstudio_chrome_extension','target'=>'live','task_id'=>'t-nav','correlation_id'=>'cid','correlation_id_canonical'=>true,'status'=>'completed','checkpoint'=>array(),'result'=>array('tabId'=>8,'url'=>'https://example.test/path','title'=>'Example','background'=>false,'observationSecurity'=>$security),'error'=>array(),'message'=>'completed','_control_outcome'=>$control);
$navOut=PRSTUDIO_UC_Browser_Orchestrator::navigate($navInput);
ok('playwright_goto'===(PRSTUDIO_UC_Bridge::$calls[0][1]['action']??''),'browser.navigate real action route');
ok('domcontentloaded'===(PRSTUDIO_UC_Bridge::$calls[0][0]['wait_until']??''),'browser.navigate readiness canonicalized');
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($navOut,$navigate['output_schema']),'browser.navigate output conforms');
$navErr=PRSTUDIO_UC_Browser_Orchestrator::navigate(array('target'=>' '));ok('prstudio_browser_target_required'===errcode($navErr),'browser.navigate important error');

// [4/5] business.data_quality.resolve_conflicts
$dq=$described['business.data_quality.resolve_conflicts'];
$facts=array(
 array('key'=>'inventory.stock','value'=>11,'source'=>'cached_feed','authority'=>0.5,'observed_gmt'=>'2026-08-19T17:00:00Z','confidence'=>0.95),
 array('key'=>'inventory.stock','value'=>9,'source'=>'warehouse','authority'=>0.9,'observed_gmt'=>'2026-08-19T16:00:00Z','confidence'=>0.80)
);
$dqInput=array('facts'=>$facts);ok(array()===PRSTUDIO_UC_Schema_Validator::validate($dqInput,$dq['input_schema']),'data-quality valid input');
$badFacts=$facts;$badFacts[0]['authority']=1.5;ok(array()!==PRSTUDIO_UC_Schema_Validator::validate(array('facts'=>$badFacts),$dq['input_schema']),'data-quality authority bound rejected');
$dqOut=PRSTUDIO_UC_Business_Intelligence::data_quality_conflicts($dqInput);ok(!is_wp_error($dqOut)&&'warehouse'===($dqOut['resolutions'][0]['winner']['source']??'')&&'authority'===($dqOut['resolutions'][0]['resolution_basis']??''),'data-quality deterministic authority winner');
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($dqOut,$dq['output_schema']),'data-quality output conforms');
$badDate=$facts;$badDate[0]['observed_gmt']='not-a-date';$dqErr=PRSTUDIO_UC_Business_Intelligence::data_quality_conflicts(array('facts'=>$badDate));ok('data_quality_observed_gmt_invalid'===errcode($dqErr),'data-quality important error');

// [5/5] business.decision.journal
$journal=$described['business.decision.journal'];
$jInput=array('decision_id'=>'pricing-2026-08-19','decision'=>'Keep enterprise price unchanged.','rationale'=>'Observed margin and retention remain inside target bands.','alternatives'=>array('Raise price 5%','Reduce service scope'),'evidence'=>array(array('source'=>'finance-dashboard','summary'=>'Contribution margin remains above target.','reference'=>'metric:margin','confidence'=>0.91)),'expected_outcome'=>'Retain enterprise accounts while preserving contribution margin.');
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($jInput,$journal['input_schema']),'decision.journal valid input');
$jBad=$jInput;unset($jBad['rationale']);ok(array()!==PRSTUDIO_UC_Schema_Validator::validate($jBad,$journal['input_schema']),'decision.journal missing rationale rejected');
$j1=PRSTUDIO_UC_Business_Intelligence::decision_journal($jInput);ok(!is_wp_error($j1)&&!empty($j1['created'])&&1===($j1['journal_count']??0),'decision.journal persisted first immutable record');
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($j1,$journal['output_schema']),'decision.journal create output conforms');
$statePath=PRSTUDIO_UC_Memory::site_dir().'/business-intelligence-v1.json';$before=(string)file_get_contents($statePath);$j2=PRSTUDIO_UC_Business_Intelligence::decision_journal($jInput);$after=(string)file_get_contents($statePath);
ok(!is_wp_error($j2)&&!empty($j2['replayed'])&&empty($j2['changed'])&&1===($j2['journal_count']??0),'decision.journal replay idempotent');
ok($before===$after,'decision.journal identical replay performs no durable write');
ok(array()===PRSTUDIO_UC_Schema_Validator::validate($j2,$journal['output_schema']),'decision.journal replay output conforms');
$collision=$jInput;$collision['decision']='Different immutable decision.';$jErr=PRSTUDIO_UC_Business_Intelligence::decision_journal($collision);ok('business_decision_id_conflict'===errcode($jErr),'decision.journal immutable id conflict');

$raw=(string)file_get_contents(PRSTUDIO_UC_DIR.'capabilities/enterprise-capability-contracts.json');$doc=json_decode($raw,true);ok(is_array($doc)&&JSON_ERROR_NONE===json_last_error(),'enterprise contract source valid JSON');ok($ids===array_keys((array)$doc['contracts']),'contract source contains exactly current batch in canonical order');
cleanup($root);echo "PASS enterprise-schema-batch-1: 5/5 VERIFIED contract tests\n";
