<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$root = sys_get_temp_dir() . '/prstudio-shot-' . bin2hex(random_bytes(4));
define('WP_CONTENT_DIR', $root);

final class WP_Error {
    public function __construct(private string $code='', private string $message='', private array $data=array()){}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
class WP_REST_Request {}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function trailingslashit(string $v): string { return rtrim($v,'/\\') . '/'; }
function wp_mkdir_p(string $dir): bool { return is_dir($dir) || mkdir($dir,0777,true); }
function wp_generate_uuid4(): string { return bin2hex(random_bytes(16)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function add_query_arg(array $args,string $url): string { return $url.'?'.http_build_query($args); }
function rest_url(string $path): string { return 'https://example.test/wp-json/'.$path; }
function sanitize_text_field(string $v): string { return trim($v); }
function sanitize_key(string $v): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i','',$v)); }
function absint($v): int { return abs((int)$v); }
final class PRSTUDIO_UC_Auth { public static function signing_secret(): string { return 'test-secret'; } }

require_once dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-artifacts.php';
function ok(bool $v,string $m): void { if(!$v){fwrite(STDERR,"FAIL $m\n"); exit(1);} fwrite(STDOUT,"PASS $m\n"); }

$status=PRSTUDIO_UC_Artifacts::status();
ok(!empty($status['writable']) && !empty($status['headroom_ok']),'private screenshot storage preflight is writable with headroom');
ok(($status['max_artifact_bytes']??0)===12582912,'server advertises exact bounded artifact size');
ok(in_array('image/jpeg',$status['accepted_mime_types']??[],true),'storage advertises compressed JPEG support');

$png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z1ZsAAAAASUVORK5CYII=';
$r=PRSTUDIO_UC_Artifacts::store('device-1',$png,['task_id'=>'task-1','step_index'=>2,'capture_mode'=>'cdp_native','full_page'=>true,'full_page_complete'=>false]);
ok(!is_wp_error($r) && preg_match('/^[a-f0-9]{32}$/',(string)$r['artifact_id'])===1,'PNG screenshot persists as a private artifact');
ok(($r['mime_type']??'')==='image/png' && ($r['width']??0)===1 && ($r['height']??0)===1,'artifact records verified MIME and dimensions');
$read=PRSTUDIO_UC_Artifacts::read_for_mcp((string)$r['artifact_id']);
ok(is_array($read) && ($read['mime_type']??'')==='image/png' && !empty($read['raw']),'MCP reads artifact bytes with actual MIME');

$tooLarge='data:image/png;base64,'.str_repeat('A', 18_000_000);
$large=PRSTUDIO_UC_Artifacts::store('device-1',$tooLarge);
ok(is_wp_error($large) && $large->get_error_code()==='prstudio_screenshot_size','oversized screenshot fails once with explicit size error');

// Cleanup removes expired metadata and its matching image without touching installation state.
$id=(string)$r['artifact_id'];
$base=trailingslashit(PRSTUDIO_UC_Artifacts::root());
$meta=glob($base.$id.'.json')[0]??'';
$img=glob($base.$id.'.*');
foreach(array_merge([$meta],$img) as $path){ if($path && is_file($path)) touch($path,time()-7200); }
clearstatcache();
$clean=PRSTUDIO_UC_Artifacts::cleanup();
ok($clean>=1 && !is_file($meta),'expired screenshot artifacts are pruned deterministically');

function rrmdir(string $d): void { if(!is_dir($d))return; foreach(scandir($d)?:[] as $x){if($x==='.'||$x==='..')continue;$p=$d.'/'.$x;is_dir($p)?rrmdir($p):@unlink($p);}@rmdir($d); }
rrmdir($root);
echo "OK screenshot storage smoke\n";
