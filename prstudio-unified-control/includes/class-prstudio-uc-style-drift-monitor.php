<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Rilevamento di drift nello stile di scrittura dell'output IA.
 *
 * Riferimento: "When Writing Style Drifts: Benchmarking Authorship
 * Verification under Distribution Shifts" (arXiv, settimana 13-19 agosto
 * 2026).
 *
 * Un cambio anomalo dello stile dell'output (frasi spezzate, densità di
 * punteggiatura, ricchezza lessicale) è un segnale diagnostico: possibile
 * allucinazione, cambio di provenienza del testo, template corrotto o
 * regressione nel modello. Il modulo estrae features stilistiche
 * deterministiche, mantiene una baseline a media/variance incrementale
 * (Welford) per chiave e segnala drift quando più features superano la soglia
 * z-score.
 *
 * È un monitor, non un gate: non blocca nulla, alimenta il reporting e il
 * confidence calibration (chi drift, meno fiducia).
 */
final class PRSTUDIO_UC_Style_Drift_Monitor {
    public const VERSION = '1.0.0';
    public const DRIFT_Z_THRESHOLD = 2.5;
    public const MIN_FEATURES_TO_DRIFT = 2;
    private const OPTION = 'prstudio_uc_style_drift_baselines';

    private const FEATURE_NAMES = array(
        'mean_sentence_length',
        'punctuation_density',
        'type_token_ratio',
        'function_word_ratio',
        'exclamation_density',
        'question_density',
        'digit_density',
    );

    private const FUNCTION_WORDS = array(
        'il','lo','la','i','gli','le','un','uno','una','di','a','da','in','con','su','per','tra','fra',
        'e','ed','o','ma','che','come','quando','se','non','più','piu','anche','sia','del','della','dei',
        'the','a','an','of','to','in','and','or','but','for','on','with','at','by','from','is','are','was',
        'were','be','been','it','this','that','these','those','we','you','they','he','she','as','than',
    );

    /** @var array<string,array<string,mixed>> */
    private static array $memory_store = array();

