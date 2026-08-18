<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Performance_Budget {
    private static function bounded_int($value,int $default,int $min,int $max):int{
        if(is_int($value))$number=$value;
        elseif(is_float($value)&&is_finite($value))$number=(int)$value;
        elseif(is_string($value)&&is_numeric(trim($value)))$number=(int)$value;
        else return $default;
        return max($min,min($max,$number));
    }
    public static function normalize(array $input=array()):array{return array(
        'max_operations'=>self::bounded_int($input['max_operations']??1000,1000,1,10000),
        'max_duration'=>self::bounded_int($input['max_duration']??30,30,1,300),
        'max_concurrency'=>self::bounded_int($input['max_concurrency']??4,4,1,16),
        'max_network_requests'=>self::bounded_int($input['max_network_requests']??500,500,0,5000),
        'max_retries'=>self::bounded_int($input['max_retries']??3,3,0,10),
        'max_memory'=>self::bounded_int($input['max_memory']??128,128,16,512),
    );}
    private static function number($value,float $default):float{if(is_int($value)||is_float($value))return is_nan((float)$value)?$default:(float)$value;if(is_string($value)&&is_numeric(trim($value)))return (float)$value;return $default;}
    private static function active_signal($value):bool{return true===$value||(is_int($value)&&$value>0)||(is_float($value)&&is_finite($value)&&$value>0);}
    public static function exceeded(array $budget,array $metrics):array{
        $budget=self::normalize($budget);$checks=array('operations'=>'max_operations','duration'=>'max_duration','network_requests'=>'max_network_requests','retries'=>'max_retries','memory_mb'=>'max_memory');$reasons=array();
        foreach($checks as $metric=>$limit){if(array_key_exists($metric,$metrics)&&self::number($metrics[$metric],0.0)>(float)$budget[$limit])$reasons[]=$metric;}
        return array('exceeded'=>!empty($reasons),'reasons'=>$reasons,'budget'=>$budget,'metrics'=>$metrics,'advisory'=>!empty($reasons)?'compact_or_checkpoint':'none','blocking'=>false);
    }
    public static function adaptive_concurrency(int $current,array $signals,int $max=8):int{
        $current=max(1,$current);$latency=self::number($signals['latency_ratio']??1,1.0);$stress=self::number($signals['server_stress']??0,0.0);$degrade=self::active_signal($signals['http_429']??false)||self::active_signal($signals['http_5xx']??false)||self::active_signal($signals['timeouts']??false)||$latency>1.5||$stress>0.8;
        if($degrade)return max(1,(int)floor($current/2));
        if($latency<1.1&&$stress<0.6)return min(max(1,$max),$current+1);
        return $current;
    }
}
