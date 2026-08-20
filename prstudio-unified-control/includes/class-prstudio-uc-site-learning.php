<?php
// phpcs:ignore missing_direct_file_access_protection -- testable direct-access guard.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Durable per-origin learning memory for arbitrary websites.
 *
 * The module never authorizes an action. It records what the Browser Agent
 * actually observed after execution, and it turns a verified rendered crawl
 * into bounded read-only observation batches. Anti-Crash remains the only
 * mutation guard; this class performs no site mutation at all.
 */
final class PRSTUDIO_UC_Site_Learning {
    public const VERSION = '1.0.0';
    private const SCHEMA_VERSION = 1;
    private const DEFAULT_MAX_PAGES = 120;
    private const MAX_PAGES = 1500;
    private const DEFAULT_BATCH_PAGES = 25;
    private const MAX_BATCH_PAGES = 25; // 9 read-only probes/page => <=225 flow steps.
    private const MAX_EVIDENCE_ROWS = 200;
    private const MAX_FAILURES = 50;

    private static function clamp( $value, int $min, int $max, int $fallback ): int {
        $n = is_numeric( $value ) ? (int) $value : $fallback;
        return max( $min, min( $max, $n ) );
    }

    private static function root(): string {
        $base = class_exists( 'PRSTUDIO_UC_Memory' ) && is_callable( array( 'PRSTUDIO_UC_Memory', 'site_dir' ) )
            ? (string) PRSTUDIO_UC_Memory::site_dir()
            : ( defined( 'WP_CONTENT_DIR' ) ? rtrim( WP_CONTENT_DIR, '/\\' ) . '/prstudio-unified-private' : sys_get_temp_dir() . '/prstudio-site-learning' );
        $dir = rtrim( $base, '/\\' ) . '/site-modules';
        if ( ! is_dir( $dir ) ) {
            function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : @mkdir( $dir, 0750, true );
        }
        return $dir;
    }

