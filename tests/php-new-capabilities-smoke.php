<?php

declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('PRSTUDIO_UC_VERSION', '1.0.0');
$test_root = sys_get_temp_dir() . '/prstudio-newcap-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $test_root);
@mkdir($test_root, 0750, true);

final class WP_Error {
    private string $code; private string $message; private array $data;
    public function __construct(string $code='', string $message='', array $data=array()){ $this->code=$code;$this->message=$message;$this->data=$data; }
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function sanitize_text_field(string $v): string { return trim((string)preg_replace('/[\x00-\x1F\x7F]/u','',$v)); }
function wp_parse_url(string $url){ return parse_url($url); }
function esc_url_raw(string $url): string { return filter_var($url,FILTER_SANITIZE_URL) ?: ''; }
function wp_json_encode($v): string { return (string)json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
function wp_generate_uuid4(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function home_url(string $path='/'): string { return 'https://example.test'.('/'===substr($path,0,1)?$path:'/'.$path); }
function wp_mkdir_p(string $dir): bool { return is_dir($dir) || @mkdir($dir,0750,true); }

final class PRSTUDIO_UC_Agency_State {
    private static array $states=[];
    public static function read(string $name,array $defaults=array()): array { return isset(self::$states[$name]) ? array_replace_recursive($defaults,self::$states[$name]) : $defaults; }
    public static function mutate(string $name,array $defaults,callable $cb){ $s=self::read($name,$defaults);$r=$cb($s);self::$states[$name]=$s;return $r; }
}
final class PRSTUDIO_UC_Secrets_Vault {
    private static array $v=[];
    public static function set(string $k,string $v): bool { self::$v[$k]=$v;return true; }
    public static function get(string $k): ?string { return self::$v[$k]??null; }
}
final class PRSTUDIO_UC_GSC_Provider {
    public static function analytics(array $args): array {
        $rows=[];
        foreach(($GLOBALS['SERP_KEYWORDS']??[]) as $i=>$kw){
            $rows[]=['query'=>$kw,'page'=>'https://example.test/p/'.$i,'clicks'=>10,'impressions'=>100,'ctr'=>0.10,'position'=>2.3];
        }
        return ['status'=>'completed','provider_used'=>'browser_agent_cdp','source'=>'browser_runtime','retrieved_at'=>gmdate('c'),'rows'=>$rows,'dimension_integrity'=>['status'=>'verified']];
    }
}
final class PRSTUDIO_UC_Memory {
    public static function site_dir(): string { $d=WP_CONTENT_DIR.'/memory';@mkdir($d,0750,true);return $d; }
    public static function redact($v){ return $v; }
    public static function movement(string $type,array $payload=array()): void { $GLOBALS['MOVEMENTS'][]=[$type,$payload]; }
}
final class PRSTUDIO_UC_Idempotency {
    public static function canonical_json($v): string { if(is_array($v)){ksort($v);} return (string)json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
}

$GLOBALS['SERP_KEYWORDS']=array_map(static fn($i)=>'keyword-'.$i,range(1,501));
$GLOBALS['SERP_HTTP_CALLS']=[];
function wp_safe_remote_request(string $url,array $opts=array()){
    $GLOBALS['SERP_HTTP_CALLS'][]=['url'=>$url,'method'=>$opts['method']??'GET'];
    $parts=parse_url($url);$path=$parts['path']??'';
    if('/api/keywords'===$path){
        return ['response'=>['code'=>200],'body'=>json_encode(['keywords'=>$keywords])];
    }
    if('/api/cron'===$path) return ['response'=>['code'=>200],'body'=>json_encode(['ok'=>true])];
    return ['response'=>['code'=>404],'body'=>json_encode(['error'=>'not found'])];
}
function wp_remote_retrieve_response_code(array $r): int { return (int)($r['response']['code']??0); }
function wp_remote_retrieve_body(array $r): string { return (string)($r['body']??''); }

$inc=dirname(__DIR__).'/prstudio-unified-control/includes/';
require_once $inc.'class-prstudio-uc-sequential-thinking.php';
require_once $inc.'class-prstudio-uc-procedural-skills.php';

function ok(bool $cond,string $message): void { if(!$cond){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }
function cleanup(string $root): void {
    if(!is_dir($root))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $x){$x->isDir()?@rmdir($x->getPathname()):@unlink($x->getPathname());}@rmdir($root);
}

try {




    $seq=PRSTUDIO_UC_Sequential_Thinking::think(['thought'=>'Explicit test plan note','nextThoughtNeeded'=>true,'thoughtNumber'=>1,'totalThoughts'=>2]);
    ok(!is_wp_error($seq) && !empty($seq['ok']) && false===$seq['hidden_chain_of_thought_stored'],'Sequential Thinking native durable session');
    $seq2=PRSTUDIO_UC_Sequential_Thinking::think(['session_id'=>$seq['session_id'],'thought'=>'Explicit revised note','nextThoughtNeeded'=>false,'thoughtNumber'=>2,'totalThoughts'=>2,'isRevision'=>true,'revisesThought'=>1]);
    ok(!is_wp_error($seq2) && 2===(int)$seq2['thoughtNumber'],'Sequential Thinking revision/resume');
    $session=PRSTUDIO_UC_Sequential_Thinking::session(['session_id'=>$seq['session_id']]);
    ok(!is_wp_error($session) && 2===count($session['session']['thoughts']),'Sequential Thinking persisted two explicit notes');

    $unverified=PRSTUDIO_UC_Procedural_Skills::learn_verified_capability('demo.action',['x'=>1],['ok'=>true],['ok'=>false]);
    ok(empty($unverified['learned']),'procedural skill refuses unverified success');
    $learned=PRSTUDIO_UC_Procedural_Skills::learn_verified_capability('demo.action',['x'=>1],['ok'=>true],['ok'=>true,'verifier'=>'readback'],'job-1');
    ok(!empty($learned['learned']) && !empty($learned['skill']['id']),'procedural skill learns verified success');
    $failure=PRSTUDIO_UC_Procedural_Skills::observe_failure('capability','demo.action',['x'=>1],['code'=>'known_dead_end']);
    ok(!empty($failure['recorded']),'procedural skill records failed path');
    $search=PRSTUDIO_UC_Procedural_Skills::search(['query'=>'demo.action']);
    ok(1===(int)$search['count'] && 1===(int)$search['items'][0]['failed_path_count'],'progressive skill search includes failed-path count');

    // LAW 15: learned-procedure discovery is one functional surface in Italian
    // and English. Populate technical browser recipe names, then compare the
    // complete ordered result IDs for equivalent natural-language queries.
    $browserFixtures=[
        'playwright_locator_snapshot','playwright_dom_snapshot','playwright_fill',
        'playwright_reload','playwright_go_back','playwright_go_forward',
        'playwright_select_option','playwright_screenshot_page','playwright_content',
        'playwright_click','playwright_scroll','playwright_check',
    ];
    foreach($browserFixtures as $i=>$action){
        $fixture=PRSTUDIO_UC_Procedural_Skills::learn_verified_browser_task(
            ['action'=>$action,'arguments'=>[],'task_uuid'=>'fixture-'.($i+1)],
            ['ok'=>true],
            ['ok'=>true,'verifier'=>'fixture']
        );
        ok(!empty($fixture['learned']),'procedural browser fixture learns '.$action);
    }
    $skillIds=static function(string $query):array{
        $result=PRSTUDIO_UC_Procedural_Skills::search(['query'=>$query,'kind'=>'browser','limit'=>12]);
        return array_map(static fn(array $row):string=>(string)($row['id']??''),(array)($result['items']??[]));
    };
    $skillPairs=[
        ['istantanea','snapshot'],['compila','fill'],['ricarica','reload'],
        ['indietro','back'],['avanti','forward'],['seleziona','select'],
        ['schermata','screenshot'],['pagina','page'],['contenuto','content'],
        ['clicca','click'],['scorri','scroll'],['spunta','check'],
    ];
    foreach($skillPairs as [$italian,$english]){
        $it=$skillIds($italian);$en=$skillIds($english);
        ok($it===$en && []!==$it,sprintf('procedural skill order agrees IT/EN: "%s" / "%s"',$italian,$english));
    }
    $technical=$skillIds('playwright_locator_snapshot');
    ok(1===count($technical) && str_contains($technical[0],'playwright_locator_snapshot'),'technical procedural-skill identifier keeps exact lookup semantics');
    ok([]===$skillIds('xyzzy quux'),'nonsense procedural-skill query returns no accidental row');

    // One verified success no longer qualifies a recipe for reuse. Confidence is
    // a Wilson lower bound over successes and observed failure modes, so a
    // single sample scores ~0.09 against a 0.5 reuse bar. See
    // tests/php-procedural-skill-confidence-smoke.php and arXiv:2608.17587,
    // which measured agent-authored skills performing 8-11 points worse than no
    // skill when they are trusted from n=1.
    ok(null===PRSTUDIO_UC_Procedural_Skills::best_match('capability','demo.action',['x'=>1]),'planner refuses a recipe backed by one sample');
    for($i=0;$i<6;$i++){PRSTUDIO_UC_Procedural_Skills::learn_verified_capability('demo.action',['x'=>1],['ok'=>true],['ok'=>true,'verifier'=>'readback'],'job-'.($i+2));}
    $best=PRSTUDIO_UC_Procedural_Skills::best_match('capability','demo.action',['x'=>1]);
    ok(is_array($best) && !empty($best['procedure']['verification_required']),'planner can reuse a repeatedly confirmed non-stale verified recipe');
    $inv=PRSTUDIO_UC_Procedural_Skills::invalidate(['id'=>$learned['skill']['id'],'reason'=>'test-change']);
    ok(!is_wp_error($inv) && !empty($inv['invalidated']),'skill invalidation');
    ok(null===PRSTUDIO_UC_Procedural_Skills::best_match('capability','demo.action',['x'=>1]),'stale skill is not reused');
    $curate=PRSTUDIO_UC_Procedural_Skills::curate(['apply'=>false,'force'=>true]);
    ok(!empty($curate['ok']) && in_array($learned['skill']['id'],$curate['stale_ids'],true),'skill curator detects stale procedures without deleting history');
    $curated=PRSTUDIO_UC_Procedural_Skills::curate(['apply'=>true,'force'=>true]);
    ok(!empty($curated['ok']) && false===$curated['history_deleted'] && in_array($learned['skill']['id'],$curated['archived_ids'],true),'skill curator archives conservatively and preserves history');

    $registry=json_decode((string)file_get_contents(dirname(__DIR__).'/prstudio-unified-control/capabilities/capability-registry.json'),true);
    $gsc=array_values(array_filter((array)($registry['capabilities']??array()),static fn($c)=>str_starts_with((string)($c['id']??''),'seo.gsc.')));
    ok(4===count($gsc),'GSC capability registry contains the four Browser-backed read capabilities');
    ok(!array_filter($gsc,static fn($c)=>str_contains(strtolower((string)($c['description']??'')),'come fallback')||str_contains(strtolower((string)($c['description']??'')),'restano fallback')||str_contains(strtolower((string)($c['description']??'')),'provider indipendenti di fallback')),'GSC capability descriptions do not advertise removed API/cache live fallbacks');
    $providerSource=(string)file_get_contents($inc.'class-prstudio-uc-gsc-provider.php');
    ok(
        str_contains($providerSource,'prstudio_gsc_api_provider_unavailable')
        && str_contains($providerSource,'PRSTUDIO_UC_Search_Console_Browser')
        && str_contains($providerSource,"'browser_agent_cdp'")
        && !str_contains($providerSource,'private static function api('),
        'GSC provider runtime remains Browser-only without the removed API implementation'
    );

    fwrite(STDOUT,"PHP new capabilities smoke: all assertions passed\n");
} finally { cleanup($test_root); }
