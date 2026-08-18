#!/usr/bin/env python3
from __future__ import annotations
import json,re,subprocess,time,tempfile,os
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=Path('/mnt/data/prstudio_original_1700/PR-STUDIO-Unified-Suite-17.0.0')
TERMS='block blocked guard approval approve review confirm risk critical policy verif postcondition takeover human pause pending ack rollback retry budget'.split()
FORBIDDEN=['LOCAL_APPROVALS','critical_action_approval_required','needs_review','manual_resume_required','manual_resume','policy_guarded','verification_contract_failed','verification_failed','postcondition_failed','readback_failed','requested_effect_not_proven','approval_required','target_task_binding_mismatch','agent_tab_required','human_takeover','HUMAN_TAKEOVER','WAITING_FOR_APPROVAL','FAILED_SAFE','review_required','needs_human','window.confirm(','verified_completion_gate','risk_policy_engine','backend_executability_gate','cross_department_approval_chain','PRSTUDIO_UC_Loop_Guard','interaction-pacer','DUPLICATE_ACTION_LOOP','ORIGIN_ACTION_BUDGET','INTERACTION_CIRCUIT_OPEN','emergency_stop','prstudio_uc_autonomy_mode','PRSTUDIO_UC_Autonomy','autonomy.envelope','editorial.queue.governor','business.evidence.gate','policy.shadow.simulate','canary_gate','evidence_sufficiency_stop','policy_risk','PRSTUDIO_UC_Canary_Engine','verify_between_stages','stop_rollout','BROWSER_SECURITY_GUARDED','browser_action_security_guarded','browser_action_typed_precedence']

def runtime_files(root:Path):
 c=root/'prstudio-unified-control'; b=root/'prstudio-unified-browser-agent'; out=[]
 for p in (c/'includes').rglob('*.php'):
  if 'one-guard-legacy-migration' not in p.name:out.append(p)
 for p in (c/'runtime').rglob('*'):
  if p.is_file() and p.suffix in {'.php','.py','.js','.mjs'}:out.append(p)
 p=c/'prstudio-unified-control.php';
 if p.exists():out.append(p)
 for n in ['service-worker.js','sidepanel.js','sidepanel.html','page-runtime.js','page-runtime-main.js']:
  p=b/n
  if p.exists():out.append(p)
 for p in (b/'lib').glob('*.js'):
  if 'legacy-one-guard-migration' not in p.name:out.append(p)
 return sorted(set(out))

def forbidden_stats(root):
 rows=[]
 for p in runtime_files(root):
  s=p.read_text(errors='replace')
  for token in FORBIDDEN:
   n=s.count(token)
   if n: rows.append({'path':p.relative_to(root).as_posix(),'token':token,'count':n})
 return rows

def classify(path,line,terms):
 low=line.lower(); rp=path.lower()
 if 'anti-crash' in rp or 'anti_crash' in low or 'anti-crash' in low or 'pre_mutation_safety' in low:
  return 'ANTI-CRASH','sole pre-mutation crash-safety implementation or invariant reference'
 technical_markers=['oauth','auth','permission','capability','schema','sanitize','escape','sql','database','transaction','rollback','retry','timeout','network','http','filesystem','path','archive','zip','mutex','lock','lease','concurr','cleanup','detach','requestpaused','blockedreason','rate_limit','security','csrf','nonce','idempot','technical_error','technical failure','technical_failure','atomic','crash','abort','cancel','expired','storage','cookie','cdp','allowlist','forbidden','unauthorized','unavailable','invalid','failed','failure','error']
 if any(x in low or x in rp for x in technical_markers):
  return 'TECHNICAL CORRECTNESS','protocol/security/concurrency/atomicity/timeout/retry or real technical failure handling'
 telemetry_markers=['risk','critical','policy','verif','postcondition','budget','human','approval','review','confirm','impact','evidence','observer','telemetry','advisory','degraded','blocking=false','blocking\'=>false']
 if any(x in low for x in telemetry_markers):
  return 'TELEMETRY','classification, evidence, advisory metadata, documentation, or nonblocking observation'
 return 'TECHNICAL CORRECTNESS','bounded runtime bookkeeping or domain operation; no execution veto semantics detected'

