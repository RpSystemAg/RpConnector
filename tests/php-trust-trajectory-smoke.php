<?php
/**
 * Ontological trust monitor: drift detection, determinism, and LAW 1.
 *
 * Covers PRSTUDIO_UC_Trust_Trajectory, which answers a question mission
 * verification does not: is the trajectory still the one the user authorized.
 * A mission can call the right handler with plausible arguments at every step
 * while sliding toward a broader role, an adjacent objective, or evidence
 * nobody supplied.
 *
 * Three properties matter more than the detections themselves:
 *
 *   Determinism  The output has to be replayable, or an operator cannot audit
 *                it. Asserted by evaluating twice and comparing encodings, so
 *                introducing a clock call or any nondeterminism fails here.
 *
 *   LAW 1        The monitor may never stop a mutation. `blocking` is asserted
 *                false on the drifting fixtures too, not only the clean one --
 *                a guard that only reveals itself under drift would pass a test
 *                that checked the happy path alone.
 *
 *   Monotonic    Trust does not recover. A compliant step taken after an
 *                uncompliant one does not re-authorize the mission.
 *
 * Runs bare: no database, no network, no environment.
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-trust-trajectory.php';

$passed = 0;
$broken = array();

/**
 * Assert one property.
 *
 * @param string $label  What is being asserted.
 * @param bool   $holds  Whether it holds.
 * @param string $detail Extra context when it does not.
 */
function assert_that( string $label, bool $holds, string $detail = '' ): void {
	global $passed, $broken;
	if ( $holds ) {
		++$passed;
		fwrite( STDOUT, "PASS {$label}\n" );
		return;
	}
	$broken[] = $label . ( '' !== $detail ? ' -- ' . $detail : '' );
}

/** A plan with one authorized non-browser step and one authorized browser step. */
$plan = array(
	'hash'  => str_repeat( 'a', 64 ),
	'steps' => array(
		array(
			'id'               => 'audit-content',
			'handler'          => 'content.action',
			'requires_browser' => false,
			'arguments'        => array( 'action' => 'content_audit' ),
		),
		array(
			'id'               => 'observe-page',
			'handler'          => 'browser.action',
			'requires_browser' => true,
			'arguments'        => array( 'action' => 'playwright_observation_bundle' ),
		),
	),
);

/* -- 1. A trajectory that stayed inside its authorization ------------------ */

$clean = PRSTUDIO_UC_Trust_Trajectory::evaluate(
	$plan,
	array(
		'results' => array(
			'audit-content' => array( 'result' => array( 'action' => 'content_audit', 'executed' => true ) ),
			'observe-page'  => array(
				'result'          => array( 'action' => 'playwright_observation_bundle' ),
				'browser_task_id' => 'task-9',
			),
		),
	)
);

assert_that( 'an authorized trajectory holds trust', true === $clean['ok'] );
assert_that( 'an authorized trajectory reports no drift', array() === $clean['drift'] );
assert_that( 'every declared step is evaluated', 2 === $clean['evaluated_steps'] );
assert_that( 'browser evidence is allowed on a step that requires the browser', 0 === count( $clean['drift'] ) );
assert_that( 'the monitor identifies itself for replay', 'rge_v1' === $clean['monitor'] );
assert_that( 'the plan hash is carried for replay', str_repeat( 'a', 64 ) === $clean['plan_hash'] );

/* -- 2. Determinism: the same inputs must replay identically --------------- */

$again = PRSTUDIO_UC_Trust_Trajectory::evaluate(
	$plan,
	array(
		'results' => array(
			'audit-content' => array( 'result' => array( 'action' => 'content_audit', 'executed' => true ) ),
			'observe-page'  => array(
				'result'          => array( 'action' => 'playwright_observation_bundle' ),
				'browser_task_id' => 'task-9',
			),
		),
	)
);

assert_that(
	'evaluation is a pure function, so the trajectory replays byte for byte',
	wp_json_encode_local( $clean ) === wp_json_encode_local( $again ),
	'a clock call or any other nondeterminism would break this'
);

/* -- 3. ROLE: something ran that the plan never declared ------------------- */

$role = PRSTUDIO_UC_Trust_Trajectory::evaluate(
	$plan,
	array(
		'results' => array(
			'audit-content'  => array( 'result' => array( 'action' => 'content_audit' ) ),
			'purge-database' => array( 'result' => array( 'action' => 'database_truncate' ) ),
		),
	)
);

