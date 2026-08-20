<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Site-scoped operational twin. The twin stores compact facts and provenance,
 * never page bodies, customer/order records or authentication material.
 */
final class PRSTUDIO_UC_Operational_Twin {
	public const VERSION = '1.0.0';
	private const STATE = 'operational-twin';
	private const MAX_ENTITIES = 10000;
	private const MAX_RELATIONS = 20000;
	private const PROVENANCE = array( 'observed_live', 'api', 'wordpress', 'cache', 'memory', 'derived', 'recommended' );

	private static function defaults(): array {
		return array(
			'schema_version' => 1,
			'entities' => array(),
			'relations' => array(),
			'sync' => array( 'runs'=>0, 'last_gmt'=>'', 'last_scope'=>array(), 'last_counts'=>array() ),
		);
	}

	private static function text( $value, int $max = 500 ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( function_exists( 'sanitize_text_field' ) ) { $value = sanitize_text_field( $value ); }
		if(class_exists('PRSTUDIO_UC_Memory')){$value=(string)PRSTUDIO_UC_Memory::redact($value);}
		return substr( $value, 0, $max );
	}

	private static function key( $value, int $max = 80 ): string {
		$value = strtolower( self::text( $value, $max * 2 ) );
		$value = (string) preg_replace( '/[^a-z0-9._:-]+/', '-', $value );
		return substr( trim( $value, '-.' ), 0, $max );
	}

	private static function canonical_id( string $type, string $external_id ): string {
		return self::key( $type, 40 ) . ':' . substr( hash( 'sha256', $type . '|' . $external_id ), 0, 24 );
	}

	private static function bounded_attributes( array $attributes ): array {
		$attributes=array_slice($attributes,0,80,true);
		if(class_exists('PRSTUDIO_UC_Memory')){$attributes=(array)PRSTUDIO_UC_Memory::redact($attributes);}
		$out=array();$bytes=0;
		foreach($attributes as $key=>$value){
			if(is_string($value)&&strlen($value)>2048)$value=substr($value,0,2048).'[TRUNCATED]';
			$encoded=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$size=false===$encoded?0:strlen($encoded);
			if($bytes+$size>32768){$out['_truncated']=true;break;}
			$out[$key]=$value;$bytes+=$size;
		}
		return $out;
	}

	public static function provenance( string $kind, string $source, float $confidence = 1.0, array $extra = array() ): array {
		$kind = self::key( $kind, 32 );
		if ( ! in_array( $kind, self::PROVENANCE, true ) ) { $kind = 'derived'; }
		return array_merge(
			array(
				'kind' => $kind,
				'source' => self::text( $source, 190 ),
				'observed_gmt' => gmdate( 'c' ),
				'confidence' => max( 0.0, min( 1.0, $confidence ) ),
			),
			class_exists( 'PRSTUDIO_UC_Memory' ) ? (array) PRSTUDIO_UC_Memory::redact( $extra ) : $extra
		);
	}

