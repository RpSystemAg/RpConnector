<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** SQL-backed, bounded agency worker. The option-array legacy Agency stays off. */
final class PRSTUDIO_UC_Agency_Runtime {
	public const VERSION='1.0.0';
	public const CRON_HOOK='prstudio_uc_agency_worker_tick';
	private const AS_HOOK='prstudio_uc_agency_action_scheduler_tick';
	private const FAST_HOOK='prstudio_uc_agency_fast_tick';
	private const CRON_INTERVAL='prstudio_uc_every_minute';
	private const GROUP='prstudio-unified-control';
	private const SCHEDULER_TOPOLOGY_OPTION='prstudio_uc_scheduler_topology';
	private const SCHEDULER_TOPOLOGY_VERSION='1.0.0-single-runner-v1';
	private static bool $registered=false;

	private static function uuid(): string {
		if(function_exists('wp_generate_uuid4'))return wp_generate_uuid4();
		$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20,12);
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules[self::CRON_INTERVAL]=array('interval'=>60,'display'=>'PR STUDIO durable worker every minute');return $schedules;
	}

	public static function init(): void {
		if(self::$registered)return;self::$registered=true;
		if(function_exists('add_filter'))add_filter('cron_schedules',array(__CLASS__,'cron_schedules'));
		if(function_exists('add_action')){
			add_action(self::CRON_HOOK,array(__CLASS__,'cron_tick'));
			add_action(self::AS_HOOK,array(__CLASS__,'cron_tick'));
			add_action(self::FAST_HOOK,array(__CLASS__,'fast_tick'));
			// Action Scheduler 3.9.3+ provides a dedicated periodic repair hook.
			// Use it instead of probing its store on every admin/cron bootstrap.
			add_action('action_scheduler_ensure_recurring_actions',array(__CLASS__,'ensure_schedulers'));
			add_action('init',array(__CLASS__,'ensure_schedulers_on_init'),20);
		}
		self::register_cli();
	}

	public static function activate(): void { self::ensure_schedulers(); self::ensure_default_schedule(); }
	public static function deactivate(): void {
		if(function_exists('wp_clear_scheduled_hook')){wp_clear_scheduled_hook(self::CRON_HOOK);wp_clear_scheduled_hook(self::FAST_HOOK);}
		elseif(function_exists('wp_next_scheduled')&&function_exists('wp_unschedule_event')){$ts=wp_next_scheduled(self::CRON_HOOK);if($ts)wp_unschedule_event($ts,self::CRON_HOOK);}
		if(function_exists('as_unschedule_all_actions')){as_unschedule_all_actions(self::AS_HOOK,array(),self::GROUP);as_unschedule_all_actions(self::FAST_HOOK,array(),self::GROUP);}
		if(function_exists('delete_option'))delete_option(self::SCHEDULER_TOPOLOGY_OPTION);
	}

	private static function scheduler_mode(): string {
		return function_exists('as_schedule_recurring_action')&&function_exists('as_has_scheduled_action')?'action_scheduler':'wp_cron';
	}

	private static function reconcile_scheduler_topology(string $mode): void {
		$target=self::SCHEDULER_TOPOLOGY_VERSION.'|'.$mode;
		$current=function_exists('get_option')?(string)get_option(self::SCHEDULER_TOPOLOGY_OPTION,''):'';
		if($current===$target)return;
		if(function_exists('wp_clear_scheduled_hook'))wp_clear_scheduled_hook(self::CRON_HOOK);
		if(function_exists('as_unschedule_all_actions'))as_unschedule_all_actions(self::AS_HOOK,array(),self::GROUP);
		if(function_exists('update_option'))update_option(self::SCHEDULER_TOPOLOGY_OPTION,$target,false);
	}

	public static function scheduler_state(): array {
		$mode=self::scheduler_mode();
		$wp_next=function_exists('wp_next_scheduled')?wp_next_scheduled(self::CRON_HOOK):false;
		$as_next=function_exists('as_next_scheduled_action')?as_next_scheduled_action(self::AS_HOOK,array(),self::GROUP):false;
		$next='action_scheduler'===$mode?$as_next:$wp_next;
		return array('mode'=>$mode,'wp_cron_next'=>$wp_next,'action_scheduler_available'=>function_exists('as_schedule_recurring_action'),'action_scheduler_next'=>$as_next,'scheduled'=>(bool)$next,'next_gmt'=>$next?gmdate('c',(int)$next):'','topology'=>(string)(function_exists('get_option')?get_option(self::SCHEDULER_TOPOLOGY_OPTION,''):''));
	}

	public static function ensure_schedulers_on_init(): void {
		$mode=self::scheduler_mode();
		$target=self::SCHEDULER_TOPOLOGY_VERSION.'|'.$mode;
		$current=function_exists('get_option')?(string)get_option(self::SCHEDULER_TOPOLOGY_OPTION,''):'';
		if($current!==$target){self::ensure_schedulers();return;}
		// Older Action Scheduler versions do not fire the ensure-recurring hook.
		if('action_scheduler'===$mode&&function_exists('as_supports')&&as_supports('ensure_recurring_actions_hook'))return;
		if('action_scheduler'===$mode){if(!as_has_scheduled_action(self::AS_HOOK,array(),self::GROUP))self::ensure_schedulers();return;}
		if(function_exists('wp_next_scheduled')&&!wp_next_scheduled(self::CRON_HOOK))self::ensure_schedulers();
	}

	public static function ensure_schedulers(): void {
		$mode=self::scheduler_mode();self::reconcile_scheduler_topology($mode);
		if('action_scheduler'===$mode){
			if(function_exists('wp_next_scheduled')&&wp_next_scheduled(self::CRON_HOOK)&&function_exists('wp_clear_scheduled_hook'))wp_clear_scheduled_hook(self::CRON_HOOK);
			if(!as_has_scheduled_action(self::AS_HOOK,array(),self::GROUP))as_schedule_recurring_action(time()+60,60,self::AS_HOOK,array(),self::GROUP,true);
		}else{
			if(function_exists('wp_next_scheduled')&&function_exists('wp_schedule_event')&&!wp_next_scheduled(self::CRON_HOOK))wp_schedule_event(time()+60,self::CRON_INTERVAL,self::CRON_HOOK);
		}
		self::ensure_default_schedule();
	}

	private static function ensure_default_schedule(): void {
		if(!PRSTUDIO_UC_Store::schema_ready())return;$hash=hash('sha256','prstudio-default-site-guardian');$id=substr($hash,0,8).'-'.substr($hash,8,4).'-4'.substr($hash,13,3).'-a'.substr($hash,17,3).'-'.substr($hash,20,12);
		if(!PRSTUDIO_UC_Store::get_schedule($id))PRSTUDIO_UC_Store::upsert_schedule('site_guardian','Periodic bounded site guardian',array('scope'=>array('health','queue','content'),'limit'=>100),HOUR_IN_SECONDS,gmdate('Y-m-d H:i:s',time()+300),$id);
	}

	private static function register_cli(): void {
		if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI')&&method_exists('WP_CLI','add_command')){
			WP_CLI::add_command('prstudio agency run',array(__CLASS__,'cli_run'));
			WP_CLI::add_command('prstudio agency status',array(__CLASS__,'cli_status'));
		}
	}

	public static function cli_run( array $args, array $assoc_args ): void {
		PRSTUDIO_UC_Site_Sentinel::record_external_heartbeat('wp_cli');if(class_exists('PRSTUDIO_UC_Serp_Watch')){try{PRSTUDIO_UC_Serp_Watch::tick(5);}catch(Throwable $ignored){}}if(class_exists('PRSTUDIO_UC_Procedural_Skills')){try{PRSTUDIO_UC_Procedural_Skills::curate(array('apply'=>true));}catch(Throwable $ignored){}}$limit=max(1,min(100,(int)($assoc_args['limit']??20)));$job=(string)($assoc_args['job']??'');$result=''!==$job?self::run_one($job,'wp-cli',30.0):self::run_batch($limit,'wp-cli',30.0);
		if(class_exists('WP_CLI'))WP_CLI::log((string)wp_json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	}

	public static function cli_status( array $args, array $assoc_args ): void { if(class_exists('WP_CLI'))WP_CLI::log((string)wp_json_encode(self::status(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }

	public static function submit( string $playbook, array $context = array(), array $options = array() ): array {
		$playbook=sanitize_key($playbook);$plan=PRSTUDIO_UC_Playbook_Engine::build($playbook,$context);if(!$plan)return array('ok'=>false,'error'=>'playbook_unknown','playbook'=>$playbook);
		$mission_id=substr(sanitize_text_field((string)($options['mission_id']??'')),0,120);if(''===$mission_id)$mission_id='mission:'.self::uuid();
		$occurrence=substr(sanitize_text_field((string)($options['occurrence_key']??'')),0,190);if(''===$occurrence)$occurrence='manual:'.self::uuid();
		$owner=substr(sanitize_text_field((string)($options['owner_client_id']??'')),0,190);$idempotency=hash('sha256','agency|'.PRSTUDIO_UC_Memory::site_identity()['key'].'|'.$owner.'|'.$playbook.'|'.$occurrence);
		$checkpoint=array('playbook'=>$playbook,'playbook_version'=>PRSTUDIO_UC_Playbook_Engine::VERSION,'next_step'=>0,'results'=>array());
		$job=PRSTUDIO_UC_Store::create_job((string)($options['objective']??str_replace('_',' ',$playbook)),'agency',array('playbook'=>$playbook,'context'=>$context),$plan,$idempotency,(string)$plan['hash'],array('status'=>'READY','priority'=>(int)($options['priority']??100),'mission_id'=>$mission_id,'capability'=>'agency.playbook','occurrence_key'=>$occurrence,'max_attempts'=>(int)($options['max_attempts']??5),'backoff_seconds'=>(int)($options['backoff_seconds']??30),'checkpoint'=>$checkpoint,'request_id'=>(string)($options['request_id']??''),'owner_client_id'=>$owner));
		$accepted=!empty($job['job_uuid']);$started=null;
		$start_now=array_key_exists('start_now',$options)?(bool)$options['start_now']:true;
		if($accepted&&$start_now&&in_array((string)($job['status']??''),array('READY','INTERRUPTED'),true)){
			$started=self::run_one((string)$job['job_uuid'],'submit-fast');
			$job=PRSTUDIO_UC_Store::get_job((string)$job['job_uuid'])??$job;
			if(in_array((string)($job['status']??''),array('READY','INTERRUPTED'),true))self::schedule_fast_tick();
		}
		return array('ok'=>$accepted,'accepted'=>$accepted,'mission_id'=>$mission_id,'started_inline'=>is_array($started)&&!empty($started['claimed']),'start_result'=>$started,'job'=>$job);
	}

	private static function schedule_fast_tick(): void {
		if(function_exists('as_enqueue_async_action')){as_enqueue_async_action(self::FAST_HOOK,array(),self::GROUP,true);return;}
		if(function_exists('wp_schedule_single_event'))wp_schedule_single_event(time()+1,self::FAST_HOOK,array(),true);
	}

	private static function error_payload( $error ): array {
		if(is_wp_error($error)){$data=$error->get_error_data();return array('code'=>(string)$error->get_error_code(),'message'=>(string)$error->get_error_message(),'retryable'=>is_array($data)?(bool)($data['retryable']??((int)($data['status']??500)>=500)):true,'class'=>'playbook_step');}
		return array('code'=>'playbook_step_failed','message'=>'Playbook step failed safely.','retryable'=>true,'class'=>'playbook_step');
	}

	private static function execute_step( array $step, array $job ) {
		$handler=(string)($step['handler']??'');$args=is_array($step['arguments']??null)?$step['arguments']:array();
		switch($handler){
			case 'sentinel.scan':return PRSTUDIO_UC_Site_Sentinel::scan($args);
			case 'twin.sync':return PRSTUDIO_UC_Operational_Twin::sync($args);
			case 'social.insights':return PRSTUDIO_UC_Social_Intelligence::insights($args);
			case 'opportunity.rank':return PRSTUDIO_UC_Opportunity_Engine::rank($args);
			case 'browser.action':
				$action=sanitize_key((string)($args['action']??''));$browser=is_array($args['arguments']??null)?$args['arguments']:array();$browser['browser_target']='live';$browser['sync_wait_seconds']=0;$browser['_prstudio_job_uuid']=(string)$job['job_uuid'];
				return PRSTUDIO_UC_Bridge::dispatch(null,$browser,array('action'=>$action));
		}
		return new WP_Error('playbook_handler_unknown','Playbook handler is unavailable.',array('status'=>500,'retryable'=>false,'handler'=>$handler));
	}

	public static function run_one( string $job_uuid = '', string $worker_source = 'runtime', float $budget_seconds = 5.0 ): array {
		$worker=$worker_source.':'.substr(self::uuid(),0,12);$job=''!==$job_uuid?PRSTUDIO_UC_Store::claim_job($job_uuid,$worker):PRSTUDIO_UC_Store::claim_next_job($worker);if(!$job)return array('ok'=>true,'claimed'=>false);
		$lease=(string)($job['lease_token']??'');$started=microtime(true);$processed=0;$budget_seconds=max(0.25,min(30.0,$budget_seconds));
		try{
			$plan=is_array($job['plan']??null)?$job['plan']:array();$steps=is_array($plan['steps']??null)?$plan['steps']:array();$checkpoint=(array)($job['checkpoint']??array());$index=max((int)($job['step_index']??0),(int)($checkpoint['next_step']??0));
			while($index<count($steps)&&(microtime(true)-$started)<$budget_seconds){
				$step=$steps[$index];$step_id=(string)($step['id']??('step_'.$index));
				if(isset($checkpoint['browser_result'])&&(int)($checkpoint['browser_step_index']??-1)===$index){$checkpoint['results'][$step_id]=$checkpoint['browser_result'];unset($checkpoint['browser_result'],$checkpoint['browser_error'],$checkpoint['browser_task_id'],$checkpoint['browser_step_index']);$checkpoint['next_step']=$index+1;$saved=PRSTUDIO_UC_Store::checkpoint_leased_job((string)$job['job_uuid'],$lease,$index,$checkpoint,(int)floor((($index+1)/max(1,count($steps)))*90));if(!$saved)return array('ok'=>false,'claimed'=>true,'conflict'=>true,'job_id'=>$job['job_uuid']);$job=$saved;$index++;$processed++;continue;}
				$result=self::execute_step($step,$job);if(is_wp_error($result)){$error=self::error_payload($result);$updated=!empty($error['retryable'])?PRSTUDIO_UC_Store::retry_leased_job((string)$job['job_uuid'],$lease,$error):(PRSTUDIO_UC_Store::dead_letter_job((string)$job['job_uuid'],$error,'non_retryable',$lease)?PRSTUDIO_UC_Store::get_job((string)$job['job_uuid']):null);return array('ok'=>false,'claimed'=>true,'job'=>$updated,'error'=>$error);}
				$status=is_array($result)?strtolower((string)($result['status']??'')):'';$task_id=is_array($result)?(string)($result['task_id']??''):'';
				if(!empty($step['requires_browser'])){
					if(''===$task_id){$error=array('code'=>'browser_task_not_created','message'=>'The browser step was not durably queued.','retryable'=>true,'class'=>'browser_task');$updated=PRSTUDIO_UC_Store::retry_leased_job((string)$job['job_uuid'],$lease,$error);return array('ok'=>false,'claimed'=>true,'job'=>$updated,'error'=>$error);}
					if(in_array($status,array('failed','cancelled','expired'),true)){$payload=is_array($result['error']??null)?$result['error']:array();$retryable='cancelled'!==$status&&(bool)($payload['retryable']??true);$error=array_merge($payload,array('code'=>(string)($payload['code']??('browser_task_'.$status)),'message'=>(string)($payload['message']??('Browser task ended as '.$status.'.')),'retryable'=>$retryable,'class'=>'browser_task'));$updated=$retryable?PRSTUDIO_UC_Store::retry_leased_job((string)$job['job_uuid'],$lease,$error):(PRSTUDIO_UC_Store::dead_letter_job((string)$job['job_uuid'],$error,'browser_terminal',$lease)?PRSTUDIO_UC_Store::get_job((string)$job['job_uuid']):null);return array('ok'=>false,'claimed'=>true,'job'=>$updated,'error'=>$error);}
					if('completed'!==$status){$checkpoint['browser_task_id']=$task_id;$checkpoint['browser_step_index']=$index;$checkpoint['next_step']=$index;$waiting=PRSTUDIO_UC_Store::wait_leased_job((string)$job['job_uuid'],$lease,'WAITING_FOR_BROWSER',$checkpoint);return array('ok'=>true,'claimed'=>true,'waiting_for_browser'=>true,'job'=>$waiting,'task_id'=>$task_id);}
				}
				$checkpoint['results'][$step_id]=class_exists('PRSTUDIO_UC_Memory')?PRSTUDIO_UC_Memory::redact($result):$result;$checkpoint['next_step']=$index+1;$progress=(int)floor((($index+1)/max(1,count($steps)))*90);$saved=PRSTUDIO_UC_Store::checkpoint_leased_job((string)$job['job_uuid'],$lease,$index,$checkpoint,$progress);if(!$saved)return array('ok'=>false,'claimed'=>true,'conflict'=>true,'job_id'=>$job['job_uuid']);$job=$saved;$index++;$processed++;
			}
			if($index>=count($steps)){$verification=array('ok'=>true,'verifier'=>'agency_playbook_v10','plan_hash'=>(string)($plan['hash']??''),'verified_gmt'=>gmdate('c'));$done=PRSTUDIO_UC_Store::complete_leased_job((string)$job['job_uuid'],$lease,array('playbook'=>$checkpoint['playbook']??'','results'=>$checkpoint['results']??array()),$verification);return array('ok'=>true,'claimed'=>true,'completed'=>(bool)$done,'job'=>$done);}
			$released=PRSTUDIO_UC_Store::release_leased_job((string)$job['job_uuid'],$lease,$checkpoint,$index,(int)($job['progress']??0),0);return array('ok'=>true,'claimed'=>true,'bounded_yield'=>true,'processed_steps'=>$processed,'job'=>$released);
		}catch(Throwable $e){$error=array('code'=>'agency_worker_exception','message'=>'Agency worker failed safely.','exception_class'=>get_class($e),'retryable'=>true,'class'=>'worker_exception');$updated=PRSTUDIO_UC_Store::retry_leased_job((string)$job['job_uuid'],$lease,$error);return array('ok'=>false,'claimed'=>true,'job'=>$updated,'error'=>$error);}
	}

	private static function process_schedules( int $limit = 10 ): int {
		$count=0;foreach(PRSTUDIO_UC_Store::due_schedules($limit) as $schedule){$occurrence='schedule:'.$schedule['schedule_uuid'].':'.gmdate('YmdHis',strtotime((string)$schedule['next_run_gmt'].' UTC'));$submitted=self::submit((string)$schedule['playbook'],(array)$schedule['context'],array('objective'=>(string)$schedule['objective'],'occurrence_key'=>$occurrence,'start_now'=>false));if(!empty($submitted['accepted'])){PRSTUDIO_UC_Store::advance_schedule((string)$schedule['schedule_uuid'],(string)$schedule['next_run_gmt']);$count++;}}return $count;
	}

	public static function run_batch( int $limit = 5, string $worker_source = 'runtime', float $budget_seconds = 5.0 ): array {
		$limit=max(1,min(100,$limit));$budget_seconds=max(0.5,min(60.0,$budget_seconds));$started=microtime(true);$items=array();
		for($i=0;$i<$limit;$i++){
			$remaining=$budget_seconds-(microtime(true)-$started);if($remaining<=0.05)break;
			$item=self::run_one('',$worker_source,min(5.0,max(0.25,$remaining)));if(empty($item['claimed']))break;$items[]=$item;
		}
		return array('ok'=>true,'processed'=>count($items),'items'=>$items,'queue'=>PRSTUDIO_UC_Store::queue_stats(),'budget_seconds'=>$budget_seconds,'elapsed_ms'=>round((microtime(true)-$started)*1000,3));
	}

	public static function fast_tick(): void { if(!PRSTUDIO_UC_Store::schema_ready())return;self::run_batch(5,'fast-scheduler',4.0); }

	public static function cron_tick(): void { if(!PRSTUDIO_UC_Store::schema_ready())return;self::process_schedules(10);if(class_exists('PRSTUDIO_UC_Serp_Watch')){try{PRSTUDIO_UC_Serp_Watch::tick(1);}catch(Throwable $ignored){}}if(class_exists('PRSTUDIO_UC_Procedural_Skills')){try{PRSTUDIO_UC_Procedural_Skills::curate(array('apply'=>true));}catch(Throwable $ignored){}}self::run_batch(5,'scheduler',4.0); }

	public static function control( string $job_uuid, string $action, array $args = array() ) {
		$job=PRSTUDIO_UC_Store::get_job($job_uuid);if(!$job)return new WP_Error('agency_job_missing','Mission job not found.',array('status'=>404));$action=sanitize_key($action);$checkpoint=(array)($job['checkpoint']??array());
		if('cancel'===$action)return PRSTUDIO_UC_Store::cancel_job($job_uuid,(string)($args['reason']??'operator_cancel'));
		if('retry'===$action&&in_array((string)$job['status'],array('TECHNICAL_ERROR','INTERRUPTED'),true))return PRSTUDIO_UC_Store::set_job_state($job_uuid,'READY',array('available_gmt'=>gmdate('Y-m-d H:i:s'),'error'=>array()));
		return new WP_Error('agency_job_control_conflict','Mission cannot perform that transition.',array('status'=>409,'state'=>$job['status'],'action'=>$action));
	}

	public static function status(): array {
		return array('version'=>self::VERSION,'enabled'=>true,'legacy_agency_enabled'=>defined('PRSTUDIO_UC_ENABLE_LEGACY_MCP')?(bool)PRSTUDIO_UC_ENABLE_LEGACY_MCP:false,'store'=>PRSTUDIO_UC_Store::queue_stats(),'playbooks'=>PRSTUDIO_UC_Playbook_Engine::describe(),'scheduler'=>self::scheduler_state(),'h24'=>PRSTUDIO_UC_Site_Sentinel::status());
	}
}
