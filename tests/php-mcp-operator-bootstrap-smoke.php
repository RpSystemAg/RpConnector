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
if (!class_exists('PRSTUDIO_UC_Memory')) {
    final class PRSTUDIO_UC_Memory {
        public static array $identity=['key'=>'site-a','host'=>'a.example','path'=>'/','blog_id'=>1];
        public static function site_identity():array{return self::$identity;}
    }
}

final class WP_Error { public function __construct(private string $code='',private string $message='',private $data=null){} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function esc_url_raw($v){return (string)$v;}
function wp_salt($scheme='auth'){return 'operator-bootstrap-'.$scheme;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_generate_uuid4(){return '11111111-2222-4333-8444-555555555555';}
function get_option($k,$d=false){return $d;}
function update_option($k,$v,$autoload=null){return true;}

final class WP_REST_Request {
    public function __construct(private string $method, private $payload, private string $protocol='2025-06-18'){}
    public function get_header($name){return strtolower((string)$name)==='mcp-protocol-version'?$this->protocol:'';}
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
}
final class PRSTUDIO_UC_MCP_Auth_V5 {
    public static function permission($write=false){return ['client_id'=>'client'];}
    public static function protected_resource_metadata_url(){return 'https://example.test/.well-known/oauth-protected-resource';}
    public static function mcp_url(){return 'https://example.test/mcp';}
    public static function bearer_token_from_request(){return 'token';}
    public static function verify_access_token($token,$write){return ['client_id'=>'client'];}
}
final class PRSTUDIO_UC_Execution_Lanes {
    public static function open(array $args,array $context=[]){return ['ok'=>true,'lane_id'=>'lane_'.str_repeat('a',32),'lane_handle'=>'lane_'.str_repeat('a',32),'lane_token'=>'secret','mission_id'=>'mission:test','reused'=>false];}
}

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';
require_once dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-seo-operating-policy.php';
function check($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL $message\n");exit(1);}fwrite(STDOUT,"PASS $message\n");}

$init=PRSTUDIO_UC_MCP_V5::handle(new WP_REST_Request('POST',['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>'2025-06-18']]));
$instructions=(string)($init->get_data()['result']['instructions']??'');
check(str_contains($instructions,'observe -> act -> verify -> record'),'initialize teaches the verified execution loop');
check(str_contains($instructions,'prstudio_tool_manual'),'initialize teaches progressive tool disclosure');
check(str_contains($instructions,'anti-crash test is the only blocking pre-mutation guardian'),'initialize declares the single mutation guardian');
check(str_contains($instructions,'snapshot=browser_snapshot') && str_contains($instructions,'screenshot-only=browser_screenshot'),'initialize contains visual-first snapshot plus screenshot-only fast paths');
check(str_contains($instructions,'SEO POLICY 1.0.0') && str_contains($instructions,'Non-SEO work is unchanged'),'initialize contains compact conditional SEO policy activation');

$policy=PRSTUDIO_UC_SEO_Operating_Policy::load();
check(($policy['id']??'')==='prstudio.seo-operating-policy' && ($policy['version']??'')==='1.0.0' && ($policy['scope']??'')==='global','SEO policy has canonical global identity and version');
check(($policy['quality_gate']['decision']??[])===['PASS','ITERATE'] && !empty($policy['quality_gate']['no_numeric_score']),'SEO quality gate is PASS/ITERATE without a fabricated numeric score');
check(in_array('search_intent_fit',PRSTUDIO_UC_SEO_Operating_Policy::quality_gate_dimensions(),true) && in_array('currentness',PRSTUDIO_UC_SEO_Operating_Policy::quality_gate_dimensions(),true),'SEO quality gate exposes required dimensions');

// Requested activation matrix A-G.
check(PRSTUDIO_UC_SEO_Operating_Policy::applies_to('Analizza questo report Ahrefs e scegli su cosa intervenire'),'A Ahrefs task activates SEO policy');
check(PRSTUDIO_UC_SEO_Operating_Policy::applies_to('Questo è Search Console, scegli l’intervento migliore'),'B Search Console task activates SEO policy');
check(PRSTUDIO_UC_SEO_Operating_Policy::applies_to('Ottimizza questo articolo per la ricerca organica'),'C organic-search article activates SEO policy');
check(PRSTUDIO_UC_SEO_Operating_Policy::applies_to('Crea una hero per questo articolo SEO'),'D SEO hero activates SEO plus media methodology');
check(!PRSTUDIO_UC_SEO_Operating_Policy::applies_to('Crea una Story Instagram promozionale'),'E promotional Instagram Story does not activate SEO policy');
check(!PRSTUDIO_UC_SEO_Operating_Policy::applies_to('Aggiorna lo stock di questo prodotto WooCommerce'),'F WooCommerce stock update does not activate SEO policy');
check(!PRSTUDIO_UC_SEO_Operating_Policy::applies_to('Correggi questo bug CSS'),'G CSS bug does not activate SEO policy');
check(str_contains(PRSTUDIO_UC_SEO_Operating_Policy::instruction_fragment(),'never inherits another site\'s branding'),'SEO media rule derives branding from the current site');

// The policy has to recognise SEO work in whichever language it is asked for.
//
// Measured across 18 equivalent objectives before the bilingual trigger table
// existed, it fired on 6 of the Italian ones and 7 of the English ones, so this
// was never only an Italian gap: "sistema la sitemap", "controlla il file
// robots", "sistema i reindirizzamenti" and "controlla i dati strutturati"
// activated nothing in EITHER language.
//
// Each row asserts two things at once, and the first is the one that matters.
// The two languages must AGREE -- a policy that attaches for an English
// operator and not an Italian one is two different products sharing a version
// number, and nothing in the runtime would report the difference. Then both
// must match what the row says the right answer is, which is what catches a
// term that was never taught rather than merely taught unevenly.
$seo_pairs = array(
    // Work that must carry the policy.
    array('migliora il posizionamento del sito', 'improve the site ranking', true),
    array('fai una ricerca di parole chiave', 'do keyword research', true),
    array('ottimizza per i motori di ricerca', 'optimize for search engines', true),
    array('aumenta le visite organiche', 'increase organic traffic', true),
    array('sistema la sitemap', 'fix the sitemap', true),
    array('controlla il file robots', 'check the robots file', true),
    array('analizza i backlink', 'analyse the backlinks', true),
    array('ottimizza i meta title', 'optimise the meta titles', true),
    array('controlla la search console', 'check search console', true),
    array('analisi delle serp', 'serp analysis', true),
    array('migliora la visibilita su google', 'improve google visibility', true),
    array('sistema i reindirizzamenti', 'fix the redirects', true),
    array('controlla i dati strutturati', 'check structured data', true),
    array('correggi i titoli duplicati', 'fix duplicate titles', true),
    array('analizza i concorrenti organici', 'analyse organic competitors', true),
    array('controlla i link interni', 'check the internal links', true),
    array('migliora la velocita della pagina per la seo', 'improve page speed for seo', true),

    // Work that must not. Page speed and organic are the two traps: both are
    // ranking vocabulary and both are ordinary words. "prodotti organici" is
    // food in a shop this suite is built to run, and a slow page is as often a
    // hosting complaint as an SEO task, so neither activates on its own.
    array('migliora la velocita della pagina', 'improve page speed', false),
    array('vendi prodotti organici', 'sell organic products', false),
    array('svuota la cache', 'purge the cache', false),
    array('rimborsa un ordine', 'refund an order', false),
    array('carica una immagine', 'upload an image', false),
    array('pubblica un articolo', 'publish an article', false),
);
foreach ($seo_pairs as $row) {
    list($italian, $english, $expected) = $row;
    $itHit = PRSTUDIO_UC_SEO_Operating_Policy::applies_to($italian);
    $enHit = PRSTUDIO_UC_SEO_Operating_Policy::applies_to($english);
    check(
        $itHit === $enHit && $itHit === $expected,
        sprintf(
            'SEO activation %s: "%s" / "%s" (IT=%s EN=%s, expected %s)',
            ($itHit !== $enHit) ? 'DISAGREES between languages' : 'correct',
            $italian,
            $english,
            $itHit ? 'yes' : 'no',
            $enHit ? 'yes' : 'no',
            $expected ? 'yes' : 'no'
        )
    );
}

// Requested isolation case H: same global method, distinct site-scoped context.
PRSTUDIO_UC_Memory::$identity=['key'=>'site-a','host'=>'a.example','path'=>'/','blog_id'=>1];
$siteA=PRSTUDIO_UC_SEO_Operating_Policy::runtime_context('Analizza Search Console e proponi la strategia SEO');
PRSTUDIO_UC_Memory::$identity=['key'=>'site-b','host'=>'b.example','path'=>'/shop','blog_id'=>2];
$siteB=PRSTUDIO_UC_SEO_Operating_Policy::runtime_context('Analizza Search Console e proponi la strategia SEO');
check(($siteA['policy_id']??'')===($siteB['policy_id']??'') && ($siteA['policy_version']??'')===($siteB['policy_version']??''),'H same SEO method applies across sites');
check(($siteA['site_context']['site_key']??'')!==($siteB['site_context']['site_key']??'') && ($siteA['site_context']['host']??'')!==($siteB['site_context']['host']??''),'H client/site context stays separated');

$tools=PRSTUDIO_UC_MCP_V5::tools();
$toolNames=array_map(static fn(array $tool):string=>(string)($tool['name']??''),$tools);
check(!in_array('prstudio_seo_operating_policy',$toolNames,true) && !in_array('seo_operating_policy',$toolNames,true),'I SEO policy adds no MCP tool/action');

$bootstrapMethod=new ReflectionMethod(PRSTUDIO_UC_MCP_V5::class,'operator_bootstrap');
$bootstrap=$bootstrapMethod->invoke(null);
check(is_array($bootstrap),'context_open returns operator_bootstrap');
check(($bootstrap['version']??'')==='2.0.0','operator bootstrap has explicit version');
check(($bootstrap['fast_paths']['snapshot']??'')==='browser_snapshot' && ($bootstrap['fast_paths']['screenshot_only']??'')==='browser_screenshot','operator bootstrap maps visual snapshot and screenshot-only directly');
check(($bootstrap['discovery']['advanced_browser']??'')==='browser_actions_search -> browser_action','operator bootstrap maps advanced browser discovery');
check(($bootstrap['discovery']['server_capability']??'')==='prstudio_capability_search -> prstudio_capability_describe -> prstudio_execute','operator bootstrap maps server capability discovery');
check(($bootstrap['sequential_thinking_role']??'')==='reasoning_notes_only_not_tool_discovery','operator bootstrap prevents sequential-thinking misuse');
check((int)($bootstrap['direct_tool_count']??0)>=100,'operator bootstrap reports broad direct tool surface');

fwrite(STDOUT,"OK MCP operator bootstrap smoke\n");
