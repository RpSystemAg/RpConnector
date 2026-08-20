<?php
/**
 * The vocabulary that lets one person's words reach the catalog.
 *
 * WHY THIS EXISTS
 * ---------------
 * The capability catalog is written in English: identifiers like
 * `legacy.catalog-commerce.inventory-manage.export` and descriptions built from
 * English verbs. The people operating this suite write Italian. Search matched
 * query words against that English text directly, so an Italian sentence had
 * nothing to hit -- "carica una immagine", "gestisci le spedizioni" and
 * "aggiungi un coupon" each returned literally nothing, while "attiva il tema"
 * returned the SEO autopilot.
 *
 * That is not a ranking problem. Roughly 25 tools fit under the LAW 9 token
 * ceiling and the other ~1270 capabilities are reachable only through
 * `prstudio_capability_search`, so for almost everything this product can do,
 * search is not a convenience -- it is the only door. A door that does not open
 * for the language the operator speaks is a closed door, and LAW 13 says human
 * intent must resolve to action.
 *
 * HOW IT WORKS
 * ------------
 * Words are grouped into concepts. A concept is one meaning, and it holds every
 * surface form of that meaning in BOTH languages together with the English
 * tokens the catalog is actually indexed under:
 *
 *     'delete remove' => 'elimina eliminare cancella cancellare delete remove'
 *
 * The key is what to look for in the catalog. The value is what a person might
 * type. Because Italian and English words for the same meaning live in the same
 * concept, "elimina un file" and "delete a file" reduce to the same concept set
 * before anything is scored -- so they return the same capabilities in the same
 * order, not by coincidence but by construction. That is the property
 * tests/php-capability-search-relevance-smoke.php asserts on whole result
 * lists rather than on the first row alone.
 *
 * WHY CONCEPTS RATHER THAN A TRANSLATION TABLE
 * --------------------------------------------
 * A plain italian->english table breaks the moment one word carries a meaning
 * the other language splits, and it makes the two directions drift apart: you
 * fix the Italian side and the English side quietly stops agreeing. Grouping by
 * meaning removes the direction entirely. There is no "source" language here.
 *
 * A word may name several catalog tokens -- "controlla" and "check" both reach
 * `check`, `inspect`, `status` and `list`, because someone asking to check the
 * orders wants to see them. Those tokens stay inside ONE concept, so the phrase
 * still counts as a single unit of intent and coverage scoring is not distorted.
 *
 * EXTENDING IT
 * ------------
 * Add the missing word to the concept that already carries its meaning, or add
 * a new row when the meaning itself is new. Both languages go in the same
 * string. Include the plural and the infinitive -- "spedizione spedizioni",
 * "elimina eliminare" -- because matching is exact for known vocabulary.
 * Accents are folded away before lookup, so "velocita" is the spelling to add,
 * not "velocità".
 *
 * Nothing here decides what runs. It only decides what the operator is able to
 * find, and that is deliberately the widest part of the funnel: the anti-crash
 * gate remains the only thing standing between a found capability and a
 * mutation (LAW 1).
 */

defined( 'ABSPATH' ) || defined( 'PRSTUDIO_UC_TESTING' ) || exit;

final class PRSTUDIO_UC_Action_Lexicon {

	/**
	 * Words that carry no intent, in either language.
	 *
	 * They are dropped before anything is scored. Left in, they are actively
	 * harmful rather than merely useless: "del" and "il" used to score against
	 * `delete` and `profile`, which is how "aggiorna il prezzo del prodotto"
	 * came back with file deletion.
	 */
	private const STOP_WORDS = 'a ad agli ai al all alla alle allo an and che chi come con cosa da dai dal dall dalla dalle dallo de degli dei del dell della delle dello di do does e ed gli ha hai hanno ho i il in into is it la le lo mi mia mio my nei nel nell nella nelle nello non of on or per please posso puoi qual quale quali quanto questa queste questi questo si sia sono su sui sul sull sulla sulle sullo the their them there this to tra tu tua tuo un una uno vorrei voglio want with would you your';

