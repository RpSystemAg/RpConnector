<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Bounded site/queue watchdog. It reports; repair is explicit and fail-safe. */
final class PRSTUDIO_UC_Site_Sentinel {
	public const VERSION='1.0.0';
	private const STATE='site-sentinel';
	private const EXTERNAL_HEARTBEAT='prstudio_uc_external_runner_heartbeat';
	private const EXECUTION_HEARTBEAT='prstudio_uc_execution_heartbeat';

	public static function record_external_heartbeat( string $source = 'wp_cli' ): void {
		if(function_exists('update_option'))update_option(self::EXTERNAL_HEARTBEAT,array('source'=>sanitize_key($source),'gmt'=>gmdate('c'),'timestamp'=>time()),false);
		self::record_execution_heartbeat($source);
	}

	/**
	 * Record that the recurring worker ran, whichever lane drove it.
	 *
	 * Kept separate from the external-runner heartbeat because they answer
	 * different questions. The external one asks "is a true out-of-band runner
	 * configured", which is what an H24 guarantee needs. This one asks "did
	 * anything execute recently at all" -- and only that second answer being no
	 * is an outage. Conflating them made a site with a healthy Action Scheduler
	 * report as dead, which trains people to ignore the warning.
	 */
	public static function record_execution_heartbeat( string $lane ): void {
		if(!function_exists('update_option'))return;
		update_option(self::EXECUTION_HEARTBEAT,array('lane'=>sanitize_key($lane),'gmt'=>gmdate('c'),'timestamp'=>time()),false);
	}

	public static function status(): array {
		$heartbeat=function_exists('get_option')?get_option(self::EXTERNAL_HEARTBEAT,array()):array();$ts=is_array($heartbeat)?(int)($heartbeat['timestamp']??0):0;
		$execution=function_exists('get_option')?get_option(self::EXECUTION_HEARTBEAT,array()):array();$exec_ts=is_array($execution)?(int)($execution['timestamp']??0):0;
		$scheduler=class_exists('PRSTUDIO_UC_Agency_Runtime')?PRSTUDIO_UC_Agency_Runtime::scheduler_state():array('mode'=>'unknown','scheduled'=>false,'next_gmt'=>'');
		return array(
			'version'=>self::VERSION,
			'h24_external_runner_required'=>true,
			'wp_cron_is_fallback_only'=>true,
			'external_runner_command'=>'wp prstudio agency run --limit=20',
			'external_runner_fresh'=>$ts>time()-300,
			'external_runner_heartbeat'=>is_array($heartbeat)?$heartbeat:array(),
			// Whether anything is executing, as distinct from whether a true
			// out-of-band runner is configured. A site with a healthy Action
			// Scheduler is working, even without the external runner an H24
			// guarantee needs -- reporting both as one number said "dead" about a
			// system that was running.
			'execution_fresh'=>$exec_ts>time()-300,
			'execution_heartbeat'=>is_array($execution)?$execution:array(),
			'execution_lane'=>(string)($execution['lane']??''),
			'h24_guaranteed'=>$ts>time()-300,
			'h24_effective'=>$exec_ts>time()-300
				?'Recurring work is executing via the in-WordPress scheduler. That depends on traffic or system cron firing wp-cron, so it is not a wall-clock guarantee.'
				:'Nothing has executed recently. Configure the external runner or verify that WP-Cron/Action Scheduler is firing.',
			'scheduler_mode'=>(string)($scheduler['mode']??'unknown'),
			'cron_scheduled'=>(bool)($scheduler['scheduled']??false),
			'cron_next_gmt'=>(string)($scheduler['next_gmt']??''),
		);
	}

	private static function finding( string $code, string $severity, string $message, array $details = array() ): array {
		return array('code'=>sanitize_key($code),'severity'=>in_array($severity,array('info','warning','critical'),true)?$severity:'warning','message'=>$message,'details'=>$details);
	}

