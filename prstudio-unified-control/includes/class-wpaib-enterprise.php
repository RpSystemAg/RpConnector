<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPAIB_Enterprise {
	private const LOCK_OPTION = 'wpaib_enterprise_locks';
	private const STATE_OPTION = 'wpaib_enterprise_state';

	private static function clean_key( string $key ): string {
		return substr( (string) preg_replace( '/[^a-zA-Z0-9_.:-]/', '', $key ), 0, 160 );
	}

	private static function sensitive_key( string $key ): bool {
		return (bool) preg_match( '/(?:password|passwd|secret|token|private|credential|client[_-]?secret|api[_-]?key)/i', $key );
	}

	private static function redact( $value, string $key = '' ) {
		if ( self::sensitive_key( $key ) ) {
			return '[REDACTED]';
		}

		if ( is_array( $value ) ) {
			$out = array();

			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::redact( $v, (string) $k );
			}

			return $out;
		}

		if ( is_object( $value ) ) {
			return self::redact( get_object_vars( $value ), $key );
		}

		return $value;
	}

	private static function json_safe( $value ) {
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			$out = array();

			foreach ( $value as $key => $item ) {
				if ( is_int( $key ) ) {
					$out[ $key ] = self::json_safe( $item );
					continue;
				}

				/*
				 * Site Kit datapoints use case-sensitive camelCase parameters
				 * such as accountID, internalContainerID and startDate.
				 * sanitize_key() lowercases them and silently changes the
				 * request contract, so preserve case while allowing only the
				 * same conservative key alphabet used by bridge state keys.
				 */
				$safe_key = self::clean_key( (string) $key );

				if ( '' !== $safe_key ) {
					$out[ $safe_key ] = self::json_safe( $item );
				}
			}

			return $out;
		}

		return (string) $value;
	}

	private static function expected_matches( $expected, $actual ): bool {
		return null === $expected
			|| wp_json_encode( self::json_safe( $expected ) ) === wp_json_encode( self::json_safe( $actual ) );
	}

	public static function status(): array {
		$agency = class_exists( 'PRSTUDIO_Agency' ) ? PRSTUDIO_Agency::status() : array();

		return array(
			'enterprise'  => true,
			'product_name' => 'PR STUDIO AI BRIDGE',
			'version'      => WPAIB_VERSION,
			'endpoint'     => WPAIB_Auth::mcp_url(),
			'features'     => array(
				'coordination_locks'    => true,
				'persistent_state'      => true,
				'audit_read'            => true,
				'taxonomies'            => true,
				'rank_math_meta'         => defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\Helper' ),
				'media_metadata'        => true,
				'woocommerce_crud'                => function_exists( 'wc_get_product' ),
				'hpos_safe_orders'                => function_exists( 'wc_get_orders' ),
				'search_console_provider'         => 'prstudio-browser-agent-same-profile',
				'google_search_console_connected' => ! empty( PRSTUDIO_UC_Search_Console_Browser::status()['connected'] ),
				'google_login_managed_by_user'    => true,
				'native_fallbacks'                => true,
				'change_email_reports'  => ! empty( WPAIB_Auth::settings()['report_enabled'] ),
			),
			'limits' => array(
				'max_batch_items' => 100,
				'max_lock_ttl'    => 3600,
				'max_state_bytes' => 1048576,
			),
			'agency' => $agency,
		);
	}

	public static function audit_log( array $args ): array {
		return WPAIB_Audit::recent(
			max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) ),
			sanitize_text_field( (string) ( $args['action_prefix'] ?? '' ) )
		);
	}

	public static function work_lock( array $args ) {
		$action = sanitize_key( (string) ( $args['action'] ?? 'status' ) );
		$key    = self::clean_key( (string) ( $args['key'] ?? 'global-write' ) );
		$owner  = self::clean_key( (string) ( $args['owner'] ?? WPAIB_Auth::current_token_id() ) );
		$now    = time();

		$locks = get_option( self::LOCK_OPTION, array() );
		$locks = is_array( $locks ) ? $locks : array();

		foreach ( $locks as $k => $lock ) {
			if ( ! is_array( $lock ) || (int) ( $lock['expires_at'] ?? 0 ) <= $now ) {
				unset( $locks[ $k ] );
			}
		}

		$current = $locks[ $key ] ?? null;

		if ( 'status' === $action ) {
			return array(
				'key'    => $key,
				'locked' => is_array( $current ),
				'lock'   => is_array( $current )
					? array_diff_key( $current, array( 'token' => true ) )
					: null,
			);
		}

		$write = self::write_allowed();

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		if ( 'acquire' === $action ) {
			$ttl = max( 30, min( 3600, (int) ( $args['ttl'] ?? 600 ) ) );

			if ( is_array( $current ) && (string) ( $current['owner'] ?? '' ) !== $owner ) {
				return new WP_Error(
					'wpaib_lock_busy',
					'Risorsa occupata da un altro reparto.',
					array(
						'status' => 409,
						'lock'   => array_diff_key( $current, array( 'token' => true ) ),
					)
				);
			}

			$token = wp_generate_password( 40, false, false );

			$locks[ $key ] = array(
				'owner'       => $owner,
				'token'       => $token,
				'acquired_at' => $now,
				'expires_at'  => $now + $ttl,
			);

			update_option( self::LOCK_OPTION, $locks, false );

			WPAIB_Audit::log(
				'enterprise.lock.acquire',
				'success',
				$key,
				array(
					'owner' => $owner,
					'ttl'   => $ttl,
				)
			);

			return array(
				'key'    => $key,
				'locked' => true,
				'lock'   => $locks[ $key ],
			);
		}

		if ( 'release' === $action ) {
			if ( ! is_array( $current ) ) {
				return array(
					'key'        => $key,
					'released'   => true,
					'was_locked' => false,
				);
			}

			$token = (string) ( $args['token'] ?? '' );

			if ( '' !== $token && ! hash_equals( (string) $current['token'], $token ) ) {
				return new WP_Error(
					'wpaib_lock_token_invalid',
					'Token lock non valido.',
					array( 'status' => 409 )
				);
			}

			if ( '' === $token && (string) $current['owner'] !== $owner ) {
				return new WP_Error(
					'wpaib_lock_owner_invalid',
					'Lock appartenente a un altro reparto.',
					array( 'status' => 409 )
				);
			}

			unset( $locks[ $key ] );
			update_option( self::LOCK_OPTION, $locks, false );

			WPAIB_Audit::log(
				'enterprise.lock.release',
				'success',
				$key,
				array( 'owner' => $owner )
			);

			return array(
				'key'        => $key,
				'released'   => true,
				'was_locked' => true,
			);
		}

		return new WP_Error(
			'wpaib_lock_action_invalid',
			'Azione lock non valida.',
			array( 'status' => 400 )
		);
	}

	public static function work_state( array $args ) {
		$key    = self::clean_key( (string) ( $args['key'] ?? '' ) );
		$action = sanitize_key( (string) ( $args['action'] ?? 'get' ) );

		if ( '' === $key ) {
			return new WP_Error(
				'wpaib_state_key_required',
				'Chiave stato obbligatoria.',
				array( 'status' => 400 )
			);
		}

		$all     = get_option( self::STATE_OPTION, array() );
		$all     = is_array( $all ) ? $all : array();
		$current = is_array( $all[ $key ] ?? null )
			? $all[ $key ]
			: array(
				'version'    => 0,
				'data'       => null,
				'updated_at' => null,
			);

		if ( 'get' === $action ) {
			return array(
				'key'   => $key,
				'state' => self::redact( $current ),
			);
		}

		$write = self::write_allowed();

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$expected = array_key_exists( 'expected_version', $args )
			? (int) $args['expected_version']
			: null;

		if ( null !== $expected && (int) $current['version'] !== $expected ) {
			return new WP_Error(
				'wpaib_state_version_conflict',
				'Versione stato non corrispondente.',
				array(
					'status'  => 409,
					'current' => $current,
				)
			);
		}

		$data = self::json_safe( $args['data'] ?? null );

		if ( 'append' === $action ) {
			$items   = is_array( $current['data'] ) ? $current['data'] : array();
			$items[] = $data;
			$data    = array_slice( $items, -1000 );
		} elseif ( 'merge' === $action ) {
			$base = is_array( $current['data'] ) ? $current['data'] : array();
			$data = array_replace_recursive(
				$base,
				is_array( $data ) ? $data : array()
			);
		} elseif ( 'set' !== $action ) {
			return new WP_Error(
				'wpaib_state_action_invalid',
				'Azione stato non valida.',
				array( 'status' => 400 )
			);
		}

		$encoded = wp_json_encode( $data );

		if ( false === $encoded || strlen( $encoded ) > 1048576 ) {
			return new WP_Error(
				'wpaib_state_too_large',
				'Stato oltre il limite.',
				array( 'status' => 413 )
			);
		}

		$record = array(
			'version'    => (int) $current['version'] + 1,
			'data'       => $data,
			'updated_at' => current_time( 'mysql', true ),
		);

		$all[ $key ] = $record;
		update_option( self::STATE_OPTION, $all, false );

		WPAIB_Audit::log(
			'enterprise.state.' . $action,
			'success',
			$key,
			array( 'version' => $record['version'] )
		);

		return array(
			'key'   => $key,
			'state' => $record,
		);
	}

	public static function taxonomies( array $args ): array {
		$object_type = sanitize_key( (string) ( $args['object_type'] ?? '' ) );
		$items       = array();

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( $object_type && ! in_array( $object_type, (array) $taxonomy->object_type, true ) ) {
				continue;
			}

			$items[] = array(
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->label,
				'object_types' => array_values( (array) $taxonomy->object_type ),
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'public'       => (bool) $taxonomy->public,
				'show_ui'      => (bool) $taxonomy->show_ui,
				'rest_base'    => $taxonomy->rest_base,
			);
		}

		return array(
			'items' => $items,
			'count' => count( $items ),
		);
	}

	public static function terms( array $args ) {
		$taxonomy = sanitize_key( (string) ( $args['taxonomy'] ?? '' ) );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'wpaib_taxonomy_invalid',
				'Tassonomia non valida.',
				array( 'status' => 400 )
			);
		}

		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per   = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$query = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => (bool) ( $args['hide_empty'] ?? false ),
			'number'     => $per,
			'offset'     => ( $page - 1 ) * $per,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query['search'] = sanitize_text_field( (string) $args['search'] );
		}

		$terms = get_terms( $query );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();

		foreach ( $terms as $term ) {
			$items[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
				'link'        => get_term_link( $term ),
			);
		}

		$count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => (bool) $query['hide_empty'],
			)
		);

		return array(
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per,
			'total'    => is_wp_error( $count ) ? null : (int) $count,
		);
	}

	public static function upsert_term( array $args ) {
		$write = self::write_allowed();

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$taxonomy = sanitize_key( (string) ( $args['taxonomy'] ?? '' ) );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'wpaib_taxonomy_invalid',
				'Tassonomia non valida.',
				array( 'status' => 400 )
			);
		}

		$id   = absint( $args['id'] ?? 0 );
		$data = array();

		foreach ( array( 'name', 'slug', 'description' ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$data[ $field ] = 'description' === $field
					? wp_kses_post( (string) $args[ $field ] )
					: sanitize_text_field( (string) $args[ $field ] );
			}
		}

		if ( array_key_exists( 'parent', $args ) ) {
			$data['parent'] = absint( $args['parent'] );
		}

		if ( $id ) {
			$before_obj = get_term( $id, $taxonomy );

			if ( ! $before_obj || is_wp_error( $before_obj ) ) {
				return new WP_Error(
					'wpaib_term_missing',
					'Termine non trovato.',
					array( 'status' => 404 )
				);
			}

			$before = $before_obj->to_array();
			$result = wp_update_term( $id, $taxonomy, $data );
		} else {
			if ( empty( $data['name'] ) ) {
				return new WP_Error(
					'wpaib_term_name_required',
					'Nome termine obbligatorio.',
					array( 'status' => 400 )
				);
			}

			$before = null;
			$name   = $data['name'];

			unset( $data['name'] );

			$result = wp_insert_term( $name, $taxonomy, $data );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];
		$after   = get_term( $term_id, $taxonomy )->to_array();

		WPAIB_Audit::log(
			$id ? 'enterprise.term.update' : 'enterprise.term.create',
			'success',
			$taxonomy . ':' . $term_id,
			array(
				'before' => $before,
				'after'  => $after,
				'readback_available' => is_array( $after ),
			)
		);

		PRSTUDIO_Report::record_change(
			'Tassonomia',
			$taxonomy . ':' . $term_id,
			$before,
			$after
		);

		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	public static function assign_terms( array $args ) {
		$write = self::write_allowed();

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$object_id = absint( $args['object_id'] ?? 0 );
		$taxonomy  = sanitize_key( (string) ( $args['taxonomy'] ?? '' ) );
		$terms     = array_map(
			'absint',
			is_array( $args['term_ids'] ?? null ) ? $args['term_ids'] : array()
		);

		if ( ! get_post( $object_id ) || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'wpaib_assignment_invalid',
				'Oggetto o tassonomia non validi.',
				array( 'status' => 400 )
			);
		}

		$before = wp_get_object_terms(
			$object_id,
			$taxonomy,
			array( 'fields' => 'ids' )
		);

		$result = wp_set_object_terms(
			$object_id,
			$terms,
			$taxonomy,
			(bool) ( $args['append'] ?? false )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after = wp_get_object_terms(
			$object_id,
			$taxonomy,
			array( 'fields' => 'ids' )
		);

		WPAIB_Audit::log(
			'enterprise.terms.assign',
			'success',
			$taxonomy . ':' . $object_id,
			array(
				'before' => $before,
				'after'  => $after,
				'readback_available' => is_array( $after ),
			)
		);

		PRSTUDIO_Report::record_change(
			'Assegnazione termini',
			$taxonomy . ':' . $object_id,
			$before,
			$after
		);

		return array(
			'object_id' => $object_id,
			'taxonomy'  => $taxonomy,
			'before'    => $before,
			'after'     => $after,
		);
	}

	private static function meta_key_allowed( string $key ): bool {
		if ( self::sensitive_key( $key ) ) {
			return false;
		}

		$prefixes = array(
			'rank_math_',
			'wpaib_',
			'pr_studio_',
			'_yoast_wpseo_',
			'_aioseo_',
		);

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return in_array(
			$key,
			array(
				'_wp_attachment_image_alt',
				'_thumbnail_id',
				'_product_image_gallery',
				'_global_unique_id',
				'_crosssell_ids',
				'_upsell_ids',
			),
			true
		);
	}

	private static function meta_get( string $type, int $id, string $key ) {
		return 'term' === $type
			? get_term_meta( $id, $key, true )
			: get_post_meta( $id, $key, true );
	}

	public static function rank_math_sitemap_invalidate( array $args ) {
		$type = sanitize_key( (string) ( $args['object_type'] ?? 'post' ) );
		$id   = absint( $args['object_id'] ?? 0 );

		if ( ! in_array( $type, array( 'post', 'term', 'user' ), true ) || ! $id ) {
			return new WP_Error(
				'wpaib_sitemap_object_invalid',
				'Oggetto sitemap non valido.',
				array( 'status' => 400 )
			);
		}

		if ( 'post' === $type && ! get_post( $id ) ) {
			return new WP_Error( 'wpaib_sitemap_post_missing', 'Contenuto non trovato.', array( 'status' => 404 ) );
		}
		if ( 'term' === $type ) {
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error( 'wpaib_sitemap_term_missing', 'Termine non trovato.', array( 'status' => 404 ) );
			}
		}
		if ( 'user' === $type && ! get_user_by( 'id', $id ) ) {
			return new WP_Error( 'wpaib_sitemap_user_missing', 'Utente non trovato.', array( 'status' => 404 ) );
		}

		do_action( 'rank_math/sitemap/invalidate_object_type', $type, $id );

		if ( class_exists( '\\RankMath\\Sitemap\\Cache_Watcher' ) ) {
			\RankMath\Sitemap\Cache_Watcher::clear_queued();
		}

		return array(
			'invalidated' => true,
			'provider'    => 'rank_math',
			'object_type' => $type,
			'object_id'   => $id,
		);
	}

	public static function get_object_meta( array $args ) {
		$type = sanitize_key( (string) ( $args['object_type'] ?? 'post' ) );
		$id   = absint( $args['object_id'] ?? 0 );

		if ( ! in_array( $type, array( 'post', 'term' ), true ) || ! $id ) {
			return new WP_Error(
				'wpaib_meta_object_invalid',
				'Oggetto meta non valido.',
				array( 'status' => 400 )
			);
		}

		$keys = is_array( $args['keys'] ?? null )
			? array_map( 'sanitize_key', $args['keys'] )
			: array();

		if ( ! $keys ) {
			$all  = 'term' === $type ? get_term_meta( $id ) : get_post_meta( $id );
			$keys = array_keys( is_array( $all ) ? $all : array() );
		}

		$values = array();

		foreach ( $keys as $key ) {
			if ( self::meta_key_allowed( $key ) ) {
				$values[ $key ] = self::meta_get( $type, $id, $key );
			}
		}

		return array(
			'object_type' => $type,
			'object_id'   => $id,
			'meta'        => $values,
		);
	}

	private static function seo_description_guard( int $post_id, string $proposed ): array {
		$clean = static function ( string $text ): string {
			$text = wp_strip_all_tags( strip_shortcodes( $text ) );
			return trim( preg_replace( '/\\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		};
		$score = static function ( string $text ): int {
			$words = preg_split( '/\\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
			$abrupt = '' !== $text && ( preg_match( '/(?:…|\\.\\.\\.|[,;:\\/-]|\\b(?:e|ed|di|del|della|con|per|a|da|in|su|che|un|una))$/iu', $text ) || count( $words ) < 6 );
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
			return min( 200, count( $words ) * 4 ) + min( 80, (int) floor( $length / 4 ) ) + ( $abrupt ? -100 : 30 );
		};
		$proposed_clean = $clean( $proposed );
		$fallbacks = array();
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) { $fallbacks[] = $clean( (string) $product->get_short_description() ); $fallbacks[] = $clean( (string) $product->get_description() ); }
		}
		$fallbacks[] = $clean( (string) get_post_field( 'post_excerpt', $post_id ) );
		$fallbacks[] = $clean( (string) get_post_field( 'post_content', $post_id ) );
		$fallbacks = array_values( array_filter( array_unique( $fallbacks ) ) );
		$best_fallback = '';
		foreach ( $fallbacks as $candidate ) { if ( $score( $candidate ) > $score( $best_fallback ) ) { $best_fallback = $candidate; } }
		$selected = $proposed_clean;
		$source = 'proposed';
		if ( $best_fallback && $score( $proposed_clean ) < $score( $best_fallback ) ) { $selected = $best_fallback; $source = 'deterministic_content_fallback_promoted_to_override'; }
		return array( 'value' => $selected, 'source' => $source, 'proposed_score' => $score( $proposed_clean ), 'fallback_score' => $score( $best_fallback ), 'selected_score' => $score( $selected ), 'truncation_guard' => true );
	}

	public static function update_object_meta( array $args ) {

		$type   = sanitize_key( (string) ( $args['object_type'] ?? 'post' ) );
		$id     = absint( $args['object_id'] ?? 0 );
		$key    = sanitize_key( (string) ( $args['key'] ?? '' ) );
		$action = sanitize_key( (string) ( $args['action'] ?? 'set' ) );
		if ( ! in_array( $type, array( 'post', 'term' ), true ) || ! $id || ! self::meta_key_allowed( $key ) ) {
			return new WP_Error( 'wpaib_meta_update_invalid', 'Oggetto o chiave meta non consentiti.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $action, array( 'set', 'delete' ), true ) ) {
			return new WP_Error( 'wpaib_meta_action_invalid', 'Azione meta non valida.', array( 'status' => 400 ) );
		}

		/* WooCommerce owns the global unique ID through WC_Product CRUD. Writing the
		 * private meta key directly can leave object/data-store caches inconsistent. */
		if ( 'post' === $type && '_global_unique_id' === $key && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $id );
			if ( $product && method_exists( $product, 'get_global_unique_id' ) && method_exists( $product, 'set_global_unique_id' ) ) {
				$before = (string) $product->get_global_unique_id();
				if ( array_key_exists( 'expected_before', $args ) && ! self::expected_matches( $args['expected_before'], $before ) ) {
					return new WP_Error( 'wpaib_meta_conflict', 'Global Unique ID cambiato.', array( 'status' => 409, 'current' => $before ) );
				}
				$requested = 'delete' === $action ? '' : sanitize_text_field( (string) ( $args['value'] ?? '' ) );
				if ( '' !== $requested && $requested !== $before && function_exists( 'wc_product_has_global_unique_id' ) && ! wc_product_has_global_unique_id( $id, $requested ) ) {
					return new WP_Error( 'wpaib_global_unique_id_conflict', 'Global Unique ID già assegnato a un altro prodotto.', array( 'status' => 409, 'requested' => $requested ) );
				}
				try {
					$product->set_global_unique_id( $requested );
					$product->save();
				} catch ( Throwable $e ) {
					return new WP_Error( 'wpaib_global_unique_id_save_failed', $e->getMessage(), array( 'status' => 500 ) );
				}
				if ( function_exists( 'wc_delete_product_transients' ) ) { wc_delete_product_transients( $id ); }
				clean_post_cache( $id );
				$fresh = wc_get_product( $id );
				$after = $fresh && method_exists( $fresh, 'get_global_unique_id' ) ? (string) $fresh->get_global_unique_id() : null;
				$verified = is_string( $after ) && hash_equals( hash( 'sha256', $requested ), hash( 'sha256', $after ) );
				$changed = $before !== $requested;
				return array(
					'object_type' => $type, 'object_id' => $id, 'key' => $key,
					'before' => $before, 'after' => $after, 'requested' => $requested,
					'persistence' => 'woocommerce_crud', 'requested_effect_verified' => $verified,
					'degraded'=>!$verified, 'blocking'=>false,
					'_control_outcome' => array( 'status' => $verified ? ( $changed ? 'completed' : 'verified' ) : 'degraded', 'executed' => true, 'mutated' => $changed, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false, 'reason' => $verified ? ( $changed ? '' : 'already_in_requested_state' ) : 'effect_unverified' ),
				);
			}
		}

		$before = self::meta_get( $type, $id, $key );
		if ( array_key_exists( 'expected_before', $args ) && ! self::expected_matches( $args['expected_before'], $before ) ) {
			return new WP_Error( 'wpaib_meta_conflict', 'Valore meta cambiato.', array( 'status' => 409, 'current' => $before ) );
		}
		$requested = null;
		$quality = null;
		if ( 'delete' === $action ) {
			$deleted = 'term' === $type ? delete_term_meta( $id, $key ) : delete_post_meta( $id, $key );
			$requested = '';
		} else {
			$requested = self::json_safe( $args['value'] ?? '' );
			if ( '_wp_attachment_image_alt' === $key ) { $requested = sanitize_text_field( (string) $requested ); }
			if ( 'rank_math_description' === $key && 'post' === $type ) {
				$guard = self::seo_description_guard( $id, (string) $requested );
				$requested = $guard['value'];
				$quality = $guard;
			}
			if ( 'term' === $type ) { update_term_meta( $id, $key, $requested ); }
			else { update_post_meta( $id, $key, $requested ); }
		}
		if ( 'post' === $type ) { clean_post_cache( $id ); }
		$after = self::meta_get( $type, $id, $key );
		$verified = 'delete' === $action
			? ( '' === $after || null === $after || array() === $after )
			: hash_equals( hash( 'sha256', wp_json_encode( self::json_safe( $requested ) ) ?: 'null' ), hash( 'sha256', wp_json_encode( self::json_safe( $after ) ) ?: 'null' ) );
		$sitemap = null;
		if ( 0 === strpos( $key, 'rank_math_' ) ) {
			$sitemap = self::rank_math_sitemap_invalidate( array( 'object_type' => $type, 'object_id' => $id ) );
		}
		WPAIB_Audit::log( 'enterprise.meta.' . $action, $verified ? 'success' : 'degraded', $type . ':' . $id . ':' . $key, array( 'before' => $before, 'after' => $after, 'verified' => $verified ) );
		PRSTUDIO_Report::record_change( 'Metadato', $type . ':' . $id . ':' . $key, $before, $after );
		$changed = 'delete' === $action ? !( '' === $before || null === $before || array() === $before ) : ! self::expected_matches( $requested, $before );
		return array(
			'object_type' => $type, 'object_id' => $id, 'key' => $key, 'before' => $before, 'after' => $after,
			'requested' => $requested, 'requested_effect_verified' => $verified, 'persistence' => 'wordpress_metadata_api',
			'quality_guard' => $quality, 'sitemap' => is_wp_error( $sitemap ) ? null : $sitemap, 'degraded'=>!$verified, 'blocking'=>false,
			'_control_outcome' => array( 'status' => $verified ? ( $changed ? 'completed' : 'verified' ) : 'degraded', 'executed' => true, 'mutated' => $changed, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false, 'reason' => $verified ? ( $changed ? '' : 'already_in_requested_state' ) : 'effect_unverified' ),
		);
	}

	private static function media_item( int $id ) {
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error(
				'wpaib_media_missing',
				'Allegato non trovato.',
				array( 'status' => 404 )
			);
		}

		return array(
			'id'           => $id,
			'title'        => $post->post_title,
			'caption'      => $post->post_excerpt,
			'description'  => $post->post_content,
			'alt'          => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'mime_type'    => $post->post_mime_type,
			'url'          => wp_get_attachment_url( $id ),
			'file'         => get_attached_file( $id ),
			'metadata'     => wp_get_attachment_metadata( $id ),
			'parent'       => (int) $post->post_parent,
			'modified_gmt' => get_post_modified_time( DATE_ATOM, true, $post ),
		);
	}

	public static function list_media( array $args ): array {
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per  = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => sanitize_text_field( (string) ( $args['mime_type'] ?? 'image' ) ),
				'paged'          => $page,
				'posts_per_page' => $per,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				's'              => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			$item = self::media_item( (int) $post->ID );

			if ( ! is_wp_error( $item ) ) {
				$items[] = $item;
			}
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	public static function get_media( array $args ) {
		return self::media_item( absint( $args['id'] ?? 0 ) );
	}

	public static function update_media( array $args ) {
		$write = self::write_allowed();

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$id     = absint( $args['id'] ?? 0 );
		$before = self::media_item( $id );

		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if (
			! empty( $args['expected_modified_gmt'] )
			&& (string) $args['expected_modified_gmt'] !== (string) $before['modified_gmt']
		) {
			return new WP_Error(
				'wpaib_media_conflict',
				'Allegato modificato nel frattempo.',
				array(
					'status'  => 409,
					'current' => $before,
				)
			);
		}

		$payload = array( 'ID' => $id );

		foreach (
			array(
				'title'       => 'post_title',
				'caption'     => 'post_excerpt',
				'description' => 'post_content',
			) as $input => $field
		) {
			if ( array_key_exists( $input, $args ) ) {
				$payload[ $field ] = 'description' === $input
					? wp_kses_post( (string) $args[ $input ] )
					: sanitize_text_field( (string) $args[ $input ] );
			}
		}

		if ( count( $payload ) > 1 ) {
			$result = wp_update_post( wp_slash( $payload ), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'alt', $args ) ) {
			update_post_meta(
				$id,
				'_wp_attachment_image_alt',
				sanitize_text_field( (string) $args['alt'] )
			);
		}

		$after = self::media_item( $id );

		WPAIB_Audit::log(
			'enterprise.media.update',
			'success',
			(string) $id,
			array(
				'before' => $before,
				'after'  => $after,
				'readback_available' => is_array( $after ),
			)
		);

		PRSTUDIO_Report::record_change(
			'Media',
			(string) $id,
			$before,
			$after,
			array( 'url' => $after['url'] ?? '' )
		);

		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	private static function require_wc() {
		return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_products' )
			? true
			: new WP_Error(
				'wpaib_woocommerce_unavailable',
				'WooCommerce non disponibile.',
				array( 'status' => 503 )
			);
	}

	private static function product_item( $product ): array {
		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			$attributes[] = array(
				'id'        => $attribute->get_id(),
				'name'      => $attribute->get_name(),
				'options'   => $attribute->get_options(),
				'position'  => $attribute->get_position(),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
				'taxonomy'  => $attribute->is_taxonomy(),
			);
		}

		return array(
			'id'                => $product->get_id(),
			'type'              => $product->get_type(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'status'            => $product->get_status(),
			'featured'          => $product->get_featured(),
			'catalog_visibility'=> $product->get_catalog_visibility(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'sku'               => $product->get_sku(),
			'global_unique_id'  => method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id() : (string) get_post_meta( $product->get_id(), '_global_unique_id', true ),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'manage_stock'      => $product->get_manage_stock(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'stock_status'      => $product->get_stock_status(),
			'backorders'        => $product->get_backorders(),
			'sold_individually' => $product->get_sold_individually(),
			'weight'            => $product->get_weight(),
			'length'            => $product->get_length(),
			'width'             => $product->get_width(),
			'height'            => $product->get_height(),
			'tax_status'        => $product->get_tax_status(),
			'tax_class'         => $product->get_tax_class(),
			'shipping_class_id' => $product->get_shipping_class_id(),
			'category_ids'      => $product->get_category_ids(),
			'tag_ids'           => $product->get_tag_ids(),
			'image_id'          => $product->get_image_id(),
			'gallery_image_ids' => $product->get_gallery_image_ids(),
			'attributes'        => $attributes,
			'permalink'         => $product->get_permalink(),
			'date_modified_gmt' => $product->get_date_modified()
				? $product->get_date_modified()->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM )
				: null,
		);
	}

	public static function list_products( array $args ) {
		$ready = self::require_wc();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );

		$query = array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'modified',
			'order'    => 'DESC',
			'return'   => 'objects',
		);

		foreach ( array( 'status', 'sku', 'stock_status', 'type' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$query[ $key ] = sanitize_text_field( (string) $args[ $key ] );
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( (string) $args['search'] );
		}

		$result = wc_get_products( $query );

		return array(
			'items'       => array_map( array( self::class, 'product_item' ), $result->products ),
			'page'        => $page,
			'per_page'    => $limit,
			'total'       => (int) $result->total,
			'total_pages' => (int) $result->max_num_pages,
		);
	}

	public static function get_product( array $args ) {
		$ready = self::require_wc();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$product = wc_get_product( absint( $args['id'] ?? 0 ) );

		return $product
			? self::product_item( $product )
			: new WP_Error(
				'wpaib_product_missing',
				'Prodotto non trovato.',
				array( 'status' => 404 )
			);
	}

	private static function build_attributes( array $input ) {
		$out = array();
		$seen = array();
		if ( count( $input ) > 100 ) { return new WP_Error( 'wpaib_product_attributes_limit', 'Massimo 100 attributi per prodotto.', array( 'status' => 400 ) ); }
		foreach ( $input as $index => $item ) {
			if ( ! is_array( $item ) || ! class_exists( 'WC_Product_Attribute' ) ) { return new WP_Error( 'wpaib_product_attribute_invalid', 'Attributo prodotto non valido.', array( 'status' => 400, 'index' => $index ) ); }
			$attribute = new WC_Product_Attribute();
			$id = absint( $item['id'] ?? 0 );
			$name = $id ? wc_attribute_taxonomy_name_by_id( $id ) : sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			if ( ! $name ) { return new WP_Error( 'wpaib_product_attribute_name_required', 'Ogni attributo richiede id tassonomia valido oppure name.', array( 'status' => 400, 'index' => $index ) ); }
			$key = strtolower( (string) $name );
			if ( isset( $seen[ $key ] ) ) { return new WP_Error( 'wpaib_product_attribute_duplicate', 'Attributo duplicato nel payload.', array( 'status' => 400, 'index' => $index, 'name' => $name ) ); }
			$seen[ $key ] = true;
			$options = is_array( $item['options'] ?? null ) ? array_values( array_unique( array_map( static function ( $value ) { return is_numeric( $value ) ? (int) $value : sanitize_text_field( (string) $value ); }, $item['options'] ) ) ) : array();
			$attribute->set_id( $id ); $attribute->set_name( $name ); $attribute->set_options( $options );
			$attribute->set_position( max( 0, (int) ( $item['position'] ?? $index ) ) );
			$attribute->set_visible( array_key_exists( 'visible', $item ) ? (bool) $item['visible'] : true );
			$attribute->set_variation( (bool) ( $item['variation'] ?? false ) );
			$out[] = $attribute;
		}
		return $out;
	}

	public static function update_product( array $args ) {
		$write = self::write_allowed();

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$ready = self::require_wc();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$product = wc_get_product( absint( $args['id'] ?? 0 ) );

		if ( ! $product ) {
			return new WP_Error(
				'wpaib_product_missing',
				'Prodotto non trovato.',
				array( 'status' => 404 )
			);
		}

		$before = self::product_item( $product );

		if (
			! empty( $args['expected_modified_gmt'] )
			&& (string) $args['expected_modified_gmt'] !== (string) $before['date_modified_gmt']
		) {
			return new WP_Error(
				'wpaib_product_conflict',
				'Prodotto modificato nel frattempo.',
				array(
					'status'  => 409,
					'current' => $before,
				)
			);
		}

		/* Validate Global Unique ID before entering the generic setter loop. WooCommerce
		 * exposes this as first-class product data (since 9.1), not as arbitrary meta. */
		if ( array_key_exists( 'global_unique_id', $args ) && method_exists( $product, 'get_global_unique_id' ) ) {
			$requested_global_id = sanitize_text_field( (string) $args['global_unique_id'] );
			$current_global_id = (string) $product->get_global_unique_id();
			if ( '' !== $requested_global_id && $requested_global_id !== $current_global_id && function_exists( 'wc_product_has_global_unique_id' ) && ! wc_product_has_global_unique_id( $product->get_id(), $requested_global_id ) ) {
				return new WP_Error( 'wpaib_global_unique_id_conflict', 'Global Unique ID già assegnato a un altro prodotto.', array( 'status' => 409, 'requested' => $requested_global_id ) );
			}
			$args['global_unique_id'] = $requested_global_id;
		}

		$string_setters = array(
			'name'              => 'set_name',
			'slug'              => 'set_slug',
			'status'            => 'set_status',
			'catalog_visibility'=> 'set_catalog_visibility',
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
			'sku'               => 'set_sku',
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'stock_status'      => 'set_stock_status',
			'backorders'        => 'set_backorders',
			'weight'            => 'set_weight',
			'length'            => 'set_length',
			'width'             => 'set_width',
			'height'            => 'set_height',
			'tax_status'        => 'set_tax_status',
			'tax_class'         => 'set_tax_class',
			'purchase_note'     => 'set_purchase_note',
			'global_unique_id'  => 'set_global_unique_id',
		);

		foreach ( $string_setters as $field => $method ) {
			if ( ! array_key_exists( $field, $args ) ) {
				continue;
			}

			$value = in_array(
				$field,
				array( 'description', 'short_description', 'purchase_note' ),
				true
			)
				? wp_kses_post( (string) $args[ $field ] )
				: (string) $args[ $field ];

			$product->{$method}( $value );
		}

		foreach (
			array(
				'featured'          => 'set_featured',
				'manage_stock'      => 'set_manage_stock',
				'sold_individually' => 'set_sold_individually',
			) as $field => $method
		) {
			if ( array_key_exists( $field, $args ) ) {
				$product->{$method}( (bool) $args[ $field ] );
			}
		}

		foreach (
			array(
				'stock_quantity'   => 'set_stock_quantity',
				'shipping_class_id'=> 'set_shipping_class_id',
				'menu_order'       => 'set_menu_order',
				'image_id'         => 'set_image_id',
			) as $field => $method
		) {
			if ( array_key_exists( $field, $args ) ) {
				$value = null === $args[ $field ] && 'stock_quantity' === $field
					? null
					: (int) $args[ $field ];

				$product->{$method}( $value );
			}
		}

		foreach (
			array(
				'category_ids'      => 'set_category_ids',
				'tag_ids'           => 'set_tag_ids',
				'gallery_image_ids' => 'set_gallery_image_ids',
			) as $field => $method
		) {
			if ( array_key_exists( $field, $args ) && is_array( $args[ $field ] ) ) {
				$product->{$method}( array_map( 'absint', $args[ $field ] ) );
			}
		}

		$built_attributes = null;
		if ( array_key_exists( 'attributes', $args ) && is_array( $args['attributes'] ) ) {
			$built_attributes = self::build_attributes( $args['attributes'] );
			if ( is_wp_error( $built_attributes ) ) { return $built_attributes; }
			$product->set_attributes( $built_attributes );
		}

		foreach ( array( 'date_on_sale_from', 'date_on_sale_to' ) as $field ) {
			if ( ! array_key_exists( $field, $args ) ) {
				continue;
			}

			$method = 'date_on_sale_from' === $field
				? 'set_date_on_sale_from'
				: 'set_date_on_sale_to';

			$product->{$method}(
				$args[ $field ]
					? wc_string_to_datetime( (string) $args[ $field ] )
					: null
			);
		}

		try {
			$product->save();
		} catch ( Throwable $e ) {
			return new WP_Error(
				'wpaib_product_save_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		/* Product save clears WooCommerce's product instance cache in current WooCommerce,
		 * but explicitly clear public caches too before the verification read. This protects
		 * installations with persistent object caches and older WooCommerce data stores. */
		if ( function_exists( 'wc_delete_product_transients' ) ) { wc_delete_product_transients( $product->get_id() ); }
		if ( function_exists( 'clean_post_cache' ) ) { clean_post_cache( $product->get_id() ); }
		if ( class_exists( 'WC_Cache_Helper' ) && is_callable( array( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) ) { WC_Cache_Helper::invalidate_cache_group( 'product_' . $product->get_id() ); }
		$fresh_product = wc_get_product( $product->get_id() );
		$after = $fresh_product ? self::product_item( $fresh_product ) : null;

		WPAIB_Audit::log(
			'enterprise.product.update',
			'success',
			(string) $product->get_id(),
			array(
				'before' => $before,
				'after'  => $after,
				'readback_available' => is_array( $after ),
			)
		);

		PRSTUDIO_Report::record_change(
			'Prodotto',
			(string) $product->get_id(),
			$before,
			$after,
			array( 'url' => is_array( $after ) ? ( $after['permalink'] ?? '' ) : '' )
		);

		$verification_request = $args;
		if ( is_array( $built_attributes ) ) {
			/* Verify against the normalized objects actually submitted to WooCommerce.
			 * This avoids false mismatches for taxonomy attributes supplied by ID only. */
			$verification_request['attributes'] = self::attribute_objects_to_rows( $built_attributes );
		}
		$verification = is_array( $after ) ? self::verify_product_effect( $verification_request, $after ) : array( 'verified'=>false, 'verified_fields'=>array(), 'mismatches'=>array( array( 'field'=>'readback', 'reason'=>'unavailable' ) ) );
		$verified = ! empty( $verification['verified'] );
		$changed = true;
		return array(
			'before' => $before, 'after' => $after, 'requested_effect_verified' => $verified, 'verified_fields' => $verification['verified_fields'], 'mismatches'=>$verification['mismatches'], 'degraded'=>!$verified, 'blocking'=>false,
			'_control_outcome' => array( 'status' => $verified ? 'completed' : 'degraded', 'executed' => true, 'mutated' => $changed, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false, 'reason' => $verified ? '' : 'effect_unverified' ),
		);
	}

	private static function normalize_attribute_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$out[] = array( 'id' => absint( $row['id'] ?? 0 ), 'name' => (string) ( $row['name'] ?? '' ), 'options' => array_values( (array) ( $row['options'] ?? array() ) ), 'position' => (int) ( $row['position'] ?? 0 ), 'visible' => array_key_exists( 'visible', $row ) ? (bool) $row['visible'] : true, 'variation' => (bool) ( $row['variation'] ?? false ) );
		}
		usort( $out, static fn( $a, $b ) => ( $a['position'] <=> $b['position'] ) ?: strcmp( $a['name'], $b['name'] ) );
		return $out;
	}

	private static function attribute_objects_to_rows( array $attributes ): array {
		$rows = array();
		foreach ( $attributes as $attribute ) {
			if ( ! is_object( $attribute ) ) { continue; }
			$rows[] = array(
				'id' => method_exists( $attribute, 'get_id' ) ? (int) $attribute->get_id() : 0,
				'name' => method_exists( $attribute, 'get_name' ) ? (string) $attribute->get_name() : '',
				'options' => method_exists( $attribute, 'get_options' ) ? array_values( (array) $attribute->get_options() ) : array(),
				'position' => method_exists( $attribute, 'get_position' ) ? (int) $attribute->get_position() : 0,
				'visible' => method_exists( $attribute, 'get_visible' ) ? (bool) $attribute->get_visible() : true,
				'variation' => method_exists( $attribute, 'get_variation' ) ? (bool) $attribute->get_variation() : false,
			);
		}
		return self::normalize_attribute_rows( $rows );
	}

	private static function verify_product_effect( array $requested, array $after ): array {
		$verified_fields = array(); $mismatches = array();
		$direct = array( 'name','slug','status','catalog_visibility','description','short_description','sku','global_unique_id','regular_price','sale_price','stock_status','backorders','weight','length','width','height','tax_status','tax_class','featured','manage_stock','stock_quantity','shipping_class_id','image_id','category_ids','tag_ids','gallery_image_ids','sold_individually' );
		foreach ( $direct as $field ) {
			if ( ! array_key_exists( $field, $requested ) ) { continue; }
			$want = $requested[ $field ]; $got = $after[ $field ] ?? null;
			if ( is_array( $want ) ) { $want = array_values( $want ); $got = array_values( (array) $got ); sort( $want ); sort( $got ); }
			if ( (string) wp_json_encode( $want ) !== (string) wp_json_encode( $got ) ) { $mismatches[ $field ] = array( 'requested' => $want, 'persisted' => $got ); }
			else { $verified_fields[] = $field; }
		}
		if ( array_key_exists( 'attributes', $requested ) ) {
			$want = self::normalize_attribute_rows( (array) $requested['attributes'] );
			$got = self::normalize_attribute_rows( (array) ( $after['attributes'] ?? array() ) );
			if ( wp_json_encode( $want ) !== wp_json_encode( $got ) ) { $mismatches['attributes'] = array( 'requested' => $want, 'persisted' => $got ); }
			else { $verified_fields[] = 'attributes'; }
		}
		return array( 'verified' => empty( $mismatches ), 'verified_fields' => $verified_fields, 'mismatches' => $mismatches );
	}

	public static function get_products_batch( array $args ) {
		$ready = self::require_wc(); if ( is_wp_error( $ready ) ) { return $ready; }
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['ids'] ?? $args['product_ids'] ?? array() ) ) ) ) );
		if ( ! $ids && ! empty( $args['id'] ) ) { $ids = array( absint( $args['id'] ) ); }
		if ( ! $ids ) { return array( 'items' => array(), 'count' => 0, 'missing_ids' => array(), 'batch' => array( 'local' => true, 'external_http_calls' => 0, 'chunk_size' => 100 ) ); }
		$found = array();
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			$products = wc_get_products( array( 'include' => $chunk, 'limit' => count( $chunk ), 'return' => 'objects' ) );
			foreach ( $products as $product ) { $found[ (int) $product->get_id() ] = self::product_item( $product ); }
		}
		$items = array(); $missing = array();
		foreach ( $ids as $id ) { if ( isset( $found[ $id ] ) ) { $items[] = $found[ $id ]; } else { $missing[] = $id; } }
		return array( 'items' => $items, 'count' => count( $items ), 'requested_count' => count( $ids ), 'missing_ids' => $missing, 'batch' => array( 'local' => true, 'external_http_calls' => 0, 'chunk_size' => 100, 'strategy' => 'woocommerce_crud_include_chunks' ) );
	}

	private static function resolve_product_ids( array $args, int $limit = 100 ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['product_ids'] ?? $args['ids'] ?? array() ) ) ) ) );
		if ( ! $ids && ! empty( $args['id'] ) ) { $ids[] = absint( $args['id'] ); }
		if ( ! $ids && ! empty( $args['product_id'] ) ) { $ids[] = absint( $args['product_id'] ); }
		if ( ! $ids && ! empty( $args['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) { $sku_id = wc_get_product_id_by_sku( sanitize_text_field( (string) $args['sku'] ) ); if ( $sku_id ) { $ids[] = absint( $sku_id ); } }
		if ( ! $ids && ( ! empty( $args['search'] ) || ! empty( $args['status'] ) ) && function_exists( 'wc_get_products' ) ) {
			$query = array( 'limit' => max( 1, min( 100, $limit ) ), 'return' => 'ids' );
			if ( ! empty( $args['search'] ) ) { $query['s'] = sanitize_text_field( (string) $args['search'] ); }
			if ( ! empty( $args['status'] ) ) { $query['status'] = sanitize_key( (string) $args['status'] ); }
			$ids = array_map( 'absint', wc_get_products( $query ) );
		}
		return array_values( array_slice( array_unique( array_filter( $ids ) ), 0, max( 1, min( 100, $limit ) ) ) );
	}

	public static function preview_product_attributes( array $args ) {
		$ids = self::resolve_product_ids( $args, (int) ( $args['limit'] ?? 100 ) );
		$has_attributes = array_key_exists( 'attributes', $args ) || ( is_array( $args['changes'] ?? null ) && array_key_exists( 'attributes', $args['changes'] ) );
		$attributes = $has_attributes ? (array) ( $args['attributes'] ?? $args['changes']['attributes'] ?? array() ) : array();
		$validated = $has_attributes ? self::build_attributes( $attributes ) : new WP_Error( 'wpaib_product_attributes_required', 'attributes deve essere presente; [] è valido e significa rimuovere tutti gli attributi.', array( 'status' => 400 ) );
		$normalized_proposed = is_wp_error( $validated ) ? array() : self::attribute_objects_to_rows( $validated );
		$validation = is_wp_error( $validated )
			? array( 'valid' => false, 'error' => $validated->get_error_message(), 'code' => $validated->get_error_code(), 'data' => $validated->get_error_data() )
			: array( 'valid' => true, 'normalized_count' => count( $normalized_proposed ), 'normalized' => $normalized_proposed );
		$current = $ids ? self::get_products_batch( array( 'ids' => $ids ) ) : array( 'items' => array(), 'count' => 0, 'missing_ids' => array() );
		$diffs = array();
		if ( ! is_wp_error( $current ) ) {
			foreach ( $current['items'] as $item ) {
				$before = self::normalize_attribute_rows( (array) $item['attributes'] );
				$diffs[] = array( 'product_id' => $item['id'], 'before' => $before, 'proposed' => $normalized_proposed, 'would_change' => $before !== $normalized_proposed );
			}
		}
		$would_change = count( array_filter( $diffs, static fn( $d ) => ! empty( $d['would_change'] ) ) );
		$missing_inputs = array_values( array_filter( array( $ids ? null : 'product_ids|id|sku|search/status', $has_attributes ? null : 'attributes' ) ) );
		return array(
			'preview' => true, 'contract_version' => '1.0.0', 'operation' => 'manage_product_attributes',
			'targets' => array( 'resolved_ids' => $ids, 'count' => count( $ids ), 'missing_ids' => is_array( $current ) ? (array) ( $current['missing_ids'] ?? array() ) : array(), 'resolution' => $ids ? 'id_or_filter_resolved' : 'target_selector_required_by_action_contract' ),
			'attributes' => array( 'present' => $has_attributes, 'provided_count' => count( $attributes ), 'validation' => $validation ),
			'diffs' => $diffs, 'would_change_count' => $would_change, 'missing_inputs' => $missing_inputs,
			'execution' => array(
				'provider' => 'woocommerce_crud', 'external_http_calls' => 0, 'batch_read_chunk_size' => 100, 'write_chunk_size' => 25,
				'atomicity' => 'compensating_chunk', 'rollback' => 'reverse_order_woocommerce_crud_plus_fresh_read_verification',
				'estimated_product_reads' => count( $ids ), 'estimated_product_writes' => $would_change,
				'failure_semantics' => 'stop_first_error_then_restore_all_prior_products_in_chunk',
			),
			'safety' => array( 'touches_only' => array( 'product.attributes' ), 'does_not_touch' => array( 'title','content','price','stock','categories','canonical','robots','open_graph' ) ),
			'action_contract' => array( 'target_selectors' => array( 'product_ids','ids','id','product_id','sku','search/status' ), 'attributes_required' => true, 'empty_attributes_means' => 'clear_all_attributes' ),
		);
	}

	public static function manage_product_attributes( array $args ) {
		$preview = self::preview_product_attributes( $args );
		if ( ! empty( $args['preview'] ) || ( is_array( $args['mutation'] ?? null ) && 'preview' === (string) ( $args['mutation']['mode'] ?? '' ) ) ) { return $preview; }
		if ( ! empty( $preview['missing_inputs'] ) ) { return new WP_Error( 'wpaib_product_attributes_contract_incomplete', 'Il contratto attributi non è completo.', array( 'status' => 400, 'preview' => $preview ) ); }
		if ( empty( $preview['attributes']['validation']['valid'] ) ) { return new WP_Error( 'wpaib_product_attributes_invalid', (string) $preview['attributes']['validation']['error'], array( 'status' => 400, 'preview' => $preview ) ); }
		$ids = (array) $preview['targets']['resolved_ids'];
		$attrs = array_key_exists( 'attributes', $args ) ? (array) $args['attributes'] : (array) ( $args['changes']['attributes'] ?? array() );
		$before_by_id = array(); foreach ( (array) $preview['diffs'] as $diff ) { $before_by_id[ (int) $diff['product_id'] ] = (array) $diff['before']; }
		$results = array(); $changed = 0; $chunks = 0;
		foreach ( array_chunk( $ids, 25 ) as $chunk ) {
			$chunks++; $applied = array();
			foreach ( $chunk as $id ) {
				$result = self::update_product( array( 'id' => $id, 'attributes' => $attrs ) );
				if ( is_wp_error( $result ) ) {
					$rollback = array(); $rollback_verified = true;
					foreach ( array_reverse( $applied ) as $applied_id ) {
						$restore = self::update_product( array( 'id' => $applied_id, 'attributes' => (array) ( $before_by_id[ $applied_id ] ?? array() ) ) );
						$ok = ! is_wp_error( $restore ) && ! empty( $restore['requested_effect_verified'] );
						$rollback[ $applied_id ] = $ok ? array( 'verified' => true ) : array( 'verified' => false, 'degraded'=>true, 'error' => is_wp_error( $restore ) ? $restore->get_error_code() : 'restore_effect_unverified' );
						$rollback_verified = $rollback_verified && $ok;
					}
					return new WP_Error(
						'wpaib_product_attributes_chunk_failed',
						'Aggiornamento attributi interrotto da un errore tecnico; il ripristino compensativo è riportato come evidenza.',
						array( 'status' => 500, 'failed_product_id' => $id, 'cause' => array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ), 'rollback_verified' => $rollback_verified, 'rollback_degraded'=>!$rollback_verified, 'rollback' => $rollback, 'preview' => $preview )
					);
				}
				$results[ $id ] = $result;
				if ( $result['before'] !== $result['after'] ) { $changed++; $applied[] = (int) $id; }
			}
		}
		return array( 'processed' => count( $results ), 'changed' => $changed, 'chunks' => $chunks, 'results' => $results, 'preview_contract' => $preview, 'requested_effect_verified' => true, 'rollback_strategy' => 'compensating_chunk', '_control_outcome' => array( 'status' => $changed ? 'completed' : 'verified', 'executed' => true, 'mutated' => $changed > 0, 'verified' => true ) );
	}

	public static function list_orders( array $args ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error(
				'wpaib_woocommerce_unavailable',
				'WooCommerce non disponibile.',
				array( 'status' => 503 )
			);
		}

		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );

		$query = array(
			'limit'    => $limit,
			'paged'    => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		foreach ( array( 'status', 'date_created', 'date_modified' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$query[ $key ] = is_array( $args[ $key ] )
					? array_map( 'sanitize_key', $args[ $key ] )
					: sanitize_text_field( (string) $args[ $key ] );
			}
		}

		$result = wc_get_orders( $query );
		$items  = array();

		foreach ( $result->orders as $order ) {
			$items[] = array(
				'id'               => $order->get_id(),
				'status'           => $order->get_status(),
				'currency'         => $order->get_currency(),
				'total'            => $order->get_total(),
				'date_created_gmt' => $order->get_date_created()
					? $order->get_date_created()->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM )
					: null,
				'item_count'       => $order->get_item_count(),
			);
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $limit,
			'total'       => (int) $result->total,
			'total_pages' => (int) $result->max_num_pages,
		);
	}

	public static function commerce_summary( array $args ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error(
				'wpaib_woocommerce_unavailable',
				'WooCommerce non disponibile.',
				array( 'status' => 503 )
			);
		}

		$after    = sanitize_text_field( (string) ( $args['after'] ?? gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ) ) );
		$before   = sanitize_text_field( (string) ( $args['before'] ?? gmdate( 'Y-m-d' ) ) );
		$statuses = is_array( $args['statuses'] ?? null )
			? array_map( 'sanitize_key', $args['statuses'] )
			: wc_get_is_paid_statuses();

		$page      = 1;
		$orders    = 0;
		$items     = 0;
		$revenue   = 0.0;
		$currency  = get_woocommerce_currency();
		$truncated = false;

		do {
			$result = wc_get_orders(
				array(
					'limit'        => 100,
					'paged'        => $page,
					'paginate'     => true,
					'status'       => $statuses,
					'date_created' => $after . '...' . $before,
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);

			foreach ( $result->orders as $order ) {
				++$orders;
				$items    += $order->get_item_count();
				$revenue += (float) $order->get_total();
				$currency = $order->get_currency() ?: $currency;
			}

			++$page;

			if ( $page > 50 && $page <= $result->max_num_pages ) {
				$truncated = true;
				break;
			}
		} while ( $page <= $result->max_num_pages );

		return array(
			'after'     => $after,
			'before'    => $before,
			'statuses'  => $statuses,
			'orders'    => $orders,
			'items'     => $items,
			'revenue'   => wc_format_decimal( $revenue, wc_get_price_decimals() ),
			'currency'  => $currency,
			'aov'       => $orders
				? wc_format_decimal( $revenue / $orders, wc_get_price_decimals() )
				: '0',
			'truncated' => $truncated,
		);
	}

	private static function rank_math_redirect_sources( array $args, array $current = array() ): array {
		$input = $args['sources'] ?? $args['source'] ?? $args['from'] ?? ( $current['sources'] ?? array() );
		if ( is_string( $input ) ) { $input = array( $input ); }
		$sources = array();

		foreach ( is_array( $input ) ? array_slice( $input, 0, 100 ) : array() as $source ) {
			if ( is_string( $source ) ) {
				$sources[] = array( 'pattern' => $source, 'comparison' => 'exact', 'ignore' => false );
				continue;
			}
			if ( ! is_array( $source ) || empty( $source['pattern'] ) ) { continue; }
			$comparison = sanitize_key( (string) ( $source['comparison'] ?? 'exact' ) );
			if ( ! in_array( $comparison, array( 'exact', 'contains', 'start', 'end', 'regex' ), true ) ) { $comparison = 'exact'; }
			$sources[] = array( 'pattern' => (string) $source['pattern'], 'comparison' => $comparison, 'ignore' => ! empty( $source['ignore'] ) );
		}

		return $sources;
	}

	private static function rank_math_redirect_item( $item ) {
		if ( ! is_array( $item ) ) { return $item; }
		if ( isset( $item['sources'] ) && ! is_array( $item['sources'] ) ) { $item['sources'] = maybe_unserialize( $item['sources'] ); }
		return $item;
	}

	public static function rank_math_redirects( array $args ) {
		if ( ! class_exists( '\\RankMath\\Redirections\\DB' ) || ! class_exists( '\\RankMath\\Redirections\\Redirection' ) ) {
			return new WP_Error( 'wpaib_rank_math_redirections_unavailable', 'Modulo redirect Rank Math non disponibile.', array( 'status' => 503 ) );
		}

		$action = sanitize_key( (string) ( $args['action'] ?? 'list' ) );
		$id = absint( $args['id'] ?? $args['redirect_id'] ?? 0 );
		if ( in_array( $action, array( 'get', 'verify', 'update', 'delete' ), true ) && ! $id ) {
			return new WP_Error( 'wpaib_redirect_id_required', 'ID redirect obbligatorio.', array( 'status' => 400 ) );
		}

		if ( 'list' === $action ) {
			$result = \RankMath\Redirections\DB::get_redirections(
				array(
					'orderby' => sanitize_key( (string) ( $args['orderby'] ?? 'id' ) ),
					'order'   => strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
					'limit'   => max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) ),
					'paged'   => max( 1, (int) ( $args['page'] ?? 1 ) ),
					'search'  => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
					'status'  => sanitize_key( (string) ( $args['status'] ?? 'any' ) ),
				)
			);
			$result['redirections'] = array_map( array( __CLASS__, 'rank_math_redirect_item' ), (array) ( $result['redirections'] ?? array() ) );
			return $result;
		}

		if ( in_array( $action, array( 'get', 'verify' ), true ) ) {
			$item = \RankMath\Redirections\DB::get_redirection_by_id( $id );
			return $item ? self::rank_math_redirect_item( $item ) : new WP_Error( 'wpaib_redirect_missing', 'Redirect non trovato.', array( 'status' => 404 ) );
		}


		$before = $id ? \RankMath\Redirections\DB::get_redirection_by_id( $id ) : null;
		if ( $id && ! $before ) { return new WP_Error( 'wpaib_redirect_missing', 'Redirect non trovato.', array( 'status' => 404 ) ); }

		if ( 'delete' === $action ) {
			$deleted = \RankMath\Redirections\DB::delete( array( $id ) );
			WPAIB_Audit::log( 'enterprise.rank_math_redirect.delete', 'success', (string) $id, array( 'before' => self::rank_math_redirect_item( $before ), 'deleted' => $deleted ) );
			PRSTUDIO_Report::record_change( 'Redirect Rank Math', (string) $id, self::rank_math_redirect_item( $before ), array( 'deleted' => true ) );
			return array( 'id' => $id, 'deleted' => (bool) $deleted );
		}

		if ( ! in_array( $action, array( 'create', 'update', 'upsert' ), true ) ) {
			return new WP_Error( 'wpaib_redirect_action_invalid', 'Azione redirect non valida.', array( 'status' => 400 ) );
		}

		$sources = self::rank_math_redirect_sources( $args, is_array( $before ) ? self::rank_math_redirect_item( $before ) : array() );
		if ( ! $sources ) { return new WP_Error( 'wpaib_redirect_source_required', 'Sorgente redirect obbligatoria.', array( 'status' => 400 ) ); }
		$code = (int) ( $args['header_code'] ?? $args['code'] ?? ( $before['header_code'] ?? 301 ) );
		if ( ! in_array( $code, array( 301, 302, 307, 410, 451 ), true ) ) { return new WP_Error( 'wpaib_redirect_code_invalid', 'Codice redirect non valido.', array( 'status' => 400 ) ); }
		$destination = (string) ( $args['destination'] ?? $args['url_to'] ?? $args['to'] ?? ( $before['url_to'] ?? '' ) );
		if ( ! in_array( $code, array( 410, 451 ), true ) && '' === $destination ) { return new WP_Error( 'wpaib_redirect_destination_required', 'Destinazione redirect obbligatoria.', array( 'status' => 400 ) ); }
		$status = sanitize_key( (string) ( $args['status'] ?? ( $before['status'] ?? 'active' ) ) );
		if ( ! in_array( $status, array( 'active', 'inactive', 'trashed' ), true ) ) { $status = 'active'; }

		$data = array( 'id' => $id, 'sources' => $sources, 'url_to' => $destination, 'header_code' => (string) $code, 'status' => $status );
		$redirect = \RankMath\Redirections\Redirection::from( $data );
		if ( $redirect->is_infinite_loop() ) { return new WP_Error( 'wpaib_redirect_loop', 'Il redirect creerebbe un ciclo.', array( 'status' => 409 ) ); }
		$saved_id = (int) $redirect->save();
		if ( ! $saved_id ) { return new WP_Error( 'wpaib_redirect_save_failed', 'Salvataggio redirect non riuscito.', array( 'status' => 500 ) ); }
		$after = self::rank_math_redirect_item( \RankMath\Redirections\DB::get_redirection_by_id( $saved_id ) );
		WPAIB_Audit::log( 'enterprise.rank_math_redirect.' . ( $id ? 'update' : 'create' ), 'success', (string) $saved_id, array( 'before' => self::rank_math_redirect_item( $before ), 'after' => $after ) );
		PRSTUDIO_Report::record_change( 'Redirect Rank Math', (string) $saved_id, self::rank_math_redirect_item( $before ), $after );
		return array( 'id' => $saved_id, 'before' => self::rank_math_redirect_item( $before ), 'after' => $after );
	}

	private static function search_console_site_url( $value ) {
		$value = trim( (string) $value );
		if ( 0 === strpos( $value, 'sc-domain:' ) ) {
			$domain = substr( $value, 10 );
			if ( '' !== $domain && preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain ) ) {
				return 'sc-domain:' . strtolower( $domain );
			}
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( $url && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) && wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $url;
		}
		return new WP_Error( 'wpaib_search_console_site_invalid', 'Proprietà Search Console non valida.', array( 'status' => 400 ) );
	}

	private static function search_console_date( $value, string $field ) {
		$value = trim( (string) $value );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error( 'wpaib_search_console_date_invalid', $field . ' deve essere nel formato YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		return $value;
	}

	private static function search_console_filters( $groups ) {
		if ( null === $groups || array() === $groups ) { return array(); }
		if ( ! is_array( $groups ) ) { return new WP_Error( 'wpaib_search_console_filters_invalid', 'dimension_filter_groups deve essere un array.', array( 'status' => 400 ) ); }
		$allowed_dimensions = array( 'country', 'device', 'page', 'query', 'searchAppearance' );
		$allowed_operators = array( 'equals', 'notEquals', 'contains', 'notContains' );
		$out = array();
		foreach ( array_slice( $groups, 0, 10 ) as $group ) {
			if ( ! is_array( $group ) ) { return new WP_Error( 'wpaib_search_console_filters_invalid', 'Gruppo filtri non valido.', array( 'status' => 400 ) ); }
			$filters = array();
			foreach ( array_slice( is_array( $group['filters'] ?? null ) ? $group['filters'] : array(), 0, 20 ) as $filter ) {
				if ( ! is_array( $filter ) ) { continue; }
				$dimension = (string) ( $filter['dimension'] ?? '' );
				$operator = (string) ( $filter['operator'] ?? 'equals' );
				$expression = trim( (string) ( $filter['expression'] ?? '' ) );
				if ( ! in_array( $dimension, $allowed_dimensions, true ) || ! in_array( $operator, $allowed_operators, true ) || '' === $expression || strlen( $expression ) > 4096 ) {
					return new WP_Error( 'wpaib_search_console_filter_invalid', 'Filtro Search Console non valido.', array( 'status' => 400 ) );
				}
				$filters[] = array( 'dimension' => $dimension, 'operator' => $operator, 'expression' => $expression );
			}
			if ( $filters ) { $out[] = array( 'groupType' => 'and', 'filters' => $filters ); }
		}
		return $out;
	}


	public static function search_console_status(): array {
		return PRSTUDIO_UC_Search_Console_Browser::status();
	}

	public static function search_console_sites() {
		return PRSTUDIO_UC_Search_Console_Browser::sites();
	}

	public static function search_console_search_analytics( array $args ) {
		return PRSTUDIO_UC_Search_Console_Browser::analytics( $args );
	}

	public static function search_console_sitemaps( array $args ) {
		return PRSTUDIO_UC_Search_Console_Browser::sitemaps( $args );
	}

	public static function search_console_url_inspection( array $args ) {
		return PRSTUDIO_UC_Search_Console_Browser::inspect_url( $args );
	}

	public static function search_console_request_indexing( array $args ) {
		return PRSTUDIO_UC_GSC_Provider::request_indexing( $args );
	}
}
