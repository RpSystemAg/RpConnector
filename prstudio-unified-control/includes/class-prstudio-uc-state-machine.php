<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) {
	exit;
}

final class PRSTUDIO_UC_State_Machine {
	public const QUEUED = 'queued';
	public const LEASED = 'leased';
	public const RUNNING = 'running';
	public const COMPLETED = 'completed';
	public const FAILED = 'failed';
	public const CANCELLED = 'cancelled';
	public const EXPIRED = 'expired';

	// PR STUDIO ONE-GUARD INVARIANT:
	// Anti-Crash is the only mutation guard. Verification/risk/telemetry must
	// never block an executable action. Human auth challenges remain RUNNING.
	private const TRANSITIONS = array(
		self::QUEUED         => array( self::LEASED, self::CANCELLED, self::EXPIRED ),
		self::LEASED         => array( self::RUNNING, self::QUEUED, self::FAILED, self::CANCELLED ),
		self::RUNNING        => array( self::COMPLETED, self::FAILED, self::CANCELLED ),
		self::COMPLETED      => array(),
		self::FAILED         => array(),
		self::CANCELLED      => array(),
		self::EXPIRED        => array(),
	);

	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	public static function assert_transition( string $from, string $to ): void {
		if ( ! self::can_transition( $from, $to ) ) {
			throw new LogicException( sprintf( 'Transizione non valida: %s -> %s', $from, $to ) );
		}
	}

	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED ), true );
	}

	public static function next_checkpoint( array $checkpoint, int $step_index, array $result = array() ): array {
		$checkpoint['last_completed_step'] = $step_index;
		$checkpoint['last_result'] = $result;
		$checkpoint['updated_at'] = time();
		return $checkpoint;
	}

	public static function recover_status( string $status, bool $lease_expired ): string {
		if ( ! $lease_expired ) {
			return $status;
		}
		if ( self::LEASED === $status ) {
			return self::QUEUED;
		}
		return $status;
	}
}
