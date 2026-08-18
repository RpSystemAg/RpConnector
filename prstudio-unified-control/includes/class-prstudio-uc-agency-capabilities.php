<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Stable one-array executors for Capability Registry dispatch. */
final class PRSTUDIO_UC_Agency_Capabilities {
	public static function twin_sync( array $args ): array { return PRSTUDIO_UC_Operational_Twin::sync( $args ); }
	public static function twin_query( array $args ): array {
		return PRSTUDIO_UC_Operational_Twin::query( (string)($args['query']??''), array( 'type'=>(string)($args['type']??''), 'limit'=>(int)($args['limit']??50) ) );
	}
	public static function social_ingest( array $args ): array { return PRSTUDIO_UC_Social_Intelligence::ingest( $args ); }
	public static function social_insights( array $args ): array { return PRSTUDIO_UC_Social_Intelligence::insights( $args ); }
	public static function opportunity_rank( array $args ): array { return PRSTUDIO_UC_Opportunity_Engine::rank( $args ); }
	public static function agency_status( array $args = array() ): array {
		return class_exists('PRSTUDIO_UC_Agency_Runtime') ? PRSTUDIO_UC_Agency_Runtime::status() : array('ok'=>false,'error'=>'agency_runtime_unavailable');
	}
	public static function sentinel_scan( array $args ): array {
		return class_exists('PRSTUDIO_UC_Site_Sentinel') ? PRSTUDIO_UC_Site_Sentinel::scan($args) : array('ok'=>false,'error'=>'site_sentinel_unavailable');
	}
}
