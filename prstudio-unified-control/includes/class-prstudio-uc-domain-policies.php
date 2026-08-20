<?php
/**
 * Operating methods for the domains where knowing the tool is not enough.
 *
 * WHY THIS EXISTS
 * ---------------
 * The SEO policy established a shape worth reusing: a domain gets a written
 * method, a small set of operating rules, and a PASS/ITERATE quality gate with
 * named dimensions and no invented numeric score. What it does not do is add a
 * tool. The catalogue already contains every action; a policy decides how they
 * are used and what counts as done.
 *
 * Four more domains earn that treatment, chosen by how badly a wrong judgement
 * lands rather than by how many capabilities they hold:
 *
 *   extensions  127 capabilities, 74 of them mutating. The largest blast radius
 *               in the suite. An incompatible activation produces a blank page,
 *               and a blank page is not an error anywhere the API can see it.
 *   data         77 capabilities, 45 mutating. Irreversible by nature: the
 *               question is never "did it work" but "can this be undone".
 *   commerce    143 capabilities across catalogue and orders. The effect is
 *               money and it is public the moment it is written.
 *   browser     137 capabilities. The risk here is not damage, it is false
 *               confidence -- reporting success because nothing complained.
 *
 * WHY NOTHING IS ADDED TO THE HANDSHAKE
 * -------------------------------------
 * The SEO policy carries an activation line in the initialize instructions.
 * These four deliberately do not, and the reason is worth writing down because
 * the obvious move is to copy the pattern four more times.
 *
 * The handshake sits at roughly 6,300 characters against a 6,400 ceiling, and
 * that ceiling exists because every session pays for it whether or not the work
 * ever touches the domain. Four more activation lines would cost about a
 * thousand characters and buy nothing: at initialize there is no objective yet,
 * so the model cannot act on a list of domain names. It can only remember that
 * some exist. runtime_context() attaches the entire method -- rules, gate,
 * guiding question -- at plan time, when the objective is known and the text is
 * about to be used. Naming the domains twice would be paying twice to say less.
 *
 * ACTIVATION IS BILINGUAL, LIKE EVERYTHING ELSE HERE
 * --------------------------------------------------
 * Every trigger row carries both languages. This suite is operated in Italian
 * against a catalogue written in English, and the SEO policy demonstrated what
 * happens when that is left to chance: it fired on 6 of 18 Italian objectives
 * and 7 of 18 English ones, and three equivalent pairs disagreed with each
 * other. A policy that attaches for one language and not the other is two
 * different products wearing the same version number.
 *
 * Text is folded through PRSTUDIO_UC_Action_Lexicon::fold_accents(), so write
 * the unaccented spelling in the tables below -- "velocita", never "velocità".
 *
 * More than one policy may apply at once, and that is correct rather than a
 * conflict: "fai uno screenshot del prezzo del prodotto" is genuinely commerce
 * work carried out through the browser, and both methods have something to say
 * about it.
 */

defined( 'ABSPATH' ) || defined( 'PRSTUDIO_UC_TESTING' ) || exit;

require_once __DIR__ . '/class-prstudio-uc-action-lexicon.php';

final class PRSTUDIO_UC_Domain_Policies {

	public const VERSION = '1.0.0';

	/**
	 * Which contract file backs each policy.
	 *
	 * @var array<string,string>
	 */
	private const CONTRACTS = array(
		'prstudio.extensions-operating-policy' => 'extensions-operating-policy-v1.json',
		'prstudio.data-operating-policy'       => 'data-operating-policy-v1.json',
		'prstudio.commerce-operating-policy'   => 'commerce-operating-policy-v1.json',
		'prstudio.browser-evidence-policy'     => 'browser-evidence-policy-v1.json',
	);

