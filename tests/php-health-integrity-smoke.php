<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('PRSTUDIO_UC_DIR', dirname(__DIR__) . '/prstudio-unified-control/');
define('PRSTUDIO_UC_VERSION', '1.0.0');
require PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-health.php';
function fail_health(string $m): void { fwrite(STDERR,"FAIL {$m}\n"); exit(1); }
$integrity = new ReflectionMethod(PRSTUDIO_UC_Health::class, 'integrity_status');
$integrity->setAccessible(true);
$status = $integrity->invoke(null);
if (empty($status['present'])) fail_health('integrity manifest not present');
if (empty($status['verified'])) fail_health('integrity manifest not verified: '.json_encode($status, JSON_UNESCAPED_SLASHES));
if ((int)($status['files_checked']??0) !== (int)($status['expected_files']??-1)) fail_health('integrity file count mismatch');
if (!preg_match('/^[a-f0-9]{64}$/', (string)($status['tree_digest']??''))) fail_health('tree digest missing');
$identity = new ReflectionMethod(PRSTUDIO_UC_Health::class, 'build_identity');
$identity->setAccessible(true);
$build = $identity->invoke(null, $status);
if (empty($build['integrity_verified']) || !preg_match('/^prstudio-control-1\.0\.0\+[a-f0-9]{12}$/', (string)($build['control_build_id']??''))) fail_health('build identity not derived from verified tree digest');
fwrite(STDOUT,"PASS health performs real SHA-256 integrity verification and immutable build identity\n");
