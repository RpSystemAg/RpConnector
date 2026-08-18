import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const files=['service-worker.js','sidepanel.js','sidepanel.html',...fs.readdirSync(path.join(root,'lib')).filter(x=>x.endsWith('.js')&&x!=='legacy-one-guard-migration.js').map(x=>'lib/'+x)];
const forbidden=['LOCAL_APPROVALS','critical_action_approval_required','needs_review','manual_resume_required','policy_guarded','verification_contract_failed','window.confirm(','HUMAN_TAKEOVER','RESUMING','target_task_binding_mismatch','agent_tab_required','WAITING_FOR_APPROVAL','FAILED_SAFE','review_required','manual_resume','needs_human','approval_required'];
test('ONE_GUARD_CONSTITUTION browser deployable runtime',()=>{
  for(const rel of files){const src=fs.readFileSync(path.join(root,rel),'utf8');for(const token of forbidden)assert.equal(src.includes(token),false,`${rel} contains ${token}`);}
  const sw=fs.readFileSync(path.join(root,'service-worker.js'),'utf8');
  assert.match(sw,/about:blank/); assert.match(sw,/attachDebugger/); assert.match(sw,/waitForExternalAuthChallenge/);
});
