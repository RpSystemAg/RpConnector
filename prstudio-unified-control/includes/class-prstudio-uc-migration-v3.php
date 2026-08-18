<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Migration_V3 {
    public const VERSION='3.0.0';
    private static function state():array{$s=function_exists('get_option')?get_option('prstudio_uc_migration_v3',array()):array();return is_array($s)?$s:array();}
    private static function write_state(array $s):void{if(function_exists('update_option'))update_option('prstudio_uc_migration_v3',$s,false);}
    private static function copy_atomic(string $src,string $dst):bool{
        if(!is_readable($src)||is_file($dst))return true;$dir=dirname($dst);if(!is_dir($dir)){function_exists('wp_mkdir_p')?wp_mkdir_p($dir):@mkdir($dir,0750,true);} $raw=@file_get_contents($src);if(false===$raw)return false;
        try{$suffix=bin2hex(random_bytes(6));}catch(Throwable $e){$suffix=uniqid('',true);} $tmp=$dst.'.'.$suffix.'.tmp';if(false===@file_put_contents($tmp,$raw,LOCK_EX))return false;@chmod($tmp,0640);if(!@rename($tmp,$dst)){@unlink($tmp);return false;}return true;
    }
    public static function migrate_memory():array{
        $legacy=PRSTUDIO_UC_Memory::legacy_site_identity();$new=PRSTUDIO_UC_Memory::site_identity();$root=PRSTUDIO_UC_Memory::root();$src=$root.'/'.$legacy['key'];$dst=$root.'/'.$new['key'];
        if($src===$dst||!is_dir($src))return array('ok'=>true,'needed'=>false,'legacy'=>$legacy['key'],'new'=>$new['key']);
        if(!is_dir($dst)){function_exists('wp_mkdir_p')?wp_mkdir_p($dst):@mkdir($dst,0750,true);} if(!is_dir($dst))return array('ok'=>false,'needed'=>true,'code'=>'memory_target_unavailable');
        $files=array('memory-summary.txt','memory-index.json','memory-chain.ndjson','context.json','site-knowledge-graph.json');$copied=array();
        foreach($files as $file){if(is_file($src.'/'.$file)){if(!self::copy_atomic($src.'/'.$file,$dst.'/'.$file))return array('ok'=>false,'needed'=>true,'code'=>'memory_copy_failed','file'=>$file,'copied'=>$copied);$copied[]=$file;}}
        return array('ok'=>true,'needed'=>true,'copied'=>$copied,'legacy'=>$legacy['key'],'new'=>$new['key'],'legacy_preserved'=>true);
    }
    public static function run():array{
        $s=self::state();if(($s['version']??'')===self::VERSION&&!empty($s['completed']))return $s;
        $before=array('wpaib_settings'=>function_exists('get_option')?get_option('wpaib_settings',array()):array(),'devices_preserved'=>'database_in_place','browser_pairing'=>'unchanged','gsc_oauth'=>'unchanged','legacy_contract'=>'internal_only');
        $snapshot=array('created_at'=>gmdate('c'),'before'=>$before);if(function_exists('update_option'))update_option('prstudio_uc_migration_v3_rollback',$snapshot,false);
        try{
            $memory=self::migrate_memory();if(empty($memory['ok']))throw new RuntimeException((string)($memory['code']??'memory_migration_failed'));
            $consistency=PRSTUDIO_UC_Capability_Registry::consistency();if(empty($consistency['ok']))throw new RuntimeException('capability_registry_inconsistent');
            $s=array('version'=>self::VERSION,'completed'=>true,'completed_gmt'=>gmdate('c'),'memory'=>$memory,'capability_registry'=>array('count'=>$consistency['count'],'hash'=>$consistency['registry_hash']),'browser_pairing_preserved'=>true,'configuration_preserved'=>true,'legacy_mcp_required'=>false,'rollback_snapshot'=>'prstudio_uc_migration_v3_rollback');self::write_state($s);return $s;
        }catch(Throwable $e){$s=array('version'=>self::VERSION,'completed'=>false,'failed_gmt'=>gmdate('c'),'error'=>substr($e->getMessage(),0,240),'rollback_safe'=>true);self::write_state($s);return $s;}
    }
}
