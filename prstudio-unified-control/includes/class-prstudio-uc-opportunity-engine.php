<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Deterministic opportunity ranking over the Operational Twin and social ledger. */
final class PRSTUDIO_UC_Opportunity_Engine {
	public const VERSION = '1.0.0';

	private static function text( $value, int $max = 500 ): string {
		$value = is_scalar($value) ? trim((string)$value) : '';
		if(function_exists('sanitize_text_field')){$value=sanitize_text_field($value);}
		return substr($value,0,$max);
	}

	private static function unit( $value, float $default = 0.5 ): float {
		return is_numeric($value) ? max(0.0,min(1.0,(float)$value)) : $default;
	}

	private static function candidate( string $domain, string $title, string $reason, float $impact, float $confidence, float $effort, float $urgency, array $evidence, array $actions ): array {
		$impact=self::unit($impact);$confidence=self::unit($confidence);$effort=self::unit($effort);$urgency=self::unit($urgency);
		$score=round(100*($impact*0.38+$confidence*0.25+(1-$effort)*0.17+$urgency*0.20),2);
		$id=substr(hash('sha256',$domain.'|'.$title.'|'.json_encode($evidence)),0,24);
		return array(
			'id'=>$id,'domain'=>$domain,'title'=>self::text($title,240),'reason'=>self::text($reason,800),
			'score'=>$score,'factors'=>array('impact'=>$impact,'confidence'=>$confidence,'effort'=>$effort,'urgency'=>$urgency),
			'evidence'=>class_exists('PRSTUDIO_UC_Memory')?PRSTUDIO_UC_Memory::redact($evidence):$evidence,
			'recommended_actions'=>array_slice(array_values(array_filter(array_map(static fn($v)=>self::text($v,190),$actions))),0,8),
			'provenance'=>PRSTUDIO_UC_Operational_Twin::provenance('recommended','opportunity_engine', $confidence, array('formula_version'=>1)),
		);
	}

	private static function social_candidates( array $filters ): array {
		if(!class_exists('PRSTUDIO_UC_Social_Intelligence'))return array();
		$insights=PRSTUDIO_UC_Social_Intelligence::insights(array_merge($filters,array('limit'=>20)));$out=array();
		foreach(array_slice((array)($insights['top_content']??array()),0,20) as $item){
			$score=(float)($item['derived']['virality_score']??0);$eng=(float)($item['derived']['engagement_rate']??0);$ctr=(float)($item['derived']['click_through_rate']??0);
			if($score>=8){$out[]=self::candidate('social','Amplifica un contenuto ad alta viralità','Il contenuto ha segnali di condivisione, salvataggio o completamento superiori agli altri elementi osservati.',min(1,$score/40),0.86,0.28,0.78,array('content_id'=>$item['id']??'','url'=>$item['url']??'','platform'=>$item['_platform']??'','virality_score'=>$score),array('social_observe','browser_pointer','content repurpose','cross-platform distribution'));}
			if($eng>=0.02&&$ctr>0&&$ctr<0.005){$out[]=self::candidate('social','Trasforma coinvolgimento in traffico qualificato','Il pubblico interagisce ma il click-through è relativamente basso: CTA, hook e destinazione meritano un test controllato.',0.72,0.78,0.42,0.68,array('content_id'=>$item['id']??'','engagement_rate'=>$eng,'click_through_rate'=>$ctr),array('browser_observe','CTA variant','landing-page verification','tracked canary'));}
		}
		foreach((array)($insights['trends']??array()) as $account=>$trend){foreach(array('reach','impressions','views') as $metric){$relative=$trend['delta'][$metric]['relative']??null;if(is_numeric($relative)&&(float)$relative<=-0.20){$out[]=self::candidate('social','Recupera il calo di distribuzione su '.$account,'La metrica '.$metric.' è diminuita di almeno il 20% tra le ultime due osservazioni comparabili.',0.78,0.72,0.55,0.85,array('account'=>$account,'metric'=>$metric,'relative_delta'=>(float)$relative),array('social_observe','format mix analysis','publishing cadence review','hook test'));break;}}}
		return $out;
	}

