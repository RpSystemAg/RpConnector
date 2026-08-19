<?php
// Self-contained contract/runtime checks for exactly the five capabilities in Enterprise Schema Migration batch 1.
define('PRSTUDIO_UC_TESTING', true);
define('PRSTUDIO_UC_DIR', dirname(__DIR__) . '/prstudio-unified-control/');

class WP_Error {
    public string $code; public string $message; public array $data;
    public function __construct($code, $message, $data = array()) { $this->code=(string)$code; $this->message=(string)$message; $this->data=(array)$data; }
    public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function sanitize_key($value){return strtolower((string)preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value)));}
function sanitize_text_field($value){return trim((string)$value);}
function get_option($key,$default=''){return $default;}
function wp_next_scheduled($hook){return false;}

class PRSTUDIO_UC_Store {
    public static function queue_stats(): array { return array('ready'=>2,'running'=>1,'terminal'=>7); }
}
class PRSTUDIO_UC_Playbook_Engine {
    public static function describe(): array { return array('site_guardian'=>array('version'=>'1.0.0','steps'=>3,'plan_hash'=>str_repeat('a',64))); }
}
class PRSTUDIO_UC_Site_Sentinel {
    public static function status(): array { return array('ok'=>true,'execution'=>'scheduler'); }
}
class PRSTUDIO_UC_Bridge {
    public static array $calls=array();
    public static function dispatch($current,array $args,array $meta){
        self::$calls[]=array($args,$meta);
        return array(
            'provider'=>'prstudio_chrome_extension','target'=>'live','task_id'=>'task-1','correlation_id'=>'','correlation_id_canonical'=>false,
            'status'=>'READY','checkpoint'=>array(),'result'=>array('url'=>$args['url']??''),'error'=>array(),
            'message'=>'Task accodato o in esecuzione.',
            '_control_outcome'=>array('status'=>'queued','executed'=>false,'mutated'=>false,'verified'=>false,'degraded'=>false,'blocking'=>false),
        );
    }
}

require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-agency-runtime.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-agency-capabilities.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-browser-orchestrator-v3.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-business-intelligence.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-capability-registry.php';

function check($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function is_list_array(array $value): bool { return function_exists('array_is_list') ? array_is_list($value) : array_keys($value)===range(0,count($value)-1); }
function resolve_ref(array $root,string $ref): array {
    check(str_starts_with($ref,'#/'),'only local JSON Schema refs are used');
    $node=$root; foreach(explode('/',substr($ref,2)) as $part){$part=str_replace(array('~1','~0'),array('/','~'),$part);check(is_array($node)&&array_key_exists($part,$node),'JSON Schema ref resolves: '.$ref);$node=$node[$part];}
    check(is_array($node),'JSON Schema ref target is a schema object'); return $node;
}
function schema_valid($value,array $schema,array $root=null): bool {
    $root=$root??$schema;
    if(isset($schema['$ref'])) return schema_valid($value,resolve_ref($root,(string)$schema['$ref']),$root);
    if(isset($schema['oneOf'])){ $matches=0; foreach($schema['oneOf'] as $sub){if(schema_valid($value,$sub,$root))$matches++;} return 1===$matches; }
    if(array_key_exists('const',$schema)&&$value!==$schema['const']) return false;
    if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true)) return false;
    if(isset($schema['type'])){
        $types=is_array($schema['type'])?$schema['type']:array($schema['type']);$ok=false;
        foreach($types as $type){
            if('object'===$type && is_array($value) && (empty($value)||!is_list_array($value)))$ok=true;
            elseif('array'===$type && is_array($value) && is_list_array($value))$ok=true;
            elseif('string'===$type && is_string($value))$ok=true;
            elseif('integer'===$type && is_int($value))$ok=true;
            elseif('number'===$type && (is_int($value)||is_float($value)))$ok=true;
            elseif('boolean'===$type && is_bool($value))$ok=true;
            elseif('null'===$type && null===$value)$ok=true;
        }
        if(!$ok)return false;
    }
    if(is_string($value)){if(isset($schema['minLength'])&&strlen($value)<$schema['minLength'])return false;if(isset($schema['maxLength'])&&strlen($value)>$schema['maxLength'])return false;}
    if((is_int($value)||is_float($value))){if(isset($schema['minimum'])&&$value<$schema['minimum'])return false;if(isset($schema['maximum'])&&$value>$schema['maximum'])return false;}
    if(is_array($value)&&is_list_array($value)){
        if(isset($schema['minItems'])&&count($value)<$schema['minItems'])return false;if(isset($schema['maxItems'])&&count($value)>$schema['maxItems'])return false;
        if(isset($schema['items'])&&is_array($schema['items']))foreach($value as $item)if(!schema_valid($item,$schema['items'],$root))return false;
    }
    if(is_array($value)&&(!is_list_array($value)||empty($value))){
        foreach((array)($schema['required']??array()) as $key)if(!array_key_exists($key,$value))return false;
        $properties=(array)($schema['properties']??array());
        foreach($value as $key=>$item){
            if(array_key_exists($key,$properties)){if(!schema_valid($item,$properties[$key],$root))return false;continue;}
            if(false===($schema['additionalProperties']??true))return false;
            if(is_array($schema['additionalProperties']??null)&&!schema_valid($item,$schema['additionalProperties'],$root))return false;
        }
    }
    return true;
}

