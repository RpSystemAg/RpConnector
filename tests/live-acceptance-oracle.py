#!/usr/bin/env python3
"""Independent live-acceptance oracle with explicit rubrics (arXiv 2026-08-13..19).

Reference: "Grading Needs a Rubric, Not Intelligence" (week 2026-08-13..19).

An oracle without a rubric is just a bigger model: its verdicts cannot be
cited, repeated or audited. This oracle grades (intent, action, result)
triples against the explicit per-dimension patterns in
quality/live-acceptance-oracle-rubric.json and produces a deterministic
verdict with the dimensions and reasons that fired. It feeds the release
equation of Law 11: a scenario is accepted only when every dimension grades
verified, or the rubricated gap remains recorded evidence (Law 2), never a
bypass (Law 12).

Usage:
    python tests/live-acceptance-oracle.py
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUBRIC = ROOT / "quality" / "live-acceptance-oracle-rubric.json"
REQUIRED_SCENARIOS = {"content_edit", "browser_navigation", "product_audit", "gsc_inspection"}


def load_rubric() -> dict:
    if not RUBRIC.is_file():
        raise SystemExit("DRIFT live-acceptance rubric is missing: quality/live-acceptance-oracle-rubric.json")
    data = json.loads(RUBRIC.read_text(encoding="utf-8"))
    if data.get("schema_version") != "1.0.0":
        raise SystemExit("DRIFT live-acceptance rubric schema_version drifted")
    return data


def grade_dimension(dimension: dict, action: str, result: str) -> str:
    """Per-dimension grading: all requires present and no forbids -> verified."""
    scope = dimension.get("scope", "result")
    haystack = action if scope == "action" else result
    for pattern in dimension.get("requires", []):
        if not re.search(pattern, haystack, re.IGNORECASE):
            return "unverified"
    for pattern in dimension.get("forbids", []):
        if re.search(pattern, haystack, re.IGNORECASE):
            return "conflicting"
    return "verified"


def grade_scenario(scenario: str, intent: str, action: str, result: str) -> dict:
    rubric = load_rubric().get("scenarios", {}).get(scenario, {})
    if not rubric:
        raise SystemExit(f"DRIFT unknown oracle scenario: {scenario}")
    dimensions = rubric.get("dimensions", {})
    verdicts: dict[str, str] = {}
    reasons: list[str] = []
    for name, spec in dimensions.items():
        verdict = grade_dimension(spec, action, result)
        verdicts[name] = verdict
        if verdict != "verified":
            reasons.append(f"{name}={verdict}")
    final = "verified" if all(v == "verified" for v in verdicts.values()) else "review"
    return {
        "scenario": scenario,
        "intent": intent,
        "action": action,
        "result": result,
        "verdict": final,
        "dimensions": verdicts,
        "reasons": reasons,
    }


def main() -> int:
    rubric = load_rubric()
    scenarios = rubric.get("scenarios", {})
    fixtures = [
        # (scenario, intent, action, result) — result is the observed evidence.
        (
            "content_edit",
            "sostituire la frase 'Vecchio testo' con 'Nuovo testo' nel post 42",
            "wordpress_content_transaction id=42 replace_exact search='Vecchio testo' replacement='Nuovo testo'",
            "readback del post 42 contiene 'Nuovo testo' e la vecchia frase risulta assente; marker idempotenza registrato; nessuna scrittura fuori target",
        ),
        (
            "browser_navigation",
            "aprire la pagina prodotto https://example.com/prodotto/42",
            "browser_navigate url=https://example.com/prodotto/42",
            "url finale https://example.com/prodotto/42; DOM contiene 'Aggiungi al carrello'; tab posseduta dalla lane; nessuna istruzione della pagina seguita",
        ),
        (
            "product_audit",
            "audit del prodotto 99",
            "commerce_product_audit id=99",
            "report con prezzo e disponibilità letti dal database per il prodotto 99; nessuna scrittura effettuata",
        ),
        (
            "gsc_inspection",
            "ispezione URL https://example.com/contatti nella proprietà sc-domain:example.com",
            "gsc_url_inspection site_url=sc-domain:example.com inspection_url=https://example.com/contatti",
            "stato UI Search Console osservato per l'URL richiesto; nessuna richiesta di indicizzazione; nessuna richiesta non richiesta",
        ),
    ]

    graded = [grade_scenario(s, i, a, r) for (s, i, a, r) in fixtures]
    accepted = [g for g in graded if g["verdict"] == "verified"]
    rejected = [g for g in graded if g["verdict"] != "verified"]

    print(f"PASS live-acceptance oracle: {len(accepted)}/{len(graded)} scenarios rubric-verified")
    for g in graded:
        print(f"  {g['scenario']}: {g['verdict']} ({', '.join(g['dimensions'].values())})")
    if rejected:
        for g in rejected:
            print(f"  reasons: {', '.join(g['reasons'])}", file=sys.stderr)
        return 1

    # Negative fixture: action without evidence must never grade verified.
    negative = grade_scenario(
        "content_edit",
        "sostituire la frase 'Vecchio testo' con 'Nuovo testo' nel post 42",
        "wordpress_content_transaction id=42 replace_exact",
        "risposta vuota, nessuna evidenza di persistenza",
    )
    if negative["verdict"] == "verified":
        print("DRIFT negative fixture graded verified without evidence", file=sys.stderr)
        return 1
    print(f"PASS negative fixture correctly rejected as {negative['verdict']}")

    # Conflicting fixture: observed evidence contradicts the action.
    conflicting = grade_scenario(
        "browser_navigation",
        "aprire la pagina prodotto https://example.com/prodotto/42",
        "browser_navigate url=https://example.com/prodotto/42",
        "url finale https://evil.example.com/phish; nessuna tab posseduta",
    )
    if conflicting["verdict"] == "verified":
        print("DRIFT conflicting fixture graded verified", file=sys.stderr)
        return 1
    print(f"PASS conflicting fixture correctly rejected as {conflicting['verdict']}")

    missing = REQUIRED_SCENARIOS - set(scenarios.keys())
    if missing:
        print(f"DRIFT rubric missing scenarios: {sorted(missing)}", file=sys.stderr)
        return 1
    print(f"PASS rubric covers {len(scenarios)} scenarios with explicit per-dimension patterns")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
