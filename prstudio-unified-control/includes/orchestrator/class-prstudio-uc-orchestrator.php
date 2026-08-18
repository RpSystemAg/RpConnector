<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }
/* PR STUDIO ONE-GUARD INVARIANT: Anti-Crash is the only mutation guard. Verification/risk/telemetry never block an executable action. */

final class PRSTUDIO_UC_Orchestrator {
	private const VERSION = '2.0.0';
	private static ?array $catalog = null;
	private static ?array $domains = null;

	public static function normalize( string $value ): string {
		$value = strtolower( str_replace( '_', ' ', $value ) );
		if ( function_exists( 'remove_accents' ) ) { $value = remove_accents( $value ); }
		$value = preg_replace( '/[^a-z0-9\s\/-]+/u', ' ', $value );
		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}
	public static function tokens( string $value ): array {
		$stop = array_fill_keys( array( 'che','con','del','della','delle','degli','per','una','uno','un','il','la','lo','le','i','gli','di','da','in','su','e','o','fare','devo','voglio','please','the','a','to','and' ), true );
		return array_values( array_unique( array_filter( explode( ' ', self::normalize( $value ) ), static fn( $token ) => strlen( $token ) >= 2 && ! isset( $stop[ $token ] ) ) ) );
	}

	public static function catalog(): array {
		if ( null !== self::$catalog ) { return self::$catalog; }
		$path = trailingslashit( PRSTUDIO_UC_DIR ) . 'connector/action-catalog.json';
		$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array();
		self::$catalog = is_array( $data['actions'] ?? null ) ? array_values( array_filter( $data['actions'], 'is_array' ) ) : array();
		return self::$catalog;
	}
	/** @return array<string,PRSTUDIO_UC_Domain_Abstract> */
	public static function domains(): array {
		if ( null !== self::$domains ) { return self::$domains; }
		$objects = array(
			new PRSTUDIO_Domain_Browser(), new PRSTUDIO_Domain_Content_SEO(), new PRSTUDIO_Domain_Catalog_Commerce(),
			new PRSTUDIO_Domain_Orders_Customers(), new PRSTUDIO_Domain_Media_Stories(), new PRSTUDIO_Domain_Experience_UI(),
			new PRSTUDIO_Domain_Extensions_Themes(), new PRSTUDIO_Domain_Data_Storage(), new PRSTUDIO_Domain_Security_Identity(), new PRSTUDIO_Domain_Operations(),
		);
		self::$domains = array();
		foreach ( $objects as $object ) { self::$domains[ $object->id() ] = $object; }
		return self::$domains;
	}


