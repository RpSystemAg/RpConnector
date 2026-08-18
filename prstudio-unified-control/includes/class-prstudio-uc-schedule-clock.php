<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic schedule calculations shared by SQL schedules and tests.
 *
 * Existing interval schedules keep their historical behaviour. Daily wall-clock
 * schedules intentionally calculate the next *future* local occurrence so a
 * long outage cannot create a catch-up storm and DST cannot drift the chosen
 * local hour.
 */
final class PRSTUDIO_UC_Schedule_Clock {
	public const MODE_DAILY_WALL_CLOCK = 'daily_wall_clock';

	public static function is_wall_clock( array $context ): bool {
		return self::MODE_DAILY_WALL_CLOCK === sanitize_key( (string) ( $context['schedule_mode'] ?? '' ) );
	}

	public static function next_run(
		string $expected_next_gmt,
		array $context,
		int $interval_seconds,
		?DateTimeImmutable $now_utc = null
	): DateTimeImmutable {
		$utc = new DateTimeZone( 'UTC' );
		$now_utc = ( $now_utc ?: new DateTimeImmutable( 'now', $utc ) )->setTimezone( $utc );

		if ( ! self::is_wall_clock( $context ) ) {
			$base = self::parse_utc( $expected_next_gmt, $now_utc );
			$next_timestamp = max(
				$now_utc->getTimestamp() + 60,
				$base->getTimestamp() + max( 300, $interval_seconds )
			);
			return ( new DateTimeImmutable( '@' . $next_timestamp ) )->setTimezone( $utc );
		}

		$timezone = self::timezone( (string) ( $context['schedule_timezone'] ?? 'Europe/Rome' ) );
		list( $hour, $minute, $second ) = self::local_time( (string) ( $context['schedule_local_time'] ?? '03:30' ) );
		$local_now = $now_utc->setTimezone( $timezone );
		$candidate = $local_now->setTime( $hour, $minute, $second );
		if ( $candidate->getTimestamp() <= $now_utc->getTimestamp() ) {
			$candidate = $candidate->modify( '+1 day' )->setTime( $hour, $minute, $second );
		}

		return $candidate->setTimezone( $utc );
	}

	public static function initial_run( array $context, int $interval_seconds, ?DateTimeImmutable $now_utc = null ): DateTimeImmutable {
		if ( self::is_wall_clock( $context ) ) {
			return self::next_run( '', $context, $interval_seconds, $now_utc );
		}
		$utc = new DateTimeZone( 'UTC' );
		$now_utc = ( $now_utc ?: new DateTimeImmutable( 'now', $utc ) )->setTimezone( $utc );
		return $now_utc->modify( '+60 seconds' );
	}

	public static function occurrence_key( string $schedule_uuid, string $expected_next_gmt ): string {
		$utc = new DateTimeZone( 'UTC' );
		$base = self::parse_utc( $expected_next_gmt, new DateTimeImmutable( 'now', $utc ) );
		return 'schedule:' . sanitize_text_field( $schedule_uuid ) . ':' . $base->format( 'YmdHis' );
	}

	private static function parse_utc( string $value, DateTimeImmutable $fallback ): DateTimeImmutable {
		$value = trim( $value );
		if ( '' === $value ) {
			return $fallback;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $parsed instanceof DateTimeImmutable ? $parsed : $fallback;
	}

	private static function timezone( string $name ): DateTimeZone {
		try {
			return new DateTimeZone( trim( $name ) ?: 'Europe/Rome' );
		} catch ( Exception $error ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/** @return array{0:int,1:int,2:int} */
	private static function local_time( string $value ): array {
		if ( ! preg_match( '/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', trim( $value ) ) ) {
			$value = '03:30';
		}
		$parts = array_map( 'intval', explode( ':', $value ) );
		return array( $parts[0], $parts[1], $parts[2] ?? 0 );
	}
}
