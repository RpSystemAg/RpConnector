<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Contract for future first-party OAuth social providers. */
interface PRSTUDIO_UC_Social_Provider_Interface {
	public function id(): string;
	public function status(): array;
	public function collect( array $request ): array;
}

/**
 * Provider-neutral social intelligence ledger. It normalizes API imports and
 * Browser-Agent observations, but never pretends that an OAuth connector exists.
 */
final class PRSTUDIO_UC_Social_Intelligence {
	public const VERSION = '1.0.0';
	private const STATE = 'social-intelligence';
	private const MAX_SNAPSHOTS = 500;
	private const MAX_CONTENT_ITEMS = 100;
	private const PLATFORMS = array( 'instagram','facebook','tiktok','youtube','linkedin','x','threads','pinterest','snapchat','other' );

	private static function defaults(): array {
		return array( 'schema_version'=>1, 'snapshots'=>array(), 'providers'=>array(), 'metrics'=>array( 'ingested'=>0 ) );
	}

	private static function text( $value, int $max = 300 ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( function_exists( 'sanitize_text_field' ) ) { $value = sanitize_text_field( $value ); }
		if(class_exists('PRSTUDIO_UC_Memory')){$value=(string)PRSTUDIO_UC_Memory::redact($value);}
		return substr( $value, 0, $max );
	}

	private static function key( $value, int $max = 64 ): string {
		$value = strtolower( self::text( $value, $max * 2 ) );
		$value = (string) preg_replace( '/[^a-z0-9._:-]+/', '_', $value );
		return substr( trim( $value, '._:-' ), 0, $max );
	}

	private static function platform( $value ): string {
		$platform = self::key( $value, 24 );
		return in_array( $platform, self::PLATFORMS, true ) ? $platform : 'other';
	}

	private static function finite_number( $value ): ?float {
		if ( ! is_numeric( $value ) ) { return null; }
		$value = (float) $value;
		return is_finite( $value ) ? max( -1000000000000.0, min( 1000000000000.0, $value ) ) : null;
	}

	private static function metrics( array $metrics ): array {
		$out = array();
		foreach ( array_slice( $metrics, 0, 120, true ) as $name=>$value ) {
			$key = self::key( $name, 64 );
			if(preg_match('/password|secret|token|api[_-]?key|credential|authorization|cookie|session|oauth/i',$key)){continue;}
			$number = self::finite_number( $value );
			if ( '' !== $key && null !== $number ) { $out[ $key ] = $number; }
		}
		ksort( $out );
		return $out;
	}

	private static function ratio( float $numerator, float $denominator ): ?float {
		return $denominator > 0 ? round( $numerator / $denominator, 6 ) : null;
	}

	private static function derived( array $m ): array {
		$get = static fn( string $key ): float => (float) ( $m[ $key ] ?? 0.0 );
		$reach = max( $get('reach'), $get('unique_reach') );
		$impressions = max( $get('impressions'), $reach );
		$views = max( $get('views'), $get('video_views'), $impressions );
		$interactions = $get('likes') + $get('comments') + $get('shares') + $get('saves') + $get('replies');
		$out = array(
			'interactions'=>$interactions,
			'engagement_rate'=>self::ratio( $interactions, $reach > 0 ? $reach : $impressions ),
			'share_rate'=>self::ratio( $get('shares'), $reach > 0 ? $reach : $impressions ),
			'save_rate'=>self::ratio( $get('saves'), $reach > 0 ? $reach : $impressions ),
			'completion_rate'=>self::ratio( max($get('completions'),$get('completed_views')), $views ),
			'click_through_rate'=>self::ratio( $get('clicks'), $impressions ),
			'conversion_rate'=>self::ratio( $get('conversions'), max($get('clicks'),$get('landing_page_views')) ),
		);
		$out = array_filter( $out, static fn( $v ) => null !== $v );
		$engagement = (float) ( $out['engagement_rate'] ?? 0 );
		$share = (float) ( $out['share_rate'] ?? 0 );
		$save = (float) ( $out['save_rate'] ?? 0 );
		$completion = (float) ( $out['completion_rate'] ?? 0 );
		$out['virality_score'] = round( min( 100, 100 * ( $engagement * 0.35 + $share * 2.5 + $save * 1.5 + $completion * 0.2 ) ), 2 );
		return $out;
	}

