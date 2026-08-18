#!/usr/bin/env python3
from __future__ import annotations
import json,re,sys
from pathlib import Path
from m11_contract_audit import audit_contracts
ROOT=Path(__file__).resolve().parent.parent
COMP=ROOT/'prstudio-unified-control/includes/class-prstudio-uc-complete-action-executor.php'
AGENCY=ROOT/'prstudio-unified-control/includes/class-prstudio-agency.php'
AGSEM=ROOT/'prstudio-unified-control/includes/class-prstudio-uc-agency-action-executor.php'
BACKEND=ROOT/'prstudio-unified-control/includes/class-prstudio-uc-backend-executability.php'
REST=ROOT/'prstudio-unified-control/includes/class-wpaib-rest.php'
MCP=ROOT/'prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php'
CAT=ROOT/'prstudio-unified-control/connector/action-catalog.json'
MCP_BASELINE=ROOT/'tests/mcp-tool-surface-compatibility-baseline.json'

def block(text,start):
    i=text.find('{',start)
    if i<0:return ''
    d=0;sq=dq=False;esc=False
    for j in range(i,len(text)):
        c=text[j]
        if esc:esc=False;continue
        if c=='\\' and (sq or dq):esc=True;continue
        if c=="'" and not dq:sq=not sq;continue
        if c=='"' and not sq:dq=not dq;continue
        if sq or dq:continue
        if c=='{':d+=1
        elif c=='}':
            d-=1
            if d==0:return text[i+1:j]
    return text[i+1:]

def func(text,name):
    m=re.search(r'function\s+'+re.escape(name)+r'\s*\(',text)
    return block(text,m.end()) if m else ''

def literals(body):
    x=set(re.findall(r"case\s+['\"]([a-z0-9_:-]+)['\"]\s*:",body))
    return x

def parse_complete():
    s=COMP.read_text(encoding='utf-8')
    routes={}
    for mm in re.finditer(r"\n\t\t'(/[^']+)'\s*=>\s*array\((.*?)\n\t\t\),",s,re.S):
        routes[mm.group(1)]=re.findall(r"['\"]([a-z0-9_:-]+)['\"]",mm.group(2))
    failures=[];evidence=[]
    for route,actions in routes.items():
        handler='route_'+route.strip('/').replace('-','_')
        b=func(s,handler)
        if not b:
            failures.append(f'complete:no_handler:{route}');continue
        if '_action_unhandled' not in b and 'action_unhandled' not in b:
            failures.append(f'complete:no_defensive_unhandled:{route}')
        for a in actions:
            covered=(f"'{a}'" in b or f'"{a}"' in b)
            if not covered and route=='/files-manage' and a.startswith('audit_'): covered="str_starts_with( $action, 'audit_' )" in b or "str_starts_with($action,'audit_')" in b
            if not covered and route=='/plugins-manage' and a.startswith('inspect_plugin_'): covered="inspect_plugin_" in b and a.replace('inspect_plugin_','') in func(s,'inspect_plugin_kind')
            if not covered and route=='/themes-manage' and a.startswith('inspect_theme_'): covered="inspect_theme_" in b and a.replace('inspect_theme_','') in func(s,'inspect_theme_kind')
            if not covered and route=='/seo-manage' and a.startswith('audit_'): covered="audit_" in b and (a=='audit_site' or a in func(s,'seo_audit'))
            if not covered and route=='/seo-manage' and a.startswith('generate_') and a.endswith('_schema'): covered="generate_" in b and a in func(s,'schema_for_action')
            if not covered: failures.append(f'complete:missing_semantics:{route}::{a}')
            evidence.append((route,a,covered))
    if len(routes)!=23:failures.append(f'complete:route_count:{len(routes)}')
    if sum(len(v) for v in routes.values())!=437:failures.append(f'complete:action_count:{sum(len(v) for v in routes.values())}')
    if "PRSTUDIO_UC_Complete_Action_Executor::execute" not in REST.read_text(encoding='utf-8'): failures.append('complete:not_routed_from_rest')
    if "complete_native" not in BACKEND.read_text(encoding='utf-8'): failures.append('complete:not_bound_backend')
    return failures,{'routes':len(routes),'actions':sum(len(v) for v in routes.values()),'semantically_covered':sum(1 for *_,ok in evidence if ok)}