	/**
	 * One row per meaning.
	 *
	 * Key   -- the English tokens the catalog is indexed under, space separated.
	 * Value -- every word a person might type for that meaning, both languages,
	 *          space separated, accents already folded.
	 */
	private const CONCEPTS = array(

		/* -- What to do ---------------------------------------------------- */

		'create add insert new'          => 'crea creare creo aggiungi aggiungere inserisci inserire nuovo nuova nuovi nuove',
		'update edit modify set change configure' => 'aggiorna aggiornare aggiorno modifica modificare modifico cambia cambiare cambio imposta impostare configura configurare configuro amend setup',
		'delete remove destroy drop'     => 'elimina eliminare elimino cancella cancellare cancello rimuovi rimuovere rimuovo togli togliere',
		'list show display browse'       => 'elenca elencare elenco lista liste mostra mostrare mostrami visualizza visualizzare vedi vedere guarda guardare',
		'get read fetch retrieve'        => 'leggi leggere ottieni ottenere prendi prendere recupera recuperare dammi',
		'check inspect status list'      => 'controlla controllare controllo ispeziona ispezionare',
		'verify validate'                => 'verifica verificare verifico valida validare convalida convalidare',
		'audit'                          => 'traccia tracciare tracciamento auditing',
		'search find lookup locate'      => 'cerca cercare cerco trova trovare trovo ricerca ricercare',
		'publish'                        => 'pubblica pubblicare pubblico',
		'unpublish draft'                => 'spubblica spubblicare bozza bozze',
		'upload'                         => 'carica caricare carico',
		'download'                       => 'scarica scaricare scarico',
		'import'                         => 'importa importare importo',
		'export'                         => 'esporta esportare esporto',
		'backup'                         => 'salvataggio salvataggi',
		'restore rollback'               => 'ripristina ripristinare ripristino',
		'optimize'                       => 'ottimizza ottimizzare ottimizzo',
		'install'                        => 'installa installare installo',
		'uninstall'                      => 'disinstalla disinstallare',
		'activate enable'                => 'attiva attivare attivo abilita abilitare',
		'deactivate disable'             => 'disattiva disattivare disabilita disabilitare',
		'schedule'                       => 'pianifica pianificare programma programmare schedula schedulare',
		'run execute launch start perform' => 'esegui eseguire eseguo lancia lanciare avvia avviare',
		'stop cancel abort'              => 'ferma fermare arresta arrestare annulla annullare interrompi interrompere',
		'generate produce'               => 'genera generare genero',
		'regenerate rebuild'             => 'rigenera rigenerare ricostruisci ricostruire',
		'manage administer'              => 'gestisci gestire gestione amministra amministrare',
		'clone duplicate copy'           => 'clona clonare duplica duplicare copia copiare',
		'move relocate'                  => 'sposta spostare',
		'rename'                         => 'rinomina rinominare',
		'replace swap'                   => 'sostituisci sostituire rimpiazza rimpiazzare',
		'merge combine'                  => 'unisci unire fondi fondere',
		'split'                          => 'dividi dividere separa separare',
		'assign'                         => 'assegna assegnare attribuisci attribuire',
		'reorder sort'                   => 'riordina riordinare ordina ordinare',
		'refund'                         => 'rimborsa rimborsare',
		'approve'                        => 'approva approvare approvo',
		'reject spam'                    => 'rifiuta rifiutare respingi respingere',
		'trash'                          => 'cestina cestinare cestino',
		'purge flush clear'              => 'svuota svuotare svuoto pulisci pulire pulisco empty',
		'sync'                           => 'sincronizza sincronizzare synchronize allinea allineare',
		'migrate'                        => 'migra migrare migrazione',
		'lock'                           => 'blocca bloccare blocco freeze',
		'unlock'                         => 'sblocca sbloccare unfreeze',
		'reset'                          => 'azzera azzerare resetta resettare',
		'test'                           => 'testa testare prova provare',
		'debug'                          => 'debugga debuggare diagnostica diagnosticare',
		'monitor watch'                  => 'monitora monitorare sorveglia sorvegliare',
		'send notify'                    => 'invia inviare manda mandare spedisci notifica notificare email',
		'crawl scan'                     => 'scansiona scansionare scansione spider',
		'compare diff'                   => 'confronta confrontare paragona paragonare',
		'count'                          => 'conta contare conteggio',
		'repair remediate fix'           => 'ripara riparare aggiusta aggiustare',
		'harden'                         => 'irrobustisci irrobustire rafforza rafforzare',
		'preview'                        => 'anteprima anteprime',
		'revise'                         => 'revisiona revisionare',
		'customize'                      => 'personalizza personalizzare',

		/* -- Browser actions ----------------------------------------------- */

		'screenshot'                     => 'schermata schermate istantanea',
		'click tap press'                => 'clicca cliccare clicco premi premere',
		'type write'                     => 'scrivi scrivere digita digitare',
		'navigate open goto visit'       => 'naviga navigare vai andare apri aprire',
		'scroll'                         => 'scorri scorrere',
		'close'                          => 'chiudi chiudere',
		'record video'                   => 'registra registrare registrazione filmato',
		'wait'                           => 'aspetta aspettare attendi attendere',
		'crop'                           => 'ritaglia ritagliare ritaglio',
		'resize scale'                   => 'ridimensiona ridimensionare scala scalare',
		'rotate'                         => 'ruota ruotare rotazione',

		/* -- What to act on ------------------------------------------------ */

		'product products'               => 'prodotto prodotti',
		'order orders'                   => 'ordine ordini',
		'customer customers'             => 'cliente clienti acquirente acquirenti',
		'user users'                     => 'utente utenti',
		'account accounts'               => 'profilo profili',
		'page pages'                     => 'pagina pagine',
		// One concept, not two. In WordPress an "articolo" is a post, and the
		// catalog files the act of publishing one under `content-manage`, not
		// under any token containing "post". Split apart, "pubblica un
		// articolo" reached convert-post-type while "publish content" reached
		// publish -- the same request, two answers, one of them wrong.
		'content post posts'             => 'articolo articoli contenuto contenuti testo testi article articles',
		'data'                           => 'dato dati',
		'media image images'             => 'immagine immagini foto fotografia fotografie picture pictures',
		'video'                          => 'filmati',
		'file files'                     => 'documento documenti',
		'directory folder'               => 'cartella cartelle folders',
		'database'                       => 'db',
		'table tables'                   => 'tabella tabelle',
		'query'                          => 'interrogazione interrogazioni',
		'cache'                          => 'cache',
		'plugin plugins'                 => 'estensione estensioni extension extensions',
		'theme themes'                   => 'tema temi',
		'template templates'             => 'modello modelli',
		'menu menus'                     => 'menu',
		'widget widgets'                 => 'widget',
		'block blocks'                   => 'blocco blocchi',
		'comment comments'               => 'commento commenti',
		'category categories'            => 'categoria categorie',
		'tag tags'                       => 'etichetta etichette',
		'taxonomy taxonomies'            => 'tassonomia tassonomie',
		'term terms'                     => 'termine termini',
		'tax taxes vat'                  => 'tassa tasse iva imposta imposte fiscale fiscalita',
		'invoice invoices billing'       => 'fattura fatture fatturazione',
		'attribute attributes'           => 'attributo attributi caratteristica caratteristiche',
		'attachment attachments'         => 'allegato allegati',
		'gallery galleries'              => 'galleria gallerie',
		'settings options'               => 'impostazione impostazioni configurazione configurazioni preferenze option',
		'permissions'                    => 'permesso permessi autorizzazione autorizzazioni permission',
		'role roles'                     => 'ruolo ruoli',
		'security'                       => 'sicurezza sicuro',
		'identity'                       => 'identita',
		'credentials'                    => 'credenziale credenziali password credential',
		'token tokens'                   => 'gettone',
		'session sessions'               => 'sessione sessioni',
		'logs log'                       => 'registro registri giornale',
		'inventory stock'                => 'magazzino inventario scorte giacenza giacenze',
		'shipping'                       => 'spedizione spedizioni consegna consegne delivery',
		'coupons coupon'                 => 'buono buoni sconto sconti discount promozione promozioni',
		'price prices'                   => 'prezzo prezzi costo costi',
		'reviews review'                 => 'recensione recensioni',
		'payment payments'               => 'pagamento pagamenti',
		'cart carts basket baskets'      => 'carrello carrelli',
		'refunds'                        => 'rimborso rimborsi',
		'seo'                            => 'posizionamento indicizzazione',
		'sitemap'                        => 'sitemap',
		'redirect redirects'             => 'reindirizzamento reindirizzamenti',
		'link links'                     => 'collegamento collegamenti',
		'keyword keywords'               => 'keyword keywords',
		'schema'                         => 'schemi markup',
		'robots'                         => 'robot',
		'site website'                   => 'sito siti',
		'frontend'                       => 'vetrina',
		'browser'                        => 'navigatore chrome chromium',
		'console'                        => 'consolle',
		'network'                        => 'rete',
		'dom'                            => 'dom',
		'css styles style'               => 'stile stili',
		'javascript'                     => 'js',
		'font fonts'                     => 'carattere caratteri tipografia',
		'logo logos'                     => 'loghi',
		'design'                         => 'grafica aspetto',
		'header headers'                 => 'header intestazione intestazioni testata testate',
		'footer footers'                 => 'footer pie fondo',
		'language languages locale locales' => 'lingua lingue idioma idiomi localizzazione',
		'translation translations translate' => 'traduzione traduzioni traduci tradurre',
		'currency currencies'            => 'valuta valute moneta monete',
		'field fields input inputs'      => 'campo campi input',
		'button buttons'                 => 'pulsante pulsanti bottone bottoni',
		'tab tabs'                       => 'scheda schede linguetta linguette',
		'revision revisions'             => 'revisione revisioni',
		'metadata meta'                  => 'metadato metadati',
		'cron'                           => 'cron',
		'job jobs'                       => 'lavoro lavori processo processi task',
		'version'                        => 'versione versioni',
		'maintenance'                    => 'manutenzione',
		'performance speed'              => 'prestazioni velocita rapidita',
		'traffic'                        => 'traffico visite',
		'report'                         => 'rapporto rapporti resoconto',
		'story stories'                  => 'storia storie',
		'variation variations'           => 'variazione variazioni variante varianti',
		'note notes'                     => 'nota',
		'snapshot'                       => 'snapshot',
		'integrity'                      => 'integrita',
		'state status'                   => 'stato stati',
		'bulk'                           => 'massa massivo massiva multiplo multipla',
		'global'                         => 'globale globali',
		'local'                          => 'locale locali',
		'root'                           => 'radice',
		'wordpress'                      => 'wp',
		'woocommerce commerce ecommerce' => 'woo e-commerce negozio shop store commercio',
		'google'                         => 'google',
	);

