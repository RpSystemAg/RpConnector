<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Gate anti-allucinazione sul flusso di evidenza.
 *
 * Riferimento: "Mixture-of-Expert Blocks Contain Strong Hallucination
 * Detection Signals" (arXiv, settimana 13-19 agosto 2026).
 *
 * Controlla la coerenza tra l'azione dichiarata dal modello e l'evidenza
 * restituita (DOM, screenshot, URL, testo). Le azioni senza evidenza coerente
 * vengono marcate `unverified` e restano fuori dall'accettazione live — senza
 * MAI bloccare l'esecuzione già avvenuta né trasformare la verifica in
 * autorizzazione (Law 2: la verifica è evidenza, mai autorizzazione; il
 * risultato eseguito resta executed=true con verified=false e degraded=true).
 *
 * Verdict:
 * - verified:    l'evidenza conferma l'effetto dichiarato;
 * - unverified:  nessuna evidenza coerente disponibile;
 * - conflicting: l'evidenza contraddice la dichiarazione.
 */
final class PRSTUDIO_UC_Evidence_Gate {
    public const VERSION = '1.0.0';

    /**
     * Valuta la coerenza tra azione dichiarata ed evidenza osservata.
     *
     * @param array{action?:string,url?:string,expect_text?:string,expect_selector?:string,target?:string} $declared
     * @param array{url?:string,text?:string,dom_text?:string,selectors?:array<int,string>,ok?:bool,error?:string} $evidence
     * @return array{verdict:string,reasons:array<int,string>,checks:array<string,bool>}
     */
    public static function evaluate( array $declared, array $evidence ): array {
        $reasons = array();
        $checks = array();

        // 1. Evidenza tecnica assente o errore: mai "verified".
        $ok = $evidence['ok'] ?? true;
        if ( true !== $ok ) {
            $checks['evidence_ok'] = false;
            $reasons[] = 'evidence_ok=false';
        } else {
            $checks['evidence_ok'] = true;
        }

        // 2. Coerenza URL: se entrambi presenti, devono corrispondere.
        $declared_url = self::normalize_url( (string) ( $declared['url'] ?? '' ) );
        $observed_url = self::normalize_url( (string) ( $evidence['url'] ?? '' ) );
        if ( '' !== $declared_url && '' !== $observed_url ) {
            $match = $declared_url === $observed_url
                || str_starts_with( $observed_url, $declared_url )
                || str_starts_with( $declared_url, $observed_url );
            $checks['url_coherent'] = $match;
            if ( ! $match ) {
                $reasons[] = 'url_conflict:declared=' . $declared_url . ',observed=' . $observed_url;
            }
        } else {
            $checks['url_coherent'] = true;
            if ( '' === $observed_url ) { $reasons[] = 'url_evidence_missing'; }
        }

        // 3. Testo atteso presente nell'evidenza testuale (DOM/screenshot OCR).
        $haystack = (string) ( $evidence['text'] ?? '' ) . "\n" . (string) ( $evidence['dom_text'] ?? '' );
        $expect_text = trim( (string) ( $declared['expect_text'] ?? '' ) );
        if ( '' !== $expect_text ) {
            $found = '' !== $haystack && false !== mb_strpos( $haystack, $expect_text );
            $checks['text_evidence'] = $found;
            if ( ! $found ) {
                $reasons[] = 'expected_text_not_found';
            }
        } else {
            $checks['text_evidence'] = true;
        }

        // 4. Selettore atteso presente nell'evidenza DOM.
        $expect_selector = trim( (string) ( $declared['expect_selector'] ?? '' ) );
        $selectors = is_array( $evidence['selectors'] ?? null ) ? array_values( $evidence['selectors'] ) : array();
        if ( '' !== $expect_selector ) {
            $found = in_array( $expect_selector, $selectors, true );
            $checks['selector_evidence'] = $found;
            if ( ! $found ) {
                $reasons[] = 'expected_selector_not_found';
            }
        } else {
            $checks['selector_evidence'] = true;
        }

        // 5. Errore esplicito nell'evidenza: contraddizione con un esito pulito.
        if ( '' !== trim( (string) ( $evidence['error'] ?? '' ) ) ) {
            $checks['no_evidence_error'] = false;
            $reasons[] = 'evidence_error_present';
        } else {
            $checks['no_evidence_error'] = true;
        }

        $conflict = ! empty( $checks['url_coherent'] ) && false === $checks['url_coherent'];
        foreach ( array( 'url_conflict' => true ) as $name => $unused ) {
            foreach ( $reasons as $reason ) {
                if ( str_starts_with( $reason, 'url_conflict' ) ) { $conflict = true; }
            }
        }
        $unverified = false === $checks['evidence_ok'] || false === $checks['text_evidence'] || false === $checks['selector_evidence'] || false === $checks['no_evidence_error'];
        $verdict = $conflict ? 'conflicting' : ( $unverified ? 'unverified' : 'verified' );

        return array(
            'verdict' => $verdict,
            'reasons' => $reasons,
            'checks' => $checks,
        );
    }

    /**
     * Marca un risultato eseguito con il verdetto del gate.
     *
     * Law 2: un risultato eseguito resta executed=true; la mancanza di
     * evidenza produce verified=false, degraded=true, mai un veto o un
     * rollback.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function tag( array $result, string $verdict ): array {
        $verdict = in_array( $verdict, array( 'verified', 'unverified', 'conflicting' ), true ) ? $verdict : 'unverified';
        $result['verified'] = 'verified' === $verdict;
        if ( 'verified' !== $verdict ) {
            $result['degraded'] = true;
        }
        $result['evidence_gate'] = $verdict;
        $result['executed'] = true === ( $result['executed'] ?? true ) ? true : false;
        return $result;
    }

    /** Report completo dichiarato+evidenza+verdict (utile al flusso MCP). */
    public static function gate( array $declared, array $evidence, array $result = array() ): array {
        $evaluation = self::evaluate( $declared, $evidence );
        $result = self::tag( $result, (string) $evaluation['verdict'] );
        $result['evidence_gate_report'] = $evaluation;
        return $result;
    }

    private static function normalize_url( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) { return ''; }
        $url = preg_replace( '/^https?:\/\//i', '', $url ) ?: $url;
        $url = rtrim( $url, '/' );
        $url = preg_replace( '/#.*$/', '', $url ) ?: $url;
        return strtolower( $url );
    }
}
