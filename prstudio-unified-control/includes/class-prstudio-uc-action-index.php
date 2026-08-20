<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-prstudio-uc-action-lexicon.php';

/**
 * Precompiled knowledge graph for instant tool/action resolution.
 *
 * The build creates action-hot-index.php/json once. Runtime lookups never scan
 * the 1,278-tool catalog: exact lookups are O(1), text search intersects short
 * token posting lists, and the result is cached in the WordPress object cache.
 */
final class PRSTUDIO_UC_Action_Index {
	private const CACHE_GROUP = 'prstudio_uc_contract';
	private const CACHE_KEY = 'action_hot_index_0_3_11';
	private const LEGACY_CACHE_KEYS = array( 'action_hot_index_0_3_10', 'action_hot_index_0_3_9', 'action_hot_index_0_3_8' );
	private const CACHE_TTL = 86400;
	private static ?array $data = null;
	private static bool $cache_hit = false;
	private static float $load_ms = 0.0;

	private static function now_ns(): int {
		return function_exists( 'hrtime' ) ? (int) hrtime( true ) : (int) round( microtime( true ) * 1000000000 );
	}

	private static function sanitize_key_safe( string $value ): string {
		return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) );
	}

	public static function warm( bool $force = false ): array {
		if ( null !== self::$data && ! $force ) { return self::$data; }
		$started = self::now_ns();
		self::$cache_hit = false;
		$cached = false;
		if ( ! $force && function_exists( 'wp_cache_get' ) ) {
			$found = false;
			$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP, false, $found );
			if ( $found && is_array( $cached ) && ! empty( $cached['tools'] ) ) {
				self::$data = $cached;
				self::$cache_hit = true;
			}
		}
		if ( null === self::$data ) {
			$path = trailingslashit( PRSTUDIO_UC_DIR ) . 'contract/action-hot-index.php';
			$loaded = is_readable( $path ) ? require $path : array();
			self::$data = is_array( $loaded ) ? $loaded : array();
			if ( function_exists( 'wp_cache_set' ) && self::$data ) {
				wp_cache_set( self::CACHE_KEY, self::$data, self::CACHE_GROUP, self::CACHE_TTL );
			}
		}
		self::$load_ms = round( ( self::now_ns() - $started ) / 1000000, 3 );
		return self::$data;
	}

	public static function invalidate(): void {
		self::$data = null;
		self::$cache_hit = false;
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
			foreach ( self::LEGACY_CACHE_KEYS as $legacy_key ) { wp_cache_delete( $legacy_key, self::CACHE_GROUP ); }
		}
		if ( class_exists( 'PRSTUDIO_UC_Log_Orchestrator' ) ) { PRSTUDIO_UC_Log_Orchestrator::log( 'plugin', 'cache.action_index.invalidated', array( 'current'=>self::CACHE_KEY, 'legacy'=>self::LEGACY_CACHE_KEYS ), 'info', __FILE__ ); }
	}


	/** Runtime metadata corrections for legacy catalog entries whose immutable wire identity stays stable. */
	private static function normalize_meta( ?array $item ): ?array {
		if ( ! is_array( $item ) ) { return null; }
		$key = '/' . trim( (string) ( $item['route'] ?? '' ), '/' ) . '|' . self::sanitize_key_safe( (string) ( $item['action'] ?? '' ) );
		if ( in_array( $key, array( '/commerce-settings-manage|get_settings', '/settings-manage|get_settings' ), true ) ) {
			$item['read_only'] = true;
			$item['destructive'] = false;
			$item['idempotent'] = true;
			$item['risk'] = 'low';
		}
		return $item;
	}

	public static function by_tool( string $tool_name ): ?array {
		$data = self::warm();
		$item = $data['tools'][ $tool_name ] ?? null;
		return self::normalize_meta( is_array( $item ) ? $item : null );
	}

	public static function by_action( string $route, string $action ): ?array {
		$data = self::warm();
		$key = '/' . trim( $route, '/' ) . '|' . self::sanitize_key_safe( $action );
		$tool = (string) ( $data['route_action'][ $key ] ?? '' );
		return '' !== $tool ? self::by_tool( $tool ) : null;
	}

	public static function domain_tools( string $domain ): array {
		$data = self::warm();
		return array_values( (array) ( $data['domain_tools'][ self::sanitize_key_safe( $domain ) ] ?? array() ) );
	}

	public static function route_tools( string $route ): array {
		$data = self::warm();
		return array_values( (array) ( $data['route_tools'][ '/' . trim( $route, '/' ) ] ?? array() ) );
	}

	/**
	 * Fast ranked search. It intersects/post-ranks the prebuilt posting lists and
	 * never scans the full catalog at request time.
	 */
	public static function search( string $query, int $limit = 20, string $domain = '' ): array {
		return self::search_detailed( $query, $limit, $domain )['items'];
	}

	/**
	 * Ranked search with count and optional exact route scope for compatibility
	 * adapters. Existing search() keeps its original signature and return shape.
	 */
	public static function search_detailed( string $query, int $limit = 20, string $domain = '', string $route = '' ): array {
		$data = self::warm();
		$limit = max( 1, min( 100, $limit ) );
		$query = trim( $query );
		$domain = self::sanitize_key_safe( $domain );
		$route_input = trim( $route );
		$routed_only = '*' === $route_input;
		$route = ! $routed_only && '' !== $route_input ? '/' . trim( $route_input, '/' ) : '';
		if ( '' === $query ) {
			$names = array();
			foreach ( (array) ( $data['tools'] ?? array() ) as $tool_name => $meta ) {
				if ( '' !== $domain && (string) ( $meta['domain'] ?? '' ) !== $domain ) { continue; }
				if ( $routed_only && '' === (string) ( $meta['route'] ?? '' ) ) { continue; }
				if ( '' !== $route && (string) ( $meta['route'] ?? '' ) !== $route ) { continue; }
				$names[] = (string) $tool_name;
			}
			return array( 'items' => self::hydrate( array_slice( $names, 0, $limit ) ), 'total_matches' => count( $names ) );
		}
		if ( isset( $data['tools'][ $query ] ) ) {
			$exact = (array) $data['tools'][ $query ];
			if ( ( ! $routed_only || '' !== (string) ( $exact['route'] ?? '' ) ) && ( '' === $domain || (string) ( $exact['domain'] ?? '' ) === $domain ) && ( '' === $route || (string) ( $exact['route'] ?? '' ) === $route ) ) {
				return array( 'items' => array( $exact + array( '_score' => 1000 ) ), 'total_matches' => 1 );
			}
		}

		$concepts = PRSTUDIO_UC_Action_Lexicon::query_concepts( $query );
		$scores = array();
		foreach ( $concepts as $concept ) {
			$concept_scores = array();
			foreach ( (array) $concept as $token ) {
				foreach ( (array) ( $data['token_index'][ $token ] ?? array() ) as $position => $tool_name ) {
					$meta = (array) ( $data['tools'][ $tool_name ] ?? array() );
					if ( '' !== $domain && (string) ( $meta['domain'] ?? '' ) !== $domain ) { continue; }
					if ( $routed_only && '' === (string) ( $meta['route'] ?? '' ) ) { continue; }
					if ( '' !== $route && (string) ( $meta['route'] ?? '' ) !== $route ) { continue; }
					$weight = 12 - min( 8, (int) floor( $position / 12 ) );
					$concept_scores[ $tool_name ] = max( $concept_scores[ $tool_name ] ?? 0, $weight );
				}
			}
			foreach ( $concept_scores as $tool_name => $weight ) {
				$scores[ $tool_name ] = ( $scores[ $tool_name ] ?? 0 ) + $weight;
			}
		}
		$technical = strtolower( $query );
		foreach ( $scores as $tool_name => &$score ) {
			$meta = (array) ( $data['tools'][ $tool_name ] ?? array() );
			$action = (string) ( $meta['action'] ?? '' );
			$title = (string) ( $meta['title'] ?? '' );
			$action_concepts = PRSTUDIO_UC_Action_Lexicon::query_concepts( $action );
			$title_concepts = PRSTUDIO_UC_Action_Lexicon::query_concepts( $title );
			if ( $technical === strtolower( (string) $tool_name ) || $technical === strtolower( $action ) ) {
				$score += 300;
			} elseif ( PRSTUDIO_UC_Action_Lexicon::equivalent( $concepts, $action_concepts ) ) {
				$score += 200;
			} elseif ( PRSTUDIO_UC_Action_Lexicon::covers( $action_concepts, $concepts ) ) {
				$score += 60;
			}
			if ( PRSTUDIO_UC_Action_Lexicon::covers( $title_concepts, $concepts ) ) { $score += 30; }
		}
		unset( $score );
		$total_matches = count( $scores );
		uksort( $scores, static function ( string $left, string $right ) use ( $scores ): int {
			$rank = $scores[ $right ] <=> $scores[ $left ];
			return 0 !== $rank ? $rank : strcmp( $left, $right );
		} );
		$items = array();
		foreach ( array_slice( $scores, 0, $limit, true ) as $tool_name => $score ) {
			$items[] = (array) $data['tools'][ $tool_name ] + array( '_score' => $score );
		}
		return array( 'items' => $items, 'total_matches' => $total_matches );
	}

	private static function hydrate( array $names ): array {
		$data = self::warm();
		$items = array();
		foreach ( $names as $name ) {
			if ( isset( $data['tools'][ $name ] ) ) { $items[] = $data['tools'][ $name ]; }
		}
		return $items;
	}

	public static function domain_for_query( string $query ): string {
		$data = self::warm();
		$query = trim( $query );
		if ( isset( $data['tools'][ $query ] ) ) {
			return self::sanitize_key_safe( (string) ( $data['tools'][ $query ]['domain'] ?? 'operations' ) );
		}
		$concepts = PRSTUDIO_UC_Action_Lexicon::query_concepts( $query );
		if ( array() === $concepts ) { return 'operations'; }
		$scores = array();
		foreach ( $concepts as $concept ) {
			$concept_scores = array();
			foreach ( (array) $concept as $token ) {
				foreach ( (array) ( $data['token_index'][ $token ] ?? array() ) as $position => $tool_name ) {
					$domain = self::sanitize_key_safe( (string) ( $data['tools'][ $tool_name ]['domain'] ?? 'operations' ) );
					$weight = 12 - min( 8, (int) floor( $position / 12 ) );
					$concept_scores[ $domain ] = max( $concept_scores[ $domain ] ?? 0, $weight );
				}
			}
			foreach ( $concept_scores as $domain => $weight ) {
				$scores[ $domain ] = ( $scores[ $domain ] ?? 0 ) + $weight;
			}
		}
		uksort( $scores, static function ( string $left, string $right ) use ( $scores ): int {
			$rank = $scores[ $right ] <=> $scores[ $left ];
			return 0 !== $rank ? $rank : strcmp( $left, $right );
		} );
		return (string) ( array_key_first( $scores ) ?: 'operations' );
	}

	public static function knowledge_snapshot(): array {
		$data = self::warm();
		return array(
			'suite_version' => (string) ( $data['suite_version'] ?? '' ),
			'contract_hash' => (string) ( $data['contract_hash'] ?? '' ),
			'hot_index_hash' => (string) ( $data['hot_index_hash'] ?? '' ),
			'counts' => (array) ( $data['counts'] ?? array() ),
			'orchestrator' => (array) ( $data['orchestrator'] ?? array() ),
			'modules' => array_map(
				static fn( array $module ): array => array(
					'id' => (string) ( $module['id'] ?? '' ),
					'label' => (string) ( $module['label'] ?? '' ),
					'class' => (string) ( $module['class'] ?? '' ),
					'routes' => (array) ( $module['routes'] ?? array() ),
					'tool_count' => (int) ( $module['tool_count'] ?? 0 ),
				),
				(array) ( $data['modules'] ?? array() )
			),
			'cache' => array(
				'hit' => self::$cache_hit,
				'load_ms' => self::$load_ms,
				'backend' => function_exists( 'wp_cache_get' ) ? 'wordpress_object_cache' : 'request_memory',
			),
		);
	}

	public static function benchmark( int $iterations = 250 ): array {
		$iterations = max( 10, min( 5000, $iterations ) );
		$queries = array( 'apri google trends', 'aggiorna prezzo prodotto', 'screenshot pagina', 'elenca ordini', 'svuota cache', 'meta title seo' );
		$started = self::now_ns();
		$found = 0;
		for ( $i = 0; $i < $iterations; $i++ ) {
			$found += count( self::search( $queries[ $i % count( $queries ) ], 5 ) );
		}
		$elapsed = ( self::now_ns() - $started ) / 1000000;
		return array(
			'iterations' => $iterations,
			'lookups' => $found,
			'total_ms' => round( $elapsed, 3 ),
			'average_ms' => round( $elapsed / $iterations, 4 ),
			'target_ms' => 5.0,
			'passed' => ( $elapsed / $iterations ) <= 5.0,
			'index' => self::knowledge_snapshot(),
		);
	}
}
