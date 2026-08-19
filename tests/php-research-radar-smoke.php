<?php
/**
 * Research radar smoke — "Deep Academic Survey" + "SGHA: Evidence-Grounded
 * Research Problem Discovery" (arXiv 2026-08-13..19).
 *
 * Il radar classifica i paper arXiv sui 6 sottosistemi della suite e
 * produce 5 proposte con mappatura sottosistema -> area repo. Deterministico
 * (digest offline) e senza rete nei test.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-research-radar.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

// 1) Scan completo: sorgente digest offline, 5 proposte, mappature valide.
$scan = PRSTUDIO_UC_Research_Radar::scan( array() );
ok( true === $scan['ok'] && 'digest_offline' === $scan['source'], 'scan falls back to the offline digest without network' );
ok( 5 === count( $scan['proposals'] ), 'scan returns exactly 5 contribution proposals' );
foreach ( $scan['proposals'] as $proposal ) {
    ok( in_array( $proposal['subsystem'], PRSTUDIO_UC_Research_Radar::SUBSYSTEMS, true ), 'proposal subsystem is one of the 6 suite subsystems' );
    ok( '' !== $proposal['repo_area'] && false !== strpos( $proposal['repo_area'], '/' ), 'proposal maps to a concrete repo area (' . $proposal['repo_area'] . ')' );
    ok( '' !== $proposal['paper_id'] && '' !== $proposal['proposal'], 'proposal carries paper id and text' );
}
$top = $scan['classified'][0] ?? array();
ok( (int) ( $top['score'] ?? 0 ) >= (int) ( $scan['classified'][1]['score'] ?? 0 ), 'classified papers are sorted by relevance score' );

// 2) Determinismo: stesso input, stesso output.
$scan_again = PRSTUDIO_UC_Research_Radar::scan( array() );
ok( $scan['classified'] === $scan_again['classified'], 'scan is deterministic' );

// 3) Classificazione di paper noti.
$mobile = PRSTUDIO_UC_Research_Radar::classify( array(
    'title' => 'MobileWorldSafety: Benchmarking GUI Agent Safety Against Environmental Injection Attacks',
    'abstract' => 'safety of browser agents against environmental injection attacks',
    'category' => 'cs.CR',
) );
ok( 'security' === $mobile['subsystem'], 'MobileWorldSafety classifies to security' );

$wuying = PRSTUDIO_UC_Research_Radar::classify( array(
    'title' => 'Wuying-Browser-Agent: Real-World Centric Fundamental Long-Horizon Browser Agents',
    'abstract' => 'long-horizon browser agents with dense dom evidence states',
    'category' => 'cs.AI',
) );
ok( 'browser_agent' === $wuying['subsystem'], 'Wuying classifies to browser_agent' );

$fragility = PRSTUDIO_UC_Research_Radar::classify( array(
    'title' => 'On the Fragility of Self-Improving Agents: Variance, Task Order and a Way Forward',
    'abstract' => 'variance and task order sensitivity of agent runtimes; retry policies',
    'category' => 'cs.AI',
) );
ok( 'runtime_robustness' === $fragility['subsystem'], 'Fragility classifies to runtime_robustness' );

// 4) Filtro per categoria.
$scan_cr = PRSTUDIO_UC_Research_Radar::scan( array( 'category' => 'cs.CR' ) );
foreach ( $scan_cr['classified'] as $row ) {
    ok( 'cs.CR' === ( $row['category'] ?? '' ), 'category filter keeps only matching papers' );
}

// 5) Limiti: limit e window_days vengono clampati.
$scan_small = PRSTUDIO_UC_Research_Radar::scan( array( 'limit' => 3, 'window_days' => 999 ) );
ok( 3 === count( $scan_small['classified'] ), 'limit is honored (3 papers)' );
$scan_huge = PRSTUDIO_UC_Research_Radar::scan( array( 'limit' => 9999 ) );
ok( count( $scan_huge['classified'] ) <= 50, 'limit is clamped to 50' );

// 6) Il tool MCP è registrato con descrizione compatta (Law 9: ~40 token).
$mcp_source = (string) file_get_contents( dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php' );
ok( false !== strpos( $mcp_source, "self::tool('prstudio_research_radar'" ), 'MCP V5 registers prstudio_research_radar' );
ok( false !== strpos( $mcp_source, 'PRSTUDIO_UC_Research_Radar::scan' ), 'MCP V5 routes the radar tool to the module' );

fwrite( STDOUT, "PASS research radar smoke complete\n" );
exit( 0 );
