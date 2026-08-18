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
function absint($v): int { return abs((int)$v); }
function sanitize_key(string $v): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i','',$v)); }
function wp_kses_post(string $v): string { return $v; }
function wp_slash($v){ return $v; }
function get_post(int $id){ return isset($GLOBALS['POSTS'][$id]) ? clone $GLOBALS['POSTS'][$id] : null; }
function get_post_modified_time($format,$gmt,$post): string { return '2026-08-10T12:00:00+00:00'; }
function get_permalink($post): string { return 'https://example.test/page/'; }
function wp_save_post_revision(int $id): int { return ++$GLOBALS['REV']; }
function wp_update_post(array $data,bool $wp_error=false){
    $id=(int)$data['ID']; if(!isset($GLOBALS['POSTS'][$id])) return new WP_Error('missing','missing');
    if(array_key_exists('post_content',$data)) $GLOBALS['POSTS'][$id]->post_content=(string)$data['post_content'];
    $GLOBALS['POSTS'][$id]->post_modified_gmt='2026-08-10 12:00:01';
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
final class WPAIB_Audit { public static function log(...$args): void {} }
final class PRSTUDIO_Report { public static function record_change(...$args): void {} }

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

echo "OK reliability hotfix smoke\n";
