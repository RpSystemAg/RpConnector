<?php
/**
 * Turn PHP diagnostics into test failures.
 *
 * Loaded with `php -d auto_prepend_file=tests/strict-php-errors.php <suite>`, so
 * it applies to every standalone suite without editing a hundred files.
 *
 * Why this exists
 * ---------------
 * The suites run with PHP's defaults, where a notice or a deprecation is
 * printed and execution continues. A suite that emits "Undefined array key
 * 'status'" on every run and then asserts its way to the end still exits 0, and
 * CI reports it green. The diagnostic is the interesting part and it was being
 * discarded.
 *
 * That matters more here than in most codebases, because the defects this
 * project actually ships are the quiet ones. A query that never reaches MySQL,
 * a lease that is never recovered, an option write that loses a concurrent
 * update -- none of them throw. They emit a diagnostic, or nothing at all, and
 * the caller reads the empty result as "there was nothing to do".
 *
 * _doing_it_wrong
 * ---------------
 * WordPress reports API misuse through `_doing_it_wrong()`, and `wpdb::prepare()`
 * is one of its callers: pass the wrong number of arguments and it complains
 * there, returns an empty string, and the query silently never runs. That is
 * exactly how `recover_stale_tasks()` sat dead. The test shims do not define the
 * function, so the signal had nowhere to go. It is defined here, and it throws.
 *
 * Deliberately not caught
 * -----------------------
 * Errors suppressed with `@`. Several suites use it for genuinely optional
 * filesystem work (`@unlink` of a temp store on the failure path). Honouring the
 * suppression operator keeps this from turning cleanup into failure, and
 * `error_reporting()` returning 0 is how PHP signals it.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

set_error_handler(
	static function ( int $severity, string $message, string $file = '', int $line = 0 ): bool {
		// `@` sets error_reporting() to 0 (to E_ERROR|... on PHP 8). Respect it.
		if ( 0 === ( error_reporting() & $severity ) ) {
			return false;
		}

		$names = array(
			E_WARNING           => 'Warning',
			E_NOTICE            => 'Notice',
			E_USER_ERROR        => 'User error',
			E_USER_WARNING      => 'User warning',
			E_USER_NOTICE       => 'User notice',
			E_USER_DEPRECATED   => 'User deprecation',
			E_DEPRECATED        => 'Deprecation',
			E_RECOVERABLE_ERROR => 'Recoverable error',
		);
		$label = $names[ $severity ] ?? ( 'Diagnostic(' . $severity . ')' );

		fwrite(
			STDERR,
			"FAIL php diagnostic promoted to failure -- {$label}: {$message}\n"
			. "  at {$file}:{$line}\n"
			. "  A suite that prints diagnostics and still exits 0 is reported green.\n"
			. "  Fix the cause, or narrow the call with @ if the failure is genuinely optional.\n"
		);
		exit( 1 );
	}
);

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * WordPress's API-misuse channel.
	 *
	 * Real WordPress routes this to trigger_error() under WP_DEBUG. The shims do
	 * not define it at all, so every misuse signal was being dropped. Throwing
	 * is the right response in a test: `wpdb::prepare()` reaching here means the
	 * statement it was building will never be sent, and the caller is about to
	 * read that as "no rows matched".
	 *
	 * @param string $function_name Function that was misused.
	 * @param string $message       What was wrong.
	 * @param string $version       Version the notice was added.
	 */
	function _doing_it_wrong( $function_name, $message, $version = '' ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- deliberately shadowing the WordPress core function inside the test harness.
		fwrite(
			STDERR,
			"FAIL WordPress reported API misuse -- _doing_it_wrong({$function_name})\n"
			. "  {$message}\n"
			. "  This is not cosmetic: wpdb::prepare() returns '' after reporting here,\n"
			. "  so the statement is never sent and the caller sees an empty result.\n"
		);
		exit( 1 );
	}
}
