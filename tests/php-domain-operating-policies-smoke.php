<?php
/**
 * A domain method must reach the operator regardless of which language they use.
 *
 * The SEO policy showed what happens when bilingual activation is left to
 * chance: it fired on 6 of 18 equivalent Italian objectives and 7 of 18 English
 * ones, and three equivalent pairs disagreed with each other outright. The
 * operator whose language lost the coin toss does the work without the method
 * and never learns that a method existed.
 *
 * So the primary assertion here is agreement, not correctness. Correctness
 * catches a word nobody taught; agreement catches the failure that ships
 * silently, because both halves of a disagreeing pair look fine on their own.
 *
 * The negative cases matter as much as the positive ones. A method that
 * attaches to everything is not a method, it is noise with a version number --
 * and these four policies between them name most of what this product does, so
 * the boundaries are where they earn their keep.
 *
 * Runs bare: contract files only, no database and no network.
 */

declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', dirname(__DIR__) . '/');
define('PRSTUDIO_UC_DIR', dirname(__DIR__) . '/prstudio-unified-control/');

require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-domain-policies.php';

$passed = 0;
$failed = 0;

function policy_check(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        ++$passed;
        fwrite(STDOUT, "PASS {$message}\n");
        return;
    }
    ++$failed;
    fwrite(STDERR, "FAIL {$message}\n");
}

const EXTENSIONS = 'prstudio.extensions-operating-policy';
const DATA       = 'prstudio.data-operating-policy';
const COMMERCE   = 'prstudio.commerce-operating-policy';
const BROWSER    = 'prstudio.browser-evidence-policy';

/* -- Every contract is readable and shaped like a method ------------------- */

foreach (PRSTUDIO_UC_Domain_Policies::ids() as $id) {
    $doc = PRSTUDIO_UC_Domain_Policies::load($id);
    policy_check(
        empty($doc['_error']) && $id === (string)($doc['id'] ?? ''),
        "contract loads and identifies itself: {$id}" . (empty($doc['_error']) ? '' : " [{$doc['_error']}]")
    );
    policy_check(
        count((array)($doc['method'] ?? [])) >= 4
        && count((array)($doc['operating_rules'] ?? [])) >= 5
        && count((array)($doc['quality_gate']['dimensions'] ?? [])) >= 3,
        "carries a method, rules and gate dimensions: {$id}"
    );
    // A PASS/ITERATE gate with no invented number is the property that keeps a
    // quality judgement honest: a score of 7.4 reads like a measurement, and
    // nothing here measures anything.
    policy_check(
        ((array)($doc['quality_gate']['decision'] ?? [])) === ['PASS', 'ITERATE']
        && !empty($doc['quality_gate']['no_numeric_score']),
        "gate is PASS/ITERATE without a fabricated score: {$id}"
    );
    // A policy that added a tool would be changing the surface rather than
    // describing how to use it, and LAW 9 pays for every tool in the surface.
    policy_check(
        0 === (int)($doc['tool_surface']['new_public_mcp_tools'] ?? -1),
        "adds no public tool: {$id}"
    );
}

/* -- The same objective, either language, the same policies ---------------- */

