<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Durable job facade: retry-safe creation, nonblocking evidence and deterministic recovery. */
final class PRSTUDIO_UC_Job_Engine {
	public const VERSION = '1.0.0';

	public static function create_browser_task( string $action, array $arguments, ?string $device_uuid = null, string $job_uuid = '' ): array {
		$explicit = PRSTUDIO_UC_Idempotency::explicit_key( $arguments );
		$scope = 'browser:' . sanitize_key( $action ) . ':' . (string) $device_uuid . ':' . $job_uuid;
		$idempotency = PRSTUDIO_UC_Idempotency::storage_key( $scope, $explicit );
		$plan_hash = PRSTUDIO_UC_Idempotency::plan_hash( $action, $arguments );
		return PRSTUDIO_UC_Store::create_task( $action, $arguments, $device_uuid, $idempotency, $plan_hash, $job_uuid );
	}

	private static function persistent_browser_payload( array $payload ): array {
		if ( ! class_exists( 'PRSTUDIO_UC_Memory' ) ) { return $payload; }
		$clean = PRSTUDIO_UC_Memory::redact( $payload );
		return is_array( $clean ) ? $clean : array();
	}

	private static function reconcile_browser_parent( array $task, bool $ok, array $payload ): bool {
		$job_uuid = (string) ( $task['job_uuid'] ?? '' );
		if ( '' === $job_uuid ) { return false; }
		$job = PRSTUDIO_UC_Store::get_job( $job_uuid );
		if ( ! $job || 'WAITING_FOR_BROWSER' !== (string) $job['status'] ) { return false; }
		$checkpoint = (array) ( $job['checkpoint'] ?? array() );
		$waiting = (string) ( $checkpoint['browser_task_id'] ?? $checkpoint['waiting_browser_task_id'] ?? '' );
		if ( '' !== $waiting && ! hash_equals( $waiting, (string) $task['task_uuid'] ) ) { return false; }
		$status = (string) ( $task['status'] ?? '' );
		$checkpoint['browser_task_id'] = (string) $task['task_uuid'];
		$checkpoint['browser_completed_gmt'] = gmdate( 'c' );
		$checkpoint['browser_terminal_status'] = $status;
		if ( $ok || PRSTUDIO_UC_State_Machine::COMPLETED === $status ) {
			$checkpoint['browser_result'] = self::persistent_browser_payload( $payload );
			$checkpoint['browser_error'] = null;
			return is_array( PRSTUDIO_UC_Store::set_job_state( $job_uuid, 'READY', array( 'checkpoint'=>$checkpoint, 'available_gmt'=>gmdate('Y-m-d H:i:s') ) ) );
		}
		if ( PRSTUDIO_UC_State_Machine::CANCELLED === $status ) {
			$error = $payload ?: array( 'code'=>'browser_task_cancelled', 'message'=>'Browser task cancelled.', 'retryable'=>false );
			$persistent_error = self::persistent_browser_payload( $error );
			$checkpoint['browser_error'] = $persistent_error;
			return is_array( PRSTUDIO_UC_Store::set_job_state( $job_uuid, 'CANCELLED', array( 'checkpoint'=>$checkpoint, 'error'=>$persistent_error, 'failure_class'=>'browser_cancelled' ) ) );
		}
		if ( PRSTUDIO_UC_State_Machine::EXPIRED === $status && empty( $payload ) ) {
			$payload = array( 'code'=>'browser_task_expired', 'message'=>'Browser task expired before completion.', 'retryable'=>true );
		}
		if ( empty( $payload ) ) {
			$payload = (array) ( $task['error'] ?? array( 'code'=>'browser_task_failed', 'message'=>'Browser task terminated without a result.', 'retryable'=>false ) );
		}
		$persistent_error = self::persistent_browser_payload( $payload );
		$checkpoint['browser_error'] = $persistent_error;
		if ( ! empty( $payload['retryable'] ) && (int) $job['attempts'] < (int) $job['max_attempts'] ) {
			return is_array( PRSTUDIO_UC_Store::set_job_state( $job_uuid, 'READY', array( 'checkpoint'=>$checkpoint, 'error'=>$persistent_error, 'failure_class'=>'browser_retryable', 'available_gmt'=>gmdate('Y-m-d H:i:s',time()+30) ) ) );
		}
		return is_array( PRSTUDIO_UC_Store::set_job_state( $job_uuid, 'TECHNICAL_ERROR', array( 'checkpoint'=>$checkpoint, 'error'=>$persistent_error, 'failure_class'=>'browser_terminal' ) ) );
	}

