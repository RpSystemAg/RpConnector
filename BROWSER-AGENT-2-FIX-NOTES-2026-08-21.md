# Browser Agent 2.0 — preventive fix notes — 2026-08-21

Closed inventory before merge:

- Ownership/control: Chrome tab-group topology and debugger attachment must remain authoritative; origin policy must not revoke ownership.
- Origin policy: allow/ask/deny is independent from tab ownership; `target_origin_mismatch` must not be an ownership gate.
- Shadow/multiframe: MAIN + ISOLATED runtimes must cover related-origin fallback frames (`about:`, `data:`, `blob:`, `filesystem:`) via MV3 content-script fallback matching.
- Download: `Browser.downloadWillBegin` / `Browser.downloadProgress` require a typed internal `Browser.setDownloadBehavior` path with `eventsEnabled: true`; raw Browser-domain CDP remains forbidden.
- Release/manifest: no synthetic `windows` permission; use only permissions actually required by Chrome APIs. Component integrity manifest is a generated build artifact, not a hand-maintained second source of truth.
- Chromium live smoke: migrate extension loading away from legacy `--load-extension` toward CDP `Extensions.loadUnpacked` with remote-debugging pipe and unsafe-extension-debugging explicitly enabled.
- CI observability: Browser Agent certification and unified full suite publish commit statuses for the exact tested SHA.
- Protocol mismatch: incompatibility must stop operational polling/claim/dispatch and surface canonical UPDATE_REQUIRED semantics.
- MCP surface: `browser_adopt_tabs` must remain in the essential Browser surface and Browser intent profile under token budget.

Primary references used: Chrome Extensions Manifest V3/content scripts/service-worker lifecycle, Chrome DevTools Protocol Browser/Extensions domains, Chrome windows API permissions, GitHub Actions workflow permissions/status API, Playwright browser-extension guidance.