	/**
	 * Governs every MCP call without removing or renaming the original tool.
	 * Resolution is O(1) through the shared contract indexes.
	 */
	public static function govern_tool_call( string $tool_name, array $args = array() ) {
		$meta = null;
		$route = '';
		$action = '';
		$action_contract_match = true;

		if ( 'idealmarket_action_call' === $tool_name ) {
			$route = (string) ( $args['route'] ?? '' );
			$action = (string) ( $args['action'] ?? '' );
			if ( ! empty( $args['tool_name'] ) ) { $meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_tool( (string) $args['tool_name'] ) : PRSTUDIO_UC_Contract::by_tool( (string) $args['tool_name'] ); }
			if ( ! $meta && '' !== $route && '' !== $action ) { $meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_action( $route, $action ) : PRSTUDIO_UC_Contract::by_action( $route, $action ); }
			if ( ! $meta && '' !== $route && '' !== $action ) { $meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_tool( $tool_name ) : PRSTUDIO_UC_Contract::by_tool( $tool_name ); $action_contract_match = false; }
			if ( ! $meta && '' === $route && '' === $action ) { $meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_tool( $tool_name ) : PRSTUDIO_UC_Contract::by_tool( $tool_name ); }
		} elseif ( 0 === strpos( $tool_name, 'idealmarket_' ) && isset( $args['action'] ) ) {
			$slug = substr( $tool_name, strlen( 'idealmarket_' ) );
			$route = '/' . str_replace( '_', '-', $slug );
			$action = (string) $args['action'];
			$meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_action( $route, $action ) : PRSTUDIO_UC_Contract::by_action( $route, $action );
			if ( ! $meta ) { $meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_tool( $tool_name ) : PRSTUDIO_UC_Contract::by_tool( $tool_name ); $action_contract_match = false; }
		} else {
			$meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_tool( $tool_name ) : PRSTUDIO_UC_Contract::by_tool( $tool_name );
		}

		if ( ! is_array( $meta ) ) {
			return new WP_Error(
				'prstudio_contract_tool_missing',
				'Il tool è esposto ma non è presente nel contratto condiviso 0.3.3.',
				array( 'status'=>500, 'tool_name'=>$tool_name, 'contract_hash'=>PRSTUDIO_UC_Contract::hash() )
			);
		}
		$domain_id = $action_contract_match ? sanitize_key( (string) ( $meta['domain'] ?? 'operations' ) ) : PRSTUDIO_UC_Contract::domain_for_route( $route );
		$domains = self::domains();
		if ( ! isset( $domains[ $domain_id ] ) ) {
			return new WP_Error( 'prstudio_contract_domain_missing', 'Dominio del contratto non disponibile.', array( 'status'=>500, 'tool_name'=>$tool_name, 'domain'=>$domain_id ) );
		}
		$domain = $domains[ $domain_id ];
		return array(
			'orchestrated' => true,
			'orchestrator_version' => self::VERSION,
			'contract_version' => PRSTUDIO_UC_Contract::protocol_version(),
			'contract_hash' => PRSTUDIO_UC_Contract::hash(),
			'tool_name' => $tool_name,
			'route' => (string) ( $meta['route'] ?? $route ),
			'action' => (string) ( $meta['action'] ?? $action ?: $tool_name ),
			'domain' => $domain_id,
			'domain_class' => $domain->class_name(),
			'executor' => (string) ( $meta['executor'] ?? 'wordpress' ),
			'strategy' => (string) ( $meta['strategy'] ?? 'wordpress_native' ),
			'read_only' => ! empty( $meta['read_only'] ), 'destructive' => ! empty( $meta['destructive'] ), 'idempotent' => ! empty( $meta['idempotent'] ),
		);
	}

	public static function governance_meta( array $governance ): array {
		return array( 'prstudio/orchestration' => $governance );
	}

