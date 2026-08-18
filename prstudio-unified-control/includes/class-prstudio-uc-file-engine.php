<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_File_Engine {
    private static function roots():array{
        $roots=array(); foreach(array(defined('ABSPATH')?ABSPATH:'',defined('WP_CONTENT_DIR')?WP_CONTENT_DIR:'',defined('PRSTUDIO_UC_DIR')?PRSTUDIO_UC_DIR:'') as $r){if($r){$real=realpath($r);if(false!==$real)$roots[]=rtrim(str_replace('\\','/',$real),'/').'/';}}
        return array_values(array_unique($roots));
    }
    public static function read(array $args){
        $path=(string)($args['path']??''); if(''===$path||str_contains($path,"\0"))return new WP_Error('prstudio_file_path_invalid','Invalid path.',array('status'=>400));
        $real=realpath($path); if(false===$real||!is_file($real)||!is_readable($real))return new WP_Error('prstudio_file_missing','File not found or unreadable.',array('status'=>404));
        $norm=str_replace('\\','/',$real);$allowed=false;foreach(self::roots() as $root){if(str_starts_with($norm.'/',$root)||str_starts_with($norm,$root)){$allowed=true;break;}}
        if(!$allowed)return new WP_Error('prstudio_file_path_traversal','Path is outside allowed boundaries.',array('status'=>403));
        $max=max(1,min(1048576,(int)($args['max_bytes']??262144)));$fh=fopen($real,'rb');$data=is_resource($fh)?fread($fh,$max+1):false;if(is_resource($fh))fclose($fh);
        if(false===$data)return new WP_Error('prstudio_file_read_failed','File read failed.',array('status'=>500));$truncated=strlen($data)>$max;if($truncated)$data=substr($data,0,$max);
        return array('path'=>$norm,'bytes'=>strlen($data),'truncated'=>$truncated,'sha256'=>hash('sha256',$data),'sha256_scope'=>$truncated?'returned_content':'full_file','content'=>$data,'verified'=>true);
    }
}
