<?php
declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('PRSTUDIO_UC_DIR', dirname(__DIR__) . '/prstudio-unified-control/');
define('DAY_IN_SECONDS', 86400);
if (!defined('DATE_ATOM')) { define('DATE_ATOM', 'Y-m-d\\TH:i:sP'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

final class WP_Error {
    public function __construct(private string $code = '', private string $message = '', private $data = null) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
final class WP_Post {
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_title = '';
    public string $post_name = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_modified_gmt = '';
}

$GLOBALS['opts'] = array();
$GLOBALS['posts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['next_id'] = 200;
$GLOBALS['public_ok'] = true;
$GLOBALS['failed'] = false;

function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return sanitize_text_field($value); }
function sanitize_key($value) { return strtolower((string) preg_replace('/[^a-z0-9_\\-]/i', '', (string) $value)); }
function sanitize_title($value) { $value = strtolower(trim((string) $value)); return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-'); }
function esc_url_raw($value) { $value = trim((string) $value); return $value; }
function esc_url($value) { return (string) $value; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, is_array($args) ? $args : array()); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_parse_url($value, $component = -1) { return parse_url((string) $value, $component); }
function home_url($path = '/') { return 'https://example.test' . ('/' === substr((string) $path, 0, 1) ? $path : '/' . $path); }
function absint($value) { return abs((int) $value); }
function get_option($key, $default = false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['opts'][$key] = $value; return true; }
function add_option($key, $value = '', $deprecated = '', $autoload = null) { if (array_key_exists($key, $GLOBALS['opts'])) { return false; } $GLOBALS['opts'][$key] = $value; return true; }
function delete_option($key) { if (!array_key_exists($key, $GLOBALS['opts'])) { return false; } unset($GLOBALS['opts'][$key]); return true; }
function wp_kses_post($value) { return (string) $value; }
function wp_slash($value) { return $value; }
function post_type_exists($type) { return in_array((string) $type, array('post', 'page'), true); }
function taxonomy_exists($type) { return true; }
function wp_attachment_is_image($id) { return (int) $id > 0; }
function set_post_thumbnail($id, $img) { return true; }
function get_current_user_id() { return 1; }
function get_posts($args) {
    $out = array();
    foreach ($GLOBALS['posts'] as $post) {
        if (isset($args['meta_key']) && (($GLOBALS['meta'][$post->ID][$args['meta_key']] ?? null) !== ($args['meta_value'] ?? null))) { continue; }
        $out[] = $post;
    }
    return array_slice($out, 0, (int) ($args['posts_per_page'] ?? 10));
}
function get_page_by_path($slug, $output, $type) {
    foreach ($GLOBALS['posts'] as $post) {
        if ($post->post_name === $slug && $post->post_type === $type) { return $post; }
    }
    return null;
}
function wp_insert_post($arr, $error = false) {
    $post = new WP_Post();
    $post->ID = $GLOBALS['next_id']++;
    $post->post_type = (string) ($arr['post_type'] ?? 'post');
    $post->post_status = (string) ($arr['post_status'] ?? 'draft');
    $post->post_title = (string) ($arr['post_title'] ?? '');
    $post->post_name = (string) ($arr['post_name'] ?? '');
    $post->post_content = (string) ($arr['post_content'] ?? '');
    $post->post_excerpt = (string) ($arr['post_excerpt'] ?? '');
    $post->post_modified_gmt = gmdate('c');
    $GLOBALS['posts'][$post->ID] = $post;
    return $post->ID;
}
function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][(int) $id][$key] = $value; return true; }
function wp_set_post_terms() { return true; }
function clean_post_cache() {}
function wp_update_post($arr, $error = false) {
    $post = get_post((int) $arr['ID']);
    if (!$post) { return $error ? new WP_Error('missing', 'missing') : 0; }
    foreach (array('post_status', 'post_title', 'post_content') as $key) {
        if (isset($arr[$key])) { $post->$key = $arr[$key]; }
    }
    $post->post_modified_gmt = gmdate('c');
    return $post->ID;
}
function get_permalink($post) {
    $id = $post instanceof WP_Post ? $post->ID : (int) $post;
    $row = get_post($id);
    return 'https://example.test/' . ($row ? ($row->post_name ?: $id) : $id) . '/';
}
function get_post_modified_time($format, $gmt, $post) {
    return $post instanceof WP_Post && '' !== $post->post_modified_gmt ? $post->post_modified_gmt : gmdate('c');
}
function url_to_postid($url) {
    foreach ($GLOBALS['posts'] as $post) {
        if (get_permalink($post) === $url) { return $post->ID; }
    }
    return 0;
}
function wp_remote_get($url, $args = array()) {
    if (str_contains((string) $url, 'wp-sitemap-posts-')) {
        if (str_ends_with((string) $url, '-1.xml')) {
            $locs = '';
            foreach ($GLOBALS['posts'] as $post) { $locs .= '<loc>' . get_permalink($post) . '</loc>'; }
            return array('code' => 200, 'body' => '<urlset>' . $locs . '</urlset>');
        }
        return array('code' => 404, 'body' => '');
    }
    $body = $GLOBALS['public_ok']
        ? '<html><head><link rel="canonical" href="' . $url . '"><meta name="robots" content="index,follow"></head><body>Editorial Test patched</body></html>'
        : '<html><body>missing</body></html>';
    return array('code' => 200, 'body' => $body);
}
function wp_safe_remote_get($url, $args = array()) { return wp_remote_get($url, $args); }
function wp_remote_retrieve_response_code($response) { return (int) ($response['code'] ?? 0); }
function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }

require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-schema-validator.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-capability-registry.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-editorial-autonomy.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-publish-transaction.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-content-transaction.php';

function fail(string $message): void { fwrite(STDERR, "FAIL {$message}\n"); exit(1); }
function pass(string $message): void { fwrite(STDOUT, "PASS {$message}\n"); }
function check(bool $ok, string $message): void { if (!$ok) { fail($message); } pass($message); }
function schema_ok($value, array $schema, string $label): void {
    $errors = PRSTUDIO_UC_Schema_Validator::validate($value, $schema);
    check(array() === $errors, $label . ( $errors ? ' :: ' . implode('; ', $errors) : '' ));
}
function schema_bad($value, array $schema, string $label): void {
    $errors = PRSTUDIO_UC_Schema_Validator::validate($value, $schema);
    check(array() !== $errors, $label);
}

$ids = array('authority.outreach.engine', 'content.brief.compile', 'content.claim.ledger', 'content.publish.transaction', 'content.transaction.patch');
$raw = file_get_contents(PRSTUDIO_UC_DIR . 'capabilities/enterprise-capability-contracts.json');
$doc = json_decode((string) $raw, true);
check(is_array($doc) && array_keys((array) ($doc['contracts'] ?? array())) === $ids, 'batch 2 overlay contains exactly the five file-disjoint capabilities');

foreach ($ids as $id) {
    $described = PRSTUDIO_UC_Capability_Registry::describe($id);
    check(is_array($described), $id . ' is discoverable and executable');
    check(is_array($described['input_schema'] ?? null) && is_array($described['output_schema'] ?? null), $id . ' exposes input and output schemas');
    check(($described['input_schema']['additionalProperties'] ?? null) === false, $id . ' input forbids unknown properties');
    $generic = (($described['output_schema']['additionalProperties'] ?? null) === true) && count((array) ($described['output_schema']['properties'] ?? array())) === 0;
    check(!$generic, $id . ' output is not a generic additionalProperties object');
}

// [1/5] authority.outreach.engine
$outreach = PRSTUDIO_UC_Capability_Registry::describe('authority.outreach.engine');
schema_ok(array(), $outreach['input_schema'], 'outreach list input valid');
schema_ok(array('operation' => 'upsert', 'domain' => 'winery.example', 'relevance' => 0.8), $outreach['input_schema'], 'outreach upsert input valid');
schema_bad(array('operation' => 'delete'), $outreach['input_schema'], 'outreach rejects unknown operation');
schema_bad(array('extra' => true), $outreach['input_schema'], 'outreach rejects unknown input');
$list = PRSTUDIO_UC_Editorial_Autonomy::authority_outreach_engine(array());
check(!is_wp_error($list) && isset($list['records']), 'outreach list succeeds');
schema_ok($list, $outreach['output_schema'], 'outreach list output conforms');
$missing = PRSTUDIO_UC_Editorial_Autonomy::authority_outreach_engine(array('operation' => 'upsert'));
check(is_wp_error($missing) && 'outreach_domain_required' === $missing->get_error_code(), 'outreach upsert without domain is typed error');
$up = PRSTUDIO_UC_Editorial_Autonomy::authority_outreach_engine(array('operation' => 'upsert', 'domain' => 'Winery.Example', 'entity' => 'Cantina', 'relevance' => 0.9, 'authority' => 0.7, 'link_status' => 'prospect'));
check(!is_wp_error($up) && ($up['record']['domain'] ?? '') === 'winery.example', 'outreach upsert persists lowercased domain');
schema_ok($up, $outreach['output_schema'], 'outreach upsert output conforms');
$again = PRSTUDIO_UC_Editorial_Autonomy::authority_outreach_engine(array('operation' => 'list'));
check(1 === count($again['records']) && $again['records'][0]['id'] === $up['record']['id'], 'outreach list returns the upserted record');

// [2/5] content.brief.compile
$briefCap = PRSTUDIO_UC_Capability_Registry::describe('content.brief.compile');
schema_ok(array('keyword' => 'vino siciliano', 'required_sections' => array('Storia')), $briefCap['input_schema'], 'brief valid input');
schema_bad(array(), $briefCap['input_schema'], 'brief requires keyword');
schema_bad(array('keyword' => 'vino', 'unexpected' => 1), $briefCap['input_schema'], 'brief rejects unknown input');
$emptyKw = PRSTUDIO_UC_Editorial_Autonomy::brief_compiler(array('keyword' => '   '));
check(is_wp_error($emptyKw) && 'brief_keyword_required' === $emptyKw->get_error_code(), 'brief empty keyword is typed error');
$brief = PRSTUDIO_UC_Editorial_Autonomy::brief_compiler(array('keyword' => 'Vino', 'required_sections' => array('Come scegliere'), 'schema_type' => 'Article'));
check(!is_wp_error($brief) && ($brief['brief']['primary_keyword'] ?? '') === 'vino' && 64 === strlen((string) ($brief['brief']['brief_hash'] ?? '')), 'brief compiles hashed contract');
schema_ok($brief, $briefCap['output_schema'], 'brief success output conforms');

// [3/5] content.claim.ledger
$claimCap = PRSTUDIO_UC_Capability_Registry::describe('content.claim.ledger');
schema_ok(array('operation' => 'list'), $claimCap['input_schema'], 'claim list input valid');
schema_ok(array('operation' => 'upsert', 'claim' => 'ApeNera is Sicilian gin', 'confidence' => 0.9), $claimCap['input_schema'], 'claim upsert input valid');
schema_bad(array('operation' => 'approve'), $claimCap['input_schema'], 'claim rejects unknown operation');
$listed = PRSTUDIO_UC_Editorial_Autonomy::claim_ledger(array('operation' => 'list'));
check(!is_wp_error($listed) && isset($listed['claims']), 'claim list succeeds');
schema_ok($listed, $claimCap['output_schema'], 'claim list output conforms');
$needClaim = PRSTUDIO_UC_Editorial_Autonomy::claim_ledger(array('operation' => 'check'));
check(is_wp_error($needClaim) && 'claim_required' === $needClaim->get_error_code(), 'claim check without text is typed error');
$missingClaim = PRSTUDIO_UC_Editorial_Autonomy::claim_ledger(array('operation' => 'check', 'claim' => 'unknown fact'));
check(!is_wp_error($missingClaim) && false === $missingClaim['found'] && false === $missingClaim['verified'], 'claim check miss shape');
schema_ok($missingClaim, $claimCap['output_schema'], 'claim miss output conforms');
$saved = PRSTUDIO_UC_Editorial_Autonomy::claim_ledger(array('operation' => 'upsert', 'claim' => 'ApeNera is Sicilian gin', 'source_url' => 'https://source.example/apenera', 'authority' => 'producer', 'confidence' => 0.99));
check(!is_wp_error($saved) && 'verified' === ($saved['claim']['status'] ?? ''), 'claim upsert stores verified evidence');
schema_ok($saved, $claimCap['output_schema'], 'claim upsert output conforms');
$hit = PRSTUDIO_UC_Editorial_Autonomy::claim_ledger(array('operation' => 'check', 'claim' => 'ApeNera is Sicilian gin'));
check(!empty($hit['verified']) && !empty($hit['found']), 'claim check finds stored evidence');
schema_ok($hit, $claimCap['output_schema'], 'claim check output conforms');
$badOp = PRSTUDIO_UC_Editorial_Autonomy::claim_ledger(array('operation' => 'merge', 'claim' => 'x'));
check(is_wp_error($badOp) && 'claim_operation_invalid' === $badOp->get_error_code(), 'claim invalid operation is typed error');

// [4/5] content.publish.transaction
$pubCap = PRSTUDIO_UC_Capability_Registry::describe('content.publish.transaction');
schema_ok(array('title' => 'Editorial Test', 'content' => '<p>Editorial Test</p>', 'status' => 'future'), $pubCap['input_schema'], 'publish accepts future status used by runtime');
schema_bad(array('title' => 'x'), $pubCap['input_schema'], 'publish requires content');
schema_bad(array('title' => 'x', 'content' => 'y', 'status' => 'inherit'), $pubCap['input_schema'], 'publish rejects unsupported status');
$needBody = PRSTUDIO_UC_Publish_Transaction::create_publish(array('title' => 'Only title', 'content' => '   '));
check(is_wp_error($needBody) && 'publish_content_required' === $needBody->get_error_code(), 'publish empty content is typed error');
$badType = PRSTUDIO_UC_Publish_Transaction::create_publish(array('title' => 'T', 'content' => 'C', 'post_type' => 'not-a-type'));
check(is_wp_error($badType) && 'publish_post_type_invalid' === $badType->get_error_code(), 'publish unknown post type is typed error');
$pub = PRSTUDIO_UC_Publish_Transaction::create_publish(array('title' => 'Editorial Test', 'slug' => 'editorial-test', 'content' => '<p>Editorial Test</p>', 'idempotency_key' => 'batch2-pub-1'));
check(!is_wp_error($pub) && true === ($pub['executed'] ?? null) && (int) $pub['post_id'] > 0, 'publish creates a post');
schema_ok($pub, $pubCap['output_schema'], 'publish create output conforms');
$replay = PRSTUDIO_UC_Publish_Transaction::create_publish(array('title' => 'Editorial Test', 'slug' => 'editorial-test', 'content' => '<p>Editorial Test</p>', 'idempotency_key' => 'batch2-pub-1'));
check(!is_wp_error($replay) && !empty($replay['idempotent_reuse']) && $replay['post_id'] === $pub['post_id'], 'publish replays identical idempotency key');
schema_ok($replay, $pubCap['output_schema'], 'publish replay output conforms');

// [5/5] content.transaction.patch
$patchCap = PRSTUDIO_UC_Capability_Registry::describe('content.transaction.patch');
schema_ok(array('id' => 1, 'operation' => 'replace_exact', 'search' => 'a', 'replacement' => 'b'), $patchCap['input_schema'], 'patch id input valid');
schema_ok(array('url' => 'https://example.test/x/', 'operation' => 'append_once', 'replacement' => 'tail'), $patchCap['input_schema'], 'patch URL input valid');
schema_bad(array('operation' => 'replace_exact', 'replacement' => 'b'), $patchCap['input_schema'], 'patch requires id or URL');
schema_bad(array('id' => 1, 'operation' => 'rewrite', 'replacement' => 'b'), $patchCap['input_schema'], 'patch rejects unknown operation');
$needId = PRSTUDIO_UC_Content_Transaction::patch(array('operation' => 'append_once', 'replacement' => 'x'));
check(is_wp_error($needId) && 'prstudio_content_id_required' === $needId->get_error_code(), 'patch unresolved target is typed error');
$missingPost = PRSTUDIO_UC_Content_Transaction::patch(array('id' => 999999, 'operation' => 'append_once', 'replacement' => 'x'));
check(is_wp_error($missingPost) && 'prstudio_content_missing' === $missingPost->get_error_code(), 'patch missing post is typed 404');
$seed = new WP_Post();
$seed->ID = 501;
$seed->post_name = 'patch-me';
$seed->post_content = 'Hello world';
$seed->post_modified_gmt = gmdate('c');
$GLOBALS['posts'][501] = $seed;
$needSearch = PRSTUDIO_UC_Content_Transaction::patch(array('id' => 501, 'operation' => 'replace_exact', 'replacement' => 'Hi'));
check(is_wp_error($needSearch) && 'prstudio_content_search_required' === $needSearch->get_error_code(), 'replace_exact without search is typed error');
$mismatch = PRSTUDIO_UC_Content_Transaction::patch(array('id' => 501, 'operation' => 'replace_exact', 'search' => 'absent', 'replacement' => 'Hi'));
check(is_wp_error($mismatch) && 'prstudio_content_anchor_count_mismatch' === $mismatch->get_error_code(), 'anchor count mismatch is typed 409');
$patched = PRSTUDIO_UC_Content_Transaction::patch(array('id' => 501, 'operation' => 'replace_exact', 'search' => 'Hello', 'replacement' => 'Hi', 'idempotency_marker' => '<!--p2-->'));
check(!is_wp_error($patched) && true === ($patched['changed'] ?? null) && true === ($patched['db_verified'] ?? null), 'patch replace_exact mutates and readbacks');
schema_ok($patched, $patchCap['output_schema'], 'patch success output conforms');
$replayPatch = PRSTUDIO_UC_Content_Transaction::patch(array('id' => 501, 'operation' => 'replace_exact', 'search' => 'Hello', 'replacement' => 'Hi', 'idempotency_marker' => '<!--p2-->'));
check(!is_wp_error($replayPatch) && false === $replayPatch['changed'] && 'marker_already_present' === ($replayPatch['reason'] ?? ''), 'patch marker replay is no-change');
schema_ok($replayPatch, $patchCap['output_schema'], 'patch replay output conforms');
$urlPatch = PRSTUDIO_UC_Content_Transaction::patch(array('url' => get_permalink(501), 'operation' => 'append_once', 'replacement' => 'END'));
check(!is_wp_error($urlPatch) && true === ($urlPatch['changed'] ?? null), 'patch accepts a resolvable URL instead of id');
schema_ok($urlPatch, $patchCap['output_schema'], 'patch URL success output conforms');

fwrite(STDOUT, "OK enterprise-schema-batch-2\n");
