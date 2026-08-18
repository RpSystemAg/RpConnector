<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PRSTUDIO_Agency {
	private const JOBS = 'prstudio_agency_jobs';
	private const SCHEDULES = 'prstudio_agency_schedules';
	private const CONFIG = 'prstudio_agency_config';
	private const JOB_BACKUPS = 'prstudio_agency_job_purge_backups';
	private const PURGE_LOCK = 'prstudio_agency_job_purge_lock';
	private const VERSION = '1.5.0';
	private const CRON_HOOK = 'prstudio_agency_cron_tick';
	private const ACTIVE_JOB_STATUSES = array( 'queued', 'running' );
	private const TERMINAL_JOB_STATUSES = array( 'completed', 'degraded', 'technical_error', 'cancelled' );


	private static function clear_legacy_cron(): void {
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK ) ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) { wp_clear_scheduled_hook( self::CRON_HOOK ); return; }
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp && function_exists( 'wp_unschedule_event' ) ) { wp_unschedule_event( $timestamp, self::CRON_HOOK ); }
		}
	}

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_tick' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'mcp_pre_dispatch' ), 5, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'mcp_after_callbacks' ), 20, 3 );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_event( time() + 120, 'prstudio_five_minutes', self::CRON_HOOK ); }
	}
	public static function activate(): void { self::init(); }
	public static function deactivate(): void { self::clear_legacy_cron(); }
	public static function cron_schedules( array $schedules ): array { $schedules['prstudio_five_minutes'] = array( 'interval' => 300, 'display' => 'PR STUDIO ogni 5 minuti' ); return $schedules; }

	private static function groups(): array {
		return array(
			'Commerce & Merchandising' => 'catalog_enrich_bulk,bundle_builder,marketplace_catalog_sync,personalized_merchandising,price_competitor_scan,dynamic_pricing_engine,promotion_engine_schedule,multi_currency_price_set',
			'Supply Chain, Logistica & Fulfillment' => 'carrier_rate_shopping,rma_process,delivery_tracking_notify,generate_shipping_label,fulfillment_order_route,purchase_order_create,warehouse_stock_sync',
			'Finance & Accounting' => 'invoice_generate,tax_calculate,reconcile_payment_gateway,financial_report_generate,subscription_billing_manage,chargeback_dispute_handle,accounting_software_sync,currency_rates_update',
			'Customer Experience & Support' => 'support_ticket_create_route,chatbot_response_generate,live_chat_handoff,refund_process,review_request_automate,csat_nps_survey_send,loyalty_program_manage,customer_360_lookup',
			'Legal & Compliance' => 'cookie_consent_manage,privacy_policy_update,terms_conditions_generate,gdpr_ccpa_data_request,accessibility_audit,vendor_contract_manage,ip_trademark_monitor,regulatory_compliance_check',
			'Infrastruttura, Sicurezza & DevOps' => 'performance_monitor,cdn_cache_manage,deploy_pipeline_trigger,database_optimize,security_scan,waf_rule_manage,ssl_cert_manage,disaster_recovery_failover,load_test_run',
			'Dati & Business Intelligence' => 'data_warehouse_sync,recommendation_engine_train,recommendation_engine_serve,ab_test_create,ab_test_status,cohort_analysis,churn_prediction,ltv_calculate,attribution_model_report',
			'SEO & Search Console' => 'search_console_status,search_console_sites,search_console_search_analytics,search_console_sitemaps,search_console_url_inspection',
			'Advertising & Paid Media' => 'ad_campaign_create,ad_budget_pacing_adjust,bid_optimize,creative_ab_test,roas_report,retargeting_audience_sync',
			'Partnership, Marketplace & Affiliate' => 'marketplace_onboarding,affiliate_program_manage,influencer_collab_track,b2b_wholesale_portal_manage,partner_api_integration_config',
			'Internazionalizzazione & Espansione' => 'geo_redirect_config,cross_border_duty_calculate,local_payment_method_enable,market_entry_compliance_check',
			'PR & Reputazione' => 'press_release_distribute,media_monitoring_search,review_platform_monitor,crisis_alert_detect,reputation_score_report',
			'Strategia, R&D & Competitive Intelligence' => 'competitor_price_monitor,market_trend_analysis,feature_flag_manage,product_roadmap_track,experiment_pipeline_manage',
			'Direzione & Orchestrazione' => 'agent_role_define,task_assign_route,escalation_handle,department_kpi_dashboard,budget_allocate_across_divisions,org_chart_config',
		);
	}

	private static function descriptions(): array {
		return array(
			'catalog_enrich_bulk' => 'Arricchisce in batch descrizioni, attributi e metadati catalogo.', 'bundle_builder' => 'Crea o aggiorna bundle e relazioni prodotto.', 'marketplace_catalog_sync' => 'Sincronizza dati catalogo con marketplace configurati.', 'personalized_merchandising' => 'Gestisce ordinamento e merchandising contestuale.', 'price_competitor_scan' => 'Analizza prezzi pubblici dei concorrenti.',
			'performance_monitor' => 'Monitora uptime, HTTP e indicatori tecnici.', 'security_scan' => 'Esegue analisi statica di sicurezza del sito.', 'financial_report_generate' => 'Genera report commerciali aggregati.', 'department_kpi_dashboard' => 'Calcola KPI operativi aggregati.', 'market_trend_analysis' => 'Analizza trend di mercato italiano e locale.',
			'search_console_status' => 'Verifica il Browser Agent e la sessione Search Console nel Chrome personale.', 'search_console_sites' => 'Elenca le proprietà Google Search Console disponibili.', 'search_console_search_analytics' => 'Legge clic, impressioni, CTR e posizione da Search Console.', 'search_console_sitemaps' => 'Legge le sitemap registrate in Search Console.', 'search_console_url_inspection' => 'Ispeziona lo stato di indicizzazione di un URL.',
			'task_assign_route' => 'Assegna un task persistente a un reparto.', 'escalation_handle' => 'Registra e instrada un’eccezione.', 'product_roadmap_track' => 'Salva e aggiorna la roadmap operativa.', 'experiment_pipeline_manage' => 'Gestisce esperimenti e criteri di successo.',
		);
	}

	private static function high_risk(): array {
		return array( 'refund_process','subscription_billing_manage','chargeback_dispute_handle','gdpr_ccpa_data_request','vendor_contract_manage','database_optimize','waf_rule_manage','ssl_cert_manage','disaster_recovery_failover','deploy_pipeline_trigger','load_test_run','ad_budget_pacing_adjust','bid_optimize','partner_api_integration_config','local_payment_method_enable','budget_allocate_across_divisions','dynamic_pricing_engine','promotion_engine_schedule','multi_currency_price_set','generate_shipping_label','fulfillment_order_route','purchase_order_create','warehouse_stock_sync' );
	}
	private static function read_only(): array { return array( 'search_console_status','search_console_sites','search_console_search_analytics','search_console_sitemaps','search_console_url_inspection','price_competitor_scan','carrier_rate_shopping','tax_calculate','financial_report_generate','customer_360_lookup','accessibility_audit','ip_trademark_monitor','regulatory_compliance_check','performance_monitor','security_scan','ab_test_status','cohort_analysis','churn_prediction','ltv_calculate','attribution_model_report','roas_report','cross_border_duty_calculate','market_entry_compliance_check','media_monitoring_search','review_platform_monitor','crisis_alert_detect','reputation_score_report','competitor_price_monitor','market_trend_analysis','department_kpi_dashboard' ); }
	private static function native_actions(): array { return array( 'search_console_status','search_console_sites','search_console_search_analytics','search_console_sitemaps','search_console_url_inspection','financial_report_generate','performance_monitor','security_scan','department_kpi_dashboard','task_assign_route','accessibility_audit','catalog_enrich_bulk','deploy_pipeline_trigger','cdn_cache_manage','database_optimize','escalation_handle','agent_role_define','org_chart_config','feature_flag_manage','product_roadmap_track','experiment_pipeline_manage','budget_allocate_across_divisions' ); }
	private static function stored_only_actions(): array { return array(); }
	private static function continuation_actions(): array { return array( 'market_trend_analysis','competitor_price_monitor','price_competitor_scan','media_monitoring_search','review_platform_monitor','reputation_score_report' ); }
	private static function capability_class( string $name, bool $read_only = false ): string {
		if ( in_array( $name, self::stored_only_actions(), true ) ) { return 'native_state'; }
		if ( in_array( $name, self::native_actions(), true ) ) { return $read_only ? 'native_read' : 'native_mutation'; }
		if ( class_exists( 'PRSTUDIO_UC_Agency_Action_Executor' ) && PRSTUDIO_UC_Agency_Action_Executor::supports( $name ) ) { return $read_only ? 'semantic_read' : 'semantic_mutation'; }
		return 'binding_contract_error';
	}

	public static function actions(): array {
		static $actions = null; if ( null !== $actions ) { return $actions; } $actions = array(); $descriptions = self::descriptions();
		foreach ( self::groups() as $division => $csv ) {
			foreach ( explode( ',', $csv ) as $name ) {
				$risk = in_array( $name, self::high_risk(), true ) ? 'critical' : ( in_array( $name, array( 'marketplace_catalog_sync','invoice_generate','accounting_software_sync','cookie_consent_manage','privacy_policy_update','terms_conditions_generate','cdn_cache_manage','data_warehouse_sync','ad_campaign_create','retargeting_audience_sync','marketplace_onboarding','affiliate_program_manage','geo_redirect_config','press_release_distribute','feature_flag_manage','org_chart_config' ), true ) ? 'high' : ( in_array( $name, self::read_only(), true ) ? 'low' : 'medium' ) );
				$read_only = in_array( $name, self::read_only(), true );
				$capability_class = self::capability_class( $name, $read_only );
				$actions[ $name ] = array( 'name' => $name, 'division' => $division, 'description' => $descriptions[ $name ] ?? ( 'Esegue il processo enterprise PR STUDIO “' . str_replace( '_', ' ', $name ) . '”.' ), 'risk' => $risk, 'read_only' => $read_only, 'native' => 0 === strpos( $capability_class, 'native_' ) || 0 === strpos( $capability_class, 'semantic_' ), 'capability_class' => $capability_class, 'destructive' => in_array( $name, self::high_risk(), true ), 'idempotent' => ! in_array( $name, array( 'refund_process','invoice_generate','support_ticket_create_route','live_chat_handoff','csat_nps_survey_send','ad_campaign_create','creative_ab_test','marketplace_onboarding','affiliate_program_manage','press_release_distribute' ), true ), 'adapter_hook' => 'prstudio_ai_bridge_execute_' . $name );
			}
		}
		return $actions;
	}


	private static function control_catalog_data(): array {
		static $catalog = null;
		if ( null !== $catalog ) { return $catalog; }
		$base = defined( 'WPAIB_DIR' ) ? WPAIB_DIR : trailingslashit( dirname( __DIR__ ) );
		$path = $base . 'connector/action-catalog.json';
		if ( ! is_readable( $path ) ) { return $catalog = array( 'version' => 'missing', 'count' => 0, 'registry_hash' => '', 'routes' => array(), 'actions' => array() ); }
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['actions'] ) || ! is_array( $decoded['actions'] ) ) { return $catalog = array( 'version' => 'invalid', 'count' => 0, 'registry_hash' => '', 'routes' => array(), 'actions' => array() ); }
		return $catalog = $decoded;
	}

	public static function control_registry_info(): array {
		$catalog = self::control_catalog_data();
		return array(
			'version' => (string) ( $catalog['version'] ?? '' ),
			'count' => (int) ( $catalog['count'] ?? count( $catalog['actions'] ?? array() ) ),
			'unique_action_names' => (int) ( $catalog['unique_action_names'] ?? 0 ),
			'registry_hash' => (string) ( $catalog['registry_hash'] ?? '' ),
			'source_openapi_version' => (string) ( $catalog['source_openapi_version'] ?? '' ),
		);
	}

	public static function control_routes(): array {
		$catalog = self::control_catalog_data();
		return is_array( $catalog['routes'] ?? null ) ? $catalog['routes'] : array();
	}

	/**
	 * Canonical read-only overrides for inspection, preview and validation actions.
	 *
	 * These operations share route families that also contain mutations, so route-
	 * level inference in older generated catalogs classified them too broadly.
	 * Keep the correction keyed by the exact route/action pair: similarly named
	 * mutating operations must continue to require write scope and lane governance.
	 */
	private static function read_only_control_actions(): array {
		return array(
			'/system-manage' => array( 'get_runtime_config' ),
			'/global-search' => array( 'preview_replace', 'verify_replace', 'preview_regex_replace', 'preview_url_replace' ),
			'/cache-manage' => array( 'get_rewrite_rules' ),
			'/content-manage' => array( 'list_post_types', 'get_post_type', 'validate_blocks' ),
			'/widgets-manage' => array( 'list_widget_types' ),
			'/templates-manage' => array( 'list_block_templates', 'get_block_template' ),
			'/plugins-manage' => array( 'inspect_plugin_settings', 'inspect_plugin_rest_routes', 'inspect_plugin_blocks', 'inspect_plugin_assets' ),
			'/themes-manage' => array( 'inspect_theme_assets', 'inspect_theme_rest_routes', 'inspect_theme_blocks' ),
			'/seo-manage' => array( 'audit_news_seo' ),
			'/files-manage' => array( 'audit_run_batch' ),
			'/maintenance-manage' => array( 'list_updates' ),
			'/frontend-manage' => array( 'query_selector' ),
		);
	}

	private static function normalize_control_action_metadata( array $meta ): array {
		$route = '/' . trim( (string) ( $meta['route'] ?? '' ), '/' );
		$action = (string) ( $meta['action'] ?? '' );
		$read_actions = self::read_only_control_actions();
		if ( ! isset( $read_actions[ $route ] ) || ! in_array( $action, $read_actions[ $route ], true ) ) {
			return $meta;
		}

		$meta['read_only'] = true;
		$meta['destructive'] = false;
		$meta['idempotent'] = true;
		$meta['risk'] = 'low';
		return $meta;
	}

	public static function control_actions(): array {
		static $actions = null;
		if ( null !== $actions ) { return $actions; }
		$actions = array();
		foreach ( self::control_catalog_data()['actions'] ?? array() as $meta ) {
			if ( ! is_array( $meta ) || empty( $meta['tool_name'] ) || empty( $meta['route'] ) || empty( $meta['action'] ) ) { continue; }
			$meta = self::normalize_control_action_metadata( $meta );
			$actions[ (string) $meta['tool_name'] ] = $meta;
		}
		ksort( $actions );
		return $actions;
	}

	public static function control_action_by_tool( string $name ): ?array {
		$actions = self::control_actions();
		return isset( $actions[ $name ] ) ? $actions[ $name ] : null;
	}

	public static function control_action_by_route( string $route, string $action ): ?array {
		static $index = null;
		if ( null === $index ) {
			$index = array();
			foreach ( self::control_actions() as $meta ) { $index[ (string) $meta['route'] ][ (string) $meta['action'] ] = $meta; }
		}
		$route = '/' . trim( $route, '/' );
		return isset( $index[ $route ][ $action ] ) ? $index[ $route ][ $action ] : null;
	}

	public static function control_tools(): array {
		$tools = array();
		foreach ( self::control_actions() as $meta ) {
			$tool = self::tool(
				(string) $meta['tool_name'],
				(string) ( $meta['title'] ?? $meta['tool_name'] ),
				(string) ( $meta['description'] ?? '' ),
				is_array( $meta['input_schema'] ?? null ) ? $meta['input_schema'] : self::schema( array(), array(), true ),
				! empty( $meta['read_only'] ),
				! empty( $meta['destructive'] ),
				! empty( $meta['idempotent'] )
			);
			$tool['outputSchema'] = is_array( $meta['output_schema'] ?? null ) ? $meta['output_schema'] : array( 'type' => 'object', 'additionalProperties' => true );
			$tool['_meta']['idealmarket'] = array( 'route' => $meta['route'], 'action' => $meta['action'], 'operationId' => $meta['operation_id'] ?? '', 'registryHash' => self::control_registry_info()['registry_hash'], 'provider' => 'wordpress_native_and_semantic_executor_with_explicit_plan_compiler', 'externalWorkerRequired' => false, 'createsPendingJob' => false );
			$tools[] = $tool;
		}
		return $tools;
	}

	private static function schema( array $properties = array(), array $required = array(), bool $additional = false ): array { $schema = array( 'type' => 'object', 'properties' => $properties ?: new stdClass(), 'additionalProperties' => $additional ); if ( $required ) { $schema['required'] = $required; } return $schema; }
	private static function tool( string $name, string $title, string $description, array $schema, bool $read_only = false, bool $destructive = false, bool $idempotent = true ): array {
		$scopes = $read_only ? array( 'wp_ai_bridge.read' ) : array( 'wp_ai_bridge.read','wp_ai_bridge.write' ); $security = array( array( 'type' => 'oauth2', 'scopes' => $scopes ) );
		return array( 'name' => $name, 'title' => $title, 'description' => $description, 'inputSchema' => $schema, 'outputSchema' => array( 'type' => 'object', 'additionalProperties' => true ), 'securitySchemes' => $security, '_meta' => array( 'securitySchemes' => $security, 'ui' => array( 'visibility' => array( 'model','app' ) ) ), 'annotations' => array( 'readOnlyHint' => $read_only, 'destructiveHint' => $destructive, 'idempotentHint' => $idempotent, 'openWorldHint' => true ) );
	}

	public static function tools(): array {
		$common = self::schema( array( 'payload' => array( 'type' => 'object', 'additionalProperties' => true ), 'execution_mode' => array( 'type' => 'string', 'enum' => array( 'preview','queue','run','schedule' ), 'default' => 'preview' ), 'schedule' => array( 'type' => 'object', 'additionalProperties' => true ), 'idempotency_key' => array( 'type' => 'string', 'maxLength' => 160 ), 'priority' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 255, 'default' => 10 ) ) );
		$tools = array(
			self::tool( 'enterprise_action_catalog', 'PR STUDIO Catalogo azioni', 'Elenca tutte le azioni operative e il relativo executor server-side verificato.', self::schema( array( 'division' => array( 'type' => 'string' ), 'risk' => array( 'type' => 'string' ), 'search' => array( 'type' => 'string' ) ) ), true ),
			self::tool( 'enterprise_action_execute', 'PR STUDIO Esegui azione', 'Anteprima, esegue o programma un’azione. Risolve sempre server-side tramite provider nativo, adapter registrato o plan compiler backend; non delega l’esecuzione al client.', self::schema( array( 'action' => array( 'type' => 'string' ), 'payload' => array( 'type' => 'object', 'additionalProperties' => true ), 'execution_mode' => array( 'type' => 'string', 'enum' => array( 'preview','queue','run','schedule' ), 'default' => 'preview' ), 'schedule' => array( 'type' => 'object', 'additionalProperties' => true ), 'idempotency_key' => array( 'type' => 'string' ), 'priority' => array( 'type' => 'integer' ) ), array( 'action' ) ) ),
			self::tool( 'enterprise_orchestration_status', 'PR STUDIO Stato orchestrazione', 'Legge backend, job, schedule, report e capacità fallback.', self::schema(), true ),
			self::tool( 'enterprise_job_list', 'PR STUDIO Elenca job', 'Elenca job persistenti.', self::schema( array( 'status' => array( 'type' => 'string' ), 'action' => array( 'type' => 'string' ), 'division' => array( 'type' => 'string' ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ) ) ), true ),
			self::tool( 'enterprise_job_get', 'PR STUDIO Leggi job', 'Legge un job completo.', self::schema( array( 'job_id' => array( 'type' => 'string' ) ), array( 'job_id' ) ), true ),
			self::tool( 'enterprise_job_cancel', 'PR STUDIO Annulla job', 'Annulla un job non concluso.', self::schema( array( 'job_id' => array( 'type' => 'string' ), 'expected_status' => array( 'type' => 'string' ) ), array( 'job_id' ) ) ),
			self::tool( 'enterprise_job_retry', 'PR STUDIO Riprova job', 'Ricrea in coda un job fallito, bloccato o annullato conservando azione e payload.', self::schema( array( 'job_id' => array( 'type' => 'string' ), 'expected_status' => array( 'type' => 'string' ), 'idempotency_key' => array( 'type' => 'string' ), 'priority' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 255 ) ), array( 'job_id' ) ) ),
			self::tool( 'enterprise_job_delete', 'PR STUDIO Elimina job', 'Elimina un singolo job terminale con controllo dello stato atteso.', self::schema( array( 'job_id' => array( 'type' => 'string' ), 'expected_status' => array( 'type' => 'string' ), 'force' => array( 'type' => 'boolean', 'default' => false ) ), array( 'job_id' ) ), false, true, false ),
			self::tool( 'enterprise_job_purge', 'PR STUDIO Purge job', 'Anteprima o elimina i job filtrati con conteggio atteso, lock esclusivo, backup e verifica finale.', self::schema( array( 'statuses' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'action' => array( 'type' => 'string' ), 'older_than_gmt' => array( 'type' => 'string' ), 'expected_count' => array( 'type' => 'integer', 'minimum' => 0 ), 'dry_run' => array( 'type' => 'boolean', 'default' => true ), 'force' => array( 'type' => 'boolean', 'default' => false ), 'backup' => array( 'type' => 'boolean', 'default' => true ) ) ), false, true, false ),
			self::tool( 'enterprise_job_purge_restore', 'PR STUDIO Ripristina purge job', 'Ripristina un backup creato da enterprise_job_purge, con controllo del conteggio corrente.', self::schema( array( 'backup_id' => array( 'type' => 'string' ), 'expected_current_count' => array( 'type' => 'integer', 'minimum' => 0 ) ), array( 'backup_id' ) ), false, true, false ),
			self::tool( 'enterprise_schedule_list', 'PR STUDIO Elenca schedule', 'Elenca pianificazioni interne.', self::schema( array( 'enabled' => array( 'type' => 'boolean' ), 'action' => array( 'type' => 'string' ) ) ), true ),
			self::tool( 'enterprise_schedule_upsert', 'PR STUDIO Configura schedule', 'Crea o aggiorna una pianificazione persistente.', self::schema( array( 'schedule_id' => array( 'type' => 'string' ), 'action' => array( 'type' => 'string' ), 'payload' => array( 'type' => 'object', 'additionalProperties' => true ), 'first_run_gmt' => array( 'type' => 'string' ), 'interval_seconds' => array( 'type' => 'integer', 'minimum' => 300 ), 'cron' => array( 'type' => 'string' ), 'enabled' => array( 'type' => 'boolean' ), 'priority' => array( 'type' => 'integer' ), 'expected_updated_at' => array( 'type' => 'string' ) ), array( 'action' ) ) ),
			self::tool( 'enterprise_schedule_delete', 'PR STUDIO Elimina schedule', 'Elimina una pianificazione.', self::schema( array( 'schedule_id' => array( 'type' => 'string' ), 'expected_updated_at' => array( 'type' => 'string' ) ), array( 'schedule_id' ) ), false, true, false ),
			self::tool( 'pr_studio_broken_link_scan', 'PR STUDIO Broken Link', 'Scansiona link interni nei contenuti e registra collegamenti non validi.', self::schema( array( 'post_type' => array( 'type' => 'string', 'default' => 'any' ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ), 'repair' => array( 'type' => 'boolean', 'default' => false ) ) ), false ),
			self::tool( 'pr_studio_anchor_link_manage', 'PR STUDIO Anchor Link', 'Analizza o aggiorna collegamenti interni contestuali con expected content.', self::schema( array( 'content_id' => array( 'type' => 'integer' ), 'old_url' => array( 'type' => 'string' ), 'new_url' => array( 'type' => 'string' ), 'old_anchor' => array( 'type' => 'string' ), 'new_anchor' => array( 'type' => 'string' ), 'preview' => array( 'type' => 'boolean', 'default' => true ) ), array( 'content_id' ) ) ),
			self::tool( 'pr_studio_backlink_opportunity', 'PR STUDIO Backlink', 'Registra e ordina opportunità backlink italiane, siciliane e agrigentine.', self::schema( array( 'operation' => array( 'type' => 'string', 'enum' => array( 'list','upsert' ), 'default' => 'list' ), 'opportunity' => array( 'type' => 'object', 'additionalProperties' => true ) ) ) ),
			self::tool( 'pr_studio_report_generate', 'PR STUDIO Report', 'Genera un report operativo da audit, job e modifiche.', self::schema( array( 'since_hours' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'default' => 24 ), 'send_email' => array( 'type' => 'boolean', 'default' => false ) ) ) ),
		);
		foreach ( self::actions() as $name => $meta ) { $tools[] = self::tool( $name, 'PR STUDIO ' . ucwords( str_replace( '_', ' ', $name ) ), $meta['description'] . ' Supporta preview, queue, run e schedule.', $common, $meta['read_only'], $meta['destructive'], $meta['idempotent'] ); }
		$tools = array_merge( $tools, self::control_tools() );
		return $tools;
	}

	public static function is_tool( string $name ): bool {
		if ( isset( self::actions()[ $name ] ) || null !== self::control_action_by_tool( $name ) ) { return true; }
		foreach ( self::tools() as $tool ) { if ( $tool['name'] === $name ) { return true; } }
		return false;
	}
	public static function is_write( string $name ): bool {
		$control = self::control_action_by_tool( $name );
		if ( is_array( $control ) ) { return empty( $control['read_only'] ); }
		if ( isset( self::actions()[ $name ] ) ) { return ! self::actions()[ $name ]['read_only']; }
		return ! in_array( $name, array( 'enterprise_action_catalog','enterprise_orchestration_status','enterprise_job_list','enterprise_job_get','enterprise_schedule_list' ), true );
	}
	private static function safe_payload_key( string $key ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_.:-]/', '', $key ), 0, 160 );
	}
	private static function normalize( $value, int $depth = 0 ) {
		if ( $depth > 8 ) { return '[DEPTH_LIMIT]'; }
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
		if ( is_string( $value ) ) { return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 100000 ) : substr( $value, 0, 100000 ); }
		if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		if ( ! is_array( $value ) ) { return (string) $value; }
		$out = array();
		foreach ( array_slice( $value, 0, 1000, true ) as $key => $item ) {
			$safe_key = is_int( $key ) ? $key : self::safe_payload_key( (string) $key );
			if ( '' === $safe_key ) { continue; }
			$out[ $safe_key ] = self::normalize( $item, $depth + 1 );
		}
		return $out;
	}
	private static function clean( $value, int $depth = 0 ) {
		$value = self::normalize( $value, $depth );
		if ( ! is_array( $value ) ) { return $value; }
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = ! is_int( $key ) && preg_match( '/secret|token|password|credential|api[_-]?key|authorization|cookie|card|iban/i', (string) $key )
				? '[REDACTED]'
				: self::clean( $item, $depth + 1 );
		}
		return $out;
	}
	private static function store( string $option, array $records ): void { if ( count( $records ) > 2000 ) { $records = array_slice( $records, -2000, null, true ); } update_option( $option, $records, false ); }
	private static function id( string $prefix ): string { static $sequence = 0; $sequence++; $entropy = microtime( true ) . '|' . $sequence . '|' . wp_generate_password( 24, false, false ); return $prefix . '_' . gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', $entropy ), 0, 16 ); }
	private static function hash_payload( string $action, array $payload ): string { return hash( 'sha256', wp_json_encode( array( $action, self::normalize( $payload ) ) ) ); }

	public static function status(): array {
		$jobs = (array) get_option( self::JOBS, array() ); $schedules = (array) get_option( self::SCHEDULES, array() ); $job_stats = array(); foreach ( $jobs as $job ) { $s = sanitize_key( (string) ( $job['status'] ?? 'unknown' ) ); $job_stats[ $s ] = ( $job_stats[ $s ] ?? 0 ) + 1; }
		return array( 'enabled' => true, 'version' => self::VERSION, 'action_count' => count( self::actions() ), 'openapi_action_count' => self::control_registry_info()['count'], 'tool_registry' => self::control_registry_info(), 'native_action_count' => count( array_filter( self::actions(), static function( $item ) { return ! empty( $item['native'] ); } ) ), 'capability_classes' => array_count_values( array_map( static function( $item ) { return (string) ( $item['capability_class'] ?? 'unknown' ); }, self::actions() ) ), 'execution_model' => 'native_first_semantic_then_plan_evidence_nonblocking', 'backend_executability' => class_exists( 'PRSTUDIO_UC_Backend_Executability' ) ? PRSTUDIO_UC_Backend_Executability::audit_catalog() : array( 'ok' => false, 'reason' => 'backend_executability_class_missing' ), 'external_worker_required' => false, 'creates_suspended_jobs' => false, 'scheduler_backend' => 'wp_cron', 'jobs_retained' => count( $jobs ), 'job_stats' => $job_stats, 'job_statuses' => array( 'active' => self::ACTIVE_JOB_STATUSES, 'terminal' => self::TERMINAL_JOB_STATUSES ), 'schedules' => count( $schedules ), 'report_email' => (string) ( WPAIB_Auth::settings()['report_email'] ?? '' ) );
	}

	public static function action_catalog( array $args = array() ): array {
		$items = array(); foreach ( self::actions() as $meta ) { if ( ! empty( $args['division'] ) && false === stripos( $meta['division'], (string) $args['division'] ) ) { continue; } if ( ! empty( $args['risk'] ) && $meta['risk'] !== $args['risk'] ) { continue; } if ( ! empty( $args['search'] ) && false === stripos( $meta['name'] . ' ' . $meta['description'], (string) $args['search'] ) ) { continue; } $class = (string) ( $meta['capability_class'] ?? 'binding_contract_error' ); $meta['provider'] = 0 === strpos( $class, 'native_' ) ? 'wordpress_native' : ( 0 === strpos( $class, 'semantic_' ) ? 'wordpress_semantic' : 'binding_contract_error' ); $meta['external_worker_required'] = false; $meta['creates_pending_job'] = false; $items[] = $meta; } return array( 'items' => $items, 'count' => count( $items ) );
	}

	private static function create_job( string $action, array $payload, string $idempotency_key = '', int $priority = 10, string $status = 'queued' ): array {
		$jobs = (array) get_option( self::JOBS, array() ); $hash = self::hash_payload( $action, $payload ); $key = sanitize_text_field( $idempotency_key ?: $hash );
		foreach ( $jobs as $job ) { if ( ( $job['idempotency_key'] ?? '' ) === $key && ! in_array( $job['status'] ?? '', array( 'technical_error','cancelled' ), true ) ) { return array( 'job' => $job, 'idempotent_reuse' => true ); } }
		$id = self::id( 'job' ); $meta = self::actions()[ $action ]; $jobs[ $id ] = array( 'id' => $id, 'action' => $action, 'division' => $meta['division'], 'risk' => $meta['risk'], 'status' => $status, 'priority' => max( 0, min( 255, $priority ) ), 'idempotency_key' => $key, 'payload_hash' => $hash, 'payload' => self::clean( $payload ), 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ), 'attempts' => 0, 'result' => null, 'error' => null ); self::store( self::JOBS, $jobs ); WPAIB_Audit::log( 'agency.job.create', 'success', $id, array( 'action' => $action, 'status' => $status ) ); return array( 'job' => $jobs[ $id ], 'idempotent_reuse' => false );
	}

	public static function execute( string $action, array $args ) {
		if ( ! isset( self::actions()[ $action ] ) ) { return new WP_Error( 'prstudio_action_unknown', 'Azione PR STUDIO sconosciuta.', array( 'status' => 400 ) ); }
		$payload = is_array( $args['payload'] ?? null ) ? self::normalize( $args['payload'] ) : array(); $mode = sanitize_key( (string) ( $args['execution_mode'] ?? 'preview' ) ); $meta = self::actions()[ $action ]; $hash = self::hash_payload( $action, $payload ); $class = (string) ( $meta['capability_class'] ?? 'binding_contract_error' );
		if ( 'preview' === $mode ) { if ( 'catalog_enrich_bulk' === $action ) { $payload['_preview'] = true; return self::catalog_enrich_native( $payload ); } return array( 'action' => $action, 'metadata' => $meta, 'payload_hash' => $hash, 'executable' => true, 'execution_path' => 0 === strpos( $class, 'native_' ) ? 'wordpress_native' : ( 0 === strpos( $class, 'semantic_' ) ? 'wordpress_semantic' : 'binding_contract_error' ), 'external_dependency' => class_exists( 'PRSTUDIO_UC_Agency_Action_Executor' ) ? PRSTUDIO_UC_Agency_Action_Executor::external_dependency( $action ) : false, 'creates_job' => false, 'input_resolution' => array( 'auto_hydration' => true, 'client_continuation' => false ), 'contract_version' => '1.0.0' ); }
		if ( 'schedule' === $mode ) { $schedule = is_array( $args['schedule'] ?? null ) ? $args['schedule'] : array(); $schedule['action'] = $action; $schedule['payload'] = $payload; return self::schedule_upsert( $schedule ); }
		$has_plan = self::payload_has_executable_plan( $payload ); // Optional in 0.3.9: server synthesis fills the plan when absent.
		$created = self::create_job( $action, $payload, (string) ( $args['idempotency_key'] ?? '' ), (int) ( $args['priority'] ?? 10 ), 'queue' === $mode ? 'queued' : 'running' ); if ( ! empty( $created['idempotent_reuse'] ) || 'queue' === $mode ) { return $created; }
		$job = $created['job']; $result = self::run_job( $job, $payload ); return $result;
	}

	private static function update_job( string $id, array $changes ): array {
		$jobs = (array) get_option( self::JOBS, array() ); if ( ! isset( $jobs[ $id ] ) ) { return array(); } $jobs[ $id ] = array_replace( $jobs[ $id ], self::clean( $changes ), array( 'updated_at' => current_time( 'mysql', true ) ) ); self::store( self::JOBS, $jobs ); return $jobs[ $id ];
	}
	private static function run_job( array $job, ?array $runtime_payload = null ): array {
		$action = $job['action'];
		$payload = null !== $runtime_payload ? $runtime_payload : ( is_array( $job['payload'] ?? null ) ? $job['payload'] : array() );
		$meta = self::actions()[ $action ];
		$job = self::update_job( $job['id'], array( 'status' => 'running', 'attempts' => (int) ( $job['attempts'] ?? 0 ) + 1 ) );
		try {
			$adapter = apply_filters( 'prstudio_ai_bridge_execute_' . $action, null, $payload, $meta, $job );
			if ( is_wp_error( $adapter ) ) { return self::fail_job( $job, $action, $adapter ); }
			if ( null !== $adapter ) { return self::complete_job( $job, $action, 'adapter', $adapter ); }

			$native = self::native_fallback( $action, $payload );
			if ( is_wp_error( $native ) ) { return self::fail_job( $job, $action, $native ); }
			if ( null !== $native ) { return self::complete_job( $job, $action, 'native_fallback', $native ); }

			if ( class_exists( 'PRSTUDIO_UC_Agency_Action_Executor' ) && PRSTUDIO_UC_Agency_Action_Executor::supports( $action ) ) {
				$semantic = PRSTUDIO_UC_Agency_Action_Executor::execute( $action, $payload, $meta );
				if ( is_wp_error( $semantic ) ) { return self::fail_job( $job, $action, $semantic ); }
				if ( in_array( $action, self::continuation_actions(), true ) && ! empty( $payload['persist_research'] ) && is_array( $semantic ) ) {
					$semantic['research_record'] = self::save_research( $action, $payload, $semantic );
				}
				return self::complete_job( $job, $action, 'semantic_executor', $semantic );
			}

			$plan = self::payload_plan_fallback( $action, $payload, $meta );
			if ( is_wp_error( $plan ) ) { return self::fail_job( $job, $action, $plan ); }
			if ( null !== $plan ) { return self::complete_job( $job, $action, 'payload_plan', $plan ); }

			if ( class_exists( 'PRSTUDIO_UC_Orchestrator' ) ) {
				$objective = trim( str_replace( '_', ' ', $action ) );
				$synthesized = PRSTUDIO_UC_Orchestrator::execute( array( 'objective' => $objective, 'arguments' => $payload, 'sync_wait_seconds' => 5 ) );
				if ( ! is_wp_error( $synthesized ) && ! empty( $synthesized['results'] ) ) { return self::complete_job( $job, $action, 'server_orchestrator_synthesis', self::with_outcome( array( 'synthesized' => true, 'orchestration' => $synthesized ), 'completed', true, ! empty( $meta['read_only'] ) ? false : true, ! empty( $synthesized['verified'] ) ) ); }
			}

			return self::fail_job(
				$job,
				$action,
				new WP_Error(
					'prstudio_agency_binding_contract_violation',
					'Azione enterprise registrata senza executor concreto: difetto di contratto della release.',
					array( 'status' => 500, 'provider' => 'binding_contract_error' )
				)
			);
		} catch ( Throwable $e ) {
			return self::fail_job( $job, $action, new WP_Error( 'prstudio_action_exception', $e->getMessage(), array( 'status' => 500 ) ) );
		}
	}

	private static function complete_job( array $job, string $action, string $execution, $output ): array {
		$meta = self::actions()[ $action ] ?? array();
		$outcome = is_array( $output['_job_outcome'] ?? null ) ? $output['_job_outcome'] : array();
		if ( ! $outcome && is_array( $output ) && array_key_exists( 'executed', $output ) && array_key_exists( 'verified', $output ) ) {
			$outcome = array_intersect_key( $output, array_flip( array( 'status','accepted','executed','mutated','verified','degraded','blocking','reason' ) ) );
		}
		if ( is_array( $output ) ) { unset( $output['_job_outcome'] ); }
		if ( ! $outcome ) {
			if ( ! empty( $meta['read_only'] ) ) {
				$outcome = array( 'status' => 'completed', 'accepted' => true, 'executed' => true, 'mutated' => false, 'verified' => true, 'degraded'=>false, 'blocking'=>false );
			} elseif ( 'native_state' === (string) ( $meta['capability_class'] ?? '' ) || ( is_array( $output ) && ( ! empty( $output['stored'] ) || ! empty( $output['recorded'] ) ) ) ) {
				$outcome = array( 'status' => 'degraded', 'accepted' => true, 'executed' => true, 'mutated' => true, 'verified' => false, 'degraded'=>true, 'blocking'=>false, 'reason'=>'persistence_evidence_incomplete' );
			} else {
				$outcome = array( 'status' => 'degraded', 'accepted' => true, 'executed' => true, 'mutated' => false, 'verified' => false, 'degraded'=>true, 'blocking'=>false, 'reason' => 'effect_evidence_incomplete' );
			}
		}
		$outcome = self::normalize_job_outcome( $outcome );
		$status = (string) $outcome['status'];
		$result = array( 'execution' => $execution, 'outcome' => $outcome, 'output' => self::clean( $output ) );
		$done = self::update_job( $job['id'], array( 'status' => $status, 'result' => $result, 'error' => null ) );
		WPAIB_Audit::log( 'agency.action.' . $action, ! empty( $outcome['executed'] ) ? 'success' : $status, $job['id'], $result );
		return array_merge( array( 'job' => $done ), $outcome );
	}

	private static function normalize_job_outcome( array $outcome ): array {
		$outcome = array_replace( array( 'status' => 'degraded', 'accepted' => true, 'executed' => false, 'mutated' => false, 'verified' => false, 'degraded'=>false, 'blocking'=>false ), self::clean( $outcome ) );
		$executed = ! empty( $outcome['executed'] ); $mutated = ! empty( $outcome['mutated'] ); $verified = ! empty( $outcome['verified'] );
		// PR STUDIO ONE-GUARD INVARIANT: verification evidence never authorizes or vetoes execution.
		if ( $executed && $verified ) { $outcome['status']='completed'; $outcome['degraded']=false; $outcome['blocking']=false; }
		elseif ( $executed ) { $outcome['status']='degraded'; $outcome['degraded']=true; $outcome['blocking']=false; if(empty($outcome['reason']))$outcome['reason']='effect_evidence_incomplete'; }
		else { $outcome['status']='technical_error'; $outcome['degraded']=false; $outcome['blocking']=true; if(empty($outcome['reason']))$outcome['reason']='operation_not_executed'; }
		return array(
			'status'=>(string)$outcome['status'], 'accepted'=>!array_key_exists('accepted',$outcome)||!empty($outcome['accepted']),
			'executed'=>$executed, 'mutated'=>$mutated, 'verified'=>$verified,
			'degraded'=>!empty($outcome['degraded']), 'blocking'=>!empty($outcome['blocking']),
			'reason'=>(string)($outcome['reason']??''),
		);
	}

	private static function with_outcome( array $output, string $status, bool $executed, bool $mutated, bool $verified ): array {
		$output['_job_outcome'] = self::normalize_job_outcome( array( 'status' => $status, 'accepted' => true, 'executed' => $executed, 'mutated' => $mutated, 'verified' => $verified ) );
		return $output;
	}

	private static function fail_job( array $job, string $action, WP_Error $error ): array {
		$error_data = array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'data' => self::clean( $error->get_error_data() ) );
		$failed = self::update_job( $job['id'], array( 'status' => 'technical_error', 'error' => $error_data ) );
		WPAIB_Audit::log( 'agency.action.' . $action, 'error', $job['id'], $error_data );
		return array( 'job' => $failed, 'accepted' => true, 'executed' => false, 'mutated' => false, 'verified' => false, 'degraded'=>false, 'blocking'=>true, 'status' => 'technical_error', 'error' => $error_data );
	}

	private static function native_fallback( string $action, array $payload ) {
		switch ( $action ) {
			case 'search_console_status': return WPAIB_Enterprise::search_console_status();
			case 'search_console_sites': return WPAIB_Enterprise::search_console_sites();
			case 'search_console_search_analytics': return WPAIB_Enterprise::search_console_search_analytics( $payload );
			case 'search_console_sitemaps': return WPAIB_Enterprise::search_console_sitemaps( $payload );
			case 'search_console_url_inspection': return WPAIB_Enterprise::search_console_url_inspection( $payload );
			case 'financial_report_generate': return WPAIB_Enterprise::commerce_summary( $payload );
			case 'department_kpi_dashboard': return array( 'agency' => self::status(), 'commerce' => function_exists( 'wc_get_orders' ) ? WPAIB_Enterprise::commerce_summary( $payload ) : null, 'audit' => WPAIB_Audit::recent( 25 ) );
			case 'performance_monitor': return self::performance_native( $payload );
			case 'security_scan': return self::security_scan_native( $payload );
			case 'accessibility_audit': return self::accessibility_native( $payload );
			case 'deploy_pipeline_trigger': return self::deploy_pipeline_native( $payload );
			case 'cdn_cache_manage': return self::cache_native( $payload );
			case 'database_optimize': return self::database_optimize_native( $payload );
			case 'task_assign_route':
				$target = sanitize_key( (string) ( $payload['target_action'] ?? $payload['action'] ?? '' ) );
				if ( '' === $target || 'task_assign_route' === $target || ! isset( self::actions()[ $target ] ) ) { return new WP_Error( 'prstudio_route_target_required', 'target_action deve indicare un’azione enterprise valida e diversa da task_assign_route.', array( 'status' => 400, 'available_actions' => array_keys( self::actions() ) ) ); }
				$target_role = sanitize_key( (string) ( $payload['target_role'] ?? '' ) );
				$target_payload = is_array( $payload['payload'] ?? null ) ? $payload['payload'] : array();
				if ( $target_role ) { $target_payload['_prstudio_agent_affinity'] = $target_role; }
				$created = self::create_job( $target, $target_payload, (string) ( $payload['idempotency_key'] ?? '' ), (int) ( $payload['priority'] ?? 10 ), 'queued' );
				$reused = ! empty( $created['idempotent_reuse'] );
				return self::with_outcome( array( 'routed' => true, 'target_action' => $target, 'target_role' => $target_role ?: null, 'target_job' => $created ), $reused ? 'verified' : 'completed', true, ! $reused, true );
			case 'agent_role_define': return self::manage_agent_roles( $payload );
			case 'org_chart_config': return self::manage_org_chart( $payload );
			case 'feature_flag_manage': return self::manage_feature_flags( $payload );
			case 'product_roadmap_track': return self::manage_collection( 'product_roadmap_track', 'roadmap', $payload );
			case 'experiment_pipeline_manage': return self::manage_collection( 'experiment_pipeline_manage', 'experiments', $payload );
			case 'budget_allocate_across_divisions': return self::manage_budget_allocations( $payload );
			case 'escalation_handle': return self::manage_escalation( $payload );
			case 'catalog_enrich_bulk': return self::catalog_enrich_native( $payload );
		}
		return null;
	}

	public static function execute_backend_plan( string $action, array $payload, array $meta = array() ) {
		return self::payload_plan_fallback( $action, self::normalize_plan_payload( $payload ), $meta );
	}

	public static function payload_has_executable_plan( array $payload ): bool {
		$payload = self::normalize_plan_payload( $payload );
		return ! empty( $payload['operations'] ) || ( ! empty( $payload['control_route'] ) && ! empty( $payload['control_action'] ) );
	}

	private static function normalize_plan_payload( array $payload ): array {
		foreach ( array( 'body', 'params', 'mutation' ) as $container ) {
			if ( ! isset( $payload[ $container ] ) || ! is_array( $payload[ $container ] ) ) { continue; }
			foreach ( array( 'operations', 'control_route', 'control_action', 'operation', 'type' ) as $key ) {
				if ( ! array_key_exists( $key, $payload ) && array_key_exists( $key, $payload[ $container ] ) ) { $payload[ $key ] = $payload[ $container ][ $key ]; }
			}
		}
		return $payload;
	}

	private static function payload_plan_fallback( string $action, array $payload, array $meta ) {
		$operations = is_array( $payload['operations'] ?? null ) ? $payload['operations'] : array();
		if ( $operations ) {
			$results = array(); $mutated = false;
			foreach ( array_slice( $operations, 0, 100 ) as $index => $operation ) {
				if ( ! is_array( $operation ) ) { continue; }
				$result = self::execute_payload_operation( $operation );
				if ( is_wp_error( $result ) ) {
					return new WP_Error( 'prstudio_operation_failed', 'Operazione del piano non riuscita.', array( 'status' => 500, 'index' => $index, 'cause' => array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ), 'completed' => $results ) );
				}
				$outcome = self::operation_outcome( $result );
				if ( ! $outcome ) { $outcome = array( 'status'=>'degraded','executed'=>true,'mutated'=>false,'verified'=>false,'degraded'=>true,'blocking'=>false,'reason'=>'operation_outcome_missing' ); }
				if ( empty( $outcome['executed'] ) ) {
					return new WP_Error( 'prstudio_operation_not_executed', 'Una sotto-operazione non era tecnicamente eseguibile.', array( 'status' => 500, 'index' => $index, 'result' => self::clean( $result ), 'completed' => $results ) );
				}
				if ( empty( $outcome['verified'] ) ) {
					$result['_control_outcome'] = array_merge( $outcome, array( 'status'=>'degraded','degraded'=>true,'blocking'=>false,'reason'=>(string)($outcome['reason']??'operation_effect_unverified') ) );
				}
				$mutated = $mutated || ! empty( $outcome['mutated'] ); $results[] = $result;
			}
			if ( ! $results ) { return new WP_Error( 'prstudio_operation_required', 'È richiesta almeno una sotto-operazione valida.', array( 'status' => 400 ) ); }
			$all_verified = true; foreach ( $results as $completed_result ) { $completed_outcome = self::operation_outcome( $completed_result ); if ( ! $completed_outcome || empty( $completed_outcome['verified'] ) ) { $all_verified = false; break; } }
			return self::with_outcome( array( 'action' => $action, 'operations_completed' => count( $results ), 'results' => $results, 'degraded' => ! $all_verified, 'blocking' => false ), $all_verified ? ( $mutated ? 'completed' : 'verified' ) : 'degraded', true, $mutated, $all_verified );
		}

		$single = self::execute_payload_operation( $payload );
		if ( null !== $single ) { return $single; }

		return null;
	}

	private static function execute_payload_operation( array $operation ) {
		$name = sanitize_key( (string) ( $operation['operation'] ?? $operation['type'] ?? '' ) );
		if ( in_array( $name, array( 'patch_file','write_file','create_file','delete_file','restore_file' ), true ) ) {
			return self::deploy_pipeline_native( $operation );
		}
		if ( in_array( $name, array( 'purge','purge_url','purge_urls','flush','flush_all','flush_object_cache','flush_page_cache','flush_cdn_cache','regenerate_sitemap' ), true ) ) {
			return self::cache_native( $operation );
		}
		$route = (string) ( $operation['control_route'] ?? '' );
		$control_action = (string) ( $operation['control_action'] ?? '' );
		if ( $route && $control_action ) {
			$result = WPAIB_REST::execute_control_action( $route, $control_action, $operation, 'enterprise_payload_plan' );
			if ( is_wp_error( $result ) || ! is_array( $result ) ) { return $result; }
			$result['_job_outcome'] = array( 'status' => (string) ( $result['status'] ?? 'degraded' ), 'accepted' => ! array_key_exists( 'accepted', $result ) || ! empty( $result['accepted'] ), 'executed' => ! empty( $result['executed'] ), 'mutated' => ! empty( $result['mutated'] ), 'verified' => ! empty( $result['verified'] ), 'degraded'=>!empty($result['degraded']), 'blocking'=>!empty($result['blocking']) );
			return $result;
		}
		return null;
	}

	private static function operation_outcome( $result ): array {
		if ( ! is_array( $result ) ) { return array(); }
		if ( is_array( $result['_job_outcome'] ?? null ) ) { return self::normalize_job_outcome( $result['_job_outcome'] ); }
		if ( is_array( $result['_control_outcome'] ?? null ) ) { return self::normalize_job_outcome( $result['_control_outcome'] ); }
		if ( array_key_exists( 'executed', $result ) || array_key_exists( 'verified', $result ) ) {
			return self::normalize_job_outcome( array( 'status' => (string) ( $result['status'] ?? 'degraded' ), 'executed' => ! empty( $result['executed'] ), 'mutated' => ! empty( $result['mutated'] ), 'verified' => ! empty( $result['verified'] ) ) );
		}
		return array();
	}

	private static function performance_native( array $payload ) {
		$urls = is_array( $payload['urls'] ?? null ) ? $payload['urls'] : array();
		if ( ! $urls ) { $urls[] = (string) ( $payload['url'] ?? home_url( '/' ) ); }
		$items = array();
		foreach ( array_slice( array_values( array_unique( array_map( 'strval', $urls ) ) ), 0, 50 ) as $url ) {
			$start = microtime( true );
			$response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 5 ) );
			if ( is_wp_error( $response ) ) {
				$items[] = array( 'ok' => false, 'url' => $url, 'error' => $response->get_error_message(), 'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ) );
				continue;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			$items[] = array( 'ok' => $status > 0 && $status < 400, 'status' => $status, 'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ), 'url' => $url, 'bytes' => strlen( (string) wp_remote_retrieve_body( $response ) ) );
		}
		return array( 'ok' => ! array_filter( $items, static function( $item ) { return empty( $item['ok'] ); } ), 'checked' => count( $items ), 'items' => $items );
	}

	private static function deploy_pipeline_native( array $payload ) {
		$operations = is_array( $payload['operations'] ?? null ) ? $payload['operations'] : array( $payload );
		$results = array();
		foreach ( array_slice( $operations, 0, 100 ) as $index => $operation ) {
			if ( ! is_array( $operation ) ) { continue; }
			$name = sanitize_key( (string) ( $operation['operation'] ?? 'patch_file' ) );
			$path = (string) ( $operation['path'] ?? '' );
			if ( 'restore_file' === $name ) {
				$result = WPAIB_Files::restore( (string) ( $operation['backup_id'] ?? '' ), isset( $operation['expected_current_sha256'] ) ? (string) $operation['expected_current_sha256'] : null );
			} else {
				if ( '' === $path ) { return new WP_Error( 'prstudio_deploy_path_required', 'path obbligatorio per il deploy.', array( 'status' => 400, 'index' => $index ) ); }
				if ( 'delete_file' === $name ) {
					$result = WPAIB_Files::delete_file( $path, (string) ( $operation['expected_sha256'] ?? '' ) );
				} else {
				if ( 'patch_file' === $name ) {
					$result = WPAIB_Files::patch_exact(
						$path,
						(string) ( $operation['expected_sha256'] ?? '' ),
						(string) ( $operation['search'] ?? '' ),
						(string) ( $operation['replace'] ?? '' ),
						(int) ( $operation['expected_replacements'] ?? 1 ),
						(string) ( $operation['search_sha256'] ?? '' ),
						is_array( $operation['health_checks'] ?? null ) ? $operation['health_checks'] : array()
					);
				} else {
					$current = WPAIB_Files::read_file( $path, 0, null );
					$exists = ! is_wp_error( $current );
					$content = isset( $operation['content_b64'] ) ? base64_decode( (string) $operation['content_b64'], true ) : (string) ( $operation['content'] ?? '' );
					if ( false === $content ) { return new WP_Error( 'prstudio_deploy_base64_invalid', 'content_b64 non valido.', array( 'status' => 400, 'index' => $index ) ); }

					if ( preg_match( '/\.php$/i', $path ) ) {
						try { token_get_all( $content, TOKEN_PARSE ); } catch ( ParseError $e ) { return new WP_Error( 'prstudio_deploy_php_invalid', $e->getMessage(), array( 'status' => 400, 'index' => $index, 'path' => $path ) ); }
					}
					$expected = array_key_exists( 'expected_sha256', $operation ) ? ( null === $operation['expected_sha256'] ? null : (string) $operation['expected_sha256'] ) : ( $exists ? (string) $current['sha256'] : null );
					$result = WPAIB_Files::write_raw( $path, $content, $expected );
				}
				}
			}

			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'prstudio_deploy_failed', 'Deploy non riuscito.', array( 'status' => 500, 'index' => $index, 'path' => $path, 'cause' => array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ), 'completed' => $results ) );
			}
			$evidence = is_array( $result ) && ( ! empty( $result['after_sha256'] ) || ! empty( $result['deleted'] ) || ! empty( $result['restored'] ) );
			if ( ! $evidence && is_array($result) ) { $result['degraded']=true; $result['blocking']=false; $result['verification_warning']='deploy_effect_unverified'; }
			$results[] = $result;
		}
		if ( ! $results ) { return new WP_Error( 'prstudio_deploy_operation_required', 'È richiesta almeno un’operazione di deploy.', array( 'status' => 400 ) ); }
		return self::with_outcome( array( 'deployed' => true, 'operations_completed' => count( $results ), 'results' => $results ), 'completed', true, true, true );
	}

	private static function cache_native( array $payload ) {
		$operation = sanitize_key( (string) ( $payload['operation'] ?? 'purge' ) );
		$action = in_array( $operation, array( 'flush_object_cache','flush_page_cache','flush_cdn_cache' ), true ) ? $operation : 'flush_all';
		$arguments = $payload;
		$arguments['action'] = $action;
		$result = WPAIB_REST::execute_control_action( '/cache-manage', $action, $arguments, 'enterprise_native' );
		if ( is_wp_error( $result ) ) { return $result; }
		$urls = is_array( $payload['urls'] ?? null ) ? array_values( array_unique( array_map( 'strval', $payload['urls'] ) ) ) : array();
		$executed = ! empty( $result['executed'] ); $verified = $executed && ! empty( $result['verified'] );
		if ( ! $executed ) { return new WP_Error( 'prstudio_cache_not_executed', 'Il gestore cache non ha eseguito l’operazione richiesta.', array( 'status' => 502, 'result' => self::clean( $result ) ) ); }
		return self::with_outcome( array( 'operation' => $operation, 'urls' => $urls, 'cache' => $result, 'degraded'=>!$verified, 'blocking'=>false, 'verification_warning'=>$verified?'':'cache_effect_unverified' ), $verified?'completed':'mutated', true, true, $verified );
	}

	private static function database_optimize_native( array $payload ) {
		$arguments = $payload;
		$arguments['action'] = 'optimize';
		$result = WPAIB_REST::execute_control_action( '/database-manage', 'optimize', $arguments, 'enterprise_native' );
		if ( is_wp_error( $result ) ) { return $result; }
		$executed=!empty($result['executed']);$verified=$executed&&!empty($result['verified']);
		if(!$executed)return new WP_Error('prstudio_database_optimize_not_executed','L’ottimizzazione database non è stata eseguita.',array('status'=>502,'result'=>self::clean($result)));
		return self::with_outcome( array( 'database' => $result, 'degraded'=>!$verified, 'blocking'=>false, 'verification_warning'=>$verified?'':'database_optimize_unverified' ), $verified?'completed':'mutated', true, true, $verified );
	}
	private static function save_config( string $key, array $payload ): array { $all = (array) get_option( self::CONFIG, array() ); $before = $all[ $key ] ?? null; $all[ $key ] = array( 'data' => self::clean( $payload ), 'updated_at' => current_time( 'mysql', true ) ); update_option( self::CONFIG, $all, false ); $stored = (array) get_option( self::CONFIG, array() ); $verified = isset( $stored[ $key ] ) && $stored[ $key ] === $all[ $key ]; WPAIB_Audit::log( 'agency.config.' . $key, $verified ? 'stored' : 'degraded', $key, array( 'before' => $before, 'after' => $all[ $key ], 'verified'=>$verified ) ); PRSTUDIO_Report::record_change( 'Configurazione PR STUDIO', $key, $before, $all[ $key ] ); return self::with_outcome( array( 'stored' => true, 'key' => $key, 'record' => $all[ $key ], 'degraded'=>!$verified, 'blocking'=>false ), $verified?'completed':'degraded', true, true, $verified ); }
	private static function config_data( string $key, array $default = array() ): array {
		$all = (array) get_option( self::CONFIG, array() );
		$record = $all[ $key ] ?? null;
		return is_array( $record ) && is_array( $record['data'] ?? null ) ? $record['data'] : $default;
	}

	private static function store_config_data( string $key, array $data, $before = null ): array {
		$all = (array) get_option( self::CONFIG, array() );
		if ( null === $before ) { $before = $all[ $key ]['data'] ?? null; }
		$record = array( 'data' => self::clean( $data ), 'updated_at' => current_time( 'mysql', true ) );
		$all[ $key ] = $record;
		update_option( self::CONFIG, $all, false );
		$stored = (array) get_option( self::CONFIG, array() );
		$verified = isset( $stored[ $key ] ) && $stored[ $key ] === $record;
		WPAIB_Audit::log( 'agency.config.' . $key, $verified ? 'stored' : 'degraded', $key, array( 'before' => self::clean( $before ), 'after' => $record, 'verified'=>$verified ) );
		PRSTUDIO_Report::record_change( 'Configurazione PR STUDIO', $key, self::clean( $before ), $record );
		return array( 'record'=>$record, 'verified'=>$verified, 'degraded'=>!$verified, 'blocking'=>false );
	}

	private static function manage_collection( string $config_key, string $label, array $payload ) {
		$operation = sanitize_key( (string) ( $payload['operation'] ?? 'upsert' ) );
		$state = self::config_data( $config_key, array( 'items' => array() ) );
		$items = is_array( $state['items'] ?? null ) ? $state['items'] : array();
		$id = sanitize_text_field( (string) ( $payload['item_id'] ?? $payload['id'] ?? '' ) );
		if ( 'list' === $operation ) { return self::with_outcome( array( 'collection' => $label, 'items' => array_values( $items ), 'count' => count( $items ) ), 'verified', true, false, true ); }
		if ( 'get' === $operation ) {
			if ( '' === $id || ! isset( $items[ $id ] ) ) { return new WP_Error( 'prstudio_collection_item_missing', 'Elemento non trovato nella collezione.', array( 'status' => 404, 'collection' => $label, 'item_id' => $id ) ); }
			return self::with_outcome( array( 'collection' => $label, 'item' => $items[ $id ] ), 'verified', true, false, true );
		}
		if ( 'delete' === $operation ) {
			if ( '' === $id || ! isset( $items[ $id ] ) ) { return new WP_Error( 'prstudio_collection_item_missing', 'Elemento non trovato nella collezione.', array( 'status' => 404, 'collection' => $label, 'item_id' => $id ) ); }
			$before = $state; unset( $items[ $id ] ); $state['items'] = $items;
			$write = self::store_config_data( $config_key, $state, $before );
			return self::with_outcome( array( 'collection' => $label, 'item_id' => $id, 'deleted' => true, 'degraded'=>empty($write['verified']), 'blocking'=>false ), !empty($write['verified'])?'completed':'degraded', true, true, !empty($write['verified']) );
		}
		if ( ! in_array( $operation, array( 'upsert','set','transition' ), true ) ) { return new WP_Error( 'prstudio_collection_operation_invalid', 'Operazione collezione non valida.', array( 'status' => 400, 'operation' => $operation ) ); }
		if ( '' === $id ) { $id = self::id( rtrim( $label, 's' ) ?: 'item' ); }
		$current = is_array( $items[ $id ] ?? null ) ? $items[ $id ] : array();
		if ( 'transition' === $operation ) {
			if ( ! $current ) { return new WP_Error( 'prstudio_collection_item_missing', 'Elemento non trovato per la transizione.', array( 'status' => 404, 'item_id' => $id ) ); }
			$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
			if ( '' === $status ) { return new WP_Error( 'prstudio_collection_status_required', 'status è obbligatorio per la transizione.', array( 'status' => 400 ) ); }
			$item = array_replace( $current, array( 'status' => $status ) );
		} else {
			$incoming = is_array( $payload['item'] ?? null ) ? $payload['item'] : $payload;
			unset( $incoming['operation'], $incoming['item_id'], $incoming['id'] );
			$item = array_replace( $current, self::clean( $incoming ) );
		}
		$item['id'] = $id; $item['created_at'] = $current['created_at'] ?? current_time( 'mysql', true ); $item['updated_at'] = current_time( 'mysql', true );
		$before = $state; $items[ $id ] = $item; $state['items'] = $items;
		$write = self::store_config_data( $config_key, $state, $before );
		$verified = ! empty( $write['verified'] );
		return self::with_outcome( array( 'collection' => $label, 'item' => $item, 'verified' => $verified ), 'completed', true, true, $verified );
	}

	private static function manage_agent_roles( array $payload ) {
		$operation = sanitize_key( (string) ( $payload['operation'] ?? 'upsert' ) );
		$state = self::config_data( 'agent_role_define', array( 'roles' => array() ) );
		$roles = is_array( $state['roles'] ?? null ) ? $state['roles'] : array();
		$role = sanitize_key( (string) ( $payload['role'] ?? $payload['role_id'] ?? '' ) );
		if ( 'list' === $operation ) { return self::with_outcome( array( 'roles' => array_values( $roles ), 'count' => count( $roles ) ), 'verified', true, false, true ); }
		if ( 'get' === $operation ) { if ( '' === $role || ! isset( $roles[ $role ] ) ) { return new WP_Error( 'prstudio_agent_role_missing', 'Ruolo agente non trovato.', array( 'status' => 404 ) ); } return self::with_outcome( array( 'role' => $roles[ $role ] ), 'verified', true, false, true ); }
		if ( '' === $role ) { return new WP_Error( 'prstudio_agent_role_required', 'role è obbligatorio.', array( 'status' => 400 ) ); }
		if ( 'delete' === $operation ) {
			if ( ! isset( $roles[ $role ] ) ) { return new WP_Error( 'prstudio_agent_role_missing', 'Ruolo agente non trovato.', array( 'status' => 404 ) ); }
			$before = $state; unset( $roles[ $role ] ); $state['roles'] = $roles;
			$write = self::store_config_data( 'agent_role_define', $state, $before );
			return self::with_outcome( array( 'role' => $role, 'deleted' => true, 'degraded'=>empty($write['verified']), 'blocking'=>false ), !empty($write['verified'])?'completed':'degraded', true, true, !empty($write['verified']) );
		}
		if ( ! in_array( $operation, array( 'upsert','set' ), true ) ) { return new WP_Error( 'prstudio_agent_role_operation_invalid', 'Operazione ruolo non valida.', array( 'status' => 400 ) ); }
		$affinity = is_array( $payload['affinity_actions'] ?? null ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $payload['affinity_actions'] ) ) ) ) : array();
		foreach ( $affinity as $candidate ) { if ( ! isset( self::actions()[ $candidate ] ) ) { return new WP_Error( 'prstudio_agent_affinity_action_unknown', 'affinity_actions contiene un’azione sconosciuta.', array( 'status' => 400, 'action' => $candidate ) ); } }
		$before = $state; $roles[ $role ] = array( 'id' => $role, 'label' => sanitize_text_field( (string) ( $payload['label'] ?? $role ) ), 'affinity_actions' => $affinity, 'updated_at' => current_time( 'mysql', true ) ); $state['roles'] = $roles;
		$write = self::store_config_data( 'agent_role_define', $state, $before );
		return self::with_outcome( array( 'role' => $roles[ $role ], 'affinity_only' => true, 'degraded'=>empty($write['verified']), 'blocking'=>false ), !empty($write['verified'])?'completed':'degraded', true, true, !empty($write['verified']) );
	}

	private static function manage_org_chart( array $payload ) {
		$operation = sanitize_key( (string) ( $payload['operation'] ?? 'upsert' ) );
		$state = self::config_data( 'org_chart_config', array( 'nodes' => array() ) ); $nodes = is_array( $state['nodes'] ?? null ) ? $state['nodes'] : array();
		$id = sanitize_key( (string) ( $payload['node_id'] ?? $payload['id'] ?? '' ) );
		if ( 'list' === $operation ) { return self::with_outcome( array( 'nodes' => array_values( $nodes ), 'count' => count( $nodes ) ), 'verified', true, false, true ); }
		if ( 'get' === $operation ) { if ( '' === $id || ! isset( $nodes[ $id ] ) ) { return new WP_Error( 'prstudio_org_node_missing', 'Nodo organigramma non trovato.', array( 'status' => 404 ) ); } return self::with_outcome( array( 'node' => $nodes[ $id ] ), 'verified', true, false, true ); }
		$before = $state;
		if ( 'set' === $operation && is_array( $payload['nodes'] ?? null ) ) {
			$nodes = array(); foreach ( array_slice( $payload['nodes'], 0, 500 ) as $raw ) { if ( ! is_array( $raw ) ) { continue; } $node_id = sanitize_key( (string) ( $raw['id'] ?? $raw['node_id'] ?? '' ) ); if ( '' === $node_id ) { return new WP_Error( 'prstudio_org_node_id_required', 'Ogni nodo deve avere id.', array( 'status' => 400 ) ); } $nodes[ $node_id ] = array( 'id' => $node_id, 'parent_id' => sanitize_key( (string) ( $raw['parent_id'] ?? '' ) ), 'department' => sanitize_text_field( (string) ( $raw['department'] ?? '' ) ), 'label' => sanitize_text_field( (string) ( $raw['label'] ?? $node_id ) ) ); }
		} elseif ( 'upsert' === $operation ) {
			if ( '' === $id ) { return new WP_Error( 'prstudio_org_node_id_required', 'node_id è obbligatorio.', array( 'status' => 400 ) ); }
			$nodes[ $id ] = array_replace( is_array( $nodes[ $id ] ?? null ) ? $nodes[ $id ] : array(), array( 'id' => $id, 'parent_id' => sanitize_key( (string) ( $payload['parent_id'] ?? '' ) ), 'department' => sanitize_text_field( (string) ( $payload['department'] ?? '' ) ), 'label' => sanitize_text_field( (string) ( $payload['label'] ?? $id ) ) ) );
		} elseif ( 'delete' === $operation ) {
			if ( '' === $id || ! isset( $nodes[ $id ] ) ) { return new WP_Error( 'prstudio_org_node_missing', 'Nodo organigramma non trovato.', array( 'status' => 404 ) ); }
			foreach ( $nodes as $node ) { if ( ( $node['parent_id'] ?? '' ) === $id ) { return new WP_Error( 'prstudio_org_node_has_children', 'Impossibile eliminare un nodo con figli senza prima riassegnarli.', array( 'status' => 409, 'node_id' => $id ) ); } }
			unset( $nodes[ $id ] );
		} else { return new WP_Error( 'prstudio_org_operation_invalid', 'Operazione organigramma non valida.', array( 'status' => 400 ) ); }
		$validation = self::validate_org_nodes( $nodes ); if ( is_wp_error( $validation ) ) { return $validation; }
		$state['nodes'] = $nodes; $write = self::store_config_data( 'org_chart_config', $state, $before );
		return self::with_outcome( array( 'nodes' => array_values( $nodes ), 'count' => count( $nodes ), 'acyclic' => true, 'degraded'=>empty($write['verified']), 'blocking'=>false ), !empty($write['verified'])?'completed':'degraded', true, true, !empty($write['verified']) );
	}

	private static function validate_org_nodes( array $nodes ) {
		foreach ( $nodes as $id => $node ) { $parent = (string) ( $node['parent_id'] ?? '' ); if ( $parent && ! isset( $nodes[ $parent ] ) ) { return new WP_Error( 'prstudio_org_parent_missing', 'parent_id non esiste nell’organigramma.', array( 'status' => 400, 'node_id' => $id, 'parent_id' => $parent ) ); } }
		foreach ( array_keys( $nodes ) as $start ) { $seen = array(); $cursor = $start; while ( $cursor && isset( $nodes[ $cursor ] ) ) { if ( isset( $seen[ $cursor ] ) ) { return new WP_Error( 'prstudio_org_cycle', 'L’organigramma contiene un ciclo.', array( 'status' => 409, 'node_id' => $start ) ); } $seen[ $cursor ] = true; $cursor = (string) ( $nodes[ $cursor ]['parent_id'] ?? '' ); } }
		return true;
	}

	private static function manage_feature_flags( array $payload ) {
		$operation = sanitize_key( (string) ( $payload['operation'] ?? 'upsert' ) ); $state = self::config_data( 'feature_flag_manage', array( 'flags' => array() ) ); $flags = is_array( $state['flags'] ?? null ) ? $state['flags'] : array(); $name = sanitize_key( (string) ( $payload['flag'] ?? $payload['name'] ?? '' ) );
		if ( 'list' === $operation ) { return self::with_outcome( array( 'flags' => array_values( $flags ), 'count' => count( $flags ) ), 'verified', true, false, true ); }
		if ( 'evaluate' === $operation ) { if ( '' === $name ) { return new WP_Error( 'prstudio_feature_flag_required', 'flag è obbligatorio.', array( 'status' => 400 ) ); } $enabled = self::feature_flag_observed_enabled( $name, (bool) ( $payload['default'] ?? false ), is_array( $payload['context'] ?? null ) ? $payload['context'] : array() ); return self::with_outcome( array( 'flag' => $name, 'enabled' => $enabled, 'evaluated' => true, 'telemetry_only'=>true, 'controls_execution'=>false ), 'verified', true, false, true ); }
		if ( 'get' === $operation ) { if ( '' === $name || ! isset( $flags[ $name ] ) ) { return new WP_Error( 'prstudio_feature_flag_missing', 'Feature flag non trovata.', array( 'status' => 404 ) ); } return self::with_outcome( array( 'flag' => $flags[ $name ] ), 'verified', true, false, true ); }
		if ( '' === $name ) { return new WP_Error( 'prstudio_feature_flag_required', 'flag è obbligatorio.', array( 'status' => 400 ) ); }
		$before = $state;
		if ( 'delete' === $operation ) { if ( ! isset( $flags[ $name ] ) ) { return new WP_Error( 'prstudio_feature_flag_missing', 'Feature flag non trovata.', array( 'status' => 404 ) ); } unset( $flags[ $name ] ); }
		elseif ( in_array( $operation, array( 'upsert','set' ), true ) ) { $rollout = max( 0.0, min( 100.0, (float) ( $payload['rollout_percent'] ?? 100 ) ) ); $flags[ $name ] = array( 'name' => $name, 'enabled' => array_key_exists( 'enabled', $payload ) ? (bool) $payload['enabled'] : true, 'rollout_percent' => $rollout, 'description' => sanitize_text_field( (string) ( $payload['description'] ?? '' ) ), 'updated_at' => current_time( 'mysql', true ) ); }
		else { return new WP_Error( 'prstudio_feature_flag_operation_invalid', 'Operazione feature flag non valida.', array( 'status' => 400 ) ); }
		$state['flags'] = $flags; $write = self::store_config_data( 'feature_flag_manage', $state, $before );
		return self::with_outcome( array( 'flag' => $name, 'record' => $flags[ $name ] ?? null, 'telemetry_only' => true, 'controls_execution' => false, 'degraded'=>empty($write['verified']), 'blocking'=>false ), !empty($write['verified'])?'completed':'degraded', true, true, !empty($write['verified']) );
	}

	public static function feature_flag_observed_enabled( string $name, bool $default = true, array $context = array() ): bool {
		$name = sanitize_key( $name ); $state = self::config_data( 'feature_flag_manage', array( 'flags' => array() ) ); $record = $state['flags'][ $name ] ?? null;
		if ( ! is_array( $record ) ) { return $default; } if ( empty( $record['enabled'] ) ) { return false; }
		$rollout = max( 0.0, min( 100.0, (float) ( $record['rollout_percent'] ?? 100 ) ) ); if ( $rollout >= 100 ) { return true; } if ( $rollout <= 0 ) { return false; }
		$subject = sanitize_text_field( (string) ( $context['subject'] ?? $context['subject_id'] ?? $context['user_id'] ?? 'anonymous' ) ); $bucket = hexdec( substr( hash( 'sha256', $name . '|' . $subject ), 0, 8 ) ) % 10000;
		return $bucket < (int) round( $rollout * 100 );
	}

	private static function manage_budget_allocations( array $payload ) {
		$operation = sanitize_key( (string) ( $payload['operation'] ?? 'allocate' ) ); $state = self::config_data( 'budget_allocate_across_divisions', array( 'total_budget' => 0.0, 'allocations' => array() ) ); $allocations = is_array( $state['allocations'] ?? null ) ? $state['allocations'] : array();
		if ( in_array( $operation, array( 'get','list','status' ), true ) ) { $allocated = array_sum( array_map( 'floatval', $allocations ) ); return self::with_outcome( array( 'total_budget' => (float) ( $state['total_budget'] ?? 0 ), 'allocations' => $allocations, 'allocated' => $allocated, 'remaining' => (float) ( $state['total_budget'] ?? 0 ) - $allocated ), 'verified', true, false, true ); }
		$before = $state; $total = array_key_exists( 'total_budget', $payload ) ? (float) $payload['total_budget'] : (float) ( $state['total_budget'] ?? 0 ); if ( $total < 0 ) { return new WP_Error( 'prstudio_budget_total_invalid', 'total_budget non può essere negativo.', array( 'status' => 400 ) ); }
		if ( 'release' === $operation ) { $division = sanitize_text_field( (string) ( $payload['division'] ?? '' ) ); if ( '' === $division || ! array_key_exists( $division, $allocations ) ) { return new WP_Error( 'prstudio_budget_division_missing', 'Divisione non trovata nelle allocazioni.', array( 'status' => 404 ) ); } unset( $allocations[ $division ] ); }
		elseif ( in_array( $operation, array( 'allocate','set','upsert' ), true ) ) {
			if ( is_array( $payload['allocations'] ?? null ) ) { $allocations = array(); foreach ( array_slice( $payload['allocations'], 0, 200, true ) as $division => $amount ) { $division = sanitize_text_field( (string) $division ); $amount = (float) $amount; if ( '' === $division || $amount < 0 ) { return new WP_Error( 'prstudio_budget_allocation_invalid', 'Allocazione non valida.', array( 'status' => 400, 'division' => $division ) ); } $allocations[ $division ] = $amount; } }
			elseif ( isset( $payload['division'] ) ) { $division = sanitize_text_field( (string) $payload['division'] ); $amount = (float) ( $payload['amount'] ?? -1 ); if ( '' === $division || $amount < 0 ) { return new WP_Error( 'prstudio_budget_allocation_invalid', 'division e amount >= 0 sono obbligatori.', array( 'status' => 400 ) ); } $allocations[ $division ] = $amount; }
			else { return new WP_Error( 'prstudio_budget_allocation_required', 'allocations oppure division+amount sono obbligatori.', array( 'status' => 400 ) ); }
		} else { return new WP_Error( 'prstudio_budget_operation_invalid', 'Operazione budget non valida.', array( 'status' => 400 ) ); }
		$allocated = array_sum( array_map( 'floatval', $allocations ) ); if ( $allocated > $total + 0.000001 ) { return new WP_Error( 'prstudio_budget_overallocated', 'Le allocazioni superano il budget totale.', array( 'status' => 409, 'total_budget' => $total, 'allocated' => $allocated ) ); }
		$state = array( 'total_budget' => $total, 'allocations' => $allocations, 'updated_at' => current_time( 'mysql', true ) ); $write = self::store_config_data( 'budget_allocate_across_divisions', $state, $before );
		return self::with_outcome( array( 'total_budget' => $total, 'allocations' => $allocations, 'allocated' => $allocated, 'remaining' => $total - $allocated, 'balanced' => abs($total-$allocated)<0.000001, 'degraded'=>empty($write['verified']), 'blocking'=>false ), !empty($write['verified'])?'completed':'degraded', true, true, !empty($write['verified']) );
	}


	private static function manage_escalation( array $payload ) {
		$operation = sanitize_key( (string) ( $payload['operation'] ?? 'create' ) ); $state = self::config_data( 'escalation_handle', array( 'items' => array() ) ); $items = is_array( $state['items'] ?? null ) ? $state['items'] : array(); $id = sanitize_text_field( (string) ( $payload['escalation_id'] ?? $payload['id'] ?? '' ) );
		if ( 'list' === $operation ) { return self::with_outcome( array( 'items' => array_values( $items ), 'count' => count( $items ) ), 'verified', true, false, true ); }
		if ( 'resolve' === $operation ) { if ( '' === $id || ! isset( $items[ $id ] ) ) { return new WP_Error( 'prstudio_escalation_missing', 'Escalation non trovata.', array( 'status' => 404 ) ); } $before = $state; $items[ $id ]['status'] = 'resolved'; $items[ $id ]['resolution'] = sanitize_textarea_field( (string) ( $payload['resolution'] ?? '' ) ); $items[ $id ]['resolved_at'] = current_time( 'mysql', true ); $state['items'] = $items; $write = self::store_config_data( 'escalation_handle', $state, $before ); return self::with_outcome( array( 'escalation' => $items[ $id ], 'resolved' => true, 'degraded'=>empty($write['verified']), 'blocking'=>false ), !empty($write['verified'])?'completed':'degraded', true, true, !empty($write['verified']) ); }
		if ( 'create' !== $operation ) { return new WP_Error( 'prstudio_escalation_operation_invalid', 'Operazione escalation non valida.', array( 'status' => 400 ) ); }
		$target = sanitize_key( (string) ( $payload['target_action'] ?? '' ) ); if ( '' === $target || 'escalation_handle' === $target || ! isset( self::actions()[ $target ] ) ) { return new WP_Error( 'prstudio_escalation_target_required', 'target_action deve essere un’azione enterprise valida.', array( 'status' => 400 ) ); }
		$severity = sanitize_key( (string) ( $payload['severity'] ?? 'high' ) ); if ( ! in_array( $severity, array( 'low','medium','high','critical' ), true ) ) { return new WP_Error( 'prstudio_escalation_severity_invalid', 'severity non valida.', array( 'status' => 400 ) ); }
		$id = $id ?: self::id( 'escalation' ); $routed_payload = is_array( $payload['payload'] ?? null ) ? $payload['payload'] : array(); $routed_payload['_prstudio_escalation'] = array( 'id' => $id, 'severity' => $severity, 'reason' => sanitize_textarea_field( (string) ( $payload['reason'] ?? '' ) ) ); $created = self::create_job( $target, $routed_payload, (string) ( $payload['idempotency_key'] ?? 'escalation:' . $id ), (int) ( $payload['priority'] ?? ( 'critical' === $severity ? 200 : 100 ) ), 'queued' ); $job_id = (string) ( $created['job']['id'] ?? '' );
		$record = array( 'id' => $id, 'status' => 'routed', 'severity' => $severity, 'reason' => sanitize_textarea_field( (string) ( $payload['reason'] ?? '' ) ), 'target_action' => $target, 'target_job_id' => $job_id, 'created_at' => current_time( 'mysql', true ) ); $before = $state; $items[ $id ] = $record; $state['items'] = $items; $write = self::store_config_data( 'escalation_handle', $state, $before );
		return self::with_outcome( array( 'escalation' => $record, 'target_job' => $created, 'routed' => true, 'degraded'=>empty($write['verified']), 'blocking'=>false ), !empty($write['verified'])?'completed':'degraded', true, true, !empty($write['verified']) );
	}
	private static function save_research( string $action, array $payload, array $result = array() ): array { $key = 'pr_studio_research_' . $action; $safe_payload = self::clean( $payload ); unset( $safe_payload['persist_research'] ); $state = WPAIB_Enterprise::work_state( array( 'action' => 'append', 'key' => $key, 'data' => array( 'market' => array( 'country' => 'Italia', 'region' => 'Sicilia', 'province' => 'Agrigento' ), 'request' => $safe_payload, 'evidence' => self::clean( $result ), 'recorded_at' => current_time( 'mysql', true ) ) ) ); return array( 'recorded' => true, 'state' => $state, 'note' => 'Persistenza opzionale dell’evidenza già prodotta dall’executor semantico; nessun risultato esterno viene inventato.' ); }
	private static function security_scan_native( array $payload ): array { $path = sanitize_text_field( (string) ( $payload['path'] ?? 'wp-content' ) ); $patterns = array( 'eval' . '(base64_decode' => 'possible_obfuscation', 'gzinflate' . '(base64_decode' => 'possible_obfuscation', 'shell_exec' . '(' => 'shell_execution', 'passthru' . '(' => 'shell_execution' ); $findings = array(); foreach ( $patterns as $needle => $type ) { $result = WPAIB_Files::search( $needle, $path, array( 'php' ), 0, 30 ); if ( ! is_wp_error( $result ) && ! empty( $result['matches'] ) ) { foreach ( $result['matches'] as $match ) { $match['type'] = $type; $findings[] = $match; } } } return array( 'path' => $path, 'findings' => $findings, 'count' => count( $findings ), 'automated_verdict' => $findings ? 'findings_detected' : 'no_known_pattern_found' ); }
	private static function accessibility_native( array $payload ): array { $url = (string) ( $payload['url_or_path'] ?? '/' ); $page = WPAIB_Site::fetch_page( $url ); if ( is_wp_error( $page ) ) { return $page; } $html = (string) $page['html']; preg_match_all( '/<img\b(?![^>]*\balt=)[^>]*>/i', $html, $missing_alt ); preg_match_all( '/<html\b(?![^>]*\blang=)[^>]*>/i', $html, $missing_lang ); return array( 'url' => $page['url'], 'status' => $page['status'], 'checks' => array( 'images_missing_alt' => count( $missing_alt[0] ), 'html_missing_lang' => count( $missing_lang[0] ) ), 'scope' => 'automated_basic_check_not_legal_certification' ); }
	private static function catalog_enrich_native( array $payload ) {
		global $wpdb;
		$limit = max( 1, min( 100, (int) ( $payload['limit'] ?? 100 ) ) );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $payload['product_ids'] ?? null ) ? array_slice( $payload['product_ids'], 0, $limit ) : array() ) ) ) );
		if ( ! $ids && ! empty( $payload['id'] ) ) { $ids[] = absint( $payload['id'] ); }
		if ( ! $ids && function_exists( 'wc_get_product_id_by_sku' ) && ! empty( $payload['sku'] ) ) { $sku_id = wc_get_product_id_by_sku( sanitize_text_field( (string) $payload['sku'] ) ); if ( $sku_id ) { $ids[] = absint( $sku_id ); } }
		if ( ! $ids && function_exists( 'wc_get_products' ) && ( ! empty( $payload['search'] ) || ! empty( $payload['status'] ) ) ) {
			$query = array( 'limit' => $limit, 'return' => 'ids' ); if ( ! empty( $payload['search'] ) ) { $query['s'] = sanitize_text_field( (string) $payload['search'] ); } if ( ! empty( $payload['status'] ) ) { $query['status'] = sanitize_key( (string) $payload['status'] ); } $ids = array_map( 'absint', wc_get_products( $query ) );
		}
		$changes = is_array( $payload['changes'] ?? null ) ? $payload['changes'] : array();
		$missing = array(); if ( ! $ids ) { $missing[] = 'product_ids|id|sku|search/status'; } if ( ! $changes ) { $missing[] = 'changes'; }

		/* 0.3.10: compile the batch contract before writing. 0.3.9 forwarded all
		 * keys to WC_Product, so metadata could be silently ignored while save()
		 * still changed date_modified. Unknown fields return a technical schema error. */
		$product_fields = array(
			'name','slug','status','catalog_visibility','description','short_description','sku','regular_price','sale_price','stock_status','backorders','weight','length','width','height','tax_status','tax_class','purchase_note','global_unique_id',
			'featured','manage_stock','sold_individually','stock_quantity','shipping_class_id','menu_order','image_id','category_ids','tag_ids','gallery_image_ids','attributes','date_on_sale_from','date_on_sale_to','expected_modified_gmt'
		);
		$product_changes = array(); $meta_changes = array(); $unknown = array();
		foreach ( $changes as $field => $value ) {
			$key = sanitize_key( (string) $field );
			if ( in_array( $key, array( 'metadata', 'meta' ), true ) ) {
				if ( ! is_array( $value ) ) { $unknown[] = $field; continue; }
				foreach ( $value as $meta_key => $meta_value ) { $meta_changes[ sanitize_key( (string) $meta_key ) ] = $meta_value; }
				continue;
			}
			if ( in_array( $key, $product_fields, true ) ) { $product_changes[ $key ] = $value; continue; }
			if ( 0 === strpos( $key, 'rank_math_' ) || 0 === strpos( $key, '_yoast_wpseo_' ) || 0 === strpos( $key, '_aioseo_' ) || in_array( $key, array( '_wp_attachment_image_alt','_thumbnail_id','_product_image_gallery','_global_unique_id','_crosssell_ids','_upsell_ids' ), true ) ) { $meta_changes[ $key ] = $value; continue; }
			$unknown[] = $field;
		}
		if ( $unknown ) { $missing[] = 'unsupported_changes:' . implode( ',', array_map( 'sanitize_key', $unknown ) ); }
		$meta_keys = array_values( array_unique( array_filter( array_keys( $meta_changes ) ) ) );

		$snapshot = static function( array $target_ids, array $keys ): array {
			$batch = WPAIB_Enterprise::get_products_batch( array( 'ids' => $target_ids ) );
			$items = is_wp_error( $batch ) ? array() : (array) ( $batch['items'] ?? array() );
			$meta = array();
			foreach ( $target_ids as $product_id ) {
				if ( ! $keys ) { $meta[ $product_id ] = array(); continue; }
				$read = WPAIB_Enterprise::get_object_meta( array( 'object_type' => 'post', 'object_id' => $product_id, 'keys' => $keys ) );
				$meta[ $product_id ] = is_wp_error( $read ) ? array( '__error' => $read->get_error_code() ) : (array) ( $read['meta'] ?? array() );
			}
			return array( 'items' => $items, 'missing_ids' => is_wp_error( $batch ) ? $target_ids : (array) ( $batch['missing_ids'] ?? array() ), 'meta' => $meta );
		};
		$before_state = $ids ? $snapshot( $ids, $meta_keys ) : array( 'items' => array(), 'missing_ids' => array(), 'meta' => array() );
		$changed_fields = array_values( array_unique( array_merge( array_keys( $product_changes ), $meta_keys ) ) );
		$preview = array(
			'preview' => true, 'action' => 'catalog_enrich_bulk', 'contract_version' => '1.0.0', 'executable' => empty( $missing ),
			'targets' => array( 'resolved_ids' => $ids, 'count' => count( $ids ), 'missing_ids' => (array) ( $before_state['missing_ids'] ?? array() ) ),
			'changes' => array( 'fields' => $changed_fields, 'product_fields' => array_keys( $product_changes ), 'metadata_fields' => $meta_keys, 'payload' => self::clean( $changes ) ), 'missing_inputs' => $missing,
			'write_set' => array_map( static fn( $id ) => array( 'product_id' => $id, 'fields' => $changed_fields ), $ids ),
			'read_set' => array_map( static fn( $item ) => array( 'product_id' => $item['id'], 'date_modified_gmt' => $item['date_modified_gmt'] ?? null ), (array) $before_state['items'] ),
			'execution' => array( 'provider' => 'woocommerce_crud_plus_wordpress_metadata_api', 'external_http_calls' => 0, 'batch_get' => true, 'chunk_size' => 25, 'transaction' => 'single_mysql_transaction', 'rollback' => 'rollback_plus_product_and_metadata_fresh_read_verification', 'pacing' => 'local_crud_no_remote_burst' ),
			'safety' => array( 'only_requested_fields' => true, 'unknown_fields_rejected' => true, 'metadata_verified_individually' => true ),
		);
		if ( ! empty( $payload['_preview'] ) || ! empty( $payload['preview'] ) ) { return $preview; }
		if ( $missing ) { return new WP_Error( 'prstudio_catalog_contract_incomplete', 'Il contratto batch contiene input mancanti o campi non supportati.', array( 'status' => 400, 'preview' => $preview ) ); }

		$tables = array_filter( array_unique( array( $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'wc_product_meta_lookup' ) ), static fn( $table ) => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		foreach ( $tables as $table ) { $engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ); if ( $engine && ! in_array( strtolower( $engine ), array( 'innodb','ndbcluster' ), true ) ) { return new WP_Error( 'prstudio_catalog_transaction_engine', 'Il batch richiede tabelle transazionali per garantire rollback atomico.', array( 'status' => 409, 'table' => $table, 'engine' => $engine ) ); } }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new WP_Error( 'prstudio_catalog_transaction_start_failed', 'Impossibile avviare la transazione catalogo.', array( 'status' => 503, 'cause' => $wpdb->last_error ) ); }
		$results = array(); $changed = 0; $failure = null;
		try {
			if ( false === $wpdb->query( 'SAVEPOINT prstudio_catalog_guard' ) ) { throw new RuntimeException( $wpdb->last_error ?: 'SAVEPOINT catalogo non disponibile.' ); }
			foreach ( $ids as $id ) {
				$row = array( 'product_id' => $id, 'product' => null, 'metadata' => array(), 'verified_fields' => array() ); $row_changed = false;
				if ( $product_changes ) {
					$args = array_merge( array( 'id' => $id ), $product_changes ); $result = WPAIB_Enterprise::update_product( $args );
					if ( is_wp_error( $result ) ) { $failure = $result; throw new RuntimeException( 'product[' . $id . ']: ' . $result->get_error_message() ); }
					$row['product'] = $result; $row['verified_fields'] = array_merge( $row['verified_fields'], (array) ( $result['verified_fields'] ?? array() ) );
					$row_changed = $row_changed || ( $result['before'] !== $result['after'] );
				}
				foreach ( $meta_changes as $meta_key => $spec ) {
					$meta_action = 'set'; $meta_value = $spec; $expected_before = null; $has_expected = false;
					if ( is_array( $spec ) && isset( $spec['action'] ) ) { $meta_action = sanitize_key( (string) $spec['action'] ); $meta_value = $spec['value'] ?? ''; if ( array_key_exists( 'expected_before', $spec ) ) { $expected_before = $spec['expected_before']; $has_expected = true; } }
					if ( null === $spec ) { $meta_action = 'delete'; $meta_value = ''; }
					$meta_args = array( 'object_type' => 'post', 'object_id' => $id, 'key' => $meta_key, 'action' => $meta_action, 'value' => $meta_value );
					if ( $has_expected ) { $meta_args['expected_before'] = $expected_before; }
					$meta_result = WPAIB_Enterprise::update_object_meta( $meta_args );
					if ( is_wp_error( $meta_result ) ) { $failure = $meta_result; throw new RuntimeException( 'meta[' . $id . '][' . $meta_key . ']: ' . $meta_result->get_error_message() ); }
					$row['metadata'][ $meta_key ] = $meta_result; $row['verified_fields'][] = $meta_key; $row_changed = $row_changed || ( ( $meta_result['before'] ?? null ) !== ( $meta_result['after'] ?? null ) );
				}
				$row['verified_fields'] = array_values( array_unique( $row['verified_fields'] ) ); $row['requested_effect_verified'] = true; $results[ $id ] = $row; if ( $row_changed ) { $changed++; }
			}
			if ( false === $wpdb->query( 'RELEASE SAVEPOINT prstudio_catalog_guard' ) || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( $wpdb->last_error ?: 'COMMIT catalogo fallito.' ); }
		} catch ( Throwable $e ) {
			$rollback = false !== $wpdb->query( 'ROLLBACK' ); if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); }
			$after_rollback = $snapshot( $ids, $meta_keys );
			$verified = $rollback && wp_json_encode( $before_state ) === wp_json_encode( $after_rollback );
			return new WP_Error( 'prstudio_catalog_transaction_failed', 'Batch catalogo non completato per errore tecnico; il ripristino compensativo è riportato come evidenza.', array( 'status' => 500, 'cause' => $e->getMessage(), 'operation_error' => $failure ? array( 'code' => $failure->get_error_code(), 'message' => $failure->get_error_message() ) : null, 'rollback_verified' => $verified, 'rollback_degraded'=>!$verified ) );
		}
		if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); }
		$after_state = $snapshot( $ids, $meta_keys );
		$status = $changed > 0 ? 'completed' : 'verified';
		return self::with_outcome( array( 'processed' => count( $results ), 'changed' => $changed, 'results' => $results, 'before_state' => $before_state, 'after_state' => $after_state, 'preview_contract' => $preview, 'transaction' => 'committed', 'requested_effect_verified' => true ), $status, true, $changed > 0, true );
	}

	public static function job_list( array $args = array() ): array { $jobs = array_values( (array) get_option( self::JOBS, array() ) ); $jobs = array_reverse( $jobs ); $items = array(); foreach ( $jobs as $job ) { if ( ! empty( $args['status'] ) && $job['status'] !== $args['status'] ) { continue; } if ( ! empty( $args['action'] ) && $job['action'] !== $args['action'] ) { continue; } if ( ! empty( $args['division'] ) && false === stripos( $job['division'], (string) $args['division'] ) ) { continue; } $items[] = $job; if ( count( $items ) >= max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) ) ) { break; } } return array( 'items' => $items, 'count' => count( $items ) ); }
	public static function job_get( string $id ) { $jobs = (array) get_option( self::JOBS, array() ); return isset( $jobs[ $id ] ) ? $jobs[ $id ] : new WP_Error( 'prstudio_job_missing', 'Job non trovato.', array( 'status' => 404 ) ); }
	public static function job_cancel( string $id, string $expected = '' ) { $job = self::job_get( $id ); if ( is_wp_error( $job ) ) { return $job; } if ( $expected && $job['status'] !== $expected ) { return new WP_Error( 'prstudio_job_conflict', 'Stato job non corrispondente.', array( 'status' => 409, 'current' => $job ) ); } if ( in_array( $job['status'], self::TERMINAL_JOB_STATUSES, true ) ) { return new WP_Error( 'prstudio_job_terminal', 'Job già concluso.', array( 'status' => 409 ) ); } return self::update_job( $id, array( 'status' => 'cancelled' ) ); }

	public static function job_retry( array $args ) {
		$id = sanitize_text_field( (string) ( $args['job_id'] ?? '' ) ); $job = self::job_get( $id ); if ( is_wp_error( $job ) ) { return $job; }
		$expected = sanitize_key( (string) ( $args['expected_status'] ?? '' ) ); if ( $expected && $expected !== (string) $job['status'] ) { return new WP_Error( 'prstudio_job_conflict', 'Stato job non corrispondente.', array( 'status' => 409, 'current' => $job ) ); }
		if ( ! in_array( (string) $job['status'], array( 'technical_error','cancelled' ), true ) ) { return new WP_Error( 'prstudio_job_retry_invalid', 'Sono riprovabili soltanto job technical_error o cancelled.', array( 'status' => 409, 'current' => $job ) ); }
		$key = sanitize_text_field( (string) ( $args['idempotency_key'] ?? ( 'retry:' . $id . ':' . gmdate( 'YmdHis' ) ) ) );
		return self::create_job( (string) $job['action'], is_array( $job['payload'] ?? null ) ? $job['payload'] : array(), $key, (int) ( $args['priority'] ?? $job['priority'] ?? 10 ), 'queued' );
	}

	public static function job_delete( array $args ) {
		$id = sanitize_text_field( (string) ( $args['job_id'] ?? '' ) ); $jobs = (array) get_option( self::JOBS, array() );
		if ( ! isset( $jobs[ $id ] ) ) { return new WP_Error( 'prstudio_job_missing', 'Job non trovato.', array( 'status' => 404 ) ); }
		$job = $jobs[ $id ]; $expected = sanitize_key( (string) ( $args['expected_status'] ?? '' ) );
		if ( $expected && $expected !== (string) ( $job['status'] ?? '' ) ) { return new WP_Error( 'prstudio_job_conflict', 'Stato job non corrispondente.', array( 'status' => 409, 'current' => $job ) ); }
		unset( $jobs[ $id ] ); self::store( self::JOBS, $jobs ); $verified = ! isset( ( (array) get_option( self::JOBS, array() ) )[ $id ] );
		WPAIB_Audit::log( 'agency.job.delete', $verified ? 'success' : 'degraded', $id, array( 'before' => $job, 'verified'=>$verified ) );
		return array( 'job_id' => $id, 'deleted' => true, 'executed'=>true, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false, 'remaining' => count( $jobs ) );
	}

	private static function matching_jobs( array $jobs, array $args ): array {
		$statuses = is_array( $args['statuses'] ?? null ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $args['statuses'] ) ) ) ) : self::TERMINAL_JOB_STATUSES;
		$action = sanitize_key( (string) ( $args['action'] ?? '' ) ); $older_than = ! empty( $args['older_than_gmt'] ) ? strtotime( (string) $args['older_than_gmt'] . ' UTC' ) : false; $matched = array();
		foreach ( $jobs as $id => $job ) {
			$status = sanitize_key( (string) ( $job['status'] ?? '' ) ); if ( $statuses && ! in_array( $status, $statuses, true ) ) { continue; }
			if ( $action && $action !== sanitize_key( (string) ( $job['action'] ?? '' ) ) ) { continue; }
			if ( $older_than ) { $updated = strtotime( (string) ( $job['updated_at'] ?? $job['created_at'] ?? '' ) . ' UTC' ); if ( ! $updated || $updated >= $older_than ) { continue; } }
			$matched[ $id ] = $job;
		}
		return $matched;
	}

	private static function store_job_backup( array $jobs, array $filters ): string {
		$backups = (array) get_option( self::JOB_BACKUPS, array() ); $id = self::id( 'jobpurge' );
		$backups[ $id ] = array( 'id' => $id, 'created_at' => current_time( 'mysql', true ), 'filters' => self::clean( $filters ), 'count' => count( $jobs ), 'jobs' => $jobs );
		if ( count( $backups ) > 3 ) { $backups = array_slice( $backups, -3, null, true ); }
		update_option( self::JOB_BACKUPS, $backups, false ); return $id;
	}

	public static function job_purge( array $args ) {
		$jobs = (array) get_option( self::JOBS, array() ); $matched = self::matching_jobs( $jobs, $args ); $count = count( $matched ); $dry_run = ! array_key_exists( 'dry_run', $args ) || ! empty( $args['dry_run'] );
		if ( array_key_exists( 'expected_count', $args ) && (int) $args['expected_count'] !== $count ) { return new WP_Error( 'prstudio_job_purge_count_conflict', 'Il conteggio dei job da eliminare non corrisponde a expected_count.', array( 'status' => 409, 'expected_count' => (int) $args['expected_count'], 'actual_count' => $count ) ); }
		$preview = array( 'dry_run' => $dry_run, 'matched_count' => $count, 'matched_statuses' => array_count_values( array_map( static function( $job ) { return sanitize_key( (string) ( $job['status'] ?? 'unknown' ) ); }, $matched ) ), 'job_ids' => array_slice( array_keys( $matched ), 0, 200 ), 'truncated_ids' => $count > 200, 'current_total' => count( $jobs ) );
		if ( $dry_run || 0 === $count ) { return $preview + array( 'deleted' => 0, 'verified' => true ); }
		$existing_lock = get_option( self::PURGE_LOCK, null );
		if ( is_array( $existing_lock ) && (int) ( $existing_lock['created_at'] ?? 0 ) < time() - 900 ) {
			delete_option( self::PURGE_LOCK );
			WPAIB_Audit::log( 'agency.job.purge_lock', 'recovered', self::PURGE_LOCK, array( 'stale_lock' => self::clean( $existing_lock ) ) );
		}
		if ( ! function_exists( 'add_option' ) || ! add_option( self::PURGE_LOCK, array( 'created_at' => time(), 'count' => $count ), '', false ) ) { return new WP_Error( 'prstudio_job_purge_locked', 'Un’altra operazione di purge è già in corso.', array( 'status' => 423 ) ); }
		$backup_id = '';
		try {
			if ( ! array_key_exists( 'backup', $args ) || ! empty( $args['backup'] ) ) { $backup_id = self::store_job_backup( $matched, $args ); }
			foreach ( array_keys( $matched ) as $id ) { unset( $jobs[ $id ] ); }
			self::store( self::JOBS, $jobs ); $remaining = (array) get_option( self::JOBS, array() ); $left = array_intersect_key( $remaining, $matched ); $verified = empty( $left );
			WPAIB_Audit::log( 'agency.job.purge', $verified ? 'success' : 'degraded', $backup_id, array( 'deleted' => $count, 'filters' => self::clean( $args ), 'remaining' => count( $remaining ), 'verified'=>$verified ) );
			return $preview + array( 'dry_run' => false, 'deleted' => $count, 'executed'=>true, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false, 'backup_id' => $backup_id, 'remaining' => count( $remaining ), 'remaining_matched_ids'=>array_keys( $left ) );
		} finally { delete_option( self::PURGE_LOCK ); }
	}

	public static function job_purge_restore( array $args ) {
		$id = sanitize_text_field( (string) ( $args['backup_id'] ?? '' ) ); $backups = (array) get_option( self::JOB_BACKUPS, array() );
		if ( ! isset( $backups[ $id ] ) || ! is_array( $backups[ $id ]['jobs'] ?? null ) ) { return new WP_Error( 'prstudio_job_backup_missing', 'Backup purge non trovato.', array( 'status' => 404 ) ); }
		$current = (array) get_option( self::JOBS, array() ); if ( array_key_exists( 'expected_current_count', $args ) && (int) $args['expected_current_count'] !== count( $current ) ) { return new WP_Error( 'prstudio_job_restore_count_conflict', 'Il conteggio corrente non corrisponde a expected_current_count.', array( 'status' => 409, 'current_count' => count( $current ) ) ); }
		$conflicts = array_intersect_key( $current, $backups[ $id ]['jobs'] ); if ( $conflicts ) { return new WP_Error( 'prstudio_job_restore_conflict', 'Alcuni ID del backup sono già presenti.', array( 'status' => 409, 'job_ids' => array_keys( $conflicts ) ) ); }
		$restored = $current + $backups[ $id ]['jobs']; self::store( self::JOBS, $restored ); $verified = count( (array) get_option( self::JOBS, array() ) ) === count( $restored );
		WPAIB_Audit::log( 'agency.job.purge_restore', $verified ? 'success' : 'degraded', $id, array( 'restored' => count( $backups[ $id ]['jobs'] ), 'total' => count( $restored ), 'verified'=>$verified ) );
		return array( 'backup_id' => $id, 'restored' => count( $backups[ $id ]['jobs'] ), 'executed'=>true, 'verified' => $verified, 'degraded'=>!$verified, 'blocking'=>false, 'total' => count( $restored ) );
	}

	public static function schedule_list( array $args = array() ): array { $items = array_values( (array) get_option( self::SCHEDULES, array() ) ); $items = array_values( array_filter( $items, static function( $item ) use ( $args ) { if ( array_key_exists( 'enabled', $args ) && (bool) $item['enabled'] !== (bool) $args['enabled'] ) { return false; } if ( ! empty( $args['action'] ) && $item['action'] !== $args['action'] ) { return false; } return true; } ) ); return array( 'items' => $items, 'count' => count( $items ) ); }
	public static function schedule_upsert( array $args ) {
		$action = sanitize_key( (string) ( $args['action'] ?? '' ) ); if ( ! isset( self::actions()[ $action ] ) ) { return new WP_Error( 'prstudio_action_unknown', 'Azione non valida.', array( 'status' => 400 ) ); } $all = (array) get_option( self::SCHEDULES, array() ); $id = sanitize_text_field( (string) ( $args['schedule_id'] ?? '' ) ); if ( ! $id ) { $id = self::id( 'schedule' ); }
		$current = $all[ $id ] ?? null; if ( $current && ! empty( $args['expected_updated_at'] ) && (string) $current['updated_at'] !== (string) $args['expected_updated_at'] ) { return new WP_Error( 'prstudio_schedule_conflict', 'Schedule modificato nel frattempo.', array( 'status' => 409, 'current' => $current ) ); }
		$first = ! empty( $args['first_run_gmt'] ) ? strtotime( (string) $args['first_run_gmt'] ) : time() + 60; if ( ! $first ) { $first = time() + 60; } $interval = max( 300, (int) ( $args['interval_seconds'] ?? 3600 ) ); $all[ $id ] = array( 'id' => $id, 'action' => $action, 'payload' => self::clean( is_array( $args['payload'] ?? null ) ? $args['payload'] : array() ), 'first_run_gmt' => gmdate( DATE_ATOM, $first ), 'next_run_gmt' => gmdate( DATE_ATOM, $first ), 'interval_seconds' => $interval, 'cron' => sanitize_text_field( (string) ( $args['cron'] ?? '' ) ), 'enabled' => array_key_exists( 'enabled', $args ) ? (bool) $args['enabled'] : true, 'priority' => max( 0, min( 255, (int) ( $args['priority'] ?? 10 ) ) ), 'created_at' => $current['created_at'] ?? current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ), 'last_run_gmt' => $current['last_run_gmt'] ?? null ); self::store( self::SCHEDULES, $all ); WPAIB_Audit::log( 'agency.schedule.upsert', 'success', $id, $all[ $id ] ); PRSTUDIO_Report::record_change( 'Pianificazione PR STUDIO', $id, $current, $all[ $id ] ); return $all[ $id ];
	}
	public static function schedule_delete( string $id, string $expected = '' ) { $all = (array) get_option( self::SCHEDULES, array() ); if ( ! isset( $all[ $id ] ) ) { return new WP_Error( 'prstudio_schedule_missing', 'Schedule non trovato.', array( 'status' => 404 ) ); } $before = $all[ $id ]; if ( $expected && $before['updated_at'] !== $expected ) { return new WP_Error( 'prstudio_schedule_conflict', 'Schedule modificato nel frattempo.', array( 'status' => 409 ) ); } unset( $all[ $id ] ); self::store( self::SCHEDULES, $all ); WPAIB_Audit::log( 'agency.schedule.delete', 'success', $id, array( 'before' => $before ) ); PRSTUDIO_Report::record_change( 'Pianificazione PR STUDIO', $id, $before, array( 'deleted' => true ) ); return array( 'schedule_id' => $id, 'deleted' => true ); }

	public static function cron_tick(): void {
		$now = time(); $schedules = (array) get_option( self::SCHEDULES, array() ); $changed = false;
		foreach ( $schedules as $id => &$schedule ) { if ( empty( $schedule['enabled'] ) ) { continue; } $next = strtotime( (string) ( $schedule['next_run_gmt'] ?? '' ) ); if ( ! $next || $next > $now ) { continue; } self::execute( $schedule['action'], array( 'payload' => $schedule['payload'], 'execution_mode' => 'run', 'idempotency_key' => 'schedule:' . $id . ':' . gmdate( 'YmdHi', $next ), 'priority' => $schedule['priority'] ) ); $schedule['last_run_gmt'] = gmdate( DATE_ATOM, $now ); $schedule['next_run_gmt'] = gmdate( DATE_ATOM, $now + max( 300, (int) $schedule['interval_seconds'] ) ); $schedule['updated_at'] = current_time( 'mysql', true ); $changed = true; }
		unset( $schedule ); if ( $changed ) { self::store( self::SCHEDULES, $schedules ); }
		$jobs = (array) get_option( self::JOBS, array() ); $queued = array_filter( $jobs, static function( $job ) { return 'queued' === ( $job['status'] ?? '' ); } ); uasort( $queued, static function( $a, $b ) { return (int) ( $b['priority'] ?? 10 ) <=> (int) ( $a['priority'] ?? 10 ); } ); foreach ( array_slice( $queued, 0, 10, true ) as $job ) { self::run_job( $job ); }
	}

	private static function broken_link_scan( array $args ): array {
		$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) ); $post_type = sanitize_key( (string) ( $args['post_type'] ?? 'any' ) ); $posts = get_posts( array( 'post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => $limit, 'orderby' => 'modified', 'order' => 'DESC' ) ); $findings = array();
		foreach ( $posts as $post ) { if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $post->post_content, $links, PREG_SET_ORDER ) ) { continue; } foreach ( $links as $link ) { $url = trim( html_entity_decode( $link[1] ) ); if ( '' === $url || '#' === $url[0] || preg_match( '#^(?:mailto|tel|sms|javascript|data):#i', $url ) ) { continue; } $parts = wp_parse_url( $url ); if ( ! is_array( $parts ) ) { continue; } if ( ! empty( $parts['scheme'] ) && ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http','https' ), true ) ) { continue; } if ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) { continue; } $target = ! empty( $parts['host'] ) ? $url : home_url( '/' . ltrim( $url, '/' ) ); $response = wp_safe_remote_head( $target, array( 'timeout' => 8, 'redirection' => 3 ) ); $status = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ); if ( 0 === $status || $status >= 400 ) { $findings[] = array( 'content_id' => $post->ID, 'content_url' => get_permalink( $post ), 'target_url' => $target, 'anchor' => wp_strip_all_tags( $link[2] ), 'status' => $status, 'error' => is_wp_error( $response ) ? $response->get_error_message() : null ); } } }
		$state = WPAIB_Enterprise::work_state( array( 'action' => 'set', 'key' => 'pr_studio_broken_links', 'data' => array( 'checked_at' => current_time( 'mysql', true ), 'findings' => $findings ) ) ); return array( 'checked_contents' => count( $posts ), 'broken_links' => $findings, 'count' => count( $findings ), 'state' => $state );
	}
	private static function anchor_manage( array $args ) {
		$id = absint( $args['content_id'] ?? 0 ); $content = WPAIB_Site::get_content( $id ); if ( is_wp_error( $content ) ) { return $content; } $old_url = (string) ( $args['old_url'] ?? '' ); $new_url = (string) ( $args['new_url'] ?? '' ); $old_anchor = (string) ( $args['old_anchor'] ?? '' ); $new_anchor = (string) ( $args['new_anchor'] ?? '' ); $updated = $content['content']; if ( $old_url && $new_url ) { $updated = str_replace( 'href="' . esc_attr( $old_url ) . '"', 'href="' . esc_attr( $new_url ) . '"', $updated ); $updated = str_replace( "href='" . esc_attr( $old_url ) . "'", "href='" . esc_attr( $new_url ) . "'", $updated ); } if ( $old_anchor && $new_anchor ) { $updated = str_replace( '>' . $old_anchor . '</a>', '>' . $new_anchor . '</a>', $updated ); } $changes = $updated !== $content['content']; if ( ! empty( $args['preview'] ) || ! $changes ) { return array( 'content_id' => $id, 'changes_detected' => $changes, 'preview' => true, 'before_hash' => hash( 'sha256', $content['content'] ), 'after_hash' => hash( 'sha256', $updated ) ); } return WPAIB_Site::update_content( array( 'id' => $id, 'content' => $updated, 'expected_modified_gmt' => $content['modified_gmt'] ) );
	}
	private static function backlink( array $args ) { $operation = sanitize_key( (string) ( $args['operation'] ?? 'list' ) ); if ( 'list' === $operation ) { return WPAIB_Enterprise::work_state( array( 'action' => 'get', 'key' => 'pr_studio_backlinks' ) ); } $opportunity = is_array( $args['opportunity'] ?? null ) ? self::clean( $args['opportunity'] ) : array(); $opportunity['market'] = $opportunity['market'] ?? 'Italia / Sicilia / Agrigento'; $opportunity['updated_at'] = current_time( 'mysql', true ); return WPAIB_Enterprise::work_state( array( 'action' => 'append', 'key' => 'pr_studio_backlinks', 'data' => $opportunity ) ); }
	private static function report_generate( array $args ): array { $hours = max( 1, min( 720, (int) ( $args['since_hours'] ?? 24 ) ) ); $audit = WPAIB_Audit::recent( 200 ); $cutoff = time() - $hours * HOUR_IN_SECONDS; $items = array_values( array_filter( $audit['items'] ?? array(), static function( $item ) use ( $cutoff ) { return strtotime( (string) ( $item['created_at'] ?? '' ) . ' UTC' ) >= $cutoff; } ) ); $report = array( 'generated_at' => current_time( 'mysql' ), 'period_hours' => $hours, 'audit_events' => $items, 'jobs' => self::job_list( array( 'limit' => 100 ) ), 'orchestration' => self::status() ); if ( ! empty( $args['send_email'] ) ) { PRSTUDIO_Report::record_change( 'Report PR STUDIO', 'report-' . gmdate( 'YmdHis' ), null, array( 'events' => count( $items ), 'period_hours' => $hours ) ); PRSTUDIO_Report::flush(); $report['email_requested'] = true; } return $report; }

	public static function execute_control_fallback( string $route, string $action, array $arguments, array $meta ) {
		$plan = self::execute_backend_plan( $action, $arguments, $meta );
		if ( null !== $plan ) { return $plan; }
		return new WP_Error(
			'prstudio_backend_executor_contract_violation',
			'L’azione è dichiarata nel catalogo ma non ha prodotto un executor backend nativo o un piano compilabile. Il runtime segnala questa condizione come errore tecnico di binding senza inoltrarla a ChatGPT.',
			array(
				'status' => 500,
				'route' => $route,
				'action' => $action,
				'provider' => 'backend_executor',
				'catalog_only' => true,
			)
		);
	}

	public static function dispatch( string $name, array $args ) {
		$control = self::control_action_by_tool( $name );
		if ( is_array( $control ) ) { return WPAIB_REST::execute_control_action( (string) $control['route'], (string) $control['action'], $args, 'mcp' ); }
		if ( isset( self::actions()[ $name ] ) ) { return self::execute( $name, $args ); }
		switch ( $name ) {
			case 'enterprise_action_catalog': return self::action_catalog( $args );
			case 'enterprise_action_execute': return self::execute( sanitize_key( (string) ( $args['action'] ?? '' ) ), $args );
			case 'enterprise_orchestration_status': return self::status();
			case 'enterprise_job_list': return self::job_list( $args );
			case 'enterprise_job_get': return self::job_get( sanitize_text_field( (string) ( $args['job_id'] ?? '' ) ) );
			case 'enterprise_job_cancel': return self::job_cancel( sanitize_text_field( (string) ( $args['job_id'] ?? '' ) ), sanitize_text_field( (string) ( $args['expected_status'] ?? '' ) ) );
			case 'enterprise_job_retry': return self::job_retry( $args );
			case 'enterprise_job_delete': return self::job_delete( $args );
			case 'enterprise_job_purge': return self::job_purge( $args );
			case 'enterprise_job_purge_restore': return self::job_purge_restore( $args );
			case 'enterprise_schedule_list': return self::schedule_list( $args );
			case 'enterprise_schedule_upsert': return self::schedule_upsert( $args );
			case 'enterprise_schedule_delete': return self::schedule_delete( sanitize_text_field( (string) ( $args['schedule_id'] ?? '' ) ), sanitize_text_field( (string) ( $args['expected_updated_at'] ?? '' ) ) );
			case 'pr_studio_broken_link_scan': return self::broken_link_scan( $args );
			case 'pr_studio_anchor_link_manage': return self::anchor_manage( $args );
			case 'pr_studio_backlink_opportunity': return self::backlink( $args );
			case 'pr_studio_report_generate': return self::report_generate( $args );
		}
		return new WP_Error( 'prstudio_tool_unknown', 'Tool PR STUDIO sconosciuto.', array( 'status' => 400 ) );
	}

	public static function mcp_pre_dispatch( $response, $server, $request ) {
		/*
		 * Il dispatcher MCP centrale valida Origin, JSON-RPC, versione del
		 * protocollo, autenticazione e schema degli argomenti. Non bypassarlo:
		 * WPAIB_MCP inoltra poi i tool enterprise a self::dispatch().
		 */
		return $response;
	}

	public static function mcp_after_callbacks( $response, $handler, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request || '/wp-ai-bridge/v1/mcp' !== $request->get_route() ) { return $response; }
		$payload = $request->get_json_params(); $method = is_array( $payload ) ? (string) ( $payload['method'] ?? '' ) : ''; $data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['result'] ) ) { return $response; }
		if ( 'initialize' === $method ) {
			$data['result']['serverInfo']['name'] = 'pr-studio-ai-bridge';
			$data['result']['serverInfo']['version'] = WPAIB_VERSION;
			$data['result']['capabilities']['tools']['listChanged'] = false;
			$data['result']['instructions'] = 'PR STUDIO Unified Control Plane per WordPress/WooCommerce e Browser Agent personale. Usa prima prstudio_orchestrator_resolve: seleziona una delle 10 classi e restituisce workflow e azioni esatte. Prepara il lavoro senza gate; immediatamente prima della modifica reale esegui una sola attestazione anti-crash verificata e poi finalizza con un solo backup consolidato. Per il browser usa target live. Nessuna azione catalogata può usare client_action_required come executor: deve avere un executor server-side registrato e auto-risolvere i dati contestuali prima dell’esecuzione. Una scrittura eseguita resta valida anche se l’evidence è degradata: verified=false non introduce rollback, review o veto.';
			$response->set_data( $data );
		} elseif ( 'tools/list' === $method && isset( $data['result']['tools'] ) && is_array( $data['result']['tools'] ) ) {
			$response->header( 'Cache-Control', 'private, max-age=300, stale-while-revalidate=60' );
		} elseif ( 'tools/call' === $method ) {
			$name = sanitize_key( (string) ( $payload['params']['name'] ?? '' ) );
			if ( in_array( $name, array( 'bridge_status','verify_private_wordpress_access','enterprise_status' ), true ) && isset( $data['result']['structuredContent'] ) && is_array( $data['result']['structuredContent'] ) ) {
				$structured = $data['result']['structuredContent']; $structured['pr_studio'] = self::status(); if ( 'enterprise_status' === $name ) { $structured['features']['agency_orchestration'] = true; $structured['features']['wordpress_native_execution'] = true; $structured['features']['chatgpt_web_continuation'] = false; $structured['features']['external_worker_required'] = false; } $data['result']['structuredContent'] = $structured; $data['result']['content'] = array( array( 'type' => 'text', 'text' => wp_json_encode( $structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ); $response->set_data( $data );
			}
		}
		return $response;
	}

	private static function mcp_result( $id, $payload, bool $is_error ): WP_REST_Response {
		$structured = is_array( $payload ) ? $payload : array( 'value' => $payload ); $text = wp_json_encode( $structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => false === $text ? '{}' : $text ) ), 'structuredContent' => $structured, 'isError' => $is_error ) ), 200 );
	}

}
