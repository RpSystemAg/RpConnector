export const REMOTE_RECOVERY_POLICY_VERSION = "1.2.0";
export const REMOTE_MAX_STEP_ATTEMPTS = 2;
export const REMOTE_MAX_FRESH_RESTARTS = 1;
export const HARD_TASK_WATCHDOG_ALARM = "prstudio-hard-task-watchdog";
export const HARD_TASK_WATCHDOG_PERIOD_MINUTES = 0.5;
export const HARD_TASK_WATCHDOG_COOLDOWN_MS = 5 * 60 * 1000;

const ACTIVE_TASK_STORAGE_KEY = "prstudioActiveTask";
const HARD_RECOVERY_STORAGE_KEY = "prstudioHardRecovery";
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
// These failures are emitted by ownership/binding assertions before page input
// or application mutation is dispatched. They are therefore safe to retry even
// when the requested step itself is mutating. Treating safety by error phase,
// rather than by the eventual action type, lets a lost provisional claim recover
// without making click/fill/upload generally retryable.
const PRE_EFFECT_OWNERSHIP_CODES = [
  "technical_tab_not_controlled",
  "controlled_tab_missing",
  "tab_ownership_missing",
  "tab_ownership_nonce_mismatch",
  "tab_lane_mismatch",
  "tab_affinity_mismatch",
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

export function isPreEffectOwnershipError(error = {}) {
  const text = errorText(error);
  return PRE_EFFECT_OWNERSHIP_CODES.some((code) => hasSemanticToken(text, code));
}

export function isRetrySafeFailure(step = {}, error = {}) {
  const type = stepType(step);
  if (isPreEffectOwnershipError(error)) return true;
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
    open_tab: 30000,
    navigate: 50000,
    click: 30000,
    fill: 30000,
    type_text: 30000,
    press: 30000,
    select: 30000,
    check: 30000,
    screenshot: 9500,
    screenshot_element: 9500,
    scroll: 18000,
    native_input: 15000,
    wait_selector: 35000,
    wait_url: 35000,
    wait_load: 50000,
    reload: 50000,
    contract_action: 60000,
  };
  const base = requested > 0 ? requested + 5000 : (defaults[type] || 60000);
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
        : stepWatchdogMs({ type, action });
  return now - activityAt > limit;
}

/**
 * MV3 service workers can remain alive while an unresolved extension API
 * promise monopolises the task lane. AbortController alone cannot cancel a
 * Chrome API promise that never observes its signal. This independent alarm is
 * the final fail-closed circuit: once the persisted in-flight step exceeds its
 * bounded no-progress budget, reload the extension exactly once for that
 * attempt. Startup recovery then reconciles the persisted task/lease and never
 * blindly replays an uncertain mutation.
 */
export function installHardTaskWatchdog(chromeApi = globalThis.chrome) {
  if (!chromeApi?.alarms?.create || !chromeApi?.alarms?.onAlarm?.addListener || !chromeApi?.storage?.local || !chromeApi?.runtime?.reload) {
    return false;
  }

  try {
    chromeApi.alarms.create(HARD_TASK_WATCHDOG_ALARM, {
      delayInMinutes: HARD_TASK_WATCHDOG_PERIOD_MINUTES,
      periodInMinutes: HARD_TASK_WATCHDOG_PERIOD_MINUTES,
    });
  } catch {
    return false;
  }

  chromeApi.alarms.onAlarm.addListener(async (alarm) => {
    if (alarm?.name !== HARD_TASK_WATCHDOG_ALARM) return;
    try {
      const row = await chromeApi.storage.local.get([ACTIVE_TASK_STORAGE_KEY, HARD_RECOVERY_STORAGE_KEY]);
      const state = row?.[ACTIVE_TASK_STORAGE_KEY] || null;
      if (!state?.taskId || !noProgressExceeded(state)) return;

      const attemptId = String(state?.inFlight?.attemptId || "");
      const marker = row?.[HARD_RECOVERY_STORAGE_KEY] || null;
      const sameAttempt = marker
        && String(marker.taskId || "") === String(state.taskId)
        && String(marker.attemptId || "") === attemptId;
      const recent = sameAttempt && Number.isFinite(Number(marker.at))
        && (Date.now() - Number(marker.at)) < HARD_TASK_WATCHDOG_COOLDOWN_MS;
      if (recent) return;

      await chromeApi.storage.local.set({
        [HARD_RECOVERY_STORAGE_KEY]: {
          taskId: String(state.taskId),
          attemptId,
          stepType: String(state?.inFlight?.type || ""),
          at: Date.now(),
          reason: "hard_no_progress_reload",
        },
      });
      chromeApi.runtime.reload();
    } catch {
      // Never let the watchdog itself terminate the service worker. The normal
      // lease timeout/recovery path remains available if storage is unhealthy.
    }
  });
  return true;
}

installHardTaskWatchdog();
