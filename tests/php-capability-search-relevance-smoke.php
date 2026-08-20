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
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-do.php';

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
);

foreach ($equivalent as $pair) { expect_identical_results($pair[0], $pair[1]); }

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
