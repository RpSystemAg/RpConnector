<?php
/**
 * The same request, written in either language, must reach the same capability.
 *
 * Roughly 25 tools fit under the LAW 9 token ceiling. The other ~1270
 * capabilities are reachable only through prstudio_capability_search, so for
 * almost everything this product can do, search is not a convenience -- it is
 * the only door. The people operating this suite write Italian and the catalog
 * is written in English, which made that door language-dependent: "carica una
 * immagine", "gestisci le spedizioni" and "aggiungi un coupon" each returned
 * literally nothing, while "attiva il tema" returned the SEO autopilot.
 *
 * Two contracts are asserted here, and the second is the one that matters.
 *
 * TOP RESULT. A phrase in either language reaches the right capability. This
 * catches vocabulary gaps -- a word nobody taught the lexicon.
 *
 * WHOLE LIST. Two phrasings that mean the same thing return the same rows in
 * the same order. This is stronger on purpose. Agreeing on the first row while
 * disagreeing below it means the two languages are being ranked by different
 * rules and merely happen to collide at the top; the next catalog change moves
 * one and not the other, and the Italian operator quietly gets a worse product
 * than the English one. PRSTUDIO_UC_Action_Lexicon makes the equality
 * structural -- both phrases reduce to the same concepts before anything is
 * scored -- so this assertion either holds exactly or reveals a real defect.
 *
 * Measured before the lexicon existed, across 30 equivalent pairs: 11 returned
 * identical lists, 15 agreed on the first row, and 4 Italian phrases returned
 * nothing at all. After: 29 identical, 30 agreeing, none empty.
 *
 * The one pair that is deliberately NOT asserted as identical is "controlla gli
 * ordini" against "list orders". Those are not the same sentence: "controlla"
 * also means check and inspect, so it legitimately reaches more than "list"
 * does. They agree on the first row and diverge below it, which is the correct
 * answer rather than a gap. Its equivalent-phrasing counterpart, "elenca gli
 * ordini", is asserted identical below.
 *
 * Runs bare: catalog data only, no database and no network.
 */

declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', dirname(__DIR__) . '/');
define('PRSTUDIO_UC_DIR', dirname(__DIR__) . '/prstudio-unified-control/');
define('WPAIB_DIR', PRSTUDIO_UC_DIR);

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string { return rtrim($value, '/\\') . '/'; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($value): string {
        return strtolower((string)preg_replace('/[^a-z0-9_\-]/', '', str_replace(' ', '_', trim((string)$value))));
    }
}

// Capability discovery filters non-callable executors. These inert fixtures
// expose the real shipped index to the scoring path without executing work.
foreach (array(
    'PRSTUDIO_UC_Legacy_Capability_Executor',
    'PRSTUDIO_UC_Commerce_Engine',
    'PRSTUDIO_UC_Database_Engine',
    'PRSTUDIO_UC_SEO_Autopilot',
) as $class) {
    if (!class_exists($class)) {
        eval('class ' . $class . ' { public static function __callStatic($name, $arguments) { return array(); } }');
    }
}

require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-capability-registry.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-action-index.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-do.php';
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-agency.php';
require PRSTUDIO_UC_DIR . 'includes/class-wpaib-mcp.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/class-prstudio-uc-domain-abstract.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/class-prstudio-uc-orchestrator.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-browser.php';

$passed = 0;

