<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class PRSTUDIO_UC_Domain_Abstract {
	abstract public function id(): string;
	abstract public function label(): string;
	/** @return array<int,string> */
	abstract public function routes(): array;
	/** @return array<int,string> */
	abstract public function keywords(): array;

	public function class_name(): string { return static::class; }

	public function score( string $objective, array $catalog ): int {
		$text = PRSTUDIO_UC_Orchestrator::normalize( $objective );
		$score = 0;
		foreach ( $this->keywords() as $keyword ) {
			$keyword = PRSTUDIO_UC_Orchestrator::normalize( $keyword );
			if ( '' !== $keyword && false !== strpos( $text, $keyword ) ) {
				$score += max( 3, substr_count( $keyword, ' ' ) + 3 );
			}
		}
		$action_bonus = 0;
		$seen_actions = array();
		foreach ( $catalog as $meta ) {
			if ( ! in_array( (string) ( $meta['route'] ?? '' ), $this->routes(), true ) ) { continue; }
			$action = PRSTUDIO_UC_Orchestrator::normalize( str_replace( '_', ' ', (string) ( $meta['action'] ?? '' ) ) );
			if ( '' === $action || isset( $seen_actions[ $action ] ) || strlen( $action ) < 4 ) { continue; }
			$seen_actions[ $action ] = true;
			if ( false !== strpos( $text, $action ) ) {
				$action_bonus = max( $action_bonus, 6 + min( 6, substr_count( $action, ' ' ) + 1 ) );
			}
		}
		return $score + $action_bonus;
	}

	/** @return array<int,array<string,mixed>> */
	public function actions( array $catalog, string $query = '', int $limit = 250, bool $include_schema = false ): array {
		$limit = max( 1, min( 500, $limit ) );
		if ( class_exists( 'PRSTUDIO_UC_Action_Index' ) ) {
			$matches = PRSTUDIO_UC_Action_Index::search( $query, $limit, $this->id() );
			$items = array();
			foreach ( $matches as $meta ) {
				$item = array(
					'tool_name' => (string) ( $meta['tool_name'] ?? '' ),
					'route' => (string) ( $meta['route'] ?? '' ),
					'action' => (string) ( $meta['action'] ?? '' ),
					'title' => (string) ( $meta['title'] ?? '' ),
					'description' => (string) ( $meta['description'] ?? '' ),
					'read_only' => ! empty( $meta['read_only'] ),
					'destructive' => ! empty( $meta['destructive'] ),
					'idempotent' => ! empty( $meta['idempotent'] ),
					'executor' => (string) ( $meta['executor'] ?? 'wordpress' ),
					'strategy' => (string) ( $meta['strategy'] ?? 'wordpress_native' ),
					'parameters' => array_values( (array) ( $meta['parameters'] ?? array() ) ),
					'enterprise_risk' => (string) ( $meta['risk'] ?? 'read' ),
					'index_score' => (int) ( $meta['_score'] ?? 0 ),
				);
				if ( $include_schema ) {
					foreach ( $catalog as $full ) {
						if ( (string) ( $full['tool_name'] ?? '' ) === $item['tool_name'] ) {
							$item['input_schema'] = (array) ( $full['input_schema'] ?? array() );
							break;
						}
					}
				}
				$items[] = $item;
			}
			return $items;
		}

		$items = array();
		$query_tokens = PRSTUDIO_UC_Orchestrator::tokens( $query );
		foreach ( $catalog as $meta ) {
			if ( ! in_array( (string) ( $meta['route'] ?? '' ), $this->routes(), true ) ) { continue; }
			$haystack = PRSTUDIO_UC_Orchestrator::normalize( implode( ' ', array(
				(string) ( $meta['action'] ?? '' ),
				(string) ( $meta['tool_name'] ?? '' ),
				(string) ( $meta['title'] ?? '' ),
				(string) ( $meta['description'] ?? '' ),
			) ) );
			$rank = 0;
			foreach ( $query_tokens as $token ) {
				if ( false !== strpos( $haystack, $token ) ) { $rank += strlen( $token ) > 5 ? 3 : 1; }
			}
			if ( $query_tokens && 0 === $rank ) { continue; }
			$item = array(
				'tool_name' => (string) ( $meta['tool_name'] ?? '' ),
				'route' => (string) ( $meta['route'] ?? '' ),
				'action' => (string) ( $meta['action'] ?? '' ),
				'title' => (string) ( $meta['title'] ?? '' ),
				'description' => (string) ( $meta['description'] ?? '' ),
				'read_only' => ! empty( $meta['read_only'] ),
				'destructive' => ! empty( $meta['destructive'] ),
				'parameters' => array_values( array_keys( (array) ( $meta['input_schema']['properties'] ?? array() ) ) ),
				'_rank' => $rank,
			);
			if ( $include_schema ) { $item['input_schema'] = (array) ( $meta['input_schema'] ?? array() ); }
			$items[] = $item;
		}
		usort( $items, static function ( array $a, array $b ): int {
			if ( $a['_rank'] === $b['_rank'] ) { return strcmp( $a['tool_name'], $b['tool_name'] ); }
			return $b['_rank'] <=> $a['_rank'];
		} );
		$items = array_slice( $items, 0, $limit );
		foreach ( $items as &$item ) { unset( $item['_rank'] ); }
		unset( $item );
		return $items;
	}

	/** @return array<int,array<string,mixed>> */
	public function workflow( string $objective, array $arguments, array $catalog ): array {
		$matches = $this->actions( $catalog, $objective, 5, false );
		if ( ! $matches ) { return array(); }
		$best = $matches[0];
		return array( array(
			'tool_name' => $best['tool_name'],
			'route' => $best['route'],
			'action' => $best['action'],
			'arguments' => $arguments,
			'reason' => 'Migliore corrispondenza nel dominio ' . $this->label() . '.',
			'read_only' => $best['read_only'],
			'destructive' => $best['destructive'],
		) );
	}
}
