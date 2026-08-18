<?php
declare(strict_types=1);
define('ABSPATH', sys_get_temp_dir().'/prstudio-db-bench/');
$root=rtrim((string)(getenv('PRSTUDIO_BENCH_ROOT')?:dirname(__DIR__)),'/\\');
$control=$root.'/prstudio-unified-control';
$mode=is_file($control.'/includes/class-prstudio-uc-execution-router.php')?'after':'before';
define('ARRAY_A','ARRAY_A');define('ARRAY_N','ARRAY_N');
final class WP_Error{public function __construct(private string $c,private string $m='',private array $d=[]){ }public function get_error_code(){return $this->c;}public function get_error_message(){return $this->m;}public function get_error_data(){return $this->d;}}
function is_wp_error($v){return $v instanceof WP_Error;} function wp_json_encode($v,$f=0){return json_encode($v,$f);} function esc_sql($v){return addslashes((string)$v);} function sanitize_file_name($v){return preg_replace('/[^A-Za-z0-9._-]/','_',(string)$v);} function trailingslashit($v){return rtrim((string)$v,'/\\').'/';} function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0755,true);} 
final class WPAIB_Files{public static function ensure_backup_directory(){}public static function backup_root(){return sys_get_temp_dir().'/prstudio-db-bench-backups';}}
final class FakeWpdb{
 public int $num_queries=0,$insert_id=42; public string $last_error=''; public string $prefix='wp_',$options='wp_options'; public array $queries=[];
 private function hit(string $sql){$this->num_queries++;$this->queries[]=$sql;return $sql;} public function prepare($sql,...$a){foreach($a as $v){$q=is_numeric($v)?(string)$v:"'".addslashes((string)$v)."'";$sql=preg_replace('/%[sd]/',$q,$sql,1);}return $sql;}
 public function esc_like($v){return addcslashes((string)$v,'_%\\');}
 public function get_var($sql){$this->hit($sql);if(str_starts_with($sql,'SHOW TABLES LIKE')){if(preg_match("/LIKE\s+'([^']+)'/",$sql,$m))return stripslashes($m[1]);return 'wp_posts';}if(str_contains($sql,'COUNT(*)'))return 100000;return null;}
 public function get_row($sql,$fmt=null){$this->hit($sql);if(str_starts_with($sql,'CHECKSUM TABLE'))return ['Table'=>'wp_posts','Checksum'=>'123'];if(str_starts_with($sql,'SHOW CREATE TABLE'))return $fmt===ARRAY_N?['wp_posts','CREATE TABLE `wp_posts` (`ID` bigint PRIMARY KEY) ENGINE=InnoDB']:['Table'=>'wp_posts','Create Table'=>'CREATE TABLE'];if(str_contains($sql,'information_schema.TABLES'))return ['ENGINE'=>'InnoDB','TABLE_ROWS'=>100000,'DATA_LENGTH'=>1000,'INDEX_LENGTH'=>500];return [];}
 public function get_results($sql,$fmt=null){$this->hit($sql);if(str_starts_with($sql,'SHOW TABLES'))return [['wp_posts']];if(str_starts_with($sql,'OPTIMIZE TABLE')||str_starts_with($sql,'ANALYZE TABLE')||str_starts_with($sql,'CHECK TABLE'))return [['Table'=>'wp_posts','Msg_text'=>'OK']];return [];}
 public function get_col($sql){$this->hit($sql);return ['wp_posts'];}
 public function query($sql){$this->hit($sql);return preg_match('/^(UPDATE|DELETE|INSERT|REPLACE)/i',trim($sql))?1:0;}
 public function insert($t,$d){$this->hit('INSERT '.$t);return 1;} public function update($t,$d,$w){$this->hit('UPDATE '.$t);return 1;} public function delete($t,$w){$this->hit('DELETE '.$t);return 1;}
}
$GLOBALS['wpdb']=new FakeWpdb();
require $control.'/includes/class-prstudio-uc-database-backend.php';
function pct(array $v,float $p):float{sort($v,SORT_NUMERIC);$i=max(0,min(count($v)-1,(int)ceil(count($v)*$p)-1));return $v[$i];}
$scenarios=[
 'dml_update'=>fn()=>PRSTUDIO_UC_Database_Backend::execute('execute',['sql'=>"UPDATE wp_posts SET post_title='X' WHERE ID=123"]),
 'structured_update'=>fn()=>PRSTUDIO_UC_Database_Backend::execute('update',['table'=>'wp_posts','data'=>['post_title'=>'X'],'where'=>['ID'=>123]]),
 'structured_delete'=>fn()=>PRSTUDIO_UC_Database_Backend::execute('delete',['table'=>'wp_posts','where'=>['ID'=>123]]),
 'optimize'=>fn()=>PRSTUDIO_UC_Database_Backend::execute('optimize',['tables'=>['wp_posts']]),
 'optimize_128_tables'=>fn()=>PRSTUDIO_UC_Database_Backend::execute('optimize',['tables'=>array_map(fn($i)=>'wp_bench_'.$i,range(1,128))]),
];
$out=[];foreach($scenarios as $name=>$fn){$times=[];$counts=[];$success=0;for($i=0;$i<20;$i++){$GLOBALS['wpdb']=new FakeWpdb();$t=hrtime(true);$r=$fn();$times[]=(hrtime(true)-$t)/1e6;$counts[]=$GLOBALS['wpdb']->num_queries;if(!is_wp_error($r))$success++;}$out[$name]=['p50_ms'=>round(pct($times,.5),4),'p90_ms'=>round(pct($times,.9),4),'p95_ms'=>round(pct($times,.95),4),'max_ms'=>round(max($times),4),'query_count_p50'=>pct($counts,.5),'query_count_max'=>max($counts),'success_rate'=>$success/20];}
echo json_encode(['mode'=>$mode,'runs_per_case'=>20,'cases'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
