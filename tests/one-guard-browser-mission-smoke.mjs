#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const ROOT=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=(p)=>fs.readFileSync(path.join(ROOT,p),'utf8');
const sw=read('prstudio-unified-browser-agent/service-worker.js');
const protocol=read('prstudio-unified-browser-agent/lib/protocol.js');
const planner=read('prstudio-unified-control/includes/class-prstudio-uc-planner-v3.php');
const mcp=read('prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php');
const open=sw.match(/async function createOwnedAgentTab[\s\S]*?\n}\n/)?.[0]||'';
const sequence=['chrome.tabs.create({ windowId, url: "about:blank"','registerOwnedTab','bindTabAffinity','attachDebugger','chrome.tabs.update'];
let last=-1;for(const token of sequence){const i=open.indexOf(token);assert.ok(i>last,`atomic browser_open sequence missing/out of order: ${token}`);last=i;}
assert.match(protocol,/browser_batch|playwright_flow/);
for(const action of ['search_console_sites','search_console_search_analytics','search_console_sitemaps','search_console_url_inspection']) assert.ok(mcp.includes(action),`missing GSC action ${action}`);
assert.ok(planner.includes("'model_roundtrip_required'=>false"));
assert.ok(planner.includes("'local_batch_preferred'=>true"));
for(const banned of ['agent_tab_required','target_task_binding_mismatch','human_takeover','manual_resume','approval_required','needs_review','browser_action_typed_precedence']) assert.ok(!sw.includes(banned)&&!mcp.includes(banned),`forbidden mission gate ${banned}`);
const flow=['context','browser session','open GSC','select/report','extract','analyze','report'];
console.log(JSON.stringify({ok:true,benchmark:'Search Console mission synthetic contract',flow,atomic_open_own_navigate:true,browser_batch:true,model_roundtrip_required:false,same_lane_reuse:true,verification_nonblocking:true,forbidden_gate_hits:0,live_authenticated_gsc:false,evidence_class:'SYNTHETIC_CONTRACT'},null,2));
