<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/* PR STUDIO ONE-GUARD INVARIANT: Anti-Crash is the only mutation guard. Verification/risk/telemetry never block an executable action. */
final class PRSTUDIO_UC_Planner_V3 {
    /**
     * Compile only deterministic execution work. No risk/depth mode, policy step,
     * canary, pre-action snapshot, evidence stop or verifier authorization exists.
     */
    public static function plan(array $cap,array $args,array $impact):array{
        $steps=array('validate_schema','resolve_execution_binding');
        if(empty($cap['read_only']))$steps[]='anti_crash';
        $steps=array_merge($steps,array('execute','observe','report'));
        return array(
            'version'=>'17.0.0-one-guard',
            'capability'=>$cap['id']??'',
            'mode'=>'quick',
            'steps'=>$steps,
            'browser_scope'=>class_exists('PRSTUDIO_UC_Browser_Orchestrator')?PRSTUDIO_UC_Browser_Orchestrator::scope($cap,$args):array(),
            'impact_telemetry'=>$impact,
            'deterministic'=>true,
            'local_batch_preferred'=>true,
            'model_roundtrip_required'=>false,
            'verification_authorizes_execution'=>false,
            'rules'=>array(
                'prefer_server_executor_over_browser'=>true,
                'batch_when_same_provider'=>true,
                'reuse_controlled_session'=>true,
                'return_to_model_only_for_new_judgment'=>true,
            ),
        );
    }
    public static function high_level(string $objective,array $context=array()):array{
        return array(
            'objective'=>$objective,
            'mode'=>'quick',
            'steps'=>array('context','execution_compile','execution','observe','analysis','report'),
            'context'=>PRSTUDIO_UC_Memory::redact($context),
            'persistent'=>true,
            'local_batch_preferred'=>true,
            'model_roundtrip_required'=>false,
            'avoid_repeated_discovery'=>true,
        );
    }
}
