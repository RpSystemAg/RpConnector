<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Site_Context {
    public static function current():array{$id=PRSTUDIO_UC_Memory::site_identity();return array('namespace'=>$id['key'],'blog_id'=>$id['blog_id'],'host'=>$id['host'],'path'=>$id['path'],'site_url'=>function_exists('home_url')?home_url('/'):'');}
    public static function execute($site,callable $callback){
        $current=self::current();
        if(null===$site){$site='';}
        elseif(is_int($site)){$site=(string)$site;}
        elseif(is_string($site)){$site=trim($site);}
        else{return new WP_Error('prstudio_site_scope_invalid','Requested site selector must be a namespace string or integer blog ID.',array('status'=>409,'current'=>$current['namespace']));}
        if(''===$site||$site===(string)$current['namespace']||$site===(string)$current['blog_id'])return $callback($current);
        if(function_exists('is_multisite')&&is_multisite()&&ctype_digit($site)&&function_exists('switch_to_blog')&&function_exists('restore_current_blog')){
            $blog=(int)$site;
            if($blog<1||!function_exists('get_site')||!get_site($blog))return new WP_Error('prstudio_site_not_found','Requested site is not available.',array('status'=>404));
            switch_to_blog($blog);try{return $callback(self::current());}finally{restore_current_blog();}
        }
        return new WP_Error('prstudio_site_scope_invalid','Requested site namespace does not match the current site.',array('status'=>409,'current'=>$current['namespace']));
    }
}
