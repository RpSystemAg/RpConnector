<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PRSTUDIO_UC_Admin {
	public static function register_menu(): void {
		add_management_page(
			'PR STUDIO Unified Browser',
			'PR STUDIO Unified Browser',
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


	public static function actions_key_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Accesso negato.', 403 ); }
		check_admin_referer( 'prstudio_uc_actions_key' );
		$mode=sanitize_key((string)($_POST['key_mode']??'create'));$fingerprint=sanitize_text_field((string)($_POST['fingerprint']??''));
		if('revoke'===$mode){PRSTUDIO_UC_GPT_Actions_Auth::revoke($fingerprint,'admin_revoke');}
		elseif('rotate'===$mode){$created=PRSTUDIO_UC_GPT_Actions_Auth::rotate($fingerprint);set_transient('prstudio_uc_actions_key_display_'.get_current_user_id(),$created,10*MINUTE_IN_SECONDS);}
		else{$created=PRSTUDIO_UC_GPT_Actions_Auth::create_key();set_transient('prstudio_uc_actions_key_display_'.get_current_user_id(),$created,10*MINUTE_IN_SECONDS);}
		wp_safe_redirect( admin_url( 'tools.php?page=prstudio-unified-browser' ) ); exit;
	}

	/**
	 * Autonomy mode and retention are operator decisions, so they live in the
	 * WordPress admin rather than in a tool argument. Nothing the model sends
	 * can widen them; an argument can only ever make one call more careful.
	 */
	public static function maintenance_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Accesso negato.', 403 ); }
		check_admin_referer( 'prstudio_uc_maintenance' );

		$retention = PRSTUDIO_UC_GC::retention();
		foreach ( array_keys( $retention ) as $key ) {
			if ( isset( $_POST[ 'retention_' . $key ] ) ) {
				$retention[ $key ] = max( 1, (int) $_POST[ 'retention_' . $key ] );
			}
		}
		update_option( PRSTUDIO_UC_GC::OPTION, $retention, false );

		if ( ! empty( $_POST['run_gc_now'] ) ) {
			$report = PRSTUDIO_UC_GC::run( true );
			set_transient( 'prstudio_uc_gc_display_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
		}
		if ( isset( $_POST['long_poll'] ) ) {
			update_option( 'prstudio_uc_long_poll', 'on' === sanitize_key( (string) $_POST['long_poll'] ) ? 'on' : 'off', false );
		}
		wp_safe_redirect( admin_url( 'tools.php?page=prstudio-unified-browser' ) ); exit;
	}

	public static function revoke_device_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accesso negato.', 403 );
		}
		check_admin_referer( 'prstudio_uc_revoke_device' );
		$device_id = sanitize_text_field( (string) ( $_POST['device_id'] ?? '' ) );
		PRSTUDIO_UC_Store::revoke_device( $device_id );
		PRSTUDIO_UC_Artifacts::delete_current( $device_id );
		wp_safe_redirect( admin_url( 'tools.php?page=prstudio-unified-browser' ) );
		exit;
	}

	private static function plain_label( string $value ): string {
		$labels = array(
			'active'=>'Attivo', 'revoked'=>'Revocato', 'online'=>'Online', 'offline'=>'Offline', 'stale'=>'Non contatta da oltre 24 ore',
			'queued'=>'In attesa', 'running'=>'In esecuzione', 'completed'=>'Completato', 'failed'=>'Non riuscito',
			'cancelled'=>'Annullato', 'expired'=>'Scaduto', 'failed_nonreplayable'=>'Ambiguità tecnica terminalizzata',
		);
		$key = strtolower( trim( $value ) );
		if ( isset( $labels[ $key ] ) ) { return $labels[ $key ]; }
		$key = preg_replace( '/^(?:playwright|puppeteer)_/', '', $key );
		return ucfirst( str_replace( '_', ' ', (string) $key ) );
	}

	private static function age_label( $seconds ): string {
		if ( null === $seconds || ! is_numeric( $seconds ) ) { return 'Mai'; }
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < 60 ) { return 'Adesso'; }
		if ( $seconds < HOUR_IN_SECONDS ) { return (string) floor( $seconds / 60 ) . ' min fa'; }
		if ( $seconds < DAY_IN_SECONDS ) { return (string) floor( $seconds / HOUR_IN_SECONDS ) . ' ore fa'; }
		return (string) floor( $seconds / DAY_IN_SECONDS ) . ' giorni fa';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$code = get_transient( 'prstudio_uc_pairing_display_' . get_current_user_id() );
		if ( $code ) {
			delete_transient( 'prstudio_uc_pairing_display_' . get_current_user_id() );
		}
		$actions_key = get_transient( 'prstudio_uc_actions_key_display_' . get_current_user_id() ); if($actions_key){delete_transient('prstudio_uc_actions_key_display_'.get_current_user_id());}
		$actions_keys = PRSTUDIO_UC_GPT_Actions_Auth::metadata();
		$mcp_status = PRSTUDIO_UC_MCP_Auth_V5::status();
		$devices = PRSTUDIO_UC_Store::list_devices();
		$tasks = PRSTUDIO_UC_Store::recent_tasks( 30 );
		$work = PRSTUDIO_UC_Work_Session::active();
		$ocr = PRSTUDIO_UC_OCR::status();
		$registry = PRSTUDIO_UC_Capability_Registry::counts();
		$registry_consistency = PRSTUDIO_UC_Capability_Registry::consistency();
		$agency = class_exists( 'PRSTUDIO_UC_Agency_Runtime' ) && PRSTUDIO_UC_Store::schema_ready() ? PRSTUDIO_UC_Agency_Runtime::status() : array( 'enabled'=>false, 'schema_ready'=>false );
		$online_count = count( array_filter( $devices, static fn( $device ) => 'online' === (string) ( $device['connection_status'] ?? '' ) ) );
		$stale_count = count( array_filter( $devices, static fn( $device ) => 'stale' === (string) ( $device['connection_status'] ?? '' ) ) );
		$authorization_count = (int) ( $mcp_status['active_authorizations'] ?? 0 );
		$runner_ready = ! empty( $agency['h24']['external_runner_fresh'] );
		?>
		<div class="wrap prstudio-dashboard">
			<style>
				.prstudio-dashboard{max-width:1180px}.prstudio-dashboard .prstudio-lead{font-size:16px;max-width:820px;color:#3c434a}
				.prstudio-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:20px 0}
				.prstudio-card,.prstudio-panel,.prstudio-details{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
				.prstudio-card strong{display:block;font-size:14px;color:#50575e;margin-bottom:8px}.prstudio-card b{font-size:23px;line-height:1.2}.prstudio-ok{color:#137333}.prstudio-warn{color:#9a6700}.prstudio-muted{color:#646970}
				.prstudio-setup{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin:20px 0}.prstudio-panel h2{margin-top:0}.prstudio-url{display:block;overflow-wrap:anywhere;background:#f6f7f7;border-radius:6px;padding:10px;margin:10px 0}
				.prstudio-table td,.prstudio-table th{vertical-align:middle}.prstudio-pill{display:inline-block;border-radius:999px;padding:3px 9px;background:#f0f0f1;font-weight:600}.prstudio-pill.online{background:#e8f5e9;color:#137333}.prstudio-pill.stale{background:#fff3cd;color:#7a5200}.prstudio-pill.revoked{background:#fce8e6;color:#a50e0e}
				.prstudio-details{margin:18px 0}.prstudio-details>summary{cursor:pointer;font-size:15px}.prstudio-empty{padding:28px;text-align:center;color:#646970}
				@media(max-width:782px){.prstudio-dashboard .widefat{display:block;overflow-x:auto}.prstudio-setup{grid-template-columns:1fr}}
			</style>
			<h1>PR STUDIO Suite <?php echo esc_html( PRSTUDIO_UC_VERSION ); ?></h1>
			<p class="prstudio-lead">Collega ChatGPT e Chrome, controlla le attività e verifica subito se l’automazione è pronta. Le impostazioni avanzate restano disponibili più in basso, senza occupare la vista principale.</p>

			<div class="prstudio-cards" aria-label="Stato generale">
				<div class="prstudio-card"><strong>ChatGPT</strong><b class="<?php echo $authorization_count > 0 ? 'prstudio-ok' : 'prstudio-warn'; ?>"><?php echo $authorization_count > 0 ? 'Collegato' : 'Da collegare'; ?></b><p><?php echo esc_html( $authorization_count > 0 ? $authorization_count . ' autorizzazioni attive' : 'Segui il passaggio 1 qui sotto' ); ?></p></div>
				<div class="prstudio-card"><strong>Browser Chrome</strong><b class="<?php echo $online_count > 0 ? 'prstudio-ok' : 'prstudio-warn'; ?>"><?php echo esc_html( $online_count > 0 ? $online_count . ' online' : 'Nessuno online' ); ?></b><p><?php echo esc_html( $stale_count > 0 ? $stale_count . ' dispositivi non contattano da oltre 24 ore' : 'Il collegamento resta memorizzato anche offline' ); ?></p></div>
				<div class="prstudio-card"><strong>Automazione continua</strong><b class="<?php echo $runner_ready ? 'prstudio-ok' : 'prstudio-warn'; ?>"><?php echo $runner_ready ? 'Pronta' : 'Da completare'; ?></b><p><?php echo $runner_ready ? 'Il processo esterno risponde regolarmente.' : 'WordPress usa temporaneamente il controllo di riserva.'; ?></p></div>
				<div class="prstudio-card"><strong>Attività recenti</strong><b><?php echo esc_html( (string) count( $tasks ) ); ?></b><p><?php echo $work ? 'Una sessione di lavoro è attiva.' : 'Nessuna sessione manuale attiva.'; ?></p></div>
			</div>

			<?php if ( ! $runner_ready ) : ?><div class="notice notice-warning"><p><strong>Automazione notturna non ancora completa.</strong> Il sito continua a funzionare, ma per il lavoro H24 serve il processo esterno. <details><summary>Mostra istruzione per il tecnico</summary><p>Eseguire <code>wp prstudio agency run --limit=20</code> ogni minuto tramite lo scheduler del server.</p></details></p></div><?php endif; ?>


			<?php if ( $code ) : ?>
				<div class="notice notice-warning"><p><strong>Codice pairing:</strong> <code style="font-size:18px"><?php echo esc_html( $code ); ?></code> — scade tra 10 minuti.</p></div>
			<?php endif; ?>

			<?php if ( $actions_key ) : ?>
				<div class="notice notice-info"><p><strong>Chiave GPT Actions 4.x di compatibilità — mostrata una sola volta:</strong> <code style="font-size:16px"><?php echo esc_html( (string)$actions_key['key'] ); ?></code> — non serve per il Plugin MCP 5.0.</p></div>
			<?php endif; ?>

			<div class="prstudio-setup">
				<section class="prstudio-panel"><h2>1. Collega ChatGPT</h2><p>In ChatGPT aggiungi il plugin, incolla questo indirizzo server e scegli <strong>OAuth</strong>. Non serve creare una chiave manuale.</p><code class="prstudio-url"><?php echo esc_html( PRSTUDIO_UC_MCP_Auth_V5::mcp_url() ); ?></code><p class="prstudio-muted">Il metodo di collegamento e l’indirizzo restano invariati rispetto alle versioni precedenti.</p></section>
				<section class="prstudio-panel"><h2>2. Collega Chrome</h2><p>Genera un codice temporaneo, poi inseriscilo nel pannello dell’estensione PR STUDIO. Il codice scade dopo 10 minuti.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="prstudio_uc_pairing_code"><?php wp_nonce_field( 'prstudio_uc_pairing_code' ); ?><?php submit_button( 'Genera codice per Chrome', 'primary', 'submit', false ); ?></form></section>
			</div>

			<h2>Browser collegati</h2>
			<table class="widefat striped prstudio-table">
				<thead><tr><th>Nome</th><th>Connessione</th><th>Versione</th><th>Ultimo contatto</th><th>Azioni</th></tr></thead><tbody>
				<?php if ( ! $devices ) : ?><tr><td colspan="5" class="prstudio-empty">Nessun browser collegato. Completa il passaggio 2.</td></tr><?php endif; ?>
				<?php foreach ( $devices as $device ) : $connection = (string) ( $device['connection_status'] ?? 'offline' ); ?>
				<tr><td><strong><?php echo esc_html( (string) $device['name'] ); ?></strong><br><details><summary>Mostra ID</summary><code><?php echo esc_html( (string) $device['device_uuid'] ); ?></code></details></td><td><span class="prstudio-pill <?php echo esc_attr( $connection ); ?>"><?php echo esc_html( self::plain_label( $connection ) ); ?></span></td><td><?php echo esc_html( (string) ( $device['capabilities']['suiteVersion'] ?? $device['capabilities']['version'] ?? '—' ) ); ?></td><td><?php echo esc_html( self::age_label( $device['last_seen_age_seconds'] ?? null ) ); ?></td><td><?php if ( 'active' === $device['status'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="prstudio_uc_revoke_device"><input type="hidden" name="device_id" value="<?php echo esc_attr( (string) $device['device_uuid'] ); ?>"><?php wp_nonce_field( 'prstudio_uc_revoke_device' ); ?><button class="button" type="submit">Scollega</button></form><?php else : ?>—<?php endif; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>

			<h2>Attività recenti</h2>
			<table class="widefat striped prstudio-table"><thead><tr><th>Attività</th><th>Stato</th><th>Progresso</th><th>Aggiornata</th></tr></thead><tbody>
				<?php if ( ! $tasks ) : ?><tr><td colspan="4" class="prstudio-empty">Non ci sono ancora attività da mostrare.</td></tr><?php endif; ?>
				<?php foreach ( $tasks as $task ) : ?><tr><td><strong><?php echo esc_html( self::plain_label( (string) $task['action'] ) ); ?></strong><details><summary>Mostra ID</summary><code><?php echo esc_html( (string) $task['task_uuid'] ); ?></code></details></td><td><?php echo esc_html( self::plain_label( (string) $task['status'] ) ); ?></td><td><?php $step=(int)($task['checkpoint']['last_completed_step']??-1);echo esc_html($step>=0?'Passaggio '.($step+1).' completato':'In preparazione'); ?></td><td><?php echo esc_html( (string) $task['updated_gmt'] ); ?> UTC</td></tr><?php endforeach; ?>
				</tbody></table>

			<details class="prstudio-details">
			<summary><strong>Dettagli tecnici e diagnostica</strong></summary>
			<p>Questa sezione è destinata all’assistenza tecnica e non è necessaria per l’uso quotidiano.</p>
			<table class="widefat striped" style="max-width:1100px">
				<tbody>
					<tr><th>Versione</th><td><?php echo esc_html( PRSTUDIO_UC_VERSION ); ?></td></tr>
					<tr><th>Legacy MCP/OAuth</th><td><?php echo PRSTUDIO_UC_ENABLE_LEGACY_MCP ? 'Abilitato manualmente (compatibilità)' : 'Disabilitato — non richiesto'; ?></td></tr>
					<tr><th>Browser Runtime legacy</th><td><?php echo PRSTUDIO_UC_ENABLE_LEGACY_BROWSER_RUNTIME ? 'Abilitato manualmente' : 'Disabilitato — Agent esterno executor'; ?></td></tr>
					<tr><th>Orchestratore</th><td>10 classi / <?php echo esc_html( (string) count( PRSTUDIO_UC_Orchestrator::catalog() ) ); ?> azioni catalogate</td></tr>
					<tr><th>Capability Registry</th><td><code><?php echo esc_html( wp_json_encode( array( 'capabilities'=>$registry['capabilities']??0, 'native'=>$registry['native']??0, 'legacy_mapped'=>$registry['legacy_mapped']??0, 'consistent'=>$registry_consistency['ok']??false ) ) ); ?></code></td></tr>
					<tr><th>ChatGPT Plugin MCP 5.0</th><td><code><?php echo esc_html( PRSTUDIO_UC_MCP_Auth_V5::mcp_url() ); ?></code> — OAuth PKCE + offline_access/refresh token; Browser-first per workflow live/UI.</td></tr>
					<tr><th>OAuth Plugin</th><td><code><?php echo esc_html( wp_json_encode( $mcp_status ) ); ?></code></td></tr>
					<tr><th>Agency runtime SQL</th><td><code><?php echo esc_html( wp_json_encode( $agency ) ); ?></code></td></tr>
					<tr><th>GPT Actions 4.x</th><td>Compatibilità legacy, non necessaria per il Plugin 5.0.</td></tr>
					<tr><th>Pacing responsabile</th><td><code><?php echo esc_html( wp_json_encode( array( 'default'=>$interaction['default_profile']??'', 'profiles'=>array_keys((array)($interaction['profiles']??array())), 'anti_bot_bypass'=>false ) ) ); ?></code></td></tr>
					<tr><th>OCR</th><td><code><?php echo esc_html( wp_json_encode( $ocr ) ); ?></code></td></tr>
					<tr><th>Screenshot</th><td>Evidence immutabile con artifact ID univoco + SHA-256; retention bounded per dispositivo; filesystem privato; nessun Base64 nel database.</td></tr>
					<tr><th>Lavoro attivo</th><td><code><?php echo esc_html( $work ? wp_json_encode( array( 'work_id'=>$work['work_id']??'', 'status'=>$work['status']??'', 'anti_crash'=>$work['anti_crash']['status']??'pending', 'changes'=>count((array)($work['changes']??array())) ) ) : 'nessuno' ); ?></code></td></tr>
					<tr><th>Endpoint estensione</th><td><code><?php echo esc_html( rest_url( 'prstudio-unified/v1' ) ); ?></code></td></tr>
				</tbody>
			</table>
			</details>


			<details style="max-width:1100px;margin:22px 0">
				<summary><strong>Compatibilità GPT Actions 4.x (non richiesta in 5.0)</strong></summary>
				<p>Usare solo per client 4.x già esistenti. Il Plugin ChatGPT 5.0 usa OAuth MCP e non richiede questa chiave.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px">
					<input type="hidden" name="action" value="prstudio_uc_actions_key"><input type="hidden" name="key_mode" value="create">
					<?php wp_nonce_field( 'prstudio_uc_actions_key' ); ?><?php submit_button( 'Genera chiave compatibilità GPT Actions 4.x', 'secondary', 'submit', false ); ?>
				</form>
				<table class="widefat striped"><thead><tr><th>Fingerprint</th><th>Stato</th><th>Scope</th><th>Creata</th><th>Ultimo uso</th><th>Azioni</th></tr></thead><tbody>
				<?php foreach($actions_keys as $keymeta): ?><tr><td><code><?php echo esc_html((string)$keymeta['fingerprint']); ?></code></td><td><?php echo esc_html((string)$keymeta['status']); ?></td><td><?php echo esc_html(implode(', ',(array)$keymeta['scopes'])); ?></td><td><?php echo esc_html((string)$keymeta['created_at']); ?></td><td><?php echo esc_html((string)($keymeta['last_used']??'—')); ?></td><td><?php if('active'===(string)$keymeta['status']): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><input type="hidden" name="action" value="prstudio_uc_actions_key"><input type="hidden" name="key_mode" value="rotate"><input type="hidden" name="fingerprint" value="<?php echo esc_attr((string)$keymeta['fingerprint']); ?>"><?php wp_nonce_field('prstudio_uc_actions_key'); ?><button class="button" type="submit">Ruota</button></form> <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><input type="hidden" name="action" value="prstudio_uc_actions_key"><input type="hidden" name="key_mode" value="revoke"><input type="hidden" name="fingerprint" value="<?php echo esc_attr((string)$keymeta['fingerprint']); ?>"><?php wp_nonce_field('prstudio_uc_actions_key'); ?><button class="button" type="submit">Revoca</button></form><?php endif; ?></td></tr><?php endforeach; ?>
				</tbody></table>
			</details>

			<?php
			$retention = PRSTUDIO_UC_GC::retention();
			$last_gc = PRSTUDIO_UC_GC::last_run();
			$gc_display = get_transient( 'prstudio_uc_gc_display_' . get_current_user_id() );
			if ( $gc_display ) { delete_transient( 'prstudio_uc_gc_display_' . get_current_user_id() ); }
			$long_poll_on = 'off' !== (string) get_option( 'prstudio_uc_long_poll', 'on' );
			$interventions = PRSTUDIO_UC_Interventions::stats();
			?>
			<h2 style="margin-top:28px">Manutenzione runtime (17.0)</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:1100px">
				<input type="hidden" name="action" value="prstudio_uc_maintenance">
				<?php wp_nonce_field( 'prstudio_uc_maintenance' ); ?>
				<h3>Canale e manutenzione</h3>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row">Canale long-poll</th>
						<td>
							<label><input type="radio" name="long_poll" value="on" <?php checked( true, $long_poll_on ); ?>> Attivo (consigliato)</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="long_poll" value="off" <?php checked( false, $long_poll_on ); ?>> Disattivo</label>
							<p class="description">Con il long-poll il Browser Agent apre una richiesta sola e resta in ascolto, invece di interrogare il sito ogni frazione di secondo. Se lo disattivi il sistema continua a funzionare, ma torna a scrivere sul database molto più spesso.</p>
						</td>
					</tr>
				</tbody></table>

				<h3>Pulizia automatica del database</h3>
				<p class="description" style="max-width:70ch">Per quanti giorni o ore conservare ogni tipo di riga prima di cancellarla. La pulizia gira ogni ora a piccoli lotti, così non blocca mai il sito.</p>
				<table class="form-table" role="presentation"><tbody>
					<?php
					$labels = array(
						'events_hours' => 'Eventi (ore)',
						'tasks_hours' => 'Task conclusi (ore)',
						'jobs_days' => 'Job conclusi (giorni)',
						'dead_letters_days' => 'Dead letter (giorni)',
						'audit_days' => 'Log di audit (giorni)',
						'transients_hours' => 'Transient scaduti (ore)',
						'revisions_keep' => 'Revisioni da conservare per pagina',
						'work_sessions_days' => 'Sessioni di lavoro (giorni)',
						'schedules_days' => 'Pianificazioni (giorni)',
					);
					foreach ( $labels as $key => $label ) :
						if ( ! isset( $retention[ $key ] ) ) { continue; } ?>
						<tr>
							<th scope="row"><label for="retention_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="number" min="1" class="small-text" id="retention_<?php echo esc_attr( $key ); ?>" name="retention_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $retention[ $key ] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody></table>

				<p>
					<?php submit_button( 'Salva impostazioni', 'primary', 'submit', false ); ?>
					&nbsp;
					<button class="button" type="submit" name="run_gc_now" value="1">Esegui la pulizia adesso</button>
				</p>
			</form>

			<?php if ( is_array( $gc_display ) ) : ?>
				<div class="notice notice-success inline" style="max-width:1100px"><p>
					Pulizia eseguita in <?php echo esc_html( (string) ( $gc_display['elapsed_ms'] ?? 0 ) ); ?> ms.
					Righe rimosse: <strong><?php echo esc_html( (string) ( $gc_display['total_removed'] ?? 0 ) ); ?></strong>
					<?php if ( ! empty( $gc_display['removed'] ) ) : ?>
						(<?php echo esc_html( implode( ', ', array_map( static fn( $k, $v ): string => "$k: $v", array_keys( (array) $gc_display['removed'] ), array_values( (array) $gc_display['removed'] ) ) ) ); ?>)
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:1100px;margin-top:14px"><tbody>
				<tr><th style="width:280px">Ultima pulizia automatica</th><td><?php echo esc_html( (string) ( $last_gc['ran_gmt'] ?? 'mai eseguita' ) ); ?> — <?php echo esc_html( (string) ( $last_gc['total_removed'] ?? 0 ) ); ?> righe rimosse</td></tr>
				<tr><th>Registro interventi</th><td>
					<?php echo esc_html( (string) ( $interventions['applied'] ?? 0 ) ); ?> applicati,
					<?php echo esc_html( (string) ( $interventions['rejected'] ?? 0 ) ); ?> rifiutati,
					<?php echo esc_html( (string) ( $interventions['proposed'] ?? 0 ) ); ?> ancora da fare
				</td></tr>
				<tr><th>Classi caricate in questa richiesta</th><td><?php echo esc_html( (string) ( PRSTUDIO_UC_Autoload::stats()['loaded_this_request'] ?? 0 ) ); ?> su <?php echo esc_html( (string) ( PRSTUDIO_UC_Autoload::stats()['mapped_classes'] ?? 0 ) ); ?> disponibili</td></tr>
			</tbody></table>

		</div>
		<?php
	}
}