	private static function reconcile_terminal_browser_task( array $task ): bool {
		$status = (string) ( $task['status'] ?? '' );
		if ( PRSTUDIO_UC_State_Machine::COMPLETED === $status ) {
			return self::reconcile_browser_parent( $task, true, array( 'result'=>(array)($task['result']??array()), 'verification'=>(array)($task['verification']??array()) ) );
		}
		if ( PRSTUDIO_UC_State_Machine::FAILED === $status ) {
			return self::reconcile_browser_parent( $task, false, (array)($task['error']??array()) );
		}
		if ( PRSTUDIO_UC_State_Machine::CANCELLED === $status ) {
			return self::reconcile_browser_parent( $task, false, array( 'code'=>'browser_task_cancelled', 'message'=>'Browser task cancelled.', 'retryable'=>false ) );
		}
		if ( PRSTUDIO_UC_State_Machine::EXPIRED === $status ) {
			return self::reconcile_browser_parent( $task, false, array( 'code'=>'browser_task_expired', 'message'=>'Browser task expired before completion.', 'retryable'=>true ) );
		}
		return false;
	}

	public static function complete_browser_task( string $task_uuid, string $lease_token, array $result, string $device_uuid = '' ) {
		$task = PRSTUDIO_UC_Store::get_task( $task_uuid );
		if ( ! $task ) { return null; }
		if ( PRSTUDIO_UC_State_Machine::COMPLETED === (string) $task['status'] ) {
			if ( '' === $device_uuid || ! hash_equals( (string) ( $task['device_uuid'] ?? '' ), $device_uuid ) ) { return null; }
			self::reconcile_terminal_browser_task( $task );
			$task['idempotent_replay'] = true;
			return $task;
		}
		$verification = PRSTUDIO_UC_Verifier::browser_result( $task, $result );
		PRSTUDIO_UC_Store::set_verification( $task_uuid, $verification );
		if ( empty( $verification['ok'] ) ) {
			$result['verification']=$verification; $result['degraded']=true; $result['blocking']=false;
		}
		$completed=PRSTUDIO_UC_Store::complete( $task_uuid, $lease_token, $result );
		if(is_array($completed)){if(class_exists('PRSTUDIO_UC_Procedural_Skills')){try{PRSTUDIO_UC_Procedural_Skills::learn_verified_browser_task($task,$result,$verification);}catch(Throwable $ignored){}}self::reconcile_browser_parent($completed,true,array('result'=>$result,'verification'=>$verification));}
		return $completed;
	}

	public static function fail_browser_task( string $task_uuid, string $lease_token, array $error ) {
		$task=PRSTUDIO_UC_Store::get_task($task_uuid);if(is_array($task)&&class_exists('PRSTUDIO_UC_Procedural_Skills')){try{PRSTUDIO_UC_Procedural_Skills::observe_failure('browser',(string)($task['action']??''),(array)($task['arguments']??array()),$error);}catch(Throwable $ignored){}}
		$failed=PRSTUDIO_UC_Store::fail($task_uuid,$lease_token,$error);
		if(is_array($failed))self::reconcile_browser_parent($failed,false,$error);
		return $failed;
	}

