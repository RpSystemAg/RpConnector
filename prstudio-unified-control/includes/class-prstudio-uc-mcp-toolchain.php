<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Local/sidecar MCP toolchain federation for PR STUDIO 1.0.0.
 *
 * Design invariants:
 * - no new account/API-key requirement;
 * - no process is started at plugin bootstrap;
 * - user input can never select an executable or arbitrary command line;
 * - filesystem/database paths stay inside WordPress/PR STUDIO roots;
 * - sidecars are pinned, one-shot and forcibly terminated on timeout;
 * - Browser Agent remains the only Chrome/Playwright executor to avoid profile/session races.
 */
final class PRSTUDIO_UC_MCP_Toolchain {
    public const VERSION = '1.0.0';
    private const MCP_PROTOCOL = '2025-06-18';
    private const PROCESS_OUTPUT_LIMIT = 2097152;
    private const SIDECAR_TIMEOUT = 120;
    private const MAX_TEXT_BYTES = 1048576;

    private static function error( string $code, string $message, int $status = 400, array $details = array() ): WP_Error {
        return new WP_Error( $code, $message, array( 'status'=>$status, 'details'=>$details ) );
    }

    private static function normalize_slashes( string $path ): string { return str_replace( '\\', '/', $path ); }

    private static function roots(): array {
        $candidates = array(
            defined( 'ABSPATH' ) ? ABSPATH : '',
            defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
            defined( 'PRSTUDIO_UC_DIR' ) ? PRSTUDIO_UC_DIR : dirname( __DIR__ ) . '/',
        );
        $roots = array();
        foreach ( $candidates as $candidate ) {
            if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) { continue; }
            $real = realpath( $candidate );
            if ( false === $real || ! is_dir( $real ) ) { continue; }
            $roots[] = rtrim( self::normalize_slashes( $real ), '/' );
        }
        return array_values( array_unique( $roots ) );
    }

    private static function default_root(): string {
        $roots = self::roots();
        return (string) ( $roots[0] ?? dirname( __DIR__ ) );
    }

    private static function path_inside_roots( string $real ): bool {
        $real = rtrim( self::normalize_slashes( $real ), '/' );
        foreach ( self::roots() as $root ) {
            if ( $real === $root || str_starts_with( $real . '/', $root . '/' ) ) { return true; }
        }
        return false;
    }

    private static function existing_path( string $path, bool $directory = false ): string|WP_Error {
        $path = trim( $path );
        if ( '' === $path || str_contains( $path, "\0" ) ) { return self::error( 'toolchain_path_invalid', 'Path is invalid.' ); }
        $real = realpath( $path );
        if ( false === $real || ( $directory ? ! is_dir( $real ) : ! file_exists( $real ) ) ) {
            return self::error( 'toolchain_path_missing', 'Path does not exist.', 404 );
        }
        $real = self::normalize_slashes( $real );
        if ( ! self::path_inside_roots( $real ) ) { return self::error( 'toolchain_path_outside_root', 'Path is outside the allowed WordPress roots.', 403 ); }
        return $real;
    }

    private static function output_path( string $path ): string|WP_Error {
        $path = trim( $path );
        if ( '' === $path || str_contains( $path, "\0" ) ) { return self::error( 'toolchain_path_invalid', 'Output path is invalid.' ); }
        $parent = realpath( dirname( $path ) );
        if ( false === $parent || ! is_dir( $parent ) ) { return self::error( 'toolchain_parent_missing', 'Output parent directory does not exist.', 404 ); }
        $parent = self::normalize_slashes( $parent );
        if ( ! self::path_inside_roots( $parent ) ) { return self::error( 'toolchain_path_outside_root', 'Output path is outside the allowed WordPress roots.', 403 ); }
        return rtrim( $parent, '/' ) . '/' . basename( $path );
    }

    private static function binary_path( string $binary ): string {
        if ( '' === $binary || str_contains( $binary, '/' ) || str_contains( $binary, '\\' ) ) { return ''; }
        $path = (string) getenv( 'PATH' );
        foreach ( explode( PATH_SEPARATOR, $path ) as $dir ) {
            $candidate = rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $binary;
            if ( '\\' === DIRECTORY_SEPARATOR ) {
                // Node installations commonly ship both an extensionless POSIX
                // shim and npx.cmd.  Selecting the former first on Windows can
                // start an unusable process that never speaks MCP.  Resolve a
                // native/PATHEXT target before considering any bare file.
                foreach ( array( '.exe', '.com', '.cmd', '.bat' ) as $ext ) {
                    if ( is_file( $candidate . $ext ) ) { return $candidate . $ext; }
                }
                continue;
            }
            if ( is_file( $candidate ) && is_executable( $candidate ) ) { return $candidate; }
        }
        return '';
    }

    /** Resolve trusted argv without asking cmd.exe to interpret a batch file. */
    private static function executable_argv( array $command ): array|WP_Error {
        if ( ! $command || ! is_string( $command[0] ?? null ) ) {
            return self::error( 'toolchain_command_invalid', 'Internal command definition is invalid.', 500 );
        }
        $requested = basename( (string) $command[0] );
        $binary = self::binary_path( $requested );
        if ( '' === $binary ) {
            return self::error( 'toolchain_dependency_missing', 'Required local executable is unavailable.', 503, array( 'binary'=>$requested ) );
        }
        $arguments = array_slice( $command, 1 );
        if ( '\\' === DIRECTORY_SEPARATOR && in_array( strtolower( pathinfo( $binary, PATHINFO_EXTENSION ) ), array( 'cmd', 'bat' ), true ) ) {
            // npx.cmd is a shell wrapper. Launch its pinned JavaScript entry
            // point directly through node.exe so bypass_shell remains true.
            if ( 'npx' === strtolower( pathinfo( $requested, PATHINFO_FILENAME ) ) ) {
                $node = self::binary_path( 'node' );
                $npx_cli = dirname( $binary ) . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'npm' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'npx-cli.js';
                if ( '' !== $node && is_file( $npx_cli ) ) {
                    return array_merge( array( $node, $npx_cli ), $arguments );
                }
                $fixture = preg_replace( '/\.(?:cmd|bat)$/i', '', $binary );
                if ( defined( 'PRSTUDIO_UC_TESTING' ) && is_string( $fixture ) && is_file( $fixture ) ) {
                    return array_merge( array( PHP_BINARY, $fixture ), $arguments );
                }
            }
            return self::error( 'toolchain_windows_batch_launcher_unsupported', 'A trusted native launcher is required for this Windows sidecar.', 503, array( 'binary'=>$requested ) );
        }
        return array_merge( array( $binary ), $arguments );
    }

    private static function process_available(): bool {
        if ( ! function_exists( 'proc_open' ) ) { return false; }
        $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
        return ! in_array( 'proc_open', $disabled, true );
    }

    private static function sidecar_timeout(): int {
        return defined( 'PRSTUDIO_UC_TESTING' ) ? 5 : self::SIDECAR_TIMEOUT;
    }

    /** No shell: command MUST be an argv array assembled exclusively by trusted code. */
    private static function run_process( array $command, int $timeout = 20, ?string $stdin = null, array $env_overrides = array() ): array|WP_Error {
        if ( ! self::process_available() ) { return self::error( 'toolchain_process_unavailable', 'proc_open is unavailable on this host.', 503 ); }
        if ( ! $command || ! is_string( $command[0] ?? null ) || '' === (string) $command[0] ) { return self::error( 'toolchain_command_invalid', 'Internal command definition is invalid.', 500 ); }
        if ( is_executable( (string) $command[0] ) ) {
            $command = array_merge( array( (string) $command[0] ), array_slice( $command, 1 ) );
        } else {
            $command = self::executable_argv( $command );
            if ( is_wp_error( $command ) ) { return $command; }
        }
        $spec = array( 0=>array('pipe','r'), 1=>array('pipe','w'), 2=>array('pipe','w') );
        $base_env = getenv();
        $env = is_array( $base_env ) ? array_merge( $base_env, $env_overrides ) : null;
        $pipes = array();
        $proc = @proc_open( $command, $spec, $pipes, null, $env, array( 'bypass_shell'=>true, 'suppress_errors'=>true ) );
        if ( ! is_resource( $proc ) ) { return self::error( 'toolchain_process_start_failed', 'Unable to start local tool safely.', 503 ); }
        foreach ( array(1,2) as $i ) { stream_set_blocking( $pipes[$i], false ); }
        if ( null !== $stdin ) { fwrite( $pipes[0], $stdin ); }
        fclose( $pipes[0] );
        $stdout=''; $stderr=''; $deadline=microtime(true)+max(1,min(300,$timeout)); $timed_out=false;
        while ( true ) {
            $status=proc_get_status($proc);
            $stdout .= (string) stream_get_contents( $pipes[1] );
            $stderr .= (string) stream_get_contents( $pipes[2] );
            if ( strlen($stdout)+strlen($stderr) > self::PROCESS_OUTPUT_LIMIT ) { $timed_out=true; break; }
            if ( ! $status['running'] ) { break; }
            if ( microtime(true) >= $deadline ) { $timed_out=true; break; }
            usleep( 20000 );
        }
        if ( $timed_out ) { @proc_terminate( $proc ); usleep( 50000 ); @proc_terminate( $proc, 9 ); }
        $stdout .= (string) stream_get_contents( $pipes[1] );
        $stderr .= (string) stream_get_contents( $pipes[2] );
        fclose($pipes[1]); fclose($pipes[2]);
        $status=proc_get_status($proc); $exit=(int)($status['exitcode']??-1); $closed=@proc_close($proc); if($exit<0&&is_int($closed))$exit=$closed;
        if ( $timed_out ) { return self::error( 'toolchain_process_timeout', 'Local tool exceeded its bounded execution time.', 504, array('timeout_seconds'=>$timeout) ); }
        return array('ok'=>0===$exit,'exit_code'=>$exit,'stdout'=>substr($stdout,0,self::PROCESS_OUTPUT_LIMIT),'stderr'=>substr($stderr,0,262144),'verified'=>0===$exit);
    }

    private static function sidecar_profiles(): array {
        return array(
            'filesystem'=>array('kind'=>'mcp_sidecar','binary'=>'npx','package'=>'@modelcontextprotocol/server-filesystem@2026.7.10','mode'=>'sandboxed_root','source'=>'official_reference'),
            'sequential_thinking'=>array('kind'=>'mcp_sidecar','binary'=>'npx','package'=>'@modelcontextprotocol/server-sequential-thinking@2026.7.4','mode'=>'structured_reasoning','source'=>'official_reference'),
            'git'=>array('kind'=>'mcp_sidecar','binary'=>'uvx','package'=>'mcp-server-git==2026.7.10','mode'=>'sandboxed_repository','source'=>'official_reference'),
            'sqlite'=>array('kind'=>'mcp_sidecar','binary'=>'npx','package'=>'@mseep/mcp-server-sqlite-npx@0.3.0','mode'=>'sandboxed_database','source'=>'community_compat'),
            'postgres'=>array('kind'=>'disabled_upstream_security','binary'=>'docker','package'=>'crystaldba/postgres-mcp:0.3.0','mode'=>'disabled_until_patched_release','source'=>'upstream_project','security_note'=>'Upstream 0.3.0 has unresolved 2026 restricted-mode/ExplainPlan security reports; this optional executable is treated as technically unavailable until a compatible patched release exists.'),
            'accessibility'=>array('kind'=>'mcp_sidecar','binary'=>'npx','package'=>'@mseep/mcp-accessibility-scanner@1.0.7','mode'=>'read_scan','source'=>'community_compat'),
            'pdf'=>array('kind'=>'mcp_sidecar','binary'=>'npx','package'=>'@sylphx/pdf-reader-mcp@4.1.2','mode'=>'local_first','source'=>'upstream_project'),
            'mermaid'=>array('kind'=>'mcp_sidecar','binary'=>'npx','package'=>'@peng-shawn/mermaid-mcp-server@0.2.0','mode'=>'render','source'=>'community_compat'),
            'osv'=>array('kind'=>'mcp_sidecar','binary'=>'npx','package'=>'@cyanheads/osv-advisory-mcp-server@0.1.12','mode'=>'keyless_stdio','source'=>'upstream_project'),
            'local_wp'=>array('kind'=>'mcp_sidecar','binary'=>'npx','package'=>'@verygoodplugins/mcp-local-wp@1.1.0','mode'=>'read_only_default','source'=>'community_compat'),
            'playwright'=>array('kind'=>'native_supersedes_sidecar','binary'=>'','package'=>'@playwright/mcp','mode'=>'browser_agent','source'=>'official_upstream'),
            'chrome_devtools'=>array('kind'=>'native_supersedes_sidecar','binary'=>'','package'=>'chrome-devtools-mcp','mode'=>'browser_agent_cdp','source'=>'official_upstream'),
            'image_optimizer'=>array('kind'=>'native_supersedes_sidecar','binary'=>'','package'=>'mcp-image-optimizer','mode'=>'wp_image_editor','source'=>'native'),
            'pandoc'=>array('kind'=>'native_cli','binary'=>'pandoc','package'=>'mcp-pandoc','mode'=>'bounded_files','source'=>'community_compat'),
            'wordpress_mcp'=>array('kind'=>'native','binary'=>'','package'=>'WordPress MCP Adapter compatible discovery model','mode'=>'prstudio_mcp_v5','source'=>'native'),
            'wp_cli'=>array('kind'=>'native_cli','binary'=>'wp','package'=>'WP-CLI','mode'=>'read_only_allowlist','source'=>'native'),
        );
    }

    private static function profile_status( string $name, array $profile ): array {
        $kind=(string)($profile['kind']??''); $binary=(string)($profile['binary']??'');
        $disabled = str_starts_with( $kind, 'disabled_' );
        $available = ! $disabled && ( in_array($kind,array('native','native_supersedes_sidecar'),true) || (''!==$binary && ''!==self::binary_path($binary)) );
        $reason = $disabled ? 'disabled_due_to_verified_upstream_security_finding' : ( $available ? 'ready_or_on_demand' : 'runtime_missing' );
        if ('postgres'===$name && ! $disabled && ''===(string)getenv('DATABASE_URI')) { $available=false; $reason='existing_database_uri_missing'; }
        if ('osv'===$name && $available) { $reason='runtime_present_version_check_deferred_until_call'; }
        return array_merge($profile,array('name'=>$name,'available'=>$available,'availability_reason'=>$reason,'runtime_requirement'=>'osv'===$name?'node>=24':'','required_at_install'=>false,'requires_account'=>false,'requires_api_key'=>false,'started_at_boot'=>false));
    }

    public static function status( array $args = array() ): array {
        $profiles=array(); foreach(self::sidecar_profiles() as $name=>$profile){$profiles[$name]=self::profile_status($name,$profile);}
        return array(
            'ok'=>true,'version'=>self::VERSION,'mode'=>'native_first_optional_sidecars','installation_changed'=>false,'configuration_changed'=>false,
            'mcp_top_level_contract'=>'unchanged','browser_executor'=>'prstudio_browser_agent_only','allowed_roots'=>self::roots(),
            'profiles'=>$profiles,
            'routing'=>array(
                'wordpress'=>'PR STUDIO MCP v5 + capability search/describe/execute',
                'playwright'=>'existing typed Browser Agent tools',
                'chrome_devtools'=>'existing Browser Agent CDP/network/console/performance tools',
                'accessibility'=>'existing browser_accessibility_scan; optional axe-core sidecar when explicitly requested',
                'filesystem'=>'native sandbox + optional official filesystem MCP',
                'sequential_thinking'=>'native structured-session fallback + optional official Sequential Thinking MCP',
                'git'=>'native safe inspection + optional official Git MCP',
                'sqlite'=>'native PDO/SQLite when present + optional sidecar',
                'postgres'=>'disabled until a patched upstream release resolves the verified 0.3.0 security findings',
            ),
            'source'=>'toolchain_registry','verified'=>true,
        );
    }

    public static function filesystem_inspect( array $args ) {
        $action=strtolower((string)($args['action']??'stat')); $path=(string)($args['path']??self::default_root());
        $real=self::existing_path($path,'list'===$action); if(is_wp_error($real))return $real;
        if('stat'===$action){return array('ok'=>true,'path'=>$real,'type'=>is_dir($real)?'directory':'file','size'=>is_file($real)?filesize($real):null,'mtime_gmt'=>gmdate('c',(int)filemtime($real)),'sha256'=>is_file($real)?hash_file('sha256',$real):null,'source'=>'native_filesystem','verified'=>true);}
        if('read'===$action){if(!is_file($real)||!is_readable($real))return self::error('toolchain_file_unreadable','File is not readable.',404);$max=max(1,min(self::MAX_TEXT_BYTES,(int)($args['max_bytes']??262144)));$fh=fopen($real,'rb');$data=is_resource($fh)?fread($fh,$max+1):false;if(is_resource($fh))fclose($fh);if(false===$data)return self::error('toolchain_file_read_failed','File read failed.',500);$truncated=strlen($data)>$max;if($truncated)$data=substr($data,0,$max);return array('ok'=>true,'path'=>$real,'content'=>$data,'bytes'=>strlen($data),'truncated'=>$truncated,'sha256'=>hash_file('sha256',$real),'source'=>'native_filesystem','verified'=>true);}
        if('list'===$action){$limit=max(1,min(500,(int)($args['limit']??200)));$rows=array();$it=new DirectoryIterator($real);foreach($it as $item){if($item->isDot())continue;$candidate=self::normalize_slashes($item->getPathname());if(is_link($candidate))continue;$rows[]=array('name'=>$item->getFilename(),'path'=>$candidate,'type'=>$item->isDir()?'directory':'file','size'=>$item->isFile()?$item->getSize():null);if(count($rows)>=$limit)break;}usort($rows,static fn($a,$b)=>strcmp((string)$a['name'],(string)$b['name']));return array('ok'=>true,'path'=>$real,'items'=>$rows,'count'=>count($rows),'source'=>'native_filesystem','verified'=>true);}
        if('search'===$action){$query=(string)($args['query']??'');if(''===$query)return self::error('toolchain_search_query_required','Search query is required.');$limit=max(1,min(200,(int)($args['limit']??50)));$max_files=max(1,min(5000,(int)($args['max_files']??1000)));$hits=array();$seen=0;$scan=static function(string $p)use(&$hits,&$seen,$query,$limit,$max_files):void{if($seen>=$max_files||count($hits)>=$limit)return;$seen++;if(!is_file($p)||is_link($p)||filesize($p)>self::MAX_TEXT_BYTES||!self::path_inside_roots($p))return;$content=@file_get_contents($p,false,null,0,self::MAX_TEXT_BYTES);if(!is_string($content))return;$pos=stripos($content,$query);if(false===$pos)return;$hits[]=array('path'=>self::normalize_slashes($p),'offset'=>$pos,'snippet'=>substr($content,max(0,$pos-120),min(400,strlen($content)-max(0,$pos-120))));};if(is_file($real)){$scan($real);}else{$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS));foreach($iterator as $item){if($seen>=$max_files||count($hits)>=$limit)break;if(!$item->isFile()||$item->isLink())continue;$scan(self::normalize_slashes($item->getPathname()));}}return array('ok'=>true,'root'=>$real,'query'=>$query,'hits'=>$hits,'count'=>count($hits),'scanned_files'=>min($seen,$max_files),'source'=>'native_filesystem','verified'=>true);}
        return self::error('toolchain_filesystem_action_invalid','Unsupported filesystem inspection action.');
    }

    public static function filesystem_write( array $args ) {
        $action=strtolower((string)($args['action']??'write')); $path=(string)($args['path']??'');
        if('mkdir'===$action){$out=self::output_path($path);if(is_wp_error($out))return $out;if(file_exists($out))return self::error('toolchain_path_exists','Target already exists.',409);$ok=@mkdir($out,0755,false);return $ok&&is_dir($out)?array('ok'=>true,'path'=>$out,'action'=>'mkdir','verified'=>true,'source'=>'native_filesystem'):self::error('toolchain_mkdir_failed','Directory creation failed.',500);}
        $out=self::output_path($path);if(is_wp_error($out))return $out;$content=(string)($args['content']??'');if(strlen($content)>self::MAX_TEXT_BYTES)return self::error('toolchain_write_too_large','Write exceeds bounded size.');
        if('replace'===$action){if(!is_file($out)||!is_readable($out))return self::error('toolchain_file_missing','Target file is missing.',404);$needle=(string)($args['search']??'');$replacement=(string)($args['replace']??'');if(''===$needle)return self::error('toolchain_replace_search_required','Exact search text is required.');$before=(string)file_get_contents($out);$count=substr_count($before,$needle);$expected=max(1,min(100,(int)($args['expected_occurrences']??1)));if($count!==$expected)return self::error('toolchain_replace_ambiguous','Exact replacement occurrence count does not match.',409,array('expected'=>$expected,'found'=>$count));$content=str_replace($needle,$replacement,$before);}
        elseif('write'!==$action){return self::error('toolchain_filesystem_write_action_invalid','Unsupported filesystem write action.');}
        if(!empty($args['expected_sha256'])&&is_file($out)){ $current=hash_file('sha256',$out); if(!hash_equals(strtolower((string)$args['expected_sha256']),strtolower($current)))return self::error('toolchain_file_changed','Target changed since it was inspected.',409); }
        $tmp=$out.'.prstudio-'.bin2hex(random_bytes(6)).'.tmp';if(false===@file_put_contents($tmp,$content,LOCK_EX)){return self::error('toolchain_write_failed','Atomic staging write failed.',500);}if(!@rename($tmp,$out)){@unlink($tmp);return self::error('toolchain_write_failed','Atomic replace failed.',500);}clearstatcache(true,$out);$sha=hash_file('sha256',$out);return array('ok'=>true,'path'=>$out,'bytes'=>filesize($out),'sha256'=>$sha,'action'=>$action,'verified'=>is_string($sha)&&''!==$sha,'source'=>'native_filesystem');
    }

    private static function safe_ref( string $ref ): string|WP_Error {
        $ref=trim($ref);if(''===$ref)return '';if(!preg_match('/^[A-Za-z0-9._\/-]{1,190}$/',$ref)||str_contains($ref,'..'))return self::error('toolchain_git_ref_invalid','Git ref is invalid.');return $ref;
    }

    public static function git_inspect( array $args ) {
        $repo=(string)($args['repository']??self::default_root());$real=self::existing_path($repo,true);if(is_wp_error($real))return $real;if(!is_dir($real.'/.git'))return self::error('toolchain_git_repository_invalid','Path is not a Git working tree.',400);
        $git=self::binary_path('git');if(''===$git)return self::error('toolchain_git_missing','Git is unavailable on this host.',503);$action=strtolower((string)($args['action']??'status'));$limit=max(1,min(200,(int)($args['limit']??50)));$cmd=array($git,'-C',$real);
        if('status'===$action)$cmd=array_merge($cmd,array('status','--short','--branch'));
        elseif('log'===$action)$cmd=array_merge($cmd,array('log','--no-decorate','--date=iso-strict','--pretty=format:%H%x09%ad%x09%an%x09%s','-n',(string)$limit));
        elseif('diff'===$action){$ref=self::safe_ref((string)($args['ref']??''));if(is_wp_error($ref))return $ref;$cmd[]= 'diff';$cmd[]='--no-ext-diff';$cmd[]='--no-color';if(''!==$ref)$cmd[]=$ref;if(!empty($args['file'])){$file=(string)$args['file'];if(str_contains($file,'..')||str_starts_with($file,'/'))return self::error('toolchain_git_path_invalid','Repository-relative file path is invalid.');$cmd[]='--';$cmd[]=$file;}}
        elseif('show'===$action){$ref=self::safe_ref((string)($args['ref']??'HEAD'));if(is_wp_error($ref))return $ref;$cmd=array_merge($cmd,array('show','--no-ext-diff','--no-color','--stat','--oneline',$ref));}
        elseif('branches'===$action)$cmd=array_merge($cmd,array('branch','--format=%(refname:short)%09%(objectname:short)%09%(upstream:short)'));
        elseif('remotes'===$action)$cmd=array_merge($cmd,array('remote','-v'));
        else return self::error('toolchain_git_action_invalid','Unsupported safe Git inspection action.');
        $run=self::run_process($cmd,30);if(is_wp_error($run))return $run;if(empty($run['ok']))return self::error('toolchain_git_failed','Git inspection failed.',409,array('exit_code'=>$run['exit_code'],'stderr'=>$run['stderr']));return array('ok'=>true,'repository'=>$real,'action'=>$action,'output'=>$run['stdout'],'source'=>'native_git','verified'=>true);
    }

    private static function sqlite_read_sql( string $sql ): bool {
        $trim=ltrim($sql);if(''===$trim||str_contains($trim,"\0")||preg_match('/;\s*\S/s',$trim))return false;
        if(!preg_match('/^(SELECT|WITH|EXPLAIN|PRAGMA\s+(table_info|index_list|index_info|foreign_key_list|database_list)\b)/i',$trim))return false;
        return !preg_match('/\b(ATTACH|DETACH|INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|VACUUM|REINDEX|LOAD_EXTENSION|writable_schema)\b/i',$trim);
    }

    public static function sqlite_query( array $args ) {
        $db=self::existing_path((string)($args['database']??''),false);if(is_wp_error($db))return $db;if(!is_file($db))return self::error('toolchain_sqlite_db_invalid','SQLite database must be a file.');$sql=(string)($args['sql']??'');if(!self::sqlite_read_sql($sql))return self::error('toolchain_sqlite_query_denied','Only bounded read-only SQLite statements are allowed.',403);$params=is_array($args['params']??null)?array_values($args['params']):array();$limit=max(1,min(1000,(int)($args['limit']??200)));
        if(class_exists('SQLite3')){$conn=new SQLite3($db,SQLITE3_OPEN_READONLY);$stmt=$conn->prepare($sql);if(!$stmt){$msg=$conn->lastErrorMsg();$conn->close();return self::error('toolchain_sqlite_prepare_failed','SQLite query could not be prepared.',400,array('sqlite_error'=>$msg));}foreach($params as $i=>$value){$type=is_int($value)?SQLITE3_INTEGER:(is_float($value)?SQLITE3_FLOAT:(null===$value?SQLITE3_NULL:SQLITE3_TEXT));$stmt->bindValue($i+1,$value,$type);} $res=$stmt->execute();if(!$res){$msg=$conn->lastErrorMsg();$conn->close();return self::error('toolchain_sqlite_query_failed','SQLite read failed.',400,array('sqlite_error'=>$msg));}$rows=array();while(($row=$res->fetchArray(SQLITE3_ASSOC))&&count($rows)<$limit)$rows[]=$row;$res->finalize();$stmt->close();$conn->close();return array('ok'=>true,'database'=>$db,'rows'=>$rows,'count'=>count($rows),'truncated'=>count($rows)>=$limit,'source'=>'native_sqlite','verified'=>true);}
        if(class_exists('PDO')&&in_array('sqlite',PDO::getAvailableDrivers(),true)){$pdo=new PDO('sqlite:'.$db,null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));$stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$rows=array_slice($rows,0,$limit);return array('ok'=>true,'database'=>$db,'rows'=>$rows,'count'=>count($rows),'truncated'=>count($rows)>=$limit,'source'=>'pdo_sqlite','verified'=>true);}
        return self::error('toolchain_sqlite_driver_missing','PHP SQLite support is unavailable; the optional pinned SQLite MCP sidecar can be used instead.',503);
    }

    public static function postgres_query( array $args ) {
        $uri=(string)getenv('DATABASE_URI');if(''===$uri)return self::error('toolchain_postgres_uri_unavailable','DATABASE_URI is not present in the existing host environment; PR STUDIO will not ask you to configure a new credential.',503);$sql=(string)($args['sql']??'');if(!preg_match('/^\s*(SELECT|WITH|EXPLAIN|SHOW)\b/i',$sql)||preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|COPY|GRANT|REVOKE|CALL|DO)\b/i',$sql))return self::error('toolchain_postgres_query_denied','Only restricted read-only PostgreSQL queries are allowed.',403);$limit=max(1,min(1000,(int)($args['limit']??200)));
        if(class_exists('PDO')&&in_array('pgsql',PDO::getAvailableDrivers(),true)){try{
            $parts=parse_url($uri);if(!is_array($parts)||!isset($parts['host']))return self::error('toolchain_postgres_uri_invalid','Existing DATABASE_URI is not a valid PostgreSQL URI.',400);
            $dsn='pgsql:host='.$parts['host'].';port='.(int)($parts['port']??5432).';dbname='.ltrim((string)($parts['path']??''),'/');
            $user=isset($parts['user'])?rawurldecode((string)$parts['user']):null;$pass=isset($parts['pass'])?rawurldecode((string)$parts['pass']):null;
            $pdo=new PDO($dsn,$user,$pass,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5));$pdo->exec('SET default_transaction_read_only = on');
            $stmt=$pdo->prepare($sql);$stmt->execute(is_array($args['params']??null)?array_values($args['params']):array());$rows=array_slice($stmt->fetchAll(PDO::FETCH_ASSOC),0,$limit);return array('ok'=>true,'rows'=>$rows,'count'=>count($rows),'truncated'=>count($rows)>=$limit,'source'=>'pdo_pgsql_restricted','verified'=>true);
        }catch(Throwable $e){return self::error('toolchain_postgres_query_failed','Restricted PostgreSQL read failed.',502,array('exception_class'=>get_class($e)));}}
        return self::error('toolchain_postgres_driver_missing','PHP PostgreSQL support is unavailable; the optional restricted Postgres MCP sidecar can be used instead.',503);
    }

    public static function wpcli_inspect( array $args ) {
        $wp=self::binary_path('wp');if(''===$wp)return self::error('toolchain_wpcli_missing','WP-CLI is not installed on this host. The suite remains fully operational without it.',503);$root=self::existing_path((string)($args['path']??self::default_root()),true);if(is_wp_error($root))return $root;$action=strtolower((string)($args['action']??'core_version'));$map=array(
            'core_version'=>array('core','version'), 'plugin_list'=>array('plugin','list','--format=json'), 'theme_list'=>array('theme','list','--format=json'),
            'cron_list'=>array('cron','event','list','--format=json'), 'db_size'=>array('db','size','--format=json'),
        );
        if('option_get'===$action){$name=(string)($args['name']??'');if(!preg_match('/^[A-Za-z0-9_.:-]{1,190}$/',$name))return self::error('toolchain_wpcli_option_invalid','Option name is invalid.');$tail=array('option','get',$name,'--format=json');}
        elseif(isset($map[$action]))$tail=$map[$action];else return self::error('toolchain_wpcli_action_denied','WP-CLI command is outside the read-only allowlist.',403);
        $cmd=array_merge(array($wp,'--path='.$root,'--no-color','--skip-plugins','--skip-themes'),$tail);$run=self::run_process($cmd,45);if(is_wp_error($run))return $run;if(empty($run['ok']))return self::error('toolchain_wpcli_failed','WP-CLI read failed.',409,array('exit_code'=>$run['exit_code'],'stderr'=>$run['stderr']));return array('ok'=>true,'action'=>$action,'output'=>$run['stdout'],'source'=>'wp_cli_read_only','verified'=>true);
    }

    public static function image_optimize( array $args ) {
        if(!function_exists('wp_get_image_editor'))return self::error('toolchain_image_editor_unavailable','WordPress image editor is unavailable.',503);$input=self::existing_path((string)($args['path']??''),false);if(is_wp_error($input))return $input;if(!is_file($input))return self::error('toolchain_image_invalid','Input image must be a file.');$editor=wp_get_image_editor($input);if(is_wp_error($editor))return $editor;$max_w=max(1,min(8192,(int)($args['max_width']??2048)));$max_h=max(1,min(8192,(int)($args['max_height']??2048)));$quality=max(30,min(100,(int)($args['quality']??82)));$editor->set_quality($quality);$size=$editor->get_size();if(is_array($size)&&(($size['width']??0)>$max_w||($size['height']??0)>$max_h)){$r=$editor->resize($max_w,$max_h,false);if(is_wp_error($r))return $r;}$output=(string)($args['output_path']??'');if(''===$output)$output=dirname($input).'/'.pathinfo($input,PATHINFO_FILENAME).'-optimized.'.pathinfo($input,PATHINFO_EXTENSION);$out=self::output_path($output);if(is_wp_error($out))return $out;$saved=$editor->save($out);if(is_wp_error($saved))return $saved;if(!is_file($out))return self::error('toolchain_image_save_failed','Optimized image was not created.',500);return array('ok'=>true,'input'=>$input,'output'=>$out,'bytes'=>filesize($out),'sha256'=>hash_file('sha256',$out),'mime'=>$saved['mime-type']??'','source'=>'wp_image_editor','verified'=>true);
    }

    public static function pdf_read( array $args ) {
        $pdf=self::existing_path((string)($args['path']??''),false);if(is_wp_error($pdf))return $pdf;if('pdf'!==strtolower(pathinfo($pdf,PATHINFO_EXTENSION)))return self::error('toolchain_pdf_extension_invalid','Input must be a PDF file.');$bin=self::binary_path('pdftotext');if(''===$bin)return self::error('toolchain_pdftotext_missing','pdftotext is unavailable; the pinned PDF MCP sidecar can be used instead.',503);$first=max(1,min(100000,(int)($args['first_page']??1)));$last=max($first,min(100000,(int)($args['last_page']??$first+49)));$cmd=array($bin,'-f',(string)$first,'-l',(string)$last,'-enc','UTF-8','-layout',$pdf,'-');$run=self::run_process($cmd,60);if(is_wp_error($run))return $run;if(empty($run['ok']))return self::error('toolchain_pdf_read_failed','PDF extraction failed.',409,array('exit_code'=>$run['exit_code'],'stderr'=>$run['stderr']));$max=max(1,min(self::MAX_TEXT_BYTES,(int)($args['max_bytes']??524288)));$text=substr((string)$run['stdout'],0,$max);return array('ok'=>true,'path'=>$pdf,'first_page'=>$first,'last_page'=>$last,'content'=>$text,'truncated'=>strlen((string)$run['stdout'])>$max,'source'=>'pdftotext_local','verified'=>true);
    }

    public static function pandoc_convert( array $args ) {
        $pandoc=self::binary_path('pandoc');if(''===$pandoc)return self::error('toolchain_pandoc_missing','Pandoc is not installed; no new setup is required for the rest of PR STUDIO.',503);$input=self::existing_path((string)($args['input']??''),false);if(is_wp_error($input))return $input;$output=self::output_path((string)($args['output']??''));if(is_wp_error($output))return $output;$from=(string)($args['from']??'');$to=(string)($args['to']??'');foreach(array($from,$to) as $format){if(''!==$format&&!preg_match('/^[a-zA-Z0-9_+.-]{1,40}$/',$format))return self::error('toolchain_pandoc_format_invalid','Pandoc format is invalid.');}$cmd=array($pandoc,$input,'--output',$output);if(''!==$from)$cmd[]= '--from='.$from;if(''!==$to)$cmd[]= '--to='.$to;$run=self::run_process($cmd,120);if(is_wp_error($run))return $run;if(empty($run['ok'])||!is_file($output))return self::error('toolchain_pandoc_failed','Pandoc conversion failed.',409,array('exit_code'=>$run['exit_code'],'stderr'=>$run['stderr']));return array('ok'=>true,'input'=>$input,'output'=>$output,'bytes'=>filesize($output),'sha256'=>hash_file('sha256',$output),'source'=>'pandoc_local','verified'=>true);
    }

    private static function sidecar_command( string $profile, array $args ): array|WP_Error {
        $profiles=self::sidecar_profiles();if(!isset($profiles[$profile]))return self::error('toolchain_sidecar_unknown','Unknown fixed MCP sidecar profile.',404);$meta=$profiles[$profile];$kind=(string)($meta['kind']??'');if(str_starts_with($kind,'disabled_'))return self::error('toolchain_sidecar_upstream_security_hold','This optional sidecar is disabled until a patched upstream release is verified.',503,array('profile'=>$profile,'package'=>(string)($meta['package']??''),'security_note'=>(string)($meta['security_note']??'')));if('mcp_sidecar'!==$kind)return self::error('toolchain_sidecar_native_preferred','This profile is served natively by PR STUDIO and is not launched as a second sidecar.',409,array('profile'=>$profile));
        $env=array();$root=(string)($args['root']??self::default_root());
        if('filesystem'===$profile){$root=self::existing_path($root,true);if(is_wp_error($root))return $root;return array('command'=>array('npx','-y',$meta['package'],$root),'env'=>$env,'profile'=>$meta);}
        if('sequential_thinking'===$profile)return array('command'=>array('npx','-y',$meta['package']),'env'=>array('DISABLE_THOUGHT_LOGGING'=>'true'),'profile'=>$meta);
        if('git'===$profile){$repo=self::existing_path((string)($args['repository']??$root),true);if(is_wp_error($repo))return $repo;if(!is_dir($repo.'/.git'))return self::error('toolchain_git_repository_invalid','Path is not a Git working tree.');return array('command'=>array('uvx',$meta['package'],'--repository',$repo),'env'=>$env,'profile'=>$meta);}
        if('sqlite'===$profile){$db=self::existing_path((string)($args['database']??''),false);if(is_wp_error($db))return $db;return array('command'=>array('npx','-y',$meta['package'],$db),'env'=>$env,'profile'=>$meta);}
        if('postgres'===$profile){if(''===self::binary_path('docker'))return self::error('toolchain_docker_missing','Docker is unavailable on this host.',503);if(''===(string)getenv('DATABASE_URI'))return self::error('toolchain_postgres_uri_unavailable','Existing DATABASE_URI is required; PR STUDIO will not create or ask for a new credential.',503);return array('command'=>array('docker','run','-i','--rm','-e','DATABASE_URI',$meta['package'],'--access-mode=restricted'),'env'=>$env,'profile'=>$meta);}
        if('accessibility'===$profile)return array('command'=>array('npx','-y',$meta['package']),'env'=>array('MCP_PROXY_DEBUG'=>'false'),'profile'=>$meta);
        if('pdf'===$profile)return array('command'=>array('npx','-y',$meta['package']),'env'=>array('MCP_TRANSPORT'=>'stdio'),'profile'=>$meta);
        if('mermaid'===$profile)return array('command'=>array('npx','-y',$meta['package']),'env'=>array('CONTENT_IMAGE_SUPPORTED'=>'true'),'profile'=>$meta);
        if('osv'===$profile){$node=self::binary_path('node');if(''===$node)return self::error('toolchain_node_missing','Node.js is unavailable for the OSV sidecar.',503);$probe=self::run_process(array($node,'--version'),5);if(is_wp_error($probe))return $probe;$major=preg_match('/v?(\d+)/',(string)($probe['stdout']??''),$m)?(int)$m[1]:0;if($major>0&&$major<24)return self::error('node_24_required_by_sidecar','The pinned OSV sidecar requires Node.js 24 or newer; PR STUDIO itself remains unaffected.',503);return array('command'=>array('npx','-y',$meta['package']),'env'=>array('MCP_TRANSPORT_TYPE'=>'stdio','MCP_LOG_LEVEL'=>'warn','OTEL_ENABLED'=>'false','STORAGE_PROVIDER_TYPE'=>'in-memory'),'profile'=>$meta);}
        if('local_wp'===$profile)return array('command'=>array('npx','-y',$meta['package']),'env'=>array('WPCLI_ALLOW_WRITES'=>'false','MYSQL_ALLOW_WRITES'=>'false','FS_ALLOW_WRITES'=>'false'),'profile'=>$meta);
        return self::error('toolchain_sidecar_unsupported','Sidecar profile is not executable.',503);
    }

    private static function read_mcp_response( $proc, string $stdout_path, string $stderr_path, int $wanted_id, float $deadline, int &$offset, string &$buffer ): array|WP_Error {
        $stderr = '';
        while ( microtime( true ) < $deadline ) {
            clearstatcache( true, $stdout_path );
            $size = @filesize( $stdout_path );
            if ( is_int( $size ) && $size > $offset ) {
                $remaining = min( self::PROCESS_OUTPUT_LIMIT - strlen( $buffer ), $size - $offset );
                if ( $remaining <= 0 ) { return self::error( 'toolchain_sidecar_output_limit', 'MCP sidecar exceeded output limit.', 502 ); }
                $handle = @fopen( $stdout_path, 'rb' );
                if ( is_resource( $handle ) ) {
                    @fseek( $handle, $offset );
                    $chunk = @fread( $handle, $remaining );
                    @fclose( $handle );
                    if ( is_string( $chunk ) && '' !== $chunk ) {
                        $offset += strlen( $chunk );
                        $buffer .= $chunk;
                    }
                }
            }
            $stderr_value = @file_get_contents( $stderr_path );
            $stderr = is_string( $stderr_value ) ? $stderr_value : '';
            if ( $offset + strlen( $buffer ) + strlen( $stderr ) > self::PROCESS_OUTPUT_LIMIT ) {
                return self::error( 'toolchain_sidecar_output_limit', 'MCP sidecar exceeded output limit.', 502 );
            }
            while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
                $line = trim( substr( $buffer, 0, $pos ) );
                $buffer = substr( $buffer, $pos + 1 );
                if ( '' === $line ) { continue; }
                $message = json_decode( $line, true );
                if ( ! is_array( $message ) || (int) ( $message['id'] ?? -1 ) !== $wanted_id ) { continue; }
                if ( isset( $message['error'] ) ) {
                    return self::error( 'toolchain_sidecar_rpc_error', 'MCP sidecar returned an RPC error.', 502, array( 'rpc_error'=>$message['error'], 'stderr'=>substr( $stderr, 0, 4096 ) ) );
                }
                return (array) ( $message['result'] ?? array() );
            }
            $status = @proc_get_status( $proc );
            if ( ! is_array( $status ) || empty( $status['running'] ) ) { break; }
            usleep( 20000 );
        }
        return self::error( 'toolchain_sidecar_timeout', 'MCP sidecar did not return the expected response in time.', 504, array( 'stderr'=>substr( $stderr, 0, 4096 ) ) );
    }

    /**
     * Close an stdio child without racing Windows process teardown.
     *
     * MCP servers normally stay alive until stdin reaches EOF.  Closing stdin
     * first lets a conforming child leave on its own; terminating it before the
     * pipe is closed can leave proc_close() waiting forever on Windows.
     */
    private static function close_sidecar_process( $proc, array $pipes ): void {
        if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) { @fclose( $pipes[0] ); }
        $deadline = microtime( true ) + 1.0;
        $running = true;
        do {
            $status = @proc_get_status( $proc );
            $running = is_array( $status ) && ! empty( $status['running'] );
            if ( ! $running ) { break; }
            usleep( 20000 );
        } while ( microtime( true ) < $deadline );
        if ( $running ) {
            @proc_terminate( $proc );
            $deadline = microtime( true ) + 1.0;
            do {
                $status = @proc_get_status( $proc );
                $running = is_array( $status ) && ! empty( $status['running'] );
                if ( ! $running ) { break; }
                usleep( 20000 );
            } while ( microtime( true ) < $deadline );
        }
        if ( $running && '\\' !== DIRECTORY_SEPARATOR ) { @proc_terminate( $proc, 9 ); }
        foreach ( array( 1, 2 ) as $index ) {
            if ( isset( $pipes[$index] ) && is_resource( $pipes[$index] ) ) {
                @stream_get_contents( $pipes[$index] );
                @fclose( $pipes[$index] );
            }
        }
        // proc_close is safe once the child stopped. Avoid its unbounded wait
        // if a hostile/broken Windows process ignored termination.
        $status = @proc_get_status( $proc );
        if ( is_array( $status ) && empty( $status['running'] ) ) { @proc_close( $proc ); }
    }

    private static function mcp_sidecar_rpc( string $profile, string $method, array $params, array $context ): array|WP_Error {
        $built = self::sidecar_command( $profile, $context );
        if ( is_wp_error( $built ) ) { return $built; }
        if ( ! self::process_available() ) { return self::error( 'toolchain_process_unavailable', 'proc_open is unavailable on this host.', 503 ); }
        $command = self::executable_argv( (array) $built['command'] );
        if ( is_wp_error( $command ) ) { return $command; }

        // PHP anonymous stdout pipes can remain blocking on Windows even after
        // stream_set_blocking(false). Use private bounded spool files so an MCP
        // server may stay alive between JSON-RPC messages without deadlocking.
        $stdout_path = @tempnam( sys_get_temp_dir(), 'prstudio-mcp-out-' );
        $stderr_path = @tempnam( sys_get_temp_dir(), 'prstudio-mcp-err-' );
        if ( false === $stdout_path || false === $stderr_path ) {
            if ( is_string( $stdout_path ) ) { @unlink( $stdout_path ); }
            if ( is_string( $stderr_path ) ) { @unlink( $stderr_path ); }
            return self::error( 'toolchain_sidecar_spool_failed', 'Unable to create bounded MCP sidecar output storage.', 503 );
        }
        @chmod( $stdout_path, 0600 );
        @chmod( $stderr_path, 0600 );
        $base_env = getenv();
        $env = is_array( $base_env ) ? array_merge( $base_env, (array) $built['env'] ) : null;
        $spec = array( 0=>array( 'pipe', 'r' ), 1=>array( 'file', $stdout_path, 'a' ), 2=>array( 'file', $stderr_path, 'a' ) );
        $pipes = array();
        $proc = @proc_open( $command, $spec, $pipes, null, $env, array( 'bypass_shell'=>true, 'suppress_errors'=>true ) );
        if ( ! is_resource( $proc ) ) {
            @unlink( $stdout_path );
            @unlink( $stderr_path );
            return self::error( 'toolchain_sidecar_start_failed', 'Unable to start pinned MCP sidecar.', 503 );
        }
        $offset = 0;
        $buffer = '';
        $init = array( 'jsonrpc'=>'2.0', 'id'=>1, 'method'=>'initialize', 'params'=>array( 'protocolVersion'=>self::MCP_PROTOCOL, 'capabilities'=>new stdClass(), 'clientInfo'=>array( 'name'=>'prstudio-unified-control', 'version'=>self::VERSION ) ) );
        $init_json = json_encode( $init, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $init_json ) || false === @fwrite( $pipes[0], $init_json . "\n" ) ) {
            self::close_sidecar_process( $proc, $pipes );
            @unlink( $stdout_path );
            @unlink( $stderr_path );
            return self::error( 'toolchain_sidecar_write_failed', 'Unable to send MCP initialize request.', 502 );
        }
        @fflush( $pipes[0] );
        $initialized = self::read_mcp_response( $proc, $stdout_path, $stderr_path, 1, microtime( true ) + self::sidecar_timeout(), $offset, $buffer );
        if ( is_wp_error( $initialized ) ) {
            self::close_sidecar_process( $proc, $pipes );
            @unlink( $stdout_path );
            @unlink( $stderr_path );
            return $initialized;
        }
        $notification = json_encode( array( 'jsonrpc'=>'2.0', 'method'=>'notifications/initialized' ), JSON_UNESCAPED_SLASHES );
        $request = json_encode( array( 'jsonrpc'=>'2.0', 'id'=>2, 'method'=>$method, 'params'=>$params ), JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $notification ) || ! is_string( $request ) || false === @fwrite( $pipes[0], $notification . "\n" . $request . "\n" ) ) {
            self::close_sidecar_process( $proc, $pipes );
            @unlink( $stdout_path );
            @unlink( $stderr_path );
            return self::error( 'toolchain_sidecar_write_failed', 'Unable to send MCP tool request.', 502 );
        }
        @fflush( $pipes[0] );
        $result = self::read_mcp_response( $proc, $stdout_path, $stderr_path, 2, microtime( true ) + self::sidecar_timeout(), $offset, $buffer );
        self::close_sidecar_process( $proc, $pipes );
        @unlink( $stdout_path );
        @unlink( $stderr_path );
        if ( is_wp_error( $result ) ) { return $result; }
        return array( 'ok'=>true, 'profile'=>$profile, 'protocol'=>(string) ( $initialized['protocolVersion'] ?? self::MCP_PROTOCOL ), 'result'=>$result, 'source'=>'pinned_mcp_sidecar', 'verified'=>true );
    }

    public static function sequential_thinking( array $args ) {
        $payload=array(
            'thought'=>(string)($args['thought']??''),
            'nextThoughtNeeded'=>(bool)($args['nextThoughtNeeded']??$args['next_thought_needed']??false),
            'thoughtNumber'=>(int)($args['thoughtNumber']??$args['thought_number']??1),
            'totalThoughts'=>(int)($args['totalThoughts']??$args['total_thoughts']??1),
        );
        foreach(array('isRevision'=>'isRevision','revisesThought'=>'revisesThought','branchFromThought'=>'branchFromThought','branchId'=>'branchId','needsMoreThoughts'=>'needsMoreThoughts') as $source=>$target){if(array_key_exists($source,$args))$payload[$target]=$args[$source];}
        foreach(array('is_revision'=>'isRevision','revises_thought'=>'revisesThought','branch_from_thought'=>'branchFromThought','branch_id'=>'branchId','needs_more_thoughts'=>'needsMoreThoughts') as $source=>$target){if(!array_key_exists($target,$payload)&&array_key_exists($source,$args))$payload[$target]=$args[$source];}
        if(''===trim($payload['thought']))return self::error('sequential_thinking_thought_required','thought is required.');
        if($payload['thoughtNumber']<1||$payload['totalThoughts']<1)return self::error('sequential_thinking_number_invalid','thoughtNumber and totalThoughts must be positive integers.');
        return self::mcp_sidecar_rpc('sequential_thinking','tools/call',array('name'=>'sequential_thinking','arguments'=>$payload),array());
    }

    public static function sidecar_tools( array $args ) {
        $profile=strtolower((string)($args['profile']??''));return self::mcp_sidecar_rpc($profile,'tools/list',array(),$args);
    }

    private static function read_only_sidecar_tool( string $profile, string $tool ): bool {
        if(in_array($profile,array('accessibility','pdf','osv','postgres','local_wp'),true))return true;
        $deny='/\b(write|edit|create|delete|remove|move|rename|commit|add|checkout|merge|rebase|insert|update|execute|exec|run|install|activate|deactivate|reset|push|pull|generate|render|export)\b/i';
        return !preg_match($deny,str_replace(array('/','_','-'), ' ', $tool));
    }

    private static function contains_remote_url( $value, int $depth = 0 ): bool {
        if($depth>12)return true;if(is_string($value))return (bool)preg_match('#https?://#i',$value);if(!is_array($value))return false;foreach($value as $child){if(self::contains_remote_url($child,$depth+1))return true;}return false;
    }

    public static function sidecar_read( array $args ) {
        $profile=strtolower((string)($args['profile']??''));$tool=(string)($args['tool']??'');if(''===$tool)return self::error('toolchain_sidecar_tool_required','MCP tool name is required.');if(!self::read_only_sidecar_tool($profile,$tool))return self::error('toolchain_sidecar_write_denied','This tool name is not allowed through the read-only sidecar lane.',403);$tool_args=is_array($args['arguments']??null)?$args['arguments']:array();if('pdf'===$profile&&self::contains_remote_url($tool_args))return self::error('toolchain_pdf_remote_source_denied','The read-only PDF sidecar lane accepts local files only.',403);return self::mcp_sidecar_rpc($profile,'tools/call',array('name'=>$tool,'arguments'=>$tool_args),$args);
    }

    public static function sidecar_call( array $args ) {
        $profile=strtolower((string)($args['profile']??''));$tool=(string)($args['tool']??'');if(''===$tool)return self::error('toolchain_sidecar_tool_required','MCP tool name is required.');return self::mcp_sidecar_rpc($profile,'tools/call',array('name'=>$tool,'arguments'=>is_array($args['arguments']??null)?$args['arguments']:array()),$args);
    }

    public static function accessibility_axe_scan( array $args ) {
        $url=trim((string)($args['url']??''));if(!preg_match('#^https?://#i',$url))return self::error('toolchain_accessibility_url_invalid','Accessibility scan requires an HTTP(S) URL.');
        $payload=array('url'=>$url,'violationsTag'=>array_values((array)($args['violationsTag']??array('wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa'))),'shouldRunInHeadless'=>true);
        if(is_array($args['viewport']??null))$payload['viewport']=$args['viewport'];
        return self::mcp_sidecar_rpc('accessibility','tools/call',array('name'=>'scan_accessibility','arguments'=>$payload),array());
    }

    public static function mermaid_render( array $args ) {
        $code=(string)($args['code']??'');if(''===trim($code)||strlen($code)>262144)return self::error('toolchain_mermaid_code_invalid','Mermaid source is empty or exceeds the bounded size.');
        $payload=array('code'=>$code,'outputFormat'=>(string)($args['outputFormat']??'svg'));
        foreach(array('theme','backgroundColor') as $key){if(isset($args[$key])&&''!==(string)$args[$key])$payload[$key]=(string)$args[$key];}
        if(isset($args['name'])&&''!==(string)$args['name']){$name=(string)$args['name'];if(!preg_match('/^[A-Za-z0-9._-]{1,190}$/',$name))return self::error('toolchain_mermaid_name_invalid','Mermaid output name must be a safe basename.');$payload['name']=$name;}
        if(!empty($args['folder'])){$folder=self::existing_path((string)$args['folder'],true);if(is_wp_error($folder))return $folder;$payload['folder']=$folder;}
        return self::mcp_sidecar_rpc('mermaid','tools/call',array('name'=>'generate','arguments'=>$payload),array());
    }

    public static function osv_audit( array $args ) {
        $action=strtolower((string)($args['action']??'query_package'));
        $map=array('query_package'=>'osv_query_package','query_batch'=>'osv_query_batch','get_vulnerability'=>'osv_get_vulnerability','list_ecosystems'=>'osv_list_ecosystems');
        if(!isset($map[$action]))return self::error('toolchain_osv_action_invalid','Unsupported OSV audit action.');
        $payload=is_array($args['arguments']??null)?$args['arguments']:array();
        return self::mcp_sidecar_rpc('osv','tools/call',array('name'=>$map[$action],'arguments'=>$payload),array());
    }

    public static function pdf_mcp_evidence( array $args ) {
        $operation=strtolower((string)($args['operation']??'read'));$map=array('read'=>'read_pdf','search'=>'search_pdf','evidence'=>'pdf_evidence');if(!isset($map[$operation]))return self::error('toolchain_pdf_operation_invalid','Unsupported PDF MCP operation.');
        $payload=is_array($args['arguments']??null)?$args['arguments']:array();if(self::contains_remote_url($payload))return self::error('toolchain_pdf_remote_source_denied','PDF evidence facade accepts local files only.',403);
        foreach(array('sources','source') as $key){if(!isset($payload[$key]))continue;$items='sources'===$key?(array)$payload[$key]:array($payload[$key]);foreach($items as $item){if(is_array($item)&&isset($item['path'])){$safe=self::existing_path((string)$item['path'],false);if(is_wp_error($safe))return $safe;}}}
        return self::mcp_sidecar_rpc('pdf','tools/call',array('name'=>$map[$operation],'arguments'=>$payload),array());
    }

    public static function local_wp_read( array $args ) {
        $tool=trim((string)($args['tool']??''));if(''===$tool)return self::error('toolchain_local_wp_tool_required','Local WP MCP tool name is required.');
        return self::mcp_sidecar_rpc('local_wp','tools/call',array('name'=>$tool,'arguments'=>is_array($args['arguments']??null)?$args['arguments']:array()),array());
    }
}
