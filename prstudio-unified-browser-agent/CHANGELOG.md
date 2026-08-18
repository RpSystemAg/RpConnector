# Changelog
## 1.0.0

- Replaced the Core Web Vitals Performance.getMetrics surrogate with a document_start PerformanceObserver collector following web-vitals 6.0.1 metric semantics for LCP, CLS and INP.
- Added BFCache and browser-supported soft-navigation metric segmentation.
- Renamed the legacy Lighthouse-compatible audit provider to devtools_quality_audit; it does not claim to execute Lighthouse.
- Browser interaction/execution architecture otherwise remains unchanged.

## 16.0.0

### Control-plane latency compatibility hotfix (same 16.0.0 build)
- The Agent keeps the 20-second idle long-poll hold to avoid reintroducing database churn; the Control plane now closes the lost-wakeup race that could turn that hold into user-visible dispatch latency.
- No Browser runtime, persistent CDP, targetRef, multi-frame or flow execution behavior is removed.


### Pre-mutation / recovery hotfix (same 16.0.0 build)
- Canonical step hashing now handles JavaScript `undefined` deterministically
  (object fields omitted, array entries normalized to `null`).
- Restart recovery is fail-closed for uncertain mutating steps while verified
  read-only work can resume safely.
- Search Console collection now binds verification to the exact requested surface
  and data contract instead of treating transport completion as data verification.
- Local Studio mutating restart state remains `failed_nonreplayable` until explicitly
  acknowledged; install, pairing and account/API requirements are unchanged.

### Long-poll dispatch
- The idle poll loop against `/tasks/next` is replaced by a held request
  (`?wait=20`). The 15.x loop polled every 100–750 ms and each poll cost the
  control plane a device UPDATE plus a claim transaction — several write queries
  per poll, several polls per second, from a browser doing nothing.
- The agent detects support from the `wait_supported` field. Against a 15.x
  control plane it stops asking and uses the original cadence, so the extension
  works on either version.
- When the server declines a hold because too many are open, the agent backs off
  from requesting one for a minute rather than hammering a refused feature.
- When the server honoured the hold the agent does not sleep again on top of it,
  so dispatch latency stays low instead of compounding.
- Pairing, `prstudioConfig`, wire protocol 3.0.0 and every existing workflow,
  schedule, workspace and visual baseline are untouched.

## 15.0.0

### Suite 15 convergence
- Added one-time Suite 15 cleanup for obsolete ephemeral sessions, queues, takeover/recorder state and stale tab affinity while preserving `prstudioConfig`, pairing, user workflows, schedules, workspaces, profiles and visual baselines.
- Added build identity (`componentVersion`, suite version, timestamp, build ID and capability SHA-256) to the existing capability/heartbeat contract without changing pairing or protocol 3.0.0.
- Added end-to-end opaque correlation IDs to Browser checkpoints, completion/failure evidence and bounded logs.
- Fixed `verify_url`, the retry/debugger-detach takeover race, expired takeover reconciliation, adopted-tab Sentinel cleanup, remote-vs-scheduled lane races and Local Studio context leakage between concurrent side-panel calls.
- Screenshot acquisition now performs preflight, applies a bounded API timeout, reports fallback completeness/dimensions truthfully and releases large buffers after persistence.
- GSC evidence is generation-bound so stale network/DOM payloads cannot be mixed into a current result.
- The Side Panel now shows all active takeovers and uses plain-language status/actions instead of a single technical takeover view.

### Stabilization hardening (same 16.0.0 build)
- Fixed Side Panel ES-module bootstrap: removed a duplicate top-level `downloadJson()` declaration that caused Chrome to reject the whole module and left every UI control inert. Added module-context parsing and deterministic Side Panel handler/pairing tests.
- Fixed legacy gateway source TypeError, WooCommerce setting single-item writes/readback, native AdTribes feed scheduling, Browser Agent takeover queue parking, and mandatory anti-regression gates.

### Remote queue self-healing (same 16.0.0 build)
- Added bounded two-attempt recovery for retry-safe Browser steps. After two technical failures with no accepted checkpoint, the Agent can clean task runtime/tab affinity and request one server-side fresh restart from step zero.
- Fresh restart is limited to one automatic cycle and is rejected once a completed checkpoint exists, so completed mutations are never replayed automatically.
- Added step watchdogs for scroll/native input, heartbeat `ok:false` lease-loss handling, and internal scroll-container fallback for applications that do not scroll the document root.
- Native pointer aliases (`pointerDown`, `pointerUp`, `pointerMove`, `tap`, mouse variants) are normalized before exact CDP dispatch, preventing pre-dispatch `pointer_event_invalid` failures.