assert_that( 'a step outside the plan breaks trust', false === $role['ok'] );
assert_that( 'the undeclared step is named', 'purge-database' === ( $role['drift'][0]['step'] ?? '' ) );
assert_that( 'it is attributed to the role dimension', 'role' === ( $role['drift'][0]['dimension'] ?? '' ) );
assert_that( 'an undeclared step yields one finding, not three invented ones', 1 === count( $role['drift'] ) );

/* -- 4. GOAL: the right step, a different objective ------------------------ */

$goal = PRSTUDIO_UC_Trust_Trajectory::evaluate(
	$plan,
	array(
		'results' => array(
			'audit-content' => array( 'result' => array( 'action' => 'content_delete_all' ) ),
		),
	)
);

assert_that( 'an action that differs from the declared one breaks trust', false === $goal['ok'] );
assert_that( 'it is attributed to the goal dimension', 'goal' === ( $goal['drift'][0]['dimension'] ?? '' ) );
assert_that( 'the declared action is recorded', 'content_audit' === ( $goal['drift'][0]['declared'] ?? '' ) );
assert_that( 'the observed action is recorded', 'content_delete_all' === ( $goal['drift'][0]['observed'] ?? '' ) );

/* -- 5. EVIDENCE: evidence the step never authorized ----------------------- */

$evidence = PRSTUDIO_UC_Trust_Trajectory::evaluate(
	$plan,
	array(
		'results' => array(
			'audit-content' => array(
				'result'          => array( 'action' => 'content_audit' ),
				'browser_task_id' => 'task-3',
			),
		),
	)
);

assert_that( 'browser evidence on a non-browser step breaks trust', false === $evidence['ok'] );
assert_that( 'it is attributed to the evidence dimension', 'evidence' === ( $evidence['drift'][0]['dimension'] ?? '' ) );

/* -- 6. LAW 1: the monitor never stops anything ---------------------------- */

foreach ( array( 'authorized' => $clean, 'role' => $role, 'goal' => $goal, 'evidence' => $evidence ) as $case => $report ) {
	assert_that( "the monitor stays non-blocking on the {$case} trajectory", false === $report['blocking'] );
}

/* -- 7. Trust is monotone across the prefix -------------------------------- */

$recovery = PRSTUDIO_UC_Trust_Trajectory::evaluate(
	$plan,
	array(
		'results' => array(
			'purge-database' => array( 'result' => array( 'action' => 'database_truncate' ) ),
			'audit-content'  => array( 'result' => array( 'action' => 'content_audit' ) ),
		),
	)
);

$last = $recovery['prefixes'][ count( $recovery['prefixes'] ) - 1 ] ?? array();
assert_that(
	'a compliant step after an uncompliant one does not restore trust',
	false === ( $last['held'] ?? true ),
	'trust must not recover: the mission was already outside its authorization'
);

/* -- 8. Degenerate inputs must not throw ----------------------------------- */

$empty = PRSTUDIO_UC_Trust_Trajectory::evaluate( array(), array() );
assert_that( 'an empty plan and checkpoint evaluate without throwing', true === $empty['ok'] );
assert_that( 'an empty trajectory evaluates zero steps', 0 === $empty['evaluated_steps'] );

$hostile = PRSTUDIO_UC_Trust_Trajectory::evaluate(
	array( 'steps' => array( 'not-an-array', array( 'id' => '' ) ) ),
	array( 'results' => array( 'ghost' => 'not-an-array' ) )
);
assert_that( 'malformed plan entries are ignored rather than fatal', is_array( $hostile['drift'] ) );
assert_that( 'a malformed result is still attributed to a dimension', 'role' === ( $hostile['drift'][0]['dimension'] ?? '' ) );

/* -------------------------------------------------------------------------- */

/**
 * Stable JSON encoding used to compare two evaluations.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_local( $value ): string {
	return (string) json_encode( $value );
}

if ( array() !== $broken ) {
	fwrite( STDERR, 'trust trajectory: ' . count( $broken ) . " assertion(s) did not hold\n" );
	foreach ( $broken as $item ) {
		fwrite( STDERR, "  - {$item}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "trust trajectory: {$passed} assertions held\n" );
exit( 0 );
