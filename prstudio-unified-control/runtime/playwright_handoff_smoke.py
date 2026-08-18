#!/usr/bin/env python3
"""Smoke test delle challenge auth visibili e dei falsi positivi CAPTCHA.

Non accede ad account reali e non tenta di risolvere CAPTCHA. Senza URL crea
una pagina locale con un iframe reCAPTCHA invisibile (da ignorare), poi una
challenge auth visibile (da rilevare) e verifica l'auto-continuazione dopo la sua scomparsa.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from urllib.parse import unquote, urlparse

from playwright.sync_api import Locator, Page, sync_playwright


CAPTCHA_SELECTORS = [
    "iframe[src*='recaptcha']",
    "iframe[src*='hcaptcha']",
    "iframe[src*='challenges.cloudflare.com']",
    ".g-recaptcha",
    ".h-captcha",
    "[data-sitekey]",
    "input[autocomplete='one-time-code']",
]


def meaningful(locator: Locator, selector: str) -> bool:
    try:
        if not locator.is_visible():
            return False
        box = locator.bounding_box()
        if not box:
            return False
        width = float(box.get("width", 0))
        height = float(box.get("height", 0))
        opacity = float(locator.evaluate("el => Number(getComputedStyle(el).opacity || 1)"))
        if opacity <= 0.05 or width < 40 or height < 20:
            return False
        if selector.startswith("iframe"):
            return width >= 120 and height >= 60
        if "one-time-code" in selector:
            return width >= 80 and height >= 20
        return True
    except Exception:
        return False


def detect_auth_challenge(page: Page) -> str | None:
    for selector in CAPTCHA_SELECTORS:
        for index in range(page.locator(selector).count()):
            if meaningful(page.locator(selector).nth(index), selector):
                return selector
    text = page.locator("body").inner_text().lower()
    for marker in ("verify you are human", "verifica che sei umano", "codice di verifica"):
        if marker in text:
            return marker
    return None


def local_fixture() -> str:
    return """
<!doctype html><html><body>
  <iframe id="invisible" src="about:blank?recaptcha" style="width:1px;height:1px;opacity:0;border:0"></iframe>
  <button id="show-human" onclick="document.querySelector('#gate').style.display='block'">Avvia verifica</button>
  <div id="gate" data-sitekey="test-key" style="display:none;width:300px;height:90px;border:1px solid #000">
    <p>Verify you are human</p>
    <button id="human-solve" onclick="this.parentElement.remove();document.querySelector('#protected-content').hidden=false">Completato</button>
  </div>
  <div id="protected-content" hidden><button id="continue" onclick="document.querySelector('#done').hidden=false">Continua</button></div>
  <div id="done" hidden>OK</div>
</body></html>
"""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("url", nargs="?")
    args = parser.parse_args()

    events: list[dict] = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, executable_path="/usr/bin/chromium", args=["--no-sandbox"])
        page = browser.new_page()
        if not args.url:
            page.set_content(local_fixture(), wait_until="domcontentloaded")
        elif args.url.startswith("file://"):
            parsed = urlparse(args.url)
            page.set_content(Path(unquote(parsed.path)).read_text(encoding="utf-8"), wait_until="domcontentloaded")
        else:
            page.goto(args.url, wait_until="domcontentloaded")

        initial_gate = detect_auth_challenge(page)
        if initial_gate:
            raise SystemExit(f"Falso positivo iniziale: {initial_gate}")
        events.append({"status": "no_false_positive", "invisible_captcha_ignored": True})

        if page.locator("#show-human").count():
            page.locator("#show-human").click()
            gate = detect_auth_challenge(page)
            if not gate:
                raise SystemExit("Gate visibile non rilevato")
            events.append({"status": "auth_challenge", "reason": gate, "blocking": "external_challenge_only"})
            page.locator("#human-solve").click()
            page.wait_for_selector("#protected-content:not([hidden])")
            if detect_auth_challenge(page):
                raise SystemExit("Gate ancora presente")
            events.append({"status": "auth_challenge_cleared", "auto_resume": True})
            page.locator("#continue").click()
            page.wait_for_selector("#done:not([hidden])")
            events.append({"status": "completed", "text": page.locator("#done").inner_text()})
        browser.close()

    print(json.dumps(events, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
