<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Verified procedural memory compatible with the Agent Skills progressive-disclosure model. */
final class PRSTUDIO_UC_Procedural_Skills {
    public const VERSION='1.0.0';
    private const INDEX='procedural-skills-v1.json';
    private const LOCK='.procedural-skills-v1.lock';
    /**
     * Retrieval sizing, taken from a measured curve rather than from taste.
     *
     * arXiv:2608.14036 (Demystifying Agent Skills: Why They Work -- Until They
     * Don't, 14 Aug 2026) measured actual-use precision -- the share of the
     * skills put in front of the agent that it actually goes on to use -- as the
     * available pool grows:
     *
     *     pool of   5 skills  ->  29.6% precision
     *     pool of 100 skills  ->   3.3% precision
     *
     * Those are the only two points the paper reports, and they are far enough
     * apart to pin a power law. Fitting precision(n) = a * n^-b through them:
     *
     *     b = ln(29.6 / 3.3) / ln(100 / 5) = 0.7323
     *     a = 29.6 * 5^0.7323               = 96.20
     *     precision(n) = 96.20 * n^-0.7323  (percent)
     *
     * which reproduces both measured points exactly. The same paper found that
     * 65.7% of a skill's value comes from procedural anchoring and only 4.5%
     * from explicit knowledge injection: skills stabilise how the agent acts,
     * they do not teach it facts. Ten mediocre recipes therefore do not add up
     * to one good one -- they only add noise to the anchor. A small,
     * well-evidenced pool beats a large one, and that is what these three
     * numbers encode.
     *
     * MAX_SKILLS = 100 (was 2000)
     *   100 is the largest pool the paper actually measured. Above it there is
     *   no data at all, only the direction of the trend, so 2000 was not a
     *   conservative setting -- it was twenty times outside every observation
     *   that exists, at a fitted 0.37% precision. The store is not the surface
     *   handed to the model (search() truncates, see below), so it is allowed to
     *   sit at the top of the measured interval rather than at the retrieval
     *   ceiling; what it is not allowed to do is grow into a region where the
     *   curve has never been observed.
     *
     * SEARCH_RESULT_CEILING = 12 (was 100)
     *   This one IS the surface handed to the model, so it is placed where
     *   precision is still usable. The criterion is "at least half the best
     *   precision the paper ever measured", i.e. 29.6% / 2 = 14.8%. Solving
     *   96.20 * n^-0.7323 = 14.8 gives n = 12.88, floored to 12 -- a ceiling is
     *   rounded down, never up past the point it was derived from. Fitted
     *   precision at 12 is 15.6%, which is 4.7x the 3.3% measured at the old
     *   cap of 100 and 1.5x the old default of 20.
     *
     * SEARCH_RESULT_DEFAULT = 5 (was 20)
     *   5 is not an interpolation, it is the measured point where precision was
     *   highest (29.6%). A caller who expresses no opinion about breadth gets
     *   the best-measured surface; a caller who needs more can ask, up to the
     *   point where precision halves. The old default of 20 sat at a fitted
     *   10.7%, below that halving point already.
     *
     * None of this can stop an execution. search() is read-only, truncation is
     * reported (`matched`, `truncated`, `limit_applied`) rather than silent,
     * get() still returns any skill by ID, and pool eviction never drops the
     * entry the current mutation just wrote -- so a learn always lands (LAW 1)
     * and a human request always still resolves to an action (LAW 13).
     */
    private const MAX_SKILLS=100;
    private const SEARCH_RESULT_CEILING=12;
    private const SEARCH_RESULT_DEFAULT=5;
    /** best_match() hands a recipe back only at or above this Wilson lower bound. */
    public const REUSE_THRESHOLD=0.5;
    private const MAX_FAILURES_PER_SKILL=20;
    /** How long an archived skill stays in the index before it is dropped. */
    private const ARCHIVE_RETENTION_SECONDS=2592000; // 30 days.

