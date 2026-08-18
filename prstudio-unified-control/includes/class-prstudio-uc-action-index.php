<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

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

	private static function normalize( string $value ): string {
		$value = strtolower( str_replace( array( '_', '/' ), ' ', $value ) );
		if ( function_exists( 'remove_accents' ) ) { $value = remove_accents( $value ); }
		$value = preg_replace( '/[^a-z0-9\s\-]+/u', ' ', $value );
		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	private static function tokens( string $value ): array {
		$stop = array_fill_keys( array( 'che','con','del','della','delle','degli','per','una','uno','un','il','la','lo','le','i','gli','di','da','in','su','e','o','fare','devo','voglio','please','the','a','to','and' ), true );
		return array_values( array_unique( array_filter(
			explode( ' ', self::normalize( $value ) ),
			static fn( string $token ): bool => strlen( $token ) >= 2 && ! isset( $stop[ $token ] )
		) ) );
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
		$data = self::warm();
		$limit = max( 1, min( 100, $limit ) );
		$query = trim( $query );
		if ( '' === $query ) {
			$names = '' !== $domain ? self::domain_tools( $domain ) : array_keys( (array) ( $data['tools'] ?? array() ) );
			return self::hydrate( array_slice( $names, 0, $limit ) );
		}
		if ( isset( $data['tools'][ $query ] ) ) { return array( $data['tools'][ $query ] + array( '_score' => 1000 ) ); }

		$tokens = self::tokens( $query );
		$scores = array();
		foreach ( $tokens as $token ) {
			$posting = (array) ( $data['token_index'][ $token ] ?? array() );
			foreach ( $posting as $position => $tool_name ) {
				if ( '' !== $domain && (string) ( $data['tools'][ $tool_name ]['domain'] ?? '' ) !== $domain ) { continue; }
				$scores[ $tool_name ] = ( $scores[ $tool_name ] ?? 0 ) + 12 - min( 8, (int) floor( $position / 12 ) );
			}
		}
		$normalized = self::normalize( $query );
		foreach ( $scores as $tool_name => &$score ) {
			$meta = (array) ( $data['tools'][ $tool_name ] ?? array() );
			$exact = self::normalize( (string) ( $meta['action'] ?? '' ) );
			$title = self::normalize( (string) ( $meta['title'] ?? '' ) );
			if ( $normalized === self::normalize( $tool_name ) || $normalized === $exact ) { $score += 200; }
			elseif ( '' !== $exact && str_contains( $normalized, $exact ) ) { $score += 60; }
			if ( '' !== $title && str_contains( $title, $normalized ) ) { $score += 30; }
		}
		unset( $score );
		arsort( $scores, SORT_NUMERIC );
		$items = array();
		foreach ( array_slice( $scores, 0, $limit, true ) as $tool_name => $score ) {
			$items[] = (array) $data['tools'][ $tool_name ] + array( '_score' => $score );
		}
		return $items;
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
		$results = self::search( $query, 8 );
		$scores = array();
		foreach ( $results as $item ) {
			$domain = self::sanitize_key_safe( (string) ( $item['domain'] ?? 'operations' ) );
			$scores[ $domain ] = ( $scores[ $domain ] ?? 0 ) + max( 1, (int) ( $item['_score'] ?? 1 ) );
		}
		arsort( $scores, SORT_NUMERIC );
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
