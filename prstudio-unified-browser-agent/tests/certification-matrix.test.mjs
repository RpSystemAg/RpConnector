import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parseUserUrl } from "../lib/url-input.js";
import { candidateTabUrl, provisionalOwnershipState, migrateTabReplacementRecord, migrateTabReplacementState, tabBindingCompatibility } from "../lib/tab-ownership.js";
import { normalizeUrlForEvidence, assessUserUrlRegex, testUserUrlRegex, matchLiteralWildcard, compareUrlEvidence } from "../lib/protocol.js";
import { DEBUGGER_PROTOCOL_CANDIDATES, attachWithProtocolFallback } from "../lib/cdp-protocol.js";

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), "..");
const manifest = JSON.parse(fs.readFileSync(path.join(root, "manifest.json"), "utf8"));

// Every generated subtest below has a distinct runtime input/state tuple and an
// explicit oracle. Real-Chrome/remote gates remain separate and mandatory.
const mi = [
  ["MV3",manifest.manifest_version,3],["semantic version",/^\d+\.\d+\.\d+$/.test(manifest.version),true],
  ["minimum Chrome numeric",/^\d+$/.test(manifest.minimum_chrome_version),true],["minimum Chrome floor",Number(manifest.minimum_chrome_version)>=120,true],
  ["module background",manifest.background?.type,"module"],["bootstrap background",manifest.background?.service_worker,"service-worker-bootstrap.js"],
  ["action title",Boolean(manifest.action?.default_title),true],["side panel",manifest.side_panel?.default_path,"sidepanel.html"],
  ["all urls host",manifest.host_permissions?.includes("<all_urls>"),true],["all urls content",manifest.content_scripts?.[0]?.matches?.includes("<all_urls>"),true],
  ["document start",manifest.content_scripts?.[0]?.run_at,"document_start"],["all frames",manifest.content_scripts?.[0]?.all_frames,true],
  ["about blank",manifest.content_scripts?.[0]?.match_about_blank,true],["isolated world",manifest.content_scripts?.[0]?.world,"ISOLATED"],
  ["reconnect first",manifest.content_scripts?.[0]?.js?.[0],"lib/reconnect-backoff.js"],["dirty notifier second",manifest.content_scripts?.[0]?.js?.[1],"lib/runtime-dirty-notifier.js"],
  ["page runtime last",manifest.content_scripts?.[0]?.js?.at(-1),"page-runtime.js"],["no legacy scripts",Array.isArray(manifest.background?.scripts),false],
  ["no background page",Boolean(manifest.background?.page),false],["description",Boolean(String(manifest.description||"").trim()),true]
];
for (const [n,a,e] of mi) test(`manifest invariant: ${n}`,()=>assert.deepEqual(a,e));
for (const p of ["storage","alarms","tabs","scripting","debugger","activeTab","downloads","webNavigation","sidePanel","tabGroups","system.display","notifications"]) test(`manifest permission: ${p}`,()=>assert.equal(manifest.permissions.includes(p),true));
for (const r of [manifest.background.service_worker,manifest.side_panel.default_path,...manifest.content_scripts[0].js]) test(`manifest resource exists: ${r}`,()=>assert.equal(fs.existsSync(path.join(root,r)),true));

const hosts=["example.com","www.example.com","api.example.com","sub.domain.example","localhost","127.0.0.1","search.google.com","trends.google.com","idealmarket1987.com","xn--bcher-kva.example"];
const suffixes=["","/","/alpha","/a/b","/?q=one","/path?x=1&y=2#frag"];
for(const h of hosts)for(const s of suffixes)for(const explicit of [false,true]){
  const raw=`${explicit?"https://":""}${h}${s}`;
  test(`URL input ${explicit?"explicit":"bare"}: ${h}${s||"<root>"}`,()=>{const p=parseUserUrl(raw);assert.ok(p?.url instanceof URL);assert.equal(p.url.protocol,"https:");assert.equal(p.url.hostname,new URL(`https://${h}`).hostname);assert.equal(p.coerced,!explicit);});
}
for(const port of [80,81,443,444,3000,38889,8080,8082,9000,65535]) test(`URL input localhost port ${port}`,()=>{const p=parseUserUrl(`localhost:${port}/health`);assert.ok(p?.url instanceof URL);assert.equal(p.url.protocol,"https:");assert.equal(p.url.hostname,"localhost");assert.equal(p.coerced,true);});
const bad=["","   ","not a host","two words.example",".","..","...","http://","https://","://bad","exa mple.com","example .com","[","]","{}","?query-only","#fragment-only","/relative-only","\\windows\\path","http://[::1","https://?x","https://#x","localhost port","\u0000","\n","\t"];
for(const [i,raw] of bad.entries())test(`URL input rejects malformed variant ${i+1}`,()=>assert.equal(parseUserUrl(raw),null));
for(const raw of ["javascript:alert(1)","data:text/plain,hello","about:blank","file:///tmp/a","chrome://settings/"])test(`URL parser preserves explicit scheme: ${raw.split(":")[0]}`,()=>{const p=parseUserUrl(raw);assert.ok(p?.url instanceof URL);assert.equal(p.coerced,false);assert.equal(p.url.protocol,`${raw.split(":")[0]}:`);});

