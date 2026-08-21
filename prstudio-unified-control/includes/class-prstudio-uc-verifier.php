<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Evidence observer only. It classifies what happened after execution and never authorizes, vetoes or rolls back a mutation. */
final class PRSTUDIO_UC_Verifier {
	private static function boolean_flag( $value ): ?bool {
		if ( is_bool( $value ) ) { return $value; }
		if ( 0 === $value || '0' === $value ) { return false; }
		if ( 1 === $value || '1' === $value ) { return true; }
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( 'false' === $value ) { return false; }
			if ( 'true' === $value ) { return true; }
		}
		return null;
	}
	private static function requirement_flag( array $source, string $key ): bool {
		if ( ! array_key_exists( $key, $source ) || null === $source[$key] ) { return false; }
		$flag = self::boolean_flag( $source[$key] );
		return null === $flag ? true : $flag;
	}
	private static function text_value( $value ): string { return is_string( $value ) || is_int( $value ) || is_float( $value ) ? (string) $value : ''; }
	private static function first_boolean( array $source, array $keys ): ?bool {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $source ) || null === $source[$key] ) { continue; }
			$flag = self::boolean_flag( $source[$key] );
			if ( null !== $flag ) { return $flag; }
		}
		return null;
	}
	private static function mutating_browser_action( string $action, string $step_type ): bool {
		$read_only_steps = array(
			'agent_status','list_tabs','wait','wait_load','wait_url','wait_selector','extract_text','dom_snapshot','page_snapshot',
			'accessibility_snapshot','computed_styles','screenshot','screenshot_element','pdf','network_report','console_report',
			'page_errors','headers','service_workers','core_web_vitals','accessibility_scan','observation_bundle','find_elements','verify_url',
		);
		if ( '' !== $step_type && in_array( $step_type, $read_only_steps, true ) ) { return false; }
		$read_only_actions = array(
			'playwright_content','playwright_dom_snapshot','playwright_accessibility_snapshot','playwright_screenshot_page',
			'playwright_screenshot_element','playwright_page_errors','playwright_console_report','playwright_network_report',
			'playwright_headers','playwright_status','playwright_list_pages','playwright_get_pages',
		);
		return ! in_array( $action, $read_only_actions, true );
	}

	private static function connection_contract_verdict( string $action, array $result ): ?array {
		if ( ! in_array( $action, array( 'playwright_connect_browser','playwright_connect_over_cdp','playwright_new_context','playwright_launch_chrome','playwright_launch_chromium','playwright_list_contexts' ), true ) ) { return null; }
		$window_id = (int) ( $result['hostWindowId'] ?? $result['agentWindowId'] ?? 0 );
		$executed = true === self::boolean_flag( $result['executed'] ?? false );
		if ( 'playwright_connect_browser' === $action || 'playwright_launch_chrome' === $action || 'playwright_launch_chromium' === $action ) {
			$ok = $executed && $window_id > 0 && 'same_profile_existing_window' === (string) ( $result['mode'] ?? '' );
			return array( 'ok'=>$ok, 'reason'=>$ok ? '' : 'browser_connection_not_proven', 'strength'=>'extension_window_probe', 'effect_verified'=>$ok );
		}
		if ( 'playwright_connect_over_cdp' === $action ) {
			$cdp = is_array( $result['cdpProbe'] ?? null ) ? $result['cdpProbe'] : ( is_array( $result['cdp_probe'] ?? null ) ? $result['cdp_probe'] : array() );
			$ok = $executed && $window_id > 0 && ! empty( $cdp['attached'] ) && '0.1' === (string) ( $cdp['protocolVersion'] ?? $cdp['protocol_version'] ?? '' );
			return array( 'ok'=>$ok, 'reason'=>$ok ? '' : 'cdp_connection_not_proven', 'strength'=>'chrome_debugger_attach_probe', 'effect_verified'=>$ok );
		}
		if ( 'playwright_new_context' === $action ) {
			// The extension cannot create a separate Playwright/incognito BrowserContext
			// inside the user's existing profile. It may expose lane isolation, but it
			// must not certify that as a newly-created browser context.
			$isolated = ! empty( $result['isolatedLane'] ) || ! empty( $result['isolated_lane'] );
			return array( 'ok'=>$executed && $isolated, 'reason'=>$isolated ? '' : 'new_browser_context_not_created', 'strength'=>'lane_isolation_receipt', 'effect_verified'=>$executed && $isolated );
		}
		if ( 'playwright_list_contexts' === $action ) {
			$contexts = $result['contexts'] ?? null;
			$ok = $executed && is_array( $contexts );
			return array( 'ok'=>$ok, 'reason'=>$ok ? '' : 'context_inventory_not_proven', 'strength'=>'context_inventory', 'effect_verified'=>$ok );
		}
		return null;
	}

	public static function browser_result( array $task, array $result ): array {
		$verified = true === ( $result['verified'] ?? false );
		$step_type = sanitize_key( self::text_value( $result['stepType'] ?? $result['step_type'] ?? '' ) );
		$args = is_array( $task['arguments'] ?? null ) ? $task['arguments'] : array();
		$postcondition = is_array( $args['postcondition'] ?? null ) ? $args['postcondition'] : ( is_array( $args['verify'] ?? null ) ? $args['verify'] : array() );
		$requires_application = self::requirement_flag( $postcondition, 'required' ) || self::requirement_flag( $args, 'require_application_acceptance' );
		$accepted_present = array_key_exists( 'applicationAccepted', $result ) || array_key_exists( 'application_accepted', $result );
		$accepted_value = $result['applicationAccepted'] ?? $result['application_accepted'] ?? null;
		$accepted = null; $accepted_valid = true;
		if ( $accepted_present && null !== $accepted_value ) { $accepted = self::boolean_flag( $accepted_value ); $accepted_valid = null !== $accepted; }
		$reason = $verified ? '' : 'browser_result_missing_verified_evidence';
		$action = sanitize_key( self::text_value( $task['action'] ?? '' ) );
		$strength = sanitize_key( self::text_value( $result['verificationStrength'] ?? $result['verification_strength'] ?? '' ) );
		$effect_verified = self::first_boolean( $result, array( 'effectVerified','effect_verified','applicationEffectVerified','application_effect_verified','postconditionVerified','postcondition_verified' ) );

		$connection = self::connection_contract_verdict( $action, $result );
		if ( is_array( $connection ) ) {
			$verified = ! empty( $connection['ok'] );
			$reason = (string) $connection['reason'];
			$strength = (string) $connection['strength'];
			$effect_verified = (bool) $connection['effect_verified'];
		}

		if ( $verified && false === $effect_verified ) { $verified = false; $reason = 'browser_effect_rejected_or_unverified'; }
		$weak_strength = in_array( $strength, array( 'transport_or_ui_dispatch','transport_only','dispatch_only','ui_dispatch' ), true );
		if ( $verified && $weak_strength && self::mutating_browser_action( $action, $step_type ) && true !== $effect_verified && true !== $accepted ) {
			$verified = false; $reason = 'browser_effect_not_verified';
		}

		$observation = is_array( $result['observation'] ?? null ) ? $result['observation'] : array();
		$payload = is_array( $observation['data'] ?? null ) ? $observation['data'] : $result;
		if ( $verified && str_starts_with( $action, 'search_console_' ) ) {
			$property = is_array( $payload['propertySelection'] ?? null ) ? $payload['propertySelection'] : array();
			$property_ok = ! array_key_exists( 'verified', $property ) || ! empty( $property['verified'] );
			if ( 'search_console_search_analytics' === $action ) {
				$metrics = is_array( $payload['structuredMetrics'] ?? null ) ? $payload['structuredMetrics'] : array();
				$integrity = is_array( $metrics['dimension_integrity'] ?? null ) ? $metrics['dimension_integrity'] : array();
				$verified = $property_ok && ! empty( $metrics['verified'] ) && 'verified' === (string) ( $integrity['status'] ?? '' ) && empty( $metrics['missing_dimensions'] );
				if ( ! $verified ) { $reason = 'gsc_search_analytics_data_not_verified'; }
			} elseif ( 'search_console_sitemaps' === $action ) {
				$url = strtolower( self::text_value( $payload['url'] ?? '' ) ); $title = strtolower( self::text_value( $payload['title'] ?? '' ) );
				$text = strtolower( substr( self::text_value( $payload['text'] ?? '' ), 0, 12000 ) );
				$surface_ok = str_contains( $url, '/search-console/sitemaps' ) && ( str_contains( $title, 'sitemap' ) || str_contains( $text, 'sitemap' ) );
				$verified = $property_ok && $surface_ok && 'sitemaps' === (string) ( $payload['mode'] ?? '' );
				if ( ! $verified ) { $reason = 'gsc_sitemaps_surface_not_verified'; }
			} elseif ( 'search_console_url_inspection' === $action ) {
				$inspection = is_array( $payload['structuredInspection'] ?? null ) ? $payload['structuredInspection'] : ( is_array( $payload['inspection'] ?? null ) ? $payload['inspection'] : array() );
				$verified = $property_ok && ! empty( $inspection['data_verified'] ) && ! empty( $inspection['verified_fields'] );
				if ( ! $verified ) { $reason = 'gsc_url_inspection_data_not_verified'; }
				if ( $verified && self::boolean_flag( $args['request_indexing'] ?? false ) ) {
					$indexing = is_array( $payload['indexingRequest'] ?? null ) ? $payload['indexingRequest'] : array();
					$verified = ! empty( $indexing['verified'] ); if ( ! $verified ) { $reason = 'gsc_indexing_confirmation_not_verified'; }
				}
			} elseif ( 'search_console_request_indexing' === $action ) {
				$inspection = is_array( $payload['structuredInspection'] ?? null ) ? $payload['structuredInspection'] : array();
				$indexing = is_array( $payload['indexingRequest'] ?? null ) ? $payload['indexingRequest'] : array();
				$verified = $property_ok && ! empty( $inspection['data_verified'] ) && ! empty( $indexing['verified'] );
				if ( ! $verified ) { $reason = 'gsc_indexing_confirmation_not_verified'; }
			}
		}

		if ( $verified && ! $accepted_valid ) { $verified = false; $reason = 'browser_application_acceptance_invalid'; }
		if ( $verified && false === $accepted ) { $verified = false; $reason = 'browser_application_state_rejected'; }
		if ( $verified && $requires_application && true !== $accepted ) { $verified = false; $reason = 'browser_application_acceptance_not_observed'; }
		return array(
			'ok'=>$verified, 'blocking'=>false, 'verifier'=>'prstudio_uc_verifier',
			'version'=>defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : '1.0.0',
			'task_id'=>self::text_value( $task['task_uuid'] ?? '' ), 'action'=>$action, 'step_type'=>$step_type,
			'evidence_hash'=>hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $result ) ),
			'application_accepted'=>$accepted, 'effect_verified'=>$effect_verified, 'verification_strength'=>$strength,
			'reason'=>$reason, 'verified_gmt'=>gmdate( 'c' ),
		);
	}

	public static function control_receipt( string $route, string $action, array $outcome, $result ): array {
		$executed = ! empty( $outcome['executed'] ); $verified = ! empty( $outcome['verified'] ); $ok = $executed && $verified;
		$reason = ! $executed ? 'execution_not_observed' : ( ! $verified ? 'executed_effect_unverified' : '' );
		return array(
			'ok'=>$ok, 'executed'=>$executed, 'verified'=>$verified, 'degraded'=>$executed && ! $verified, 'blocking'=>false,
			'route'=>'/' . trim( $route, '/' ), 'action'=>sanitize_key( $action ),
			'outcome_hash'=>hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $outcome ) ),
			'evidence_hash'=>hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $result ) ), 'reason'=>$reason,
		);
	}
}
