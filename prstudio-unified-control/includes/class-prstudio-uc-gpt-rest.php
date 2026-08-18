<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** REST facade dedicated to the seven stable Custom GPT Actions. */
final class PRSTUDIO_UC_GPT_REST {
    private const NS='prstudio-unified/v1';
    private const MAX_BODY=262144;

    public static function register_routes(): void {
        // Permission callbacks intentionally remain public here: authentication is
        // executed inside dispatch() so every failure uses the same GPT-safe envelope.
        register_rest_route(self::NS,'/openapi',array('methods'=>'GET','callback'=>array(__CLASS__,'openapi'),'permission_callback'=>'__return_true'));
        register_rest_route(self::NS,'/capabilities/search',array('methods'=>'POST','callback'=>static fn($r)=>self::dispatch($r,'capabilities',array(__CLASS__,'search_capabilities')),'permission_callback'=>'__return_true'));
        register_rest_route(self::NS,'/capabilities/describe',array('methods'=>'POST','callback'=>static fn($r)=>self::dispatch($r,'capabilities',array(__CLASS__,'describe_capability')),'permission_callback'=>'__return_true'));
        register_rest_route(self::NS,'/execute',array('methods'=>'POST','callback'=>static fn($r)=>self::dispatch($r,'execute',array(__CLASS__,'execute')),'permission_callback'=>'__return_true'));
        register_rest_route(self::NS,'/memory/search',array('methods'=>'POST','callback'=>static fn($r)=>self::dispatch($r,'memory',array(__CLASS__,'search_memory')),'permission_callback'=>'__return_true'));
        register_rest_route(self::NS,'/health',array('methods'=>'GET','callback'=>static fn($r)=>self::dispatch($r,'health',array(__CLASS__,'health')),'permission_callback'=>'__return_true'));
        register_rest_route(self::NS,'/jobs/(?P<job_id>[a-f0-9-]+)',array('methods'=>'GET','callback'=>static fn($r)=>self::dispatch($r,'jobs',array(__CLASS__,'job')),'permission_callback'=>'__return_true'));
        register_rest_route(self::NS,'/jobs/(?P<job_id>[a-f0-9-]+)/control',array('methods'=>'POST','callback'=>static fn($r)=>self::dispatch($r,'jobs',array(__CLASS__,'control_job')),'permission_callback'=>'__return_true'));
    }

