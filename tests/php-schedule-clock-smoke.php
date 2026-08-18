<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
function sanitize_key( $value ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-schedule-clock.php';

function schedule_check( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL {$message}\n" ); exit( 1 ); }
	fwrite( STDOUT, "PASS {$message}\n" );
}

$context = array(
	'schedule_mode' => 'daily_wall_clock',
	'schedule_timezone' => 'Europe/Rome',
	'schedule_local_time' => '03:30',
);
$utc = new DateTimeZone( 'UTC' );

$before_spring = new DateTimeImmutable( '2026-03-28 04:00:00', $utc );
$spring = PRSTUDIO_UC_Schedule_Clock::next_run( '2026-03-28 02:30:00', $context, 86400, $before_spring );
schedule_check( '2026-03-29 01:30:00' === $spring->format( 'Y-m-d H:i:s' ), 'spring DST keeps 03:30 Europe/Rome while UTC offset changes' );

$before_fall = new DateTimeImmutable( '2026-10-24 04:00:00', $utc );
$fall = PRSTUDIO_UC_Schedule_Clock::next_run( '2026-10-24 01:30:00', $context, 86400, $before_fall );
schedule_check( '2026-10-25 02:30:00' === $fall->format( 'Y-m-d H:i:s' ), 'autumn DST keeps 03:30 Europe/Rome while UTC offset changes' );

$after_outage = new DateTimeImmutable( '2026-04-08 12:00:00', $utc );
$next = PRSTUDIO_UC_Schedule_Clock::next_run( '2026-04-01 01:30:00', $context, 86400, $after_outage );
schedule_check( '2026-04-09 01:30:00' === $next->format( 'Y-m-d H:i:s' ), 'downtime skips missed backlog and selects one future occurrence' );

$same_a = PRSTUDIO_UC_Schedule_Clock::occurrence_key( 'schedule-abc', '2026-04-01 01:30:00' );
$same_b = PRSTUDIO_UC_Schedule_Clock::occurrence_key( 'schedule-abc', '2026-04-01 01:30:00' );
$other = PRSTUDIO_UC_Schedule_Clock::occurrence_key( 'schedule-abc', '2026-04-02 01:30:00' );
schedule_check( $same_a === $same_b && $same_a !== $other, 'occurrence keys are stable and unique per scheduled UTC occurrence' );

$interval = PRSTUDIO_UC_Schedule_Clock::next_run(
	'2026-04-01 00:00:00',
	array(),
	3600,
	new DateTimeImmutable( '2026-04-01 03:00:00', $utc )
);
schedule_check( '2026-04-01 03:01:00' === $interval->format( 'Y-m-d H:i:s' ), 'legacy interval schedules retain skip-ahead semantics' );

fwrite( STDOUT, "PASS schedule clock smoke complete\n" );
