<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Long-poll wait channel — the single largest load reduction in 16.0.
 *
 * The 15.x Browser Agent kept a tight poll loop against /tasks/next with an
 * idle cadence between 100 ms and 750 ms. Every one of those polls did real
 * work on the server: `touch_device()` issued an UPDATE, `claim_next()` ran
 * `recover_stale_tasks()` (another UPDATE), then opened a transaction, ran a
 * SELECT, an UPDATE and a COMMIT. Roughly five write queries per poll, at up to
 * ten polls a second, from a browser that was doing nothing at all — on the
 * order of half a million write queries a day, each one also parsing the
 * plugin's PHP. That, more than anything else, is what filled the database
 * within minutes and made the whole site feel heavy.
 *
 * The channel replaces it with one held request. While no work exists, the loop
 * consults a cheap generation counter in the object cache and touches no table
 * at all. The counter is bumped by whoever enqueues work, so a waiting agent is
 * released within one tick — dispatch latency stays in the low hundreds of
 * milliseconds while idle database traffic drops to nothing.
 *
 * Holding a request occupies a PHP worker, so this is bounded on purpose:
 * a hard ceiling on the hold, an early return the moment the client disconnects,
 * and a concurrency cap so a fleet of agents can never consume the whole pool.
 * If any of those limits is hit the response is a normal empty poll, and the
 * caller falls back to its old cadence. The channel is an optimisation that
 * degrades to correct behaviour, never a dependency.
 */
final class PRSTUDIO_UC_Wait_Channel {
    public const VERSION = '1.0.0';

    private const GENERATION_KEY = 'prstudio_uc_work_generation';
    private const GROUP = 'prstudio_uc_wait';
    private const WAITERS_KEY = 'prstudio_uc_wait_active';

    /** Hard ceiling on one held request. Kept below common 30 s proxy timeouts. */
    private const MAX_WAIT_SECONDS = 25;
    private const DEFAULT_WAIT_SECONDS = 20;
    /** Cross-worker wakeup tick. Filesystem token read only; no DB polling. */
    private const TICK_MS = 40;
    /** Maximum simultaneously held requests across all devices. */
    private const MAX_CONCURRENT_WAITERS = 4;
    /** Device rows are refreshed at most this often regardless of poll rate. */
    private const DEVICE_TOUCH_INTERVAL = 60;

    /* ------------------------------------------------------------------ */
    /* Work generation                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Bumped by every producer of agent work. A waiting request compares the
     * generation it started with against the current one; a change is the only
     * thing that justifies going to the database.
     */
    public static function signal( string $reason = 'work_enqueued' ): int {
        $next = self::generation() + 1;
        if ( function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( self::GENERATION_KEY, $next, self::GROUP, 3600 );
        }
        // Keep the persisted counter for diagnostics/compatibility.
        if ( function_exists( 'update_option' ) ) {
            update_option( self::GENERATION_KEY, $next, false );
        }
        // A WordPress object cache is process-local on many installs. A PHP
        // request already waiting in wait_for_work() can therefore keep seeing
        // its own stale cache until the full long-poll deadline. Publish a tiny
        // cross-worker filesystem token too: every PHP worker sees it at once,
        // without touching the database inside the wait loop.
        self::write_signal_token( $next, $reason );
        return $next;
    }

    public static function generation(): int {
        if ( function_exists( 'wp_cache_get' ) ) {
            $found = false;
            $value = wp_cache_get( self::GENERATION_KEY, self::GROUP, false, $found );
            if ( $found && is_numeric( $value ) ) { return (int) $value; }
        }
        $stored = function_exists( 'get_option' ) ? get_option( self::GENERATION_KEY, 0 ) : 0;
        return (int) $stored;
    }


    /** Cross-process wake token. Deliberately independent from WP object cache. */
    private static function signal_file(): string {
        $base = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : sys_get_temp_dir();
        return rtrim( $base, '/\\' ) . '/prstudio-unified-private/runtime/work-signal.txt';
    }

