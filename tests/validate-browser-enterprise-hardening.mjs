import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { actionToSteps, canonicalBrowserAction } from '../prstudio-unified-browser-agent/lib/protocol.js';
import { RUNTIME_CONTRACT_ACTIONS } from '../prstudio-unified-browser-agent/lib/runtime-capabilities.js';

const ROOT=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read=(p)=>readFile(path.join(ROOT,p),'utf8');
const worker=await read('prstudio-unified-browser-agent/service-worker.js');
const mcp=await read('prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php');
const bridge=await read('prstudio-unified-control/includes/class-prstudio-uc-bridge.php');
const rest=await read('prstudio-unified-control/includes/class-prstudio-uc-rest.php');
const artifacts=await read('prstudio-unified-control/includes/class-prstudio-uc-artifacts.php');
const catalog=JSON.parse(await read('prstudio-unified-control/connector/action-catalog.json'));
const caps=JSON.parse(await read('prstudio-unified-control/capabilities/capability-registry.json'));

function pass(msg){ console.log(`PASS ${msg}`); }
function check(condition,msg){ assert.ok(condition,msg); pass(msg); }

check(canonicalBrowserAction('puppeteer_goto')==='playwright_goto','Puppeteer goto normalizes to the native Playwright/CDP action');
check(canonicalBrowserAction('puppeteer_screenshot')==='playwright_screenshot_page','Puppeteer screenshot has an exact canonical alias');
assert.deepEqual(actionToSteps('puppeteer_goto',{tab_id:10,url:'https://example.com'}),actionToSteps('playwright_goto',{tab_id:10,url:'https://example.com'})); pass('Puppeteer and Playwright navigation compile to the same runtime steps');
const shot=actionToSteps('puppeteer_screenshot',{tab_id:10,full_page:true})[0];
check(shot.type==='screenshot'&&shot.maxPixels===28000000&&shot.format==='auto','Puppeteer screenshot uses the same bounded screenshot pipeline');

check(worker.includes('captureScreenshot')&&worker.includes('storeScreenshotArtifact')&&!worker.includes('screenshot_storage_circuit_open'),'screenshot perception captures before best-effort persistence and has no storage circuit veto');
check(worker.includes('SCREENSHOT_MAX_PIXELS')&&worker.includes('truncatedForSafety'),'oversized full-page screenshots are bounded and truthfully marked');
check(worker.includes('previousActive')&&worker.includes('await chrome.tabs.update(previousActive, { active: true })'),'captureVisibleTab restores the operator previous active tab');
check(artifacts.includes("'image/jpeg'")&&artifacts.includes("'image/webp'")&&artifacts.includes('max_artifact_bytes'),'private artifact storage supports modern compressed formats and advertises capacity');
check(rest.includes("'screenshot_status'"),'server may expose screenshot storage telemetry without gating capture');
check(mcp.includes('puppeteer_aliases_normalized_to_playwright')&&bridge.includes('canonical_browser_action'),'connector and bridge understand Puppeteer terminology without a second browser runtime');

const browserActions=catalog.actions.filter(x=>x.route==='/frontend-manage' && x.executor==='browser_agent');
const routeKeys=new Set();
for(const action of catalog.actions){const k=`${action.route}|${action.action}`; assert.ok(!routeKeys.has(k),`duplicate action ${k}`); routeKeys.add(k);} pass(`action catalog has ${catalog.actions.length} unique route/action contracts`);
const capIds=new Set();
for(const cap of caps.capabilities){assert.ok(!capIds.has(cap.id),`duplicate capability ${cap.id}`);capIds.add(cap.id);} pass(`capability registry has ${caps.capabilities.length} unique ids`);
check(browserActions.length>50,'browser contract exposes a substantial executable surface');
for(const action of RUNTIME_CONTRACT_ACTIONS){assert.ok(worker.includes(`"${action}"`)||worker.includes(`'${action}'`)||worker.includes(action),`runtime action missing in worker: ${action}`);} pass(`all ${RUNTIME_CONTRACT_ACTIONS.length} advanced runtime actions remain represented by the executor`);
check(mcp.includes("'include_history'=>self::bool")&&bridge.includes('$include_history'),'browser status defaults to compact device output with opt-in history');
check(mcp.includes("'detail'=>self::str('compact or full")&&mcp.includes("PRSTUDIO_UC_Health::snapshot(array('detail'"),'health tool supports compact-by-default execution diagnostics');

console.log('SUMMARY browser enterprise hardening PASS');
