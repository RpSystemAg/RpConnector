<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', __DIR__ . '/');

final class WP_Error {
    public function __construct(private string $code='', private string $message='', private array $data=array()){}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
class WP_Post {
    public int $ID; public string $post_type='page'; public string $post_status='publish'; public string $post_content=''; public string $post_modified_gmt='2026-08-10 12:00:00';
    public function __construct(int $id,string $content){$this->ID=$id;$this->post_content=$content;}
}
$GLOBALS['POSTS']=[42=>new WP_Post(42,"<p>Intro</p>\n<p>ANCHOR</p>\n")];
$GLOBALS['PUBLIC_BODY']="<html><body><p>Intro</p><p>ANCHOR</p></body></html>";
$GLOBALS['REV']=0;
$GLOBALS['UPDATE_CALLS']=0;
$GLOBALS['AUDIT_CALLS']=0;
$GLOBALS['REPORT_CALLS']=0;
$GLOBALS['INTERVENTION_CALLS']=0;
function absint($v): int { return abs((int)$v); }
function sanitize_key(string $v): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i','',$v)); }
// Model the relevant WordPress behavior from the live failure: ordinary post
// sanitization removes HTML comments. The transaction must preserve a bounded,
// inert idempotency comment explicitly instead of silently turning it into bytes
// of whitespace and then claiming db_verified=true.
function wp_kses_post(string $v): string { return (string) preg_replace('/<!--.*?-->/s','',$v); }
function wp_slash($v){ return $v; }
function get_post(int $id){ return isset($GLOBALS['POSTS'][$id]) ? clone $GLOBALS['POSTS'][$id] : null; }
function get_post_modified_time($format,$gmt,$post): string { return $post->post_modified_gmt; }
function get_permalink($post): string { return 'https://example.test/page/'; }
function wp_save_post_revision(int $id): int { return ++$GLOBALS['REV']; }
function wp_update_post(array $data,bool $wp_error=false){
    $GLOBALS['UPDATE_CALLS']++;
    $id=(int)$data['ID']; if(!isset($GLOBALS['POSTS'][$id])) return new WP_Error('missing','missing');
    if(array_key_exists('post_content',$data)) $GLOBALS['POSTS'][$id]->post_content=(string)$data['post_content'];
    $GLOBALS['POSTS'][$id]->post_modified_gmt='2026-08-10T12:00:01+00:00';
    $GLOBALS['PUBLIC_BODY']='<html><body>'.$GLOBALS['POSTS'][$id]->post_content.'</body></html>';
    return $id;
}
function wp_safe_remote_get(string $url,array $args=[]){ return ['response'=>['code'=>200],'body'=>$GLOBALS['PUBLIC_BODY']]; }
function wp_remote_retrieve_body(array $r): string { return (string)$r['body']; }
function wp_remote_retrieve_response_code(array $r): int { return (int)$r['response']['code']; }
function clean_post_cache(int $id): void {}
function url_to_postid(string $url): int { return 42; }
function wp_cache_flush(): bool { return true; }
function has_action(string $hook): bool { return false; }
function do_action(string $hook,...$args): void {}
final class WPAIB_Auth { public static function settings(): array { return ['allow_content_write'=>true]; } }
final class WPAIB_Audit { public static function log(...$args): void { $GLOBALS['AUDIT_CALLS']++; } }
final class PRSTUDIO_Report { public static function record_change(...$args): void { $GLOBALS['REPORT_CALLS']++; } }
final class PRSTUDIO_UC_Interventions {
    public const APPLIED='applied';
    public static function normalize_entity(string $type,int $id): string { return $type.':'.$id; }
    public static function record(...$args): void { $GLOBALS['INTERVENTION_CALLS']++; }
}

require_once dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-content-transaction.php';

function ok(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"FAIL: $message\n"); exit(1);} fwrite(STDOUT,"PASS: $message\n"); }

$before=(string)$GLOBALS['POSTS'][42]->post_content;
$hash=hash('sha256',$before);
$r=PRSTUDIO_UC_Content_Transaction::patch([
    'id'=>42,
    'operation'=>'insert_after',
    'search'=>'<p>ANCHOR</p>',
    'replacement'=>'<section id="horeca-marker">Ho.Re.Ca.</section>',
    'expected_before_sha256'=>$hash,
    'expected_occurrences'=>1,
    'idempotency_marker'=>'horeca-marker',
    'public_verify'=>true,
    'verify_contains'=>'horeca-marker',
]);
ok(!is_wp_error($r) && !empty($r['changed']),'content transaction changes exact anchor');
ok(!empty($r['db_verified']) && !empty($r['frontend_verified']) && !empty($r['fully_verified']),'DB and frontend postconditions are independently verified');
ok(substr_count($GLOBALS['POSTS'][42]->post_content,'horeca-marker')===1,'content is inserted exactly once');

