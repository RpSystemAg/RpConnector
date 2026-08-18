<?php

declare(strict_types=1);

define('ABSPATH', sys_get_temp_dir() . '/prstudio-database-v17/');
@mkdir(ABSPATH, 0777, true);
function esc_sql($value){ return str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value); }

require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-database-backend.php';

function db17_check(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$rows=[];
for($i=1;$i<=5000;$i++) $rows[]=['id'=>$i,'name'=>'row-'.$i,'payload'=>str_repeat('x',32)];
$method=new ReflectionMethod(PRSTUDIO_UC_Database_Backend::class,'bulk_insert_statements');
$method->setAccessible(true);
$statements=$method->invoke(null,'wp_demo',$rows,250,524288);
db17_check(count($statements)===20,'5000 regular rows are compiled into 20 set-based INSERT statements');
db17_check(str_starts_with($statements[0],'INSERT INTO `wp_demo` (`id`,`name`,`payload`) VALUES '),'multi-row INSERT includes deterministic columns');
db17_check(substr_count($statements[0],'),(')===249,'first INSERT carries 250 rows');
db17_check(count(array_filter($statements,fn($sql)=>strlen($sql)>524288))===0,'bounded statements stay below the configured packet budget for regular rows');

$large=[];
for($i=1;$i<=12;$i++) $large[]=['id'=>$i,'payload'=>str_repeat('y',70000)];
$largeStatements=$method->invoke(null,'wp_demo',$large,250,131072);
db17_check(count($largeStatements)>=6,'byte budget splits large payloads before packet-sized statements grow unbounded');
db17_check(array_sum(array_map(fn($sql)=>substr_count($sql,'),(')+1,$largeStatements))===12,'byte-chunked INSERTs preserve every row');

$sqlExport=new ReflectionMethod(PRSTUDIO_UC_Database_Backend::class,'sql_export');
$sqlExport->setAccessible(true);
$payload=['tables'=>['wp_demo'=>['schema'=>'CREATE TABLE `wp_demo` (`id` bigint NOT NULL, `name` text)','rows'=>array_slice($rows,0,600)]]];
$sql=$sqlExport->invoke(null,$payload);
db17_check(substr_count($sql,'INSERT INTO `wp_demo`')===3,'SQL export uses extended multi-row inserts instead of one statement per row');
db17_check(str_contains($sql,'SET FOREIGN_KEY_CHECKS=0;') && str_contains($sql,'SET FOREIGN_KEY_CHECKS=1;'),'SQL export preserves dump foreign-key envelope');

fwrite(STDOUT,"PHP database bulk v17 smoke: 8 assertions passed\n");
