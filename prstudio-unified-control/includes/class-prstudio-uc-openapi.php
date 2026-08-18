<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_OpenAPI {
    public const VERSION='5.0.0';
    public const OPENAPI_VERSION='3.1.0';
    public const NS='prstudio-unified/v1';

    private static function server_url(?string $override=null): string {
        $url=trim((string)$override);
        if(''===$url&&function_exists('rest_url')){$url=(string)rest_url(self::NS);}
        if(''===$url&&function_exists('home_url')){$url=rtrim((string)home_url('/'),'/').'/wp-json/'.self::NS;}
        if(''===$url){$url='https://example.invalid/wp-json/'.self::NS;}
        return rtrim($url,'/');
    }
    private static function obj(array $properties,array $required=array(),bool $additional=true): array {
        $s=array('type'=>'object','properties'=>$properties,'additionalProperties'=>$additional);
        if($required){$s['required']=$required;}
        return $s;
    }
    private static function success(string $data_ref,array $extra=array()): array {
        return self::obj(array_merge(array(
            'ok'=>array('type'=>'boolean','enum'=>array(true)),
            'data'=>array('$ref'=>$data_ref),
            'request_id'=>array('type'=>'string'),
            'trace_id'=>array('type'=>'string'),
        ),$extra),array('ok','data','request_id','trace_id'),true);
    }
    private static function response_ref(string $schema_ref,string $description): array {
        return array('description'=>$description,'content'=>array('application/json'=>array('schema'=>array('$ref'=>$schema_ref))));
    }
    private static function responses(string $data_ref,array $success_codes,array $error_codes,array $extra=array()): array {
        $r=array();
        foreach($success_codes as $code){$r[(string)$code]=array('description'=>202===(int)$code?'Accepted':'Success','content'=>array('application/json'=>array('schema'=>self::success($data_ref,$extra))));}
        foreach($error_codes as $code){$r[(string)$code]=self::response_ref('#/components/schemas/ErrorEnvelope','Error');}
        ksort($r,SORT_NATURAL);return $r;
    }
    public static function schema(?string $server_url=null): array {
        $security=array(array('PRStudioActionsKey'=>array()));
        $paths=array(
            '/capabilities/search'=>array('post'=>array(
                'operationId'=>'searchCapabilities','summary'=>'Search internal capabilities','security'=>$security,
                'requestBody'=>array('required'=>true,'content'=>array('application/json'=>array('schema'=>self::obj(array(
                    'query'=>array('type'=>'string','maxLength'=>500),'domain'=>array('type'=>'string','maxLength'=>64),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>25),
                ),array('query'),false)))),
                'responses'=>self::responses('#/components/schemas/SearchData',array(200),array(400,401,403,413,429,500)),
            )),
            '/capabilities/describe'=>array('post'=>array(
                'operationId'=>'describeCapability','summary'=>'Describe one capability','security'=>$security,
                'requestBody'=>array('required'=>true,'content'=>array('application/json'=>array('schema'=>self::obj(array('capability'=>array('type'=>'string','maxLength'=>240)),array('capability'),false)))),
                'responses'=>self::responses('#/components/schemas/DescribeData',array(200),array(400,401,403,404,413,429,500)),
            )),
            '/execute'=>array('post'=>array(
                'operationId'=>'executeCapability','summary'=>'Execute one governed capability','security'=>$security,
                'requestBody'=>array('required'=>true,'content'=>array('application/json'=>array('schema'=>array('$ref'=>'#/components/schemas/ExecuteRequest')))),
                'responses'=>self::responses('#/components/schemas/ExecuteData',array(200,202),array(400,401,403,409,413,429,500,503)),
            )),
            '/jobs/{job_id}'=>array('get'=>array(
                'operationId'=>'getJob','summary'=>'Get durable job status','security'=>$security,
                'parameters'=>array(array('name'=>'job_id','in'=>'path','required'=>true,'schema'=>array('type'=>'string','maxLength'=>80))),
                'responses'=>self::responses('#/components/schemas/JobData',array(200),array(401,403,404,429,500)),
            )),
            '/memory/search'=>array('post'=>array(
                'operationId'=>'searchMemory','summary'=>'Search reusable site memory','security'=>$security,
                'requestBody'=>array('required'=>true,'content'=>array('application/json'=>array('schema'=>self::obj(array(
                    'query'=>array('type'=>'string','maxLength'=>500),'type'=>array('type'=>'string','maxLength'=>120),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>50),
                ),array('query'),false)))),
                'responses'=>self::responses('#/components/schemas/MemoryData',array(200),array(400,401,403,413,429,500),array('site'=>self::obj(array('site_id'=>array('type'=>'string'),'site_url'=>array('type'=>'string')),array(),true))),
            )),
            '/health'=>array('get'=>array(
                'operationId'=>'getHealth','summary'=>'Get control-plane health','security'=>$security,
                'responses'=>self::responses('#/components/schemas/HealthData',array(200),array(401,403,429,500)),
            )),
            '/jobs/{job_id}/control'=>array('post'=>array(
                'operationId'=>'controlJob','summary'=>'Control a durable job','security'=>$security,
                'parameters'=>array(array('name'=>'job_id','in'=>'path','required'=>true,'schema'=>array('type'=>'string','maxLength'=>80))),
                'requestBody'=>array('required'=>true,'content'=>array('application/json'=>array('schema'=>self::obj(array(
                    'action'=>array('type'=>'string','enum'=>array('retry','cancel')),'reason'=>array('type'=>'string','maxLength'=>500),
                ),array('action'),false)))),
                'responses'=>self::responses('#/components/schemas/JobData',array(200),array(400,401,403,404,409,413,429,500)),
            )),
        );
        $cap_item=self::obj(array(
            'id'=>array('type'=>'string'),'version'=>array('type'=>'string'),'domain'=>array('type'=>'string'),'description'=>array('type'=>'string'),
            'read_only'=>array('type'=>'boolean'),'risk_level'=>array('type'=>'string'),'browser_required'=>array('type'=>'boolean'),'gsc_required'=>array('type'=>'boolean'),
            'estimated_cost'=>array('type'=>'string'),'score'=>array('type'=>'integer'),
        ),array('id','version','domain','description'),true);
        $generic=self::obj(array('status'=>array('type'=>'string'),'state'=>array('type'=>'string'),'reason'=>array('type'=>'string'),'message'=>array('type'=>'string')),array(),true);
        $job=self::obj(array(
            'job_id'=>array('type'=>'string'),'request_id'=>array('type'=>'string'),'idempotency_key'=>array('type'=>'string'),'status'=>array('type'=>'string'),
            'state'=>array('type'=>'string'),'progress'=>array('type'=>'integer'),'attempts'=>array('type'=>'integer'),'created_at'=>array('type'=>'string'),'updated_at'=>array('type'=>'string'),
            'checkpoint'=>$generic,'evidence'=>$generic,'verification'=>$generic,
        ),array(),true);
        $schemas=array(
            'ErrorEnvelope'=>self::obj(array(
                'ok'=>array('type'=>'boolean','enum'=>array(false)),
                'error'=>self::obj(array('code'=>array('type'=>'string'),'message'=>array('type'=>'string'),'retryable'=>array('type'=>'boolean'),'details'=>$generic),array('code','message','retryable','details'),true),
                'request_id'=>array('type'=>'string'),'trace_id'=>array('type'=>'string'),
            ),array('ok','error','request_id','trace_id'),false),
            'ExecuteRequest'=>self::obj(array(
                'site'=>array('type'=>'string','maxLength'=>240),'capability'=>array('type'=>'string','maxLength'=>240),
                'arguments'=>self::obj(array('resource'=>array('type'=>'string'),'url'=>array('type'=>'string'),'id'=>array('type'=>'integer')),array(),true),
                'request_id'=>array('type'=>'string','minLength'=>8,'maxLength'=>160),'idempotency_key'=>array('type'=>'string','minLength'=>8,'maxLength'=>160),
                'dry_run'=>array('type'=>'boolean','default'=>false),'execution_mode'=>array('type'=>'string','enum'=>array('auto','sync','async'),'default'=>'auto'),
                'mission_id'=>array('type'=>'string','maxLength'=>120),'budget'=>self::obj(array(
                    'max_operations'=>array('type'=>'integer'),'max_duration'=>array('type'=>'integer'),'max_concurrency'=>array('type'=>'integer'),
                    'max_network_requests'=>array('type'=>'integer'),'max_retries'=>array('type'=>'integer'),'max_memory'=>array('type'=>'integer'),
                ),array(),true),
            ),array('capability','arguments','request_id','idempotency_key'),false),
            'SearchData'=>self::obj(array(
                'query'=>array('type'=>'string'),'count'=>array('type'=>'integer'),'items'=>array('type'=>'array','items'=>$cap_item),
                'registry_hash'=>array('type'=>'string'),'total_capabilities'=>array('type'=>'integer'),
            ),array('query','count','items'),true),
            'DescribeData'=>self::obj(array(
                'id'=>array('type'=>'string'),'version'=>array('type'=>'string'),'domain'=>array('type'=>'string'),'description'=>array('type'=>'string'),
                'read_only'=>array('type'=>'boolean'),'risk_level'=>array('type'=>'string'),'destructive'=>array('type'=>'boolean'),'idempotent'=>array('type'=>'boolean'),
                'browser_required'=>array('type'=>'boolean'),'gsc_required'=>array('type'=>'boolean'),
                'input_schema'=>$generic,'output_schema'=>$generic,'verification_policy'=>$generic,'evidence_policy'=>$generic,
            ),array('id','version','domain','description'),true),
            'ExecuteData'=>self::obj(array(
                'job_id'=>array('type'=>'string'),'mission_id'=>array('type'=>'string'),'status'=>array('type'=>'string'),'state'=>array('type'=>'string'),
                'waiting_for_browser'=>array('type'=>'boolean'),'progress'=>array('type'=>'integer'),'requested'=>array('type'=>'integer'),'processed'=>array('type'=>'integer'),
                'changed'=>array('type'=>'integer'),'verified'=>array('type'=>'integer'),'failed'=>array('type'=>'integer'),'skipped'=>array('type'=>'integer'),'memory_reused'=>array('type'=>'integer'),
                'result'=>$generic,'verification'=>$generic,'evidence'=>$generic,
            ),array(),true),
            'JobData'=>$job,
            'MemoryData'=>array('type'=>'array','items'=>self::obj(array(
                'type'=>array('type'=>'string'),'key'=>array('type'=>'string'),'resource'=>array('type'=>'string'),'url'=>array('type'=>'string'),
                'fingerprint'=>array('type'=>'string'),'freshness'=>array('type'=>'string'),'memory_reused'=>array('type'=>'boolean'),'updated_at'=>array('type'=>'string'),'summary'=>array('type'=>'string'),
            ),array(),true)),
            'HealthData'=>self::obj(array(
                'status'=>array('type'=>'string'),'version'=>array('type'=>'string'),'site_id'=>array('type'=>'string'),
                'capability_registry'=>$generic,
                'gpt_actions'=>self::obj(array(
                    'surface_operation_ids'=>array('type'=>'array','items'=>array('type'=>'string')),'actions_keys'=>array('type'=>'integer'),
                    'authentication_mode'=>array('type'=>'string'),'ephemeral_auth_required'=>array('type'=>'boolean'),'legacy_mcp_required'=>array('type'=>'boolean'),'legacy_mcp_enabled'=>array('type'=>'boolean'),
                ),array('surface_operation_ids','authentication_mode'),true),
                'migration'=>$generic,'browser_protocol'=>$generic,
            ),array(),true),
        );
        return array(
            'openapi'=>self::OPENAPI_VERSION,
            'info'=>array('title'=>'RP Studio Connector','version'=>self::VERSION,'description'=>'Stable seven-operation gateway for PR STUDIO Unified Control.'),
            'servers'=>array(array('url'=>self::server_url($server_url))),
            'paths'=>$paths,
            'components'=>array(
                'securitySchemes'=>array('PRStudioActionsKey'=>array('type'=>'apiKey','in'=>'header','name'=>'X-PRSTUDIO-AUTH','description'=>'Dedicated ChatGPT Actions API key generated in PR STUDIO Unified Control.')),
                'schemas'=>$schemas,
            ),
        );
    }
    public static function json(?string $server_url=null): string {return json_encode(self::schema($server_url),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
    public static function operation_ids(): array {$ids=array();foreach(self::schema()['paths'] as $p){foreach($p as $op){if(is_array($op)&&isset($op['operationId']))$ids[]=(string)$op['operationId'];}}return $ids;}
    public static function hash(?string $server_url=null): string {return hash('sha256',self::json($server_url));}
    public static function preflight(?string $server_url=null): array {
        $s=self::schema($server_url);$ids=self::operation_ids();$e=array();$server=(string)($s['servers'][0]['url']??'');
        if(!preg_match('#^https://[^/]+/.+#i',$server))$e[]='server_url_must_be_absolute_https';
        if(!in_array((string)($s['openapi']??''),array('3.1.0','3.1.1'),true))$e[]='openapi_version_not_supported_by_gpt_actions';
        if(count($ids)!==7||count($ids)!==count(array_unique($ids)))$e[]='operation_id_surface_invalid';
        if(isset($s['paths']['/openapi'])||count($s['paths'])!==7)$e[]='action_path_surface_invalid';
        foreach($s['paths'] as $path=>$item){foreach($item as $method=>$op){if(!is_array($op)||empty($op['operationId']))continue;$r=$op['responses']['200']['content']['application/json']['schema']??array();if(($r['type']??'')!=='object'||empty($r['properties']))$e[]='response_object_missing_properties:'.$path;}}
        $ex=$s['paths']['/execute']['post']['responses']['202']['content']['application/json']['schema']??array();if(($ex['type']??'')!=='object'||($ex['properties']['ok']['enum'][0]??null)!==true)$e[]='execute_202_must_be_success_schema';
        $raw=self::json($server_url);if(strlen($raw)>65536)$e[]='schema_too_large';
        foreach(array('"const"','"oneOf"','"anyOf"','"allOf"') as $n){if(str_contains($raw,$n))$e[]='compat_keyword_present:'.$n;}
        $a=$s['components']['securitySchemes']['PRStudioActionsKey']??array();if(($a['type']??'')!=='apiKey'||($a['in']??'')!=='header'||($a['name']??'')!=='X-PRSTUDIO-AUTH')$e[]='auth_scheme_invalid';
        return array('ok'=>empty($e),'errors'=>$e,'operation_ids'=>$ids,'path_count'=>count($s['paths']),'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'server'=>$server,'openapi'=>self::OPENAPI_VERSION);
    }
}
