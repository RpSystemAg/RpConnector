<?php

define( 'PRSTUDIO_UC_TESTING', true );
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/prstudio-site-learning-wp-content' );

final class WP_Error {
    public function __construct( private string $code, private string $message, private array $data = array() ) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_mkdir_p( $dir ): bool { return is_dir( $dir ) || mkdir( $dir, 0750, true ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string)$value ) ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string)$value ) ); }
function esc_url_raw( $value ): string { return (string)$value; }
function home_url( $path = '/' ): string { return 'https://example.test' . $path; }
function admin_url( $path = '/' ): string { return 'https://example.test/wp-admin/' . ltrim((string)$path,'/'); }
function fail_site_learning( string $message ): void { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
function assert_site_learning( bool $condition, string $message ): void { if ( ! $condition ) fail_site_learning( $message ); }

final class PRSTUDIO_UC_Memory {
    public static function site_dir(): string {$dir=sys_get_temp_dir().'/prstudio-site-learning-memory-'.getmypid();if(!is_dir($dir))mkdir($dir,0750,true);return$dir;}
    public static function redact( $value ) {if(is_string($value)){if(str_starts_with($value,'data:image/'))return'[REDACTED_INLINE_IMAGE]';return preg_replace('/([?&](?:token|key|secret|auth)=[^&]+)/i','?redacted=1',$value);}if(is_array($value)){$out=array();foreach($value as $k=>$v)$out[$k]=self::redact($v);return$out;}return$value;}
}
final class PRSTUDIO_UC_Capability_Registry {public static function all(): array {$rows=array();for($i=0;$i<1378;$i++)$rows[]=array('id'=>'capability.'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'browser_required'=>0===($i%7),'read_only'=>0===($i%3));return$rows;}}
final class PRSTUDIO_UC_Idempotency {public static function canonical_json( $value ): string { return (string)json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }}
final class PRSTUDIO_UC_Store {
    public static array $jobs=array();public static array $events=array();
    public static function get_job( string $id ) { return self::$jobs[$id]??null; }
    public static function set_job_state( string $id, string $state, array $patch=array() ) {if(!isset(self::$jobs[$id]))return null;self::$jobs[$id]['status']=$state;foreach($patch as $key=>$value)self::$jobs[$id][$key]=$value;return self::$jobs[$id];}
    public static function event( string $id, string $type, array $payload=array() ): void { self::$events[]=array($id,$type,$payload); }
}
final class PRSTUDIO_UC_Job_Engine {
    public static array $tasks=array();
    public static function create_browser_task( string $action, array $arguments, ?string $device_uuid=null, string $job_uuid='' ): array {$id='child-'.(count(self::$tasks)+1);$task=array('task_uuid'=>$id,'job_uuid'=>$job_uuid,'device_uuid'=>$device_uuid,'action'=>$action,'arguments'=>$arguments,'status'=>'queued');self::$tasks[$id]=$task;return$task;}
}

require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-site-learning.php';
require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-playbook-engine.php';

$a=PRSTUDIO_UC_Site_Learning::module_id_for_url('https://Example.test/a?x=1');$b=PRSTUDIO_UC_Site_Learning::module_id_for_url('https://example.test/b');$c=PRSTUDIO_UC_Site_Learning::module_id_for_url('https://other.test/');
assert_site_learning(''!==$a&&$a===$b&&$a!==$c,'module identity must be origin-scoped and independent between sites');
$contract=PRSTUDIO_UC_Site_Learning::capability_contract();assert_site_learning(1378===(int)$contract['count'],'every capability must enter the learning contract');assert_site_learning(true===$contract['all_capabilities_adopted']&&array()===$contract['excluded'],'capability learning contract must have no exclusions');assert_site_learning(1378===count((array)$contract['rows']),'capability contract silently dropped rows');

$prepared=PRSTUDIO_UC_Site_Learning::prepare_context(array('url'=>'https://example.test/','max_pages'=>99999,'max_depth'=>99,'concurrency'=>99,'delay_ms'=>1,'batch_pages'=>99));$args=(array)$prepared['crawler_arguments'];
assert_site_learning(1500===(int)$args['max_pages'],'crawler max_pages bound must match Browser Agent maximum');assert_site_learning(5===(int)$args['max_depth']&&4===(int)$args['concurrency']&&100===(int)$args['delay_ms'],'crawler bounds were not clamped');assert_site_learning(false===$args['allow_cross_origin']&&!empty($args['_prstudio_site_read_only']),'generic study must default to same-origin read-only execution');

$steps=PRSTUDIO_UC_Site_Learning::flow_steps_for_urls(array('https://example.test/','https://example.test/about'),'https://example.test');assert_site_learning(18===count($steps),'each generic page must receive nine deterministic browser steps');$types=array_count_values(array_map(static fn($row)=>(string)($row['type']??''),$steps));foreach(array('page_snapshot','accessibility_snapshot','screenshot','observation_bundle','core_web_vitals','network_report','console_report') as $required)assert_site_learning(2===($types[$required]??0),'missing per-page evidence step '.$required);foreach($steps as $step)assert_site_learning(!in_array((string)($step['type']??''),array('click','fill','type_text','select','check','press','dialog','contract_action','javascript_exec','native_input'),true),'generic crawler acquired active input: '.($step['type']??''));

$wp=PRSTUDIO_UC_Site_Learning::prepare_context(array('study_target'=>'wordpress'));
assert_site_learning('wordpress_admin'===($wp['mode']??'')&&'https://example.test/wp-admin/'===($wp['url']??''),'WordPress study must resolve current authenticated admin URL when target is omitted');
assert_site_learning('playwright_flow'===($wp['initial_browser']['action']??''),'WordPress study must start with a real Browser Agent flow, not URL crawl');
$wp_steps=(array)($wp['initial_browser']['arguments']['steps']??array());$wp_types=array_count_values(array_map(static fn($row)=>(string)($row['type']??''),$wp_steps));
assert_site_learning(($wp_types['click']??0)>=1&&($wp_types['javascript_exec']??0)>=2&&($wp_types['screenshot']??0)>=2,'WordPress probe must perform a real safe click, structured DOM observations and screenshot evidence');
foreach($wp_steps as $step){$type=(string)($step['type']??'');assert_site_learning(!in_array($type,array('fill','type_text','select','check','press','dialog','contract_action','native_input'),true),'mutating/input step leaked into WordPress study: '.$type);if('click'===$type){$selector=strtolower((string)($step['selector']??''));assert_site_learning(!preg_match('/save|submit|publish|delete|trash|activate|deactivate|logout/',$selector),'unsafe click selector leaked into WordPress study');assert_site_learning(!empty($step['_prstudio_exploratory_read_only']),'WordPress click lacks explicit exploratory/read-only classification');}}

$plan=PRSTUDIO_UC_Playbook_Engine::build('site_study',array('url'=>'https://example.test/','max_pages'=>4,'batch_pages'=>2));assert_site_learning('site_study'===($plan['type']??'')&&1===count((array)$plan['steps']),'site_study must start as one durable discovery step');$first=(array)$plan['steps'][0];assert_site_learning('browser.action'===($first['handler']??'')&&!empty($first['requires_browser'])&&!empty($first['read_only']),'site_study discovery contract is not read-only browser work');assert_site_learning('playwright_link_crawl'===($first['arguments']['action']??''),'generic site_study must keep rendered Browser Agent crawl');
$wp_plan=PRSTUDIO_UC_Playbook_Engine::build('site_study',array('study_target'=>'wordpress'));assert_site_learning('playwright_flow'===($wp_plan['steps'][0]['arguments']['action']??''),'WordPress site_study plan did not select the Browser Agent flow');

$study=PRSTUDIO_UC_Site_Learning::prepare_context(array('url'=>'https://example.test/','max_pages'=>12,'batch_pages'=>2));$crawl_task=array('task_uuid'=>'crawl-1','job_uuid'=>'job-1','device_uuid'=>'device-1','action'=>'playwright_link_crawl','arguments'=>$study['crawler_arguments']);PRSTUDIO_UC_Store::$jobs['job-1']=array('job_uuid'=>'job-1','status'=>'WAITING_FOR_BROWSER','step_index'=>0,'checkpoint'=>array('browser_task_id'=>'crawl-1','browser_step_index'=>0));
$crawl_result=array('seed_url'=>'https://example.test/','nodes'=>array(array('url'=>'https://example.test/'),array('url'=>'https://example.test/about'),array('url'=>'https://example.test/catalog?utm_source=test'),array('url'=>'https://example.test/private?token=super-secret'),array('url'=>'https://example.test/logout'),array('url'=>'https://other.test/external')));$verification=array('ok'=>true,'verifier'=>'site-learning-smoke');$continuation=PRSTUDIO_UC_Site_Learning::after_browser_completion($crawl_task,$crawl_result,$verification);assert_site_learning(!empty($continuation['handled'])&&!empty($continuation['defer_parent'])&&!empty($continuation['next_task_id']),'crawler completion did not chain a durable observation batch');assert_site_learning($continuation['next_task_id']===(PRSTUDIO_UC_Store::$jobs['job-1']['checkpoint']['browser_task_id']??''),'parent job was not retargeted to next browser batch');
$guard=0;$current_id=(string)$continuation['next_task_id'];$last=$continuation;while(!empty($last['defer_parent'])){if(++$guard>10)fail_site_learning('site-study continuation did not converge');$task=PRSTUDIO_UC_Job_Engine::$tasks[$current_id]??null;assert_site_learning(is_array($task),'missing queued child task');$task['status']='completed';$result=array('page'=>'ok','screenshot'=>array('artifact_id'=>str_repeat(dechex($guard%15+1),32),'sha256'=>str_repeat(dechex($guard%15+1),64),'dataUrl'=>'data:image/png;base64,SECRET_BYTES'));$last=PRSTUDIO_UC_Site_Learning::after_browser_completion($task,$result,$verification);$current_id=(string)($last['next_task_id']??'');}
$status=PRSTUDIO_UC_Site_Learning::status('https://example.test/');assert_site_learning(!empty($status['coverage']['complete'])&&100.0==(float)$status['coverage']['percent'],'site study did not reach explicit complete coverage');assert_site_learning('ready'===$status['state'],'fully verified generic study must end ready');
$module_path=PRSTUDIO_UC_Memory::site_dir().'/site-modules/'.$study['module_id'].'/module.json';$stored=(string)file_get_contents($module_path);assert_site_learning(!str_contains($stored,'data:image/'),'inline screenshot bytes leaked into persistent site memory');assert_site_learning(!str_contains($stored,'super-secret'),'sensitive URL query leaked into persistent site memory');assert_site_learning(str_contains($stored,'artifact_id')&&str_contains($stored,'sha256'),'screenshot proof references were not retained');
$restudy=PRSTUDIO_UC_Site_Learning::prepare_context(array('url'=>'https://example.test/','max_pages'=>12,'batch_pages'=>2));$second_task=array('task_uuid'=>'crawl-2','job_uuid'=>'job-2','device_uuid'=>'device-1','action'=>'playwright_link_crawl','arguments'=>$restudy['crawler_arguments']);PRSTUDIO_UC_Store::$jobs['job-2']=array('job_uuid'=>'job-2','status'=>'WAITING_FOR_BROWSER','step_index'=>0,'checkpoint'=>array('browser_task_id'=>'crawl-2','browser_step_index'=>0));$drift=PRSTUDIO_UC_Site_Learning::after_browser_completion($second_task,array('nodes'=>array(array('url'=>'https://example.test/'),array('url'=>'https://example.test/new-section'))),$verification);assert_site_learning(!empty($drift['drift']['changed'])&&!empty($drift['drift']['revalidation_required']),'surface change must invalidate stale site-specific assumptions without blocking execution');

fwrite(STDOUT,"PASS site learning: generic same-origin regression plus WordPress Browser Agent safe-click probe, structured DOM extraction, screenshot refs, redaction and drift\n");