	private static function finding_map( array $findings ): array {
		$map=array();
		foreach($findings as $finding){
			if(!is_array($finding)||empty($finding['code'])){continue;}
			$map[(string)$finding['code']]=$finding;
		}
		ksort($map,SORT_STRING);
		return $map;
	}

	/** Signature intentionally excludes timestamps and volatile detail payloads. */
	private static function signature( array $findings ): string {
		$stable=array();
		foreach(self::finding_map($findings) as $code=>$finding){$stable[]=array('code'=>$code,'severity'=>(string)($finding['severity']??'warning'));}
		$json=function_exists('wp_json_encode')?wp_json_encode($stable):json_encode($stable);
		return hash('sha256',(string)$json);
	}

	public static function scan( array $args = array() ): array {
		$scope=array_values(array_unique(array_map('sanitize_key',(array)($args['scope']??array('health','queue','content')))));$findings=array();$recovery=array();
		if(in_array('health',$scope,true)){
			if(!PRSTUDIO_UC_Store::schema_ready())$findings[]=self::finding('schema_not_ready','critical','Durable schema v4 is not ready.',array('expected'=>PRSTUDIO_UC_Store::schema_version()));
			// Two different conditions, previously reported as one. Nothing
			// executing is an outage; no external runner while the in-WordPress
			// scheduler is firing is a known limitation of the deployment, and
			// raising it at the same severity as an outage taught everyone to
			// ignore the finding.
			$runner=self::status();
			if(empty($runner['execution_fresh'])){
				$findings[]=self::finding('recurring_execution_stalled','critical','No recurring work has executed recently: neither the external runner nor the in-WordPress scheduler is firing.',$runner);
			}elseif(empty($runner['external_runner_fresh'])){
				$findings[]=self::finding('external_runner_stale','info','Recurring work is executing via the in-WordPress scheduler. That depends on site traffic or system cron, so it is not a wall-clock H24 guarantee.',$runner);
			}
		}
		if(in_array('queue',$scope,true)&&PRSTUDIO_UC_Store::schema_ready()){
			$stats=PRSTUDIO_UC_Store::queue_stats();$running=(int)($stats['states']['RUNNING']??0);$dead=(int)($stats['dead_letters']??0);
			if($dead>0)$findings[]=self::finding('dead_letters_present','warning','One or more mission jobs require operator review.',array('count'=>$dead));
			// A device that heartbeats is not a device that is consuming work. Those
			// are separate paths, and when only the second broke the watchdog kept
			// reporting healthy while every browser task sat unclaimed. Queued with
			// attempt_count still zero is the exact signature of a dispatcher that
			// never picked the task up, as opposed to one that tried and failed.
			$browser_queue=is_array($stats['browser_tasks']??null)?$stats['browser_tasks']:array();
			$undispatched=(int)($browser_queue['undispatched']??0);
			if($undispatched>0){
				$devices_online=0;
				if(method_exists('PRSTUDIO_UC_Store','list_devices')){
					foreach(PRSTUDIO_UC_Store::list_devices() as $device){
						if('revoked'===(string)($device['status']??''))continue;
						$seen=strtotime((string)($device['last_seen_gmt']??''));
						if($seen>0&&(time()-$seen)<120)$devices_online++;
					}
				}
				$findings[]=self::finding(
					'browser_dispatcher_not_consuming',
					$devices_online>0?'critical':'warning',
					$devices_online>0
						?'Browser Agent reports online but is not claiming queued tasks; the dispatcher is not consuming the queue.'
						:'Browser tasks are queued with no agent online to claim them.',
					array(
						'undispatched'=>$undispatched,
						'devices_online'=>$devices_online,
						'threshold_seconds'=>(int)($browser_queue['undispatched_threshold_seconds']??120),
						'oldest_undispatched_gmt'=>(string)($browser_queue['oldest_undispatched_gmt']??''),
						'remedy'=>'Inspect with browser_status; clear or requeue individual tasks with browser_task_control.',
					)
				);
			}
			if($running>100)$findings[]=self::finding('running_queue_pressure','warning','Unusually high number of running jobs.',array('count'=>$running));
			if(!empty($args['repair'])){$recovery=array('jobs'=>PRSTUDIO_UC_Store::recover_stale_jobs(300),'tasks'=>PRSTUDIO_UC_Store::recover_stale_tasks());if(class_exists('PRSTUDIO_UC_Artifacts')){$recovery['expired_artifacts']=PRSTUDIO_UC_Artifacts::cleanup();}}
		}
		if(in_array('content',$scope,true)&&function_exists('get_posts')){
			$limit=max(1,min(500,(int)($args['limit']??100)));$pending=get_posts(array('post_status'=>array('future','pending'),'post_type'=>'any','numberposts'=>$limit,'fields'=>'ids','suppress_filters'=>false));
			if(count((array)$pending)>=$limit)$findings[]=self::finding('content_review_backlog','info','Content review/scheduling backlog reached the bounded scan limit.',array('limit'=>$limit));
		}
		$now=gmdate('c');$severity=array_count_values(array_map(static fn($row)=>(string)$row['severity'],$findings));
		$stored=class_exists('PRSTUDIO_UC_Agency_State')?PRSTUDIO_UC_Agency_State::read(self::STATE,array()):array();
		$previous_current=is_array($stored['current']??null)?$stored['current']:(isset($stored['findings'])?$stored:array());
		$previous_findings=self::finding_map((array)($previous_current['findings']??array()));$current_findings=self::finding_map($findings);
		$new_findings=array();$degraded_findings=array();$resolved_findings=array();$rank=array('info'=>1,'warning'=>2,'critical'=>3);
		foreach($current_findings as $code=>$finding){
			if(!isset($previous_findings[$code])){$new_findings[]=$finding;continue;}
			$before=(string)($previous_findings[$code]['severity']??'warning');$after=(string)($finding['severity']??'warning');
			if(($rank[$after]??2)>($rank[$before]??2)){$degraded_findings[]=array('before'=>$previous_findings[$code],'after'=>$finding);}
		}
		foreach($previous_findings as $code=>$finding){if(!isset($current_findings[$code]))$resolved_findings[]=$finding;}
		$signature=self::signature($findings);$previous_signature=(string)($stored['current_signature']??self::signature((array)($previous_current['findings']??array())));$changed=!hash_equals($previous_signature,$signature);
		$should_alert=!empty($new_findings)||!empty($degraded_findings);
		$result=array('ok'=>empty($severity['critical']),'version'=>self::VERSION,'scope'=>$scope,'findings'=>array_values($current_findings),'counts'=>array('total'=>count($findings),'critical'=>(int)($severity['critical']??0),'warning'=>(int)($severity['warning']??0),'info'=>(int)($severity['info']??0)),'recovery'=>$recovery,'changed'=>$changed,'new_findings'=>$new_findings,'degraded_findings'=>$degraded_findings,'resolved_findings'=>$resolved_findings,'alert_emitted'=>$should_alert&&function_exists('do_action'),'signature'=>$signature,'scanned_gmt'=>$now);
		if(class_exists('PRSTUDIO_UC_Agency_State'))PRSTUDIO_UC_Agency_State::mutate(self::STATE,array(),static function(array &$state)use($result,$signature,$previous_signature,$changed,$now):bool{
			$state=array('version'=>self::VERSION,'current'=>$result,'current_signature'=>$signature,'previous_signature'=>$previous_signature,'last_changed_gmt'=>$changed?$now:(string)($state['last_changed_gmt']??''));return true;
		});
		if($should_alert&&function_exists('do_action'))do_action('prstudio_uc_site_sentinel_alert',$result,$previous_current);
		return $result;
	}

	public static function snapshot(): array { return class_exists('PRSTUDIO_UC_Agency_State')?PRSTUDIO_UC_Agency_State::read(self::STATE,array('version'=>self::VERSION,'current'=>array('findings'=>array()))):array('version'=>self::VERSION,'current'=>array('findings'=>array())); }
}
