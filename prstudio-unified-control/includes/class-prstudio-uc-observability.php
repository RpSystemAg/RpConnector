<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Observability { public const VERSION='2.1.0'; private static function dir():string{$d=PRSTUDIO_UC_Memory::site_dir().'/observability';if(!is_dir($d)){function_exists('wp_mkdir_p')?wp_mkdir_p($d):@mkdir($d,0750,true);}return$d;}
    /**
     * Append one NDJSON record, rotating the file when it grows past the cap.
     *
     * The rotation used to run outside any lock: check filesize, unlink the old
     * .previous, rename the live file. Two writers crossing the threshold
     * together could both decide to rotate -- the second unlinking the archive
     * the first had just created and renaming a file the first had already
     * started appending to. Events vanished or ended up split across two files,
     * and telemetry became least trustworthy exactly when load was highest,
     * which is when it is most needed.
     *
     * A lock file separate from the data file covers the whole
     * check-rotate-append sequence. Failing to take it is not worth dropping a
     * record over: telemetry is best-effort by nature, so an unlocked append
     * still runs, and only the rotation -- the part that can destroy data -- is
     * skipped without the lock.
     */
    private static function append($f,$x){
        $p=self::dir().'/'.$f;
        $line=json_encode($x,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(false===$line)return;
        $line.="\n";
        $lock=@fopen(self::dir().'/.'.$f.'.rotate.lock','c+');
        if(!is_resource($lock)||!@flock($lock,LOCK_EX)){
            if(is_resource($lock))@fclose($lock);
            @file_put_contents($p,$line,FILE_APPEND|LOCK_EX);
            return;
        }
        try{
            clearstatcache(true,$p);
            if(is_file($p)&&filesize($p)>8388608){@unlink($p.'.previous');@rename($p,$p.'.previous');}
            @file_put_contents($p,$line,FILE_APPEND|LOCK_EX);
        }finally{@flock($lock,LOCK_UN);@fclose($lock);}
    }
 private static function id(string $prefix,int $length=32):string{return$prefix.substr(hash('sha256',random_bytes(32).microtime(true)),0,$length);}
 public static function start(string $name,array $a=[]):array{
    $context=is_array($a['context']??null)?$a['context']:array();
    $trace=(string)($a['trace_id']??$context['trace_id']??'');
    if(!preg_match('/^trace_[a-f0-9]{32}$/',$trace))$trace=self::id('trace_',32);
    $parent=(string)($a['parent_span_id']??$context['span_id']??'');
    if(!preg_match('/^span_[a-f0-9]{20}$/',$parent))$parent='';
    unset($a['context'],$a['trace_id'],$a['parent_span_id']);
    return[
        'trace_id'=>$trace,
        'span_id'=>self::id('span_',20),
        'parent_span_id'=>$parent,
        'name'=>substr(sanitize_text_field($name),0,96),
        'started_at'=>gmdate('c'),
        'ns'=>function_exists('hrtime')?hrtime(true):(int)(microtime(true)*1e9),
        'mem'=>memory_get_usage(true),
        'attributes'=>$a,
    ];
 }
 public static function finish(array $s,string $status='ok',array $a=[]):array{
    $n=function_exists('hrtime')?hrtime(true):(int)(microtime(true)*1e9);
    $attributes=array_merge((array)($s['attributes']??[]),$a);
    foreach(array('job_uuid','mission_id','step_id','state_from','state_to','progress_signature') as $field){if(!array_key_exists($field,$attributes))$attributes[$field]='';}
    $o=[
        'trace_id'=>(string)($s['trace_id']??''),
        'span_id'=>(string)($s['span_id']??''),
        'parent_span_id'=>(string)($s['parent_span_id']??''),
        'name'=>(string)($s['name']??''),
        'started_at'=>(string)($s['started_at']??''),
        'finished_at'=>gmdate('c'),
        'gmt'=>gmdate('c'),
        'status'=>sanitize_key($status),
        'duration_ms'=>round(($n-(int)($s['ns']??$n))/1e6,3),
        'memory_delta_bytes'=>memory_get_usage(true)-(int)($s['mem']??0),
        'attributes'=>$attributes,
    ];
    self::append('traces.ndjson',$o);self::metric('span_duration_ms',(float)$o['duration_ms'],['span'=>$o['name'],'status'=>$o['status']]);return$o;
 }
 public static function metric(string $name,float $value,array $labels=[]):void{self::append('metrics.ndjson',['gmt'=>gmdate('c'),'name'=>sanitize_key($name),'value'=>$value,'labels'=>$labels]);}
 public static function anomaly(string $name,int $limit=50):array{$p=self::dir().'/metrics.ndjson';if(!is_readable($p))return['ok'=>true,'anomaly'=>false,'reason'=>'no_history'];$rows=[];foreach(array_reverse(@file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]) as $l){$x=json_decode($l,true);if(is_array($x)&&($x['name']??'')===sanitize_key($name))$rows[]=(float)$x['value'];if(count($rows)>=$limit)break;}if(count($rows)<10)return['ok'=>true,'anomaly'=>false,'reason'=>'insufficient_history','samples'=>count($rows)];$cur=array_shift($rows);$m=array_sum($rows)/count($rows);$v=0;foreach($rows as $x)$v+=($x-$m)**2;$sd=sqrt($v/count($rows));$z=$sd?abs(($cur-$m)/$sd):0;return['ok'=>true,'anomaly'=>$z>=3.5,'current'=>$cur,'mean'=>$m,'z_score'=>round($z,3),'samples'=>count($rows)+1];}
 public static function replay(string $job,array $request,array $response):array{$b=['version'=>self::VERSION,'gmt'=>gmdate('c'),'job_id'=>$job,'request_hash'=>PRSTUDIO_UC_Memory::fingerprint($request),'response_hash'=>PRSTUDIO_UC_Memory::fingerprint($response),'request'=>PRSTUDIO_UC_Memory::redact($request),'response'=>PRSTUDIO_UC_Memory::redact($response)];$b['sha256']=hash('sha256',json_encode($b,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$p=self::dir().'/replay-'.preg_replace('/[^a-zA-Z0-9._-]+/','_',substr($job,0,80)).'.json';@file_put_contents($p,json_encode($b,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",LOCK_EX);return['ok'=>true,'path'=>$p,'sha256'=>$b['sha256']];}
 public static function offline_replay(array $original,array $replayed):array{
    // Offline replay compares recorded observations only. Mutations and
    // external calls are forbidden in replay mode and are never invoked here.
    $keys=array('sequence','event_type','job_uuid','step_id','logical_attempt','state_from','state_to','input_hash','output_hash','effect_id','idempotency_key','executed','evidence_id','verified','strength','source','observation_hash','recorded_payload_or_artifact_hash','final_verdict');
    $count=max(count($original),count($replayed));
    for($i=0;$i<$count;$i++){
        $left=array_intersect_key((array)($original[$i]??array()),array_flip($keys));
        $right=array_intersect_key((array)($replayed[$i]??array()),array_flip($keys));
        if($left!==$right)return['ok'=>false,'replay_mode'=>'offline','mutation_forbidden'=>true,'first_divergent_event'=>$i,'original'=>$left,'replayed'=>$right];
    }
    return['ok'=>true,'replay_mode'=>'offline','mutation_forbidden'=>true,'events'=>$count,'first_divergent_event'=>null];
 }
 public static function snapshot():array{return['ok'=>true,'version'=>self::VERSION,'trace_file'=>self::dir().'/traces.ndjson','metric_file'=>self::dir().'/metrics.ndjson'];}
}