	/** Multi-word surface forms, matched before individual words are reduced. */
	private const PHRASES = array(
		'banca dati'              => 'database',
		'mappa del sito'          => 'sitemap',
		'mappe del sito'          => 'sitemap',
		'parola chiave'           => 'keyword keywords',
		'parole chiave'           => 'keyword keywords',
		'foglio di stile'         => 'css styles style',
		'fogli di stile'          => 'css styles style',
		'front end'               => 'frontend',
		'istantanea di stato'     => 'snapshot',
		'istantanea stato'        => 'snapshot',
		'pie di pagina'           => 'footer footers',
		'intestazione di pagina'  => 'header headers',
		'campo di input'          => 'field fields input inputs',
		'campi di input'          => 'field fields input inputs',
		'carrello della spesa'    => 'cart carts basket baskets',
		'tasso di cambio'         => 'currency currencies',
	);

	/** @var array<string,int>|null word => position of the concept it means */
	private static ?array $word_to_concept = null;

	/** @var array<int,array<int,string>>|null concept position => catalog tokens */
	private static ?array $concept_tokens = null;

	/** @var array<string,int>|null normalised phrase => concept position */
	private static ?array $phrase_to_concept = null;

	/** @var array<string,string>|null token signature => stable concept key */
	private static ?array $signature_to_key = null;

