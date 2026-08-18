<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** Google Search Console fallback through the authenticated personal Chrome. */
final class PRSTUDIO_UC_Search_Console_Browser {
    private const URL = 'https://search.google.com/search-console/';
    private const STRICT_DIMENSION_PROTOCOL = '4.0.0';
    private const DIMENSION_SESSION_FEATURE = '1.0.0';

    private static function online_devices(): array {
        $devices = class_exists('PRSTUDIO_UC_Store') ? PRSTUDIO_UC_Store::list_devices() : array();
        return array_values(array_filter($devices, static fn($device)=>!empty($device['online'])));
    }
    private static function protocol(array $device): string {
        $c=(array)($device['capabilities']??array());
        return sanitize_text_field((string)($c['executorProtocolVersion']??$c['executor_protocol_version']??''));
    }
    private static function dimension_session_version(array $device): string {
        $c=(array)($device['capabilities']??array());
        return sanitize_text_field((string)($c['gscDimensionSessionVersion']??$c['gsc_dimension_session_version']??''));
    }
    private static function dimension_session_supported(array $device): bool {
        $feature=self::dimension_session_version($device);
        if(''!==$feature && version_compare($feature,self::DIMENSION_SESSION_FEATURE,'>=')) return true;
        // Accept the already-issued 4.0.0 Agent as a compatibility bridge.
        return version_compare(self::protocol($device),self::STRICT_DIMENSION_PROTOCOL,'>=');
    }
    public static function status(): array {
        $devices=class_exists('PRSTUDIO_UC_Store')?PRSTUDIO_UC_Store::list_devices():array();$online=self::online_devices();$protocols=array_values(array_unique(array_filter(array_map(array(__CLASS__,'protocol'),$online))));
        return array(
            'provider'=>'prstudio_browser_agent_same_profile','available'=>!empty($online),'connected'=>!empty($online),
            'requires_oauth_configuration'=>false,'requires_browser_login'=>true,'login_managed_by_user'=>true,'url'=>self::URL,
            'devices'=>$devices,'online_executor_protocols'=>$protocols,'stable_wire_protocol'=>'3.0.0','strict_dimension_protocol_legacy'=>self::STRICT_DIMENSION_PROTOCOL,'dimension_session_feature'=>self::DIMENSION_SESSION_FEATURE,
            'collector'=>'gsc_dimension_session_v4','structured_metrics'=>true,'tab_affinity'=>'persistent_agent_owned',
            'single_tab_dependency'=>true,'same_page_dimension_switch'=>true,'cross_dimension_join_in_browser'=>false,
        );
    }
    private static function requested_dimensions(array $args): array {
        $source=(array)($args['dimensions']??array('query'));$out=array();
        foreach($source as $dimension){
            $raw=strtolower(preg_replace('/[^a-zA-Z]/','',(string)$dimension));
            $canonical='searchappearance'===$raw?'searchAppearance':('dates'===$raw?'date':$raw);
            if(!in_array($canonical,$out,true))$out[]=$canonical;
        }
        return $out?:array('query');
    }
    private static function dimension_protocol_ok(array $args): bool {
        $dims=self::requested_dimensions($args);
        $strict=count($dims)>1||!empty(array_intersect($dims,array('page','country','device','date','searchAppearance')));
        if(!$strict)return true;
        foreach(self::online_devices() as $device){if(self::dimension_session_supported($device))return true;}
        return false;
    }
    private static function execute(string $action,array $args=array()) {
        $args['url']=self::URL;$args['browser_target']='live';$args['sync_wait_seconds']=max(1,min(20,(int)($args['sync_wait_seconds']??5)));
        $result=PRSTUDIO_UC_Bridge::dispatch(null,$args,array('action'=>$action));
        if(is_array($result)){$result['provider']='prstudio_browser_agent_same_profile';$result['login_managed_by_user']=true;}
        return $result;
    }
    public static function sites(array $args=array()){return self::execute('search_console_sites',$args);}
    public static function analytics(array $args){
        $dims=self::requested_dimensions($args);
        $supported=array('query','page','country','device','date','searchAppearance');
        $unsupported=array_values(array_diff($dims,$supported));
        if($unsupported)return new WP_Error('prstudio_gsc_browser_dimension_unsupported','The Browser Search Console collector cannot verify the requested dimension. The hourly dimension is API-only in this build because the Search Console 24-hour view has no dimension table to bind.',array('status'=>400,'retryable'=>false,'unsupported_dimensions'=>$unsupported,'supported_dimensions'=>$supported));
        if(!empty($args['start_row'])||!empty($args['dimension_filter_groups']))return new WP_Error('prstudio_gsc_browser_query_shape_unsupported','start_row and dimension_filter_groups are Search Analytics API query controls and are not silently emulated by the Browser-only collector.',array('status'=>400,'retryable'=>false));
        if(!self::dimension_protocol_ok($args))return new WP_Error('prstudio_gsc_browser_dimension_protocol_outdated','Browser Agent with GSC dimension-session support is required for verified page/country/device/date/searchAppearance or multi-dimension GSC collection. Pairing remains valid; update/reload the same extension.',array('status'=>409,'retryable'=>false,'details'=>self::status()));
        $args['collector_contract']='gsc_dimension_session_v4';$args['require_dimension_integrity']=true;
        return self::execute('search_console_search_analytics',$args);
    }
    public static function sitemaps(array $args){return self::execute('search_console_sitemaps',$args);}
    public static function inspect_url(array $args){return self::execute('search_console_url_inspection',$args);}
    public static function request_indexing(array $args){
        $args['request_indexing']=true;
        // Generic landing pages must use the authenticated Search Console UI;
        // do not route this to Google's restricted Indexing API.
        return self::execute('search_console_url_inspection',$args);
    }
}
