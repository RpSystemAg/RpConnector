<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** Dedicated credentials for Custom GPT Actions; Browser Agent tokens are never reused. */
final class PRSTUDIO_UC_GPT_Actions_Auth {
    private const OPTION = 'prstudio_uc_actions_keys_v3';
    private const MAX_KEYS = 8;
    /** Minimum spacing between last_used writes for one key. */
    private const TOUCH_INTERVAL_SECONDS = 300;
    public static function create_key( string $label = 'RP Studio Connector', array $scopes = array('capabilities','execute','jobs','memory','health') ): array {
        $raw='prsa_'.bin2hex(random_bytes(32)); $fp=substr(hash('sha256',$raw),0,16); $now=gmdate('c');
        $record=array('fingerprint'=>$fp,'label'=>substr(self::text($label),0,120),'hash'=>self::hash_token($raw),'scopes'=>array_values(array_unique(array_map(array(__CLASS__,'scope'),$scopes))),'status'=>'active','created_at'=>$now,'last_used'=>null,'rotated_at'=>null,'revoked_at'=>null);
        // Compare-and-swap: a key created while another request is revoking a
        // different one must not be lost to a stale full-option overwrite.
        self::mutate(static function(array &$keys)use($fp,$record){
            $keys=array_values(array_filter($keys,static fn($x)=>(string)($x['fingerprint']??'')!==$fp));
            array_unshift($keys,$record);
            $keys=array_slice($keys,0,self::MAX_KEYS);
            return true;
        });
        self::audit('actions_key.created',$record);
        return array('key'=>$raw,'fingerprint'=>$fp,'label'=>$record['label'],'scopes'=>$record['scopes'],'created_at'=>$now,'display_once'=>true);
    }
    public static function rotate( string $fingerprint ): array {
        self::revoke($fingerprint,'rotated'); return self::create_key('RP Studio Connector (rotated)');
    }
    public static function revoke( string $fingerprint, string $reason='revoked' ): bool {
        $found=false;$revoked=array();
        self::mutate(static function(array &$keys)use($fingerprint,$reason,&$found,&$revoked){
            foreach($keys as &$r){
                if(!hash_equals((string)($r['fingerprint']??''),$fingerprint))continue;
                $r['status']='revoked'; $r['revoked_at']=gmdate('c'); $r['revoke_reason']=substr(self::text($reason),0,120);
                $found=true; $revoked[]=$r;
            }
            unset($r);
            return $found;
        });
        // Audit after the write commits, so the log records revocations that
        // actually persisted rather than ones a losing CAS attempt discarded.
        foreach($revoked as $r){self::audit('actions_key.revoked',$r);}
        return $found;
    }
    public static function metadata(): array {
        return array_map(static function($r){unset($r['hash']);return $r;},self::records());
    }
    private static function records(): array { $v=function_exists('get_option')?get_option(self::OPTION,array()):array(); return is_array($v)?$v:array(); }
    private static function save(array $v):void{if(function_exists('update_option')){update_option(self::OPTION,$v,false);}}

    /**
     * Read-modify-write the key records under an atomic compare-and-swap.
     *
     * create_key(), revoke() and touch() all read the whole option, edit one
     * record and write the whole option back. Concurrently that is a lost
     * update: a key created while another request was revoking a different one
     * could vanish when the slower writer saved its stale copy. These are
     * credentials, so a silently dropped record is worse than a rejected write.
     *
     * WordPress has no compare-and-swap for options, so this issues the
     * comparison and the write as one UPDATE keyed on the previous serialized
     * value, and retries on a miss. The callback receives the freshly-read state
     * each attempt and returns false to abort without writing.
     */
    private static function mutate( callable $callback, int $attempts = 5 ): bool {
        global $wpdb;
        for ( $i = 0; $i < max( 1, $attempts ); $i++ ) {
            $current = self::records();
            $next = $current;
            if ( false === $callback( $next ) ) { return false; }
            if ( $next === $current ) { return true; }

            if ( ! isset( $wpdb ) || ! function_exists( 'maybe_serialize' ) ) { self::save( $next ); return true; }

            $serialized_before = maybe_serialize( $current );
            $serialized_after  = maybe_serialize( $next );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap cannot be expressed through the options API.
            $rows = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $serialized_after,
                self::OPTION,
                $serialized_before
            ) );
            if ( $rows > 0 ) {
                if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' ); }
                return true;
            }

