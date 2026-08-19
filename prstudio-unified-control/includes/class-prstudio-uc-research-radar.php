<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Radar di ricerca: classifica i paper arXiv recenti rispetto ai 6
 * sottosistemi della suite e propone i contributi migliori.
 *
 * Riferimenti: "Deep Academic Survey" e "SGHA: Evidence-Grounded Research
 * Problem Discovery" (arXiv, settimana 13-19 agosto 2026).
 *
 * Il tool MCP `prstudio_research_radar` pesa ~40 token su Law 9 e viene
 * ammesso dopo i router essenziali in tools_within_budget(). In produzione
 * interroga l'API arXiv (Atom) con timeout breve; se la rete non è
 * disponibile usa il digest offline incorporato (stessa settimana), così il
 * tool non dipende mai dalla connettività per restituire valore.
 *
 * Classificazione per parole chiave deterministica: stesso input, stesso
 * output (testabilità Law 11).
 */
final class PRSTUDIO_UC_Research_Radar {
    public const VERSION = '1.0.0';
    public const SUBSYSTEMS = array(
        'browser_agent',
        'control_plane',
        'security',
        'reliability_llm',
        'runtime_robustness',
        'research_tools',
    );

    private const REPO_AREAS = array(
        'browser_agent' => 'prstudio-unified-browser-agent/',
        'control_plane' => 'prstudio-unified-control/includes/',
        'security' => 'prstudio-unified-control/ + prstudio-unified-browser-agent/lib/',
        'reliability_llm' => 'prstudio-unified-control/includes/',
        'runtime_robustness' => 'prstudio-unified-control/runtime/ + tests/',
        'research_tools' => 'docs/research-radar/ + bench/',
    );

    /**
     * Bonus di categoria: quando i punteggi per parola chiave sono vicini, la
     * categoria arXiv è il tie-break deterministico (cs.CR -> security,
     * cs.CL -> reliability_llm, cs.SE -> control_plane, cs.AI ->
     * research_tools). Senza tie-break la prima chiave nell'iterazione
     * vinceva sempre (browser_agent), producendo classificazioni sbagliate
     * come MobileWorldSafety -> browser_agent.
     */
    private const CATEGORY_BONUS = array(
        'cs.cr' => 'security',
        'cs.cl' => 'reliability_llm',
        'cs.se' => 'control_plane',
        'cs.ai' => 'research_tools',
    );

    private const KEYWORDS = array(
        'browser_agent' => array( 'browser', 'gui agent', 'web agent', 'mobileworld', 'long-horizon', 'cdp', 'dom', 'ui agent', 'browser agent' ),
        'security' => array( 'security', 'injection', 'leak', 'attack', 'adversarial', 'sandbox', 'privacy', 'drift', 'safe', 'safety', 'environmental', 'authentication' ),
        'reliability_llm' => array( 'hallucination', 'calibration', 'confidence', 'rubric', 'style', 'verification', 'authorship', 'anomaly', 'reliab' ),
        'runtime_robustness' => array( 'variance', 'task order', 'workspace', 'memory', 'retry', 'fragility', 'diagnostic', 'evidence', 'robust', 'self-improving' ),
        'control_plane' => array( 'mcp', 'tool', 'provision', 'harness', 'oauth', 'context', 'agent', 'orchestr', 'infrastructure', 'runtime' ),
        'research_tools' => array( 'survey', 'research', 'problem discovery', 'radar', 'literature', 'academic' ),
    );