	private static function normalize_content( array $items ): array {
		$out = array();
		foreach ( array_slice( $items, 0, self::MAX_CONTENT_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$metrics = self::metrics( (array) ( $item['metrics'] ?? array() ) );
			$id = self::text( $item['id'] ?? ( $item['url'] ?? '' ), 500 );
			if ( '' === $id ) { $id = substr( hash( 'sha256', json_encode( $item ) ), 0, 24 ); }
			$url=function_exists('esc_url_raw')?esc_url_raw((string)($item['url']??'')):self::text($item['url']??'',2000);
			if(class_exists('PRSTUDIO_UC_Memory')){$url=(string)PRSTUDIO_UC_Memory::redact($url);}
			$out[] = array(
				'id'=>$id,
				'url'=>$url,
				'type'=>self::key($item['type']??'post',32),
				'published_gmt'=>self::text($item['published_gmt']??'',40),
				'caption_excerpt'=>self::text($item['caption_excerpt']??($item['caption']??''),500),
				'metrics'=>$metrics,
				'derived'=>self::derived($metrics),
			);
		}
		return $out;
	}

	public static function ingest( array $args ): array {
		$platform = self::platform( $args['platform'] ?? '' );
		$account = self::text( $args['account'] ?? '', 190 );
		if ( '' === $account ) { return array( 'ok'=>false, 'error'=>array( 'code'=>'social_account_required', 'message'=>'A public account identifier is required.' ) ); }
		$source = self::key( $args['source'] ?? 'manual', 32 );
		if ( ! in_array( $source, array( 'manual','browser_live','api','webhook','import' ), true ) ) { $source = 'manual'; }
		$metrics = self::metrics( (array) ( $args['metrics'] ?? array() ) );
		$content = self::normalize_content( (array) ( $args['content'] ?? array() ) );
		$observed = self::text( $args['observed_gmt'] ?? gmdate('c'), 40 );
		$id = substr( hash( 'sha256', $platform . '|' . strtolower($account) . '|' . $observed . '|' . json_encode($metrics) ), 0, 32 );
		$snapshot = array(
			'id'=>$id, 'platform'=>$platform, 'account'=>$account, 'source'=>$source,
			'period'=>array( 'start'=>self::text($args['period_start']??'',40), 'end'=>self::text($args['period_end']??'',40) ),
			'observed_gmt'=>$observed, 'metrics'=>$metrics, 'derived'=>self::derived($metrics), 'content'=>$content,
			'provenance'=>PRSTUDIO_UC_Operational_Twin::provenance( 'browser_live'===$source?'observed_live':'api', $source.':'.$platform, 'browser_live'===$source?0.85:0.95, array('account'=>$account) ),
		);
		$result = PRSTUDIO_UC_Agency_State::mutate( self::STATE, self::defaults(), static function ( array &$state ) use ( $id, $snapshot, $source, $platform ): array {
			$storage_pruned=false;
			$state['snapshots'][ $id ] = $snapshot;
			if ( count( $state['snapshots'] ) > self::MAX_SNAPSHOTS ) {
				uasort( $state['snapshots'], static fn($a,$b)=>strcmp((string)($b['observed_gmt']??''),(string)($a['observed_gmt']??'')) );
				$state['snapshots'] = array_slice( $state['snapshots'], 0, self::MAX_SNAPSHOTS, true );
			}
			$state['metrics']['ingested'] = (int)($state['metrics']['ingested']??0)+1;
			$state['providers'][$source.':'.$platform] = array('source'=>$source,'platform'=>$platform,'last_seen_gmt'=>gmdate('c'));
			$encoded=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$guard=0;
			while(false!==$encoded&&strlen($encoded)>7340032&&count($state['snapshots'])>20&&$guard++<8){
				uasort($state['snapshots'],static fn($a,$b)=>strcmp((string)($b['observed_gmt']??''),(string)($a['observed_gmt']??'')));
				$state['snapshots']=array_slice($state['snapshots'],0,max(20,(int)floor(count($state['snapshots'])*0.75)),true);
				$storage_pruned=true;$encoded=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
			}
			return array('snapshot_id'=>$id,'total_snapshots'=>count($state['snapshots']),'storage_pruned'=>$storage_pruned);
		} );
		if ( class_exists('PRSTUDIO_UC_Memory') ) { PRSTUDIO_UC_Memory::movement('social.snapshot.ingested',array('platform'=>$platform,'account'=>$account,'source'=>$source,'snapshot_id'=>$id)); }
		if(class_exists('PRSTUDIO_UC_Operational_Twin')){
			$twin_entities=array(array('type'=>'social_account','external_id'=>$platform.':'.$account,'label'=>$account.' · '.$platform,'attributes'=>array('platform'=>$platform,'latest_metrics'=>$metrics,'derived'=>$snapshot['derived'],'source'=>$source,'snapshot_id'=>$id)));
			foreach(array_slice($content,0,25) as $item){$twin_entities[]=array('type'=>'social_content','external_id'=>$platform.':'.$account.':'.(string)$item['id'],'label'=>(string)($item['caption_excerpt']?:$item['id']),'url'=>(string)$item['url'],'attributes'=>array('platform'=>$platform,'account'=>$account,'content_type'=>$item['type'],'published_gmt'=>$item['published_gmt'],'metrics'=>$item['metrics'],'derived'=>$item['derived'],'snapshot_id'=>$id));}
			PRSTUDIO_UC_Operational_Twin::ingest($twin_entities,array(),$snapshot['provenance']);
		}
		return is_array($result) ? array_merge(array('ok'=>true,'version'=>self::VERSION,'snapshot'=>$snapshot),$result) : array('ok'=>false,'error'=>array('code'=>'social_state_unavailable'));
	}

	private static function matches( array $snapshot, array $filters ): bool {
		if ( ! empty($filters['platform']) && self::platform($filters['platform']) !== (string)($snapshot['platform']??'') ) { return false; }
		if ( ! empty($filters['account']) && strtolower(self::text($filters['account'],190)) !== strtolower((string)($snapshot['account']??'')) ) { return false; }
		return true;
	}

	public static function insights( array $filters = array() ): array {
		$state = PRSTUDIO_UC_Agency_State::read( self::STATE, self::defaults() );
		$limit = max(1,min(100,(int)($filters['limit']??20)));
		$snapshots = array_values(array_filter((array)$state['snapshots'],static fn($row)=>is_array($row)&&self::matches($row,$filters)));
		usort($snapshots,static fn($a,$b)=>strcmp((string)($b['observed_gmt']??''),(string)($a['observed_gmt']??'')));
		$latest = array_slice($snapshots,0,$limit);
		$content = array();
		foreach($latest as $snapshot){foreach((array)($snapshot['content']??array()) as $item){$item['_platform']=$snapshot['platform'];$item['_account']=$snapshot['account'];$content[]=$item;}}
		usort($content,static fn($a,$b)=>(float)($b['derived']['virality_score']??0)<=>(float)($a['derived']['virality_score']??0));
		$trends=array(); $by_account=array();
		foreach($snapshots as $snapshot){$key=(string)$snapshot['platform'].'|'.strtolower((string)$snapshot['account']);$by_account[$key][]=$snapshot;}
		foreach($by_account as $key=>$rows){if(count($rows)<2)continue;$current=$rows[0];$previous=$rows[1];$metric_names=array_unique(array_merge(array_keys((array)$current['metrics']),array_keys((array)$previous['metrics'])));$delta=array();foreach($metric_names as $metric){$a=(float)($current['metrics'][$metric]??0);$b=(float)($previous['metrics'][$metric]??0);$delta[$metric]=array('absolute'=>round($a-$b,4),'relative'=>$b!=0?round(($a-$b)/abs($b),6):null);}$trends[$key]=array('current'=>$current['id'],'previous'=>$previous['id'],'delta'=>$delta);}
		return array(
			'ok'=>true,'version'=>self::VERSION,'snapshot_count'=>count($snapshots),'latest'=>$latest,
			'top_content'=>array_slice($content,0,25),'trends'=>$trends,
			'provider_status'=>self::provider_status(),'methodology'=>array('rates'=>'ratios are fractions, not percentages','virality_score'=>'bounded deterministic heuristic 0-100','source_labels_required'=>true),
		);
	}

	public static function provider_status(): array {
		$devices = class_exists('PRSTUDIO_UC_Store') ? PRSTUDIO_UC_Store::list_devices() : array();
		$online = count(array_filter($devices,static fn($d)=>!empty($d['online'])));
		return array(
			'native_oauth_connectors'=>array(),
			'native_oauth_ready'=>false,
			'browser_live_available'=>$online>0,
			'browser_devices_online'=>$online,
			'message'=>'Native platform APIs require separate OAuth applications and approvals; signed-in Browser Agent observation is available only while Chrome is online.',
		);
	}

	public static function snapshot(): array {
		$state=PRSTUDIO_UC_Agency_State::read(self::STATE,self::defaults());$platforms=array();
		foreach((array)$state['snapshots'] as $row){$p=(string)($row['platform']??'other');$platforms[$p]=(int)($platforms[$p]??0)+1;}
		ksort($platforms);
		return array('version'=>self::VERSION,'snapshots'=>count((array)$state['snapshots']),'platforms'=>$platforms,'providers'=>$state['providers'],'status'=>self::provider_status());
	}
}
