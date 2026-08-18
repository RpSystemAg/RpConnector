<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PRSTUDIO_UC_Bridge {
	private static array $registered = array();

	public static function register(): void {
		if ( ! defined( 'WPAIB_DIR' ) ) {
			return;
		}
		$catalog_path = trailingslashit( WPAIB_DIR ) . 'connector/action-catalog.json';
		$catalog = is_readable( $catalog_path ) ? json_decode( (string) file_get_contents( $catalog_path ), true ) : null;
		if ( ! is_array( $catalog ) || ! is_array( $catalog['actions'] ?? null ) ) {
			return;
		}
		foreach ( $catalog['actions'] as $meta ) {
			if ( ! is_array( $meta ) || '/frontend-manage' !== (string) ( $meta['route'] ?? '' ) ) {
				continue;
			}
			$action = sanitize_key( (string) ( $meta['action'] ?? '' ) );
			if ( ! self::is_browser_action( $action ) ) {
				continue;
			}
			$hook = sanitize_key( (string) ( $meta['adapter_hook'] ?? '' ) );
			if ( '' === $hook ) {
				$hook = 'rpconnector_admin_execute_frontend_manage_' . $action;
			}
			if ( isset( self::$registered[ $hook ] ) ) {
				continue;
			}
			add_filter( $hook, array( __CLASS__, 'dispatch' ), 5, 3 );
			self::$registered[ $hook ] = true;
		}
		if ( class_exists( 'PRSTUDIO_UC_Contract' ) ) {
			foreach ( PRSTUDIO_UC_Contract::browser_overlay_actions() as $meta ) {
				$action = sanitize_key( (string) ( $meta['action'] ?? '' ) );
				if ( ! self::is_browser_action( $action ) ) { continue; }
				$hook = sanitize_key( (string) ( $meta['adapter_hook'] ?? '' ) );
				if ( '' === $hook ) { $hook = 'rpconnector_admin_execute_frontend_manage_' . $action; }
				if ( isset( self::$registered[ $hook ] ) ) { continue; }
				add_filter( $hook, array( __CLASS__, 'dispatch' ), 5, 3 );
				self::$registered[ $hook ] = true;
			}
		}
	}

	private static function canonical_browser_action( string $action ): string {
		$action = sanitize_key( $action );
		if ( 'puppeteer_screenshot' === $action || 'puppeteer_page_screenshot' === $action ) { return 'playwright_screenshot_page'; }
		if ( 'puppeteer_screenshot_element' === $action ) { return 'playwright_screenshot_element'; }
		if ( 'puppeteer_new_page' === $action ) { return 'playwright_new_page'; }
		return str_starts_with( $action, 'puppeteer_' ) ? 'playwright_' . substr( $action, 10 ) : $action;
	}

	private static function is_browser_action( string $action ): bool {
		$action = self::canonical_browser_action( $action );
		if ( str_starts_with( $action, 'search_console_' ) ) { return true; }
		if ( ! class_exists( 'PRSTUDIO_UC_Contract' ) ) { return str_starts_with( $action, 'playwright_' ); }
		$meta = PRSTUDIO_UC_Contract::by_action( '/frontend-manage', $action );
		return is_array( $meta ) && 'browser_agent' === (string) ( $meta['executor'] ?? '' );
	}

	public static function dispatch( $current, array $arguments, array $meta ) {
		if ( null !== $current ) {
			return $current;
		}
		$arguments = self::normalize_arguments( $arguments );
		$correlation_id = sanitize_text_field( (string) ( $arguments['_prstudio_correlation_id'] ?? $arguments['correlation_id'] ?? '' ) );
		$correlation_id = preg_match( '/^corr_[a-f0-9]{32}$/', $correlation_id ) ? $correlation_id : '';
		unset( $arguments['_prstudio_correlation_id'] );
		if ( '' !== $correlation_id ) { $arguments['correlation_id'] = $correlation_id; }
		$requested_action = sanitize_key( (string) ( $meta['action'] ?? $arguments['action'] ?? '' ) );
		$action = self::canonical_browser_action( $requested_action );
		if ( $requested_action !== $action ) { $arguments['_prstudio_action_alias'] = $requested_action; }
		$target = sanitize_key( (string) ( $arguments['browser_target'] ?? $arguments['target'] ?? '' ) );
		if ( '' === $target ) {
			/* Prefer the authenticated personal browser whenever an online agent exists. */
			$online = array_filter( PRSTUDIO_UC_Store::list_devices(), static fn( $device ) => ! empty( $device['online'] ) );
			$target = $online ? 'live' : 'lab';
		}
		if ( 'lab' === $target || 'local' === $target || 'playwright' === $target ) { return null; }
		if ( ! in_array( $target, array( 'live', 'personal_chrome', 'chrome_extension' ), true ) ) { return null; }
		if ( ! self::is_browser_action( $action ) ) {
			return null;
		}

		if ( 'playwright_status' === $action && ! empty( $arguments['task_id'] ) ) {
			$task_id = sanitize_text_field( (string) $arguments['task_id'] );
			$wait = max( 0, min( 20, absint( $arguments['sync_wait_seconds'] ?? $arguments['wait_seconds'] ?? 0 ) ) );
			if ( $wait > 0 && class_exists( 'PRSTUDIO_UC_Wait_Channel' ) ) {
				$outcome = PRSTUDIO_UC_Wait_Channel::wait_until(
					$wait,
					static fn() => PRSTUDIO_UC_Store::get_task( $task_id ),
					static function( $task ): bool {
						if ( ! is_array( $task ) ) { return true; }
						$status = (string) ( $task['status'] ?? '' );
						return PRSTUDIO_UC_State_Machine::is_terminal( $status );
					}
				);
				$task = is_array( $outcome['value'] ?? null ) ? $outcome['value'] : null;
			} else {
				$task = PRSTUDIO_UC_Store::get_task( $task_id );
			}
			return $task ?: new WP_Error( 'prstudio_uc_task_missing', 'Task browser non trovato.', array( 'status' => 404 ) );
		}

		/* 5.0 Browser-first: Browser contract actions execute in the paired personal Agent. */

		if ( 'playwright_status' === $action ) {
			$all_devices = PRSTUDIO_UC_Store::list_devices();
			$include_history = ! empty( $arguments['include_history'] );
			$visible_devices = $include_history ? $all_devices : array_values( array_filter( $all_devices, static fn( $device ) => 'active' === (string) ( $device['status'] ?? '' ) ) );

			// include_history returned every device ever paired -- on a site with
			// 52 of them that is ~231 KB, past the point where the transport
			// truncates. The diagnostic became unusable exactly when it was
			// needed: asking for history is what you do when something is wrong,
			// and the answer arrived cut off mid-record.
			//
			// Bound it and say so. limit/offset make the rest reachable instead
			// of silently absent, and the filters answer the question people
			// actually open this for -- which device, and what state is it in.
			$device_filter = sanitize_key( (string) ( $arguments['device_status'] ?? '' ) );
			if ( '' !== $device_filter ) {
				$visible_devices = array_values( array_filter(
					$visible_devices,
					static fn( $device ) => $device_filter === (string) ( $device['status'] ?? '' )
						|| $device_filter === (string) ( $device['connection_status'] ?? '' )
				) );
			}
			$device_id_filter = sanitize_text_field( (string) ( $arguments['device_id'] ?? '' ) );
			if ( '' !== $device_id_filter ) {
				$visible_devices = array_values( array_filter(
					$visible_devices,
					static fn( $device ) => $device_id_filter === (string) ( $device['device_uuid'] ?? '' )
				) );
			}

			$matched = count( $visible_devices );
			$limit = max( 1, min( 100, (int) ( $arguments['limit'] ?? ( $include_history ? 25 : 100 ) ) ) );
			$offset = max( 0, (int) ( $arguments['offset'] ?? 0 ) );
			$page = array_slice( $visible_devices, $offset, $limit );
			$devices = PRSTUDIO_UC_Store::public_devices( $page );
			return array(
				'available' => ! empty( array_filter( $all_devices, static fn( $device ) => ! empty( $device['online'] ) ) ),
				'provider' => 'prstudio_chrome_extension',
				'target' => 'live',
				'devices' => $devices,
				'page' => array(
					'returned' => count( $devices ),
					'matched' => $matched,
					'limit' => $limit,
					'offset' => $offset,
					'has_more' => ( $offset + count( $devices ) ) < $matched,
					'next_offset' => ( $offset + count( $devices ) ) < $matched ? $offset + $limit : null,
					'filters' => array( 'device_status' => $device_filter, 'device_id' => $device_id_filter ),
					'note' => 'Bounded so the response is never truncated in transport. Use offset, or device_id/device_status, to reach the rest.',
				),
				'device_history' => array(
					'total' => count( $all_devices ),
					'active' => count( array_filter( $all_devices, static fn( $row ) => 'active' === (string) ( $row['status'] ?? '' ) ) ),
					'online' => count( array_filter( $all_devices, static fn( $row ) => 'online' === (string) ( $row['connection_status'] ?? '' ) ) ),
					'offline' => count( array_filter( $all_devices, static fn( $row ) => 'offline' === (string) ( $row['connection_status'] ?? '' ) ) ),
					'stale' => count( array_filter( $all_devices, static fn( $row ) => 'stale' === (string) ( $row['connection_status'] ?? '' ) ) ),
					'revoked' => count( array_filter( $all_devices, static fn( $row ) => 'revoked' === (string) ( $row['status'] ?? '' ) ) ),
				),
				'ocr' => PRSTUDIO_UC_OCR::status(),
				'extension_local_studio' => class_exists( 'PRSTUDIO_UC_REST' ) ? PRSTUDIO_UC_REST::browser_extension_summary( $include_history ) : array( 'aware'=>false ),
				'integration_chain' => class_exists( 'PRSTUDIO_UC_REST' ) ? PRSTUDIO_UC_REST::integration_capabilities() : array(),
				'screenshot_storage' => class_exists( 'PRSTUDIO_UC_Artifacts' ) ? PRSTUDIO_UC_Artifacts::status() : array( 'ok'=>false ),
				'_control_outcome' => array(
					'status' => 'verified',
					'executed' => true,
					'mutated' => false,
					'verified' => true,
					'degraded' => false,
					'blocking' => false,
				),
			);
		}

		$job_uuid = sanitize_text_field( (string) ( $arguments['_prstudio_job_uuid'] ?? $arguments['job_id'] ?? '' ) );
		unset( $arguments['browser_target'], $arguments['target'], $arguments['_prstudio_job_uuid'], $arguments['job_id'] );
		$online_devices = array_values( array_filter( PRSTUDIO_UC_Store::list_devices(), static fn( $device ) => ! empty( $device['online'] ) ) );
		if ( ! $online_devices ) { return new WP_Error( 'prstudio_browser_agent_offline', 'PR STUDIO Browser Agent non è online.', array( 'status'=>503, 'devices'=>PRSTUDIO_UC_Store::public_devices( PRSTUDIO_UC_Store::list_devices() ) ) ); }
		$requested_device = ! empty( $arguments['device_id'] ) ? sanitize_text_field( (string) $arguments['device_id'] ) : (string) $online_devices[0]['device_uuid'];
		$device_id = PRSTUDIO_UC_Store::resolve_device_uuid( $requested_device, true );
		if ( null === $device_id ) {
			return new WP_Error( 'prstudio_browser_device_unavailable', 'Il Browser Agent richiesto non esiste o non è online.', array( 'status'=>409, 'devices'=>PRSTUDIO_UC_Store::public_devices( $online_devices ) ) );
		}
		unset( $arguments['device_id'] );
		$wait = max( 0, min( 20, absint( $arguments['sync_wait_seconds'] ?? 5 ) ) );
		unset( $arguments['sync_wait_seconds'] );

		$task = PRSTUDIO_UC_Job_Engine::create_browser_task( $action, $arguments, $device_id, $job_uuid );
		$task_uuid = (string) ( $task['task_uuid'] ?? '' );
		if ( '' === $task_uuid || $wait <= 0 ) {
			return self::task_response( $task );
		}

		if ( class_exists( 'PRSTUDIO_UC_Wait_Channel' ) ) {
			$outcome = PRSTUDIO_UC_Wait_Channel::wait_until(
				$wait,
				static fn() => PRSTUDIO_UC_Store::get_task( $task_uuid ),
				static function( $current_task ): bool {
					if ( ! is_array( $current_task ) ) { return false; }
					$status = (string) ( $current_task['status'] ?? '' );
					return PRSTUDIO_UC_State_Machine::is_terminal( $status );
				}
			);
			$final_task = is_array( $outcome['value'] ?? null ) ? $outcome['value'] : $task;
			return self::task_response( $final_task );
		}

		// Compatibility fallback when the wait channel is intentionally disabled.
		$deadline = microtime( true ) + $wait;
		do {
			$current_task = PRSTUDIO_UC_Store::get_task( $task_uuid );
			if ( $current_task ) {
				$status = (string) ( $current_task['status'] ?? '' );
				if ( PRSTUDIO_UC_State_Machine::is_terminal( $status ) ) {
					return self::task_response( $current_task );
				}
			}
			usleep( 100000 );
		} while ( microtime( true ) < $deadline );
		return self::task_response( PRSTUDIO_UC_Store::get_task( $task_uuid ) ?? $task );
	}


	private static function normalize_arguments( array $arguments ): array {
		foreach ( array( 'payload', 'params', 'body', 'query' ) as $container ) {
			if ( isset( $arguments[ $container ] ) && is_array( $arguments[ $container ] ) ) {
				$arguments = array_replace( $arguments[ $container ], $arguments );
			}
		}
		unset( $arguments['_route'], $arguments['_source'], $arguments['mutation'] );
		return $arguments;
	}

	private static function task_response( array $task ): array {
		$status = (string) ( $task['status'] ?? 'unknown' );
		$terminal = PRSTUDIO_UC_State_Machine::is_terminal( $status );
		$verification = is_array( $task['verification'] ?? null ) ? $task['verification'] : array();
		$executed = PRSTUDIO_UC_State_Machine::COMPLETED === $status;
		$completed_verified = $executed && ! empty( $verification['ok'] );
		// Preserve the caller's correlation id instead of discarding anything that
		// does not match the canonical `corr_<32 hex>` shape. Blanking it meant the
		// outer response carried an id while this inner payload reported "", which
		// silently broke tracing across the two halves of the same task. The value
		// is only ever echoed back, so a length-and-charset bound is the property
		// that matters; the canonical form is still reported so a consumer can tell
		// a generated id from a caller-supplied one.
		$correlation_raw = sanitize_text_field( (string) ( $task['arguments']['correlation_id'] ?? $task['result']['correlation_id'] ?? '' ) );
		$correlation_id = substr( (string) preg_replace( '/[^A-Za-z0-9_.:-]/', '', $correlation_raw ), 0, 128 );
		$correlation_canonical = 1 === preg_match( '/^corr_[a-f0-9]{32}$/', $correlation_id );

		// A cancellation is an outcome the operator asked for, not a fault. Folding
		// every non-completed terminal state into technical_error reported a
		// successful cancel as a failure -- the job really was CANCELLED, but the
		// response said the call had errored, so a caller could not tell a
		// deliberate stop from a crash. expired is likewise not a technical fault.
		if ( $completed_verified ) {
			$outcome_status = 'verified';
		} elseif ( $executed ) {
			$outcome_status = 'degraded';
		} elseif ( PRSTUDIO_UC_State_Machine::CANCELLED === $status ) {
			$outcome_status = 'cancelled';
		} elseif ( PRSTUDIO_UC_State_Machine::EXPIRED === $status ) {
			$outcome_status = 'expired';
		} elseif ( $terminal ) {
			$outcome_status = 'technical_error';
		} else {
			$outcome_status = 'queued';
		}

		return array(
			'provider' => 'prstudio_chrome_extension',
			'target' => 'live',
			'task_id' => $task['task_uuid'] ?? '',
			'correlation_id' => $correlation_id,
			'correlation_id_canonical' => $correlation_canonical,
			'status' => $status,
			'checkpoint' => $task['checkpoint'] ?? array(),
			'result' => $task['result'] ?? array(),
			'error' => $task['error'] ?? array(),
			'message' => $terminal ? 'Task terminato.' : 'Task accodato o in esecuzione.',
			'_control_outcome' => array(
				'status' => $outcome_status,
				'executed' => $executed,
				'mutated' => false,
				'verified' => $completed_verified,
				'degraded' => $executed && ! $completed_verified,
				'blocking' => false,
			),
		);
	}
}
