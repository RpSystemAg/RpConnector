<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Browser_Orchestrator {
    private static function signals($value):array{$out=array();if(!is_array($value))return$out;foreach(array_slice($value,0,25) as $v){if(!is_string($v))continue;$v=strtolower(trim($v));if(''!==$v)$out[]=$v;}return$out;}
    private static function target($value):string{return is_string($value)?trim($value):'';}
    private static function wait_mode($value):string{$v=is_string($value)?strtolower(trim($value)):'';return in_array($v,array('complete','interactive','domcontentloaded','dom_content_loaded','none','no_wait','nowait','loading'),true)?$v:'complete';}
    private static function wait_seconds($value):int{if(is_int($value))$n=$value;elseif(is_string($value)&&preg_match('/^-?\d+$/',trim($value)))$n=(int)$value;else$n=2;return max(0,min(20,$n));}
    private static function need_ocr(array $args):bool{
        $signals=self::signals($args['evidence_required']??array());
        foreach($signals as $s){if(in_array($s,array('ocr','pixel_text','image_text','screenshot_text'),true))return true;}
        return false;
    }
    public static function scope(array $cap,array $args):array{
        $required=true===($cap['browser_required']??false);
        $checks=self::signals($args['checks']??array());
        foreach($checks as $check){if(in_array($check,array('runtime_dom','javascript_render','browser_console','network_runtime'),true))$required=true;}
        return array('browser_required'=>$required,'browser_primary'=>true,'ocr_required'=>$required&&self::need_ocr($args),'reason'=>$required?'browser_first_live_execution':'browser_available_as_primary_live_evidence','priority_order'=>array('browser_dom_runtime','browser_network_runtime','browser_visual_runtime','wordpress_database','server_html','public_http','screenshot_ocr'));
    }
    private static function dispatch(string $operation,array $args){
        if(!class_exists('PRSTUDIO_UC_Bridge'))return new WP_Error('prstudio_browser_unavailable','Browser bridge unavailable.',array('status'=>503));
        $args['sync_wait_seconds']=self::wait_seconds($args['sync_wait_seconds']??2);
        return PRSTUDIO_UC_Bridge::dispatch(null,$args,array('action'=>$operation));
    }
    public static function inspect(array $args,array $cap=array()){
        $target=self::target($args['target']??null); if(''===$target)return new WP_Error('prstudio_browser_target_required','target is required.',array('status'=>400));
        $scope=self::scope($cap?:array('browser_required'=>true),$args); $call=array('url'=>$target,'browser_target'=>'live','evidence_required'=>$args['evidence_required']??array());
        if($scope['ocr_required']){$call['ocr']=true; return self::dispatch('playwright_screenshot_page',$call);}
        return self::dispatch('inspect',$call);
    }
    public static function navigate(array $args,array $cap=array()){
        $target=self::target($args['target']??null); if(''===$target)return new WP_Error('prstudio_browser_target_required','target is required.',array('status'=>400));
        return self::dispatch('playwright_goto',array('url'=>$target,'wait_until'=>self::wait_mode($args['wait_until']??'complete'),'browser_target'=>'live'));
    }
}