	/**
	 * What each policy answers to, in both languages, on the same row.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const TRIGGERS = array(

		'prstudio.extensions-operating-policy' => array(
			// The things themselves.
			'/\bplugins?\b/', '/\bestension[ei]\b/', '/\bextensions?\b/',
			'/\bthemes?\b/', '/\btem[ai]\b/', '/\bchild theme\b/', '/\btema figlio\b/',
			'/\bmu[- ]plugins?\b/', '/\bmust[- ]use\b/', '/\bdrop[- ]?in\b/',
			// The files people name instead of naming the theme.
			'/\bfunctions\.php\b/', '/\bstyle\.css\b/', '/\bwp[- ]content\b/',
			// The symptom people describe instead of naming the cause.
			// Italian puts a verb between the noun and the adjective -- "il sito
			// e bianco" -- so a two-word pattern misses the way people actually
			// report this, which is the only way most people report it at all.
			'/\bwhite screens?\b/', '/\bblank pages?\b/',
			'/\b(?:sit[oi]|schermat[ae]|schermo|pagin[ae])\s+(?:\w+\s+){0,2}bianc[ahoi]\b/',
			'/\bfatal errors?\b/', '/\berror[ei] fatal[ei]\b/',
			'/\bplugin conflicts?\b/', '/\bconflitt[oi] (?:tra )?plugin\b/',
		),

		'prstudio.data-operating-policy' => array(
			'/\bdatabase\b/', '/\bbanca dati\b/', '/\bdb\b/',
			'/\bsql\b/', '/\bquer(?:y|ies)\b/', '/\binterrogazion[ei]\b/',
			'/\btables?\b/', '/\btabell[ae]\b/',
			'/\bwp_options\b/', '/\btransients?\b/', '/\btransient[ei]\b/',
			'/\bbackups?\b/', '/\bsalvatagg(?:io|i)\b/', '/\bcopi[ae] di sicurezza\b/',
			'/\brestores?\b/', '/\bripristin\w*\b/',
			'/\bmigrations?\b/', '/\bmigrazion[ei]\b/',
			'/\btruncate\b/', '/\bdrop table\b/', '/\bsvuota(?:re)? la tabella\b/',
			'/\bbulk delete\b/', '/\bcancellazione (?:di )?mass[ae]\b/', '/\beliminazione (?:di )?mass[ae]\b/',
			'/\borphan(?:ed)? rows?\b/', '/\brigh[ae] orfan[ae]\b/', '/\bdat[oi] orfan[oi]\b/',
		),

		'prstudio.commerce-operating-policy' => array(
			'/\bwoo(?:commerce)?\b/', '/\becommerce\b/', '/\be-commerce\b/', '/\bnegozio\b/', '/\bshop\b/',
			'/\bproducts?\b/', '/\bprodott[oi]\b/',
			'/\bprices?\b/', '/\bprezz[oi]\b/', '/\bsale price\b/', '/\bprezzo scontato\b/',
			'/\bstock\b/', '/\binventor(?:y|io)\b/', '/\bmagazzino\b/', '/\bgiacenz[ae]\b/', '/\bscorte\b/',
			'/\bcoupons?\b/', '/\bbuon[oi] sconto\b/', '/\bsconti?\b/', '/\bdiscounts?\b/',
			'/\borders?\b/', '/\bordin[ei]\b/',
			'/\brefunds?\b/', '/\brimbors\w*\b/',
			'/\bcustomers?\b/', '/\bclient[ei]\b/', '/\bacquirent[ei]\b/',
			'/\bshipping\b/', '/\bspedizion[ei]\b/', '/\bconsegn[ae]\b/',
			'/\bvat\b/', '/\biva\b/', '/\btax(?:es)?\b/', '/\bimpost[ae]\b/', '/\baliquot[ae]\b/',
			'/\binvoices?\b/', '/\bfattur[ae]\b/',
			'/\bcarts?\b/', '/\bcarrell[oi]\b/', '/\bcheckout\b/', '/\bcass[ae]\b/',
			'/\bvariations?\b/', '/\bvariazion[ei]\b/', '/\bvariant[ei]\b/',
		),

		'prstudio.browser-evidence-policy' => array(
			'/\bbrowsers?\b/', '/\bnavigatore\b/', '/\bchrom(?:e|ium)\b/',
			'/\bclicks?\b/', '/\bclicc\w*\b/', '/\bpremere il pulsante\b/',
			'/\bscreenshots?\b/', '/\bschermat[ae]\b/', '/\bistantane[ae]\b/',
			'/\bnavigat\w*\b/', '/\bnaviga(?:re)?\b/', '/\bscrolls?\b/', '/\bscorrere\b/',
			'/\bpixels?\b/', '/\bvisual(?:ly)?\b/', '/\bvisiv[ao]\b/', '/\bconfronto visivo\b/',
			'/\brender(?:ed|ing)?\b/', '/\brenderizza\w*\b/',
			'/\bselectors?\b/', '/\bselettor[ei]\b/',
			'/\bdevtools?\b/', '/\bconsol(?:e|le)\b/',
			'/\bfront[- ]?end\b/', '/\bstorefront\b/', '/\bvetrina\b/',
			'/\bblock editor\b/', '/\beditor a blocchi\b/', '/\bgutenberg\b/',
		),
	);

	/** @var array<string,array<string,mixed>> */
	private static array $cache = array();

