<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('PRSTUDIO_UC_TESTING', true);
define('PRSTUDIO_UC_DIR', dirname(__DIR__) . '/prstudio-unified-control/');
define('WPAIB_DIR', PRSTUDIO_UC_DIR);
final class WP_Error {
    public function __construct(private string $code='', private string $message='', private array $data=[]){ }
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function sanitize_text_field(string $v): string { return trim((string)preg_replace('/[\x00-\x1F\x7F]/u','',$v)); }
function trailingslashit(string $v): string { return rtrim($v, '/\\') . '/'; }
function sanitize_key($v): string { return strtolower((string)preg_replace('/[^a-z0-9_\-]/','',str_replace(' ','_',trim((string)$v)))); }

// Capability discovery filters non-callable executors. These inert fixtures
// expose the real shipped route catalog without executing any work.
foreach ([
    'PRSTUDIO_UC_Legacy_Capability_Executor',
    'PRSTUDIO_UC_Commerce_Engine',
    'PRSTUDIO_UC_Database_Engine',
    'PRSTUDIO_UC_SEO_Autopilot',
] as $class) {
    if (!class_exists($class)) {
        eval('class ' . $class . ' { public static function __callStatic($name, $arguments) { return array(); } }');
    }
}
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-action-index.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-agency.php';
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-wpaib-mcp.php';
function ok(bool $c,string $m): void { if(!$c){fwrite(STDERR,"FAIL {$m}\n");exit(1);} fwrite(STDOUT,"PASS {$m}\n"); }
$protocols=(new ReflectionMethod(WPAIB_MCP::class,'supported_protocols'))->invoke(null);
ok($protocols===['2025-11-25','2025-06-18','2025-03-26'],'legacy MCP advertises only implemented 2025 protocol generations');
$_SERVER['HTTP_MCP_PROTOCOL_VERSION']='2026-07-28';
$r=(new ReflectionMethod(WPAIB_MCP::class,'validate_protocol_header'))->invoke(null,'initialize');
ok(is_wp_error($r)&&$r->get_error_code()==='wpaib_mcp_protocol_unsupported','legacy initialize rejects final 2026 protocol header');
$_SERVER['HTTP_MCP_PROTOCOL_VERSION']='2025-11-25';
$r=(new ReflectionMethod(WPAIB_MCP::class,'validate_protocol_header'))->invoke(null,'initialize');
ok($r===true,'legacy initialize retains 2025-11-25 compatibility');
unset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']);
$r=(new ReflectionMethod(WPAIB_MCP::class,'validate_protocol_header'))->invoke(null,'initialize');
ok($r===true,'legacy initialize retains headerless 2025-03-26 compatibility');

/* -- rpconnector_capability_search speaks the operator's language ----------
 *
 * This surface is the legacy protocol's only door to ~1270 capabilities, and
 * until recently it opened onto a private copy of the matcher: its own
 * italian->english synonym table, its own stop words, and a `phrase_action`
 * bonus worth 300 points that fired when the query joined by underscores
 * equalled an action name. "publish content" earned it; "pubblica contenuto"
 * could not, because no action in the catalog is called pubblica_contenuto.
 * The result was two rankings for one request under one version number.
 *
 * These assertions are deliberately about AGREEMENT rather than correctness.
 * Asserting that the English phrase finds the right tool proves the English
 * product works. Asserting that the Italian phrase finds the same rows in the
 * same order is what proves there is only one product.
 */

$matches = new ReflectionMethod(WPAIB_MCP::class, 'capability_matches');
$matches->setAccessible(true);
/** @return array{matches:array<int,array<string,mixed>>,total_matches:int} */
function legacy_search(string $query, string $route = '', int $limit = 8, bool $schema = false): array {
    global $matches;
    return (array)$matches->invoke(null, $query, $route, $limit, $schema);
}
/** @return array<int,string> */
function legacy_tools(string $query, string $route = '', int $limit = 8): array {
    return array_column((array)legacy_search($query, $route, $limit)['matches'], 'tool_name');
}

ok(legacy_tools('publish an article') !== [], 'legacy capability search still answers an English request');

foreach ([
    ['pubblica un articolo', 'publish an article'],
    ['crea una pagina', 'create a page'],
    ['elimina un file', 'delete a file'],
    ['carica una immagine', 'upload an image'],
    ['aggiungi un coupon', 'create a coupon'],
    ['gestisci le spedizioni', 'manage shipping'],
    ['rimborsa un ordine', 'refund an order'],
    ['controlla il magazzino', 'check inventory'],
    ['svuota la cache', 'purge the cache'],
    ['imposta descrizione seo', 'set SEO description'],
] as [$italian, $english]) {
    $it = legacy_tools($italian);
    $en = legacy_tools($english);
    ok($it !== [], "legacy capability search finds something for '{$italian}'");
    ok(
        $it === $en,
        "legacy capability search ranks '{$italian}' exactly as '{$english}'"
        . ($it === $en ? '' : "\n        IT: " . implode(' | ', $it) . "\n        EN: " . implode(' | ', $en))
    );
}

/* The route filter still narrows, and narrows identically in both languages. */
foreach ([
    ['/content-manage', 'pubblica un articolo', 'publish an article'],
    ['/inventory-manage', 'aggiorna il prezzo', 'update the price'],
] as [$route, $italian, $english]) {
    $scoped_it = legacy_search($italian, $route);
    $scoped_en = legacy_search($english, $route);
    ok($scoped_it['matches'] !== [], "route-filtered legacy search returns rows for '{$italian}'");
    ok(
        array_column($scoped_it['matches'], 'tool_name') === array_column($scoped_en['matches'], 'tool_name'),
        "the {$route} filter produces one ranking, not one per language"
    );
    foreach ($scoped_it['matches'] as $match) {
        ok($route === (string)($match['route'] ?? ''), "the {$route} filter admits only its own route");
    }
    ok(
        $scoped_it['total_matches'] <= legacy_search($italian)['total_matches'],
        "filtering by {$route} never widens the result set"
    );
}
// The filter is accepted as a path, as a bare slug and as the MCP tool name,
// and all three must resolve to the same route rather than to three answers.
ok(
    legacy_tools('pubblica un articolo', '/content-manage')
    === legacy_tools('pubblica un articolo', 'content_manage')
    && legacy_tools('pubblica un articolo', 'content_manage')
    === legacy_tools('pubblica un articolo', 'rpconnector_content_manage'),
    'path, slug and tool-name spellings of a route filter agree'
);
ok(legacy_search('pubblica un articolo', '/route-che-non-esiste')['matches'] !== [], 'an unresolvable route filter falls back to the routed catalog rather than failing closed');

/* The legacy payload contract: every row stays callable and routed. */
$shape = legacy_search('pubblica un articolo', '', 5, true);
ok($shape['matches'] !== [], 'schema-inclusive legacy search still returns rows');
foreach ($shape['matches'] as $match) {
    ok('' !== (string)($match['route'] ?? ''), 'every legacy match carries its route');
    ok('rpconnector_action_call' === (string)($match['call']['tool'] ?? ''), 'every legacy match stays callable through rpconnector_action_call');
    ok(isset($match['input_schema']), 'include_schema=true attaches the input schema');
    foreach (['tool_name','action','route_tool','title','description','read_only','destructive','parameters','score'] as $field) {
        ok(array_key_exists($field, $match), "legacy match preserves the '{$field}' field");
    }
    ok(is_int($match['score']), 'the legacy score field stays an integer');
    break;
}
ok(!isset(legacy_search('pubblica un articolo', '', 5, false)['matches'][0]['input_schema']), 'include_schema=false omits the input schema');
ok(
    array_column((array)legacy_search('pubblica un articolo', '', 5, true)['matches'], 'tool_name')
    === array_column((array)legacy_search('pubblica un articolo', '', 5, false)['matches'], 'tool_name'),
    'asking for schemas does not reorder the results'
);

$limited = legacy_search('pubblica un articolo', '', 3);
ok(count($limited['matches']) <= 3, 'the limit argument bounds the returned rows');
ok($limited['total_matches'] >= count($limited['matches']), 'total_matches counts every match, not only the returned page');

/* Nonsense finds nothing, in either language's shape. */
foreach (['quantum banana frobnicate', 'zxqwvy plughgorp', 'frobnicare il quantistico banano'] as $nonsense) {
    $none = legacy_search($nonsense);
    ok([] === $none['matches'], "legacy capability search returns nothing for '{$nonsense}'");
    ok(0 === (int)$none['total_matches'], "legacy total_matches is zero for '{$nonsense}'");
}

fwrite(STDOUT,"OK legacy MCP protocol surface smoke\n");
