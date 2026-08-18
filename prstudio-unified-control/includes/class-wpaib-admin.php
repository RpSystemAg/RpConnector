<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPAIB_Admin {
	public static function register_menu(): void { add_management_page( 'PR STUDIO AI BRIDGE', 'PR STUDIO AI BRIDGE', WPAIB_Auth::admin_capability(), 'wp-ai-bridge', array( __CLASS__, 'render' ) ); }
	public static function handle_actions(): void {
		if ( ! is_admin() || ! WPAIB_Auth::can_administer() || empty( $_POST['prstudio_bridge_action'] ) ) { return; }
		check_admin_referer( 'prstudio_bridge_admin' ); $action = sanitize_key( (string) wp_unslash( $_POST['prstudio_bridge_action'] ) ); $notice = 'saved';
		if ( 'save' === $action ) {
			WPAIB_Auth::update_settings( array(
				'max_file_bytes' => max( 1048576, min( 33554432, (int) ( $_POST['max_file_bytes'] ?? 8388608 ) ) ), 'rate_limit_per_min' => max( 10, min( 1000, (int) ( $_POST['rate_limit_per_min'] ?? 600 ) ) ), 'report_email' => sanitize_email( wp_unslash( (string) ( $_POST['report_email'] ?? '' ) ) ), 'report_enabled' => ! empty( $_POST['report_enabled'] ), 'market_country' => sanitize_text_field( wp_unslash( (string) ( $_POST['market_country'] ?? 'IT' ) ) ), 'market_region' => sanitize_text_field( wp_unslash( (string) ( $_POST['market_region'] ?? 'Sicilia' ) ) ), 'market_province' => sanitize_text_field( wp_unslash( (string) ( $_POST['market_province'] ?? 'Agrigento' ) ) ),
			) );
		} elseif ( 'rotate_key' === $action ) { $key = WPAIB_Auth::rotate_pairing_key(); set_transient( 'prstudio_pairing_key_' . get_current_user_id(), $key, 300 ); $notice = 'key_rotated'; }
		elseif ( 'revoke_tokens' === $action ) { WPAIB_Auth::revoke_all( false ); $notice = 'tokens_revoked'; }
		elseif ( 'test_email' === $action ) { $settings = WPAIB_Auth::settings(); $ok = wp_mail( (string) $settings['report_email'], '[PR STUDIO] Test report bridge', "PR STUDIO AI BRIDGE è configurato per inviare i report delle modifiche.\nSito: " . home_url() ); WPAIB_Audit::log( 'report.test', $ok ? 'success' : 'error', (string) $settings['report_email'] ); $notice = $ok ? 'email_sent' : 'email_failed'; }
		wp_safe_redirect( add_query_arg( 'prstudio_notice', $notice, admin_url( 'tools.php?page=wp-ai-bridge' ) ) ); exit;
	}
	private static function checked_setting( array $settings, string $key ): void { checked( ! empty( $settings[ $key ] ) ); }
	public static function render(): void {
		if ( ! WPAIB_Auth::can_administer() ) { wp_die( esc_html__( 'Accesso negato.', 'prstudio-unified-control' ) ); }
		$settings = WPAIB_Auth::settings(); $status = WPAIB_Site::status(); $agency = PRSTUDIO_Agency::status(); $search_console = PRSTUDIO_UC_Search_Console_Browser::status(); $key = get_transient( 'prstudio_pairing_key_' . get_current_user_id() ); if ( $key ) { delete_transient( 'prstudio_pairing_key_' . get_current_user_id() ); }
		$notice = sanitize_key( (string) ( $_GET['prstudio_notice'] ?? '' ) );
		$notice_error = in_array( $notice, array( 'email_failed' ), true );
		?>
		<div class="wrap"><h1>PR STUDIO AI BRIDGE</h1>
		<p>Bridge OAuth 2.1/MCP e OpenAPI privato per WordPress e WooCommerce. Il catalogo OpenAPI viene esposto anche come strumenti MCP indipendenti.</p>
		<?php if ( $notice ) : ?><div class="notice <?php echo $notice_error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( str_replace( '_', ' ', $notice ) ); ?></p></div><?php endif; ?>
		<?php if ( $key ) : ?><div class="notice notice-warning"><p><strong>Chiave di collegamento, mostrata una sola volta:</strong> usala nel flusso OAuth oppure come valore dell’header <code>X-IM-Admin-Key</code> previsto dal documento OpenAPI.</p><p><code style="font-size:14px;user-select:all"><?php echo esc_html( $key ); ?></code></p></div><?php endif; ?>
		<table class="widefat striped" style="max-width:1100px;margin:18px 0"><tbody>
		<tr><th>Endpoint MCP</th><td><code><?php echo esc_html( WPAIB_Auth::mcp_url() ); ?></code></td></tr><tr><th>Endpoint OpenAPI</th><td><code><?php echo esc_html( rest_url( 'rpconnector-admin/v1' ) ); ?></code></td></tr><tr><th>Versione</th><td><?php echo esc_html( WPAIB_VERSION ); ?></td></tr><tr><th>Strumenti OpenAPI → MCP</th><td><?php echo esc_html( (string) ( $agency['openapi_action_count'] ?? 0 ) ); ?> — hash: <code><?php echo esc_html( (string) ( $agency['tool_registry']['registry_hash'] ?? '' ) ); ?></code></td></tr><tr><th>Azioni enterprise legacy</th><td><?php echo esc_html( (string) $agency['action_count'] ); ?> — modello esecuzione: <?php echo esc_html( (string) ( $agency['execution_model'] ?? '' ) ); ?></td></tr><tr><th>Mercato</th><td><?php echo esc_html( $settings['market_country'] . ' / ' . $settings['market_region'] . ' / ' . $settings['market_province'] ); ?></td></tr><tr><th>Audit</th><td><?php echo esc_html( WPAIB_Audit::table_name() ); ?></td></tr>
		</tbody></table>
		<form method="post"><?php wp_nonce_field( 'prstudio_bridge_admin' ); ?><input type="hidden" name="prstudio_bridge_action" value="save">
		<h2>Capacità</h2><table class="form-table"><tbody>
		<?php foreach ( array( 'report_enabled' => 'Report email dopo ogni modifica' ) as $field => $label ) : ?><tr><th><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>" value="1" <?php self::checked_setting( $settings, $field ); ?>> Abilitato</label></td></tr><?php endforeach; ?>
		<tr><th>Limite file</th><td><input type="number" name="max_file_bytes" value="<?php echo esc_attr( (string) $settings['max_file_bytes'] ); ?>" min="1048576" max="33554432"></td></tr><tr><th>Rate limit/minuto</th><td><input type="number" name="rate_limit_per_min" value="<?php echo esc_attr( (string) $settings['rate_limit_per_min'] ); ?>" min="10" max="1000"></td></tr><tr><th>Email report</th><td><input type="email" class="regular-text" name="report_email" value="<?php echo esc_attr( (string) $settings['report_email'] ); ?>"></td></tr>
		<tr><th>Mercato</th><td><input name="market_country" value="<?php echo esc_attr( (string) $settings['market_country'] ); ?>" size="8"> <input name="market_region" value="<?php echo esc_attr( (string) $settings['market_region'] ); ?>"> <input name="market_province" value="<?php echo esc_attr( (string) $settings['market_province'] ); ?>"></td></tr>
		</tbody></table><?php submit_button( 'Salva configurazione' ); ?></form>
		<hr><h2>Google Search Console tramite Browser Agent</h2>
		<p>Usa la sessione Google già presente nel Chrome personale. Login, MFA e CAPTCHA restano sotto il controllo dell’utente.</p>
		<table class="widefat striped" style="max-width:1100px;margin:14px 0"><tbody>
		<tr><th style="width:220px">Stato</th><td><strong><?php echo esc_html( ! empty( $search_console['connected'] ) ? 'Browser Agent online' : 'Browser Agent da collegare' ); ?></strong></td></tr>
		<tr><th>Provider</th><td><code>prstudio-browser-agent-same-profile</code></td></tr>
		<tr><th>URL</th><td><code><?php echo esc_html( (string) $search_console['url'] ); ?></code></td></tr>
		<tr><th>Autenticazione Google</th><td>Gestita esclusivamente dall’utente nel proprio browser.</td></tr>
		</tbody></table>
		<hr><h2>OAuth MCP e manutenzione</h2><div style="display:flex;gap:12px;flex-wrap:wrap">
		<form method="post"><?php wp_nonce_field( 'prstudio_bridge_admin' ); ?><input type="hidden" name="prstudio_bridge_action" value="rotate_key"><?php submit_button( 'Genera nuova chiave di collegamento', 'secondary', 'submit', false ); ?></form>
		<form method="post"><?php wp_nonce_field( 'prstudio_bridge_admin' ); ?><input type="hidden" name="prstudio_bridge_action" value="revoke_tokens"><?php submit_button( 'Revoca token OAuth', 'delete', 'submit', false ); ?></form>
		<form method="post"><?php wp_nonce_field( 'prstudio_bridge_admin' ); ?><input type="hidden" name="prstudio_bridge_action" value="test_email"><?php submit_button( 'Invia email di test', 'secondary', 'submit', false ); ?></form></div>
		<h2>Stato tecnico</h2><pre style="max-width:1100px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:15px"><?php echo esc_html( wp_json_encode( array( 'site' => $status, 'agency' => $agency ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
		</div><?php
	}
}
