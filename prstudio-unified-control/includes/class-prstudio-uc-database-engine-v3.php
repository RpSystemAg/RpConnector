<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Database_Engine {
    private static function structural_sql(string $sql):?string{
        $out='';$quote=null;$length=strlen($sql);
        for($i=0;$i<$length;$i++){
            $char=$sql[$i];
            if(null!==$quote){
                if('\\'===$char&&'`'!==$quote&&$i+1<$length){$out.='  ';$i++;continue;}
                if($char===$quote){if($i+1<$length&&$sql[$i+1]===$quote){$out.='  ';$i++;continue;}$quote=null;}
                $out.=' ';continue;
            }
            if("'"===$char||'"'===$char||'`'===$char){$quote=$char;$out.=' ';continue;}
            $out.=$char;
        }
        return null===$quote?$out:null;
    }
    private static function guard(string $sql,bool $write){
        if(strlen($sql)>20000)return new WP_Error('prstudio_sql_unsafe','SQL exceeds the bounded statement size.',array('status'=>403));
        $structure=self::structural_sql($sql);if(null===$structure||preg_match('/(?:\/\*|--|#|;\s*\S)/',$structure))return new WP_Error('prstudio_sql_unsafe','SQL comments or multiple statements are not allowed.',array('status'=>403));
        if(preg_match('/\b(?:OUTFILE|DUMPFILE|LOAD_FILE|SLEEP|BENCHMARK|INTO\s+OUTFILE|xp_)\b/i',$structure))return new WP_Error('prstudio_sql_unsafe','Unsafe SQL primitive rejected as a technical validation error.',array('status'=>403));
        $trimmed=trim($structure);
        if($write){if(!preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i',$trimmed))return new WP_Error('prstudio_sql_write_statement_required','Only bounded mutation statements are allowed.',array('status'=>403)); if(preg_match('/^(UPDATE|DELETE)\b/i',$trimmed)&&!preg_match('/\bWHERE\b/i',$structure))return new WP_Error('prstudio_sql_where_required','UPDATE/DELETE require WHERE.',array('status'=>403));}
        return true;
    }
    public static function query(array $args){$sql=trim((string)($args['sql']??''));$g=self::guard($sql,false);if(is_wp_error($g))return $g;return PRSTUDIO_UC_Database_Backend::execute('query',$args);}
    public static function mutate(array $args){$sql=trim((string)($args['sql']??''));$g=self::guard($sql,true);if(is_wp_error($g))return $g;return PRSTUDIO_UC_Database_Backend::execute('execute',$args);}
}