function fail_relevance(string $message): void {
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** Every capability id a query returns, in the order it returns them. */
function result_ids(string $query): array {
    $result = PRSTUDIO_UC_Capability_Registry::search($query, array('limit' => 5));
    $ids = array();
    foreach ((array)($result['items'] ?? array()) as $item) {
        $ids[] = (string)($item['id'] ?? '');
    }
    return $ids;
}

function expect_top_result(string $language, string $query, string $expected): void {
    global $passed;
    $actual = (string)(result_ids($query)[0] ?? '');
    if ($actual !== $expected) {
        fail_relevance("{$language} query '{$query}' returned '{$actual}', expected '{$expected}'");
    }
    ++$passed;
    fwrite(STDOUT, "PASS {$language} {$query} -> {$actual}\n");
}

/**
 * The same request in two languages must produce the same ranked list.
 *
 * Reported as a diff rather than a bare mismatch, because when this breaks the
 * useful question is which side moved and by how much -- a missing word in the
 * lexicon shows up as one language collapsing, while a scoring change shows up
 * as both lists holding the same rows in a different order.
 */
function expect_identical_results(string $italian, string $english): void {
    global $passed;
    $it = result_ids($italian);
    $en = result_ids($english);
    if (array() === $it) {
        fail_relevance("italian query '{$italian}' found nothing; the lexicon is missing a word it uses");
    }
    if ($it !== $en) {
        fail_relevance(
            "'{$italian}' and '{$english}' mean the same thing but rank differently\n"
            . "        IT: " . (implode(' | ', $it) ?: '(nothing)') . "\n"
            . "        EN: " . (implode(' | ', $en) ?: '(nothing)')
        );
    }
    ++$passed;
    fwrite(STDOUT, "PASS identical  {$italian}  ==  {$english}\n");
}

/* -- The right capability, in either language ------------------------------ */

$italian = array(
    'svuota la cache' => 'legacy.browser.frontend-manage.purge-cache',
    'aggiorna il prezzo del prodotto' => 'commerce.product.update',
    'controlla gli ordini' => 'legacy.orders-customers.orders-manage.list',
    'pubblica un articolo' => 'legacy.content-seo.content-manage.publish',
    'crea un backup del database' => 'legacy.data-storage.backup-manage.create',
);
$english = array(
    'purge the cache' => 'legacy.browser.frontend-manage.purge-cache',
    'update product price' => 'commerce.product.update',
    'list woocommerce orders' => 'legacy.orders-customers.orders-manage.list',
    'publish an article' => 'legacy.content-seo.content-manage.publish',
    'create a database backup' => 'legacy.data-storage.backup-manage.create',
);

foreach ($italian as $query => $expected) { expect_top_result('IT', $query, $expected); }
foreach ($english as $query => $expected) { expect_top_result('EN', $query, $expected); }

/* -- The same ranking, in either language ---------------------------------- */

$equivalent = array(
    // Verbs, across the whole catalog rather than one corner of it.
    array('crea una pagina', 'create a page'),
    array('elimina un file', 'delete a file'),
    array('carica una immagine', 'upload an image'),
    array('scarica i log', 'download logs'),
    array('esporta i dati', 'export data'),
    array('importa i prodotti', 'import products'),
    array('ripristina il backup', 'restore the backup'),
    array('ottimizza le immagini', 'optimize images'),
    array('installa un plugin', 'install a plugin'),
    array('attiva il tema', 'activate the theme'),
    array('pianifica un cron', 'schedule a cron job'),
    array('genera la sitemap', 'generate the sitemap'),
    array('verifica i permessi', 'verify permissions'),
    array('cerca nel sito', 'search the site'),
    array('gestisci le spedizioni', 'manage shipping'),

    // Nouns, including the commerce vocabulary an Italian shop owner uses.
    array('elenca gli utenti', 'list users'),
    array('elenca gli ordini', 'list orders'),
    array('modifica le impostazioni', 'update settings'),
    array('cancella i commenti', 'delete comments'),
    array('modifica il menu', 'update the menu'),
    array('aggiungi un coupon', 'create a coupon'),
    array('controlla il magazzino', 'check inventory'),
    array('rimborsa un ordine', 'refund an order'),
    array('controlla la sicurezza', 'check security'),
    array('fai uno screenshot della pagina', 'take a page screenshot'),

    // Multi-word aliases must be consumed as one concept before stop words.
    array('genera la mappa del sito', 'generate the sitemap'),
    array('cerca le parole chiave', 'search keywords'),
    array('modifica il foglio di stile', 'update CSS'),
    array('crea un backup della banca dati', 'create a database backup'),
);

foreach ($equivalent as $pair) { expect_identical_results($pair[0], $pair[1]); }

/* -- The shared lexicon and hot action index obey the same language contract - */

function action_index_tools(string $query): array {
    return array_map(
        static fn(array $item): string => (string)($item['tool_name'] ?? ''),
        PRSTUDIO_UC_Action_Index::search($query, 8)
    );
}

$shared_pairs = array(
    array('crea una pagina', 'create a page'),
    array('ridimensiona una immagine', 'resize an image'),
    array('campo di input', 'input field'),
    array('piè di pagina', 'footer'),
    array('iva e fattura', 'vat and invoice'),
    array('carrello', 'cart'),
	array('mappa del sito', 'sitemap'),
	array('parole chiave', 'keywords'),
	array('foglio di stile', 'CSS'),
	array('banca dati', 'database'),
);
foreach ($shared_pairs as [$italian_query, $english_query]) {
    $it_concepts = PRSTUDIO_UC_Action_Lexicon::query_concepts($italian_query);
    $en_concepts = PRSTUDIO_UC_Action_Lexicon::query_concepts($english_query);
    if (!PRSTUDIO_UC_Action_Lexicon::equivalent($it_concepts, $en_concepts)) {
        fail_relevance("shared lexicon differs for '{$italian_query}'/'{$english_query}'");
    }
    if (!PRSTUDIO_UC_Action_Lexicon::covers($it_concepts, $en_concepts)
        || !PRSTUDIO_UC_Action_Lexicon::covers($en_concepts, $it_concepts)) {
        fail_relevance("shared concept coverage differs for '{$italian_query}'/'{$english_query}'");
    }
    if (action_index_tools($italian_query) !== action_index_tools($english_query)) {
        fail_relevance("action index ranking differs for '{$italian_query}'/'{$english_query}'");
    }
    if (result_ids($italian_query) !== result_ids($english_query)) {
        fail_relevance("capability registry ranking differs for '{$italian_query}'/'{$english_query}'");
    }
    if (PRSTUDIO_UC_Action_Index::domain_for_query($italian_query) !== PRSTUDIO_UC_Action_Index::domain_for_query($english_query)) {
        fail_relevance("action index domain differs for '{$italian_query}'/'{$english_query}'");
    }
    ++$passed;
    fwrite(STDOUT, "PASS shared action concepts {$italian_query} == {$english_query}\n");
}

$detailed_unknown = PRSTUDIO_UC_Action_Index::search_detailed('quantum banana frobnicate', 8);
if (!empty($detailed_unknown['items']) || 0 !== (int)($detailed_unknown['total_matches'] ?? -1)) {
    fail_relevance('shared action index returned results for a nonsense query');
}
++ $passed;
fwrite(STDOUT, "PASS shared action index rejects nonsense\n");

/* -- Specialist SEO workflows use the same route-scoped semantic ranking -- */

function seo_specialist_actions(string $query): array {
    $allowed = array_fill_keys(array(
        'build_keyword_map', 'audit_product_seo', 'audit_orphan_pages', 'build_internal_link_graph',
        'audit_http_statuses', 'audit_broken_internal_links', 'audit_sitemap_coverage',
        'set_canonical', 'set_description', 'set_title',
    ), true);
    $ranked = PRSTUDIO_UC_Action_Index::search_detailed($query, 50, 'content_seo', '/seo-manage');
    return array_values(array_map(
        static fn(array $item): string => (string)($item['action'] ?? ''),
        array_filter((array)($ranked['items'] ?? array()), static fn(array $item): bool => isset($allowed[(string)($item['action'] ?? '')]))
    ));
}

foreach (array(
    array('imposta canonical', 'set canonical', 'set_canonical'),
    array('imposta descrizione seo', 'set SEO description', 'set_description'),
    array('imposta titolo seo', 'set SEO title', 'set_title'),
) as [$italian_query, $english_query, $expected_action]) {
    $it_actions = seo_specialist_actions($italian_query);
    $en_actions = seo_specialist_actions($english_query);
    if (!$it_actions || $it_actions !== $en_actions || $expected_action !== $it_actions[0]) {
        fail_relevance("SEO specialist ranking differs for '{$italian_query}'/'{$english_query}'");
    }
    ++$passed;
    fwrite(STDOUT, "PASS SEO specialist {$italian_query} == {$english_query} -> {$expected_action}\n");
}

/* -- The legacy public discovery tool delegates to the shared index -------- */

function wpaib_match_result(string $query, bool $includeSchema = false): array {
    static $method = null;
    if (!$method) {
        $method = new ReflectionMethod('WPAIB_MCP', 'capability_matches');
        $method->setAccessible(true);
    }
    return (array)$method->invoke(null, $query, '', 8, $includeSchema);
}

$wpaib_pairs = array(
    array('gestisci le spedizioni', 'manage shipping'),
    array('pubblica un articolo', 'publish an article'),
    array('ottimizza le immagini', 'optimize images'),
    array('rimborsa un ordine', 'refund an order'),
);
foreach ($wpaib_pairs as [$italian_query, $english_query]) {
    $it = wpaib_match_result($italian_query);
    $en = wpaib_match_result($english_query);
    $it_matches = (array)($it['matches'] ?? array());
    $en_matches = (array)($en['matches'] ?? array());
    $it_tools = array_column($it_matches, 'tool_name');
    $en_tools = array_column($en_matches, 'tool_name');
    if (!$it_tools || $it_tools !== $en_tools) {
        fail_relevance("legacy capability ranking differs for '{$italian_query}'/'{$english_query}'");
    }
    foreach ($it_matches as $match) {
        if ('' === (string)($match['route'] ?? '') || 'rpconnector_action_call' !== (string)($match['call']['tool'] ?? '')) {
            fail_relevance("legacy capability adapter returned a non-routed or non-callable match for '{$italian_query}'");
        }
    }
    if ((int)($it['total_matches'] ?? 0) < count($it_matches)) {
        fail_relevance("legacy capability adapter returned an invalid total for '{$italian_query}'");
    }
    ++$passed;
    fwrite(STDOUT, "PASS legacy discovery {$italian_query} == {$english_query}\n");
}

$without_schema = wpaib_match_result('aggiungi un coupon', false);
$with_schema = wpaib_match_result('create a coupon', true);
if (array_column((array)$without_schema['matches'], 'tool_name') !== array_column((array)$with_schema['matches'], 'tool_name')) {
    fail_relevance('legacy schema inclusion changed bilingual ranking');
}
if (isset($without_schema['matches'][0]['input_schema']) || !isset($with_schema['matches'][0]['input_schema'])) {
    fail_relevance('legacy schema inclusion no longer follows include_schema');
}
++ $passed;
fwrite(STDOUT, "PASS legacy discovery schema flag preserves ranking\n");

/* -- The public command front door has equivalent IT/EN fast paths -------- */

$direct_intents = array(
    array('apri', 'open', 'browser_open'),
    array('clicca', 'click', 'browser_click'),
    array('compila', 'fill', 'browser_fill'),
    array('leggi', 'read', 'prstudio_observe'),
    array('modifica contenuto', 'edit content', 'wordpress_content_transaction'),
    array('salute', 'health', 'prstudio_health'),
);
foreach ($direct_intents as $case) {
    [$italian_intent, $english_intent, $expected_tool] = $case;
    $it = PRSTUDIO_UC_Do::resolve(array('intent' => $italian_intent));
    $en = PRSTUDIO_UC_Do::resolve(array('intent' => $english_intent));
    $it_tool = is_array($it) ? (string)($it['tool'] ?? '') : '';
    $en_tool = is_array($en) ? (string)($en['tool'] ?? '') : '';
    if ($expected_tool !== $it_tool || $it_tool !== $en_tool) {
        fail_relevance("prstudio_do parity failed for '{$italian_intent}'/'{$english_intent}': IT={$it_tool}, EN={$en_tool}, expected={$expected_tool}");
    }
    ++$passed;
    fwrite(STDOUT, "PASS direct intent {$italian_intent} == {$english_intent} -> {$expected_tool}\n");
}

$fallback = PRSTUDIO_UC_Do::resolve(array('intent' => 'quantum banana frobnicate'));
if (true !== ($fallback['arguments']['include_legacy'] ?? null)) {
    fail_relevance('prstudio_do fallback does not search the complete compatibility catalog');
}
++ $passed;
fwrite(STDOUT, "PASS command fallback includes the complete compatibility catalog\n");

/* -- Orchestrator and domain routing share the same semantic vocabulary ---- */

final class PRSTUDIO_UC_Test_Semantic_Domain extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'orders_customers'; }
    public function label(): string { return 'Orders and customers'; }
    public function routes(): array { return array('/orders-manage', '/customers-manage'); }
    public function keywords(): array { return array('refund', 'order', 'customer'); }
}