    private static function write_signal_token( int $generation, string $reason ): void {
        $path = self::signal_file();
        $dir = dirname( $path );
        if ( function_exists( 'wp_mkdir_p' ) ) { @wp_mkdir_p( $dir ); }
        elseif ( ! is_dir( $dir ) ) { @mkdir( $dir, 0700, true ); }
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { return; }
        $token = $generation . ':' . sprintf( '%.6f', microtime( true ) ) . ':' . substr( hash( 'sha256', $reason . '|' . mt_rand() ), 0, 12 );
        @file_put_contents( $path, $token, LOCK_EX );
    }

    private static function signal_token(): string {
        $path = self::signal_file();
        if ( ! is_file( $path ) ) { return 'g:' . self::generation(); }
        clearstatcache( true, $path );
        $value = @file_get_contents( $path );
        return false === $value ? 'g:' . self::generation() : trim( (string) $value );
    }

    /**
     * State-change wakeups are deliberately separate from work-enqueue wakeups.
     * A Browser Agent waiting for new work must not hit the tasks table every
     * time an unrelated durable job checkpoints. State waiters, on the other
     * hand, need immediate completion notification without polling SQL.
     */
    private static function state_signal_file(): string {
        $base = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : sys_get_temp_dir();
        return rtrim( $base, '/\\' ) . '/prstudio-unified-private/runtime/state-signal.txt';
    }

    public static function signal_state( string $reason = 'state_changed' ): void {
        $path = self::state_signal_file();
        $dir = dirname( $path );
        if ( function_exists( 'wp_mkdir_p' ) ) { @wp_mkdir_p( $dir ); }
        elseif ( ! is_dir( $dir ) ) { @mkdir( $dir, 0700, true ); }
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { return; }
        $token = sprintf( '%.6f', microtime( true ) ) . ':' . substr( hash( 'sha256', $reason . '|' . mt_rand() ), 0, 16 );
        @file_put_contents( $path, $token, LOCK_EX );
    }

    private static function state_token(): string {
        $path = self::state_signal_file();
        if ( ! is_file( $path ) ) { return 'state:0'; }
        clearstatcache( true, $path );
        $value = @file_get_contents( $path );
        return false === $value ? 'state:0' : trim( (string) $value );
    }

    /* ------------------------------------------------------------------ */
    /* Wait                                                                */
    /* ------------------------------------------------------------------ */

    public static function enabled(): bool {
        if ( defined( 'PRSTUDIO_UC_DISABLE_LONG_POLL' ) && PRSTUDIO_UC_DISABLE_LONG_POLL ) { return false; }
        $option = function_exists( 'get_option' ) ? get_option( 'prstudio_uc_long_poll', 'on' ) : 'on';
        return 'off' !== (string) $option;
    }

    /**
     * Holds the request until work exists for the device, the budget expires,
     * or the client goes away.
     *
     * @param callable $claim Returns a task array or null. Called only when the
     *                        generation moved, or once at entry and once at exit.
     * @return array{task: mixed, waited_ms: int, mode: string}
     */
    public static function wait_for_work( string $device_uuid, int $requested_seconds, callable $claim ): array {
        $started = microtime( true );

        // Take the wake-generation baseline before the entry claim. If a producer
        // enqueues between this read and claim_next(), the claim sees the task; if
        // it enqueues immediately after claim_next(), the changed token is still
        // visible to the wait loop. Reading the baseline after claim_next() loses
        // that wake and can add the full long-poll budget to an otherwise instant
        // Browser dispatch.
        $seen_signal = self::signal_token();

        // Entry check: never hold a request when work is already queued.
        $task = $claim();
        if ( null !== $task && false !== $task ) {
            return self::result( $task, $started, 'immediate' );
        }

        if ( ! self::enabled() || $requested_seconds <= 0 ) {
            return self::result( null, $started, 'disabled' );
        }
        if ( ! self::acquire_slot() ) {
            // Too many held requests already. Returning an empty poll makes the
            // caller fall back to its own cadence, which is correct behaviour.
            return self::result( null, $started, 'saturated' );
        }

        $budget = min( self::MAX_WAIT_SECONDS, max( 1, $requested_seconds ) );
        $deadline = microtime( true ) + $budget;

        // A disconnecting client must free the worker immediately rather than
        // burning the rest of the budget on a socket nobody is reading.
        if ( function_exists( 'ignore_user_abort' ) ) { ignore_user_abort( false ); }

        try {
            while ( microtime( true ) < $deadline ) {
                usleep( self::TICK_MS * 1000 );

                if ( connection_aborted() ) {
                    return self::result( null, $started, 'client_gone' );
                }
                $signal = self::signal_token();
                if ( hash_equals( $seen_signal, $signal ) ) { continue; }

                $seen_signal = $signal;
                $task = $claim();
                if ( null !== $task && false !== $task ) {
                    return self::result( $task, $started, 'signalled' );
                }
                // The generation moved but another device took the work. Keep
                // waiting on the remaining budget rather than returning empty.
            }
            // One final look before giving up: a producer may have bumped the
            // generation in the same tick the budget ran out.
            $task = $claim();
            return self::result( is_array( $task ) ? $task : null, $started, 'timeout' );
        } finally {
            self::release_slot();
        }
    }

