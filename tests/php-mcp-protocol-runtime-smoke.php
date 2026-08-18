<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);

final class WP_Error { public function __construct(private string $code='',private string $message='',private $data=null){} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function esc_url_raw($v){return (string)$v;}
function wp_salt($scheme='auth'){return 'mcp-protocol-test-'.$scheme;}
function get_option($k,$d=false){global $opts;return $opts[$k]??$d;}
function update_option($k,$v,$autoload=null){global $opts;$opts[$k]=$v;return true;}
$opts=[];

final class WP_REST_Request {
    public function __construct(private string $method, private $payload, private string $protocol='2025-06-18', private array $headers=[]){}
    public function get_header($name){$key=strtolower((string)$name);if($key==='mcp-protocol-version')return $this->protocol;return (string)($this->headers[$key]??'');}
    public function get_method(){return $this->method;}
    public function get_body(){return json_encode($this->payload);}
    public function get_json_params(){return $this->payload;}
    public function get_params(){return is_array($this->payload)?$this->payload:[];}
}
final class WP_REST_Response {
    private array $headers=[];
    public function __construct(private $data=null, private int $status=200){}
    public function header($k,$v){$this->headers[$k]=$v;}
    public function get_data(){return $this->data;}
    public function get_status(){return $this->status;}
    public function get_headers(){return $this->headers;}
}
final class PRSTUDIO_UC_MCP_Auth_V5 {
    public static function permission($write=false){return ['client_id'=>'client'];}
    public static function protected_resource_metadata_url(){return 'https://example.test/.well-known/oauth-protected-resource';}
    public static function mcp_url(){return 'https://example.test/mcp';}
    public static function bearer_token_from_request(){return 'token';}
    public static function verify_access_token($token,$write){return ['client_id'=>'client'];}
}
final class PRSTUDIO_UC_Store {
    public static array $cancelledJobs=[]; public static array $cancelledTasks=[];
    public static function get_job($id){return ['job_uuid'=>$id,'owner_client_id'=>'client','status'=>'RUNNING'];}
    public static function cancel_job($id,$reason='cancelled'){self::$cancelledJobs[$id]=$reason;return ['job_uuid'=>$id,'status'=>'CANCELLED'];}
}
final class PRSTUDIO_UC_Job_Engine {
    public static function cancel_browser_task($id,$device='',$error=[]){PRSTUDIO_UC_Store::$cancelledTasks[$id]=$error;return ['task_uuid'=>$id,'status'=>'cancelled'];}
}

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}fwrite(STDOUT,"PASS $m\n");}
function modern_rpc(int $id,string $method,array $params=[]): array {
    $params['_meta']=[
        'io.modelcontextprotocol/protocolVersion'=>'2026-07-28',
        'io.modelcontextprotocol/clientCapabilities'=>[],
        'io.modelcontextprotocol/clientInfo'=>['name'=>'prstudio-test','version'=>'17.0.0'],
    ];
    return ['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params];
}

$batch=[['jsonrpc'=>'2.0','id'=>1,'method'=>'ping'],['jsonrpc'=>'2.0','id'=>2,'method'=>'ping']];
$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',$batch,'2025-06-18'));
ok($r->get_status()===400&&($r->get_data()['error']['code']??null)===-32600,'MCP 2025-06-18 rejects removed JSON-RPC batching');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',$batch,'2025-03-26'));
ok($r->get_status()===200&&is_array($r->get_data())&&count($r->get_data())===2,'legacy 2025-03-26 compatibility can still process bounded batches');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',['jsonrpc'=>'2.0','id'=>3,'method'=>'ping'],'2025-06-18'));
$data=$r->get_data();
ok($r->get_status()===200&&isset($data['result'])&&is_object($data['result']),'MCP ping returns a prompt empty response object');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',modern_rpc(31,'server/discover'),'2026-07-28',['mcp-method'=>'server/discover']));
$data=$r->get_data();
ok($r->get_status()===200&&($data['result']['ttlMs']??0)===300000&&($data['result']['cacheScope']??'')==='private'&&in_array('2026-07-28',(array)($data['result']['supportedVersions']??[]),true),'MCP 2026 server/discover returns stateless discovery plus private cache hints');
ok(($data['result']['resultType']??'')==='complete','MCP 2026 results carry the required complete resultType');
ok(($data['result']['_meta']['io.modelcontextprotocol/serverInfo']['version']??'')==='17.0.0','MCP 2026 result metadata carries serverInfo in _meta');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',modern_rpc(32,'initialize',['protocolVersion'=>'2026-07-28']),'2026-07-28',['mcp-method'=>'initialize']));
$data=$r->get_data();
ok($r->get_status()===404&&($data['error']['code']??null)===-32601,'MCP 2026 rejects legacy initialize handshake with method-not-found HTTP semantics');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',modern_rpc(33,'ping'),'2026-07-28',['mcp-method'=>'tools/list']));
$data=$r->get_data();
ok($r->get_status()===400&&($data['error']['code']??null)===-32020,'MCP 2026 enforces Mcp-Method request binding');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',['jsonrpc'=>'2.0','id'=>34,'method'=>'ping','params'=>['_meta'=>['io.modelcontextprotocol/protocolVersion'=>'2025-06-18','io.modelcontextprotocol/clientCapabilities'=>[]]]],'2026-07-28',['mcp-method'=>'ping']));
$data=$r->get_data();
ok($r->get_status()===400&&($data['error']['code']??null)===-32020,'MCP 2026 rejects header/body protocol-version mismatch');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',['jsonrpc'=>'2.0','id'=>35,'method'=>'ping','params'=>['_meta'=>['io.modelcontextprotocol/protocolVersion'=>'2026-07-28']]],'2026-07-28',['mcp-method'=>'ping']));
$data=$r->get_data();
ok($r->get_status()===400&&($data['error']['code']??null)===-32602,'MCP 2026 requires per-request client capabilities metadata');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',modern_rpc(36,'future/method'),'2026-07-28',['mcp-method'=>'future/method']));
$data=$r->get_data();
ok($r->get_status()===404&&($data['error']['code']??null)===-32601,'MCP 2026 maps an unknown method to HTTP 404 plus JSON-RPC method-not-found');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',modern_rpc(37,'ping'),'2099-01-01',['mcp-method'=>'ping']));
$data=$r->get_data();
ok($r->get_status()===400&&($data['error']['code']??null)===-32022&&($data['error']['data']['requested']??'')==='2099-01-01'&&in_array('2026-07-28',(array)($data['error']['data']['supported']??[]),true),'MCP 2026 rejects unsupported protocol versions with supported/requested evidence');

$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('GET',modern_rpc(38,'ping'),'2026-07-28',['mcp-method'=>'ping']));
ok($r->get_status()===405&&($r->get_headers()['Allow']??'')==='POST','MCP 2026 rejects GET on the stateless endpoint');
$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('DELETE',modern_rpc(39,'ping'),'2026-07-28',['mcp-method'=>'ping']));
ok($r->get_status()===405,'MCP 2026 rejects DELETE on the stateless endpoint');

$owner=hash_hmac('sha256','client',wp_salt('auth').'|prstudio-mcp-task-owner');
$requestKey=hash_hmac('sha256','42',$owner.'|prstudio-mcp-request');
$job=str_repeat('a',32);$task=str_repeat('b',32);
$opts['prstudio_mcp_v5_task_owners']=[
  $job=>['owner'=>$owner,'type'=>'job','request_key'=>$requestKey,'updated_at'=>time()],
  $task=>['owner'=>$owner,'type'=>'browser_task','request_key'=>$requestKey,'updated_at'=>time()],
];
$r=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',['jsonrpc'=>'2.0','method'=>'notifications/cancelled','params'=>['requestId'=>42,'reason'=>'client timeout']],'2025-06-18'));
ok($r->get_status()===204,'MCP cancellation notification is notification-only');
ok(isset(PRSTUDIO_UC_Store::$cancelledJobs[$job])&&isset(PRSTUDIO_UC_Store::$cancelledTasks[$task])&&((PRSTUDIO_UC_Store::$cancelledTasks[$task]['code']??'')==='mcp_request_cancelled'),'MCP cancellation propagates through parent-aware Browser task cancellation');

fwrite(STDOUT,"OK MCP protocol runtime smoke\n");
