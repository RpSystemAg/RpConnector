<?php
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

function fail_relevance(string $message): void {
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function expect_top_result(string $language, string $query, string $expected): void {
    $result = PRSTUDIO_UC_Capability_Registry::search($query, array('limit' => 5));
    $actual = (string)($result['items'][0]['id'] ?? '');
    if ($actual !== $expected) {
        fail_relevance("{$language} query '{$query}' returned '{$actual}', expected '{$expected}'");
    }
    fwrite(STDOUT, "PASS {$language} {$query} -> {$actual}\n");
}

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

$unknown = PRSTUDIO_UC_Capability_Registry::search('quantum banana frobnicate', array('limit' => 5));
if (0 !== (int)($unknown['count'] ?? -1) || !empty($unknown['items'])) {
    fail_relevance('unmatched query returned irrelevant native capabilities');
}
fwrite(STDOUT, "PASS unmatched query fails closed without irrelevant native results\n");
fwrite(STDOUT, "SUMMARY 11 passed, 0 failed\n");
