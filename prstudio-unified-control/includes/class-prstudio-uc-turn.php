<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Turn contract for PR STUDIO 16.0 — the Codex-style termination guarantee.
 *
 * The 15.x surface could hand a caller a bare task_id and let five independent
 * state machines (lane, job, browser task, sync window, correlation) decide what
 * happened next. A model that receives an identifier with no termination
 * semantics has exactly one strategy available: poll forever. That is the loop.
 *
 * 16.0 makes one promise instead: every tool call resolves to a terminal state,
 * or to an explicit continuation that carries its own deadline. There is no
 * third outcome. A continuation always answers three questions the caller would
 * otherwise have to guess:
 *
 *   - when may I ask again          -> poll_after_ms
 *   - when does waiting stop being  -> deadline_gmt
 *     reasonable
 *   - what exactly do I call        -> next_action { tool, arguments }
 *
 * Past the deadline a continuation is no longer a continuation: it resolves to
 * `technical_error` with the last evidence attached. Nothing here retries on its own and
 * nothing here resurrects work the caller is still waiting on.
 */
final class PRSTUDIO_UC_Turn {
    public const VERSION = '1.0.0';

    /** Terminal: the work finished and the effect was verified. */
    public const COMPLETED = 'completed';
    /** Terminal: a technical error prevented completion. */
    public const TECHNICAL_ERROR = 'technical_error';
    /** Terminal special category: the one permitted mutation veto. */
    public const ANTI_CRASH = 'anti_crash';
    /** Non-terminal, always carries deadline_gmt + poll_after_ms + next_action. */
    public const PENDING = 'pending';

    private const TERMINAL = array( self::COMPLETED, self::TECHNICAL_ERROR, self::ANTI_CRASH );

    /** Default wall-clock budget for one continuation chain. */
    private const DEFAULT_DEADLINE_SECONDS = 180;
    /** Never ask a model to come back faster than this; it just burns turns. */
    private const MIN_POLL_MS = 750;
    private const MAX_POLL_MS = 15000;

    public static function is_terminal( string $status ): bool {
        return in_array( $status, self::TERMINAL, true );
    }

    /**
     * Wall-clock deadline for a continuation chain. Stored on the first pending
     * response and echoed unchanged afterwards, so a chain cannot extend itself
     * one poll at a time.
     */
    public static function deadline( string $correlation_id, int $seconds = 0, string $continuation_scope = '' ): string {
        $seconds = $seconds > 0 ? min( 900, max( 15, $seconds ) ) : self::DEFAULT_DEADLINE_SECONDS;
        $identity = $correlation_id . '|' . $continuation_scope;
        $key = 'prstudio_deadline_' . substr( hash( 'sha256', $identity ), 0, 40 );
        $existing = self::cache_get( $key );
        if ( is_string( $existing ) && '' !== $existing ) { return $existing; }
        $deadline = gmdate( 'Y-m-d H:i:s', time() + $seconds );
        self::cache_set( $key, $deadline, $seconds + 120 );
        return $deadline;
    }

    public static function deadline_passed( string $deadline_gmt ): bool {
        if ( '' === $deadline_gmt ) { return false; }
        $timestamp = strtotime( $deadline_gmt . ' UTC' );
        return is_int( $timestamp ) && $timestamp > 0 && $timestamp <= time();
    }

    /**
     * Backoff that widens with the attempt count. A browser step that has not
     * settled after eight polls is not going to settle in another 750 ms, and
     * the extra calls only cost the caller context.
     */
    public static function poll_after_ms( int $attempt, int $floor_ms = 0 ): int {
        $attempt = max( 1, min( 40, $attempt ) );
        $base = $floor_ms > 0 ? $floor_ms : 1000;
        $value = (int) round( $base * pow( 1.45, $attempt - 1 ) );
        return max( self::MIN_POLL_MS, min( self::MAX_POLL_MS, $value ) );
    }

    /**
     * Terminal success. `evidence` is what makes the difference between "the
     * call returned" and "the effect happened"; it is carried verbatim so the
     * model can cite it instead of asserting it.
     */
    public static function completed( array $result, array $evidence = array(), array $extra = array() ): array {
        $turn = array(
            'status'   => self::COMPLETED,
            'terminal' => true,
            'result'   => $result,
        );
        if ( $evidence ) { $turn['evidence'] = $evidence; }
        return array_merge( $turn, $extra );
    }