### Local Studio hardening (same 16.0.0 build)
- Added zero-account/no-API-key Standalone Mode, semantic recorder/workflows, inspector, page health/debug, responsive matrix, bounded site scan, visual+semantic baselines, local diagnostic reports, workspaces, scheduler, command palette, flight recorder and fail-closed local recovery.
- Local and remote lanes share ownership/emergency-stop policy without sharing tabs. Active local executions yield remote task acquisition; active remote work blocks local mutation starts.
- Extension capabilities are advertised through the existing pairing/heartbeat contract; WordPress and the existing `browser_status` MCP output can discover them, while the existing lane-bound connector can invoke only the explicitly allowlisted Local Studio operations; page-bound calls still require an owned/adopted tab and local recovery acknowledgement remains non-remote.
- No Chrome permission, install folder, pairing route, endpoint, tool count or executor protocol was added/changed; Suite 15 runtime storage was extended only with versioned ephemeral session state.
- Bounded all remote API/CDP/body reads, extended watchdog coverage to screenshots/baselines/observation, and serialized poll stop/start so a stale MV3 loop cannot overlap a replacement loop.
- Canonicalized legacy numeric device aliases to UUIDs, redacted internal DB identifiers/device token hashes, and normalized legacy lane credentials to the authenticated public lane handle before persistence.
- Made human takeover durable local-first and detector-fail-closed, inherited popup ownership from owned opener tabs, and kept cross-origin transitions fail-closed unless explicitly allowlisted.
- Made Local Studio baselines exact-tab, byte-bounded and hash/semantic-first; observation, CDP, GSC, trace and screencast buffers now have explicit memory budgets and no duplicate Network/Console storage.
- Converted trace/video/HAR/route/coverage start-stop operations to explicit expiring runtime sessions and made close-context/browser perform real owned-Agent cleanup while preserving adopted user tabs.
- Scheduled local checks now hold the shared execution lane for the full run and create an ephemeral non-Agent worker window when no normal user window exists.
- Search Console request-indexing is classified as a critical mutation; read-only Search Console operations remain non-critical.


- Added exact raw/internal CDP validation and removed custom-step policy bypasses.
- Added centralized untrusted-observation envelopes with recursive secret redaction, provenance and truncation metadata.
- Hardened pairing with HTTPS/loopback policy, timeout, redirect rejection, omitted credentials, same-origin API binding and protocol intersection.
- Made tab ownership explicit and persistent, excluded the sentinel, and rejected unknown explicit tab IDs.
- Persisted in-flight step/attempt/result digests; mutating crash recovery now requires human takeover and never auto-replays.
- Added real cancellation/lease-loss aborts, task alarm heartbeat, native CDP pointer/drag/wheel/touch/keyboard sequences, observation bundles and social snapshots.
- Added coherent side-panel takeover, emergency stop and runtime status while preserving unpacked install, pairing endpoint, `prstudioConfig` and executor protocol `3.0.0`.

## 5.0.0
- Promoted Browser Agent to a first-class/primary executor for live UI and human-visible work.
- Preserved the 2.0 extension folder, Manifest V3 permissions, pairing endpoint, `prstudioConfig`, reload and owned-window/tab isolation contracts.
- Decoupled product version 5.0.0 from stable executor wire protocol 3.0.0 so ordinary extension upgrades do not require re-pairing.
- Negotiates Chrome DevTools Protocol 1.3 first with controlled 0.1 compatibility fallback.
- Covers navigation, tabs, DOM/accessibility, click/fill/type/press/select/check/tap/drag/scroll, screenshots/PDF, network/console/errors, responsive/performance diagnostics, trace/HAR and bounded crawlers.
- Maps 119 Browser catalog actions to real executors; 11 cookie/session-secret, arbitrary-JS or global-permission actions remain intentional security guards.
- Added persistent owned GSC session, in-page dimension switching, active-dimension/header verification and no fabricated cross-dimension join.
- Retained service-worker recovery, tab ownership, pacing, circuit breaker, lease/deadline/step budgets and bounded retries.