    private static function store(): array {
        if ( function_exists( 'get_option' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) {
            $stored = get_option( self::OPTION, array() );
            return is_array( $stored ) ? $stored : array();
        }
        return self::$memory_store;
    }

    private static function persist( array $store ): void {
        if ( function_exists( 'update_option' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) {
            update_option( self::OPTION, $store, false );
            return;
        }
        self::$memory_store = $store;
    }

    public static function set_store_for_test( array $store ): void {
        self::$memory_store = $store;
    }

    /**
     * Estrae le features stilistiche deterministiche di un testo.
     *
     * @return array<string,float>
     */
    public static function features( string $text ): array {
        $text = trim( (string) $text );
        $sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: array();
        $sentences = array_values( array_filter( $sentences, static fn( $s ): bool => '' !== trim( (string) $s ) ) );
        $words = preg_split( '/\s+/u', $text ) ?: array();
        $words = array_values( array_filter( $words, static fn( $w ): bool => '' !== trim( (string) $w ) ) );
        $word_count = count( $words );
        $sentence_count = count( $sentences );

        $letters = preg_replace( '/[^a-zA-Zàèéìòùáíóúüäöß]/u', '', $text ) ?: '';
        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $letters ) : strtolower( $letters );
        $types = array();
        $function_words = 0;
        $sample = array_slice( $words, 0, 200 );
        foreach ( $sample as $word ) {
            $clean = strtolower( trim( (string) $word, ".,;:!?()\"'«»" ) );
            if ( '' === $clean ) { continue; }
            $types[ $clean ] = true;
            if ( in_array( $clean, self::FUNCTION_WORDS, true ) ) { $function_words++; }
        }

        return array(
            'mean_sentence_length' => $sentence_count > 0 ? round( $word_count / $sentence_count, 3 ) : 0.0,
            'punctuation_density' => $word_count > 0 ? round( preg_match_all( '/[.,;:!?]/u', $text ) / $word_count, 3 ) : 0.0,
            'type_token_ratio' => count( $sample ) > 0 ? round( count( $types ) / count( $sample ), 3 ) : 0.0,
            'function_word_ratio' => count( $sample ) > 0 ? round( $function_words / count( $sample ), 3 ) : 0.0,
            'exclamation_density' => $word_count > 0 ? round( substr_count( $text, '!' ) / $word_count, 3 ) : 0.0,
            'question_density' => $word_count > 0 ? round( substr_count( $text, '?' ) / $word_count, 3 ) : 0.0,
            'digit_density' => $word_count > 0 ? round( preg_match_all( '/[0-9]/', $text ) / $word_count, 3 ) : 0.0,
        );
    }

    /**
     * Registra un campione di stile nella baseline della chiave.
     *
     * Media e varianza incrementali (algoritmo di Welford) per ogni feature:
     * la baseline non richiede di conservare lo storico.
     */
    public static function record( string $key, array $features ): array {
        $key = sanitize_key( $key );
        if ( '' === $key ) { $key = 'default'; }
        $store = self::store();
        $entry = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
        $stats = isset( $entry['stats'] ) && is_array( $entry['stats'] ) ? $entry['stats'] : array();
        foreach ( self::FEATURE_NAMES as $name ) {
            $value = (float) ( $features[ $name ] ?? 0.0 );
            $stat = isset( $stats[ $name ] ) && is_array( $stats[ $name ] ) ? $stats[ $name ] : array( 'n' => 0, 'mean' => 0.0, 'm2' => 0.0 );
            $n = (int) $stat['n'] + 1;
            $mean = (float) $stat['mean'];
            $m2 = (float) $stat['m2'];
            $delta = $value - $mean;
            $mean += $delta / $n;
            $m2 += $delta * ( $value - $mean );
            $stats[ $name ] = array( 'n' => $n, 'mean' => $mean, 'm2' => $m2 );
        }
        $entry['stats'] = $stats;
        $entry['samples'] = (int) ( $entry['samples'] ?? 0 ) + 1;
        $store[ $key ] = $entry;
        self::persist( $store );
        return array( 'key' => $key, 'samples' => (int) $entry['samples'] );
    }

    /** @return array{drifted:bool,changed_features:array<string,string>,features:array<string,array<string,float>>} */
    public static function drift( string $key, array $features ): array {
        $key = sanitize_key( $key );
        $store = self::store();
        $entry = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
        $stats = isset( $entry['stats'] ) && is_array( $entry['stats'] ) ? $entry['stats'] : array();
        $changed = array();
        $detail = array();
        foreach ( self::FEATURE_NAMES as $name ) {
            $stat = isset( $stats[ $name ] ) && is_array( $stats[ $name ] ) ? $stats[ $name ] : array( 'n' => 0, 'mean' => 0.0, 'm2' => 0.0 );
            $n = (int) $stat['n'];
            $mean = (float) $stat['mean'];
            $sd = $n > 1 ? sqrt( (float) $stat['m2'] / ( $n - 1 ) ) : 0.0;
            $value = (float) ( $features[ $name ] ?? 0.0 );
            $z = $sd > 0.0001 ? ( $value - $mean ) / $sd : 0.0;
            $detail[ $name ] = array(
                'value' => round( $value, 3 ),
                'mean' => round( $mean, 3 ),
                'sd' => round( $sd, 3 ),
                'z' => round( $z, 3 ),
                'samples' => $n,
            );
            if ( $n >= 8 && abs( $z ) >= self::DRIFT_Z_THRESHOLD ) {
                $changed[ $name ] = $z > 0 ? 'up' : 'down';
            }
        }
        return array(
            'drifted' => count( $changed ) >= self::MIN_FEATURES_TO_DRIFT,
            'changed_features' => $changed,
            'features' => $detail,
        );
    }

    public static function reset( string $key ): bool {
        $key = sanitize_key( $key );
        $store = self::store();
        if ( ! isset( $store[ $key ] ) ) { return false; }
        unset( $store[ $key ] );
        self::persist( $store );
        return true;
    }
}