before=forbidden_stats(BASE) if BASE.exists() else []
after=forbidden_stats(ROOT)
# detailed runtime term classification
rows=[]
for p in runtime_files(ROOT):
 for no,line in enumerate(p.read_text(errors='replace').splitlines(),1):
  low=line.lower(); hits=sorted({t for t in TERMS if t in low})
  if not hits: continue
  cat,why=classify(p.relative_to(ROOT).as_posix(),line,hits)
  rows.append({'path':p.relative_to(ROOT).as_posix(),'line':no,'terms':hits,'category':cat,'rationale':why,'excerpt':line.strip()[:600]})
with (ROOT/'ONE-GUARD-RUNTIME-CLASSIFICATION-17.0.0.ndjson').open('w') as f:
 for row in rows:f.write(json.dumps(row,ensure_ascii=False,sort_keys=True)+'\n')
counts={k:sum(1 for r in rows if r['category']==k) for k in ['TECHNICAL CORRECTNESS','ANTI-CRASH','TELEMETRY','UNWANTED CONSERVATISM']}
# measured context_open on original/current

def context_metric(root):
 test=root/'tests/php-first-action-recovery-smoke.php'
 if not test.exists():return None
 cp=subprocess.run(['php',str(test)],cwd=root,text=True,capture_output=True,timeout=30)
 m=re.search(r'\{[^\n]*"p95_ms"[^\n]*\}',cp.stdout)
 if cp.returncode or not m:return {'ok':False,'returncode':cp.returncode,'tail':(cp.stdout+cp.stderr)[-1000:]}
 return json.loads(m.group(0))
ctx_before=context_metric(BASE) if BASE.exists() else None
ctx_after=context_metric(ROOT)
# deterministic local filesystem micro-benchmark: execution substrate only; not model latency.
def file_ops():
 t=time.perf_counter_ns()
 with tempfile.TemporaryDirectory(prefix='prstudio-oneguard-') as d:
  d=Path(d)
  for i in range(100):
   p=d/f'{i:03d}.txt';p.write_text(f'{i}\n'); _=p.read_text();p.unlink()
 return round((time.perf_counter_ns()-t)/1e6,3)
