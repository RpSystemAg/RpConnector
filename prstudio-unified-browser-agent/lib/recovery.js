let attemptSequence = 0;

function nextAttemptNonce() {
  attemptSequence = attemptSequence >= Number.MAX_SAFE_INTEGER ? 1 : attemptSequence + 1;
  try {
    const words = new Uint32Array(2);
    crypto.getRandomValues(words);
    return `${words[0].toString(16).padStart(8, "0")}${words[1].toString(16).padStart(8, "0")}:${attemptSequence}`;
  } catch {
    return `seq:${attemptSequence}`;
  }
}

function normalizedTabId(value) {
  if (typeof value === "string" && !value.trim()) return null;
  const numeric = Number(value);
  return Number.isSafeInteger(numeric) ? numeric : null;
}

function sameTabId(left, right) {
  const normalizedLeft = normalizedTabId(left);
  const normalizedRight = normalizedTabId(right);
  return normalizedLeft !== null && normalizedRight !== null && normalizedLeft === normalizedRight;
}

export function interruptionReason(event, activeTask, details = {}) {
  if (!activeTask?.taskId) return null;
  if (event === "tab_removed") {
    return sameTabId(details.tabId, activeTask.tabId) ? "tab_closed" : null;
  }
  if (event === "debugger_detached") {
    if (activeTask.intentionalDetach === true) return null;
    return sameTabId(details.tabId, activeTask.tabId)
      ? `debugger_detached:${details.reason || "unknown"}`
      : null;
  }
  if (event === "service_worker_restart") return "recover_from_checkpoint";
  if (event === "lease_expired") return ["leased", "running"].includes(details.status) ? "requeue" : "preserve";
  return null;
}

function canonicalValue(value, stack = new WeakSet(), inArray = false) {
  if (value === undefined) return inArray ? null : undefined;
  if (value === null || typeof value === "boolean" || typeof value === "string") return value;
  if (typeof value === "number") {
    if (!Number.isFinite(value)) throw new Error("step_canonicalization_non_finite_number");
    return value;
  }
  if (typeof value !== "object") throw new Error(`step_canonicalization_unsupported_type:${typeof value}`);
  if (stack.has(value)) throw new Error("step_canonicalization_cycle");
  const isArray = Array.isArray(value);
  const prototype = Object.getPrototypeOf(value);
  if (!isArray && prototype !== Object.prototype && prototype !== null) throw new Error("step_canonicalization_unsupported_object");
  stack.add(value);
  try {
    if (isArray) return value.map((item) => canonicalValue(item, stack, true));
    return Object.fromEntries(Object.keys(value).sort().filter((key) => value[key] !== undefined).map((key) => [key, canonicalValue(value[key], stack, false)]));
  } finally {
    stack.delete(value);
  }
}

export function canonicalStep(step = {}) {
  return JSON.stringify(canonicalValue(step));
}

export async function digestStep(step = {}) {
  const bytes = new TextEncoder().encode(canonicalStep(step));
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

export function beginInFlightState(state = {}, step = {}, stepIndex = 0, digest = "", mutating = false) {
  const now = Date.now();
  return {
    ...state,
    phase: "in_flight",
    stepIndex: Number(stepIndex),
    inFlight: {
      stepIndex: Number(stepIndex),
      digest: String(digest || ""),
      type: String(step.type || ""),
      action: String(step.action || ""),
      mutating: Boolean(mutating),
      attemptId: `${String(state.taskId || "task")}:${Number(stepIndex)}:${now}:${nextAttemptNonce()}`,
      startedAt: now,
    },
  };
}

export function markCommittingState(state = {}, resultDigest = "") {
  return {
    ...state,
    phase: "committing",
    inFlight: state.inFlight ? { ...state.inFlight, resultDigest: String(resultDigest || ""), completedAt: Date.now() } : null,
  };
}

export function clearInFlightState(state = {}) {
  return { ...state, phase: "ready", inFlight: null };
}

export function recoveryDisposition(state = {}) {
  if (!state?.taskId) return { action: "none" };
  if (state.phase === "cancel_requested" || state.phase === "cancelled") return { action: "preserve_cancel" };
  if (state.phase === "lease_lost") return { action: "lease_lost" };
  if (["in_flight", "committing"].includes(state.phase)) {
    const inFlight = state.inFlight;
    const valid = Boolean(
      inFlight
      && typeof inFlight === "object"
      && Number.isSafeInteger(Number(inFlight.stepIndex))
      && Number(inFlight.stepIndex) >= 0
      && typeof inFlight.digest === "string"
      && inFlight.digest.length > 0
      && typeof inFlight.type === "string"
      && inFlight.type.length > 0
      && typeof inFlight.mutating === "boolean"
      && typeof inFlight.attemptId === "string"
      && inFlight.attemptId.length > 0
      && Number.isFinite(Number(inFlight.startedAt))
      && Number(inFlight.startedAt) > 0
    );
    // PR STUDIO ONE-GUARD INVARIANT: after a crash, never replay an uncertain
    // durable mutation. This is Anti-Crash correctness, not verification policy.
    if (!valid || inFlight.mutating === true || state.phase === "committing") {
      return { action: "uncertain_side_effect", reason: "uncertain_side_effect_after_restart" };
    }
    return { action: "resume_readonly" };
  }
  return { action: "resume" };
}

