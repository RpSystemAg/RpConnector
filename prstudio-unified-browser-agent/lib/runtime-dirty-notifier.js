(() => {
  if (globalThis.__PRSTUDIO_RUNTIME_DIRTY_NOTIFIER_V1__) return;

  function createRuntimeDirtyNotifier(send) {
    if (typeof send !== 'function') throw new TypeError('runtime_dirty_notifier_send_required');
    let dirtyNotified = false;

    const deliver = (domVersion, url) => {
      try {
        send({ type: 'dom_mutation', domVersion: Number(domVersion || 0), url: String(url || '') });
        return true;
      } catch {
        return false;
      }
    };

    return Object.freeze({
      notify(domVersion, url) {
        if (dirtyNotified) return false;
        const delivered = deliver(domVersion, url);
        if (delivered) dirtyNotified = true;
        return delivered;
      },
      synchronize(domVersion, url) {
        const delivered = deliver(domVersion, url);
        // A runtime request is the acknowledgement boundary: the worker now has
        // a current version, and the first mutation after it must be reported.
        dirtyNotified = false;
        return delivered;
      },
      reset() {
        dirtyNotified = false;
      },
      state() {
        return { dirtyNotified };
      },
    });
  }

  Object.defineProperty(globalThis, '__PRSTUDIO_RUNTIME_DIRTY_NOTIFIER_V1__', {
    value: Object.freeze({ create: createRuntimeDirtyNotifier }),
    configurable: false,
    enumerable: false,
    writable: false,
  });
})();
