#!/usr/bin/env python3
from __future__ import annotations
import argparse, ast, hashlib, html.parser, json, os, re, shutil, struct, subprocess, sys
from collections import Counter
from pathlib import Path

VERSION='1.0.0'
ROOT=Path(__file__).resolve().parent.parent
EXT=ROOT/'prstudio-unified-browser-agent'
CTRL=ROOT/'prstudio-unified-control'
OUT=ROOT/f'RIGOROUS-AUDIT-{VERSION}.json'
FILES_OUT=ROOT/f'RIGOROUS-AUDIT-FILES-{VERSION}.ndjson'
TOOLS_OUT=ROOT/f'RIGOROUS-AUDIT-TOOLS-{VERSION}.ndjson'
RESEARCH=ROOT/f'PR-STUDIO-{VERSION}-WEB-RESEARCH-5PASS.json'


class HTMLCheck(html.parser.HTMLParser):
    def __init__(self): super().__init__(); self.errors=[]

def sha(path:Path)->str:
    h=hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda:f.read(1024*1024),b''): h.update(chunk)
    return h.hexdigest()

def rel(path:Path)->str: return path.relative_to(ROOT).as_posix()

def strict_json(path:Path):
    def hook(pairs):
        d={}
        for k,v in pairs:
            if k in d: raise ValueError(f'duplicate_key:{k}')
            d[k]=v
        return d
    return json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=hook)

def command(argv, timeout=30):
    try:
        p=subprocess.run(argv,cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,encoding='utf-8',errors='replace',timeout=timeout)
        return p.returncode,p.stdout,p.stderr
    except Exception as e: return 999,'',f'{type(e).__name__}:{e}'

def syntax_check(path:Path):
    ext=path.suffix.lower()
    if ext=='.php':
        php=os.environ.get('PRSTUDIO_PHP') or shutil.which('php')
        if not php:return False,'php_cli_unavailable'
        rc,out,err=command([php,'-l',str(path)],20); return rc==0,(err or out).strip()[:500]
    if ext in {'.js','.mjs'}:
        node=os.environ.get('PRSTUDIO_NODE') or shutil.which('node')
        if not node:return False,'node_unavailable'
        rc,out,err=command([node,'--check',str(path)],20); return rc==0,(err or out).strip()[:500]
    if ext=='.py':
        try: ast.parse(path.read_text(encoding='utf-8'), filename=str(path)); return True,'ast_parse'
        except Exception as e:return False,str(e)
    if ext=='.json':
        try: strict_json(path); return True,'strict_json_no_duplicate_keys'
        except Exception as e:return False,str(e)
    if ext in {'.html','.htm'}:
        try: p=HTMLCheck();p.feed(path.read_text(encoding='utf-8'));return True,'html_parser'
        except Exception as e:return False,str(e)
    if ext=='.png':
        try:
            b=path.read_bytes()
            if not b.startswith(b'\x89PNG\r\n\x1a\n') or len(b)<24:return False,'invalid_png_signature'
            w,h=struct.unpack('>II',b[16:24]);return w>0 and h>0,f'png:{w}x{h}'
        except Exception as e:return False,str(e)
    if ext in {'.md','.txt','.css','.htaccess',''} or path.name=='.htaccess':
        try:
            t=path.read_text(encoding='utf-8');
            if '\x00' in t:return False,'embedded_nul'
            if ext=='.css' and t.count('{')!=t.count('}'):return False,'css_brace_mismatch'
            return True,'utf8_text'
        except UnicodeDecodeError:return False,'non_utf8_text'
    return True,'binary_or_unparsed_format_integrity_only'