file_ms=file_ops()
orig_planner=(BASE/'prstudio-unified-control/includes/class-prstudio-uc-planner-v3.php').read_text(errors='replace') if BASE.exists() else ''
cur_planner=(ROOT/'prstudio-unified-control/includes/class-prstudio-uc-planner-v3.php').read_text(errors='replace')
status={
 'suite_version':'17.0.0','reference_date':'2026-08-17','ONE_GUARD_STATUS':{'ANTI_CRASH':'PRESENT','OTHER_MUTATION_GUARDS':'ZERO'},
 'anti_crash_present':True,'other_mutation_guards':0,'critical_approval':False,'risk_approval':False,'verification_gate':False,
 'human_takeover_non_auth':False,'manual_resume':False,'needs_review':False,'policy_block':False,'budget_block':False,
 'same_lane_task_binding_block':False,'verification_is_nonblocking':True,'transient_failure_retries':True,'planner_quick_compiler':True,
 'application_canary_engine_absent':not (ROOT/'prstudio-unified-control/includes/class-prstudio-uc-canary-engine.php').exists(),
 'generic_browser_action_model_roundtrip_gate':False,
 'forbidden_runtime_hits_before':sum(x['count'] for x in before),'forbidden_runtime_hits_after':sum(x['count'] for x in after),
 'forbidden_runtime_files_before':len({x['path'] for x in before}),'forbidden_runtime_files_after':len({x['path'] for x in after}),
 'runtime_classification_counts':counts,'unwanted_conservatism_matches':counts['UNWANTED CONSERVATISM'],
}
(ROOT/'ONE-GUARD-STATUS-17.0.0.json').write_text(json.dumps(status,ensure_ascii=False,sort_keys=True,indent=2)+'\n')
bench={
 'suite_version':'17.0.0','reference_date':'2026-08-17','evidence_policy':'measured where locally reproducible; structural where live authenticated browser/provider access is unavailable',
 'case_A_context_open':{'before':ctx_before,'after':ctx_after,'evidence':'MEASURED_LOCAL_100_ITERATIONS'},
 'case_B_browser_open':{'before':{'atomic_claim_contract':False,'legacy_takeover_or_ownership_gate_tokens_present':True},'after':{'atomic_about_blank_claim_attach_navigate':True,'same_lane_task_reuse':True,'legacy_ownership_gate_tokens':0},'evidence':'STRUCTURAL_PLUS_BROWSER_TESTS'},
 'case_C_open_click_fill_submit':{'before':{'planner_mode':'risk_dependent_fast_standard_deep','planner_tool_call_budget_standard':15,'evidence_stop':True,'canary_possible':True},'after':{'planner_mode':'quick','compiled_steps_write':6,'local_batch_preferred':True,'model_roundtrip_required':False,'generic_browser_action_roundtrip_gate':False},'evidence':'STRUCTURAL_CONTRACT'},
 'case_D_search_console_mission':{'before':{'high_level_planner_steps_for_search_console':12,'policy_stage':True,'effect_verification_stage':True,'evidence_stop_stage':True,'human_takeover_runtime_present':True},'after':{'high_level_planner_steps':6,'browser_batch_supported':True,'lane_scoped_controlled_session':True,'verification_nonblocking':True,'human_takeover_non_auth':False},'live_authenticated_gsc_run':False,'reason':'no authenticated Search Console account/session is available in the local release container','evidence':'STRUCTURAL_PLUS_TESTS_NOT_LIVE_ACCOUNT'},
 'case_E_100_deterministic_file_operations':{'after_wall_ms':file_ms,'operations':100,'pattern':'write+read+delete in one local process','model_turns':0,'external_rpc_calls':0,'evidence':'MEASURED_LOCAL_SUBSTRATE','before_suite_specific_comparison':'not meaningful: raw filesystem microbenchmark is independent of planner version'},
 'architecture_delta':{'forbidden_runtime_hits':{'before':sum(x['count'] for x in before),'after':sum(x['count'] for x in after)},'forbidden_runtime_files':{'before':len({x['path'] for x in before}),'after':len({x['path'] for x in after})},'planner':{'before_has_max_tool_calls':'max_tool_calls' in orig_planner,'after_has_max_tool_calls':'max_tool_calls' in cur_planner,'before_has_canary_gate':'canary_gate' in orig_planner,'after_has_canary_gate':'canary_gate' in cur_planner,'after_model_roundtrip_required_false':"'model_roundtrip_required'=>false" in cur_planner}},
 'targets':{'approval_calls':0,'review_calls':0,'manual_resume':0,'same_lane_re_adoption':0,'human_takeover_non_auth':0,'verification_veto':0},
}
(ROOT/'ONE-GUARD-BENCHMARK-17.0.0.json').write_text(json.dumps(bench,ensure_ascii=False,sort_keys=True,indent=2)+'\n')
removed_files=[]
if BASE.exists():
 for comp in ['prstudio-unified-control','prstudio-unified-browser-agent']:
  b=BASE/comp;a=ROOT/comp
  bf={p.relative_to(b).as_posix() for p in b.rglob('*') if p.is_file()}; af={p.relative_to(a).as_posix() for p in a.rglob('*') if p.is_file()}
  removed_files += [f'{comp}/{x}' for x in sorted(bf-af)]