$normalized = PRSTUDIO_UC_Orchestrator::normalize('Velocità del sito');
if ($normalized !== PRSTUDIO_UC_Action_Lexicon::normalize_text('Velocità del sito')) {
    fail_relevance('orchestrator normalization no longer delegates to the shared lexicon');
}
$it_tokens = PRSTUDIO_UC_Orchestrator::tokens('rimborsa un ordine');
$en_tokens = PRSTUDIO_UC_Orchestrator::tokens('refund an order');
if (!$it_tokens || $it_tokens !== $en_tokens) {
    fail_relevance('orchestrator tokens differ for equivalent Italian and English intent');
}
++ $passed;
fwrite(STDOUT, "PASS orchestrator normalization and tokens use shared concepts\n");

$browser_signal = new ReflectionMethod('PRSTUDIO_UC_Orchestrator', 'has_any_concept');
$browser_signal->setAccessible(true);
if (!$browser_signal->invoke(null, 'fai uno screenshot della pagina', 'browser screenshot click console network dom')) {
    fail_relevance('Italian screenshot intent no longer reaches the semantic browser fast path');
}
if ($browser_signal->invoke(null, 'pubblica un articolo', 'browser screenshot click console network dom')) {
    fail_relevance('content-only intent incorrectly reaches the semantic browser fast path');
}
++ $passed;
fwrite(STDOUT, "PASS semantic browser fast path is bilingual and bounded\n");

