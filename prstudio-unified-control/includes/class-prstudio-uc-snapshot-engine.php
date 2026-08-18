<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Snapshot_Engine {
    private static function dir():string{$base=class_exists('PRSTUDIO_UC_Memory')?PRSTUDIO_UC_Memory::site_dir():(defined('WP_CONTENT_DIR')?WP_CONTENT_DIR:sys_get_temp_dir());$d=rtrim($base,'/').'/snapshots';if(!is_dir($d)){function_exists('wp_mkdir_p')?wp_mkdir_p($d):@mkdir($d,0750,true);}return $d;}
    private static function path(string $job):string{return self::dir().'/'.preg_replace('/[^a-zA-Z0-9._-]+/','_',substr($job,0,100)).'.json';}
    public static function create(string $job,array $cap,array $args):array{
        $policy=(string)($cap['snapshot_policy']??'none'); if('none'===$policy||empty($cap['write']))return array('ok'=>true,'created'=>false,'policy'=>$policy);
        $data=array('capability'=>$cap['id']??'','arguments'=>class_exists('PRSTUDIO_UC_Memory')?PRSTUDIO_UC_Memory::redact($args):$args,'created_at'=>gmdate('c'),'policy'=>$policy);
        if(in_array((string)($cap['id']??''),array('commerce.product.update','commerce.inventory.update'),true)&&class_exists('PRSTUDIO_UC_Commerce_Engine')){$data['before']=PRSTUDIO_UC_Commerce_Engine::product_read(array('id'=>(int)($args['id']??0)));}
        elseif(str_starts_with((string)($cap['id']??''),'database.')&&class_exists('PRSTUDIO_UC_Database_Backend')){$data['database_snapshot']='requires_explicit_table_or_migration_snapshot';}
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$ok=false!==@file_put_contents(self::path($job),$json."\n",LOCK_EX);if($ok)@chmod(self::path($job),0640);
        return array('ok'=>$ok,'created'=>$ok,'policy'=>$policy,'snapshot_id'=>$ok?basename(self::path($job)):null,'sha256'=>$ok?hash('sha256',$json):null);
    }
    public static function rollback_job(array $args,array $cap=array()){
        $job=(string)($args['job_id']??'');$path=self::path($job);if(''===$job||!is_readable($path))return new WP_Error('prstudio_snapshot_missing','Snapshot not found for job.',array('status'=>404));
        $d=json_decode((string)file_get_contents($path),true);if(!is_array($d))return new WP_Error('prstudio_snapshot_invalid','Snapshot is invalid.',array('status'=>500));
        $cid=(string)($d['capability']??'');$before=(array)($d['before']??array());
        if('commerce.product.update'===$cid||'commerce.inventory.update'===$cid){
            $id=(int)($before['id']??0);if(!$id)return new WP_Error('prstudio_snapshot_incomplete','Product snapshot incomplete.',array('status'=>500));
            $changes=array('name'=>$before['name']??'','slug'=>$before['slug']??'','description'=>$before['description']??'','short_description'=>$before['short_description']??'','status'=>$before['status']??'','regular_price'=>$before['regular_price']??'','sale_price'=>$before['sale_price']??'','sku'=>$before['sku']??'','seo'=>$before['seo']??array());
            // Restore the four flags product_update() can change. Only send the
            // ones the snapshot actually captured: an older snapshot predating
            // this fix has no value for them, and inventing a default would
            // overwrite the live setting with a guess.
            foreach(array('catalog_visibility','featured','virtual','downloadable') as $flag){
                if(array_key_exists($flag,$before))$changes[$flag]=$before[$flag];
            }
            $incomplete=array_values(array_diff(array('catalog_visibility','featured','virtual','downloadable'),array_keys($before)));
            $r=PRSTUDIO_UC_Commerce_Engine::product_update(array('id'=>$id,'changes'=>$changes)); if(is_wp_error($r))return $r;
            if(array_key_exists('stock_quantity',$before)||array_key_exists('stock_status',$before)){$inv=array('id'=>$id);if(array_key_exists('stock_quantity',$before))$inv['stock_quantity']=$before['stock_quantity'];if(array_key_exists('stock_status',$before))$inv['stock_status']=$before['stock_status'];
                // The result of this restore used to be discarded while the
                // caller was still told verified:true. A rollback that partly
                // failed and reports success is worse than one that fails
                // loudly: the operator stops looking.
                $inventory_result=PRSTUDIO_UC_Commerce_Engine::inventory_update($inv);
                if(is_wp_error($inventory_result))return new WP_Error(
                    'prstudio_rollback_inventory_failed',
                    'Product fields were restored but stock was not: '.$inventory_result->get_error_message(),
                    array('status'=>500,'capability'=>$cid,'job_id'=>$job,'fields_restored'=>true,'stock_restored'=>false,'partial'=>true)
                );
            }
            // Verified means the pre-change state was fully reinstated. A
            // snapshot missing mutable fields cannot support that claim, so say
            // so rather than asserting a restoration that did not happen.
            $fully_restored=empty($incomplete);
            return array(
                'ok'=>true,'job_id'=>$job,'capability'=>$cid,'rollback'=>'semantic_product_restore','result'=>$r,
                'verified'=>$fully_restored,
                'fields_not_in_snapshot'=>$incomplete,
                'degraded'=>!$fully_restored,
                'degraded_reason'=>$fully_restored?'':'The snapshot predates full field coverage, so these fields keep their current values.',
            );
        }
        return new WP_Error('prstudio_rollback_not_supported','Semantic rollback is not available for this snapshot type.',array('status'=>409,'capability'=>$cid));
    }
}
