<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal operator surface for PR STUDIO.
 *
 * P32 deliberately removes operational history, diagnostics and maintenance
 * controls from wp-admin. Runtime state remains available to the suite through
 * its typed MCP/capability surfaces; this page is only for connecting ChatGPT
 * and pairing Chrome.
 */
final class PRSTUDIO_UC_Admin {
	private const P32_MIGRATION_OPTION = 'prstudio_uc_p32_minimal_dashboard_migrated';

	public static function register_menu(): void {
		self::run_history_migration_once();
		add_management_page(
			'PR STUDIO Collegamenti',
			'PR STUDIO',
			'manage_options',
			'prstudio-unified-browser',
			array( __CLASS__, 'render' )
		);
	}

	public static function pairing_code_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accesso negato.', 403 );
		}
		check_admin_referer( 'prstudio_uc_pairing_code' );
		$code = PRSTUDIO_UC_Auth::create_pairing_code();
		set_transient( 'prstudio_uc_pairing_display_' . get_current_user_id(), $code, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=prstudio-unified-browser' ) );
		exit;
	}

	/**
	 * One-time P32 cleanup of admin-facing historical baggage.
	 *
	 * The migration intentionally does not touch Procedural Skills, Memory,
	 * Operational Twin, intervention/evidence stores, active devices, active
	 * browser tasks, schedules or current work sessions. Those are runtime state,
	 * not dashboard history.
	 *
	 * Existing terminal browser/task history is allowed to drain through the
	 * normal GC with compact retention; revoked Chrome identities are removed
	 * immediately because they are neither executable nor needed for pairing.
	 */
	public static function run_history_migration_once(): array {
		if ( ! function_exists( 'get_option' ) || get_option( self::P32_MIGRATION_OPTION, false ) ) {
			return array( 'migrated' => false, 'reason' => 'already_done' );
		}
		if ( ! class_exists( 'PRSTUDIO_UC_Store' ) || ! PRSTUDIO_UC_Store::schema_ready() ) {
			return array( 'migrated' => false, 'reason' => 'store_not_ready' );
		}

		$removed_revoked = 0;
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
			$deleted = $wpdb->delete(
				PRSTUDIO_UC_Store::devices_table(),
				array( 'status' => 'revoked' ),
				array( '%s' )
			);
			$removed_revoked = false === $deleted ? 0 : (int) $deleted;
		}

		$gc_report = array();
		if ( class_exists( 'PRSTUDIO_UC_GC' ) ) {
			$retention = PRSTUDIO_UC_GC::retention();
			// Keep only short operational windows required for polling/recovery.
			$retention['events_hours'] = 1;
			$retention['tasks_hours'] = 1;
			$retention['jobs_days'] = 1;
			$retention['dead_letters_days'] = 1;
			$retention['transients_hours'] = 1;
			$retention['revoked_devices_days'] = 1;
			update_option( PRSTUDIO_UC_GC::OPTION, $retention, false );
			$gc_report = PRSTUDIO_UC_GC::run( true );
			if ( defined( 'PRSTUDIO_UC_GC::LAST_RUN_OPTION' ) ) {
				delete_option( PRSTUDIO_UC_GC::LAST_RUN_OPTION );
			}
		}

		update_option(
			self::P32_MIGRATION_OPTION,
			array(
				'completed_gmt' => gmdate( 'c' ),
				'revoked_devices_removed' => $removed_revoked,
			),
			false
		);

		return array(
			'migrated' => true,
			'revoked_devices_removed' => $removed_revoked,
			'gc' => $gc_report,
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$code = get_transient( 'prstudio_uc_pairing_display_' . get_current_user_id() );
		if ( $code ) {
			delete_transient( 'prstudio_uc_pairing_display_' . get_current_user_id() );
		}
		$mcp_url = PRSTUDIO_UC_MCP_Auth_V5::mcp_url();
		?>
		<div class="wrap prstudio-connect">
			<style>
				.prstudio-connect{max-width:920px}
				.prstudio-connect h1{font-size:24px;font-weight:650;margin-bottom:6px}
				.prstudio-connect .lead{max-width:720px;color:#50575e;font-size:14px;line-height:1.55;margin-top:0}
				.prstudio-connect-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:22px}
				.prstudio-connect-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
				.prstudio-connect-card h2{font-size:17px;margin:0 0 10px}
				.prstudio-connect-card p{color:#50575e;line-height:1.5;margin:8px 0}
				.prstudio-value{display:flex;align-items:center;gap:8px;margin:14px 0;flex-wrap:wrap}
				.prstudio-value code{flex:1;min-width:220px;overflow-wrap:anywhere;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:8px;padding:11px 12px;font-size:12.5px;color:#1d2327}
				.prstudio-pair-code{font-size:18px!important;font-weight:700;letter-spacing:.04em}
				.prstudio-note{font-size:12.5px!important;color:#646970!important}
				.prstudio-copy-status{font-size:12px;color:#0a7a3f;min-height:18px}
				@media(max-width:782px){.prstudio-connect-grid{grid-template-columns:1fr}}
			</style>

			<h1>PR STUDIO — Collegamenti</h1>
			<p class="lead">Questa pagina serve solo a collegare ChatGPT e Chrome. Stato operativo, skill, memoria ed evidenze restano gestiti internamente dalla suite.</p>

			<?php if ( $code ) : ?>
				<div class="notice notice-success inline"><p><strong>Codice pairing generato.</strong> Scade tra 10 minuti.</p></div>
			<?php endif; ?>

			<div class="prstudio-connect-grid">
				<section class="prstudio-connect-card">
					<h2>1. Collega ChatGPT</h2>
					<p>In ChatGPT aggiungi il plugin, usa questo indirizzo server e scegli <strong>OAuth</strong>. Non serve creare chiavi manuali.</p>
					<div class="prstudio-value">
						<code id="prstudio-mcp-url"><?php echo esc_html( $mcp_url ); ?></code>
						<button type="button" class="button" data-copy-target="prstudio-mcp-url">Copia indirizzo</button>
					</div>
					<div class="prstudio-copy-status" aria-live="polite"></div>
				</section>

				<section class="prstudio-connect-card">
					<h2>2. Collega Chrome</h2>
					<p>Genera un codice temporaneo e inseriscilo nell’estensione PR STUDIO. Il codice scade dopo 10 minuti.</p>
					<?php if ( $code ) : ?>
						<div class="prstudio-value">
							<code id="prstudio-pair-code" class="prstudio-pair-code"><?php echo esc_html( $code ); ?></code>
							<button type="button" class="button" data-copy-target="prstudio-pair-code">Copia codice</button>
						</div>
						<div class="prstudio-copy-status" aria-live="polite"></div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="prstudio_uc_pairing_code">
						<?php wp_nonce_field( 'prstudio_uc_pairing_code' ); ?>
						<?php submit_button( $code ? 'Genera un nuovo codice' : 'Genera codice pairing', 'primary', 'submit', false ); ?>
					</form>
					<p class="prstudio-note">Il pairing attivo continua a funzionare anche se questa pagina non mostra dispositivi, sessioni o cronologia.</p>
				</section>
			</div>
		</div>
		<script>
		(() => {
			document.querySelectorAll('[data-copy-target]').forEach((button) => {
				button.addEventListener('click', async () => {
					const target = document.getElementById(button.dataset.copyTarget || '');
					if (!target) return;
					const value = target.textContent.trim();
					try {
						await navigator.clipboard.writeText(value);
						const status = button.closest('.prstudio-connect-card')?.querySelector('.prstudio-copy-status');
						if (status) status.textContent = 'Copiato.';
					} catch (_) {
						window.prompt('Copia questo valore:', value);
					}
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Compatibility no-ops for stale admin forms from pre-P32 pages cached in a
	 * browser tab. They intentionally perform no maintenance/history mutation.
	 */
	public static function revoke_device_action(): void { self::redirect_from_legacy_action( 'prstudio_uc_revoke_device' ); }
	public static function actions_key_action(): void { self::redirect_from_legacy_action( 'prstudio_uc_actions_key' ); }
	public static function maintenance_action(): void { self::redirect_from_legacy_action( 'prstudio_uc_maintenance' ); }

	private static function redirect_from_legacy_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Accesso negato.', 403 ); }
		check_admin_referer( $nonce_action );
		wp_safe_redirect( admin_url( 'tools.php?page=prstudio-unified-browser' ) );
		exit;
	}
}
