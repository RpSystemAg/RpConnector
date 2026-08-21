<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

final class PRSTUDIO_UC_Execution_Gateway {
    private const ARBITRARY_SCRIPT_CAPABILITIES = array( 'legacy.browser.frontend-manage.playwright-evaluate' );

    private static function error( string $code, string $message, int $status = 400, array $details = array(), bool $retryable = false ): WP_Error {
        return new WP_Error( $code, $message, array( 'status'=>$status, 'retryable'=>$retryable, 'details'=>$details ) );
    }

    private static function invoke( array $cap, array $args ) {
        $executor = (string) ( $cap['executor'] ?? '' );
        if ( ! str_contains( $executor, '::' ) ) {
            return self::error( 'capability_executor_invalid', 'Capability executor is invalid.', 500, array( 'capability'=>$cap['id'] ?? '' ) );
        }
        [ $class, $method ] = explode( '::', $executor, 2 );
        if ( ! class_exists( $class ) || ! is_callable( array( $class, $method ) ) ) {
            return self::error( 'capability_executor_missing', 'Capability executor is unavailable.', 500, array( 'capability'=>$cap['id'] ?? '', 'executor'=>$executor ) );
        }
        try {
            $rm = new ReflectionMethod( $class, $method );
            return $rm->getNumberOfParameters() >= 2 ? $class::$method( $args, $cap ) : $class::$method( $args );
        } catch ( Throwable $e ) {
            return self::error( 'capability_execution_exception', 'Capability execution failed safely.', 500, array( 'capability'=>$cap['id'] ?? '', 'exception_class'=>get_class( $e ) ), true );
        }
    }

    private static function terminal( array $job ): bool {
        return PRSTUDIO_UC_Store::terminal_job_state( (string) ( $job['status'] ?? '' ) );
    }

    private static function terminal_job_ok( array $job ): bool {
        return 'COMPLETED' === strtoupper( (string) ( $job['status'] ?? '' ) );
    }

    private static function structured_failure( $result ): ?array {
        if ( ! is_array( $result ) ) { return null; }
        $status = strtolower( (string) ( $result['status'] ?? $result['task']['status'] ?? '' ) );
        $failed_status = in_array( $status, array( 'failed', 'error', 'technical_error', 'cancelled', 'expired', 'dead_letter' ), true );
        $explicit_false = array_key_exists( 'ok', $result ) && false === $result['ok'];
        $not_executed = array_key_exists( 'executed', $result ) && false === $result['executed'];
        $error = is_array( $result['error'] ?? null ) ? $result['error'] : array();
        if ( ! $failed_status && ! $explicit_false && ! $not_executed && empty( $error ) ) { return null; }
        return array(
            'code' => (string) ( $error['code'] ?? ( $failed_status ? 'executor_reported_failure' : ( $not_executed ? 'execution_not_observed' : 'executor_result_not_ok' ) ) ),
            'message' => (string) ( $error['message'] ?? 'Executor returned a non-success outcome.' ),
            'status' => $status,
        );
    }

    private static function mission_event( string $mission_id, string $event, array $payload = array() ): void {
        if ( '' === trim( $mission_id ) || ! class_exists( 'PRSTUDIO_UC_Memory' ) ) { return; }
        try {
            $state = PRSTUDIO_UC_Memory::mission( $mission_id );
            if ( ! is_array( $state ) ) { $state = array(); }
            $state['mission_id'] = $mission_id;
            $state['status'] = (string) ( $payload['status'] ?? $state['status'] ?? 'running' );
            $state['resume_point'] = (string) ( $payload['resume_point'] ?? $state['resume_point'] ?? '' );
            $state['last_event'] = $event;
            $state['last_job_id'] = (string) ( $payload['job_id'] ?? $state['last_job_id'] ?? '' );
            $state['completed_steps'] = array_values( array_unique( array_filter( array_merge( (array) ( $state['completed_steps'] ?? array() ), (array) ( $payload['completed_steps'] ?? array() ) ) ) ) );
            $state['failed_paths'] = array_slice( array_values( array_merge( (array) ( $state['failed_paths'] ?? array() ), (array) ( $payload['failed_paths'] ?? array() ) ) ), -50 );
            $state['evidence'] = array_slice( array_values( array_merge( (array) ( $state['evidence'] ?? array() ), (array) ( $payload['evidence'] ?? array() ) ) ), -100 );
            $state['history'] = array_slice( array_values( array_merge( (array) ( $state['history'] ?? array() ), array( array( 'event'=>$event, 'job_id'=>(string) ( $payload['job_id'] ?? '' ), 'capability'=>(string) ( $payload['capability'] ?? '' ), 'gmt'=>gmdate( 'c' ) ) ) ) ), -100 );
            PRSTUDIO_UC_Memory::mission( $mission_id, $state );
        } catch ( Throwable $ignored ) {}
    }

