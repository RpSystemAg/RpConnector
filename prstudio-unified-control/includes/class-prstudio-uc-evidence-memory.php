<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Memory bank con evidenza preservata — protocollo diagnostico a doppio loop.
 *
 * Riferimento: "D²ACCI: A Dual-Loop Diagnostic Protocol for
 * Evidence-Preserving Agent Memory" (arXiv, settimana 13-19 agosto 2026).
 *
 * Ogni decisione passata viene conservata con la sua evidenza (cosa è stato
 * deciso, su quale evidenza, in quale loop, con quale esito) in un archivio
 * append-only NDJSON. Il doppio loop distingue il loop di analisi (perché ho
 * deciso questo?) dal loop di osservazione (cosa ho osservato dopo?): il
 * diagnostico ripercorre entrambi e segnala i gap (azioni eseguite senza
 * evidenza, analisi senza esito).
 *
 * Storage: file NDJSON nella directory del sito (o directory iniettabile per
 * i test), con rotazione a cap dimensionale come il resto della suite.
 * Nessuna dipendenza WordPress obbligatoria.
 */
final class PRSTUDIO_UC_Evidence_Memory {
    public const VERSION = '1.0.0';
    private const MAX_FILE_BYTES = 8388608;
    private const LOOPS = array( 'analyze', 'observe', 'execute', 'verify' );

    private static ?string $test_dir = null;

    /** Directory iniettabile per i test deterministici. */
    public static function set_dir_for_test( string $dir ): void {
        self::$test_dir = rtrim( $dir, '/' );
    }