    private static function dir(): string {
        $d=PRSTUDIO_UC_Memory::site_dir().'/skills';
        if(!is_dir($d)){function_exists('wp_mkdir_p')?wp_mkdir_p($d):@mkdir($d,0750,true);}
        return $d;
    }
    /**
     * Why the skill store could not be written, in terms someone can act on.
     *
     * Both failure paths below used to return "Unable to lock/persist
     * procedural skill store" and nothing else -- no path, no permissions, no
     * message from the filesystem. An operator who hit it could only report
     * that saving the skill "had a technical error", which is exactly what
     * happened and exactly as far as anyone could get.
     *
     * A store that cannot be written is a real and fixable condition: the
     * memory root is usually not writable, or open_basedir excludes it, or the
     * disk is full. All three are one `ls -la` away once the message says
     * which directory it meant.
     */
    private static function store_diagnosis(): array {
        $dir = self::dir();
        $parent = dirname( $dir );
        $last = error_get_last();
        return array(
            'skills_dir'       => $dir,
            'skills_dir_exists'=> is_dir( $dir ),
            'skills_dir_writable' => is_dir( $dir ) && is_writable( $dir ),
            'parent_dir'       => $parent,
            'parent_writable'  => is_dir( $parent ) && is_writable( $parent ),
            'index_path'       => self::index_path(),
            'index_writable'   => is_readable( self::index_path() ) ? is_writable( self::index_path() ) : null,
            'free_bytes'       => is_dir( $dir ) ? @disk_free_space( $dir ) : null,
            'last_php_error'   => is_array( $last ) ? substr( (string) ( $last['message'] ?? '' ), 0, 300 ) : '',
        );
    }
    private static function index_path(): string {return self::dir().'/'.self::INDEX;}
    private static function lock_path(): string {return self::dir().'/'.self::LOCK;}
    private static function defaults(): array {return array('schema_version'=>1,'skills'=>array(),'metrics'=>array('learned'=>0,'reused'=>0,'failures_observed'=>0),'updated_gmt'=>'');}
    private static function clean($v){return PRSTUDIO_UC_Memory::redact($v);}
    private static function key(string $v,int $max=100):string{$v=strtolower(trim($v));$v=(string)preg_replace('/[^a-z0-9._:-]+/','-',$v);return substr(trim($v,'-.'),0,$max);}
    private static function state_unlocked():array{$raw=is_readable(self::index_path())?(string)file_get_contents(self::index_path()):'';$d=''!==$raw?json_decode($raw,true):array();return is_array($d)?array_merge(self::defaults(),$d):self::defaults();}
    private static function atomic(array $state):bool{$state['updated_gmt']=gmdate('c');$json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(false===$json||strlen($json)>16777216)return false;try{$s=bin2hex(random_bytes(5));}catch(Throwable $e){$s=str_replace('.','',uniqid('',true));}$tmp=self::index_path().'.'.$s.'.tmp';if(false===@file_put_contents($tmp,$json."\n",LOCK_EX))return false;@chmod($tmp,0640);if(@rename($tmp,self::index_path()))return true;@unlink($tmp);return false;}
    private static function mutate(callable $cb){
        $fh=@fopen(self::lock_path(),'c+');
        if(!is_resource($fh)||!@flock($fh,LOCK_EX)){
            if(is_resource($fh))@fclose($fh);
            return new WP_Error('procedural_skill_lock_failed','Unable to lock procedural skill store: '.self::dir().' is not writable by the web user.',array_merge(array('status'=>503,'retryable'=>true,'lock_path'=>self::lock_path()),self::store_diagnosis()));
        }
        try{
            $state=self::state_unlocked();$r=$cb($state);
            if(is_wp_error($r))return$r;
            if(!self::atomic($state))return new WP_Error('procedural_skill_write_failed','Unable to persist procedural skill store: writing '.self::index_path().' failed.',array_merge(array('status'=>503,'retryable'=>true),self::store_diagnosis()));
            return$r;
        }finally{@flock($fh,LOCK_UN);@fclose($fh);}
    }
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

    /**
     * Would best_match() actually hand this recipe back right now?
     *
     * This is the one honest definition of "useful" available here, and it is
     * exactly what actual-use precision counts. An archived entry, an expired
     * entry, or one whose Wilson bound is under the reuse bar will not be
     * offered to a planner no matter how it is presented, so ranking it above a
     * usable entry spends part of a small result surface on something that
     * cannot be used. It is ranked down, never removed: get() still returns it
     * by ID and a narrower query still finds it.
     *
     * @param array $skill Stored skill row.
     * @param int   $now   Reference timestamp.
     * @return bool Whether the skill is reusable as it stands.
     */
    private static function is_reusable(array $skill,int $now): bool {
        if('archived'===(string)($skill['curated_state']??''))return false;
        if(strtotime((string)($skill['expires_gmt']??''))<=$now)return false;
        return (float)($skill['confidence']??0)>=self::REUSE_THRESHOLD;
    }

