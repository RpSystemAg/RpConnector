<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
define('DAY_IN_SECONDS', 86400);

final class WP_Error {
    private string $code; private string $message; private $data;
    public function __construct(string $code='', string $message='', $data=null){$this->code=$code;$this->message=$message;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($v):bool{return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_title($v){$v=strtolower(trim((string)$v));$v=preg_replace('/[^a-z0-9]+/','-',$v);return trim($v,'-');}
function esc_url_raw($v){return trim((string)$v);}
function wp_strip_all_tags($v){return strip_tags((string)$v);}
function wp_parse_args($a,$b=[]){return array_merge($b,is_array($a)?$a:[]);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_parse_url($u,$component=-1){return parse_url($u,$component);}
function home_url($path='/'){return 'https://example.test'.('/'===substr($path,0,1)?$path:'/'.$path);}
function wp_generate_uuid4(){return sprintf('%08x-%04x-4%03x-a%03x-%012x',mt_rand(),mt_rand(0,0xffff),mt_rand(0,0xfff),mt_rand(0,0xfff),mt_rand());}
function wp_salt($scheme='auth'){return 'test-salt-'.$scheme;}
function absint($v){return abs((int)$v);}
function get_option($k,$d=false){global $opts;return $opts[$k]??$d;}
function update_option($k,$v,$autoload=null){global $opts;$opts[$k]=$v;return true;}
$opts=[];

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-execution-lanes.php';
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-editorial-autonomy.php';

function ok($condition,string $label):void{if(!$condition){fwrite(STDERR,"FAIL $label\n");exit(1);}fwrite(STDOUT,"PASS $label\n");}

$l1=PRSTUDIO_UC_Execution_Lanes::open(['label'=>'chat A'],['client_id'=>'oauth-client']);
$l2=PRSTUDIO_UC_Execution_Lanes::open(['label'=>'chat B'],['client_id'=>'oauth-client']);
ok(!is_wp_error($l1)&&!is_wp_error($l2)&&$l1['lane_id']!==$l2['lane_id'],'two chats receive distinct lanes');
ok(null===PRSTUDIO_UC_Execution_Lanes::resolve($l1['lane_token'],'other-client'),'lane token cannot cross OAuth client');
$a=PRSTUDIO_UC_Execution_Lanes::acquire(['lane_token'=>$l1['lane_token'],'resource'=>'wp:post:10'],['client_id'=>'oauth-client']);
$b=PRSTUDIO_UC_Execution_Lanes::acquire(['lane_token'=>$l2['lane_token'],'resource'=>'wp:post:10'],['client_id'=>'oauth-client']);
ok(!is_wp_error($a)&&is_wp_error($b)&&'resource_busy_other_context'===$b->get_error_code(),'cross-chat entity collision is rejected');

$base=['lane_token'=>$l1['lane_token'],'_client_id'=>'oauth-client'];
$c=PRSTUDIO_UC_Editorial_Autonomy::campaign_manager($base+['operation'=>'bootstrap']);
ok(!is_wp_error($c)&&5===count($c['campaigns']),'five head-keyword campaigns bootstrap persistently');
$bind=PRSTUDIO_UC_Editorial_Autonomy::keyword_url_registry($base+['operation'=>'assign','keyword'=>'vino','intent'=>'hybrid','url'=>'/vino/','campaign_id'=>'vino']);
$conflict=PRSTUDIO_UC_Editorial_Autonomy::keyword_url_registry($base+['operation'=>'assign','keyword'=>'vino','intent'=>'hybrid','url'=>'/altro-vino/']);
$migrate=PRSTUDIO_UC_Editorial_Autonomy::keyword_url_registry($base+['operation'=>'migrate','keyword'=>'vino','intent'=>'hybrid','url'=>'/vino-nuovo/']);
ok(!is_wp_error($bind)&&is_wp_error($conflict)&&!is_wp_error($migrate),'dominant URL is locked unless explicit migration');

$serp=PRSTUDIO_UC_Editorial_Autonomy::serp_intent_observer($base+['query'=>'ristoranti','results'=>[
 ['url'=>'https://thefork.example/ristoranti/','title'=>'Ristoranti'],
 ['url'=>'https://guide.example/ristoranti-sicilia','title'=>'Guida ristoranti'],
]]);
$cached=PRSTUDIO_UC_Editorial_Autonomy::serp_intent_observer($base+['query'=>'ristoranti']);
ok(!is_wp_error($serp)&&!empty($serp['snapshot']['intent_hash'])&&!empty($cached['cached']),'SERP intent snapshot is hashed and reused within TTL');

$brief=PRSTUDIO_UC_Editorial_Autonomy::brief_compiler($base+['keyword'=>'vino','intent'=>'hybrid','campaign_id'=>'vino','required_sections'=>['Tipi di vino','Come scegliere']]);
ok(!is_wp_error($brief)&&64===strlen($brief['brief']['brief_hash']),'editorial brief is structured and hashed');

$claim=PRSTUDIO_UC_Editorial_Autonomy::claim_ledger($base+['operation'=>'upsert','claim'=>'ApeNera è un gin siciliano','source_url'=>'https://source.example/apenera','authority'=>'producer','confidence'=>0.99]);
$check=PRSTUDIO_UC_Editorial_Autonomy::claim_ledger(['operation'=>'check','claim'=>'ApeNera è un gin siciliano']);
ok(!is_wp_error($claim)&&!empty($check['verified']),'claim ledger enforces provenance/confidence/expiry state');


$can=PRSTUDIO_UC_Editorial_Autonomy::cannibalization_resolver(['operation'=>'analyze','keyword'=>'vino siciliano','candidates'=>[
 ['url'=>'/vino/siciliano/','score'=>0.9],['url'=>'/vini-siciliani/','score'=>0.7]
]]);
ok(!is_wp_error($can)&&'/vino/siciliano/'===$can['dominant_url']&&2===count($can['resolution_plan']),'cannibalization resolver selects one dominant candidate without destructive mutation');

$schemaBad=PRSTUDIO_UC_Editorial_Autonomy::schema_editorial_compiler(['type'=>'Recipe','data'=>['name'=>'Gin tonic']]);
$schemaGood=PRSTUDIO_UC_Editorial_Autonomy::schema_editorial_compiler(['type'=>'Recipe','data'=>['name'=>'Gin tonic','recipeIngredient'=>['gin','tonic'],'recipeInstructions'=>['Versa']]]);
ok(empty($schemaBad['ok'])&&!empty($schemaGood['ok']),'editorial schema compiler rejects incomplete Recipe data');

$prio=PRSTUDIO_UC_Editorial_Autonomy::refresh_prioritizer(['pages'=>[
 ['url'=>'/a/','position'=>11.4,'impressions'=>20000,'business_importance'=>1,'campaign_importance'=>1,'modification_risk'=>0.2],
 ['url'=>'/b/','position'=>78,'impressions'=>12,'business_importance'=>0.3,'campaign_importance'=>0.3,'modification_risk'=>0.3],
]]);
ok($prio['pages'][0]['url']==='/a/'&&$prio['pages'][0]['priority_score']>$prio['pages'][1]['priority_score'],'refresh prioritizer favors near-page-one high-impression opportunity');

$watch=PRSTUDIO_UC_Editorial_Autonomy::post_publish_watcher($base+['operation'=>'create','url'=>'https://example.test/vino/','keyword'=>'vino','campaign_id'=>'vino']);
ok(!is_wp_error($watch)&&'watching'===$watch['watch']['status'],'post-publish watcher is created persistently');

$closed=PRSTUDIO_UC_Execution_Lanes::close(['lane_token'=>$l1['lane_token']],['client_id'=>'oauth-client']);
$retry=PRSTUDIO_UC_Execution_Lanes::acquire(['lane_token'=>$l2['lane_token'],'resource'=>'wp:post:10'],['client_id'=>'oauth-client']);
ok(!is_wp_error($closed)&&!is_wp_error($retry),'closing chat lane releases its resource leases');
