<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-schema-validator.php';
function svt(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
$tests=[];
$tests['real integer-null union accepts integer and null']=static function(){foreach(array(7,null) as $v)svt(PRSTUDIO_UC_Schema_Validator::validate($v,array('type'=>array('integer','null')),'$.stock_quantity')===array(),'valid union rejected');};
$tests['real integer-null union rejects other shapes']=static function(){foreach(array('7',array('x'=>1),array(1),true) as $v)svt(PRSTUDIO_UC_Schema_Validator::validate($v,array('type'=>array('integer','null')),'$.stock_quantity')!==array(),'invalid union value accepted');};
$tests['nonempty object and array shapes stay distinct']=static function(){svt(PRSTUDIO_UC_Schema_Validator::validate(array('x'=>1),array('type'=>'object'))===array(),'map rejected as object');svt(PRSTUDIO_UC_Schema_Validator::validate(array(1),array('type'=>'object'))!==array(),'list accepted as object');svt(PRSTUDIO_UC_Schema_Validator::validate(array(1),array('type'=>'array','items'=>array('type'=>'integer')))===array(),'list rejected as array');svt(PRSTUDIO_UC_Schema_Validator::validate(array('x'=>1),array('type'=>'array','items'=>array('type'=>'integer')))!==array(),'map accepted as array');};
$tests['empty PHP array remains valid for empty object or array representation']=static function(){svt(PRSTUDIO_UC_Schema_Validator::validate(array(),array('type'=>'object'))===array(),'empty object representation rejected');svt(PRSTUDIO_UC_Schema_Validator::validate(array(),array('type'=>'array'))===array(),'empty array rejected');};
$tests['unicode length counts code points']=static function(){svt(PRSTUDIO_UC_Schema_Validator::validate('é',array('type'=>'string','minLength'=>1,'maxLength'=>1))===array(),'single Unicode code point length wrong');svt(PRSTUDIO_UC_Schema_Validator::validate('éé',array('type'=>'string','maxLength'=>1))!==array(),'Unicode maxLength bypassed');};
$tests['real minLength contract rejects empty']=static function(){svt(PRSTUDIO_UC_Schema_Validator::validate('',array('type'=>'string','minLength'=>1,'maxLength'=>100))!==array(),'minLength bypassed');svt(PRSTUDIO_UC_Schema_Validator::validate('group',array('type'=>'string','minLength'=>1,'maxLength'=>100))===array(),'valid string rejected');};
$tests['real minItems transaction contract rejects empty']=static function(){ $s=array('type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>array('type'=>'object','additionalProperties'=>true));svt(PRSTUDIO_UC_Schema_Validator::validate(array(),$s)!==array(),'minItems bypassed');svt(PRSTUDIO_UC_Schema_Validator::validate(array(array('action'=>'insert')),$s)===array(),'valid operation rejected');};
$tests['real confidence number bounds enforced']=static function(){ $s=array('type'=>'number','minimum'=>0,'maximum'=>1);svt(PRSTUDIO_UC_Schema_Validator::validate(0.5,$s)===array(),'valid confidence rejected');svt(PRSTUDIO_UC_Schema_Validator::validate(1.5,$s)!==array(),'maximum bypassed');svt(PRSTUDIO_UC_Schema_Validator::validate(-0.1,$s)!==array(),'minimum bypassed');};
$tests['object required and additionalProperties remain enforced']=static function(){ $s=array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer')),'additionalProperties'=>false);svt(PRSTUDIO_UC_Schema_Validator::validate(array('id'=>1),$s)===array(),'valid object rejected');svt(PRSTUDIO_UC_Schema_Validator::validate(array(),$s)!==array(),'required bypassed');svt(PRSTUDIO_UC_Schema_Validator::validate(array('id'=>1,'x'=>2),$s)!==array(),'additional property bypassed');};
$tests['pattern and enum remain enforced']=static function(){svt(PRSTUDIO_UC_Schema_Validator::validate('abc',array('type'=>'string','pattern'=>'^[a-z]+$','enum'=>array('abc','def')))===array(),'valid pattern/enum rejected');svt(PRSTUDIO_UC_Schema_Validator::validate('123',array('type'=>'string','pattern'=>'^[a-z]+$'))!==array(),'pattern bypassed');svt(PRSTUDIO_UC_Schema_Validator::validate('zzz',array('type'=>'string','enum'=>array('abc','def')))!==array(),'enum bypassed');};
$tests['invalid schema type returns a technical failure']=static function(){svt(PRSTUDIO_UC_Schema_Validator::validate('x',array('type'=>array('string',new stdClass())))!==array(),'malformed schema type bypassed');svt(PRSTUDIO_UC_Schema_Validator::validate('x',array('type'=>'unknown'))!==array(),'unknown schema type bypassed');};
$tests['const anyOf oneOf and local ref are enforced']=static function(){
    svt(PRSTUDIO_UC_Schema_Validator::validate(true,array('const'=>true))===array(),'matching const rejected');
    svt(PRSTUDIO_UC_Schema_Validator::validate(false,array('const'=>true))!==array(),'const bypassed');
    $any=array('anyOf'=>array(array('type'=>'integer'),array('type'=>'string','minLength'=>1)));
    svt(PRSTUDIO_UC_Schema_Validator::validate(3,$any)===array(),'anyOf integer rejected');
    svt(PRSTUDIO_UC_Schema_Validator::validate('x',$any)===array(),'anyOf string rejected');
    svt(PRSTUDIO_UC_Schema_Validator::validate(true,$any)!==array(),'anyOf accepted non-matching value');
    $one=array('oneOf'=>array(array('type'=>'object','required'=>array('a'),'properties'=>array('a'=>array('type'=>'integer')),'additionalProperties'=>true),array('type'=>'object','required'=>array('b'),'properties'=>array('b'=>array('type'=>'integer')),'additionalProperties'=>true)));
    svt(PRSTUDIO_UC_Schema_Validator::validate(array('a'=>1),$one)===array(),'oneOf first branch rejected');
    svt(PRSTUDIO_UC_Schema_Validator::validate(array('a'=>1,'b'=>2),$one)!==array(),'oneOf accepted ambiguous value');
    $ref=array('$defs'=>array('item'=>array('type'=>'string','minLength'=>1)),'type'=>'object','required'=>array('name'),'properties'=>array('name'=>array('$ref'=>'#/$defs/item')),'additionalProperties'=>false);
    svt(PRSTUDIO_UC_Schema_Validator::validate(array('name'=>'ok'),$ref)===array(),'local $ref rejected valid object');
    svt(PRSTUDIO_UC_Schema_Validator::validate(array('name'=>''),$ref)!==array(),'local $ref ignored nested constraints');
};
$tests['additionalProperties schema validates unknown keys']=static function(){
    $s=array('type'=>'object','properties'=>array(),'additionalProperties'=>array('type'=>'integer'));
    svt(PRSTUDIO_UC_Schema_Validator::validate(array('n'=>2),$s)===array(),'schema-valued additionalProperties rejected integer');
    svt(PRSTUDIO_UC_Schema_Validator::validate(array('n'=>'x'),$s)!==array(),'schema-valued additionalProperties accepted string');
};
$pass=0;foreach($tests as $name=>$fn){try{$fn();echo "PASS: $name\n";$pass++;}catch(Throwable $e){fwrite(STDERR,"FAIL: $name :: {$e->getMessage()}\n");exit(1);}}echo "PASS: schema validator targeted $pass/".count($tests)."\n";
