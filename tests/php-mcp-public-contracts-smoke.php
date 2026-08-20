<?php
declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-public-tool-contracts.php';
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';

function fail_contract(string $message): void { fwrite(STDERR, "FAIL {$message}\n"); exit(1); }
function pass_contract(string $message): void { fwrite(STDOUT, "PASS {$message}\n"); }
function expect_contract(bool $condition, string $message): void { if (!$condition) fail_contract($message); }

$tools = PRSTUDIO_UC_MCP_V5::tools();
$by_name = array();
foreach ($tools as $tool) {
    $by_name[(string)$tool['name']] = $tool;
}

$targets = array(
    'agency_status','browser_launch','browser_open','browser_screenshot',
    'browser_snapshot','browser_status','browser_task_control','engineering_repo_map','engineering_status',
    'procedural_skill_get','procedural_skill_invalidate','procedural_skill_search','procedural_skill_status',
    'prstudio_backlog','prstudio_capability_describe','prstudio_capability_search','prstudio_context_close',
    'prstudio_context_open','prstudio_context_status','prstudio_do','prstudio_execute','prstudio_flow',
    'prstudio_health','prstudio_intervention_record','prstudio_job_control','prstudio_job_get',
    'prstudio_observe','prstudio_seo_autopilot_status','prstudio_tool_manual','sentinel_scan',
    'sequential_thinking_session','sequential_thinking_status','social_metrics_ingest','twin_query',
    'wordpress_content_transaction',
);
foreach ($targets as $name) {
    expect_contract(isset($by_name[$name]), "missing refined public tool {$name}");
    expect_contract(strlen((string)$by_name[$name]['description']) >= 45, "{$name} needs a discriminating model-facing description");
}
expect_contract(!isset($by_name['browser_live_status']), 'removed Browser LIVE status tool must stay absent from the public catalog');

foreach (array('agency_status','engineering_status','procedural_skill_status','prstudio_context_status','sequential_thinking_status') as $name) {
    expect_contract(($by_name[$name]['inputSchema']['additionalProperties'] ?? null) === false, "{$name} must reject unknown input");
    expect_contract(($by_name[$name]['inputSchema']['maxProperties'] ?? null) === 0, "{$name} must explicitly advertise a zero-argument contract");
}

$open = $by_name['browser_open']['inputSchema'];
expect_contract(!isset($open['properties']['url']['format']) && !isset($open['properties']['url']['pattern']), 'browser_open.url must allow the Browser Agent to normalize bare hosts to HTTPS');
expect_contract(($open['properties']['wait_until']['enum'] ?? array()) === array('complete','interactive','none'), 'browser_open.wait_until must be an enum');

$snapshot = $by_name['browser_snapshot']['inputSchema'];
expect_contract(($snapshot['properties']['selector_type']['enum'] ?? array()) === array('auto','css','text','role','label','xpath'), 'browser_snapshot.selector_type must be closed');
expect_contract(strpos((string)$snapshot['properties']['selector_type']['description'], 'semantic') !== false, 'browser_snapshot must tell the model to prefer semantic locators');

$flow = $by_name['prstudio_flow'];
$step = $flow['inputSchema']['properties']['steps']['items'] ?? array();
expect_contract(isset($step['oneOf']) && count($step['oneOf']) === 2, 'prstudio_flow step must select exactly one of tool/capability');
expect_contract(($step['additionalProperties'] ?? null) === false, 'prstudio_flow step must reject unknown envelope keys');
expect_contract(($step['properties']['arguments']['additionalProperties'] ?? null) === true, 'prstudio_flow arguments stay dynamic by design');
expect_contract(($flow['annotations']['readOnlyHint'] ?? true) === false, 'prstudio_flow cannot claim read-only because steps may write');
expect_contract(($flow['annotations']['destructiveHint'] ?? false) === true, 'prstudio_flow must conservatively advertise destructive potential');
expect_contract(($flow['annotations']['idempotentHint'] ?? true) === false, 'prstudio_flow cannot claim idempotence for arbitrary steps');

$execute = $by_name['prstudio_execute']['inputSchema'];
expect_contract(($execute['properties']['arguments']['additionalProperties'] ?? null) === true, 'prstudio_execute.arguments must remain capability-defined/dynamic');
expect_contract(strpos((string)$execute['properties']['arguments']['description'], 'prstudio_capability_describe') !== false, 'prstudio_execute must explain where the exact dynamic schema comes from');