	private static function twin_candidates( array $filters ): array {
		if(!class_exists('PRSTUDIO_UC_Operational_Twin'))return array();
		$query=PRSTUDIO_UC_Operational_Twin::query('',array('limit'=>200));$out=array();$now=time();
		foreach((array)($query['items']??array()) as $entity){$type=(string)($entity['type']??'');$attrs=(array)($entity['attributes']??array());
			if('content'===$type){$modified=strtotime((string)($attrs['modified_gmt']??''));if($modified&&$modified<$now-180*86400){$age=(int)floor(($now-$modified)/86400);$out[]=self::candidate('content','Aggiorna contenuto non revisionato da '.$age.' giorni','Un contenuto pubblico del twin è stabile da oltre sei mesi; va verificato contro query, SERP e conversioni prima di decidere una modifica.',min(1,0.45+$age/1000),0.70,0.48,min(1,$age/730),array('entity_id'=>$entity['id']??'','url'=>$entity['url']??'','modified_gmt'=>$attrs['modified_gmt']??''),array('browser_observe','gsc_search_analytics','content refresh plan','visual regression check'));}}
			if('product'===$type){if('outofstock'===(string)($attrs['stock_status']??'')){$out[]=self::candidate('commerce','Riduci perdita di domanda su prodotto esaurito','Il twin rileva un prodotto non disponibile; prima di promuoverlo occorre gestire stock, alternative o acquisizione lead.',0.82,0.96,0.45,0.92,array('entity_id'=>$entity['id']??'','url'=>$entity['url']??'','stock_status'=>'outofstock'),array('commerce_product_audit','inventory verification','alternative-product path','merchant validation'));}if(''===(string)($attrs['price']??'')){$out[]=self::candidate('commerce','Correggi prodotto senza prezzo osservabile','Il prezzo manca nel twin e può compromettere conversione, feed e rich results.',0.74,0.92,0.30,0.88,array('entity_id'=>$entity['id']??'','url'=>$entity['url']??''),array('commerce_product_audit','schema verification','merchant validation'));}}
		}
		return $out;
	}

	private static function supplied_candidates( array $signals ): array {
		$out=array();foreach(array_slice($signals,0,100) as $signal){if(!is_array($signal))continue;$title=self::text($signal['title']??'',240);if(''===$title)continue;$out[]=self::candidate(self::text($signal['domain']??'custom',40),$title,self::text($signal['reason']??'Segnale operativo fornito.',800),self::unit($signal['impact']??0.5),self::unit($signal['confidence']??0.5),self::unit($signal['effort']??0.5),self::unit($signal['urgency']??0.5),(array)($signal['evidence']??array()),(array)($signal['recommended_actions']??array()));}return $out;
	}

	public static function rank( array $args = array() ): array {
		$limit=max(1,min(100,(int)($args['limit']??25)));$filters=(array)($args['filters']??array());
		$requested=array_values(array_unique(array_filter(array_map(static fn($v)=>sanitize_key((string)$v),(array)($args['domains']??array())))));
		$items=array_merge(self::social_candidates($filters),self::twin_candidates($filters),self::supplied_candidates((array)($args['signals']??array())));
		if($requested){$items=array_values(array_filter($items,static function($item)use($requested){$domain=sanitize_key((string)($item['domain']??''));$bucket='content'===$domain?'site':$domain;return in_array($bucket,$requested,true);}));}
		$unique=array();foreach($items as $item){$unique[$item['id']]=$item;}$items=array_values($unique);
		usort($items,static function($a,$b){$score=(float)$b['score']<=>(float)$a['score'];return 0!==$score?$score:strcmp((string)$a['id'],(string)$b['id']);});$items=array_slice($items,0,$limit);
		$result=array('ok'=>true,'version'=>self::VERSION,'count'=>count($items),'items'=>$items,'formula'=>'100*(impact*.38 + confidence*.25 + (1-effort)*.17 + urgency*.20)','domains_applied'=>$requested,'generated_gmt'=>gmdate('c'));
		if(class_exists('PRSTUDIO_UC_Memory')){PRSTUDIO_UC_Memory::remember('opportunity','latest',PRSTUDIO_UC_Memory::fingerprint($items),array('count'=>count($items),'top_ids'=>array_column(array_slice($items,0,10),'id')),86400);}
		return $result;
	}
}
