#!/usr/bin/env python3
"""Read-only multi-viewport capture and deterministic visual comparison."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import statistics
import sys
import threading
import time
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Iterator
from urllib.parse import urljoin, urlparse

from PIL import Image, ImageChops
from playwright.sync_api import Browser, Page, Playwright, sync_playwright


ROOT = Path(__file__).resolve().parent
REQUIRED_VIEWPORTS = {
    "mobile": (360, 800),
    "mobile-large": (430, 932),
    "tablet": (768, 1024),
    "desktop": (1440, 1000),
    "desktop-wide": (1920, 1080),
}
REQUIRED_SURFACES = {"home", "shop", "product", "cart", "checkout", "account"}
VITALS_SCRIPT = r"""
(() => {
  window.__prstudioVitals = { lcp: null, cls: 0 };
  try { new PerformanceObserver(list => {
    const entries = list.getEntries();
    if (entries.length) window.__prstudioVitals.lcp = entries[entries.length - 1].startTime;
  }).observe({type:'largest-contentful-paint', buffered:true}); } catch (_) {}
  try { new PerformanceObserver(list => {
    for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.__prstudioVitals.cls += entry.value;
  }).observe({type:'layout-shift', buffered:true}); } catch (_) {}
})();
"""


class VisualError(RuntimeError):
    pass


def canonical(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def load_config(path: Path) -> dict[str, Any]:
    try:
        config = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise VisualError(f"invalid config: {exc}") from exc
    if not isinstance(config, dict):
        raise VisualError("config must be a JSON object")
    if int(config.get("metric_runs", 0)) < 5:
        raise VisualError("metric_runs must be at least 5")
    viewports = {
        str(item.get("name")): (int(item.get("width", 0)), int(item.get("height", 0)))
        for item in config.get("viewports", []) if isinstance(item, dict)
    }
    if viewports != REQUIRED_VIEWPORTS:
        raise VisualError(f"viewports must be exactly {REQUIRED_VIEWPORTS}")
    surfaces = [item for item in config.get("surfaces", []) if isinstance(item, dict)]
    names = {str(item.get("name")) for item in surfaces}
    if names != REQUIRED_SURFACES or len(surfaces) != len(REQUIRED_SURFACES):
        raise VisualError(f"surfaces must be exactly {sorted(REQUIRED_SURFACES)}")
    for surface in surfaces:
        if not str(surface.get("path", "")).startswith("/"):
            raise VisualError(f"surface path must be root-relative: {surface.get('name')}")
    return config


class FixtureHandler(BaseHTTPRequestHandler):
    fixture = (ROOT / "fixture" / "index.html").read_bytes()

    def log_message(self, _format: str, *_args: Any) -> None:
        return

    def do_GET(self) -> None:  # noqa: N802
        if self.path == "/favicon.ico":
            self.send_response(204)
            self.end_headers()
            return
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(self.fixture)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(self.fixture)


@contextmanager
def resolved_base_url(value: str) -> Iterator[str]:
    if value != "fixture://local":
        parsed = urlparse(value)
        if parsed.scheme not in {"http", "https"} or not parsed.hostname:
            raise VisualError("base_url must be http(s) or fixture://local")
        yield value.rstrip("/") + "/"
        return
    server = ThreadingHTTPServer(("127.0.0.1", 0), FixtureHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{server.server_address[1]}/"
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)


def same_origin_url(base_url: str, path: str) -> str:
    target = urljoin(base_url, path.lstrip("/"))
    base = urlparse(base_url)
    parsed = urlparse(target)
    if (parsed.scheme, parsed.hostname, parsed.port) != (base.scheme, base.hostname, base.port):
        raise VisualError(f"surface escapes base origin: {path}")
    return target


def page_metrics(page: Page) -> dict[str, Any]:
    return page.evaluate(
        """() => {
          const nav = performance.getEntriesByType('navigation')[0];
          const paints = Object.fromEntries(performance.getEntriesByType('paint').map(e => [e.name, e.startTime]));
          const v = window.__prstudioVitals || {lcp:null,cls:null};
          return {
            ttfb_ms: nav ? nav.responseStart : null,
            dom_content_loaded_ms: nav ? nav.domContentLoadedEventEnd : null,
            load_ms: nav ? nav.loadEventEnd : null,
            transfer_size: nav ? nav.transferSize : null,
            fcp_ms: paints['first-contentful-paint'] ?? null,
            lcp_ms: v.lcp,
            cls: v.cls,
            inp_ms: null,
            inp_note: 'not measured: the read-only visual run performs no representative user interaction',
            dom_nodes: document.getElementsByTagName('*').length
          };
        }"""
    )


def layout_snapshot(page: Page) -> dict[str, Any]:
    return page.evaluate(
        """() => {
          const root=document.documentElement, vw=root.clientWidth;
          const overflow=[];
          for (const el of document.querySelectorAll('body *')) {
            const r=el.getBoundingClientRect(), s=getComputedStyle(el);
            if (!r.width || !r.height || s.display==='none' || s.visibility==='hidden') continue;
            if (r.right > vw + 1 || r.left < -1) overflow.push({tag:el.tagName,id:el.id||'',class:String(el.className||''),left:r.left,right:r.right});
            if (overflow.length >= 100) break;
          }
          return {
            viewport:{width:root.clientWidth,height:root.clientHeight},
            document:{width:root.scrollWidth,height:root.scrollHeight},
            horizontal_overflow:root.scrollWidth > root.clientWidth + 1,
            overflow_items:overflow,
            broken_images:[...document.images].filter(i=>!i.complete||i.naturalWidth===0).map(i=>i.currentSrc||i.src).slice(0,100)
          };
        }"""
    )


def medians(samples: list[dict[str, Any]]) -> dict[str, float | None]:
    result: dict[str, float | None] = {}
    keys = sorted({key for sample in samples for key, value in sample.items() if isinstance(value, (int, float)) and not isinstance(value, bool)})
    for key in keys:
        values = [float(sample[key]) for sample in samples if isinstance(sample.get(key), (int, float)) and not isinstance(sample.get(key), bool)]
        result[key] = round(float(statistics.median(values)), 6) if values else None
    return result


def safe_name(value: str) -> str:
    cleaned = "".join(character if character.isalnum() or character in "-_" else "-" for character in value)
    return cleaned.strip("-")[:80] or "item"


def attach_observers(page: Page, events: dict[str, list[dict[str, Any]]], phase: list[str]) -> None:
    page.on("console", lambda message: events["console"].append({"phase": phase[0], "type": message.type, "text": message.text}) if message.type in {"error", "warning"} else None)
    page.on("pageerror", lambda error: events["page_errors"].append({"phase": phase[0], "message": str(error)}))
    page.on("requestfailed", lambda request: events["network"].append({"phase": phase[0], "event": "requestfailed", "method": request.method, "url": request.url, "failure": request.failure}))
    page.on("response", lambda response: events["network"].append({"phase": phase[0], "event": "response", "status": response.status, "url": response.url}) if response.status >= 400 else None)


def capture(config_path: Path, output: Path, chromium: str | None) -> int:
    config = load_config(config_path)
    if output.exists():
        raise VisualError(f"output already exists: {output}")
    output.mkdir(parents=True)
    screenshots = output / "screenshots"
    screenshots.mkdir()
    config_hash = hashlib.sha256(canonical(config)).hexdigest()
    manifest: dict[str, Any] = {
        "schema_version": "1.0.0",
        "kind": "prstudio_visual_matrix",
        "created_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "config_sha256": config_hash,
        "read_only": bool(config.get("read_only", True)),
        "metric_runs": int(config["metric_runs"]),
        "required_viewports": REQUIRED_VIEWPORTS,
        "results": [],
        "production_proven": False,
    }
    failures: list[str] = []
    with resolved_base_url(str(config["base_url"])) as base_url, sync_playwright() as playwright:
        launch_args: dict[str, Any] = {"headless": True, "args": ["--no-sandbox", "--disable-dev-shm-usage"]}
        if chromium:
            launch_args["executable_path"] = str(Path(chromium).resolve())
        browser = playwright.chromium.launch(**launch_args)
        try:
            for surface in config["surfaces"]:
                target = same_origin_url(base_url, str(surface["path"]))
                for viewport in config["viewports"]:
                    cold_samples: list[dict[str, Any]] = []
                    warm_samples: list[dict[str, Any]] = []
                    events: dict[str, list[dict[str, Any]]] = {"console": [], "page_errors": [], "network": [], "blocked_mutations": []}
                    layout: dict[str, Any] | None = None
                    artifacts: list[dict[str, Any]] = []
                    for run_index in range(int(config["metric_runs"])):
                        context = browser.new_context(
                            viewport={"width": int(viewport["width"]), "height": int(viewport["height"])},
                            locale="it-IT",
                            timezone_id="Europe/Rome",
                            color_scheme="light",
                            reduced_motion="reduce",
                            service_workers="allow",
                        )
                        phase = [f"cold-{run_index + 1}"]
                        page = context.new_page()
                        page.set_default_navigation_timeout(int(config.get("navigation_timeout_ms", 45000)))
                        page.add_init_script(VITALS_SCRIPT)
                        attach_observers(page, events, phase)
                        if bool(config.get("read_only", True)):
                            def route_read_only(route: Any) -> None:
                                method = route.request.method.upper()
                                if method not in {"GET", "HEAD", "OPTIONS"}:
                                    events["blocked_mutations"].append({"phase": phase[0], "method": method, "url": route.request.url})
                                    route.abort("blockedbyclient")
                                else:
                                    route.continue_()
                            page.route("**/*", route_read_only)
                        response = page.goto(target, wait_until="load")
                        page.wait_for_timeout(int(config.get("settle_ms", 1000)))
                        if response is None or response.status >= 400:
                            failures.append(f"{surface['name']}/{viewport['name']}: navigation status {response.status if response else 'none'}")
                        cold_samples.append(page_metrics(page))
                        if run_index == 0:
                            mask = [page.locator(selector) for selector in config.get("mask_selectors", []) if page.locator(selector).count()]
                            prefix = f"{safe_name(surface['name'])}--{safe_name(viewport['name'])}"
                            viewport_path = screenshots / f"{prefix}--viewport.png"
                            page.screenshot(path=str(viewport_path), full_page=False, animations="disabled", mask=mask)
                            artifacts.append({"kind": "viewport", "path": viewport_path.relative_to(output).as_posix(), "sha256": sha256_file(viewport_path)})
                            if bool(surface.get("full_page", False)):
                                full_path = screenshots / f"{prefix}--full-page.png"
                                page.screenshot(path=str(full_path), full_page=True, animations="disabled", mask=mask)
                                artifacts.append({"kind": "full_page", "path": full_path.relative_to(output).as_posix(), "sha256": sha256_file(full_path)})
                            for component in surface.get("components", []):
                                locator = page.locator(str(component["selector"])).first
                                if locator.count() == 0 or not locator.is_visible():
                                    if bool(component.get("required", False)):
                                        failures.append(f"{surface['name']}/{viewport['name']}: missing component {component['name']}")
                                    continue
                                component_path = screenshots / f"{prefix}--component-{safe_name(component['name'])}.png"
                                locator.screenshot(path=str(component_path), animations="disabled")
                                artifacts.append({"kind": "component", "name": component["name"], "selector": component["selector"], "path": component_path.relative_to(output).as_posix(), "sha256": sha256_file(component_path)})
                            layout = layout_snapshot(page)
                        phase[0] = f"warm-{run_index + 1}"
                        page.reload(wait_until="load")
                        page.wait_for_timeout(int(config.get("settle_ms", 1000)))
                        warm_samples.append(page_metrics(page))
                        context.close()
                    assert layout is not None
                    if layout.get("horizontal_overflow"):
                        failures.append(f"{surface['name']}/{viewport['name']}: horizontal overflow")
                    if layout.get("broken_images"):
                        failures.append(f"{surface['name']}/{viewport['name']}: broken images")
                    manifest["results"].append({
                        "surface": surface["name"],
                        "path": surface["path"],
                        "viewport": viewport,
                        "responsive_hash": hashlib.sha256(canonical({"layout": layout, "artifacts": [item["sha256"] for item in artifacts]})).hexdigest(),
                        "layout": layout,
                        "cold_samples": cold_samples,
                        "cold_median": medians(cold_samples),
                        "warm_samples": warm_samples,
                        "warm_median": medians(warm_samples),
                        "events": events,
                        "artifacts": artifacts,
                    })
        finally:
            browser.close()
    manifest["failures"] = failures
    manifest["ok"] = not failures
    (output / "manifest.json").write_bytes(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True).encode("utf-8") + b"\n")
    print(json.dumps({"ok": not failures, "manifest": str((output / 'manifest.json').resolve()), "captures": len(manifest["results"]), "failures": failures}, ensure_ascii=False))
    return 0 if not failures else 1


def pixel_difference(baseline: Path, candidate: Path, diff_path: Path) -> tuple[float, bool]:
    image_a = Image.open(baseline).convert("RGBA")
    image_b = Image.open(candidate).convert("RGBA")
    size_changed = image_a.size != image_b.size
    if size_changed:
        width, height = max(image_a.width, image_b.width), max(image_a.height, image_b.height)
        padded_a = Image.new("RGBA", (width, height), (255, 255, 255, 0))
        padded_b = Image.new("RGBA", (width, height), (255, 255, 255, 0))
        padded_a.paste(image_a, (0, 0)); padded_b.paste(image_b, (0, 0))
        image_a, image_b = padded_a, padded_b
    diff = ImageChops.difference(image_a, image_b)
    grayscale = diff.convert("L")
    pixels = grayscale.get_flattened_data() if hasattr(grayscale, "get_flattened_data") else grayscale.getdata()
    changed = sum(1 for value in pixels if value > 16)
    total = image_a.width * image_a.height
    diff_path.parent.mkdir(parents=True, exist_ok=True)
    diff.save(diff_path, "PNG")
    return round(changed / max(1, total) * 100, 8), size_changed


def compare(config_path: Path, baseline_dir: Path, candidate_dir: Path) -> int:
    config = load_config(config_path)
    baseline = json.loads((baseline_dir / "manifest.json").read_text(encoding="utf-8"))
    candidate = json.loads((candidate_dir / "manifest.json").read_text(encoding="utf-8"))
    expected_hash = hashlib.sha256(canonical(config)).hexdigest()
    if baseline.get("config_sha256") != expected_hash or candidate.get("config_sha256") != expected_hash:
        raise VisualError("baseline/candidate config hash mismatch")
    baseline_items = {(item["surface"], item["viewport"]["name"]): item for item in baseline["results"]}
    candidate_items = {(item["surface"], item["viewport"]["name"]): item for item in candidate["results"]}
    if baseline_items.keys() != candidate_items.keys():
        raise VisualError("baseline/candidate surface matrix mismatch")
    threshold = float(config.get("max_difference_percent", 0.1))
    comparisons: list[dict[str, Any]] = []
    failures: list[str] = []
    for key in sorted(baseline_items):
        old = baseline_items[key]
        new = candidate_items[key]
        old_artifacts = {(item["kind"], item.get("name")): item for item in old["artifacts"]}
        new_artifacts = {(item["kind"], item.get("name")): item for item in new["artifacts"]}
        if old_artifacts.keys() != new_artifacts.keys():
            failures.append(f"{key}: artifact set changed")
            continue
        for artifact_key in sorted(old_artifacts, key=str):
            old_path = baseline_dir / old_artifacts[artifact_key]["path"]
            new_path = candidate_dir / new_artifacts[artifact_key]["path"]
            diff_name = f"{safe_name(key[0])}--{safe_name(key[1])}--{safe_name(str(artifact_key[0]))}--{safe_name(str(artifact_key[1] or 'page'))}.png"
            percent, size_changed = pixel_difference(old_path, new_path, candidate_dir / "diffs" / diff_name)
            passed = percent <= threshold and not size_changed
            comparisons.append({"surface": key[0], "viewport": key[1], "artifact": artifact_key, "difference_percent": percent, "size_changed": size_changed, "passed": passed, "diff": f"diffs/{diff_name}"})
            if not passed:
                failures.append(f"{key}/{artifact_key}: {percent}% changed, size_changed={size_changed}")
    report = {"schema_version": "1.0.0", "kind": "prstudio_visual_comparison", "threshold_percent": threshold, "comparisons": comparisons, "failures": failures, "ok": not failures, "production_proven": False}
    report_path = candidate_dir / "comparison.json"
    report_path.write_bytes(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True).encode("utf-8") + b"\n")
    print(json.dumps({"ok": not failures, "report": str(report_path.resolve()), "comparisons": len(comparisons), "failures": failures}, ensure_ascii=False))
    return 0 if not failures else 1


def main() -> int:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)
    capture_parser = subparsers.add_parser("capture")
    capture_parser.add_argument("--config", type=Path, required=True)
    capture_parser.add_argument("--output", type=Path, required=True)
    capture_parser.add_argument("--chromium", default=os.environ.get("PRSTUDIO_CHROMIUM"))
    compare_parser = subparsers.add_parser("compare")
    compare_parser.add_argument("--config", type=Path, required=True)
    compare_parser.add_argument("--baseline", type=Path, required=True)
    compare_parser.add_argument("--candidate", type=Path, required=True)
    args = parser.parse_args()
    if args.command == "capture":
        return capture(args.config.resolve(), args.output.resolve(), args.chromium)
    return compare(args.config.resolve(), args.baseline.resolve(), args.candidate.resolve())


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except VisualError as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False), file=sys.stderr)
        raise SystemExit(2)