$do = $by_name['prstudio_do']['inputSchema'];
expect_contract(($do['properties']['params']['additionalProperties'] ?? null) === true, 'prstudio_do.params must remain intent-defined/dynamic');
expect_contract(strpos((string)$do['properties']['params']['description'], 'Dynamic by design') !== false, 'prstudio_do must explain why params is dynamic');

$capability_search = $by_name['prstudio_capability_search']['inputSchema'];
expect_contract(strpos((string)$capability_search['properties']['query']['description'], 'Italian or English') !== false, 'capability search must advertise its bilingual public contract');
expect_contract(strpos((string)$capability_search['properties']['include_legacy']['description'], 'Default true') !== false, 'capability search must advertise the complete catalog as its public default');

$observe = $by_name['prstudio_observe']['inputSchema'];
expect_contract(isset($observe['allOf']) && count($observe['allOf']) >= 4, 'prstudio_observe must advertise target-dependent argument requirements');
expect_contract(!in_array('target', $observe['required'] ?? array(), true), 'prstudio_observe must preserve id/url target inference compatibility');
expect_contract(($observe['properties']['anchors']['uniqueItems'] ?? false) === true, 'prstudio_observe anchors should be unique');

$sentinel = $by_name['sentinel_scan']['inputSchema'];
expect_contract(($sentinel['properties']['scope']['items']['enum'] ?? array()) === array('health','queue','content'), 'sentinel_scan.scope must be a closed enum');
expect_contract(($sentinel['properties']['scope']['uniqueItems'] ?? false) === true, 'sentinel_scan.scope should reject duplicate dimensions');

$social = $by_name['social_metrics_ingest'];
$social_schema = $social['inputSchema'];
expect_contract(($social_schema['additionalProperties'] ?? null) === false, 'social_metrics_ingest root must be closed');
expect_contract(in_array('account', $social_schema['required'] ?? array(), true), 'social_metrics_ingest.account must be required because the handler rejects an empty account');
expect_contract(($social_schema['properties']['platform']['enum'] ?? array()) === array('instagram','facebook','tiktok','youtube','linkedin','x','threads','pinterest','snapchat','other'), 'social platform vocabulary must match runtime normalization');
expect_contract(($social_schema['properties']['source']['enum'] ?? array()) === array('manual','browser_live','api','webhook','import'), 'social source vocabulary must match runtime normalization');
expect_contract(($social_schema['properties']['metrics']['maxProperties'] ?? 0) === 120, 'social aggregate metrics must advertise the runtime bound');
expect_contract(($social_schema['properties']['metrics']['additionalProperties']['type'] ?? '') === 'number', 'social aggregate metrics must be numeric');
expect_contract(($social_schema['properties']['content']['maxItems'] ?? 0) === 100, 'social content must advertise the runtime bound');
expect_contract(($social['annotations']['idempotentHint'] ?? true) === false, 'social ingest must not falsely advertise idempotence');

$twin = $by_name['twin_query']['inputSchema'];
expect_contract(in_array('query', $twin['required'] ?? array(), true), 'twin_query.query must be required');

$transaction = $by_name['wordpress_content_transaction']['inputSchema'];
expect_contract(isset($transaction['allOf']) && count($transaction['allOf']) === 1, 'content transaction must advertise operation-dependent search requirement');
expect_contract(($transaction['properties']['expected_before_sha256']['pattern'] ?? '') === '^[a-fA-F0-9]{64}$', 'content transaction SHA-256 precondition must be structurally constrained');

$job = $by_name['prstudio_job_get']['inputSchema'];
expect_contract(($job['properties']['job_id']['format'] ?? '') === 'uuid', 'durable job IDs must advertise UUID format');

$json = json_encode($tools, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
expect_contract($json !== false, 'refined catalog must JSON encode');
expect_contract(strlen((string)$json) < 1024 * 1024, 'refined full catalog must remain below the 1 MiB hard response budget');

pass_contract('All 35 requested public ChatGPT/MCP contracts are present and refined');
pass_contract('Removed Browser LIVE public tooling remains absent');
pass_contract('Dynamic envelopes remain dynamic only where runtime-selected schemas require it');
pass_contract('Conditional, enum, annotation, and ingest contracts match runtime behavior');
