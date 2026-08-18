<?php
/**
 * Agent operating-model constitution test.
 *
 * This test protects the ARCHITECTURE, not the runtime. It asserts that the
 * self-model PR STUDIO presents to a connected client stays truthful, stays
 * compiled from one source, and -- critically -- never grows an approval,
 * confirmation or blocking-verification concept. The anti-crash test is the
 * only blocking pre-mutation guardian in this suite; a prompt that tells a
 * model otherwise is a defect even when no code enforces it, because the model
 * will behave as if the gate exists.
 *
 * This file must never become a runtime guard. It checks content and
 * consistency at build time and has no effect on execution.
 *
 * Usage: php tests/php-agent-model-constitution.php
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );
define( 'PRSTUDIO_UC_VERSION', '1.0.0' );

$root = dirname( __DIR__);
$plugin = $root . '/prstudio-unified-control';

require_once $plugin . '/includes/class-prstudio-uc-agent-model.php';

$failures = array();
$passes = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
    global $failures, $passes;
    if ( $ok ) { $passes++; echo "PASS  {$label}\n"; return; }
    $failures[] = $label . ( '' !== $detail ? ' -- ' . $detail : '' );
    echo "FAIL  {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
}

$instructions = PRSTUDIO_UC_Agent_Model::instructions( 125 );
$lower = strtolower( $instructions );
$routing = PRSTUDIO_UC_Agent_Model::routing();
$constitution = PRSTUDIO_UC_Agent_Model::constitution();
$runtime = PRSTUDIO_UC_Agent_Model::runtime( 125 );

echo "=== PR STUDIO agent operating model ===\n\n";

// --- 1. The compiled contract exists and is actually compiled. -------------
check( 'instructions are non-empty', '' !== trim( $instructions ) );
check( 'instructions are compiled, not a stub', strlen( $instructions ) > 1500,
    'length=' . strlen( $instructions ) );

// --- 2. MCP handshake is wired to the canonical source. -------------------
$mcp = (string) file_get_contents( $plugin . '/includes/class-prstudio-uc-mcp-v5.php' );
check( 'MCP initialize returns instructions',
    (bool) preg_match( "/'initialize' === \\\$method/", $mcp ) && str_contains( $mcp, "'instructions' => self::operator_instructions()" ) );
check( 'MCP server/discover returns instructions',
    str_contains( $mcp, "'server/discover' === \$method" ) && substr_count( $mcp, "'instructions' => self::operator_instructions()" ) >= 2 );
check( 'MCP delegates to the canonical agent model',
    str_contains( $mcp, 'PRSTUDIO_UC_Agent_Model::instructions(' ),
    'operator_instructions() must compile from PRSTUDIO_UC_Agent_Model' );

// --- 3. The class is loadable in production (autoload registration). ------
$autoload = (string) file_get_contents( $plugin . '/includes/class-prstudio-uc-autoload.php' );
check( 'agent model is registered in the autoloader',
    str_contains( $autoload, "'PRSTUDIO_UC_Agent_Model' => 'includes/class-prstudio-uc-agent-model.php'" ) );

// --- 4. AGENTS.md and the runtime model do not diverge. -------------------
$agents = strtolower( (string) file_get_contents( $root . '/AGENTS.md' ) );
check( 'AGENTS.md declares anti-crash as the only mutation guard',
    str_contains( $agents, 'anti-crash is the only mutation guard' ) );
check( 'AGENTS.md declares verification is evidence, not authorization',
    str_contains( $agents, 'verification is evidence, never authorization' ) );
check( 'runtime instructions agree: anti-crash is the only guard',
    str_contains( $lower, 'anti-crash test is the only blocking pre-mutation guardian' ) );
check( 'runtime instructions agree: verification is not authorization',
    str_contains( $lower, 'not authorization' ) );
check( 'constitution flags match AGENTS.md laws',
    true === $constitution['anti_crash_is_only_mutation_guard']
    && true === $constitution['verification_is_evidence_not_authorization']
    && true === $constitution['executable_actions_execute']
    && true === $constitution['human_interaction_is_auth_challenge_only'] );

// --- 5. The runtime self-model uses real runtime data. -------------------
check( 'runtime self-model reports a reference date', ! empty( $runtime['reference_date_gmt'] ) );
check( 'runtime reference date is today (derived, not hardcoded)',
    ( $runtime['reference_date_gmt'] ?? '' ) === gmdate( 'Y-m-d' ) );
check( 'tool count is injected at runtime, not literal in source',
    str_contains( $instructions, '125 typed tools' ),
    'instructions must reflect the count passed in' );

$source = (string) file_get_contents( $plugin . '/includes/class-prstudio-uc-agent-model.php' );
check( 'capability count is not hardcoded in the source',
    ! preg_match( '/\b1[,.]?3\d\d\s+capabilit/i', $source ),
    'capability totals must come from PRSTUDIO_UC_Capability_Registry::counts()' );
check( 'capability count is read from the live registry',
    str_contains( $source, 'PRSTUDIO_UC_Capability_Registry::counts()' ) );
check( 'browser availability is not hardcoded',
    str_contains( $source, 'PRSTUDIO_UC_Store::list_devices()' )
    && ! preg_match( '/browser_agents_online.{0,20}=>\s*[1-9]/', $source ) );

// --- 6. The prompt stays compact; the registry is never serialized in it. --
check( 'instructions do not serialize the capability registry',
    strlen( $instructions ) < 6000,
    'length=' . strlen( $instructions ) . ' -- keep the handshake compact' );
check( 'instructions contain no JSON blob dump',
    ! str_contains( $instructions, '{"' ) && ! str_contains( $instructions, '":[' ) );

// --- 7. Discovery surfaces are described. --------------------------------
check( 'capability discovery is described', str_contains( $lower, 'prstudio_capability_search' ) );
check( 'capability schema lookup is described', str_contains( $lower, 'prstudio_capability_describe' ) );
check( 'prstudio_tool_manual is described', str_contains( $lower, 'prstudio_tool_manual' ) );
check( 'procedural skills are described', str_contains( $lower, 'procedural_skill_search' ) );
check( 'memory/skill reuse is described', str_contains( $lower, 'verified' ) && str_contains( $lower, 'reuse' ) );
check( 'routing table covers the unknown-operation case',
    isset( $routing['unknown operation'] ) );
check( 'routing table covers the known-capability-unknown-schema case',
    isset( $routing['known capability, unknown schema'] ) );
check( 'routing table covers prior-work reuse',
    isset( $routing['done something like this before'] ) );
check( 'routing table is a map of work kinds, not a capability listing',
    count( $routing ) < 30, 'count=' . count( $routing ) );

// --- 8. Observed failure modes are addressed. ---------------------------
check( 'instructions suppress redundant self-rediscovery',
    str_contains( $lower, 'you already have every capability' ) );
check( 'instructions forbid the self-analysis/auto-fix loop',
    str_contains( $lower, 'do not audit yourself' ) && str_contains( $lower, 'do not attempt to repair' ) );
check( 'instructions make the current date authoritative',
    str_contains( $lower, 'never search the web to establish the current date' ) );
check( 'instructions require cleanup of partial work',
    str_contains( $lower, 'orphaned' ) || str_contains( $lower, 'clean up after yourself' ) );

// --- 9. REGRESSION GUARD: no new approval concepts. ---------------------
// These phrases describe an authorization step gating a technically valid
// action. Anti-crash and external auth challenges are the only legitimate
// interruptions, and both are named explicitly rather than matched here.
$forbidden = array(
    'manual approval'                => '/manual approval/i',
    'approval required'              => '/approval\s+(is\s+)?required/i',
    'requires approval'              => '/requires\s+approval/i',
    'verification required before'   => '/verification\s+required\s+before/i',
    'must be verified before'        => '/must\s+be\s+verified\s+before\s+(commit|writ|mutat|appl)/i',
    'human confirmation required'    => '/human\s+confirmation/i',
    'user confirmation required'     => '/user\s+confirmation\s+(is\s+)?required/i',
    'await confirmation'             => '/await(ing)?\s+(user\s+|human\s+)?confirmation/i',
    'confidence threshold blocks'    => '/confidence\s+(threshold|score).{0,40}(block|gate|prevent|deny|refus)/i',
    'blocked pending review'         => '/pending\s+review/i',
    'operator must approve'          => '/operator\s+must\s+(approve|confirm|review)/i',
    'ask permission before writing'  => '/ask\s+(for\s+)?permission\s+before/i',
);
foreach ( $forbidden as $label => $pattern ) {
    check( "no new approval concept: {$label}", 0 === preg_match( $pattern, $instructions ),
        'the anti-crash test is the only blocking pre-mutation guardian' );
}

// The legitimate exceptions must still be present and correctly framed.
check( 'CAPTCHA/MFA/login exception is preserved',
    str_contains( $lower, 'captcha/mfa/login' ) && str_contains( $lower, 'inline' ) );
check( 'no approval/preview/risk/pacing gates are claimed',
    str_contains( $lower, 'no operator approval, preview, risk, pacing or destructive-action confirmation gates' ) );
check( 'degraded evidence is explicitly nonblocking',
    str_contains( $lower, 'nonblocking' ) || str_contains( $lower, 'without veto or rollback' ) );

// --- 10. This test is not a runtime guard. ------------------------------
check( 'agent model introduces no execution-blocking code',
    ! preg_match( '/\b(wp_die|exit\s*\(|WP_Error)\b/', preg_replace( '/^.*?final class/s', '', $source ) ),
    'PRSTUDIO_UC_Agent_Model must orient only, never block' );

echo "\n=== {$passes} passed, " . count( $failures ) . " failed ===\n";
if ( $failures ) {
    echo "\nFAILURES:\n";
    foreach ( $failures as $f ) { echo "  - {$f}\n"; }
    exit( 1 );
}
echo "PASS agent operating model is coherent and introduces no new mutation guard\n";
echo 'Compiled instruction size: ' . strlen( $instructions ) . " bytes\n";
exit( 0 );