    /**
     * Order two skills best-first, by evidence rather than by arrival time.
     *
     * Recency was the old key everywhere -- eviction sorted on
     * `last_verified_gmt` alone -- which meant a recipe seen once five minutes
     * ago (Wilson bound 0.21) outranked one confirmed twenty times last month
     * (0.83). Under arXiv:2608.14036 that is the wrong direction twice over: it
     * keeps the low-value entries that dilute the pool and discards the
     * procedural anchors that carry 65.7% of the value. Confidence leads,
     * success count breaks ties at equal bound, recency only breaks what is
     * otherwise identical.
     *
     * @param array $a   First skill.
     * @param array $b   Second skill.
     * @param int   $now Reference timestamp.
     * @return int Negative when $a should come first.
     */
    private static function compare_value(array $a,array $b,int $now): int {
        $ra=self::is_reusable($a,$now)?1:0;$rb=self::is_reusable($b,$now)?1:0;
        if($ra!==$rb)return $rb<=>$ra;
        $ca=(float)($a['confidence']??0);$cb=(float)($b['confidence']??0);
        if($ca!==$cb)return $cb<=>$ca;
        $sa=(int)($a['success_count']??0);$sb=(int)($b['success_count']??0);
        if($sa!==$sb)return $sb<=>$sa;
        return strcmp((string)($b['last_verified_gmt']??''),(string)($a['last_verified_gmt']??''));
    }

    /**
     * Hold the stored pool at MAX_SKILLS by retiring the least-evidenced entries.
     *
     * Three separate holes made the old 2000 ceiling weaker than it looked:
     * only learn_verified_capability() enforced it at all, so
     * learn_verified_browser_task() and observe_failure() could both grow the
     * index without limit; and the enforcement that did exist sorted by recency,
     * so it evicted proven procedures in favour of single-sample ones. All three
     * writers now go through this.
     *
     * The entry the current mutation just wrote is never the one evicted. The
     * write always lands and only some other, less-evidenced entry leaves, so a
     * saturated store can still learn something new (LAW 1: the ceiling is a
     * retention policy, not a mutation guard) instead of freezing until the
     * expiry window turns over.
     *
     * @param array  $state      Store state, mutated in place.
     * @param string $protect_id Skill written by the current mutation.
     * @return string[] Evicted skill IDs.
     */
    private static function enforce_pool_ceiling(array &$state,string $protect_id=''): array {
        $skills=is_array($state['skills']??null)?$state['skills']:array();
        if(count($skills)<=self::MAX_SKILLS){$state['skills']=$skills;return array();}
        $protected=null;
        if(''!==$protect_id&&isset($skills[$protect_id])){$protected=$skills[$protect_id];unset($skills[$protect_id]);}
        $now=time();
        uasort($skills,static function($a,$b)use($now){return self::compare_value(is_array($a)?$a:array(),is_array($b)?$b:array(),$now);});
        $keep=max(0,self::MAX_SKILLS-(null===$protected?0:1));
        $evicted=array_keys(array_slice($skills,$keep,null,true));
        $skills=array_slice($skills,0,$keep,true);
        if(null!==$protected)$skills[$protect_id]=$protected;
        $state['skills']=$skills;
        if($evicted)$state['metrics']['pool_evicted']=(int)($state['metrics']['pool_evicted']??0)+count($evicted);
        return array_map('strval',$evicted);
    }

    /** Learn only from independently verified capability completion. */

