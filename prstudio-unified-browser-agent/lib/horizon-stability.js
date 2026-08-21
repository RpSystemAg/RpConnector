/**
 * Horizon stability — long-horizon browser task hardening (Wuying-Browser-
 * Agent, arXiv week 2026-08-13..19).
 *
 * Long-horizon multi-step tasks fail when the page mutates between the
 * evidence a step was planned against and the moment the step executes. This
 * module provides:
 *
 *  - dense evidence states: a digest (DOM text + URL + title) of every
 *    observation, so a step can know *which* page state it was planned on;
 *  - single-step fallback: when the current evidence no longer matches the
 *    evidence the multi-step plan was based on, the runtime degrades to a
 *    fresh read-only observation followed by one action (scatto singolo)
 *    instead of replaying stale selectors;
 *  - deterministic replay: every planned step and its evidence digest are
 *    journaled; replaying the journal through the same policy produces the
 *    identical decision sequence (Law 11: deterministic tests).
 *
 * Pure and deterministic: no chrome API access, no global state.
 */

/** Stable string digest of an evidence object (FNV-1a 32-bit, hex). */
export function evidenceDigest(evidence) {
  const text = pageSignature(evidence);
  let hash = 0x811c9dc5;
  for (let i = 0; i < text.length; i++) {
    hash ^= text.charCodeAt(i);
    hash = Math.imul(hash, 0x01000193) >>> 0;
  }
  return hash.toString(16).padStart(8, "0");
}

/** Compact signature of the observable page state. */
export function pageSignature(evidence) {
  if (!evidence || typeof evidence !== "object") return "";
  if (Array.isArray(evidence)) return evidence.map(pageSignature).join("|");
  const parts = [];
  for (const [key, value] of Object.entries(evidence)) {
    if (/(url|title|text|innerText|outerText|dom|snapshot)/i.test(key) && typeof value === "string") {
      parts.push(`${key}=${value}`);
    } else if (typeof value === "object" && value !== null) {
      parts.push(pageSignature(value));
    }
  }
  return parts.sort().join("|");
}

/** Create a horizon session bound to the evidence a plan was built on. */
export function createHorizonSession({ previousEvidence = null, planDigest = "" } = {}) {
  return {
    planDigest: planDigest || (previousEvidence ? evidenceDigest(previousEvidence) : ""),
    evidence: previousEvidence,
  };
}

/** Whether the live evidence still matches the plan's evidence state. */
export function evidenceStable(session, liveEvidence) {
  if (!session?.planDigest) return true;
  if (!liveEvidence) return false;
  return session.planDigest === evidenceDigest(liveEvidence);
}

/**
 * Plan the next step under horizon rules.
 *
 * @param {object} step
 * @param {{session?: object, liveEvidence?: unknown, multiStepRemaining?: number}} options
 * @returns {{singleStepFallback: boolean, step: object, reason: string}}
 */
export function planHorizonStep(step, options = {}) {
  const session = options?.session || null;
  const liveEvidence = options?.liveEvidence ?? null;
  const remaining = Number(options?.multiStepRemaining ?? 0);
  const stepCopy = { ...step };

  if (!session?.planDigest) {
    return { singleStepFallback: false, step: stepCopy, reason: "no_plan_evidence" };
  }
  const stable = evidenceStable(session, liveEvidence);
  if (stable) {
    return { singleStepFallback: false, step: stepCopy, reason: "evidence_stable" };
  }
  // Page mutated mid-task: degrade to a fresh observation + one action.
  // The single action that follows is executed only after fresh evidence.
  if (remaining > 0) {
    return {
      singleStepFallback: true,
      step: { type: "observation_bundle", includeScreenshot: true, tabId: stepCopy.tabId, _prstudio_horizon_refresh: true },
      reason: "page_mutated_multi_step_fallback",
    };
  }
  return {
    singleStepFallback: true,
    step: { ...stepCopy, _prstudio_horizon_restep: true },
    reason: "page_mutated_single_step_restep",
  };
}

/**
 * Deterministic replay: apply planHorizonStep to a journal of
 * (step, liveEvidence, multiStepRemaining) entries and return the decision
 * sequence. Identical journals produce identical decisions.
 */
export function replayHorizonJournal(journal, session) {
  return (Array.isArray(journal) ? journal : []).map((entry, index) => {
    const decision = planHorizonStep(entry.step || {}, {
      session: session || createHorizonSession({ previousEvidence: entry.planEvidence || null }),
      liveEvidence: entry.liveEvidence ?? null,
      multiStepRemaining: Number(entry.multiStepRemaining ?? 0),
    });
    return {
      index,
      stepDigest: entry.step ? evidenceDigest({ type: entry.step.type, value: entry.step.value }) : "",
      verdict: decision.singleStepFallback ? "fallback" : "proceed",
      reason: decision.reason,
      fallbackType: decision.step?.type || "",
    };
  });
}
