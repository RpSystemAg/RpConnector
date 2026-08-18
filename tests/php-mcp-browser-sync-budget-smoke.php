<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);

final class WP_Error { public function __construct(private string $code='',private string $message='',private $data=null){} }
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function esc_url_raw($v){return (string)$v;}
function wp_salt($scheme='auth'){return 'sync-budget-'.$scheme;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_generate_uuid4(){return '11111111-2222-4333-8444-555555555555';}
function get_option($k,$d=false){return $d;}
function update_option($k,$v,$autoload=null){return true;}

final class PRSTUDIO_UC_Bridge {
    public static array $last_args=[];
    public static array $last_context=[];
    public static function dispatch($request,array $args,array $context=[]){
        self::$last_args=$args;
        self::$last_context=$context;
        return ['ok'=>true,'args'=>$args,'context'=>$context];
    }
}

require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';

function check($condition,string $message):void{
    if(!$condition){fwrite(STDERR,"FAIL $message\n");exit(1);}
    fwrite(STDOUT,"PASS $message\n");
}

$method=new ReflectionMethod(PRSTUDIO_UC_MCP_V5::class,'browser_dispatch');
$method->setAccessible(true);

$method->invoke(null,'playwright_screenshot_page',[]);
check((int)(PRSTUDIO_UC_Bridge::$last_args['sync_wait_seconds']??-1)===5,'default MCP browser sync budget is 5 seconds');
check((PRSTUDIO_UC_Bridge::$last_args['browser_target']??'')==='live','browser target remains live');
check((PRSTUDIO_UC_Bridge::$last_context['action']??'')==='playwright_screenshot_page','canonical screenshot executor action preserved');

$method->invoke(null,'playwright_screenshot_page',['sync_wait_seconds'=>20]);
check((int)(PRSTUDIO_UC_Bridge::$last_args['sync_wait_seconds']??-1)===20,'explicit legacy 20 second budget remains compatible');

$method->invoke(null,'puppeteer_screenshot',[]);
check((PRSTUDIO_UC_Bridge::$last_context['action']??'')==='playwright_screenshot_page','legacy screenshot alias resolves to canonical executor');
check((PRSTUDIO_UC_Bridge::$last_args['_prstudio_action_alias']??'')==='puppeteer_screenshot','legacy alias evidence is preserved');

fwrite(STDOUT,"OK MCP browser sync budget smoke\n");