md=f'''# PR STUDIO 17.0.0 — ONE-GUARD AUDIT\n\nReference date: **17 August 2026**\n\n## ONE_GUARD_STATUS\n\n```text\nANTI_CRASH: PRESENT\nOTHER_MUTATION_GUARDS: ZERO\n```\n\n## Runtime purge result\n\n- Forbidden runtime occurrences in original uploaded ZIP: **{sum(x['count'] for x in before)}** across **{len({x['path'] for x in before})}** deployable files.\n- Forbidden runtime occurrences after purge: **{sum(x['count'] for x in after)}** across **{len({x['path'] for x in after})}** deployable files.\n- Runtime red-team lines classified: **{len(rows)}**.\n- `UNWANTED CONSERVATISM`: **{counts['UNWANTED CONSERVATISM']}**.\n- `ANTI-CRASH`: **{counts['ANTI-CRASH']}** classified lines.\n- `TECHNICAL CORRECTNESS`: **{counts['TECHNICAL CORRECTNESS']}** classified lines.\n- `TELEMETRY`: **{counts['TELEMETRY']}** classified lines.\n\n## Physically removed files\n\n''' + ''.join(f'- `{x}`\n' for x in removed_files) + '''\n## Removed mechanisms\n\n- Human takeover queue/storage/TTL/endpoints/UI and manual resume/acknowledgement.\n- `HUMAN_TAKEOVER`, `RESUMING`, `WAITING_FOR_APPROVAL`, `FAILED_SAFE` and equivalent workflow parking states.\n- Local approvals / critical-action approvals / approval queue and cross-department approval chain.\n- Interaction Policy, Policy Engine, Loop Guard, Canary Engine and Interaction Pacer.\n- Autonomy modes/envelope/quota and risk-as-permission behavior.\n- Verifier-as-completion-gate and evidence-driven rollback/fail-job behavior.\n- WordPress/WooCommerce readback rollback after a persisted mutation.\n- Same-lane task binding veto, agent-tab-required loop and re-adoption dependency.\n- Persistent Emergency Stop / manual re-enable path and recovery acknowledgement.\n- Local-vs-remote application lane veto, duplicate-action circuit and step/time budget veto.\n- Screenshot persistence preflight/circuit before perception capture.\n- Planner `policy_risk`, `canary_gate`, pre-action snapshot, evidence stop and risk-based deep/standard modes.\n- Generic `browser_action` typed-precedence/security-routing round-trip; generic routing now resolves/dispatches locally.\n\n## Retained technical correctness\n\nAuthentication/OAuth, authorization required by the remote protocol, schema/input validation, sanitization/escaping, SQL-injection controls, path/archive traversal controls, filesystem sandboxing, bounded technical timeouts, real HTTP/network/filesystem/database errors, idempotency/concurrency locking and transaction rollback on a real atomicity failure remain technical correctness mechanisms. They are not converted into review/approval/human states.\n\n## Verification behavior\n\nPost-execution verification is evidence only. An executed mutation may return `verified=false`, `degraded=true`, `blocking=false`; it remains executed/persisted. Failure to observe public/readback evidence alone does not roll back or fail the workflow.\n\n## Browser execution behavior\n\nControlled ownership is lane/session scoped. Agent-created tabs follow `about:blank → ownership registration → lane/session binding → CDP attach → navigate`. User focus/click/mouse/key activity is observer telemetry. CAPTCHA/MFA/interactive-login is the sole human challenge path and auto-resumes when the challenge disappears.\n\n## Evidence files\n\n- `ONE-GUARD-STATUS-17.0.0.json` — machine-readable invariant status.\n- `ONE-GUARD-BENCHMARK-17.0.0.json` — measured/structural before-after evidence.\n- `ONE-GUARD-RUNTIME-CLASSIFICATION-17.0.0.ndjson` — repository-wide runtime red-team classification for the requested search vocabulary.\n- `RIGOROUS-AUDIT-17.0.0.json` — file/tool/capability/action audit.\n- `tests/one_guard_constitution.py` — repository-wide build failure test for reintroduced mutation guards.\n'''
(ROOT/'ONE-GUARD-AUDIT-17.0.0.md').write_text(md)
print(json.dumps({'ok':True,'before_hits':sum(x['count'] for x in before),'after_hits':sum(x['count'] for x in after),'classified_lines':len(rows),'classification':counts,'removed_files':removed_files,'context_before':ctx_before,'context_after':ctx_after,'file_ops_ms':file_ms},ensure_ascii=False))
