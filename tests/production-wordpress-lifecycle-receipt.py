#!/usr/bin/env python3
"""Build upgrade_migration and backup_restore receipts from real WP lifecycle evidence.

This script does not execute WordPress. It consumes snapshots and marker files
created only after independent WP-CLI/database assertions have succeeded and
normalizes them into production-readiness receipts for the exact commit.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "evidence" / "production"


def iso_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def load(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def marker(marker_dir: Path, name: str) -> tuple[bool, dict[str, Any]]:
    path = marker_dir / name
    return path.is_file() and path.stat().st_size > 0, {
        "path": str(path),
        "contents": path.read_text(encoding="utf-8", errors="replace")[-2000:] if path.is_file() else "",
    }


def part(snapshot: dict[str, Any], key: str) -> Any:
    return snapshot.get("preserved", {}).get(key)


def check_equal(before: dict[str, Any], after: dict[str, Any], keys: list[str]) -> tuple[bool, dict[str, Any]]:
    mismatches = {}
    for key in keys:
        left = part(before, key)
        right = part(after, key)
        if left != right:
            mismatches[key] = {"before": left, "after": right}
    return not mismatches, {"keys": keys, "mismatches": mismatches}


def receipt(
    gate_id: str,
    commit: str,
    started_at: str,
    environment: dict[str, Any],
    checks: list[dict[str, Any]],
    detail_path: Path,
) -> dict[str, Any]:
    return {
        "schema_version": 1,
        "gate_id": gate_id,
        "commit_sha": commit,
        "ok": all(c.get("ok") is True for c in checks),
        "started_at": started_at,
        "finished_at": iso_now(),
        "environment": environment,
        "checks": checks,
        "artifacts": [{"path": str(detail_path), "sha256": sha256(detail_path)}],
        "waivers": [],
        "skipped": [],
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--commit", default=os.environ.get("GITHUB_SHA", ""))
    parser.add_argument("--marker-dir", required=True)
    parser.add_argument("--baseline", required=True)
    parser.add_argument("--post-upgrade", required=True)
    parser.add_argument("--post-restore", required=True)
    parser.add_argument("--metrics", required=True)
    parser.add_argument("--environment-id", required=True)
    parser.add_argument("--started-at", required=True)
    args = parser.parse_args()

    commit = args.commit.strip()
    if not commit:
        raise SystemExit("commit is required")
    marker_dir = Path(args.marker_dir)
    baseline_path = Path(args.baseline)
    post_upgrade_path = Path(args.post_upgrade)
    post_restore_path = Path(args.post_restore)
    metrics_path = Path(args.metrics)
    baseline = load(baseline_path)
    post_upgrade = load(post_upgrade_path)
    post_restore = load(post_restore_path)
    metrics = load(metrics_path)

    if baseline.get("preserved_hash") != post_upgrade.get("preserved_hash"):
        upgrade_hash_ok = False
    else:
        upgrade_hash_ok = True
    restore_hash_ok = baseline.get("preserved_hash") == post_restore.get("preserved_hash")

    fresh_ok, fresh_ev = marker(marker_dir, "fresh_install")
    previous_ok, previous_ev = marker(marker_dir, "previous_release_upgrade")
    interrupted_ok, interrupted_ev = marker(marker_dir, "interrupted_migration_resume")
    idempotency_ok, idempotency_ev = marker(marker_dir, "migration_idempotency")
    failed_incomplete_ok, failed_incomplete_ev = marker(marker_dir, "failed_migration_not_marked_complete")

    settings_ok, settings_ev = check_equal(baseline, post_upgrade, ["settings_marker", "actions_keys", "google_oauth", "google_tokens"])
    jobs_memory_ok, jobs_memory_ev = check_equal(baseline, post_upgrade, ["job_uuid", "job", "memory"])
    oauth_pairing_ok, oauth_pairing_ev = check_equal(baseline, post_upgrade, ["oauth_clients", "oauth_tokens", "oauth_generation", "device_uuid", "device"])

    upgrade_checks = [
        {"id": "fresh_install", "ok": fresh_ok, "evidence": fresh_ev},
        {"id": "previous_release_upgrade", "ok": previous_ok and upgrade_hash_ok, "evidence": {**previous_ev, "preserved_hash_equal": upgrade_hash_ok}},
        {"id": "interrupted_migration_resume", "ok": interrupted_ok, "evidence": interrupted_ev},
        {"id": "migration_idempotency", "ok": idempotency_ok, "evidence": idempotency_ev},
        {"id": "settings_preserved", "ok": settings_ok, "evidence": settings_ev},
        {"id": "jobs_memory_evidence_preserved", "ok": jobs_memory_ok, "evidence": jobs_memory_ev},
        {"id": "oauth_pairing_preserved", "ok": oauth_pairing_ok, "evidence": oauth_pairing_ev},
        {"id": "failed_migration_not_marked_complete", "ok": failed_incomplete_ok, "evidence": failed_incomplete_ev},
    ]

    backup_ok, backup_ev = marker(marker_dir, "backup_created")
    clean_restore_ok, clean_restore_ev = marker(marker_dir, "clean_environment_restore")
    restore_verify_ok, restore_verify_ev = marker(marker_dir, "restore_verification")
    config_restore_ok, config_restore_ev = check_equal(baseline, post_restore, ["settings_marker", "actions_keys", "google_oauth", "google_tokens", "oauth_clients", "oauth_tokens", "oauth_generation"])
    runtime_restore_ok, runtime_restore_ev = check_equal(baseline, post_restore, ["device_uuid", "device", "job_uuid", "job"])
    memory_restore_ok, memory_restore_ev = check_equal(baseline, post_restore, ["memory"])
    rpo = metrics.get("rpo_seconds")
    rto = metrics.get("rto_seconds")
    rpo_rto_ok = isinstance(rpo, (int, float)) and rpo >= 0 and isinstance(rto, (int, float)) and rto >= 0

    backup_checks = [
        {"id": "backup_created", "ok": backup_ok, "evidence": backup_ev},
        {"id": "clean_environment_restore", "ok": clean_restore_ok, "evidence": clean_restore_ev},
        {"id": "configuration_preserved", "ok": config_restore_ok, "evidence": config_restore_ev},
        {"id": "runtime_state_integrity", "ok": runtime_restore_ok, "evidence": runtime_restore_ev},
        {"id": "jobs_memory_evidence_integrity", "ok": memory_restore_ok, "evidence": memory_restore_ev},
        {"id": "restore_verification", "ok": restore_verify_ok and restore_hash_ok, "evidence": {**restore_verify_ev, "preserved_hash_equal": restore_hash_ok}},
        {"id": "rpo_rto_recorded", "ok": rpo_rto_ok, "evidence": metrics},
    ]

    OUT.mkdir(parents=True, exist_ok=True)
    detail = {
        "schema_version": 1,
        "commit_sha": commit,
        "environment_id": args.environment_id,
        "baseline": baseline,
        "post_upgrade": post_upgrade,
        "post_restore": post_restore,
        "metrics": metrics,
        "upgrade_checks": upgrade_checks,
        "backup_checks": backup_checks,
    }
    detail_path = OUT / "wordpress-lifecycle-details.json"
    detail_path.write_text(json.dumps(detail, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    environment = {
        "real": True,
        "class": "wordpress-mariadb-lifecycle",
        "environment_id": args.environment_id,
        "wordpress_version": metrics.get("wordpress_version"),
        "php_version": metrics.get("php_version"),
        "database_version": metrics.get("database_version"),
        "previous_ref": metrics.get("previous_ref"),
    }
    upgrade = receipt("upgrade_migration", commit, args.started_at, environment, upgrade_checks, detail_path)
    backup = receipt("backup_restore", commit, args.started_at, environment, backup_checks, detail_path)
    (OUT / "upgrade-migration.json").write_text(json.dumps(upgrade, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (OUT / "backup-restore.json").write_text(json.dumps(backup, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("WORDPRESS PRODUCTION LIFECYCLE RECEIPTS")
    for gate, result in (("upgrade_migration", upgrade), ("backup_restore", backup)):
        print(f"{gate} ok={str(result['ok']).lower()}")
        for check in result["checks"]:
            print(f"  {'PASS' if check['ok'] else 'FAIL'} {check['id']}")
    return 0 if upgrade["ok"] and backup["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