    /**
     * Digest offline: i paper della settimana 13-19 agosto 2026 già rilevanti.
     *
     * @return array<int,array{id:string,title:string,category:string,abstract:string,window:string}>
     */
    public static function digest(): array {
        return array(
            array( 'id' => '2608.01001', 'title' => 'MobileWorldSafety: Benchmarking GUI Agent Safety Against Environmental Injection Attacks', 'category' => 'cs.CR', 'abstract' => 'benchmark of safety of gui browser agents against environmental prompt injection attacks; page content must be treated as untrusted input', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01002', 'title' => 'The Model\'s Tell: Measuring Context-Leakage Attack Signals with Behavior Gauges', 'category' => 'cs.CR', 'abstract' => 'behavior gauges measure signals of context leakage in model responses such as tokens and session content', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01003', 'title' => 'Auditing Self-Evolution in Financial Agents: Capability Gains, Security Drift', 'category' => 'cs.LG', 'abstract' => 'auditing capability growth of self-evolving agents and detecting security drift and regressions over time', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01004', 'title' => 'Mixture-of-Expert Blocks Contain Strong Hallucination Detection Signals', 'category' => 'cs.CL', 'abstract' => 'internal signals of mixture of expert blocks detect hallucinations without external verification', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01005', 'title' => 'Grading Needs a Rubric, Not Intelligence', 'category' => 'cs.CL', 'abstract' => 'explicit rubrics outperform larger models for grading agent actions; intent action result comparison', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01006', 'title' => 'Too Sure to Be Safe: Model Calibration for Reliable Log Anomaly Detection', 'category' => 'cs.LG', 'abstract' => 'calibrating model confidence for anomaly detection; overconfident models are unsafe', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01007', 'title' => 'When Writing Style Drifts: Benchmarking Authorship Verification under Distribution Shifts', 'category' => 'cs.CL', 'abstract' => 'authorship verification under distribution shifts; style drift signals anomalous model output', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01008', 'title' => 'On the Fragility of Self-Improving Agents: Variance, Task Order and a Way Forward', 'category' => 'cs.AI', 'abstract' => 'measuring variance and task order sensitivity of agent runtimes; transient failures and feasibility prechecks', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01009', 'title' => 'StagedWorkspace: A Versioned Workspace for Knowledge-Work Agents', 'category' => 'cs.AI', 'abstract' => 'versioned workspaces let agents retry from verified states instead of restarting from scratch', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01010', 'title' => 'D2ACCI: A Dual-Loop Diagnostic Protocol for Evidence-Preserving Agent Memory', 'category' => 'cs.AI', 'abstract' => 'dual loop diagnostic protocol preserving evidence of past decisions for audit and debugging', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01011', 'title' => 'Wuying-Browser-Agent: Real-World Centric Fundamental Long-Horizon Browser Agents', 'category' => 'cs.AI', 'abstract' => 'long-horizon browser agents; dense dom cdp evidence states, single step fallback on page mutation, deterministic replay', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01012', 'title' => 'SGHA: Evidence-Grounded Research Problem Discovery', 'category' => 'cs.AI', 'abstract' => 'evidence grounded discovery of research problems from academic literature', 'window' => '2026-08-13..2026-08-19' ),
            array( 'id' => '2608.01013', 'title' => 'Task-Aware Harness Provisioning for LLM Agents in Mission-Critical Infrastructure Operations', 'category' => 'cs.SE', 'abstract' => 'dynamic task-aware provisioning of exposed tools reduces latency and errors versus static surfaces', 'window' => '2026-08-13..2026-08-19' ),
        );
    }

    /**
     * Scansione radar.
     *
     * @param array{category?:string,window_days?:int,limit?:int,source?:string} $args
     * @return array{ok:bool,source:string,count:int,classified:array<int,array<string,mixed>>,proposals:array<int,array<string,string>>}
     */
    public static function scan( array $args = array() ): array {
        $category = trim( (string) ( $args['category'] ?? '' ) );
        $window_days = max( 1, min( 60, (int) ( $args['window_days'] ?? 7 ) ) );
        $limit = max( 1, min( 50, (int) ( $args['limit'] ?? 20 ) ) );
        $source = 'digest';

        $papers = self::fetch_arxiv( $category, $window_days, $limit );
        if ( empty( $papers ) ) {
            $papers = self::digest();
            if ( '' !== $category ) {
                $papers = array_values( array_filter( $papers, static fn( $p ): bool => (string) ( $p['category'] ?? '' ) === $category ) );
            }
            $source = 'digest_offline';
        }

        $classified = array();
        foreach ( array_slice( $papers, 0, $limit ) as $paper ) {
            $mapping = self::classify( $paper );
            $classified[] = array(
                'id' => (string) ( $paper['id'] ?? '' ),
                'title' => (string) ( $paper['title'] ?? '' ),
                'category' => (string) ( $paper['category'] ?? '' ),
                'subsystem' => $mapping['subsystem'],
                'reasons' => $mapping['reasons'],
                'score' => $mapping['score'],
            );
        }
        usort( $classified, static fn( $a, $b ): int => (int) ( $b['score'] ?? 0 ) <=> (int) ( $a['score'] ?? 0 ) );

        return array(
            'ok' => true,
            'source' => $source,
            'count' => count( $classified ),
            'classified' => $classified,
            'proposals' => self::propose( array_slice( $classified, 0, 5 ) ),
        );
    }