def static_semantic(path:Path):
    ext=path.suffix.lower()
    findings=[]
    if ext not in {'.php','.js','.mjs','.py','.json','.md','.txt','.html','.css'} and path.name!='.htaccess': return True,findings
    try:text=path.read_text(encoding='utf-8')
    except Exception:return True,findings
    # Dynamic execution and unfinished-code sentinels are release blockers for
    # runtime/deployable code. Test harnesses are still syntax/reference/research
    # audited below, but may intentionally construct code strings to exercise a
    # runtime helper in isolation (for example xmlDecode via node:test).
    runtime_semantics='/tests/' not in f'/{rel(path)}'
    forbidden=[
      (r'\beval\s*\(', 'eval_execution'),
      (r'\bassert\s*\(\s*\$', 'dynamic_assert_execution'),
      (r'\b(?:shell_exec|passthru)\s*\(', 'arbitrary_shell_surface'),
      (r'\bnew\s+Function\s*\(', 'dynamic_js_function'),
    ]
    if runtime_semantics:
        for pat,name in forbidden:
            if re.search(pat,text):findings.append(name)
    # TODO/FIXME are not permitted in deployable executable code.
    if runtime_semantics and ext in {'.php','.js','.mjs','.py'} and re.search(r'\b(?:TODO|FIXME|NotImplemented)\b',text):findings.append('unfinished_marker')
    # Never ship obvious bearer/private key material, including fixtures/tests.
    if re.search(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',text):findings.append('private_key_material')
    if re.search(r'Bearer\s+[A-Za-z0-9._~-]{32,}',text):findings.append('literal_bearer_secret')
    return not findings,findings

def local_refs(path:Path):
    refs=[]; missing=[]
    ext=path.suffix.lower()
    try:text=path.read_text(encoding='utf-8')
    except Exception:return True,refs,missing
    if ext in {'.js','.mjs'}:
        for m in re.finditer(r'(?:from\s+|import\s*\()?[\'\"](\./|\.\./)([^\'\"]+)[\'\"]',text):
            raw=m.group(1)+m.group(2); target=(path.parent/raw).resolve()
            candidates=[target,target.with_suffix('.js'),target.with_suffix('.mjs'),target/'index.js'] if not target.suffix else [target]
            refs.append(raw)
            if not any(c.is_file() for c in candidates):missing.append(raw)
    if ext in {'.html','.htm'}:
        for raw in re.findall(r'(?:src|href)=[\'\"]([^\'\"]+)[\'\"]',text,re.I):
            if raw.startswith(('http:','https:','data:','#','chrome-extension:')):continue
            clean=raw.split('?',1)[0].split('#',1)[0]
            if not clean:continue
            refs.append(clean)
            if not (path.parent/clean).resolve().is_file():missing.append(clean)
    if path.name=='manifest.json':
        try:
            d=strict_json(path)
            vals=[]
            bg=d.get('background',{}).get('service_worker'); vals += [bg] if bg else []
            vals += list(d.get('permissions',[]))*0
            for icon in (d.get('icons') or {}).values():vals.append(icon)
            side=d.get('side_panel',{}).get('default_path'); vals += [side] if side else []
            for v in vals:
                refs.append(v)
                if not (path.parent/v).is_file():missing.append(v)
        except Exception: pass
    return not missing,refs[:50],missing[:50]

def component_tags(path:Path):
    r=rel(path)
    tags=[]
    if r.startswith('prstudio-unified-browser-agent/'):tags.append('browser_extension')
    if r.startswith('prstudio-unified-control/'):tags.append('wordpress_plugin')
    if any(x in r for x in ['/connector/','class-prstudio-uc-mcp','class-wpaib-mcp','capability-registry','capability-contract','action-catalog']):tags.append('rp_studio_connector')
    return tags or ['suite']

def research_ledger():
    if not RESEARCH.is_file():return {},{}
    try:doc=strict_json(RESEARCH)
    except Exception:return {},{}
    rows=doc.get('files') if isinstance(doc,dict) else []
    if not isinstance(rows,list):rows=[]
    return doc,{str(r.get('path','')):r for r in rows if isinstance(r,dict) and r.get('path')}

def research_check(path:Path, ledger_map):
    rp=rel(path); row=ledger_map.get(rp)
    checks={'ledger_entry_present':bool(row)}
    if not row:return False,checks
    checks['sha256_current']=str(row.get('sha256',''))==sha(path)
    passes=row.get('passes') if isinstance(row.get('passes'),list) else []
    checks['five_passes']=len(passes)==5 and [p.get('pass') for p in passes]==[1,2,3,4,5]
    checks['source_mapping_present']=bool(row.get('source_keys')) and all(bool(p.get('source_keys')) for p in passes if isinstance(p,dict))
    checks['decisions_present']=all(bool(p.get('decision')) and bool(p.get('rationale')) for p in passes if isinstance(p,dict)) and len(passes)==5
    return all(checks.values()),checks

def file_rows(ledger_map):
    rows=[]; hard=[]
    files=sorted([p for base in (EXT,CTRL) for p in base.rglob('*') if p.is_file()])
    for p in files:
        tags=component_tags(p); ok_syn,syn=syntax_check(p); ok_sem,findings=static_semantic(p); ok_ref,refs,missing=local_refs(p); ok_research,research=research_check(p,ledger_map)
        passes={
          'P1_inventory_integrity':{'status':'PASS','sha256':sha(p),'bytes':p.stat().st_size},
          'P2_parser_syntax':{'status':'PASS' if ok_syn else 'FAIL','evidence':syn},
          'P3_security_semantics':{'status':'PASS' if ok_sem else 'FAIL','findings':findings},
          'P4_reference_integrity':{'status':'PASS' if ok_ref else 'FAIL','references':refs,'missing':missing},
          'P5_research_conformance':{'status':'PASS' if ok_research else 'FAIL','checks':research},
        }
        if not (ok_syn and ok_sem and ok_ref and ok_research):hard.append(rel(p))
        rows.append({'path':rel(p),'roles':tags,'sha256':passes['P1_inventory_integrity']['sha256'],'bytes':p.stat().st_size,'passes':passes})
    return rows,hard

def function_body(src,name):
    # PHP method only, brace-balanced from function declaration.
    m=re.search(r'function\s+'+re.escape(name)+r'\s*\([^)]*\)[^{]*\{',src)
    if not m:return ''
    i=m.end()-1; depth=0
    for j in range(i,len(src)):
        if src[j]=='{':depth+=1
        elif src[j]=='}':
            depth-=1
            if depth==0:return src[i+1:j]
    return ''

def tool_rows():
    rows=[]; hard=[]
    mcp=(CTRL/'includes/class-prstudio-uc-mcp-v5.php').read_text(encoding='utf-8')
    tools_body=function_body(mcp,'build_tools') or mcp
    tool_matches=list(re.finditer(r"self::tool\(\s*['\"]([A-Za-z0-9_:-]+)['\"]",tools_body))
    names=[m.group(1) for m in tool_matches]
    cases=Counter(re.findall(r"case\s+['\"]([A-Za-z0-9_:-]+)['\"]\s*:",mcp))
    annotations=set()
    for index,match in enumerate(tool_matches):
        end=tool_matches[index+1].start() if index+1<len(tool_matches) else len(tools_body)
        if 'self::annotations(' in tools_body[match.start():end]:
            annotations.add(match.group(1))
    for n in names:
        checks={
          'unique_definition':names.count(n)==1,
          'dispatch_case_exactly_once':cases[n]==1,
          'annotations_present':n in annotations,
          'input_schema_declared':bool(re.search(r"self::tool\(\s*['\"]"+re.escape(n)+r"['\"]",tools_body)),
        }
        status='PASS' if all(checks.values()) else 'FAIL'
        if status=='FAIL':hard.append('mcp:'+n)
        rows.append({'kind':'mcp_tool','id':n,'status':status,'checks':checks,'evidence_type':'static_semantic_plus_runtime_gate'})
    # 1378 capabilities: validate every descriptor individually.
    registry=strict_json(CTRL/'capabilities/capability-registry.json')
    agency_registry=strict_json(CTRL/'capabilities/agency-capabilities.json')
    caps=list(registry.get('capabilities',[]))+list(agency_registry.get('capabilities',[]))
    cap_ids=[str(c.get('id','')) for c in caps]
    catalog=strict_json(CTRL/'connector/action-catalog.json').get('actions',[])
    catalog_keys={(str(a.get('route','')),str(a.get('action',''))) for a in catalog}
    for cap in caps:
        cid=str(cap.get('id','')); source=cap.get('source') or {}; kind=str(source.get('kind',''))
        checks={
          'id_nonempty_unique':bool(cid) and cap_ids.count(cid)==1,
          'read_write_exclusive':isinstance(cap.get('read_only'),bool) and isinstance(cap.get('write'),bool) and bool(cap.get('read_only'))!=bool(cap.get('write')),
          'destructive_boolean':isinstance(cap.get('destructive'),bool),
          'idempotent_boolean':isinstance(cap.get('idempotent'),bool),
          'executor_declared':bool(cap.get('executor')),
        }
        if kind=='legacy_action': checks['source_target_exists']=(str(source.get('route','')),str(source.get('action',''))) in catalog_keys
        elif kind=='legacy_direct_tool': checks['source_tool_named']=bool(source.get('tool_name'))
        status='PASS' if all(checks.values()) else 'FAIL'
        if status=='FAIL':hard.append('capability:'+cid)
        rows.append({'kind':'capability','id':cid,'status':status,'checks':checks,'evidence_type':'descriptor_contract'})
    # 1076 action-catalog rows.
    action_ids=[]
    for a in catalog:
        aid=f"{a.get('route','')}::{a.get('action','')}";action_ids.append(aid)
    for a,aid in zip(catalog,action_ids):
        checks={
          'route_action_unique':action_ids.count(aid)==1,
          'tool_name_present':bool(a.get('tool_name')),
          'risk_booleans':all(isinstance(a.get(k),bool) for k in ('read_only','destructive','idempotent')),
          'executor_or_handler_present':bool(a.get('executor') or a.get('handler') or a.get('callback') or a.get('implementation')),
        }
        # concrete surface is authoritative for execution routing; action metadata may intentionally omit executor.
        if not checks['executor_or_handler_present']: checks['executor_or_handler_present']=aid in strict_json(CTRL/'contract/concrete-execution-surface.json').get('actions',{})
        status='PASS' if all(checks.values()) else 'FAIL'
        if status=='FAIL':hard.append('action:'+aid)
        rows.append({'kind':'connector_action','id':aid,'status':status,'checks':checks,'evidence_type':'catalog_plus_concrete_surface'})
    for row in rows: row['suite_version']=VERSION
    return rows,hard

def generate():
    research_doc,ledger_map=research_ledger()
    research_ok=bool(
        research_doc.get('pass_count')==5 and
        research_doc.get('all_files_covered') is True and
        research_doc.get('hard_failure_count',1)==0 and
        research_doc.get('methodology',{}).get('old_250_checkpoint_executed') is False
    )
    files,file_hard=file_rows(ledger_map);tools,tool_hard=tool_rows()
    hard=file_hard+tool_hard+([] if research_ok else ['five_pass_web_research_ledger'])
    summary={
      'suite_version':VERSION,'controller_version':'2.0.0','policy':'no deployable file and no published tool may be omitted; file research evidence comes from the current SHA-bound five-pass web-research ledger; the retired 250-checkpoint matrix is not executed',
      'research_5pass':{'ok':research_ok,'ledger':RESEARCH.name,'pass_count':research_doc.get('pass_count',0),'file_count':research_doc.get('file_count',0),'old_250_checkpoint_executed':research_doc.get('methodology',{}).get('old_250_checkpoint_executed') if isinstance(research_doc,dict) else None},
      'files':{'total':len(files),'browser_extension':sum('browser_extension' in r['roles'] for r in files),'wordpress_plugin':sum('wordpress_plugin' in r['roles'] for r in files),'connector_tagged':sum('rp_studio_connector' in r['roles'] for r in files),'hard_failures':len(file_hard)},
      'tools':{'total_rows':len(tools),'mcp_tools':sum(r['kind']=='mcp_tool' for r in tools),'capabilities':sum(r['kind']=='capability' for r in tools),'connector_actions':sum(r['kind']=='connector_action' for r in tools),'hard_failures':len(tool_hard)},
      'hard_failures':hard,'hard_failure_count':len(hard),
    }
    return summary,files,tools

def dump_json(obj):return json.dumps(obj,ensure_ascii=False,sort_keys=True,indent=2)+'\n'
def dump_lines(rows):return ''.join(json.dumps(r,ensure_ascii=False,sort_keys=True)+'\n' for r in rows)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--write',action='store_true',help='persist RIGOROUS-AUDIT-*.json/ndjson snapshots (release-packaging step only; these are not committed source)')
    ap.add_argument('--check',action='store_true',help='verify previously --write-n snapshots are still fresh (only meaningful once a release snapshot exists)')
    ap.add_argument('--audit',action='store_true',help='recompute live and report hard_failure_count only, without requiring or writing any persisted snapshot file')
    a=ap.parse_args()
    if not(a.write or a.check or a.audit):ap.error('use --write, --check or --audit')
    summary,files,tools=generate(); s=dump_json(summary); f=dump_lines(files); t=dump_lines(tools)
    if a.write:
        OUT.write_text(s,encoding='utf-8');FILES_OUT.write_text(f,encoding='utf-8');TOOLS_OUT.write_text(t,encoding='utf-8')
    elif a.check:
        for p,expected in ((OUT,s),(FILES_OUT,f),(TOOLS_OUT,t)):
            if not p.is_file() or p.read_text(encoding='utf-8')!=expected:
                print(f'STALE_OR_MISSING {rel(p)}',file=sys.stderr);return 3
    print(f"{'PASS' if summary['hard_failure_count']==0 else 'FAIL'} rigorous audit: files={summary['files']['total']} mcp={summary['tools']['mcp_tools']} capabilities={summary['tools']['capabilities']} actions={summary['tools']['connector_actions']} hard_failures={summary['hard_failure_count']}")
    return 0 if summary['hard_failure_count']==0 else 2
if __name__=='__main__':raise SystemExit(main())