    public static function technical_error( string $code, string $message, array $details = array(), bool $retryable = false ): array {
        return array(
            'status'   => self::TECHNICAL_ERROR,
            'terminal' => true,
            'error'    => array(
                'code'      => $code,
                'message'   => $message,
                'retryable' => $retryable,
                'details'   => $details,
            ),
        );
    }

    public static function anti_crash( string $code, string $message, array $details = array() ): array {
        return array(
            'status'   => self::ANTI_CRASH,
            'terminal' => true,
            'error'    => array(
                'code'      => $code,
                'message'   => $message,
                'retryable' => false,
                'details'   => $details,
            ),
        );
    }

    /**
     * The only non-terminal shape in the system. Refuses to be constructed
     * without a next_action: a continuation the caller cannot act on is exactly
     * the bare-task_id failure mode 16.0 exists to remove.
     */
    public static function pending( string $correlation_id, array $next_action, int $attempt = 1, array $partial = array(), int $deadline_seconds = 0 ): array {
        $tool = (string) ( $next_action['tool'] ?? '' );
        $arguments = is_array( $next_action['arguments'] ?? null ) ? $next_action['arguments'] : array();
        $scope_id = $tool . '|' . (string) ( $arguments['task_id'] ?? $arguments['job_id'] ?? '' );
        $deadline = self::deadline( $correlation_id, $deadline_seconds, $scope_id );
        if ( self::deadline_passed( $deadline ) ) {
            $settled = self::settled_continuation( $tool, $arguments );
            if ( is_array( $settled ) ) { return $settled; }
            return self::technical_error(
                'prstudio_turn_deadline_exceeded',
                'The operation did not reach a terminal state within its deadline; no further polling will help.',
                array( 'deadline_gmt' => $deadline, 'attempts' => $attempt, 'last_known' => $partial ),
                false
            );
        }
        if ( '' === $tool ) {
            return self::technical_error(
                'prstudio_turn_continuation_invalid',
                'Internal continuation was built without a callable next action.',
                array( 'partial' => $partial ),
                false
            );
        }
        $turn = array(
            'status'        => self::PENDING,
            'terminal'      => false,
            'poll_after_ms' => self::poll_after_ms( $attempt ),
            'deadline_gmt'  => $deadline,
            'attempt'       => $attempt,
            'next_action'   => array(
                'tool'      => $tool,
                'arguments' => $arguments,
            ),
        );
        if ( $partial ) { $turn['partial'] = $partial; }
        return $turn;
    }

    /**
     * Normalizes anything a legacy executor returns into the turn contract.
     *
     * The important branch is the last one: a payload that carries a job_id or
     * task_id but no status used to be returned as-is, which is precisely how a
     * caller ended up holding an identifier and no instructions. Here it becomes
     * a proper continuation pointed at the long-polling reader.
     */
    public static function normalize( $value, string $correlation_id, string $tool = '', int $attempt = 1 ): array {
        if ( is_wp_error( $value ) ) {
            $data = (array) $value->get_error_data();
            $status = (int) ( $data['status'] ?? 0 );
            $retryable = in_array( $status, array( 408, 409, 423, 429, 502, 503, 504 ), true );
            unset( $data['status'] );
            if ( str_starts_with( (string) $value->get_error_code(), 'prstudio_anti_crash_' ) ) {
                return self::anti_crash( (string) $value->get_error_code(), (string) $value->get_error_message(), $data );
            }
            return self::technical_error( (string) $value->get_error_code(), (string) $value->get_error_message(), $data, $retryable );
        }
        if ( ! is_array( $value ) ) {
            return self::completed( array( 'value' => $value ) );
        }

        // Already a 16.0 turn.
        if ( isset( $value['status'] ) && is_string( $value['status'] )
            && ( self::is_terminal( $value['status'] ) || self::PENDING === $value['status'] ) ) {
            if ( self::PENDING === $value['status'] && empty( $value['next_action'] ) ) {
                return self::pending( $correlation_id, self::reader_for( $value ), $attempt, $value );
            }
            return $value;
        }

        // Legacy 15.x control envelope.
        $outcome = is_array( $value['_control_outcome'] ?? null ) ? $value['_control_outcome'] : array();
        if ( $outcome ) {
            $legacy = (string) ( $outcome['status'] ?? '' );
            if ( 'completed' === $legacy ) {
                return self::completed( $value, array(
                    'executed' => (bool) ( $outcome['executed'] ?? false ),
                    'mutated'  => (bool) ( $outcome['mutated'] ?? false ),
                    'verified' => (bool) ( $outcome['verified'] ?? false ),
                ) );
            }
            if ( 'technical_error' === $legacy ) {
                return self::technical_error( 'prstudio_execution_technical_error', 'The operation could not technically execute.', $outcome, ! empty( $outcome['retryable'] ) );
            }
            if ( 'anti_crash' === $legacy ) {
                return self::anti_crash( 'prstudio_anti_crash_required', 'Anti-Crash prevented the mutation.', $outcome );
            }
        }

        // A durable identifier with no termination semantics: the 15.x loop.
        $reader = self::reader_for( $value );
        if ( $reader ) {
            $done = strtoupper( (string) ( $value['status'] ?? $value['state'] ?? '' ) );
            if ( in_array( $done, array( 'COMPLETED', 'DONE', 'SUCCEEDED' ), true ) ) {
                return self::completed( $value );
            }
            if ( in_array( $done, array( 'FAILED', 'TECHNICAL_ERROR', 'DEAD_LETTER', 'CANCELLED', 'EXPIRED' ), true ) ) {
                return self::technical_error( 'prstudio_job_' . strtolower( $done ), 'The durable job reached a terminal technical-error state.', $value, false );
            }
            return self::pending( $correlation_id, $reader, $attempt, $value );
        }

        return self::completed( $value );
    }


