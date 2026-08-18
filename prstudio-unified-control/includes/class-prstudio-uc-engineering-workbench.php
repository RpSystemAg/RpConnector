<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Enterprise-safe engineering workbench.
 * It intentionally exposes a bounded operation catalogue rather than arbitrary shell strings.
 */
final class PRSTUDIO_UC_Engineering_Workbench {
    public const VERSION = '1.0.0';
    private const MAX_FILES = 5000;
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_OUTPUT = 1048576;
    private const DEFAULT_TIMEOUT = 30;

    private static function root(): string {
        $root = defined('PRSTUDIO_UC_PATH') ? (string) PRSTUDIO_UC_PATH : dirname(__DIR__) . '/';
        $real = realpath($root);
        return $real ? rtrim($real, DIRECTORY_SEPARATOR) : rtrim($root, DIRECTORY_SEPARATOR);
    }

    private static function relative_path(string $input=''): string {
        $input = str_replace('\\','/',trim($input));
        if ('' === $input || '.' === $input) { return ''; }
        if (str_contains($input,"\0") || preg_match('#(^|/)\.\.(/|$)#',$input) || str_starts_with($input,'/')) {
            throw new InvalidArgumentException('path_outside_workbench');
        }
        return trim($input,'/');
    }

    private static function allowed_roots(): array {
        $roots=array(self::root());
        if(defined('ABSPATH')){
            $site=rtrim((string)ABSPATH,'/\\');
            foreach(array('wp-content/themes','wp-content/plugins') as $rel){$candidate=$site.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);$real=realpath($candidate);if($real)$roots[]=$real;}
        }
        return array_values(array_unique(array_filter($roots)));
    }

    private static function resolve(string $relative='', bool $must_exist=true): string {
        $plugin_root=self::root(); $rel=self::relative_path($relative);
        $candidate=$plugin_root . (''!==$rel?DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel):'');
        if(''!==$rel && str_starts_with($rel,'wp-content/') && defined('ABSPATH')){
            $candidate=rtrim((string)ABSPATH,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);
        }
        $real=realpath($candidate);
        if(false===$real){ if($must_exist) throw new InvalidArgumentException('path_not_found'); $real=$candidate; }
        $normalized=rtrim(str_replace('\\','/',$real),'/'); $inside=false;
        foreach(self::allowed_roots() as $root){$root_n=rtrim(str_replace('\\','/',$root),'/');if($normalized===$root_n||str_starts_with($normalized,$root_n.'/')){$inside=true;break;}}
        if(!$inside) throw new InvalidArgumentException('path_outside_workbench');
        return $real;
    }

    private static function ignored(string $path): bool {
        $path='/' . trim(str_replace('\\','/',$path),'/') . '/';
        foreach(array('/.git/','/node_modules/','/vendor/','/cache/','/uploads/','/.idea/','/.vscode/') as $needle){ if(str_contains($path,$needle)) return true; }
        return false;
    }

    private static function files(string $relative='', array $extensions=array()): array {
        $root=self::resolve($relative); if(is_file($root)) return array($root);
        $out=array(); $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){ if(count($out)>=self::MAX_FILES) break; if(!$file->isFile()) continue; $path=$file->getPathname(); if(self::ignored($path)) continue; if($file->getSize()>self::MAX_FILE_BYTES) continue; $ext=strtolower($file->getExtension()); if($extensions && !in_array($ext,$extensions,true)) continue; $out[]=$path; }
        sort($out,SORT_STRING); return $out;
    }

    private static function rel(string $path): string {
        $p=str_replace('\\','/',$path);
        if(defined('ABSPATH')){$site=rtrim(str_replace('\\','/',(string)ABSPATH),'/');if(str_starts_with($p,$site.'/'))return ltrim(substr($p,strlen($site)),'/');}
        $root=str_replace('\\','/',self::root()); return ltrim(str_starts_with($p,$root)?substr($p,strlen($root)):$p,'/');
    }

    public static function repo_map(array $args=array()) {
        try { $paths=self::files((string)($args['path']??''),array('php','js','mjs','json','py','md')); } catch(Throwable $e){
            $reason='path_not_found'===$e->getMessage()?'not_found':'outside_workspace';
            $code='not_found'===$reason?'engineering_path_not_found':'engineering_path_invalid';
            $message='not_found'===$reason?'Path does not exist in the bounded PR STUDIO plugin workspace.':'Path is outside the bounded PR STUDIO plugin workspace.';
            return new WP_Error($code,$message,array('status'=>'not_found'===$reason?404:400,'reason'=>$reason));
        }
        $limit=max(1,min(self::MAX_FILES,(int)($args['limit']??1000))); $paths=array_slice($paths,0,$limit); $rows=array(); $symbols=0;
        foreach($paths as $path){ $content=@file_get_contents($path,false,null,0,self::MAX_FILE_BYTES); if(false===$content) continue; $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION)); $found=array();
            if('php'===$ext){ if(preg_match_all('/\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)|\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',$content,$m,PREG_SET_ORDER)){foreach($m as $hit){$name=(string)($hit[1]?:$hit[2]??'');if(''!==$name)$found[]=$name;}} }
            elseif(in_array($ext,array('js','mjs'),true)){ if(preg_match_all('/\b(?:export\s+)?(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)|\bclass\s+([A-Za-z_$][A-Za-z0-9_$]*)/',$content,$m,PREG_SET_ORDER)){foreach($m as $hit){$name=(string)($hit[1]?:$hit[2]??'');if(''!==$name)$found[]=$name;}} }
            $found=array_values(array_unique(array_slice($found,0,100))); $symbols+=count($found);
            $rows[]=array('path'=>self::rel($path),'bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'symbols'=>$found);
        }
        return array('ok'=>true,'version'=>self::VERSION,'root'=>'prstudio-unified-control','files'=>count($rows),'symbols'=>$symbols,'truncated'=>count($paths)>=$limit,'map'=>$rows,'strategy'=>'bounded_repo_map_progressive_disclosure');
    }

    private static function run_process(array $argv,int $timeout=self::DEFAULT_TIMEOUT): array {
        if(!function_exists('proc_open') || in_array('proc_open',array_map('trim',explode(',',(string)ini_get('disable_functions'))),true)) return array('ok'=>false,'error'=>'proc_open_unavailable','exit_code'=>null,'stdout'=>'','stderr'=>'');
        $spec=array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')); $proc=@proc_open($argv,$spec,$pipes,self::root(),array());
        if(!is_resource($proc)) return array('ok'=>false,'error'=>'process_start_failed','exit_code'=>null,'stdout'=>'','stderr'=>'');
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false); $start=microtime(true);$stdout='';$stderr='';$timed=false;
        $observed_exit=null;
        do { $status=proc_get_status($proc); $stdout.=stream_get_contents($pipes[1]); $stderr.=stream_get_contents($pipes[2]); if(strlen($stdout)>self::MAX_OUTPUT)$stdout=substr($stdout,0,self::MAX_OUTPUT); if(strlen($stderr)>self::MAX_OUTPUT)$stderr=substr($stderr,0,self::MAX_OUTPUT); if(!$status['running']){$candidate=(int)($status['exitcode']??-1);if($candidate>=0)$observed_exit=$candidate;break;} if(microtime(true)-$start>$timeout){$timed=true;proc_terminate($proc,15);usleep(100000);$s=proc_get_status($proc);if(!$s['running']&&(int)($s['exitcode']??-1)>=0)$observed_exit=(int)$s['exitcode'];if($s['running'])proc_terminate($proc,9);break;} usleep(20000); } while(true);
        $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$close_exit=proc_close($proc);$exit=null!==$observed_exit?$observed_exit:$close_exit;
        $error=$timed?'process_timeout':(0===$exit?'':'process_exit_nonzero');
        return array('ok'=>!$timed&&0===$exit,'error'=>$error,'exit_code'=>$exit,'proc_get_status_exit_code'=>$observed_exit,'proc_close_exit_code'=>$close_exit,'stdout'=>substr($stdout,0,self::MAX_OUTPUT),'stderr'=>substr($stderr,0,self::MAX_OUTPUT),'timeout'=>$timed);
    }

    private static function php_binary_candidates(): array {
        $candidates=array();
        $configured=defined('PRSTUDIO_UC_PHP_CLI')?(string)PRSTUDIO_UC_PHP_CLI:(string)getenv('PRSTUDIO_PHP_CLI');
        if(''!==trim($configured))$candidates[]=trim($configured);
        $suffix='';
        if(defined('PHP_BINARY')&&PHP_BINARY){
            $binary=(string)PHP_BINARY;
            $candidates[]=$binary;
            if(preg_match('/php-fpm(?:([0-9]+(?:\.[0-9]+)?))?(?:\.exe)?$/i',basename($binary),$m))$suffix=(string)($m[1]??'');
        }
        if(defined('PHP_BINDIR')&&PHP_BINDIR){
            $dir=rtrim((string)PHP_BINDIR,'/\\');
            if(''!==$suffix)$candidates[]=$dir.DIRECTORY_SEPARATOR.'php'.$suffix.(PHP_OS_FAMILY==='Windows'?'.exe':'');
            $candidates[]=$dir.DIRECTORY_SEPARATOR.'php'.(PHP_OS_FAMILY==='Windows'?'.exe':'');
        }
        if(PHP_OS_FAMILY==='Windows'){
            $candidates[]=dirname((string)(defined('PHP_BINARY')?PHP_BINARY:__FILE__)).DIRECTORY_SEPARATOR.'php.exe';
            $candidates[]='php.exe';
        }else{
            if(''!==$suffix){$candidates[]='/usr/bin/php'.$suffix;$candidates[]='/usr/local/bin/php'.$suffix;}
            $candidates[]='/usr/bin/php';$candidates[]='/usr/local/bin/php';$candidates[]='php';
        }
        return array_values(array_unique(array_filter($candidates,static fn($v)=>''!==trim((string)$v))));
    }

    private static function php_binary(): string {
        static $resolved=null;
        if(is_string($resolved)&&''!==$resolved)return $resolved;
        foreach(self::php_binary_candidates() as $candidate){
            if(str_contains($candidate,DIRECTORY_SEPARATOR)&&!is_file($candidate))continue;
            $probe=self::run_process(array($candidate,'-r','echo PHP_SAPI;'),5);
            if(!empty($probe['ok'])&&'cli'===trim((string)($probe['stdout']??''))){$resolved=$candidate;return $resolved;}
        }
        throw new RuntimeException('php_cli_unavailable');
    }

    public static function validate(array $args=array()) {
        $profile=sanitize_key((string)($args['profile']??'matrix')); $allowed=array('matrix','php_lint','json_validate','no_stub_scan'); if(!in_array($profile,$allowed,true)) return new WP_Error('engineering_profile_invalid','Only fixed validation profiles are allowed.',array('status'=>400,'allowed'=>$allowed));
        try{$relative=(string)($args['path']??'');$root=self::resolve($relative);}catch(Throwable $e){return new WP_Error('engineering_path_invalid','Path is outside the bounded PR STUDIO plugin workspace.',array('status'=>400));}
        $results=array();$failures=array();$checked=0;
        if(in_array($profile,array('matrix','php_lint'),true)){
            $lint_evidence=array(); $lint_files=self::files($relative,array('php'));
            foreach($lint_files as $file){
                $checked++; $stdout=''; $stderr=''; $ok=true; $exit=0;
                $raw=@file_get_contents($file,false,null,0,self::MAX_FILE_BYTES);
                if(false===$raw){$ok=false;$exit=1;$stderr='file_read_failed';}
                else{
                    try { token_get_all((string)$raw, defined('TOKEN_PARSE') ? TOKEN_PARSE : 0); }
                    catch(ParseError $e){$ok=false;$exit=255;$stderr=$e->getMessage();}
                    catch(Throwable $e){$ok=false;$exit=1;$stderr=$e->getMessage();}
                }
                if(count($lint_evidence)<20)$lint_evidence[]=array('path'=>self::rel($file),'ok'=>$ok,'exit_code'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr,'runner'=>'php_token_parse_in_process');
                if(!$ok){$failures[]=array('kind'=>'php_lint','path'=>self::rel($file),'error'=>$stderr?:'php_lint_failed','exit_code'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr);if(count($failures)>=100)break;}
            }
            $results['php_lint']=array('checked'=>$checked,'failures'=>count(array_filter($failures,static fn($f)=>'php_lint'===$f['kind'])),'evidence_preview'=>$lint_evidence,'process_spawns'=>0,'runner'=>'php_token_parse_in_process');
        }
        if(in_array($profile,array('matrix','json_validate'),true)){
            $json_checked=0;$json_fail=0;foreach(self::files($relative,array('json')) as $file){$json_checked++;$raw=@file_get_contents($file);json_decode((string)$raw,true);if(JSON_ERROR_NONE!==json_last_error()){$json_fail++;$failures[]=array('kind'=>'json_validate','path'=>self::rel($file),'error'=>json_last_error_msg());if(count($failures)>=100)break;}}$results['json_validate']=array('checked'=>$json_checked,'failures'=>$json_fail);
        }
        if(in_array($profile,array('matrix','no_stub_scan'),true)){
            $stub_checked=0;$stub_hits=array();$patterns=array('/\bTO'.'DO\b/i','/\bFIX'.'ME\b/i','/not[_ -]?'.'implemented/i','/throw\s+new\s+Exception\s*\(\s*[\'\"]TO'.'DO/i');
            foreach(self::files($relative,array('php','js','mjs')) as $file){$stub_checked++;$raw=@file_get_contents($file);foreach($patterns as $pattern){if(preg_match($pattern,(string)$raw)){$stub_hits[]=array('path'=>self::rel($file),'pattern'=>$pattern);break;}}if(count($stub_hits)>=100)break;}
            $results['no_stub_scan']=array('checked'=>$stub_checked,'hits'=>count($stub_hits),'hits_preview'=>array_slice($stub_hits,0,25));
        }
        return array('ok'=>0===count($failures),'version'=>self::VERSION,'profile'=>$profile,'path'=>self::rel($root),'results'=>$results,'failures'=>$failures,'arbitrary_shell_exposed'=>false,'bounded_process_runner'=>true,'max_files'=>self::MAX_FILES);
    }


    private static function inventory(array $args=array()): array {
        $relative=(string)($args['path']??''); $limit=max(1,min(self::MAX_FILES,(int)($args['limit']??self::MAX_FILES)));
        $paths=array_slice(self::files($relative),0,$limit); $bytes=0;$types=array();
        foreach($paths as $path){$size=@filesize($path)?:0;$bytes+=$size;$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION))?:'(none)';$types[$ext]=($types[$ext]??0)+1;}
        ksort($types,SORT_STRING); return array('ok'=>true,'operation'=>'inventory','files'=>count($paths),'bytes'=>$bytes,'types'=>$types,'process_spawns'=>0,'truncated'=>count($paths)>=$limit);
    }
    private static function sha_batch(array $args=array()): array {
        $relative=(string)($args['path']??'');$limit=max(1,min(self::MAX_FILES,(int)($args['limit']??self::MAX_FILES)));$rows=array();
        foreach(array_slice(self::files($relative),0,$limit) as $path){$rows[]=array('path'=>self::rel($path),'bytes'=>(int)(@filesize($path)?:0),'sha256'=>hash_file('sha256',$path));}
        return array('ok'=>true,'operation'=>'sha256','files'=>count($rows),'process_spawns'=>0,'rows'=>$rows);
    }
    private static function search_files(array $args=array()): array {
        $needle=(string)($args['query']??''); if(''===$needle)return array('ok'=>false,'operation'=>'search','error'=>'query_required','process_spawns'=>0);
        $relative=(string)($args['path']??'');$limit=max(1,min(500,(int)($args['limit']??100)));$rows=array();
        foreach(self::files($relative,array('php','js','mjs','json','py','md','css','html','txt')) as $path){$raw=@file_get_contents($path,false,null,0,self::MAX_FILE_BYTES);if(false===$raw)continue;$pos=stripos((string)$raw,$needle);if(false!==$pos){$line=1+substr_count(substr((string)$raw,0,$pos),"\n");$rows[]=array('path'=>self::rel($path),'line'=>$line);if(count($rows)>=$limit)break;}}
        return array('ok'=>true,'operation'=>'search','query'=>$needle,'matches'=>count($rows),'process_spawns'=>0,'rows'=>$rows);
    }
    private static function archive_inspect(array $args=array()): array {
        $path=self::resolve((string)($args['path']??'')); if(!is_file($path))return array('ok'=>false,'operation'=>'archive_inspect','error'=>'archive_not_file','process_spawns'=>0);
        if(!class_exists('ZipArchive'))return array('ok'=>false,'operation'=>'archive_inspect','error'=>'ziparchive_unavailable','process_spawns'=>0);
        $z=new ZipArchive();if(true!==$z->open($path))return array('ok'=>false,'operation'=>'archive_inspect','error'=>'archive_open_failed','process_spawns'=>0);
        $limit=max(1,min(self::MAX_FILES,(int)($args['limit']??self::MAX_FILES)));$rows=array();$total=0;
        for($i=0;$i<$z->numFiles&&count($rows)<$limit;$i++){$st=$z->statIndex($i);if(!$st)continue;$name=(string)$st['name'];if(str_starts_with($name,'/')||str_contains('/'.$name.'/','/../')){$z->close();return array('ok'=>false,'operation'=>'archive_inspect','error'=>'archive_traversal_entry','entry'=>$name,'process_spawns'=>0);}$rows[]=array('name'=>$name,'size'=>(int)($st['size']??0),'crc'=>(int)($st['crc']??0));$total+=(int)($st['size']??0);}
        $count=$z->numFiles;$z->close();return array('ok'=>true,'operation'=>'archive_inspect','entries'=>$count,'uncompressed_bytes'=>$total,'process_spawns'=>0,'truncated'=>$count>count($rows),'rows'=>$rows);
    }
    private static function batch_flow(array $args=array()) {
        $steps=is_array($args['operations']??null)?$args['operations']:array(); if(!$steps||count($steps)>32)return new WP_Error('engineering_batch_invalid','Batch requires 1-32 fixed operations.',array('status'=>400));
        $out=array();$ok=true;$spawns=0;
        foreach($steps as $i=>$step){if(!is_array($step))return new WP_Error('engineering_batch_step_invalid','Each batch step must be an object.',array('status'=>400,'index'=>$i));$op=sanitize_key((string)($step['operation']??''));$row=null;
            if('repo_map'===$op)$row=self::repo_map($step);elseif('inventory'===$op)$row=self::inventory($step);elseif('sha256'===$op)$row=self::sha_batch($step);elseif('search'===$op)$row=self::search_files($step);elseif('archive_inspect'===$op)$row=self::archive_inspect($step);elseif(in_array($op,array('php_lint','json_validate','test_matrix'),true))$row=self::validate(array('profile'=>'test_matrix'===$op?'matrix':$op,'path'=>(string)($step['path']??'')));else return new WP_Error('engineering_batch_operation_invalid','Unsupported batch operation.',array('status'=>400,'index'=>$i,'operation'=>$op));
            if(is_wp_error($row))return $row;$row=(array)$row;$ok=$ok&&!empty($row['ok']);$spawns+=(int)($row['process_spawns']??0);$out[]=array('index'=>$i,'operation'=>$op,'result'=>$row);
        }
        return array('ok'=>$ok,'operation'=>'batch_flow','steps'=>count($out),'process_spawns'=>$spawns,'results'=>$out,'strategy'=>'set_based_in_process_first');
    }

    public static function terminal(array $args=array()) {
        $operation=sanitize_key((string)($args['operation']??''));
        if('php_version'===$operation){try{$php_cli=self::php_binary();}catch(Throwable $e){return array('operation'=>$operation,'arbitrary_shell_exposed'=>false,'ok'=>false,'error'=>'php_cli_unavailable','exit_code'=>null,'stdout'=>'','stderr'=>'No PHP CLI SAPI binary was found.');}$r=self::run_process(array($php_cli,'-v'),10);return array_merge(array('operation'=>$operation,'arbitrary_shell_exposed'=>false,'php_binary'=>$php_cli,'sapi'=>'cli'),$r);}
        if('php_lint'===$operation)return self::validate(array('profile'=>'php_lint','path'=>(string)($args['path']??'')));
        if('json_validate'===$operation)return self::validate(array('profile'=>'json_validate','path'=>(string)($args['path']??'')));
        if('test_matrix'===$operation)return self::validate(array('profile'=>'matrix','path'=>(string)($args['path']??'')));
        if('repo_map'===$operation)return self::repo_map($args);
        if('inventory'===$operation)return self::inventory($args);
        if('sha256'===$operation)return self::sha_batch($args);
        if('search'===$operation)return self::search_files($args);
        if('archive_inspect'===$operation)return self::archive_inspect($args);
        if('batch_flow'===$operation)return self::batch_flow($args);
        return new WP_Error('engineering_operation_invalid','Arbitrary shell commands are not accepted; choose a fixed safe operation.',array('status'=>400,'allowed'=>array('php_version','php_lint','json_validate','test_matrix','repo_map','inventory','sha256','search','archive_inspect','batch_flow')));
    }

    public static function status(array $args=array()): array { try{$php_cli=self::php_binary();$php_cli_ready=true;}catch(Throwable $e){$php_cli='';$php_cli_ready=false;} return array('ok'=>true,'version'=>self::VERSION,'root'=>'prstudio-unified-control','operations'=>array('repo_map','php_version','php_lint','json_validate','test_matrix','inventory','sha256','search','archive_inspect','batch_flow'),'arbitrary_shell_exposed'=>false,'process_runner'=>'argv_no_shell','php_cli_ready'=>$php_cli_ready,'php_cli_binary'=>$php_cli_ready?$php_cli:'','timeout_seconds'=>self::DEFAULT_TIMEOUT,'max_files'=>self::MAX_FILES); }
}