$contract_path=PRSTUDIO_UC_DIR.'capabilities/enterprise-capability-contracts.json';
$raw=file_get_contents($contract_path);check(is_string($raw)&&''!==$raw,'contract file readable');
$doc=json_decode($raw,true);check(is_array($doc)&&JSON_ERROR_NONE===json_last_error(),'contract file is valid JSON');
$contracts=(array)($doc['contracts']??array());
check(array_keys($contracts)===array('agency.status','browser.inspect','browser.navigate'),'only the three semantically verified capabilities receive overrides in this batch');
foreach($contracts as $id=>$contract){check(is_array($contract['input_schema']??null)&&is_array($contract['output_schema']??null),$id.' has input/output schemas');}

// [1/5] agency.status — no arguments, actual status path, and explicit unavailable variant.
$agency=PRSTUDIO_UC_Capability_Registry::describe('agency.status');check(is_array($agency),'agency.status discoverable');
check(schema_valid(array(),$agency['input_schema']),'agency.status accepts empty input');
check(!schema_valid(array('unexpected'=>true),$agency['input_schema']),'agency.status rejects unknown input');
$agency_out=PRSTUDIO_UC_Agency_Capabilities::agency_status(array());
check(schema_valid($agency_out,$agency['output_schema']),'agency.status success output conforms');
check(schema_valid(array('ok'=>false,'error'=>'agency_runtime_unavailable'),$agency['output_schema']),'agency.status unavailable output conforms');
check(!schema_valid(array('ok'=>false,'error'=>'other'),$agency['output_schema']),'agency.status rejects noncanonical unavailable error');

// [2/5] browser.inspect — valid DOM/OCR dispatch, invalid target, success envelope.
$inspect=PRSTUDIO_UC_Capability_Registry::describe('browser.inspect');check(is_array($inspect),'browser.inspect discoverable');
check(schema_valid(array('target'=>'https://example.test','evidence_required'=>array('ocr')),$inspect['input_schema']),'browser.inspect accepts valid input');
check(!schema_valid(array(),$inspect['input_schema']),'browser.inspect requires target');
check(!schema_valid(array('target'=>'https://example.test','extra'=>1),$inspect['input_schema']),'browser.inspect rejects unknown input');
PRSTUDIO_UC_Bridge::$calls=array();$inspect_out=PRSTUDIO_UC_Browser_Orchestrator::inspect(array('target'=>' https://example.test ','evidence_required'=>array('ocr')));
check('playwright_screenshot_page'===(PRSTUDIO_UC_Bridge::$calls[0][1]['action']??''),'browser.inspect OCR dispatch path');
check(schema_valid($inspect_out,$inspect['output_schema']),'browser.inspect success output conforms');
$inspect_error=PRSTUDIO_UC_Browser_Orchestrator::inspect(array('target'=>'   '));check($inspect_error instanceof WP_Error&&'prstudio_browser_target_required'===$inspect_error->code,'browser.inspect invalid target error contract');

// [3/5] browser.navigate — exact wait modes, invalid schema value, runtime default and envelope.
$navigate=PRSTUDIO_UC_Capability_Registry::describe('browser.navigate');check(is_array($navigate),'browser.navigate discoverable');
check(schema_valid(array('target'=>'https://example.test','wait_until'=>'domcontentloaded'),$navigate['input_schema']),'browser.navigate accepts valid input');
check(!schema_valid(array('target'=>'https://example.test','wait_until'=>'bogus'),$navigate['input_schema']),'browser.navigate schema rejects unsupported wait mode');
PRSTUDIO_UC_Bridge::$calls=array();$navigate_out=PRSTUDIO_UC_Browser_Orchestrator::navigate(array('target'=>'https://example.test','wait_until'=>'DOMCONTENTLOADED'));
check('domcontentloaded'===(PRSTUDIO_UC_Bridge::$calls[0][0]['wait_until']??''),'browser.navigate runtime canonicalizes supported wait mode');
check(schema_valid($navigate_out,$navigate['output_schema']),'browser.navigate success output conforms');
$navigate_error=PRSTUDIO_UC_Browser_Orchestrator::navigate(array('target'=>new stdClass()));check($navigate_error instanceof WP_Error&&'prstudio_browser_target_required'===$navigate_error->code,'browser.navigate invalid target error contract');

// [4/5] and [5/5] are deliberately blocked: registry promises methods absent from the runtime class.
check(!method_exists('PRSTUDIO_UC_Business_Intelligence','data_quality_conflicts'),'business.data_quality.resolve_conflicts blocker reproduced: executor absent');
check(!method_exists('PRSTUDIO_UC_Business_Intelligence','decision_journal'),'business.decision.journal blocker reproduced: executor absent');
check(null===PRSTUDIO_UC_Capability_Registry::describe('business.data_quality.resolve_conflicts'),'blocked data-quality capability is not falsely discoverable as executable');
check(null===PRSTUDIO_UC_Capability_Registry::describe('business.decision.journal'),'blocked decision-journal capability is not falsely discoverable as executable');

$consistency=PRSTUDIO_UC_Capability_Registry::consistency();
check(in_array('business.data_quality.resolve_conflicts',$consistency['missing_executor'],true),'registry consistency reports data-quality executor gap');
check(in_array('business.decision.journal',$consistency['missing_executor'],true),'registry consistency reports decision-journal executor gap');

echo "PASS enterprise-schema-batch-1\n";
