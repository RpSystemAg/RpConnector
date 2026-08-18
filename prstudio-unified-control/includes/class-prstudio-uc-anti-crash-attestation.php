<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Anti_Crash_Attestation {
    public const VERSION='1.0.0';
    private const OPTION='prstudio_uc_anti_crash_attestations_v1';
    private const TTL=14400;
    private static function fingerprint():string{
        $anchors=array();foreach(array('prstudio-unified-control.php','capabilities/agency-capabilities.json','contract/capability-contract.json') as $rel){$p=PRSTUDIO_UC_DIR.$rel;$anchors[$rel]=is_readable($p)?hash_file('sha256',$p):'missing';}
        return hash('sha256',wp_json_encode($anchors));
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
        $scope=self::scope_for_tool($tool_name);$all=get_option(self::OPTION,array());if(!is_array($all))$all=array();$fp=self::fingerprint();$now=time();foreach($all as $row){if((int)($row['expires_at']??0)<=$now)continue;if(!hash_equals((string)($row['fingerprint']??''),$fp))continue;$scopes=array_filter(explode(',',(string)($row['scope']??'')));if(!in_array($scope,$scopes,true)&&!in_array('suite',$scopes,true)&&!in_array('wordpress',$scopes,true))continue;return array('ok'=>true,'reused'=>true,'scope'=>$scope,'evidence_sha256'=>$row['evidence_sha256']??'','expires_gmt'=>gmdate('c',(int)$row['expires_at']));}return array('ok'=>false,'reused'=>false,'scope'=>$scope);
    }
    public static function status():array{$all=get_option(self::OPTION,array());$now=time();$active=array_values(array_filter(is_array($all)?$all:array(),static fn($r)=>(int)($r['expires_at']??0)>$now));return array('version'=>self::VERSION,'active'=>count($active),'fingerprint'=>self::fingerprint(),'ttl_seconds'=>self::TTL);}
}
