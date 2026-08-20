<?php
define('ABSPATH',__DIR__);
abstract class PRSTUDIO_UC_Domain_Abstract{public function workflow(string $objective,array $arguments,array $catalog):array{return [['parent'=>true,'objective'=>$objective,'arguments'=>$arguments]];}}
final class PRSTUDIO_UC_Orchestrator{static function normalize(string $s):string{return strtolower($s);}}
final class PRSTUDIO_UC_Action_Index{static function by_action(string $route,string $action):?array{return ['tool_name'=>'db_'.$action,'route'=>$route,'action'=>$action,'read_only'=>$action==='query','destructive'=>false];}}
final class PRSTUDIO_UC_Contract{static function by_action(string $r,string $a):?array{return null;}}
require dirname(__DIR__).'/prstudio-unified-control/includes/orchestrator/domains/class-prstudio-domain-data-storage.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}}$d=new PRSTUDIO_Domain_Data_Storage();
$r=$d->workflow('run database query',['sql'=>'SELECT * FROM wp_posts'],[]);ok(($r[0]['action']??'')==='query'&&!empty($r[0]['read_only']),'SELECT fast path read');
$r=$d->workflow('update database sql',['sql'=>'UPDATE wp_posts SET post_title=\'X\' WHERE ID=1'],[]);ok(($r[0]['action']??'')==='execute'&&empty($r[0]['read_only']),'DML fast path mutation');
$it=$d->workflow('esegui questa operazione',['sql'=>'SELECT ID FROM wp_posts'],[]);$en=$d->workflow('perform this operation',['sql'=>'SELECT ID FROM wp_posts'],[]);ok(($it[0]['action']??'')==='query'&&($it[0]['action']??'')===($en[0]['action']??''),'SQL payload routes identically without language keywords');
$it=$d->workflow('applica questa modifica',['query'=>'DELETE FROM wp_options WHERE option_name=\'x\''],[]);$en=$d->workflow('apply this change',['query'=>'DELETE FROM wp_options WHERE option_name=\'x\''],[]);ok(($it[0]['action']??'')==='execute'&&($it[0]['action']??'')===($en[0]['action']??''),'query payload mutation routes identically in IT and EN');
$r=$d->workflow('create table database',['sql'=>'CREATE TABLE x (id int)'],[]);ok(!empty($r[0]['parent']),'DDL must fall through to dedicated catalog planner');
$r=$d->workflow('backup database',['backup_id'=>'b'],[]);ok(!empty($r[0]['parent']),'non-SQL database intent falls through');
ok($d->routes()===['/files-manage','/database-manage','/backup-manage'],'routes');
echo "PASS domain-data-storage\n";
