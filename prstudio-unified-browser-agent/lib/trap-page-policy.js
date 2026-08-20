/**
 * Trap-page policy — page content is untrusted input (MobileWorldSafety,
 * arXiv week 2026-08-13..19).
 *
 * A visited page can try to drive the agent: text that reads like an
 * instruction ("click the delete button", "enter your password here",
 * "ignore previous instructions", fake overlays, infinite scroll traps).
 * This module classifies page input and hardens an action step sequence so
 * that no action *derived from untrusted page content* can produce effects
 * outside the sandbox without an authorization challenge (Law 4: human
 * intervention is auth-challenge only).
 *
 * Hardening model, per step:
 *  - steps whose target/url/value literally appear in the page text are
 *    marked `page_derived: true`;
 *  - page-derived steps whose effect class is sandbox-escape (credential
 *    entry, destructive navigation, transfer/delete wording, direct
 *    instruction following) are additionally marked `requires_auth_challenge`
 *    unless the same value was explicitly supplied by the operator intent
 *    (task arguments);
 *  - the runtime (service worker) then substitutes a read-only observation
 *    step when no live auth challenge is present, i.e. the action is
 *    CONTAINED, never executed blind.
 *
 * The module is pure and deterministic: same page + same steps -> same
 * hardening verdict.
 */

const DIRECTIVE_PATTERNS = [
  /\bignore\s+(?:all\s+)?(?:your\s+)?(?:previous|prior|above|earlier)\s+(?:instructions?|prompts?|messages?|context)/i,
  /\bdisregard\s+(?:all\s+)?(?:your\s+)?(?:previous|prior|above)\s+(?:instructions?|prompts?)/i,
  /\byou\s+are\s+(?:now\s+)?(?:an?|the)\s+(?:[\w-]+\s+)*(?:ai|agent|assistant|model|bot|helper)\b/i,
  /\bact\s+as\s+(?:an?|the)\s+/i,
  /\bsystem\s+prompt\s*:/i,
  /\b(?:new|updated|override)\s+(?:instructions?|prompt)/i,
  /\bdo\s+not\s+tell\s+(?:the\s+user|anyone|your\s+operator)/i,
  /\breveal\s+(?:your|the)\s+(?:token|api\s*key|secret|password|credentials)/i,
  /\b(?:enter|provide|submit)\s+(?:your|the)\s+(?:password|credentials|login|otp|verification\s*code)/i,
  /\bclick\s+(?:on\s+)?(?:the\s+)?(?:delete|remove|transfer|send|pay|purchase|refund|cancel|publish)\b/i,
  /\b(?:download|install|run|execute)\s+(?:this\s+)?(?:file|script|extension|update|binary)/i,
  /\b(?:transfer|send|wire)\s+(?:\d+\s*)?(?:money|funds|payment|euros?|bitcoin|crypto|usd|eur)\b/i,
  /\bdelete\s+(?:your|the)\s+(?:account|site|shop|store|post|page)/i,
];

const SANDBOX_ESCAPE_TYPES = new Set([
  "fill",
  "type_text",
  "press",
  "navigate",
  "open_tab",
  "click",
  "select",
  "check",
  "javascript_exec",
  "cdp",
  "contract_action",
]);

const CREDENTIAL_HINT = /(password|passwd|pwd|otp|one[-_ ]?time|verification|login|signin|credential|token|secret|payment|card|iban|bank)/i;

/** Flatten page evidence (observation bundles, snapshots, extracts) into text. */
export function pageTextFromEvidence(evidence, depth = 0) {
  if (depth > 6) return "";
  if (typeof evidence === "string") return evidence;
  if (!evidence || typeof evidence !== "object") return "";
  if (Array.isArray(evidence)) return evidence.map((e) => pageTextFromEvidence(e, depth + 1)).join("\n");
  const parts = [];
  for (const [key, value] of Object.entries(evidence)) {
    if (/(url|title|text|content|innerText|outerText|snapshot|value|aria|label|selector|href|src)/i.test(key) && typeof value === "string") {
      parts.push(value);
    } else if (typeof value === "object" && value !== null) {
      parts.push(pageTextFromEvidence(value, depth + 1));
    }
  }
  return parts.join("\n");
}

