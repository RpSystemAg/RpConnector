<?php
/**
 * WP-CLI eval-file helper for production lifecycle acceptance.
 *
 * Usage is controlled only through RP_LIFECYCLE_ACTION / RP_LIFECYCLE_FILE.
 * It seeds deterministic synthetic state and independently fingerprints the
 * persisted WordPress/PR STUDIO state across upgrade, failure and restore.
 */
if ( ! defined( 'ABSPATH' ) ) {
    $rp_wp_path = rtrim( (string) getenv( 'RP_WP_PATH' ), "/\\" );
    $rp_wp_load = '' !== $rp_wp_path ? $rp_wp_path . '/wp-load.php' : '';
    if ( '' === $rp_wp_load || ! is_file( $rp_wp_load ) ) { fwrite( STDERR, "RP_WP_PATH WordPress bootstrap missing\n" ); exit( 2 ); }
    require_once $rp_wp_load;
}
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress bootstrap required\n" ); exit( 2 ); }

function rp_lifecycle_fail( string $message ): void { fwrite( STDERR, "FAIL {$message}\n" ); exit( 1 ); }
function rp_lifecycle_ok( string $message ): void { fwrite( STDOUT, "PASS {$message}\n" ); }
function rp_lifecycle_json( $value ): string { return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
function rp_lifecycle_atomic_write( string $path, string $contents ): void {
    $dir = dirname( $path );
    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { rp_lifecycle_fail( "cannot create {$dir}" ); }
    $tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
    if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) ) { rp_lifecycle_fail( "cannot write {$tmp}" ); }
    if ( ! rename( $tmp, $path ) ) { @unlink( $tmp ); rp_lifecycle_fail( "cannot commit {$path}" ); }
}
function rp_lifecycle_remove_tree( string $path, string $allowed_root ): void {
    $normalized = str_replace( '\\', '/', $path );
    $allowed = rtrim( str_replace( '\\', '/', $allowed_root ), '/' ) . '/';
    if ( 0 !== strpos( rtrim( $normalized, '/' ) . '/', $allowed ) ) { rp_lifecycle_fail( 'unsafe delete outside memory root' ); }
    if ( is_link( $path ) || is_file( $path ) ) { @unlink( $path ); return; }
    if ( ! is_dir( $path ) ) { return; }
    foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $item ) { rp_lifecycle_remove_tree( $item->getPathname(), $allowed_root ); }
    @rmdir( $path );
}
function rp_lifecycle_file_fact( string $path ): array {
    return array( 'exists'=>is_file($path), 'bytes'=>is_file($path)?(int)filesize($path):0, 'sha256'=>is_file($path)?(string)hash_file('sha256',$path):'' );
}
function rp_lifecycle_snapshot(): array {
    global $wpdb;
    $device_uuid = (string) get_option( 'rp_production_test_device_uuid', '' );
    $job_uuid = (string) get_option( 'rp_production_test_job_uuid', '' );
    $device = null; $job = null; $device_count = 0; $job_count = 0;
    if ( $device_uuid && class_exists( 'PRSTUDIO_UC_Store' ) ) {
        $device = $wpdb->get_row( $wpdb->prepare(
            'SELECT device_uuid,name,status,capabilities,created_gmt FROM ' . PRSTUDIO_UC_Store::devices_table() . ' WHERE device_uuid=%s LIMIT 1', $device_uuid
        ), ARRAY_A );
        $device_count = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . PRSTUDIO_UC_Store::devices_table() . ' WHERE device_uuid=%s', $device_uuid
        ) );
    }
    if ( $job_uuid && class_exists( 'PRSTUDIO_UC_Store' ) ) {
        $job = $wpdb->get_row( $wpdb->prepare(
            'SELECT job_uuid,request_id,mission_id,owner_client_id,capability,idempotency_key,objective,domain,arguments,status,priority,step_index,progress,max_attempts,backoff_seconds,occurrence_key FROM ' . PRSTUDIO_UC_Store::jobs_table() . ' WHERE job_uuid=%s LIMIT 1', $job_uuid
        ), ARRAY_A );
        $job_count = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . PRSTUDIO_UC_Store::jobs_table() . ' WHERE job_uuid=%s', $job_uuid
        ) );
    }
    // context.json is intentionally excluded: Recovery_Manager::boot() regenerates
    // it from the current suite version/capability registry. The durable memory
    // evidence that must survive upgrade is summary + machine index + hash chain.
    $memory = array();
    if ( class_exists( 'PRSTUDIO_UC_Memory' ) ) {
        $dir = PRSTUDIO_UC_Memory::site_dir();
        foreach ( array( 'memory-summary.txt', 'memory-index.json', 'memory-chain.ndjson' ) as $name ) { $memory[$name] = rp_lifecycle_file_fact( $dir . '/' . $name ); }
    }
    $settings = get_option( 'wpaib_settings', array() );
    $settings = is_array( $settings ) ? $settings : array();
    $preserved = array(
        'settings_marker' => (string) ( $settings['rp_production_marker'] ?? '' ),
        'oauth_clients' => get_option( 'prstudio_mcp_v5_clients', array() ),
        'oauth_tokens' => get_option( 'prstudio_mcp_v5_tokens', array() ),
        'oauth_generation' => get_option( 'prstudio_mcp_v5_generation', null ),
        'actions_keys' => get_option( 'prstudio_uc_actions_keys_v3', array() ),
        'google_oauth' => get_option( 'wpaib_google_oauth', array() ),
        'google_tokens' => get_option( 'wpaib_google_tokens', array() ),
        'device_uuid' => $device_uuid, 'device_count'=>$device_count, 'device'=>$device,
        'job_uuid' => $job_uuid, 'job_count'=>$job_count, 'job'=>$job,
        'memory' => $memory,
    );
    $canonical = wp_json_encode( $preserved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    return array(
        'preserved'=>$preserved,
        'preserved_hash'=>hash('sha256',(string)$canonical),
        'schema_version'=>(string)get_option('prstudio_uc_schema_version',''),
        'migration_state'=>get_option('prstudio_uc_migration_state',array()),
        'migration_v3'=>get_option('prstudio_uc_migration_v3',array()),
        'migration_v4'=>get_option('prstudio_uc_migration_v4',array()),
        'plugin_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'',
        'captured_at'=>gmdate('c'),
    );
}
function rp_lifecycle_seed(): void {
    global $wpdb;
    if ( ! class_exists('PRSTUDIO_UC_Store') || ! class_exists('PRSTUDIO_UC_Memory') ) { rp_lifecycle_fail('runtime classes unavailable'); }
    PRSTUDIO_UC_Store::install();
    $settings=get_option('wpaib_settings',array()); $settings=is_array($settings)?$settings:array(); $settings['rp_production_marker']='rp-production-preserve-v1'; update_option('wpaib_settings',$settings,false);
    update_option('prstudio_mcp_v5_clients',array('rp-client'=>array('client_id'=>'rp-client','client_name'=>'RP production synthetic client','redirect_uris'=>array('https://chatgpt.com/oauth/callback'),'created_at'=>1770000000)),false);
    update_option('prstudio_mcp_v5_tokens',array('rp-token'=>array(
        'id'=>'rp-token','access_hash'=>hash('sha256','synthetic-access-never-a-real-secret'),'refresh_hash'=>hash('sha256','synthetic-refresh-never-a-real-secret'),
        'access_exp'=>1999999999,'refresh_exp'=>1999999999,'client_id'=>'rp-client','scope'=>'prstudio.read prstudio.write',
        'resource'=>'https://lifecycle.invalid/wp-json/prstudio-unified/v1/mcp','generation'=>17,'created_at'=>1770000000,'last_used'=>1770000000,
    )),false);
    update_option('prstudio_mcp_v5_generation',17,false);
    update_option('prstudio_uc_actions_keys_v3',array('rp-synthetic-key'=>array('hash'=>hash('sha256','synthetic-actions-key'))),false);
    update_option('wpaib_google_oauth',array('state'=>'synthetic-preserved'),false);
    update_option('wpaib_google_tokens',array('access_token'=>'[SYNTHETIC-NOT-REAL]','refresh_token'=>'[SYNTHETIC-NOT-REAL]'),false);

    $device_uuid=(string)get_option('rp_production_test_device_uuid','');
    if(''===$device_uuid){$device=PRSTUDIO_UC_Store::create_device('RP Production Device',array('browser.execute','screenshot'));$device_uuid=(string)($device['device_id']??'');if(''===$device_uuid)rp_lifecycle_fail('device seed failed');update_option('rp_production_test_device_uuid',$device_uuid,false);}
    $job_uuid=(string)get_option('rp_production_test_job_uuid','');
    if(''===$job_uuid){
        $job_uuid='rp-production-job-0001';$now=gmdate('Y-m-d H:i:s');$expires=gmdate('Y-m-d H:i:s',time()+30*DAY_IN_SECONDS);
        $inserted=$wpdb->insert(PRSTUDIO_UC_Store::jobs_table(),array(
            'job_uuid'=>$job_uuid,'request_id'=>'rp-production-request-0001','mission_id'=>'rp-production-mission-0001','owner_client_id'=>'rp-client',
            'capability'=>'production.lifecycle.sentinel','idempotency_key'=>hash('sha256','rp-production-job-0001'),'objective'=>'Persistent lifecycle sentinel',
            'domain'=>'production-test','arguments'=>wp_json_encode(array('sentinel'=>true)),'status'=>'PENDING','priority'=>100,'step_index'=>0,'progress'=>0,'attempts'=>0,
            'max_attempts'=>5,'backoff_seconds'=>30,'occurrence_key'=>'rp-production-occurrence-0001','created_gmt'=>$now,'updated_gmt'=>$now,'expires_gmt'=>$expires,
        ));
        if(false===$inserted)rp_lifecycle_fail('durable job seed failed: '.(string)$wpdb->last_error);update_option('rp_production_test_job_uuid',$job_uuid,false);
    }
    $movement=PRSTUDIO_UC_Memory::movement('production.lifecycle.seed',array('resource'=>'synthetic-lifecycle-state','outcome'=>'seeded','fingerprint'=>hash('sha256','rp-production-memory-sentinel')),$job_uuid);
    if(empty($movement['ok']))rp_lifecycle_fail('memory seed failed');
    rp_lifecycle_ok('synthetic persistent state seeded');
}
function rp_lifecycle_prepare_failure(): void {
    if(!class_exists('PRSTUDIO_UC_Memory'))rp_lifecycle_fail('memory runtime unavailable');
    delete_option('prstudio_uc_migration_v3');delete_option('prstudio_uc_migration_v4');delete_option('prstudio_uc_migration_state');delete_option('prstudio_uc_migration_failure');update_option('prstudio_uc_schema_version','3.0.0',false);
    $root=PRSTUDIO_UC_Memory::root();$legacy=PRSTUDIO_UC_Memory::legacy_site_identity();$modern=PRSTUDIO_UC_Memory::site_identity();$legacy_dir=$root.'/'.$legacy['key'];$modern_dir=$root.'/'.$modern['key'];
    if(!is_dir($legacy_dir)&&!wp_mkdir_p($legacy_dir))rp_lifecycle_fail('legacy dir create failed');file_put_contents($legacy_dir.'/memory-summary.txt',"legacy migration sentinel\n",LOCK_EX);
    if($legacy_dir===$modern_dir)rp_lifecycle_fail('test requires distinct legacy and modern memory namespaces');rp_lifecycle_remove_tree($modern_dir,$root);if(!is_dir(dirname($modern_dir)))wp_mkdir_p(dirname($modern_dir));
    if(false===file_put_contents($modern_dir,'intentional migration blocker',LOCK_EX))rp_lifecycle_fail('cannot create migration blocker');update_option('rp_production_migration_blocker',$modern_dir,false);rp_lifecycle_ok('interrupted migration condition prepared');
}
function rp_lifecycle_cleanup_failure(): void {$path=(string)get_option('rp_production_migration_blocker','');if($path&&is_file($path))@unlink($path);delete_option('rp_production_migration_blocker');rp_lifecycle_ok('migration blocker removed');}
function rp_lifecycle_assert_retryable(): void {$state=get_option('prstudio_uc_migration_state',array());$v3=get_option('prstudio_uc_migration_v3',array());if(!is_array($state)||'retryable'!==(string)($state['state']??''))rp_lifecycle_fail('migration did not remain retryable');if(is_array($v3)&&!empty($v3['completed']))rp_lifecycle_fail('failed migration was incorrectly marked completed');rp_lifecycle_ok('failed migration remains retryable and incomplete');}
function rp_lifecycle_assert_completed(): void {$state=get_option('prstudio_uc_migration_state',array());$v3=get_option('prstudio_uc_migration_v3',array());$v4=get_option('prstudio_uc_migration_v4',array());if('completed'!==(string)($state['state']??''))rp_lifecycle_fail('migration state is not completed');if(empty($v3['completed'])||empty($v4['completed']))rp_lifecycle_fail('v3/v4 migration not completed');if(!PRSTUDIO_UC_Store::schema_ready())rp_lifecycle_fail('schema not ready after migration');rp_lifecycle_ok('migration completed with schema ready');}
function rp_lifecycle_mutate(): void {
    global $wpdb;$settings=get_option('wpaib_settings',array());$settings=is_array($settings)?$settings:array();$settings['rp_production_marker']='CORRUPTED';update_option('wpaib_settings',$settings,false);delete_option('prstudio_mcp_v5_clients');delete_option('prstudio_mcp_v5_tokens');delete_option('prstudio_mcp_v5_generation');
    $device_uuid=(string)get_option('rp_production_test_device_uuid','');$job_uuid=(string)get_option('rp_production_test_job_uuid','');if($device_uuid)$wpdb->delete(PRSTUDIO_UC_Store::devices_table(),array('device_uuid'=>$device_uuid));if($job_uuid)$wpdb->delete(PRSTUDIO_UC_Store::jobs_table(),array('job_uuid'=>$job_uuid));$summary=PRSTUDIO_UC_Memory::summary_path();if(is_file($summary))file_put_contents($summary,"CORRUPTED\n",LOCK_EX);rp_lifecycle_ok('persistent state intentionally mutated');
}
function rp_lifecycle_direct_self_test(): void {
    if ( ! class_exists( 'PRSTUDIO_UC_Store' ) || ! class_exists( 'PRSTUDIO_UC_Memory' ) ) { rp_lifecycle_fail( 'runtime classes unavailable' ); }
    if ( ! PRSTUDIO_UC_Store::schema_ready() ) { rp_lifecycle_fail( 'schema not ready for direct self-test' ); }

    $wp_path = rtrim( (string) getenv( 'RP_WP_PATH' ), "/\\" );
    $expected_plugin_root = realpath( $wp_path . '/wp-content/plugins/prstudio-unified-control' );
    $store_file = realpath( (string) ( new ReflectionClass( 'PRSTUDIO_UC_Store' ) )->getFileName() );
    $memory_file = realpath( (string) ( new ReflectionClass( 'PRSTUDIO_UC_Memory' ) )->getFileName() );
    if ( false === $expected_plugin_root || false === $store_file || false === $memory_file ) { rp_lifecycle_fail( 'runtime source paths unavailable' ); }
    $expected_prefix = rtrim( str_replace( '\\', '/', $expected_plugin_root ), '/' ) . '/';
    foreach ( array( 'store' => $store_file, 'memory' => $memory_file ) as $name => $runtime_file ) {
        $normalized = str_replace( '\\', '/', (string) $runtime_file );
        if ( 0 !== strpos( $normalized, $expected_prefix ) ) { rp_lifecycle_fail( "{$name} runtime is not the RP_WP_PATH candidate" ); }
    }

    $first = rp_lifecycle_snapshot();
    $second = rp_lifecycle_snapshot();
    foreach ( array( $first, $second ) as $snapshot ) {
        if ( ! is_array( $snapshot['preserved'] ?? null ) ) { rp_lifecycle_fail( 'snapshot preserved payload missing' ); }
        $hash = (string) ( $snapshot['preserved_hash'] ?? '' );
        if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) { rp_lifecycle_fail( 'snapshot fingerprint invalid' ); }
        $canonical = wp_json_encode( $snapshot['preserved'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! hash_equals( $hash, hash( 'sha256', (string) $canonical ) ) ) { rp_lifecycle_fail( 'snapshot fingerprint does not match preserved payload' ); }
    }
    if ( ! hash_equals( (string) $first['preserved_hash'], (string) $second['preserved_hash'] ) ) { rp_lifecycle_fail( 'read-only snapshots are not deterministic' ); }

    $memory = $first['preserved']['memory'] ?? null;
    if ( ! is_array( $memory ) ) { rp_lifecycle_fail( 'memory filesystem evidence missing' ); }
    foreach ( array( 'memory-summary.txt', 'memory-index.json', 'memory-chain.ndjson' ) as $name ) {
        $fact = $memory[$name] ?? null;
        if ( ! is_array( $fact ) || ! array_key_exists( 'exists', $fact ) || ! array_key_exists( 'bytes', $fact ) || ! array_key_exists( 'sha256', $fact ) ) { rp_lifecycle_fail( "filesystem fact missing for {$name}" ); }
        if ( ! empty( $fact['exists'] ) && ( (int) $fact['bytes'] < 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $fact['sha256'] ) ) ) { rp_lifecycle_fail( "filesystem fact invalid for {$name}" ); }
    }

    fwrite( STDOUT, rp_lifecycle_json( array(
        'ok' => true,
        'mode' => 'direct-real-wordpress',
        'plugin_root' => $expected_plugin_root,
        'plugin_version' => (string) ( $first['plugin_version'] ?? '' ),
        'schema_version' => (string) ( $first['schema_version'] ?? '' ),
        'preserved_hash' => (string) $first['preserved_hash'],
        'memory' => $memory,
    ) ) . "\n" );
}

