<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * SerpBear + Search Console keyword watch.
 *
 * Completion authority is SerpBear's exact SERP position. Search Console is
 * collected independently as supporting first-party evidence because its
 * reported position is aggregated and is not treated as an exact live rank.
 */
final class PRSTUDIO_UC_Serp_Watch {
    public const VERSION = '17.0.0';
    private const STATE = 'serp-watch';
    private const SECRET = 'serpbear_api_key';
    private const MAX_WATCHES = 250;
    private const MAX_KEYWORDS_PER_WATCH = 500;
    private const MAX_HISTORY = 180;
    private const DEFAULT_INTERVAL = 86400;

    private static function defaults(): array {
        return array(
            'schema_version'=>1,
            'config'=>array('base_url'=>'','domain'=>'','site_url'=>'','allow_private_host'=>false,'configured_gmt'=>''),
            'watches'=>array(),
            'metrics'=>array('runs'=>0,'completed'=>0,'errors'=>0,'last_tick_gmt'=>''),
        );
    }

    private static function text( $value, int $max = 300 ): string {
        $v=is_scalar($value)?trim((string)$value):'';
        if(function_exists('sanitize_text_field'))$v=sanitize_text_field($v);
        return substr($v,0,$max);
    }
    private static function key( $value, int $max = 100 ): string {
        $v=strtolower(self::text($value,$max*2));$v=(string)preg_replace('/[^a-z0-9._:-]+/','-',$v);return substr(trim($v,'-.'),0,$max);
    }
    private static function state(): array {
        $s=PRSTUDIO_UC_Agency_State::read(self::STATE,self::defaults());return is_array($s)?array_merge(self::defaults(),$s):self::defaults();
    }
    private static function error(string $code,string $message,int $status=400,array $details=array(),bool $retryable=false): WP_Error {
        return new WP_Error($code,$message,array('status'=>$status,'details'=>$details,'retryable'=>$retryable));
    }
    private static function uuid(): string {
        if(function_exists('wp_generate_uuid4'))return wp_generate_uuid4();$b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    private static function normalize_base_url(string $url,bool $allow_private=false) {
        $url=rtrim(trim($url),'/');if(''===$url)return self::error('serpbear_url_required','SerpBear base_url is required.');
        $parts=function_exists('wp_parse_url')?wp_parse_url($url):parse_url($url);if(!is_array($parts)||empty($parts['scheme'])||empty($parts['host']))return self::error('serpbear_url_invalid','SerpBear base_url must be an absolute HTTP(S) URL.');
        $scheme=strtolower((string)$parts['scheme']);if(!in_array($scheme,array('https','http'),true))return self::error('serpbear_scheme_invalid','Only HTTP(S) SerpBear endpoints are supported.');
        if(!$allow_private&&'https'!==$scheme)return self::error('serpbear_https_required','Remote SerpBear endpoints must use HTTPS unless allow_private_host is explicitly enabled.');
        if(str_contains($url,'@'))return self::error('serpbear_url_credentials_denied','Credentials must not be embedded in the SerpBear URL.');
        return $url;
    }
    private static function config(): array {return (array)(self::state()['config']??array());}

    public static function configure(array $args) {
        if(!class_exists('PRSTUDIO_UC_Secrets_Vault'))return self::error('serpbear_vault_unavailable','Secrets vault is unavailable.',503,array(),true);
        $allow=!empty($args['allow_private_host']);$base=self::normalize_base_url((string)($args['base_url']??''),$allow);if(is_wp_error($base))return $base;
        $api_key=trim((string)($args['api_key']??''));if(strlen($api_key)<8)return self::error('serpbear_api_key_invalid','A valid SerpBear API key is required.');
        $domain=self::text($args['domain']??'',190);if(''===$domain)return self::error('serpbear_domain_required','SerpBear domain is required.');
        $site=(string)($args['site_url']??(function_exists('home_url')?home_url('/') : ''));if(''!==$site&&function_exists('esc_url_raw'))$site=esc_url_raw($site);
        if(!PRSTUDIO_UC_Secrets_Vault::set(self::SECRET,$api_key))return self::error('serpbear_secret_write_failed','Unable to store the SerpBear API key securely.',503,array(),true);
        $result=PRSTUDIO_UC_Agency_State::mutate(self::STATE,self::defaults(),static function(array &$s)use($base,$domain,$site,$allow):array{
            $s['config']=array('base_url'=>$base,'domain'=>$domain,'site_url'=>$site,'allow_private_host'=>$allow,'configured_gmt'=>gmdate('c'));
            return $s['config'];
        });
        return array('ok'=>is_array($result),'configured'=>is_array($result),'config'=>$result,'api_key_stored'=>true,'version'=>self::VERSION);
    }

    private static function request(string $method,string $path,array $query=array(),?array $json=null,int $timeout=30) {
        $cfg=self::config();$base=(string)($cfg['base_url']??'');if(''===$base)return self::error('serpbear_not_configured','SerpBear is not configured.',409);
        $key=class_exists('PRSTUDIO_UC_Secrets_Vault')?PRSTUDIO_UC_Secrets_Vault::get(self::SECRET):null;if(!is_string($key)||''===$key)return self::error('serpbear_api_key_missing','SerpBear API key is missing from the secure vault.',409);
        $url=$base.'/'.ltrim($path,'/');if($query){$qs=http_build_query($query,'','&',PHP_QUERY_RFC3986);$url.=(str_contains($url,'?')?'&':'?').$qs;}
        $timeout=max(1,min(30,$timeout));$opts=array('method'=>strtoupper($method),'timeout'=>$timeout,'redirection'=>2,'headers'=>array('Authorization'=>'Bearer '.$key,'Accept'=>'application/json','User-Agent'=>'PR-STUDIO/'.self::VERSION));
        if(null!==$json){$opts['headers']['Content-Type']='application/json';$opts['body']=function_exists('wp_json_encode')?wp_json_encode($json):json_encode($json);}
        $allow_private=!empty($cfg['allow_private_host']);
        if($allow_private&&function_exists('wp_remote_request'))$response=wp_remote_request($url,$opts);
        elseif(function_exists('wp_safe_remote_request'))$response=wp_safe_remote_request($url,$opts);
        else return self::error('serpbear_safe_http_unavailable','WordPress safe HTTP API is required for non-private SerpBear hosts.',503,array(),true);
        if(is_wp_error($response))return self::error('serpbear_http_error',$response->get_error_message(),503,array('upstream_code'=>$response->get_error_code()),true);
        $status=function_exists('wp_remote_retrieve_response_code')?(int)wp_remote_retrieve_response_code($response):0;
        $body=function_exists('wp_remote_retrieve_body')?(string)wp_remote_retrieve_body($response):'';
        if(strlen($body)>4194304)return self::error('serpbear_response_too_large','SerpBear response exceeded the bounded response size.',502);
        $data=json_decode($body,true);if($status<200||$status>=300)return self::error('serpbear_upstream_error','SerpBear returned a non-success response.',502,array('http_status'=>$status,'body'=>substr(self::text($body,1000),0,1000)),in_array($status,array(408,425,429,500,502,503,504),true));
        if(!is_array($data))$data=array('raw'=>substr($body,0,10000));
        return array('ok'=>true,'http_status'=>$status,'data'=>$data,'retrieved_gmt'=>gmdate('c'),'source'=>'serpbear_api','verified'=>true);
    }

    public static function keywords(array $args=array()) {
        $domain=self::text($args['domain']??(self::config()['domain']??''),190);if(''===$domain)return self::error('serpbear_domain_required','domain is required.');
        $r=self::request('GET','/api/keywords',array('domain'=>$domain),null,(int)($args['http_timeout']??30));if(is_wp_error($r))return$r;
        $rows=(array)($r['data']['keywords']??array());$out=array();foreach(array_slice($rows,0,5000) as $row){if(!is_array($row))continue;$out[]=array('id'=>(int)($row['ID']??$row['id']??0),'keyword'=>self::text($row['keyword']??'',300),'position'=>is_numeric($row['position']??null)?(float)$row['position']:null,'url'=>(string)($row['url']??''),'device'=>self::text($row['device']??'',30),'country'=>self::text($row['country']??'',10),'last_updated'=>(string)($row['lastUpdated']??''),'updating'=>(bool)($row['updating']??false),'last_error'=>self::text($row['lastUpdateError']??'',500));}
        return array('ok'=>true,'domain'=>$domain,'count'=>count($out),'keywords'=>$out,'retrieved_gmt'=>$r['retrieved_gmt'],'source'=>'serpbear_api','verified'=>true);
    }

    /** Explicit all-keyword refresh. This uses SerpBear's documented /api/cron route. */
    public static function refresh(array $args=array()) {
        return self::request('POST','/api/cron',array(),array(),(int)($args['http_timeout']??30));
    }

    private static function normalize_keywords(array $keywords): array {
        $out=array();foreach(array_slice($keywords,0,self::MAX_KEYWORDS_PER_WATCH) as $item){$name=is_array($item)?($item['keyword']??''):$item;$name=self::text($name,300);if(''===$name)continue;$k=strtolower($name);if(isset($out[$k]))continue;$out[$k]=array('keyword'=>$name,'serpbear_id'=>is_array($item)?(int)($item['serpbear_id']??$item['id']??0):0,'first_page_reached_gmt'=>'','first_reached_gmt'=>'','target_streak'=>0,'last_position'=>null,'last_serp_url'=>'','last_gsc'=>null,'last_checked_gmt'=>'');}return $out;
    }

    public static function watch_create(array $args) {
        $keywords=self::normalize_keywords((array)($args['keywords']??array()));if(!$keywords)return self::error('serp_watch_keywords_required','At least one keyword is required.');
        $cfg=self::config();if(''===(string)($cfg['base_url']??''))return self::error('serpbear_not_configured','Configure SerpBear before creating a watch.',409);
        $id='serp-'.substr(str_replace('-','',self::uuid()),0,24);$intervalInput=isset($args['interval_seconds'])?(int)$args['interval_seconds']:(isset($args['interval_hours'])?(int)$args['interval_hours']*3600:self::DEFAULT_INTERVAL);$interval=max(3600,min(604800,$intervalInput));$consecutive=max(1,min(14,(int)($args['required_consecutive']??2)));$target=max(1,min(100,(int)($args['target_position']??1)));$firstPage=max(1,min(100,(int)($args['first_page_position']??10)));
        $domain=self::text($args['domain']??($cfg['domain']??''),190);$site=(string)($args['site_url']??($cfg['site_url']??''));$now=gmdate('c');
        $watch=array('id'=>$id,'group_id'=>self::key($args['group_id']??'',100),'status'=>'active','domain'=>$domain,'site_url'=>$site,'target_position'=>$target,'first_page_position'=>$firstPage,'required_consecutive'=>$consecutive,'interval_seconds'=>$interval,'keywords'=>$keywords,'created_gmt'=>$now,'updated_gmt'=>$now,'next_run_gmt'=>gmdate('c',time()+min(60,$interval)),'last_run_gmt'=>'','completed_gmt'=>'','runs'=>0,'history'=>array(),'gsc_provider_preference'=>in_array((string)($args['gsc_provider_preference']??'browser_first'),array('browser_first','api_first'),true)?(string)($args['gsc_provider_preference']??'browser_first'):'browser_first','refresh_serpbear_before_check'=>!empty($args['refresh_serpbear_before_check']));
        $saved=PRSTUDIO_UC_Agency_State::mutate(self::STATE,self::defaults(),static function(array &$s)use($id,$watch):bool{if(count((array)$s['watches'])>=self::MAX_WATCHES)return false;$s['watches'][$id]=$watch;return true;});
        if(!$saved)return self::error('serp_watch_store_full','Unable to create watch; bounded watch store is full.',409);
        return array('ok'=>true,'watch'=>$watch,'completion_authority'=>'serpbear_exact_position','gsc_role'=>'supporting_first_party_evidence','version'=>self::VERSION);
    }

    /** Create sharded daily watches for every keyword currently configured in SerpBear. */
    public static function watch_create_all(array $args) {
        $inventory=self::keywords(array('domain'=>$args['domain']??(self::config()['domain']??'')));if(is_wp_error($inventory))return $inventory;
        $rows=array_values(array_filter((array)($inventory['keywords']??array()),static fn($r)=>is_array($r)&&''!==trim((string)($r['keyword']??''))));if(!$rows)return self::error('serp_watch_no_keywords','SerpBear returned no keywords to watch.',409);
        $chunks=array_chunk($rows,self::MAX_KEYWORDS_PER_WATCH);$state=self::state();if(count((array)$state['watches'])+count($chunks)>self::MAX_WATCHES)return self::error('serp_watch_store_full','Not enough bounded watch slots for all SerpBear keywords.',409,array('keywords'=>count($rows),'required_watches'=>count($chunks),'available_watches'=>max(0,self::MAX_WATCHES-count((array)$state['watches']))));
        $group='serp-group-'.substr(str_replace('-','',self::uuid()),0,20);$ids=array();$errors=array();
        foreach($chunks as $index=>$chunk){$create=$args;$create['keywords']=$chunk;$create['group_id']=$group;$create['target_position']=$args['target_position']??1;$create['first_page_position']=$args['first_page_position']??10;$create['required_consecutive']=$args['required_consecutive']??2;$create['interval_hours']=$args['interval_hours']??24;$create['refresh_serpbear_before_check']=$args['refresh_serpbear_before_check']??false;$r=self::watch_create($create);if(is_wp_error($r)){$errors[]=array('shard'=>$index,'code'=>$r->get_error_code(),'message'=>$r->get_error_message());break;}$ids[]=(string)($r['watch']['id']??'');}
        return array('ok'=>empty($errors),'group_id'=>$group,'keyword_count'=>count($rows),'watch_count'=>count($ids),'watch_ids'=>$ids,'errors'=>$errors,'shard_size'=>self::MAX_KEYWORDS_PER_WATCH,'target_position'=>(int)($args['target_position']??1),'first_page_position'=>(int)($args['first_page_position']??10),'interval_hours'=>(int)($args['interval_hours']??24),'completion_authority'=>'serpbear_exact_position','gsc_role'=>'supporting_first_party_evidence','version'=>self::VERSION);
    }

    private static function gsc_rows_for_keywords(array $watch,int $sync_wait_seconds=5) {
        if(!class_exists('PRSTUDIO_UC_GSC_Provider'))return self::error('gsc_provider_unavailable','Search Console provider unavailable.',503,array(),true);
        $end=gmdate('Y-m-d',time()-86400);$start=gmdate('Y-m-d',time()-28*86400);
        $args=array('site_url'=>(string)$watch['site_url'],'start_date'=>$start,'end_date'=>$end,'dimensions'=>array('query','page'),'row_limit'=>25000,'provider_preference'=>(string)$watch['gsc_provider_preference'],'allow_browser_fallback'=>true,'sync_wait_seconds'=>max(0,min(5,$sync_wait_seconds)));
        return PRSTUDIO_UC_GSC_Provider::analytics($args);
    }

    private static function map_gsc($gsc,array $keywords): array {
        $map=array();foreach(array_keys($keywords) as $k)$map[$k]=null;if(is_wp_error($gsc))return array('status'=>'error','error'=>array('code'=>$gsc->get_error_code(),'message'=>$gsc->get_error_message()),'by_keyword'=>$map);
        if(!is_array($gsc))return array('status'=>'unavailable','by_keyword'=>$map);
        $status=strtolower((string)($gsc['status']??''));if(in_array($status,array('queued','leased','running','waiting_for_browser'),true))return array('status'=>'pending','task_id'=>(string)($gsc['task_id']??$gsc['task']['task_uuid']??''),'by_keyword'=>$map);
        foreach((array)($gsc['rows']??array()) as $row){if(!is_array($row))continue;$query=strtolower(trim((string)($row['query']??($row['keys'][0]??''))));if(!array_key_exists($query,$map))continue;$candidate=array('clicks'=>(float)($row['clicks']??0),'impressions'=>(float)($row['impressions']??0),'ctr'=>(float)($row['ctr']??0),'position'=>is_numeric($row['position']??null)?(float)$row['position']:null,'page'=>(string)($row['page']??($row['keys'][1]??'')));
            $prev=$map[$query];if(null===$prev||$candidate['impressions']>(float)($prev['impressions']??0))$map[$query]=$candidate;
        }
        return array('status'=>'completed','provider'=>(string)($gsc['provider_used']??$gsc['source']??''),'retrieved_at'=>(string)($gsc['retrieved_at']??gmdate('c')),'by_keyword'=>$map,'dimension_integrity'=>$gsc['dimension_integrity']??null);
    }

    public static function watch_run(array $args) {
        $id=self::key($args['watch_id']??'',100);if(''===$id)return self::error('serp_watch_id_required','watch_id is required.');$state=self::state();$watch=$state['watches'][$id]??null;if(!is_array($watch))return self::error('serp_watch_not_found','Watch not found.',404);if('cancelled'===(string)$watch['status'])return self::error('serp_watch_cancelled','Watch is cancelled.',409);
        $refresh_error=null;
        if(!empty($watch['refresh_serpbear_before_check'])||!empty($args['refresh_serpbear'])){$refresh=self::refresh(array('refresh_all'=>true,'http_timeout'=>(int)($args['http_timeout']??30)));if(is_wp_error($refresh))$refresh_error=array('code'=>$refresh->get_error_code(),'message'=>$refresh->get_error_message());}
        $serp=self::keywords(array('domain'=>$watch['domain'],'http_timeout'=>(int)($args['http_timeout']??30)));if(is_wp_error($serp)){PRSTUDIO_UC_Agency_State::mutate(self::STATE,self::defaults(),static function(array &$s)use($id,$serp):bool{$s['metrics']['errors']=(int)$s['metrics']['errors']+1;$s['metrics']['last_tick_gmt']=gmdate('c');if(isset($s['watches'][$id])){$s['watches'][$id]['last_error']=array('code'=>$serp->get_error_code(),'message'=>$serp->get_error_message(),'gmt'=>gmdate('c'));$s['watches'][$id]['next_run_gmt']=gmdate('c',time()+3600);}return true;});return$serp;}
        $serp_map=array();foreach((array)$serp['keywords'] as $row){$serp_map[strtolower((string)$row['keyword'])]=$row;}
        $gsc=self::gsc_rows_for_keywords($watch,(int)($args['gsc_sync_wait_seconds']??5));$gsc_map=self::map_gsc($gsc,(array)$watch['keywords']);$now=gmdate('c');$all_target=true;$first_page_count=0;$target_count=0;$updates=array();
        foreach((array)$watch['keywords'] as $k=>$kw){$row=$serp_map[$k]??null;$pos=is_array($row)&&is_numeric($row['position']??null)?(float)$row['position']:null;$kw['last_checked_gmt']=$now;$kw['last_position']=$pos;$kw['last_serp_url']=is_array($row)?(string)($row['url']??''):'';$kw['last_gsc']=$gsc_map['by_keyword'][$k]??null;
            if(null!==$pos&&$pos<=(int)($watch['first_page_position']??10)){$first_page_count++;if(''===(string)$kw['first_page_reached_gmt'])$kw['first_page_reached_gmt']=$now;}
            if(null!==$pos&&$pos<=(int)$watch['target_position']){$kw['target_streak']=(int)$kw['target_streak']+1;}else{$kw['target_streak']=0;}
            $reached=(int)$kw['target_streak']>=(int)$watch['required_consecutive'];if($reached){$target_count++;if(''===(string)$kw['first_reached_gmt'])$kw['first_reached_gmt']=$now;}else{$all_target=false;}
            $updates[$k]=$kw;
        }
        $snapshot=array('gmt'=>$now,'first_page_count'=>$first_page_count,'target_count'=>$target_count,'total'=>count($updates),'gsc_status'=>$gsc_map['status'],'positions'=>array_map(static fn($x)=>$x['last_position'],$updates));
        $saved=PRSTUDIO_UC_Agency_State::mutate(self::STATE,self::defaults(),static function(array &$s)use($id,$updates,$snapshot,$all_target,$watch,$now,$gsc_map,$refresh_error):array{
            if(!isset($s['watches'][$id]))return array();$w=&$s['watches'][$id];$w['keywords']=$updates;$w['runs']=(int)$w['runs']+1;$w['last_run_gmt']=$now;$w['updated_gmt']=$now;$w['last_gsc_status']=$gsc_map;$w['history'][]=$snapshot;if(count($w['history'])>self::MAX_HISTORY)$w['history']=array_slice($w['history'],-self::MAX_HISTORY);if(isset($refresh_error))$w['last_refresh_error']=$refresh_error;else unset($w['last_refresh_error']);
            if($all_target){$w['status']='completed';$w['completed_gmt']=$now;$w['next_run_gmt']='';$s['metrics']['completed']=(int)$s['metrics']['completed']+1;}else{$w['status']='active';$w['next_run_gmt']=gmdate('c',time()+(int)$w['interval_seconds']);}
            $s['metrics']['runs']=(int)$s['metrics']['runs']+1;$s['metrics']['last_tick_gmt']=$now;return $w;
        });
        return array('ok'=>true,'watch'=>$saved,'snapshot'=>$snapshot,'completed'=>$all_target,'completion_authority'=>'serpbear_exact_position','gsc_role'=>'supporting_first_party_evidence','version'=>self::VERSION);
    }

    public static function watch_list(array $args=array()): array {
        $s=self::state();$status=self::key($args['status']??'',30);$items=array();foreach((array)$s['watches'] as $w){if($status&&$status!==(string)($w['status']??''))continue;$items[]=$w;}usort($items,static fn($a,$b)=>strcmp((string)($b['updated_gmt']??''),(string)($a['updated_gmt']??'')));return array('ok'=>true,'count'=>count($items),'items'=>array_slice($items,0,max(1,min(250,(int)($args['limit']??100)))),'metrics'=>$s['metrics'],'version'=>self::VERSION);
    }
    public static function watch_status(array $args) {
        $id=self::key($args['watch_id']??'',100);$w=self::state()['watches'][$id]??null;return is_array($w)?array('ok'=>true,'watch'=>$w,'version'=>self::VERSION):self::error('serp_watch_not_found','Watch not found.',404);
    }
    public static function watch_cancel(array $args) {
        $id=self::key($args['watch_id']??'',100);$reason=self::text($args['reason']??'cancelled_by_user',300);$r=PRSTUDIO_UC_Agency_State::mutate(self::STATE,self::defaults(),static function(array &$s)use($id,$reason){if(!isset($s['watches'][$id]))return null;$s['watches'][$id]['status']='cancelled';$s['watches'][$id]['cancelled_gmt']=gmdate('c');$s['watches'][$id]['cancel_reason']=$reason;$s['watches'][$id]['next_run_gmt']='';return$s['watches'][$id];});return is_array($r)?array('ok'=>true,'watch'=>$r,'version'=>self::VERSION):self::error('serp_watch_not_found','Watch not found.',404);
    }

    /** Bounded scheduler hook. Never loops until success inside a single request. */
    public static function tick(int $limit=1): array {
        $s=self::state();$due=array();$now=time();foreach((array)$s['watches'] as $id=>$w){if('active'!==(string)($w['status']??''))continue;$next=strtotime((string)($w['next_run_gmt']??''));if(!$next||$next<=$now)$due[]=$id;if(count($due)>=max(1,min(20,$limit)))break;}
        $results=array();foreach($due as $id){$r=self::watch_run(array('watch_id'=>$id,'http_timeout'=>2,'gsc_sync_wait_seconds'=>0));$results[$id]=is_wp_error($r)?array('ok'=>false,'error'=>$r->get_error_code(),'message'=>$r->get_error_message()):array('ok'=>true,'completed'=>!empty($r['completed']));}
        return array('ok'=>true,'processed'=>count($results),'results'=>$results,'version'=>self::VERSION);
    }

    public static function status(array $args=array()): array {
        $s=self::state();$cfg=(array)$s['config'];$valid_secret=class_exists('PRSTUDIO_UC_Secrets_Vault')&&is_string(PRSTUDIO_UC_Secrets_Vault::get(self::SECRET));$active=0;$complete=0;foreach((array)$s['watches'] as $w){if('active'===($w['status']??''))$active++;if('completed'===($w['status']??''))$complete++;}
        return array('ok'=>true,'version'=>self::VERSION,'configured'=>''!==(string)($cfg['base_url']??'')&&$valid_secret,'config'=>array_diff_key($cfg,array('api_key'=>true)),'api_key_present'=>$valid_secret,'watches'=>count((array)$s['watches']),'active'=>$active,'completed'=>$complete,'metrics'=>$s['metrics'],'completion_authority'=>'serpbear_exact_position','gsc_role'=>'supporting_first_party_evidence','daily_scheduler'=>'agency_runtime_tick','bounded'=>true);
    }
}
