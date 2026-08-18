<?php

declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('PRSTUDIO_UC_VERSION', '1.0.0');
define('DAY_IN_SECONDS', 86400);
$test_root = sys_get_temp_dir() . '/prstudio-intelligence-' . bin2hex(random_bytes(8));
define('WP_CONTENT_DIR', $test_root);

function sanitize_key(string $value): string {
    return trim((string)preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)), '-_');
}

$control = dirname(__DIR__) . '/prstudio-unified-control/includes/';
require_once $control . 'class-prstudio-uc-memory.php';
require_once $control . 'class-prstudio-uc-agency-state.php';
require_once $control . 'class-prstudio-uc-operational-twin.php';
require_once $control . 'class-prstudio-uc-social-intelligence.php';
require_once $control . 'class-prstudio-uc-opportunity-engine.php';
require_once $control . 'class-prstudio-uc-agency-capabilities.php';
require_once $control . 'class-prstudio-uc-capability-registry.php';
require_once $control . 'class-prstudio-uc-sequential-thinking.php';
require_once $control . 'class-prstudio-uc-procedural-skills.php';

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function remove_test_tree(string $path, string $expected_root): void {
    $real = realpath($path);
    $temp = realpath($expected_root);
    if (false === $real || false === $temp || !str_starts_with($real, $temp . DIRECTORY_SEPARATOR . 'prstudio-intelligence-')) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($real);
}

try {
    $twin = PRSTUDIO_UC_Operational_Twin::ingest([
        ['type'=>'content', 'external_id'=>'42', 'label'=>'Guida storica', 'url'=>'https://example.test/guida', 'attributes'=>['modified_gmt'=>'2024-01-01T00:00:00Z']],
        ['type'=>'product', 'external_id'=>'99', 'label'=>'Prodotto', 'url'=>'https://example.test/prodotto', 'attributes'=>['stock_status'=>'outofstock', 'price'=>'']],
    ], [], PRSTUDIO_UC_Operational_Twin::provenance('api', 'test_fixture', 1.0));
    assert_true(true === ($twin['ok'] ?? false), 'operational twin ingest');
    assert_true(2 === ($twin['accepted'] ?? 0), 'operational twin accepted count');

    $social = PRSTUDIO_UC_Social_Intelligence::ingest([
        'platform'=>'instagram', 'account'=>'@prstudio', 'source'=>'api',
        'observed_gmt'=>'2026-08-08T08:00:00Z',
        'metrics'=>['reach'=>10000, 'impressions'=>14000, 'likes'=>900, 'comments'=>80, 'shares'=>350, 'saves'=>420, 'clicks'=>30, 'conversions'=>4, 'api_key'=>123456],
        'content'=>[[
            'id'=>'reel-1', 'type'=>'reel', 'url'=>'https://instagram.com/p/example',
            'metrics'=>['reach'=>8000, 'likes'=>800, 'comments'=>70, 'shares'=>330, 'saves'=>400, 'views'=>12000, 'completions'=>9000, 'clicks'=>20],
        ]],
    ]);
    assert_true(true === ($social['ok'] ?? false), 'social snapshot ingest');
    assert_true((float)($social['snapshot']['derived']['engagement_rate'] ?? 0) > 0, 'engagement derivation');
    assert_true((float)($social['snapshot']['derived']['virality_score'] ?? 0) > 0, 'virality derivation');
    assert_true(!array_key_exists('api_key', (array)$social['snapshot']['metrics']), 'sensitive metric names are rejected');

    $insights = PRSTUDIO_UC_Social_Intelligence::insights(['platform'=>'instagram']);
    assert_true(1 === ($insights['snapshot_count'] ?? 0), 'social insight filtering');
    assert_true(1 === count((array)($insights['top_content'] ?? [])), 'top content extraction');

    $opportunities = PRSTUDIO_UC_Opportunity_Engine::rank(['limit'=>20]);
    assert_true(true === ($opportunities['ok'] ?? false), 'opportunity rank');
    assert_true((int)($opportunities['count'] ?? 0) >= 3, 'opportunities from social/content/commerce evidence');
    $scores = array_map(static fn($item)=>(float)$item['score'], (array)$opportunities['items']);
    $sorted = $scores; rsort($sorted, SORT_NUMERIC);
    assert_true($scores === $sorted, 'opportunities sorted deterministically');

    $state_file = PRSTUDIO_UC_Memory::site_dir() . '/agency-v10/social-intelligence.state.php';
    assert_true(is_file($state_file), 'private durable social state exists');
    $state_raw = (string)file_get_contents($state_file);
    assert_true(!str_contains($state_raw, 'Bearer fixture-secret'), 'state does not leak bearer secrets');

    $registry_counts = PRSTUDIO_UC_Capability_Registry::counts();
    assert_true(1323 <= (int)($registry_counts['capabilities'] ?? 0), 'registry preserves the 10.0 capability baseline and allows additive capabilities');
    assert_true(PRSTUDIO_UC_Capability_Registry::is_executable('sequential.thinking'), 'Sequential Thinking capability is callable');
    assert_true(PRSTUDIO_UC_Capability_Registry::is_executable('skills.search'), 'procedural skill search capability is callable');
    assert_true(PRSTUDIO_UC_Capability_Registry::is_executable('social.insights'), 'overlay executor is callable');

    fwrite(STDOUT, "PHP intelligence smoke: 18 assertions passed\n");
} finally {
    remove_test_tree($test_root, sys_get_temp_dir());
}
