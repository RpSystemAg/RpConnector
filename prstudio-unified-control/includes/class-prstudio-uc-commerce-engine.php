<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Commerce_Engine {
    /**
     * The fields a commerce mutation can change -- and therefore the fields a
     * plan is made against.
     *
     * Every other WordPress mutation path in this suite already binds a write
     * to the version of the state it was planned on: the content transaction
     * carries `expected_before_sha256`, file writes demand `expected_sha256`,
     * the option store does a real compare-and-swap, the job queue claims rows
     * with SELECT..FOR UPDATE. The two commerce executors did not, and their
     * input schemas could not even carry a precondition. So a caller could read
     * a product at 10.00, decide to add a sale price, and write it after cron,
     * an importer or a second agent had already moved the regular price to
     * 25.00 -- and the result would report `changed_fields: [sale_price]` with
     * no hint that the plan had been made against a product that no longer
     * exists in that form.
     *
     * `state_sha256` is that missing version label. It covers exactly the
     * mutable surface, so a change to `permalink` or `type` (which no caller
     * can write here) does not produce a false skew, and a change to any field
     * a plan could have depended on does.
     */
    private const STATE_FIELDS = array(
        'name', 'slug', 'sku', 'status', 'description', 'short_description',
        'regular_price', 'sale_price', 'stock_quantity', 'stock_status',
        'catalog_visibility', 'featured', 'virtual', 'downloadable', 'seo',
    );
    private const SEO_FIELDS = array( 'focus_keyword', 'title', 'description', 'canonical' );

    private static function product(int $id){if(!function_exists('wc_get_product'))return new WP_Error('prstudio_woocommerce_unavailable','WooCommerce is unavailable.',array('status'=>503));$p=wc_get_product($id);return $p?:new WP_Error('prstudio_product_missing','Product not found.',array('status'=>404,'id'=>$id));}

    /**
     * Content hash of one product's mutable state.
     *
     * Deterministic by construction: the key order comes from STATE_FIELDS and
     * SEO_FIELDS rather than from whatever order the snapshot happened to be
     * built in, booleans are normalized so `false` and `''` cannot collide, and
     * a missing field encodes as the empty string rather than disappearing.
     */
    public static function state_sha256( array $snapshot ): string {
        $state = array();
        foreach ( self::STATE_FIELDS as $field ) {
            if ( 'seo' === $field ) {
                $seo = is_array( $snapshot['seo'] ?? null ) ? $snapshot['seo'] : array();
                $normalized = array();
                foreach ( self::SEO_FIELDS as $seo_field ) {
                    $value = $seo[ $seo_field ] ?? '';
                    $normalized[ $seo_field ] = is_scalar( $value ) ? (string) $value : '';
                }
                $state['seo'] = $normalized;
                continue;
            }
            $value = $snapshot[ $field ] ?? null;
            if ( is_bool( $value ) ) { $state[ $field ] = $value ? 'true' : 'false'; continue; }
            $state[ $field ] = ( null === $value || ! is_scalar( $value ) ) ? '' : (string) $value;
        }
        $json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $state ) : json_encode( $state );
        return hash( 'sha256', is_string( $json ) ? $json : '' );
    }

    private static function snapshot( $p, int $id ): array {
        $snapshot = array('id'=>$id,'name'=>(string)$p->get_name(),'slug'=>(string)$p->get_slug(),'sku'=>(string)$p->get_sku(),'status'=>(string)$p->get_status(),'type'=>(string)$p->get_type(),'description'=>(string)$p->get_description(),'short_description'=>(string)$p->get_short_description(),'regular_price'=>(string)$p->get_regular_price(),'sale_price'=>(string)$p->get_sale_price(),'stock_quantity'=>$p->get_stock_quantity(),'stock_status'=>(string)$p->get_stock_status(),
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
        // The version label a later mutation binds itself to. It is part of the
        // read because the read is the only place it can be computed honestly.
        $snapshot['state_sha256'] = self::state_sha256( $snapshot );
        return $snapshot;
    }

    public static function product_read(array $args){
        $id=(int)($args['id']??0);$p=self::product($id);if(is_wp_error($p))return $p;
        return self::snapshot($p,$id);
    }

    /**
     * Compare the product as it is right now against the version this mutation
     * was planned on, and describe the outcome.
     *
     * This returns evidence and nothing else. A skew, an unusable token or an
     * absent declaration lowers `verified` and raises `degraded`; none of them
     * stops the write, refuses the call or rolls anything back. Anti-Crash
     * remains the only thing allowed to stop a technically valid mutation, and
     * a caller who did not declare a version still gets its write -- it just
     * also gets told, in the result, that nothing proves the write landed on
     * the product the plan described.
     */
    private static function state_binding( array $args, int $id, string $observed ): array {
        $binding = array(
            'bound' => false,
            'matched' => null,
            'source' => 'none',
            'planned_state_sha256' => '',
            'observed_state_sha256' => $observed,
            'blocking' => false,
            'detail' => 'This mutation declared no state version, so nothing shows it was planned against the product as it is now. Pass expected_state_sha256 from commerce.product.read, or the write_token prstudio_observe returns for this product.',
        );
        $expected = strtolower( trim( (string) ( $args['expected_state_sha256'] ?? '' ) ) );
        $source = '' !== $expected ? 'expected_state_sha256' : 'none';
        $token = trim( (string) ( $args['write_token'] ?? '' ) );
        if ( '' === $expected && '' !== $token && class_exists( 'PRSTUDIO_UC_Write_Token' ) ) {
            $verified = PRSTUDIO_UC_Write_Token::verify( $token, 'product:' . $id, (string) ( $args['_client_id'] ?? '' ) );
            if ( is_wp_error( $verified ) ) {
                // An unusable token is exactly as unbound as no token at all,
                // and it is reported that way rather than swallowed. It is not
                // an error return: the caller asked for a write.
                $binding['token_error'] = $verified->get_error_code();
                $binding['detail'] = 'The observation token could not be verified, so this mutation is unbound. It was executed anyway; observe the product again to obtain a token that binds.';
                return $binding;
            }
            // Only `state_sha256` binds here. A product token that carried some
            // other hash would compare unequal against every real product state
            // and manufacture a permanent skew, which is worse than reporting
            // the token as carrying nothing to bind to.
            $facts = is_array( $verified['facts'] ?? null ) ? $verified['facts'] : array();
            $expected = strtolower( trim( (string) ( $facts['state_sha256'] ?? '' ) ) );
            if ( '' === $expected ) {
                $binding['token_error'] = 'prstudio_write_token_without_state';
                $binding['detail'] = 'The observation token is valid for this product but carries no commerce state hash, so this mutation is unbound. It was executed anyway; observe the product again to obtain a token that binds.';
                return $binding;
            }
            $source = 'write_token';
        }
        if ( '' === $expected ) { return $binding; }
        $binding['bound'] = true;
        $binding['source'] = $source;
        $binding['planned_state_sha256'] = $expected;
        $binding['matched'] = hash_equals( $observed, $expected );
        $binding['detail'] = $binding['matched']
            ? 'The product still matches the version this mutation was planned against.'
            : 'The product changed between the read this mutation was planned on and this write. The write was executed; fields the request did not name may no longer hold what the plan assumed.';
        return $binding;
    }

    /** Evidence shape for a mutation whose planned-state binding is absent or stale. */
    private static function outcome( array $binding, bool $mutated ): array {
        $skewed = false === $binding['matched'];
        return array(
            'status' => 'completed',
            'executed' => true,
            'mutated' => $mutated,
            'verified' => false,
            'degraded' => $skewed || empty( $binding['bound'] ),
            'blocking' => false,
            'degraded_reason' => $skewed ? 'planned_state_superseded' : ( empty( $binding['bound'] ) ? 'planned_state_not_declared' : '' ),
        );
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

        // Read the live state once, immediately before the first setter, and
        // compare it with the version the caller planned on. Observation only:
        // whatever it finds, the write below still happens.
        $binding=self::state_binding($args,$id,self::state_sha256(self::snapshot($p,$id)));

        $changed=array();foreach($map as $key=>$method){if(self::set_if($p,$method,$changes,$key))$changed[]=$key;}
        if($changed){try{$p->save();}catch(Throwable $e){return new WP_Error('prstudio_product_save_failed',$e->getMessage(),array('status'=>500));}}
        if($seo_changes&&function_exists('update_post_meta')){
            foreach($seo_changes as $key=>$value){update_post_meta($id,$seo_map[$key],is_scalar($value)?(string)$value:'');$changed[]='seo.'.$key;}
        }
        return array('id'=>$id,'changed'=>count($changed),'changed_fields'=>$changed,'state_binding'=>$binding,'after'=>self::product_read(array('id'=>$id)),'_control_outcome'=>self::outcome($binding,!empty($changed)));
    }
    public static function inventory_update(array $args){
        $id=(int)($args['id']??0);$p=self::product($id);if(is_wp_error($p))return $p;$changed=array();
        $binding=self::state_binding($args,$id,self::state_sha256(self::snapshot($p,$id)));
        if(array_key_exists('stock_quantity',$args)){if(method_exists($p,'set_manage_stock'))$p->set_manage_stock(true);$p->set_stock_quantity((int)$args['stock_quantity']);$changed[]='stock_quantity';}
        if(array_key_exists('stock_status',$args)){if(!in_array((string)$args['stock_status'],array('instock','outofstock','onbackorder'),true))return new WP_Error('prstudio_stock_status_invalid','Invalid stock_status.',array('status'=>400));$p->set_stock_status((string)$args['stock_status']);$changed[]='stock_status';}
        if(!$changed)return new WP_Error('prstudio_inventory_no_change','No inventory change supplied.',array('status'=>400));
        try{$p->save();}catch(Throwable $e){return new WP_Error('prstudio_inventory_save_failed',$e->getMessage(),array('status'=>500));}
        return array('id'=>$id,'changed'=>count($changed),'changed_fields'=>$changed,'state_binding'=>$binding,'after'=>self::product_read(array('id'=>$id)),'_control_outcome'=>self::outcome($binding,true));
    }
}
