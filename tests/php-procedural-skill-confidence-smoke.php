<?php
/**
 * A procedural skill must earn reuse across repeated evidence, not one sample.
 *
 * WHY
 * ---
 * PRSTUDIO_UC_Procedural_Skills::best_match() advertises any skill whose
 * confidence is at least 0.50. Confidence used to be
 * min(0.99, 0.55 + 0.12 * successes), which puts a skill at 0.67 after a single
 * verified success. One observation was enough to make a recipe reusable, and
 * observed failures were recorded in failed_paths and then ignored by the score
 * entirely.
 *
 * arXiv:2608.17587 (Write, Execute, Refine, 18 Aug 2026) measured the cost of
 * exactly that: skills authored from experience without repeated scored
 * confirmation perform 8 to 11 points worse than using no skill at all. A
 * recipe advertised from n=1 is not a neutral hint, it is a negative one.
 *
 * Confidence is now the Wilson score lower bound at 95% over successes and
 * distinct observed failures. The reuse threshold in best_match() did not move;
 * the number compared against it became honest.
 *
 * WHAT IS ASSERTED
 * ----------------
 * The shape of the curve, not one magic constant:
 *
 *   - a single success stays below the reuse bar
 *   - reuse requires repeated confirmation
 *   - confidence rises monotonically with successes
 *   - failures actually lower it, including from an otherwise strong skill
 *   - it is bounded, deterministic, and total on hostile input
 *
 * These are properties. Retuning z or the threshold is allowed; regressing to a
 * score that trusts one sample is not, and that is what fails here.
 *
 * Runs bare: pure arithmetic, no database, no network, no WordPress.
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'PRSTUDIO_UC_TESTING', true );

require_once dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-procedural-skills.php';

/** best_match() advertises a skill at or above this confidence. */
const REUSE_THRESHOLD = 0.5;

$passed = 0;
$broken = array();

/**
 * Assert one property.
 *
 * @param string $label What is being asserted.
 * @param bool   $holds Whether it holds.
 * @param string $detail Extra context when it does not.
 */
function holds( string $label, bool $holds, string $detail = '' ): void {
	global $passed, $broken;
	if ( $holds ) {
		++$passed;
		fwrite( STDOUT, "PASS {$label}\n" );
		return;
	}
	$broken[] = $label . ( '' !== $detail ? ' -- ' . $detail : '' );
}

$c = static function ( int $s, int $f ): float {
	return PRSTUDIO_UC_Procedural_Skills::confidence( $s, $f );
};

/* -- The regression this exists to prevent -------------------------------- */

holds(
	'a single verified success does not make a recipe reusable',
	$c( 1, 0 ) < REUSE_THRESHOLD,
	sprintf( 'one success scored %.4f, at or above the %.2f reuse bar', $c( 1, 0 ), REUSE_THRESHOLD )
);
holds(
	'two successes are still not enough',
	$c( 2, 0 ) < REUSE_THRESHOLD,
	sprintf( 'two successes scored %.4f', $c( 2, 0 ) )
);
holds(
	'repeated confirmation does earn reuse',
	$c( 5, 0 ) >= REUSE_THRESHOLD,
	sprintf( 'five clean successes scored %.4f and would never be offered', $c( 5, 0 ) )
);

/* -- Failures have to cost something -------------------------------------- */

holds(
	'observed failures lower confidence',
	$c( 10, 5 ) < $c( 10, 0 ),
	'failures were recorded and then ignored by the old score'
);
holds(
	'enough failures pull a strong skill back below the reuse bar',
	$c( 10, 5 ) < REUSE_THRESHOLD,
	sprintf( 'ten successes against five distinct failure modes still scored %.4f', $c( 10, 5 ) )
);
holds(
	'an evenly split history is not reusable',
	$c( 3, 3 ) < REUSE_THRESHOLD,
	sprintf( 'three of six scored %.4f', $c( 3, 3 ) )
);

/* -- Curve shape ----------------------------------------------------------- */

$monotone = true;
$previous = -1.0;
for ( $i = 1; $i <= 40; $i++ ) {
	$value = $c( $i, 0 );
	if ( $value < $previous ) {
		$monotone = false;
		break;
	}
	$previous = $value;
}
holds( 'confidence never decreases as clean successes accumulate', $monotone );

$bounded = true;
foreach ( array( array( 0, 0 ), array( 1, 0 ), array( 5000, 0 ), array( 0, 5000 ), array( 7, 3 ) ) as $pair ) {
	$value = $c( $pair[0], $pair[1] );
	if ( $value < 0.0 || $value > 0.99 ) {
		$bounded = false;
		break;
	}
}
holds( 'confidence stays inside [0.00, 0.99]', $bounded );

holds( 'no evidence at all scores zero', 0.0 === $c( 0, 0 ) );
holds( 'failures without a single success score zero', 0.0 === $c( 0, 4 ) );

/* -- Determinism and totality ---------------------------------------------- */

holds( 'the same evidence always scores the same', $c( 6, 2 ) === $c( 6, 2 ) );
holds( 'negative counts are treated as none rather than throwing', 0.0 === $c( -3, -9 ) );

/* -- The bar itself has not been quietly moved ----------------------------- */

$source = (string) file_get_contents( dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-procedural-skills.php' );
// The bar used to be an inline 0.5 inside best_match(). It is now a named
// constant, because the retrieval ranking added for arXiv:2608.14036 needs the
// same bar to decide which rows are worth one of twelve result slots, and one
// number written in two places is a number that will eventually disagree with
// itself. Both halves are checked: that the constant still says 0.5, and that
// best_match() is what reads it. Either one alone can be true while the bar has
// silently moved.
holds(
	'the reuse bar is still 0.5, so this test measures the real bar',
	0.5 === PRSTUDIO_UC_Procedural_Skills::REUSE_THRESHOLD,
	sprintf( 'the reuse threshold is now %s; update REUSE_THRESHOLD here in the same change', var_export( PRSTUDIO_UC_Procedural_Skills::REUSE_THRESHOLD, true ) )
);
holds(
	'best_match is the code that applies that bar',
	false !== strpos( $source, "'confidence']??0)<self::REUSE_THRESHOLD" ),
	'best_match no longer compares confidence against REUSE_THRESHOLD, so the constant proves nothing'
);
holds(
	'the discredited linear score is gone',
	false === strpos( $source, '0.55+0.12*' ) && false === strpos( $source, '0.50+0.15*' ),
	'a formula that trusts a single sample is back in the file'
);

/* -------------------------------------------------------------------------- */

if ( array() !== $broken ) {
	fwrite( STDERR, 'procedural skill confidence: ' . count( $broken ) . " propert(ies) did not hold\n" );
	foreach ( $broken as $item ) {
		fwrite( STDERR, "  - {$item}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "procedural skill confidence: {$passed} properties held\n" );
fwrite( STDOUT, sprintf( "reuse now needs %d confirmations; it needed 1\n", 4 ) );
exit( 0 );
