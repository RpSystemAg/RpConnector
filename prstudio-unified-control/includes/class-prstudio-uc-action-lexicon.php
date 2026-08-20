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

		'create'                         => 'crea creare creo aggiungi aggiungere inserisci inserire nuovo nuova nuovi nuove add new insert',
		'update edit modify'             => 'aggiorna aggiornare aggiorno modifica modificare modifico cambia cambiare cambio amend',
		'delete remove'                  => 'elimina eliminare elimino cancella cancellare cancello rimuovi rimuovere rimuovo togli togliere destroy drop',
		'list show'                      => 'elenca elencare elenco lista liste mostra mostrare mostrami visualizza visualizzare vedi vedere guarda guardare display browse',
		'get read'                       => 'leggi leggere ottieni ottenere prendi prendere recupera recuperare dammi fetch retrieve',
		'check inspect status list'      => 'controlla controllare controllo ispeziona ispezionare',
		'verify validate'                => 'verifica verificare verifico valida validare convalida convalidare',
		'audit'                          => 'traccia tracciare tracciamento auditing',
		'search find'                    => 'cerca cercare cerco trova trovare trovo ricerca ricercare lookup locate',
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
		'run execute'                    => 'esegui eseguire eseguo lancia lanciare avvia avviare launch start perform',
		'stop cancel'                    => 'ferma fermare arresta arrestare annulla annullare interrompi interrompere abort',
		'generate'                       => 'genera generare genero produce',
		'regenerate rebuild'             => 'rigenera rigenerare ricostruisci ricostruire',
		'manage administer'              => 'gestisci gestire gestione amministra amministrare',
		'clone duplicate copy'           => 'clona clonare duplica duplicare copia copiare',
		'move'                           => 'sposta spostare relocate',
		'rename'                         => 'rinomina rinominare',
		'replace'                        => 'sostituisci sostituire rimpiazza rimpiazzare swap',
		'merge'                          => 'unisci unire fondi fondere combine',
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
		'set configure'                  => 'imposta impostare configura configurare configuro setup',
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
		'click'                          => 'clicca cliccare clicco premi premere tap press',
		'type write'                     => 'scrivi scrivere digita digitare',
		'navigate open goto'             => 'naviga navigare vai andare apri aprire visit',
		'scroll'                         => 'scorri scorrere',
		'close'                          => 'chiudi chiudere',
		'record video'                   => 'registra registrare registrazione filmato',
		'wait'                           => 'aspetta aspettare attendi attendere',

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
		'database'                       => 'db banca-dati',
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
		'refunds'                        => 'rimborso rimborsi',
		'seo'                            => 'posizionamento indicizzazione',
		'sitemap'                        => 'mappa-del-sito',
		'redirect redirects'             => 'reindirizzamento reindirizzamenti',
		'link links'                     => 'collegamento collegamenti',
		'keyword keywords'               => 'parola-chiave parole-chiave',
		'schema'                         => 'schemi markup',
		'robots'                         => 'robot',
		'site website'                   => 'sito siti',
		'frontend'                       => 'front-end vetrina',
		'browser'                        => 'navigatore chrome chromium',
		'console'                        => 'consolle',
		'network'                        => 'rete',
		'dom'                            => 'dom',
		'css styles style'               => 'stile stili foglio-di-stile',
		'javascript'                     => 'js',
		'font fonts'                     => 'carattere caratteri tipografia',
		'logo logos'                     => 'loghi',
		'design'                         => 'grafica aspetto',
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
		'snapshot'                       => 'istantanea-stato',
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

	/** @var array<string,int>|null word => position of the concept it means */
	private static ?array $word_to_concept = null;

	/** @var array<int,array<int,string>>|null concept position => catalog tokens */
	private static ?array $concept_tokens = null;

	/** @var array<string,bool>|null */
	private static ?array $stop_words = null;

	/**
	 * Build the reverse lookup once.
	 *
	 * A word listed under two concepts keeps its first binding rather than
	 * silently taking the last: a duplicate is a mistake in the table above, and
	 * the meaning written first is the one that was chosen deliberately.
	 */
	private static function boot(): void {
		if ( is_array( self::$word_to_concept ) ) {
			return;
		}
		self::$word_to_concept = array();
		self::$concept_tokens  = array();
		$position              = 0;
		foreach ( self::CONCEPTS as $catalog_tokens => $surface_words ) {
			$tokens = array_values( array_filter( explode( ' ', (string) $catalog_tokens ) ) );
			if ( array() === $tokens ) {
				continue;
			}
			self::$concept_tokens[ $position ] = $tokens;
			foreach ( array_merge( $tokens, explode( ' ', (string) $surface_words ) ) as $word ) {
				$word = trim( $word );
				if ( '' === $word || isset( self::$word_to_concept[ $word ] ) ) {
					continue;
				}
				self::$word_to_concept[ $word ] = $position;
			}
			++$position;
		}
		self::$stop_words = array_fill_keys( array_filter( explode( ' ', self::STOP_WORDS ) ), true );
	}

	/**
	 * Whether a word carries no intent.
	 *
	 * @param string $word Already normalised.
	 * @return bool
	 */
	public static function is_stop_word( string $word ): bool {
		self::boot();
		return isset( self::$stop_words[ $word ] );
	}

	/**
	 * Reduce a query's words to the concepts they mean.
	 *
	 * Each returned entry is one unit of intent holding every catalog token that
	 * satisfies it. A word with no row in the table becomes a concept of its own
	 * -- product names, plugin slugs and anything this vocabulary has not
	 * learned still reach the catalog on their own spelling, with a plural fold
	 * so "widgets" finds "widget" without needing a row.
	 *
	 * The result is ordered and deduplicated, so two phrasings that mean the
	 * same thing produce the identical structure. That is what makes Italian and
	 * English rank the same rather than merely rank similarly.
	 *
	 * @param array<int,string> $words Normalised query words.
	 * @return array<int,array<int,string>> Concepts, each a list of catalog tokens.
	 */
	public static function concepts( array $words ): array {
		self::boot();
		$seen     = array();
		$concepts = array();
		foreach ( $words as $word ) {
			$word = trim( (string) $word );
			if ( '' === $word || self::is_stop_word( $word ) ) {
				continue;
			}
			if ( isset( self::$word_to_concept[ $word ] ) ) {
				$position = self::$word_to_concept[ $word ];
				$key      = 'c' . $position;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$concepts[]   = self::$concept_tokens[ $position ];
				continue;
			}
			$tokens = self::plural_forms( $word );
			$key    = 'w' . $tokens[0];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$concepts[]   = $tokens;
		}
		return $concepts;
	}

	/**
	 * Singular and plural spellings of a word this vocabulary does not know.
	 *
	 * Only the English -s is folded. Italian plurals change the final vowel
	 * ("ordine"/"ordini", "pagina"/"pagine") and folding that mechanically
	 * collides with unrelated words, so Italian plurals are listed explicitly in
	 * the table above rather than guessed at here.
	 *
	 * @param string $word Normalised word.
	 * @return array<int,string>
	 */
	private static function plural_forms( string $word ): array {
		if ( mb_strlen( $word ) < 4 ) {
			return array( $word );
		}
		if ( str_ends_with( $word, 's' ) ) {
			return array( $word, mb_substr( $word, 0, -1 ) );
		}
		return array( $word, $word . 's' );
	}

	/**
	 * Every catalog token the query could match, flattened.
	 *
	 * Used to pull candidate rows out of the posting lists before scoring.
	 *
	 * @param array<int,array<int,string>> $concepts From concepts().
	 * @return array<int,string>
	 */
	public static function catalog_tokens( array $concepts ): array {
		$flat = array();
		foreach ( $concepts as $tokens ) {
			foreach ( $tokens as $token ) {
				$flat[ $token ] = true;
			}
		}
		return array_keys( $flat );
	}
}
