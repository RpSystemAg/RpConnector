<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING',true);
$GLOBALS['blog_id_test']=1;$GLOBALS['switch_stack']=array();$GLOBALS['switch_calls']=array();$GLOBALS['restore_calls']=0;
class WP_Error { public function __construct(public string $code,public string $message='',public array $data=array()){} }
class PRSTUDIO_UC_Memory { public static function site_identity(): array { $id=$GLOBALS['blog_id_test']; return array('key'=>'site-'.$id,'blog_id'=>$id,'host'=>'site'.$id.'.test','path'=>'/'); } }
function home_url($path='/'){return 'https://site'.$GLOBALS['blog_id_test'].'.test'.$path;}
function is_multisite(){return true;}
function get_site($id){return in_array((int)$id,array(1,2),true)?(object)array('blog_id'=>(int)$id):null;}
function switch_to_blog($id){$GLOBALS['switch_calls'][]=(int)$id;$GLOBALS['switch_stack'][]=$GLOBALS['blog_id_test'];$GLOBALS['blog_id_test']=(int)$id;return true;}
function restore_current_blog(){$GLOBALS['restore_calls']++;$GLOBALS['blog_id_test']=array_pop($GLOBALS['switch_stack']);return true;}
require __DIR__.'/../prstudio-unified-control/includes/class-prstudio-uc-site-context.php';
function check(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);} 
$c=PRSTUDIO_UC_Site_Context::current();check($c['namespace']==='site-1'&&$c['blog_id']===1&&$c['site_url']==='https://site1.test/','current');
$called=0;$r=PRSTUDIO_UC_Site_Context::execute('',function($ctx)use(&$called){$called++;return $ctx['namespace'];});check($r==='site-1'&&$called===1&&$GLOBALS['switch_calls']===array(),'empty selector direct');
$r=PRSTUDIO_UC_Site_Context::execute('site-1',fn($ctx)=>$ctx['blog_id']);check($r===1&&$GLOBALS['switch_calls']===array(),'namespace direct');
$r=PRSTUDIO_UC_Site_Context::execute(1,fn($ctx)=>$ctx['blog_id']);check($r===1&&$GLOBALS['switch_calls']===array(),'integer current direct');
$r=PRSTUDIO_UC_Site_Context::execute('2',fn($ctx)=>array($ctx['blog_id'],$ctx['namespace'],$ctx['site_url']));check($r===array(2,'site-2','https://site2.test/'),'cross-site callback');check($GLOBALS['blog_id_test']===1&&end($GLOBALS['switch_calls'])===2&&$GLOBALS['restore_calls']===1,'cross-site restore');
try{PRSTUDIO_UC_Site_Context::execute('2',function(){throw new RuntimeException('callback-fail');});throw new RuntimeException('expected callback throw');}catch(RuntimeException $e){check($e->getMessage()==='callback-fail','callback exception');}check($GLOBALS['blog_id_test']===1&&$GLOBALS['restore_calls']===2,'finally restore');
foreach(array('999','0','000',true,false,1.0,2.0,array(1)) as $bad){$before=count($GLOBALS['switch_calls']);$did=false;$res=PRSTUDIO_UC_Site_Context::execute($bad,function()use(&$did){$did=true;return 'executed';});check($res instanceof WP_Error,'bad selector error '.get_debug_type($bad));check(!$did,'bad selector must not execute '.get_debug_type($bad));check(count($GLOBALS['switch_calls'])===$before,'bad selector must not switch '.get_debug_type($bad));}
echo "PASS site-context-single-file\n";
