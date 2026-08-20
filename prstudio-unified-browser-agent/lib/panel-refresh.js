export function createRefreshLoop(task, options = {}) {
  if (typeof task !== 'function') throw new TypeError('panel_refresh_task_required');
  const intervalMs = Math.max(250, Math.min(300_000, Number(options.intervalMs || 10_000)));
  const scheduleTimer = typeof options.scheduleTimer === 'function' ? options.scheduleTimer : setTimeout;
  const clearTimer = typeof options.clearTimer === 'function' ? options.clearTimer : clearTimeout;
  const onError = typeof options.onError === 'function' ? options.onError : () => {};

  let stopped = true;
  let paused = false;
  let timer = null;
  let running = null;

  const clearScheduled = () => {
    if (timer === null) return;
    clearTimer(timer);
    timer = null;
  };

  const arm = () => {
    if (stopped || paused || running || timer !== null) return;
    timer = scheduleTimer(() => {
      timer = null;
      void run();
    }, intervalMs);
  };

  const run = () => {
    if (stopped || paused) return Promise.resolve(undefined);
    if (running) return running;
    running = Promise.resolve()
      .then(task)
      .catch((error) => { onError(error); return undefined; })
      .finally(() => {
        running = null;
        arm();
      });
    return running;
  };

  return Object.freeze({
    start({ immediate = true } = {}) {
      if (!stopped) return running || Promise.resolve(undefined);
      stopped = false;
      paused = false;
      if (immediate) return run();
      arm();
      return Promise.resolve(undefined);
    },
    pause() {
      if (stopped) return;
      paused = true;
      clearScheduled();
    },
    resume({ immediate = true } = {}) {
      if (stopped) return Promise.resolve(undefined);
      paused = false;
      if (immediate) return run();
      arm();
      return Promise.resolve(undefined);
    },
    runNow() {
      return run();
    },
    stop() {
      stopped = true;
      paused = false;
      clearScheduled();
    },
    state() {
      return { stopped, paused, running: Boolean(running), scheduled: timer !== null, intervalMs };
    },
  });
}