    /**
     * Event-driven wait for any durable state transition.
     *
     * The probe is evaluated immediately, then only after a cross-worker state
     * signal (plus one final boundary probe). This replaces tight SQL polling
     * for job/task completion while preserving the same bounded synchronous
     * contract. `$done` decides whether the current probe value is terminal.
     *
     * @return array{value:mixed,waited_ms:int,mode:string}
     */
    public static function wait_until( int $requested_seconds, callable $probe, callable $done ): array {
        $started = microtime( true );

        // Baseline before the first probe closes the same lost-wakeup window as
        // wait_for_work(): a state transition between baseline and probe is
        // either observed by the probe or remains visible as a changed token.
        $seen_signal = self::state_token();
        $value = $probe();
        if ( $done( $value ) ) {
            return array( 'value'=>$value, 'waited_ms'=>(int) round( ( microtime( true ) - $started ) * 1000 ), 'mode'=>'immediate' );
        }

        if ( ! self::enabled() || $requested_seconds <= 0 ) {
            return array( 'value'=>$value, 'waited_ms'=>(int) round( ( microtime( true ) - $started ) * 1000 ), 'mode'=>'disabled' );
        }
        if ( ! self::acquire_slot() ) {
            return array( 'value'=>$value, 'waited_ms'=>(int) round( ( microtime( true ) - $started ) * 1000 ), 'mode'=>'saturated' );
        }

        $budget = min( self::MAX_WAIT_SECONDS, max( 1, $requested_seconds ) );
        $deadline = microtime( true ) + $budget;
        if ( function_exists( 'ignore_user_abort' ) ) { ignore_user_abort( false ); }

        try {
            while ( microtime( true ) < $deadline ) {
                usleep( self::TICK_MS * 1000 );
                if ( function_exists( 'connection_aborted' ) && connection_aborted() ) { break; }

                $signal = self::state_token();
                if ( hash_equals( $seen_signal, $signal ) ) { continue; }
                $seen_signal = $signal;

                $value = $probe();
                if ( $done( $value ) ) {
                    return array(
                        'value'     => $value,
                        'waited_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
                        'mode'      => 'signalled',
                    );
                }
            }

            $value = $probe();
            return array(
                'value'     => $value,
                'waited_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
                'mode'      => 'timeout',
            );
        } finally {
            self::release_slot();
        }
    }

