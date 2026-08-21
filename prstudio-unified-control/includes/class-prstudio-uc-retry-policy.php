<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Pure bounded retry policy for transient failures. */
final class PRSTUDIO_UC_Retry_Policy {
    public const VERSION = '1.1.0';
    public const DEFAULT_BASE_MS = 500;
    public const DEFAULT_FACTOR = 2.0;
    public const DEFAULT_CAP_MS = 30000;
    public const DEFAULT_MAX_ATTEMPTS = 5;

    private const TRANSIENT_CODES = array(
        'timeout','request_timeout','connection_timeout','connect_timeout','connection_refused','connection_reset','connection_failed',
        'http_502','http_503','http_504','http_429','http_500','lock_contention','lease_lost','queue_busy','worker_busy',
        'service_unavailable','temporary_unavailable','throttled','transient_failure','network_error','dns_error','stale_lease',
        'task_requeued','retryable','cdp_attach_timeout','cdp_targets_timeout','cdp_timeout','page_runtime_timeout','wait_url_timeout',
        'browser_task_expired','prstudio_browser_agent_offline','prstudio_browser_device_unavailable',
    );
    private const PERMANENT_CODES = array(
        'invalid_arguments','invalid_parameters','validation_error','unauthorized','forbidden','not_found','missing_required',
        'capability_not_found','tool_not_found','job_not_found','browser_task_not_found','permission_denied','method_not_allowed',
        'payload_too_large','unsupported_protocol','invalid_request','action_infeasible','context_leak_blocked','permanent_failure',
        'http_400','http_401','http_403','http_404','http_405','http_422','browser_effect_unverified','new_browser_context_not_created',
    );

    public static function schedule( int $attempt, array $options = array(), ?int $seed = null ): int {
        $attempt=max(1,$attempt);$base=max(1,(int)($options['base_ms']??self::DEFAULT_BASE_MS));$factor=max(1.0,(float)($options['factor']??self::DEFAULT_FACTOR));$cap=max($base,(int)($options['cap_ms']??self::DEFAULT_CAP_MS));$jitter=(string)($options['jitter']??'full');
        $scaled=(int)min($cap,$base*($factor**max(0,$attempt-1)));
        if('none'===$jitter)return min($cap,$scaled);
        if('equal'===$jitter)return self::random_int(min($scaled,$cap),$cap,$seed,$attempt);
        return self::random_int(0,min($scaled,$cap),$seed,$attempt);
    }
    private static function random_int( int $min,int $max,?int $seed,int $salt ): int {
        if($max<=$min)return $min;$state=null!==$seed?($seed&0x7fffffff):random_int(0,2147483647);$state=($state*1103515245+12345+$salt*2654435761)%2147483648;return $min+($state%($max-$min+1));
    }

    /**
     * A retryable flag can arrive both at the envelope and inside details/data.
     * Contradiction is resolved conservatively to false and is reported, rather
     * than allowing a top-level true to override details.retryable=false.
     */
    public static function classify( array $error ): array {
        $code=strtolower((string)($error['code']??''));
        $data=is_array($error['data']??null)?$error['data']:(is_array($error['details']??null)?$error['details']:array());
        $status=(int)($error['status']??$data['status']??0);
        $outer=array_key_exists('retryable',$error)&&is_bool($error['retryable'])?$error['retryable']:null;
        $inner=array_key_exists('retryable',$data)&&is_bool($data['retryable'])?$data['retryable']:null;
        if(null!==$outer&&null!==$inner&&$outer!==$inner){return array('transient'=>false,'code'=>$code,'reason'=>'conflicting_retryable_flags');}
        $explicit=null!==$outer?$outer:$inner;
        if(true===$explicit)return array('transient'=>true,'code'=>$code,'reason'=>'explicit_retryable_flag');
        if(false===$explicit)return array('transient'=>false,'code'=>$code,'reason'=>'explicit_non_retryable_flag');
        if(in_array($code,self::TRANSIENT_CODES,true))return array('transient'=>true,'code'=>$code,'reason'=>'known_transient_code');
        if(in_array($code,self::PERMANENT_CODES,true))return array('transient'=>false,'code'=>$code,'reason'=>'known_permanent_code');
        if($status>=500)return array('transient'=>true,'code'=>$code,'reason'=>'http_5xx');
        if($status>=400&&$status<500)return array('transient'=>false,'code'=>$code,'reason'=>'http_4xx');
        if(''!==$code)return array('transient'=>false,'code'=>$code,'reason'=>'unknown_code_conservative');
        return array('transient'=>false,'code'=>'','reason'=>'no_code_conservative');
    }
    public static function should_retry( int $attempt,int $max_attempts=self::DEFAULT_MAX_ATTEMPTS ): bool {return $attempt<max(1,$max_attempts);}
    public static function next_attempt( int $attempt,array $error,array $options=array(),?int $seed=null ): array {
        $classification=self::classify($error);$max=max(1,(int)($options['max_attempts']??self::DEFAULT_MAX_ATTEMPTS));
        if(!$classification['transient'])return array('retry'=>false,'attempt'=>$attempt,'delay_ms'=>0,'reason'=>'not_transient:'.$classification['reason']);
        if(!self::should_retry($attempt,$max))return array('retry'=>false,'attempt'=>$attempt,'delay_ms'=>0,'reason'=>'max_attempts_reached');
        $next=$attempt+1;return array('retry'=>true,'attempt'=>$next,'delay_ms'=>self::schedule($next,$options,$seed),'reason'=>'transient:'.$classification['reason']);
    }
}
