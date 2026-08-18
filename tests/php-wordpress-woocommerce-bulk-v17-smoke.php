<?php

declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', sys_get_temp_dir() . '/prstudio-wp-wc-v17/');
$GLOBALS['wc_bulk_calls']=0;$GLOBALS['wc_single_calls']=0;
function absint($v){return abs((int)$v);}
final class FakeProductV17 { public function __construct(private int $id){} public function get_id():int{return $this->id;} }
function wc_get_products($args){$GLOBALS['wc_bulk_calls']++;$out=[];foreach((array)($args['include']??[]) as $id){if((int)$id===3)continue;$out[]=new FakeProductV17((int)$id);}return $out;}
function wc_get_product($id){$GLOBALS['wc_single_calls']++;return new FakeProductV17((int)$id);}
require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-complete-action-executor.php';
function check_bulk17(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
$m=new ReflectionMethod(PRSTUDIO_UC_Complete_Action_Executor::class,'product_map');$m->setAccessible(true);
$map=$m->invoke(null,[1,2,3,4,4,0]);
check_bulk17($GLOBALS['wc_bulk_calls']===1,'product map uses one WooCommerce collection query');
check_bulk17($GLOBALS['wc_single_calls']===1,'only a product missing from collection result falls back to wc_get_product');
check_bulk17(array_keys($map)===[1,2,4,3] || count($map)===4,'all requested valid products are present after fallback');
$source=file_get_contents(dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-complete-action-executor.php');
check_bulk17(str_contains($source,'wp_prime_option_caches( $keys )'),'settings export primes option caches in bulk');
check_bulk17(str_contains($source,'_prime_post_caches( $ids, false, false )'),'bulk post mutation primes post objects');
check_bulk17(str_contains($source,'update_postmeta_cache( $ids )'),'bulk post meta mutation primes meta cache');
$seo=file_get_contents(dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-seo-intelligence.php');
check_bulk17(str_contains($seo,"wc_get_products( array( 'include'=>\$product_ids"),'SEO product audit retrieves product objects as a collection');
check_bulk17(str_contains($seo,'update_postmeta_cache( array_values( array_unique( $image_ids ) ) )'),'SEO audit primes attachment alt metadata in one batch');
fwrite(STDOUT,"PHP WordPress/WooCommerce bulk v17 smoke: 8 assertions passed\n");
