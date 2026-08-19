#!/usr/bin/env python3
"""Repository-wide execution constitution release test.

Scans deployable runtime and generated execution contracts. Legacy vocabulary is
allowed only inside the two one-way migration files that delete/migrate old state.
Also proves that the mandatory no-exclusion/no-deferral law is present in every
agent and Claude entry point across the suite.
"""
from __future__ import annotations
import json,re,sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CONTROL=ROOT/'prstudio-unified-control'
BROWSER=ROOT/'prstudio-unified-browser-agent'

FORBIDDEN={
 'LOCAL_APPROVALS','critical_action_approval_required','needs_review','manual_resume_required',
 'manual_resume','policy_guarded','verification_contract_failed','verification_failed',
 'postcondition_failed','readback_failed','requested_effect_not_proven','approval_required',
 'target_task_binding_mismatch','agent_tab_required','human_takeover','HUMAN_TAKEOVER',
 'WAITING_FOR_APPROVAL','FAILED_SAFE','review_required','needs_human','window.confirm(',
 'verified_completion_gate','risk_policy_engine','backend_executability_gate',
 'cross_department_approval_chain','PRSTUDIO_UC_Loop_Guard','interaction-pacer',
 'DUPLICATE_ACTION_LOOP','ORIGIN_ACTION_BUDGET','INTERACTION_CIRCUIT_OPEN',
 'emergency_stop','emergencyStopButton','localRecoveryAckButton','prstudioPendingTakeovers',
 'screenshotCapturePreflight','withRuntimeLaneMutex','prstudio_uc_autonomy_mode',
 'PRSTUDIO_UC_Autonomy','autonomy_mode','allow_enterprise_execution','allow_content_write',
 'allow_file_write','allow_plugin_actions','allow_theme_switch','allow_core_write',
 'autonomy.envelope','editorial.queue.governor','business.evidence.gate','policy.shadow.simulate',
 'evidence_sufficiency_gate','policy_shadow_simulator','canary_gate','evidence_sufficiency_stop','policy_risk',
 'verification_still_required','stop_on_sufficient_evidence','PRSTUDIO_UC_Canary_Engine',
 'class-prstudio-uc-canary-engine.php','verify_between_stages','stop_rollout',
 'BROWSER_SECURITY_GUARDED','browser_action_security_guarded','browser_action_typed_precedence',
}
LEGACY_CONTROL=CONTROL/'includes/class-prstudio-uc-one-guard-legacy-migration.php'
LEGACY_BROWSER=BROWSER/'lib/legacy-one-guard-migration.js'


def runtime_files():
    for p in (CONTROL/'includes').rglob('*.php'):
        if p != LEGACY_CONTROL: yield p
    for p in (CONTROL/'runtime').rglob('*'):
        if p.is_file() and p.suffix in {'.php','.py','.js','.mjs'}: yield p
    yield CONTROL/'prstudio-unified-control.php'
    for p in [BROWSER/'service-worker.js',BROWSER/'sidepanel.js',BROWSER/'sidepanel.html',BROWSER/'page-runtime.js',BROWSER/'page-runtime-main.js']:
        if p.exists(): yield p
    for p in (BROWSER/'lib').glob('*.js'):
        if p != LEGACY_BROWSER: yield p

fail=[]
for p in runtime_files():
    text=p.read_text(errors='replace')
    for token in FORBIDDEN:
        if token in text: fail.append(f'{p.relative_to(ROOT)}: forbidden runtime token {token}')

# Generated contracts/audits are release behavior declarations, not historical docs.
for rel in [
    'prstudio-unified-control/contract/capability-contract.json',
    'prstudio-unified-control/capabilities/capability-registry.json',
    'prstudio-unified-control/capabilities/capability-search-index.json',
    'prstudio-unified-control/contract/action-hot-index.json',
    'prstudio-unified-control/capabilities/agency-capabilities.json',
    'prstudio-unified-browser-agent/BUILD-INFO.json',
    'prstudio-unified-browser-agent/COMPONENT-MANIFEST.json',
    'prstudio-unified-browser-agent/FILE-INTEGRITY.json',
]:
    p=ROOT/rel
    if not p.exists(): continue
    text=p.read_text(errors='replace')
    for token in FORBIDDEN:
        if token in text: fail.append(f'{rel}: forbidden release-contract token {token}')