    public static function dir(): string {
        if ( null !== self::$test_dir ) {
            if ( ! is_dir( self::$test_dir ) ) { @mkdir( self::$test_dir, 0750, true ); }
            return self::$test_dir;
        }
        $base = class_exists( 'PRSTUDIO_UC_Memory' ) && method_exists( 'PRSTUDIO_UC_Memory', 'site_dir' )
            ? PRSTUDIO_UC_Memory::site_dir()
            : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : sys_get_temp_dir() );
        $dir = rtrim( (string) $base, '/' ) . '/evidence-memory';
        if ( ! is_dir( $dir ) ) {
            function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : @mkdir( $dir, 0750, true );
        }
        return $dir;
    }

    private static function path(): string {
        return self::dir() . '/evidence.ndjson';
    }

    /**
     * Registra una decisione con la sua evidenza (append-only).
     *
     * @param array{decision:string,evidence:array<string,mixed>,outcome?:string,loop?:string} $entry
     * @return array{ok:bool,record_id:string,seq:int}
     */
    public static function remember( string $decision_id, array $entry ): array {
        $decision_id = substr( sanitize_key( $decision_id ), 0, 120 );
        if ( '' === $decision_id ) { $decision_id = 'decision_' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 ); }
        $loop = (string) ( $entry['loop'] ?? 'observe' );
        if ( ! in_array( $loop, self::LOOPS, true ) ) { $loop = 'observe'; }
        $record = array(
            'record_id' => 'em_' . substr( hash( 'sha256', uniqid( (string) microtime( true ), true ) ), 0, 24 ),
            'decision_id' => $decision_id,
            'loop' => $loop,
            'decision' => substr( (string) ( $entry['decision'] ?? '' ), 0, 2000 ),
            'evidence' => self::redact( is_array( $entry['evidence'] ?? null ) ? $entry['evidence'] : array() ),
            'outcome' => substr( (string) ( $entry['outcome'] ?? '' ), 0, 1000 ),
            'gmt' => gmdate( 'c' ),
        );
        $seq = self::append( $record );
        return array( 'ok' => true, 'record_id' => (string) $record['record_id'], 'seq' => $seq );
    }

    /**
     * Ricerca per parola chiave su decisione/evidenza/esito.
     *
     * @return array{count:int,records:array<int,array<string,mixed>>}
     */
    public static function recall( string $query, int $limit = 20 ): array {
        $query = strtolower( trim( $query ) );
        $limit = max( 1, min( 200, $limit ) );
        $records = array();
        if ( '' !== $query ) {
            foreach ( self::read_all() as $record ) {
                $haystack = strtolower(
                    (string) $record['decision'] . ' ' . wp_json_encode( $record['evidence'] ) . ' ' . (string) $record['outcome']
                );
                if ( false !== strpos( $haystack, $query ) ) { $records[] = $record; }
                if ( count( $records ) >= $limit ) { break; }
            }
        }
        return array( 'count' => count( $records ), 'records' => $records );
    }

    /**
     * Diagnostica a doppio loop di una decisione.
     *
     * Ripercorre la catena analyze -> observe -> execute -> verify e segnala
     * i gap: loop di esecuzione senza evidenza, analisi senza esito.
     *
     * @return array{decision_id:string,records:array<int,array<string,mixed>>,loops:array<string,int>,gaps:array<int,string>}
     */
    public static function diagnose( string $decision_id ): array {
        $decision_id = substr( sanitize_key( $decision_id ), 0, 120 );
        $records = array();
        $gaps = array();
        foreach ( self::read_all() as $record ) {
            if ( (string) ( $record['decision_id'] ?? '' ) !== $decision_id ) { continue; }
            $records[] = $record;
            if ( in_array( (string) ( $record['loop'] ?? '' ), array( 'execute', 'verify' ), true ) && empty( $record['evidence'] ) ) {
                $gaps[] = 'no_evidence_on_' . $record['loop'] . ':' . (string) ( $record['record_id'] ?? '' );
            }
            if ( 'analyze' === (string) ( $record['loop'] ?? '' ) && '' === (string) ( $record['outcome'] ?? '' ) ) {
                $gaps[] = 'analysis_without_outcome:' . (string) ( $record['record_id'] ?? '' );
            }
        }
        $loops = array();
        foreach ( $records as $record ) {
            $loop = (string) ( $record['loop'] ?? '' );
            $loops[ $loop ] = (int) ( $loops[ $loop ] ?? 0 ) + 1;
        }
        return array( 'decision_id' => $decision_id, 'records' => $records, 'loops' => $loops, 'gaps' => $gaps );
    }

    /** Esportazione redatta (audit/compliance). */
    public static function export( int $limit = 100 ): array {
        $rows = self::read_all();
        return array( 'count' => count( $rows ), 'records' => array_slice( $rows, 0, max( 1, min( 500, $limit ) ) ) );
    }

    private static function append( array $record ): int {
        $path = self::path();
        $line = (string) json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( '' === $line ) { return 0; }
        $lock = @fopen( self::dir() . '/.evidence-memory.lock', 'c+' );
        if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX ) ) {
            if ( is_resource( $lock ) ) { @fclose( $lock ); }
            @file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
            return self::count_lines( $path );
        }
        try {
            clearstatcache( true, $path );
            if ( is_file( $path ) && filesize( $path ) > self::MAX_FILE_BYTES ) {
                @unlink( $path . '.previous' );
                @rename( $path, $path . '.previous' );
            }
            @file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
            return self::count_lines( $path );
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function read_all(): array {
        $path = self::path();
        if ( ! is_readable( $path ) ) { return array(); }
        $rows = array();
        foreach ( @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
            $row = json_decode( (string) $line, true );
            if ( is_array( $row ) ) { $rows[] = $row; }
        }
        return $rows;
    }

    private static function count_lines( string $path ): int {
        if ( ! is_readable( $path ) ) { return 0; }
        $count = 0;
        foreach ( @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $unused ) { $count++; }
        return $count;
    }

    /** Redazione di base per l'evidenza (stesso spirito di PRSTUDIO_UC_Memory). */
    private static function redact( array $value ): array {
        $out = array();
        foreach ( $value as $key => $item ) {
            if ( is_array( $item ) ) { $out[ $key ] = self::redact( $item ); continue; }
            if ( preg_match( '/password|secret|token|api[_-]?key|credential|authorization|cookie|session|code_verifier/i', (string) $key ) ) {
                $out[ $key ] = '[REDACTED]';
                continue;
            }
            $out[ $key ] = $item;
        }
        return $out;
    }
}