	/**
	 * Where the contracts live.
	 *
	 * @param string $file Contract filename.
	 * @return string
	 */
	private static function contract_path( string $file ): string {
		return dirname( __DIR__ ) . '/contract/' . $file;
	}

	/**
	 * Every policy this class knows about.
	 *
	 * @return array<int,string>
	 */
	public static function ids(): array {
		return array_keys( self::CONTRACTS );
	}

	/**
	 * Read one policy contract.
	 *
	 * A contract that cannot be read returns its identity plus an explicit
	 * error rather than an empty array, so a caller that attaches it to a plan
	 * ships a visible fault instead of a silently method-less plan.
	 *
	 * @param string $id Policy id.
	 * @return array<string,mixed>
	 */
	public static function load( string $id ): array {
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		$file = self::CONTRACTS[ $id ] ?? '';
		if ( '' === $file ) {
			return array( 'id' => $id, '_error' => 'unknown_policy' );
		}
		$path    = self::contract_path( $file );
		$raw     = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		$decoded = '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $decoded ) || $id !== (string) ( $decoded['id'] ?? '' ) ) {
			self::$cache[ $id ] = array(
				'id'      => $id,
				'version' => self::VERSION,
				'_error'  => 'policy_file_unreadable_or_identity_mismatch',
			);
			return self::$cache[ $id ];
		}
		self::$cache[ $id ] = $decoded;
		return $decoded;
	}

	/**
	 * Whether one policy governs this objective.
	 *
	 * @param string $id        Policy id.
	 * @param string $objective What the operator asked for, in either language.
	 * @return bool
	 */
	public static function applies_to( string $id, string $objective ): bool {
		$text = self::normalize( $objective );
		if ( '' === $text ) {
			return false;
		}
		foreach ( self::TRIGGERS[ $id ] ?? array() as $pattern ) {
			if ( 1 === preg_match( $pattern, $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every policy that governs this objective.
	 *
	 * More than one is a normal answer, not a conflict to resolve: work can be
	 * commerce carried out through the browser, and both methods apply to it.
	 * Order follows the declaration order above so the result is stable, which
	 * matters because it is attached to a plan and compared in tests.
	 *
	 * @param string $objective What the operator asked for.
	 * @return array<int,string>
	 */
	public static function for_objective( string $objective ): array {
		$matched = array();
		foreach ( self::ids() as $id ) {
			if ( self::applies_to( $id, $objective ) ) {
				$matched[] = $id;
			}
		}
		return $matched;
	}

	/**
	 * The methods to attach to a plan for this objective.
	 *
	 * This is where the full text belongs -- the stages, the operating rules,
	 * the quality gate. The objective is known here, so the words are about to
	 * be used rather than merely carried.
	 *
	 * @param string $objective What the operator asked for.
	 * @return array<string,mixed>
	 */
	public static function runtime_context( string $objective ): array {
		$ids      = self::for_objective( $objective );
		$policies = array();
		foreach ( $ids as $id ) {
			$policies[ $id ] = self::load( $id );
		}
		return array(
			'applicable'      => array() !== $ids,
			'policy_ids'      => $ids,
			'policy_version'  => self::VERSION,
			'policies'        => $policies,
		);
	}

	/**
	 * Fold an objective the same way every other matcher in this suite does.
	 *
	 * @param string $text Raw objective.
	 * @return string
	 */
	private static function normalize( string $text ): string {
		$text = PRSTUDIO_UC_Action_Lexicon::fold_accents( trim( $text ) );
		$text = (string) preg_replace( '/[^a-z0-9\s._\-\/]+/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}
}