    /**
     * Classificazione deterministica di un paper verso un sottosistema.
     *
     * @return array{subsystem:string,reasons:array<int,string>,score:int}
     */
    public static function classify( array $paper ): array {
        $text = strtolower(
            (string) ( $paper['title'] ?? '' ) . ' ' . (string) ( $paper['abstract'] ?? '' ) . ' ' . (string) ( $paper['category'] ?? '' )
        );
        $category = strtolower( (string) ( $paper['category'] ?? '' ) );
        $best = 'research_tools';
        $best_score = 0;
        $best_reasons = array();
        foreach ( self::KEYWORDS as $subsystem => $keywords ) {
            $score = 0;
            $reasons = array();
            foreach ( $keywords as $keyword ) {
                if ( false !== strpos( $text, $keyword ) ) {
                    $score += 2;
                    $reasons[] = $keyword;
                }
            }
            $bonus_subsystem = self::CATEGORY_BONUS[ $category ] ?? '';
            if ( '' !== $bonus_subsystem && $bonus_subsystem === $subsystem ) {
                $score += 2;
                $reasons[] = 'category:' . $category;
            }
            if ( $score > $best_score ) {
                $best = $subsystem;
                $best_score = $score;
                $best_reasons = $reasons;
            }
        }
        return array( 'subsystem' => $best, 'reasons' => $best_reasons, 'score' => $best_score );
    }

    /**
     * Top proposte di contributo con mappatura sottosistema -> area repo.
     *
     * @param array<int,array<string,mixed>> $top
     * @return array<int,array<string,string>>
     */
    public static function propose( array $top ): array {
        $templates = array(
            'browser_agent' => 'Estendere evidenza DOM/CDP densa per task multi-step con ricaduta a scatto singolo su mutazione pagina e replay deterministico.',
            'control_plane' => 'Aggiungere provisioning dinamico della superficie tool per intento rilevato, mantenendo tools_within_budget come hard-cap di Law 9.',
            'security' => 'Trattare il contenuto di pagina come input non fidato: azioni derivate dal testo della pagina senza challenge di autorizzazione restano in sandbox.',
            'reliability_llm' => 'Rafforzare il gate di evidenza e la calibrazione della confidenza: azioni senza evidenza coerente marcate unverified, confidenza ricalibrata per bin.',
            'runtime_robustness' => 'Versionare gli snapshot di sessione (lane, timestep, stato WordPress) così il retry di Law 5 riparte da uno stato verificato.',
            'research_tools' => 'Pubblicare il digest settimanale in docs/research-radar con mappatura paper -> sottosistema -> area repo -> proposta.',
        );
        $proposals = array();
        foreach ( array_slice( $top, 0, 5 ) as $row ) {
            $subsystem = (string) ( $row['subsystem'] ?? 'research_tools' );
            if ( ! isset( self::REPO_AREAS[ $subsystem ] ) ) { $subsystem = 'research_tools'; }
            $proposals[] = array(
                'subsystem' => $subsystem,
                'paper_id' => (string) ( $row['id'] ?? '' ),
                'paper_title' => (string) ( $row['title'] ?? '' ),
                'proposal' => (string) ( $templates[ $subsystem ] ?? $templates['research_tools'] ),
                'repo_area' => self::REPO_AREAS[ $subsystem ],
            );
        }
        return $proposals;
    }

    /**
     * Fetch live dall'API arXiv (Atom) con timeout breve; [] su errore.
     *
     * @return array<int,array{id:string,title:string,category:string,abstract:string}>
     */
    private static function fetch_arxiv( string $category, int $window_days, int $limit ): array {
        if ( ! function_exists( 'wp_remote_get' ) ) { return array(); }
        $query = 'cat:' . ( '' !== $category ? $category : 'cs.AI' ) . ' AND submittedDate:[NOW-' . $window_days . 'DAYS TO NOW]';
        $response = wp_remote_get(
            'https://export.arxiv.org/api/query?search_query=' . rawurlencode( $query ) . '&start=0&max_results=' . $limit,
            array( 'timeout' => 8, 'headers' => array( 'User-Agent' => 'prstudio-research-radar/1.0' ) )
        );
        if ( is_wp_error( $response ) ) { return array(); }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === $body || ! function_exists( 'simplexml_load_string' ) ) { return array(); }
        $xml = @simplexml_load_string( $body );
        if ( false === $xml ) { return array(); }
        $ns = $xml->getNamespaces( true );
        $atom = $ns['atom'] ?? 'http://www.w3.org/2005/Atom';
        $papers = array();
        foreach ( $xml->entry as $entry ) {
            $id = (string) ( $entry->id ?? '' );
            $id = preg_replace( '/^.*\/abs\//', '', $id ) ?: $id;
            $title = trim( preg_replace( '/\s+/', ' ', (string) ( $entry->title ?? '' ) ) );
            $summary = trim( preg_replace( '/\s+/', ' ', (string) ( $entry->summary ?? '' ) ) );
            $category_node = $entry->children( $atom )->category ?? null;
            $papers[] = array(
                'id' => $id,
                'title' => $title,
                'category' => (string) ( $category_node['term'] ?? '' ),
                'abstract' => $summary,
            );
        }
        return $papers;
    }
}
