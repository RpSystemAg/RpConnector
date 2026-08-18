<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Impact_Engine {
    private static function resource_value($value): string { if(is_string($value)) return trim($value); if(is_int($value)) return (string)$value; return ''; }
    private static function count_hint($value): int { if(is_int($value)) return max(0,$value); if(is_string($value)&&preg_match('/^\d+$/',trim($value))) return max(0,(int)$value); return 0; }
    public static function analyze( array $cap, array $args ): array {
        $resources=array();
        foreach(array('id','product_id','post_id','url','path','site_url') as $k){$value=self::resource_value($args[$k]??null);if(''!==$value)$resources[]=$k.':'.substr($value,0,240);}
        foreach(array('ids','urls','product_ids','post_ids') as $k){if(is_array($args[$k]??null)){foreach(array_slice($args[$k],0,1000) as $v){$value=self::resource_value($v);if(''!==$value)$resources[]=$k.':'.substr($value,0,240);}}}
        $estimated=max(1,count($resources),self::count_hint($args['limit']??0),self::count_hint($args['batch_size']??0));
        $risk=is_string($cap['risk_level']??null)?strtolower(trim($cap['risk_level'])):'medium';if(!in_array($risk,array('low','medium','high','critical'),true))$risk='medium';
        $write=is_bool($cap['write']??null)?$cap['write']:(is_bool($cap['read_only']??null)?!$cap['read_only']:false);
        $domain=is_string($cap['domain']??null)?$cap['domain']:'';$cap_id=is_string($cap['id']??null)?$cap['id']:'';
        return array(
            'entities'=>$estimated,'known_resources'=>array_slice(array_values(array_unique($resources)),0,1000),
            'urls'=>count(array_filter($resources,static fn($x)=>str_starts_with($x,'url'))),
            'database_rows_estimate'=>in_array($domain,array('database','commerce'),true)?$estimated:0,
            'cache_invalidation'=>$write,'sitemap_impact'=>$write&&in_array($domain,array('seo','commerce'),true),
            'canonical_redirect_impact'=>$write&&str_contains($cap_id,'seo'),
            'risk_level'=>$risk,'advisory_only'=>true,'blocking'=>false,
        );
    }
}
