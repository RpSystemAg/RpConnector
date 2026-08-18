<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Anti_Crash_Attestation {
    public const VERSION='1.0.0';
    private const OPTION='prstudio_uc_anti_crash_attestations_v1';
    /**
     * How long an attestation may be reused.
     *
     * Was 14400 (4h) while Anti_Crash::requirements() declares evidence fresh
     * for 7200s. Reuse could therefore outlive the stated freshness policy by
     * two hours, so the guard's own contract and its behaviour disagreed. The
     * shorter of the two wins: a policy the code does not honour is not a policy.
     */
    private const TTL=7200;

    /** Per-request memo; the fingerprint cannot change mid-request. */
    private static $fingerprint_memo = null;

    /**
     * Identify the code an attestation was granted against.
     *
     * This previously hashed three anchor files: the plugin bootstrap, the
     * capability overlay and the contract. Changing any executor -- the content
     * transaction, the database backend, the bridge -- left all three untouched,
     * so an attestation granted against the old code stayed valid against the
     * new. For the suite's only mutation guard that is the wrong failure
     * direction: the guard silently keeps trusting evidence for code that no
     * longer exists.
     *
     * The fingerprint now covers every PHP file the plugin loads, by size and
     * modification time. Reading 126 files on a mutation path would be the wrong
     * trade, so this stats them instead and memoises per request: any edit,
     * deploy or rollback moves mtime or size and invalidates outstanding
     * attestations, which is exactly the intent.
     */
    private static function fingerprint():string{
        if ( null !== self::$fingerprint_memo ) { return self::$fingerprint_memo; }

        $anchors=array();
        foreach(array('prstudio-unified-control.php','capabilities/agency-capabilities.json','contract/capability-contract.json') as $rel){
            $p=PRSTUDIO_UC_DIR.$rel;
            $anchors[$rel]=is_readable($p)?hash_file('sha256',$p):'missing';
        }

        $code=array();
        $includes=PRSTUDIO_UC_DIR.'includes';
        if(is_dir($includes)){
            $files=glob($includes.'/*.php');
            if(is_array($files)){
                sort($files);
                foreach($files as $file){
                    $code[basename($file)]=(int)@filesize($file).':'.(int)@filemtime($file);
                }
            }
        }

        self::$fingerprint_memo = hash('sha256',wp_json_encode(array('anchors'=>$anchors,'code'=>$code)));
        return self::$fingerprint_memo;
    }
    private static function scope_for_tool(string $tool):string{
        if(str_contains($tool,'files')||str_contains($tool,'theme')||str_contains($tool,'plugin')||str_contains($tool,'filesystem'))return 'filesystem';
        if(str_contains($tool,'database'))return 'database';
        if(str_contains($tool,'browser')||str_contains($tool,'frontend'))return 'browser';
        return 'wordpress';
    }
    public static function store(array $record,array $work=array()):array{
        if('passed'!==(string)($record['status']??''))return array('ok'=>false,'stored'=>false,'reason'=>'record_not_passed');$scope=implode(',',array_values(array_unique((array)($work['scope']??array('wordpress')))));$all=get_option(self::OPTION,array());if(!is_array($all))$all=array();$key=hash('sha256',$scope.'|'.self::fingerprint());$all[$key]=array('scope'=>$scope,'fingerprint'=>self::fingerprint(),'evidence_sha256'=>(string)($record['evidence_sha256']??''),'created_at'=>time(),'expires_at'=>time()+self::TTL);update_option(self::OPTION,array_slice($all,-32,null,true),false);return array('ok'=>true,'stored'=>true,'key'=>$key,'expires_gmt'=>gmdate('c',time()+self::TTL));
    }
    public static function reusable(string $tool_name,array $args=array()):array{
        $scope = self::scope_for_tool( $tool_name );
        $all = get_option( self::OPTION, array() );
        if ( ! is_array( $all ) ) { $all = array(); }
        $fp = self::fingerprint();
        $now = time();

        foreach ( $all as $row ) {
            if ( (int) ( $row['expires_at'] ?? 0 ) <= $now ) { continue; }
            if ( ! hash_equals( (string) ( $row['fingerprint'] ?? '' ), $fp ) ) { continue; }

            $scopes = array_filter( explode( ',', (string) ( $row['scope'] ?? '' ) ) );

            // `suite` is the only wildcard: it means the operator attested the
            // whole surface deliberately. `wordpress` used to be accepted here as
            // a second wildcard, which silently defeated scope separation --
            // wordpress is also the DEFAULT scope for anything unclassified, so
            // nearly every attestation carried it, and a still-valid WordPress
            // attestation was therefore reusable for filesystem, database and
            // browser mutations that were never attested at all.
            //
            // In an architecture where the anti-crash test is the only mutation
            // guard, a scope that leaks across domains is the guard silently not
            // running. Exact match or an explicit `suite` attestation only.
            if ( ! in_array( $scope, $scopes, true ) && ! in_array( 'suite', $scopes, true ) ) { continue; }

            return array(
                'ok' => true,
                'reused' => true,
                'scope' => $scope,
                'matched_scope' => in_array( $scope, $scopes, true ) ? $scope : 'suite',
                'evidence_sha256' => $row['evidence_sha256'] ?? '',
                'expires_gmt' => gmdate( 'c', (int) $row['expires_at'] ),
            );
        }
        return array( 'ok' => false, 'reused' => false, 'scope' => $scope );
    }
    public static function status():array{$all=get_option(self::OPTION,array());$now=time();$active=array_values(array_filter(is_array($all)?$all:array(),static fn($r)=>(int)($r['expires_at']??0)>$now));return array('version'=>self::VERSION,'active'=>count($active),'fingerprint'=>self::fingerprint(),'ttl_seconds'=>self::TTL);}
}
