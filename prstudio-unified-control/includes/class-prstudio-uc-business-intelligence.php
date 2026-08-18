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
