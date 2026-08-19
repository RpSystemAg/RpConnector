<?php
/**
 * Ontological trust: does the trajectory still match what the user authorized?
 *
 * WHY
 * ---
 * Mission verification answers "did the requested effects happen". It does not
 * answer a second, independent question: is the mission still doing the job it
 * was authorized to do. An agent can call the right handler with plausible
 * arguments at every single step while the trajectory as a whole slides toward
 * a broader role, an adjacent objective, or evidence nobody supplied. Every
 * local check passes and the drift is invisible, because nothing compares the
 * accumulated prefix against the authorization.
 *
 * The approach follows arXiv:2608.17718 (Beyond Suspicious Steps: Ontological
 * Trust in Long-Horizon Agents, 18 Aug 2026), which decomposes trust along
 * Role, Goal and Evidence and evaluates it per trajectory prefix rather than
 * once at the end.
 *
 * WHAT THIS IS NOT
 * ----------------
 * It is not a guard. LAW 1 says the anti-crash gate is the only thing that may
 * stop a technically valid mutation, and this class cannot stop anything: it
 * takes a finished checkpoint and returns a report. `blocking` is a literal
 * false and there is no code path that sets it otherwise. Under LAW 2 the
 * output is evidence, never authorization.
 *
 * DETERMINISM IS A REQUIREMENT, NOT A PREFERENCE
 * ----------------------------------------------
 * The value of a trust trajectory is that an operator can replay it and get the
 * same answer. So `evaluate()` is a pure function of its two arguments: no
 * clock, no randomness, no global state, no I/O. The same plan and checkpoint
 * always produce a byte-identical result. The paper makes the same choice --
 * the model is used only to build representations, while the trust updates and
 * projections stay deterministic -- and it is what separates an auditable
 * trajectory from a judge's opinion. tests/php-trust-trajectory-smoke.php
 * asserts this by evaluating twice and comparing encodings.
 *
 * The three dimensions, grounded in the structures this plugin already builds:
 *
 *   ROLE     The plan declares its steps. A result recorded for a step the plan
 *            never declared means something executed outside the authorized
 *            role, whatever its individual arguments looked like.
 *
 *   GOAL     Each step declares the action it will perform. A result whose
 *            recorded action differs from the declared one is the "right tool,
 *            adjacent objective" case: locally valid, not what was approved.
 *
 *   EVIDENCE A step that does not require the browser has no business carrying
 *            browser evidence. Evidence appearing from a source the step never
 *            authorized is evidence the user never supplied.
 */

defined( 'ABSPATH' ) || exit;

final class PRSTUDIO_UC_Trust_Trajectory {

	public const MONITOR = 'rge_v1';

	public const HELD    = 'held';
	public const DRIFTED = 'drifted';

	/**
	 * Evaluate the trust trajectory of a mission.
	 *
	 * Pure: same inputs, same output, every time. See the class docblock.
	 *
	 * @param array $plan       Plan under execution: hash plus declared steps.
	 * @param array $checkpoint Accumulated execution state, including results.
	 * @return array Replayable trust report. Never blocking.
	 */
	public static function evaluate( array $plan, array $checkpoint ): array {
		$declared = self::declared_steps( $plan );
		$results  = is_array( $checkpoint['results'] ?? null ) ? $checkpoint['results'] : array();

		$prefixes = array();
		$drift    = array();
		$index    = 0;

		foreach ( $results as $step_id => $step_result ) {
			$step_id  = (string) $step_id;
			$findings = self::findings_for( $step_id, $step_result, $declared );

			$dimensions = array(
				'role'     => self::HELD,
				'goal'     => self::HELD,
				'evidence' => self::HELD,
			);
			foreach ( $findings as $finding ) {
				$dimensions[ $finding['dimension'] ] = self::DRIFTED;
				$drift[]                             = $finding;
			}

			$prefixes[] = array(
				'step_index' => $index,
				'step_id'    => $step_id,
				'role'       => $dimensions['role'],
				'goal'       => $dimensions['goal'],
				'evidence'   => $dimensions['evidence'],
				// Trust is monotone across the prefix: once a dimension has
				// drifted it stays drifted for every later prefix. A mission
				// does not become re-authorized by taking a compliant step
				// after an uncompliant one.
				'held'       => 0 === count( $drift ),
			);
			++$index;
		}

		return array(
			'monitor'         => self::MONITOR,
			'plan_hash'       => (string) ( $plan['hash'] ?? '' ),
			'declared_steps'  => array_keys( $declared ),
			'evaluated_steps' => $index,
			'ok'              => 0 === count( $drift ),
			'drift'           => $drift,
			'prefixes'        => $prefixes,
			// LAW 1. This monitor observes; it never stops a mutation. The
			// value is a literal because nothing may ever compute it.
			'blocking'        => false,
		);
	}

