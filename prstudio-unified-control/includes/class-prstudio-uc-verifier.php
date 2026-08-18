<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Evidence observer only. It classifies what happened after execution and never authorizes, vetoes, rolls back or fails a mutation. */
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

		// Search Console completion is data-semantic, not merely transport/UI
		// dispatch. The Browser Agent wraps its collector result under
		// observation.data, so validate the requested surface there as well as at
		// the top level.
		$action = sanitize_key( self::text_value( $task['action'] ?? '' ) );
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
				// "Request indexing" is dispatched as a URL inspection carrying
				// request_indexing=true -- there is no distinct queued action name --
				// so without this branch the run was graded on the inspection alone
				// and reported verified while the indexing request itself was never
				// confirmed. That is a false positive on the one part the caller
				// actually asked for, so require the confirmation when it was requested.
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
			'verification_strength' => sanitize_key( self::text_value( $result['verificationStrength'] ?? $result['verification_strength'] ?? '' ) ),
			'reason' => $reason,
			'verified_gmt' => gmdate( 'c' ),
		);
		return $receipt;
	}

	public static function control_receipt( string $route, string $action, array $outcome, $result ): array {
		$executed = ! empty( $outcome['executed'] );
		$verified = ! empty( $outcome['verified'] );
		$reason = $executed && ! $verified ? 'executed_evidence_unverified' : '';
		return array(
			'ok' => true,
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