$pairs = array(
    // [italian, english, policies expected to attach]
    array('installa un plugin',                     'install a plugin',                    [EXTENSIONS]),
    array('attiva il tema figlio',                  'activate the child theme',            [EXTENSIONS]),
    array('il sito e bianco dopo l aggiornamento',  'white screen after the update',       [EXTENSIONS]),
    array('modifica functions.php',                 'edit functions.php',                  [EXTENSIONS]),
    array('risolvi un conflitto tra plugin',        'resolve a plugin conflict',           [EXTENSIONS]),

    array('fai un backup del database',             'take a database backup',              [DATA]),
    array('ripristina il backup',                   'restore the backup',                  [DATA]),
    array('elimina le righe orfane',                'delete the orphaned rows',            [DATA]),
    array('ottimizza le tabelle',                   'optimise the tables',                 [DATA]),
    array('esegui una migrazione',                  'run a migration',                     [DATA]),

    array('aggiorna il prezzo del prodotto',        'update the product price',            [COMMERCE]),
    array('rimborsa un ordine',                     'refund an order',                     [COMMERCE]),
    array('controlla il magazzino',                 'check the stock',                     [COMMERCE]),
    array('crea un buono sconto',                   'create a discount coupon',            [COMMERCE]),
    array('verifica l iva sulla fattura',           'check the vat on the invoice',        [COMMERCE]),
    array('sistema il carrello',                    'fix the cart',                        [COMMERCE]),

    array('fai uno screenshot della pagina',        'take a page screenshot',              [BROWSER]),
    array('clicca sul pulsante',                    'click the button',                    [BROWSER]),
    array('confronta i pixel',                      'compare the pixels',                  [BROWSER]),
    array('apri l editor a blocchi',                'open the block editor',               [BROWSER]),
    array('leggi la console del browser',           'read the browser console',            [BROWSER]),

    // Work that is genuinely two things at once. Both methods have something to
    // say, and returning both is the right answer rather than a collision.
    array('fai uno screenshot del prezzo del prodotto', 'screenshot the product price',    [COMMERCE, BROWSER]),
    array('clicca per attivare il plugin',              'click to activate the plugin',    [EXTENSIONS, BROWSER]),

    // Work none of them govern. The SEO policy covers the first two; nothing
    // here should reach for them.
    array('scrivi la meta description',             'write the meta description',          []),
    array('controlla il posizionamento',            'check the ranking',                   []),
    array('crea un utente amministratore',          'create an administrator user',        []),
    array('svuota la cache',                        'purge the cache',                     []),
);

foreach ($pairs as $row) {
    list($italian, $english, $expected) = $row;
    $it = PRSTUDIO_UC_Domain_Policies::for_objective($italian);
    $en = PRSTUDIO_UC_Domain_Policies::for_objective($english);

    $short = static function (array $ids): string {
        if ([] === $ids) { return '(nessuna)'; }
        return implode('+', array_map(static fn(string $id): string => explode('.', $id)[1] ?? $id, $ids));
    };

    policy_check(
        $it === $en,
        sprintf('same policies in both languages: "%s" / "%s"  [IT %s | EN %s]',
            $italian, $english, $short($it), $short($en))
    );
    policy_check(
        $it === $expected,
        sprintf('right policies for "%s": expected %s, got %s',
            $italian, $short($expected), $short($it))
    );
}

/* -- Attaching the method is what runtime_context is for ------------------- */

$context = PRSTUDIO_UC_Domain_Policies::runtime_context('rimborsa un ordine');
policy_check(
    true === ($context['applicable'] ?? false)
    && [COMMERCE] === ($context['policy_ids'] ?? [])
    && !empty($context['policies'][COMMERCE]['operating_rules']),
    'runtime_context attaches the full commerce method, not just its name'
);

$none = PRSTUDIO_UC_Domain_Policies::runtime_context('scrivi la meta description');
policy_check(
    false === ($none['applicable'] ?? true) && [] === ($none['policy_ids'] ?? ['x']),
    'runtime_context attaches nothing when no domain governs the objective'
);

/* -- Accents must not decide which policy the operator gets ---------------- */

// iconv's ASCII//TRANSLIT once folded "piè" to "pi`e" on some platforms, which
// meant an accented word matched in CI and not on the machine it was written
// on. Anything that routes on human text has to be immune to that.
$accented = array(
    array('controlla la disponibilita in magazzino', 'controlla la disponibilità in magazzino'),
    array('verifica l integrita del database',       'verifica l integrità del database'),
);
foreach ($accented as $row) {
    policy_check(
        PRSTUDIO_UC_Domain_Policies::for_objective($row[0]) === PRSTUDIO_UC_Domain_Policies::for_objective($row[1]),
        sprintf('accent does not change the answer: "%s"', $row[1])
    );
}

/* --------------------------------------------------------------------------- */

if ($failed > 0) {
    fwrite(STDERR, "\ndomain operating policies: {$failed} failed, {$passed} passed\n");
    exit(1);
}
fwrite(STDOUT, "SUMMARY {$passed} passed, 0 failed\n");
