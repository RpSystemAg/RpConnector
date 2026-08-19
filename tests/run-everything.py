#!/usr/bin/env python3
"""Zero-exception repository verifier.

Every tracked source/config file receives a concrete parser/lint check and every
executable file under tests/ or prstudio-unified-browser-agent/tests/ is launched
as a real process. There is deliberately no skip/allow/exclude mechanism.
A file that cannot be executed by this harness makes the workflow fail until the
file or harness is corrected.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shlex
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEST_ROOTS = (ROOT / "tests", ROOT / "prstudio-unified-browser-agent" / "tests")
EXEC_EXTS = {".php", ".py", ".js", ".mjs", ".sh"}


def tracked() -> list[Path]:
    raw = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT)
    return [ROOT / p.decode("utf-8") for p in raw.split(b"\0") if p]


def test_exec_files(exts: set[str]) -> list[Path]:
    out: list[Path] = []
    for root in TEST_ROOTS:
        if not root.is_dir():
            continue
        for p in root.rglob("*"):
            if p.is_file() and p.suffix.lower() in exts:
                out.append(p)
    return sorted(set(out))


def rel(p: Path) -> str:
    return p.relative_to(ROOT).as_posix()


def run(cmd: list[str], timeout: int = 180, env: dict[str, str] | None = None) -> dict:
    started = __import__("time").time()
    try:
        cp = subprocess.run(cmd, cwd=ROOT, text=True, capture_output=True, timeout=timeout, env=env or os.environ.copy())
        return {
            "command": shlex.join(cmd),
            "exit_code": cp.returncode,
            "seconds": round(__import__("time").time() - started, 3),
            "stdout_tail": cp.stdout[-4000:],
            "stderr_tail": cp.stderr[-4000:],
        }
    except subprocess.TimeoutExpired as exc:
        return {
            "command": shlex.join(cmd),
            "exit_code": 124,
            "seconds": round(__import__("time").time() - started, 3),
            "stdout_tail": str(exc.stdout or "")[-4000:],
            "stderr_tail": (str(exc.stderr or "") + "\nTIMEOUT")[-4000:],
        }


def php_class_index() -> dict[str, Path]:
    idx: dict[str, Path] = {}
    for p in tracked():
        if p.suffix.lower() != ".php" or "tests/" in rel(p):
            continue
        try:
            text = p.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        for m in re.finditer(r"\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)", text):
            idx[m.group(1)] = p
    return idx


def php_args_for(test: Path, classes: dict[str, Path]) -> list[str]:
    text = test.read_text(encoding="utf-8", errors="replace")
    if not re.search(r"\$argv\s*\[\s*1\s*\]", text):
        return []
    mentioned = []
    for name in classes:
        if name in text:
            mentioned.append(name)
    # Prefer the longest class name: wrappers often mention a generic base and
    # the concrete implementation under test; the concrete symbol is specific.
    if mentioned:
        name = sorted(mentioned, key=len, reverse=True)[0]
        return [rel(classes[name])]
    # No fabricated argument: inability to resolve argv[1] is a hard failure.
    return []


def write_receipt(mode: str, rows: list[dict]) -> None:
    out = ROOT / "evidence" / f"run-everything-{mode}.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "schema": "prstudio.run-everything.v1",
        "mode": mode,
        "git_sha": os.environ.get("GITHUB_SHA") or subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
        "total": len(rows),
        "passed": sum(1 for r in rows if r.get("ok")),
        "failed": sum(1 for r in rows if not r.get("ok")),
        "rows": rows,
    }
    out.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"RECEIPT {out.relative_to(ROOT)} total={payload['total']} passed={payload['passed']} failed={payload['failed']}")


def php_mode() -> int:
    rows: list[dict] = []
    php_files = [p for p in tracked() if p.suffix.lower() == ".php"]
    for p in php_files:
        result = run(["php", "-l", rel(p)], 60)
        rows.append({"path": rel(p), "check": "php-lint", "ok": result["exit_code"] == 0, **result})
    classes = php_class_index()
    for p in test_exec_files({".php"}):
        args = php_args_for(p, classes)
        text = p.read_text(encoding="utf-8", errors="replace")
        needs_argv1 = bool(re.search(r"\$argv\s*\[\s*1\s*\]", text))
        if needs_argv1 and not args:
            rows.append({"path": rel(p), "check": "php-execute", "ok": False, "exit_code": 125, "command": "UNRESOLVED argv[1]", "stdout_tail": "", "stderr_tail": "test requires argv[1] but no implementation class could be resolved", "seconds": 0})
            continue
        cmd = ["php"]
        strict = ROOT / "tests" / "strict-php-errors.php"
        if p.resolve() != strict.resolve() and strict.exists():
            cmd += ["-d", "auto_prepend_file=tests/strict-php-errors.php"]
        cmd += [rel(p), *args]
        result = run(cmd, 240)
        rows.append({"path": rel(p), "check": "php-execute", "ok": result["exit_code"] == 0, **result})
    write_receipt("php", rows)
    return 1 if any(not r["ok"] for r in rows) else 0


def python_mode() -> int:
    rows: list[dict] = []
    py_files = [p for p in tracked() if p.suffix.lower() == ".py"]
    for p in py_files:
        result = run([sys.executable, "-m", "py_compile", rel(p)], 60)
        rows.append({"path": rel(p), "check": "python-compile", "ok": result["exit_code"] == 0, **result})
    for p in test_exec_files({".py"}):
        result = run([sys.executable, rel(p)], 300)
        rows.append({"path": rel(p), "check": "python-execute", "ok": result["exit_code"] == 0, **result})
    write_receipt("python", rows)
    return 1 if any(not r["ok"] for r in rows) else 0


def node_mode() -> int:
    rows: list[dict] = []
    js_files = [p for p in tracked() if p.suffix.lower() in {".js", ".mjs"}]
    for p in js_files:
        result = run(["node", "--check", rel(p)], 60)
        rows.append({"path": rel(p), "check": "node-check", "ok": result["exit_code"] == 0, **result})
    for p in test_exec_files({".js", ".mjs"}):
        result = run(["node", rel(p)], 300)
        rows.append({"path": rel(p), "check": "node-execute", "ok": result["exit_code"] == 0, **result})
    write_receipt("node", rows)
    return 1 if any(not r["ok"] for r in rows) else 0


def shell_data_mode() -> int:
    rows: list[dict] = []
    files = tracked()
    for p in files:
        suffix = p.suffix.lower()
        rp = rel(p)
        if suffix == ".sh":
            result = run(["bash", "-n", rp], 60)
            rows.append({"path": rp, "check": "bash-parse", "ok": result["exit_code"] == 0, **result})
        elif suffix == ".json" or p.name.endswith(".jsonl") or suffix == ".ndjson":
            try:
                text = p.read_text(encoding="utf-8")
                if p.name.endswith(".jsonl") or suffix == ".ndjson":
                    for i, line in enumerate(text.splitlines(), 1):
                        if line.strip(): json.loads(line)
                else:
                    json.loads(text)
                rows.append({"path": rp, "check": "json-parse", "ok": True, "exit_code": 0, "command": "python json parser", "stdout_tail": "", "stderr_tail": "", "seconds": 0})
            except Exception as exc:
                rows.append({"path": rp, "check": "json-parse", "ok": False, "exit_code": 1, "command": "python json parser", "stdout_tail": "", "stderr_tail": repr(exc), "seconds": 0})
        elif suffix == ".xml":
            try:
                ET.parse(p)
                rows.append({"path": rp, "check": "xml-parse", "ok": True, "exit_code": 0, "command": "python ElementTree", "stdout_tail": "", "stderr_tail": "", "seconds": 0})
            except Exception as exc:
                rows.append({"path": rp, "check": "xml-parse", "ok": False, "exit_code": 1, "command": "python ElementTree", "stdout_tail": "", "stderr_tail": repr(exc), "seconds": 0})
        elif suffix in {".yml", ".yaml"}:
            result = run([sys.executable, "-c", "import sys,yaml; yaml.safe_load(open(sys.argv[1],encoding='utf-8'))", rp], 60)
            rows.append({"path": rp, "check": "yaml-parse", "ok": result["exit_code"] == 0, **result})
        else:
            # Every remaining tracked file is still physically read and hashed.
            # This is not an exclusion: unreadable/truncated repository content
            # fails the accounting gate and every path appears in the receipt.
            try:
                data = p.read_bytes()
                digest = hashlib.sha256(data).hexdigest()
                rows.append({"path": rp, "check": "read-sha256", "ok": True, "exit_code": 0, "command": "read+sha256", "sha256": digest, "bytes": len(data), "stdout_tail": "", "stderr_tail": "", "seconds": 0})
            except Exception as exc:
                rows.append({"path": rp, "check": "read-sha256", "ok": False, "exit_code": 1, "command": "read+sha256", "stdout_tail": "", "stderr_tail": repr(exc), "seconds": 0})
    for p in test_exec_files({".sh"}):
        result = run(["bash", rel(p)], 300)
        rows.append({"path": rel(p), "check": "shell-execute", "ok": result["exit_code"] == 0, **result})
    write_receipt("shell-data", rows)
    return 1 if any(not r["ok"] for r in rows) else 0


def accounting_mode(receipts: list[str]) -> int:
    tracked_rel = {rel(p) for p in tracked()}
    test_exec_rel = {rel(p) for p in test_exec_files(EXEC_EXTS)}
    checked: set[str] = set()
    executed: set[str] = set()
    failed: list[dict] = []
    for name in receipts:
        p = Path(name)
        if not p.is_absolute(): p = ROOT / p
        payload = json.loads(p.read_text(encoding="utf-8"))
        for row in payload.get("rows", []):
            path = str(row.get("path", ""))
            if path: checked.add(path)
            if str(row.get("check", "")).endswith("-execute"):
                executed.add(path)
            if not row.get("ok", False): failed.append(row)
    missing_checked = sorted(tracked_rel - checked)
    missing_executed = sorted(test_exec_rel - executed)
    summary = {
        "schema": "prstudio.run-everything-accounting.v1",
        "tracked_total": len(tracked_rel),
        "checked_total": len(checked & tracked_rel),
        "test_executable_total": len(test_exec_rel),
        "test_executed_total": len(executed & test_exec_rel),
        "missing_checked": missing_checked,
        "missing_executed": missing_executed,
        "failed_checks": failed,
    }
    out = ROOT / "evidence" / "run-everything-accounting.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({k: v for k, v in summary.items() if k not in {"failed_checks"}}, indent=2))
    if missing_checked:
        print("MISSING CHECKED FILES:\n" + "\n".join(missing_checked), file=sys.stderr)
    if missing_executed:
        print("MISSING EXECUTED TEST FILES:\n" + "\n".join(missing_executed), file=sys.stderr)
    if failed:
        print(f"FAILED CHECKS: {len(failed)}", file=sys.stderr)
    return 1 if missing_checked or missing_executed or failed else 0


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=["php", "python", "node", "shell-data", "accounting"])
    ap.add_argument("receipts", nargs="*")
    args = ap.parse_args()
    if args.mode == "php": return php_mode()
    if args.mode == "python": return python_mode()
    if args.mode == "node": return node_mode()
    if args.mode == "shell-data": return shell_data_mode()
    return accounting_mode(args.receipts)


if __name__ == "__main__":
    raise SystemExit(main())
