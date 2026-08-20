<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$kernelTargets = array(
    'PRSTUDIO_UC_Store' => 'class-prstudio-uc-store.php',
    'PRSTUDIO_UC_Agency_Runtime' => 'class-prstudio-uc-agency-runtime.php',
    'PRSTUDIO_UC_Job_Engine' => 'class-prstudio-uc-job-engine.php',
    'PRSTUDIO_UC_Verifier' => 'class-prstudio-uc-verifier.php',
    'PRSTUDIO_UC_MCP_Auth_V5' => 'class-prstudio-uc-mcp-auth-v5.php',
    'PRSTUDIO_UC_Idempotency' => 'class-prstudio-uc-idempotency.php',
    'PRSTUDIO_UC_Publish_Transaction' => 'class-prstudio-uc-publish-transaction.php',
);

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    foreach ($kernelTargets as $file) {
        if (!is_file(ABSPATH . 'prstudio-unified-control/includes/' . $file)) {
            fwrite(STDERR, "Kernel target missing: {$file}\n");
            exit(1);
        }
    }
    echo "PHPUNIT CRITICAL KERNEL FILE CONTRACT: PASS targets=" . count($kernelTargets) . "\n";
    return;
}

final class CriticalKernelTest extends \PHPUnit\Framework\TestCase {
    private static function includeTarget(string $file): void {
        require_once ABSPATH . 'prstudio-unified-control/includes/' . $file;
    }

    public function testStoreStateAndLeaseContracts(): void {
        $GLOBALS['wpdb'] = new class { public string $prefix = 'wp_'; };
        self::includeTarget('class-prstudio-uc-store.php');
        self::assertSame('4.0.0', PRSTUDIO_UC_Store::schema_version());
        self::assertSame(120, PRSTUDIO_UC_Store::job_lease_seconds());
        self::assertSame('WAITING_FOR_BROWSER', PRSTUDIO_UC_Store::canonical_job_state('waiting-browser'));
        self::assertTrue(PRSTUDIO_UC_Store::terminal_job_state('failed'));
        self::assertFalse(PRSTUDIO_UC_Store::terminal_job_state('running'));
        self::assertSame('wp_prstudio_uc_tasks', PRSTUDIO_UC_Store::tasks_table());
    }

    public function testAgencyRuntimeRegistersBoundedMinuteSchedule(): void {
        self::includeTarget('class-prstudio-uc-agency-runtime.php');
        $schedules = PRSTUDIO_UC_Agency_Runtime::cron_schedules(array());
        self::assertSame(60, $schedules['prstudio_uc_every_minute']['interval']);
        self::assertSame('1.0.0', PRSTUDIO_UC_Agency_Runtime::VERSION);
    }

    public function testJobEngineCompilesStableBrowserIdentity(): void {
        eval('final class PRSTUDIO_UC_Store { public static array $created = array(); public static function create_task(string $action,array $arguments,?string $device_uuid=null,string $idempotency_key="",string $plan_hash="",string $job_uuid=""):array { self::$created=compact("action","arguments","device_uuid","idempotency_key","plan_hash","job_uuid"); return self::$created; } }');
        self::includeTarget('class-prstudio-uc-idempotency.php');
        self::includeTarget('class-prstudio-uc-job-engine.php');
        $first = PRSTUDIO_UC_Job_Engine::create_browser_task('open url', array('request_id'=>'richiesta-1','url'=>'https://google.com'), 'device-1', 'job-1');
        $second = PRSTUDIO_UC_Job_Engine::create_browser_task('open url', array('request_id'=>'richiesta-1','url'=>'https://google.com'), 'device-1', 'job-1');
        self::assertSame($first['idempotency_key'], $second['idempotency_key']);
        self::assertSame($first['plan_hash'], $second['plan_hash']);
        self::assertSame('job-1', $first['job_uuid']);
    }

    public function testVerifierNeverUpgradesMissingApplicationEvidence(): void {
        self::includeTarget('class-prstudio-uc-idempotency.php');
        self::includeTarget('class-prstudio-uc-verifier.php');
        $task = array('task_uuid'=>'task-1','action'=>'browser_click','arguments'=>array('postcondition'=>array('required'=>true)));
        $missing = PRSTUDIO_UC_Verifier::browser_result($task, array('verified'=>true,'stepType'=>'click'));
        self::assertFalse($missing['ok']);
        self::assertSame('browser_application_acceptance_not_observed', $missing['reason']);
        $accepted = PRSTUDIO_UC_Verifier::browser_result($task, array('verified'=>true,'stepType'=>'click','applicationAccepted'=>true));
        self::assertTrue($accepted['ok']);
        self::assertFalse($accepted['blocking']);
    }

    public function testOAuthMetadataUsesTheRealSiteOrigin(): void {
        self::includeTarget('class-prstudio-uc-mcp-auth-v5.php');
        self::assertSame('https://suite.italia.test', PRSTUDIO_UC_MCP_Auth_V5::issuer());
        $metadata = PRSTUDIO_UC_MCP_Auth_V5::authorization_server_metadata();
        self::assertSame('https://suite.italia.test', $metadata['issuer']);
        self::assertContains('S256', $metadata['code_challenge_methods_supported']);
        self::assertContains('refresh_token', $metadata['grant_types_supported']);
    }

    public function testIdempotencyCanonicalizesEquivalentRequests(): void {
        self::includeTarget('class-prstudio-uc-idempotency.php');
        self::assertSame('richiesta-7', PRSTUDIO_UC_Idempotency::explicit_key(array('request_id'=>' richiesta-7 ')));
        self::assertSame(
            PRSTUDIO_UC_Idempotency::plan_hash('Pubblica_Articolo', array('b'=>2,'a'=>1,'request_id'=>'uno')),
            PRSTUDIO_UC_Idempotency::plan_hash('pubblica_articolo', array('a'=>1,'b'=>2,'request_id'=>'due'))
        );
        self::assertNotSame(PRSTUDIO_UC_Idempotency::canonical_json(NAN), PRSTUDIO_UC_Idempotency::canonical_json(INF));
    }

    public function testPublishTransactionRejectsIncompleteOrUnknownContent(): void {
        self::includeTarget('class-prstudio-uc-publish-transaction.php');
        $missing = PRSTUDIO_UC_Publish_Transaction::create_publish(array('title'=>'Titolo','content'=>'   '));
        self::assertInstanceOf(WP_Error::class, $missing);
        self::assertSame('publish_content_required', $missing->get_error_code());
        $unknown = PRSTUDIO_UC_Publish_Transaction::create_publish(array('title'=>'Titolo','content'=>'Testo','post_type'=>'sconosciuto'));
        self::assertInstanceOf(WP_Error::class, $unknown);
        self::assertSame('publish_post_type_invalid', $unknown->get_error_code());
    }
}
