<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** Event-driven change-data-capture used to invalidate stale Enterprise memory. */
final class PRSTUDIO_UC_Change_Tracker {
    public static function register(): void {
        if ( ! function_exists( 'add_action' ) ) { return; }
        add_action( 'save_post', array( __CLASS__, 'post' ), 99, 3 );
        add_action( 'before_delete_post', array( __CLASS__, 'deleted' ), 99, 1 );
        add_action( 'set_object_terms', array( __CLASS__, 'terms' ), 99, 6 );
        add_action( 'added_post_meta', array( __CLASS__, 'post_meta' ), 99, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'post_meta' ), 99, 4 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'post_meta' ), 99, 4 );
        add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'stock' ), 99, 1 );
        add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'stock' ), 99, 1 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'upgrade' ), 99, 2 );
        add_action( 'wp_update_attachment_metadata', array( __CLASS__, 'attachment_metadata' ), 99, 2 );
        add_action( 'update_option_permalink_structure', array( __CLASS__, 'permalink_changed' ), 99, 3 );
        add_action( 'clean_post_cache', array( __CLASS__, 'cache_cleaned' ), 99, 1 );
        add_action( 'prstudio_uc_incremental_maintenance', array( __CLASS__, 'maintenance' ) );
    }
    private static function hit( string $id, string $event, array $extra = array() ): void {
        PRSTUDIO_UC_Memory::invalidate_all_views( $id, $event );
        if ( ctype_digit( $id ) && function_exists( 'get_permalink' ) ) {
            $url = (string) get_permalink( (int) $id );
            if ( '' !== $url ) { PRSTUDIO_UC_Memory::invalidate_all_views( $url, $event ); }
        }
        PRSTUDIO_UC_Memory::movement( 'cdc.' . $event, array_merge( array( 'resource'=>$id, 'outcome'=>'invalidated' ), $extra ) );
        self::schedule();
    }
    public static function post( int $id, $post, bool $update ): void {
        if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $id ) ) { return; }
        self::hit( (string) $id, 'post_saved', array( 'post_type'=>is_object($post)?(string)$post->post_type:'', 'update'=>$update ) );
    }
    public static function deleted( int $id ): void { self::hit( (string) $id, 'post_deleted' ); }
    public static function terms( $id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void { self::hit( (string) $id, 'terms_changed', array( 'taxonomy'=>(string)$taxonomy ) ); }
    public static function post_meta( $meta_id, $object_id, $meta_key, $meta_value ): void {
        $id = (int) $object_id; if ( $id <= 0 ) { return; }
        self::hit( (string) $id, 'post_meta_changed', array( 'meta_key'=>substr( (string)$meta_key, 0, 120 ) ) );
        if ( '_wp_attachment_image_alt' === (string) $meta_key ) {
            PRSTUDIO_UC_Memory::invalidate_type( 'seo_product_audit', 'attachment_alt_changed' );
        }
    }
    public static function attachment_metadata( $metadata, int $attachment_id ) { self::hit( (string)$attachment_id, 'attachment_metadata_changed' ); return $metadata; }
    public static function permalink_changed( $old, $new, $option = '' ): void { PRSTUDIO_UC_Memory::invalidate_type( 'rendered_links', 'permalink_changed' ); PRSTUDIO_UC_Memory::invalidate_type( 'http_status', 'permalink_changed' ); PRSTUDIO_UC_Memory::invalidate_type( 'seo_graph', 'permalink_changed' ); PRSTUDIO_UC_Memory::movement( 'cdc.permalink_changed', array('resource'=>'permalink_structure','state_initial'=>(string)$old,'action'=>(string)$new,'outcome'=>'invalidated') ); self::schedule(60); }
    public static function cache_cleaned( int $id ): void { if($id>0){ PRSTUDIO_UC_Memory::invalidate_all_views((string)$id,'post_cache_cleaned'); } }
    public static function stock( $product ): void {
        $id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
        if ( $id ) { self::hit( (string) $id, 'stock_changed' ); }
    }
    public static function upgrade( $upgrader, array $extra ): void {
        PRSTUDIO_UC_Memory::invalidate_type( 'rendered_links', 'wordpress_upgrade' );
        PRSTUDIO_UC_Memory::invalidate_type( 'http_status', 'wordpress_upgrade' );
        PRSTUDIO_UC_Memory::invalidate_type( 'seo_graph', 'wordpress_upgrade' );
        PRSTUDIO_UC_Memory::invalidate_type( 'product_audit_v3', 'wordpress_upgrade' );
        PRSTUDIO_UC_Memory::movement( 'cdc.upgrade', array( 'type'=>$extra['type']??'', 'action'=>$extra['action']??'', 'outcome'=>'global_context_invalidated' ) );
        PRSTUDIO_UC_Memory::save_context( array() ); self::schedule( 60 );
    }
    public static function schedule( int $delay = 300 ): bool {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) { return false; }
        if ( wp_next_scheduled( 'prstudio_uc_incremental_maintenance' ) ) { return true; }
        $day = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
        return false !== wp_schedule_single_event( time() + max( 60, min( $day, $delay ) ), 'prstudio_uc_incremental_maintenance' );
    }
    public static function maintenance(): void {
        $healed = PRSTUDIO_UC_Safety_Runtime::self_heal();
        PRSTUDIO_UC_Memory::movement( 'scheduler.incremental_maintenance', array( 'outcome'=>'completed', 'method'=>'event_driven_safe_housekeeping', 'recovery'=>$healed ) );
    }
}
