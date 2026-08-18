<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Risk_Engine_V3 {
    private static function risk($value):string{if(!is_string($value))return 'medium';$risk=strtolower(trim($value));return in_array($risk,array('low','medium','high','critical'),true)?$risk:'medium';}
    private static function rank(string $risk):int{return array('low'=>0,'medium'=>2,'high'=>4,'critical'=>5)[$risk];}
    public static function evaluate( array $cap, array $args, array $impact, bool $dry_run, array $control = array() ): array {
        $risk=self::risk($cap['risk_level']??'medium');
        return array('risk_level'=>$risk,'risk_score'=>self::rank($risk),'advisory_only'=>true);
    }
}
