<?php
declare(strict_types=1);
$root=rtrim((string)(getenv('PRSTUDIO_BENCH_ROOT')?:dirname(__DIR__)),'/\\');
$plugin=$root.'/prstudio-unified-control/prstudio-unified-control.php';
define('ABSPATH',sys_get_temp_dir().'/prstudio-bootstrap/');define('REST_REQUEST',true);define('HOUR_IN_SECONDS',3600);
$GLOBALS['hooks']=[];$GLOBALS['counts']=[];
function hit($n){$GLOBALS['counts'][$n]=($GLOBALS['counts'][$n]??0)+1;}
function plugin_dir_path($f){return dirname($f).'/';}function plugin_dir_url($f){return 'https://example.test/wp-content/plugins/prstudio-unified-control/';}function plugin_basename($f){return 'prstudio-unified-control/'.basename($f);}function add_action($h,$cb,$p=10,$a=1){$GLOBALS['hooks'][$h][]=$cb;return true;}function add_filter($h,$cb,$p=10,$a=1){return true;}function register_activation_hook($f,$cb){}function register_deactivation_hook($f,$cb){}function is_admin(){return false;}function wp_doing_cron(){return false;}function get_option($k,$d=false){if($k==='prstudio_uc_migration_pending')return ['state'=>'completed'];return $d;}function update_option($k,$v,$autoload=null){return true;}function wp_next_scheduled($h){return false;}function wp_schedule_single_event($t,$h){return true;}function esc_url($v){return (string)$v;}function admin_url($v=''){return 'https://example.test/wp-admin/'.$v;}
final class PRSTUDIO_UC_Store{public static function schema_ready(){hit('schema_ready');return true;}}
final class PRSTUDIO_UC_Job_Engine{public static function recover(){hit('job_recovery');return true;}}
final class PRSTUDIO_UC_Mission_Engine{public static function recover(){hit('mission_recovery');return true;}}
final class PRSTUDIO_UC_Change_Tracker{public static function register(){hit('change_tracker_register');}public static function schedule($n){hit('change_tracker_schedule');}}
final class PRSTUDIO_UC_Bridge{public static function register(){hit('bridge_register');return true;}}
final class PRSTUDIO_UC_Agency_Runtime{public static function init(){hit('agency_init');}public static function ensure_schedulers(){hit('agency_schedulers');}public static function activate(){}public static function deactivate(){}}
final class PRSTUDIO_UC_Memory{public static function save_context($v){hit('memory_context');return true;}}
final class PRSTUDIO_UC_Capability_Registry{public static function hash(){hit('registry_hash');return 'x';}public static function counts(){hit('registry_counts');return ['capabilities'=>1376];}}
final class PRSTUDIO_UC_OpenAPI{public static function operation_ids(){hit('openapi_ids');return [];}}
final class PRSTUDIO_UC_GC{public static function activate(){hit('gc_activate');}public static function deactivate(){}public static function run(){}}
final class PRSTUDIO_UC_Interventions{public static function install(){hit('interventions_install');}}
$t=hrtime(true);require $plugin;$require_ms=(hrtime(true)-$t)/1e6;
$cb=$GLOBALS['hooks']['plugins_loaded'][0]??null;$t=hrtime(true);if(is_callable($cb))$cb();$boot_ms=(hrtime(true)-$t)/1e6;
$autoload=class_exists('PRSTUDIO_UC_Autoload',false)?PRSTUDIO_UC_Autoload::stats():[];
echo json_encode(['require_ms'=>$require_ms,'boot_ms'=>$boot_ms,'calls'=>$GLOBALS['counts'],'autoload'=>$autoload],JSON_UNESCAPED_SLASHES),"\n";