# Legacy gate implementations must be physically gone, not no-ops.
for rel in [
 'includes/class-prstudio-uc-interaction-policy.php',
 'includes/class-prstudio-uc-policy-engine.php',
 'includes/class-prstudio-uc-loop-guard.php',
 'includes/class-prstudio-uc-autonomy.php',
 'includes/class-prstudio-uc-canary-engine.php',
]:
    if (CONTROL/rel).exists(): fail.append(f'{rel}: legacy gate file still exists')
if (BROWSER/'lib/interaction-pacer.js').exists(): fail.append('Browser interaction pacer file still exists')

# Canonical Anti-Crash must be present and be the sole pre-mutation safety implementation.
anti=CONTROL/'includes/class-prstudio-uc-anti-crash.php'
if not anti.exists(): fail.append('Anti-Crash implementation missing')
else:
    anti_text=anti.read_text(errors='replace')
    if 'PRSTUDIO_UC_Anti_Crash' not in anti_text or 'PRSTUDIO_UC_Pre_Mutation_Safety' not in anti_text:
        fail.append('Anti-Crash/pre-mutation adapter incomplete')
for rel in ['includes/class-prstudio-uc-execution-gateway.php','includes/class-prstudio-uc-publish-transaction.php','includes/class-prstudio-uc-content-transaction.php']:
    text=(CONTROL/rel).read_text(errors='replace')
    if 'Pre_Mutation_Safety' not in text and 'Anti_Crash' not in text: fail.append(f'{rel}: Anti-Crash invocation missing')

# Risk/verification/budget can observe or degrade only.
risk=(CONTROL/'includes/class-prstudio-uc-risk-engine-v3.php').read_text(errors='replace')
for token in ['allowed','requires_confirmation','confirmation_present','approval']:
    if re.search(rf"['\"]{re.escape(token)}['\"]\s*=>",risk): fail.append(f'Risk Engine exposes permission field {token}')
if 'advisory_only' not in risk: fail.append('Risk Engine is not telemetry-only')
ver=(CONTROL/'includes/class-prstudio-uc-verifier.php').read_text(errors='replace')
if "'blocking' => false" not in ver and "'blocking'=>false" not in ver: fail.append('Verifier lacks blocking=false')
if re.search(r'verification[^\n]{0,120}(?:rollback|fail_job|fail_workflow)',ver,re.I): fail.append('Verifier contains verification->rollback/fail behavior')
budget=(CONTROL/'includes/class-prstudio-uc-performance-budget.php').read_text(errors='replace')
if "'blocking'=>false" not in budget and "'blocking' => false" not in budget: fail.append('Performance budget is not explicitly nonblocking')

# Browser ownership/session and auth challenge invariants.
own=(BROWSER/'lib/tab-ownership.js').read_text(errors='replace')
if 'lane_task_rebind' not in own and 'session_task_rebind' not in own: fail.append('same-lane task rebind path missing')
sw=(BROWSER/'service-worker.js').read_text(errors='replace')
for token in ['about:blank','registerOwnedTab','attachDebugger']:
    if token not in sw: fail.append(f'atomic open/own/navigate path missing {token}')
if 'waitForExternalAuthChallenge' not in sw: fail.append('auth challenge auto-resume path missing')
if 'observerOnly: true' not in sw: fail.append('human input is not observer-only telemetry')
if re.search(r'phase\s*=\s*["\']pending["\']',sw): fail.append('runtime uses pending as an execution phase')

