<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Deterministic Director/Consultant primitives.
 *
 * These methods intentionally avoid LLM-only semantics. Every public capability
 * performs bounded calculations or durable state transitions and returns evidence
 * that can be verified by the normal Execution Gateway.
 */
final class PRSTUDIO_UC_Business_Intelligence {
    public const VERSION = '1.0.0';
    private const STATE_FILE = 'business-intelligence-v1.json';
    private const LOCK_FILE = '.business-intelligence-v1.lock';
    private const MAX_GOALS = 200;
    private const MAX_DECISIONS = 5000;
    private const MAX_OUTCOMES = 5000;

    private static function defaults(): array {
        return array(
            'schema_version'=>1,
            'goals'=>array(),
            'decisions'=>array(),
            'outcomes'=>array(),
            'calibration'=>array(),
            'updated_gmt'=>'',
        );
    }

    private static function dir(): string {
        $dir = PRSTUDIO_UC_Memory::site_dir();
        if ( ! is_dir( $dir ) ) { function_exists('wp_mkdir_p') ? wp_mkdir_p($dir) : @mkdir($dir,0750,true); }
        return $dir;
    }
    private static function path(): string { return self::dir() . '/' . self::STATE_FILE; }
    private static function lock_path(): string { return self::dir() . '/' . self::LOCK_FILE; }
    private static function clean( $value ) { return PRSTUDIO_UC_Memory::redact( $value ); }
    private static function key( string $value, int $max=96 ): string {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9._:-]+/','-',$value);
        return substr(trim($value,'-.'),0,$max);
    }
    private static function load_unlocked(): array {
        $raw=is_readable(self::path())?(string)file_get_contents(self::path()):'';
        $data=''!==$raw?json_decode($raw,true):array();
        return is_array($data)?array_merge(self::defaults(),$data):self::defaults();
    }
    private static function atomic_write(array $state): bool {
        $state['updated_gmt']=gmdate('c');
        $json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(false===$json||strlen($json)>16777216)return false;
        try{$suffix=bin2hex(random_bytes(6));}catch(Throwable $e){$suffix=str_replace('.','',uniqid('',true));}
        $tmp=self::path().'.'.$suffix.'.tmp';
        if(false===@file_put_contents($tmp,$json."\n",LOCK_EX))return false;
        @chmod($tmp,0640);
        if(@rename($tmp,self::path()))return true;
        @unlink($tmp);return false;
    }
    private static function mutate(callable $callback) {
        $fh=@fopen(self::lock_path(),'c+');
        if(!is_resource($fh)||!@flock($fh,LOCK_EX)){if(is_resource($fh))@fclose($fh);return new WP_Error('business_state_lock_failed','Unable to lock business intelligence state.',array('status'=>503,'retryable'=>true));}
        try{
            $state=self::load_unlocked();
            $result=$callback($state);
            if(is_wp_error($result))return $result;
            if(!self::atomic_write($state))return new WP_Error('business_state_write_failed','Unable to persist business intelligence state.',array('status'=>503,'retryable'=>true));
            return $result;
        } finally { @flock($fh,LOCK_UN);@fclose($fh); }
    }
    private static function state(): array { return self::load_unlocked(); }
    private static function bounded_number($value,float $min=-1000000000000.0,float $max=1000000000000.0): float { return max($min,min($max,(float)$value)); }

    /** Stable JSON representation used only for deterministic identity/tie-breaking. */
    private static function canonical_value($value) {
        if(!is_array($value))return $value;
        $is_list=true;if(function_exists('array_is_list')){$is_list=array_is_list($value);}else{$expected=0;foreach($value as $key=>$unused){if($key!==$expected){$is_list=false;break;}$expected++;}}
        if($is_list){$out=array();foreach($value as $item)$out[]=self::canonical_value($item);return $out;}
        $out=array();$keys=array_keys($value);sort($keys,SORT_STRING);foreach($keys as $key)$out[(string)$key]=self::canonical_value($value[$key]);return $out;
    }
    private static function canonical_json($value): string {
        $json=json_encode(self::canonical_value($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
        return false===$json?'null':$json;
    }
    private static function bounded_text($value,int $max): string {
        $text=sanitize_text_field((string)$value);
        return function_exists('mb_substr')?mb_substr($text,0,$max,'UTF-8'):substr($text,0,$max);
    }
    private static function normalize_fact(array $fact) {
        $key=self::bounded_text($fact['key']??'',190);$source=self::bounded_text($fact['source']??'',190);
        if(''===$key)return new WP_Error('data_quality_fact_key_required','Each fact requires a non-empty key.',array('status'=>400));
        if(''===$source)return new WP_Error('data_quality_source_required','Each fact requires a non-empty source.',array('status'=>400));
        if(!array_key_exists('authority',$fact)||!is_numeric($fact['authority']))return new WP_Error('data_quality_authority_required','Each fact requires numeric authority.',array('status'=>400));
        if(!array_key_exists('confidence',$fact)||!is_numeric($fact['confidence']))return new WP_Error('data_quality_confidence_required','Each fact requires numeric confidence.',array('status'=>400));
        $observed=self::bounded_text($fact['observed_gmt']??'',40);$ts=''!==$observed?strtotime($observed):false;
        if(false===$ts)return new WP_Error('data_quality_observed_gmt_invalid','Each fact requires a parseable observed_gmt timestamp.',array('status'=>400));
        $value=self::clean($fact['value']??null);$authority=max(0.0,min(1.0,(float)$fact['authority']));$confidence=max(0.0,min(1.0,(float)$fact['confidence']));
        $normalized=array('key'=>$key,'value'=>$value,'source'=>$source,'authority'=>round($authority,6),'observed_gmt'=>gmdate('c',(int)$ts),'confidence'=>round($confidence,6));
        $normalized['_observed_ts']=(int)$ts;$normalized['_stable_hash']=hash('sha256',self::canonical_json(array_diff_key($normalized,array('_observed_ts'=>true,'_stable_hash'=>true))));
        return $normalized;
    }
    private static function public_fact(array $fact): array { unset($fact['_observed_ts'],$fact['_stable_hash']);return $fact; }

    /** Resolve contradictory supplied facts deterministically: authority, freshness, confidence, then stable tie-break. */
    public static function data_quality_conflicts(array $args) {
        $facts=array_values(array_filter((array)($args['facts']??array()),'is_array'));
        if(!$facts)return new WP_Error('data_quality_facts_required','facts are required.',array('status'=>400));
        if(count($facts)>500)return new WP_Error('data_quality_facts_limit','facts exceeds the bounded limit of 500.',array('status'=>400));
        $groups=array();
        foreach($facts as $fact){$normalized=self::normalize_fact($fact);if(is_wp_error($normalized))return $normalized;$groups[(string)$normalized['key']][]=$normalized;}
        ksort($groups,SORT_STRING);$resolutions=array();$conflicts=0;
        foreach($groups as $key=>$candidates){
            usort($candidates,static function(array $a,array $b):int{
                $cmp=$b['authority']<=>$a['authority'];if(0!==$cmp)return $cmp;
                $cmp=$b['_observed_ts']<=>$a['_observed_ts'];if(0!==$cmp)return $cmp;
                $cmp=$b['confidence']<=>$a['confidence'];if(0!==$cmp)return $cmp;
                return strcmp((string)$a['_stable_hash'],(string)$b['_stable_hash']);
            });
            $winner=$candidates[0];$value_hashes=array();foreach($candidates as $candidate)$value_hashes[hash('sha256',self::canonical_json($candidate['value']))]=true;
            $conflict=count($value_hashes)>1;if($conflict)$conflicts++;
            $basis='identical';
            if(count($candidates)>1){$runner=$candidates[1];if($winner['authority']!==$runner['authority'])$basis='authority';elseif($winner['_observed_ts']!==$runner['_observed_ts'])$basis='freshness';elseif($winner['confidence']!==$runner['confidence'])$basis='confidence';elseif($conflict)$basis='stable_tiebreak';}
            $alternatives=array();foreach(array_slice($candidates,1) as $candidate)$alternatives[]=self::public_fact($candidate);
            $resolutions[]=array('key'=>$key,'conflict'=>$conflict,'candidate_count'=>count($candidates),'winner'=>self::public_fact($winner),'alternatives'=>$alternatives,'resolution_basis'=>$basis);
        }
        return array('ok'=>true,'version'=>self::VERSION,'resolved_count'=>count($resolutions),'conflict_count'=>$conflicts,'resolutions'=>$resolutions);
    }

    /** Persist an immutable, bounded managerial decision record with deterministic idempotency. */
    public static function decision_journal(array $args) {
        $decision=self::bounded_text($args['decision']??'',4000);$rationale=self::bounded_text($args['rationale']??'',8000);$expected=self::bounded_text($args['expected_outcome']??'',4000);
        if(''===$decision)return new WP_Error('business_decision_required','decision is required.',array('status'=>400));
        if(''===$rationale)return new WP_Error('business_decision_rationale_required','rationale is required.',array('status'=>400));
        if(''===$expected)return new WP_Error('business_expected_outcome_required','expected_outcome is required.',array('status'=>400));
        $alternatives=array();foreach(array_slice((array)($args['alternatives']??array()),0,50) as $alternative){if(!is_string($alternative))return new WP_Error('business_decision_alternative_invalid','alternatives must contain strings.',array('status'=>400));$text=self::bounded_text($alternative,2000);if(''!==$text)$alternatives[]=$text;}
        $evidence=array();foreach(array_slice((array)($args['evidence']??array()),0,100) as $item){if(!is_array($item))return new WP_Error('business_decision_evidence_invalid','evidence must contain objects.',array('status'=>400));$source=self::bounded_text($item['source']??'',190);$summary=self::bounded_text($item['summary']??'',4000);if(''===$source||''===$summary)return new WP_Error('business_decision_evidence_invalid','Each evidence item requires source and summary.',array('status'=>400));$row=array('source'=>$source,'summary'=>$summary);if(isset($item['reference']))$row['reference']=self::bounded_text($item['reference'],2048);if(isset($item['confidence'])){if(!is_numeric($item['confidence']))return new WP_Error('business_decision_evidence_confidence_invalid','Evidence confidence must be numeric.',array('status'=>400));$row['confidence']=round(max(0.0,min(1.0,(float)$item['confidence'])),6);}$evidence[]=$row;}
        $core=self::clean(array('decision'=>$decision,'rationale'=>$rationale,'alternatives'=>$alternatives,'evidence'=>$evidence,'expected_outcome'=>$expected));$content_hash=hash('sha256',self::canonical_json($core));
        $provided_id=self::bounded_text($args['decision_id']??'',96);$decision_id=''!==$provided_id?self::key($provided_id,96):'decision_'.substr($content_hash,0,24);
        if(''===$decision_id)return new WP_Error('business_decision_id_invalid','decision_id is invalid.',array('status'=>400));
        $existing_state=self::state();foreach((array)($existing_state['decisions']??array()) as $row){if(is_array($row)&&$decision_id===(string)($row['decision_id']??'')){if(hash_equals((string)($row['content_hash']??''),$content_hash))return array('ok'=>true,'version'=>self::VERSION,'created'=>false,'changed'=>false,'replayed'=>true,'decision'=>$row,'journal_count'=>count((array)$existing_state['decisions']));return new WP_Error('business_decision_id_conflict','decision_id already refers to different immutable content.',array('status'=>409,'decision_id'=>$decision_id));}}
        return self::mutate(static function(array &$state)use($decision_id,$core,$content_hash){
            foreach((array)($state['decisions']??array()) as $row){if(!is_array($row)||$decision_id!==(string)($row['decision_id']??''))continue;if(hash_equals((string)($row['content_hash']??''),$content_hash))return array('ok'=>true,'version'=>self::VERSION,'created'=>false,'changed'=>false,'replayed'=>true,'decision'=>$row,'journal_count'=>count((array)$state['decisions']));return new WP_Error('business_decision_id_conflict','decision_id already refers to different immutable content.',array('status'=>409,'decision_id'=>$decision_id));}
            $row=array_merge(array('decision_id'=>$decision_id),$core,array('content_hash'=>$content_hash,'decided_gmt'=>gmdate('c')));$state['decisions'][]=$row;if(count($state['decisions'])>self::MAX_DECISIONS)$state['decisions']=array_slice($state['decisions'],-self::MAX_DECISIONS);
            return array('ok'=>true,'version'=>self::VERSION,'created'=>true,'changed'=>true,'replayed'=>false,'decision'=>$row,'journal_count'=>count($state['decisions']));
        });
    }

    /** Deterministic contribution-margin model. */
    public static function unit_economics(array $args) {
        $revenue=self::bounded_number($args['revenue']??0);$cogs=self::bounded_number($args['cogs']??0,0);$discount=self::bounded_number($args['discount']??0,0);$payment=self::bounded_number($args['payment_fees']??0,0);$packaging=self::bounded_number($args['packaging']??0,0);$shipping=self::bounded_number($args['shipping_cost']??0,0);$ads=self::bounded_number($args['advertising_cost']??0,0);$returns=self::bounded_number($args['returns_cost']??0,0);$other=self::bounded_number($args['other_variable_cost']??0,0);$costs=$cogs+$discount+$payment+$packaging+$shipping+$ads+$returns+$other;$margin=$revenue-$costs;$pct=abs($revenue)>1.0e-12?($margin/$revenue)*100:0.0;
        return array('ok'=>true,'revenue'=>$revenue,'variable_costs'=>$costs,'contribution_margin'=>$margin,'contribution_margin_percent'=>round($pct,4),'breakdown'=>array('cogs'=>$cogs,'discount'=>$discount,'payment_fees'=>$payment,'packaging'=>$packaging,'shipping_cost'=>$shipping,'advertising_cost'=>$ads,'returns_cost'=>$returns,'other_variable_cost'=>$other),'version'=>self::VERSION);
    }

    /** Bounded what-if simulator for percentage/absolute metric changes. */
    public static function scenario_simulator(array $args) {
        $baseline=is_array($args['baseline']??null)?$args['baseline']:array();$changes=is_array($args['changes']??null)?$args['changes']:array();$projected=array();
        foreach(array_slice($baseline,0,200,true) as $metric=>$value){if(!is_numeric($value))continue;$v=self::bounded_number($value);$change=$changes[$metric]??array();if(is_numeric($change))$change=array('percent'=>(float)$change);if(!is_array($change))$change=array();$pct=self::bounded_number($change['percent']??0,-10000,10000);$abs=self::bounded_number($change['absolute']??0);$projected[$metric]=array('baseline'=>$v,'percent_change'=>$pct,'absolute_change'=>$abs,'projected'=>$v*(1+$pct/100)+$abs);}
        return array('ok'=>true,'scenario'=>sanitize_text_field((string)($args['name']??'scenario')),'projected'=>$projected,'assumptions'=>'independent_linear_changes','executed'=>false,'version'=>self::VERSION);
    }

    /** Traverse semantic relations and quantify the bounded downstream blast radius. */
    public static function semantic_blast_radius(array $args) {
        $roots=array_values(array_unique(array_filter(array_map('strval',(array)($args['roots']??array())))));$relations=array_values(array_filter((array)($args['relations']??array()),'is_array'));if(!$roots)return new WP_Error('blast_radius_roots_required','roots are required.',array('status'=>400));$max_depth=max(1,min(8,(int)($args['max_depth']??4)));$max_nodes=max(10,min(5000,(int)($args['max_nodes']??1000)));$adj=array();foreach(array_slice($relations,0,20000) as $r){$from=(string)($r['from']??'');$to=(string)($r['to']??'');if(''===$from||''===$to)continue;$adj[$from][]=array('to'=>$to,'type'=>self::key((string)($r['type']??'related_to'),40));}
        $seen=array_fill_keys($roots,0);$queue=array_map(static fn($r)=>array($r,0),$roots);$edges=array();while($queue&&count($seen)<$max_nodes){[$node,$depth]=array_shift($queue);if($depth>=$max_depth)continue;foreach((array)($adj[$node]??array()) as $edge){$to=$edge['to'];$edges[]=array('from'=>$node,'to'=>$to,'type'=>$edge['type'],'depth'=>$depth+1);if(!array_key_exists($to,$seen)){$seen[$to]=$depth+1;$queue[]=array($to,$depth+1);if(count($seen)>=$max_nodes)break;}}}
        return array('ok'=>true,'root_count'=>count($roots),'affected_node_count'=>max(0,count($seen)-count($roots)),'nodes'=>$seen,'edges'=>array_slice($edges,0,20000),'bounded'=>count($seen)>=$max_nodes,'version'=>self::VERSION);
    }

    /** Reorder-point, safety-stock, stockout and overstock heuristics from supplied demand data. */
    public static function demand_replenishment_planner(array $args) {
        $items=array_values(array_filter((array)($args['items']??array()),'is_array'));if(!$items)return new WP_Error('replenishment_items_required','items are required.',array('status'=>400));$out=array();
        foreach(array_slice($items,0,5000) as $item){$id=substr(sanitize_text_field((string)($item['id']??$item['sku']??'')),0,190);if(''===$id)continue;$daily=max(0.0,self::bounded_number($item['daily_demand']??0));$lead=max(0.0,self::bounded_number($item['lead_time_days']??0,0,3650));$stock=max(0.0,self::bounded_number($item['stock']??0,0));$std=max(0.0,self::bounded_number($item['daily_demand_stddev']??0,0));$service=max(0.0,min(4.0,(float)($item['service_z']??1.65)));$safety=$service*$std*sqrt(max(1.0,$lead));$reorder=$daily*$lead+$safety;$days=$daily>0?$stock/$daily:null;$recommended=max(0.0,$reorder-$stock);$out[]=array('id'=>$id,'reorder_point'=>round($reorder,3),'safety_stock'=>round($safety,3),'days_of_inventory'=>null===$days?null:round($days,3),'recommended_order_qty'=>round($recommended,3),'stockout_risk'=>$stock<$reorder?'elevated':'controlled','overstock_risk'=>null!==$days&&$days>max(90,$lead*4)?'elevated':'controlled');}
        return array('ok'=>true,'count'=>count($out),'items'=>$out,'version'=>self::VERSION);
    }

    /** Evaluate a two-variant experiment with confidence interval and guardrails. */
    public static function causal_experiment_evaluator(array $args) {
        $a=is_array($args['control']??null)?$args['control']:array();$b=is_array($args['variant']??null)?$args['variant']:array();$na=max(0,(int)($a['exposures']??0));$nb=max(0,(int)($b['exposures']??0));$ca=max(0,min($na,(int)($a['conversions']??0)));$cb=max(0,min($nb,(int)($b['conversions']??0)));if($na<1||$nb<1)return new WP_Error('experiment_exposures_required','Both variants require exposures.',array('status'=>400));$pa=$ca/$na;$pb=$cb/$nb;$pooled=($ca+$cb)/($na+$nb);$se=sqrt(max(0.0,$pooled*(1-$pooled)*(1/$na+1/$nb)));$z=$se>0?($pb-$pa)/$se:0.0;$p=erfc(abs($z)/sqrt(2.0));$diff=$pb-$pa;$seDiff=sqrt(max(0.0,$pa*(1-$pa)/$na+$pb*(1-$pb)/$nb));$low=$diff-1.96*$seDiff;$high=$diff+1.96*$seDiff;$minEffect=max(0.0,(float)($args['minimum_effect']??0));$sig=$p<=(float)($args['alpha']??0.05);$winner=$sig&&$diff>$minEffect?'variant':($sig&&$diff<-$minEffect?'control':'inconclusive');$guardrails=[];$guardrailFailed=false;foreach(array_slice((array)($args['guardrails']??array()),0,50) as $g){if(!is_array($g))continue;$metric=self::key((string)($g['metric']??''),100);$value=(float)($g['value']??0);$min=isset($g['min'])?(float)$g['min']:null;$max=isset($g['max'])?(float)$g['max']:null;$ok=(null===$min||$value>=$min)&&(null===$max||$value<=$max);if(!$ok)$guardrailFailed=true;$guardrails[]=array('metric'=>$metric,'value'=>$value,'ok'=>$ok,'min'=>$min,'max'=>$max);}$decision=$guardrailFailed?'guardrail_failed':$winner;
        return array('ok'=>true,'control_rate'=>round($pa,6),'variant_rate'=>round($pb,6),'absolute_lift'=>round($diff,6),'relative_lift_percent'=>abs($pa)>1.0e-12?round(($diff/$pa)*100,4):null,'z_score'=>round($z,6),'p_value'=>round($p,8),'confidence_interval_95'=>array(round($low,6),round($high,6)),'statistically_significant'=>$sig,'decision'=>$decision,'guardrails'=>$guardrails,'version'=>self::VERSION);
    }

    /** Calibrate recommendation confidence from predicted vs measured impact. */
    public static function recommendation_feedback_calibrator(array $args) {
        $class=self::key((string)($args['class']??'general'),100);$pred=max(-1.0,min(1.0,(float)($args['predicted_impact']??0)));$actual=max(-1.0,min(1.0,(float)($args['actual_impact']??0)));$success=(($pred>=0&&$actual>=0)||($pred<0&&$actual<0));
        return self::mutate(static function(array &$state)use($class,$pred,$actual,$success){$row=is_array($state['calibration'][$class]??null)?$state['calibration'][$class]:array('samples'=>0,'direction_hits'=>0,'absolute_error_sum'=>0.0);$row['samples']=(int)$row['samples']+1;$row['direction_hits']=(int)$row['direction_hits']+($success?1:0);$row['absolute_error_sum']=(float)$row['absolute_error_sum']+abs($pred-$actual);$row['direction_accuracy']=round($row['direction_hits']/max(1,$row['samples']),4);$row['mean_absolute_error']=round($row['absolute_error_sum']/max(1,$row['samples']),4);$row['confidence_multiplier']=round(max(0.25,min(1.25,$row['direction_accuracy']*(1-$row['mean_absolute_error']/2)+0.25)),4);$row['updated_gmt']=gmdate('c');$state['calibration'][$class]=$row;return array('ok'=>true,'class'=>$class,'calibration'=>$row,'version'=>self::VERSION);});
    }

    /** Revalidate or expire recommendations whose evidence is stale. */
    public static function recommendation_expiry_revalidator(array $args) {
        $items=array_values(array_filter((array)($args['recommendations']??array()),'is_array'));$now=time();$out=array();foreach(array_slice($items,0,5000) as $item){$ttl=max(60,min(31536000,(int)($item['ttl_seconds']??604800)));$ts=strtotime((string)($item['evidence_gmt']??$item['updated_gmt']??''));$age=$ts?max(0,$now-$ts):PHP_INT_MAX;$status=$age<=$ttl?'valid':'stale';$out[]=array('id'=>substr(sanitize_text_field((string)($item['id']??'')),0,190),'status'=>$status,'age_seconds'=>$age===PHP_INT_MAX?null:$age,'ttl_seconds'=>$ttl,'requires_revalidation'=>'stale'===$status);}
        return array('ok'=>true,'items'=>$out,'valid'=>count(array_filter($out,static fn($r)=>'valid'===$r['status'])),'stale'=>count(array_filter($out,static fn($r)=>'stale'===$r['status'])),'version'=>self::VERSION);
    }
}