            // No row matched: either the option does not exist yet, or another
            // writer changed it. Seed it once, then fall through to a retry.
            if ( empty( $current ) && function_exists( 'add_option' ) && add_option( self::OPTION, $next, '', false ) ) { return true; }
            if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' ); }
            usleep( random_int( 1000, 5000 ) );
        }
        return false;
    }
    private static function root_secret(): string {
        if(class_exists('PRSTUDIO_UC_Auth')){return PRSTUDIO_UC_Auth::signing_secret();}
        return defined('AUTH_SALT')?(string)AUTH_SALT:'prstudio-test-root';
    }
    private static function hash_token(string $raw):string{return hash_hmac('sha256',$raw,self::root_secret());}
    private static function text(string $v):string{return function_exists('sanitize_text_field')?sanitize_text_field($v):trim(preg_replace('/[\r\n\t]+/',' ',$v));}
    private static function scope(string $v):string{$v=strtolower(preg_replace('/[^a-z0-9_-]+/','',$v));return $v?:'health';}
    private static function audit(string $event,array $r):void{if(class_exists('PRSTUDIO_UC_Memory')){PRSTUDIO_UC_Memory::movement($event,array('fingerprint'=>$r['fingerprint']??'','label'=>$r['label']??'','scopes'=>$r['scopes']??array(),'status'=>$r['status']??'','outcome'=>'recorded'));}}
    public static function token_from_request( $request ): string {
        $header=is_object($request)&&method_exists($request,'get_header')?(string)$request->get_header('x-prstudio-auth'):'';
        if(''!==trim($header)){return trim($header);} $auth=is_object($request)&&method_exists($request,'get_header')?(string)$request->get_header('authorization'):'';
        return preg_match('/^Bearer\s+(.+)$/i',trim($auth),$m)?trim($m[1]):'';
    }
    public static function verify_token( string $token, string $required_scope ): array {
        if(strlen($token)<40){return array('ok'=>false,'code'=>'invalid_key');}
        $candidate=self::hash_token($token); $fp=substr(hash('sha256',$token),0,16);
        foreach(self::records() as $idx=>$r){
            if((string)($r['status']??'')!=='active')continue; $stored=(string)($r['hash']??''); if(strlen($stored)!==64)continue;
            if(hash_equals($stored,$candidate)){
                $scopes=(array)($r['scopes']??array()); if(!in_array($required_scope,$scopes,true)&&!in_array('*',$scopes,true)){return array('ok'=>false,'code'=>'scope_denied','fingerprint'=>$fp);}
                self::touch((string)$r['fingerprint']); return array('ok'=>true,'fingerprint'=>(string)$r['fingerprint'],'scopes'=>$scopes);
            }
        }
        return array('ok'=>false,'code'=>'invalid_key','fingerprint'=>$fp);
    }
    /**
     * Record that a key was used, at most once per TOUCH_INTERVAL_SECONDS.
     *
     * This previously rewrote the entire keys option on every authenticated
     * request. Under sustained MCP traffic that is one full option write per
     * call -- write amplification on wp_options plus an autoload-cache
     * invalidation each time -- to move a timestamp nobody reads at that
     * resolution. Coarsening to a bounded interval keeps last_used useful for
     * "is this credential still in use" while making the steady-state cost zero
     * writes for most requests.
     */
    private static function touch(string $fp):void{
        $now = time();
        foreach ( self::records() as $record ) {
            if ( (string) ( $record['fingerprint'] ?? '' ) !== $fp ) { continue; }
            $last = strtotime( (string) ( $record['last_used'] ?? '' ) );
            if ( $last > 0 && ( $now - $last ) < self::TOUCH_INTERVAL_SECONDS ) { return; }
            break;
        }
        self::mutate( static function ( array &$keys ) use ( $fp, $now ) {
            $changed = false;
            foreach ( $keys as &$r ) {
                if ( (string) ( $r['fingerprint'] ?? '' ) !== $fp ) { continue; }
                $last = strtotime( (string) ( $r['last_used'] ?? '' ) );
                if ( $last > 0 && ( $now - $last ) < self::TOUCH_INTERVAL_SECONDS ) { return false; }
                $r['last_used'] = gmdate( 'c', $now );
                $changed = true;
            }
            unset( $r );
            return $changed;
        } );
    }
    private static function failure_rate_limit(): bool {
        if(!function_exists('get_transient')||!function_exists('set_transient'))return true;
        $ip=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'unknown';$bucket=(int)floor(time()/300);$key='prstudio_a3_fail_'.substr(hash('sha256',$ip.'|'.$bucket),0,32);$count=(int)get_transient($key);if($count>=30)return false;set_transient($key,$count+1,360);return true;
    }
    private static function origin_allowed(): bool {
        $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));if(''===$origin)return true;
        $defaults=array('https://chatgpt.com','https://chat.openai.com','https://platform.openai.com');$settings=function_exists('get_option')?get_option('wpaib_settings',array()):array();$allowed=array_values(array_unique(array_merge($defaults,is_array($settings)?(array)($settings['allowed_origins']??array()):array())));
        return in_array(rtrim($origin,'/'),array_map(static fn($v)=>rtrim((string)$v,'/'),$allowed),true);
    }
    private static function rate_limit(string $fingerprint): bool {
        if(!function_exists('get_transient')||!function_exists('set_transient'))return true;
        $ip=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'unknown'; $bucket=(int)floor(time()/60); $key='prstudio_a3_rl_'.substr(hash('sha256',$fingerprint.'|'.$ip.'|'.$bucket),0,32);
        $count=(int)get_transient($key); if($count>=120)return false; set_transient($key,$count+1,90); return true;
    }
    public static function permission( $request, string $scope ) {
        if(!self::origin_allowed())return new WP_Error('prstudio_actions_origin_denied','Request Origin is not allowed.',array('status'=>403));
        $v=self::verify_token(self::token_from_request($request),$scope);
        if(empty($v['ok'])){if(!self::failure_rate_limit())return new WP_Error('prstudio_actions_bruteforce_limited','Too many failed authentication attempts.',array('status'=>429));$status='scope_denied'===($v['code']??'')?403:401;return new WP_Error('prstudio_actions_'.$v['code'],'GPT Actions authentication failed.',array('status'=>$status,'reason'=>$v['code']));}
        if(!self::rate_limit((string)$v['fingerprint'])){return new WP_Error('prstudio_actions_rate_limited','Rate limit exceeded.',array('status'=>429));}
        if(is_object($request)&&method_exists($request,'set_param')){$request->set_param('_prstudio_actions_identity',$v);} return true;
    }
    /** @deprecated GPT Actions never fall back to an expiring bridge/OAuth session. */
    public static function permission_or_legacy( $request, string $scope, bool $write=false ) {
        return self::permission($request,$scope);
    }
}
