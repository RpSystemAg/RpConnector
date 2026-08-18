<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_HTTP_Engine {
    private static function allowed(string $url):bool{
        $host=strtolower((string)(function_exists('wp_parse_url')?wp_parse_url($url,PHP_URL_HOST):parse_url($url,PHP_URL_HOST)));
        $scheme=strtolower((string)(function_exists('wp_parse_url')?wp_parse_url($url,PHP_URL_SCHEME):parse_url($url,PHP_URL_SCHEME)));
        if('https'!==$scheme&&'http'!==$scheme)return false;
        $home=function_exists('home_url')?(string)home_url('/'):'http://localhost/';
        $home_host=strtolower((string)(function_exists('wp_parse_url')?wp_parse_url($home,PHP_URL_HOST):parse_url($home,PHP_URL_HOST)));
        if($host===$home_host)return true;
        $extra=function_exists('get_option')?get_option('prstudio_uc_http_allow_hosts',array()):array();
        return in_array($host,array_map('strtolower',array_map('strval',is_array($extra)?$extra:array())),true);
    }
    private static function same_site_host(string $url): bool {
        if(!function_exists('home_url'))return false;
        $u=parse_url($url);$h=parse_url(home_url('/'));
        return is_array($u)&&is_array($h)&&strtolower((string)($u['host']??''))===strtolower((string)($h['host']??''));
    }
    private static function same_site_rest(string $url): ?string {
        if(!function_exists('home_url'))return null; $home=home_url('/');
        $u=parse_url($url);$h=parse_url($home); if(!is_array($u)||!is_array($h)||strtolower((string)($u['host']??''))!==strtolower((string)($h['host']??'')))return null;
        $path=(string)($u['path']??''); $needle='/wp-json/'; $pos=strpos($path,$needle); if(false===$pos)return null; return '/'.ltrim(substr($path,$pos+strlen($needle)),'/');
    }
    public static function inspect(array $args){
        $url=trim((string)($args['url']??'')); if(''===$url||!self::allowed($url))return new WP_Error('prstudio_http_ssrf_forbidden','URL is not in the HTTP allowlist.',array('status'=>403));
        $method=strtoupper((string)($args['method']??'HEAD')); if(!in_array($method,array('HEAD','GET'),true))return new WP_Error('prstudio_http_method_invalid','Only HEAD/GET are allowed.',array('status'=>405));
        $max=max(1024,min(2097152,(int)($args['max_bytes']??65536)));
        $rest=self::same_site_rest($url);
        if(null!==$rest&&function_exists('rest_do_request')){
            $req=new WP_REST_Request($method,$rest); $resp=rest_do_request($req); $status=is_object($resp)&&method_exists($resp,'get_status')?(int)$resp->get_status():200;
            return array('url'=>$url,'status'=>$status,'ok'=>$status>=200&&$status<400,'transport'=>'wordpress_internal_rest_dispatch','network_loopback'=>false,'verified'=>true,'body_bytes'=>0);
        }
        $request_args=array('method'=>$method,'timeout'=>8,'redirection'=>2,'reject_unsafe_urls'=>true,'limit_response_size'=>$max,'user-agent'=>'PRSTUDIO-Unified-Control/5.0.0');
        $resp=wp_safe_remote_request($url,$request_args); $status=is_wp_error($resp)?0:(int)wp_remote_retrieve_response_code($resp); $transport='wordpress_http_'.$method;
        if('HEAD'===$method&&(is_wp_error($resp)||0===$status||in_array($status,array(405,501),true))){$request_args['method']='GET';$resp=wp_safe_remote_request($url,$request_args);$status=is_wp_error($resp)?0:(int)wp_remote_retrieve_response_code($resp);$transport='wordpress_http_get_fallback';}
        if(is_wp_error($resp)){if(self::same_site_host($url))return array('url'=>$url,'status'=>0,'ok'=>null,'site_offline'=>false,'transport'=>$transport,'network_loopback'=>true,'verified'=>false,'verification'=>'inconclusive_loopback_transport','transport_error'=>array('code'=>$resp->get_error_code(),'message'=>$resp->get_error_message()),'loopback_not_equal_offline'=>true);return new WP_Error('prstudio_http_transport',$resp->get_error_message(),array('status'=>502,'original_code'=>$resp->get_error_code(),'loopback_not_equal_offline'=>true));}
        $body=(string)wp_remote_retrieve_body($resp);
        return array('url'=>$url,'status'=>$status,'ok'=>$status>=200&&$status<400,'transport'=>$transport,'network_loopback'=>true,'verified'=>true,'body_bytes'=>strlen($body),'body'=>substr($body,0,$max));
    }
}