$replay=PRSTUDIO_UC_Content_Transaction::patch([
    'id'=>42,
    'operation'=>'insert_after',
    'search'=>'<p>ANCHOR</p>',
    'replacement'=>'<section id="horeca-marker">Ho.Re.Ca.</section>',
    'expected_before_sha256'=>hash('sha256',$GLOBALS['POSTS'][42]->post_content),
    'idempotency_marker'=>'horeca-marker',
    'public_verify'=>true,
    'verify_contains'=>'horeca-marker',
]);
ok(!is_wp_error($replay) && empty($replay['changed']) && !empty($replay['idempotent_reuse']),'replay is a verified no-op');
ok(substr_count($GLOBALS['POSTS'][42]->post_content,'horeca-marker')===1,'replay never duplicates content');

$stale=PRSTUDIO_UC_Content_Transaction::patch([
    'id'=>42,'operation'=>'append_once','replacement'=>'X','expected_before_sha256'=>str_repeat('0',64),
]);
ok(is_wp_error($stale) && $stale->get_error_code()==='prstudio_content_hash_conflict','stale optimistic lock returns a technical failure');

$badCount=PRSTUDIO_UC_Content_Transaction::patch([
    'id'=>42,'operation'=>'replace_exact','search'=>'missing-anchor','replacement'=>'x',
    'expected_before_sha256'=>hash('sha256',$GLOBALS['POSTS'][42]->post_content),'expected_occurrences'=>1,
]);
ok(is_wp_error($badCount) && $badCount->get_error_code()==='prstudio_content_anchor_count_mismatch','wrong anchor count returns a technical failure');

// Exact regression for LIVE ACCEPTANCE CF-1. WordPress kses strips comments in
// this test shim. The transaction must preserve the bounded inert marker itself,
// not merely verify that three newline bytes were persisted.
$liveMarker='<!-- PRSTUDIO-LIVE-ACCEPTANCE-20260819 -->';
$markerResult=PRSTUDIO_UC_Content_Transaction::patch([
    'id'=>42,
    'operation'=>'append_once',
    'replacement'=>$liveMarker,
    'idempotency_marker'=>'PRSTUDIO-LIVE-ACCEPTANCE-20260819',
    'verify_contains'=>'PRSTUDIO-LIVE-ACCEPTANCE-20260819',
    'expected_before_sha256'=>hash('sha256',$GLOBALS['POSTS'][42]->post_content),
]);
ok(!is_wp_error($markerResult) && !empty($markerResult['changed']),'live acceptance HTML comment mutation executes');
ok(!empty($markerResult['db_verified']),'live acceptance marker receives semantic DB verification');
ok(substr_count($GLOBALS['POSTS'][42]->post_content,$liveMarker)===1,'live acceptance HTML comment survives sanitization exactly once');

// Exact regression for LIVE ACCEPTANCE CF-2. dry_run must end before every
// revision/write/audit/report/intervention side effect.
$dryBefore=(string)$GLOBALS['POSTS'][42]->post_content;
$dryModified=$GLOBALS['POSTS'][42]->post_modified_gmt;
$dryRevision=$GLOBALS['REV'];
$dryUpdates=$GLOBALS['UPDATE_CALLS'];
$dryAudit=$GLOBALS['AUDIT_CALLS'];
$dryReport=$GLOBALS['REPORT_CALLS'];
$dryInterventions=$GLOBALS['INTERVENTION_CALLS'];
$dry=PRSTUDIO_UC_Content_Transaction::patch([
    'id'=>42,
    'operation'=>'append_once',
    'replacement'=>'<p>DRY RUN MUST NOT PERSIST</p>',
    'idempotency_marker'=>'dry-run-no-persist',
    'expected_before_sha256'=>hash('sha256',$dryBefore),
    'dry_run'=>true,
]);
ok(!is_wp_error($dry) && !empty($dry['dry_run']) && !empty($dry['would_change']) && empty($dry['executed']) && empty($dry['changed']),'dry run reports the planned delta without claiming execution');
ok($GLOBALS['POSTS'][42]->post_content===$dryBefore,'dry run leaves post content byte-identical');
ok($GLOBALS['POSTS'][42]->post_modified_gmt===$dryModified,'dry run leaves modified timestamp unchanged');
ok($GLOBALS['REV']===$dryRevision,'dry run creates no revision');
ok($GLOBALS['UPDATE_CALLS']===$dryUpdates,'dry run never calls wp_update_post');
ok($GLOBALS['AUDIT_CALLS']===$dryAudit && $GLOBALS['REPORT_CALLS']===$dryReport && $GLOBALS['INTERVENTION_CALLS']===$dryInterventions,'dry run writes no audit/report/intervention side effects');

echo "OK reliability hotfix smoke\n";
