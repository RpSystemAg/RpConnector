<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PRSTUDIO_Report {
	private static array $changes = array();
	private static bool $registered = false;

	public static function init(): void {
		if ( ! self::$registered ) {
			self::$registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 999 );
		}
	}

	private static function safe( $value, string $key = '', int $depth = 0 ) {
		if ( $depth > 6 ) { return '[DEPTH_LIMIT]'; }
		if ( preg_match( '/password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|authorization|cookie|iban|card/i', $key ) ) { return '[REDACTED]'; }
		if ( is_array( $value ) ) { $out = array(); foreach ( array_slice( $value, 0, 300, true ) as $k => $v ) { $out[ $k ] = self::safe( $v, (string) $k, $depth + 1 ); } return $out; }
		if ( is_object( $value ) ) { return self::safe( get_object_vars( $value ), $key, $depth + 1 ); }
		if ( is_string( $value ) && strlen( $value ) > 10000 ) { return substr( $value, 0, 10000 ) . '…'; }
		return $value;
	}

	public static function record_change( string $action, string $target, $before, $after, array $context = array() ): void {
		self::$changes[] = array(
			'action' => sanitize_text_field( $action ),
			'target' => sanitize_text_field( $target ),
			'before' => self::safe( $before ),
			'after' => self::safe( $after ),
			'context' => self::safe( $context ),
			'time_gmt' => gmdate( DATE_ATOM ),
		);
	}

	public static function flush(): void {
		if ( empty( self::$changes ) ) { return; }
		$settings = WPAIB_Auth::settings();
		if ( empty( $settings['report_enabled'] ) ) { self::$changes = array(); return; }
		$email = sanitize_email( (string) ( $settings['report_email'] ?? '' ) );
		if ( ! is_email( $email ) ) { self::$changes = array(); return; }
		$payload = array(
			'site' => home_url(),
			'bridge' => PRSTUDIO_BRIDGE_NAME,
			'version' => WPAIB_VERSION,
			'market' => array( $settings['market_country'] ?? 'IT', $settings['market_region'] ?? '', $settings['market_province'] ?? '' ),
			'changes' => self::$changes,
		);
		$subject = '[PR STUDIO] Modifiche eseguite - ' . wp_date( 'd/m/Y H:i:s' );
		$body = "PR STUDIO AI BRIDGE\nSito: " . home_url() . "\nData GMT: " . gmdate( DATE_ATOM ) . "\n\n";
		foreach ( self::$changes as $index => $change ) {
			$body .= ( $index + 1 ) . '. ' . $change['action'] . ' — ' . $change['target'] . "\n";
			$body .= 'Prima: ' . wp_json_encode( $change['before'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
			$body .= 'Dopo: ' . wp_json_encode( $change['after'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n";
		}
		$sent = wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		WPAIB_Audit::log( 'prstudio.report.email', $sent ? 'success' : 'error', $email, array( 'change_count' => count( self::$changes ) ) );
		if ( ! $sent ) {
			$outbox = get_option( 'prstudio_report_outbox', array() );
			$outbox = is_array( $outbox ) ? $outbox : array();
			$outbox[] = array( 'email' => $email, 'subject' => $subject, 'body' => $body, 'payload' => $payload, 'attempts' => 0, 'created_at' => gmdate( DATE_ATOM ) );
			update_option( 'prstudio_report_outbox', array_slice( $outbox, -100 ), false );
		}
		self::$changes = array();
	}

	public static function retry_outbox(): void {
		$outbox = get_option( 'prstudio_report_outbox', array() );
		if ( ! is_array( $outbox ) || empty( $outbox ) ) { return; }
		$remaining = array();
		foreach ( array_slice( $outbox, 0, 10 ) as $item ) {
			$sent = wp_mail( sanitize_email( $item['email'] ?? '' ), sanitize_text_field( $item['subject'] ?? '' ), (string) ( $item['body'] ?? '' ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
			if ( ! $sent ) {
				$item['attempts'] = (int) ( $item['attempts'] ?? 0 ) + 1;
				if ( $item['attempts'] < 12 ) { $remaining[] = $item; }
			}
		}
		$remaining = array_merge( $remaining, array_slice( $outbox, 10 ) );
		update_option( 'prstudio_report_outbox', $remaining, false );
	}
}
