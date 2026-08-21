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
		if ( ! array_key_exists( $key, $source ) || null === $source[ $key ] ) { return false; }
		$flag = self::boolean_flag( $source[ $key ] );
		return null === $flag ? true : $flag;
	}

	private static function text_value( $value ): string {
		return is_string( $value ) || is_int( $value ) || is_float( $value ) ? (string) $value : '';
	}

	private static function first_boolean( array $source, array $keys ): ?bool {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $source ) || null === $source[ $key ] ) { continue; }
			$flag = self::boolean_flag( $source[ $key ] );
			if ( null !== $flag ) { return $flag; }
		}
		return null;
	}

	private static function mutating_browser_action( string $action, string $step_type ): bool {
		$read_only_steps = array(
			'agent_status', 'list_tabs', 'wait', 'wait_load', 'wait_url', 'wait_selector',
			'extract_text', 'dom_snapshot', 'page_snapshot', 'accessibility_snapshot',
			'computed_styles', 'screenshot', 'screenshot_element', 'pdf', 'network_report',
			'console_report', 'page_errors', 'headers', 'service_workers', 'core_web_vitals',
			'accessibility_scan', 'observation_bundle', 'find_elements', 'verify_url',
		);
		if ( '' !== $step_type && in_array( $step_type, $read_only_steps, true ) ) { return false; }
		$read_only_actions = array(
			'playwright_content', 'playwright_dom_snapshot', 'playwright_accessibility_snapshot',
			'playwright_screenshot_page', 'playwright_screenshot_element', 'playwright_page_errors',
			'playwright_console_report', 'playwright_network_report', 'playwright_headers',
			'playwright_status', 'playwright_list_pages', 'playwright_get_pages',
		);
		if ( in_array( $action, $read_only_actions, true ) ) { return false; }
		return true;
	}

	public static function browser_result( array $task, array $result ): array {
		$verified = true === ( $result['verified'] ?? false );
		$step_value = $result['stepType'] ?? $result['step_type'] ?? '';
		$step_type = sanitize_key( self::text_value( $step_value ) );
		$args = is_array( $task['arguments'] ?? null ) ? $task['arguments'] : array();
		$postcondition = is_array( $args['postcondition'] ?? null ) ? $args['postcondition'] : ( is_array( $args['verify'] ?? null ) ? $args['verify'] : array() );
		$requires_application = self::requirement_flag( $postcondition, 'required' ) || self::requirement_flag( $args, 'require_application_acceptance' );
		$accepted_present = array_key_exists( 'applicationAccepted', $result ) || array_key_exists( 'application_accepted', $result );
		$accepted_value = $result['applicationAccepted'] ?? $result['application_accepted'] ?? null;
		$accepted = null;
		$accepted_valid = true;
		if ( $accepted_present && null !== $accepted_value ) {
			$accepted = self::boolean_flag( $accepted_value );
			$accepted_valid = null !== $accepted;
		}
		$reason = $verified ? '' : 'browser_result_missing_verified_evidence';
		$action = sanitize_key( self::text_value( $task['action'] ?? '' ) );
		$strength = sanitize_key( self::text_value( $result['verificationStrength'] ?? $result['verification_strength'] ?? '' ) );
		$effect_verified = self::first_boolean( $result, array(
			'effectVerified', 'effect_verified', 'applicationEffectVerified', 'application_effect_verified',
			'postconditionVerified', 'postcondition_verified',
		) );

		// A dispatch receipt proves only that Chrome accepted an instruction. For
		// mutating browser actions it is not evidence that the application state
		// changed. An explicit negative effect signal always wins; a weak
		// transport/UI strength cannot be promoted to verified without a positive
		// effect/application signal.
		if ( $verified && false === $effect_verified ) {
			$verified = false;
			$reason = 'browser_effect_rejected_or_unverified';
		}
		$weak_strength = in_array( $strength, array( 'transport_or_ui_dispatch', 'transport_only', 'dispatch_only', 'ui_dispatch' ), true );
		if ( $verified && $weak_strength && self::mutating_browser_action( $action, $step_type ) && true !== $effect_verified && true !== $accepted ) {
			$verified = false;
			$reason = 'browser_effect_not_verified';
		}

		// Search Console completion is data-semantic, not merely transport/UI dispatch.
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
				$url = strtolower( self::text_value( $payload['url'] ?? '' ) );
				$title = strtolower( self::text_value( $payload['title'] ?? '' ) );
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
					$verified = ! empty( $indexing['verified'] );
					if ( ! $verified ) { $reason = 'gsc_indexing_confirmation_not_verified'; }
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
		$receipt = array(
			'ok' => $verified,
			'blocking' => false,
			'verifier' => 'prstudio_uc_verifier',
			'version' => defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : '1.0.0',
			'task_id' => self::text_value( $task['task_uuid'] ?? '' ),
			'action' => $action,
			'step_type' => $step_type,
			'evidence_hash' => hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $result ) ),
			'application_accepted' => $accepted,
			'effect_verified' => $effect_verified,
			'verification_strength' => $strength,
			'reason' => $reason,
			'verified_gmt' => gmdate( 'c' ),
		);
		return $receipt;
	}

	public static function control_receipt( string $route, string $action, array $outcome, $result ): array {
		$executed = ! empty( $outcome['executed'] );
		$verified = ! empty( $outcome['verified'] );
		$ok = $executed && $verified;
		$reason = ! $executed ? 'execution_not_observed' : ( ! $verified ? 'executed_effect_unverified' : '' );
		return array(
			'ok' => $ok,
			'executed' => $executed,
			'verified' => $verified,
			'degraded' => $executed && ! $verified,
			'blocking' => false,
			'route' => '/' . trim( $route, '/' ),
			'action' => sanitize_key( $action ),
			'outcome_hash' => hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $outcome ) ),
			'evidence_hash' => hash( 'sha256', PRSTUDIO_UC_Idempotency::canonical_json( $result ) ),
			'reason' => $reason,
		);
	}
}