$semantic_domain = new PRSTUDIO_UC_Test_Semantic_Domain();
$it_actions = $semantic_domain->actions(array(), 'rimborsa un ordine', 8, false);
$en_actions = $semantic_domain->actions(array(), 'refund an order', 8, false);
$it_action_tools = array_column($it_actions, 'tool_name');
$en_action_tools = array_column($en_actions, 'tool_name');
if (!$it_action_tools || $it_action_tools !== $en_action_tools) {
    fail_relevance('domain action ranking differs for equivalent Italian and English intent');
}
foreach (array('tool_name', 'route', 'action', 'parameters', 'index_score') as $field) {
    if (!array_key_exists($field, $it_actions[0])) {
        fail_relevance("domain action adapter no longer preserves '{$field}'");
    }
}
if (isset($it_actions[0]['input_schema'])) {
    fail_relevance('domain action adapter returned input_schema when include_schema=false');
}
$it_domain_score = $semantic_domain->score('rimborsa un ordine', array());
$en_domain_score = $semantic_domain->score('refund an order', array());
if ($it_domain_score < 1 || $it_domain_score !== $en_domain_score) {
    fail_relevance('domain keyword scoring is not semantically equivalent in Italian and English');
}
++ $passed;
fwrite(STDOUT, "PASS domain actions and scoring preserve bilingual ranking and shape\n");

