<?php
declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);

if (!class_exists('PRSTUDIO_UC_Loop_Guard')) {
    final class PRSTUDIO_UC_Loop_Guard {
        public static function check(string $tool,array $args,string $correlation_id,string $owner=''){ return null; }
        public static function record(string $tool,array $args,$turn,string $owner=''): void {}
        public static function resolve(string $correlation_id): void {}
    }
}
define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('PRSTUDIO_UC_PATH', dirname(__DIR__) . '/prstudio-unified-control/');
define('PRSTUDIO_UC_DIR', PRSTUDIO_UC_PATH);

final class WP_Error {
    public function __construct(private string $code='', private string $message='', private $data=null) {}
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
    public function get_error_data(){ return $this->data; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_text_field($value){ return trim(strip_tags((string)$value)); }
function sanitize_textarea_field($value){ return sanitize_text_field($value); }
function sanitize_key($value){ return strtolower((string)preg_replace('/[^a-z0-9_\-]/i', '', (string)$value)); }
function wp_salt($scheme='auth'){ return 'm11-contract-' . $scheme; }
function wp_parse_args($args,$defaults=[]){ return array_merge($defaults,is_array($args)?$args:[]); }
function wp_generate_uuid4(){ return '12345678-1234-4123-a123-' . substr(bin2hex(random_bytes(8)),0,12); }
function wp_json_encode($value,$flags=0){ return json_encode($value,$flags); }
function absint($value){ return abs((int)$value); }
function untrailingslashit($value){ return rtrim((string)$value,"/\\"); }
function trailingslashit($value){ return rtrim((string)$value,"/\\") . '/'; }
function wp_parse_url($value){ return parse_url((string)$value); }
function wp_cache_delete(...$args){ return true; }

$GLOBALS['m11_options']=[];
function get_option($key,$default=false){ return $GLOBALS['m11_options'][$key]??$default; }
function update_option($key,$value,$autoload=null){ $GLOBALS['m11_options'][$key]=$value; return true; }
function add_option($key,$value='',$deprecated='',$autoload=null){ if(array_key_exists($key,$GLOBALS['m11_options']))return false;$GLOBALS['m11_options'][$key]=$value;return true; }
function delete_option($key){ if(!array_key_exists($key,$GLOBALS['m11_options']))return false;unset($GLOBALS['m11_options'][$key]);return true; }

class WP_HTTP_Response {
    protected array $headers=[];
    public function __construct(protected $data=null,protected int $status=200){}
    public function get_data(){return $this->data;}
    public function get_status(){return $this->status;}
    public function header($key,$value){$this->headers[$key]=$value;}
}
final class WP_REST_Response extends WP_HTTP_Response {}
final class WP_REST_Request {
    public function __construct(private string $method,private array $payload){}
    public function get_header($name){return 'mcp-protocol-version'===(string)$name?'2025-06-18':'';}
    public function get_method(){return $this->method;}
    public function get_body(){return (string)json_encode($this->payload);}
    public function get_json_params(){return $this->payload;}
    public function get_params(){return $this->payload;}
}
final class PRSTUDIO_UC_MCP_Auth_V5 {
    public static function permission($write=false){return ['client_id'=>'oauth-a'];}
    public static function bearer_token_from_request(){return 'test-token';}
    public static function verify_access_token($token,$write){return ['client_id'=>'oauth-a'];}
    public static function protected_resource_metadata_url(){return 'https://example.test/oauth';}
    public static function mcp_url(){return 'https://example.test/mcp';}
    public static function status(){return ['ok'=>true];}
}

final class PRSTUDIO_UC_Memory {
    public static function mission(...$args){ return []; }
    public static function movement(...$args){ return []; }
    public static function remember_call(...$args){ return []; }
    public static function redact($value){
        if(!is_array($value))return $value;
        $out=[];
        foreach($value as $key=>$item){$out[$key]=('lane_token'===(string)$key)?'[REDACTED]':self::redact($item);}
        return $out;
    }
}
final class PRSTUDIO_UC_Orchestrator {
    public static function tool_definitions(){return self::definitions(['prstudio_orchestrator_resolve'=>true,'prstudio_orchestrator_domain_actions'=>true,'prstudio_orchestrator_execute'=>false]);}
    private static function definitions(array $names){$out=[];foreach($names as $name=>$read)$out[]=['name'=>$name,'inputSchema'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>true],'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false]];return $out;}
    public static function govern_tool_call($name,$args){return ['executor'=>'wordpress','strategy'=>'direct'];}
    public static function governance_meta($governance){return ['test'=>'m11'];}
}
final class PRSTUDIO_UC_Anti_Crash {
    public static function guard($name,$args){return true;}
    public static function tool_definitions(){
        $names=['prstudio_work_begin'=>false,'prstudio_work_status'=>true,'prstudio_anti_crash_requirements'=>true,'prstudio_anti_crash_run'=>true,'prstudio_anti_crash_submit'=>false,'prstudio_work_finalize'=>false,'prstudio_work_abort'=>false];$out=[];
        foreach($names as $name=>$read)$out[]=['name'=>$name,'inputSchema'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>true],'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>false,'idempotentHint'=>false,'openWorldHint'=>false]];
        return $out;
    }
}
final class WPAIB_Files {
    public static bool $returnNull=false;
    public static function append_file($path,$suffix,$expected){return self::$returnNull?null:['executor'=>'WPAIB_Files::append_file','path'=>$path,'suffix'=>$suffix,'expected_sha256'=>$expected];}
}

require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-execution-lanes.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-agency.php';
require PRSTUDIO_UC_DIR . 'includes/class-wpaib-mcp.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-legacy-capability-executor.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-capability-registry.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-engineering-workbench.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-mcp-v5.php';

function fail(string $message): void { fwrite(STDERR,"FAIL {$message}\n"); exit(1); }
function pass(string $message): void { fwrite(STDOUT,"PASS {$message}\n"); }
function check(bool $condition,string $message): void { if(!$condition)fail($message);pass($message); }

// The MCP result exposes a stable, non-secret handle. The historical token is
// retained only for internal/backward-compatible callers and remains redacted.
$opened=PRSTUDIO_UC_Execution_Lanes::open(['label'=>'M11','chat_key'=>'stable-chat'],['client_id'=>'oauth-a']);
check(!is_wp_error($opened)&&preg_match('/^lane_[a-f0-9]{32}$/',(string)($opened['lane_handle']??''))===1,'context_open returns a stable non-secret lane_handle');
check(is_array(PRSTUDIO_UC_Execution_Lanes::resolve((string)$opened['lane_handle'],'oauth-a')),'lane_handle resolves for its authenticated OAuth client');
check(null===PRSTUDIO_UC_Execution_Lanes::resolve((string)$opened['lane_handle'],'oauth-b'),'lane_handle is rejected for a different OAuth client');
check(null===PRSTUDIO_UC_Execution_Lanes::resolve((string)$opened['lane_handle'],''),'lane_handle cannot resolve without an authenticated OAuth binding');
$clean=(new ReflectionMethod(PRSTUDIO_UC_MCP_V5::class,'clean_result'))->invoke(null,$opened);
check(($clean['lane_handle']??'')===$opened['lane_handle']&&($clean['lane_token']??'')==='[REDACTED]','MCP cleaning preserves lane_handle and never exposes lane_token');

$tools=[];foreach(PRSTUDIO_UC_MCP_V5::tools() as $tool){$tools[$tool['name']]=$tool;}
foreach(['browser_launch','browser_screenshot','prstudio_execute','prstudio_context_heartbeat'] as $name){
    $schema=$tools[$name]['inputSchema']??[];$properties=(array)($schema['properties']??[]);
    check(isset($properties['lane_handle'])&&!isset($properties['lane_token']),'tool '.$name.' advertises only the public lane_handle');
    check(!isset($schema['anyOf']),'tool '.$name.' avoids redundant lane credential schema branches');
}
$missingLaneSchemas=[];$laneSchemaCount=0;
foreach($tools as $name=>$tool){
    $annotations=(array)$tool['annotations'];
    // 17.0 fast-path contract: lanes coordinate Browser/GSC/Local Studio
    // concurrency. Deterministic WordPress/DB/filesystem writes may execute
    // directly without forcing an extra context-open MCP round trip.
    $requiresLane=('prstudio_context_open'!==$name)&&(
        (str_starts_with($name,'browser_')&&'browser_status'!==$name)
        || str_starts_with($name,'gsc_')
        || 'local_studio'===$name
    );
    if(!$requiresLane)continue;$laneSchemaCount++;
    $schema=(array)$tool['inputSchema'];$properties=(array)($schema['properties']??[]);
    if(!isset($properties['lane_handle'])||isset($properties['lane_token']))$missingLaneSchemas[]=$name;
}
check([]===$missingLaneSchemas&&$laneSchemaCount>0,'every lane-bound MCP tool advertises the public handle without leaking the legacy token');
$validateBasic=new ReflectionMethod(PRSTUDIO_UC_MCP_V5::class,'validate_basic');
$emptyArgs=$validateBasic->invoke(null,[],$tools['browser_launch']['inputSchema']);
$handleCredential=$validateBasic->invoke(null,['lane_handle'=>$opened['lane_handle']],$tools['browser_launch']['inputSchema']);
$legacyCredential=$validateBasic->invoke(null,['lane_token'=>$opened['lane_token']],$tools['browser_launch']['inputSchema']);
check($emptyArgs===true&&$handleCredential===true&&$legacyCredential===true,'schema validation preserves legacy lane_token compatibility while lane enforcement remains in call routing');

$heartbeat=PRSTUDIO_UC_Execution_Lanes::heartbeat(['lane_handle'=>$opened['lane_handle'],'ttl_seconds'=>900],['client_id'=>'oauth-a']);
check(!is_wp_error($heartbeat)&&($heartbeat['lane_handle']??'')===$opened['lane_handle'],'execution-lane heartbeat resolves the public lane_handle');
$closed=
$batch=PRSTUDIO_UC_Engineering_Workbench::terminal(['operation'=>'batch_flow','operations'=>[
 ['operation'=>'inventory','path'=>'includes','limit'=>50],
 ['operation'=>'search','path'=>'includes','query'=>'PRSTUDIO_UC_Engineering_Workbench','limit'=>10],
 ['operation'=>'sha256','path'=>'includes/class-prstudio-uc-engineering-workbench.php','limit'=>1],
]]);
check(is_array($batch)&&$batch['ok']===true&&($batch['steps']??0)===3&&($batch['process_spawns']??-1)===0,'engineering batch_flow executes set-based in-process operations without spawn');

PRSTUDIO_UC_Execution_Lanes::close(['lane_handle'=>$opened['lane_handle']],['client_id'=>'oauth-a']);
check(!is_wp_error($closed),'execution-lane close accepts the public lane_handle');
// Re-open for the legacy executor assertions below.
$opened=PRSTUDIO_UC_Execution_Lanes::open(['label'=>'M11 legacy','chat_key'=>'stable-chat-legacy'],['client_id'=>'oauth-a']);

// Direct legacy tools must reach the real WPAIB_MCP dispatcher, return typed technical errors on
// unknown tools, and retain authenticated lane binding for writes.
$cap=['id'=>'legacy.direct.append-file','read_only'=>false,'risk_level'=>'medium','source'=>['kind'=>'legacy_direct_tool','tool_name'=>'append_file']];
$missing=PRSTUDIO_UC_Legacy_Capability_Executor::execute(['path'=>'x','suffix'=>'y','expected_sha256'=>'z','_client_id'=>'oauth-a'],$cap);
check(is_wp_error($missing)&&$missing->get_error_code()==='execution_lane_required','legacy direct write rejects a missing execution lane');
$executed=PRSTUDIO_UC_Legacy_Capability_Executor::execute(['path'=>'x','suffix'=>'y','expected_sha256'=>'z','lane_handle'=>$opened['lane_handle'],'_client_id'=>'oauth-a'],$cap);
check(is_array($executed)&&($executed['executor']??'')==='WPAIB_Files::append_file','legacy direct adapter reaches the actual WPAIB_MCP tool dispatcher');
WPAIB_Files::$returnNull=true;
$empty=PRSTUDIO_UC_Legacy_Capability_Executor::execute(['path'=>'x','suffix'=>'y','expected_sha256'=>'z','lane_handle'=>$opened['lane_handle'],'_client_id'=>'oauth-a'],$cap);
check(is_wp_error($empty)&&$empty->get_error_code()==='wpaib_mcp_legacy_tool_empty_result','legacy direct adapter rejects a null executor result');
WPAIB_Files::$returnNull=false;
$unknown=PRSTUDIO_UC_Legacy_Capability_Executor::execute(['lane_handle'=>$opened['lane_handle'],'_client_id'=>'oauth-a'],['source'=>['kind'=>'legacy_direct_tool','tool_name'=>'definitely_unknown']]);
check(is_wp_error($unknown)&&$unknown->get_error_code()==='wpaib_mcp_unknown_tool','legacy direct adapter rejects unknown tools without null fallback');

$allToolNames=[];foreach(WPAIB_MCP::all_tools() as $tool){$allToolNames[(string)$tool['name']]=true;}
$directCapabilities=array_values(array_filter(PRSTUDIO_UC_Capability_Registry::all(),static fn($cap)=>'legacy_direct_tool'===(string)($cap['source']['kind']??'')));
$unreachableDirect=[];foreach($directCapabilities as $capability){$toolName=(string)($capability['source']['tool_name']??'');if(''===$toolName||!isset($allToolNames[$toolName]))$unreachableDirect[]=$toolName?:'(missing)';}
check(count($directCapabilities)===199&&[]===$unreachableDirect&&is_callable([WPAIB_MCP::class,'call_tool_compat']),'all 199 shipped legacy_direct_tool mappings reach the callable WPAIB_MCP adapter after approval-chain deletion; missing='.implode(',',array_slice($unreachableDirect,0,20)));

// Runtime metadata comes from the canonical WPAIB_MCP tool annotations rather
// than stale generated legacy defaults.
$canonical=[];foreach(WPAIB_MCP::tools() as $tool){$canonical[$tool['name']]=$tool['annotations'];}
foreach(['append_file','truncate_file','patch_file','purge_cache','switch_theme','upsert_term','assign_terms','rank_math_redirect_list','rank_math_redirect_upsert','rank_math_redirect_delete','rank_math_sitemap_invalidate'] as $name){
    $capability=PRSTUDIO_UC_Capability_Registry::get('legacy.direct.'.str_replace('_','-',$name));
    check(is_array($capability),'registry contains '.$name);
    check((bool)$capability['read_only']===(bool)$canonical[$name]['readOnlyHint'],'registry read_only matches WPAIB_MCP for '.$name);
    check((bool)$capability['write']===!(bool)$canonical[$name]['readOnlyHint'],'registry write flag matches WPAIB_MCP for '.$name);
    check((bool)$capability['destructive']===(bool)$canonical[$name]['destructiveHint'],'registry destructive matches WPAIB_MCP for '.$name);
}
$appendDescription=PRSTUDIO_UC_Capability_Registry::describe('legacy.direct.append-file');
check(($appendDescription['input_schema']['required']??[])===['path','suffix','expected_sha256'],'legacy direct capability exposes the exact canonical input schema');
$inspect=PRSTUDIO_UC_Capability_Registry::get('legacy.extensions-themes.themes-manage.inspect-theme-assets');
check(is_array($inspect)&&$inspect['read_only']===true&&$inspect['write']===false&&$inspect['risk_level']==='low'&&$inspect['destructive']===false,'inspect-theme-assets is read-only, low risk and confirmation-free at runtime');
$inspectDescription=PRSTUDIO_UC_Capability_Registry::describe('legacy.extensions-themes.themes-manage.inspect-theme-assets');
check(is_array($inspectDescription)&&(!array_key_exists('confirmation',$inspectDescription)||$inspectDescription['confirmation']===false),'inspect-theme-assets capability description requires no confirmation');
$inspectTool=array_values(array_filter(WPAIB_MCP::all_tools(),static fn($tool)=>'idealmarket_themes_manage_inspect_theme_assets'===(string)($tool['name']??'')))[0]??null;
check(is_array($inspectTool)&&($inspectTool['annotations']['readOnlyHint']??false)===true&&($inspectTool['annotations']['destructiveHint']??true)===false,'inspect-theme-assets canonical WPAIB_MCP tool is read-only and non-destructive');

// Read semantics must be corrected at the canonical PHP authority even while
// generated catalog/index artifacts are being rebuilt for the release.
$readControlActions=[
    ['/system-manage','get_runtime_config'],
    ['/global-search','preview_replace'],['/global-search','verify_replace'],['/global-search','preview_regex_replace'],['/global-search','preview_url_replace'],
    ['/cache-manage','get_rewrite_rules'],
    ['/content-manage','list_post_types'],['/content-manage','get_post_type'],['/content-manage','validate_blocks'],
    ['/widgets-manage','list_widget_types'],
    ['/templates-manage','list_block_templates'],['/templates-manage','get_block_template'],
    ['/plugins-manage','inspect_plugin_settings'],['/plugins-manage','inspect_plugin_rest_routes'],['/plugins-manage','inspect_plugin_blocks'],['/plugins-manage','inspect_plugin_assets'],
    ['/themes-manage','inspect_theme_assets'],['/themes-manage','inspect_theme_rest_routes'],['/themes-manage','inspect_theme_blocks'],
    ['/seo-manage','audit_news_seo'],
    ['/files-manage','audit_run_batch'],
    ['/maintenance-manage','list_updates'],
    ['/frontend-manage','query_selector'],
];
$controlDrift=[];$capabilityDrift=[];
foreach($readControlActions as [$route,$action]){
    $meta=PRSTUDIO_Agency::control_action_by_route($route,$action);
    if(!is_array($meta)||empty($meta['read_only'])||!empty($meta['destructive'])||'low'!==($meta['risk']??''))$controlDrift[]=$route.' '.$action;
    $matches=array_values(array_filter(PRSTUDIO_UC_Capability_Registry::all(),static fn($cap)=>
        'legacy_action'===(string)($cap['source']['kind']??'')
        && $route===(string)($cap['source']['route']??'')
        && $action===(string)($cap['source']['action']??'')
    ));
    if(count($matches)!==1||empty($matches[0]['read_only'])||!empty($matches[0]['write'])||!empty($matches[0]['destructive'])||'low'!==($matches[0]['risk_level']??''))$capabilityDrift[]=$route.' '.$action;
}
check([]===$controlDrift,'all 23 inspection/preview catalog actions are canonical read operations; drift='.implode(',',$controlDrift));
check([]===$capabilityDrift,'all 23 matching capabilities inherit canonical read/risk metadata; drift='.implode(',',$capabilityDrift));

// Invalid paths return WP_Error despite the previous restrictive return type.
try{$badMap=PRSTUDIO_UC_Engineering_Workbench::repo_map(['path'=>'missing-directory-m11']);}catch(Throwable $e){fail('engineering_repo_map threw '.get_class($e).' instead of returning WP_Error');}
check(is_wp_error($badMap)&&$badMap->get_error_code()==='engineering_path_not_found','engineering_repo_map returns a typed missing-directory error');

// proc_get_status often owns the only reliable exit code on Windows; proc_close
// may return -1. Preserve both stream evidence and the observed process code.
$runner=new ReflectionMethod(PRSTUDIO_UC_Engineering_Workbench::class,'run_process');
$process=$runner->invoke(null,[PHP_BINARY,'-r','fwrite(STDOUT,"m11-out"); fwrite(STDERR,"m11-err"); exit(7);'],10);
check(($process['exit_code']??null)===7,'engineering runner preserves the real non-zero process exit code');
check(str_contains((string)($process['stdout']??''),'m11-out')&&str_contains((string)($process['stderr']??''),'m11-err'),'engineering runner preserves stdout and stderr evidence');
$lint=PRSTUDIO_UC_Engineering_Workbench::validate(['profile'=>'php_lint','path'=>'includes/class-prstudio-uc-engineering-workbench.php']);
check(is_array($lint)&&$lint['ok']===true&&($lint['results']['php_lint']['checked']??0)===1,'engineering php_lint accepts a valid PHP file using the recovered exit code');
check(($lint['results']['php_lint']['evidence_preview'][0]['runner']??'')==='php_token_parse_in_process'&&($lint['results']['php_lint']['process_spawns']??-1)===0,'engineering php_lint uses in-process TOKEN_PARSE with zero process spawns');


$batch=PRSTUDIO_UC_Engineering_Workbench::terminal(['operation'=>'batch_flow','operations'=>[
 ['operation'=>'inventory','path'=>'includes','limit'=>50],
 ['operation'=>'search','path'=>'includes','query'=>'PRSTUDIO_UC_Engineering_Workbench','limit'=>10],
 ['operation'=>'sha256','path'=>'includes/class-prstudio-uc-engineering-workbench.php','limit'=>1],
]]);
check(is_array($batch)&&$batch['ok']===true&&($batch['steps']??0)===3&&($batch['process_spawns']??-1)===0,'engineering batch_flow executes set-based in-process operations without spawn');

PRSTUDIO_UC_Execution_Lanes::close(['lane_handle'=>$opened['lane_handle']],['client_id'=>'oauth-a']);
fwrite(STDOUT,"OK M11 core contract smoke\n");