    private static function normalize_url( string $value, bool $strip_query = false ): string {
        $value = trim( $value );
        if ( '' === $value ) { return ''; }
        $parts = @parse_url( $value );
        if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) { return ''; }
        $scheme = strtolower( (string) $parts['scheme'] );
        $host = strtolower( (string) $parts['host'] );
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
        $default = ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port );
        $path = (string) ( $parts['path'] ?? '/' );
        if ( '' === $path ) { $path = '/'; }
        $url = $scheme . '://' . $host . ( $port > 0 && ! $default ? ':' . $port : '' ) . $path;
        if ( ! $strip_query && isset( $parts['query'] ) && '' !== (string) $parts['query'] ) { $url .= '?' . (string) $parts['query']; }
        return $url;
    }

    public static function origin_for_url( string $url ): string {
        $normalized = self::normalize_url( $url, true );
        if ( '' === $normalized ) { return ''; }
        $parts = parse_url( $normalized );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
        $default = ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port );
        return $scheme . '://' . $host . ( $port > 0 && ! $default ? ':' . $port : '' );
    }

    public static function module_id_for_url( string $url ): string {
        $origin = self::origin_for_url( $url );
        return '' === $origin ? '' : 'site-' . substr( hash( 'sha256', $origin ), 0, 24 );
    }

    private static function module_dir( string $module_id ): string {
        $safe = preg_replace( '/[^a-z0-9-]/', '', strtolower( $module_id ) );
        $dir = self::root() . '/' . $safe;
        if ( ! is_dir( $dir ) ) {
            function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : @mkdir( $dir, 0750, true );
        }
        return $dir;
    }

    private static function module_path( string $module_id ): string { return self::module_dir( $module_id ) . '/module.json'; }
    private static function lock_path( string $module_id ): string { return self::module_dir( $module_id ) . '/.lock'; }

    private static function defaults( string $module_id, string $origin ): array {
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'version' => self::VERSION,
            'module_id' => $module_id,
            'origin' => $origin,
            'state' => 'new',
            'created_gmt' => gmdate( 'c' ),
            'updated_gmt' => gmdate( 'c' ),
            'revision' => 0,
            'run_id' => '',
            'surface_hash' => '',
            'previous_surface_hash' => '',
            'drift' => array( 'changed'=>false, 'revalidation_required'=>false ),
            'coverage' => array( 'discovered'=>0, 'visited'=>0, 'remaining'=>0, 'percent'=>0.0, 'complete'=>false ),
            'pending_urls' => array(),
            'visited_urls' => array(),
            'active_task_id' => '',
            'evidence' => array(),
            'failures' => array(),
            'metrics' => array( 'crawl_runs'=>0, 'batch_runs'=>0, 'verified_batches'=>0, 'degraded_batches'=>0 ),
            'capability_contract' => array(),
        );
    }

    private static function read( string $module_id, string $origin = '' ): array {
        if ( '' === $module_id ) { return self::defaults( '', $origin ); }
        $path = self::module_path( $module_id );
        $decoded = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array();
        return is_array( $decoded ) ? array_merge( self::defaults( $module_id, $origin ), $decoded ) : self::defaults( $module_id, $origin );
    }

    private static function clean( $value ) {
        if ( class_exists( 'PRSTUDIO_UC_Memory' ) && is_callable( array( 'PRSTUDIO_UC_Memory', 'redact' ) ) ) { return PRSTUDIO_UC_Memory::redact( $value ); }
        if ( is_string( $value ) && 0 === strpos( $value, 'data:image/' ) ) { return array( '_omitted'=>'inline_image', 'sha256'=>hash( 'sha256', $value ), 'bytes'=>strlen( $value ) ); }
        if ( is_array( $value ) ) { $out=array(); foreach ( $value as $k=>$v ) { $out[$k]=self::clean($v); } return $out; }
        return $value;
    }

    private static function write_skill_md( array $state ): void {
        if ( empty( $state['module_id'] ) ) { return; }
        $coverage = (array) ( $state['coverage'] ?? array() );
        $lines = array(
            '---',
            'name: ' . (string) $state['module_id'],
            'description: Verified site-specific operating memory for ' . (string) ( $state['origin'] ?? '' ),
            'version: ' . self::VERSION,
            '---', '',
            '# Site learning module', '',
            'This module is evidence, not authorization. Reuse must still follow the normal execution path and Anti-Crash law.', '',
            '## Scope',
            '- Origin: `' . (string) ( $state['origin'] ?? '' ) . '`',
            '- State: `' . (string) ( $state['state'] ?? '' ) . '`',
            '- Coverage: `' . (string) ( $coverage['visited'] ?? 0 ) . '/' . (string) ( $coverage['discovered'] ?? 0 ) . '`',
            '- Coverage complete: `' . ( ! empty( $coverage['complete'] ) ? 'yes' : 'no' ) . '`',
            '- Surface hash: `' . (string) ( $state['surface_hash'] ?? '' ) . '`',
            '- Revalidation required: `' . ( ! empty( $state['drift']['revalidation_required'] ) ? 'yes' : 'no' ) . '`', '',
            '## Learned procedure',
            '1. Discover the rendered same-origin graph in the paired personal Browser Agent.',
            '2. Visit every discovered safe URL in bounded batches.',
            '3. For each page collect page/interactive snapshot, accessibility tree, screenshot, observation bundle, Core Web Vitals, network report and console report.',
            '4. Persist only compact redacted evidence, hashes and screenshot artifact references; never credentials or inline screenshot bytes.',
            '5. Re-study after surface drift before trusting stale site-specific navigation knowledge.', '',
            '## Safety',
            '- Study flows contain no click, fill, select, check, press, submit, purchase, publish, delete, logout or other mutation step.',
            '- Same-origin traversal is the default and cross-origin traversal is disabled.',
            '- A degraded verification result is recorded and reported; it is never converted into an authorization gate.',
        );
        @file_put_contents( self::module_dir( (string) $state['module_id'] ) . '/SKILL.md', implode( "\n", $lines ) . "\n", LOCK_EX );
    }

    private static function atomic( array $state ): bool {
        $module_id = (string) ( $state['module_id'] ?? '' );
        if ( '' === $module_id ) { return false; }
        $state['updated_gmt'] = gmdate( 'c' );
        $state['revision'] = max( 0, (int) ( $state['revision'] ?? 0 ) ) + 1;
        $json = json_encode( self::clean( $state ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json || strlen( $json ) > 8388608 ) { return false; }
        $path = self::module_path( $module_id );
        $tmp = $path . '.tmp-' . substr( hash( 'sha256', uniqid( '', true ) ), 0, 12 );
        if ( false === @file_put_contents( $tmp, $json . "\n", LOCK_EX ) ) { return false; }
        @chmod( $tmp, 0640 );
        if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return false; }
        self::write_skill_md( $state );
        return true;
    }

    private static function mutate( string $module_id, string $origin, callable $callback ) {
        if ( '' === $module_id ) { return new WP_Error( 'site_learning_module_missing', 'Unable to identify site learning module.', array( 'status'=>400 ) ); }
        $fh = @fopen( self::lock_path( $module_id ), 'c+' );
        if ( ! is_resource( $fh ) || ! @flock( $fh, LOCK_EX ) ) { if ( is_resource( $fh ) ) { @fclose( $fh ); } return new WP_Error( 'site_learning_lock_failed', 'Unable to lock site learning memory.', array( 'status'=>503, 'retryable'=>true ) ); }
        try {
            $state = self::read( $module_id, $origin );
            $result = $callback( $state );
            if ( is_wp_error( $result ) ) { return $result; }
            if ( ! self::atomic( $state ) ) { return new WP_Error( 'site_learning_write_failed', 'Unable to persist site learning memory.', array( 'status'=>503, 'retryable'=>true ) ); }
            return is_array( $result ) ? $result : array( 'ok'=>true );
        } finally { @flock( $fh, LOCK_UN ); @fclose( $fh ); }
    }

    /** One universal contract: every registered capability belongs to a learning lane; none is excluded. */
    public static function capability_contract(): array {
        $rows = array();
        if ( class_exists( 'PRSTUDIO_UC_Capability_Registry' ) && is_callable( array( 'PRSTUDIO_UC_Capability_Registry', 'all' ) ) ) {
            foreach ( PRSTUDIO_UC_Capability_Registry::all() as $cap ) {
                if ( ! is_array( $cap ) || empty( $cap['id'] ) ) { continue; }
                $rows[] = array(
                    'id'=>(string)$cap['id'],
                    'lane'=>!empty($cap['browser_required']) ? 'browser_verified_evidence' : 'procedural_verified_evidence',
                    'read_only'=>!empty($cap['read_only']),
                );
            }
        }
        usort( $rows, static fn( $a, $b ) => strcmp( (string)$a['id'], (string)$b['id'] ) );
        return array(
            'version'=>self::VERSION,
            'count'=>count($rows),
            'excluded'=>array(),
            'all_capabilities_adopted'=>true,
            'rows'=>$rows,
            'hash'=>hash('sha256',(string)json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
            'execution_invariant'=>'CAN_EXECUTE -> ANTI_CRASH -> EXECUTE -> OBSERVE -> CONTINUE/REPORT',
        );
    }

    /** Build the first rendered-crawl task. With no URL the Browser Agent uses its current owned tab. */
    public static function prepare_context( array $context ): array {
        $url = self::normalize_url( (string) ( $context['url'] ?? $context['target'] ?? '' ) );
        if ( '' === $url && function_exists( 'home_url' ) ) { $url = self::normalize_url( (string) home_url( '/' ) ); }
        $origin = self::origin_for_url( $url );
        $module_id = self::module_id_for_url( $url );
        $max_pages = self::clamp( $context['max_pages'] ?? self::DEFAULT_MAX_PAGES, 1, self::MAX_PAGES, self::DEFAULT_MAX_PAGES );
        $max_depth = self::clamp( $context['max_depth'] ?? 5, 0, 5, 5 );
        $concurrency = self::clamp( $context['concurrency'] ?? 3, 1, 4, 3 );
        $delay_ms = self::clamp( $context['delay_ms'] ?? 250, 100, 10000, 250 );
        $batch_pages = self::clamp( $context['batch_pages'] ?? self::DEFAULT_BATCH_PAGES, 1, self::MAX_BATCH_PAGES, self::DEFAULT_BATCH_PAGES );
        $run_id = 'study-' . substr( hash( 'sha256', ( $origin ?: 'current-tab' ) . '|' . microtime( true ) . '|' . random_int( 0, PHP_INT_MAX ) ), 0, 20 );
        $arguments = array(
            'url'=>$url,
            'max_pages'=>$max_pages,
            'max_depth'=>$max_depth,
            'concurrency'=>$concurrency,
            'delay_ms'=>$delay_ms,
            'allow_cross_origin'=>false,
            '_prstudio_site_study'=>true,
            '_prstudio_site_module_id'=>$module_id,
            '_prstudio_site_origin'=>$origin,
            '_prstudio_site_run_id'=>$run_id,
            '_prstudio_site_batch_pages'=>$batch_pages,
            '_prstudio_site_max_pages'=>$max_pages,
            '_prstudio_site_read_only'=>true,
        );
        if ( '' !== $module_id ) {
            self::mutate( $module_id, $origin, static function( array &$state ) use ( $run_id, $arguments ) {
                $state['state']='discovering';$state['run_id']=$run_id;$state['active_task_id']='';
                $state['capability_contract']=array_intersect_key(self::capability_contract(),array_flip(array('version','count','excluded','all_capabilities_adopted','hash','execution_invariant')));
                $state['study_policy']=array('same_origin'=>true,'read_only'=>true,'screenshots_per_page'=>true,'max_pages'=>$arguments['max_pages'],'max_depth'=>$arguments['max_depth'],'batch_pages'=>$arguments['_prstudio_site_batch_pages'],'tests_per_page'=>7);
                return array('ok'=>true);
            } );
        }
        return array( 'url'=>$url, 'origin'=>$origin, 'module_id'=>$module_id, 'run_id'=>$run_id, 'crawler_arguments'=>$arguments );
    }

    private static function risky_navigation( string $url ): bool {
        $parts = @parse_url( $url );
        $subject = strtolower( (string) ( $parts['path'] ?? '' ) . '?' . (string) ( $parts['query'] ?? '' ) );
        return 1 === preg_match( '/(?:^|[\/_?&=.-])(logout|log-out|signout|sign-out|delete|destroy|remove-account|unsubscribe|purchase|buy-now|checkout|payment|pay-now|publish|trash|revoke|reset-password|cancel-subscription)(?:$|[\/_?&=.-])/i', $subject );
    }

    private static function discovered_urls( $value, string $origin, int $limit ): array {
        $out=array();$queue=array($value);$seen_objects=0;
        while($queue && count($out)<$limit && $seen_objects<20000){
            $current=array_shift($queue);$seen_objects++;
            if(is_string($current)){
                if(!preg_match('#^https?://#i',$current))continue;
                $normalized=self::normalize_url($current);
                if(''===$normalized||self::origin_for_url($normalized)!==$origin||self::risky_navigation($normalized))continue;
                $persistent=self::normalize_url($normalized,true);
                if(''!==$persistent&&!isset($out[$persistent]))$out[$persistent]=$normalized;
                continue;
            }
            if(!is_array($current))continue;
            foreach(array_slice($current,0,5000,true) as $child)$queue[]=$child;
        }
        ksort($out,SORT_STRING);
        return array_values($out);
    }

    private static function artifact_refs( $value ): array {
        $refs=array();$queue=array($value);$nodes=0;
        while($queue&&$nodes<12000&&count($refs)<200){
            $current=array_shift($queue);$nodes++;if(!is_array($current))continue;
            $artifact=(string)($current['artifact_id']??'');$sha=(string)($current['sha256']??'');
            if(preg_match('/^[a-f0-9]{32}$/i',$artifact)||preg_match('/^[a-f0-9]{64}$/i',$sha)){
                $key=hash('sha256',$artifact.'|'.$sha);$refs[$key]=array('artifact_id'=>preg_match('/^[a-f0-9]{32}$/i',$artifact)?strtolower($artifact):'','sha256'=>preg_match('/^[a-f0-9]{64}$/i',$sha)?strtolower($sha):'');
            }
            foreach(array_slice($current,0,1000,true) as $child)if(is_array($child))$queue[]=$child;
        }
        return array_values($refs);
    }

    public static function flow_steps_for_urls( array $urls, string $origin ): array {
        $steps=array();
        foreach(array_slice(array_values($urls),0,self::MAX_BATCH_PAGES) as $url){
            $url=self::normalize_url((string)$url);if(''===$url||self::origin_for_url($url)!==$origin||self::risky_navigation($url))continue;
            $steps[]=array('type'=>'navigate','url'=>$url,'expectedOrigin'=>$origin,'expectedUrl'=>$url,'waitUntil'=>'interactive','timeoutMs'=>45000);
            $steps[]=array('type'=>'wait_load','state'=>'complete','timeoutMs'=>45000);
            $steps[]=array('type'=>'page_snapshot','includeInteractive'=>true);
            $steps[]=array('type'=>'accessibility_snapshot');
            $steps[]=array('type'=>'screenshot','fullPage'=>false,'lazyLoad'=>true,'format'=>'auto','quality'=>82,'maxPixels'=>28000000);
            $steps[]=array('type'=>'observation_bundle','includeScreenshot'=>true,'viewerOnly'=>false);
            $steps[]=array('type'=>'core_web_vitals');
            $steps[]=array('type'=>'network_report');
            $steps[]=array('type'=>'console_report');
        }
        return $steps;
    }

    private static function retarget_parent( array $task, array $child ): bool {
        $job_uuid=(string)($task['job_uuid']??'');$child_id=(string)($child['task_uuid']??'');
        if(''===$job_uuid||''===$child_id||!class_exists('PRSTUDIO_UC_Store'))return false;
        $job=PRSTUDIO_UC_Store::get_job($job_uuid);if(!is_array($job))return false;
        $checkpoint=(array)($job['checkpoint']??array());
        $checkpoint['browser_task_id']=$child_id;
        $checkpoint['browser_step_index']=(int)($checkpoint['browser_step_index']??$job['step_index']??0);
        $checkpoint['browser_deadline_gmt']=gmdate('c',time()+300);
        $checkpoint['site_learning_module_id']=(string)($task['arguments']['_prstudio_site_module_id']??'');
        return is_array(PRSTUDIO_UC_Store::set_job_state($job_uuid,'WAITING_FOR_BROWSER',array('checkpoint'=>$checkpoint)));
    }

    private static function queue_next_batch( array $task, string $module_id, string $origin, array &$state ): array {
        $pending=array_values(array_unique(array_filter((array)($state['pending_urls']??array()),'is_string')));
        if(!$pending)return array('queued'=>false,'defer_parent'=>false);
        $batch_pages=self::clamp($task['arguments']['_prstudio_site_batch_pages']??self::DEFAULT_BATCH_PAGES,1,self::MAX_BATCH_PAGES,self::DEFAULT_BATCH_PAGES);
        $batch=array_slice($pending,0,$batch_pages);$steps=self::flow_steps_for_urls($batch,$origin);
        if(!$steps){$state['pending_urls']=array_values(array_diff($pending,$batch));return self::queue_next_batch($task,$module_id,$origin,$state);}
        $batch_index=(int)($state['metrics']['batch_runs']??0)+1;
        $child_args=array(
            'steps'=>$steps,
            'expected_origin'=>$origin,
            '_prstudio_site_study'=>true,
            '_prstudio_site_module_id'=>$module_id,
            '_prstudio_site_origin'=>$origin,
            '_prstudio_site_run_id'=>(string)($state['run_id']??$task['arguments']['_prstudio_site_run_id']??''),
            '_prstudio_site_batch_pages'=>$batch_pages,
            '_prstudio_site_batch_index'=>$batch_index,
            '_prstudio_site_batch_urls'=>$batch,
            '_prstudio_site_read_only'=>true,
            '_idempotency_key'=>'site-study:'.$module_id.':'.(string)($state['run_id']??'').':batch:'.$batch_index,
        );
        $device=(string)($task['device_uuid']??'');$job=(string)($task['job_uuid']??'');
        $child=PRSTUDIO_UC_Job_Engine::create_browser_task('playwright_flow',$child_args,''===$device?null:$device,$job);
        if(!is_array($child)||empty($child['task_uuid']))return array('queued'=>false,'defer_parent'=>false,'error'=>'site_learning_batch_queue_failed');
        $state['active_task_id']=(string)$child['task_uuid'];$state['state']='studying';
        $state['metrics']['batch_runs']=$batch_index;
        $retargeted=self::retarget_parent($task,$child);
        return array('queued'=>true,'defer_parent'=>$retargeted,'next_task_id'=>(string)$child['task_uuid'],'batch_urls'=>$batch);
    }

    private static function update_coverage( array &$state ): void {
        $discovered=count(array_unique((array)($state['discovered_urls']??array())));$visited=count(array_unique((array)($state['visited_urls']??array())));$remaining=count(array_unique((array)($state['pending_urls']??array())));
        $complete=$discovered>0&&0===$remaining&&$visited>=$discovered;
        $state['coverage']=array('discovered'=>$discovered,'visited'=>$visited,'remaining'=>$remaining,'percent'=>$discovered>0?round(min(1,$visited/$discovered)*100,2):0.0,'complete'=>$complete);
    }

    public static function after_browser_completion( array $task, array $result, array $verification ): array {
        $args=is_array($task['arguments']??null)?$task['arguments']:array();
        if(empty($args['_prstudio_site_study']))return array('handled'=>false,'defer_parent'=>false);
        $action=(string)($task['action']??'');
        $origin=(string)($args['_prstudio_site_origin']??'');
        if(''===$origin){$origin=(string)($result['origin']??'');if(''===$origin)$origin=self::origin_for_url((string)($result['seed_url']??$result['url']??$args['url']??''));}
        $origin=self::origin_for_url($origin.'/');
        if(''===$origin)return array('handled'=>true,'defer_parent'=>false,'reason'=>'origin_unresolved');
        $module_id=(string)($args['_prstudio_site_module_id']??'');if(''===$module_id)$module_id=self::module_id_for_url($origin.'/');
        $max_pages=self::clamp($args['_prstudio_site_max_pages']??$args['max_pages']??self::MAX_PAGES,1,self::MAX_PAGES,self::MAX_PAGES);
        $response=self::mutate($module_id,$origin,function(array &$state)use($task,$result,$verification,$action,$origin,$module_id,$max_pages){
            $state['module_id']=$module_id;$state['origin']=$origin;
            if(''===(string)($state['run_id']??''))$state['run_id']=(string)($task['arguments']['_prstudio_site_run_id']??'study-'.substr(hash('sha256',$origin.'|'.microtime(true)),0,20));
            $evidence=array('task_id'=>(string)($task['task_uuid']??''),'action'=>$action,'verified'=>!empty($verification['ok']),'evidence_hash'=>hash('sha256',(string)json_encode(self::clean($result),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'artifacts'=>self::artifact_refs($result),'observed_gmt'=>gmdate('c'));
            $state['evidence']=array_slice(array_merge((array)($state['evidence']??array()),array($evidence)),-self::MAX_EVIDENCE_ROWS);
            if('playwright_link_crawl'===$action){
                $urls=self::discovered_urls($result,$origin,$max_pages);$seed=self::normalize_url((string)($task['arguments']['url']??''));if(''!==$seed&&self::origin_for_url($seed)===$origin&&!self::risky_navigation($seed))array_unshift($urls,$seed);
                $urls=array_values(array_unique($urls));$persistent=array_map(static fn($u)=>self::normalize_url((string)$u,true),$urls);sort($persistent,SORT_STRING);$surface_hash=hash('sha256',(string)json_encode($persistent,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                $previous=(string)($state['surface_hash']??'');$state['previous_surface_hash']=$previous;$state['surface_hash']=$surface_hash;$state['drift']=array('changed'=>''!==$previous&&!hash_equals($previous,$surface_hash),'revalidation_required'=>''!==$previous&&!hash_equals($previous,$surface_hash));
                $state['discovered_urls']=$persistent;$state['pending_urls']=$urls;$state['visited_urls']=array();$state['metrics']['crawl_runs']=(int)($state['metrics']['crawl_runs']??0)+1;$state['state']='studying';
            }elseif('playwright_flow'===$action){
                $batch=array_values(array_filter((array)($task['arguments']['_prstudio_site_batch_urls']??array()),'is_string'));$visited=(array)($state['visited_urls']??array());foreach($batch as $url){$p=self::normalize_url($url,true);if(''!==$p)$visited[]=$p;}$state['visited_urls']=array_values(array_unique($visited));
                $pending=(array)($state['pending_urls']??array());$done=array_fill_keys(array_map(static fn($u)=>self::normalize_url((string)$u,true),$batch),true);$state['pending_urls']=array_values(array_filter($pending,static fn($u)=>!isset($done[self::normalize_url((string)$u,true)])));
                if(!empty($verification['ok']))$state['metrics']['verified_batches']=(int)($state['metrics']['verified_batches']??0)+1;else{$state['metrics']['degraded_batches']=(int)($state['metrics']['degraded_batches']??0)+1;$state['drift']['revalidation_required']=true;}
            }
            self::update_coverage($state);
            $next=self::queue_next_batch($task,$module_id,$origin,$state);
            if(empty($next['queued'])){
                self::update_coverage($state);$complete=!empty($state['coverage']['complete']);$degraded=(int)($state['metrics']['degraded_batches']??0)>0;
                $state['state']=$complete?($degraded?'studied_degraded':'ready'):'studying';$state['active_task_id']='';
                if($complete&&!$degraded)$state['drift']['revalidation_required']=false;
            }
            return array_merge(array('ok'=>true,'handled'=>true,'module_id'=>$module_id,'state'=>(string)$state['state'],'coverage'=>$state['coverage'],'drift'=>$state['drift']),$next);
        });
        return is_wp_error($response)?array('handled'=>true,'defer_parent'=>false,'error'=>$response->get_error_code()):$response;
    }

    public static function after_browser_failure( array $task, array $error ): array {
        $args=is_array($task['arguments']??null)?$task['arguments']:array();if(empty($args['_prstudio_site_study']))return array('handled'=>false);
        $origin=(string)($args['_prstudio_site_origin']??'');if(''===$origin)$origin=self::origin_for_url((string)($args['url']??''));$module_id=(string)($args['_prstudio_site_module_id']??'');if(''===$module_id&&''!==$origin)$module_id=self::module_id_for_url($origin.'/');if(''===$module_id)return array('handled'=>true,'recorded'=>false);
        $r=self::mutate($module_id,$origin,static function(array &$state)use($task,$error){$state['state']='studied_degraded';$state['active_task_id']='';$state['drift']['revalidation_required']=true;$row=array('task_id'=>(string)($task['task_uuid']??''),'action'=>(string)($task['action']??''),'code'=>(string)($error['code']??'browser_failure'),'observed_gmt'=>gmdate('c'));$state['failures']=array_slice(array_merge((array)($state['failures']??array()),array($row)),-self::MAX_FAILURES);return array('ok'=>true,'handled'=>true,'recorded'=>true);});
        return is_wp_error($r)?array('handled'=>true,'recorded'=>false):$r;
    }

    public static function status( string $url ): array {
        $origin=self::origin_for_url($url);$module_id=self::module_id_for_url($url);if(''===$module_id)return array('ok'=>false,'reason'=>'invalid_url');$state=self::read($module_id,$origin);
        return array('ok'=>true,'module_id'=>$module_id,'origin'=>$origin,'state'=>$state['state'],'coverage'=>$state['coverage'],'drift'=>$state['drift'],'metrics'=>$state['metrics'],'updated_gmt'=>$state['updated_gmt'],'capability_contract'=>$state['capability_contract']);
    }
}
