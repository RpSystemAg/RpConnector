(() => {
  if (globalThis.__PRSTUDIO_RECONNECT_BACKOFF_V1__) return;

  const clampInteger = (value, fallback, minimum, maximum) => {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return fallback;
    return Math.max(minimum, Math.min(maximum, Math.floor(parsed)));
  };

  function createReconnectBackoff(options = {}) {
    const baseDelayMs = clampInteger(options.baseDelayMs, 250, 100, 10_000);
    const maxDelayMs = clampInteger(options.maxDelayMs, 10_000, baseDelayMs, 60_000);
    const stableConnectionMs = clampInteger(options.stableConnectionMs, 5_000, 1_000, 60_000);
    const jitterRatio = Math.max(0, Math.min(0.5, Number(options.jitterRatio ?? 0.2) || 0));
    const now = typeof options.now === 'function' ? options.now : Date.now;
    const random = typeof options.random === 'function' ? options.random : Math.random;
    const scheduleTimer = typeof options.scheduleTimer === 'function' ? options.scheduleTimer : setTimeout;
    const clearTimer = typeof options.clearTimer === 'function' ? options.clearTimer : clearTimeout;

    let attempt = 0;
    let timer = null;
    let connectedAt = 0;

    const nextDelay = () => {
      const exponent = Math.min(attempt, 16);
      const raw = Math.min(maxDelayMs, baseDelayMs * (2 ** exponent));
      const jittered = Math.floor(raw * (1 + Math.max(0, Math.min(1, Number(random()) || 0)) * jitterRatio));
      return Math.max(baseDelayMs, Math.min(maxDelayMs, jittered));
    };

    return Object.freeze({
      markConnected() {
        connectedAt = Number(now()) || Date.now();
      },
      markActivity() {
        attempt = 0;
      },
      schedule(callback) {
        if (timer !== null) return { scheduled: false, attempt, delayMs: null };
        const lifetime = connectedAt > 0 ? Math.max(0, (Number(now()) || 0) - connectedAt) : 0;
        if (lifetime >= stableConnectionMs) attempt = 0;
        const scheduledAttempt = attempt;
        const delayMs = nextDelay();
        attempt = Math.min(32, attempt + 1);
        timer = scheduleTimer(() => {
          timer = null;
          callback();
        }, delayMs);
        return { scheduled: true, attempt: scheduledAttempt, delayMs };
      },
      cancel() {
        if (timer === null) return false;
        clearTimer(timer);
        timer = null;
        return true;
      },
      state() {
        return { attempt, pending: timer !== null, connectedAt };
      },
    });
  }

  Object.defineProperty(globalThis, '__PRSTUDIO_RECONNECT_BACKOFF_V1__', {
    value: Object.freeze({ create: createReconnectBackoff }),
    configurable: false,
    enumerable: false,
    writable: false,
  });
})();
