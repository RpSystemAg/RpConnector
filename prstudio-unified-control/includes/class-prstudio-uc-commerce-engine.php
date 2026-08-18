<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Commerce_Engine {
    private static function product(int $id){if(!function_exists('wc_get_product'))return new WP_Error('prstudio_woocommerce_unavailable','WooCommerce is unavailable.',array('status'=>503));$p=wc_get_product($id);return $p?:new WP_Error('prstudio_product_missing','Product not found.',array('status'=>404,'id'=>$id));}
    public static function product_read(array $args){
        $id=(int)($args['id']??0);$p=self::product($id);if(is_wp_error($p))return $p;
        return array('id'=>$id,'name'=>(string)$p->get_name(),'slug'=>(string)$p->get_slug(),'sku'=>(string)$p->get_sku(),'status'=>(string)$p->get_status(),'type'=>(string)$p->get_type(),'description'=>(string)$p->get_description(),'short_description'=>(string)$p->get_short_description(),'regular_price'=>(string)$p->get_regular_price(),'sale_price'=>(string)$p->get_sale_price(),'stock_quantity'=>$p->get_stock_quantity(),'stock_status'=>(string)$p->get_stock_status(),
            // These four are writable through product_update() but were absent
            // from the snapshot, so a rollback could not restore them and still
            // reported verified:true -- leaving the product visibly different
            // from its pre-change state while claiming it had been restored.
            // A snapshot has to cover every mutable field or it is not a snapshot.
            'catalog_visibility'=>(string)$p->get_catalog_visibility(),
            'featured'=>(bool)$p->get_featured(),
            'virtual'=>(bool)$p->get_virtual(),
            'downloadable'=>(bool)$p->get_downloadable(),
            'image_id'=>(int)$p->get_image_id(),'permalink'=>function_exists('get_permalink')?(string)get_permalink($id):'','seo'=>array('focus_keyword'=>function_exists('get_post_meta')?(string)get_post_meta($id,'rank_math_focus_keyword',true):'','title'=>function_exists('get_post_meta')?(string)get_post_meta($id,'rank_math_title',true):'','description'=>function_exists('get_post_meta')?(string)get_post_meta($id,'rank_math_description',true):'','canonical'=>function_exists('get_post_meta')?(string)get_post_meta($id,'rank_math_canonical_url',true):''),'verified'=>true,'source'=>'woocommerce_crud_plus_wordpress_meta');
    }
    private static function set_if($p,string $method,array $changes,string $key):bool{if(!array_key_exists($key,$changes))return false;if(!method_exists($p,$method))return false;$p->{$method}($changes[$key]);return true;}
    public static function product_update(array $args){
        $id=(int)($args['id']??0);$changes=(array)($args['changes']??array());$p=self::product($id);if(is_wp_error($p))return $p;
        $allowed=array('name','slug','description','short_description','status','regular_price','sale_price','catalog_visibility','featured','virtual','downloadable','sku');
        $unknown=array_diff(array_keys($changes),array_merge($allowed,array('seo'))); if($unknown)return new WP_Error('prstudio_product_field_unsupported','Unsupported product fields.',array('status'=>400,'fields'=>array_values($unknown)));
        $map=array('name'=>'set_name','slug'=>'set_slug','description'=>'set_description','short_description'=>'set_short_description','status'=>'set_status','regular_price'=>'set_regular_price','sale_price'=>'set_sale_price','catalog_visibility'=>'set_catalog_visibility','featured'=>'set_featured','virtual'=>'set_virtual','downloadable'=>'set_downloadable','sku'=>'set_sku');
        // Validate the ENTIRE payload before the first side effect. The SEO keys
        // used to be checked only after the product had already been saved, so a
        // request carrying a valid price change and one unsupported SEO key
        // returned a 400 with the price already written -- and the SEO loop
        // itself could update several metas before hitting the bad key, leaving
        // a half-applied change behind a validation error. A caller that sees a
        // validation failure must be able to assume nothing was written.
        $seo_map=array('focus_keyword'=>'rank_math_focus_keyword','title'=>'rank_math_title','description'=>'rank_math_description','canonical'=>'rank_math_canonical_url');
        $seo_changes=isset($changes['seo'])&&is_array($changes['seo'])?$changes['seo']:array();
        if($seo_changes){
            $bad=array_values(array_diff(array_keys($seo_changes),array_keys($seo_map)));
            if($bad)return new WP_Error('prstudio_product_seo_field_unsupported','Unsupported SEO field.',array('status'=>400,'fields'=>$bad,'supported'=>array_keys($seo_map),'applied'=>false));
        }

        $changed=array();foreach($map as $key=>$method){if(self::set_if($p,$method,$changes,$key))$changed[]=$key;}
        if($changed){try{$p->save();}catch(Throwable $e){return new WP_Error('prstudio_product_save_failed',$e->getMessage(),array('status'=>500));}}
        if($seo_changes&&function_exists('update_post_meta')){
            foreach($seo_changes as $key=>$value){update_post_meta($id,$seo_map[$key],is_scalar($value)?(string)$value:'');$changed[]='seo.'.$key;}
        }
        return array('id'=>$id,'changed'=>count($changed),'changed_fields'=>$changed,'after'=>self::product_read(array('id'=>$id)),'_control_outcome'=>array('status'=>'completed','executed'=>true,'mutated'=>!empty($changed),'verified'=>false));
    }
    public static function inventory_update(array $args){
        $id=(int)($args['id']??0);$p=self::product($id);if(is_wp_error($p))return $p;$changed=array();
        if(array_key_exists('stock_quantity',$args)){if(method_exists($p,'set_manage_stock'))$p->set_manage_stock(true);$p->set_stock_quantity((int)$args['stock_quantity']);$changed[]='stock_quantity';}
        if(array_key_exists('stock_status',$args)){if(!in_array((string)$args['stock_status'],array('instock','outofstock','onbackorder'),true))return new WP_Error('prstudio_stock_status_invalid','Invalid stock_status.',array('status'=>400));$p->set_stock_status((string)$args['stock_status']);$changed[]='stock_status';}
        if(!$changed)return new WP_Error('prstudio_inventory_no_change','No inventory change supplied.',array('status'=>400));
        try{$p->save();}catch(Throwable $e){return new WP_Error('prstudio_inventory_save_failed',$e->getMessage(),array('status'=>500));}
        return array('id'=>$id,'changed'=>count($changed),'changed_fields'=>$changed,'after'=>self::product_read(array('id'=>$id)),'_control_outcome'=>array('status'=>'completed','executed'=>true,'mutated'=>true,'verified'=>false));
    }
}