$action=(string)getenv('RP_LIFECYCLE_ACTION');$file=(string)getenv('RP_LIFECYCLE_FILE');
if ( '' === $action ) { rp_lifecycle_direct_self_test(); exit( 0 ); }
switch($action){
    case 'seed':rp_lifecycle_seed();break;
    case 'snapshot':$snapshot=rp_lifecycle_snapshot();if(''===$file)fwrite(STDOUT,rp_lifecycle_json($snapshot)."\n");else{rp_lifecycle_atomic_write($file,rp_lifecycle_json($snapshot)."\n");rp_lifecycle_ok("snapshot {$file}");}break;
    case 'assert-preserved':if(''===$file||!is_file($file))rp_lifecycle_fail('baseline snapshot missing');$baseline=json_decode((string)file_get_contents($file),true);$current=rp_lifecycle_snapshot();if(!is_array($baseline)||!hash_equals((string)($baseline['preserved_hash']??''),(string)$current['preserved_hash'])){fwrite(STDERR,"BASELINE\n".rp_lifecycle_json($baseline)."\nCURRENT\n".rp_lifecycle_json($current)."\n");rp_lifecycle_fail('persistent state fingerprint changed');}rp_lifecycle_ok('persistent state fingerprint preserved');break;
    case 'prepare-migration-failure':rp_lifecycle_prepare_failure();break;
    case 'cleanup-migration-failure':rp_lifecycle_cleanup_failure();break;
    case 'assert-migration-retryable':rp_lifecycle_assert_retryable();break;
    case 'assert-migration-completed':rp_lifecycle_assert_completed();break;
    case 'mutate':rp_lifecycle_mutate();break;
    default:rp_lifecycle_fail('unknown RP_LIFECYCLE_ACTION');
}
