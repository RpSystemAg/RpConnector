---
name: prstudio-suite-release
description: Verify, package and ship a new build of the PR STUDIO Unified Suite (prstudio-unified-browser-agent + prstudio-unified-control) to the RpSystemAg/RpConnector GitHub repo. Use this whenever the user hands over a new suite zip and wants it checked, fixed, and pushed — it replaces the manual sequence of extracting, cross-checking client/server contracts, running tests, scanning for secrets, committing and pushing that was previously done by hand. Trigger on phrases like "parti da questo zip", "carica su github", "rendi operativa la suite", or any request to verify and ship a new PR STUDIO build.
---

## What this skill does, and what it deliberately does not do

This automates the mechanical, safe-to-repeat half of a suite release: verify → scan → package → ship → confirm CI is actually green. It does **not** automate finding or fixing bugs — that step stays manual, read-the-code, cross-check-the-contract work, because tonight's session proved exactly why: two different zip lineages had two different, non-obvious integration bugs (client/server event-envelope mismatch, a missing `?.` guard, a wrong offscreen `reasons` value) that only surfaced by actually reading both sides of each contract, not by pattern-matching. Encoding a blind auto-patcher here would repeat the same mistake that made this project's history so noisy. Diagnose and fix with real reading and the existing verified reference (this repo's current `master`) as ground truth; use this skill for everything after that.

## The two fixed rules — these do not change, ever

1. Every mutation in the extension passes through the single existing anti-crash gate (`lib/resilience.js`). Never add a new guard, verification, or approval layer to the runtime code — this skill is dev/release tooling, it never touches that architecture.
2. The installation method never changes: "carica estensione non pacchettizzata" for the extension, plugin ZIP upload for WordPress. This skill packages artifacts; it never introduces a different install mechanism (no PowerShell installers, no auto-reload scripts).

## Sequence

1. **Locate and extract.** If given a zip path, extract it to a scratch folder. If asked to work from "the latest zip", check `~/Downloads` for the newest `PR-STUDIO-*.zip` by mtime and confirm the choice before proceeding — silently picking the wrong one among several same-named variants is exactly how this session lost track of state earlier.

2. **Read before touching.** Compare the extracted `prstudio-unified-browser-agent/` and `prstudio-unified-control/` against the current `master` branch of this repo (clone or `git show` reference, don't assume from memory — a memory of what a file contained is not the same as what it contains now). Note every file that differs. For anything touching the LIVE/WebRTC path or the click/locate path specifically, read both the extension-side caller and the WordPress-side handler together — the bug is almost always in the seam between them, not inside either file alone.

3. **Verify.** Run the extension's own verify script:
   ```bash
   node prstudio-unified-browser-agent/.claude/skills/prstudio-extension-verify/scripts/verify.mjs
   ```
   Do not proceed to packaging on a red result. If PHP is installed on the machine, also run `php -l` on every changed `.php` file — this session never had PHP available locally, so that check was always skipped and should be treated as a real gap, not assumed clean, whenever it's possible to actually run it.

4. **Scan for secrets before anything leaves the machine.**
   ```bash
   grep -rEn "(api[_-]?key|secret|password|token)\s*[:=]\s*['\"][A-Za-z0-9_\-]{16,}['\"]" --include="*.js" --include="*.php" --include="*.json" . | grep -vi "test|example|placeholder"
   ```
   A non-empty result is a stop, not a warning to note and continue past.

5. **Package.** Remove stale duplicate artifacts (e.g. nested component zips that just re-duplicate source already present as directories — they add weight and go stale the moment source changes, they don't belong in version control). Use PowerShell `Compress-Archive -LiteralPath ... -DestinationPath ...` for a zip deliverable, not the `zip` CLI — it is not installed on this machine. Always use `-LiteralPath`/single-quoted paths since the working folders on this machine contain parentheses that break naive path interpolation.

6. **Ship.** Commit with a message that states what was actually verified (test counts, what was checked against what), never what was merely intended. Push to `RpSystemAg/RpConnector` (`gh repo create` only if the repo doesn't exist yet; otherwise a plain `git push`). If `.github/workflows/browser-agent-tests.yml` is missing, add it — it runs `node --test tests/*.test.mjs` on every push so there's a real, independent, free CI signal beyond this skill's own local run.

7. **Confirm CI, don't assume it.** After pushing, poll the actual run:
   ```bash
   gh run list --repo RpSystemAg/RpConnector --limit 3
   ```
   Report the real status once it completes (`completed`/`success` or the specific failure) — a push without a confirmed green run is not a finished release.

## Reporting back

State plainly what was checked and what wasn't (e.g. "PHP not lint-checked, not installed here"), cite concrete evidence (test counts, commit hashes, CI run URLs) rather than confidence language, and don't claim "identical to X" or "production ready" without having actually observed the behavior live — a green test suite confirms the code paths it covers, not the parts (like a real browser reload) that were never exercised.