/** Detect instruction-like directives embedded in page text. */
export function detectDirectives(pageText) {
  const haystack = String(pageText || "");
  const found = [];
  for (const pattern of DIRECTIVE_PATTERNS) {
    if (pattern.test(haystack)) found.push(pattern.source);
  }
  return [...new Set(found)];
}

/** Whether a value appears literally inside untrusted page text. */
export function isPageDerived(value, pageText) {
  if (typeof value !== "string" || !value) return false;
  const normalized = value.trim();
  if (!normalized || normalized.length < 4) return false;
  return pageText.includes(normalized);
}

function effectClass(step) {
  const type = String(step?.type || "");
  if (!SANDBOX_ESCAPE_TYPES.has(type)) return "observation";
  const value = String(step?.value ?? step?.url ?? step?.key ?? step?.script ?? step?.action ?? "");
  const target = String(step?.selector ?? step?.label ?? step?.name ?? step?.role ?? "");
  const text = `${value} ${target} ${step?.action || ""}`;
  if (CREDENTIAL_HINT.test(text)) return "credential";
  if (/(delete|remove|transfer|send|pay|purchase|refund|cancel|publish)/i.test(text)) return "destructive";
  return "interaction";
}

/**
 * Harden a step sequence against trap pages.
 *
 * @param {Array<object>} steps
 * @param {{previousEvidence?: unknown, taskArguments?: object}} options
 * @returns {{steps: Array<object>, directives: string[], pageDerivedCount: number, containedCount: number}}
 */
export function hardenStepsForUntrustedPage(steps, options = {}) {
  const pageText = pageTextFromEvidence(options?.previousEvidence);
  const directives = detectDirectives(pageText);
  const taskArgs = options?.taskArguments || {};
  const taskText = pageTextFromEvidence(taskArgs);

  const hardened = (Array.isArray(steps) ? steps : []).map((step) => {
    if (!step || typeof step !== "object") return step;
    const copy = { ...step };
    const candidate = String(
      copy.value ?? copy.url ?? copy.selector ?? copy.label ?? copy.name ?? copy.key ?? copy.script ?? copy.action ?? ""
    );
    const derived = isPageDerived(candidate, pageText) && !isPageDerived(candidate, taskText);
    if (!derived) return copy;
    copy.page_derived = true;
    const klass = effectClass(copy);
    if (klass === "credential" || klass === "destructive" || directives.length > 0) {
      copy.requires_auth_challenge = true;
      copy._prstudio_trap = klass;
    }
    return copy;
  });

  return {
    steps: hardened,
    directives,
    pageDerivedCount: hardened.filter((s) => s?.page_derived).length,
    containedCount: hardened.filter((s) => s?.requires_auth_challenge).length,
  };
}

/**
 * Containment decision for one page-derived step: whether the runtime may
 * execute it without a live authorization challenge (Law 4).
 */
export function containmentDecision(step, { challengePresent = false, operatorIntent = false } = {}) {
  if (!step?.page_derived) {
    return { execute: true, reason: "not_page_derived", contained: false };
  }
  if (operatorIntent) {
    return { execute: true, reason: "operator_intent", contained: false };
  }
  if (step.requires_auth_challenge && !challengePresent) {
    return { execute: false, reason: "auth_challenge_required", contained: true };
  }
  return { execute: true, reason: "challenge_present", contained: false };
}

/** Read-only fallback step used to contain a page-derived action. */
export function containmentFallbackStep(tabId) {
  return {
    type: "observation_bundle",
    tabId,
    includeScreenshot: true,
    _prstudio_trap_contained: true,
    page_derived: false,
    requires_auth_challenge: false,
  };
}
