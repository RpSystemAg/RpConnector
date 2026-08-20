<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
require_once __DIR__ . '/class-prstudio-uc-action-lexicon.php';
/** Central, bounded discovery layer over native 4.0 capabilities and the mapped 2.0 catalog. */
final class PRSTUDIO_UC_Capability_Registry {
    public const VERSION = '1.0.0';
    private const DEFAULT_LIMIT = 12;
    private const MAX_LIMIT = 25;
    private static ?array $cache = null;
    private static ?array $search_index = null;
    private static ?array $compact_document = null;
    private static ?array $legacy_direct_contracts = null;
    private static ?array $enterprise_contracts = null;

    private static function path(): string {
        return ( defined( 'PRSTUDIO_UC_DIR' ) ? PRSTUDIO_UC_DIR : dirname( __DIR__ ) . '/' ) . 'capabilities/capability-registry.json';
    }
    private static function compact_path(): string {
        return ( defined( 'PRSTUDIO_UC_DIR' ) ? PRSTUDIO_UC_DIR : dirname( __DIR__ ) . '/' ) . 'capabilities/capability-search-index.json';
    }
    private static function overlay_path(): string {
        return ( defined( 'PRSTUDIO_UC_DIR' ) ? PRSTUDIO_UC_DIR : dirname( __DIR__ ) . '/' ) . 'capabilities/agency-capabilities.json';
    }
    private static function enterprise_contract_path(): string {
        return ( defined( 'PRSTUDIO_UC_DIR' ) ? PRSTUDIO_UC_DIR : dirname( __DIR__ ) . '/' ) . 'capabilities/enterprise-capability-contracts.json';
    }
    private static function overlay(): array {
        $raw=is_readable(self::overlay_path())?(string)file_get_contents(self::overlay_path()):'';
        $decoded=''!==$raw?json_decode($raw,true):array();
        return is_array($decoded)?array_values((array)($decoded['capabilities']??array())):array();
    }
    /** Capability-specific semantic contract migrations keyed by canonical capability id. */
    private static function enterprise_contracts(): array {
        if ( is_array( self::$enterprise_contracts ) ) { return self::$enterprise_contracts; }
        $raw=is_readable(self::enterprise_contract_path())?(string)file_get_contents(self::enterprise_contract_path()):'';
        $decoded=''!==$raw?json_decode($raw,true):array();
        if(!is_array($decoded)){self::$enterprise_contracts=array();return self::$enterprise_contracts;}
        $dialect=(string)($decoded['json_schema_dialect']??'');$out=array();
        foreach((array)($decoded['contracts']??array()) as $id=>$contract){
            if(!is_array($contract))continue;
            $contract['json_schema_dialect']=$dialect;$out[strtolower((string)$id)]=$contract;
        }
        self::$enterprise_contracts=$out;
        return self::$enterprise_contracts;
    }
    /** Canonical runtime annotations for the old direct-tool compatibility set. */
    private static function legacy_direct_contracts(): array {
        if ( is_array( self::$legacy_direct_contracts ) ) { return self::$legacy_direct_contracts; }
        $map = array();
        if ( class_exists( 'WPAIB_MCP' ) ) {
            try {
                $tools = is_callable( array( 'WPAIB_MCP', 'all_tools' ) ) ? WPAIB_MCP::all_tools() : WPAIB_MCP::tools();
            } catch ( Throwable $ignored ) {
                try { $tools = WPAIB_MCP::tools(); } catch ( Throwable $also_ignored ) { $tools = array(); }
            }
            try {
                foreach ( (array) $tools as $tool ) {
                    if ( ! is_array( $tool ) || empty( $tool['name'] ) ) { continue; }
                    $annotations = is_array( $tool['annotations'] ?? null ) ? $tool['annotations'] : array();
                    $map[ (string) $tool['name'] ] = array(
                        'read_only'  => (bool) ( $annotations['readOnlyHint'] ?? false ),
                        'destructive'=> (bool) ( $annotations['destructiveHint'] ?? false ),
                        'idempotent' => (bool) ( $annotations['idempotentHint'] ?? false ),
                        'input_schema'=> is_array( $tool['inputSchema'] ?? null ) ? $tool['inputSchema'] : array( 'type'=>'object', 'additionalProperties'=>true ),
                        'output_schema'=> is_array( $tool['outputSchema'] ?? null ) ? $tool['outputSchema'] : array( 'type'=>'object', 'additionalProperties'=>true ),
                    );
                }
            } catch ( Throwable $ignored ) {
                // Missing callable mappings return a typed technical execution error below.
            }
        }
        self::$legacy_direct_contracts = $map;
        return $map;
    }
    /**
     * Correct generated compatibility metadata at the runtime authority.
     * Generated JSON remains an index; executable WPAIB_MCP annotations are the
     * source of truth for auth, lane and risk decisions.
     */
    private static function normalize_capability( array $cap ): array {
        $source = is_array( $cap['source'] ?? null ) ? $cap['source'] : array();
        if ( 'legacy_direct_tool' === (string) ( $source['kind'] ?? '' ) ) {
            $tool_name = (string) ( $source['tool_name'] ?? '' );
            $contract = self::legacy_direct_contracts()[ $tool_name ] ?? null;
            if ( is_array( $contract ) ) {
                $cap['read_only'] = $contract['read_only'];
                $cap['destructive'] = $contract['destructive'];
                $cap['idempotent'] = $contract['idempotent'];
                $cap['risk_level'] = $contract['read_only'] ? 'low' : ( $contract['destructive'] ? 'critical' : 'medium' );
                $cap['write'] = ! $contract['read_only'];
                $cap['concurrency_policy'] = $contract['read_only'] ? 'parallel_read' : 'exclusive_resource';
                $cap['input_schema'] = $contract['input_schema'];
                $cap['output_schema'] = $contract['output_schema'];
            } else {
                // Unknown compatibility mappings must never inherit a stale
                // read-only green light.
                $cap['read_only'] = false;
                $cap['risk_level'] = 'high';
                $cap['write'] = true;
                $cap['concurrency_policy'] = 'exclusive_resource';
            }
        }

        if ( 'legacy_action' === (string) ( $source['kind'] ?? '' ) && class_exists( 'PRSTUDIO_Agency' ) ) {
            try {
                $contract = PRSTUDIO_Agency::control_action_by_route(
                    (string) ( $source['route'] ?? '' ),
                    (string) ( $source['action'] ?? '' )
                );
            } catch ( Throwable $ignored ) {
                $contract = null;
            }
            if ( is_array( $contract ) ) {
                $read_only = ! empty( $contract['read_only'] );
                $destructive = ! empty( $contract['destructive'] );
                $risk = strtolower( (string) ( $contract['risk'] ?? '' ) );
                if ( ! in_array( $risk, array( 'low', 'medium', 'high', 'critical' ), true ) ) {
                    $risk = $read_only ? 'low' : ( $destructive ? 'critical' : 'medium' );
                }
                $cap['read_only'] = $read_only;
                $cap['risk_level'] = $risk;
                $cap['destructive'] = $destructive;
                $cap['idempotent'] = ! empty( $contract['idempotent'] );
                $cap['write'] = ! $read_only;
                $cap['concurrency_policy'] = $read_only ? 'parallel_read' : 'exclusive_resource';
                if ( is_array( $contract['input_schema'] ?? null ) ) { $cap['input_schema'] = $contract['input_schema']; }
                if ( is_array( $contract['output_schema'] ?? null ) ) { $cap['output_schema'] = $contract['output_schema']; }
            }
        }
        $id = strtolower( (string) ( $cap['id'] ?? '' ) );
        $enterprise = self::enterprise_contracts()[ $id ] ?? null;
        if ( is_array( $enterprise ) ) {
            foreach ( array( 'description', 'input_schema', 'output_schema' ) as $field ) {
                if ( array_key_exists( $field, $enterprise ) ) { $cap[ $field ] = $enterprise[ $field ]; }
            }
            $cap['enterprise_contract_version'] = (string) ( $enterprise['contract_version'] ?? '' );
            $cap['json_schema_dialect'] = (string) ( $enterprise['json_schema_dialect'] ?? '' );
            $cap['error_contract'] = array_values( array_filter( (array) ( $enterprise['error_contract'] ?? array() ), 'is_array' ) );
            $cap['tool_annotations'] = is_array( $enterprise['annotations'] ?? null ) ? $enterprise['annotations'] : array();
        }
        if ( class_exists( 'PRSTUDIO_UC_Execution_Router' ) ) { $cap = PRSTUDIO_UC_Execution_Router::annotate_capability( $cap ); }
        return $cap;
    }
    private static function compact_document(): array {
        if ( is_array( self::$compact_document ) ) { return self::$compact_document; }
        $raw=is_readable(self::compact_path())?(string)file_get_contents(self::compact_path()):'';
        $decoded=''!==$raw?json_decode($raw,true):array();
        self::$compact_document=is_array($decoded)?$decoded:array('items'=>array());
        $items=array();
        foreach((array)(self::$compact_document['items']??array()) as $cap){if(is_array($cap)&&!empty($cap['id'])){$cap=self::normalize_capability($cap);$items[(string)$cap['id']]=$cap;}}
        foreach(self::overlay() as $cap){
            if(!is_array($cap)||empty($cap['id']))continue;
            $cap=self::normalize_capability($cap);
            $items[(string)$cap['id']]=array_intersect_key($cap,array_flip(array('id','version','domain','description','read_only','risk_level','browser_required','gsc_required','estimated_cost','source','executor','execution_class','preferred_executor','estimated_work','supports_flow','can_execute_inline','minimal_verification')));
        }
        self::$compact_document['items']=array_values($items);
        self::$compact_document['count']=count($items);
        self::$compact_document['suite_version']=self::VERSION;
        self::$compact_document['registry_hash']=hash('sha256',json_encode(self::$compact_document['items'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return self::$compact_document;
    }
    public static function document(): array {
        if ( is_array( self::$cache ) ) { return self::$cache; }
        $raw = is_readable( self::path() ) ? (string) file_get_contents( self::path() ) : '';
        $decoded = '' !== $raw ? json_decode( $raw, true ) : array();
        self::$cache = is_array( $decoded ) ? $decoded : array( 'capabilities'=>array(), 'counts'=>array() );
        $capabilities=array();
        foreach((array)(self::$cache['capabilities']??array()) as $cap){if(is_array($cap)&&!empty($cap['id'])){$cap=self::normalize_capability($cap);$capabilities[(string)$cap['id']]=$cap;}}
        foreach(self::overlay() as $cap){if(is_array($cap)&&!empty($cap['id'])){$cap=self::normalize_capability($cap);$capabilities[(string)$cap['id']]=$cap;}}
        self::$cache['capabilities']=array_values($capabilities);
        $native=count(array_filter($capabilities,static fn($cap)=>'native'===(string)($cap['source']['kind']??'')));
        self::$cache['counts']=array('capabilities'=>count($capabilities),'native'=>$native,'legacy_mapped'=>count($capabilities)-$native);
        self::$cache['suite_version']=self::VERSION;
        self::$cache['registry_hash']=hash('sha256',json_encode(self::$cache['capabilities'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return self::$cache;
    }
    public static function all(): array { return array_values( (array) ( self::document()['capabilities'] ?? array() ) ); }
    public static function counts(): array { return (array) ( self::document()['counts'] ?? array() ); }
    public static function hash(): string { return (string) ( self::document()['registry_hash'] ?? '' ); }
    private static function normalize( string $value ): string {
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        if ( function_exists( 'remove_accents' ) ) {
            $value = remove_accents( $value );
        } elseif ( function_exists( 'iconv' ) ) {
            $ascii = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
            if ( false !== $ascii ) { $value = $ascii; }
        }
        $value = preg_replace( '/[^a-z0-9.]+/', ' ', $value );
        return trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
    }
    private static function tokens( string $value ): array {
        $tokens = array_values( array_unique( array_filter( explode( ' ', str_replace( '.', ' ', self::normalize( $value ) ) ), static fn( $x ) => strlen( (string) $x ) >= 2 ) ) );
        return array_slice( $tokens, 0, 32 );
    }
    /**
     * What a query means, as units of intent rather than as words.
     *
     * PRSTUDIO_UC_Action_Lexicon groups words by meaning across both languages,
     * so "elimina un file" and "delete a file" arrive here as the same list of
     * concepts. Everything below scores concepts, never the raw words, and that
     * is the whole reason the two languages return the same rows in the same
     * order instead of merely similar ones.
     *
     * @param string $value Normalised query.
     * @return array<int,array<int,string>>
     */
    private static function query_concepts( string $value ): array {
        return array_slice( PRSTUDIO_UC_Action_Lexicon::concepts( self::tokens( $value ) ), 0, 32 );
    }
    /**
     * Whether one unit of intent is satisfied by one field of a capability.
     *
     * A concept holds every catalog token that satisfies it, so any single hit
     * settles it. Counting the hits instead would let a concept that happens to
     * name four tokens outweigh one that names a single token, which would make
     * a capability's rank depend on how richly this vocabulary happens to
     * describe the word the operator used.
     *
     * @param array<string,bool>  $field_tokens Token set of id, tool name or description.
     * @param array<int,string>   $concept      Catalog tokens for one meaning.
     * @return bool
     */
    private static function concept_matches( array $field_tokens, array $concept ): bool {
        foreach ( $concept as $token ) {
            if ( isset( $field_tokens[ $token ] ) ) { return true; }
        }
        return false;
    }
    private static function executor_name_available( string $executor ): bool {
        if(''===$executor||!str_contains($executor,'::'))return false;
        [$class,$method]=explode('::',$executor,2);
        return class_exists($class) && is_callable(array($class,$method));
    }
    private static function executor_available( array $cap ): bool {
        return self::executor_name_available((string)($cap['executor']??''));
    }
    public static function is_executable( string $id ): bool { $cap=self::get($id); return is_array($cap) && self::executor_available($cap); }
    public static function get( string $id ): ?array {
        $id = strtolower( trim( $id ) );
        foreach ( self::all() as $cap ) {
            if ( strtolower( (string) ( $cap['id'] ?? '' ) ) === $id ) { return $cap; }
            $source = (array) ( $cap['source'] ?? array() );
            if ( strtolower( (string) ( $source['tool_name'] ?? '' ) ) === $id ) { return $cap; }
        }
        return null;
    }
    private static function search_index(): array {
        if ( is_array( self::$search_index ) ) { return self::$search_index; }
        $rows=array(); $postings=array();
        foreach((array)(self::compact_document()['items']??array()) as $i=>$cap){
            $id=self::normalize((string)($cap['id']??'')); $desc=self::normalize((string)($cap['description']??'')); $source=self::normalize((string)($cap['source']['tool_name']??'')); $text=$id.' '.$source.' '.$desc;
            $rows[$i]=array(
                'cap'=>$cap, 'id'=>$id, 'desc'=>$desc, 'source'=>$source, 'text'=>$text,
                'id_tokens'=>array_fill_keys(self::tokens($id),true),
                'desc_tokens'=>array_fill_keys(self::tokens($desc),true),
                'source_tokens'=>array_fill_keys(self::tokens($source),true),
            );
            foreach(self::tokens($text) as $token){$postings[$token][$i]=true;}
        }
        self::$search_index=array('rows'=>$rows,'postings'=>$postings); return self::$search_index;
    }
    public static function search( string $query, array $filters = array() ): array {
        $q = self::normalize( $query ); $concepts = self::query_concepts( $q );
        $limit = max( 1, min( self::MAX_LIMIT, (int) ( $filters['limit'] ?? self::DEFAULT_LIMIT ) ) );
        $domain = strtolower( trim( (string) ( $filters['domain'] ?? '' ) ) );
        $include_legacy = array_key_exists( 'include_legacy', $filters ) ? (bool) $filters['include_legacy'] : true;
        $index=self::search_index(); $candidate_ids=array();
        if(''===$q){$candidate_ids=array_fill_keys(array_keys($index['rows']),true);}else{foreach(PRSTUDIO_UC_Action_Lexicon::catalog_tokens($concepts) as $token){if(isset($index['postings'][$token])){$candidate_ids += $index['postings'][$token];}}}
        $scored = array();
        foreach ( array_keys($candidate_ids) as $idx ) { $row=$index['rows'][$idx]??null; if(!$row)continue; $cap=$row['cap'];
            if ( ! $include_legacy && 'native' !== (string) ( $cap['source']['kind'] ?? '' ) ) { continue; }
            if ( '' !== $domain && strtolower( (string) ( $cap['domain'] ?? '' ) ) !== $domain ) { continue; }
            if(!self::executor_name_available((string)($cap['executor']??''))){continue;}
            $score = 0; $covered_specific = 0; $covered_any = 0; $source_matches = 0;
            if ( '' === $q ) { $score += 1; }
            foreach ( $concepts as $concept ) {
                $in_id     = self::concept_matches( $row['id_tokens'], $concept );
                $in_source = self::concept_matches( $row['source_tokens'], $concept );
                $in_desc   = self::concept_matches( $row['desc_tokens'], $concept );
                if ( $in_id ) { $score += 16; }
                elseif ( $in_source ) { $score += 12; }
                elseif ( $in_desc ) { $score += 4; }
                if ( $in_id || $in_source ) { ++$covered_specific; ++$covered_any; }
                elseif ( $in_desc ) { ++$covered_any; }
                if ( $in_source ) { ++$source_matches; }
            }
            // Answering the whole request beats answering part of it loudly.
            //
            // This replaces a bonus for containing the query as a literal
            // phrase, which could only ever fire for the language the catalog
            // happens to be written in: "list orders" matched the id
            // legacy.direct.list-orders and collected 100 points, while
            // "elenca gli ordini" -- the same request -- collected none, and
            // the two languages ranked differently for a reason that had
            // nothing to do with what was being asked. Coverage asks the same
            // question of both: is every unit of intent accounted for.
            $total = count( $concepts );
            if ( 0 < $total && $covered_specific === $total ) { $score += 100; }
            elseif ( 0 < $total && $covered_any === $total ) { $score += 60; }
            if ( $score <= 0 ) { continue; }
            $kind = (string) ( $cap['source']['kind'] ?? '' );
            $specific_tokens = $row['source_tokens'] ?: $row['id_tokens'];
            $specific_matches = 0;
            foreach ( $concepts as $concept ) {
                if ( self::concept_matches( $specific_tokens, $concept ) ) { ++$specific_matches; }
            }
            $scored[] = array(
                'score'=>$score,
                'native'=>'native' === $kind,
                'legacy_action'=>'legacy_action' === $kind,
                'source_matches'=>$source_matches,
                'extra_tokens'=>max( 0, count( $specific_tokens ) - $specific_matches ),
                'cap'=>$cap,
            );
        }
        usort( $scored, static function ( array $a, array $b ): int {
            $cmp = (int) $b['score'] <=> (int) $a['score'];
            if ( 0 !== $cmp ) { return $cmp; }
            $native_cmp = (int) $b['native'] <=> (int) $a['native'];
            if ( 0 !== $native_cmp ) { return $native_cmp; }
            $source_cmp = (int) $b['source_matches'] <=> (int) $a['source_matches'];
            if ( 0 !== $source_cmp ) { return $source_cmp; }
            $canonical_cmp = (int) $b['legacy_action'] <=> (int) $a['legacy_action'];
            if ( 0 !== $canonical_cmp ) { return $canonical_cmp; }
            $specificity_cmp = (int) $a['extra_tokens'] <=> (int) $b['extra_tokens'];
            return 0 !== $specificity_cmp ? $specificity_cmp : strcmp( (string) $a['cap']['id'], (string) $b['cap']['id'] );
        } );
        $items = array();
        foreach ( array_slice( $scored, 0, $limit ) as $row ) {
            $c = $row['cap'];
            $items[] = array(
                'id'=>(string)$c['id'], 'version'=>(string)$c['version'], 'domain'=>(string)$c['domain'],
                'description'=>(string)$c['description'], 'read_only'=>(bool)$c['read_only'], 'risk_level'=>(string)$c['risk_level'],
                'browser_required'=>(bool)$c['browser_required'], 'gsc_required'=>(bool)$c['gsc_required'],
                'estimated_cost'=>(string)$c['estimated_cost'], 'score'=>(int)$row['score'],
            );
        }
        return array( 'query'=>$query, 'count'=>count($items), 'items'=>$items, 'registry_hash'=>(string)(self::compact_document()['registry_hash']??self::hash()), 'total_capabilities'=>(int)(self::compact_document()['count']??self::counts()['capabilities']??0) );
    }
    public static function describe( string $id ): ?array {
        $cap = self::get( $id ); if ( ! $cap || ! self::executor_available($cap) ) { return null; }
        return array(
            'id'=>$cap['id'], 'version'=>$cap['version'], 'domain'=>$cap['domain'], 'description'=>$cap['description'],
            'input_schema'=>$cap['input_schema'], 'output_schema'=>$cap['output_schema'], 'read_only'=>$cap['read_only'], 'risk_level'=>$cap['risk_level'],
            'destructive'=>$cap['destructive'], 'idempotent'=>$cap['idempotent'], 'dependencies'=>$cap['dependencies'],
            'browser_required'=>$cap['browser_required'], 'gsc_required'=>$cap['gsc_required'], 'memory_policy'=>$cap['memory_policy'],
            'cache_policy'=>$cap['cache_policy'], 'verification_policy'=>$cap['verification_policy'], 'evidence_policy'=>$cap['evidence_policy'],
            'snapshot_policy'=>$cap['snapshot_policy'], 'rollback_capability'=>$cap['rollback_capability'], 'estimated_cost'=>$cap['estimated_cost'],
            'concurrency_policy'=>$cap['concurrency_policy'],
            'execution_class'=>(string)($cap['execution_class']??''), 'preferred_executor'=>(string)($cap['preferred_executor']??''),
            'estimated_work'=>(string)($cap['estimated_work']??''), 'supports_flow'=>!empty($cap['supports_flow']), 'can_execute_inline'=>!empty($cap['can_execute_inline']),
            'minimal_verification'=>(string)($cap['minimal_verification']??''),
            'enterprise_contract_version'=>(string)($cap['enterprise_contract_version']??''),
            'json_schema_dialect'=>(string)($cap['json_schema_dialect']??''),
            'error_contract'=>array_values((array)($cap['error_contract']??array())),
            'tool_annotations'=>(array)($cap['tool_annotations']??array()),
            'prerequisites'=>array_values( array_filter( array( !empty($cap['browser_required'])?'browser_agent_online':'', !empty($cap['gsc_required'])?'gsc_provider_available':'' ) ) ),
        );
    }
    public static function consistency(): array {
        $ids=array(); $duplicates=array(); $missing_executor=array(); $bad=array();
        foreach ( self::all() as $cap ) {
            $id=(string)($cap['id']??''); if(isset($ids[$id])){$duplicates[]=$id;} $ids[$id]=true;
            $executor=(string)($cap['executor']??''); if(''===$executor||!self::executor_available($cap)){$missing_executor[]=$id;}
            foreach(array('id','version','domain','description','input_schema','output_schema','executor','read_only','risk_level','destructive','idempotent','dependencies','browser_required','gsc_required','memory_policy','cache_policy','verification_policy','evidence_policy','snapshot_policy','rollback_capability','estimated_cost','concurrency_policy') as $key){if(!array_key_exists($key,$cap)){$bad[]=$id.':'.$key;}}
        }
        return array('ok'=>empty($duplicates)&&empty($missing_executor)&&empty($bad),'count'=>count($ids),'duplicate_ids'=>$duplicates,'missing_executor'=>$missing_executor,'missing_fields'=>$bad,'registry_hash'=>self::hash());
    }
}
