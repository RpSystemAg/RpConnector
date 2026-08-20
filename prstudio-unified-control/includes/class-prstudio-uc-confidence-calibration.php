<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Calibrazione della confidenza delle decisioni IA.
 *
 * Riferimento: "Too Sure to Be Safe: Model Calibration for Reliable Log
 * Anomaly Detection" (arXiv, settimana 13-19 agosto 2026).
 *
 * Un modello che dichiara "sicuro al 95%" ma azzecca il 60% delle volte è un
 * rischio operativo: la confidenza dichiarata guida le priorità di verifica e
 * il triage. Questo modulo mantiene, per chiave di decisione, una tabella di
 * bin di confidenza (calibrazione a binning, ECE - Expected Calibration
 * Error) e restituisce una confidenza ricalibrata e un verdetto di
 * "overconfidence" quando il divario dichiarato/osservato supera la tolleranza.
 *
 * È un osservatore tecnico, non un gate autorizzativo (Law 2, Law 4): non
 * blocca mai l'esecuzione; ridimensiona la confidenza che i livelli superiori
 * usano per decidere quanto verificare e come riportare.
 *
 * Storage: opzione WordPress quando disponibile, altrimenti store statico in
 * memoria (sufficiente per il runtime di test e per installazioni che non
 * vogliono persistenza).
 */
final class PRSTUDIO_UC_Confidence_Calibration {
    public const VERSION = '1.0.0';
    public const BIN_COUNT = 10;
    public const MIN_BIN_SAMPLES = 5;
    public const DEFAULT_TOLERANCE = 0.15;
    private const OPTION = 'prstudio_uc_confidence_calibration';

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

    /** Store iniettato per i test deterministici (evita contaminazione tra suite). */
    public static function set_store_for_test( array $store ): void {
        self::$memory_store = $store;
    }

    private static function bin_index( float $declared ): int {
        $clamped = max( 0.0, min( 1.0, $declared ) );
        $index = (int) floor( $clamped * self::BIN_COUNT );
        return min( self::BIN_COUNT - 1, max( 0, $index ) );
    }

    private static function bin_center( int $index ): float {
        return ( $index + 0.5 ) / self::BIN_COUNT;
    }

    /**
     * Registra un esito osservato: confidenza dichiarata e correttezza.
     *
     * @return array{key:string,samples:int,bin:int}
     */
    public static function record( string $key, float $declared, bool $correct ): array {
        $key = sanitize_key( $key );
        if ( '' === $key ) { $key = 'default'; }
        $store = self::store();
        $entry = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
        $bins = isset( $entry['bins'] ) && is_array( $entry['bins'] ) ? $entry['bins'] : array();
        $index = self::bin_index( $declared );
        $bin = isset( $bins[ $index ] ) && is_array( $bins[ $index ] ) ? $bins[ $index ] : array( 'n' => 0, 'correct' => 0, 'sum_conf' => 0.0 );
        $bin['n'] = (int) $bin['n'] + 1;
        $bin['correct'] = (int) $bin['correct'] + ( $correct ? 1 : 0 );
        $bin['sum_conf'] = (float) $bin['sum_conf'] + max( 0.0, min( 1.0, $declared ) );
        $bins[ $index ] = $bin;
        $entry['bins'] = $bins;
        $entry['samples'] = (int) ( $entry['samples'] ?? 0 ) + 1;
        $store[ $key ] = $entry;
        self::persist( $store );
        return array( 'key' => $key, 'samples' => (int) $entry['samples'], 'bin' => $index );
    }

    public static function reset( string $key ): bool {
        $key = sanitize_key( $key );
        $store = self::store();
        if ( ! isset( $store[ $key ] ) ) { return false; }
        unset( $store[ $key ] );
        self::persist( $store );
        return true;
    }

    /** @return array{bins:array<int,array<string,mixed>>,ece:float,samples:int} */
    public static function expected_calibration_error( string $key ): array {
        $key = sanitize_key( $key );
        $store = self::store();
        $entry = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
        $bins = isset( $entry['bins'] ) && is_array( $entry['bins'] ) ? $entry['bins'] : array();
        $total = 0;
        $ece = 0.0;
        $out = array();
        foreach ( $bins as $index => $bin ) {
            if ( ! is_array( $bin ) ) { continue; }
            $n = max( 1, (int) ( $bin['n'] ?? 0 ) );
            $accuracy = (int) ( $bin['correct'] ?? 0 ) / $n;
            $confidence = (float) ( $bin['sum_conf'] ?? 0 ) / $n;
            $out[ (int) $index ] = array(
                'n' => $n,
                'accuracy' => round( $accuracy, 4 ),
                'confidence' => round( $confidence, 4 ),
                'gap' => round( abs( $accuracy - $confidence ), 4 ),
            );
            $total += $n;
            $ece += ( $n * abs( $accuracy - $confidence ) );
        }
        return array(
            'bins' => $out,
            'ece' => $total > 0 ? round( $ece / $total, 4 ) : 0.0,
            'samples' => (int) ( $entry['samples'] ?? 0 ),
        );
    }

    /**
     * Confidenza ricalibrata per una dichiarazione.
     *
     * Usa l'accuratezza osservata del bin quando il bin ha campioni
     * sufficienti; altrimenti la confidenza dichiarata (nessuna evidenza per
     * ridimensionare non è motivo per inventarne).
     */
    public static function recalibrated( string $key, float $declared ): float {
        $key = sanitize_key( $key );
        $store = self::store();
        $entry = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
        $bins = isset( $entry['bins'] ) && is_array( $entry['bins'] ) ? $entry['bins'] : array();
        $index = self::bin_index( $declared );
        $bin = isset( $bins[ $index ] ) && is_array( $bins[ $index ] ) ? $bins[ $index ] : array();
        $n = (int) ( $bin['n'] ?? 0 );
        if ( $n < self::MIN_BIN_SAMPLES ) {
            return round( max( 0.0, min( 1.0, $declared ) ), 4 );
        }
        return round( (int) ( $bin['correct'] ?? 0 ) / $n, 4 );
    }

    /**
     * Verdetto di overconfidence per una dichiarazione.
     *
     * "Too sure to be safe": quando la confidenza ricalibrata è
     * significativamente sotto quella dichiarata, il sistema deve smettere di
     * fidarsi del numero dichiarato.
     *
     * @return array{key:string,declared:float,recalibrated:float,gap:float,overconfident:bool,reason:string}
     */
    public static function verdict( string $key, float $declared, float $tolerance = self::DEFAULT_TOLERANCE ): array {
        $declared = round( max( 0.0, min( 1.0, $declared ) ), 4 );
        $recalibrated = self::recalibrated( $key, $declared );
        $gap = round( $declared - $recalibrated, 4 );
        $overconfident = $gap > $tolerance;
        return array(
            'key' => sanitize_key( $key ),
            'declared' => $declared,
            'recalibrated' => $recalibrated,
            'gap' => $gap,
            'overconfident' => $overconfident,
            'reason' => $overconfident
                ? 'declared confidence exceeds observed calibration by more than the tolerance'
                : 'declared confidence is within observed calibration tolerance',
        );
    }

    /** @return array<string,array<string,mixed>> */
    public static function snapshot(): array {
        $out = array();
        foreach ( self::store() as $key => $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            $out[ (string) $key ] = array(
                'samples' => (int) ( $entry['samples'] ?? 0 ),
                'ece' => self::expected_calibration_error( (string) $key )['ece'],
            );
        }
        return $out;
    }

    /** Accessor per i test: centro del bin i-esimo. */
    public static function bin_center_for_test( int $index ): float {
        return self::bin_center( $index );
    }
}
