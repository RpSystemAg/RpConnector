<?php
$tmp=sys_get_temp_dir().'/prstudio-file-engine-'.bin2hex(random_bytes(4));mkdir($tmp);mkdir($tmp.'/wp-content');$outside=$tmp.'-outside';mkdir($outside);
define('PRSTUDIO_UC_TESTING',true);define('ABSPATH',$tmp.'/');define('WP_CONTENT_DIR',$tmp.'/wp-content');define('PRSTUDIO_UC_DIR',$tmp.'/plugin');mkdir(PRSTUDIO_UC_DIR);
class WP_Error{public function __construct(public string $code,public string $message='',public array $data=[]){ }public function get_error_code(){return $this->code;}}function is_wp_error($v){return $v instanceof WP_Error;}
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-file-engine.php';function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}}
$file=WP_CONTENT_DIR.'/large.txt';$content=str_repeat('0123456789abcdef',8192);file_put_contents($file,$content); // 128 KiB
$r=PRSTUDIO_UC_File_Engine::read(['path'=>$file,'max_bytes'=>64]);ok(!is_wp_error($r)&&$r['bytes']===64&&$r['truncated']===true,'bounded valid read');ok($r['content']===substr($content,0,64),'exact prefix');ok($r['sha256']===hash('sha256',$r['content']),'hash must attest returned bytes');ok(($r['sha256_scope']??'')==='returned_content','truncated hash scope explicit');
$r=PRSTUDIO_UC_File_Engine::read(['path'=>$file,'max_bytes'=>1048577]);ok(!is_wp_error($r)&&$r['truncated']===false&&$r['sha256']===hash('sha256',$content)&&($r['sha256_scope']??'')==='full_file','full bounded file hash');
$outsideFile=$outside.'/secret.txt';file_put_contents($outsideFile,'secret');$r=PRSTUDIO_UC_File_Engine::read(['path'=>$outsideFile]);ok(is_wp_error($r)&&$r->get_error_code()==='prstudio_file_path_traversal','outside denied');
$link=WP_CONTENT_DIR.'/link.txt';@symlink($outsideFile,$link);if(is_link($link)){$r=PRSTUDIO_UC_File_Engine::read(['path'=>$link]);ok(is_wp_error($r)&&$r->get_error_code()==='prstudio_file_path_traversal','symlink escape denied');}
$r=PRSTUDIO_UC_File_Engine::read(['path'=>"bad\0path"]);ok(is_wp_error($r)&&$r->get_error_code()==='prstudio_file_path_invalid','nul path denied');
@unlink($link);@unlink($file);@unlink($outsideFile);@rmdir(WP_CONTENT_DIR);@rmdir(PRSTUDIO_UC_DIR);@rmdir($tmp);@rmdir($outside);
echo "PASS file-engine\n";