for(const ol of ["","lane-a","lane-b"])for(const rl of ["","lane-a","lane-b","lane-c"])for(const ot of ["","task-a","task-b"])for(const rt of ["","task-a","task-c"]){
  test(`ownership ownerLane=${ol||"none"} requestedLane=${rl||"none"} ownerTask=${ot||"none"} requestedTask=${rt||"none"}`,()=>{const r=tabBindingCompatibility({laneId:ol,taskId:ot},{laneId:rl,taskId:rt});const conflict=Boolean(ol&&rl&&ol!==rl);assert.equal(r.ok,!conflict);if(conflict)assert.equal(r.code,"tab_lane_conflict");else{const changed=Boolean(ot&&rt&&ot!==rt),lane=ol||rl;assert.equal(r.mode,changed?(lane?"lane_task_rebind":"session_task_rebind"):(lane?"lane_owned":"session_owned"));}});
}
for(const [i,[record,ctx,ok,value]] of [[{laneId:"lane-a",taskId:"old"},{_prstudio_lane_id:"lane-a",taskId:"new"},true,"lane_task_rebind"],[{laneId:"lane-a",taskId:"old"},{_prstudio_lane_id:"lane-b",taskId:"new"},false,"tab_lane_conflict"],[{laneId:"",taskId:"old"},{_prstudio_lane_id:"lane-a",taskId:"new"},true,"lane_task_rebind"],[{laneId:"lane-a",taskId:""},{_prstudio_lane_id:"lane-a",taskId:"new"},true,"lane_owned"]].entries())test(`ownership alternate lane context ${i+1}`,()=>{const r=tabBindingCompatibility(record,ctx);assert.equal(r.ok,ok);assert.equal(ok?r.mode:r.code,value);});

const committed=["about:blank","chrome://newtab/","","https://example.com/a","http://localhost:8080/a"],pending=["","https://pending.example/a","http://127.0.0.1:8080/p","chrome://settings/","data:text/plain,x"];
for(const c of committed)for(const p of pending)test(`provisional ownership committed=${c||"empty"} pending=${p||"empty"}`,()=>{const fallback="https://fallback.example/path",expected=/^https?:/i.test(c)?c:(/^https?:/i.test(p)?p:fallback);assert.equal(candidateTabUrl({url:c,pendingUrl:p},fallback),expected);const s=provisionalOwnershipState({url:c,pendingUrl:p},{url:fallback,provisional:true});assert.equal(s.candidateUrl,expected);assert.equal(s.committedHttp,/^https?:/i.test(c));assert.equal(s.candidateHttp,/^https?:/i.test(expected));assert.equal(s.provisional,!/^https?:/i.test(c)&&/^https?:/i.test(expected));});

const repl=["https://example.com/one","https://search.google.com/two","http://localhost:8080/three","about:blank"];
for(const oldId of [7,11,23,41])for(const newId of [101,202,303])for(const [v,url] of repl.entries())test(`tab replacement ${oldId}->${newId} urlVariant=${v}`,()=>{const now=1700000000000+oldId*100+newId+v;const record={tabId:oldId,windowId:2,url:`https://fallback.example/${oldId}/${newId}`,title:"before",laneId:"lane-a",taskId:"task-old",provisional:false};const tab={id:newId,windowId:9,url,pendingUrl:url==="about:blank"?`https://pending.example/${newId}`:"",title:`after-${v}`};const m=migrateTabReplacementRecord(record,tab,oldId,now);assert.equal(m.tabId,newId);assert.equal(m.replacedFromTabId,oldId);assert.equal(m.laneId,"lane-a");assert.equal(m.taskId,"task-old");assert.equal(m.updatedAt,now);const s=migrateTabReplacementState({registry:{[oldId]:record,999:{tabId:999,laneId:"lane-z"}},affinityTasks:{a:{tabId:oldId},b:{tabId:999}},lastTabId:oldId,activeTask:{taskId:"task-old",tabId:oldId},runtimeSessions:{s1:{tabId:oldId},s2:{tabId:999}}},tab,oldId,now);assert.equal(s.registry[String(oldId)],undefined);assert.equal(s.registry[String(newId)].tabId,newId);assert.equal(s.affinityTasks.a.tabId,newId);assert.equal(s.affinityTasks.a.reason,"tab_replaced");assert.equal(s.affinityTasks.b.tabId,999);assert.equal(s.lastTabId,newId);assert.equal(s.activeTask.tabId,newId);assert.equal(s.runtimeSessions.s1.interruptedByTabReplacement,true);assert.equal(s.runtimeSessions.s2.tabId,999);});

