#!/usr/bin/env python3
"""Fail-closed repository syntax + real test-surface execution gate.

There is no exception table, helper classification, baseline, waiver or advisory
mode in this gate. The denominator is discovered from the exact checkout at
runtime. Every tracked source/data file receives its required parser/compiler
check. Every tracked file under tests/ and prstudio-unified-browser-agent/tests/
must additionally be proven by a successful runtime execution record.

Code files are invoked directly by their runtime and require syscall evidence
that the exact candidate file was executed/opened by that successful process.
Data files are counted as runtime-executed only when a successful traced test
process actually opens the exact file; merely parsing the file in the syntax
phase does not satisfy the execution requirement.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import py_compile
import shutil
import subprocess
import sys
import tempfile
import time
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    import yaml  # type: ignore
except Exception as exc:  # pragma: no cover - CI must install the parser.
    raise SystemExit(f"PyYAML is required for the YAML parser gate: {exc}")

ROOT = Path(__file__).resolve().parents[2]
TEST_ROOTS = (Path("tests"), Path("prstudio-unified-browser-agent/tests"))
CODE_SUFFIXES = {".php", ".py", ".js", ".mjs", ".cjs", ".sh", ".bash"}
DATA_SUFFIXES = {".json", ".yaml", ".yml", ".xml"}
SYNTAX_SUFFIXES = CODE_SUFFIXES | DATA_SUFFIXES
DEFAULT_TIMEOUT_SECONDS = 180


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=ROOT, text=True).strip()


def tracked_files() -> list[Path]:
    raw = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT)
    names = [item.decode("utf-8") for item in raw.split(b"\0") if item]
    return [Path(name) for name in names]


def is_test_surface(path: Path) -> bool:
    text = path.as_posix()
    return any(text == root.as_posix() or text.startswith(root.as_posix() + "/") for root in TEST_ROOTS)


def source_kind(rel: Path) -> str | None:
    """Return the parser/runtime kind, including extensionless shebang scripts."""
    suffix = rel.suffix.lower()
    if suffix in SYNTAX_SUFFIXES:
        return suffix
    path = ROOT / rel
    try:
        first = path.open("rb").readline(512).decode("utf-8", errors="ignore").strip().lower()
    except OSError:
        return None
    if not first.startswith("#!"):
        return None
    if "python" in first:
        return ".py"
    if "node" in first or "deno" in first:
        return ".js"
    if "php" in first:
        return ".php"
    if "bash" in first or first.endswith("/sh") or " /sh" in first:
        return ".sh"
    return None


def run_command(command: list[str], *, timeout: int, env: dict[str, str] | None = None, trace: Path | None = None) -> dict[str, Any]:
    effective = list(command)
    if trace is not None:
        effective = ["strace", "-f", "-qq", "-e", "trace=execve,openat,chdir", "-s", "4096", "-o", str(trace), "--", *command]
    started = time.monotonic()
    try:
        proc = subprocess.run(
            effective,
            cwd=ROOT,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
        )
        return {
            "command": command,
            "returncode": proc.returncode,
            "duration_seconds": round(time.monotonic() - started, 3),
            "output_tail": proc.stdout[-12000:],
            "ok": proc.returncode == 0,
        }
    except subprocess.TimeoutExpired as exc:
        output = exc.stdout if isinstance(exc.stdout, str) else ""
        return {
            "command": command,
            "returncode": None,
            "duration_seconds": round(time.monotonic() - started, 3),
            "output_tail": output[-12000:],
            "timeout": True,
            "ok": False,
        }
    except Exception as exc:
        return {
            "command": command,
            "returncode": None,
            "duration_seconds": round(time.monotonic() - started, 3),
            "error": f"{type(exc).__name__}: {exc}",
            "ok": False,
        }


def syntax_check(rel: Path, pycache: Path) -> dict[str, Any]:
    path = ROOT / rel
    kind = source_kind(rel)
    started = time.monotonic()
    try:
        if kind == ".php":
            result = run_command(["php", "-l", rel.as_posix()], timeout=60)
            parser = "php -l"
            ok = bool(result["ok"])
            detail = result.get("output_tail", "")
        elif kind in {".js", ".mjs", ".cjs"}:
            result = run_command(["node", "--check", rel.as_posix()], timeout=60)
            parser = "node --check"
            ok = bool(result["ok"])
            detail = result.get("output_tail", "")
        elif kind == ".py":
            target = pycache / (hashlib.sha256(rel.as_posix().encode()).hexdigest() + ".pyc")
            py_compile.compile(str(path), cfile=str(target), dfile=rel.as_posix(), doraise=True)
            parser = "python py_compile"
            ok = True
            detail = "compiled"
        elif kind in {".sh", ".bash"}:
            result = run_command(["bash", "-n", rel.as_posix()], timeout=60)
            parser = "bash -n"
            ok = bool(result["ok"])
            detail = result.get("output_tail", "")
        elif kind == ".json":
            with path.open("r", encoding="utf-8") as handle:
                json.load(handle)
            parser = "python json"
            ok = True
            detail = "parsed"
        elif kind in {".yaml", ".yml"}:
            with path.open("r", encoding="utf-8") as handle:
                list(yaml.safe_load_all(handle))
            parser = "PyYAML safe_load_all"
            ok = True
            detail = "parsed"
        elif kind == ".xml":
            ET.parse(path)
            parser = "python ElementTree"
            ok = True
            detail = "parsed"
        else:
            raise AssertionError(f"syntax_check called for unsupported source {rel}")
    except Exception as exc:
        parser = {
            ".php": "php -l",
            ".js": "node --check",
            ".mjs": "node --check",
            ".cjs": "node --check",
            ".py": "python py_compile",
            ".sh": "bash -n",
            ".bash": "bash -n",
            ".json": "python json",
            ".yaml": "PyYAML safe_load_all",
            ".yml": "PyYAML safe_load_all",
            ".xml": "python ElementTree",
        }.get(kind or "", "unknown")
        ok = False
        detail = f"{type(exc).__name__}: {exc}"
    return {
        "parser": parser,
        "ok": ok,
        "duration_seconds": round(time.monotonic() - started, 3),
        "detail": detail[-12000:],
    }


def execution_command(rel: Path) -> list[str] | None:
    kind = source_kind(rel)
    name = rel.name
    if kind == ".php":
        return ["php", "-d", "auto_prepend_file=tests/strict-php-errors.php", "-f", rel.as_posix()]
    if kind == ".py":
        return [sys.executable, rel.as_posix()]
    if kind in {".js", ".mjs", ".cjs"}:
        if ".test." in name or rel.as_posix().startswith("prstudio-unified-browser-agent/tests/"):
            return ["node", "--test", rel.as_posix()]
        return ["node", rel.as_posix()]
    if kind in {".sh", ".bash"}:
        return ["bash", rel.as_posix()]
    return None


def successful_runtime_evidence(trace_text: str, rel: Path) -> str | None:
    rel_text = rel.as_posix()
    abs_text = str((ROOT / rel).resolve())
    candidates = (f'"{rel_text}"', f'"{abs_text}"')
    for line in trace_text.splitlines():
        if " = -1 " in line:
            continue
        if ("execve(" in line or "openat(" in line) and any(candidate in line for candidate in candidates):
            return line[-2000:]
    return None


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--registry", default="evidence/full-surface-execution.json")
    parser.add_argument("--timeout-seconds", type=int, default=DEFAULT_TIMEOUT_SECONDS)
    args = parser.parse_args()

    if shutil.which("strace") is None:
        raise SystemExit("strace is mandatory: runtime execution cannot be certified without syscall evidence")

    all_tracked = tracked_files()
    syntax_targets = [p for p in all_tracked if source_kind(p) is not None]
    surface = [p for p in all_tracked if is_test_surface(p)]
    if not surface:
        raise SystemExit("test surface is empty")

    registry_path = ROOT / args.registry
    registry_path.parent.mkdir(parents=True, exist_ok=True)
    commit_sha = git("rev-parse", "HEAD")
    env = os.environ.copy()
    env.update(
        {
            "CI": "1",
            "PRSTUDIO_FULL_SURFACE_EXECUTION": "1",
            "PRSTUDIO_EXECUTION_REGISTRY_COMMIT": commit_sha,
            "PYTHONDONTWRITEBYTECODE": "1",
        }
    )

    syntax_records: dict[str, Any] = {}
    execution_records: dict[str, Any] = {}
    trace_texts: list[str] = []
    failures: list[str] = []

    with tempfile.TemporaryDirectory(prefix="rp-full-surface-") as tmp:
        temp = Path(tmp)
        pycache = temp / "pycache"
        pycache.mkdir()

        for rel in syntax_targets:
            record = syntax_check(rel, pycache)
            syntax_records[rel.as_posix()] = record
            if not record["ok"]:
                failures.append(f"syntax:{rel.as_posix()}: {record['detail']}")

        direct_targets = [p for p in surface if execution_command(p) is not None]
        for index, rel in enumerate(direct_targets):
            command = execution_command(rel)
            assert command is not None
            trace = temp / f"trace-{index:04d}.log"
            result = run_command(command, timeout=args.timeout_seconds, env=env, trace=trace)
            trace_text = trace.read_text(encoding="utf-8", errors="replace") if trace.exists() else ""
            trace_texts.append(trace_text)
            evidence = successful_runtime_evidence(trace_text, rel)
            result["sha256"] = sha256(ROOT / rel)
            result["mode"] = "direct-runtime"
            result["trace_evidence"] = evidence
            if result["ok"] and evidence is None:
                result["ok"] = False
                result["evidence_error"] = "successful process had no syscall evidence for the exact file"
            execution_records[rel.as_posix()] = result
            if not result["ok"]:
                failures.append(
                    f"execution:{rel.as_posix()}: rc={result.get('returncode')} timeout={result.get('timeout', False)} "
                    f"evidence={bool(evidence)} output={result.get('output_tail', '')[-3000:]}"
                )

        combined_trace = "\n".join(trace_texts)
        for rel in surface:
            key = rel.as_posix()
            if key in execution_records:
                continue
            evidence = successful_runtime_evidence(combined_trace, rel)
            execution_records[key] = {
                "sha256": sha256(ROOT / rel),
                "mode": "runtime-consumed-data",
                "ok": evidence is not None,
                "trace_evidence": evidence,
            }
            if evidence is None:
                failures.append(f"execution:{key}: file was never successfully opened by any successful traced test process")

    syntax_ok = sum(1 for record in syntax_records.values() if record["ok"])
    executed_ok = sum(1 for record in execution_records.values() if record["ok"])
    total_surface = len(surface)
    coverage = (executed_ok * 100.0 / total_surface) if total_surface else 0.0
    exact_100 = executed_ok == total_surface

    registry = {
        "schema_version": 2,
        "gate_id": "full_surface_execution_100_percent",
        "generated_at": utc_now(),
        "commit_sha": commit_sha,
        "requirements": {
            "no_exception_table": True,
            "no_helper_classification": True,
            "no_baseline": True,
            "syntax_target_source": "git ls-files + recognized shebangs",
            "test_execution_target_source": [root.as_posix() for root in TEST_ROOTS],
            "parse_does_not_count_as_execution": True,
            "direct_execution_requires_syscall_evidence": True,
            "required_execution_percent": 100.0,
        },
        "counts": {
            "tracked_files": len(all_tracked),
            "syntax_targets": len(syntax_targets),
            "syntax_passed": syntax_ok,
            "total_test_surface_files": total_surface,
            "real_executed_files": executed_ok,
            "execution_percent": round(coverage, 6),
        },
        "syntax": syntax_records,
        "execution": execution_records,
        "failures": failures,
        "ok": not failures and syntax_ok == len(syntax_targets) and exact_100,
    }
    registry_path.write_text(json.dumps(registry, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print(
        "FULL_SURFACE_EXECUTION "
        f"syntax={syntax_ok}/{len(syntax_targets)} "
        f"executed={executed_ok}/{total_surface} "
        f"coverage={coverage:.6f}%"
    )
    if failures:
        for item in failures:
            print("FAIL", item, file=sys.stderr)
    if not registry["ok"]:
        return 1
    print("FULL_SURFACE_EXECUTION: PASS 100%")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
