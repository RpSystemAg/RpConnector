#!/usr/bin/env python3
"""Local read-only Playwright worker for PR STUDIO Browser Runtime."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import signal
import sys
import threading
import time
import traceback
import uuid
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

from PIL import Image, ImageChops
from playwright.sync_api import Browser, BrowserContext, Locator, Page, Playwright, sync_playwright


MAX_JSON_BODY = 4 * 1024 * 1024
MAX_LOG_ITEMS = 1000
MAX_TEXT = 100_000
RISKY_PATTERN = re.compile(
    r"(?:add[-_ ]?to[-_ ]?cart|checkout|ordine|ordina|acquista|purchase|pay|pagamento|"
    r"submit|invia|login|log[-_ ]?in|logout|register|registr|wishlist|preferit|remove|"
    r"delete|elimina|coupon|apply[-_ ]?coupon|place[-_ ]?order)",
    re.IGNORECASE,
)


def now_iso() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def clean_id(value: Any) -> str:
    text = str(value or "").strip()
    return re.sub(r"[^a-zA-Z0-9_-]", "", text)[:100]


def first_value(data: dict[str, Any], *keys: str, default: Any = None) -> Any:
    for key in keys:
        if key in data and data[key] is not None:
            return data[key]
    return default


def flatten_arguments(value: dict[str, Any]) -> dict[str, Any]:
    merged: dict[str, Any] = {}
    for key in ("payload", "params", "body", "query"):
        child = value.get(key)
        if isinstance(child, dict):
            merged.update(child)
    merged.update(value)
    return merged


@dataclass
class PageState:
    page: Page
    context_id: str
    console: list[dict[str, Any]] = field(default_factory=list)
    network: list[dict[str, Any]] = field(default_factory=list)
    errors: list[dict[str, Any]] = field(default_factory=list)


class BrowserManager:
    def __init__(self, chromium_path: str, data_dir: Path, allowed_host: str) -> None:
        self.chromium_path = chromium_path
        self.data_dir = data_dir
        self.artifacts_dir = data_dir / "artifacts"
        self.profiles_dir = data_dir / "profiles"
        self.allowed_host = allowed_host.lower().strip(".")
        self.playwright: Playwright | None = None
        self.browser: Browser | None = None
        self.contexts: dict[str, BrowserContext] = {}
        self.pages: dict[str, PageState] = {}
        self.lock = threading.RLock()
        self.artifacts_dir.mkdir(parents=True, exist_ok=True)
        self.profiles_dir.mkdir(parents=True, exist_ok=True)

    def status(self) -> dict[str, Any]:
        with self.lock:
            return {
                "available": True,
                "provider": "prstudio_browser_runtime",
                "browser_running": self.browser is not None and self.browser.is_connected(),
                "contexts": len(self.contexts),
                "pages": len(self.pages),
                "chromium": self.chromium_path,
                "allowed_host": self.allowed_host,
                "read_only": True,
                "external_worker_required": False,
                "creates_pending_job": False,
                "checked_at": now_iso(),
            }

    def ensure_browser(self) -> Browser:
        with self.lock:
            if self.browser is not None and self.browser.is_connected():
                return self.browser
            if self.playwright is None:
                self.playwright = sync_playwright().start()
            self.browser = self.playwright.chromium.launch(
                executable_path=self.chromium_path,
                headless=True,
                args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
            )
            return self.browser

    def close_all(self) -> None:
        with self.lock:
            for state in list(self.pages.values()):
                try:
                    state.page.close()
                except Exception:
                    pass
            self.pages.clear()
            for context in list(self.contexts.values()):
                try:
                    context.close()
                except Exception:
                    pass
            self.contexts.clear()
            if self.browser is not None:
                try:
                    self.browser.close()
                except Exception:
                    pass
                self.browser = None
            if self.playwright is not None:
                try:
                    self.playwright.stop()
                except Exception:
                    pass
                self.playwright = None

    def _new_context(self, args: dict[str, Any]) -> tuple[str, BrowserContext]:
        browser = self.ensure_browser()
        context_id = clean_id(first_value(args, "context_id", "session_id")) or uuid.uuid4().hex
        width = max(320, min(3840, int(first_value(args, "width", "viewport_width", default=1440))))
        height = max(320, min(4000, int(first_value(args, "height", "viewport_height", default=1000))))
        locale = str(first_value(args, "locale", default="it-IT"))
        color_scheme = str(first_value(args, "color_scheme", "colorScheme", default="light"))
        reduced_motion = str(first_value(args, "reduced_motion", "reducedMotion", default="no-preference"))
        context = browser.new_context(
            viewport={"width": width, "height": height},
            locale=locale,
            timezone_id="Europe/Rome",
            color_scheme=color_scheme if color_scheme in {"light", "dark", "no-preference"} else "light",
            reduced_motion=reduced_motion if reduced_motion in {"reduce", "no-preference"} else "no-preference",
            ignore_https_errors=False,
            service_workers="allow",
        )
        self.contexts[context_id] = context
        return context_id, context

    def _attach_page(self, page: Page, context_id: str, page_id: str | None = None) -> str:
        page_id = clean_id(page_id) or uuid.uuid4().hex
        state = PageState(page=page, context_id=context_id)
        self.pages[page_id] = state

        def add_console(message: Any) -> None:
            state.console.append({
                "type": getattr(message, "type", "log"),
                "text": getattr(message, "text", ""),
                "location": getattr(message, "location", None),
                "at": now_iso(),
            })
            del state.console[:-MAX_LOG_ITEMS]

        def add_page_error(error: Exception) -> None:
            state.errors.append({"type": "pageerror", "message": str(error), "at": now_iso()})
            del state.errors[:-MAX_LOG_ITEMS]

        def add_request_failed(request: Any) -> None:
            state.network.append({
                "event": "requestfailed",
                "url": request.url,
                "method": request.method,
                "resource_type": request.resource_type,
                "failure": request.failure,
                "at": now_iso(),
            })
            del state.network[:-MAX_LOG_ITEMS]

        def add_response(response: Any) -> None:
            if response.status >= 400:
                state.network.append({
                    "event": "response",
                    "url": response.url,
                    "status": response.status,
                    "status_text": response.status_text,
                    "resource_type": response.request.resource_type,
                    "at": now_iso(),
                })
                del state.network[:-MAX_LOG_ITEMS]

        page.on("console", add_console)
        page.on("pageerror", add_page_error)
        page.on("requestfailed", add_request_failed)
        page.on("response", add_response)
        page.set_default_timeout(15_000)
        page.set_default_navigation_timeout(45_000)
        return page_id

    def _get_context(self, args: dict[str, Any]) -> tuple[str, BrowserContext]:
        context_id = clean_id(first_value(args, "context_id", "session_id"))
        if context_id and context_id in self.contexts:
            return context_id, self.contexts[context_id]
        return self._new_context(args)

    def _get_page(self, args: dict[str, Any], create: bool = True) -> tuple[str, PageState]:
        page_id = clean_id(first_value(args, "page_id", "tab_id"))
        if page_id and page_id in self.pages:
            return page_id, self.pages[page_id]
        if not create:
            raise ValueError("page_id non valido o pagina non disponibile")
        context_id, context = self._get_context(args)
        page = context.new_page()
        page_id = self._attach_page(page, context_id, page_id or None)
        return page_id, self.pages[page_id]

    def _same_site_url(self, raw: str, current_url: str = "") -> str:
        raw = raw.strip()
        if raw in {"about:blank", ""}:
            return "about:blank"
        if raw.startswith("/"):
            raw = f"https://{self.allowed_host}{raw}"
        parsed = urlparse(raw)
        if parsed.scheme not in {"http", "https"}:
            raise ValueError("Sono consentite soltanto URL HTTP/HTTPS del sito")
        host = (parsed.hostname or "").lower().strip(".")
        if host not in {self.allowed_host, f"www.{self.allowed_host}"}:
            raise ValueError("Navigazione bloccata: host esterno non consentito")
        return raw

    def _locator(self, page: Page, args: dict[str, Any]) -> Locator:
        role = str(first_value(args, "role", default="")).strip()
        name = first_value(args, "name", "accessible_name")
        text = first_value(args, "text", "label")
        selector = str(first_value(args, "selector", "locator", "css", default="")).strip()
        if role:
            return page.get_by_role(role, name=name if name is not None else None).first
        if text is not None and not selector:
            return page.get_by_text(str(text), exact=bool(first_value(args, "exact", default=False))).first
        if not selector:
            raise ValueError("selector, role/name oppure text è obbligatorio")
        return page.locator(selector).first

    def _click_is_safe(self, locator: Locator) -> tuple[bool, dict[str, Any]]:
        info = locator.evaluate(
            """el => ({
              tag: el.tagName.toLowerCase(),
              type: (el.getAttribute('type') || '').toLowerCase(),
              href: el.href || '',
              action: el.form ? (el.form.action || '') : '',
              method: el.form ? (el.form.method || 'get').toLowerCase() : '',
              text: (el.innerText || el.textContent || '').trim().slice(0,300),
              id: el.id || '',
              cls: typeof el.className === 'string' ? el.className : '',
              role: el.getAttribute('role') || '',
              aria: el.getAttribute('aria-label') || ''
            })"""
        )
        blob = " ".join(str(info.get(k, "")) for k in ("href", "action", "text", "id", "cls", "role", "aria"))
        if info.get("method") == "post" or info.get("type") == "submit" or RISKY_PATTERN.search(blob):
            return False, info
        href = str(info.get("href") or "")
        if href:
            try:
                self._same_site_url(href)
            except ValueError:
                return False, info
        return True, info

    def _artifact(self, suffix: str, data: bytes | str) -> dict[str, Any]:
        filename = f"{uuid.uuid4().hex}.{suffix.lstrip('.')}"
        path = self.artifacts_dir / filename
        if isinstance(data, str):
            path.write_text(data, encoding="utf-8")
        else:
            path.write_bytes(data)
        content = path.read_bytes()
        return {
            "filename": filename,
            "bytes": len(content),
            "sha256": hashlib.sha256(content).hexdigest(),
        }

    def _screenshot(self, args: dict[str, Any], baseline_name: str | None = None) -> dict[str, Any]:
        page_id, state = self._get_page(args)
        page = state.page
        full_page = bool(first_value(args, "full_page", "fullPage", default=True))
        selector = str(first_value(args, "selector", default="")).strip()
        image_type = str(first_value(args, "type", "format", default="png")).lower()
        if image_type not in {"png", "jpeg"}:
            image_type = "png"
        quality = None if image_type == "png" else max(1, min(100, int(first_value(args, "quality", default=85))))
        screenshot_args: dict[str, Any] = {"type": image_type, "animations": "disabled"}
        if quality is not None:
            screenshot_args["quality"] = quality
        if selector:
            raw = self._locator(page, args).screenshot(**screenshot_args)
        else:
            raw = page.screenshot(full_page=full_page, **screenshot_args)
        artifact = self._artifact("jpg" if image_type == "jpeg" else "png", raw)
        if baseline_name:
            safe = re.sub(r"[^a-zA-Z0-9_-]", "-", baseline_name)[:80] or "baseline"
            target = self.artifacts_dir / f"baseline-{safe}.png"
            target.write_bytes(raw)
            artifact["baseline_filename"] = target.name
        size = Image.open(self.artifacts_dir / artifact["filename"]).size
        return {
            "page_id": page_id,
            "url": page.url,
            "title": page.title(),
            "viewport": page.viewport_size,
            "full_page": full_page,
            "image_width": size[0],
            "image_height": size[1],
            "artifact": artifact,
        }

    def execute(self, raw_action: str, raw_args: dict[str, Any]) -> dict[str, Any]:
        with self.lock:
            args = flatten_arguments(raw_args)
            action = raw_action.strip().lower()
            if action.startswith("playwright_"):
                action = action[len("playwright_"):]
            aliases = {
                "launch_chrome": "launch_chromium",
                "connect_browser": "launch_chromium",
                "connect_over_cdp": "launch_chromium",
                "double_click": "dblclick",
                "go_back": "back",
                "go_forward": "forward",
                "accessibility_tree": "accessibility_snapshot",
                "network_log": "network_logs",
                "console_log": "console_logs",
                "create_visual_baseline": "visual_baseline",
            }
            action = aliases.get(action, action)

            if action in {"status", "health"}:
                return self.status()
            if action == "launch_chromium":
                self.ensure_browser()
                return self.status()
            if action == "close_browser":
                self.close_all()
                return {"closed": True, "at": now_iso()}
            if action == "new_context":
                context_id, context = self._new_context(args)
                return {"context_id": context_id, "viewport": first_value(args, "viewport", default=None), "contexts": len(self.contexts)}
            if action == "close_context":
                context_id = clean_id(first_value(args, "context_id", "session_id"))
                context = self.contexts.pop(context_id, None)
                if context is None:
                    raise ValueError("context_id non trovato")
                for page_id in [pid for pid, state in self.pages.items() if state.context_id == context_id]:
                    self.pages.pop(page_id, None)
                context.close()
                return {"closed": True, "context_id": context_id}
            if action == "list_contexts":
                return {"items": [{"context_id": cid, "pages": sum(1 for s in self.pages.values() if s.context_id == cid)} for cid in self.contexts]}
            if action == "new_page":
                page_id, state = self._get_page(args)
                url = str(first_value(args, "url", default="")).strip()
                if url:
                    state.page.goto(self._same_site_url(url), wait_until="domcontentloaded")
                return {"page_id": page_id, "context_id": state.context_id, "url": state.page.url, "title": state.page.title()}
            if action == "close_page":
                page_id = clean_id(first_value(args, "page_id", "tab_id"))
                state = self.pages.pop(page_id, None)
                if state is None:
                    raise ValueError("page_id non trovato")
                state.page.close()
                return {"closed": True, "page_id": page_id}
            if action == "list_pages":
                return {"items": [{"page_id": pid, "context_id": state.context_id, "url": state.page.url, "title": state.page.title()} for pid, state in self.pages.items()]}

            page_id, state = self._get_page(args)
            page = state.page

            if action == "goto":
                url = self._same_site_url(str(first_value(args, "url", "url_or_path", default="/")))
                wait_until = str(first_value(args, "wait_until", "waitUntil", default="domcontentloaded"))
                if wait_until not in {"commit", "domcontentloaded", "load", "networkidle"}:
                    wait_until = "domcontentloaded"
                response = page.goto(url, wait_until=wait_until)
                return {"page_id": page_id, "url": page.url, "title": page.title(), "status": response.status if response else None}
            if action == "reload":
                response = page.reload(wait_until="domcontentloaded")
                return {"page_id": page_id, "url": page.url, "title": page.title(), "status": response.status if response else None}
            if action == "back":
                page.go_back(wait_until="domcontentloaded")
                return {"page_id": page_id, "url": page.url, "title": page.title()}
            if action == "forward":
                page.go_forward(wait_until="domcontentloaded")
                return {"page_id": page_id, "url": page.url, "title": page.title()}
            if action == "wait_for_load_state":
                state_name = str(first_value(args, "state", "load_state", default="networkidle"))
                page.wait_for_load_state(state_name, timeout=int(first_value(args, "timeout", default=30000)))
                return {"waited": True, "state": state_name, "page_id": page_id}
            if action == "wait_for_url":
                url = str(first_value(args, "url", "pattern", default=""))
                page.wait_for_url(url, timeout=int(first_value(args, "timeout", default=30000)))
                return {"waited": True, "url": page.url, "page_id": page_id}
            if action == "wait_for_selector":
                selector = str(first_value(args, "selector", default=""))
                page.wait_for_selector(selector, state=str(first_value(args, "state", default="visible")), timeout=int(first_value(args, "timeout", default=30000)))
                return {"waited": True, "selector": selector, "page_id": page_id}
            if action in {"wait_for_response", "wait_for_request"}:
                raise ValueError("Questa attesa richiede una callback persistente e non è esposta in modalità read-only")

            if action in {"click", "dblclick"}:
                locator = self._locator(page, args)
                safe, info = self._click_is_safe(locator)
                if not safe:
                    raise ValueError("Click bloccato: l'elemento può modificare carrello, account, ordine o dati del sito")
                before = page.url
                if action == "dblclick":
                    locator.dblclick()
                else:
                    locator.click()
                page.wait_for_timeout(int(first_value(args, "settle_ms", default=250)))
                if page.url and page.url != before:
                    self._same_site_url(page.url)
                return {"clicked": True, "page_id": page_id, "url": page.url, "element": info}
            if action == "hover":
                self._locator(page, args).hover()
                return {"hovered": True, "page_id": page_id}
            if action == "focus":
                self._locator(page, args).focus()
                return {"focused": True, "page_id": page_id}
            if action in {"fill", "type"}:
                locator = self._locator(page, args)
                value = str(first_value(args, "value", "text", default=""))[:5000]
                info = locator.evaluate("el => ({type:(el.type||'').toLowerCase(), name:el.name||'', id:el.id||'', cls:typeof el.className==='string'?el.className:''})")
                blob = " ".join(str(v) for v in info.values())
                if RISKY_PATTERN.search(blob) or info.get("type") in {"password", "hidden"}:
                    raise ValueError("Compilazione bloccata su campo sensibile o mutativo")
                if action == "type":
                    locator.press_sequentially(value, delay=max(0, min(500, int(first_value(args, "delay", default=0)))))
                else:
                    locator.fill(value)
                return {"filled": True, "page_id": page_id, "length": len(value)}
            if action == "press":
                key = str(first_value(args, "key", default=""))
                if key.lower() in {"enter", "numpadenter"}:
                    raise ValueError("Invio bloccato in modalità read-only")
                self._locator(page, args).press(key)
                return {"pressed": True, "key": key, "page_id": page_id}
            if action == "select_option":
                values = first_value(args, "values", "value", default=[])
                if not isinstance(values, list):
                    values = [str(values)]
                selected = self._locator(page, args).select_option(values)
                return {"selected": selected, "page_id": page_id}
            if action in {"check", "uncheck"}:
                locator = self._locator(page, args)
                info = locator.evaluate("el => ({name:el.name||'', id:el.id||'', cls:typeof el.className==='string'?el.className:''})")
                if RISKY_PATTERN.search(" ".join(str(v) for v in info.values())):
                    raise ValueError("Controllo bloccato su campo potenzialmente mutativo")
                locator.check() if action == "check" else locator.uncheck()
                return {action + "ed": True, "page_id": page_id}
            if action == "scroll_into_view":
                self._locator(page, args).scroll_into_view_if_needed()
                return {"scrolled": True, "page_id": page_id}

            if action == "screenshot":
                return self._screenshot(args)
            if action == "visual_baseline":
                name = str(first_value(args, "name", "baseline", default=urlparse(page.url).path.strip("/") or "home"))
                return self._screenshot(args, baseline_name=name)
            if action == "visual_diff":
                baseline = str(first_value(args, "baseline_filename", "baseline", default=""))
                if baseline and not baseline.startswith("baseline-"):
                    baseline = "baseline-" + re.sub(r"[^a-zA-Z0-9_-]", "-", baseline)[:80] + ".png"
                baseline_path = self.artifacts_dir / baseline
                if not baseline_path.is_file():
                    raise ValueError("Baseline non trovata")
                current = self._screenshot(args)
                current_path = self.artifacts_dir / current["artifact"]["filename"]
                image_a = Image.open(baseline_path).convert("RGBA")
                image_b = Image.open(current_path).convert("RGBA")
                if image_a.size != image_b.size:
                    canvas = Image.new("RGBA", (max(image_a.width, image_b.width), max(image_a.height, image_b.height)), (255, 255, 255, 0))
                    canvas.paste(image_a, (0, 0))
                    image_a = canvas
                    canvas_b = Image.new("RGBA", image_a.size, (255, 255, 255, 0))
                    canvas_b.paste(image_b, (0, 0))
                    image_b = canvas_b
                diff = ImageChops.difference(image_a, image_b)
                bbox = diff.getbbox()
                changed = 0
                if bbox:
                    changed = sum(1 for pixel in diff.getdata() if pixel != (0, 0, 0, 0))
                total = image_a.width * image_a.height
                diff_bytes_path = self.artifacts_dir / f"{uuid.uuid4().hex}.png"
                diff.save(diff_bytes_path, "PNG")
                artifact = self._artifact("png", diff_bytes_path.read_bytes())
                diff_bytes_path.unlink(missing_ok=True)
                return {
                    "page_id": page_id,
                    "baseline_filename": baseline_path.name,
                    "current": current,
                    "changed_pixels": changed,
                    "total_pixels": total,
                    "difference_percent": round((changed / total * 100) if total else 0.0, 6),
                    "bounding_box": bbox,
                    "artifact": artifact,
                }

            if action in {"dom_snapshot", "content", "page_content"}:
                content = page.content()
                artifact = self._artifact("html", content)
                return {"page_id": page_id, "url": page.url, "title": page.title(), "bytes": len(content.encode("utf-8")), "sha256": hashlib.sha256(content.encode("utf-8")).hexdigest(), "excerpt": content[:MAX_TEXT], "artifact": artifact}
            if action == "accessibility_snapshot":
                tree = page.locator("body").aria_snapshot(timeout=15_000)
                artifact = self._artifact("txt", tree)
                return {"page_id": page_id, "url": page.url, "snapshot": tree[:MAX_TEXT], "artifact": artifact}
            if action in {"computed_style", "computed_styles"}:
                locator = self._locator(page, args)
                properties = first_value(args, "properties", default=[])
                if not isinstance(properties, list) or not properties:
                    properties = ["display", "position", "top", "left", "width", "height", "margin", "padding", "font-family", "font-size", "font-weight", "line-height", "letter-spacing", "color", "background-color", "border", "z-index", "overflow", "transform"]
                styles = locator.evaluate("(el, props) => { const s=getComputedStyle(el); const o={}; for (const p of props) o[p]=s.getPropertyValue(p); return o; }", properties[:100])
                return {"page_id": page_id, "selector": first_value(args, "selector", default=None), "styles": styles}
            if action in {"bounding_box", "box"}:
                box = self._locator(page, args).bounding_box()
                return {"page_id": page_id, "bounding_box": box}
            if action in {"inner_text", "text_content"}:
                locator = self._locator(page, args)
                text = locator.inner_text() if action == "inner_text" else locator.text_content()
                return {"page_id": page_id, "text": (text or "")[:MAX_TEXT]}
            if action == "get_attribute":
                name = str(first_value(args, "attribute", "name", default=""))
                return {"page_id": page_id, "attribute": name, "value": self._locator(page, args).get_attribute(name)}
            if action == "count":
                selector = str(first_value(args, "selector", default=""))
                return {"page_id": page_id, "selector": selector, "count": page.locator(selector).count()}
            if action == "console_logs":
                return {"page_id": page_id, "items": state.console[-max(1, min(MAX_LOG_ITEMS, int(first_value(args, "limit", default=200)))):], "page_errors": state.errors[-200:]}
            if action == "network_logs":
                return {"page_id": page_id, "items": state.network[-max(1, min(MAX_LOG_ITEMS, int(first_value(args, "limit", default=300)))):]}
            if action in {"layout_snapshot", "check_horizontal_overflow", "overflow_scan"}:
                result = page.evaluate(
                    """() => {
                    const vw=document.documentElement.clientWidth;
                    const vh=document.documentElement.clientHeight;
                    const bad=[];
                    for (const el of document.querySelectorAll('body *')) {
                      const r=el.getBoundingClientRect();
                      const s=getComputedStyle(el);
                      if (!r.width || !r.height || s.display==='none' || s.visibility==='hidden') continue;
                      if (r.right > vw + 1 || r.left < -1) bad.push({tag:el.tagName.toLowerCase(),id:el.id||'',class:typeof el.className==='string'?el.className:'',left:r.left,right:r.right,width:r.width,overflowX:s.overflowX});
                      if (bad.length>=300) break;
                    }
                    return {viewport:{width:vw,height:vh},document:{scrollWidth:document.documentElement.scrollWidth,scrollHeight:document.documentElement.scrollHeight},hasHorizontalOverflow:document.documentElement.scrollWidth>vw+1,items:bad};
                    }"""
                )
                return {"page_id": page_id, **result}
            if action in {"broken_images", "check_broken_images"}:
                items = page.evaluate("""() => [...document.images].filter(i => !i.complete || i.naturalWidth===0).slice(0,500).map(i => ({src:i.currentSrc||i.src,alt:i.alt||'',width:i.width,height:i.height}))""")
                return {"page_id": page_id, "count": len(items), "items": items}
            if action in {"list_links", "links"}:
                items = page.evaluate("""() => [...document.querySelectorAll('a[href]')].slice(0,2000).map(a => ({text:(a.innerText||a.textContent||'').trim().slice(0,180),href:a.href,rel:a.rel||'',target:a.target||''}))""")
                internal = [item for item in items if (urlparse(item.get("href", "")).hostname or "").lower() in {self.allowed_host, f"www.{self.allowed_host}"}]
                return {"page_id": page_id, "count": len(items), "internal_count": len(internal), "items": internal[:1000]}
            if action in {"performance_metrics", "metrics"}:
                metrics = page.evaluate("""() => ({navigation: performance.getEntriesByType('navigation').map(n=>({domContentLoaded:n.domContentLoadedEventEnd,loadEventEnd:n.loadEventEnd,responseEnd:n.responseEnd,transferSize:n.transferSize,encodedBodySize:n.encodedBodySize}))[0]||null,resources:performance.getEntriesByType('resource').length,domNodes:document.getElementsByTagName('*').length,scrollWidth:document.documentElement.scrollWidth,scrollHeight:document.documentElement.scrollHeight})""")
                return {"page_id": page_id, **metrics}
            if action in {"audit_page", "audit"}:
                overflow = self.execute("check_horizontal_overflow", {**args, "page_id": page_id})
                broken = self.execute("check_broken_images", {**args, "page_id": page_id})
                metrics = self.execute("performance_metrics", {**args, "page_id": page_id})
                screenshot = self._screenshot({**args, "page_id": page_id})
                return {"page_id": page_id, "url": page.url, "title": page.title(), "overflow": overflow, "broken_images": broken, "metrics": metrics, "console_errors": state.errors[-100:], "network_failures": state.network[-300:], "screenshot": screenshot}
            if action in {"storage_snapshot", "cookies"}:
                cookies = self.contexts[state.context_id].cookies()
                storage = page.evaluate("() => ({localStorage:{...localStorage},sessionStorage:{...sessionStorage}})")
                for cookie in cookies:
                    cookie.pop("value", None)
                return {"page_id": page_id, "cookies_metadata": cookies, "storage_keys": {"localStorage": list(storage["localStorage"].keys()), "sessionStorage": list(storage["sessionStorage"].keys())}}

            if action in {"evaluate", "evaluate_handle", "route", "intercept", "upload_file", "set_input_files", "download", "submit", "keyboard_type"}:
                raise ValueError("Azione non esposta per ragioni di sicurezza")
            raise ValueError(f"Azione Playwright non supportata dal runtime: {action}")


class RuntimeServer(HTTPServer):
    allow_reuse_address = True

    def __init__(self, address: tuple[str, int], handler: type[BaseHTTPRequestHandler], manager: BrowserManager, secret: str) -> None:
        super().__init__(address, handler)
        self.manager = manager
        self.secret = secret.encode("utf-8")


class Handler(BaseHTTPRequestHandler):
    server: RuntimeServer

    def log_message(self, fmt: str, *args: Any) -> None:
        sys.stderr.write("[%s] %s\n" % (now_iso(), fmt % args))

    def _authorized(self) -> bool:
        supplied = self.headers.get("X-PRStudio-Secret", "").encode("utf-8")
        return bool(supplied) and hmac.compare_digest(supplied, self.server.secret)

    def _json(self, status: int, value: dict[str, Any]) -> None:
        raw = json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self) -> None:  # noqa: N802
        if not self._authorized():
            self._json(403, {"ok": False, "error": "forbidden"})
            return
        if self.path.split("?", 1)[0] == "/health":
            self._json(200, {"ok": True, "result": self.server.manager.status()})
            return
        self._json(404, {"ok": False, "error": "not_found"})

    def do_POST(self) -> None:  # noqa: N802
        if not self._authorized():
            self._json(403, {"ok": False, "error": "forbidden"})
            return
        length = int(self.headers.get("Content-Length", "0") or 0)
        if length < 0 or length > MAX_JSON_BODY:
            self._json(413, {"ok": False, "error": "payload_too_large"})
            return
        try:
            body = json.loads(self.rfile.read(length).decode("utf-8") or "{}")
        except Exception:
            self._json(400, {"ok": False, "error": "invalid_json"})
            return
        path = self.path.split("?", 1)[0]
        if path == "/shutdown":
            self._json(200, {"ok": True, "result": {"shutdown": True}})
            threading.Thread(target=self.server.shutdown, daemon=True).start()
            return
        if path != "/v1/action":
            self._json(404, {"ok": False, "error": "not_found"})
            return
        try:
            action = str(body.get("action", ""))
            arguments = body.get("arguments") if isinstance(body.get("arguments"), dict) else {}
            result = self.server.manager.execute(action, arguments)
            self._json(200, {"ok": True, "result": result})
        except Exception as exc:
            self._json(400, {"ok": False, "error": str(exc), "error_type": type(exc).__name__})


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--secret", required=True)
    parser.add_argument("--data-dir", required=True)
    parser.add_argument("--chromium", required=True)
    parser.add_argument("--allowed-host", required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    data_dir = Path(args.data_dir).resolve()
    data_dir.mkdir(parents=True, exist_ok=True)
    manager = BrowserManager(args.chromium, data_dir, args.allowed_host)
    server = RuntimeServer((args.host, args.port), Handler, manager, args.secret)

    def stop(_signum: int, _frame: Any) -> None:
        threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    try:
        server.serve_forever(poll_interval=0.25)
    finally:
        manager.close_all()
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
