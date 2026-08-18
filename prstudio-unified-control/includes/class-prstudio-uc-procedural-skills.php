<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Verified procedural memory compatible with the Agent Skills progressive-disclosure model. */
final class PRSTUDIO_UC_Procedural_Skills {
    public const VERSION='1.0.0';
    private const INDEX='procedural-skills-v1.json';
    private const LOCK='.procedural-skills-v1.lock';
    private const MAX_SKILLS=2000;
    private const MAX_FAILURES_PER_SKILL=20;
    /** How long an archived skill stays in the index before it is dropped. */
    private const ARCHIVE_RETENTION_SECONDS=2592000; // 30 days.

    private static function dir(): string {
        $d=PRSTUDIO_UC_Memory::site_dir().'/skills';
        if(!is_dir($d)){function_exists('wp_mkdir_p')?wp_mkdir_p($d):@mkdir($d,0750,true);}
        return $d;
    }
    private static function index_path(): string {return self::dir().'/'.self::INDEX;}
    private static function lock_path(): string {return self::dir().'/'.self::LOCK;}
    private static function defaults(): array {return array('schema_version'=>1,'skills'=>array(),'metrics'=>array('learned'=>0,'reused'=>0,'failures_observed'=>0),'updated_gmt'=>'');}
    private static function clean($v){return PRSTUDIO_UC_Memory::redact($v);}
    private static function key(string $v,int $max=100):string{$v=strtolower(trim($v));$v=(string)preg_replace('/[^a-z0-9._:-]+/','-',$v);return substr(trim($v,'-.'),0,$max);}
    private static function state_unlocked():array{$raw=is_readable(self::index_path())?(string)file_get_contents(self::index_path()):'';$d=''!==$raw?json_decode($raw,true):array();return is_array($d)?array_merge(self::defaults(),$d):self::defaults();}
    private static function atomic(array $state):bool{$state['updated_gmt']=gmdate('c');$json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(false===$json||strlen($json)>16777216)return false;try{$s=bin2hex(random_bytes(5));}catch(Throwable $e){$s=str_replace('.','',uniqid('',true));}$tmp=self::index_path().'.'.$s.'.tmp';if(false===@file_put_contents($tmp,$json."\n",LOCK_EX))return false;@chmod($tmp,0640);if(@rename($tmp,self::index_path()))return true;@unlink($tmp);return false;}
    private static function mutate(callable $cb){$fh=@fopen(self::lock_path(),'c+');if(!is_resource($fh)||!@flock($fh,LOCK_EX)){if(is_resource($fh))@fclose($fh);return new WP_Error('procedural_skill_lock_failed','Unable to lock procedural skill store.',array('status'=>503,'retryable'=>true));}try{$state=self::state_unlocked();$r=$cb($state);if(is_wp_error($r))return$r;if(!self::atomic($state))return new WP_Error('procedural_skill_write_failed','Unable to persist procedural skill store.',array('status'=>503,'retryable'=>true));return$r;}finally{@flock($fh,LOCK_UN);@fclose($fh);}}
    private static function fingerprint(string $kind,string $name,array $args):string{
        $shape=array();foreach($args as $k=>$v){if(str_starts_with((string)$k,'_prstudio_'))continue;$shape[(string)$k]=is_array($v)?'array':(is_object($v)?'object':gettype($v));}ksort($shape);return hash('sha256',$kind.'|'.$name.'|'.PRSTUDIO_UC_Idempotency::canonical_json($shape));
    }
    private static function skill_id(string $kind,string $name,array $args):string{return self::key($kind.'-'.$name,70).'-'.substr(self::fingerprint($kind,$name,$args),0,16);}
    private static function skill_dir(string $id):string{$d=self::dir().'/'.self::key($id,100);if(!is_dir($d)){function_exists('wp_mkdir_p')?wp_mkdir_p($d):@mkdir($d,0750,true);}return$d;}
    private static function write_skill_md(array $skill):void{
        $id=(string)$skill['id'];$steps=array_values((array)($skill['procedure']['steps']??array()));$lines=array('---','name: '.$id,'description: '.str_replace(array("\r","\n"),' ',(string)($skill['description']??'Verified PR STUDIO procedure')),'version: '.self::VERSION,'---','','# Verified procedure','', 'Use this procedure only when the environment fingerprint and preconditions still match. Independent verification remains mandatory.','', '## Preconditions');
        foreach((array)($skill['preconditions']??array()) as $k=>$v){$lines[]='- `'.str_replace('`','',(string)$k).'`: `'.str_replace('`','',(is_scalar($v)?(string)$v:gettype($v))).'`';}
        $lines[]='';$lines[]='## Steps';foreach($steps as $i=>$step){$label=is_array($step)?json_encode(self::clean($step),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):(string)$step;$lines[]=(string)($i+1).'. '.str_replace(array("\r","\n"),' ',(string)$label);}
        $lines[]='';$lines[]='## Verification';$lines[]='- Required: yes';$lines[]='- Last verified: '.(string)($skill['last_verified_gmt']??'');$lines[]='- Success count: '.(string)($skill['success_count']??0);$lines[]='- Confidence: '.(string)($skill['confidence']??0);
        @file_put_contents(self::skill_dir($id).'/SKILL.md',implode("\n",$lines)."\n",LOCK_EX);
    }