	public static function cancel_browser_task( string $task_uuid, string $device_uuid = '', array $error = array() ) {
		$task = PRSTUDIO_UC_Store::get_task( $task_uuid );
		if ( ! $task ) { return null; }
		if ( PRSTUDIO_UC_State_Machine::CANCELLED === (string) $task['status'] ) {
			if ( '' !== $device_uuid && ! hash_equals( (string) ( $task['device_uuid'] ?? '' ), $device_uuid ) ) { return null; }
			self::reconcile_browser_parent( $task, false, $error ?: array( 'code'=>'browser_task_cancelled', 'message'=>'Browser task cancelled.', 'retryable'=>false ) );
			$task['idempotent_replay'] = true;
			return $task;
		}
		$cancelled = '' !== $device_uuid
			? PRSTUDIO_UC_Store::cancel_for_device( $task_uuid, $device_uuid )
			: PRSTUDIO_UC_Store::cancel( $task_uuid );
		if ( is_array( $cancelled ) ) {
			self::reconcile_browser_parent( $cancelled, false, $error ?: array( 'code'=>'browser_task_cancelled', 'message'=>'Browser task cancelled.', 'retryable'=>false ) );
		}
		return $cancelled;
	}

	public static function begin_workflow( string $objective, string $domain, array $arguments, array $plan ): array {
		$explicit = PRSTUDIO_UC_Idempotency::explicit_key( $arguments );
		if ( '' === $explicit && is_array( $arguments['arguments'] ?? null ) ) { $explicit = PRSTUDIO_UC_Idempotency::explicit_key( $arguments['arguments'] ); }
		$idempotency = PRSTUDIO_UC_Idempotency::storage_key( 'workflow:' . sanitize_key( $domain ), $explicit );
		$plan_hash = hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $plan ) );
		$job = PRSTUDIO_UC_Store::create_job( $objective, $domain, $arguments, $plan, $idempotency, $plan_hash );
		if ( class_exists('PRSTUDIO_UC_Memory') ) { PRSTUDIO_UC_Memory::movement('workflow.registered',['resource'=>$objective,'domain'=>$domain,'outcome'=>$job['status']??'created','method'=>'durable_job_engine'],(string)($job['job_uuid']??'')); }
		return $job;
	}

	public static function checkpoint_workflow( string $job_uuid, int $step_index, array $result ): ?array {
		return PRSTUDIO_UC_Store::checkpoint_job( $job_uuid, $step_index, $result );
	}

	public static function finish_workflow( string $job_uuid, array $result, bool $verified ): ?array {
		$verification = array(
			'ok' => $verified,
			'verifier' => 'prstudio_workflow_verifier',
			'version' => self::VERSION,
			'evidence_hash' => hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $result ) ),
			'verified_gmt' => gmdate( 'c' ),
		);
		if ( ! $verified ) { $result['degraded']=true; $result['blocking']=false; $result['verification']=$verification; }
		return PRSTUDIO_UC_Store::complete_job( $job_uuid, $result, $verification );
	}

	public static function fail_workflow( string $job_uuid, array $error ): ?array {
		return PRSTUDIO_UC_Store::fail_job( $job_uuid, $error, array( 'ok'=>false, 'verifier'=>'prstudio_workflow_verifier', 'version'=>self::VERSION ) );
	}

	public static function recover(): array {
		$tasks = PRSTUDIO_UC_Store::recover_stale_tasks();
		$jobs = PRSTUDIO_UC_Store::recover_stale_jobs();
		$parents = 0;
		foreach ( PRSTUDIO_UC_Store::terminal_browser_tasks_with_waiting_parents( 200 ) as $task ) {
			if ( is_array( $task ) && self::reconcile_terminal_browser_task( $task ) ) { $parents++; }
		}
		return array( 'ok'=>true, 'recovered_tasks'=>$tasks, 'interrupted_jobs'=>$jobs, 'reconciled_browser_parents'=>$parents, 'version'=>self::VERSION );
	}
}