def parse_agency():
    a=AGENCY.read_text(encoding='utf-8'); sem=AGSEM.read_text(encoding='utf-8');fail=[]
    groups=func(a,'groups')
    actions=[]
    for csv in re.findall(r"=>\s*'([a-z0-9_,:-]+)'",groups): actions += [x for x in csv.split(',') if x]
    actions=list(dict.fromkeys(actions)); aset=set(actions)
    native=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]",func(a,'native_actions')))
    state=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]",func(a,'stored_only_actions')))
    cm=re.search(r'private const ACTIONS\s*=\s*array\s*\((.*?)\n\t\);',sem,re.S)
    semantic=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]",cm.group(1))) if cm else set()
    cases=literals(func(sem,'execute'))
    if len(aset)!=93:fail.append(f'agency:action_count:{len(aset)}')
    missing=aset-native-state-semantic
    orphan=semantic-aset
    smiss=(semantic & aset)-cases
    overlap=(native&state)|(native&semantic)|(state&semantic)
    for n in sorted(missing):fail.append('agency:missing_executor:'+n)
    for n in sorted(orphan):fail.append('agency:orphan_semantic:'+n)
    for n in sorted(smiss):fail.append('agency:semantic_missing_case:'+n)
    for n in sorted(overlap):fail.append('agency:ownership_overlap:'+n)
    run=func(a,'run_job')
    if run.find('PRSTUDIO_UC_Agency_Action_Executor::execute')<0:fail.append('agency:semantic_not_called')
    elif run.find('PRSTUDIO_UC_Agency_Action_Executor::execute')>run.find('payload_plan_fallback'):fail.append('agency:semantic_after_generic_plan')
    complete=func(a,'complete_job')
    if "array_key_exists( 'executed'" not in complete or "array_key_exists( 'verified'" not in complete:fail.append('agency:direct_outcome_contract_missing')
    plan=func(a,'payload_plan_fallback')
    append_count=len(re.findall(r'\$results\[\]\s*=\s*\$result\s*;',plan))
    if append_count!=1:fail.append(f'agency:plan_result_append_count:{append_count}')
    if 'prstudio_agency_binding_contract_violation' not in run:fail.append('agency:no_defensive_binding_failure')
    if "'status' => 500" not in run:fail.append('agency:binding_failure_not_server_error')
    return fail,{'actions':len(aset),'native':len(native&aset),'state_native':len(state&aset),'semantic':len(semantic&aset),'semantic_cases':len(cases&aset),'plan_result_append_count':append_count}

def global_checks():
    fail=[];e={}
    cat=json.loads(CAT.read_text(encoding='utf-8'))['actions'];e['control_catalog']=len(cat)
    if len(cat)!=1076:fail.append(f'catalog:count:{len(cat)}')
    m=MCP.read_text(encoding='utf-8');tools=re.findall(r"self::tool\(\s*['\"]([a-zA-Z0-9_:-]+)['\"]",m);cases=literals(m);e['mcp_tools']=len(tools);e['mcp_unique']=len(set(tools));e['mcp_dispatched']=sum(1 for x in tools if x in cases)
    baseline=json.loads(MCP_BASELINE.read_text(encoding='utf-8'))['tools'];e['mcp_baseline']=len(baseline);e['mcp_additive']=max(0,len(set(tools))-len(baseline))
    missing_baseline=sorted(set(baseline)-set(tools));undispatched=sorted(set(tools)-cases)
    if len(tools)!=len(set(tools)):fail.append('mcp:duplicate_tool_declaration')
    for name in missing_baseline:fail.append('mcp:baseline_tool_removed:'+name)
    for name in undispatched:fail.append('mcp:tool_not_dispatched:'+name)
    backend=BACKEND.read_text(encoding='utf-8')
    if 'prstudio_action_not_implemented' in backend:fail.append('backend:not_implemented_fallback_present')
    # `client_action_required` may appear only in explanatory compatibility text,
    # never as an executor return code in the concrete execution paths.
    for path in [COMP,AGSEM,BACKEND]:
        t=path.read_text(encoding='utf-8')
        if re.search(r"WP_Error\s*\(\s*['\"](?:[^'\"]*not_implemented|client_action_required|backend_executor_resolution_failed)",t,re.I):fail.append('forbidden_public_stub_error:'+path.name)
    return fail,e

def main():
    failures=[];evidence={}
    f,e=parse_complete();failures+=f;evidence['complete_executor']=e
    f,e=parse_agency();failures+=f;evidence['agency']=e
    f,e=global_checks();failures+=f;evidence['global']=e
    f,e=audit_contracts();failures+=f;evidence['milestone_11_contracts']=e
    out={'suite_version':'17.0.0','ok':not failures,'failure_count':len(failures),'failures':failures,'evidence':evidence,'policy':'Every declared public action resolves to a concrete semantic executor. Defensive binding errors are release-defect sentinels, not normal implementation paths.'}
    print(json.dumps(out,ensure_ascii=False,indent=2,sort_keys=True))
    return 0 if not failures else 1
if __name__=='__main__':raise SystemExit(main())