for(const h of ["example.com","search.google.com","trends.google.com","idealmarket1987.com","localhost:8080"])for(const p of ["/","/alpha","/a/b","/wp-admin/","/search?q=term"]){const actual=`https://${h}${p}`;test(`URL evidence exact ${h}${p}`,()=>{const r=compareUrlEvidence(actual,actual);assert.equal(r.matched,true);assert.equal(r.matchStrategy,"normalized_exact");});test(`URL evidence wildcard ${h}${p}`,()=>{const r=compareUrlEvidence(actual,`https://${h}*`);assert.equal(r.matched,true);assert.equal(r.matchStrategy,"anchored_wildcard");});test(`URL evidence literal ${h}${p}`,()=>{const token=p==="/"?h:(p==="/wp-admin/"?"wp-admin":p.split("?")[0]);const r=compareUrlEvidence(actual,token);assert.equal(r.matched,true);assert.equal(r.matchStrategy,"literal_substring");});}
for(const [raw,expected] of [["HTTPS://EXAMPLE.COM:443/a","https://example.com/a"],["http://EXAMPLE.COM:80/a","http://example.com/a"],["https://user:pass@example.com/a","https://example.com/a"],["https://Example.Com/a?x=1#z","https://example.com/a?x=1#z"],["not-a-url","not-a-url"]])test(`URL evidence normalization: ${raw}`,()=>assert.equal(normalizeUrlForEvidence(raw),expected));
for(const p of ["example","^https://example\\.com","path[0-9]","a?b","a{1}","a{1,3}","^[a-z]{1,8}$","https://[^/]+/a"])test(`safe URL regex: ${p}`,()=>assert.equal(assessUserUrlRegex(p).safe,true));
for(const p of ["","(a)","a|b","(a|b)","a+b+","a*b*","(.*)","a{1,999}","a{3,1}","a{1,}","a\\1","[abc","abc\\","a{","a{foo}"])test(`unsafe URL regex: ${JSON.stringify(p)}`,()=>assert.equal(assessUserUrlRegex(p).safe,false));
for(const [a,p,e] of [["https://example.com/a","https://*.com/*",true],["https://example.com/a","http://*.com/*",false],["abcXYZdef","abc*def",true],["abcXYZ","abc*def",false],["prefix-middle-suffix","prefix*middle*suffix",true],["prefix-X-suffix","prefix*middle*suffix",false],["same","same",true],["same","different",false]])test(`literal wildcard ${a} vs ${p}`,()=>assert.equal(matchLiteralWildcard(a,p),e));
for(const [a,p,e] of [["https://example.com/a","^https://example\\.com",true],["https://example.com/a","^http://example\\.com",false],["abc123","^[a-z]{1,8}[0-9]{1,3}$",true],["123abc","^[a-z]{1,8}[0-9]{1,3}$",false]])test(`URL regex execution ${a} vs ${p}`,()=>assert.equal(testUserUrlRegex(a,p).matched,e));

test("CDP candidate list is production protocol 1.3 only",()=>assert.deepEqual([...DEBUGGER_PROTOCOL_CANDIDATES],["1.3"]));
for(let i=1;i<=20;i++)test(`CDP incompatible attach error variant ${i}`,async()=>{const msg=`Protocol error variant ${i}`;await assert.rejects(()=>attachWithProtocolFallback({attach:async()=>{throw new Error(msg);}},{tabId:1000+i},async()=>false),e=>e?.code==="cdp_protocol_incompatible"&&e?.details?.errors?.[0]?.message===msg);});
for(const [i,msg] of ["Already attached","already attached to this target","Another debugger is already attached","ANOTHER DEBUGGER IS ALREADY ATTACHED","already ATTACHED","Another debugger is already attached to the tab","already attached: target","Another debugger is already attached."].entries())test(`CDP existing attachment recovery ${i+1}`,async()=>{const r=await attachWithProtocolFallback({attach:async()=>{throw new Error(msg);}},{tabId:2000+i},async()=>true);assert.equal(r.ok,true);assert.equal(r.alreadyAttached,true);assert.equal(r.protocolVersion,"1.3");});
for(let i=1;i<=8;i++)test(`CDP ambiguous state variant ${i}`,async()=>assert.rejects(()=>attachWithProtocolFallback({attach:async()=>{throw new Error(`permission-like-${i}`);}},{tabId:3000+i},async()=>true),e=>e?.code==="cdp_attach_state_ambiguous"));
for(let i=1;i<=8;i++)test(`CDP unverifiable state variant ${i}`,async()=>assert.rejects(()=>attachWithProtocolFallback({attach:async()=>{throw new Error(`attach-${i}`);}},{tabId:4000+i},async()=>{throw new Error(`state-${i}`);}),e=>e?.code==="cdp_attach_state_unverified"&&e?.details?.stateError===`state-${i}`));
