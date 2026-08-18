<?php

if ( ! defined( 'ABSPATH' ) ) {
/* PR STUDIO ONE-GUARD INVARIANT: Anti-Crash is the only mutation guard. Verification/risk/telemetry never block an executable action. */
	exit;
}

final class PRSTUDIO_UC_REST {
	private const NS = 'prstudio-unified/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/pair',
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'pair' ),
				'permission_callback' => '__return_true',
				'args' => array(
					'code' => array( 'required' => true, 'type' => 'string' ),
					'name' => array( 'required' => true, 'type' => 'string' ),
					'capabilities' => array( 'type' => 'object', 'default' => array() ),
					'previous_device_id' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/device/heartbeat',
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'device_heartbeat' ),
				'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/tasks/next',
			array(
				'methods' => 'GET',
				'callback' => array( __CLASS__, 'next_task' ),
				'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_permission' ),
			)
		);

		foreach ( array( 'running', 'heartbeat', 'checkpoint', 'complete', 'fail' ) as $action ) {
			register_rest_route(
				self::NS,
				'/tasks/(?P<task>[a-f0-9-]+)/' . $action,
				array(
					'methods' => 'POST',
					'callback' => array( __CLASS__, 'task_' . $action ),
					'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_permission' ),
				)
			);
		}

		register_rest_route(
			self::NS,
			'/tasks/(?P<task>[a-f0-9-]+)',
			array(
				'methods' => 'GET',
				'callback' => array( __CLASS__, 'task_status' ),
				'permission_callback' => static fn() => PRSTUDIO_UC_Auth::bridge_permission( false ),
			)
		);

		register_rest_route(
			self::NS,
			'/tasks',
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'create_task' ),
				'permission_callback' => static fn() => PRSTUDIO_UC_Auth::bridge_permission( true ),
			)
		);

		register_rest_route(
			self::NS,
			'/tasks/(?P<task>[a-f0-9-]+)/cancel',
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'cancel_task' ),
				'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_or_bridge_permission' ),
			)
		);


		register_rest_route(
			self::NS,
			'/stream/session',
			array(
				array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'stream_session_create'), 'permission_callback'=>array('PRSTUDIO_UC_Auth','device_permission') ),
				array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'stream_active'), 'permission_callback'=>array('PRSTUDIO_UC_Auth','device_permission') ),
			)
		);

		register_rest_route(
			self::NS,
			'/stream/session/(?P<session>[a-f0-9]{32})',
			array(
				array( 'methods'=>array('GET','POST'), 'callback'=>array(__CLASS__,'stream_session_exchange'), 'permission_callback'=>array('PRSTUDIO_UC_Auth','device_permission') ),
				array( 'methods'=>'DELETE', 'callback'=>array(__CLASS__,'stream_session_close'), 'permission_callback'=>array('PRSTUDIO_UC_Auth','device_permission') ),
			)
		);

		register_rest_route(
			self::NS,
			'/artifact/screenshot',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'screenshot_status' ), 'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_permission' ) ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'screenshot_store' ), 'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_permission' ) ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'screenshot_delete' ), 'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_permission' ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/artifact/screenshot/(?P<id>[a-f0-9]{32})',
			array( 'methods' => 'GET', 'callback' => array( 'PRSTUDIO_UC_Artifacts', 'serve' ), 'permission_callback' => '__return_true' )
		);

		register_rest_route(
			self::NS,
			'/ocr',
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'ocr' ),
				'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/logs',
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'extension_logs' ),
				'permission_callback' => array( 'PRSTUDIO_UC_Auth', 'device_permission' ),
				'args' => array(
					'events' => array( 'required' => true, 'type' => 'array' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods' => 'GET',
				'callback' => array( __CLASS__, 'status' ),
				'permission_callback' => static fn() => PRSTUDIO_UC_Auth::bridge_permission( false ),
			)
		);


		register_rest_route(
			self::NS,
			'/jobs',
			array(
				'methods' => 'GET',
				'callback' => array( __CLASS__, 'jobs' ),
				'permission_callback' => static fn() => PRSTUDIO_UC_Auth::bridge_permission( false ),
			)
		);

		register_rest_route(self::NS,'/missions',array('methods'=>'POST','callback'=>array(__CLASS__,'mission_create'),'permission_callback'=>static fn()=>PRSTUDIO_UC_Auth::bridge_permission(true)));
		register_rest_route(self::NS,'/missions/(?P<job>[a-f0-9-]+)',array('methods'=>'GET','callback'=>array(__CLASS__,'job_status'),'permission_callback'=>static fn()=>PRSTUDIO_UC_Auth::bridge_permission(false)));
		register_rest_route(self::NS,'/missions/(?P<job>[a-f0-9-]+)/control',array('methods'=>'POST','callback'=>array(__CLASS__,'mission_control'),'permission_callback'=>static fn()=>PRSTUDIO_UC_Auth::bridge_permission(true)));
		register_rest_route(self::NS,'/agency/status',array('methods'=>'GET','callback'=>array(__CLASS__,'agency_status'),'permission_callback'=>static fn()=>PRSTUDIO_UC_Auth::bridge_permission(false)));
		register_rest_route(self::NS,'/agency/run-one',array('methods'=>'POST','callback'=>array(__CLASS__,'agency_run_one'),'permission_callback'=>static fn()=>PRSTUDIO_UC_Auth::bridge_permission(true)));
		register_rest_route(self::NS,'/agency/schedules',array(
			array('methods'=>'GET','callback'=>array(__CLASS__,'agency_schedules_due'),'permission_callback'=>static fn()=>PRSTUDIO_UC_Auth::bridge_permission(false)),
			array('methods'=>'POST','callback'=>array(__CLASS__,'agency_schedule_upsert'),'permission_callback'=>static fn()=>PRSTUDIO_UC_Auth::bridge_permission(true)),
		));

	}

	public static function pair( WP_REST_Request $request ) {
		$rate = PRSTUDIO_UC_Auth::pair_rate_limit(); if ( is_wp_error( $rate ) ) { return $rate; }
		$capabilities = (array) $request->get_param( 'capabilities' );
		$compatibility = PRSTUDIO_UC_Browser_Protocol::compatibility( $capabilities );
		if ( empty( $compatibility['compatible'] ) ) {
			return new WP_Error( 'prstudio_uc_contract_mismatch', 'Plugin ed estensione non condividono lo stesso contratto 0.3.3.', array( 'status'=>409, 'compatibility'=>$compatibility ) );
		}
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		if ( ! PRSTUDIO_UC_Auth::consume_pairing_code( $code ) ) {
			return new WP_Error( 'prstudio_uc_pair_invalid', 'Codice pairing non valido o scaduto.', array( 'status' => 403 ) );
		}
		$previous_device_id = sanitize_text_field( (string) $request->get_param( 'previous_device_id' ) );
		if ( '' !== $previous_device_id ) {
			PRSTUDIO_UC_Store::revoke_device( $previous_device_id );
			PRSTUDIO_UC_Artifacts::delete_current( $previous_device_id );
		}
		$device = PRSTUDIO_UC_Store::create_device(
			sanitize_text_field( (string) $request->get_param( 'name' ) ),
			(array) $request->get_param( 'capabilities' )
		);
		return array(
			'device_id' => $device['device_id'],
			'token' => $device['token'],
			'api_base' => rest_url( self::NS ),
			'version' => PRSTUDIO_UC_VERSION,
			'protocol_version' => PRSTUDIO_UC_Contract::protocol_version(),
			'contract_hash' => PRSTUDIO_UC_Contract::hash(),
			'browser_executor_protocol' => PRSTUDIO_UC_Browser_Protocol::negotiated_protocol( $capabilities ),
			'browser_executor_protocol_preferred' => PRSTUDIO_UC_Browser_Protocol::EXECUTOR_PROTOCOL,
			'browser_executor_protocols_accepted' => PRSTUDIO_UC_Browser_Protocol::ACCEPTED_EXECUTOR_PROTOCOLS,
			'server_capabilities' => self::integration_capabilities(),
		);
	}

	public static function device_heartbeat( WP_REST_Request $request ) {
		$device = (array) $request->get_param( '_prstudio_device' );
		$capabilities = (array) $request->get_param( 'capabilities' );
		$compatibility = PRSTUDIO_UC_Browser_Protocol::compatibility( $capabilities );
		PRSTUDIO_UC_Store::touch_device( (string) $device['device_uuid'], $capabilities );
		if ( empty( $compatibility['compatible'] ) ) { return new WP_Error( 'prstudio_uc_contract_mismatch', 'Contratto Browser Agent non allineato.', array( 'status'=>409, 'compatibility'=>$compatibility ) ); }
		return array( 'ok'=>true, 'server_time'=>time(), 'contract_hash'=>PRSTUDIO_UC_Contract::hash(), 'protocol_version'=>PRSTUDIO_UC_Contract::protocol_version(), 'server_capabilities'=>self::integration_capabilities() );
	}

	/**
	 * Long-polling task dispatch.
	 *
	 * The 15.x agent hit this endpoint every 100–750 ms while idle, and each
	 * call did real database work: touch_device() issued an UPDATE, claim_next()
	 * ran recover_stale_tasks() plus a transaction, a SELECT and an UPDATE.
	 * Roughly five write queries per poll, several polls per second, from a
	 * browser sitting idle — hundreds of thousands of writes a day and a
	 * constant PHP load on the whole site.
	 *
	 * With `wait` the request is held instead. While no work exists the wait
	 * channel consults a cache-resident generation counter and touches no table
	 * at all; producers bump that counter when they enqueue, so a waiting agent
	 * is released within a tick. Dispatch stays fast, idle cost goes to zero.
	 *
	 * Clients that do not send `wait` get exactly the old behaviour, so an
	 * unupgraded extension keeps working.
	 */
	public static function next_task( WP_REST_Request $request ): array {
		$device = (array) $request->get_param( '_prstudio_device' );
		$compatibility = PRSTUDIO_UC_Browser_Protocol::compatibility( (array) ( $device['capabilities'] ?? array() ) );
		if ( empty( $compatibility['compatible'] ) ) { return array( 'task'=>null, 'technical_error'=>array( 'code'=>'contract_mismatch', 'retryable'=>false ), 'compatibility'=>$compatibility, 'server_time'=>time() ); }

		$device_uuid = (string) $device['device_uuid'];
		// Presence needs minute resolution, not millisecond. Writing last_seen
		// on every poll bought nothing and cost one UPDATE each time.
		if ( PRSTUDIO_UC_Wait_Channel::should_touch_device( $device_uuid ) ) {
			PRSTUDIO_UC_Store::touch_device( $device_uuid );
		}

		$requested_wait = (int) $request->get_param( 'wait' );
		if ( $requested_wait <= 0 ) {
			return array( 'task' => PRSTUDIO_UC_Store::claim_next( $device_uuid ), 'server_time' => time(), 'wait_supported' => true );
		}

		$outcome = PRSTUDIO_UC_Wait_Channel::wait_for_work(
			$device_uuid,
			$requested_wait,
			static function () use ( $device_uuid ) { return PRSTUDIO_UC_Store::claim_next( $device_uuid ); }
		);
		return array(
			'task'        => $outcome['task'],
			'server_time' => time(),
			'wait_supported' => true,
			'waited_ms'   => $outcome['waited_ms'],
			'wait_mode'   => $outcome['mode'],
		);
	}

	private static function lease( WP_REST_Request $request ): string {
		return sanitize_text_field( (string) $request->get_param( 'lease_token' ) );
	}

	public static function task_running( WP_REST_Request $request ) {
		return PRSTUDIO_UC_Store::mark_running( (string) $request['task'], self::lease( $request ) )
			?: new WP_Error( 'prstudio_uc_task_conflict', 'Lease task non valido.', array( 'status' => 409 ) );
	}

	public static function task_heartbeat( WP_REST_Request $request ) {
		return array(
			'ok' => PRSTUDIO_UC_Store::heartbeat( (string) $request['task'], self::lease( $request ) ),
		);
	}

	public static function task_checkpoint( WP_REST_Request $request ) {
		return PRSTUDIO_UC_Store::checkpoint(
			(string) $request['task'],
			self::lease( $request ),
			absint( $request->get_param( 'step_index' ) ),
			(array) $request->get_param( 'result' )
		) ?: new WP_Error( 'prstudio_uc_task_conflict', 'Checkpoint rifiutato.', array( 'status' => 409 ) );
	}

	public static function task_complete( WP_REST_Request $request ) {
		$device = (array) $request->get_param( '_prstudio_device' );
		return PRSTUDIO_UC_Job_Engine::complete_browser_task(
			(string) $request['task'],
			self::lease( $request ),
			(array) $request->get_param( 'result' ),
			(string) ( $device['device_uuid'] ?? '' )
		) ?: new WP_Error( 'prstudio_uc_task_conflict', 'Completamento non persistito per conflitto tecnico di lease/stato.', array( 'status' => 409 ) );
	}

	public static function task_fail( WP_REST_Request $request ) {
		return PRSTUDIO_UC_Job_Engine::fail_browser_task(
			(string) $request['task'],
			self::lease( $request ),
			(array) $request->get_param( 'error' )
		) ?: new WP_Error( 'prstudio_uc_task_conflict', 'Errore task rifiutato.', array( 'status' => 409 ) );
	}

	public static function task_status( WP_REST_Request $request ) {
		return PRSTUDIO_UC_Store::get_task( (string) $request['task'] )
			?: new WP_Error( 'prstudio_uc_task_missing', 'Task non trovato.', array( 'status' => 404 ) );
	}

	public static function create_task( WP_REST_Request $request ): array {
		return PRSTUDIO_UC_Job_Engine::create_browser_task(
			sanitize_key( (string) $request->get_param( 'action' ) ),
			(array) $request->get_param( 'arguments' ),
			$request->get_param( 'device_id' ) ? sanitize_text_field( (string) $request->get_param( 'device_id' ) ) : null,
			sanitize_text_field( (string) $request->get_param( 'job_id' ) )
		);
	}

	public static function cancel_task( WP_REST_Request $request ) {
		$device=(array)$request->get_param('_prstudio_device');
		$mode=sanitize_key((string)$request->get_param('mode'));
		if ( 'restart_fresh' === $mode && ! empty($device['device_uuid']) ) {
			$result=PRSTUDIO_UC_Store::restart_fresh_for_device(
				(string)$request['task'],
				(string)$device['device_uuid'],
				sanitize_text_field((string)($request->get_param('reason') ?: 'two_attempts_without_progress'))
			);
			return $result ?: new WP_Error( 'prstudio_uc_fresh_restart_rejected', 'Ripartenza pulita rifiutata: task non sicuro, già ripartito o con checkpoint completati.', array( 'status'=>409 ) );
		}
		$reason=sanitize_text_field((string)($request->get_param('reason') ?: 'browser_task_cancelled'));
		$result=PRSTUDIO_UC_Job_Engine::cancel_browser_task(
			(string)$request['task'],
			(string)($device['device_uuid']??''),
			array('code'=>'browser_task_cancelled','message'=>$reason,'retryable'=>false)
		);
		return $result
			?: new WP_Error( 'prstudio_uc_task_conflict', 'Cancellazione non consentita.', array( 'status' => 409 ) );
	}

	public static function health(): array {
		return PRSTUDIO_UC_Health::snapshot();
	}

	public static function jobs(): array {
		return array( 'ok'=>true, 'version'=>PRSTUDIO_UC_VERSION, 'jobs'=>PRSTUDIO_UC_Store::recent_jobs( 50 ) );
	}

	public static function job_status( WP_REST_Request $request ) {
		return PRSTUDIO_UC_Store::get_job( (string) $request['job'] )
			?: new WP_Error( 'prstudio_uc_job_missing', 'Workflow non trovato.', array( 'status'=>404 ) );
	}

	public static function mission_create( WP_REST_Request $request ): array {
		$context = (array) $request->get_param( 'context' );
		foreach ( array( 'playbook', 'occurrence_key' ) as $key ) {
			$value = (string) $request->get_param( $key );
			if ( '' !== $value ) {
				$context[ $key ] = 'playbook' === $key ? sanitize_key( $value ) : sanitize_text_field( $value );
			}
		}
		if ( null !== $request->get_param( 'priority' ) ) {
			$context['priority'] = (int) $request->get_param( 'priority' );
		}
		return PRSTUDIO_UC_Mission_Engine::create(
			sanitize_text_field( (string) ( $request->get_param( 'objective' ) ?: 'Agency mission' ) ),
			$context
		);
	}

	public static function mission_control( WP_REST_Request $request ) {
		return PRSTUDIO_UC_Mission_Engine::control((string)$request['job'],sanitize_key((string)$request->get_param('action')),array('reason'=>sanitize_text_field((string)$request->get_param('reason'))));
	}

	public static function agency_status(): array { return PRSTUDIO_UC_Agency_Runtime::status(); }
	public static function agency_run_one( WP_REST_Request $request ): array { return PRSTUDIO_UC_Agency_Runtime::run_one(sanitize_text_field((string)$request->get_param('job_id')),'rest'); }
	public static function agency_schedules_due(): array { return array('items'=>PRSTUDIO_UC_Store::due_schedules(100)); }
	public static function agency_schedule_upsert( WP_REST_Request $request ): array {
		return PRSTUDIO_UC_Store::upsert_schedule(sanitize_key((string)$request->get_param('playbook')),sanitize_text_field((string)$request->get_param('objective')),(array)$request->get_param('context'),(int)$request->get_param('interval_seconds'),sanitize_text_field((string)$request->get_param('next_run_gmt')),sanitize_text_field((string)$request->get_param('schedule_id')));
	}


	public static function stream_session_create( WP_REST_Request $request ) {
		$device=(array)$request->get_param('_prstudio_device');
		$device_id=(string)($device['device_uuid']??'');
		$tab_id=absint($request->get_param('tab_id'));
		$meta=array(
			'source'=>sanitize_text_field((string)$request->get_param('source')),
			'title'=>sanitize_text_field((string)$request->get_param('title')),
			'url'=>esc_url_raw((string)$request->get_param('url')),
		);
		return PRSTUDIO_UC_Browser_Live::create_agent_session($device_id,$tab_id,$meta);
	}

	public static function stream_active( WP_REST_Request $request ) {
		$device=(array)$request->get_param('_prstudio_device');
		$tab_id=absint($request->get_param('tab_id'));
		if($tab_id<=0)return new WP_Error('browser_live_tab_required','tab_id è obbligatorio.',array('status'=>400));
		$session=PRSTUDIO_UC_Browser_Live::find_active($tab_id,(string)($device['device_uuid']??''));
		return $session?:array('ok'=>true,'available'=>false,'tab_id'=>$tab_id);
	}

	public static function stream_session_exchange( WP_REST_Request $request ) {
		$device=(array)$request->get_param('_prstudio_device');
		$session=sanitize_text_field((string)$request['session']);
		$after=max(0,(int)$request->get_param('after'));
		$events=(array)$request->get_param('events');
		return PRSTUDIO_UC_Browser_Live::agent_exchange($session,(string)($device['device_uuid']??''),$after,$events);
	}

	public static function stream_session_close( WP_REST_Request $request ) {
		$device=(array)$request->get_param('_prstudio_device');
		$session=sanitize_text_field((string)$request['session']);
		$reason=sanitize_text_field((string)$request->get_param('reason'));
		return PRSTUDIO_UC_Browser_Live::close_agent($session,(string)($device['device_uuid']??''),$reason?:'agent_stop');
	}

	public static function screenshot_status(): array {
		return PRSTUDIO_UC_Artifacts::status();
	}

	public static function screenshot_store( WP_REST_Request $request ) {
		$device = (array) $request->get_param( '_prstudio_device' );
		return PRSTUDIO_UC_Artifacts::store(
			(string) $device['device_uuid'],
			(string) $request->get_param( 'image' ),
			array(
				'task_id' => (string) $request->get_param( 'task_id' ),
				'step_index' => absint( $request->get_param( 'step_index' ) ),
				'capture_mode' => sanitize_key( (string) $request->get_param( 'capture_mode' ) ),
				'full_page' => (bool) $request->get_param( 'full_page' ),
				'full_page_complete' => (bool) $request->get_param( 'full_page_complete' ),
			)
		);
	}

	public static function screenshot_delete( WP_REST_Request $request ): array {
		$device = (array) $request->get_param( '_prstudio_device' );
		return PRSTUDIO_UC_Artifacts::delete_current( (string) $device['device_uuid'] );
	}

	public static function ocr( WP_REST_Request $request ) {
		return PRSTUDIO_UC_OCR::run(
			(string) $request->get_param( 'image' ),
			sanitize_text_field( (string) ( $request->get_param( 'language' ) ?: 'ita+eng' ) )
		);
	}

	public static function extension_logs( WP_REST_Request $request ): array {
		$device = (array) $request->get_param( '_prstudio_device' );
		$events = (array) $request->get_param( 'events' );
		if ( ! class_exists( 'PRSTUDIO_UC_Log_Orchestrator' ) ) {
			return array( 'ok'=>false, 'accepted'=>0, 'reason'=>'orchestrator_unavailable' );
		}
		return PRSTUDIO_UC_Log_Orchestrator::ingest_extension( $events, (string) ( $device['device_uuid'] ?? '' ) );
	}

	public static function integration_capabilities(): array {
		$counts = class_exists( 'PRSTUDIO_UC_Capability_Registry' ) ? PRSTUDIO_UC_Capability_Registry::counts() : array();
		$toolchain = class_exists( 'PRSTUDIO_UC_MCP_Toolchain' ) ? PRSTUDIO_UC_MCP_Toolchain::status() : array();
		$toolchain_profiles = array();
		foreach ( (array) ( $toolchain['profiles'] ?? array() ) as $name=>$profile ) {
			$toolchain_profiles[ sanitize_key( (string) $name ) ] = array(
				'available' => ! empty( $profile['available'] ),
				'kind' => sanitize_key( (string) ( $profile['kind'] ?? '' ) ),
				'mode' => sanitize_key( (string) ( $profile['mode'] ?? '' ) ),
			);
		}
		return array(
			'suite_version' => PRSTUDIO_UC_VERSION,
			'component' => 'wordpress_control_plane',
			'component_version' => PRSTUDIO_UC_VERSION,
			'browser_executor_protocol' => PRSTUDIO_UC_Browser_Protocol::EXECUTOR_PROTOCOL,
			'pairing_contract_unchanged' => true,
			'wordpress_install_contract_unchanged' => true,
			'mcp_available' => class_exists( 'PRSTUDIO_UC_MCP_V5' ),
			'mcp_protocol' => class_exists( 'PRSTUDIO_UC_MCP_V5' ) ? PRSTUDIO_UC_MCP_V5::MCP_PROTOCOL : '',
			'chatgpt_primary_integration' => 'custom_chatgpt_plugin_app_via_mcp',
			'routing_precedence' => array(
				array( 'priority'=>1, 'lane'=>'typed_mcp_tool', 'when'=>'a dedicated typed tool exists' ),
				array( 'priority'=>2, 'lane'=>'local_studio', 'when'=>'the caller explicitly requests a local-only workflow' ),
				array( 'priority'=>3, 'lane'=>'browser_agent_contract', 'when'=>'live UI or browser evidence is required' ),
				array( 'priority'=>4, 'lane'=>'generic_browser_action', 'when'=>'no dedicated typed browser tool exists and the action is allowlisted' ),
				array( 'priority'=>5, 'lane'=>'legacy_compatibility', 'when'=>'explicitly enabled for migration only' ),
			),
			'legacy_hidden_by_default' => true,
			'capability_counts' => $counts,
			'extension_feature_awareness' => true,
			'extension_local_features_are_local_only' => true,
			'no_external_account_requirement' => true,
			'browser_queue_self_healing' => true,
			'browser_fresh_restart_after_attempts' => 2,
			'browser_fresh_restart_limit' => 1,
			'browser_stale_lease_detection' => true,
			'browser_live_webrtc' => class_exists('PRSTUDIO_UC_Browser_Live') ? PRSTUDIO_UC_Browser_Live::status() : array('ok'=>false,'available'=>false),
			'mcp_toolchain_version' => class_exists( 'PRSTUDIO_UC_MCP_Toolchain' ) ? PRSTUDIO_UC_MCP_Toolchain::VERSION : '',
			'mcp_toolchain_native_first' => true,
			'mcp_toolchain_sidecars_optional' => true,
			'mcp_toolchain_no_boot_processes' => true,
			'mcp_toolchain_profiles' => $toolchain_profiles,
		);
	}

	public static function browser_extension_summary( bool $include_history = false ): array {
		$items = array();
		$all_devices = PRSTUDIO_UC_Store::list_devices();
		$devices = $include_history ? $all_devices : array_values( array_filter( $all_devices, static fn( $device ) => 'active' === (string) ( $device['status'] ?? '' ) ) );
		foreach ( $devices as $device ) {
			$cap = (array) ( $device['capabilities'] ?? array() );
			$local = (array) ( $cap['localStudio'] ?? array() );
			$features = array_values( array_filter( array_map( 'sanitize_key', (array) ( $local['features'] ?? $cap['localFeatures'] ?? array() ) ) ) );
			$items[] = array(
				'device_id' => sanitize_text_field( (string) ( $device['device_uuid'] ?? '' ) ),
				'name' => sanitize_text_field( (string) ( $device['name'] ?? '' ) ),
				'online' => ! empty( $device['online'] ),
				'connection_status' => sanitize_key( (string) ( $device['connection_status'] ?? '' ) ),
				'last_seen_age_seconds' => isset( $device['last_seen_age_seconds'] ) ? (int) $device['last_seen_age_seconds'] : null,
				'component' => sanitize_key( (string) ( $cap['component'] ?? 'browser_agent' ) ),
				'component_version' => sanitize_text_field( (string) ( $cap['componentVersion'] ?? $cap['agentImplementationVersion'] ?? '' ) ),
				'suite_version' => sanitize_text_field( (string) ( $cap['suiteVersion'] ?? '' ) ),
				'agent_build' => sanitize_text_field( (string) ( $cap['agentBuild'] ?? '' ) ),
				'build_timestamp' => sanitize_text_field( (string) ( $cap['buildTimestamp'] ?? '' ) ),
				'capability_hash' => sanitize_text_field( (string) ( $cap['capabilityHash'] ?? '' ) ),
				'runtime_operation_count' => isset( $cap['runtimeOperationCount'] ) ? (int) $cap['runtimeOperationCount'] : 0,
				'executor_protocol' => sanitize_text_field( (string) ( $cap['executorProtocolVersion'] ?? '' ) ),
				'local_studio_version' => sanitize_text_field( (string) ( $local['version'] ?? $cap['localStudioVersion'] ?? '' ) ),
				'local_standalone_mode' => ! empty( $cap['localStandaloneMode'] ) || in_array( 'standalone_mode', $features, true ),
				'local_no_external_accounts' => ! empty( $cap['localNoExternalAccounts'] ) || ! empty( $local['noExternalAccounts'] ),
				'local_no_api_keys' => ! empty( $cap['localNoApiKeys'] ) || ! empty( $local['noApiKeys'] ),
				'local_features' => array_slice( $features, 0, 64 ),
				'feature_count' => count( $features ),
				'queue_self_healing' => ! empty( $cap['remoteQueueSelfHealing'] ),
				'fresh_restart_after_attempts' => isset( $cap['remoteAutoFreshRestartAfterAttempts'] ) ? (int) $cap['remoteAutoFreshRestartAfterAttempts'] : 0,
				'no_progress_watchdog' => ! empty( $cap['remoteNoProgressWatchdog'] ),
				'internal_scroll_fallback' => ! empty( $cap['remoteInternalScrollFallback'] ),
				'explicit_user_tab_adoption' => ! empty( $cap['explicitUserTabAdoption'] ),
				'multi_lane_tab_isolation' => ! empty( $cap['multiLaneTabIsolation'] ),
				'local_remote_invocation' => ! empty( $cap['localRemoteInvocation'] ),
			);
		}
		return array(
			'aware' => true,
			'local_features_remote_invocation' => (bool) array_filter( $items, static fn( $row ) => ! empty( $row['local_remote_invocation'] ) ),
			'local_features_discovery_via_heartbeat' => true,
			'devices' => $items,
			'device_history' => array(
				'total' => count( $all_devices ),
				'active' => count( array_filter( $all_devices, static fn( $row ) => 'active' === (string) ( $row['status'] ?? '' ) ) ),
				'online' => count( array_filter( $all_devices, static fn( $row ) => 'online' === (string) ( $row['connection_status'] ?? '' ) ) ),
				'offline' => count( array_filter( $all_devices, static fn( $row ) => 'offline' === (string) ( $row['connection_status'] ?? '' ) ) ),
				'stale' => count( array_filter( $all_devices, static fn( $row ) => 'stale' === (string) ( $row['connection_status'] ?? '' ) ) ),
				'revoked' => count( array_filter( $all_devices, static fn( $row ) => 'revoked' === (string) ( $row['status'] ?? '' ) ) ),
			),
		);
	}

	public static function status(): array {
		return array(
			'version' => PRSTUDIO_UC_VERSION,
			'devices' => PRSTUDIO_UC_Store::public_devices( PRSTUDIO_UC_Store::list_devices() ),
			'ocr' => PRSTUDIO_UC_OCR::status(),
			'bridge_available' => class_exists( 'WPAIB_Auth' ),
			'lab_runtime_available' => class_exists( 'PRSTUDIO_Browser_Runtime' ),
			'orchestrator_available' => class_exists( 'PRSTUDIO_UC_Orchestrator' ),
			'contract' => PRSTUDIO_UC_Contract::status(),
			'integration_capabilities' => self::integration_capabilities(),
			'browser_extension' => self::browser_extension_summary( true ),
			'active_work' => class_exists( 'PRSTUDIO_UC_Work_Session' ) ? PRSTUDIO_UC_Work_Session::active() : array(),
			'screenshot_policy' => array_merge( array( 'storage' => 'filesystem_private', 'database_data_urls' => false ), class_exists( 'PRSTUDIO_UC_Artifacts' ) ? PRSTUDIO_UC_Artifacts::status() : array() ),
		);
	}
}