    /**
     * Confidence as a lower bound on the success rate, not a step counter.
     *
     * The previous formulas were min(0.99, 0.55 + 0.12 * successes) and
     * min(0.99, 0.50 + 0.15 * successes). Both put a skill at 0.65-0.67 after a
     * SINGLE verified success, and best_match() reuses anything at or above
     * 0.50. So one sample was enough to make a recipe reusable, and observed
     * failures did not lower the number at all -- they were recorded in
     * failed_paths and then ignored by the score.
     *
     * arXiv:2608.17587 (Write, Execute, Refine, 18 Aug 2026) measured what that
     * produces: skills authored from experience without repeated scored
     * confirmation perform 8 to 11 points WORSE than using no skill at all.
     * Advertising a recipe from n=1 is not neutral, it is negative.
     *
     * This is the Wilson score lower bound at 95%, which is the standard answer
     * to "how much can one success tell you". It is deterministic, needs no new
     * data, and behaves the way the evidence should:
     *
     *     1 success,  0 failures  ->  0.21   (was 0.67, now below the reuse bar)
     *     5 successes, 0 failures ->  0.57   (reusable, on repeated confirmation)
     *    10 successes, 0 failures ->  0.72
     *    10 successes, 5 failures ->  0.42   (failures now actually cost)
     *
     * The reuse threshold in best_match() is untouched at 0.50. The bar did not
     * move; the number being compared against it became honest.
     *
     * Caveat worth stating: failed_paths deduplicates by signature, so the
     * failure term counts distinct failure modes rather than total failed
     * trials. That makes this bound optimistic, not pessimistic, which is the
     * direction to be careful about. It is still strictly better than the zero
     * weight failures carried before.
     *
     * Nothing here can stop an execution. best_match() only annotates a
     * response that has already run, so withholding a low-evidence recipe never
     * blocks a mutation (LAW 1) and never prevents intent resolving to action
     * (LAW 13).
     *
     * @param int $successes Verified successful observations.
     * @param int $failures  Distinct observed failure signatures.
     * @return float Lower bound in [0.0, 0.99].
     */
    public static function confidence(int $successes, int $failures): float {
        $successes = max(0, $successes);
        $failures  = max(0, $failures);
        $n         = $successes + $failures;
        if ($n <= 0) { return 0.0; }
        $z  = 1.96;
        $z2 = $z * $z;
        $p  = $successes / $n;
        $denominator = 1.0 + ($z2 / $n);
        $centre      = $p + ($z2 / (2 * $n));
        $margin      = $z * sqrt((($p * (1 - $p)) / $n) + ($z2 / (4 * $n * $n)));
        $lower       = ($centre - $margin) / $denominator;
        return round(max(0.0, min(0.99, $lower)), 4);
    }

