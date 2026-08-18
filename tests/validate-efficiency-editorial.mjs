#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');
const checks = [];
function check(ok, name) {
  checks.push({ok: Boolean(ok), name});
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}`);
}

const sw = read('prstudio-unified-browser-agent/service-worker.js');
const protocol = read('prstudio-unified-browser-agent/lib/protocol.js');
const mcp = read('prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php');
const lanes = read('prstudio-unified-control/includes/class-prstudio-uc-execution-lanes.php');
const contentTx = read('prstudio-unified-control/includes/class-prstudio-uc-content-transaction.php');
const planner = read('prstudio-unified-control/includes/class-prstudio-uc-planner-v3.php');
const gsc = read('prstudio-unified-control/includes/class-prstudio-uc-search-console-browser.php');
const workbench = read('prstudio-unified-control/includes/class-prstudio-uc-engineering-workbench.php');
const cap = JSON.parse(read('prstudio-unified-control/capabilities/agency-capabilities.json'));
const allCaps = JSON.parse(read('prstudio-unified-control/capabilities/capability-registry.json')).capabilities || [];
const catalog = JSON.parse(read('prstudio-unified-control/connector/action-catalog.json')).actions || [];

check(protocol.includes('playwright_adopt_tabs') && sw.includes('adoptUserTabs'), 'explicit multi-tab adoption is implemented');
check(sw.includes('adoptedExternal') && sw.includes('laneId'), 'adopted user tabs preserve lane ownership');
check(sw.includes('resource_busy_other_context') || sw.includes('lane_conflict') || sw.includes('different lane'), 'browser cross-lane technical contention is typed');
check(protocol.includes('local_studio_run') && sw.includes('executeRemoteLocalStudio'), 'Local Studio remote gateway has a real executor');
check(sw.includes('workflow_run') && sw.includes('baseline_compare') && sw.includes('recorder_start'), 'remote Local Studio exposes workflow/monitor/visual primitives');
check(mcp.includes('prstudio_context_open') && mcp.includes('lane_handle') && mcp.includes('lane_token') && mcp.includes('anyOf'), 'MCP exposes additive lane_handle with legacy lane_token compatibility');
check(mcp.includes("'generic_dispatch_roundtrip_required'=>false") && !mcp.includes('browser_action_typed_precedence') && !mcp.includes('BROWSER_SECURITY_GUARDED'), 'generic browser_action dispatches locally without typed-tool round-trip gating');
check(lanes.includes("'lane_handle'") && lanes.includes('private static function credential'), 'execution lanes resolve the opaque public handle internally');
check(lanes.includes('resource_busy_other_context') && lanes.includes("'locks'"), 'server resources are lease-isolated across chats');
check(sw.includes('gscLaneKey') && sw.includes('sessions[laneKey]'), 'GSC browser sessions are lane-scoped');
check(planner.includes("'mode'=>'quick'") && planner.includes("'local_batch_preferred'=>true") && planner.includes("'model_roundtrip_required'=>false") && planner.includes("'execute','observe','report'") && !planner.includes('max_tool_calls') && !planner.includes('evidence_sufficiency_stop') && !planner.includes('canary_gate'), 'planner is a quick local execution compiler with no policy/evidence gate');
check(!/frontend_verified\s*=>\s*false[\s\S]{0,220}fully_verified\s*=>\s*true/u.test(contentTx), 'content transaction cannot claim full verification after explicit frontend failure');
check(!/function\s+repo_map\s*\([^)]*\)\s*:\s*array\s*\{/u.test(workbench) && workbench.includes('engineering_path_invalid'), 'repo_map invalid paths return typed WP_Error instead of TypeError');
check(workbench.includes("['exitcode']") && workbench.includes('proc_close'), 'engineering process runner preserves Windows exit codes for php_lint');

const inspectAction = catalog.find((item) => item.route === '/themes-manage' && item.action === 'inspect_theme_assets');
const inspectCaps = allCaps.filter((item) => item?.source?.route === '/themes-manage' && item?.source?.action === 'inspect_theme_assets');
check(Boolean(inspectAction?.read_only) && !inspectAction?.destructive, 'inspect_theme_assets catalog action is read-only');
check(inspectCaps.length === 1 && inspectCaps[0].read_only === true && inspectCaps[0].write === false && inspectCaps[0].risk_level === 'low', 'inspect_theme_assets capability is read-only and low-risk');

const ids = new Set((Array.isArray(cap) ? cap : (cap.capabilities || [])).map((x) => x.id));
const required = [
  'render.source.resolve','seo.campaign.manager','seo.keyword_url.registry','seo.serp_intent.observe',
  'content.brief.compile','content.publish.transaction','content.claim.ledger',
  'seo.internal_link.graph','seo.cannibalization.resolver','seo.post_publish.watcher',
  'seo.refresh.prioritize','schema.editorial.compile','media.editorial.pipeline','directory.entity.engine',
  'authority.outreach.engine','insights.first_party.publisher'
];
for (const id of required) check(ids.has(id), `native capability ${id} exists`);

const failed = checks.filter((c) => !c.ok);
console.log(`SUMMARY ${checks.length - failed.length}/${checks.length} passed`);
process.exit(failed.length ? 1 : 0);
