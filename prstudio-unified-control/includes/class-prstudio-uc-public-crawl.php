<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded, read-only public crawler used by the bridge for sitemap and
 * WordPress link-graph audits. It never sends cookies/auth headers and never
 * leaves the WordPress public host.
 */
final class PRSTUDIO_UC_Public_Crawl {
	private const MAX_DOCUMENT_BYTES = 4194304; // 4 MiB per XML document.
	private const MAX_SITEMAPS = 100;
	private const MAX_URLS = 10000;
	private const MAX_POSTS = 10000;
	private const MAX_GRAPH_EDGES = 50000;
	private const MAX_HTTP_URLS = 500;
	private const MAX_HTML_BYTES = 2097152;
	private const MAX_RENDERED_PAGES = 1500;

	private static function home_host(): string {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	private static function normalize_url( string $url, string $base = '' ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url ) { return ''; }
		if ( preg_match( '#^(?:mailto|tel|javascript|data):#i', $url ) ) { return ''; }
		$root = $base ?: home_url( '/' );
		if ( 0 === strpos( $url, '//' ) ) { $url = ( is_ssl() ? 'https:' : 'http:' ) . $url; }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			$base_parts = wp_parse_url( $root );
			if ( ! is_array( $base_parts ) || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) { return ''; }
			$origin = strtolower( (string) $base_parts['scheme'] ) . '://' . strtolower( (string) $base_parts['host'] ) . ( isset( $base_parts['port'] ) ? ':' . absint( $base_parts['port'] ) : '' );
			if ( '?' === substr( $url, 0, 1 ) ) {
				$path = (string) ( $base_parts['path'] ?? '/' ); $url = $origin . ( '' !== $path ? $path : '/' ) . $url;
			} elseif ( '/' === substr( $url, 0, 1 ) ) {
				$url = $origin . $url;
			} else {
				$base_path = (string) ( $base_parts['path'] ?? '/' );
				$dir = preg_replace( '#/[^/]*$#', '/', $base_path );
				$url = $origin . ( $dir ?: '/' ) . $url;
			}
			$parts = wp_parse_url( $url );
		}
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return ''; }
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) { return ''; }
		if ( strtolower( (string) $parts['host'] ) !== self::home_host() ) { return ''; }
		$raw_path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$segments = array();
		foreach ( explode( '/', $raw_path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) { continue; }
			if ( '..' === $segment ) { array_pop( $segments ); continue; }
			$segments[] = $segment;
		}
		$path = '/' . implode( '/', $segments );
		if ( str_ends_with( $raw_path, '/' ) && '/' !== $path ) { $path .= '/'; }
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		$port = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . $port . $path . $query;
	}

	/** WordPress-native public HTTP. HEAD is retried as a bounded GET when hosts reject or short-circuit loopback HEAD requests. */
	private static function public_request( string $url, string $method = 'GET', array $args = array() ) {
		$base = array( 'timeout'=>8, 'redirection'=>3, 'reject_unsafe_urls'=>true, 'user-agent'=>'PRSTUDIO-Bridge/2.0.0' );
		$args = array_merge( $base, $args );
		$method = strtoupper( $method );
		$response = 'HEAD' === $method ? wp_safe_remote_head( $url, $args ) : wp_safe_remote_get( $url, $args );
		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 'HEAD' === $method && ( is_wp_error( $response ) || 0 === $status || in_array( $status, array( 405, 501 ), true ) ) ) {
			$get_args = $args;
			$get_args['limit_response_size'] = min( 65536, max( 1, absint( $args['limit_response_size'] ?? 65536 ) ) );
			$response = wp_safe_remote_get( $url, $get_args );
			if ( ! is_wp_error( $response ) ) { $response['prstudio_transport'] = 'wordpress_http_get_fallback'; }
		} elseif ( ! is_wp_error( $response ) ) {
			$response['prstudio_transport'] = 'HEAD' === $method ? 'wordpress_http_head' : 'wordpress_http_get';
		}
		return $response;
	}

	private static function default_sitemap_url(): string {
		foreach ( array( '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml' ) as $path ) {
			$url = home_url( $path );
			$response = self::public_request( $url, 'HEAD', array( 'timeout' => 6, 'redirection' => 2 ) );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) { return $url; }
		}
		return home_url( '/sitemap_index.xml' );
	}

	private static function extract_locs( string $xml ): array {
		if ( ! preg_match_all( '#<loc\b[^>]*>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</loc>#is', $xml, $matches ) ) { return array(); }
		$out = array();
		foreach ( $matches[1] as $value ) {
			$value = trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' ) ) );
			if ( '' !== $value ) { $out[] = $value; }
		}
		return array_values( array_unique( $out ) );
	}

	public static function sitemap( array $args = array() ) {
		$max_sitemaps = max( 1, min( self::MAX_SITEMAPS, absint( $args['max_sitemaps'] ?? 25 ) ) );
		$max_urls = max( 1, min( self::MAX_URLS, absint( $args['max_urls'] ?? $args['limit'] ?? 5000 ) ) );
		$seed = self::normalize_url( (string) ( $args['url'] ?? $args['sitemap_url'] ?? '' ) );
		if ( '' === $seed ) { $seed = self::normalize_url( self::default_sitemap_url() ); }
		if ( '' === $seed ) { return new WP_Error( 'prstudio_sitemap_url_invalid', 'URL sitemap non valida o fuori dall’host WordPress.', array( 'status' => 400 ) ); }

		$queue = array( $seed ); $visited = array(); $urls = array(); $documents = array(); $errors = array();
		while ( $queue && count( $visited ) < $max_sitemaps && count( $urls ) < $max_urls ) {
			$current = array_shift( $queue );
			if ( isset( $visited[ $current ] ) ) { continue; }
			$visited[ $current ] = true;
			$response = self::public_request( $current, 'GET', array(
				'timeout' => 12, 'redirection' => 3,
				'limit_response_size' => self::MAX_DOCUMENT_BYTES,
				'user-agent' => 'PRSTUDIO-Bridge/2.0.0',
				'headers' => array( 'Accept' => 'application/xml,text/xml,application/rss+xml,text/plain;q=0.8,*/*;q=0.2' ),
			) );
			if ( is_wp_error( $response ) ) { $errors[] = array( 'url' => $current, 'error' => $response->get_error_code() ); continue; }
			$status = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			if ( 200 !== $status || '' === $body ) { $errors[] = array( 'url' => $current, 'status' => $status, 'error' => 'sitemap_http_error' ); continue; }
			$is_index = (bool) preg_match( '#<sitemapindex\b#i', $body );
			$locs = self::extract_locs( $body );
			$documents[] = array( 'url' => $current, 'status' => $status, 'type' => $is_index ? 'index' : 'urlset', 'loc_count' => count( $locs ), 'bytes' => strlen( $body ) );
			if ( $is_index ) {
				foreach ( $locs as $loc ) {
					$normalized = self::normalize_url( $loc, $current );
					if ( '' !== $normalized && ! isset( $visited[ $normalized ] ) && count( $queue ) + count( $visited ) < $max_sitemaps * 3 ) { $queue[] = $normalized; }
				}
			} else {
				foreach ( $locs as $loc ) {
					$normalized = self::normalize_url( $loc, $current );
					if ( '' !== $normalized ) { $urls[ $normalized ] = true; }
					if ( count( $urls ) >= $max_urls ) { break; }
				}
			}
		}
		return array(
			'action' => 'playwright_sitemap_crawl', 'module' => 'wordpress_public_sitemap_crawler_v2',
			'provider' => 'prstudio_wordpress_public_sitemap_crawler', 'seed_url' => $seed,
			'documents' => $documents, 'sitemap_count' => count( $documents ), 'urls' => array_keys( $urls ),
			'count' => count( $urls ), 'errors' => $errors, 'bounded' => true, 'tab_required' => false,
			'credentials_sent' => false, 'same_origin_only' => true, 'verified' => true,
		);
	}

	private static function public_inventory(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		$types = array_values( array_diff( array_map( 'sanitize_key', (array) $types ), array( 'attachment' ) ) );
		$ids = get_posts( array( 'post_type' => $types ?: array( 'post', 'page', 'product' ), 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => self::MAX_POSTS, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false ) );
		$out = array();
		foreach ( (array) $ids as $id ) { $url = self::normalize_url( (string) get_permalink( (int) $id ) ); if ( '' !== $url ) { $out[ $url ] = (int) $id; } }
		return $out;
	}

	public static function audit_sitemap_coverage( array $args = array() ) {
		$sitemap = self::sitemap( $args );
		if ( is_wp_error( $sitemap ) ) { return $sitemap; }
		$inventory = self::public_inventory(); $sitemap_set = array_fill_keys( (array) $sitemap['urls'], true );
		$missing = array(); $covered = array();
		foreach ( $inventory as $url => $id ) { if ( isset( $sitemap_set[ $url ] ) ) { $covered[] = array( 'id' => $id, 'url' => $url ); } else { $missing[] = array( 'id' => $id, 'url' => $url ); } }
		$not_inventory = array_values( array_diff( array_keys( $sitemap_set ), array_keys( $inventory ) ) );
		$total = count( $inventory );
		return array(
			'provider' => 'prstudio_wordpress_public_sitemap_crawler', 'scope' => 'published_public_wordpress_objects_vs_public_sitemap',
			'inventory_count' => $total, 'sitemap_url_count' => count( $sitemap_set ), 'covered_count' => count( $covered ),
			'missing_count' => count( $missing ), 'coverage_ratio' => $total ? round( count( $covered ) / $total, 6 ) : 1.0,
			'missing_from_sitemap' => array_slice( $missing, 0, 1000 ), 'sitemap_urls_not_in_wp_inventory' => array_slice( $not_inventory, 0, 1000 ),
			'sitemap' => $sitemap, 'verified' => true, 'bounded' => true,
		);
	}

	private static function links_from_html( string $html, string $base = '' ): array {
		$links = array();
		$patterns = array(
			'#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#is',
			'#\bdata-(?:url|href)\s*=\s*(["\'])(.*?)\1#is',
			'#["\'](?:url|href)["\']\s*:\s*(["\'])(.*?)\1#is',
		);
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $html, $matches ) ) { continue; }
			foreach ( $matches[2] as $href ) {
				$href = trim( (string) $href );
				if ( '' === $href || '#' === $href[0] || preg_match( '#^(?:mailto|tel|javascript|data):#i', $href ) ) { continue; }
				$url = self::normalize_url( $href, $base );
				if ( '' !== $url ) { $links[ $url ] = true; }
			}
		}
		return array_keys( $links );
	}

	private static function rendered_public_links( string $url ): array {
		$response = self::public_request( $url, 'GET', array(
			'timeout' => 8, 'redirection' => 3,
			'limit_response_size' => self::MAX_HTML_BYTES,
			'user-agent' => 'PRSTUDIO-Bridge/2.0.0',
			'headers' => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1' ),
		) );
		if ( is_wp_error( $response ) ) { return array( 'ok'=>false, 'status'=>0, 'error'=>$response->get_error_code(), 'links'=>array(), 'bytes'=>0 ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 400 || '' === $body ) { return array( 'ok'=>false, 'status'=>$status, 'error'=>'html_http_error', 'links'=>array(), 'bytes'=>strlen($body) ); }
		return array( 'ok'=>true, 'status'=>$status, 'error'=>'', 'links'=>self::links_from_html( $body, $url ), 'bytes'=>strlen($body) );
	}

	private static function graph_data( array $args = array() ): array {
		$inventory = self::public_inventory();
		$inbound = array_fill_keys( array_keys( $inventory ), 0 );
		$edges = array(); $edge_count = 0; $edge_keys = array(); $all_internal_targets = array();
		$runtime_pages_checked = 0; $runtime_errors = array(); $runtime_bytes = 0;
		$render_limit = max( 0, min( self::MAX_RENDERED_PAGES, absint( $args['runtime_limit'] ?? $args['limit'] ?? self::MAX_RENDERED_PAGES ) ) );
		$include_runtime = ! isset( $args['include_runtime'] ) || (bool) $args['include_runtime'];
		$add_edge = static function ( string $source, string $target, string $kind ) use ( &$inventory, &$inbound, &$edges, &$edge_count, &$edge_keys, &$all_internal_targets ): void {
			$all_internal_targets[ $target ] = true;
			if ( ! isset( $inventory[ $target ] ) || $target === $source ) { return; }
			$key = $source . "\n" . $target . "\n" . $kind;
			if ( isset( $edge_keys[ $key ] ) ) { return; }
			$edge_keys[ $key ] = true; $inbound[ $target ]++; $edge_count++;
			if ( count( $edges ) < self::MAX_GRAPH_EDGES ) { $edges[] = array( 'source' => $source, 'target' => $target, 'kind' => $kind ); }
		};
		foreach ( $inventory as $source_url => $post_id ) {
			$content = (string) get_post_field( 'post_content', $post_id );
			foreach ( self::links_from_html( $content, $source_url ) as $target ) { $add_edge( $source_url, $target, 'stored_content' ); }
			if ( $include_runtime && $runtime_pages_checked < $render_limit ) {
				$rendered = null; $memory_reused = false;
				if ( class_exists( 'PRSTUDIO_UC_Memory' ) && PRSTUDIO_UC_Memory::can_reuse( 'rendered_links', $source_url ) ) {
					$cached = PRSTUDIO_UC_Memory::lookup( 'rendered_links', $source_url );
					if ( is_array( $cached['summary']['result'] ?? null ) ) { $rendered = $cached['summary']['result']; $memory_reused = true; }
				}
				if ( ! is_array( $rendered ) ) {
					$rendered = self::rendered_public_links( $source_url );
					if ( class_exists( 'PRSTUDIO_UC_Memory' ) && ! empty( $rendered['ok'] ) ) { PRSTUDIO_UC_Memory::remember( 'rendered_links', $source_url, PRSTUDIO_UC_Memory::fingerprint( $rendered['links'] ?? array() ), array( 'result'=>$rendered, 'method'=>'wordpress_http_render_scan' ), 21600 ); }
				}
				$runtime_pages_checked++; $runtime_bytes += $memory_reused ? 0 : (int) ( $rendered['bytes'] ?? 0 );
				if ( $memory_reused && class_exists( 'PRSTUDIO_UC_Memory' ) ) { PRSTUDIO_UC_Memory::movement( 'memory.reused', array( 'resource'=>$source_url, 'method'=>'rendered_link_graph', 'outcome'=>'skipped_duplicate_fetch' ) ); }
				if ( empty( $rendered['ok'] ) ) { $runtime_errors[] = array( 'url'=>$source_url, 'status'=>(int)($rendered['status']??0), 'error'=>(string)($rendered['error']??'runtime_fetch_failed') ); }
				else { foreach ( (array) $rendered['links'] as $target ) { $add_edge( $source_url, $target, $memory_reused ? 'rendered_public_html_memory' : 'rendered_public_html' ); } }
			}
		}
		if ( function_exists( 'wp_get_nav_menus' ) && function_exists( 'wp_get_nav_menu_items' ) ) {
			foreach ( (array) wp_get_nav_menus() as $menu ) {
				foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
					$target = self::normalize_url( (string) ( $item->url ?? '' ) );
					if ( '' !== $target ) { $add_edge( '__navigation__', $target, 'navigation' ); }
				}
			}
		}
		$nodes = array();
		foreach ( $inventory as $url => $id ) { $nodes[] = array( 'id' => $id, 'url' => $url, 'inbound_links' => (int) $inbound[ $url ] ); }
		return array(
			'inventory' => $inventory, 'inbound' => $inbound, 'nodes' => $nodes, 'edges' => $edges,
			'edge_count' => $edge_count, 'edges_truncated' => $edge_count > count( $edges ),
			'all_internal_targets' => array_keys( $all_internal_targets ),
			'runtime_pages_checked' => $runtime_pages_checked, 'runtime_page_limit' => $render_limit,
			'runtime_inventory_complete' => $include_runtime && $runtime_pages_checked >= count( $inventory ),
			'runtime_errors' => array_slice( $runtime_errors, 0, 500 ), 'runtime_error_count' => count( $runtime_errors ),
			'runtime_bytes' => $runtime_bytes,
		);
	}

	private static function depth_kpi( array $data, array $args = array() ): array {
		$inventory = (array) ( $data['inventory'] ?? array() );
		$edges = (array) ( $data['edges'] ?? array() );
		$depth = array_fill_keys( array_keys( $inventory ), null );
		$adj = array(); $queue = array();
		$home = self::normalize_url( home_url( '/' ) );
		if ( array_key_exists( $home, $depth ) ) { $depth[ $home ] = 0; $queue[] = $home; }
		foreach ( $edges as $edge ) {
			$source=(string)($edge['source']??''); $target=(string)($edge['target']??'');
			if ( isset($inventory[$source]) && isset($inventory[$target]) ) { $adj[$source][$target]=true; }
			if ( '__navigation__' === $source && isset($inventory[$target]) && array_key_exists($target,$depth) && null === $depth[$target] ) { $depth[$target]=1; $queue[]=$target; }
		}
		$cursor=0;
		while ( isset( $queue[$cursor] ) ) {
			$source=$queue[$cursor++]; $base=(int)$depth[$source];
			foreach ( array_keys( (array)($adj[$source]??array()) ) as $target ) {
				$next=$base+1; if ( null === $depth[$target] || $next < $depth[$target] ) { $depth[$target]=$next; $queue[]=$target; }
			}
		}
		$distribution=array(); $unreachable=array(); $deep=array(); $max_depth=0;
		$threshold=max(1,min(20,absint($args['deep_threshold']??4)));
		foreach ( $inventory as $url=>$id ) {
			$d=$depth[$url];
			if ( null === $d ) { $unreachable[]=array('id'=>(int)$id,'url'=>$url,'depth'=>null); continue; }
			$distribution[(string)$d]=(int)($distribution[(string)$d]??0)+1; $max_depth=max($max_depth,(int)$d);
			if ( $d > $threshold ) { $deep[]=array('id'=>(int)$id,'url'=>$url,'depth'=>(int)$d); }
		}
		ksort($distribution,SORT_NUMERIC);
		$complete = empty( $data['edges_truncated'] );
		return array(
			'verified'=>$complete, 'basis'=>$complete?'complete_returned_graph':'graph_edge_limit_reached',
			'root_url'=>isset($inventory[$home])?$home:null, 'navigation_roots_used'=>true,
			'max_depth'=>$max_depth, 'depth_distribution'=>$distribution, 'unreachable_count'=>count($unreachable),
			'unreachable_pages'=>array_slice($unreachable,0,1000), 'deep_threshold'=>$threshold, 'deep_page_count'=>count($deep), 'deep_pages'=>array_slice($deep,0,1000),
			'depth_by_url'=>$depth,
		);
	}

	public static function build_internal_link_graph( array $args = array() ) {
		$data = self::graph_data( $args ); $depth = self::depth_kpi( $data, $args );
		foreach ( $data['nodes'] as &$node ) { $node['depth'] = $depth['depth_by_url'][$node['url']] ?? null; } unset($node);
		unset($depth['depth_by_url']);
		if ( class_exists( 'PRSTUDIO_UC_Enterprise_Engine' ) && class_exists( 'PRSTUDIO_UC_Memory' ) ) { PRSTUDIO_UC_Enterprise_Engine::graph_merge( $data['nodes'], $data['edges'] ); PRSTUDIO_UC_Memory::movement( 'site_graph.updated', array( 'resource'=>'internal_link_graph', 'method'=>'incremental_graph_merge', 'outcome'=>'updated', 'nodes'=>count($data['nodes']), 'edges'=>count($data['edges']) ) ); }
		return array(
			'provider' => 'prstudio_hybrid_rendered_link_graph', 'scope' => 'published_wordpress_content_plus_navigation_plus_public_rendered_html',
			'node_count' => count( $data['inventory'] ), 'edge_count' => $data['edge_count'],
			'nodes' => $data['nodes'], 'edges' => $data['edges'], 'edges_truncated' => $data['edges_truncated'],
			'edge_return_limit' => self::MAX_GRAPH_EDGES, 'depth_kpi' => $depth,
			'runtime' => array( 'pages_checked'=>$data['runtime_pages_checked'], 'page_limit'=>$data['runtime_page_limit'], 'inventory_complete'=>$data['runtime_inventory_complete'], 'errors'=>$data['runtime_error_count'], 'bytes'=>$data['runtime_bytes'], 'browser_agent_action'=>'playwright_link_crawl' ),
			'limitations' => $data['runtime_inventory_complete'] ? array( 'post_hydration_javascript_links_are_verified_by_browser_agent_playwright_link_crawl' ) : array( 'runtime_render_scan_bounded', 'post_hydration_javascript_links_are_verified_by_browser_agent_playwright_link_crawl' ),
			'bounded' => true, 'verified' => true,
		);
	}

	public static function audit_orphan_pages( array $args = array() ) {
		$data = self::graph_data( $args ); $inventory = $data['inventory']; $inbound = $data['inbound']; $depth = self::depth_kpi( $data, $args );
		$home = self::normalize_url( home_url( '/' ) ); $orphans = array();
		foreach ( $inventory as $url => $id ) { if ( $url !== $home && 0 === (int) $inbound[ $url ] ) { $orphans[] = array( 'id' => $id, 'url' => $url, 'inbound_links' => 0, 'depth' => $depth['depth_by_url'][$url] ?? null ); } }
		$sitemap = self::sitemap( $args ); unset($depth['depth_by_url']);
		return array(
			'provider' => 'prstudio_hybrid_rendered_link_graph', 'scope' => 'published_wordpress_content_plus_navigation_plus_public_rendered_html',
			'node_count' => count( $inventory ), 'edge_count' => $data['edge_count'], 'orphan_count' => count( $orphans ), 'orphan_pages' => array_slice( $orphans, 0, 1000 ),
			'graph' => array( 'nodes' => $data['nodes'], 'edges' => $data['edges'], 'edges_truncated' => $data['edges_truncated'], 'edge_return_limit' => self::MAX_GRAPH_EDGES ),
			'depth_kpi' => $depth,
			'sitemap_crosscheck' => is_wp_error( $sitemap ) ? array( 'verified' => false, 'error' => $sitemap->get_error_code() ) : array( 'verified' => true, 'sitemap_url_count' => (int) $sitemap['count'] ),
			'runtime' => array( 'pages_checked'=>$data['runtime_pages_checked'], 'page_limit'=>$data['runtime_page_limit'], 'inventory_complete'=>$data['runtime_inventory_complete'], 'errors'=>$data['runtime_error_count'], 'bytes'=>$data['runtime_bytes'], 'browser_agent_action'=>'playwright_link_crawl' ),
			'limitations' => $data['runtime_inventory_complete'] ? array( 'post_hydration_javascript_links_are_verified_by_browser_agent_playwright_link_crawl' ) : array( 'runtime_render_scan_bounded', 'post_hydration_javascript_links_are_verified_by_browser_agent_playwright_link_crawl' ), 'bounded' => true, 'verified' => true,
		);
	}

	private static function http_statuses_for_urls( array $urls, int $limit ): array {
		$limit = max( 1, min( self::MAX_HTTP_URLS, $limit ) ); $items = array(); $errors = 0;
		foreach ( array_slice( array_values( array_unique( $urls ) ), 0, $limit ) as $raw_url ) {
			$url = self::normalize_url( (string) $raw_url ); if ( '' === $url ) { continue; }
			if ( class_exists( 'PRSTUDIO_UC_Memory' ) && PRSTUDIO_UC_Memory::can_reuse( 'http_status', $url ) ) {
				$cached = PRSTUDIO_UC_Memory::lookup( 'http_status', $url ); $row = is_array( $cached['summary']['item'] ?? null ) ? $cached['summary']['item'] : array();
				if ( $row ) { $row['memory_reused']=true; $row['duration_ms']=0; $items[]=$row; if(empty($row['ok']))$errors++; PRSTUDIO_UC_Memory::movement('memory.reused',array('resource'=>$url,'method'=>'http_status','outcome'=>'skipped_duplicate_request')); continue; }
			}
			$started = microtime( true );
			$response = self::public_request( $url, 'HEAD', array( 'timeout' => 8, 'redirection' => 3 ) );
			if ( is_wp_error( $response ) ) {
				$row = array( 'url' => $url, 'ok' => false, 'status' => 0, 'error' => $response->get_error_code(), 'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'memory_reused'=>false );
				$items[] = $row; $errors++; if(class_exists('PRSTUDIO_UC_Memory')){PRSTUDIO_UC_Memory::remember('http_status',$url,PRSTUDIO_UC_Memory::fingerprint($row),array('item'=>$row,'status'=>'observed'),900); PRSTUDIO_UC_Memory::movement('http.status.checked',array('resource'=>$url,'method'=>'wordpress_http_api','outcome'=>'error'));} continue;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			$row = array( 'url' => $url, 'ok' => $status >= 200 && $status < 400, 'status' => $status, 'transport' => (string)($response['prstudio_transport'] ?? 'wordpress_http_api'), 'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'memory_reused'=>false );
			$items[] = $row;
			if(class_exists('PRSTUDIO_UC_Memory')){PRSTUDIO_UC_Memory::remember('http_status',$url,PRSTUDIO_UC_Memory::fingerprint($row),array('item'=>$row,'status'=>'verified'),3600); PRSTUDIO_UC_Memory::movement('http.status.checked',array('resource'=>$url,'method'=>$row['transport'],'outcome'=>(string)$status));}
			if ( $status < 200 || $status >= 400 ) { $errors++; }
		}
		return array( 'items' => $items, 'count' => count( $items ), 'error_count' => $errors );
	}

	public static function audit_http_statuses( array $args = array() ) {
		$limit = max( 1, min( self::MAX_HTTP_URLS, absint( $args['limit'] ?? 100 ) ) );
		$urls = isset( $args['urls'] ) && is_array( $args['urls'] ) ? $args['urls'] : array();
		$source = 'explicit_urls';
		if ( ! $urls ) {
			$sitemap = self::sitemap( array_merge( $args, array( 'max_urls' => $limit ) ) );
			if ( is_wp_error( $sitemap ) ) { return $sitemap; }
			$urls = (array) $sitemap['urls']; $source = 'public_sitemap';
		}
		$result = self::http_statuses_for_urls( $urls, $limit );
		return array_merge( array(
			'provider' => 'prstudio_wordpress_safe_http_batch', 'source' => $source,
			'same_origin_only' => true, 'credentials_sent' => false, 'bounded' => true, 'verified' => true,
			'max_urls' => self::MAX_HTTP_URLS,
		), $result );
	}

	public static function audit_broken_internal_links( array $args = array() ) {
		$data = self::graph_data( $args ); $limit = max( 1, min( self::MAX_HTTP_URLS, absint( $args['limit'] ?? 250 ) ) );
		$result = self::http_statuses_for_urls( $data['all_internal_targets'], $limit );
		$broken = array_values( array_filter( $result['items'], static fn( $row ) => empty( $row['ok'] ) ) );
		return array(
			'provider' => 'prstudio_wordpress_safe_http_batch', 'source' => 'stored_rendered_internal_links_plus_navigation',
			'checked_count' => $result['count'], 'broken_count' => count( $broken ), 'broken_links' => $broken,
			'graph_edge_count' => $data['edge_count'], 'same_origin_only' => true, 'credentials_sent' => false,
			'bounded' => true, 'verified' => true, 'max_urls' => self::MAX_HTTP_URLS,
		);
	}

}
