<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/prstudio-work-binding-' . getmypid());

final class WP_Error {
    public function __construct(private string $code='', private string $message='', private array $data=array()){}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
$GLOBALS['OPTS']=[];
function get_option(string $k,$d=[]){ return $GLOBALS['OPTS'][$k] ?? $d; }
function update_option(string $k,$v,$autoload=false): bool { $GLOBALS['OPTS'][$k]=$v; return true; }
function trailingslashit(string $v): string { return rtrim($v,"/\\") . '/'; }
function wp_mkdir_p(string $v): bool { return is_dir($v) || mkdir($v,0777,true); }
function wp_generate_uuid4(): string { return bin2hex(random_bytes(16)); }
function sanitize_key(string $v): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i','',$v)); }
function sanitize_file_name(string $v): string { return preg_replace('/[^a-zA-Z0-9_.\-]/','',$v); }
function sanitize_text_field(string $v): string { return trim(strip_tags($v)); }
function wp_json_encode($v,$flags=0): string { return json_encode($v,$flags|JSON_THROW_ON_ERROR); }
function wp_normalize_path(string $v): string { return str_replace('\\','/',$v); }
function home_url(string $p='/'): string { return 'https://example.test'.$p; }
function ok(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"FAIL: $message\n"); exit(1);} fwrite(STDOUT,"PASS: $message\n"); }

require_once dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-work-session.php';
require_once dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-anti-crash.php';

$work1=PRSTUDIO_UC_Work_Session::begin(['description'=>'work one','scope'=>['wordpress'],'force'=>true]);
$work1Id=(string)$work1['work_id'];
$requirements=PRSTUDIO_UC_Anti_Crash::requirements(['work_id'=>$work1Id]);
ok(($requirements['required_tests']??[])===['pre_mutation_safety'],'anti-crash exposes exactly one composite pre-mutation test');

$details=['checks'=>['php_syntax'=>['status'=>'passed']],'policy'=>'single_pre_mutation_test'];
$e=[[
    'name'=>'pre_mutation_safety',
    'status'=>'passed',
    'exit_code'=>0,
    'command'=>'test:pre_mutation_safety',
    'output_sha256'=>hash('sha256',json_encode($details)),
    'target_sha256'=>'',
    'finished_gmt'=>gmdate('c'),
    'details'=>$details,
]];
$ref=new ReflectionClass(PRSTUDIO_UC_Anti_Crash::class);
$m=$ref->getMethod('verify_and_store');
$m->setAccessible(true);
$r=$m->invoke(null,$e,['source'=>'smoke'],$work1Id);
ok(is_array($r) && ($r['status']??'')==='passed','single composite evidence passes for explicit work_id');

// A shorthand submit must not erase already verified evidence.
$r2=PRSTUDIO_UC_Anti_Crash::submit(['work_id'=>$work1Id,'tests'=>['pre_mutation_safety'=>'passed']]);
ok(($r2['status']??'')==='passed','shorthand submit cannot downgrade previously verified composite evidence');

$work2=PRSTUDIO_UC_Work_Session::begin(['description'=>'work two','scope'=>['wordpress'],'force'=>true]);
ok($work2['work_id']!==$work1Id && ($work2['anti_crash']['status']??'')==='pending','second work becomes active and pending');
$resolved=PRSTUDIO_UC_Work_Session::resolve($work1Id);
ok(($resolved['anti_crash']['status']??'')==='passed','prior work manifest remains addressable after active work changes');
$gate=PRSTUDIO_UC_Anti_Crash::guard('some_mutating_tool',['work_id'=>$work1Id]);
ok($gate===true,'guard binds to explicit verified work instead of unrelated global active work');
$gate2=PRSTUDIO_UC_Anti_Crash::guard('some_mutating_tool',['work_id'=>(string)$work2['work_id']]);
ok(is_wp_error($gate2) && $gate2->get_error_code()==='prstudio_anti_crash_required','real mutation is blocked until the single pre-mutation test passes');

// Preparation/lifecycle must never be blocked, including capability-registry legacy ids.
ok(PRSTUDIO_UC_Anti_Crash::guard('legacy.direct.prstudio-work-begin',[])===true,'legacy work_begin is exempt from anti-crash');
ok(PRSTUDIO_UC_Anti_Crash::guard('legacy.direct.prstudio-anti-crash-run',[])===true,'legacy anti-crash runner is exempt from its own gate');
ok(PRSTUDIO_UC_Anti_Crash::guard('legacy.direct.prstudio-work-finalize',[])===true,'work finalize does not introduce a second anti-crash gate');
ok(PRSTUDIO_UC_Anti_Crash::guard('prstudio_orchestrator_execute',[])===true,'orchestrator wrapper can prepare and dispatch until the actual mutating executor');
ok(PRSTUDIO_UC_Anti_Crash::guard('legacy.operations.cache-manage.flush-all',['dry_run'=>true])===true,'dry-run preparation bypasses anti-crash');
ok(PRSTUDIO_UC_Anti_Crash::guard('legacy.operations.cache-manage.flush-all',['mutation'=>['mode'=>'preview']])===true,'preview preparation bypasses anti-crash');

echo "OK anti-crash single pre-mutation work binding smoke\n";
