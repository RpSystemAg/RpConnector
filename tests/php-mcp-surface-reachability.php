<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);

if (!function_exists('sanitize_key')) {
    function sanitize_key($value): string {
        $value = strtolower((string)$value);
        return preg_replace('/[^a-z0-9_\-]/', '', $value) ?: '';
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(private string $code='', private string $message='', private array $data=array()) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data(): array { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($value): bool { return $value instanceof WP_Error; } }

require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-do.php';

function mcp_fail(string $message): never { fwrite(STDERR, "FAIL {$message}\n"); exit(1); }
function mcp_pass(string $message): void { fwrite(STDOUT, "PASS {$message}\n"); }

$built = PRSTUDIO_UC_MCP_V5::tools();
$budget = PRSTUDIO_UC_MCP_V5::advertised_tools_for_test();
$advertised = (array)($budget['tools'] ?? array());
$names = static fn(array $rows): array => array_values(array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $rows));
$builtNames = $names($built);
$advertisedNames = $names($advertised);
$hiddenNames = array_values(array_diff($builtNames, $advertisedNames));

if (count($builtNames) !== count(array_unique($builtNames))) mcp_fail('duplicate built tool names');
if (count($advertisedNames) !== count(array_unique($advertisedNames))) mcp_fail('duplicate advertised tool names');
if (array_diff($advertisedNames, $builtNames)) mcp_fail('tools/list advertised a tool that was not built');

$source = (string)file_get_contents(dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php');
preg_match_all("/case\\s+'([^']+)'\\s*:/", $source, $caseMatches);
$caseNames = array_values(array_unique($caseMatches[1] ?? array()));
$executableNames = array_values(array_intersect($builtNames, $caseNames));
$deadEndNames = array_values(array_diff($builtNames, $executableNames));

$routableNames = array();
$unroutableNames = array();
foreach ($hiddenNames as $name) {
    $route = PRSTUDIO_UC_Do::resolve(array('intent'=>$name, 'params'=>array()));
    if (!is_wp_error($route) && (string)($route['tool'] ?? '') === $name && (string)($route['routing']['confidence'] ?? '') === 'canonical_tool') {
        $routableNames[] = $name;
    } else {
        $unroutableNames[] = $name;
    }
}

// The concrete production regression: this canonical name used to fall through
// to a generic capability search whenever tools/list trimmed it.
$gscRoute = PRSTUDIO_UC_Do::resolve(array('intent'=>'gsc_search_analytics', 'params'=>array()));
if (is_wp_error($gscRoute) || (string)($gscRoute['tool'] ?? '') !== 'gsc_search_analytics') {
    mcp_fail('gsc_search_analytics canonical intent is not directly routable');
}

$advertisedNotExecutable = array_values(array_diff($advertisedNames, $executableNames));
$hiddenNotExecutable = array_values(array_diff($hiddenNames, $executableNames));
$deadEnd = array_values(array_unique(array_merge($deadEndNames, $unroutableNames, $advertisedNotExecutable)));

$report = array(
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('c'),
    'tools_built' => count($builtNames),
    'tools_advertised' => count($advertisedNames),
    'tools_hidden' => count($hiddenNames),
    'tools_routable' => count($routableNames),
    'tools_unroutable' => count($unroutableNames),
    'tools_executable' => count($executableNames),
    'tools_dead_end' => count($deadEnd),
    'tools_list_token_budget' => PRSTUDIO_UC_MCP_V5::tools_list_budget_for_test(),
    'tools_list_approx_tokens' => (int)($budget['tokens'] ?? 0),
    'hidden_tools' => $hiddenNames,
    'routable_hidden_tools' => $routableNames,
    'unroutable_tools' => $unroutableNames,
    'non_executable_tools' => $deadEndNames,
    'advertised_not_executable' => $advertisedNotExecutable,
    'hidden_not_executable' => $hiddenNotExecutable,
    'dead_end_tools' => $deadEnd,
    'gsc_search_analytics_route' => array(
        'tool' => (string)($gscRoute['tool'] ?? ''),
        'confidence' => (string)($gscRoute['routing']['confidence'] ?? ''),
    ),
    'ok' => empty($unroutableNames) && empty($deadEndNames) && empty($advertisedNotExecutable),
);

$dir = dirname(__DIR__) . '/artifacts/mcp';
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) mcp_fail('cannot create MCP artifact directory');
if (false === file_put_contents($dir . '/mcp-surface-certification.json', json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n")) {
    mcp_fail('cannot write MCP certification report');
}

if ($report['tools_built'] !== 120) mcp_fail('expected 120 built tools, got ' . $report['tools_built']);
if ($report['tools_advertised'] < 1 || $report['tools_advertised'] >= $report['tools_built']) mcp_fail('budgeted tools/list did not exercise hidden-tool routing');
if ($report['tools_unroutable'] !== 0) mcp_fail('unroutable hidden tools: ' . implode(',', $unroutableNames));
if ($report['tools_dead_end'] !== 0) mcp_fail('dead-end tools: ' . implode(',', $deadEnd));
if ($report['tools_executable'] !== $report['tools_built']) mcp_fail('not every built tool has a runtime executor');
if ($report['tools_list_approx_tokens'] > $report['tools_list_token_budget']) mcp_fail('tools/list exceeds declared token budget');

mcp_pass("built={$report['tools_built']} advertised={$report['tools_advertised']} hidden={$report['tools_hidden']}");
mcp_pass("hidden_routable={$report['tools_routable']} unroutable=0 executable={$report['tools_executable']} dead_end=0");
mcp_pass('gsc_search_analytics canonical intent routes directly to its executor');
mcp_pass('machine report artifacts/mcp/mcp-surface-certification.json');
