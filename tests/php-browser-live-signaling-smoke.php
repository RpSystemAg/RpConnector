<?php
declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
$test_root = sys_get_temp_dir() . '/prstudio-live-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $test_root);

final class WP_Error {
    public function __construct(private string $code, private string $message = '', private array $data = array()) {}
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }
function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0750, true); }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_key($value): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
function esc_url_raw($value): string { return filter_var((string) $value, FILTER_SANITIZE_URL); }

function expect_live(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL {$message}\n"); exit(1); }
}
function remove_live_tree(string $path): void {
    if (!is_dir($path)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
    @rmdir($path);
}
register_shutdown_function(static fn() => remove_live_tree($test_root));

require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-browser-live.php';

$created = PRSTUDIO_UC_Browser_Live::create_agent_session('device-a', 42, array('url'=>'https://example.com/'));
expect_live(!is_wp_error($created) && !empty($created['session_id']), 'session creation');
$id = (string) $created['session_id'];
$files = glob($test_root . '/prstudio-unified-private/browser-live/*.json') ?: array();
expect_live(count($files) === 1, 'one signaling file per session');
$path = $files[0];

// A poll without new events is a read and must not rewrite the whole file.
$before = (string) file_get_contents($path);
$polled = PRSTUDIO_UC_Browser_Live::agent_exchange($id, 'device-a', 0, array());
$after = (string) file_get_contents($path);
expect_live(!is_wp_error($polled) && $before === $after, 'empty poll must be write-free');

$offer = PRSTUDIO_UC_Browser_Live::agent_exchange($id, 'device-a', 0, array(array('type'=>'offer','payload'=>array('sdp'=>'v=0'))));
expect_live(!is_wp_error($offer), 'agent offer');
$claimed = PRSTUDIO_UC_Browser_Live::claim_viewer($id, 'viewer-a');
expect_live(!is_wp_error($claimed), 'viewer claim');
$seen_offer = PRSTUDIO_UC_Browser_Live::viewer_exchange($id, 'viewer-a', 0, array());
expect_live(count($seen_offer['events'] ?? array()) === 1, 'viewer receives offer once');
PRSTUDIO_UC_Browser_Live::viewer_exchange($id, 'viewer-a', (int) $seen_offer['seq'], array());
$stored = json_decode((string) file_get_contents($path), true);
expect_live(empty($stored['events']), 'acknowledged agent event is compacted');

$answer = PRSTUDIO_UC_Browser_Live::viewer_exchange($id, 'viewer-a', (int) $seen_offer['seq'], array(array('type'=>'answer','payload'=>array('sdp'=>'v=0 answer'))));
$seen_answer = PRSTUDIO_UC_Browser_Live::agent_exchange($id, 'device-a', 0, array());
expect_live(count($seen_answer['events'] ?? array()) === 1, 'agent receives answer once');
PRSTUDIO_UC_Browser_Live::agent_exchange($id, 'device-a', (int) $answer['seq'], array());
$stored = json_decode((string) file_get_contents($path), true);
expect_live(empty($stored['events']), 'acknowledged viewer event is compacted');

$large = str_repeat('x', 130000);
$events = array_fill(0, 9, array('type'=>'ice','payload'=>array('candidate'=>$large)));
$bounded = PRSTUDIO_UC_Browser_Live::agent_exchange($id, 'device-a', 0, $events);
expect_live(is_wp_error($bounded) && $bounded->get_error_code() === 'browser_live_signaling_too_large', 'aggregate signaling byte cap');

echo "PASS Browser LIVE polling is write-free, delivered events compact, and signaling stays byte-bounded\n";
