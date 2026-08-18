<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
define('HOUR_IN_SECONDS', 3600);

final class WP_Error {
    public function __construct(private string $code='',private string $message='',private $data=null){}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function esc_url_raw($v){return (string)$v;}
function wp_salt($scheme='auth'){return 'oauth-2026-'.$scheme;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function untrailingslashit($v){return rtrim((string)$v,'/');}
function home_url($path=''){return 'https://site.test'.($path?:'');}
function rest_url($path=''){return 'https://site.test/wp-json/'.ltrim($path,'/');}
function admin_url($path=''){return 'https://site.test/wp-admin/'.$path;}
function current_user_can($cap){return true;}
function is_multisite(){return false;}
function is_ssl(){return true;}
function wp_get_environment_type(){return 'production';}
function apply_filters($hook,$value){return $value;}
function wp_remote_retrieve_response_code($r){return (int)($r['response']['code']??0);}
function wp_remote_retrieve_body($r){return (string)($r['body']??'');}
function wp_safe_remote_get($url,$args=[]){global $httpCalls;$httpCalls++;
    return ['response'=>['code'=>200],'body'=>json_encode([
        'client_id'=>$url,
        'client_name'=>'ChatGPT Test Client',
        'redirect_uris'=>['https://chatgpt.com/oauth/callback'],
        'grant_types'=>['authorization_code','refresh_token'],
        'response_types'=>['code'],
        'token_endpoint_auth_method'=>'none',
        'application_type'=>'web',
    ])];
}
function get_option($k,$d=false){global $opts;return $opts[$k]??$d;}
function update_option($k,$v,$autoload=null){global $opts,$updates;$opts[$k]=$v;$updates[$k]=($updates[$k]??0)+1;return true;}
function delete_option($k){global $opts;unset($opts[$k]);}
function get_transient($k){global $transients; if(!isset($transients[$k])) return false; if($transients[$k]['exp']<time()){unset($transients[$k]);return false;} return $transients[$k]['v'];}
function set_transient($k,$v,$ttl){global $transients;$transients[$k]=['v'=>$v,'exp'=>time()+$ttl];return true;}
function delete_transient($k){global $transients;unset($transients[$k]);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}

$opts=[];$transients=[];$updates=[];$httpCalls=0;
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-mcp-auth-v5.php';
function check($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}fwrite(STDOUT,"PASS $m\n");}

$metadata=PRSTUDIO_UC_MCP_Auth_V5::authorization_server_metadata();
check(($metadata['client_id_metadata_document_supported']??false)===true,'OAuth metadata advertises CIMD support');
check(($metadata['authorization_response_iss_parameter_supported']??false)===true,'OAuth metadata advertises RFC 9207 iss responses');
check(isset($metadata['registration_endpoint']),'DCR remains advertised for backwards compatibility');

$ref=new ReflectionMethod(PRSTUDIO_UC_MCP_Auth_V5::class,'client');$ref->setAccessible(true);
$url='https://client.example.test/oauth/client-metadata.json';
$client=$ref->invoke(null,$url);
check(is_array($client)&&($client['client_id']??'')===$url&&($client['metadata_document']??false)===true,'URL-form client_id resolves through CIMD');
$client2=$ref->invoke(null,$url);
check($httpCalls===1,'CIMD result is cached and does not refetch on repeated authorization checks');

// Verify last_used no longer rewrites the token option on every MCP call.
$id='abc123';$access='prstudio_at_'.$id.'_token';
$hashRef=new ReflectionMethod(PRSTUDIO_UC_MCP_Auth_V5::class,'secret_hash');$hashRef->setAccessible(true);
$opts['prstudio_mcp_v5_generation']=1;
$opts['prstudio_mcp_v5_tokens']=[$id=>[
 'id'=>$id,'access_hash'=>$hashRef->invoke(null,$access),'refresh_hash'=>'','access_exp'=>time()+3600,'refresh_exp'=>time()+3600,
 'client_id'=>'client','scope'=>'prstudio.read','resource'=>PRSTUDIO_UC_MCP_Auth_V5::mcp_url(),'generation'=>1,'created_at'=>time(),'last_used'=>time(),
]];
$before=$updates['prstudio_mcp_v5_tokens']??0;
$r=PRSTUDIO_UC_MCP_Auth_V5::verify_access_token($access,false);
check(is_array($r),'access token verifies');
check(($updates['prstudio_mcp_v5_tokens']??0)===$before,'fresh last_used does not rewrite token registry');
$opts['prstudio_mcp_v5_tokens'][$id]['last_used']=time()-301;
$r=PRSTUDIO_UC_MCP_Auth_V5::verify_access_token($access,false);
check(($updates['prstudio_mcp_v5_tokens']??0)===$before+1,'stale last_used persists one bounded durable touch');

fwrite(STDOUT,"OK MCP OAuth 2026 smoke\n");