# No manual Resume surface.
ui='\n'.join(p.read_text(errors='replace') for p in [BROWSER/'sidepanel.html',BROWSER/'sidepanel.js'])
if re.search(r'\bresume\b|riprendi|takeover|acknowledge',ui,re.I): fail.append('manual human control surface remains')


def constitution_text(path):
    """Read a constitution with whitespace collapsed to single spaces.

    The clauses below are prose sentences, and prose in Markdown wraps. Matching
    against the raw bytes meant a clause that was fully present but broken over
    two lines read as missing: on 19 Aug 2026 this reported twelve missing Law 10
    clauses across all six constitution files while every one of them was
    actually there, wrapped.

    That is worse than a false negative. A control that fails while the thing it
    guards is correct is one people learn to ignore, and it was already the only
    red in an otherwise passing local run.

    Collapsing whitespace does not weaken the requirement: the clause must still
    appear verbatim, word for word, in order. It only stops a line break from
    defeating the check.
    """
    if not path.exists():
        return ''
    return ' '.join(path.read_text(errors='replace').split())


def clause_present(text, clause):
    return ' '.join(clause.split()) in text


# Constitution files and exact laws.
required_agent_laws=[
 'ANTI-CRASH IS THE ONLY MUTATION GUARD',
 'VERIFICATION IS EVIDENCE, NEVER AUTHORIZATION',
 'EXECUTABLE ACTIONS EXECUTE',
 'HUMAN INTERVENTION IS AUTH-CHALLENGE ONLY',
 'TRANSIENT FAILURE RETRIES; IT DOES NOT PARK THE MISSION',
 'OWNERSHIP IS SESSION/LANE SCOPED',
 'NO TRIAL INPUT',
 'NO MODEL ROUND-TRIP WITHOUT NEW JUDGMENT',
 'NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE',
]
agent_constitutions=[ROOT/'AGENTS.md',CONTROL/'AGENTS.md',BROWSER/'AGENTS.md']
for p in agent_constitutions:
    text=constitution_text(p)
    for law in required_agent_laws:
        if not clause_present(text,law): fail.append(f'{p.relative_to(ROOT)}: missing law {law}')

claude_constitutions=[ROOT/'CLAUDE.md',CONTROL/'CLAUDE.md',BROWSER/'CLAUDE.md']
for p in claude_constitutions:
    text=constitution_text(p)
    if not clause_present(text,'NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE'):
        fail.append(f'{p.relative_to(ROOT)}: missing no-exclusion/no-deferral law')
    if not clause_present(text,'must read and obey') or '`AGENTS.md`' not in text:
        fail.append(f'{p.relative_to(ROOT)}: missing mandatory AGENTS.md handoff')

law10_clauses=[
 'An explicit rationale does not legalize an exclusion or a deferral.',
 'REPEAT UNTIL PROVEN',
 'The only acceptable stopping condition is verified closure',
 'No alternative path to completion exists.',
]
for p in agent_constitutions + claude_constitutions:
    text=constitution_text(p)
    for clause in law10_clauses:
        if not clause_present(text,clause): fail.append(f'{p.relative_to(ROOT)}: missing Law 10 clause {clause}')

if fail:
    print('ONE_GUARD_CONSTITUTION: FAIL')
    for item in sorted(set(fail)): print(' -',item)
    sys.exit(1)
print('ONE_GUARD_CONSTITUTION: PASS')
print('anti_crash_present=true')
print('other_mutation_guards=0')
print('critical_approval=false')
print('risk_approval=false')
print('verification_gate=false')
print('human_takeover_non_auth=false')
print('manual_resume=false')
print('needs_review=false')
print('policy_block=false')
print('budget_block=false')
print('same_lane_task_binding_block=false')
print('verification_is_nonblocking=true')
print('transient_failure_retries=true')
print('no_exclusions=true')
print('no_deferral=true')
print('no_partial_acceptance=true')
print('claude_constitution_enforced=true')
print('claude_subtree_constitutions_enforced=true')
