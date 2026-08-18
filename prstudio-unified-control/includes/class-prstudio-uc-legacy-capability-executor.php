<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** Adapter that keeps 2.0 business logic internal without exposing 1,278 GPT Actions. */
final class PRSTUDIO_UC_Legacy_Capability_Executor {
    public static function execute( array $arguments, array $capability = array() ) {
        $source=(array)($capability['source']??array()); $kind=(string)($source['kind']??'');
        if('legacy_action'===$kind){
            $route=(string)($source['route']??''); $action=(string)($source['action']??'');
            if(''===$route||''===$action){return new WP_Error('prstudio_legacy_mapping_invalid','Legacy capability mapping is incomplete.',array('status'=>500));}
            if(class_exists('PRSTUDIO_UC_Backend_Executability')){
                $provider=PRSTUDIO_UC_Backend_Executability::provider_for($route,$action,$source);
                if('database_native'===$provider&&class_exists('PRSTUDIO_UC_Database_Backend')){return PRSTUDIO_UC_Database_Backend::execute($action,$arguments);}
                if('browser_agent'===$provider&&class_exists('PRSTUDIO_UC_Bridge')){return PRSTUDIO_UC_Bridge::dispatch(null,$arguments,array('action'=>$action));}
            }
            if(class_exists('WPAIB_REST')&&is_callable(array('WPAIB_REST','execute_control_action'))){
                // 16.0.0 regression hardening: source metadata is an array, while the
                // REST executor historically accepted a scalar source label. Passing
                // the array directly caused a PHP TypeError before any real write.
                $arguments['_source_meta']=$source;
                return WPAIB_REST::execute_control_action($route,$action,$arguments,'capability_gateway');
            }
            if(class_exists('PRSTUDIO_UC_Backend_Executability')){return PRSTUDIO_UC_Backend_Executability::execute_fallback($route,$action,$arguments,$source);}
            return new WP_Error('prstudio_legacy_executor_unavailable','No internal legacy executor is available.',array('status'=>503));
        }
        if('legacy_direct_tool'===$kind){
            $tool=sanitize_key((string)($source['tool_name']??''));
            if(''===$tool){return new WP_Error('prstudio_legacy_direct_mapping_invalid','Legacy direct capability has no tool_name.',array('status'=>500));}
            if(class_exists('WPAIB_MCP')&&is_callable(array('WPAIB_MCP','call_tool_compat'))){
                $result=WPAIB_MCP::call_tool_compat($tool,$arguments);
                return null===$result?new WP_Error('prstudio_legacy_direct_empty_result','Legacy direct tool returned no result.',array('status'=>500,'tool'=>$tool)):$result;
            }
            return new WP_Error('prstudio_legacy_direct_executor_unavailable','Legacy direct tool has no callable server-side executor.',array('status'=>503,'tool'=>$tool));
        }
        return new WP_Error('prstudio_capability_source_unknown','Capability source is not executable.',array('status'=>500));
    }
}