	/** @var array<string,bool>|null */
	private static ?array $stop_words = null;

	/** Normalise human text and technical identifiers identically everywhere. */
	public static function normalize_text( string $value ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		} elseif ( function_exists( 'iconv' ) ) {
			$ascii = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
			if ( false !== $ascii ) {
				$value = $ascii;
			}
		}
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = strtr( $value, array(
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
			'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
			'À' => 'a', 'Á' => 'a', 'È' => 'e', 'É' => 'e', 'Ì' => 'i', 'Í' => 'i', 'Ò' => 'o', 'Ó' => 'o', 'Ù' => 'u', 'Ú' => 'u',
		) );
		$value = str_replace( array( '_', '/', '\\', '-' ), ' ', $value );
		$value = (string) preg_replace( '/[^a-z0-9]+/', ' ', $value );
		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}

	/** @return array<int,string> */
	private static function words( string $value ): array {
		$seen = array();
		foreach ( explode( ' ', self::normalize_text( $value ) ) as $word ) {
			if ( strlen( $word ) >= 2 ) {
				$seen[ $word ] = true;
			}
		}
		return array_keys( $seen );
	}

	/** @param array<int,string> $tokens */
	private static function token_signature( array $tokens ): string {
		$tokens = array_values( array_unique( array_filter( array_map( 'strval', $tokens ) ) ) );
		sort( $tokens, SORT_STRING );
		return implode( '|', $tokens );
	}

	/** Build all reverse lookups once. First binding wins for ambiguous words. */
	private static function boot(): void {
		if ( is_array( self::$word_to_concept ) ) {
			return;
		}
		self::$word_to_concept = array();
		self::$concept_tokens  = array();
		self::$phrase_to_concept = array();
		self::$signature_to_key = array();
		$catalog_positions = array();
		$position = 0;
		foreach ( self::CONCEPTS as $catalog_key => $surface_words ) {
			$tokens = array_values( array_filter( explode( ' ', self::normalize_text( (string) $catalog_key ) ) ) );
			if ( array() === $tokens ) {
				continue;
			}
			self::$concept_tokens[ $position ] = $tokens;
			$catalog_positions[ (string) $catalog_key ] = $position;
			self::$signature_to_key[ self::token_signature( $tokens ) ] = 'concept:' . str_replace( ' ', '_', (string) $catalog_key );
			foreach ( array_merge( $tokens, explode( ' ', (string) $surface_words ) ) as $surface_word ) {
				$word = self::normalize_text( (string) $surface_word );
				if ( '' === $word ) {
					continue;
				}
				if ( str_contains( $word, ' ' ) ) {
					if ( ! isset( self::$phrase_to_concept[ $word ] ) ) {
						self::$phrase_to_concept[ $word ] = $position;
					}
					continue;
				}
				if ( ! isset( self::$word_to_concept[ $word ] ) ) {
					self::$word_to_concept[ $word ] = $position;
				}
			}
			++$position;
		}
		foreach ( self::PHRASES as $phrase => $catalog_key ) {
			$phrase = self::normalize_text( (string) $phrase );
			if ( '' !== $phrase && isset( $catalog_positions[ $catalog_key ] ) && ! isset( self::$phrase_to_concept[ $phrase ] ) ) {
				self::$phrase_to_concept[ $phrase ] = $catalog_positions[ $catalog_key ];
			}
		}
		uksort( self::$phrase_to_concept, static function ( string $left, string $right ): int {
			$length = strlen( $right ) <=> strlen( $left );
			return 0 !== $length ? $length : strcmp( $left, $right );
		} );
		self::$stop_words = array_fill_keys( self::words( self::STOP_WORDS ), true );
	}

	/** Whether a word carries no intent. */
	public static function is_stop_word( string $word ): bool {
		self::boot();
		$word = self::normalize_text( $word );
		return '' !== $word && isset( self::$stop_words[ $word ] );
	}

	/**
	 * Legacy word-list entry point retained for existing consumers.
	 *
	 * @param array<int,string> $words
	 * @return array<int,array<int,string>>
	 */
	public static function concepts( array $words ): array {
		self::boot();
		$seen = array();
		$concepts = array();
		foreach ( $words as $raw_word ) {
			foreach ( self::words( (string) $raw_word ) as $word ) {
				if ( self::is_stop_word( $word ) ) {
					continue;
				}
				$tokens = isset( self::$word_to_concept[ $word ] )
					? self::$concept_tokens[ self::$word_to_concept[ $word ] ]
					: self::plural_forms( $word );
				$signature = self::token_signature( $tokens );
				if ( '' === $signature || isset( $seen[ $signature ] ) ) {
					continue;
				}
				$seen[ $signature ] = true;
				$concepts[] = $tokens;
			}
		}
		return $concepts;
	}

	/** Reduce text to ordered, deduplicated units of intent. */
	public static function query_concepts( string $value ): array {
		self::boot();
		$remaining = self::normalize_text( $value );
		if ( '' === $remaining ) {
			return array();
		}
		$concepts = array();
		foreach ( self::$phrase_to_concept as $phrase => $position ) {
			$bounded = ' ' . $remaining . ' ';
			$needle = ' ' . $phrase . ' ';
			if ( false === strpos( $bounded, $needle ) ) {
				continue;
			}
			$concepts[] = self::$concept_tokens[ $position ];
			$remaining = self::normalize_text( str_replace( $needle, ' ', $bounded ) );
		}
		$concepts = array_merge( $concepts, self::concepts( self::words( $remaining ) ) );
		$deduplicated = array();
		$seen = array();
		foreach ( $concepts as $tokens ) {
			$signature = self::token_signature( (array) $tokens );
			if ( '' !== $signature && ! isset( $seen[ $signature ] ) ) {
				$seen[ $signature ] = true;
				$deduplicated[] = array_values( (array) $tokens );
			}
		}
		return $deduplicated;
	}

	/** Stable, order-independent identities for a concept list. */
	public static function concept_keys( array $concepts ): array {
		self::boot();
		$keys = array();
		foreach ( $concepts as $concept ) {
			if ( is_string( $concept ) && ( str_starts_with( $concept, 'concept:' ) || str_starts_with( $concept, 'word:' ) ) ) {
				$keys[ $concept ] = true;
				continue;
			}
			$tokens = is_array( $concept ) ? $concept : array( (string) $concept );
			$signature = self::token_signature( $tokens );
			if ( '' !== $signature ) {
				$keys[ self::$signature_to_key[ $signature ] ?? ( 'word:' . $signature ) ] = true;
			}
		}
		$keys = array_keys( $keys );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/** @param array<int,array<int,string>> $concepts */
	public static function catalog_tokens_for( array $concepts ): array {
		return self::catalog_tokens( $concepts );
	}

	public static function equivalent( array $left, array $right ): bool {
		return self::concept_keys( $left ) === self::concept_keys( $right );
	}

	/** Whether every concept in needle is present in haystack. */
	public static function covers( array $haystack, array $needle ): bool {
		$available = array_fill_keys( self::concept_keys( $haystack ), true );
		foreach ( self::concept_keys( $needle ) as $key ) {
			if ( ! isset( $available[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Singular and plural spellings for an unknown English-looking token. */
	private static function plural_forms( string $word ): array {
		if ( strlen( $word ) < 4 ) {
			return array( $word );
		}
		if ( str_ends_with( $word, 's' ) ) {
			return array( $word, substr( $word, 0, -1 ) );
		}
		return array( $word, $word . 's' );
	}

	/** @param array<int,array<int,string>> $concepts */
	public static function catalog_tokens( array $concepts ): array {
		$flat = array();
		foreach ( $concepts as $tokens ) {
			foreach ( (array) $tokens as $token ) {
				$flat[ (string) $token ] = true;
			}
		}
		return array_keys( $flat );
	}
}