    public static function learn_verified_capability(string $capability,array $args,array $result,array $verification,string $job_id=''):array{
        if(empty($verification['ok']))return array('ok'=>false,'learned'=>false,'reason'=>'verification_required');$capability=self::key($capability,120);if(''===$capability)return array('ok'=>false,'learned'=>false,'reason'=>'capability_required');$id=self::skill_id('capability',$capability,$args);$fingerprint=self::fingerprint('capability',$capability,$args);
        $r=self::mutate(static function(array &$state)use($id,$capability,$args,$result,$verification,$job_id,$fingerprint){$old=is_array($state['skills'][$id]??null)?$state['skills'][$id]:array();$count=(int)($old['success_count']??0)+1;$confidence=self::confidence($count,count((array)($old['failed_paths']??array())));$skill=array_merge($old,array('id'=>$id,'kind'=>'capability','name'=>$capability,'description'=>'Verified reusable procedure for capability '.$capability.'.','fingerprint'=>$fingerprint,'preconditions'=>array('suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'','argument_keys'=>array_values(array_keys($args))),'procedure'=>array('steps'=>array(array('capability'=>$capability,'argument_shape'=>array_map(static fn($v)=>is_array($v)?'array':gettype($v),$args))),'verification_required'=>true),'last_result_signature'=>hash('sha256',PRSTUDIO_UC_Idempotency::canonical_json(self::clean($result))),'last_verifier'=>(string)($verification['verifier']??''),'last_job_id'=>$job_id,'success_count'=>$count,'confidence'=>round($confidence,3),'last_verified_gmt'=>gmdate('c'),'expires_gmt'=>gmdate('c',time()+90*86400),'failed_paths'=>array_values((array)($old['failed_paths']??array()))));$state['skills'][$id]=$skill;self::enforce_pool_ceiling($state,$id);$state['metrics']['learned']=(int)($state['metrics']['learned']??0)+1;return array('ok'=>true,'learned'=>true,'skill'=>$skill);});if(is_array($r)&&is_array($r['skill']??null)){self::write_skill_md($r['skill']);PRSTUDIO_UC_Memory::movement('skill.learned',array('resource'=>$id,'capability'=>$capability,'outcome'=>'verified_procedure'));}return$r;
    }

    /** Record failed paths so the next plan can avoid retrying the same dead end. */
    public static function observe_failure(string $kind,string $name,array $args,array $error):array{
        $name=self::key($name,120);$id=self::skill_id($kind,$name,$args);$code=self::key((string)($error['code']??'failure'),100);$sig=hash('sha256',$code.'|'.PRSTUDIO_UC_Idempotency::canonical_json(array_keys($args)));
        return self::mutate(static function(array &$state)use($id,$kind,$name,$args,$code,$sig){$skill=is_array($state['skills'][$id]??null)?$state['skills'][$id]:array('id'=>$id,'kind'=>$kind,'name'=>$name,'description'=>'Procedure history for '.$name.'.','fingerprint'=>self::fingerprint($kind,$name,$args),'preconditions'=>array('suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:''),'procedure'=>array('steps'=>array()),'success_count'=>0,'confidence'=>0.0,'failed_paths'=>array());$fail=array_values((array)($skill['failed_paths']??array()));$exists=false;foreach($fail as $row){if(($row['signature']??'')===$sig){$exists=true;break;}}if(!$exists)$fail[]=array('signature'=>$sig,'error_code'=>$code,'observed_gmt'=>gmdate('c'));$skill['failed_paths']=array_slice($fail,-self::MAX_FAILURES_PER_SKILL);$state['skills'][$id]=$skill;self::enforce_pool_ceiling($state,$id);$state['metrics']['failures_observed']=(int)($state['metrics']['failures_observed']??0)+1;return array('ok'=>true,'recorded'=>true,'skill_id'=>$id,'failure_signature'=>$sig);});
    }

    /** Learn a complete verified Browser Agent task including its successful step sequence. */
    public static function learn_verified_browser_task(array $task,array $result,array $verification):array{
        if(empty($verification['ok']))return array('ok'=>false,'learned'=>false,'reason'=>'verification_required');$action=self::key((string)($task['action']??'browser-task'),120);$args=is_array($task['arguments']??null)?$task['arguments']:array();$steps=array_values(array_filter((array)($args['steps']??array()),'is_array'));if(!$steps)$steps=array(array('action'=>$action,'arguments'=>array_keys($args)));$id=self::skill_id('browser',$action,$args);$fingerprint=self::fingerprint('browser',$action,$args);
        $r=self::mutate(static function(array &$state)use($task,$result,$verification,$action,$args,$steps,$id,$fingerprint){$old=is_array($state['skills'][$id]??null)?$state['skills'][$id]:array();$count=(int)($old['success_count']??0)+1;$skill=array_merge($old,array('id'=>$id,'kind'=>'browser','name'=>$action,'description'=>'Verified Browser Agent procedure for '.$action.'.','fingerprint'=>$fingerprint,'preconditions'=>array('suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'','expected_origin'=>$args['expectedOrigin']??$args['expected_origin']??''),'procedure'=>array('steps'=>self::clean(array_slice($steps,0,250)),'verification_required'=>true,'strict_tab_ownership'=>true),'last_result_signature'=>hash('sha256',PRSTUDIO_UC_Idempotency::canonical_json(self::clean($result))),'last_verifier'=>(string)($verification['verifier']??''),'last_task_id'=>(string)($task['task_uuid']??''),'success_count'=>$count,'confidence'=>round(self::confidence($count,count((array)($old['failed_paths']??array()))),3),'last_verified_gmt'=>gmdate('c'),'expires_gmt'=>gmdate('c',time()+30*86400),'failed_paths'=>array_values((array)($old['failed_paths']??array()))));$state['skills'][$id]=$skill;self::enforce_pool_ceiling($state,$id);$state['metrics']['learned']=(int)($state['metrics']['learned']??0)+1;return array('ok'=>true,'learned'=>true,'skill'=>$skill);});if(is_array($r)&&is_array($r['skill']??null)){self::write_skill_md($r['skill']);PRSTUDIO_UC_Memory::movement('skill.browser_learned',array('resource'=>$id,'action'=>$action,'outcome'=>'verified_procedure'));}return$r;
    }

    /** Return the best non-stale reusable recipe for this capability/action shape. */
    public static function best_match(string $kind,string $name,array $args):?array{
        $id=self::skill_id($kind,self::key($name,120),$args);$skill=self::state_unlocked()['skills'][$id]??null;if(!is_array($skill)||strtotime((string)($skill['expires_gmt']??''))<=time()||(float)($skill['confidence']??0)<self::REUSE_THRESHOLD)return null;return$skill;
    }

    /**
     * Search/list procedural skills using progressive disclosure.
     *
     * The retrieval surface, and the thing arXiv:2608.14036 measured directly.
     * It used to accept `limit` up to 100 and default to 20 -- the 100 being
     * precisely the pool size where actual-use precision was measured at 3.3%,
     * i.e. ninety-seven of every hundred rows returned were never used. It is
     * now capped at SEARCH_RESULT_CEILING and defaults to SEARCH_RESULT_DEFAULT;
     * see the constants for the arithmetic behind both numbers.
     *
     * Two changes make the smaller surface carry more, not less:
     *
     *  - Rows are ordered by whether the planner could actually reuse them
     *    first and by the Wilson bound second, so the twelve that survive
     *    truncation are the twelve with the most evidence behind them. Sorting
     *    on raw confidence alone let an expired or already-archived recipe with
     *    a strong history occupy the top of the list while best_match() would
     *    refuse it -- a result that is definitionally never used, which is the
     *    exact quantity the paper's precision metric counts against you.
     *  - Truncation is stated, never silent. `matched` is the true number of
     *    hits, `truncated` says whether the cap bit, and `limit_requested` vs
     *    `limit_applied` shows a caller that asked for more that it was clamped.
     *    Nothing is excluded from the store or from reach: a narrower query, a
     *    `kind` filter, or get() by ID still returns anything held (LAW 10).
     *
     * Search intent is normalized through the shared IT/EN action lexicon. A
     * pair such as `istantanea` / `snapshot` therefore resolves to the same
     * concept set before the evidence ranking runs, so both languages receive
     * the same rows in the same order. Technical identifiers containing `.`,
     * `:`, `_` or `-` keep exact substring semantics and never get broadened by
     * natural-language concepts.
     *
     * @param array $args query, kind, limit.
     * @return array Ranked, capped result surface.
     */
    public static function search(array $args):array{
        $q=strtolower(trim((string)($args['query']??'')));$kind=self::key((string)($args['kind']??''),30);
        $requested=isset($args['limit'])?(int)$args['limit']:self::SEARCH_RESULT_DEFAULT;
        $limit=max(1,min(self::SEARCH_RESULT_CEILING,$requested));
        if(!class_exists('PRSTUDIO_UC_Action_Lexicon')){
            $lexicon_path=__DIR__.'/class-prstudio-uc-action-lexicon.php';
            if(is_readable($lexicon_path))require_once $lexicon_path;
        }
        $lexicon_ready=class_exists('PRSTUDIO_UC_Action_Lexicon');
        $technical_query=''!==$q&&1===preg_match('/[._:-]/',$q);
        $query_normalized=$lexicon_ready?PRSTUDIO_UC_Action_Lexicon::normalize_text($q):trim(str_replace(array('_','-'),' ',$q));
        $query_concepts=(!$technical_query&&$lexicon_ready)?PRSTUDIO_UC_Action_Lexicon::query_concepts($q):array();
        $query_keys=($lexicon_ready&&$query_concepts)?PRSTUDIO_UC_Action_Lexicon::concept_keys($query_concepts):array();
        $now=time();$rows=array();
        foreach((array)(self::state_unlocked()['skills']??array()) as $skill){
            if(!is_array($skill))continue;
            if($kind&&$kind!==(string)($skill['kind']??''))continue;
            $hay=strtolower((string)($skill['name']??'').' '.(string)($skill['description']??''));
            $match=''===$q;
            if(!$match&&$technical_query)$match=str_contains($hay,$q);
            if(!$match&&$query_concepts){
                $candidate_concepts=PRSTUDIO_UC_Action_Lexicon::query_concepts($hay);
                $candidate_keys=PRSTUDIO_UC_Action_Lexicon::concept_keys($candidate_concepts);
                $match=PRSTUDIO_UC_Action_Lexicon::equivalent($candidate_concepts,$query_concepts)
                    ||PRSTUDIO_UC_Action_Lexicon::covers($candidate_concepts,$query_concepts)
                    ||0<count(array_intersect($query_keys,$candidate_keys));
            }
            if(!$match&&!$technical_query&&''!==$q){
                $hay_normalized=$lexicon_ready?PRSTUDIO_UC_Action_Lexicon::normalize_text($hay):trim(str_replace(array('_','-'),' ',$hay));
                $match=str_contains($hay,$q)||(''!==$query_normalized&&str_contains($hay_normalized,$query_normalized));
            }
            if(!$match)continue;
            $rows[]=array('id'=>(string)($skill['id']??''),'kind'=>(string)($skill['kind']??''),'name'=>(string)($skill['name']??''),'description'=>(string)($skill['description']??''),'confidence'=>(float)($skill['confidence']??0),'success_count'=>(int)($skill['success_count']??0),'last_verified_gmt'=>(string)($skill['last_verified_gmt']??''),'expires_gmt'=>(string)($skill['expires_gmt']??''),'curated_state'=>(string)($skill['curated_state']??''),'reusable'=>self::is_reusable($skill,$now),'failed_path_count'=>count((array)($skill['failed_paths']??array())));
        }
        usort($rows,static function($a,$b)use($now){return self::compare_value($a,$b,$now);});
        $matched=count($rows);$items=array_slice($rows,0,$limit);
        return array('ok'=>true,'count'=>count($items),'matched'=>$matched,'truncated'=>$matched>count($items),'limit_requested'=>$requested,'limit_applied'=>$limit,'limit_ceiling'=>self::SEARCH_RESULT_CEILING,'limit_default'=>self::SEARCH_RESULT_DEFAULT,'ranked_by'=>'reusable_then_wilson_confidence','items'=>$items,'progressive_disclosure'=>true,'query_normalized'=>$query_normalized,'bilingual_lexicon'=>$lexicon_ready,'version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'');
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
            $archived=array();$pruned=array();$ceiling_evicted=array();
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
                // A store written under the old 2000 ceiling stays oversized
                // until something touches it. Learning trims one entry per
                // write, which would take hundreds of writes to walk 2000 down
                // to 100 -- and every search in between is served from a pool
                // the paper measured at well under 1% actual-use precision.
                // The curator brings it back in one pass, retiring the
                // least-evidenced entries first.
                $ceiling_evicted=self::enforce_pool_ceiling($state);
                $state['metrics']['last_curated_gmt']=gmdate('c',$now);$state['metrics']['curated_runs']=(int)($state['metrics']['curated_runs']??0)+1;$state['metrics']['curated_archived']=(int)($state['metrics']['curated_archived']??0)+count($archived);$state['metrics']['curated_pruned']=(int)($state['metrics']['curated_pruned']??0)+count($pruned);
            }
            return array('ok'=>true,'skipped'=>false,'apply'=>$apply,'analyzed'=>count((array)($state['skills']??array())),'stale_ids'=>$stale,'failed_only_ids'=>$failed_only,'archived_ids'=>$archived,'pruned_ids'=>$pruned,'pruned'=>count($pruned),'ceiling_evicted_ids'=>$ceiling_evicted,'ceiling_evicted'=>count($ceiling_evicted),'pool_ceiling'=>self::MAX_SKILLS,'over_ceiling'=>max(0,count((array)($state['skills']??array()))-self::MAX_SKILLS),'merge_candidates'=>$merge,'exact_duplicate_candidates'=>$exact_duplicates,'history_deleted'=>false,'automatic_merge'=>false,'version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'');
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
        return array('ok'=>true,'version'=>self::VERSION,'component'=>'procedural_skills','component_version'=>self::VERSION,'suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'','skills'=>count((array)$state['skills']),'valid'=>$valid,'stale'=>$stale,'archived'=>$archived,'awaiting_curation'=>$stale,'pool_ceiling'=>self::MAX_SKILLS,'over_ceiling'=>max(0,count((array)$state['skills'])-self::MAX_SKILLS),'retrieval_ceiling'=>self::SEARCH_RESULT_CEILING,'retrieval_default'=>self::SEARCH_RESULT_DEFAULT,'reuse_threshold'=>self::REUSE_THRESHOLD,'metrics'=>$state['metrics'],'storage'=>'site_private_agent_skills','format'=>'SKILL.md+JSON-index','hidden_chain_of_thought_stored'=>false);
    }
}
