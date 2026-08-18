<?php
// Focused regression checks for the pre-mutation routing hotfix. No WordPress boot required.
define('PRSTUDIO_UC_TESTING', true);
if (!class_exists('WP_Error')) { class WP_Error { private $c,$m,$d; function __construct($c,$m,$d=array()){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} function get_error_data(){return $this->d;} } }
if (!function_exists('sanitize_key')) { function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace(' ','_',trim((string)$v)))); } }
if (!function_exists('str_starts_with')) { function str_starts_with($h,$n){return $n===''||strncmp($h,$n,strlen($n))===0;} }
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-anti-crash.php';
$fail=[];
$expect=function($cond,$name)use(&$fail){if(!$cond)$fail[]=$name;};
$expect(!PRSTUDIO_UC_Pre_Mutation_Safety::is_site_scope('browser'),'browser_not_site');
$expect(!PRSTUDIO_UC_Pre_Mutation_Safety::is_site_scope('internal'),'internal_not_site');
$expect(PRSTUDIO_UC_Pre_Mutation_Safety::is_site_scope('wordpress'),'wordpress_site');
$expect('browser'===PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_legacy_route('/frontend-manage','playwright_click',array('read_only'=>false)),'frontend_scope');
$expect('database'===PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_legacy_route('/database-manage','update',array('read_only'=>false)),'database_scope');
$expect('none'===PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_legacy_route('/content-manage','verify',array('read_only'=>true)),'read_scope');
$expect('deferred'===PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_direct_tool('wordpress_content_transaction'),'content_deferred');
$expect('filesystem'===PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_direct_tool('patch_file'),'patch_scope');
if($fail){fwrite(STDERR,"FAIL ".implode(',',$fail)."\n");exit(1);} echo "OK pre-mutation routing smoke\n";
