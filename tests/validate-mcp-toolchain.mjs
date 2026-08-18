import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (file) => readFile(path.join(ROOT, file), 'utf8');
const toolchain = await read('prstudio-unified-control/includes/class-prstudio-uc-mcp-toolchain.php');
const bootstrap = await read('prstudio-unified-control/prstudio-unified-control.php');
const rest = await read('prstudio-unified-control/includes/class-prstudio-uc-rest.php');
const worker = await read('prstudio-unified-browser-agent/service-worker.js');
const sidepanel = await read('prstudio-unified-browser-agent/sidepanel.js');
const overlay = JSON.parse(await read('prstudio-unified-control/capabilities/agency-capabilities.json'));
const manifest = JSON.parse(await read('prstudio-unified-browser-agent/manifest.json'));

assert.match(toolchain, /public const VERSION = '17\.0\.0'/);
assert.equal(manifest.version, '17.0.0');
assert.deepEqual(manifest.permissions, ['tabs','scripting','storage','debugger','sidePanel','notifications','alarms','windows','system.display','activeTab','tabCapture','offscreen','contextMenus']);

const requiredCaps = [
  'toolchain.status', 'toolchain.filesystem.inspect', 'toolchain.filesystem.write', 'toolchain.git.inspect',
  'toolchain.sqlite.query', 'toolchain.postgres.query', 'toolchain.wpcli.inspect', 'toolchain.image.optimize',
  'toolchain.pdf.read', 'toolchain.pandoc.convert', 'toolchain.sidecar.tools', 'toolchain.sidecar.read', 'toolchain.sidecar.call',
  'toolchain.accessibility.axe_scan', 'toolchain.mermaid.render', 'toolchain.dependencies.osv', 'toolchain.pdf.evidence', 'toolchain.local_wp.read',
];
for (const id of requiredCaps) {
  const cap = overlay.capabilities.find((row) => row.id === id);
  assert.ok(cap, `missing capability ${id}`);
  assert.equal(cap.version, '17.0.0', `${id} version drift`);
  assert.match(cap.executor, /^PRSTUDIO_UC_MCP_Toolchain::/);
}
assert.equal(overlay.capabilities.find((row) => row.id === 'toolchain.sidecar.call')?.risk_level, 'high');
assert.equal(overlay.capabilities.find((row) => row.id === 'toolchain.sidecar.call')?.read_only, false);
assert.equal(overlay.capabilities.find((row) => row.id === 'toolchain.sidecar.read')?.read_only, true);

// Every external package is pinned. No mutable @latest is allowed into the release artifact.
for (const pin of [
  '@modelcontextprotocol/server-filesystem@2026.7.10',
  'mcp-server-git==2026.7.10',
  '@mseep/mcp-server-sqlite-npx@0.3.0',
  'crystaldba/postgres-mcp:0.3.0',
  '@mseep/mcp-accessibility-scanner@1.0.7',
  '@sylphx/pdf-reader-mcp@4.1.2',
  '@peng-shawn/mermaid-mcp-server@0.2.0',
  '@cyanheads/osv-advisory-mcp-server@0.1.12',
  '@verygoodplugins/mcp-local-wp@1.1.0',
]) assert.ok(toolchain.includes(pin), `missing pinned integration ${pin}`);
assert.doesNotMatch(toolchain, /@latest/);

// No user-selectable executable or shell interpolation.
assert.doesNotMatch(toolchain, /shell_exec\s*\(|passthru\s*\(|\bsystem\s*\(|\bpopen\s*\(/);
assert.doesNotMatch(toolchain, /proc_open\s*\(\s*\$[a-zA-Z_]+\s*\./, 'proc_open command must never be concatenated');
assert.match(toolchain, /'bypass_shell'=>true/);
assert.match(toolchain, /sidecar_profiles\(\)/);
assert.match(toolchain, /path_inside_roots/);
assert.match(toolchain, /toolchain_path_outside_root/);
assert.match(toolchain, /WPCLI_ALLOW_WRITES'=>'false'/);
assert.match(toolchain, /MYSQL_ALLOW_WRITES'=>'false'/);
assert.match(toolchain, /FS_ALLOW_WRITES'=>'false'/);
assert.match(toolchain, /disabled_upstream_security/);
assert.match(toolchain, /toolchain_sidecar_upstream_security_hold/);
assert.match(toolchain, /OTEL_ENABLED'=>'false'/);
assert.match(toolchain, /node_24_required_by_sidecar/);
assert.match(toolchain, /native_supersedes_sidecar/);
assert.match(toolchain, /browser_agent_cdp/);
assert.match(toolchain, /toolchain_pdf_remote_source_denied/);
assert.match(toolchain, /toolchain_mermaid_name_invalid/);
assert.match(toolchain, /wp_image_editor/);

// The executor must be resolvable when the capability registry checks
// callability. In 15.x that was expressed as require ordering in the bootstrap;
// 17.0 loads classes on demand through a class map, so the guarantee is now
// "both classes are mapped and their files exist" — which is stronger, because
// it holds regardless of which entry point runs first.
//
// PRSTUDIO_UC_Capability_Registry declares no parent and touches the toolchain
// only from method bodies, so there is no class-definition-time dependency for
// ordering to protect.
const autoloader = await read('prstudio-unified-control/includes/class-prstudio-uc-autoload.php');
for (const [cls, file] of [
  ['PRSTUDIO_UC_MCP_Toolchain', 'includes/class-prstudio-uc-mcp-toolchain.php'],
  ['PRSTUDIO_UC_Capability_Registry', 'includes/class-prstudio-uc-capability-registry.php'],
]) {
  assert.ok(autoloader.includes(`'${cls}' => '${file}'`), `${cls} must be in the autoloader class map`);
  assert.ok(existsSync(path.join(ROOT, 'prstudio-unified-control', file)), `${file} must exist`);
}
// Exactly one require survives in the bootstrap: the autoloader itself, which
// cannot autoload itself. Any other per-class require is a regression back to
// parsing the whole plugin on every request.
const bootstrapRequires = [...bootstrap.matchAll(/require_once\s+[A-Z_]+\s*\.\s*'([^']+)'/g)].map((m) => m[1]);
assert.deepEqual(
  bootstrapRequires,
  ['includes/class-prstudio-uc-autoload.php'],
  `bootstrap must require only the autoloader, found: ${bootstrapRequires.join(', ')}`,
);

// Bidirectional awareness rides the existing pairing/heartbeat contract.
for (const token of [
  "'mcp_toolchain_version'", "'mcp_toolchain_native_first' => true", "'mcp_toolchain_sidecars_optional' => true",
  "'mcp_toolchain_no_boot_processes' => true", "'mcp_toolchain_profiles' => $toolchain_profiles",
]) assert.ok(rest.includes(token), `server awareness missing ${token}`);
assert.match(worker, /serverMcpToolchainAware:\s*true/);
assert.match(worker, /optionalMcpSidecarsAware:\s*true/);
assert.match(worker, /nativeMcpCompatibility:\s*\["wordpress_mcp", "playwright", "chrome_devtools", "accessibility", "image_optimizer"\]/);
assert.match(sidepanel, /mcp_toolchain_profiles/);

// Do not add a second remote contract just for toolchain federation.
assert.doesNotMatch(rest, /register_rest_route[\s\S]{0,160}toolchain/i);
assert.equal(overlay.schema_version, '17.0.0');
assert.equal(overlay.suite_version, '17.0.0');

console.log('PASS MCP toolchain integration: native-first, pinned optional sidecars, sandboxed processes, unchanged 17.0.0 install/pairing with audited WebRTC Chrome permission expansion.');
