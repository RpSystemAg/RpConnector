<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** Goal planner, capability/context engine, risk/autonomy, impact, explainability, graph and KPI history. */
final class PRSTUDIO_UC_Enterprise_Engine { public const VERSION='2.0.0';
 public static function registry():array{$p=defined('PRSTUDIO_UC_DIR')?PRSTUDIO_UC_DIR.'contract/capability-contract.json':'';$d=$p&&is_readable($p)?json_decode((string)file_get_contents($p),true):[];$a=is_array($d['actions']??null)?$d['actions']:[];$dom=[];foreach($a as $x){$k=sanitize_key((string)($x['domain']??'operations'));$dom[$k]=($dom[$k]??0)+1;}ksort($dom);return['version'=>self::VERSION,'tool_count'=>count($a),'domains'=>$dom,'contract_hash'=>(string)($d['contract_hash']??''),'protocol_version'=>(string)($d['protocol_version']??'')];}
 public static function plan(string $objective,string $domain,array $args,array $workflow):array{$id='mission_'.substr(hash('sha256',$domain."\n".$objective."\n".json_encode($args)),0,20);$steps=[];foreach($workflow as $i=>$s){$steps[]=['index'=>$i,'tool_name'=>$s['tool_name']??'','route'=>$s['route']??'','action'=>$s['action']??''];}return['mission_id'=>$id,'objective'=>$objective,'domain'=>sanitize_key($domain),'goal_mode'=>true,'phases'=>['context','compile','execute','observe','report'],'steps'=>$steps,'created_gmt'=>gmdate('c')];}
 private static function risk(array $g):int{$r=strtolower((string)($g['route']??''));$a=strtolower((string)($g['action']??''));if(!empty($g['read_only']))return 0;if(!empty($g['destructive'])||preg_match('/delete|remove|drop|truncate|reset|restore|switch|install/',$a))return 5;if(preg_match('/database|users|security|plugins|themes/',$r))return 4;if(preg_match('/bulk|batch|import|replace|update|create|set_/',$a))return 3;return 2;}
 public static function risk_telemetry(array $g,array $args=[]):array{$risk=self::risk($g);return['risk_score'=>$risk,'advisory_only'=>true];}
 public static function impact(array $g,array $args):array{$p=self::risk_telemetry($g,$args);$res=[];foreach(['id','post_id','product_id','term_id','user_id','order_id','url','option','plugin','theme'] as $k){if(isset($args[$k])&&is_scalar($args[$k])&&(string)$args[$k]!=='')$res[]=$k.':'.substr((string)$args[$k],0,200);}foreach(['ids','product_ids','post_ids','urls'] as $k){if(is_array($args[$k]??null))foreach(array_slice($args[$k],0,100) as $v)$res[]=$k.':'.substr((string)$v,0,200);}$blast=max(1,count($res),(int)($args['limit']??1),(int)($args['batch_size']??1));return['risk_score'=>$p['risk_score'],'estimated_resources'=>$blast,'known_resources'=>$res,'advisory_only'=>true,'blocking'=>false];}
 public static function confidence(array $g,array $args):array{$s=50+(!empty($g['contract_hash'])?15:0)+(!empty($g['executor'])?15:0)+(!empty($g['strategy'])?5:0)-(!empty($g['destructive'])?10:0);foreach(['id','product_id','post_id','url','ids'] as $k){if(isset($args[$k])){$s+=5;break;}}$s=max(0,min(100,$s));return['score'=>$s,'band'=>$s>=90?'very_high':($s>=75?'high':($s>=60?'medium':'low'))];}
 public static function explain(array $g,array $p,array $c):array{return['summary'=>'Telemetria deterministica basata su contratto, rischio, executor, strategia ed evidenza.','reasons'=>['route='.(string)($g['route']??''),'action='.(string)($g['action']??''),'executor='.(string)($g['executor']??''),'risk='.(string)($p['risk_score']??0),'confidence='.(string)($c['score']??0).'%']];}
 public static function business_priority(array $s):array{$x=log(1+max(0,(float)($s['revenue']??0)))*18+log(1+max(0,(float)($s['margin']??0)))*12+log(1+max(0,(float)($s['traffic']??0)))*8+log(1+max(0,(float)($s['impressions']??0)))*5+min(20,max(0,(float)($s['conversion_rate']??0))*4)+(max(0,(float)($s['stock']??0))>0?5:0);return['score'=>round($x,2),'band'=>$x>=100?'critical':($x>=60?'high':($x>=30?'medium':'normal'))];}
 /**
  * Merge nodes and edges into the site knowledge graph.
  *
  * The read, the merge and the write are held under one exclusive lock. Without
  * it two concurrent merges both loaded the same version and the second write
  * discarded whatever the first had added -- a lost update on the graph the rest
  * of the intelligence layer reasons from, and one that leaves no trace: the
  * file stays valid, it is just missing nodes nobody notices are gone.
  *
  * The lock lives in its own file so the graph itself can be replaced by an
  * atomic rename, which is what keeps a reader from ever seeing a half-written
  * document.
  */
 public static function graph_merge(array $nodes,array $edges):array{
        $p=PRSTUDIO_UC_Memory::site_dir().'/site-knowledge-graph.json';
        $lock=@fopen(PRSTUDIO_UC_Memory::site_dir().'/.site-knowledge-graph.lock','c+');
        if(!is_resource($lock)||!@flock($lock,LOCK_EX)){
            if(is_resource($lock))@fclose($lock);
            return ['ok'=>false,'persisted'=>false,'reason'=>'graph_lock_unavailable','path'=>$p,'retryable'=>true];
        }
        try{
        $d=is_readable($p)?json_decode((string)file_get_contents($p),true):[];$nm=is_array($d['nodes']??null)?$d['nodes']:[];$em=is_array($d['edges']??null)?$d['edges']:[];foreach(array_slice($nodes,0,10000) as $n){$id=trim((string)($n['id']??$n['url']??''));if($id)$nm[hash('sha256',$id)]=$n+['updated_gmt'=>gmdate('c')];}foreach(array_slice($edges,0,50000) as $e){$f=(string)($e['from']??$e['source']??'');$t=(string)($e['to']??$e['target']??'');$rel=sanitize_key((string)($e['relation']??$e['kind']??'links_to'));if($f&&$t)$em[hash('sha256',$f."\n".$rel."\n".$t)]=['from'=>$f,'to'=>$t,'relation'=>$rel,'updated_gmt'=>gmdate('c')];}$o=['version'=>self::VERSION,'site'=>PRSTUDIO_UC_Memory::site_identity(),'updated_gmt'=>gmdate('c'),'nodes'=>$nm,'edges'=>$em];
		$json=json_encode($o,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		if(false===$json)return['ok'=>false,'persisted'=>false,'reason'=>'graph_encode_failed','nodes'=>count($nm),'edges'=>count($em),'path'=>$p];
		// The return value used to be ok:true regardless of whether the write
		// landed: a read-only directory or a full disk produced a confident
		// success for a graph that was never saved, and the next merge silently
		// started from the last version that did persist. Report what happened.
		// Write to a temp file and rename: a reader never observes a partial
		// graph, and a failed write leaves the previous version intact.
		try{$suffix=bin2hex(random_bytes(5));}catch(Throwable $e){$suffix=str_replace('.','',uniqid('',true));}
		$tmp=$p.'.'.$suffix.'.tmp';
		$written=@file_put_contents($tmp,$json."\n",LOCK_EX);
		if(false===$written)return['ok'=>false,'persisted'=>false,'reason'=>'graph_write_failed','path'=>$p,'nodes'=>count($nm),'edges'=>count($em),'hint'=>'The private site directory is not writable.'];
		@chmod($tmp,0640);
		if(!@rename($tmp,$p)){@unlink($tmp);return['ok'=>false,'persisted'=>false,'reason'=>'graph_rename_failed','path'=>$p,'nodes'=>count($nm),'edges'=>count($em)];}
		return['ok'=>true,'persisted'=>true,'bytes'=>(int)$written,'nodes'=>count($nm),'edges'=>count($em),'path'=>$p];
        }finally{@flock($lock,LOCK_UN);@fclose($lock);}
    }
 public static function kpi(string $id,string $phase,array $metrics):array{$p=PRSTUDIO_UC_Memory::site_dir().'/kpi-history.ndjson';$e=['gmt'=>gmdate('c'),'intervention_id'=>substr(sanitize_text_field($id),0,96),'phase'=>sanitize_key($phase),'metrics'=>$metrics,'interpretation'=>'correlation_not_causal_proof'];
		$line=json_encode($e,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		if(false===$line)return['ok'=>false,'persisted'=>false,'reason'=>'kpi_encode_failed']+$e;
		// Same false-success shape as graph_merge: a KPI point that never reached
		// disk was still reported as recorded, so a gap in the history looked
		// like a period with no measurements rather than a failed write.
		$written=@file_put_contents($p,$line."\n",FILE_APPEND|LOCK_EX);
		if(false===$written)return['ok'=>false,'persisted'=>false,'reason'=>'kpi_write_failed','path'=>$p]+$e;
		return['ok'=>true,'persisted'=>true]+$e;}
}