	/**
	 * Index the plan's declared steps by id.
	 *
	 * @param array $plan Plan under execution.
	 * @return array<string,array> Declared step definitions keyed by step id.
	 */
	private static function declared_steps( array $plan ): array {
		$declared = array();
		foreach ( (array) ( $plan['steps'] ?? array() ) as $position => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$id = (string) ( $step['id'] ?? $position );
			if ( '' === $id ) {
				continue;
			}
			$declared[ $id ] = $step;
		}
		return $declared;
	}

	/**
	 * Findings for one executed step, across the three dimensions.
	 *
	 * @param string $step_id     Identifier the result was recorded under.
	 * @param mixed  $step_result Recorded result for that step.
	 * @param array  $declared    Declared steps keyed by id.
	 * @return array<int,array> Findings, empty when the step held on all three.
	 */
	private static function findings_for( string $step_id, $step_result, array $declared ): array {
		$findings = array();

		if ( ! array_key_exists( $step_id, $declared ) ) {
			$findings[] = array(
				'step'      => $step_id,
				'dimension' => 'role',
				'reason'    => 'result recorded for a step the plan never declared',
			);
			// Nothing further is checkable: there is no declaration to compare
			// the action or the evidence against. Reporting invented goal and
			// evidence findings here would inflate the count without adding
			// information.
			return $findings;
		}

		$step   = $declared[ $step_id ];
		$result = is_array( $step_result ) ? $step_result : array();

		$declared_action = self::action_of( is_array( $step['arguments'] ?? null ) ? $step['arguments'] : array() );
		$observed_action = self::observed_action( $result );
		if ( '' !== $declared_action && '' !== $observed_action && $declared_action !== $observed_action ) {
			$findings[] = array(
				'step'      => $step_id,
				'dimension' => 'goal',
				'reason'    => 'executed action differs from the declared action',
				'declared'  => $declared_action,
				'observed'  => $observed_action,
			);
		}

		if ( empty( $step['requires_browser'] ) && self::carries_browser_evidence( $result ) ) {
			$findings[] = array(
				'step'      => $step_id,
				'dimension' => 'evidence',
				'reason'    => 'browser evidence on a step that does not require the browser',
			);
		}

		return $findings;
	}

	/**
	 * Extract the action name from an arguments payload.
	 *
	 * @param array $arguments Step arguments.
	 * @return string Action name, or '' when none is declared.
	 */
	private static function action_of( array $arguments ): string {
		$action = $arguments['action'] ?? '';
		return is_string( $action ) ? $action : '';
	}

	/**
	 * Extract the action a result says it performed.
	 *
	 * @param array $result Recorded step result.
	 * @return string Action name, or '' when the result does not record one.
	 */
	private static function observed_action( array $result ): string {
		foreach ( array( $result, is_array( $result['result'] ?? null ) ? $result['result'] : array() ) as $level ) {
			$action = $level['action'] ?? '';
			if ( is_string( $action ) && '' !== $action ) {
				return $action;
			}
		}
		return '';
	}

	/**
	 * Whether a result carries browser-sourced evidence.
	 *
	 * @param array $result Recorded step result.
	 * @return bool
	 */
	private static function carries_browser_evidence( array $result ): bool {
		if ( isset( $result['browser_task_id'] ) && '' !== (string) $result['browser_task_id'] ) {
			return true;
		}
		$inner = is_array( $result['result'] ?? null ) ? $result['result'] : array();
		if ( isset( $inner['browser_task_id'] ) && '' !== (string) $inner['browser_task_id'] ) {
			return true;
		}
		$evidence = is_array( $result['verification'] ?? null ) ? $result['verification'] : array();
		$source   = $evidence['source'] ?? '';
		return is_string( $source ) && 'browser' === $source;
	}
}
