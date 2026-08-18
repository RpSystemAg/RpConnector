<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Mission_Engine {
    public static function create(string $objective,array $context=array()):array{
        $type=sanitize_key((string)($context['playbook']??$context['mission_type']??'site_guardian'));
        if(PRSTUDIO_UC_Playbook_Engine::supports($type)){
			$options=array('objective'=>$objective,'mission_id'=>(string)($context['mission_id']??''),'request_id'=>(string)($context['request_id']??''),'occurrence_key'=>(string)($context['occurrence_key']??$context['idempotency_key']??''),'priority'=>(int)($context['priority']??100),'owner_client_id'=>(string)($context['_owner_client_id']??''));
			unset($context['playbook'],$context['mission_type'],$context['mission_id'],$context['request_id'],$context['occurrence_key'],$context['idempotency_key'],$context['priority'],$context['_owner_client_id']);
            return PRSTUDIO_UC_Agency_Runtime::submit($type,$context,$options);
        }
        return array('ok'=>false,'error'=>'mission_playbook_unknown','available'=>PRSTUDIO_UC_Playbook_Engine::types());
    }
    public static function recover():array{return array('recovered'=>PRSTUDIO_UC_Store::recover_stale_jobs(),'checkpoint_recovery'=>true,'runtime'=>PRSTUDIO_UC_Agency_Runtime::status());}
    public static function get(string $job_uuid):?array{return PRSTUDIO_UC_Store::get_job($job_uuid);}
    public static function control(string $job_uuid,string $action,array $args=array()){return PRSTUDIO_UC_Agency_Runtime::control($job_uuid,$action,$args);}
}
