<?php
/**
 * Measure the tools/list payload against host tool-schema budgets.
 *
 * WHY THIS EXISTS
 * ---------------
 * A connected host does not read tools/list from an unlimited buffer. ChatGPT's
 * MCP connector rejects a server whose combined tool surface is too large, with
 * an in-product error to the effect that all tools -- name, description and
 * input schema together -- must stay under 5,000 tokens. That limit is not in
 * OpenAI's published documentation; it surfaces only as that error string, so
 * nothing here can assert it as a contract. What this test CAN do is measure the
 * payload the suite actually emits, so the number stops being invisible.
 *
 * It matters because an oversized surface does not fail loudly. The reported
 * symptom is tools that appear in the prompt but are not callable -- "not
 * exposed" -- which reads like a permissions or protocol problem and sends you
 * looking in the wrong place entirely.
 *
 * The class comment on tools/list states the intent plainly: descriptions are
 * one line each "so the entire surface fits in context". This test is how that
 * claim gets checked instead of assumed.
 *
 * Thresholds are advisory by design. Exceeding a third-party host's undocumented
 * budget is a fact worth printing on every run, not a reason to fail this repo's
 * build -- the number is what the maintainer needs, and a hard failure here would
 * encode someone else's unpublished constant as our contract.
 *
 * Usage: php tests/php-tools-list-budget.php
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );
require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';

/** Rough token estimate. Good enough to see an order of magnitude. */
function approx_tokens( int $bytes ): int { return (int) round( $bytes / 4 ); }

$tools = PRSTUDIO_UC_MCP_V5::tools();
if ( ! is_array( $tools ) || ! $tools ) {
    fwrite( STDERR, "FAIL tools() returned nothing\n" );
    exit( 1 );
}

// Exactly what a host ingests for tool selection: name, description, schema.
$surface = array_map(
    static function ( array $t ): array {
        return array(
            'name' => $t['name'] ?? '',
            'description' => $t['description'] ?? '',
            'inputSchema' => $t['inputSchema'] ?? array(),
        );
    },
    $tools
);
$payload = (string) json_encode( $surface, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$bytes = strlen( $payload );
$tokens = approx_tokens( $bytes );

$per_tool = array();
foreach ( $tools as $t ) {
    $one = (string) json_encode(
        array( 'name' => $t['name'] ?? '', 'description' => $t['description'] ?? '', 'inputSchema' => $t['inputSchema'] ?? array() ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $per_tool[ (string) ( $t['name'] ?? '?' ) ] = strlen( $one );
}
arsort( $per_tool );

$read_only = 0;
$as_write = 0;
$missing_hint = array();
foreach ( $tools as $t ) {
    if ( ! empty( $t['annotations']['readOnlyHint'] ) ) { $read_only++; continue; }
    $as_write++;
    if ( ! array_key_exists( 'readOnlyHint', (array) ( $t['annotations'] ?? array() ) ) ) {
        $missing_hint[] = (string) ( $t['name'] ?? '?' );
    }
}

echo "=== tools/list surface budget ===\n\n";
printf( "tools:                 %d\n", count( $tools ) );
printf( "surface bytes:         %d\n", $bytes );
printf( "approx tokens:         %d\n", $tokens );
printf( "mean bytes per tool:   %d\n", (int) round( $bytes / max( 1, count( $tools ) ) ) );
printf( "readOnlyHint true:     %d\n", $read_only );
printf( "treated as write:      %d\n", $as_write );

// A host that classifies unannotated tools as writes will route them through
// confirmation and safety checks; an omitted hint is therefore not neutral.
if ( $missing_hint ) {
    printf( "\nWARN %d tool(s) omit readOnlyHint entirely (hosts treat these as writes):\n", count( $missing_hint ) );
    foreach ( array_slice( $missing_hint, 0, 10 ) as $name ) { echo "  - {$name}\n"; }
}

echo "\n-- 15 largest tools --\n";
$shown = 0;
foreach ( $per_tool as $name => $size ) {
    printf( "  %-40s %6d bytes  (~%d tokens)\n", $name, $size, approx_tokens( $size ) );
    if ( ++$shown >= 15 ) { break; }
}

// The advertised surface -- what tools/list actually emits -- is the number that
// must obey the ceiling. The full catalogue above is measured for context only.
$advertised = PRSTUDIO_UC_MCP_V5::advertised_tools_for_test();
$advertised_payload = (string) json_encode(
    array_map(
        static function ( array $t ): array {
            return array( 'name' => $t['name'] ?? '', 'description' => $t['description'] ?? '', 'inputSchema' => $t['inputSchema'] ?? array() );
        },
        $advertised['tools']
    ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$advertised_tokens = approx_tokens( strlen( $advertised_payload ) );
$budget = PRSTUDIO_UC_MCP_V5::tools_list_budget_for_test();

echo "\n-- advertised surface (what tools/list emits) --\n";
printf( "advertised tools:      %d of %d\n", count( $advertised['tools'] ), count( $tools ) );
printf( "withheld:              %d (reachable via capability search + prstudio_execute)\n", $advertised['withheld'] );
printf( "advertised tokens:     %d\n", $advertised_tokens );
printf( "budget:                %d\n", $budget );

// Every essential tool must survive the trim, or the trim removed the very
// thing that makes the withheld tools reachable.
$advertised_names = array();
foreach ( $advertised['tools'] as $t ) { $advertised_names[ (string) ( $t['name'] ?? '' ) ] = true; }
$missing_essential = array();
foreach ( PRSTUDIO_UC_MCP_V5::essential_tools_for_test() as $name ) {
    if ( ! isset( $advertised_names[ $name ] ) ) { $missing_essential[] = $name; }
}

$failures = array();
if ( $advertised_tokens > $budget ) {
    $failures[] = sprintf(
        'tools/list emits ~%d tokens, over the %d budget by %d. A host that enforces this does not fail loudly: tools stay visible and become uncallable.',
        $advertised_tokens,
        $budget,
        $advertised_tokens - $budget
    );
}
if ( $missing_essential ) {
    $failures[] = 'essential tools were trimmed, so the withheld ones are unreachable: ' . implode( ', ', $missing_essential );
}
if ( ! $advertised['tools'] ) {
    $failures[] = 'tools/list emitted nothing';
}

echo "\n";
if ( $failures ) {
    foreach ( $failures as $f ) { fwrite( STDERR, "FAIL {$f}\n" ); }
    exit( 1 );
}
printf( "PASS tools/list stays within the %d-token surface budget with every essential tool advertised\n", $budget );
exit( 0 );