    private static function result( $task, float $started, string $mode ): array {
        return array(
            'task'      => is_array( $task ) ? $task : null,
            'waited_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
            'mode'      => $mode,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Concurrency cap                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Claim one long-poll slot.
     *
     * The previous implementation read the counter, compared it, then wrote back
     * the incremented value. Two requests arriving together both read the same
     * number and both passed the check, so the cap could be exceeded under
     * exactly the concurrency it exists to bound -- and since a slot is held for
     * up to MAX_WAIT_SECONDS, an overshoot pins PHP workers for that whole time.
     *
     * wp_cache_incr() is a single atomic operation on a real backend (Redis and
     * Memcached both implement INCR server-side), so claim first and check the
     * value the increment returned: a caller that lands above the cap gives its
     * slot straight back. wp_cache_add() seeds the key without clobbering a
     * concurrent seed, because it only writes when the key is absent.
     *
     * Honest limitation: with no persistent object cache the counter is
     * per-process, so the cap bounds each PHP worker rather than the pool. That
     * is still worth enforcing -- it is the per-worker case that pins a process
     * -- but it is not the global guarantee the constant name suggests, so
     * status() reports which of the two is actually in force.
     */
    private static function acquire_slot(): bool {
        if ( ! function_exists( 'wp_cache_incr' ) || ! function_exists( 'wp_cache_add' ) ) { return true; }

        // Seed only when absent; a concurrent seed must not reset a live count.
        wp_cache_add( self::WAITERS_KEY, 0, self::GROUP, self::MAX_WAIT_SECONDS + 10 );

        $active = wp_cache_incr( self::WAITERS_KEY, 1, self::GROUP );
        if ( false === $active ) {
            // Backend does not support atomic increment. Refusing here would
            // disable long-poll entirely on that install; allowing it keeps the
            // channel working, and the per-request budget still bounds the hold.
            return true;
        }
        if ( (int) $active > self::MAX_CONCURRENT_WAITERS ) {
            self::release_slot();
            return false;
        }
        return true;
    }

    private static function release_slot(): void {
        if ( ! function_exists( 'wp_cache_decr' ) ) { return; }
        $remaining = wp_cache_decr( self::WAITERS_KEY, 1, self::GROUP );
        // Backends clamp at zero rather than going negative, but a decrement that
        // races a key expiry can still report below zero; reseed so the next
        // acquire starts from a sane floor instead of inheriting a negative count.
        if ( false !== $remaining && (int) $remaining < 0 && function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( self::WAITERS_KEY, 0, self::GROUP, self::MAX_WAIT_SECONDS + 10 );
        }
    }

    /** Whether the waiter cap is pool-wide or only per PHP worker. */
    private static function slot_scope(): string {
        if ( ! function_exists( 'wp_cache_incr' ) ) { return 'unbounded_no_object_cache'; }
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
            ? 'global_persistent_object_cache'
            : 'per_php_worker';
    }

    /* ------------------------------------------------------------------ */
    /* Device presence                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Coalesced device heartbeat.
     *
     * `last_seen_gmt` exists to answer "is this agent alive", which needs
     * minute resolution, not millisecond. Writing it on every poll bought
     * nothing and cost an UPDATE each time.
     */
    public static function should_touch_device( string $device_uuid ): bool {
        $key = 'touch_' . substr( hash( 'sha256', $device_uuid ), 0, 24 );
        if ( function_exists( 'wp_cache_get' ) ) {
            $found = false;
            $last = wp_cache_get( $key, self::GROUP, false, $found );
            if ( $found && is_numeric( $last ) && ( time() - (int) $last ) < self::DEVICE_TOUCH_INTERVAL ) {
                return false;
            }
            wp_cache_set( $key, time(), self::GROUP, self::DEVICE_TOUCH_INTERVAL * 2 );
            return true;
        }
        // No object cache: fall back to a transient so the coalescing still
        // holds, just with an option write instead of a devices-table write.
        if ( function_exists( 'get_transient' ) ) {
            if ( false !== get_transient( 'prstudio_' . $key ) ) { return false; }
            set_transient( 'prstudio_' . $key, time(), self::DEVICE_TOUCH_INTERVAL );
        }
        return true;
    }

    public static function describe(): array {
        return array(
            'enabled'              => self::enabled(),
            'max_wait_seconds'     => self::MAX_WAIT_SECONDS,
            'default_wait_seconds' => self::DEFAULT_WAIT_SECONDS,
            'tick_ms'              => self::TICK_MS,
            'max_concurrent'       => self::MAX_CONCURRENT_WAITERS,
            'device_touch_interval_seconds' => self::DEVICE_TOUCH_INTERVAL,
            'generation'           => self::generation(),
            'wakeup_transport'      => 'cross_worker_filesystem_token',
            'state_wakeup_transport'=> 'cross_worker_filesystem_token',
            'fallback'             => 'When disabled or saturated the endpoint answers immediately and the agent uses its own cadence.',
        );
    }

    public static function default_wait_seconds(): int { return self::DEFAULT_WAIT_SECONDS; }
}