    /** Last-chance durable read before converting a continuation into timeout. */
    private static function settled_continuation( string $tool, array $arguments ): ?array {
        if ( ! class_exists( 'PRSTUDIO_UC_Store' ) ) { return null; }
        if ( 'browser_status' === $tool && ! empty( $arguments['task_id'] ) ) {
            $task = PRSTUDIO_UC_Store::get_task( (string) $arguments['task_id'] );
            if ( is_array( $task ) && class_exists( 'PRSTUDIO_UC_State_Machine' ) && PRSTUDIO_UC_State_Machine::is_terminal( (string) ( $task['status'] ?? '' ) ) ) {
                $status = (string) ( $task['status'] ?? '' );
                if ( PRSTUDIO_UC_State_Machine::COMPLETED === $status ) { return self::completed( $task, (array) ( $task['verification'] ?? array() ) ); }
                return self::technical_error( 'prstudio_browser_' . strtolower( $status ), 'The Browser task reached a terminal technical-error state.', $task, false );
            }
        }
        if ( 'prstudio_job_get' === $tool && ! empty( $arguments['job_id'] ) ) {
            $job = PRSTUDIO_UC_Store::get_job( (string) $arguments['job_id'] );
            if ( is_array( $job ) && PRSTUDIO_UC_Store::terminal_job_state( (string) ( $job['status'] ?? '' ) ) ) {
                $status = strtoupper( (string) ( $job['status'] ?? '' ) );
                if ( in_array( $status, array( 'COMPLETED', 'VERIFIED', 'MUTATED', 'STORED' ), true ) ) { return self::completed( $job, (array) ( $job['verification'] ?? array() ) ); }
                return self::technical_error( 'prstudio_job_' . strtolower( $status ), 'The durable job reached a terminal technical-error state.', $job, false );
            }
        }
        return null;
    }

    /** Builds the exact call that reads a durable identifier to completion. */
    private static function reader_for( array $value ): array {
        foreach ( array( 'job_id', 'job_uuid' ) as $key ) {
            if ( ! empty( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
                return array(
                    'tool'      => 'prstudio_job_get',
                    'arguments' => array( 'job_id' => (string) $value[ $key ], 'wait_seconds' => 5 ),
                );
            }
        }
        foreach ( array( 'task_id', 'task_uuid' ) as $key ) {
            if ( ! empty( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
                return array(
                    'tool'      => 'browser_status',
                    'arguments' => array( 'task_id' => (string) $value[ $key ], 'wait_seconds' => 5 ),
                );
            }
        }
        return array();
    }

    /* -------------------------------------------------------------------- */

    private static function cache_get( string $key ) {
        if ( function_exists( 'get_transient' ) ) {
            $value = get_transient( $key );
            if ( false !== $value ) { return $value; }
        }
        return null;
    }

    private static function cache_set( string $key, $value, int $ttl ): void {
        if ( function_exists( 'set_transient' ) ) {
            set_transient( $key, $value, max( 60, $ttl ) );
        }
    }
}
