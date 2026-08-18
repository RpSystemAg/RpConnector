import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (file) => readFile(path.join(ROOT, file), 'utf8');

const manifest = JSON.parse(await read('prstudio-unified-browser-agent/manifest.json'));
const worker = await read('prstudio-unified-browser-agent/service-worker.js');
const studio = await read('prstudio-unified-browser-agent/lib/local-studio.js');
const pageFns = await read('prstudio-unified-browser-agent/lib/local-page-functions.js');
const meta = await read('prstudio-unified-browser-agent/lib/executor-meta.js');
const sidepanel = await read('prstudio-unified-browser-agent/sidepanel.html');
const rest = await read('prstudio-unified-control/includes/class-prstudio-uc-rest.php');
const bridge = await read('prstudio-unified-control/includes/class-wpaib-rest.php');
const browserBridge = await read('prstudio-unified-control/includes/class-prstudio-uc-bridge.php');

const expectedPermissions = ['tabs','scripting','storage','debugger','sidePanel','notifications','alarms','windows','system.display','activeTab','tabCapture','offscreen','contextMenus'];
assert.equal(manifest.version, '17.0.0', 'Chrome extension version must stay 17.0.0');
assert.equal(manifest.manifest_version, 3, 'Manifest V3 must remain unchanged');
assert.deepEqual(manifest.permissions, expectedPermissions, 'Local Studio permissions include system.display for explicit screenshot/CSS/screen transforms');
assert.deepEqual(manifest.host_permissions, ['<all_urls>'], 'Existing host permission contract must remain unchanged');
assert.match(meta, /EXECUTOR_PRODUCT_VERSION\s*=\s*["']17\.0\.0["']/);
assert.match(meta, /EXECUTOR_PROTOCOL_VERSION\s*=\s*["']3\.0\.0["']/);

for (const token of [
  'standalone_mode', 'visual_recorder', 'workflow_library', 'smart_element_inspector',
  'flight_recorder', 'page_health_audit', 'debug_capture', 'diagnostic_report_builder',
  'responsive_matrix', 'semantic_diff', 'bounded_site_scan', 'visual_baseline',
  'workspace_manager', 'command_palette', 'scheduled_local_checks',
  'origin_permission_profiles', 'local_recovery_console', 'workflow_import_export',
]) assert.ok(studio.includes(`"${token}"`), `Missing local feature ${token}`);
assert.match(studio, /noExternalAccounts:\s*true/);
assert.match(studio, /noApiKeys:\s*true/);
assert.match(studio, /installationContractUnchanged:\s*true/);
assert.doesNotMatch(studio, /fetch\s*\(/, 'Local Studio capability model must not call external services');
assert.doesNotMatch(pageFns, /\beval\s*\(|new\s+Function\s*\(/, 'Injected local functions may not execute arbitrary JavaScript');

for (const token of [
  'local_stop_during_mutating_step',
  'workflow.step.interrupted_uncertain',
  'localFeatures: [...LOCAL_STUDIO_FEATURES]',
  'serverCapabilities: data.server_capabilities',
  'heartbeat?.server_capabilities',
]) assert.ok(worker.includes(token), `Missing technical local integration invariant: ${token}`);
assert.doesNotMatch(worker, /local_remote_lane_busy/, 'local site scans are not vetoed merely because remote work exists');
assert.match(worker, /validateCdpCommand\(method, params, "internal"\)/, 'Local debugger must use the existing CDP allowlist');
assert.match(worker, /async function localDebugCapture[\s\S]{0,5000}let operationError = null;[\s\S]{0,5000}let cleanupError = null;/, 'Local debug capture must track operation and cleanup failures separately');
assert.match(worker, /finally \{[\s\S]{0,500}if \(attached\) \{[\s\S]{0,500}await detachDebugger\(tab\.id, "local_debug_capture_complete"\)[\s\S]{0,500}catch \(error\) \{ cleanupError = error; \}/, 'Local debugger capture must attempt evidence-aware detach in finally and retain detach failure');
assert.match(worker, /if \(operationError && cleanupError\) throw codedError\("LOCAL_DEBUG_CAPTURE_CLEANUP_FAILED"[\s\S]{0,500}if \(cleanupError\) throw cleanupError;[\s\S]{0,500}if \(operationError\) throw operationError;/, 'Local debugger capture returns a typed technical error when operation or detach cleanup fails');
assert.match(worker, /chrome\.debugger\.detach\(\{\s*tabId:\s*id\s*\}\)/, 'Debugger cleanup helper must detach the owned tab through the bounded helper');
assert.match(worker, /function debuggerDetachWithTimeout[\s\S]{0,700}promiseWithTimeout[\s\S]{0,700}CDP_DETACH_TIMEOUT/, 'Debugger detach must remain timeout-bounded and fail with a typed error');
assert.ok(worker.includes('Emulation.clearDeviceMetricsOverride'), 'Responsive matrix must restore device metrics');
assert.ok(worker.includes('diagnostic.report.created'), 'Local diagnostic report builder must be present');
assert.ok(worker.includes('site_scan.completed'), 'Bounded site scan must be present');

for (const id of ['siteUrl','pairCode','deviceName','pairButton']) {
  assert.ok(sidepanel.includes(`id="${id}"`), `Existing pairing UI field ${id} must remain available`);
}
assert.ok(sidepanel.includes('<dt>Compatibilità</dt><dd id="protocol"></dd>'), 'Wire compatibility must remain visible without protocol jargon');
assert.ok(sidepanel.includes('Installazione e collegamento restano invariati'), 'Unchanged install/pairing contract must remain clear to operators');

for (const token of [
  "'server_capabilities' => self::integration_capabilities()",
  'public static function integration_capabilities()',
  'public static function browser_extension_summary( bool $include_history = false )',
  "'local_features_remote_invocation' => (bool) array_filter",
  "'local_features_discovery_via_heartbeat' => true",
  "'pairing_contract_unchanged' => true",
  "'wordpress_install_contract_unchanged' => true",
]) assert.ok(rest.includes(token), `Plugin integration awareness missing: ${token}`);

assert.ok(bridge.includes("'browser_extension' => class_exists( 'PRSTUDIO_UC_REST' ) ? PRSTUDIO_UC_REST::browser_extension_summary()"));
assert.ok(bridge.includes("'integration_chain' => class_exists( 'PRSTUDIO_UC_REST' ) ? PRSTUDIO_UC_REST::integration_capabilities()"));
assert.ok(browserBridge.includes("'extension_local_studio' => class_exists( 'PRSTUDIO_UC_REST' ) ? PRSTUDIO_UC_REST::browser_extension_summary( $include_history )"));
assert.ok(browserBridge.includes("'integration_chain' => class_exists( 'PRSTUDIO_UC_REST' ) ? PRSTUDIO_UC_REST::integration_capabilities()"));

// Local Studio remains off the public REST surface but is now remotely invokable through the lane-isolated MCP→Browser gateway.
assert.doesNotMatch(rest, /register_rest_route[\s\S]{0,120}local_studio/i);
assert.ok(worker.includes('executeRemoteLocalStudio'), 'Browser Agent must implement the remote Local Studio gateway');
assert.ok(worker.includes('remote_local_lane_required'), 'Remote Local Studio must require an execution lane');

console.log('PASS local integration contract: unchanged install/pairing, lane-isolated remote Local Studio gateway, no extra account/API dependency.');
