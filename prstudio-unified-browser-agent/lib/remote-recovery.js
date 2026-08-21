export const REMOTE_RECOVERY_POLICY_VERSION = "1.0.0";
export const REMOTE_MAX_STEP_ATTEMPTS = 2;
export const REMOTE_MAX_FRESH_RESTARTS = 1;

const RETRY_SAFE_TYPES = new Set(["scroll", "wait_selector", "wait_url", "wait_load", "reload"]);
const PRE_DISPATCH_CODES = [
  "pointer_event_invalid",
  "pointer_sequence_required",
  "pointer_sequence_too_long",
  "keyboard_event_invalid",
  "keyboard_sequence_required",
  "keyboard_sequence_too_long",
  "keyboard_key_required",
  "native_input_mode_invalid",
];

function isRecord(value) {
  return Boolean(value) && typeof value === "object" && !Array.isArray(value);
}

function stepType(step) {
  return isRecord(step) && typeof step.type === "string" ? step.type : "";
}

function errorText(error = {}) {
  if (typeof error === "string") return error.toLowerCase();
  if (!isRecord(error)) return "";
  const code = typeof error.code === "string" ? error.code : "";
  const message = typeof error.message === "string" ? error.message : "";
  return `${code} ${message}`.trim().toLowerCase();
}

function hasSemanticToken(text, token) {
  let from = 0;
  while (from <= text.length) {
    const at = text.indexOf(token, from);
    if (at < 0) return false;
    const before = at > 0 ? text[at - 1] : "";
    const after = at + token.length < text.length ? text[at + token.length] : "";
    const identifierChar = (value) => /[a-z0-9_]/.test(value);
    if (!identifierChar(before) && !identifierChar(after)) return true;
    from = at + token.length;
  }
  return false;
}

function normalizedRestartCount(value) {
  const candidate = typeof value === "string" && /^(0|[1-9]\d*)$/.test(value)
    ? Number(value)
    : value;
  return Number.isSafeInteger(candidate) && candidate >= 0 ? candidate : null;
}

export function isPreDispatchInputError(error = {}) {
  const text = errorText(error);
  return PRE_DISPATCH_CODES.some((code) => hasSemanticToken(text, code));
}

export function isRetrySafeFailure(step = {}, error = {}) {
  const type = stepType(step);
  if (RETRY_SAFE_TYPES.has(type)) return true;
  if (type === "native_input" && isPreDispatchInputError(error)) return true;
  if (["STEP_STALLED_TIMEOUT", "STEP_NO_PROGRESS"].includes(String(error?.code || ""))) return RETRY_SAFE_TYPES.has(type);
  return false;
}

export function stepWatchdogMs(step = {}) {
  const record = isRecord(step) ? step : {};
  const requestedRaw = Object.prototype.hasOwnProperty.call(record, "timeoutMs")
    ? record.timeoutMs
    : record.timeout_ms;
  const requested = typeof requestedRaw === "number" && Number.isFinite(requestedRaw) && requestedRaw > 0
    ? requestedRaw
    : 0;
  const type = stepType(record);
  const defaults = {
    screenshot: 9500,
    screenshot_element: 9500,
    scroll: 18000,
    native_input: 15000,
    wait_selector: 35000,
    wait_url: 35000,
    wait_load: 50000,
    reload: 50000,
  };
  const base = requested > 0 ? requested + 5000 : (defaults[type] || 90000);
  // Screenshot is an interactive operation: caller-provided timeouts may make
  // the internal attempt shorter, but can never expand the public envelope.
  if (type === "screenshot" || type === "screenshot_element") return Math.max(5000, Math.min(9500, base));
  return Math.max(5000, Math.min(120000, base));
}

export function freshRestartCount(state = {}) {
  if (!isRecord(state)) return REMOTE_MAX_FRESH_RESTARTS;
  const checkpointPresent = state.checkpoint !== undefined && state.checkpoint !== null;
  if (checkpointPresent) {
    if (!isRecord(state.checkpoint) || !Object.prototype.hasOwnProperty.call(state.checkpoint, "fresh_restart_count")) {
      return REMOTE_MAX_FRESH_RESTARTS;
    }
    return normalizedRestartCount(state.checkpoint.fresh_restart_count) ?? REMOTE_MAX_FRESH_RESTARTS;
  }
  return normalizedRestartCount(state.freshRestartCount ?? 0) ?? REMOTE_MAX_FRESH_RESTARTS;
}

export function canFreshRestart(state = {}, step = {}, error = {}, attempts = 0) {
  if (!Number.isSafeInteger(attempts) || attempts !== REMOTE_MAX_STEP_ATTEMPTS) return false;
  if (!isRetrySafeFailure(step, error)) return false;
  if (freshRestartCount(state) >= REMOTE_MAX_FRESH_RESTARTS) return false;
  // A full restart from step zero is only automatic when no prior server
  // checkpoint exists. This prevents replaying an already-completed mutation.
  if (!isRecord(state)) return false;
  if (state.checkpoint !== undefined && state.checkpoint !== null) {
    if (!isRecord(state.checkpoint)) return false;
    if (!Number.isSafeInteger(state.checkpoint.last_completed_step) || state.checkpoint.last_completed_step !== -1) return false;
  }
  return true;
}

export function noProgressExceeded(state = {}, now = Date.now()) {
  const inFlight = isRecord(state) ? state.inFlight : null;
  if (!isRecord(inFlight) || typeof inFlight.type !== "string" || !inFlight.type) return false;
  if (typeof now !== "number" || !Number.isFinite(now) || now < 0) return true;
  const startedAt = inFlight.startedAt;
  if (typeof startedAt !== "number" || !Number.isFinite(startedAt) || startedAt < 0 || startedAt > now) return true;
  const type = inFlight.type;
  const action = typeof inFlight.action === "string" ? inFlight.action : "";
  let activityAt = startedAt;
  if (inFlight.lastProgressAt !== undefined && inFlight.lastProgressAt !== null) {
    const progressAt = inFlight.lastProgressAt;
    if (typeof progressAt === "number" && Number.isFinite(progressAt) && progressAt >= startedAt && progressAt <= now) {
      activityAt = progressAt;
    }
  }
  const limit = type === "scroll" || type === "native_input"
    ? 30000
    : type === "screenshot" || type === "screenshot_element"
      ? 15000
      : type === "contract_action" && action === "playwright_responsive_matrix"
        ? 30000
        : 120000;
  return now - activityAt > limit;
}
