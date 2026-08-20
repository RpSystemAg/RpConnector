export function createRefreshLoop(refresh, { intervalMs = 5_000 } = {}) {
  if (typeof refresh !== 'function') throw new TypeError('refresh must be a function');
  const delay = Math.max(1_000, Number.isFinite(Number(intervalMs)) ? Number(intervalMs) : 5_000);

  let timer = null;
  let running = false;
  let paused = false;
  let stopped = false;
  let inFlight = null;

  const clearTimer = () => {
    if (timer !== null) clearTimeout(timer);
    timer = null;
  };

  const schedule = () => {
    clearTimer();
    if (!running || paused || stopped) return;
    timer = setTimeout(() => {
      timer = null;
      void tick();
    }, delay);
  };

  const tick = async () => {
    if (!running || paused || stopped) return;
    if (inFlight) {
      schedule();
      return inFlight;
    }
    inFlight = Promise.resolve().then(refresh);
    try {
      await inFlight;
    } finally {
      inFlight = null;
      schedule();
    }
  };

  return {
    async start({ immediate = true } = {}) {
      if (stopped) return;
      running = true;
      paused = false;
      clearTimer();
      if (immediate) await tick();
      else schedule();
    },

    pause() {
      if (stopped) return;
      paused = true;
      clearTimer();
    },

    async resume({ immediate = true } = {}) {
      if (stopped) return;
      running = true;
      paused = false;
      clearTimer();
      if (immediate) await tick();
      else schedule();
    },

    stop() {
      stopped = true;
      running = false;
      paused = false;
      clearTimer();
    },
  };
}
