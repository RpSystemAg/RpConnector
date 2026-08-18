<?php
define('PRSTUDIO_UC_TESTING',true);
class WP_Error{public function __construct(public string $code,public string $message='',public array $data=[]){ } public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
final class PRSTUDIO_UC_Database_Backend{public static array $calls=[]; public static function execute(string $action,array $args){self::$calls[]=[$action,$args];return ['ok'=>true,'action'=>$action,'sql'=>$args['sql']??''];}}
require dirname(__DIR__).'/prstudio-unified-control/includes/class-prstudio-uc-database-engine-v3.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function reset_calls(){PRSTUDIO_UC_Database_Backend::$calls=[];}
// Read/query must still delegate; backend remains final authority for read-only SELECT semantics.
reset_calls();$r=PRSTUDIO_UC_Database_Engine::query(['sql'=>'SELECT 1']);ok(!is_wp_error($r)&&count(PRSTUDIO_UC_Database_Backend::$calls)===1&&PRSTUDIO_UC_Database_Backend::$calls[0][0]==='query','query delegates');
// Positive autonomy: comment-like/keyword text inside quoted values is data, not SQL structure.
reset_calls();$r=PRSTUDIO_UC_Database_Engine::mutate(['sql'=>"INSERT INTO t(note) VALUES('a--b # SLEEP WHERE; x')"]);ok(!is_wp_error($r)&&count(PRSTUDIO_UC_Database_Backend::$calls)===1,'quoted literal must not be blocked');
// Negative safety: WHERE occurring only inside a quoted value cannot authorize a global UPDATE/DELETE.
reset_calls();$r=PRSTUDIO_UC_Database_Engine::mutate(['sql'=>"UPDATE t SET note='WHERE'"]);ok(is_wp_error($r)&&$r->get_error_code()==='prstudio_sql_where_required'&&count(PRSTUDIO_UC_Database_Backend::$calls)===0,'quoted WHERE must not satisfy guard');
// Outside-literal comments / multi-statements / dangerous primitives remain blocked.
foreach(["INSERT INTO t VALUES(1); DELETE FROM t WHERE id=1","INSERT INTO t VALUES(1) -- comment","INSERT INTO t VALUES(LOAD_FILE('/etc/passwd'))"] as $sql){reset_calls();$r=PRSTUDIO_UC_Database_Engine::mutate(['sql'=>$sql]);ok(is_wp_error($r)&&count(PRSTUDIO_UC_Database_Backend::$calls)===0,'unsafe structural sql blocked');}
// Valid bounded DML remains executable.
foreach(["UPDATE t SET note='ok' WHERE id=1","DELETE FROM t WHERE id=1","REPLACE INTO t(id,note) VALUES(1,'x')"] as $sql){reset_calls();$r=PRSTUDIO_UC_Database_Engine::mutate(['sql'=>$sql]);ok(!is_wp_error($r)&&count(PRSTUDIO_UC_Database_Backend::$calls)===1,'valid DML delegates');}
// Bounded DDL uses the same mutation lane; the backend remains final authority for statement-specific policy.
foreach(["CREATE TABLE t2 (id int)","ALTER TABLE t ADD COLUMN n int","DROP TABLE t2","TRUNCATE TABLE t","RENAME TABLE t TO t_old"] as $sql){reset_calls();$r=PRSTUDIO_UC_Database_Engine::mutate(['sql'=>$sql]);ok(!is_wp_error($r)&&count(PRSTUDIO_UC_Database_Backend::$calls)===1&&PRSTUDIO_UC_Database_Backend::$calls[0][0]==='execute','valid DDL delegates through mutation lane');}
echo "PASS database-engine-v3\n";