	private static function normalize_entity( array $entity, array $default_provenance ): ?array {
		$type = self::key( $entity['type'] ?? '', 40 );
		$external = self::text( $entity['external_id'] ?? ( $entity['id'] ?? '' ), 500 );
		if ( '' === $type || '' === $external ) { return null; }
		$id = self::canonical_id( $type, $external );
		$attributes = self::bounded_attributes( is_array( $entity['attributes'] ?? null ) ? $entity['attributes'] : array() );
		$url = function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) ( $entity['url'] ?? '' ) ) : self::text( $entity['url'] ?? '', 2000 );
		if(class_exists('PRSTUDIO_UC_Memory')){$url=(string)PRSTUDIO_UC_Memory::redact($url);}
		return array(
			'id' => $id,
			'external_id' => $external,
			'type' => $type,
			'label' => self::text( $entity['label'] ?? $external, 300 ),
			'url' => $url,
			'attributes' => $attributes,
			'provenance' => is_array( $entity['provenance'] ?? null ) ? $entity['provenance'] : $default_provenance,
			'updated_gmt' => gmdate( 'c' ),
		);
	}

	/** Ingest compact provider facts. Existing IDs are updated, never duplicated. */
	public static function ingest( array $entities, array $relations = array(), array $provenance = array() ): array {
		$provenance = $provenance ?: self::provenance( 'api', 'external_ingest', 0.8 );
		$accepted = array();
		foreach ( array_slice( $entities, 0, 2000 ) as $entity ) {
			if ( ! is_array( $entity ) ) { continue; }
			$normalized = self::normalize_entity( $entity, $provenance );
			if ( $normalized ) { $accepted[ $normalized['id'] ] = $normalized; }
		}
		$result = PRSTUDIO_UC_Agency_State::mutate( self::STATE, self::defaults(), static function ( array &$state ) use ( $accepted, $relations ): array {
			$storage_pruned=false;
			foreach ( $accepted as $id=>$entity ) {
				$entity['first_seen_gmt'] = (string) ( $state['entities'][ $id ]['first_seen_gmt'] ?? gmdate( 'c' ) );
				$state['entities'][ $id ] = $entity;
			}
			foreach ( array_slice( $relations, 0, 4000 ) as $relation ) {
				if ( ! is_array( $relation ) ) { continue; }
				$from = self::text( $relation['from'] ?? '', 100 );
				$to = self::text( $relation['to'] ?? '', 100 );
				$type = self::key( $relation['type'] ?? '', 40 );
				if ( '' === $from || '' === $to || '' === $type ) { continue; }
				$key = hash( 'sha256', $from . '|' . $type . '|' . $to );
				$state['relations'][ $key ] = array( 'from'=>$from, 'to'=>$to, 'type'=>$type, 'updated_gmt'=>gmdate( 'c' ) );
			}
			if ( count( $state['entities'] ) > self::MAX_ENTITIES ) {
				uasort( $state['entities'], static fn( $a, $b ) => strcmp( (string) ( $b['updated_gmt'] ?? '' ), (string) ( $a['updated_gmt'] ?? '' ) ) );
				$state['entities'] = array_slice( $state['entities'], 0, self::MAX_ENTITIES, true );
			}
			if ( count( $state['relations'] ) > self::MAX_RELATIONS ) {
				$state['relations'] = array_slice( $state['relations'], -self::MAX_RELATIONS, null, true );
			}
			$encoded=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$guard=0;
			while(false!==$encoded&&strlen($encoded)>7340032&&count($state['entities'])>100&&$guard++<8){
				uasort($state['entities'],static fn($a,$b)=>strcmp((string)($b['updated_gmt']??''),(string)($a['updated_gmt']??'')));
				$state['entities']=array_slice($state['entities'],0,max(100,(int)floor(count($state['entities'])*0.75)),true);
				$storage_pruned=true;$encoded=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
			}
			return array( 'accepted'=>count( $accepted ), 'total_entities'=>count( $state['entities'] ), 'total_relations'=>count( $state['relations'] ), 'storage_pruned'=>$storage_pruned );
		} );
		if ( class_exists( 'PRSTUDIO_UC_Memory' ) ) {
			PRSTUDIO_UC_Memory::movement( 'twin.ingested', array( 'accepted'=>count( $accepted ), 'source'=>$provenance['source'] ?? '' ) );
		}
		return is_array( $result ) ? array_merge( array( 'ok'=>true, 'version'=>self::VERSION ), $result ) : array( 'ok'=>false, 'error'=>'state_unavailable' );
	}

	private static function site_entities(): array {
		$url = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
		$site = array(
			'type'=>'site', 'external_id'=>$url ?: 'wordpress-site',
			'label'=>function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'WordPress',
			'url'=>$url,
			'attributes'=>array(
				'description'=>function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'description' ) : '',
				'language'=>function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '',
				'wordpress_version'=>function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
				'timezone'=>function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '',
			),
		);
		$entities = array( $site );
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( is_object( $theme ) ) {
				$entities[] = array( 'type'=>'theme', 'external_id'=>(string) $theme->get_stylesheet(), 'label'=>(string) $theme->get( 'Name' ), 'attributes'=>array( 'version'=>(string)$theme->get('Version'), 'status'=>'active' ) );
			}
		}
		return $entities;
	}

	private static function content_entities( int $limit ): array {
		if ( ! function_exists( 'get_posts' ) ) { return array(); }
		$types = function_exists( 'get_post_types' ) ? array_values( get_post_types( array( 'public'=>true ), 'names' ) ) : array( 'post', 'page' );
		$types = array_values( array_diff( $types, array( 'attachment', 'product' ) ) );
		$ids = get_posts( array( 'post_type'=>$types, 'post_status'=>array('publish','draft','pending','future'), 'numberposts'=>$limit, 'orderby'=>'modified', 'order'=>'DESC', 'fields'=>'ids', 'suppress_filters'=>false ) );
		$out = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			$out[] = array(
				'type'=>'content', 'external_id'=>(string)$id,
				'label'=>function_exists('get_the_title')?(string)get_the_title($id):('Post '.$id),
				'url'=>function_exists('get_permalink')?(string)get_permalink($id):'',
				'attributes'=>array(
					'post_type'=>function_exists('get_post_type')?(string)get_post_type($id):'',
					'status'=>function_exists('get_post_status')?(string)get_post_status($id):'',
					'modified_gmt'=>function_exists('get_post_modified_time')?(string)get_post_modified_time('c',true,$id):'',
				),
			);
		}
		return $out;
	}

	private static function commerce_entities( int $limit ): array {
		if ( ! function_exists( 'wc_get_products' ) ) { return array(); }
		$products = wc_get_products( array( 'limit'=>$limit, 'status'=>array('publish','draft','pending','private'), 'orderby'=>'modified', 'order'=>'DESC', 'return'=>'objects' ) );
		$out = array();
		foreach ( (array) $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) { continue; }
			$modified = method_exists($product,'get_date_modified') ? $product->get_date_modified() : null;
			$out[] = array(
				'type'=>'product', 'external_id'=>(string)$product->get_id(), 'label'=>(string)$product->get_name(),
				'url'=>function_exists('get_permalink')?(string)get_permalink($product->get_id()):'',
				'attributes'=>array(
					'sku'=>(string)$product->get_sku(), 'status'=>(string)$product->get_status(),
					'price'=>(string)$product->get_price(), 'stock_status'=>(string)$product->get_stock_status(),
					'catalog_visibility'=>(string)$product->get_catalog_visibility(),
					'modified_gmt'=>is_object($modified)&&method_exists($modified,'date')?$modified->date('c'):'',
				),
			);
		}
		return $out;
	}

	/** Build business-semantic relations from the WordPress runtime without storing post bodies. */
	private static function semantic_graph( array $entities ): array {
		$extra=array();$relations=array();$site_external=function_exists('home_url')?(string)home_url('/'):'wordpress-site';$site_id=self::canonical_id('site',$site_external?:'wordpress-site');$seen_terms=array();
		foreach($entities as $entity){if(!is_array($entity))continue;$type=(string)($entity['type']??'');$external=(string)($entity['external_id']??'');if(''===$type||''===$external)continue;$from=self::canonical_id($type,$external);
			if('theme'===$type){$relations[]=array('from'=>$from,'to'=>$site_id,'type'=>'active_on');continue;}
			if(in_array($type,array('content','product'),true)){$relations[]=array('from'=>$from,'to'=>$site_id,'type'=>'product'===$type?'sold_on_site':'belongs_to_site');$post_id=(int)$external;if($post_id<=0||!function_exists('get_object_taxonomies')||!function_exists('wp_get_post_terms'))continue;$post_type=function_exists('get_post_type')?(string)get_post_type($post_id):('product'===$type?'product':'post');$taxes=(array)get_object_taxonomies($post_type,'names');
				foreach(array_slice($taxes,0,20) as $taxonomy){$taxonomy=(string)$taxonomy;if(''===$taxonomy)continue;$terms=wp_get_post_terms($post_id,$taxonomy,array('fields'=>'all'));if(is_wp_error($terms)||!is_array($terms))continue;foreach(array_slice($terms,0,50) as $term){if(!is_object($term)||!isset($term->term_id))continue;$term_external=$taxonomy.':'.(int)$term->term_id;$term_id=self::canonical_id('taxonomy_term',$term_external);if(!isset($seen_terms[$term_external])){$seen_terms[$term_external]=true;$extra[]=array('type'=>'taxonomy_term','external_id'=>$term_external,'label'=>(string)($term->name??$term_external),'url'=>function_exists('get_term_link')&&!is_wp_error($u=get_term_link($term))?(string)$u:'','attributes'=>array('taxonomy'=>$taxonomy,'term_id'=>(int)$term->term_id,'slug'=>(string)($term->slug??''),'count'=>(int)($term->count??0),'parent'=>(int)($term->parent??0)));$relations[]=array('from'=>$term_id,'to'=>$site_id,'type'=>'taxonomy_on_site');}
					$relations[]=array('from'=>$from,'to'=>$term_id,'type'=>('product'===$type&&'product_cat'===$taxonomy)?'belongs_to_category':'classified_as');
					if(!empty($term->parent)){$parent_external=$taxonomy.':'.(int)$term->parent;$parent_id=self::canonical_id('taxonomy_term',$parent_external);if(!isset($seen_terms[$parent_external])&&function_exists('get_term')){$parent=get_term((int)$term->parent,$taxonomy);if(is_object($parent)&&!is_wp_error($parent)){$seen_terms[$parent_external]=true;$extra[]=array('type'=>'taxonomy_term','external_id'=>$parent_external,'label'=>(string)($parent->name??$parent_external),'attributes'=>array('taxonomy'=>$taxonomy,'term_id'=>(int)$parent->term_id,'slug'=>(string)($parent->slug??''),'count'=>(int)($parent->count??0),'parent'=>(int)($parent->parent??0)));$relations[]=array('from'=>$parent_id,'to'=>$site_id,'type'=>'taxonomy_on_site');}}$relations[]=array('from'=>$term_id,'to'=>$parent_id,'type'=>'child_of');}
				}
			}
		}
		}
		return array('entities'=>array_merge($entities,$extra),'relations'=>$relations,'extra_entities'=>count($extra));
	}

	/** Refresh a bounded slice. Scheduled calls default to 250 entities. */
	public static function sync( array $args = array() ): array {
		$scope = array_values( array_unique( array_map( array( __CLASS__, 'key' ), (array) ( $args['scope'] ?? array( 'site','content','commerce' ) ) ) ) );
		$limit = max( 10, min( 1000, (int) ( $args['limit'] ?? 250 ) ) );
		$entities = array();
		if ( in_array( 'site', $scope, true ) ) { $entities = array_merge( $entities, self::site_entities() ); }
		if ( in_array( 'content', $scope, true ) ) { $entities = array_merge( $entities, self::content_entities( $limit ) ); }
		if ( in_array( 'commerce', $scope, true ) ) { $entities = array_merge( $entities, self::commerce_entities( $limit ) ); }
		$graph = self::semantic_graph( $entities );
		$entities = $graph['entities'];
		$result = self::ingest( $entities, $graph['relations'], self::provenance( 'wordpress', 'wordpress_runtime', 1.0 ) );
		PRSTUDIO_UC_Agency_State::mutate( self::STATE, self::defaults(), static function ( array &$state ) use ( $scope, $entities, $graph ): bool {
			$counts = array(); foreach ( $entities as $entity ) { $type=(string)($entity['type']??'unknown'); $counts[$type]=(int)($counts[$type]??0)+1; }
			$state['sync'] = array( 'runs'=>(int)($state['sync']['runs']??0)+1, 'last_gmt'=>gmdate('c'), 'last_scope'=>$scope, 'last_counts'=>$counts, 'last_relations'=>count((array)$graph['relations']), 'semantic_relation_builder'=>true );
			return true;
		} );
		$result['scope'] = $scope;
		$result['observed'] = count( $entities );
		$result['relations_observed'] = count( (array)$graph['relations'] );
		$result['semantic_relation_builder'] = true;
		$result['provenance'] = 'wordpress';
		return $result;
	}

	/** Query the twin through the shared bilingual action concepts. */
	public static function query( string $query = '', array $filters = array() ): array {
		$state = PRSTUDIO_UC_Agency_State::read( self::STATE, self::defaults() );
		$q = trim( $query );
		$q_lower = strtolower( $q );
		$type = self::key( $filters['type'] ?? '', 40 );
		$limit = max( 1, min( 200, (int) ( $filters['limit'] ?? 50 ) ) );
		if ( ! class_exists( 'PRSTUDIO_UC_Action_Lexicon' ) ) {
			$lexicon_path = __DIR__ . '/class-prstudio-uc-action-lexicon.php';
			if ( is_readable( $lexicon_path ) ) { require_once $lexicon_path; }
		}
		$lexicon_ready = class_exists( 'PRSTUDIO_UC_Action_Lexicon' );
		$technical_query = '' !== $q && ( str_contains( $q, '://' ) || 1 === preg_match( '/[._:\\/]/', $q ) );
		$query_normalized = $lexicon_ready ? PRSTUDIO_UC_Action_Lexicon::normalize_text( $q ) : strtolower( trim( str_replace( array( '_', '-' ), ' ', $q ) ) );
		$query_concepts = ( ! $technical_query && $lexicon_ready ) ? PRSTUDIO_UC_Action_Lexicon::query_concepts( $q ) : array();
		$query_keys = ( $lexicon_ready && $query_concepts ) ? PRSTUDIO_UC_Action_Lexicon::concept_keys( $query_concepts ) : array();
		$rows = array();
		foreach ( (array) $state['entities'] as $entity ) {
			if ( '' !== $type && $type !== (string) ( $entity['type'] ?? '' ) ) { continue; }
			$label = (string) ( $entity['label'] ?? '' );
			$haystack = $label . ' ' . (string) ( $entity['external_id'] ?? '' ) . ' ' . (string) ( $entity['url'] ?? '' ) . ' ' . json_encode( $entity['attributes'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$hay_lower = strtolower( $haystack );
			$label_lower = strtolower( $label );
			$match = '' === $q;
			$score = '' === $q ? 1 : 0;
			if ( ! $match && $technical_query ) {
				$match = str_contains( $hay_lower, $q_lower );
				if ( $match ) { $score = str_contains( $label_lower, $q_lower ) ? 20 : 10; }
			}
			if ( ! $match && $query_concepts ) {
				$label_concepts = PRSTUDIO_UC_Action_Lexicon::query_concepts( $label );
				$candidate_concepts = PRSTUDIO_UC_Action_Lexicon::query_concepts( $haystack );
				$candidate_keys = PRSTUDIO_UC_Action_Lexicon::concept_keys( $candidate_concepts );
				if ( PRSTUDIO_UC_Action_Lexicon::equivalent( $label_concepts, $query_concepts ) ) {
					$match = true; $score = 40;
				} elseif ( PRSTUDIO_UC_Action_Lexicon::covers( $label_concepts, $query_concepts ) ) {
					$match = true; $score = 35;
				} elseif ( PRSTUDIO_UC_Action_Lexicon::equivalent( $candidate_concepts, $query_concepts ) ) {
					$match = true; $score = 30;
				} elseif ( PRSTUDIO_UC_Action_Lexicon::covers( $candidate_concepts, $query_concepts ) ) {
					$match = true; $score = 25;
				} else {
					$overlap = count( array_intersect( $query_keys, $candidate_keys ) );
					if ( 0 < $overlap ) { $match = true; $score = 20 + min( 5, $overlap ); }
				}
			}
			if ( ! $match && ! $technical_query && '' !== $q ) {
				$hay_normalized = $lexicon_ready ? PRSTUDIO_UC_Action_Lexicon::normalize_text( $haystack ) : strtolower( trim( str_replace( array( '_', '-' ), ' ', $haystack ) ) );
				$label_normalized = $lexicon_ready ? PRSTUDIO_UC_Action_Lexicon::normalize_text( $label ) : strtolower( trim( str_replace( array( '_', '-' ), ' ', $label ) ) );
				$match = str_contains( $hay_lower, $q_lower ) || ( '' !== $query_normalized && str_contains( $hay_normalized, $query_normalized ) );
				if ( $match ) { $score = str_contains( $label_lower, $q_lower ) || ( '' !== $query_normalized && str_contains( $label_normalized, $query_normalized ) ) ? 20 : 10; }
			}
			if ( ! $match ) { continue; }
			$rows[] = array( 'score'=>$score, 'entity'=>$entity );
		}
		usort( $rows, static function( array $a, array $b ): int {
			$score = (int)$b['score'] <=> (int)$a['score'];
			if ( 0 !== $score ) { return $score; }
			$updated = strcmp( (string)($b['entity']['updated_gmt']??''), (string)($a['entity']['updated_gmt']??'') );
			return 0 !== $updated ? $updated : strcmp( (string)($a['entity']['id']??''), (string)($b['entity']['id']??'') );
		} );
		$items = array_map( static fn( $row ) => $row['entity'], array_slice( $rows, 0, $limit ) );
		return array( 'ok'=>true, 'version'=>self::VERSION, 'query'=>$query, 'query_normalized'=>$query_normalized, 'bilingual_lexicon'=>$lexicon_ready, 'count'=>count($items), 'items'=>$items, 'provenance_explicit'=>true, 'last_sync'=>$state['sync'] );
	}

	public static function snapshot(): array {
		$state = PRSTUDIO_UC_Agency_State::read( self::STATE, self::defaults() );
		$types = array();
		foreach ( (array) $state['entities'] as $entity ) { $type=(string)($entity['type']??'unknown'); $types[$type]=(int)($types[$type]??0)+1; }
		ksort( $types );
		return array( 'version'=>self::VERSION, 'entities'=>count((array)$state['entities']), 'relations'=>count((array)$state['relations']), 'types'=>$types, 'last_sync'=>$state['sync'] );
	}
}