/* -- Browser composite workflows consume the central bilingual catalogue --- */

function browser_workflow_actions(string $objective, array $arguments = array()): array {
    static $domain = null;
    if (!$domain) { $domain = new PRSTUDIO_Domain_Browser(); }
    return array_column($domain->workflow($objective, $arguments, array()), 'action');
}

$browser_workflow_pairs = array(
    array('apri', 'open', array('url' => 'https://example.com/')),
    array('naviga', 'navigate', array('url' => 'https://example.com/', 'tab_id' => 7)),
    array('clicca', 'click', array('selector' => '#submit')),
    array('compila', 'fill', array('selector' => '#email', 'value' => 'name@example.com')),
    array('schermata', 'screenshot', array('url' => 'https://example.com/')),
    array('scansiona sito', 'crawl', array('url' => 'https://example.com/')),
    array('scansiona sitemap', 'crawl sitemap', array('url' => 'https://example.com/sitemap.xml')),
    array('ispeziona', 'inspect', array()),
    array('estrai', 'extract', array('selector' => 'main')),
    array('prestazioni GSC', 'GSC performance', array()),
    array(
        'apri https://example.com/ e fai una schermata',
        'open https://example.com/ and take a screenshot',
        array(),
    ),
);
foreach ($browser_workflow_pairs as [$italian_objective, $english_objective, $arguments]) {
    $it_actions = browser_workflow_actions($italian_objective, $arguments);
    $en_actions = browser_workflow_actions($english_objective, $arguments);
    if (!$it_actions || $it_actions !== $en_actions) {
        fail_relevance(
            "browser workflow differs for '{$italian_objective}'/'{$english_objective}': "
            . 'IT=' . implode(',', $it_actions) . ' EN=' . implode(',', $en_actions)
        );
    }
    ++$passed;
    fwrite(STDOUT, "PASS browser workflow {$italian_objective} == {$english_objective}\n");
}

/* -- Nonsense still finds nothing ------------------------------------------ */

$unknown = PRSTUDIO_UC_Capability_Registry::search('quantum banana frobnicate', array('limit' => 5));
if (0 !== (int)($unknown['count'] ?? -1) || !empty($unknown['items'])) {
    fail_relevance('unmatched query returned irrelevant native capabilities');
}
++$passed;
fwrite(STDOUT, "PASS unmatched query fails closed without irrelevant native results\n");

/* -- Filler words carry no meaning ----------------------------------------- */

// "del" is inside "delete" and "il" is inside "profile". When search matched on
// substrings, the filler words of an ordinary Italian sentence scored against
// unrelated capabilities and drowned the one word that carried the intent --
// which is how "aggiorna il prezzo del prodotto" came back with delete-file.
foreach (array('il', 'del', 'la', 'una', 'per', 'the', 'with') as $filler) {
    if (!PRSTUDIO_UC_Action_Lexicon::is_stop_word($filler)) {
        fail_relevance("'{$filler}' carries no intent but is not treated as a stop word");
    }
}
++$passed;
fwrite(STDOUT, "PASS filler words in both languages carry no weight\n");

fwrite(STDOUT, "SUMMARY {$passed} passed, 0 failed\n");