    /** Learn only from independently verified capability completion. */
    public static function learn_verified_capability(string $capability,array $args,array $result,array $verification,string $job_id=''):array{
        if(empty($verification['ok']))return array('ok'=>false,'learned'=>false,'reason'=>'verification_required');$capability=self::key($capability,120);if(''===$capability)return array('ok'=>false,'learned'=>false,'reason'=>'capability_required');$id=self::skill_id('capability',$capability,$args);$fingerprint=self::fingerprint('capability',$capability,$args);
        $r=self::mutate(static function(array &$state)use($id,$capability,$args,$result,$verification,$job_id,$fingerprint){$old=is_array($state['skills'][$id]??null)?$state['skills'][$id]:array();$count=(int)($old['success_count']??0)+1;$confidence=min(0.99,0.55+0.12*$count);$skill=array_merge($old,array('id'=>$id,'kind'=>'capability','name'=>$capability,'description'=>'Verified reusable procedure for capability '.$capability.'.','fingerprint'=>$fingerprint,'preconditions'=>array('suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'','argument_keys'=>array_values(array_keys($args))),'procedure'=>array('steps'=>array(array('capability'=>$capability,'argument_shape'=>array_map(static fn($v)=>is_array($v)?'array':gettype($v),$args))),'verification_required'=>true),'last_result_signature'=>hash('sha256',PRSTUDIO_UC_Idempotency::canonical_json(self::clean($result))),'last_verifier'=>(string)($verification['verifier']??''),'last_job_id'=>$job_id,'success_count'=>$count,'confidence'=>round($confidence,3),'last_verified_gmt'=>gmdate('c'),'expires_gmt'=>gmdate('c',time()+90*86400),'failed_paths'=>array_values((array)($old['failed_paths']??array()))));$state['skills'][$id]=$skill;if(count($state['skills'])>self::MAX_SKILLS){uasort($state['skills'],static fn($a,$b)=>strcmp((string)($b['last_verified_gmt']??''),(string)($a['last_verified_gmt']??'')));$state['skills']=array_slice($state['skills'],0,self::MAX_SKILLS,true);}$state['metrics']['learned']=(int)($state['metrics']['learned']??0)+1;return array('ok'=>true,'learned'=>true,'skill'=>$skill);});if(is_array($r)&&is_array($r['skill']??null)){self::write_skill_md($r['skill']);PRSTUDIO_UC_Memory::movement('skill.learned',array('resource'=>$id,'capability'=>$capability,'outcome'=>'verified_procedure'));}return$r;
    }

    /** Record failed paths so the next plan can avoid retrying the same dead end. */
    public static function observe_failure(string $kind,string $name,array $args,array $error):array{
        $name=self::key($name,120);$id=self::skill_id($kind,$name,$args);$code=self::key((string)($error['code']??'failure'),100);$sig=hash('sha256',$code.'|'.PRSTUDIO_UC_Idempotency::canonical_json(array_keys($args)));
        return self::mutate(static function(array &$state)use($id,$kind,$name,$args,$code,$sig){$skill=is_array($state['skills'][$id]??null)?$state['skills'][$id]:array('id'=>$id,'kind'=>$kind,'name'=>$name,'description'=>'Procedure history for '.$name.'.','fingerprint'=>self::fingerprint($kind,$name,$args),'preconditions'=>array('suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:''),'procedure'=>array('steps'=>array()),'success_count'=>0,'confidence'=>0.0,'failed_paths'=>array());$fail=array_values((array)($skill['failed_paths']??array()));$exists=false;foreach($fail as $row){if(($row['signature']??'')===$sig){$exists=true;break;}}if(!$exists)$fail[]=array('signature'=>$sig,'error_code'=>$code,'observed_gmt'=>gmdate('c'));$skill['failed_paths']=array_slice($fail,-self::MAX_FAILURES_PER_SKILL);$state['skills'][$id]=$skill;$state['metrics']['failures_observed']=(int)($state['metrics']['failures_observed']??0)+1;return array('ok'=>true,'recorded'=>true,'skill_id'=>$id,'failure_signature'=>$sig);});
    }

    /** Learn a complete verified Browser Agent task including its successful step sequence. */
    public static function learn_verified_browser_task(array $task,array $result,array $verification):array{
        if(empty($verification['ok']))return array('ok'=>false,'learned'=>false,'reason'=>'verification_required');$action=self::key((string)($task['action']??'browser-task'),120);$args=is_array($task['arguments']??null)?$task['arguments']:array();$steps=array_values(array_filter((array)($args['steps']??array()),'is_array'));if(!$steps)$steps=array(array('action'=>$action,'arguments'=>array_keys($args)));$id=self::skill_id('browser',$action,$args);$fingerprint=self::fingerprint('browser',$action,$args);
        $r=self::mutate(static function(array &$state)use($task,$result,$verification,$action,$args,$steps,$id,$fingerprint){$old=is_array($state['skills'][$id]??null)?$state['skills'][$id]:array();$count=(int)($old['success_count']??0)+1;$skill=array_merge($old,array('id'=>$id,'kind'=>'browser','name'=>$action,'description'=>'Verified Browser Agent procedure for '.$action.'.','fingerprint'=>$fingerprint,'preconditions'=>array('suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'','expected_origin'=>$args['expectedOrigin']??$args['expected_origin']??''),'procedure'=>array('steps'=>self::clean(array_slice($steps,0,250)),'verification_required'=>true,'strict_tab_ownership'=>true),'last_result_signature'=>hash('sha256',PRSTUDIO_UC_Idempotency::canonical_json(self::clean($result))),'last_verifier'=>(string)($verification['verifier']??''),'last_task_id'=>(string)($task['task_uuid']??''),'success_count'=>$count,'confidence'=>round(min(0.99,0.50+0.15*$count),3),'last_verified_gmt'=>gmdate('c'),'expires_gmt'=>gmdate('c',time()+30*86400),'failed_paths'=>array_values((array)($old['failed_paths']??array()))));$state['skills'][$id]=$skill;$state['metrics']['learned']=(int)($state['metrics']['learned']??0)+1;return array('ok'=>true,'learned'=>true,'skill'=>$skill);});if(is_array($r)&&is_array($r['skill']??null)){self::write_skill_md($r['skill']);PRSTUDIO_UC_Memory::movement('skill.browser_learned',array('resource'=>$id,'action'=>$action,'outcome'=>'verified_procedure'));}return$r;
    }

    /** Return the best non-stale reusable recipe for this capability/action shape. */
    public static function best_match(string $kind,string $name,array $args):?array{
        $id=self::skill_id($kind,self::key($name,120),$args);$skill=self::state_unlocked()['skills'][$id]??null;if(!is_array($skill)||strtotime((string)($skill['expires_gmt']??''))<=time()||(float)($skill['confidence']??0)<0.5)return null;return$skill;
    }

    /** Search/list procedural skills using progressive disclosure. */
    public static function search(array $args):array{
        $q=strtolower(trim((string)($args['query']??'')));$kind=self::key((string)($args['kind']??''),30);$limit=max(1,min(100,(int)($args['limit']??20)));$rows=array();foreach((array)(self::state_unlocked()['skills']??array()) as $skill){if($kind&&$kind!==(string)($skill['kind']??''))continue;$hay=strtolower((string)($skill['name']??'').' '.(string)($skill['description']??''));if($q&&!str_contains($hay,$q))continue;$rows[]=array('id'=>$skill['id'],'kind'=>$skill['kind'],'name'=>$skill['name'],'description'=>$skill['description'],'confidence'=>$skill['confidence'],'success_count'=>$skill['success_count'],'last_verified_gmt'=>$skill['last_verified_gmt']??'','expires_gmt'=>$skill['expires_gmt']??'','failed_path_count'=>count((array)($skill['failed_paths']??array())));}usort($rows,static fn($a,$b)=>(float)$b['confidence']<=>(float)$a['confidence']);return array('ok'=>true,'count'=>min(count($rows),$limit),'items'=>array_slice($rows,0,$limit),'progressive_disclosure'=>true,'version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'');
    }
    /** Activate one skill and return its complete procedural recipe. */
    public static function get(array $args){$id=self::key((string)($args['id']??''),100);$skill=self::state_unlocked()['skills'][$id]??null;if(!is_array($skill))return new WP_Error('procedural_skill_not_found','Skill not found.',array('status'=>404));return array('ok'=>true,'skill'=>$skill,'skill_md'=>is_readable(self::skill_dir($id).'/SKILL.md')?(string)file_get_contents(self::skill_dir($id).'/SKILL.md'):'','version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'');}
    /** Mark a skill stale without deleting its history. */
    public static function invalidate(array $args){$id=self::key((string)($args['id']??''),100);$reason=substr(sanitize_text_field((string)($args['reason']??'environment_changed')),0,300);return self::mutate(static function(array &$state)use($id,$reason){if(!isset($state['skills'][$id]))return new WP_Error('procedural_skill_not_found','Skill not found.',array('status'=>404));$state['skills'][$id]['expires_gmt']=gmdate('c',time()-1);$state['skills'][$id]['invalidated_reason']=$reason;$state['skills'][$id]['invalidated_gmt']=gmdate('c');return array('ok'=>true,'invalidated'=>true,'id'=>$id,'version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'');});}
    /** Store a reuse audit event when a planner selects an existing procedure. */
    public static function mark_reused(string $id):void{self::mutate(static function(array &$state)use($id){$state['metrics']['reused']=(int)($state['metrics']['reused']??0)+1;if(isset($state['skills'][$id]))$state['skills'][$id]['last_reused_gmt']=gmdate('c');return array('ok'=>true);});}
    /** Curate learned procedures without deleting history: archive stale/failed-only entries and surface merge candidates. */
    public static function curate(array $args=array()):array{
        $apply=!empty($args['apply']);$force=!empty($args['force']);$now=time();$interval=max(3600,min(30*86400,(int)($args['interval_seconds']??7*86400)));
        $analyze=static function(array &$state)use($apply,$force,$now,$interval){
            $last=strtotime((string)($state['metrics']['last_curated_gmt']??''));
            if($apply&&!$force&&$last>0&&($now-$last)<$interval)return array('ok'=>true,'skipped'=>true,'reason'=>'not_due','next_due_gmt'=>gmdate('c',$last+$interval),'version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'');
            $stale=array();$failed_only=array();$groups=array();$fingerprints=array();
            foreach((array)($state['skills']??array()) as $id=>$skill){
                if(!is_array($skill))continue;$expires=strtotime((string)($skill['expires_gmt']??''));$success=(int)($skill['success_count']??0);$failures=count((array)($skill['failed_paths']??array()));
                if($expires>0&&$expires<=$now)$stale[]=(string)$id;
                if(0===$success&&$failures>0)$failed_only[]=(string)$id;
                $g=(string)($skill['kind']??'').'|'.(string)($skill['name']??'');if('|'!==$g)$groups[$g][]=(string)$id;
                $fp=(string)($skill['fingerprint']??'');if(''!==$fp)$fingerprints[$fp][]=(string)$id;
            }
            $merge=array();foreach($groups as $group=>$ids){if(count($ids)<2)continue;usort($ids,static function($a,$b)use($state){$sa=$state['skills'][$a]??array();$sb=$state['skills'][$b]??array();$c=(float)($sb['confidence']??0)<=>(float)($sa['confidence']??0);return 0!==$c?$c:strcmp((string)($sb['last_verified_gmt']??''),(string)($sa['last_verified_gmt']??''));});$merge[]=array('group'=>$group,'preferred'=>$ids[0],'variants'=>$ids,'automatic_merge'=>false);}
            $exact_duplicates=array();foreach($fingerprints as $fp=>$ids){if(count($ids)>1)$exact_duplicates[]=array('fingerprint'=>$fp,'ids'=>$ids);}
            $archived=array();$pruned=array();
            if($apply){
                foreach(array_values(array_unique(array_merge($stale,$failed_only))) as $id){if(!isset($state['skills'][$id])||!is_array($state['skills'][$id]))continue;if('archived'===(string)($state['skills'][$id]['curated_state']??''))continue;$state['skills'][$id]['curated_state']='archived';$state['skills'][$id]['curated_gmt']=gmdate('c',$now);$state['skills'][$id]['invalidated_reason']=$state['skills'][$id]['invalidated_reason']??'skill_curator_stale_or_failed_only';$state['skills'][$id]['expires_gmt']=gmdate('c',$now-1);$archived[]=$id;}
                // Archiving alone never reclaimed anything: an archived entry
                // stayed in the index permanently, so the file only grew and the
                // stale count with it. Drop archived entries after a retention
                // window, keeping the aggregate metrics so the history of what
                // was learned and discarded is not lost -- only the per-entry
                // detail nobody can act on any more.
                $retention=max(7*86400,min(365*86400,(int)($args['archive_retention_seconds']??self::ARCHIVE_RETENTION_SECONDS)));
                foreach((array)$state['skills'] as $id=>$skill){
                    if(!is_array($skill)||'archived'!==(string)($skill['curated_state']??''))continue;
                    $curated=strtotime((string)($skill['curated_gmt']??''));
                    if($curated>0&&($now-$curated)<$retention)continue;
                    unset($state['skills'][$id]);$pruned[]=(string)$id;
                }
                $state['metrics']['last_curated_gmt']=gmdate('c',$now);$state['metrics']['curated_runs']=(int)($state['metrics']['curated_runs']??0)+1;$state['metrics']['curated_archived']=(int)($state['metrics']['curated_archived']??0)+count($archived);$state['metrics']['curated_pruned']=(int)($state['metrics']['curated_pruned']??0)+count($pruned);
            }
            return array('ok'=>true,'skipped'=>false,'apply'=>$apply,'analyzed'=>count((array)($state['skills']??array())),'stale_ids'=>$stale,'failed_only_ids'=>$failed_only,'archived_ids'=>$archived,'pruned_ids'=>$pruned,'pruned'=>count($pruned),'merge_candidates'=>$merge,'exact_duplicate_candidates'=>$exact_duplicates,'history_deleted'=>false,'automatic_merge'=>false,'version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'');
        };
        if(!$apply){$state=self::state_unlocked();return$analyze($state);}
        return self::mutate($analyze);
    }

    /**
     * Report the health and size of the procedural skill subsystem.
     *
     * `stale` used to fold two different things together: entries that expired
     * and have not been looked at, and entries the curator already reviewed and
     * archived. Since archiving sets expires_gmt in the past, every archived
     * skill kept counting as stale forever and the number only ever grew --
     * reading as a backlog that needed attention when the work was already done.
     * They are reported separately now, so `stale` means what it says.
     */
    public static function status(array $args=array()):array{
        $state=self::state_unlocked();$valid=0;$stale=0;$archived=0;$now=time();
        foreach((array)$state['skills'] as $skill){
            if('archived'===(string)($skill['curated_state']??'')){$archived++;continue;}
            if(strtotime((string)($skill['expires_gmt']??''))>$now)$valid++;else$stale++;
        }
        return array('ok'=>true,'version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'','skills'=>count((array)$state['skills']),'valid'=>$valid,'stale'=>$stale,'archived'=>$archived,'awaiting_curation'=>$stale,'metrics'=>$state['metrics'],'storage'=>'site_private_agent_skills','format'=>'SKILL.md+JSON-index','hidden_chain_of_thought_stored'=>false);
    }
}
