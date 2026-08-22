#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BROWSER = ROOT / 'prstudio-unified-browser-agent' / 'BUILD-INFO.json'
CONTROL = ROOT / 'prstudio-unified-control' / 'BUILD-INFO.json'
CONTRACT = ROOT / 'prstudio-unified-control' / 'includes' / 'class-prstudio-uc-contract.php'
PROTOCOL = ROOT / 'prstudio-unified-control' / 'includes' / 'class-prstudio-uc-browser-protocol.php'
OUT = ROOT / 'artifacts' / 'build-identity' / 'build-identity.json'

def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

browser = json.loads(BROWSER.read_text(encoding='utf-8'))
control = json.loads(CONTROL.read_text(encoding='utf-8'))
contract_hash = sha(CONTRACT)
protocol_hash = sha(PROTOCOL)
errors: list[str] = []

for label, info, prefix in (
    ('browser', browser, 'prstudio-browser-1.0.0+git.'),
    ('control', control, 'prstudio-control-1.0.0+git.'),
):
    if not str(info.get('source_commit', '')).isalnum() or len(str(info.get('source_commit', ''))) != 40:
        errors.append(f'{label}.source_commit_unbound')
    if not str(info.get('build_id', '')).startswith(prefix):
        errors.append(f'{label}.build_id_unbound')
    if str(info.get('built_at_utc', '')).upper() in ('', 'UNSTAMPED'):
        errors.append(f'{label}.built_at_unbound')

if browser.get('source_commit') != control.get('source_commit'):
    errors.append('source_commit_mismatch')
if browser.get('control_contract_sha256') != contract_hash or control.get('contract_file_sha256') != contract_hash:
    errors.append('contract_hash_mismatch')
if browser.get('control_protocol_sha256') != protocol_hash or control.get('protocol_file_sha256') != protocol_hash:
    errors.append('protocol_hash_mismatch')
if browser.get('capability_contract_sha256') != control.get('required_browser_capability_contract_sha256'):
    errors.append('capability_contract_mismatch')
if control.get('required_gsc_dimension_session_version') != '4.0.0':
    errors.append('gsc_dimension_session_not_v4')
if browser.get('executor_protocol') not in control.get('accepted_browser_executor_protocols', []):
    errors.append('executor_protocol_not_accepted')
if browser.get('cdp_protocol_preference') != ['1.3']:
    errors.append('cdp_protocol_not_1_3')

report = {
    'schema_version': '1.0.0',
    'ok': not errors,
    'source_commit': browser.get('source_commit'),
    'browser_build_id': browser.get('build_id'),
    'browser_built_at_utc': browser.get('built_at_utc'),
    'control_build_id': control.get('build_id'),
    'control_built_at_utc': control.get('built_at_utc'),
    'contract_sha256': contract_hash,
    'protocol_sha256': protocol_hash,
    'capability_contract_sha256': browser.get('capability_contract_sha256'),
    'executor_protocol': browser.get('executor_protocol'),
    'accepted_executor_protocols': control.get('accepted_browser_executor_protocols'),
    'gsc_dimension_session_version': control.get('required_gsc_dimension_session_version'),
    'cdp_protocol_preference': browser.get('cdp_protocol_preference'),
    'errors': errors,
}
OUT.parent.mkdir(parents=True, exist_ok=True)
OUT.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
print(json.dumps(report, indent=2, ensure_ascii=False))
if errors:
    raise SystemExit(1)
