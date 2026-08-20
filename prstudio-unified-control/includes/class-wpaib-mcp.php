<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPAIB_MCP {
	private static function tool( string $name, string $title, string $description, array $schema, bool $read_only, bool $destructive = false, bool $idempotent = true ): array {
		$scopes = $read_only ? array( 'wp_ai_bridge.read' ) : array( 'wp_ai_bridge.read', 'wp_ai_bridge.write' );
		$security_schemes = array( array( 'type' => 'oauth2', 'scopes' => $scopes ) );
		return array(
			'name' => $name,
			'title' => $title,
			'description' => $description,
			'inputSchema' => $schema,
			'outputSchema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'securitySchemes' => $security_schemes,
			'_meta' => array( 'securitySchemes' => $security_schemes, 'ui' => array( 'visibility' => array( 'model', 'app' ) ) ),
			'annotations' => array( 'readOnlyHint' => $read_only, 'destructiveHint' => $destructive, 'idempotentHint' => $idempotent, 'openWorldHint' => false ),
		);
	}

	public static function tools(): array {
		$object = static function ( array $properties = array(), array $required = array(), bool $additional = false ): array {
			$schema = array(
				'type' => 'object',
				'properties' => $properties ? $properties : new stdClass(),
				'additionalProperties' => $additional,
			);
			if ( $required ) {
				$schema['required'] = $required;
			}
			return $schema;
		};
		$string_array = array( 'type' => 'array', 'items' => array( 'type' => 'string' ) );
		$integer_array = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		$any = array( 'description' => 'Valore JSON scalare, array o oggetto.' );

		return array(
			self::tool( 'bridge_status', 'Stato bridge privato', 'Legge direttamente dall’installazione WordPress self-hosted autenticata URL, webroot, versioni, tema attivo e capacità.', $object(), true ),
			self::tool( 'verify_private_wordpress_access', 'Verifica accesso interno WordPress', 'USA QUESTO PER PRIMO quando l’utente chiede di analizzare o amministrare il sito. Conferma l’accesso MCP autenticato al backend e alla webroot.', $object(), true ),
			self::tool( 'enterprise_status', 'Stato funzioni enterprise', 'Verifica disponibilità di lock, stato persistente, Rank Math, media, WooCommerce CRUD, HPOS e Browser Agent senza modificare il sito.', $object(), true ),
			self::tool( 'get_audit_log', 'Registro audit bridge', 'Legge gli ultimi eventi di audit del bridge, inclusi rilasci, lock e modifiche enterprise, con segreti redatti.', $object( array(
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				'action_prefix' => array( 'type' => 'string', 'default' => '' ),
			) ), true ),
			self::tool( 'work_lock', 'Lock coordinamento reparti', 'Acquisisce, legge o rilascia un lock persistente per serializzare le scritture dei reparti. Conservare il token restituito per il rilascio.', $object( array(
				'action' => array( 'type' => 'string', 'enum' => array( 'status', 'acquire', 'release' ), 'default' => 'status' ),
				'key' => array( 'type' => 'string', 'default' => 'global-write' ),
				'owner' => array( 'type' => 'string' ),
				'ttl' => array( 'type' => 'integer', 'minimum' => 30, 'maximum' => 3600, 'default' => 600 ),
				'token' => array( 'type' => 'string' ),
			), array( 'action' ) ), false, false, false ),
			self::tool( 'work_state', 'Stato persistente reparti', 'Legge o aggiorna lo stato persistente condiviso per batch, ticket, cursori, risultati e prossimi passi con controllo versione.', $object( array(
				'action' => array( 'type' => 'string', 'enum' => array( 'get', 'set', 'merge', 'append' ), 'default' => 'get' ),
				'key' => array( 'type' => 'string' ),
				'data' => $any,
				'expected_version' => array( 'type' => 'integer', 'minimum' => 0 ),
			), array( 'action', 'key' ) ), false, false, false ),
			self::tool( 'inventory_tree', 'Inventario filesystem', 'Scansiona ricorsivamente file e cartelle della webroot privata con paginazione.', $object( array(
				'path' => array( 'type' => 'string', 'description' => 'Percorso relativo ad ABSPATH; stringa vuota per la radice.' ),
				'cursor' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 300 ),
				'hashes' => array( 'type' => 'boolean', 'default' => false ),
			) ), true ),
			self::tool( 'list_directory', 'Elenca cartella', 'Elenca file e sottocartelle reali nella webroot privata.', $object( array( 'path' => array( 'type' => 'string', 'default' => '' ) ) ), true ),
			self::tool( 'read_file', 'Leggi file', 'Legge il contenuto reale di un file nella webroot privata a blocchi, con SHA-256 e segreti redatti.', $object( array(
				'path' => array( 'type' => 'string' ), 'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ), 'length' => array( 'type' => 'integer', 'minimum' => 1 ),
			), array( 'path' ) ), true ),
			self::tool( 'search_files', 'Cerca nei file', 'Cerca codice o configurazioni nei file testuali della webroot privata.', $object( array(
				'query' => array( 'type' => 'string', 'minLength' => 1 ), 'path' => array( 'type' => 'string', 'default' => '' ), 'extensions' => $string_array,
				'cursor' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ), 'max_results' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'default' => 100 ),
			), array( 'query' ) ), true ),
			self::tool( 'get_php_log', 'Leggi log PHP applicativo', 'Legge in modo controllato e con segreti redatti un intervallo del debug.log o di un altro log sotto wp-content.', $object( array(
				'path' => array( 'type' => 'string', 'default' => 'wp-content/debug.log' ), 'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ), 'length' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1048576, 'default' => 131072 ),
			) ), true ),
			self::tool( 'write_file', 'Scrivi file', 'Crea o sostituisce atomicamente un file testuale consentito. L’originale viene acquisito una sola volta nel journal del lavoro e incluso nel backup consolidato finale.', $object( array(
				'path' => array( 'type' => 'string' ), 'content_b64' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string' ),
			), array( 'path', 'content_b64' ) ), false, false, false ),
			self::tool( 'append_file', 'Aggiungi a file', 'Aggiunge testo in coda a un file consentito con controllo SHA-256 e sostituzione atomica; l’originale viene acquisito una sola volta per lavoro.', $object( array(
				'path' => array( 'type' => 'string' ), 'suffix' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string' ),
			), array( 'path', 'suffix', 'expected_sha256' ) ), false, false, false ),
			self::tool( 'truncate_file', 'Svuota file', 'Svuota un file consentito dopo controllo SHA-256; l’originale viene conservato nel journal unico del lavoro.', $object( array(
				'path' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string' ),
			), array( 'path', 'expected_sha256' ) ), false, true, false ),
			self::tool( 'validate_file', 'Valida file', 'Valida sul server la sintassi PHP o la struttura JSON senza esporre né modificare il contenuto.', $object( array(
				'path' => array( 'type' => 'string' ), 'format' => array( 'type' => 'string', 'enum' => array( 'php', 'json' ) ),
			), array( 'path' ) ), true ),
			self::tool( 'delete_file', 'Elimina file', 'Elimina un file consentito dopo controllo hash; l’originale viene conservato nel journal unico del lavoro.', $object( array( 'path' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string' ) ), array( 'path', 'expected_sha256' ) ), false, true, false ),
			self::tool( 'restore_file', 'Ripristina backup', 'Ripristina un file da un backup creato dal bridge.', $object( array( 'backup_id' => array( 'type' => 'string' ), 'expected_current_sha256' => array( 'type' => 'string' ) ), array( 'backup_id' ) ), false, false, false ),
			self::tool( 'list_plugins', 'Elenca plugin', 'Legge tutti i plugin installati con stato e versione.', $object(), true ),
			self::tool( 'set_plugin_state', 'Attiva o disattiva plugin', 'Attiva o disattiva un plugin installato; il bridge non può disattivare sé stesso.', $object( array(
				'plugin' => array( 'type' => 'string' ), 'action' => array( 'type' => 'string', 'enum' => array( 'activate', 'deactivate' ) ),
			), array( 'plugin', 'action' ) ), false, true, true ),
			self::tool( 'list_themes', 'Elenca temi', 'Legge temi installati, tema attivo ed errori.', $object(), true ),
			self::tool( 'switch_theme', 'Cambia tema', 'Attiva un tema installato e valido.', $object( array( 'stylesheet' => array( 'type' => 'string' ) ), array( 'stylesheet' ) ), false, true, true ),
			self::tool( 'list_content', 'Elenca contenuti', 'Elenca pagine, articoli o custom post type con paginazione.', $object( array(
				'post_type' => array( 'type' => 'string', 'default' => 'page' ), 'status' => array( 'type' => 'string', 'default' => 'any' ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ), 'search' => array( 'type' => 'string', 'default' => '' ),
			) ), true ),
			self::tool( 'get_content', 'Leggi contenuto', 'Legge titolo, stato, excerpt e contenuto completo di un elemento WordPress.', $object( array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'id' ) ), true ),
			self::tool( 'update_content', 'Crea o aggiorna contenuto', 'Crea o aggiorna pagine, articoli e custom post type tramite API WordPress.', $object( array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'post_type' => array( 'type' => 'string', 'default' => 'page' ), 'title' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ),
				'excerpt' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ),
			) ), false, false, false ),
			self::tool( 'list_taxonomies', 'Elenca tassonomie', 'Elenca tassonomie WordPress/WooCommerce e relativi object type, gerarchia e REST base.', $object( array( 'object_type' => array( 'type' => 'string', 'default' => '' ) ) ), true ),
			self::tool( 'list_terms', 'Elenca termini', 'Elenca categorie, tag, attributi e altre tassonomie con paginazione.', $object( array(
				'taxonomy' => array( 'type' => 'string' ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ), 'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
				'search' => array( 'type' => 'string', 'default' => '' ), 'hide_empty' => array( 'type' => 'boolean', 'default' => false ),
			), array( 'taxonomy' ) ), true ),
			self::tool( 'upsert_term', 'Crea o aggiorna termine', 'Crea o aggiorna una categoria, tag o altro termine tassonomico con audit prima/dopo.', $object( array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'taxonomy' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ), 'parent' => array( 'type' => 'integer', 'minimum' => 0 ),
			), array( 'taxonomy' ) ), false, false, false ),
			self::tool( 'assign_terms', 'Assegna termini', 'Assegna categorie, tag o attributi a un contenuto/prodotto usando ID e API tassonomie WordPress.', $object( array(
				'object_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'taxonomy' => array( 'type' => 'string' ), 'term_ids' => $integer_array, 'append' => array( 'type' => 'boolean', 'default' => false ),
			), array( 'object_id', 'taxonomy', 'term_ids' ) ), false, false, false ),
			self::tool( 'get_object_meta', 'Leggi meta SEO e media', 'Legge esclusivamente meta consentiti: prefisso rank_math_, alt immagini e stato bridge. Supporta post/prodotti/allegati e termini.', $object( array(
				'object_type' => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'default' => 'post' ), 'object_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'keys' => $string_array,
			), array( 'object_id' ) ), true ),
			self::tool( 'update_object_meta', 'Aggiorna meta SEO e media', 'Imposta o elimina in modo controllato un campo Rank Math o alt immagine con confronto expected_before e audit prima/dopo.', $object( array(
				'object_type' => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'default' => 'post' ), 'object_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'key' => array( 'type' => 'string' ), 'action' => array( 'type' => 'string', 'enum' => array( 'set', 'delete' ), 'default' => 'set' ), 'value' => $any, 'expected_before' => $any,
			), array( 'object_id', 'key', 'action' ) ), false, false, false ),
			self::tool( 'list_media', 'Elenca media', 'Elenca allegati e immagini con titolo, alt, caption, descrizione, URL, MIME, dimensioni e metadati.', $object( array(
				'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ), 'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
				'search' => array( 'type' => 'string', 'default' => '' ), 'mime_type' => array( 'type' => 'string', 'default' => 'image' ),
			) ), true ),
			self::tool( 'get_media', 'Leggi media', 'Legge tutti i dati editoriali e tecnici di un allegato.', $object( array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'id' ) ), true ),
			self::tool( 'update_media', 'Aggiorna media', 'Aggiorna titolo, alt, caption o descrizione dell’allegato con controllo concorrenza e audit prima/dopo; non rinomina file o URL.', $object( array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'title' => array( 'type' => 'string' ), 'alt' => array( 'type' => 'string' ), 'caption' => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ), 'expected_modified_gmt' => array( 'type' => 'string' ),
			), array( 'id' ) ), false, false, false ),
			self::tool( 'list_products', 'Elenca prodotti WooCommerce', 'Elenca prodotti tramite WooCommerce CRUD, compatibile con evoluzioni dello storage.', $object( array(
				'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ), 'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
				'search' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ), 'sku' => array( 'type' => 'string' ), 'stock_status' => array( 'type' => 'string' ), 'type' => array( 'type' => 'string' ),
			) ), true ),
			self::tool( 'get_product', 'Leggi prodotto WooCommerce', 'Legge dati commerciali, stock, immagini, categorie, attributi e timestamp di un prodotto usando WooCommerce CRUD.', $object( array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'id' ) ), true ),
			self::tool( 'update_product', 'Aggiorna prodotto WooCommerce', 'Aggiorna un prodotto esistente tramite WooCommerce CRUD/HPOS-safe con controllo timestamp, audit e senza inventare dati.', $object( array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'expected_modified_gmt' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ),
				'status' => array( 'type' => 'string' ), 'featured' => array( 'type' => 'boolean' ), 'catalog_visibility' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ),
				'short_description' => array( 'type' => 'string' ), 'sku' => array( 'type' => 'string' ), 'regular_price' => array( 'type' => 'string' ), 'sale_price' => array( 'type' => 'string' ),
				'date_on_sale_from' => array( 'type' => 'string' ), 'date_on_sale_to' => array( 'type' => 'string' ), 'manage_stock' => array( 'type' => 'boolean' ), 'stock_quantity' => array( 'type' => array( 'integer', 'null' ) ),
				'stock_status' => array( 'type' => 'string' ), 'backorders' => array( 'type' => 'string' ), 'sold_individually' => array( 'type' => 'boolean' ), 'weight' => array( 'type' => 'string' ),
				'length' => array( 'type' => 'string' ), 'width' => array( 'type' => 'string' ), 'height' => array( 'type' => 'string' ), 'tax_status' => array( 'type' => 'string' ), 'tax_class' => array( 'type' => 'string' ),
				'shipping_class_id' => array( 'type' => 'integer' ), 'purchase_note' => array( 'type' => 'string' ), 'menu_order' => array( 'type' => 'integer' ), 'category_ids' => $integer_array, 'tag_ids' => $integer_array,
				'image_id' => array( 'type' => 'integer' ), 'gallery_image_ids' => $integer_array, 'attributes' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			), array( 'id' ) ), false, false, false ),
			self::tool( 'list_orders', 'Elenca ordini senza PII', 'Elenca ordini WooCommerce tramite API HPOS-safe, restituendo solo ID, stato, totale, valuta, data e conteggio articoli.', $object( array(
				'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ), 'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
				'status' => array( 'description' => 'Stato singolo o array di stati.' ), 'date_created' => array( 'type' => 'string' ), 'date_modified' => array( 'type' => 'string' ),
			) ), true ),
			self::tool( 'commerce_summary', 'Riepilogo ricavi WooCommerce', 'Calcola ordini, articoli, ricavi e AOV in un intervallo usando wc_get_orders e senza dati personali.', $object( array(
				'after' => array( 'type' => 'string', 'description' => 'Data iniziale YYYY-MM-DD.' ), 'before' => array( 'type' => 'string', 'description' => 'Data finale YYYY-MM-DD.' ), 'statuses' => $string_array,
			) ), true ),
			self::tool( 'search_console_status', 'Stato Google Search Console', 'Verifica che il Browser Agent sia online e possa usare la sessione Google già autenticata nel Chrome personale.', $object(), true ),
			self::tool( 'search_console_sites', 'Elenca proprietà Search Console', 'Elenca le proprietà visibili nell’interfaccia Search Console usando la sessione Google del Chrome personale.', $object(), true ),
			self::tool( 'search_console_search_analytics', 'Leggi Search Analytics', 'Legge dall’interfaccia Search Console clic, impressioni, CTR e posizione usando il Browser Agent e la sessione Google dell’utente.', $object( array(
				'site_url' => array( 'type' => 'string', 'description' => 'Proprietà esatta restituita da search_console_sites.' ),
				'start_date' => array( 'type' => 'string', 'description' => 'Data iniziale YYYY-MM-DD.' ),
				'end_date' => array( 'type' => 'string', 'description' => 'Data finale YYYY-MM-DD.' ),
				'dimensions' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'query', 'page', 'country', 'device', 'date', 'searchAppearance' ) ) ),
				'type' => array( 'type' => 'string', 'enum' => array( 'web', 'image', 'video', 'news', 'discover', 'googleNews' ), 'default' => 'web' ),
				'row_limit' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1000 ),
				'start_row' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				'dimension_filter_groups' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			), array( 'site_url' ) ), true ),
			self::tool( 'search_console_sitemaps', 'Leggi sitemap Search Console', 'Legge dall’interfaccia Search Console le sitemap della proprietà; sitemap_url filtra una sitemap specifica.', $object( array(
				'site_url' => array( 'type' => 'string' ),
				'sitemap_url' => array( 'type' => 'string', 'description' => 'Facoltativo: URL completo della sitemap da filtrare.' ),
			), array( 'site_url' ) ), true ),
			self::tool( 'search_console_url_inspection', 'Ispeziona URL in Google', 'Apre Ispezione URL nell’interfaccia Search Console e restituisce il risultato visibile, usando la sessione Google dell’utente.', $object( array(
				'site_url' => array( 'type' => 'string' ),
				'inspection_url' => array( 'type' => 'string' ),
			), array( 'site_url', 'inspection_url' ) ), true ),
			self::tool( 'search_console_request_indexing', 'Richiedi indicizzazione URL', 'Dopo l’Ispezione URL, richiede una nuova scansione tramite la UI Search Console autenticata e considera riuscita l’azione solo con conferma visibile. Non usa la Indexing API generica.', $object( array(
				'site_url' => array( 'type' => 'string' ),
				'inspection_url' => array( 'type' => 'string' ),
				'force_request' => array( 'type' => 'boolean', 'default' => false ),
				'request_timeout_ms' => array( 'type' => 'integer', 'minimum' => 5000, 'maximum' => 90000, 'default' => 60000 ),
			), array( 'site_url', 'inspection_url' ) ), false, false, false ),
			self::tool( 'wordpress_content_transaction', 'Transazione contenuto WordPress', 'Modifica deterministica di un contenuto esistente con optimistic lock, anchor count, idempotenza, readback DB e verifica pubblica opzionale. Preferire questo tool all’editing Gutenberg via browser quando la modifica è testuale.', $object( array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'operation' => array( 'type' => 'string', 'enum' => array( 'replace_exact', 'insert_before', 'insert_after', 'append_once' ) ),
				'search' => array( 'type' => 'string' ), 'replacement' => array( 'type' => 'string' ),
				'expected_before_sha256' => array( 'type' => 'string' ), 'expected_modified_gmt' => array( 'type' => 'string' ),
				'expected_occurrences' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 1 ),
				'write_token' => array( 'type' => 'string', 'description' => 'Token firmato restituito da prstudio_observe; applica hash e conteggi precondizione senza calcolo manuale.' ),
				'idempotency_marker' => array( 'type' => 'string' ), 'public_verify' => array( 'type' => 'boolean', 'default' => true ), 'verify_contains' => array( 'type' => 'string' ),
			), array( 'id', 'operation', 'replacement' ) ), false, false, false ),
			self::tool( 'patch_file', 'Applica patch testuale', 'Sostituisce testo esatto in un file consentito. Verifica hash, sintassi PHP e risultato; l’originale viene acquisito una sola volta nel lavoro.', $object( array(
				'path' => array( 'type' => 'string' ), 'expected_sha256' => array( 'type' => 'string' ), 'search' => array( 'type' => 'string' ), 'replace' => array( 'type' => 'string' ),
				'expected_replacements' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ), 'search_sha256' => array( 'type' => 'string' ), 'health_checks' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'home', 'wp_admin', 'mcp' ) ) ), 'reason' => array( 'type' => 'string' ),
			), array( 'path', 'expected_sha256', 'search', 'replace' ) ), false, false, false ),
			self::tool( 'purge_cache', 'Invalida cache e sitemap', 'Invalida object cache, page cache, provider CDN collegati e cache sitemap Rank Math; supporta URL mirati e restituisce i provider realmente eseguiti.', $object( array(
				'operation' => array( 'type' => 'string', 'enum' => array( 'purge', 'purge_url', 'flush_all', 'flush_object_cache', 'flush_page_cache', 'flush_cdn_cache' ), 'default' => 'purge' ),
				'urls' => $string_array, 'reason' => array( 'type' => 'string' ),
			) ), false, false, true ),
			self::tool( 'rank_math_redirect_list', 'Elenca redirect Rank Math', 'Elenca i redirect Rank Math nativi con sorgenti, destinazione, codice e stato.', $object( array(
				'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				'search' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string', 'default' => 'any' ),
			) ), true ),
			self::tool( 'rank_math_redirect_upsert', 'Crea o aggiorna redirect Rank Math', 'Crea o aggiorna un redirect Rank Math tramite le classi native del plugin, con verifica del risultato e audit prima/dopo.', $object( array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'source' => array( 'type' => 'string' ), 'sources' => array( 'type' => 'array', 'items' => array( 'description' => 'Stringa o oggetto {pattern,comparison,ignore}.' ) ),
				'destination' => array( 'type' => 'string' ), 'header_code' => array( 'type' => 'integer', 'enum' => array( 301, 302, 307, 410, 451 ), 'default' => 301 ), 'status' => array( 'type' => 'string', 'enum' => array( 'active', 'inactive', 'trashed' ), 'default' => 'active' ),
			) ), false, false, true ),
			self::tool( 'rank_math_redirect_delete', 'Elimina redirect Rank Math', 'Elimina un redirect Rank Math per ID con audit prima/dopo.', $object( array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'id' ) ), false, true, true ),
			self::tool( 'rank_math_sitemap_invalidate', 'Invalida sitemap Rank Math', 'Invalida immediatamente la sitemap Rank Math per un post, termine o utente dopo modifiche SEO.', $object( array(
				'object_type' => array( 'type' => 'string', 'enum' => array( 'post', 'term', 'user' ), 'default' => 'post' ), 'object_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			), array( 'object_id' ) ), false, false, true ),
			self::tool( 'fetch_page_html', 'Scarica HTML pagina', 'Scarica l’HTML finale di una pagina pubblica dello stesso dominio.', $object( array( 'url_or_path' => array( 'type' => 'string' ) ), array( 'url_or_path' ) ), true ),
		);
	}


	/* ======================================================================
	 * Discovery deterministica e superficie sicura (1.3.1)
	 *
	 * Il catalogo completo contiene oltre milleduecento strumenti: nessun
	 * client MCP li carica tutti. La superficie predefinita di tools/list è
	 * quindi ridotta agli strumenti base, agli strumenti PR STUDIO e a una
	 * famiglia per ogni route OpenAPI, con l'elenco delle azioni valide
	 * dichiarato come enum. I tre strumenti di discovery restano in testa al
	 * catalogo, quindi nella prima pagina, e permettono di trovare ed
	 * eseguire qualsiasi azione senza conoscerne il nome MCP.
	 * ====================================================================== */

	private const DISCOVERY_TOOLS = array(
		'prstudio_orchestrator_resolve',
		'prstudio_orchestrator_domain_actions',
		'prstudio_orchestrator_execute',
		'prstudio_work_begin',
		'prstudio_work_status',
		'prstudio_anti_crash_requirements',
		'prstudio_anti_crash_run',
		'prstudio_anti_crash_submit',
		'prstudio_work_finalize',
		'prstudio_work_abort',
		'rpconnector_capability_search',
		'rpconnector_route_index',
		'rpconnector_action_call',
	);

	private static function route_slug( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = str_replace( array( '/', '-', ' ' ), array( '', '_', '_' ), $value );
		return sanitize_key( $value );
	}

	/**
	 * Indice route → azioni costruito una sola volta per richiesta.
	 */
	private static function route_catalog(): array {
		static $catalog = null;
		if ( null !== $catalog ) {
			return $catalog;
		}
		$catalog = array();
		if ( ! class_exists( 'PRSTUDIO_Agency' ) || ! is_callable( array( 'PRSTUDIO_Agency', 'control_actions' ) ) ) {
			return $catalog;
		}
		try {
			$routes = is_callable( array( 'PRSTUDIO_Agency', 'control_routes' ) ) ? (array) PRSTUDIO_Agency::control_routes() : array();
			$actions = (array) PRSTUDIO_Agency::control_actions();
		} catch ( Throwable $e ) {
			WPAIB_Audit::log( 'mcp.route_catalog_exception', 'error', 'tools/list', array( 'message' => self::safe_exception_message( $e ) ) );
			$catalog = array();
			return $catalog;
		}

		foreach ( $routes as $route_meta ) {
			if ( ! is_array( $route_meta ) ) {
				continue;
			}
			$path = '/' . trim( (string) ( $route_meta['path'] ?? '' ), '/' );
			if ( '/' === $path ) {
				continue;
			}
			$slug = self::route_slug( '' !== (string) ( $route_meta['slug'] ?? '' ) ? (string) $route_meta['slug'] : $path );
			$catalog[ $path ] = array(
				'path' => $path,
				'slug' => $slug,
				'tool' => 'rpconnector_' . $slug,
				'summary' => (string) ( $route_meta['summary'] ?? '' ),
				'description' => (string) ( $route_meta['description'] ?? '' ),
				'actions' => array(),
			);
		}

		foreach ( $actions as $tool_name => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$path = '/' . trim( (string) ( $meta['route'] ?? '' ), '/' );
			$action = (string) ( $meta['action'] ?? '' );
			if ( '/' === $path || '' === $action ) {
				continue;
			}
			if ( ! isset( $catalog[ $path ] ) ) {
				$slug = self::route_slug( '' !== (string) ( $meta['route_slug'] ?? '' ) ? (string) $meta['route_slug'] : $path );
				$catalog[ $path ] = array( 'path' => $path, 'slug' => $slug, 'tool' => 'rpconnector_' . $slug, 'summary' => '', 'description' => '', 'actions' => array() );
			}
			$meta['tool_name'] = (string) $tool_name;
			$catalog[ $path ]['actions'][ $action ] = $meta;
		}

		foreach ( $catalog as $path => $route ) {
			if ( empty( $route['actions'] ) ) {
				unset( $catalog[ $path ] );
				continue;
			}
			ksort( $catalog[ $path ]['actions'], SORT_STRING );
		}
		ksort( $catalog, SORT_STRING );
		return $catalog;
	}

	private static function route_path_from_input( string $value ) {
		$slug = self::route_slug( $value );
		if ( '' === $slug ) {
			return null;
		}
		if ( 0 === strpos( $slug, 'rpconnector_' ) ) {
			$slug = substr( $slug, strlen( 'rpconnector_' ) );
		}
		foreach ( self::route_catalog() as $path => $route ) {
			if ( $route['slug'] === $slug ) {
				return $path;
			}
		}
		return null;
	}

	private static function route_tool_names(): array {
		$names = array();
		foreach ( self::route_catalog() as $route ) {
			$names[ (string) $route['tool'] ] = (string) $route['path'];
		}
		return $names;
	}

	/**
	 * Uno strumento per famiglia: l'azione è un enum, quindi il modello la
	 * sceglie senza indovinare e senza una seconda chiamata di discovery.
	 */
	private static function route_tools(): array {
		$tools = array();
		foreach ( self::route_catalog() as $path => $route ) {
			$action_names = array();
			$properties = array();
			$read_only = true;
			$destructive = false;
			foreach ( $route['actions'] as $action_name => $meta ) {
				$action_names[] = (string) $action_name;
				if ( empty( $meta['read_only'] ) ) {
					$read_only = false;
				}
				if ( ! empty( $meta['destructive'] ) ) {
					$destructive = true;
				}
				$schema = isset( $meta['input_schema'] ) && is_array( $meta['input_schema'] ) ? $meta['input_schema'] : array();
				$schema_properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
				foreach ( $schema_properties as $key => $definition ) {
					$key = (string) $key;
					if ( 'action' === $key || isset( $properties[ $key ] ) || ! is_array( $definition ) ) {
						continue;
					}
					$properties[ $key ] = $definition;
				}
			}
			ksort( $properties, SORT_STRING );
			$summary = trim( $route['summary'] . ( '' !== $route['description'] ? ' ' . $route['description'] : '' ) );
			$description = 'Famiglia ' . $path . ': ' . count( $action_names ) . ' azioni in un unico strumento. Scegli "action" dall’enum e passa i parametri nello stesso oggetto. ' . $summary;
			$properties = array_merge(
				array( 'action' => array( 'type' => 'string', 'enum' => $action_names, 'description' => 'Azione della route ' . $path . '. Obbligatoria, scelta dall’enum.' ) ),
				$properties
			);
			$tool = self::tool(
				(string) $route['tool'],
				'RpConnector ' . ucwords( str_replace( '_', ' ', (string) $route['slug'] ) ),
				$description,
				array( 'type' => 'object', 'properties' => $properties, 'required' => array( 'action' ), 'additionalProperties' => false ),
				$read_only,
				$destructive,
				false
			);
			$tool['_meta']['rpconnector'] = array(
				'kind' => 'route_family',
				'route' => $path,
				'slug' => (string) $route['slug'],
				'actionCount' => count( $action_names ),
			);
			$tools[] = $tool;
		}
		return $tools;
	}

	private static function discovery_tools(): array {
		$route_slugs = array();
		foreach ( self::route_catalog() as $route ) {
			$route_slugs[] = (string) $route['path'];
		}
		$route_hint = $route_slugs ? implode( ', ', array_slice( $route_slugs, 0, 8 ) ) . '…' : '';

		$search = self::tool(
			'rpconnector_capability_search',
			'RpConnector Cerca capacità',
			'PRIMO STRUMENTO DA USARE quando non sai quale azione serve. Cerca in linguaggio naturale, italiano o inglese, tra tutte le azioni del bridge e restituisce nome esatto del tool, route, azione e parametri. Esempi di query: "cambia prezzo prodotto", "meta title SEO", "redirect 301", "svuota cache", "carica immagine".',
			array(
				'type' => 'object',
				'properties' => array(
					'query' => array( 'type' => 'string', 'description' => 'Intento dell’utente o parole chiave. Italiano e inglese sono equivalenti.' ),
					'route' => array( 'type' => 'string', 'description' => 'Filtro opzionale sulla famiglia: ' . $route_hint ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10 ),
					'include_schema' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Restituisce lo schema JSON completo dei parametri di ogni risultato.' ),
				),
				'required' => array( 'query' ),
				'additionalProperties' => false,
			),
			true
		);

		$index = self::tool(
			'rpconnector_route_index',
			'RpConnector Indice route',
			'Elenca le famiglie di azioni del bridge con il nome dello strumento di famiglia e le azioni disponibili. Senza parametri restituisce tutte le route; con "route" restituisce il dettaglio completo di quella famiglia.',
			array(
				'type' => 'object',
				'properties' => array(
					'route' => array( 'type' => 'string', 'description' => 'Route o slug, es. /seo-manage, seo-manage, seo_manage.' ),
					'include_actions' => array( 'type' => 'boolean', 'default' => true, 'description' => 'Include i nomi delle azioni di ogni route.' ),
				),
				'additionalProperties' => false,
			),
			true
		);

		$call = self::tool(
			'rpconnector_action_call',
			'RpConnector Esegui azione',
			'Esecutore generico: esegue qualsiasi azione del bridge indicando route e action, senza dover conoscere il nome MCP dello strumento dedicato. Se route o action non esistono la risposta contiene le alternative corrette.',
			array(
				'type' => 'object',
				'properties' => array(
					'route' => array( 'type' => 'string', 'description' => 'Route della famiglia, es. /products-manage.' ),
					'action' => array( 'type' => 'string', 'description' => 'Nome esatto dell’azione, es. update_price.' ),
					'arguments' => array( 'type' => 'object', 'additionalProperties' => true, 'description' => 'Parametri dell’azione.' ),
					'tool_name' => array( 'type' => 'string', 'description' => 'In alternativa a route+action: nome MCP completo dell’azione.' ),
				),
				'required' => array( 'route', 'action' ),
				'additionalProperties' => false,
			),
			false,
			false,
			false
		);

		$search['_meta']['rpconnector'] = array( 'kind' => 'discovery', 'priority' => true );
		$index['_meta']['rpconnector'] = array( 'kind' => 'discovery', 'priority' => true );
		$call['_meta']['rpconnector'] = array( 'kind' => 'discovery', 'priority' => true );

		$primary = array();
		if ( class_exists( 'PRSTUDIO_UC_Orchestrator' ) ) {
			$primary = array_merge( $primary, PRSTUDIO_UC_Orchestrator::tool_definitions() );
		}
		if ( class_exists( 'PRSTUDIO_UC_Anti_Crash' ) ) {
			$primary = array_merge( $primary, PRSTUDIO_UC_Anti_Crash::tool_definitions() );
		}
		return array_merge( $primary, array( $search, $index, $call ) );
	}

	/**
	 * Shared bilingual matcher adapter. The legacy payload remains unchanged;
	 * only ranking and language normalization come from the canonical index.
	 *
	 * This class used to carry its own copy of the whole matcher -- an
	 * italian->english synonym table, a stop-word list, a token scorer and a
	 * `phrase_action` bonus that added 300 points when the query, joined with
	 * underscores, equalled the action name. That bonus only ever fired for
	 * English: "publish content" became publish_content, while "pubblica
	 * contenuto" became pubblica_contenuto, which names no action anywhere in
	 * the catalog. Two matchers meant two rankings under one version number,
	 * and nothing at runtime compared them. There is now one matcher, so the
	 * question cannot be asked twice and answered differently.
	 *
	 * When the shared index is genuinely unavailable this fails closed with an
	 * empty result rather than reaching for a second opinion: an empty list is
	 * a visible fault, whereas a divergent list is a silent one.
	 */
	private static function capability_matches( string $query, string $route_filter = '', int $limit = 10, bool $include_schema = false ): array {
		if ( ! class_exists( 'PRSTUDIO_UC_Action_Lexicon' ) ) {
			require_once __DIR__ . '/class-prstudio-uc-action-lexicon.php';
		}
		if ( ! class_exists( 'PRSTUDIO_UC_Action_Index' ) ) {
			require_once __DIR__ . '/class-prstudio-uc-action-index.php';
		}
		if ( ! class_exists( 'PRSTUDIO_UC_Action_Index' ) ) {
			return array( 'matches' => array(), 'total_matches' => 0 );
		}

		$route_path = '' !== trim( $route_filter ) ? self::route_path_from_input( $route_filter ) : null;
		$route_scope = null !== $route_path ? $route_path : '*';
		$limit = max( 1, min( 50, $limit ) );
		$found = PRSTUDIO_UC_Action_Index::search_detailed( $query, $limit, '', $route_scope );
		$catalog = self::route_catalog();
		$matches = array();

		foreach ( (array) ( $found['items'] ?? array() ) as $item ) {
			$route = (string) ( $item['route'] ?? '' );
			$action = (string) ( $item['action'] ?? '' );
			if ( '' === $route || '' === $action || ! isset( $catalog[ $route ]['actions'][ $action ] ) ) {
				continue;
			}
			$route_meta = (array) $catalog[ $route ];
			$action_meta = (array) $route_meta['actions'][ $action ];
			$schema = isset( $action_meta['input_schema'] ) && is_array( $action_meta['input_schema'] ) ? $action_meta['input_schema'] : array();
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			$match = array(
				'tool_name' => (string) ( $item['tool_name'] ?? $action_meta['tool_name'] ?? '' ),
				'route' => $route,
				'action' => $action,
				'route_tool' => (string) ( $route_meta['tool'] ?? '' ),
				'title' => (string) ( $item['title'] ?? $action_meta['title'] ?? '' ),
				'description' => (string) ( $item['description'] ?? $action_meta['description'] ?? '' ),
				'read_only' => ! empty( $item['read_only'] ),
				'destructive' => ! empty( $item['destructive'] ),
				'parameters' => array_slice( array_keys( $properties ), 0, 40 ),
				'score' => (int) ( $item['_score'] ?? 0 ),
				'call' => array(
					'tool' => 'rpconnector_action_call',
					'arguments' => array( 'route' => $route, 'action' => $action ),
				),
			);
			if ( $include_schema ) {
				$match['input_schema'] = $schema;
			}
			$matches[] = $match;
		}

		return array(
			'matches' => $matches,
			'total_matches' => (int) ( $found['total_matches'] ?? count( $matches ) ),
		);
	}

	private static function capability_search( array $args ): array {
		$query = trim( (string) ( $args['query'] ?? '' ) );
		$route = trim( (string) ( $args['route'] ?? '' ) );
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$include_schema = ! empty( $args['include_schema'] );
		if ( '' === $query ) {
			return array(
				'query' => '',
				'count' => 0,
				'matches' => array(),
				'hint' => 'Passa "query" con l’intento dell’utente, in italiano o in inglese.',
				'routes' => array_values( array_map( static function ( $item ) {
					return array( 'route' => $item['path'], 'route_tool' => $item['tool'], 'action_count' => count( $item['actions'] ) );
				}, self::route_catalog() ) ),
			);
		}

		$found = self::capability_matches( $query, $route, $limit, $include_schema );
		return array(
			'query' => $query,
			'route_filter' => '' !== $route ? ( self::route_path_from_input( $route ) ?? $route ) : '',
			'count' => count( $found['matches'] ),
			'total_matches' => $found['total_matches'],
			'matches' => $found['matches'],
			'how_to_execute' => 'Esegui subito la corrispondenza migliore con rpconnector_action_call {route, action, arguments}, oppure con lo strumento di famiglia indicato in route_tool {action, …}, oppure chiamando direttamente tool_name. Non chiedere conferma all’utente per le sole letture.',
		);
	}

	private static function route_index( array $args ): array {
		$requested = trim( (string) ( $args['route'] ?? '' ) );
		$path = '' !== $requested ? self::route_path_from_input( $requested ) : null;
		$include_actions = array_key_exists( 'include_actions', $args ) ? ! empty( $args['include_actions'] ) : true;
		$catalog = self::route_catalog();

		$routes = array();
		$total_actions = 0;
		foreach ( $catalog as $route_path => $route ) {
			$total_actions += count( $route['actions'] );
			if ( null !== $path && $route_path !== $path ) {
				continue;
			}
			$item = array(
				'route' => $route_path,
				'slug' => $route['slug'],
				'route_tool' => $route['tool'],
				'summary' => $route['summary'],
				'action_count' => count( $route['actions'] ),
			);
			if ( $include_actions ) {
				$item['actions'] = array_keys( $route['actions'] );
			}
			if ( null !== $path ) {
				$details = array();
				foreach ( $route['actions'] as $action_name => $meta ) {
					$details[] = array(
						'action' => (string) $action_name,
						'tool_name' => (string) $meta['tool_name'],
						'title' => (string) ( $meta['title'] ?? '' ),
						'read_only' => ! empty( $meta['read_only'] ),
						'destructive' => ! empty( $meta['destructive'] ),
					);
				}
				$item['action_details'] = $details;
			}
			$routes[] = $item;
		}

		$result = array(
			'routes' => $routes,
			'route_count' => count( $routes ),
			'total_routes' => count( $catalog ),
			'total_actions' => $total_actions,
			'usage' => 'Usa prima prstudio_orchestrator_resolve. Le famiglie restano eseguibili con route_tool o rpconnector_action_call come fallback diagnostico.',
		);
		if ( '' !== $requested && null === $path ) {
			$result['route_not_found'] = $requested;
		}
		return $result;
	}

	private static function action_call( array $args ) {
		$tool_name = trim( (string) ( $args['tool_name'] ?? '' ) );
		$action = sanitize_key( str_replace( '-', '_', (string) ( $args['action'] ?? '' ) ) );
		$route_input = trim( (string) ( $args['route'] ?? '' ) );
		$catalog = self::route_catalog();
		$meta = null;

		if ( '' !== $tool_name && class_exists( 'PRSTUDIO_Agency' ) && is_callable( array( 'PRSTUDIO_Agency', 'control_action_by_tool' ) ) ) {
			$candidate = PRSTUDIO_Agency::control_action_by_tool( $tool_name );
			if ( is_array( $candidate ) ) {
				$meta = $candidate;
				$meta['tool_name'] = $tool_name;
			}
		}

		if ( null === $meta ) {
			$path = self::route_path_from_input( $route_input );
			if ( null === $path ) {
				$suggestions = self::capability_matches( trim( $route_input . ' ' . $action ), '', 5 );
				return new WP_Error(
					'wpaib_action_route_unknown',
					'Route non riconosciuta: ' . sanitize_text_field( $route_input ) . '. Usa prstudio_orchestrator_resolve oppure una delle route elencate.',
					array(
						'status' => 400,
						'routes' => array_values( array_map( static function ( $item ) {
							return array( 'route' => $item['path'], 'route_tool' => $item['tool'], 'action_count' => count( $item['actions'] ) );
						}, $catalog ) ),
						'did_you_mean' => $suggestions['matches'],
					)
				);
			}
			if ( '' === $action || ! isset( $catalog[ $path ]['actions'][ $action ] ) ) {
				$suggestions = self::capability_matches( '' !== $action ? $action : $route_input, $path, 5 );
				return new WP_Error(
					'wpaib_action_unknown',
					'Azione "' . sanitize_text_field( (string) ( $args['action'] ?? '' ) ) . '" non dichiarata per la route ' . $path . '. Scegli un valore da available_actions o da did_you_mean.',
					array(
						'status' => 400,
						'route' => $path,
						'available_actions' => array_keys( $catalog[ $path ]['actions'] ),
						'did_you_mean' => $suggestions['matches'],
					)
				);
			}
			$meta = $catalog[ $path ]['actions'][ $action ];
		}

		$payload = array();
		foreach ( $args as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, array( 'route', 'action', 'arguments', 'tool_name' ), true ) ) {
				continue;
			}
			$payload[ $key ] = $value;
		}
		if ( isset( $args['arguments'] ) && is_array( $args['arguments'] ) ) {
			foreach ( $args['arguments'] as $key => $value ) {
				$payload[ (string) $key ] = $value;
			}
		}

		if ( ! class_exists( 'PRSTUDIO_Agency' ) || ! is_callable( array( 'PRSTUDIO_Agency', 'dispatch' ) ) ) {
			return new WP_Error( 'wpaib_action_dispatcher_unavailable', 'Dispatcher delle azioni non disponibile.', array( 'status' => 503 ) );
		}
		return PRSTUDIO_Agency::dispatch( (string) $meta['tool_name'], $payload );
	}

	/**
	 * Suggerimenti per un nome di tool inesistente: la risposta di errore deve
	 * insegnare il nome corretto invece di costringere a nuovi tentativi.
	 */
	public static function name_suggestions( string $name, int $limit = 5 ): array {
		$query = str_replace( '_', ' ', (string) preg_replace( '/^rpconnector_/', '', strtolower( $name ) ) );
		$found = self::capability_matches( $query, '', $limit );
		$names = array();
		foreach ( $found['matches'] as $match ) {
			$names[] = (string) $match['tool_name'];
		}
		return $names;
	}

	private static function expose_all_tools(): bool {
		$option = get_option( 'wpaib_mcp_full_tool_surface', false );
		$enabled = ! empty( $option ) && 'false' !== $option && '0' !== (string) $option;
		return (bool) apply_filters( 'wpaib_mcp_expose_all_tools', $enabled );
	}

	/**
	 * Superficie predefinita sicura: discovery, strumenti base, gestione del
	 * lifecycle e sole azioni enterprise native. Le famiglie OpenAPI e le azioni
	 * continuation/stored-only restano richiamabili esplicitamente, ma non vengono
	 * caricate automaticamente nel contesto del modello.
	 */
	public static function listable_tools( string $profile = '' ): array {
		$map = self::tool_map();
		if ( class_exists( 'PRSTUDIO_UC_Catalog_Profile' ) && PRSTUDIO_UC_Catalog_Profile::COMPACT === $profile ) {
			return PRSTUDIO_UC_Catalog_Profile::select_compact_tools( $map );
		}
		if ( self::expose_all_tools() ) { return array_values( $map ); }
		$hidden = array_fill_keys( array_keys( self::route_tool_names() ), true );
		if ( class_exists( 'PRSTUDIO_Agency' ) ) {
			try {
				if ( is_callable( array( 'PRSTUDIO_Agency', 'control_actions' ) ) ) { $hidden += (array) PRSTUDIO_Agency::control_actions(); }
				if ( is_callable( array( 'PRSTUDIO_Agency', 'actions' ) ) ) {
					foreach ( (array) PRSTUDIO_Agency::actions() as $name => $meta ) {
						if ( 0 !== strpos( (string) ( $meta['capability_class'] ?? '' ), 'native_' ) ) { $hidden[ (string) $name ] = true; }
					}
				}
			} catch ( Throwable $e ) { $hidden = array_fill_keys( array_keys( self::route_tool_names() ), true ); }
		}
		$tools = array();
		foreach ( $map as $name => $tool ) { if ( ! isset( $hidden[ $name ] ) ) { $tools[] = $tool; } }
		return $tools;
	}

	/**
	 * Istruzioni restituite da initialize: descrivono il protocollo di
	 * discovery in tre mosse invece di rimandare a metodi JSON-RPC custom che
	 * i client MCP standard non sanno chiamare.
	 */
	public static function server_instructions(): string {
		$routes = self::route_catalog();
		$actions = 0;
		foreach ( $routes as $route ) {
			$actions += count( $route['actions'] );
		}
		return implode( ' ', array(
			'PR STUDIO Unified Control Plane, autenticato via OAuth 2.1 PKCE.',
			'Lo strumento primario è prstudio_orchestrator_resolve: descrivi l’obiettivo e lascia che selezioni una delle 10 classi operative, il workflow e le azioni esatte.',
			'Il registro contiene ' . count( $routes ) . ' famiglie e ' . $actions . ' azioni OpenAPI; non inventare mai nomi di tool o azioni.',
			'Per eseguire un workflow usa prstudio_orchestrator_execute. Le operazioni browser preferiscono automaticamente PR STUDIO Browser Agent live e propagano tabId fra i passaggi.',
			'Per modifiche WordPress avvia prstudio_work_begin durante la preparazione. Il gate anti-crash non blocca discovery, pianificazione, preview o lifecycle: richiede una sola attestazione composita immediatamente prima della modifica reale.',
			'Prepara liberamente il lavoro; subito prima della prima modifica reale esegui una sola attestazione con prstudio_anti_crash_run (o prstudio_anti_crash_submit per evidenza esterna). Il gate non deve bloccare pianificazione, preview o lifecycle del work.',
			'Finalizza con prstudio_work_finalize: viene creato un solo backup consolidato degli originali per l’intero prompt, mai un backup per singolo passaggio.',
			'rpconnector_capability_search, rpconnector_route_index e rpconnector_action_call restano fallback compatibili.',
			'Considera conclusa una scrittura soltanto quando status=completed e verified=true. Mercato prioritario: Italia, Sicilia, provincia di Agrigento. Non inventare dati commerciali.',
		) );
	}


	/**
	 * Restituisce gli strumenti enterprise senza interrompere la discovery se il
	 * registro non è ancora disponibile durante il bootstrap di WordPress.
	 */
	private static function enterprise_tools(): array {
		if ( ! class_exists( 'PRSTUDIO_Agency' ) || ! is_callable( array( 'PRSTUDIO_Agency', 'tools' ) ) ) {
			return array();
		}

		try {
			$tools = PRSTUDIO_Agency::tools();
			if ( ! is_array( $tools ) ) {
				return array();
			}
			$base_names = array_fill_keys( array_column( self::tools(), 'name' ), true );
			return array_values( array_filter(
				$tools,
				static function ( $tool ) use ( $base_names ): bool {
					return is_array( $tool ) && ! isset( $base_names[ (string) ( $tool['name'] ?? '' ) ] );
				}
			) );
		} catch ( Throwable $e ) {
			WPAIB_Audit::log( 'mcp.enterprise_tools_exception', 'error', 'tools/list', array( 'message' => self::safe_exception_message( $e ) ) );
			return array();
		}
	}

	/**
	 * Normalizza uno strumento MCP. Gli strumenti privi di annotazioni vengono
	 * considerati di scrittura per impostazione predefinita: è la scelta sicura.
	 */
	private static function normalize_tool_definition( array $tool ) {
		$name = trim( (string) ( $tool['name'] ?? '' ) );
		if ( '' === $name || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,128}$/', $name ) ) {
			return null;
		}

		$annotations = isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ? $tool['annotations'] : array();
		if ( array_key_exists( 'readOnlyHint', $annotations ) ) {
			$read_only = (bool) $annotations['readOnlyHint'];
		} else {
			$declared_schemes = isset( $tool['securitySchemes'] ) && is_array( $tool['securitySchemes'] ) ? $tool['securitySchemes'] : array();
			$declared_scopes = array();
			foreach ( $declared_schemes as $declared_scheme ) {
				if ( is_array( $declared_scheme ) && isset( $declared_scheme['scopes'] ) && is_array( $declared_scheme['scopes'] ) ) {
					$declared_scopes = array_merge( $declared_scopes, $declared_scheme['scopes'] );
				}
			}
			$read_only = ! empty( $declared_scopes ) && ! in_array( 'wp_ai_bridge.write', $declared_scopes, true );
		}
		$destructive = array_key_exists( 'destructiveHint', $annotations ) ? (bool) $annotations['destructiveHint'] : false;
		$idempotent = array_key_exists( 'idempotentHint', $annotations ) ? (bool) $annotations['idempotentHint'] : false;

		$annotations['readOnlyHint'] = $read_only;
		$annotations['destructiveHint'] = $destructive;
		$annotations['idempotentHint'] = $idempotent;
		$annotations['openWorldHint'] = array_key_exists( 'openWorldHint', $annotations ) ? (bool) $annotations['openWorldHint'] : false;
		$tool['annotations'] = $annotations;

		$scopes = $read_only ? array( 'wp_ai_bridge.read' ) : array( 'wp_ai_bridge.read', 'wp_ai_bridge.write' );
		$security_schemes = array( array( 'type' => 'oauth2', 'scopes' => $scopes ) );
		if ( empty( $tool['securitySchemes'] ) || ! is_array( $tool['securitySchemes'] ) ) {
			$tool['securitySchemes'] = $security_schemes;
		}
		if ( ! isset( $tool['_meta'] ) || ! is_array( $tool['_meta'] ) ) {
			$tool['_meta'] = array();
		}
		if ( empty( $tool['_meta']['securitySchemes'] ) || ! is_array( $tool['_meta']['securitySchemes'] ) ) {
			$tool['_meta']['securitySchemes'] = $tool['securitySchemes'];
		}
		if ( empty( $tool['_meta']['ui'] ) || ! is_array( $tool['_meta']['ui'] ) ) {
			$tool['_meta']['ui'] = array( 'visibility' => array( 'model', 'app' ) );
		}

		$schema = isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] ) ? $tool['inputSchema'] : array();
		$schema['type'] = 'object';
		if ( ! isset( $schema['properties'] ) || ( ! is_array( $schema['properties'] ) && ! is_object( $schema['properties'] ) ) ) {
			$schema['properties'] = new stdClass();
		}
		if ( ! array_key_exists( 'additionalProperties', $schema ) ) {
			$schema['additionalProperties'] = false;
		}
		$tool['inputSchema'] = $schema;

		if ( empty( $tool['outputSchema'] ) || ! is_array( $tool['outputSchema'] ) ) {
			$tool['outputSchema'] = array( 'type' => 'object', 'additionalProperties' => true );
		} else {
			$tool['outputSchema']['type'] = 'object';
			if ( ! array_key_exists( 'additionalProperties', $tool['outputSchema'] ) ) {
				$tool['outputSchema']['additionalProperties'] = true;
			}
		}

		$tool['name'] = $name;
		$tool['title'] = sanitize_text_field( (string) ( $tool['title'] ?? $name ) );
		$tool['description'] = sanitize_textarea_field( (string) ( $tool['description'] ?? '' ) );
		return $tool;
	}

	/**
	 * Catalogo completo, deterministico e deduplicato. Gli strumenti base hanno
	 * precedenza sugli eventuali omonimi generati dal registro enterprise.
	 */
	private static function registry_cache_key(): string {
		$registry = self::registry_info();
		$parts = array(
			(string) ( $registry['registry_hash'] ?? '' ),
			(string) ( $registry['version'] ?? '' ),
			(string) ( $registry['count'] ?? '' ),
			(string) ( $registry['unique_action_names'] ?? '' ),
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	private static function tool_map(): array {
		static $maps = array();
		$key = self::registry_cache_key();
		if ( isset( $maps[ $key ] ) ) {
			return $maps[ $key ];
		}

		$cache_key = 'mcp_tool_map_' . $key;
		if ( function_exists( 'wp_cache_get' ) ) {
			$found = false;
			$cached = wp_cache_get( $cache_key, 'prstudio_uc_contract', false, $found );
			if ( $found && is_array( $cached ) && $cached ) {
				$maps[ $key ] = $cached;
				return $cached;
			}
		}

		$map = array();
		foreach ( array_merge( self::discovery_tools(), self::route_tools(), self::tools(), self::enterprise_tools() ) as $raw_tool ) {
			if ( ! is_array( $raw_tool ) ) {
				continue;
			}
			$tool = self::normalize_tool_definition( $raw_tool );
			if ( ! is_array( $tool ) ) {
				continue;
			}
			$name = (string) $tool['name'];
			if ( ! isset( $map[ $name ] ) ) {
				$map[ $name ] = $tool;
			}
		}
		ksort( $map, SORT_STRING );

		/* Discovery e famiglie di route restano in testa: sono sempre nella prima pagina di tools/list. */
		$priority = array();
		$ordered = array();
		foreach ( self::DISCOVERY_TOOLS as $discovery_name ) {
			$priority[] = (string) $discovery_name;
		}
		foreach ( array_keys( self::route_tool_names() ) as $route_tool_name ) {
			$priority[] = (string) $route_tool_name;
		}
		foreach ( $priority as $priority_name ) {
			if ( isset( $map[ $priority_name ] ) ) {
				$ordered[ $priority_name ] = $map[ $priority_name ];
				unset( $map[ $priority_name ] );
			}
		}
		$map = $ordered + $map;

		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $cache_key, $map, 'prstudio_uc_contract', 86400 );
		}
		$maps = array( $key => $map );
		return $map;
	}

	public static function all_tools(): array {
		return array_values( self::tool_map() );
	}

	/**
	 * Safe in-process compatibility adapter used by the capability gateway.
	 *
	 * This intentionally reuses the real WPAIB_MCP call path (governance,
	 * anti-crash/risk checks and concrete switch dispatch) instead of maintaining
	 * a second partial switch. The adapter returns the raw structured result so
	 * the v5 execution gateway can verify and record it.
	 */
	public static function call_tool_compat( string $name, array $arguments = array() ) {
		$requested_name = trim( $name );
		$name = sanitize_key( $requested_name );
		$tool = self::tool_definition( $name );
		if ( '' === $name || $name !== $requested_name || ! is_array( $tool ) ) {
			return new WP_Error( 'wpaib_mcp_unknown_tool', 'Legacy direct tool is not present in the callable WPAIB_MCP catalog.', array( 'status' => 404, 'tool' => $name ) );
		}

		$is_write = empty( $tool['annotations']['readOnlyHint'] );
		$client_id = sanitize_text_field( (string) ( $arguments['_client_id'] ?? '' ) );
		$lane_handle = trim( (string) ( $arguments['lane_handle'] ?? '' ) );
		$lane_token = trim( (string) ( $arguments['lane_token'] ?? '' ) );
		$credential = '' !== $lane_handle ? $lane_handle : $lane_token;

		if ( $is_write && '' === $client_id ) {
			return new WP_Error( 'legacy_direct_auth_required', 'Legacy direct writes require an authenticated OAuth client binding.', array( 'status' => 401, 'tool' => $name ) );
		}
		if ( $is_write && '' === $credential ) {
			return new WP_Error( 'execution_lane_required', 'Mutating legacy tools require an isolated execution lane.', array( 'status' => 409, 'tool' => $name, 'next_tool' => 'prstudio_context_open' ) );
		}
		if ( '' !== $credential && class_exists( 'PRSTUDIO_UC_Execution_Lanes' ) ) {
			if ( '' === $client_id ) {
				return new WP_Error( 'legacy_direct_auth_required', 'A lane credential can be resolved only for an authenticated OAuth client.', array( 'status' => 401, 'tool' => $name ) );
			}
			if ( '' !== $lane_handle && '' !== $lane_token ) {
				$handle_lane = PRSTUDIO_UC_Execution_Lanes::resolve( $lane_handle, $client_id );
				$token_lane = PRSTUDIO_UC_Execution_Lanes::resolve( $lane_token, $client_id );
				if ( ! $handle_lane || ! $token_lane || ! hash_equals( (string) ( $handle_lane['lane_id'] ?? '' ), (string) ( $token_lane['lane_id'] ?? '' ) ) ) {
					return new WP_Error( 'execution_lane_credential_conflict', 'lane_handle and lane_token do not identify the same OAuth-bound execution lane.', array( 'status' => 409, 'tool' => $name ) );
				}
			}
			$lane = PRSTUDIO_UC_Execution_Lanes::guard( $credential, $client_id );
			if ( is_wp_error( $lane ) ) { return $lane; }
			$arguments['lane_token'] = (string) ( $lane['lane_id'] ?? '' );
			unset( $arguments['lane_handle'] );
		}

		$response = self::call_tool( null, $name, $arguments );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( ! is_object( $response ) || ! is_callable( array( $response, 'get_data' ) ) ) {
			return new WP_Error( 'wpaib_mcp_compat_response_invalid', 'Legacy direct dispatch returned an invalid response envelope.', array( 'status' => 500, 'tool' => $name ) );
		}

		$payload = $response->get_data();
		if ( ! is_array( $payload ) || ! isset( $payload['result'] ) || ! is_array( $payload['result'] ) ) {
			return new WP_Error( 'wpaib_mcp_compat_response_invalid', 'Legacy direct dispatch returned no MCP result.', array( 'status' => 500, 'tool' => $name ) );
		}
		$result = $payload['result'];
		$structured = $result['structuredContent'] ?? null;
		if ( ! empty( $result['isError'] ) ) {
			$error = is_array( $structured ) ? $structured : array();
			$code = sanitize_key( (string) ( $error['error'] ?? 'wpaib_mcp_legacy_tool_failed' ) );
			if ( '' === $code ) { $code = 'wpaib_mcp_legacy_tool_failed'; }
			$details = is_array( $error['data'] ?? null ) ? $error['data'] : array();
			$status = max( 400, min( 599, (int) ( $details['status'] ?? 500 ) ) );
			return new WP_Error( $code, sanitize_text_field( (string) ( $error['message'] ?? 'Legacy direct tool execution failed.' ) ), array( 'status' => $status, 'tool' => $name, 'details' => $details ) );
		}
		if (
			null === $structured
			|| ( is_array( $structured ) && 1 === count( $structured ) && array_key_exists( 'value', $structured ) && null === $structured['value'] )
		) {
			return new WP_Error( 'wpaib_mcp_legacy_tool_empty_result', 'Legacy direct tool returned no structured result.', array( 'status' => 500, 'tool' => $name ) );
		}
		return $structured;
	}

	private static function tool_definition( string $name ) {
		$map = self::tool_map();
		return $map[ $name ] ?? null;
	}

	private static function enterprise_tool_map(): array {
		static $maps = array();
		$key = self::registry_cache_key();
		if ( isset( $maps[ $key ] ) ) {
			return $maps[ $key ];
		}
		$map = array();
		foreach ( self::enterprise_tools() as $raw_tool ) {
			if ( ! is_array( $raw_tool ) ) {
				continue;
			}
			$tool = self::normalize_tool_definition( $raw_tool );
			if ( is_array( $tool ) ) {
				$map[ (string) $tool['name'] ] = $tool;
			}
		}
		$maps = array( $key => $map );
		return $map;
	}

	private static function registry_info(): array {
		if ( ! class_exists( 'PRSTUDIO_Agency' ) || ! is_callable( array( 'PRSTUDIO_Agency', 'control_registry_info' ) ) ) {
			return array();
		}
		try {
			$registry = PRSTUDIO_Agency::control_registry_info();
			return is_array( $registry ) ? $registry : array();
		} catch ( Throwable $e ) {
			return array( 'error' => 'registry_unavailable' );
		}
	}

	private static function registry_hash(): string {
		$registry = self::registry_info();
		$hash = sanitize_text_field( (string) ( $registry['registry_hash'] ?? '' ) );
		if ( '' !== $hash ) {
			return $hash;
		}
		$encoded = wp_json_encode( array_keys( self::tool_map() ) );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	private static function encode_cursor( int $offset, string $registry_hash ): string {
		$raw = wp_json_encode( array( 'offset' => max( 0, $offset ), 'registry_hash' => $registry_hash ) );
		return rtrim( strtr( base64_encode( (string) $raw ), '+/', '-_' ), '=' );
	}

	private static function decode_cursor( string $cursor, string $registry_hash ) {
		if ( '' === $cursor ) {
			return 0;
		}
		$cursor = strtr( $cursor, '-_', '+/' );
		$padding = strlen( $cursor ) % 4;
		if ( 0 !== $padding ) {
			$cursor .= str_repeat( '=', 4 - $padding );
		}
		$raw = base64_decode( $cursor, true );
		$data = false !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['offset'] ) || ! hash_equals( $registry_hash, (string) ( $data['registry_hash'] ?? '' ) ) ) {
			return new WP_Error( 'wpaib_mcp_cursor_invalid', 'Cursor tools/list non valido o relativo a un catalogo precedente.', array( 'status' => 400 ) );
		}
		return max( 0, (int) $data['offset'] );
	}

	private static function auth_can_write( $auth ): bool {
		if ( ! is_array( $auth ) ) {
			return false;
		}
		$scope = preg_split( '/\s+/', trim( (string) ( $auth['scope'] ?? '' ) ) );
		return in_array( 'wp_ai_bridge.write', is_array( $scope ) ? $scope : array(), true );
	}

	private static function paginated_tools( string $cursor = '', bool $include_write = false, string $profile = 'legacy_full_catalog' ) {
		$registry = self::registry_info();
		$registry_hash = self::registry_hash();
		$catalog_hash = hash( 'sha256', $registry_hash . '|' . ( $include_write ? 'read-write' : 'read-only' ) . '|' . $profile );
		$offset = self::decode_cursor( $cursor, $catalog_hash );
		if ( is_wp_error( $offset ) ) {
			return $offset;
		}

		$expose_all = self::expose_all_tools();
		$all = self::listable_tools( $profile );
		/* 0.3.9: a refresh must observe a byte-stable catalog for the same registry. */
		usort( $all, static function ( $left, $right ): int {
			return strcmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );
		} );
		if ( ! $include_write ) {
			$all = array_values( array_filter( $all, static function ( $tool ): bool {
				return is_array( $tool ) && ! empty( $tool['annotations']['readOnlyHint'] );
			} ) );
		}
		$compact = class_exists( 'PRSTUDIO_UC_Catalog_Profile' ) && PRSTUDIO_UC_Catalog_Profile::COMPACT === $profile;
		$page_size = $compact ? PRSTUDIO_UC_Catalog_Profile::COMPACT_MAX_TOOLS : (int) apply_filters( 'wpaib_mcp_tools_page_size', 400 );
		$page_size = max( 1, min( $compact ? PRSTUDIO_UC_Catalog_Profile::COMPACT_MAX_TOOLS : 500, $page_size ) );
		$page = array_slice( $all, $offset, $page_size );
		$result = array(
			'tools' => $page,
			'_meta' => array(
				'registry' => $registry,
				'registryHash' => $registry_hash,
				'catalogHash' => $catalog_hash,
				'catalogAccess' => $include_write ? 'read-write' : 'read-only',
				'totalTools' => count( $all ),
				'pageSize' => $page_size,
				'offset' => $offset,
				'surface' => $compact ? PRSTUDIO_UC_Catalog_Profile::COMPACT : ( $expose_all ? 'full' : PRSTUDIO_UC_Catalog_Profile::LEGACY ),
				'catalogProfile' => $profile,
				'catalogProfiles' => class_exists( 'PRSTUDIO_UC_Catalog_Profile' ) ? PRSTUDIO_UC_Catalog_Profile::status( $profile ) : array(),
				'callableToolCount' => count( self::tool_map() ),
				'discoveryTools' => array_values( self::DISCOVERY_TOOLS ),
				'routeFamilyTools' => array_keys( self::route_tool_names() ),
				'ttlMs' => 300000,
				'deterministicOrder' => true,
				'knowledgeSnapshot' => class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::knowledge_snapshot() : array(),
				'usage' => $compact
					? 'Modalità compatta 2.0.0: al massimo 48 schemi canonici attivi. Tutti i tool 0.3.3 restano direttamente richiamabili; usa discovery, route family e orchestratore per recuperarli dinamicamente.'
					: 'Profilo legacy 0.3.3: comportamento tools/list invariato. Il registro completo resta direttamente richiamabile e ogni chiamata passa dall’orchestratore trasparente.',
			),
		);
		/* MCP 2026-07-28 cache contract. Do not emit these top-level fields to
		 * older protocol generations, preserving frozen connector snapshots. */
		if ( '2026-07-28' === self::request_protocol() ) {
			$result['ttlMs'] = 300000;
			$result['cacheScope'] = 'private';
		}
		if ( ! $compact && $offset + count( $page ) < count( $all ) ) {
			$result['nextCursor'] = self::encode_cursor( $offset + count( $page ), $catalog_hash );
		}
		return $result;
	}

	public static function schema_diagnostics(): array {
		$errors = array();
		$warnings = array();
		$seen = array();
		$raw_tools = array_merge( self::discovery_tools(), self::route_tools(), self::tools(), self::enterprise_tools() );

		foreach ( $raw_tools as $index => $raw_tool ) {
			if ( ! is_array( $raw_tool ) ) {
				$errors[] = 'tool_' . $index . ': definizione non valida.';
				continue;
			}
			$name = trim( (string) ( $raw_tool['name'] ?? 'tool_' . $index ) );
			if ( isset( $seen[ $name ] ) ) {
				$warnings[] = $name . ': nome duplicato; viene mantenuta la prima definizione.';
			}
			$seen[ $name ] = true;

			$tool = self::normalize_tool_definition( $raw_tool );
			if ( ! is_array( $tool ) ) {
				$errors[] = $name . ': nome MCP non valido.';
				continue;
			}
			$schema = $tool['inputSchema'];
			if ( 'object' !== ( $schema['type'] ?? null ) ) {
				$errors[] = $name . ': inputSchema non è un oggetto JSON Schema.';
			}
			if ( isset( $schema['required'] ) && ! is_array( $schema['required'] ) ) {
				$errors[] = $name . ': required non è un array.';
			}
			foreach ( (array) ( $schema['required'] ?? array() ) as $required_key ) {
				$properties = is_array( $schema['properties'] ) ? $schema['properties'] : array();
				if ( ! array_key_exists( (string) $required_key, $properties ) ) {
					$errors[] = $name . ': proprietà obbligatoria non definita: ' . (string) $required_key . '.';
				}
			}
			if ( empty( $tool['securitySchemes'] ) || empty( $tool['_meta']['securitySchemes'] ) ) {
				$errors[] = $name . ': securitySchemes mancante.';
			}
			if ( 'object' !== ( $tool['outputSchema']['type'] ?? null ) ) {
				$errors[] = $name . ': outputSchema non è un oggetto.';
			}
		}

		$tools = self::all_tools();
		return array(
			'ok' => empty( $errors ),
			'raw_tool_count' => count( $raw_tools ),
			'tool_count' => count( $tools ),
			'tool_names' => array_column( $tools, 'name' ),
			'registry_hash' => self::registry_hash(),
			'errors' => $errors,
			'warnings' => $warnings,
		);
	}

	public static function record_trace( string $method, string $result, array $extra = array() ): void {
		$trace = array_merge( array(
			'time' => time(),
			'method' => sanitize_text_field( $method ),
			'result' => sanitize_text_field( $result ),
			'auth_header_seen' => '' !== WPAIB_Auth::bearer_token_from_request(),
			'auth_header_source' => WPAIB_Auth::authorization_header_source(),
			'protocol_header' => sanitize_text_field( (string) ( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '' ) ),
		), $extra );
		update_option( 'wpaib_last_mcp_trace', $trace, false );
	}

	private static function is_write_tool( string $name ): bool {
		$tool = self::tool_definition( $name );
		if ( ! is_array( $tool ) ) {
			return true;
		}
		return empty( $tool['annotations']['readOnlyHint'] );
	}

	private static function canonical_origin( string $origin ) {
		$origin = trim( $origin );
		if ( '' === $origin || 'null' === strtolower( $origin ) ) {
			return null;
		}
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			return null;
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( '' !== $path && '/' !== $path ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}
		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( '' === $host ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( $port < 1 || $port > 65535 ) {
			return null;
		}
		return $scheme . '://' . $host . ':' . $port;
	}

	private static function allowed_origin( string $origin ): bool {
		if ( '' === trim( $origin ) ) {
			return true;
		}
		$canonical = self::canonical_origin( $origin );
		if ( null === $canonical ) {
			return false;
		}
		$site_origin = self::canonical_origin( home_url( '/' ) );
		if ( null !== $site_origin && hash_equals( $site_origin, $canonical ) ) {
			return true;
		}
		if ( 0 !== strpos( $canonical, 'https://' ) ) {
			return false;
		}
		$defaults = array( 'https://chatgpt.com', 'https://chat.openai.com', 'https://platform.openai.com' );
		$configured = (array) ( WPAIB_Auth::settings()['allowed_origins'] ?? array() );
		$allowed_origins = (array) apply_filters( 'wpaib_mcp_allowed_origins', array_merge( $defaults, $configured ) );
		foreach ( $allowed_origins as $allowed_origin ) {
			$allowed = self::canonical_origin( (string) $allowed_origin );
			if ( null !== $allowed && hash_equals( $allowed, $canonical ) ) {
				return true;
			}
		}
		return false;
	}

	private static function supported_protocols(): array {
		return array( '2025-11-25', '2025-06-18', '2025-03-26' );
	}

	private static function negotiate_protocol( string $requested ): string {
		if ( in_array( $requested, self::supported_protocols(), true ) ) { return $requested; }
		/* Never answer an unknown client version with the private forward protocol:
		 * use the newest broadly interoperable MCP generation instead. */
		return '2025-11-25';
	}

	private static function request_protocol(): string {
		$header = sanitize_text_field( (string) ( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '' ) );
		return '' !== $header ? $header : '2025-03-26';
	}

	private static function validate_protocol_header( string $method ) {
		$header = sanitize_text_field( (string) ( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '' ) );
		if ( '' === $header ) {
			// Compatibilità MCP: in assenza dell'header si assume 2025-03-26.
			return true;
		}
		if ( ! in_array( $header, self::supported_protocols(), true ) ) {
			return new WP_Error( 'wpaib_mcp_protocol_unsupported', 'Versione MCP-Protocol-Version non supportata.', array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Da usare come permission_callback della route MCP. L'autorizzazione vera
	 * viene applicata per singolo tool dentro tools/call, mentre initialize e
	 * tools/list e tools/call applicano l'autenticazione nel dispatcher JSON-RPC.
	 */
	public static function permission_callback( WP_REST_Request $request ) {
		return true;
	}

	private static function json_to_array( $value ) {
		if ( $value instanceof stdClass ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::json_to_array( $item );
			}
		}
		return $value;
	}

	private static function request_payload( WP_REST_Request $request ) {
		$body = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		if ( '' !== trim( $body ) ) {
			$decoded = json_decode( $body );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wpaib_mcp_parse_error', 'Payload JSON non valido.', array( 'status' => 400 ) );
			}
			if ( is_array( $decoded ) ) {
				return new WP_Error( 'wpaib_mcp_batch_unsupported', 'Batch JSON-RPC non supportato.', array( 'status' => 400 ) );
			}
			if ( ! $decoded instanceof stdClass ) {
				return new WP_Error( 'wpaib_mcp_request_invalid', 'La richiesta JSON-RPC deve essere un oggetto.', array( 'status' => 400 ) );
			}
			return array( 'payload' => get_object_vars( $decoded ), 'raw' => true );
		}
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || self::is_list_array( $payload ) ) {
			return new WP_Error( 'wpaib_mcp_request_invalid', 'La richiesta JSON-RPC deve essere un oggetto.', array( 'status' => 400 ) );
		}
		return array( 'payload' => $payload, 'raw' => false );
	}

	private static function json_object( $value, bool $raw, string $label ) {
		if ( $value instanceof stdClass ) {
			return self::json_to_array( $value );
		}
		if ( is_array( $value ) && ( ! self::is_list_array( $value ) || ( ! $raw && empty( $value ) ) ) ) {
			return self::json_to_array( $value );
		}
		return new WP_Error( 'wpaib_mcp_invalid_params', $label . ' deve essere un oggetto JSON.', array( 'status' => 400 ) );
	}

	private static function valid_request_id( $id ): bool {
		return is_int( $id ) || is_string( $id );
	}

	private static function rpc_auth_error_response( $id, WP_Error $error ): WP_REST_Response {
		$description = $error->get_error_message();
		$challenge = WPAIB_REST::www_authenticate_challenge( 'invalid_token', $description );
		$response = self::error_response( $id, -32001, $description, 401 );
		$response->header( 'WWW-Authenticate', $challenge );
		return $response;
	}

	public static function handle( WP_REST_Request $request ) {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( ! self::allowed_origin( $origin ) ) {
			self::record_trace( 'origin', 'rejected', array( 'origin_host' => strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) ) );
			return self::error_response( null, -32001, 'Origin non consentita.', 403 );
		}

		$decoded = self::request_payload( $request );
		if ( is_wp_error( $decoded ) ) {
			$code = 'wpaib_mcp_parse_error' === $decoded->get_error_code() ? -32700 : -32600;
			return self::error_response( null, $code, $decoded->get_error_message(), 400 );
		}
		$payload = $decoded['payload'];
		$raw = (bool) $decoded['raw'];
		$id_present = array_key_exists( 'id', $payload );
		$id = $id_present ? $payload['id'] : null;
		if ( ! array_key_exists( 'jsonrpc', $payload ) || '2.0' !== $payload['jsonrpc'] ) {
			return self::error_response( self::valid_request_id( $id ) ? $id : null, -32600, 'Versione JSON-RPC mancante o non valida.', 400 );
		}
		if ( $id_present && ! self::valid_request_id( $id ) ) {
			return self::error_response( null, -32600, 'ID JSON-RPC non valido.', 400 );
		}
		$method = is_string( $payload['method'] ?? null ) ? trim( $payload['method'] ) : '';
		if ( '' === $method || 1 !== preg_match( '/^[A-Za-z0-9_.\/-]{1,128}$/', $method ) ) {
			return self::error_response( $id, -32600, 'Metodo JSON-RPC mancante o non valido.', 400 );
		}
		$protocol_valid = self::validate_protocol_header( $method );
		if ( is_wp_error( $protocol_valid ) ) {
			if ( ! $id_present ) {
				self::record_trace( $method, 'ignored_invalid_notification', array( 'error_code' => $protocol_valid->get_error_code() ) );
				return new WP_REST_Response( null, 202 );
			}
			return self::error_response( $id, -32600, $protocol_valid->get_error_message(), 400 );
		}

		if ( ! $id_present ) {
			self::record_trace( $method, 0 === strpos( $method, 'notifications/' ) ? 'accepted_notification' : 'ignored_request_notification' );
			return new WP_REST_Response( null, 202 );
		}
		if ( 0 === strpos( $method, 'notifications/' ) ) {
			return self::error_response( $id, -32601, 'Le notifiche MCP non devono contenere id.', 200 );
		}

		$params_raw = array_key_exists( 'params', $payload ) ? $payload['params'] : new stdClass();
		$params = self::json_object( $params_raw, $raw, 'params' );
		if ( is_wp_error( $params ) ) {
			return self::error_response( $id, -32602, $params->get_error_message(), 200 );
		}
		switch ( $method ) {
			case 'server/discover':
				$auth = WPAIB_Auth::permission( false );
				if ( is_wp_error( $auth ) ) { return self::rpc_auth_error_response( $id, $auth ); }
				$knowledge = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::knowledge_snapshot() : array();
				$response = self::result_response( $id, array(
					'protocolVersions' => self::supported_protocols(),
					'serverInfo' => array(
						'name' => 'pr-studio-ai-bridge',
						'title' => 'PR STUDIO RpConnector Admin Bridge',
						'version' => defined( 'WPAIB_VERSION' ) ? WPAIB_VERSION : 'unknown',
					),
					'capabilities' => array(
						'tools' => array( 'listChanged' => false, 'cacheable' => true, 'ttlMs' => 300000 ),
						'extensions' => array( 'tasks', 'catalogProfiles', 'prstudioKnowledge' ),
					),
					'knowledge' => $knowledge,
					'catalogProfiles' => class_exists( 'PRSTUDIO_UC_Catalog_Profile' ) ? PRSTUDIO_UC_Catalog_Profile::status( PRSTUDIO_UC_Catalog_Profile::COMPACT ) : array(),
				) );
				$response->header( 'Cache-Control', 'private, max-age=300, stale-while-revalidate=60' );
				$response->header( 'ETag', '"' . hash( 'sha256', wp_json_encode( $knowledge ) ?: '' ) . '"' );
				$response->header( 'Vary', 'Authorization, MCP-Protocol-Version' );
				return $response;

			case 'initialize':
				$requested = is_string( $params['protocolVersion'] ?? null ) ? sanitize_text_field( $params['protocolVersion'] ) : '';
				if ( '' === $requested ) {
					return self::error_response( $id, -32602, 'protocolVersion mancante.', 200 );
				}
				$capabilities_raw = $params_raw instanceof stdClass ? ( $params_raw->capabilities ?? null ) : ( $params_raw['capabilities'] ?? null );
				$client_info_raw = $params_raw instanceof stdClass ? ( $params_raw->clientInfo ?? null ) : ( $params_raw['clientInfo'] ?? null );
				$capabilities = self::json_object( $capabilities_raw, $raw, 'capabilities' );
				$client_info = self::json_object( $client_info_raw, $raw, 'clientInfo' );
				if ( is_wp_error( $capabilities ) || is_wp_error( $client_info ) || empty( $client_info['name'] ) || empty( $client_info['version'] ) ) {
					return self::error_response( $id, -32602, 'capabilities e clientInfo{name,version} sono obbligatori.', 200 );
				}
				$protocol = self::negotiate_protocol( $requested );
				$selection = class_exists( 'PRSTUDIO_UC_Catalog_Profile' )
					? PRSTUDIO_UC_Catalog_Profile::negotiate( $protocol, $capabilities, $client_info )
					: array( 'profile' => 'legacy_full_catalog', 'reason' => 'profile_manager_unavailable' );
				$session_id = class_exists( 'PRSTUDIO_UC_Catalog_Profile' ) ? PRSTUDIO_UC_Catalog_Profile::create_session( $selection ) : '';
				self::record_trace( 'initialize', 'success_public_discovery', array( 'protocol_version' => $protocol, 'catalog_profile' => $selection['profile'] ?? 'legacy_full_catalog' ) );
				$response = self::result_response( $id, array(
					'protocolVersion' => $protocol,
					'capabilities' => array(
						'tools' => array( 'listChanged' => false ),
						'extensions' => array( 'tasks', 'catalogProfiles', 'prstudioKnowledge' ),
						'experimental' => array(
							'prstudio' => array(
								'catalogProfiles' => array( 'legacy_full_catalog', 'compact_dynamic_catalog' ),
								'activeCatalogProfile' => (string) ( $selection['profile'] ?? 'legacy_full_catalog' ),
								'compactMaxTools' => class_exists( 'PRSTUDIO_UC_Catalog_Profile' ) ? PRSTUDIO_UC_Catalog_Profile::COMPACT_MAX_TOOLS : 48,
								'callableTools' => class_exists( 'PRSTUDIO_UC_Contract' ) ? PRSTUDIO_UC_Contract::callable_tool_count() : count( self::tool_map() ),
								'catalogCache' => array( 'cacheable'=>true, 'ttlMs'=>300000, 'deterministic'=>true ),
							),
						),
					),
					'serverInfo' => array(
						'name' => 'pr-studio-ai-bridge',
						'title' => 'PR STUDIO RpConnector Admin Bridge',
						'version' => defined( 'WPAIB_VERSION' ) ? WPAIB_VERSION : 'unknown',
						'description' => 'WordPress/WooCommerce MCP server con registro enterprise dinamico.',
					),
					'instructions' => self::server_instructions(),
					'_meta' => array(
						'prstudio/catalog' => $selection,
						'prstudio/knowledge' => class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::knowledge_snapshot() : array(),
					),
				) );
				if ( '' !== $session_id ) { $response->header( 'Mcp-Session-Id', $session_id ); }
				$response->header( 'X-PRSTUDIO-Catalog-Profile', (string) ( $selection['profile'] ?? 'legacy_full_catalog' ) );
				return $response;

			case 'ping':
				self::record_trace( 'ping', 'success' );
				return self::result_response( $id, new stdClass() );

			case 'tools/list':
				$auth = WPAIB_Auth::permission( false );
				if ( is_wp_error( $auth ) ) {
					self::record_trace( 'tools/list', 'auth_error', array( 'error_code' => $auth->get_error_code() ) );
					return self::rpc_auth_error_response( $id, $auth );
				}
				$cursor = isset( $params['cursor'] ) && is_string( $params['cursor'] ) ? sanitize_text_field( $params['cursor'] ) : '';
				$profile_state = class_exists( 'PRSTUDIO_UC_Catalog_Profile' ) ? PRSTUDIO_UC_Catalog_Profile::profile_for_request( $params ) : array( 'profile' => 'legacy_full_catalog' );
				$profile = (string) ( $profile_state['profile'] ?? 'legacy_full_catalog' );
				$listed = self::paginated_tools( $cursor, self::auth_can_write( $auth ), $profile );
				if ( is_wp_error( $listed ) ) {
					return self::error_response( $id, -32602, $listed->get_error_message(), 200 );
				}
				self::record_trace( 'tools/list', 'success', array( 'tool_count' => count( $listed['tools'] ), 'total_tool_count' => $listed['_meta']['totalTools'] ?? null, 'catalog_profile' => $profile ) );
				$response = self::result_response( $id, $listed );
				$response->header( 'X-PRSTUDIO-Catalog-Profile', $profile );
				$response->header( 'Cache-Control', 'private, max-age=300, stale-while-revalidate=60' );
				$response->header( 'ETag', '"' . (string) ( $listed['_meta']['catalogHash'] ?? self::registry_hash() ) . '"' );
				$response->header( 'Vary', 'Authorization, MCP-Protocol-Version, X-PRSTUDIO-Catalog-Profile' );
				return $response;

			case 'tools/registry':
				$auth = WPAIB_Auth::permission( false );
				if ( is_wp_error( $auth ) ) {
					return self::rpc_auth_error_response( $id, $auth );
				}
				return self::result_response( $id, self::registry_info() );

			case 'tools/call':
				$name = is_string( $params['name'] ?? null ) ? trim( $params['name'] ) : '';
				if ( '' === $name || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,128}$/', $name ) || ! is_array( self::tool_definition( $name ) ) ) {
					$suggestions = '' !== $name ? self::name_suggestions( $name ) : array();
					$hint = $suggestions
						? ' Forse intendevi: ' . implode( ', ', $suggestions ) . '. In alternativa usa prstudio_orchestrator_resolve per ottenere classe, workflow e azione esatta.'
						: ' Usa prstudio_orchestrator_resolve per ottenere classe, workflow e azione esatta.';
					return self::error_response( $id, -32602, 'Tool sconosciuto o non valido: ' . sanitize_text_field( $name ) . '.' . $hint, 200 );
				}
				$arguments_raw = $params_raw instanceof stdClass ? ( $params_raw->arguments ?? new stdClass() ) : ( $params_raw['arguments'] ?? array() );
				$arguments = self::json_object( $arguments_raw, $raw, 'arguments' );
				if ( is_wp_error( $arguments ) ) {
					return self::error_response( $id, -32602, $arguments->get_error_message(), 200 );
				}
				$auth = WPAIB_Auth::permission( self::is_write_tool( $name ) );
				if ( is_wp_error( $auth ) ) {
					self::record_trace( 'tools/call:' . $name, 'auth_error', array( 'error_code' => $auth->get_error_code() ) );
					return self::tool_auth_error_response( $id, $auth );
				}
				return self::call_tool( $id, $name, $arguments );

			default:
				self::record_trace( $method, 'method_not_supported' );
				return self::error_response( $id, -32601, 'Metodo MCP non supportato.', 200 );
		}
	}

	private static function metadata_value( array $tool, array $keys ): string {
		$containers = array(
			$tool,
			isset( $tool['_meta'] ) && is_array( $tool['_meta'] ) ? $tool['_meta'] : array(),
			isset( $tool['_meta']['prstudio'] ) && is_array( $tool['_meta']['prstudio'] ) ? $tool['_meta']['prstudio'] : array(),
			isset( $tool['_meta']['execution'] ) && is_array( $tool['_meta']['execution'] ) ? $tool['_meta']['execution'] : array(),
			isset( $tool['x-prstudio'] ) && is_array( $tool['x-prstudio'] ) ? $tool['x-prstudio'] : array(),
		);
		foreach ( $containers as $container ) {
			foreach ( $keys as $key ) {
				if ( isset( $container[ $key ] ) && is_scalar( $container[ $key ] ) ) {
					$value = trim( (string) $container[ $key ] );
					if ( '' !== $value ) {
						return $value;
					}
				}
			}
		}
		return '';
	}

	private static function enterprise_route_names(): array {
		$routes = array(
			'commerce_settings_manage', 'maintenance_manage', 'global_search', 'system_manage',
			'backup_manage', 'cache_manage', 'cron_manage', 'logs_manage', 'content_manage',
			'taxonomy_manage', 'media_manage', 'comments_manage', 'users_manage', 'menus_manage',
			'widgets_manage', 'templates_manage', 'styles_manage', 'settings_manage', 'plugins_manage',
			'themes_manage', 'products_manage', 'inventory_manage', 'orders_manage', 'customers_manage',
			'coupons_manage', 'seo_manage', 'files_manage', 'database_manage', 'frontend_manage',
			'security_manage', 'cdn_cache_manage', 'ab_test',
		);
		usort( $routes, static function ( $left, $right ) {
			return strlen( $right ) <=> strlen( $left );
		} );
		return $routes;
	}

	private static function enterprise_execution_target( string $name, array $tool ) {
		$route = self::metadata_value( $tool, array( 'process', 'route_key', 'route', 'endpoint', 'department' ) );
		$action = self::metadata_value( $tool, array( 'action', 'command', 'operation' ) );

		$description = (string) ( $tool['description'] ?? '' );
		if ( ( '' === $route || '' === $action ) && preg_match( "/comando\\s+['\"]([^'\"]+)['\"].*?route\\s+\\/?([A-Za-z0-9_-]+)/iu", $description, $matches ) ) {
			$action = '' === $action ? (string) $matches[1] : $action;
			$route = '' === $route ? (string) $matches[2] : $route;
		}

		$route = trim( $route );
		if ( false !== strpos( $route, '/' ) ) {
			$route = (string) basename( untrailingslashit( $route ) );
		}
		$route = str_replace( '-', '_', sanitize_key( $route ) );
		$action = sanitize_key( str_replace( '-', '_', $action ) );

		$prefix = 'rpconnector_';
		$remainder = 0 === strpos( $name, $prefix ) ? substr( $name, strlen( $prefix ) ) : $name;
		if ( '' === $route || '' === $action ) {
			foreach ( self::enterprise_route_names() as $candidate ) {
				$candidate_prefix = $candidate . '_';
				if ( 0 === strpos( $remainder, $candidate_prefix ) ) {
					$route = '' === $route ? $candidate : $route;
					$action = '' === $action ? substr( $remainder, strlen( $candidate_prefix ) ) : $action;
					break;
				}
			}
		}

		if ( '' === $route || '' === $action ) {
			return new WP_Error( 'wpaib_mcp_enterprise_target_missing', 'Impossibile determinare route e azione per il tool enterprise ' . $name . '.' );
		}
		return array( 'process' => $route, 'action' => $action );
	}

	private static function dispatch_enterprise_tool( string $name, array $args ) {
		$enterprise = self::enterprise_tool_map();
		if ( ! isset( $enterprise[ $name ] ) ) {
			return new WP_Error( 'wpaib_mcp_unknown_tool', 'Tool enterprise sconosciuto: ' . $name . '.' );
		}

		if ( ! class_exists( 'PRSTUDIO_Agency' ) ) {
			return new WP_Error( 'wpaib_mcp_enterprise_unavailable', 'Dispatcher enterprise non disponibile.' );
		}
		/* Il dispatcher pubblico reale del plugin ha precedenza sui fallback. */
		foreach ( array( 'dispatch', 'execute_tool', 'call_tool', 'dispatch_tool' ) as $method ) {
			if ( is_callable( array( 'PRSTUDIO_Agency', $method ) ) ) {
				return call_user_func( array( 'PRSTUDIO_Agency', $method ), $name, $args );
			}
		}

		$target = self::enterprise_execution_target( $name, $enterprise[ $name ] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$payload = $args;
		$payload['action'] = $target['action'];
		$execution_mode = isset( $payload['execution_mode'] ) ? sanitize_key( (string) $payload['execution_mode'] ) : 'run';
		if ( ! in_array( $execution_mode, array( 'preview', 'queue', 'run', 'schedule' ), true ) ) {
			$execution_mode = 'run';
		}
		unset( $payload['execution_mode'], $payload['idempotency_key'], $payload['priority'], $payload['schedule'] );

		$idempotency_key = sanitize_text_field( (string) ( $args['idempotency_key'] ?? $args['request_id'] ?? '' ) );
		$priority = max( 0, min( 255, (int) ( $args['priority'] ?? 100 ) ) );
		$envelope = array(
			'payload' => $payload,
			'execution_mode' => $execution_mode,
			'idempotency_key' => $idempotency_key,
			'priority' => $priority,
		);
		if ( isset( $args['schedule'] ) && is_array( $args['schedule'] ) ) {
			$envelope['schedule'] = $args['schedule'];
		}

		if ( is_callable( array( 'PRSTUDIO_Agency', 'execute' ) ) ) {
			return PRSTUDIO_Agency::execute( $target['process'], $envelope );
		}

		/* Fallback locale: inoltra alla route REST enterprise senza uscire dal sito. */
		$route_path = '/rpconnector-admin/v1/' . str_replace( '_', '-', $target['process'] );
		$internal_request = new WP_REST_Request( 'POST', $route_path );
		$internal_request->set_header( 'Content-Type', 'application/json' );
		$encoded_payload = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded_payload ) {
			return new WP_Error( 'wpaib_mcp_enterprise_json_encode', 'Impossibile codificare il payload enterprise.' );
		}
		$internal_request->set_body( $encoded_payload );
		$internal_request->set_body_params( $payload );
		return rest_do_request( $internal_request );
	}

	private static function unwrap_rest_result( $result ) {
		if ( $result instanceof WP_REST_Response || $result instanceof WP_HTTP_Response ) {
			$status = (int) $result->get_status();
			$data = $result->get_data();
			if ( $status >= 400 ) {
				$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'La route enterprise ha restituito HTTP ' . $status . '.';
				return new WP_Error( 'wpaib_mcp_enterprise_http_error', $message, array( 'status' => $status, 'response' => $data ) );
			}
			return $data;
		}
		return $result;
	}

	private static function safe_exception_message( Throwable $error ): string {
		$message = (string) $error->getMessage();
		$message = preg_replace( '/Bearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message );
		$message = preg_replace( '/([?&](?:access_token|refresh_token|id_token|client_secret|authorization|code)=)[^&\s]+/i', '$1[REDACTED]', $message );
		$message = preg_replace( '/([\"\']?(?:access_token|refresh_token|id_token|client_secret|authorization|code)[\"\']?\s*[:=]\s*[\"\']?)[^\"\'\s,}]+/i', '$1[REDACTED]', $message );
		$message = preg_replace( '/\beyJ[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', '[REDACTED]', $message );
		$message = sanitize_text_field( (string) $message );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 300 ) : substr( $message, 0, 300 );
	}

	private static function call_tool( $id, string $name, $arguments ) {
		$args = is_array( $arguments ) ? $arguments : array();
		$read_only_tool = ! self::is_write_tool( $name );
		$fast_path = class_exists( 'PRSTUDIO_UC_Execution_Router' ) && PRSTUDIO_UC_Execution_Router::legacy_tool_can_inline( $name, $read_only_tool );
		$memory_job = (string)($args['request_id'] ?? $args['idempotency_key'] ?? '');
		$mcp_span = array();
		$governance = array( 'executor'=>'direct_native', 'strategy'=>'fast_inline', 'tool'=>$name );
		$orchestration_meta = array( 'prstudio/execution'=>array( 'route'=>$fast_path?'fast_inline':'complex', 'agency_bypassed'=>$fast_path, 'queue_bypassed'=>$fast_path ) );
		if ( ! $fast_path ) {
			if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('mcp.tool.received',['resource'=>$name,'outcome'=>'received','method'=>'mcp'], $memory_job);
			$mcp_span=class_exists('PRSTUDIO_UC_Observability')?PRSTUDIO_UC_Observability::start('mcp.tool',['tool'=>$name]):array();
			$governance = class_exists( 'PRSTUDIO_UC_Orchestrator' ) ? PRSTUDIO_UC_Orchestrator::govern_tool_call( $name, $args ) : new WP_Error( 'prstudio_orchestrator_missing', 'Orchestratore non disponibile.' );
			if ( is_wp_error( $governance ) ) {
				if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('mcp.tool.failed',['resource'=>$name,'outcome'=>$governance->get_error_code(),'method'=>'governance'],$memory_job);
				if(class_exists('PRSTUDIO_UC_Observability'))PRSTUDIO_UC_Observability::finish($mcp_span,'governance_error',['error'=>$governance->get_error_code()]);
				return self::tool_result_response( $id, array( 'error'=>$governance->get_error_code(), 'message'=>$governance->get_error_message(), 'data'=>$governance->get_error_data() ), true );
			}
			$orchestration_meta = PRSTUDIO_UC_Orchestrator::governance_meta( $governance );
		}
		$profile_state = class_exists( 'PRSTUDIO_UC_Catalog_Profile' ) ? PRSTUDIO_UC_Catalog_Profile::profile_for_request() : array( 'profile' => 'legacy_full_catalog' );
		$orchestration_meta['prstudio/catalog'] = array( 'profile' => (string) ( $profile_state['profile'] ?? 'legacy_full_catalog' ), 'callable_tools_unchanged' => true );
		if ( self::is_write_tool( $name ) && class_exists( 'PRSTUDIO_UC_Pre_Mutation_Safety' ) ) {
			$scope = PRSTUDIO_UC_Pre_Mutation_Safety::scope_for_direct_tool( $name, $args );
			if ( 'deferred' !== $scope ) {
				$gate = PRSTUDIO_UC_Pre_Mutation_Safety::before_commit( $scope, $name, $args );
				if ( is_wp_error( $gate ) ) {
					if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('mcp.tool.anti_crash',['resource'=>$name,'outcome'=>$gate->get_error_code(),'method'=>'pre_mutation_safety'],$memory_job);
					if(class_exists('PRSTUDIO_UC_Observability'))PRSTUDIO_UC_Observability::finish($mcp_span,'anti_crash',['error'=>$gate->get_error_code()]);
					return self::tool_result_response( $id, array('error'=>$gate->get_error_code(),'message'=>$gate->get_error_message(),'data'=>$gate->get_error_data()), true, $orchestration_meta );
				}
			}
		}
		try {
			switch ( $name ) {
				case 'prstudio_orchestrator_resolve': $result = PRSTUDIO_UC_Orchestrator::resolve( $args ); break;
				case 'prstudio_orchestrator_domain_actions': $result = PRSTUDIO_UC_Orchestrator::domain_actions( $args ); break;
				case 'prstudio_orchestrator_execute': $result = PRSTUDIO_UC_Orchestrator::execute( $args ); break;
				case 'prstudio_work_begin': $result = PRSTUDIO_UC_Work_Session::begin( $args ); break;
				case 'prstudio_work_status': $result = PRSTUDIO_UC_Work_Session::resolve( (string) ( $args['work_id'] ?? '' ) ); break;
				case 'prstudio_anti_crash_requirements': $result = PRSTUDIO_UC_Anti_Crash::requirements( $args ); break;
				case 'prstudio_anti_crash_run': $result = PRSTUDIO_UC_Anti_Crash::run_server_tests( $args ); break;
				case 'prstudio_anti_crash_submit': $result = PRSTUDIO_UC_Anti_Crash::submit( $args ); break;
				case 'prstudio_work_finalize': $result = PRSTUDIO_UC_Work_Session::finalize( $args ); break;
				case 'prstudio_work_abort': $result = PRSTUDIO_UC_Work_Session::abort( $args ); break;
				case 'bridge_status': $result = WPAIB_Site::status(); break;
				case 'verify_private_wordpress_access':
					$result = WPAIB_Site::status();
					$result['access_verified'] = true;
					$result['access_type'] = 'private_authenticated_mcp';
					$result['not_dependent_on'] = array( 'Jetpack plan', 'WordPress.com plan', 'public web browsing' );
					break;
				case 'enterprise_status': $result = WPAIB_Enterprise::status(); break;
				case 'get_audit_log': $result = WPAIB_Enterprise::audit_log( $args ); break;
				case 'work_lock': $result = WPAIB_Enterprise::work_lock( $args ); break;
				case 'work_state': $result = WPAIB_Enterprise::work_state( $args ); break;
				case 'inventory_tree': $result = WPAIB_Files::manifest( (string) ( $args['path'] ?? '' ), (int) ( $args['cursor'] ?? 0 ), (int) ( $args['limit'] ?? 300 ), (bool) ( $args['hashes'] ?? false ) ); break;
				case 'list_directory': $result = WPAIB_Files::list_directory( (string) ( $args['path'] ?? '' ) ); break;
				case 'read_file': $result = WPAIB_Files::read_file( (string) ( $args['path'] ?? '' ), (int) ( $args['offset'] ?? 0 ), isset( $args['length'] ) ? (int) $args['length'] : null ); break;
				case 'search_files': $result = WPAIB_Files::search( (string) ( $args['query'] ?? '' ), (string) ( $args['path'] ?? '' ), is_array( $args['extensions'] ?? null ) ? $args['extensions'] : array(), (int) ( $args['cursor'] ?? 0 ), (int) ( $args['max_results'] ?? 100 ) ); break;
				case 'get_php_log': $result = WPAIB_Files::read_file( (string) ( $args['path'] ?? 'wp-content/debug.log' ), (int) ( $args['offset'] ?? 0 ), max( 1, min( 1048576, (int) ( $args['length'] ?? 131072 ) ) ) ); break;
				case 'write_file': $result = WPAIB_Files::write_file( (string) ( $args['path'] ?? '' ), (string) ( $args['content_b64'] ?? '' ), array_key_exists( 'expected_sha256', $args ) ? ( null === $args['expected_sha256'] ? null : (string) $args['expected_sha256'] ) : null ); break;
				case 'append_file': $result = WPAIB_Files::append_file( (string) ( $args['path'] ?? '' ), (string) ( $args['suffix'] ?? '' ), (string) ( $args['expected_sha256'] ?? '' ) ); break;
				case 'truncate_file': $result = WPAIB_Files::truncate_file( (string) ( $args['path'] ?? '' ), (string) ( $args['expected_sha256'] ?? '' ) ); break;
				case 'validate_file': $result = WPAIB_Files::validate_file( (string) ( $args['path'] ?? '' ), (string) ( $args['format'] ?? '' ) ); break;
				case 'delete_file': $result = WPAIB_Files::delete_file( (string) ( $args['path'] ?? '' ), (string) ( $args['expected_sha256'] ?? '' ) ); break;
				case 'restore_file': $result = WPAIB_Files::restore( (string) ( $args['backup_id'] ?? '' ), isset( $args['expected_current_sha256'] ) && null !== $args['expected_current_sha256'] ? (string) $args['expected_current_sha256'] : null ); break;
				case 'list_plugins': $result = WPAIB_Site::plugins(); break;
				case 'set_plugin_state': $result = WPAIB_Site::set_plugin_state( (string) ( $args['plugin'] ?? '' ), (string) ( $args['action'] ?? '' ) ); break;
				case 'list_themes': $result = WPAIB_Site::themes(); break;
				case 'switch_theme': $result = WPAIB_Site::switch_theme( (string) ( $args['stylesheet'] ?? '' ) ); break;
				case 'list_content': $result = WPAIB_Site::list_content( $args ); break;
				case 'get_content': $result = WPAIB_Site::get_content( (int) ( $args['id'] ?? 0 ) ); break;
				case 'update_content': $result = WPAIB_Site::update_content( $args ); break;
				case 'list_taxonomies': $result = WPAIB_Enterprise::taxonomies( $args ); break;
				case 'list_terms': $result = WPAIB_Enterprise::terms( $args ); break;
				case 'upsert_term': $result = WPAIB_Enterprise::upsert_term( $args ); break;
				case 'assign_terms': $result = WPAIB_Enterprise::assign_terms( $args ); break;
				case 'get_object_meta': $result = WPAIB_Enterprise::get_object_meta( $args ); break;
				case 'update_object_meta': $result = WPAIB_Enterprise::update_object_meta( $args ); break;
				case 'list_media': $result = WPAIB_Enterprise::list_media( $args ); break;
				case 'get_media': $result = WPAIB_Enterprise::get_media( $args ); break;
				case 'update_media': $result = WPAIB_Enterprise::update_media( $args ); break;
				case 'list_products': $result = WPAIB_Enterprise::list_products( $args ); break;
				case 'get_product': $result = WPAIB_Enterprise::get_product( $args ); break;
				case 'update_product': $result = WPAIB_Enterprise::update_product( $args ); break;
				case 'list_orders': $result = WPAIB_Enterprise::list_orders( $args ); break;
				case 'commerce_summary': $result = WPAIB_Enterprise::commerce_summary( $args ); break;
				case 'search_console_status': $result = WPAIB_Enterprise::search_console_status(); break;
				case 'search_console_sites': $result = WPAIB_Enterprise::search_console_sites(); break;
				case 'search_console_search_analytics': $result = WPAIB_Enterprise::search_console_search_analytics( $args ); break;
				case 'search_console_sitemaps': $result = WPAIB_Enterprise::search_console_sitemaps( $args ); break;
				case 'search_console_url_inspection': $result = WPAIB_Enterprise::search_console_url_inspection( $args ); break;
				case 'search_console_request_indexing': $result = WPAIB_Enterprise::search_console_request_indexing( $args ); break;
				case 'wordpress_content_transaction': $result = PRSTUDIO_UC_Content_Transaction::patch( $args ); break;
				case 'patch_file':
					$result = WPAIB_Files::patch_exact(
						(string) ( $args['path'] ?? '' ),
						(string) ( $args['expected_sha256'] ?? '' ),
						(string) ( $args['search'] ?? '' ),
						(string) ( $args['replace'] ?? '' ),
						(int) ( $args['expected_replacements'] ?? 1 ),
						(string) ( $args['search_sha256'] ?? '' ),
						is_array( $args['health_checks'] ?? null ) ? $args['health_checks'] : array()
					);
					break;
				case 'purge_cache':
					$operation = sanitize_key( (string) ( $args['operation'] ?? 'flush_all' ) );
					$cache_action = in_array( $operation, array( 'flush_object_cache','flush_page_cache','flush_cdn_cache' ), true ) ? $operation : 'flush_all';
					$result = WPAIB_REST::execute_control_action( '/cache-manage', $cache_action, $args, 'legacy_fast_inline' );
					break;
				case 'rank_math_redirect_list': $args['action'] = 'list'; $result = WPAIB_Enterprise::rank_math_redirects( $args ); break;
				case 'rank_math_redirect_upsert': $args['action'] = ! empty( $args['id'] ) ? 'update' : 'create'; $result = WPAIB_Enterprise::rank_math_redirects( $args ); break;
				case 'rank_math_redirect_delete': $args['action'] = 'delete'; $result = WPAIB_Enterprise::rank_math_redirects( $args ); break;
				case 'rank_math_sitemap_invalidate': $result = WPAIB_Enterprise::rank_math_sitemap_invalidate( $args ); break;
				case 'fetch_page_html': $result = WPAIB_Site::fetch_page( (string) ( $args['url_or_path'] ?? '/' ) ); break;
				case 'rpconnector_capability_search': $result = self::capability_search( $args ); break;
				case 'rpconnector_route_index': $result = self::route_index( $args ); break;
				case 'rpconnector_action_call': $result = self::action_call( $args ); break;
				default:
					$route_tools = self::route_tool_names();
					if ( isset( $route_tools[ $name ] ) ) {
						$args['route'] = $route_tools[ $name ];
						unset( $args['tool_name'] );
						$result = self::action_call( $args );
						break;
					}
					$result = self::dispatch_enterprise_tool( $name, $args );
					break;
			}
		} catch ( Throwable $e ) {
			WPAIB_Audit::log( 'mcp.tool_exception', 'error', $name, array( 'message' => self::safe_exception_message( $e ) ) );
			if(!$fast_path&&class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('mcp.tool.failed',['resource'=>$name,'outcome'=>'mcp_tool_exception','method'=>'runtime_exception'],$memory_job);
			if(!$fast_path&&class_exists('PRSTUDIO_UC_Observability'))PRSTUDIO_UC_Observability::finish($mcp_span,'exception');
			return self::tool_result_response( $id, array( 'error' => 'mcp_tool_exception', 'message' => 'Errore interno durante l’esecuzione del tool.' ), true, $orchestration_meta );
		}

		$result = self::unwrap_rest_result( $result );
		if(!$fast_path&&class_exists('PRSTUDIO_UC_Memory')){if(is_wp_error($result)){PRSTUDIO_UC_Memory::movement('mcp.tool.failed',['resource'=>$name,'outcome'=>$result->get_error_code(),'executor'=>$governance['executor']??'','strategy'=>$governance['strategy']??''],$memory_job);}else{PRSTUDIO_UC_Memory::remember_call($governance,$args,$result,$memory_job);PRSTUDIO_UC_Memory::movement('mcp.tool.completed',['resource'=>$name,'outcome'=>'completed','executor'=>$governance['executor']??'','strategy'=>$governance['strategy']??''],$memory_job);}} if(!$fast_path&&class_exists('PRSTUDIO_UC_Observability'))PRSTUDIO_UC_Observability::finish($mcp_span,is_wp_error($result)?'error':'ok');
		if ( is_wp_error( $result ) ) {
			return self::tool_result_response( $id, array(
				'error' => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data' => $result->get_error_data(),
			), true, $orchestration_meta );
		}
		return self::tool_result_response( $id, $result, false, $orchestration_meta );
	}

	private static function is_list_array( array $value ): bool {
		$index = 0;
		foreach ( $value as $key => $_item ) {
			if ( $key !== $index ) {
				return false;
			}
			++$index;
		}
		return true;
	}

	private static function normalize_structured_content( $data ) {
		if ( is_array( $data ) && self::is_list_array( $data ) ) {
			return array( 'items' => $data, 'count' => count( $data ) );
		}
		if ( is_object( $data ) ) {
			return get_object_vars( $data );
		}
		if ( ! is_array( $data ) ) {
			return array( 'value' => $data );
		}
		return $data;
	}

	private static function tool_auth_error_response( $id, WP_Error $error ): WP_REST_Response {
		$description = $error->get_error_message();
		$challenge = WPAIB_REST::www_authenticate_challenge( 'invalid_token', $description );
		$response = self::tool_result_response( $id, array( 'error' => $error->get_error_code(), 'message' => $description ), true, array( 'mcp/www_authenticate' => array( $challenge ) ) );
		$response->header( 'WWW-Authenticate', $challenge );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function tool_result_response( $id, $data, bool $is_error, array $meta = array() ): WP_REST_Response {
		$data = self::normalize_structured_content( $data );
		$text = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $text ) {
			WPAIB_Audit::log( 'mcp.result_encode_failed', 'error', 'tools/call' );
			$data = array( 'error' => 'mcp_result_encode_failed', 'message' => 'Il risultato non è serializzabile in JSON.' );
			$text = '{"error":"mcp_result_encode_failed"}';
			$is_error = true;
		}
		$result = array(
			'content' => array( array( 'type' => 'text', 'text' => $text ) ),
			'structuredContent' => $data,
			'isError' => $is_error,
		);
		if ( $meta ) {
			$result['_meta'] = $meta;
		}
		return self::result_response( $id, $result );
	}

	private static function result_response( $id, $result ): WP_REST_Response {
		$response = new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function error_response( $id, int $code, string $message, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) ), $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
