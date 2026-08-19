#!/usr/bin/env python3
"""Verify that GitHub itself cannot bypass RpConnector's production controls.

This runs with the workflow GITHUB_TOKEN. Missing/unreadable security state is a
failure, not an implicit pass: a production claim must be able to prove the
repository governance that protects it.
"""
from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request

repo = os.environ.get("GITHUB_REPOSITORY", "RpSystemAg/RpConnector")
token = os.environ.get("GITHUB_TOKEN", "")
if not token:
    raise SystemExit("GOV-00 GITHUB_TOKEN unavailable")

headers = {
    "Accept": "application/vnd.github+json",
    "Authorization": f"Bearer {token}",
    "X-GitHub-Api-Version": "2022-11-28",
    "User-Agent": "rpconnector-governance-audit/1",
}


def get(path: str):
    request = urllib.request.Request(f"https://api.github.com{path}", headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return response.status, json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        try:
            payload = json.loads(body)
        except Exception:
            payload = {"message": body[:1000]}
        return exc.code, payload


errors: list[str] = []
evidence: dict[str, object] = {"repository": repo, "checks": {}}

status, meta = get(f"/repos/{repo}")
if status != 200:
    raise SystemExit(f"GOV-01 cannot read repository metadata: HTTP {status} {meta}")

def record(name: str, ok: bool, detail) -> None:
    evidence["checks"][name] = {"ok": bool(ok), "detail": detail}
    if not ok:
        errors.append(f"{name}: {detail}")

record("default_branch_is_master", meta.get("default_branch") == "master", meta.get("default_branch"))

status, branch = get(f"/repos/{repo}/branches/master")
record("master_metadata_readable", status == 200, {"http": status})
if status == 200:
    record("master_is_protected", branch.get("protected") is True, branch.get("protected"))
else:
    branch = {}

# Branch-protection REST is the most concrete source for force-push/deletion and
# required-check enforcement. A 404 means there is no classic protection rule.
status, protection = get(f"/repos/{repo}/branches/master/protection")
record("branch_protection_readable", status == 200, {"http": status, "message": protection.get("message") if isinstance(protection, dict) else None})
if status == 200:
    checks = protection.get("required_status_checks") or {}
    contexts = checks.get("contexts") or []
    rich_checks = checks.get("checks") or []
    record("required_status_checks_enforced", bool(contexts or rich_checks), {"contexts": contexts, "checks": rich_checks})
    record("strict_required_checks", checks.get("strict") is True, checks.get("strict"))
    record("force_push_disabled", (protection.get("allow_force_pushes") or {}).get("enabled") is False, protection.get("allow_force_pushes"))
    record("branch_deletion_disabled", (protection.get("allow_deletions") or {}).get("enabled") is False, protection.get("allow_deletions"))
else:
    for name in ("required_status_checks_enforced", "strict_required_checks", "force_push_disabled", "branch_deletion_disabled"):
        record(name, False, "no readable master protection policy")

# Rulesets may protect the branch even when classic branch protection is not the
# chosen UI. Record them separately so migration to rulesets remains visible.
status, rulesets = get(f"/repos/{repo}/rulesets?includes_parents=true")
record("rulesets_readable", status == 200, {"http": status})
active_rules = []
if status == 200 and isinstance(rulesets, list):
    active_rules = [r for r in rulesets if r.get("enforcement") == "active"]
record("active_repository_ruleset_present", bool(active_rules), [{"id": r.get("id"), "name": r.get("name")} for r in active_rules])

# security_and_analysis is returned only when the caller is allowed to inspect
# these settings. Not observable means the production certifier cannot prove it.
security = meta.get("security_and_analysis")
record("security_settings_observable", isinstance(security, dict), "present" if isinstance(security, dict) else "field omitted by GitHub API/token")
if isinstance(security, dict):
    secret_status = ((security.get("secret_scanning") or {}).get("status"))
    push_status = ((security.get("secret_scanning_push_protection") or {}).get("status"))
    record("secret_scanning_enabled", secret_status == "enabled", secret_status)
    record("push_protection_enabled", push_status == "enabled", push_status)
else:
    record("secret_scanning_enabled", False, "unprovable")
    record("push_protection_enabled", False, "unprovable")

print(json.dumps(evidence, indent=2, sort_keys=True))
print(f"REPOSITORY GOVERNANCE: errors={len(errors)}")
for error in errors:
    print("ERROR", error)
if errors:
    print("ACTION: configure an active master ruleset/branch protection and secret push protection in GitHub; do not weaken this audit.")
sys.exit(1 if errors else 0)