	public static function resolve( array $args ): array {
		$started = function_exists( 'hrtime' ) ? hrtime( true ) : (int) round( microtime( true ) * 1000000000 );
		$objective = trim( (string) ( $args['objective'] ?? $args['query'] ?? '' ) );
		$arguments = is_array( $args['arguments'] ?? null ) ? $args['arguments'] : array();
		$requested_domain = sanitize_key( (string) ( $args['domain'] ?? '' ) );
		$include_schema = ! empty( $args['include_schema'] );
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 250 ) ) );
		$catalog = ( class_exists( 'PRSTUDIO_UC_Action_Index' ) && ! $include_schema ) ? array() : self::catalog();
		$domains = self::domains();

		if ( '' !== $requested_domain && isset( $domains[ $requested_domain ] ) ) {
			$domain = $domains[ $requested_domain ];
			$resolution_reason = 'explicit_domain';
		} else {
			$normalized = self::normalize( $objective );
			if ( preg_match( '/https?:\/\//i', $objective ) || preg_match( '/\b(browser|chrome|scheda|tab|screenshot|clicca|click|google trends|search console|merchant center|instagram)\b/u', $normalized ) ) {
				$winner = 'browser';
				$resolution_reason = 'browser_intent_fast_path';
			} elseif ( class_exists( 'PRSTUDIO_UC_Action_Index' ) ) {
				$winner = PRSTUDIO_UC_Action_Index::domain_for_query( $objective );
				$resolution_reason = 'precompiled_token_index';
			} else {
				$ranked = array();
				foreach ( $domains as $id => $candidate ) { $ranked[ $id ] = $candidate->score( $objective, $catalog ); }
				arsort( $ranked, SORT_NUMERIC );
				$winner = (string) array_key_first( $ranked );
				$resolution_reason = 'legacy_domain_scan';
			}
			$domain = $domains[ $winner ?: 'operations' ];
		}

		$actions = $domain->actions( $catalog, $objective, $limit, $include_schema );
		$workflow = $domain->workflow( $objective, $arguments, $catalog );
		$enterprise_plan = class_exists('PRSTUDIO_UC_Enterprise_Engine') ? PRSTUDIO_UC_Enterprise_Engine::plan($objective,$domain->id(),$arguments,$workflow) : array();
		$elapsed = ( ( function_exists( 'hrtime' ) ? hrtime( true ) : (int) round( microtime( true ) * 1000000000 ) ) - $started ) / 1000000;
		return array(
			'orchestrator_version' => self::VERSION,
			'contract' => PRSTUDIO_UC_Contract::status(),
			'knowledge' => class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::knowledge_snapshot() : array(),
			'objective' => $objective,
			'domain' => array( 'id' => $domain->id(), 'class' => $domain->class_name(), 'label' => $domain->label(), 'routes' => $domain->routes(), 'action_count' => count( $actions ) ),
			'workflow' => $workflow, 'enterprise_plan'=>$enterprise_plan, 'memory'=>class_exists('PRSTUDIO_UC_Memory')?PRSTUDIO_UC_Memory::snapshot():array(), 'context'=>class_exists('PRSTUDIO_UC_Memory')?PRSTUDIO_UC_Memory::context():array(),
			'available_actions' => $actions,
			'resolution' => array( 'reason' => $resolution_reason, 'milliseconds' => round( $elapsed, 3 ), 'target_ms' => 10.0, 'passed' => $elapsed <= 10.0 ),
			'next_call' => array( 'tool' => 'prstudio_orchestrator_execute', 'arguments' => array( 'objective' => $objective, 'domain' => $domain->id(), 'arguments' => $arguments ) ),
			'instruction' => 'Il catalogo è già indicizzato: usa il tool o workflow restituito senza una seconda ricerca globale.',
		);
	}

	public static function domain_actions( array $args ): array {
		$domain_id = sanitize_key( (string) ( $args['domain'] ?? '' ) );
		$domains = self::domains();
		if ( ! isset( $domains[ $domain_id ] ) ) {
			return array( 'error' => 'unknown_domain', 'domains' => array_keys( $domains ) );
		}
		$include_schema = ! empty( $args['include_schema'] );
		$catalog = ( class_exists( 'PRSTUDIO_UC_Action_Index' ) && ! $include_schema ) ? array() : self::catalog();
		$items = $domains[ $domain_id ]->actions( $catalog, (string) ( $args['query'] ?? '' ), (int) ( $args['limit'] ?? 250 ), $include_schema );
		return array( 'domain' => $domain_id, 'class' => $domains[ $domain_id ]->class_name(), 'contract_hash' => PRSTUDIO_UC_Contract::hash(), 'hot_index_hash' => class_exists( 'PRSTUDIO_UC_Action_Index' ) ? (string) ( PRSTUDIO_UC_Action_Index::knowledge_snapshot()['hot_index_hash'] ?? '' ) : '', 'count' => count( $items ), 'actions' => $items );
	}

	public static function execute( array $args ) {
		$resolved = self::resolve( $args );
		$workflow = (array) ( $resolved['workflow'] ?? array() );
		if ( ! $workflow ) { return new WP_Error( 'prstudio_orchestrator_no_action', 'Nessuna azione compatibile trovata.', array( 'resolution' => $resolved ) ); }

		$domain_value = $resolved['domain'] ?? '';
		$domain_id = is_array( $domain_value ) ? (string) ( $domain_value['id'] ?? '' ) : (string) $domain_value;
		$objective = (string) ( $args['objective'] ?? '' );
		$job_uuid = '';
		$job_enabled = class_exists( 'PRSTUDIO_UC_Job_Engine' ) && class_exists( 'PRSTUDIO_UC_Store' );
		if ( $job_enabled ) {
			$job = PRSTUDIO_UC_Job_Engine::begin_workflow( $objective, $domain_id, $args, $workflow );
			if ( ! empty( $job['idempotent_replay'] ) ) {
				$status = (string) ( $job['status'] ?? '' );
				if ( 'completed' === $status && is_array( $job['result'] ?? null ) ) {
					$replay = $job['result'];
					$replay['job_id'] = (string) $job['job_uuid'];
					$replay['job_state'] = 'completed';
					$replay['idempotent_replay'] = true;
					return $replay;
				}
				return new WP_Error(
					'prstudio_orchestrator_idempotent_replay',
					'Questa richiesta è già stata registrata e non verrà rieseguita automaticamente.',
					array( 'status'=>409, 'job_id'=>(string)($job['job_uuid'] ?? ''), 'job_state'=>$status, 'checkpoint'=>$job['checkpoint'] ?? array() )
				);
			}
			$job_uuid = (string) ( $job['job_uuid'] ?? '' );
			if ( '' === $job_uuid || ! PRSTUDIO_UC_Store::mark_job_running( $job_uuid ) ) {
				return new WP_Error( 'prstudio_orchestrator_job_start_failed', 'Impossibile inizializzare il workflow persistente.', array( 'status'=>500 ) );
			}
		}

		$mission=class_exists('PRSTUDIO_UC_Enterprise_Engine')?PRSTUDIO_UC_Enterprise_Engine::plan($objective,$domain_id,is_array($args['arguments']??null)?$args['arguments']:array(),$workflow):array(); $mission_id=(string)($mission['mission_id']??$job_uuid); if(class_exists('PRSTUDIO_UC_Memory')){PRSTUDIO_UC_Memory::mission($mission_id,['status'=>'running','objective'=>$objective,'job_id'=>$job_uuid,'plan'=>$mission]);PRSTUDIO_UC_Memory::movement('mission.started',['resource'=>$objective,'outcome'=>'running','method'=>'goal_planner'],$job_uuid);}
		$results = array();
		$last_tab_id = null;
		$aggregate_status = 'completed';
		$aggregate_verified = true;

		try {
			foreach ( $workflow as $index => $step ) {
				$arguments = is_array( $step['arguments'] ?? null ) ? $step['arguments'] : array();
				if ( ! empty( $arguments['tab_from_previous'] ) ) {
					unset( $arguments['tab_from_previous'] );
					if ( $last_tab_id ) { $arguments['tab_id'] = $last_tab_id; }
				}
				if ( '/frontend-manage' === (string) ( $step['route'] ?? '' ) ) {
					$arguments['browser_target'] = (string) ( $args['browser_target'] ?? 'live' );
					$arguments['sync_wait_seconds'] = max( 1, min( 20, (int) ( $args['sync_wait_seconds'] ?? 5 ) ) );
					if ( ! empty( $args['device_id'] ) ) { $arguments['device_id'] = sanitize_text_field( (string) $args['device_id'] ); }
				}
				$payload = array_merge( $arguments, array( 'action' => (string) $step['action'] ) );
				$governance = self::govern_tool_call( (string) $step['tool_name'], $payload );
				if ( is_wp_error( $governance ) ) {
					if ( $job_enabled ) { PRSTUDIO_UC_Job_Engine::fail_workflow( $job_uuid, array( 'code'=>$governance->get_error_code(), 'message'=>$governance->get_error_message(), 'step'=>$index ) ); }
					if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('step.failed',['resource'=>(string)($step['action']??''),'outcome'=>$governance->get_error_code(),'method'=>'governance'],$job_uuid);
					return $governance;
				}
				$risk_telemetry=class_exists('PRSTUDIO_UC_Enterprise_Engine')?PRSTUDIO_UC_Enterprise_Engine::risk_telemetry($governance,$payload):array(); $impact=class_exists('PRSTUDIO_UC_Enterprise_Engine')?PRSTUDIO_UC_Enterprise_Engine::impact($governance,$payload):array(); $confidence=class_exists('PRSTUDIO_UC_Enterprise_Engine')?PRSTUDIO_UC_Enterprise_Engine::confidence($governance,$payload):array(); $explanation=class_exists('PRSTUDIO_UC_Enterprise_Engine')?PRSTUDIO_UC_Enterprise_Engine::explain($governance,$risk_telemetry,$confidence):array(); $budget=class_exists('PRSTUDIO_UC_Safety_Runtime')?PRSTUDIO_UC_Safety_Runtime::budget($impact,$risk_telemetry):array(); $budget_start=class_exists('PRSTUDIO_UC_Safety_Runtime')?PRSTUDIO_UC_Safety_Runtime::begin():array(); $span=class_exists('PRSTUDIO_UC_Observability')?PRSTUDIO_UC_Observability::start('orchestrator.step',['tool'=>$step['tool_name'],'job_id'=>$job_uuid]):array(); if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('step.started',['resource'=>(string)$step['action'],'executor'=>$governance['executor']??'','strategy'=>$governance['strategy']??'','risk_score'=>$risk_telemetry['risk_score']??0,'confidence'=>$confidence['score']??0],$job_uuid);
				$result = PRSTUDIO_Agency::dispatch( (string) $step['tool_name'], $payload );
				if ( is_wp_error( $result ) ) {
					if ( $job_enabled ) { PRSTUDIO_UC_Job_Engine::fail_workflow( $job_uuid, array( 'code'=>$result->get_error_code(), 'message'=>$result->get_error_message(), 'step'=>$index ) ); }
					if(class_exists('PRSTUDIO_UC_Observability'))PRSTUDIO_UC_Observability::finish($span,'error',['error'=>$result->get_error_code()]);
					if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('step.failed',['resource'=>(string)$step['action'],'outcome'=>$result->get_error_code(),'method'=>$governance['strategy']??''],$job_uuid);
					return $result;
				}
				$budget_state=class_exists('PRSTUDIO_UC_Safety_Runtime')?PRSTUDIO_UC_Safety_Runtime::check($budget_start,$budget):array('ok'=>true); if(empty($budget_state['ok'])){$budget_state['degraded']=true;$budget_state['blocking']=false;} $trace=class_exists('PRSTUDIO_UC_Observability')?PRSTUDIO_UC_Observability::finish($span,!empty($budget_state['ok'])?'ok':'budget_exceeded_nonblocking'):array(); $mem=class_exists('PRSTUDIO_UC_Memory')?PRSTUDIO_UC_Memory::remember_call($governance,$payload,$result,$job_uuid):array(); if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('step.completed',['resource'=>(string)$step['action'],'outcome'=>!empty($budget_state['ok'])?'completed':'budget_exceeded_nonblocking','method'=>$governance['strategy']??'','duration_ms'=>$trace['duration_ms']??0],$job_uuid); $results[] = array( 'step' => $index, 'action' => $step['action'], 'governance' => $governance, 'risk_telemetry'=>$risk_telemetry, 'impact'=>$impact, 'confidence'=>$confidence, 'explanation'=>$explanation,  'budget'=>$budget_state, 'trace'=>$trace, 'memory'=>$mem, 'result' => $result ); if(empty($budget_state['ok'])){$aggregate_verified=false;if('completed'===$aggregate_status)$aggregate_status='degraded';}
				if ( $job_enabled ) { PRSTUDIO_UC_Job_Engine::checkpoint_workflow( $job_uuid, (int) $index, array( 'action'=>$step['action'], 'result'=>$result ) ); }
				$last_tab_id = self::find_tab_id( $result ) ?: $last_tab_id;
				$outcome = is_array( $result ) && is_array( $result['_control_outcome'] ?? null ) ? $result['_control_outcome'] : array();
				if ( $outcome && true !== ( $outcome['verified'] ?? false ) ) {
					$aggregate_verified = false;
					if ( 'completed' === $aggregate_status ) { $aggregate_status = 'degraded'; }
				}
			}
		} catch ( Throwable $error ) {
			if ( $job_enabled ) { PRSTUDIO_UC_Job_Engine::fail_workflow( $job_uuid, array( 'code'=>'unexpected_runtime_error', 'message'=>$error->getMessage() ) ); }
			if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('mission.failed',['resource'=>$objective,'outcome'=>'unexpected_runtime_error','method'=>'exception_boundary'],$job_uuid);
			throw $error;
		}

		$response = array(
			'status' => $aggregate_status,
			'domain' => $resolved['domain'],
			'workflow' => $workflow,
			'results' => $results,
			'last_tab_id' => $last_tab_id,
			'verified' => $aggregate_verified, 'mission_id'=>$mission_id, 'enterprise_version'=>'2.0.0',
		);
		if ( $job_enabled ) {
			$response['job_id'] = $job_uuid;
			$response['job_state'] = $aggregate_verified && 'completed' === $aggregate_status ? 'completed' : 'completed_degraded';
			PRSTUDIO_UC_Job_Engine::finish_workflow( $job_uuid, $response, $aggregate_verified && 'completed' === $aggregate_status );
		}
		if(class_exists('PRSTUDIO_UC_Memory')){PRSTUDIO_UC_Memory::mission($mission_id,['status'=>$response['job_state']??$aggregate_status,'objective'=>$objective,'job_id'=>$job_uuid,'verified'=>$aggregate_verified,'result_hash'=>PRSTUDIO_UC_Memory::fingerprint($response)]);PRSTUDIO_UC_Memory::movement('mission.finished',['resource'=>$objective,'outcome'=>$response['job_state']??$aggregate_status,'method'=>'goal_planner','verified'=>$aggregate_verified],$job_uuid);}
		if(class_exists('PRSTUDIO_UC_Observability')&&$job_uuid)$response['replay']=PRSTUDIO_UC_Observability::replay($job_uuid,$args,$response);
		return $response;
	}

	private static function find_tab_id( $value ): ?int {
		if ( ! is_array( $value ) ) { return null; }
		foreach ( array( 'tabId', 'tab_id' ) as $key ) { if ( isset( $value[ $key ] ) && (int) $value[ $key ] > 0 ) { return (int) $value[ $key ]; } }
		foreach ( array( 'result', 'output', 'checkpoint' ) as $key ) { if ( isset( $value[ $key ] ) ) { $found = self::find_tab_id( $value[ $key ] ); if ( $found ) { return $found; } } }
		foreach ( $value as $child ) { if ( is_array( $child ) ) { $found = self::find_tab_id( $child ); if ( $found ) { return $found; } } }
		return null;
	}

	public static function tool_definitions(): array {
		$security = array( array( 'type' => 'oauth2', 'scopes' => array( 'wp_ai_bridge.read', 'wp_ai_bridge.write' ) ) );
		$make = static function ( string $name, string $title, string $description, array $schema, bool $read_only ) use ( $security ): array {
			return array( 'name'=>$name, 'title'=>$title, 'description'=>$description, 'inputSchema'=>$schema, 'outputSchema'=>array('type'=>'object','additionalProperties'=>true), 'securitySchemes'=>$security, '_meta'=>array('securitySchemes'=>$security,'ui'=>array('visibility'=>array('model','app'))), 'annotations'=>array('readOnlyHint'=>$read_only,'destructiveHint'=>false,'idempotentHint'=>$read_only,'openWorldHint'=>false) );
		};
		$object = static fn( array $properties, array $required = array() ) => array_filter( array( 'type'=>'object','properties'=>$properties ?: new stdClass(),'required'=>$required ?: null,'additionalProperties'=>false ), static fn( $v ) => null !== $v );
		return array(
			$make( 'prstudio_orchestrator_resolve', 'PR STUDIO Orchestratore', 'STRUMENTO PRIMARIO. Descrivi cosa devi fare: seleziona una delle 10 classi, restituisce il workflow esatto e tutte le azioni disponibili nel dominio.', $object( array( 'objective'=>array('type'=>'string'), 'domain'=>array('type'=>'string','enum'=>array_keys(self::domains())), 'arguments'=>array('type'=>'object','additionalProperties'=>true), 'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>500,'default'=>250), 'include_schema'=>array('type'=>'boolean','default'=>false) ), array('objective') ), true ),
			$make( 'prstudio_orchestrator_domain_actions', 'PR STUDIO Azioni classe', 'Elenca e filtra tutte le azioni della sottoclasse indicata, con route, tool, rischio, parametri e schema opzionale.', $object( array( 'domain'=>array('type'=>'string','enum'=>array_keys(self::domains())), 'query'=>array('type'=>'string'), 'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>500,'default'=>250), 'include_schema'=>array('type'=>'boolean','default'=>false) ), array('domain') ), true ),
			$make( 'prstudio_orchestrator_execute', 'PR STUDIO Esegui workflow', 'Risolve ed esegue il workflow nello stesso turno. Per il dominio Browser usa sempre PR STUDIO Browser Agent live salvo override esplicito.', $object( array( 'objective'=>array('type'=>'string'), 'domain'=>array('type'=>'string','enum'=>array_keys(self::domains())), 'arguments'=>array('type'=>'object','additionalProperties'=>true), 'browser_target'=>array('type'=>'string','enum'=>array('live','personal_chrome','chrome_extension','lab'),'default'=>'live'), 'device_id'=>array('type'=>'string'), 'sync_wait_seconds'=>array('type'=>'integer','minimum'=>1,'maximum'=>20,'default'=>5) ), array('objective') ), false ),
		);
	}
}
