<?php
/**
 * AdTribes orchestration for PR STUDIO AI BRIDGE.
 *
 * Creates and manages native AdTribes Product Feed PRO projects for the
 * channels exposed to the Italian market. No direct SQL and no plugin-core edits.
 *
 * @package PR_STUDIO_AI_BRIDGE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPAIB_AdTribes {
	private const META_MANAGED        = '_wpaib_adtribes_managed';
	private const META_ALCOHOL_MODE   = '_wpaib_adtribes_alcohol_mode';
	private const META_ALCOHOL_REASON = '_wpaib_adtribes_alcohol_reason';
	private const COUNTRY_CODE        = 'IT';
	private const COUNTRY_NAME        = 'Italy';
	private const MODULE_VERSION      = '1.0.2';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
		add_filter( 'adt_get_product_data', array( __CLASS__, 'decorate_product_data' ), 20, 3 );
		add_filter( 'adt_product_feed_get_products_query_args', array( __CLASS__, 'filter_product_query' ), 20, 2 );
	}

	public static function register_routes(): void {
		register_rest_route(
			'wp-ai-bridge/v1',
			'/adtribes/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => array( 'WPAIB_REST', 'read_permission' ),
			)
		);

		register_rest_route(
			'wp-ai-bridge/v1',
			'/adtribes/channels',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_channels' ),
				'permission_callback' => array( 'WPAIB_REST', 'read_permission' ),
			)
		);

		register_rest_route(
			'wp-ai-bridge/v1',
			'/adtribes/feeds',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_feeds' ),
				'permission_callback' => array( 'WPAIB_REST', 'read_permission' ),
			)
		);

		register_rest_route(
			'wp-ai-bridge/v1',
			'/adtribes/configure',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_configure' ),
				'permission_callback' => array( 'WPAIB_REST', 'write_permission' ),
			)
		);

		register_rest_route(
			'wp-ai-bridge/v1',
			'/adtribes/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_refresh' ),
				'permission_callback' => array( 'WPAIB_REST', 'write_permission' ),
			)
		);

		register_rest_route(
			'wp-ai-bridge/v1',
			'/adtribes/verify',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_verify' ),
				'permission_callback' => array( 'WPAIB_REST', 'read_permission' ),
			)
		);
	}

	private static function plugin_ready() {
		if (
			! class_exists( '\AdTribes\PFP\Helpers\Product_Feed_Helper' )
			|| ! class_exists( '\\AdTribes\\PFP\\Classes\\Product_Feed_Attributes' )
			|| ! post_type_exists( 'adt_product_feed' )
		) {
			return new WP_Error(
				'adtribes_unavailable',
				'Product Feed PRO by AdTribes is not active or its native feed model is unavailable.',
				array( 'status' => 503 )
			);
		}

		return true;
	}

	private static function plugin_version(): string {
		foreach ( array( 'ADT_PFP_VERSION', 'WOOOSEA_VERSION', 'WPFM_VERSION' ) as $constant ) {
			if ( defined( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		return '';
	}

	public static function rest_status(): WP_REST_Response {
		$ready = self::plugin_ready();

		return new WP_REST_Response(
			array(
				'plugin_ready'       => ! is_wp_error( $ready ),
				'plugin_version'     => self::plugin_version(),
				'module_version'     => self::MODULE_VERSION,
				'managed_feed_count' => count( self::managed_feed_ids() ),
				'country'            => self::COUNTRY_CODE,
				'timezone'           => wp_timezone_string(),
				'wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'wp_cli_command'     => 'wp adt-feed refresh',
			),
			is_wp_error( $ready ) ? 503 : 200
		);
	}

	public static function rest_channels() {
		$ready = self::plugin_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$plan = self::channel_plan();

		return new WP_REST_Response(
			array(
				'country'  => self::COUNTRY_NAME,
				'count'    => count( $plan ),
				'channels' => array_values( $plan ),
			)
		);
	}

	public static function rest_feeds() {
		$ready = self::plugin_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$rows = self::feed_rows();

		return new WP_REST_Response(
			array(
				'count' => count( $rows ),
				'feeds' => $rows,
			)
		);
	}

	public static function rest_configure( WP_REST_Request $request ) {
		$ready = self::plugin_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$dry_run  = rest_sanitize_boolean( $request->get_param( 'dry_run' ) );
		$generate = rest_sanitize_boolean( $request->get_param( 'generate' ) );
		$plan     = self::channel_plan();

		if ( $dry_run ) {
			return new WP_REST_Response(
				array(
					'dry_run'  => true,
					'count'    => count( $plan ),
					'channels' => array_values( $plan ),
				)
			);
		}

		$created = array();
		$updated = array();
		$errors  = array();

		foreach ( $plan as $channel_key => $item ) {
			try {
				$result = self::upsert_feed( $channel_key, $item, $generate );
				if ( 'created' === $result['operation'] ) {
					$created[] = $result;
				} else {
					$updated[] = $result;
				}
			} catch ( Throwable $e ) {
				$errors[] = array(
					'channel_key' => $channel_key,
					'channel'     => $item['name'],
					'message'     => $e->getMessage(),
				);
			}
		}

		update_option(
			'wpaib_adtribes_configuration',
			array(
				'version'       => self::MODULE_VERSION,
				'configured_at' => gmdate( 'c' ),
				'country'       => self::COUNTRY_CODE,
				'feed_count'    => count( $created ) + count( $updated ),
			),
			false
		);

		return new WP_REST_Response(
			array(
				'dry_run' => false,
				'created' => $created,
				'updated' => $updated,
				'errors'  => $errors,
				'feeds'   => self::feed_rows(),
			),
			empty( $errors ) ? 200 : 207
		);
	}

	public static function refresh_native( array $args = array() ) {
		$ready = self::plugin_ready();
		if ( is_wp_error( $ready ) ) { return $ready; }

		$ids = array();
		foreach ( array( 'ids', 'feed_ids' ) as $key ) {
			$value = $args[ $key ] ?? null;
			if ( is_array( $value ) ) { $ids = array_merge( $ids, $value ); }
		}
		foreach ( array( 'id', 'feed_id' ) as $key ) {
			if ( isset( $args[ $key ] ) ) { $ids[] = $args[ $key ]; }
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$limit = max( 1, min( 30, absint( $args['limit'] ?? 5 ) ) );
		$requested = array_slice( $ids ?: self::managed_feed_ids(), 0, $limit );
		if ( ! $requested ) { return new WP_Error( 'adtribes_no_feed_ids', 'Nessun feed AdTribes disponibile per la rigenerazione.', array( 'status' => 404 ) ); }

		$queued = array(); $skipped = array(); $errors = array();
		foreach ( $requested as $feed_id ) {
			try {
				$feed = \AdTribes\PFP\Helpers\Product_Feed_Helper::get_product_feed( $feed_id, 'edit' );
				if ( ! $feed || ! $feed->id ) { $skipped[] = array( 'id' => $feed_id, 'reason' => 'not_found' ); continue; }
				if ( 'processing' === $feed->status ) { $skipped[] = array( 'id' => $feed_id, 'reason' => 'already_processing' ); continue; }
				$scheduled = $feed->generate( 'manual' );
				$accepted = false !== $scheduled;
				$queued[] = array( 'id' => $feed_id, 'title' => $feed->title, 'scheduled' => $accepted );
				if ( ! $accepted ) { $errors[] = array( 'id' => $feed_id, 'message' => 'schedule_rejected' ); }
			} catch ( Throwable $e ) { $errors[] = array( 'id' => $feed_id, 'message' => $e->getMessage(), 'exception_class' => get_class( $e ) ); }
		}
		$all_accepted = empty( $errors ) && count( $queued ) > 0 && ! in_array( false, array_column( $queued, 'scheduled' ), true );
		return array(
			'provider' => 'adtribes_native_product_feed', 'requested_ids' => $requested,
			'queued' => $queued, 'skipped' => $skipped, 'errors' => $errors,
			'schedule_accepted' => $all_accepted,
			'pending_output_verification' => $all_accepted,
			'next_verification' => 'wp-ai-bridge/v1/adtribes/verify',
			'_control_outcome' => array( 'status' => $all_accepted ? 'completed' : 'failed', 'executed' => true, 'mutated' => $all_accepted, 'verified' => $all_accepted, 'reason' => $all_accepted ? 'native_schedule_accepted' : 'native_schedule_failed' ),
		);
	}

	public static function rest_refresh( WP_REST_Request $request ) {
		$result = self::refresh_native( array( 'ids' => (array) $request->get_param( 'ids' ), 'limit' => $request->get_param( 'limit' ) ?: 5 ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return new WP_REST_Response( $result, empty( $result['errors'] ) ? 200 : 207 );
	}

	public static function rest_verify() {
		$ready = self::plugin_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$rows = self::feed_rows();
		$ok   = 0;

		foreach ( $rows as &$row ) {
			$path               = $row['file_path'];
			$row['file_exists'] = is_string( $path ) && file_exists( $path );
			$row['file_bytes']  = $row['file_exists'] ? (int) filesize( $path ) : 0;
			$row['readable']    = $row['file_exists'] && is_readable( $path );

			if ( $row['file_exists'] && $row['readable'] && $row['file_bytes'] > 0 ) {
				++$ok;
			}

			unset( $row['file_path'] );
		}
		unset( $row );

		return new WP_REST_Response(
			array(
				'feed_count'       => count( $rows ),
				'valid_file_count' => $ok,
				'feeds'            => $rows,
			)
		);
	}

	private static function channel_plan(): array {
		$attributes = \AdTribes\PFP\Classes\Product_Feed_Attributes::instance();
		$channels   = $attributes->get_channels( self::COUNTRY_NAME );
		$plan       = array();

		foreach ( $channels as $channel_key => $channel ) {
			if (
				empty( $channel_key )
				|| empty( $channel['channel_hash'] )
				|| empty( $channel['name'] )
				|| empty( $channel['fields'] )
			) {
				continue;
			}

			$policy = self::alcohol_policy( $channel );
			$format = self::file_format( $channel );

			$plan[ (string) $channel_key ] = array(
				'channel_key'    => (string) $channel_key,
				'channel_hash'   => (string) $channel['channel_hash'],
				'name'           => (string) $channel['name'],
				'fields'         => (string) $channel['fields'],
				'taxonomy'       => (string) ( $channel['taxonomy'] ?? 'none' ),
				'type'           => (string) ( $channel['type'] ?? '' ),
				'format'         => $format,
				'alcohol_mode'   => $policy['mode'],
				'alcohol_reason' => $policy['reason'],
			);
		}

		uasort(
			$plan,
			static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] )
		);

		return $plan;
	}

	private static function native_channel( string $channel_identifier ): array {
		$channel_identifier = trim( $channel_identifier );
		if ( '' === $channel_identifier ) {
			return array();
		}

		$channels = \AdTribes\PFP\Classes\Product_Feed_Attributes::instance()->get_channels( self::COUNTRY_NAME );

		if ( isset( $channels[ $channel_identifier ] ) && is_array( $channels[ $channel_identifier ] ) ) {
			return $channels[ $channel_identifier ];
		}

		foreach ( $channels as $channel_key => $channel ) {
			if ( ! is_array( $channel ) ) {
				continue;
			}

			$channel_hash = (string) ( $channel['channel_hash'] ?? '' );
			$channel_name = (string) ( $channel['name'] ?? '' );

			if (
				$channel_identifier === $channel_hash
				|| $channel_identifier === (string) $channel_key
				|| ( '' !== $channel_name && 0 === strcasecmp( $channel_identifier, $channel_name ) )
			) {
				return $channel;
			}
		}

		return array();
	}

	private static function alcohol_policy( array $channel ): array {
		$fields = strtolower( (string) ( $channel['fields'] ?? '' ) );
		$name   = strtolower( (string) ( $channel['name'] ?? '' ) );

		if ( str_contains( $name, 'twitter' ) || str_contains( $name, 'x.com' ) ) {
			return array(
				'mode'   => 'exclude',
				'reason' => 'Alcohol eligibility is not enabled by default for this destination.',
			);
		}

		$allowed_fields = array(
			'google_shopping',
			'google_shopping_promotions',
			'google_drm',
			'google_dsa',
			'google_local_products',
			'google_local',
			'google_product_review',
			'facebook_drm',
			'pinterest',
			'snapchat',
			'vivino',
		);

		if ( in_array( $fields, $allowed_fields, true ) ) {
			return array(
				'mode'   => 'include',
				'reason' => 'Channel supports compliant alcohol listings; product-level classification and age label are applied.',
			);
		}

		if ( 'openai' === $fields || str_contains( $name, 'openai' ) ) {
			return array(
				'mode'   => 'exclude',
				'reason' => 'OpenAI commerce product feeds prohibit alcoholic beverages over 0.5% ABV.',
			);
		}

		if ( 'customfeed' === $fields ) {
			return array(
				'mode'   => 'exclude',
				'reason' => 'Destination is unspecified, so alcohol is excluded by default.',
			);
		}

		return array(
			'mode'   => 'exclude',
			'reason' => 'Alcohol eligibility or account approval is not established for this destination.',
		);
	}

	private static function file_format( array $channel ): string {
		$fields = strtolower( (string) ( $channel['fields'] ?? '' ) );

		if ( 'openai' === $fields ) {
			return 'jsonl';
		}

		if ( 'amazon' === $fields ) {
			return 'tsv';
		}

		if ( in_array( $fields, array( 'google_dsa', 'customfeed' ), true ) ) {
			return 'csv';
		}

		return 'xml';
	}

	private static function upsert_feed( string $channel_key, array $item, bool $generate ): array {
		$channel_hash = trim( (string) ( $item['channel_hash'] ?? '' ) );
		if ( '' === $channel_hash ) {
			throw new RuntimeException( 'Native AdTribes channel hash not found: ' . $channel_key );
		}

		$existing_id = self::find_feed_id_by_channel_key( $channel_key, $item );
		$operation   = $existing_id ? 'updated' : 'created';
		$feed        = \AdTribes\PFP\Helpers\Product_Feed_Helper::get_product_feed( $existing_id ?: 0, 'edit' );
		$channel     = self::native_channel( $channel_hash );

		if ( empty( $channel ) ) {
			throw new RuntimeException( 'Channel definition not found: ' . $channel_hash );
		}

		$legacy_hash = $existing_id && ! empty( $feed->legacy_project_hash )
			? $feed->legacy_project_hash
			: \AdTribes\PFP\Helpers\Product_Feed_Helper::generate_legacy_project_hash();

		$file_name = $existing_id && ! empty( $feed->file_name )
			? $feed->file_name
			: sanitize_file_name( 'rpconnector-it-' . sanitize_title( $item['name'] ) . '-' . substr( md5( $channel_key ), 0, 8 ) );

		$mappings = 'google_shopping' === ( $channel['taxonomy'] ?? '' )
			? self::google_category_mappings()
			: array();

		$feed->set_props(
			array(
				'title'                                  => 'Ideal Market — ' . $item['name'] . ' — IT',
				'post_status'                            => 'publish',
				'status'                                 => $existing_id ? $feed->status : '',
				'country'                                => self::COUNTRY_CODE,
				'channel_hash'                           => $channel_hash,
				'file_name'                              => $file_name,
				'file_format'                            => $item['format'],
				'delimiter'                              => 'tsv' === $item['format'] ? "\t" : ',',
				'refresh_interval'                       => '',
				'refresh_only_when_product_changed'      => true,
				'create_preview'                         => false,
				'include_product_variations'             => true,
				'only_include_default_product_variation' => false,
				'only_include_lowest_product_variation'  => false,
				'include_all_shipping_countries'         => false,
				'utm_enabled'                            => true,
				'utm_source'                             => (string) ( $channel['utm_source'] ?? sanitize_title( $item['name'] ) ),
				'utm_medium'                             => 'product-feed',
				'utm_campaign'                           => 'catalogo-italia',
				'attributes'                             => self::build_attributes( $channel ),
				'mappings'                               => $mappings,
				'rules'                                  => array(),
				'filters'                                => array(),
				'feed_filters'                           => array(),
				'feed_rules'                             => array(),
				'legacy_project_hash'                    => $legacy_hash,
			)
		);

		if ( method_exists( $feed, 'set_data_version' ) ) {
			$feed->set_data_version( 'feed_filters', '13.4.6' );
			$feed->set_data_version( 'feed_rules', '13.4.6' );
		}

		$feed_id = (int) $feed->save();
		if ( $feed_id <= 0 ) {
			throw new RuntimeException( 'AdTribes did not return a valid feed ID.' );
		}

		if ( method_exists( $feed, 'unregister_action' ) ) {
			$feed->unregister_action();
		}

		update_post_meta( $feed_id, self::META_MANAGED, 'yes' );
		update_post_meta( $feed_id, self::META_ALCOHOL_MODE, $item['alcohol_mode'] );
		update_post_meta( $feed_id, self::META_ALCOHOL_REASON, $item['alcohol_reason'] );

		$scheduled = false;
		if ( $generate && 'processing' !== $feed->status ) {
			$scheduled = false !== $feed->generate( 'manual' );
		}

		return array(
			'operation'    => $operation,
			'id'           => $feed_id,
			'title'        => $feed->title,
			'channel'      => $item['name'],
			'channel_hash' => $channel_hash,
			'format'       => $item['format'],
			'alcohol_mode' => $item['alcohol_mode'],
			'file_url'     => $feed->get_file_url(),
			'scheduled'    => $scheduled,
		);
	}

	private static function build_attributes( array $channel ): array {
		$fields = sanitize_key( (string) ( $channel['fields'] ?? '' ) );
		if ( ! defined( 'ADT_PFP_PLUGIN_DIR_PATH' ) ) {
			return self::generic_attributes();
		}

		$file = trailingslashit( ADT_PFP_PLUGIN_DIR_PATH ) . 'classes/channels/class-' . $fields . '.php';

		if ( ! is_readable( $file ) ) {
			return self::generic_attributes();
		}

		require_once $file;
		$class = 'WooSEA_' . $fields;

		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_channel_attributes' ) ) {
			return self::generic_attributes();
		}

		$definitions = (array) $class::get_channel_attributes();
		$rows        = array();
		$row         = 0;

		foreach ( $definitions as $groups ) {
			foreach ( (array) $groups as $label => $definition ) {
				if ( empty( $definition['feed_name'] ) ) {
					continue;
				}

				$format      = strtolower( (string) ( $definition['format'] ?? '' ) );
				$required    = 'required' === $format || rest_sanitize_boolean( $definition['required'] ?? false );
				$recommended = 'recommended' === $format || rest_sanitize_boolean( $definition['recommended'] ?? false );
				$feed_name   = (string) $definition['feed_name'];

				if ( ! $required && ! $recommended && ! self::important_feed_field( $feed_name ) ) {
					continue;
				}

				$mapping = self::mapping_for_definition( (string) $label, $definition );
				if ( '' === $mapping['mapfrom'] && ! $required ) {
					continue;
				}

				$mapping['attribute'] = $feed_name;
				$mapping['rowCount']  = $row;
				$rows[ $row ]         = $mapping;
				++$row;
			}
		}

		return $rows ?: self::generic_attributes();
	}

	private static function important_feed_field( string $field ): bool {
		$field = strtolower( $field );

		foreach ( array( 'brand', 'gtin', 'mpn', 'product_type', 'category', 'custom_label', 'age_restriction' ) as $needle ) {
			if ( str_contains( $field, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private static function mapping_for_definition( string $label, array $definition ): array {
		$feed_name = strtolower( (string) ( $definition['feed_name'] ?? '' ) );
		$suggest   = (string) ( $definition['woo_suggest'] ?? '' );
		$mapfrom   = $suggest;
		$value     = '';
		$static    = false;

		if ( str_starts_with( $suggest, 'static_value:' ) ) {
			$mapfrom = substr( $suggest, 13 );
			$static  = true;
		} elseif ( str_starts_with( $suggest, 'page:' ) ) {
			$mapfrom = 'page_url';
			$value   = absint( substr( $suggest, 5 ) );
		} elseif ( str_starts_with( $suggest, 'post:' ) ) {
			$mapfrom = 'post_url';
			$value   = absint( substr( $suggest, 5 ) );
		}

		if ( '' === $mapfrom ) {
			$mapfrom = self::fallback_mapfrom( $feed_name, strtolower( $label ) );
		}

		if ( str_contains( $feed_name, 'age_restriction' ) ) {
			$mapfrom = 'rpconnector_age_restriction';
			$static  = false;
		}

		if ( str_contains( $feed_name, 'custom_label_0' ) ) {
			$mapfrom = 'product_brand';
		}

		if ( str_contains( $feed_name, 'custom_label_1' ) ) {
			$mapfrom = 'category_path';
		}

		if ( str_contains( $feed_name, 'custom_label_2' ) ) {
			$mapfrom = 'rpconnector_regulation';
		}

		$row = array(
			'prefix'  => str_replace( '{{CURRENCY}}', 'EUR', (string) ( $definition['prefix'] ?? '' ) ),
			'mapfrom' => $mapfrom,
			'suffix'  => str_replace( '{{CURRENCY}}', 'EUR', (string) ( $definition['suffix'] ?? '' ) ),
			'value'   => $value,
		);

		if ( $static ) {
			$row['static_value'] = 'true';
		}

		return $row;
	}

	private static function fallback_mapfrom( string $field, string $label ): string {
		$haystack = trim( $field . ' ' . $label );

		if ( str_contains( $haystack, 'seller_name' ) || str_contains( $haystack, 'merchant_name' ) ) {
			return 'site_title';
		}

		if ( str_contains( $haystack, 'seller_url' ) || str_contains( $haystack, 'merchant_url' ) ) {
			return 'shop_url';
		}

		if ( str_contains( $haystack, 'privacy' ) ) {
			return 'privacy_policy_page_url';
		}

		if ( str_contains( $haystack, 'terms' ) ) {
			return 'terms_condtion_page_url';
		}

		if ( str_contains( $haystack, 'target_countr' ) ) {
			return 'base_country';
		}

		if ( str_contains( $haystack, 'return_deadline' ) ) {
			return 'rpconnector_return_deadline';
		}

		if ( str_contains( $haystack, 'return_policy' ) ) {
			return 'rpconnector_return_policy_url';
		}

		$map = array(
			'item_group_id'     => 'item_group_id',
			'additional_image'  => 'additional_image_1',
			'image'             => 'image',
			'description'       => 'description',
			'title'             => 'title',
			'name'              => 'title',
			'product url'       => 'link',
			'landing page'      => 'link',
			'link'              => 'link',
			'url'               => 'link',
			'availability'      => 'availability',
			'stock status'      => 'availability',
			'quantity'          => 'quantity',
			'sale_price'        => 'sale_price',
			'price'             => 'price',
			'condition'         => 'condition',
			'brand'             => 'product_brand',
			'manufacturer'      => 'product_brand',
			'gtin'              => 'gtin',
			'ean'               => 'gtin',
			'barcode'           => 'gtin',
			'mpn'               => 'mpn',
			'sku'               => 'sku',
			'product_type'      => 'category_path',
			'product type'      => 'category_path',
			'category'          => 'categories',
			'identifier_exists' => 'calculated',
			'currency'          => 'currency',
			'weight'            => 'weight',
			'id'                => 'id',
		);

		foreach ( $map as $needle => $attribute ) {
			if ( str_contains( $haystack, $needle ) ) {
				return $attribute;
			}
		}

		return '';
	}

	private static function generic_attributes(): array {
		$map = array(
			'id'           => 'id',
			'title'        => 'title',
			'description'  => 'description',
			'link'         => 'link',
			'image_link'   => 'image',
			'availability' => 'availability',
			'price'        => 'price',
			'brand'        => 'product_brand',
			'gtin'         => 'gtin',
		);
		$rows = array();
		$i    = 0;

		foreach ( $map as $attribute => $mapfrom ) {
			$rows[ $i ] = array(
				'attribute' => $attribute,
				'prefix'    => '',
				'mapfrom'   => $mapfrom,
				'suffix'    => '',
				'value'     => '',
				'rowCount'  => $i,
			);
			++$i;
		}

		return $rows;
	}

	private static function google_category_mappings(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$mappings = array();

		foreach ( $terms as $term ) {
			$target = self::google_target_for_term( $term );
			if ( '' === $target ) {
				continue;
			}

			$mappings[ $term->term_id ] = array(
				'rowCount'        => $term->term_id,
				'categoryId'      => $term->term_id,
				'criteria'        => $term->name,
				'map_to_category' => $target,
			);
		}

		return $mappings;
	}

	private static function google_target_for_term( WP_Term $term ): string {
		$path = strtolower( self::term_path( $term ) );

		if ( preg_match( '/\b(vini?|wine|bollicine|spumant|champagne|prosecco|charmat|champenoise|vitign|rossi|bianchi|rosati)\b/u', $path ) ) {
			return '421 - Food, Beverages & Tobacco > Beverages > Alcoholic Beverages > Wine';
		}

		if ( preg_match( '/\b(spirits?|grapp|gin|rum|vodka|tequila|whisk|liquor|amari|aperitivo|bitter|vermouth|marsala|passiti|cognac|brandy)\b/u', $path ) ) {
			return '417 - Food, Beverages & Tobacco > Beverages > Alcoholic Beverages > Liquor & Spirits';
		}

		if ( preg_match( '/\b(food|farina|salse|creme|sciroppi|topping|conserve|cereali|riso|pasta|panificat|dolciumi|snack|ittici|ingredienti|finger|spezie|aromi|tartufo|frutta secca|aceto|olio|freschi|surgelati)\b/u', $path ) ) {
			return '422 - Food, Beverages & Tobacco > Food Items';
		}

		if ( preg_match( '/\b(detersiv|pulizia|cleaning)\b/u', $path ) ) {
			return '623 - Home & Garden > Household Supplies > Household Cleaning Supplies';
		}

		if ( preg_match( '/\b(calici|accessori e monouso|monouso)\b/u', $path ) ) {
			return '536 - Home & Garden > Kitchen & Dining';
		}

		return '';
	}

	private static function term_path( WP_Term $term ): string {
		$parts = array( $term->name, $term->slug );

		foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( $ancestor instanceof WP_Term ) {
				array_unshift( $parts, $ancestor->name, $ancestor->slug );
			}
		}

		return implode( ' ', $parts );
	}

	private static function find_feed_id_by_channel_key( string $channel_key, array $item ): int {
		$identifiers = array_values(
			array_unique(
				array_filter(
					array_map(
						'trim',
						array(
							(string) ( $item['channel_hash'] ?? '' ),
							$channel_key,
							(string) ( $item['name'] ?? '' ),
						)
					),
					'strlen'
				)
			)
		);

		if ( empty( $identifiers ) ) {
			return 0;
		}

		$channel_meta_query = array( 'relation' => 'OR' );
		foreach ( $identifiers as $identifier ) {
			$channel_meta_query[] = array(
				'key'     => 'adt_channel_hash',
				'value'   => $identifier,
				'compare' => '=',
			);
		}

		$ids = get_posts(
			array(
				'post_type'      => 'adt_product_feed',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation'           => 'AND',
					$channel_meta_query,
					array(
						'key'     => self::META_MANAGED,
						'value'   => 'yes',
						'compare' => '=',
					),
				),
			)
		);

		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	private static function managed_feed_ids(): array {
		return array_map(
			'absint',
			get_posts(
				array(
					'post_type'      => 'adt_product_feed',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => self::META_MANAGED,
					'meta_value'     => 'yes',
				)
			)
		);
	}

	private static function feed_rows(): array {
		$rows = array();

		foreach ( self::managed_feed_ids() as $feed_id ) {
			$feed = \AdTribes\PFP\Helpers\Product_Feed_Helper::get_product_feed( $feed_id, 'view' );
			if ( ! $feed || ! $feed->id ) {
				continue;
			}

			$channel = self::native_channel( (string) $feed->channel_hash );

			$rows[] = array(
				'id'             => $feed_id,
				'title'          => $feed->title,
				'status'         => $feed->status,
				'channel'        => (string) ( $channel['name'] ?? $feed->channel_hash ),
				'channel_hash'   => $feed->channel_hash,
				'format'         => $feed->file_format,
				'alcohol_mode'   => get_post_meta( $feed_id, self::META_ALCOHOL_MODE, true ),
				'products_count' => (int) $feed->products_count,
				'last_updated'   => $feed->last_updated,
				'file_url'       => $feed->get_file_url(),
				'file_path'      => $feed->get_file_path(),
				'refresh'        => $feed->refresh_interval,
			);
		}

		return $rows;
	}

	public static function decorate_product_data( $data, $feed, $product ) {
		if ( ! is_array( $data ) || empty( $data ) || ! $product instanceof WC_Product ) {
			return $data;
		}

		$is_alcohol = self::is_alcohol_product( $product );

		if (
			$is_alcohol
			&& ! empty( $feed->id )
			&& 'exclude' === get_post_meta( (int) $feed->id, self::META_ALCOHOL_MODE, true )
		) {
			return array();
		}

		$data['rpconnector_alcohol']           = $is_alcohol ? 'yes' : 'no';
		$data['rpconnector_age_restriction']   = $is_alcohol ? '18+' : '';
		$data['rpconnector_regulation']        = $is_alcohol ? 'alcohol_18_plus' : 'standard';
		$data['rpconnector_return_deadline']   = '14';
		$data['rpconnector_return_policy_url'] = self::policy_url( 'return' );

		$channel = self::native_channel( (string) ( $feed->channel_hash ?? '' ) );
		if ( $is_alcohol && 'google_shopping' === ( $channel['taxonomy'] ?? '' ) ) {
			$data['google_category'] = self::is_wine_product( $product ) ? '421' : '417';
		}

		return $data;
	}

	public static function filter_product_query( array $query, $feed ): array {
		if ( ! $feed || empty( $feed->id ) ) {
			return $query;
		}

		if ( 'exclude' !== get_post_meta( (int) $feed->id, self::META_ALCOHOL_MODE, true ) ) {
			return $query;
		}

		$term_ids = self::alcohol_category_ids();
		if ( empty( $term_ids ) ) {
			return $query;
		}

		$tax_query   = isset( $query['tax_query'] ) && is_array( $query['tax_query'] ) ? $query['tax_query'] : array();
		$tax_query[] = array(
			'taxonomy'         => 'product_cat',
			'field'            => 'term_id',
			'terms'            => $term_ids,
			'operator'         => 'NOT IN',
			'include_children' => true,
		);
		$query['tax_query'] = $tax_query;

		return $query;
	}

	private static function alcohol_category_ids(): array {
		static $ids = null;

		if ( null !== $ids ) {
			return $ids;
		}

		$ids   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $ids;
		}

		foreach ( $terms as $term ) {
			if ( self::term_is_alcohol( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function term_is_alcohol( WP_Term $term ): bool {
		$path = strtolower( self::term_path( $term ) );

		return (bool) preg_match(
			'/\b(vini?|wine|bollicine|spumant|champagne|prosecco|spirits?|grapp|gin|rum|vodka|tequila|whisk|liquor|amari|aperitivo|bitter|vermouth|marsala|passiti|cognac|brandy|birra|beer)\b/u',
			$path
		);
	}

	private static function is_alcohol_product( WC_Product $product ): bool {
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$terms      = get_the_terms( $product_id, 'product_cat' );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term && self::term_is_alcohol( $term ) ) {
					return true;
				}
			}
		}

		foreach ( array( 'product_brand', 'pwb-brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$brands = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $brands ) && preg_match( '/musanegra/i', implode( ' ', $brands ) ) ) {
				return true;
			}
		}

		return (bool) preg_match( '/musanegra/i', $product->get_name() );
	}

	private static function is_wine_product( WC_Product $product ): bool {
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$terms      = get_the_terms( $product_id, 'product_cat' );

		if ( ! is_array( $terms ) ) {
			return false;
		}

		foreach ( $terms as $term ) {
			if (
				$term instanceof WP_Term
				&& preg_match( '/\b(vini?|wine|bollicine|spumant|champagne|prosecco|charmat|champenoise)\b/u', strtolower( self::term_path( $term ) ) )
			) {
				return true;
			}
		}

		return false;
	}

	private static function policy_url( string $type ): string {
		$keywords = 'return' === $type
			? array( 'reso', 'resi', 'recesso', 'refund', 'return' )
			: array( 'termini', 'condizioni' );

		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
			)
		);

		foreach ( $pages as $page ) {
			$haystack = strtolower( $page->post_title . ' ' . $page->post_name );
			foreach ( $keywords as $keyword ) {
				if ( str_contains( $haystack, $keyword ) ) {
					return get_permalink( $page );
				}
			}
		}

		$wc_page_id = wc_get_page_id( 'terms' );
		return $wc_page_id > 0 ? get_permalink( $wc_page_id ) : home_url( '/' );
	}
}

WPAIB_AdTribes::init();