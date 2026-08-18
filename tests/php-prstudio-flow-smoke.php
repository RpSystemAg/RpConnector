<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
final class WP_Error { public function __construct(private string $code='',private string $message='',private $data=null){} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim((string)$v);}
function esc_url_raw($v){return (string)$v;}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
final class PRSTUDIO_UC_MCP_Auth_V5 {
    public static int $writeChecks=0;
    public static function bearer_token_from_request(){return 'fixture-token';}
    public static function verify_access_token($token,$write){if($write)self::$writeChecks++;return ['client_id'=>'fixture'];}
}
final class PRSTUDIO_UC_Schema_Validator { public static function validate($args,$schema){return [];} }
final class PRSTUDIO_UC_Execution_Router {
    public static function annotate_capability($cap){$cap['supports_flow']=true;return $cap;}
    public static function tool_contract($tool,$ann){return ['can_execute_inline'=>true];}
}
final class PRSTUDIO_UC_Capability_Registry {
    public static function get($id){
        $write=str_contains($id,'write');
        return ['id'=>$id,'read_only'=>!$write,'input_schema'=>['type'=>'object'],'supports_flow'=>true,'domain'=>$write?'content_seo':'operations'];
    }
}
final class PRSTUDIO_UC_Pre_Mutation_Safety {
    public static int $begins=0,$ends=0;
    public static function scope_for_capability($cap){return empty($cap['read_only'])?'content':'none';}
    public static function scope_for_direct_tool($tool,$args=[]){return 'wordpress';}
    public static function flow_scope_for($scopes){return $scopes?reset($scopes):'none';}
    public static function begin_flow($scope,$tool,$args=[]){self::$begins++;return ['ok'=>true,'scope'=>$scope];}
    public static function end_flow(){self::$ends++;}
}
final class PRSTUDIO_UC_Execution_Gateway {
    public static int $calls=0;
    public static function execute($req){self::$calls++;return ['capability'=>$req['capability'],'value'=>$req['arguments']['value']??self::$calls];}
}
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}fwrite(STDOUT,"PASS $m\n");}
$method=new ReflectionMethod(PRSTUDIO_UC_MCP_V5::class,'execute_flow');$method->setAccessible(true);
$auth=['client_id'=>'fixture'];
$read=$method->invoke(null,['steps'=>[
 ['capability'=>'fixture.read.one','arguments'=>['value'=>1],'save_as'=>'first'],
 ['capability'=>'fixture.read.two','arguments'=>['value'=>'${first.value}']],
]],$auth);
ok(!is_wp_error($read)&&($read['ok']??false)===true,'read-only flow completes');
ok(PRSTUDIO_UC_Pre_Mutation_Safety::$begins===0&&PRSTUDIO_UC_MCP_Auth_V5::$writeChecks===0,'read-only flow bypasses anti-crash and write auth');
ok(($read['execution']['tool_calls']??0)===1&&($read['execution']['model_roundtrips_avoided']??0)===1,'two deterministic steps report one MCP call and one avoided model round-trip');
ok(($read['results'][1]['result']['value']??null)===1,'saved flow result resolves locally into later arguments');
PRSTUDIO_UC_Pre_Mutation_Safety::$begins=PRSTUDIO_UC_Pre_Mutation_Safety::$ends=0;PRSTUDIO_UC_MCP_Auth_V5::$writeChecks=0;PRSTUDIO_UC_Execution_Gateway::$calls=0;
$write=$method->invoke(null,['steps'=>[
 ['capability'=>'fixture.read.one','arguments'=>['value'=>1]],
 ['capability'=>'fixture.write.one','arguments'=>['value'=>2]],
 ['capability'=>'fixture.write.two','arguments'=>['value'=>3]],
]],$auth);
ok(!is_wp_error($write)&&($write['ok']??false)===true,'mixed write flow completes');
ok(PRSTUDIO_UC_Pre_Mutation_Safety::$begins===1&&PRSTUDIO_UC_Pre_Mutation_Safety::$ends===1,'mixed write flow enters existing anti-crash flow exactly once');
ok(PRSTUDIO_UC_MCP_Auth_V5::$writeChecks===1,'mixed write flow checks write authorization once');
ok(($write['execution']['tool_calls']??0)===1&&($write['execution']['model_roundtrips_avoided']??0)===2,'three deterministic steps report one MCP call and two avoided model round-trips');
fwrite(STDOUT,"OK prstudio_flow deterministic composition smoke\n");