    private static function param($request,string $name,$default='') {
        return is_object($request)&&method_exists($request,'get_param') ? ($request->get_param($name) ?? $default) : $default;
    }
    private static function ids($request): array {
        $rid=sanitize_text_field((string)self::param($request,'request_id',''));
        $rid=$rid?:wp_generate_uuid4();
        $trace=sanitize_text_field((string)self::param($request,'_prstudio_trace_id',''));
        $trace=$trace?:wp_generate_uuid4();
        if(is_object($request)&&method_exists($request,'set_param')){$request->set_param('_prstudio_trace_id',$trace);$request->set_param('_prstudio_request_id',$rid);}
        return array($rid,$trace);
    }
    private static function tls_ok(): bool {
        if(defined('PRSTUDIO_UC_TESTING')&&PRSTUDIO_UC_TESTING)return true;
        if(function_exists('is_ssl')&&is_ssl())return true;
        $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
        return in_array($host,array('localhost','127.0.0.1','::1'),true);
    }
    private static function payload_ok($request): bool {
        $len=(int)($_SERVER['CONTENT_LENGTH']??0); if($len>self::MAX_BODY)return false;
        return !is_object($request)||!method_exists($request,'get_body')||strlen((string)$request->get_body())<=self::MAX_BODY;
    }
    private static function authorize($request,string $scope) {
        if(!self::tls_ok())return new WP_Error('prstudio_actions_tls_required','TLS is required for GPT Actions.',array('status'=>403));
        if(!self::payload_ok($request))return new WP_Error('prstudio_actions_payload_too_large','Request payload exceeds the 256 KiB limit.',array('status'=>413));
        return PRSTUDIO_UC_GPT_Actions_Auth::permission($request,$scope);
    }
    /** PHP 8.0-compatible list-array detector; avoids PHP 8.1-only runtime APIs. */
    private static function is_list_array(array $value): bool {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected++) { return false; }
        }
        return true;
    }
    private static function json_body($request) {
        if(!is_object($request)||!method_exists($request,'get_body')){
            $v=is_object($request)&&method_exists($request,'get_json_params')?$request->get_json_params():array();
            return is_array($v)?$v:array();
        }
        $raw=(string)$request->get_body();
        if(''===trim($raw)){
            $v=method_exists($request,'get_json_params')?$request->get_json_params():array();
            return is_array($v)?$v:array();
        }
        try{$decoded=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){return new WP_Error('malformed_json','Request body is not valid JSON.',array('status'=>400));}
        if(!is_array($decoded)||self::is_list_array($decoded))return new WP_Error('malformed_payload','JSON request body must be an object.',array('status'=>400));
        return $decoded;
    }
    private static function response(array $data,$request,int $status=200): WP_REST_Response {
        [$rid,$trace]=self::ids($request);
        $body=array('ok'=>true)+$data;
        $body['request_id']=$rid; $body['trace_id']=$trace;
        $r=new WP_REST_Response(PRSTUDIO_UC_Memory::redact($body),$status);
        $r->header('Cache-Control','no-store, private');
        $r->header('Content-Type','application/json; charset=UTF-8');
        return $r;
    }
    private static function error($error,$request): WP_REST_Response {
        [$rid,$trace]=self::ids($request);
        if(is_wp_error($error)){
            $d=$error->get_error_data();$status=is_array($d)?(int)($d['status']??500):500;
            $retry=is_array($d)?(bool)($d['retryable']??false):false;
            $details=is_array($d)?(array)($d['details']??array_diff_key($d,array('status'=>1,'retryable'=>1))):array();
            $code=(string)$error->get_error_code();$message=(string)$error->get_error_message();
        } else {$status=500;$retry=false;$details=array();$code='internal_error';$message='Internal error.';}
        $body=array('ok'=>false,'error'=>array('code'=>$code,'message'=>$message,'retryable'=>$retry,'details'=>PRSTUDIO_UC_Memory::redact($details)),'request_id'=>$rid,'trace_id'=>$trace);
        $r=new WP_REST_Response($body,max(400,min(599,$status)));
        $r->header('Cache-Control','no-store, private');$r->header('Content-Type','application/json; charset=UTF-8');
        return $r;
    }
    private static function dispatch($request,string $scope,callable $callback): WP_REST_Response {
        try{
            $auth=self::authorize($request,$scope);
            if(is_wp_error($auth))return self::error($auth,$request);
            $out=$callback($request);
            return $out instanceof WP_REST_Response?$out:self::response(array('data'=>$out),$request);
        }catch(Throwable $e){
            return self::error(new WP_Error('internal_exception','Request failed safely.',array('status'=>500,'retryable'=>false,'details'=>array('exception_class'=>get_class($e)))),$request);
        }
    }

    public static function openapi($request=null): WP_REST_Response {
        try{$schema=PRSTUDIO_UC_OpenAPI::schema();$r=new WP_REST_Response($schema,200);$r->header('Cache-Control','public, max-age=300, must-revalidate');$r->header('Content-Type','application/json; charset=UTF-8');$r->header('ETag','"'.PRSTUDIO_UC_OpenAPI::hash().'"');return $r;}
        catch(Throwable $e){return self::error(new WP_Error('openapi_generation_failed','OpenAPI generation failed safely.',array('status'=>500,'details'=>array('exception_class'=>get_class($e)))),$request);}
    }
    public static function search_capabilities($r): WP_REST_Response {
        $payload=self::json_body($r);if(is_wp_error($payload))return self::error($payload,$r);
        $q=sanitize_text_field((string)($payload['query']??''));if(''===$q)return self::error(new WP_Error('query_required','query is required.',array('status'=>400)),$r);if(strlen($q)>500)return self::error(new WP_Error('query_too_long','query exceeds 500 characters.',array('status'=>400)),$r);
        $filters=array('domain'=>sanitize_key((string)($payload['domain']??'')),'limit'=>max(1,min(25,(int)($payload['limit']??12))),'include_legacy'=>true);
        return self::response(array('data'=>PRSTUDIO_UC_Capability_Registry::search($q,$filters)),$r);
    }
    public static function describe_capability($r): WP_REST_Response {
        $payload=self::json_body($r);if(is_wp_error($payload))return self::error($payload,$r);$id=sanitize_text_field((string)($payload['capability']??''));if(''===$id)return self::error(new WP_Error('capability_required','capability is required.',array('status'=>400)),$r);
        $d=PRSTUDIO_UC_Capability_Registry::describe($id);if(!$d)return self::error(new WP_Error('capability_not_found','Capability not found.',array('status'=>404)),$r);return self::response(array('data'=>$d),$r);
    }
    public static function execute($r): WP_REST_Response {
        $payload=self::json_body($r);if(is_wp_error($payload))return self::error($payload,$r);[$rid,$trace]=self::ids($r);$payload['request_id']=$payload['request_id']??$rid;$payload['trace_id']=$trace;
        $result=PRSTUDIO_UC_Site_Context::execute($payload['site']??'',static fn()=>PRSTUDIO_UC_Execution_Gateway::execute($payload));if(is_wp_error($result))return self::error($result,$r);$status=!empty($result['waiting_for_browser'])?202:200;return self::response(array('data'=>$result),$r,$status);
    }
    public static function search_memory($r): WP_REST_Response {
        $payload=self::json_body($r);if(is_wp_error($payload))return self::error($payload,$r);$q=sanitize_text_field((string)($payload['query']??''));if(''===$q)return self::error(new WP_Error('query_required','query is required.',array('status'=>400)),$r);$type=sanitize_key((string)($payload['type']??''));$limit=max(1,min(50,(int)($payload['limit']??20)));return self::response(array('data'=>PRSTUDIO_UC_Memory::search($q,$type,$limit),'site'=>PRSTUDIO_UC_Site_Context::current()),$r);
    }
    public static function health($r): WP_REST_Response {
        $h=PRSTUDIO_UC_Health::snapshot();$h['capability_registry']=array('counts'=>PRSTUDIO_UC_Capability_Registry::counts(),'hash'=>PRSTUDIO_UC_Capability_Registry::hash(),'consistency'=>PRSTUDIO_UC_Capability_Registry::consistency());
        $h['gpt_actions']=array(
            'surface_operation_ids'=>PRSTUDIO_UC_OpenAPI::operation_ids(),
            'openapi_preflight'=>PRSTUDIO_UC_OpenAPI::preflight(),
            'actions_keys'=>count(PRSTUDIO_UC_GPT_Actions_Auth::metadata()),
            'authentication_mode'=>'actions_key_only',
            'ephemeral_auth_required'=>false,
            'legacy_mcp_required'=>false,
            'legacy_mcp_enabled'=>defined('PRSTUDIO_UC_ENABLE_LEGACY_MCP')?(bool)PRSTUDIO_UC_ENABLE_LEGACY_MCP:false,
        );
        $h['migration']=array(
            'state'=>function_exists('get_option')?get_option('prstudio_uc_migration_pending',array()):array(),
            'failure'=>function_exists('get_option')?get_option('prstudio_uc_migration_failure',array()):array(),
        );
        $h['browser_protocol']=array('preferred'=>PRSTUDIO_UC_Browser_Protocol::EXECUTOR_PROTOCOL,'accepted'=>PRSTUDIO_UC_Browser_Protocol::ACCEPTED_EXECUTOR_PROTOCOLS,'repair_required_on_upgrade'=>false);
        return self::response(array('data'=>$h),$r);
    }
    public static function job($r): WP_REST_Response {
        $id=sanitize_text_field((string)self::param($r,'job_id',''));$job=PRSTUDIO_UC_Store::get_job($id);if(!$job)return self::error(new WP_Error('job_not_found','Job not found.',array('status'=>404)),$r);return self::response(array('data'=>$job),$r);
    }
    public static function control_job($r): WP_REST_Response {
        $payload=self::json_body($r);if(is_wp_error($payload))return self::error($payload,$r);$id=sanitize_text_field((string)self::param($r,'job_id',''));$job=PRSTUDIO_UC_Store::get_job($id);if(!$job)return self::error(new WP_Error('job_not_found','Job not found.',array('status'=>404)),$r);$action=sanitize_key((string)($payload['action']??''));$reason=sanitize_text_field((string)($payload['reason']??''));
        if('cancel'===$action){$out=PRSTUDIO_UC_Store::cancel_job($id,$reason?:'gpt_actions_cancel');}
		elseif('rollback'===$action){$cap=PRSTUDIO_UC_Capability_Registry::get('rollback.job');$res=PRSTUDIO_UC_Snapshot_Engine::rollback_job(array('job_id'=>$id),$cap?:array());if(is_wp_error($res))return self::error($res,$r);$out=PRSTUDIO_UC_Store::set_job_state($id,'COMPLETED',array('result'=>array('operation'=>'explicit_rollback','result'=>$res),'verification'=>array('ok'=>true,'observer'=>'explicit_rollback'),'progress'=>100));}
		elseif('retry'===$action){$state=strtoupper((string)($job['status']??''));if(!in_array($state,array('INTERRUPTED','TECHNICAL_ERROR','WAITING_FOR_BROWSER'),true))return self::error(new WP_Error('job_control_conflict','Job cannot be retried from its current state.',array('status'=>409,'details'=>array('state'=>$state))),$r);$out=PRSTUDIO_UC_Store::set_job_state($id,'READY',array('checkpoint'=>array_merge((array)$job['checkpoint'],array('control_action'=>$action,'control_reason'=>$reason,'controlled_at'=>gmdate('c'))),'progress'=>(int)$job['progress']));}
        else return self::error(new WP_Error('job_control_invalid','Unsupported control action.',array('status'=>400)),$r);
        return self::response(array('data'=>$out),$r);
    }
    /** Backward-compatible testing helper. */
    public static function serve(callable $callback,$request): WP_REST_Response {try{$out=$callback($request);return $out instanceof WP_REST_Response?$out:self::response(array('data'=>$out),$request);}catch(Throwable $e){return self::error(new WP_Error('internal_exception','Request failed safely.',array('status'=>500,'details'=>array('exception_class'=>get_class($e)))),$request);}}
}