    private static function qcount(): int {
        global $wpdb;
        return isset( $wpdb ) && is_object( $wpdb ) ? (int) ( $wpdb->num_queries ?? 0 ) : 0;
    }

    private static function sql_time_from( int $saved_index ): ?float {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! is_array( $wpdb->queries ?? null ) ) { return null; }
        $rows = array_slice( $wpdb->queries, $saved_index );
        $seconds = 0.0;
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && isset( $row[1] ) && is_numeric( $row[1] ) ) { $seconds += (float) $row[1]; }
        }
        return round( $seconds * 1000, 3 );
    }

    private static function saved_query_count(): int {
        global $wpdb;
        return isset( $wpdb ) && is_object( $wpdb ) && is_array( $wpdb->queries ?? null ) ? count( $wpdb->queries ) : 0;
    }

    private static function already_verified( $result ): bool {
        if ( ! is_array( $result ) || null !== self::structured_failure( $result ) ) { return false; }
        if ( array_key_exists( 'verified', $result ) ) { return true === $result['verified']; }
        if ( array_key_exists( 'db_verified', $result ) ) { return true === $result['db_verified']; }
        $control = is_array( $result['_control_outcome'] ?? null ) ? $result['_control_outcome'] : array();
        if ( array_key_exists( 'executed', $control ) && empty( $control['executed'] ) ) { return false; }
        if ( array_key_exists( 'verified', $control ) ) { return true === $control['verified']; }
        if ( isset( $result['after'] ) && ! is_wp_error( $result['after'] ) ) { return true; }
        if ( array_key_exists( 'affected_rows', $result ) || array_key_exists( 'insert_id', $result ) || array_key_exists( 'deleted', $result ) ) { return true; }
        return false;
    }

    private static function minimal_verify( array $cap, $result ): array {
        $failure = self::structured_failure( $result );
        if ( null !== $failure ) {
            return array( 'ok'=>false, 'verifier'=>'executor_failure', 'strategy'=>'executor_result', 'warning'=>'Executor returned a failure/non-execution outcome.', 'failure'=>$failure );
        }
        if ( ! empty( $cap['read_only'] ) ) { return array( 'ok'=>true, 'verifier'=>'read_result', 'strategy'=>'none' ); }
        $ok = self::already_verified( $result );
        return array( 'ok'=>$ok, 'verifier'=>$ok ? 'executor_readback' : 'executor_result', 'strategy'=>(string) ( $cap['minimal_verification'] ?? 'executor_result' ), 'warning'=>$ok ? '' : 'Executor completed but did not expose a local read-back marker.' );
    }

    private static function execute_inline_cap( array $cap, array $args, array $request, array $lane, float $started ) {
        $cap_id = (string) $cap['id'];
        $is_write = empty( $cap['read_only'] );
        $dry = ! empty( $request['dry_run'] );
        if ( $dry && $is_write ) {
            return array( 'ok'=>true, 'status'=>'completed', 'terminal'=>true, 'fast_path'=>true, 'dry_run'=>true, 'result'=>array( 'would_execute'=>$cap_id, 'execution_contract'=>array_intersect_key( $cap, array_flip( array( 'execution_class', 'preferred_executor', 'estimated_work', 'minimal_verification' ) ) ) ) );
        }
        $q0 = self::qcount();
        $sq0 = self::saved_query_count();
        $invoke_started = microtime( true );
        $invoke_args = $args;
        $invoke_args['_client_id'] = (string) ( $request['_owner_client_id'] ?? '' );
        if ( ! empty( $request['lane_token'] ) ) { $invoke_args['lane_token'] = (string) $request['lane_token']; }
        if ( isset( $request['work_id'] ) ) { $invoke_args['work_id'] = $request['work_id']; }
        if ( $is_write && class_exists( 'PRSTUDIO_UC_Pre_Mutation_Safety' ) ) {
            $scope = PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_capability( $cap );
            if ( 'deferred' !== $scope ) {
                $gate = PRSTUDIO_UC_Pre_Mutation_Safety::before_commit( $scope, $cap_id, $invoke_args );
                if ( is_wp_error( $gate ) ) { return $gate; }
            }
        }
        $result = self::invoke( $cap, $invoke_args );
        $invoke_ms = round( ( microtime( true ) - $invoke_started ) * 1000, 3 );
        if ( is_wp_error( $result ) ) { return $result; }
        $verification = self::minimal_verify( $cap, $result );
        $total_ms = round( ( microtime( true ) - $started ) * 1000, 3 );
        $sql_ms = self::sql_time_from( $sq0 );
        $receipt = array( 'route'=>'fast_inline', 'execution_class'=>(string) ( $cap['execution_class'] ?? '' ), 'preferred_executor'=>(string) ( $cap['preferred_executor'] ?? '' ), 'total_ms'=>$total_ms, 'executor_ms'=>$invoke_ms, 'sql_ms'=>$sql_ms, 'php_ms'=>null === $sql_ms ? $total_ms : max( 0, round( $total_ms - $sql_ms, 3 ) ), 'query_count'=>max( 0, self::qcount() - $q0 ), 'queue_ms'=>0, 'tool_calls'=>1, 'model_roundtrips'=>1 );
        $ok = ! empty( $verification['ok'] );
        $response = array( 'ok'=>$ok, 'status'=>$ok ? 'completed' : 'completed_unverified', 'terminal'=>true, 'fast_path'=>true, 'result'=>$result, 'verification'=>$verification, 'execution'=>$receipt );
        if ( ! $ok ) {
            $response['verification_warning'] = $verification['warning'] ?? 'Execution effect was not verified.';
            $response['error'] = array( 'code'=>'execution_unverified', 'message'=>'Execution returned without verified effect evidence.', 'retryable'=>false );
        }
        if ( class_exists( 'PRSTUDIO_UC_Procedural_Skills' ) ) {
            try {
                $known = PRSTUDIO_UC_Procedural_Skills::best_match( 'capability', $cap_id, $args );
                if ( is_array( $known ) ) {
                    PRSTUDIO_UC_Procedural_Skills::mark_reused( (string) $known['id'] );
                    $response['known_verified_skill'] = array( 'id'=>$known['id'], 'confidence'=>$known['confidence'] ?? 0, 'success_count'=>$known['success_count'] ?? 0, 'last_verified_gmt'=>$known['last_verified_gmt'] ?? '' );
                }
            } catch ( Throwable $ignored ) {}
        }
        return $response;
    }

    public static function execute( array $request ) {
        $started = microtime( true );
        $cap_id = strtolower( trim( (string) ( $request['capability'] ?? '' ) ) );
        $args = is_array( $request['arguments'] ?? null ) ? $request['arguments'] : array();
        $request_id = sanitize_text_field( (string) ( $request['request_id'] ?? '' ) );
        $request_id = $request_id ?: wp_generate_uuid4();
        $dry = ! empty( $request['dry_run'] );
        $owner_client = sanitize_text_field( (string) ( $request['_owner_client_id'] ?? '' ) );
        $lane_token = (string) ( $request['lane_token'] ?? '' );
        $mode = sanitize_key( (string) ( $request['execution_mode'] ?? 'sync' ) );
        $cap = PRSTUDIO_UC_Capability_Registry::get( $cap_id );
        if ( ! $cap ) { return self::error( 'capability_not_found', 'Capability not found.', 404, array( 'capability'=>$cap_id ) ); }
        if ( in_array( $cap_id, self::ARBITRARY_SCRIPT_CAPABILITIES, true ) ) {
            return self::error( 'capability_arbitrary_script_disabled', 'This capability would execute caller-supplied script in the browser; it is disabled to keep browser_arbitrary_js_exposed=false.', 403, array( 'capability'=>$cap_id ) );
        }
        $schema_errors = PRSTUDIO_UC_Schema_Validator::validate( $args, (array) $cap['input_schema'] );
        if ( ! empty( $schema_errors ) ) { return self::error( 'schema_validation_failed', 'Capability arguments are invalid.', 400, array( 'errors'=>$schema_errors ) ); }

        $is_write = empty( $cap['read_only'] );
        $lane = null;
        if ( '' !== $lane_token && class_exists( 'PRSTUDIO_UC_Execution_Lanes' ) ) {
            $resource = PRSTUDIO_UC_Execution_Lanes::resource_key( $cap_id, $args );
            $lane_candidate = PRSTUDIO_UC_Execution_Lanes::guard( $lane_token, $owner_client, $resource, false );
            if ( is_wp_error( $lane_candidate ) ) { return $lane_candidate; }
            $lane = $lane_candidate;
            if ( empty( $request['mission_id'] ) ) { $request['mission_id'] = (string) ( $lane['mission_id'] ?? '' ); }
        }

        if ( class_exists( 'PRSTUDIO_UC_Execution_Router' ) ) {
            $cap = PRSTUDIO_UC_Execution_Router::annotate_capability( $cap );
            $complex = in_array( $mode, array( 'agentic', 'deferred', 'async', 'durable', 'queue' ), true ) || ! empty( $request['force_agentic'] );
            if ( ! $complex && PRSTUDIO_UC_Execution_Router::can_inline_capability( $cap ) ) {
                return self::execute_inline_cap( $cap, $args, $request, is_array( $lane ) ? $lane : array(), $started );
            }
        }

        $impact = PRSTUDIO_UC_Impact_Engine::analyze( $cap, $args );
        $risk = PRSTUDIO_UC_Risk_Engine_V3::evaluate( $cap, $args, $impact, $dry, array() );
        $plan = PRSTUDIO_UC_Planner_V3::plan( $cap, $args, $impact );
        $key_in = (string) ( $request['idempotency_key'] ?? '' );
        $key_seed = '' !== $key_in ? $key_in : ( 'request:' . $request_id );
        $key = hash( 'sha256', 'capability|' . PRSTUDIO_UC_Memory::site_identity()['key'] . '|' . $cap_id . '|' . $key_seed );
        $mutation_scope = class_exists( 'PRSTUDIO_UC_Pre_Mutation_Safety' ) ? PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_capability( $cap ) : ( $is_write ? 'wordpress' : 'none' );
        $job = PRSTUDIO_UC_Store::create_job( 'execute ' . $cap_id, (string) $cap['domain'], array( 'arguments'=>$args, 'dry_run'=>$dry, 'execution_mode'=>$mode, 'lane_id'=>is_array( $lane ) ? (string) ( $lane['lane_id'] ?? '' ) : '' ), $plan, $key, hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $plan ) ), array( 'mission_id'=>(string) ( $request['mission_id'] ?? '' ), 'owner_client_id'=>$owner_client, 'request_id'=>$request_id ) );
        $job_id = (string) ( $job['job_uuid'] ?? '' );
        $idempotent_replay = ! empty( $job['idempotent_replay'] );
        if ( '' === $job_id ) { return self::error( 'job_create_failed', 'Unable to persist job.', 500, array(), true ); }
        PRSTUDIO_UC_Store::set_job_context( $job_id, array( 'request_id'=>$request_id, 'mission_id'=>sanitize_text_field( (string) ( $request['mission_id'] ?? '' ) ), 'capability'=>$cap_id, 'attempts'=>(int) ( $job['attempts'] ?? 0 ) + 1, 'progress'=>(int) ( $job['progress'] ?? 0 ) ) );
        $mission_id = (string) ( $request['mission_id'] ?? '' );
        self::mission_event( $mission_id, 'job.planned', array( 'job_id'=>$job_id, 'capability'=>$cap_id, 'status'=>'running', 'resume_point'=>'execute:' . $cap_id ) );
        $job = PRSTUDIO_UC_Store::get_job( $job_id ) ?: $job;
        if ( $idempotent_replay || self::terminal( $job ) ) {
            $job['idempotent_replay'] = true;
            $ok = self::terminal_job_ok( $job );
            self::mission_event( $mission_id, 'job.reused', array( 'job_id'=>$job_id, 'capability'=>$cap_id, 'completed_steps'=>$ok ? array( $cap_id ) : array(), 'resume_point'=>$ok ? 'after:' . $cap_id : 'retry:' . $cap_id ) );
            $response = array( 'ok'=>$ok, 'status'=>strtolower( (string) ( $job['status'] ?? '' ) ), 'terminal'=>true, 'job'=>$job, 'idempotent_replay'=>true );
            if ( ! $ok ) { $response['error'] = array( 'code'=>'terminal_job_not_successful', 'message'=>'The existing terminal job did not complete successfully.', 'retryable'=>false ); }
            return $response;
        }
        if ( $dry && $is_write ) {
            PRSTUDIO_UC_Store::set_job_state( $job_id, 'COMPLETED', array( 'result'=>array( 'dry_run'=>true, 'would_execute'=>$cap_id, 'impact'=>$impact, 'risk'=>$risk, 'snapshot_policy'=>$cap['snapshot_policy'], 'verification_policy'=>$cap['verification_policy'], 'plan'=>$plan ), 'verification'=>array( 'ok'=>true, 'verifier'=>'dry_run' ), 'progress'=>100 ) );
            self::mission_event( $mission_id, 'job.dry_run_completed', array( 'job_id'=>$job_id, 'capability'=>$cap_id, 'completed_steps'=>array( 'dry_run:' . $cap_id ), 'resume_point'=>'execute:' . $cap_id ) );
            return array( 'ok'=>true, 'status'=>'completed', 'terminal'=>true, 'job'=>PRSTUDIO_UC_Store::get_job( $job_id ), 'dry_run'=>true );
        }

        $budget = PRSTUDIO_UC_Performance_Budget::normalize( is_array( $request['budget'] ?? null ) ? $request['budget'] : array() );
        $budget_check = PRSTUDIO_UC_Performance_Budget::exceeded( $budget, array( 'duration'=>microtime( true ) - $started, 'operations'=>0, 'network_requests'=>0, 'retries'=>0, 'memory_mb'=>memory_get_usage( true ) / 1048576 ) );
        $budget_check['advisory_only'] = true;
        PRSTUDIO_UC_Store::set_job_state( $job_id, 'RUNNING', array( 'progress'=>15 ) );
        $snapshot = array( 'ok'=>true, 'skipped'=>true, 'reason'=>'anti_crash_is_only_pre_mutation_guard' );
        $invoke_args = $args;
        $invoke_args['_client_id'] = $owner_client;
        if ( '' !== $lane_token ) { $invoke_args['lane_token'] = $lane_token; }
        if ( ! empty( $cap['browser_required'] ) ) {
            $invoke_args['_prstudio_job_uuid'] = $job_id;
            // sync means: hold the PHP request for the bounded Browser bridge
            // window and return a truthful continuation if the task is still
            // non-terminal. Async/deferred modes remain immediate.
            $invoke_args['sync_wait_seconds'] = 'sync' === $mode ? 20 : 0;
        }
        if ( $is_write && ! $dry && class_exists( 'PRSTUDIO_UC_Pre_Mutation_Safety' ) && 'deferred' !== $mutation_scope ) {
            $anti_args = $args;
            if ( isset( $request['work_id'] ) ) { $anti_args['work_id'] = $request['work_id']; }
            $anti = PRSTUDIO_UC_Pre_Mutation_Safety::before_commit( $mutation_scope, $cap_id, $anti_args );
            if ( is_wp_error( $anti ) ) { return $anti; }
        }

        $result = self::invoke( $cap, $invoke_args );
        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'PRSTUDIO_UC_Procedural_Skills' ) ) {
                try { PRSTUDIO_UC_Procedural_Skills::observe_failure( 'capability', $cap_id, $args, array( 'code'=>$result->get_error_code(), 'message'=>$result->get_error_message() ) ); } catch ( Throwable $ignored ) {}
            }
            PRSTUDIO_UC_Store::set_job_state( $job_id, 'TECHNICAL_ERROR', array( 'error'=>array( 'code'=>$result->get_error_code(), 'message'=>$result->get_error_message() ), 'progress'=>100 ) );
            self::mission_event( $mission_id, 'job.technical_error', array( 'job_id'=>$job_id, 'capability'=>$cap_id, 'status'=>'technical_error', 'retry_path'=>'retry_or_alternate:' . $cap_id, 'failed_paths'=>array( array( 'capability'=>$cap_id, 'code'=>$result->get_error_code(), 'gmt'=>gmdate( 'c' ) ) ) ) );
            return $result;
        }

        $failure = self::structured_failure( $result );
        if ( null !== $failure ) {
            PRSTUDIO_UC_Store::set_job_state( $job_id, 'TECHNICAL_ERROR', array( 'error'=>$failure, 'result'=>$result, 'progress'=>100 ) );
            self::mission_event( $mission_id, 'job.technical_error', array( 'job_id'=>$job_id, 'capability'=>$cap_id, 'status'=>'technical_error', 'failed_paths'=>array( array( 'capability'=>$cap_id, 'code'=>$failure['code'], 'gmt'=>gmdate( 'c' ) ) ) ) );
            return array( 'ok'=>false, 'status'=>'technical_error', 'terminal'=>true, 'job'=>PRSTUDIO_UC_Store::get_job( $job_id ), 'result'=>$result, 'error'=>array( 'code'=>$failure['code'], 'message'=>$failure['message'], 'retryable'=>false ) );
        }

        $browser_wait = is_array( $result ) && in_array( strtolower( (string) ( $result['status'] ?? $result['task']['status'] ?? '' ) ), array( 'queued', 'leased', 'running', 'waiting_for_browser' ), true );
        if ( $browser_wait ) {
            $task_id = (string) ( $result['task_id'] ?? $result['task']['task_uuid'] ?? '' );
            PRSTUDIO_UC_Store::set_job_state( $job_id, 'WAITING_FOR_BROWSER', array( 'checkpoint'=>array( 'browser_task_id'=>$task_id, 'browser_result'=>$result ), 'result'=>$result, 'progress'=>50 ) );
            self::mission_event( $mission_id, 'job.waiting_browser', array( 'job_id'=>$job_id, 'capability'=>$cap_id, 'status'=>'waiting', 'resume_point'=>'browser_task:' . $task_id ) );
            return array(
                'ok'=>true,
                'status'=>'waiting_for_browser',
                'terminal'=>false,
                'job_id'=>$job_id,
                'task_id'=>$task_id,
                'job'=>PRSTUDIO_UC_Store::get_job( $job_id ),
                'waiting_for_browser'=>true,
                'poll_after_ms'=>750,
                'next_action'=>'prstudio_job_get',
                'deadline_gmt'=>gmdate( 'c', time() + 120 ),
            );
        }

        try {
            $verification = PRSTUDIO_UC_Verification_Engine_V3::verify( $cap, $args, $result );
        } catch ( Throwable $e ) {
            $verification = array( 'ok'=>false, 'verifier'=>'verification_exception', 'source'=>'verification_engine', 'independent'=>false, 'exception_class'=>get_class( $e ), 'verified_at'=>gmdate( 'c' ) );
        }
        try {
            $evidence = PRSTUDIO_UC_Evidence_Engine::receipt( $cap, is_array( $result ) ? $result : array( 'result'=>$result ), array( 'verified'=>! empty( $verification['ok'] ), 'sources'=>array( $verification['source'] ?? '' ) ) );
        } catch ( Throwable $e ) {
            $evidence = array( 'verified'=>false, 'evidence_hash'=>'', 'sources'=>array(), 'recording_error'=>array( 'code'=>'evidence_exception', 'exception_class'=>get_class( $e ) ), 'created_at'=>gmdate( 'c' ) );
        }
        $verified = ! empty( $verification['ok'] );
        $completion = array( 'result'=>$result, 'verification'=>$verification, 'evidence'=>$evidence, 'progress'=>100 );
        if ( ! $verified ) {
            $completion['verification_warning'] = array( 'code'=>'execution_unverified', 'message'=>'Execution completed; post-execution verification did not confirm the effect.' );
        }
        PRSTUDIO_UC_Store::set_job_state( $job_id, 'COMPLETED', $completion );
        self::mission_event( $mission_id, 'job.completed', array( 'job_id'=>$job_id, 'capability'=>$cap_id, 'status'=>$verified ? 'completed' : 'completed_unverified', 'completed_steps'=>array( $cap_id ), 'resume_point'=>'after:' . $cap_id, 'evidence'=>array( array( 'capability'=>$cap_id, 'hash'=>(string) ( $evidence['evidence_hash'] ?? '' ), 'verifier'=>(string) ( $verification['verifier'] ?? '' ), 'verified'=>$verified, 'gmt'=>gmdate( 'c' ) ) ) ) );
        $procedural_skill = array( 'ok'=>true, 'learned'=>false, 'reason'=>$verified ? 'skill_store_unavailable' : 'execution_unverified' );
        $known_skill = null;
        if ( class_exists( 'PRSTUDIO_UC_Procedural_Skills' ) ) {
            try {
                $known_skill = PRSTUDIO_UC_Procedural_Skills::best_match( 'capability', $cap_id, $args );
                if ( is_array( $known_skill ) ) { PRSTUDIO_UC_Procedural_Skills::mark_reused( (string) $known_skill['id'] ); }
            } catch ( Throwable $ignored ) { $known_skill = null; }
            if ( $verified ) {
                try { $procedural_skill = PRSTUDIO_UC_Procedural_Skills::learn_verified_capability( $cap_id, $args, is_array( $result ) ? $result : array( 'result'=>$result ), $verification, $job_id ); }
                catch ( Throwable $ignored ) { $procedural_skill = array( 'ok'=>false, 'learned'=>false, 'reason'=>'skill_store_exception' ); }
            }
        }
        PRSTUDIO_UC_Memory::movement( 'capability.executed', array( 'mission_id'=>(string) ( $request['mission_id'] ?? '' ), 'request_id'=>$request_id, 'trace_id'=>(string) ( $request['trace_id'] ?? '' ), 'site_id'=>PRSTUDIO_UC_Memory::site_identity()['key'], 'capability'=>$cap_id, 'resource'=>$args['url'] ?? $args['id'] ?? '', 'action'=>$dry ? 'dry_run' : 'execute', 'outcome'=>$verified ? 'verified_completed' : 'completed_unverified', 'verification'=>$verification['verifier'] ?? '', 'evidence'=>$evidence['evidence_hash'] ?? '', 'fingerprint'=>hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $args ) ), 'memory_reused'=>(bool) ( $result['memory_reused'] ?? false ), 'duration_ms'=>(int) round( ( microtime( true ) - $started ) * 1000 ) ), $job_id );

        $response = array(
            'ok'=>$verified,
            'status'=>$verified ? 'completed' : 'completed_unverified',
            'terminal'=>true,
            'job_id'=>$job_id,
            'job'=>PRSTUDIO_UC_Store::get_job( $job_id ),
            'result'=>$result,
            'verification'=>$verification,
            'evidence'=>$evidence,
            'snapshot'=>$snapshot,
            'procedural_skill'=>$procedural_skill,
        );
        if ( ! $verified ) {
            $response['error'] = array( 'code'=>'execution_unverified', 'message'=>'Execution completed but the requested effect was not verified.', 'retryable'=>false );
        }
        return $response;
    }
}
