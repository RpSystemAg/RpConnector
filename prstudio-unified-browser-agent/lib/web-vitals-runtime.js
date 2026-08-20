/*
 * PR STUDIO Core Web Vitals runtime.
 *
 * Metric selection follows GoogleChrome/web-vitals v6.0.1 algorithms for
 * LCP, CLS session windows, and INP p98 interaction selection. This small
 * MAIN-world collector is intentionally dependency-free so the extension can
 * capture buffered PerformanceObserver entries from document_start without a
 * network dependency or a DevTools Performance.getMetrics surrogate.
 *
 * Upstream algorithm reference: https://github.com/GoogleChrome/web-vitals
 * License reference: Apache-2.0 (upstream web-vitals).
 */
(() => {
  'use strict';

  if (globalThis !== globalThis.top || globalThis.__PRSTUDIO_WEB_VITALS__) return;

  const SOURCE = 'google_web_vitals_6.0.1_algorithm_port';
  const MAX_INTERACTIONS = 10;
  const INP_DURATION_THRESHOLD = 40;
  const supportedTypes = new Set(globalThis.PerformanceObserver?.supportedEntryTypes || []);
  const now = () => Date.now();

  const compactEntry = (entry) => {
    if (!entry) return null;
    const out = {
      entryType: String(entry.entryType || ''),
      name: String(entry.name || ''),
      startTime: Number(entry.startTime || 0),
      duration: Number(entry.duration || 0),
    };
    for (const key of ['value', 'interactionId', 'renderTime', 'loadTime', 'size', 'id', 'url', 'navigationId']) {
      if (entry[key] !== undefined && entry[key] !== null) out[key] = entry[key];
    }
    return out;
  };

  const navigationEntry = () => performance.getEntriesByType?.('navigation')?.[0] || null;
  const activationStart = () => Number(navigationEntry()?.activationStart || 0);

  let firstHiddenTime = document.visibilityState === 'hidden' ? 0 : Infinity;
  let navigation = {
    type: navigationEntry()?.type || 'navigate',
    id: 0,
    interactionId: 0,
    url: location.href,
    startTime: 0,
  };

  let lcp = null;
  let clsValue = 0;
  let clsSessionValue = 0;
  let clsSessionEntries = [];
  let clsEntries = [];
  let interactions = [];
  let interactionMap = new Map();
  let minKnownInteractionId = Infinity;
  let maxKnownInteractionId = 0;
  let previousInteractionCount = 0;
  const history = [];

  const nativeInteractionCount = () => {
    const value = Number(performance.interactionCount ?? NaN);
    if (Number.isFinite(value)) return value;
    return maxKnownInteractionId
      ? ((maxKnownInteractionId - minKnownInteractionId) / 7) + 1
      : 0;
  };

  const interactionCount = () => Math.max(0, nativeInteractionCount() - previousInteractionCount);

  const estimatedINP = () => {
    if (!interactions.length) {
      // web-vitals v6 reports a small 8 ms interaction for soft-navigation /
      // bfcache navigations when interactionCount proves an interaction happened
      // but no >=40 ms candidate was retained.
      if (interactionCount() > 0 && ["soft-navigation", "back-forward-cache"].includes(navigation.type)) {
        return { value: 8, rating: 'good', interactionId: -1, entries: [] };
      }
      return null;
    }
    const index = Math.min(interactions.length - 1, Math.floor(interactionCount() / 50));
    const candidate = interactions[index];
    if (!candidate) return null;
    return {
      value: Number(candidate.latency || 0),
      rating: candidate.latency <= 200 ? 'good' : (candidate.latency <= 500 ? 'needs-improvement' : 'poor'),
      interactionId: Number(candidate.id || 0),
      entries: candidate.entries.map(compactEntry).filter(Boolean),
    };
  };

  const ratingLCP = (value) => value <= 2500 ? 'good' : (value <= 4000 ? 'needs-improvement' : 'poor');
  const ratingCLS = (value) => value <= 0.1 ? 'good' : (value <= 0.25 ? 'needs-improvement' : 'poor');

  const snapshot = () => ({
    source: SOURCE,
    librarySemantics: 'web-vitals@6.0.1',
    collectedAt: now(),
    navigation: { ...navigation },
    supported: {
      lcp: supportedTypes.has('largest-contentful-paint'),
      cls: supportedTypes.has('layout-shift'),
      inp: Boolean(globalThis.PerformanceEventTiming && 'interactionId' in PerformanceEventTiming.prototype),
      softNavigation: supportedTypes.has('soft-navigation'),
    },
    metrics: {
      LCP: lcp ? { ...lcp } : null,
      CLS: {
        value: clsValue,
        rating: ratingCLS(clsValue),
        entries: clsEntries.map(compactEntry).filter(Boolean),
      },
      INP: estimatedINP(),
    },
    interactionCount: interactionCount(),
    history: history.slice(-10),
  });

  const storeHistory = (reason) => {
    history.push({ reason, ...snapshot(), history: undefined });
    if (history.length > 10) history.splice(0, history.length - 10);
  };

  const resetNavigationMetrics = (nextNavigation) => {
    lcp = null;
    clsValue = 0;
    clsSessionValue = 0;
    clsSessionEntries = [];
    clsEntries = [];
    interactions = [];
    interactionMap = new Map();
    previousInteractionCount = Math.max(0, nativeInteractionCount());
    // Do not reset the interaction-id estimator here. web-vitals keeps its
    // page-lifetime interaction counter and snapshots the previous count per
    // navigation; resetting min/max would under-count INP after soft-nav/BFCache.
    firstHiddenTime = document.visibilityState === 'hidden' ? performance.now() : Infinity;
    navigation = nextNavigation;
  };

  const processLCP = (entry, relativeStart = null) => {
    if (!entry) return;
    const renderTime = Number(entry.renderTime || entry.startTime || 0);
    if (renderTime >= firstHiddenTime) return;
    const base = relativeStart === null ? activationStart() : Number(relativeStart || 0);
    const value = Math.max(Number(entry.startTime || renderTime) - base, 0);
    lcp = {
      value,
      rating: ratingLCP(value),
      entry: compactEntry(entry),
    };
  };

  const processCLS = (entry) => {
    if (!entry || entry.hadRecentInput) return;
    const first = clsSessionEntries[0];
    const last = clsSessionEntries[clsSessionEntries.length - 1];
    if (
      clsSessionValue && first && last &&
      entry.startTime - last.startTime < 1000 &&
      entry.startTime - first.startTime < 5000
    ) {
      clsSessionValue += Number(entry.value || 0);
      clsSessionEntries.push(entry);
    } else {
      clsSessionValue = Number(entry.value || 0);
      clsSessionEntries = [entry];
    }
    if (clsSessionValue > clsValue) {
      clsValue = clsSessionValue;
      clsEntries = [...clsSessionEntries];
    }
  };

  const updateInteractionCountEstimate = (entry) => {
    const id = Number(entry?.interactionId || 0);
    if (!id) return;
    minKnownInteractionId = Math.min(minKnownInteractionId, id);
    maxKnownInteractionId = Math.max(maxKnownInteractionId, id);
  };

  const processInteraction = (entry) => {
    if (!entry) return;
    updateInteractionCountEstimate(entry);
    const id = Number(entry.interactionId || 0);
    if (!(id || entry.entryType === 'first-input')) return;

    const minCandidate = interactions[interactions.length - 1];
    let interaction = interactionMap.get(id);
    if (!interaction && interactions.length >= MAX_INTERACTIONS && Number(entry.duration || 0) <= Number(minCandidate?.latency || 0)) return;

    if (interaction) {
      if (Number(entry.duration || 0) > interaction.latency) {
        interaction.latency = Number(entry.duration || 0);
        interaction.entries = [entry];
      } else if (
        Number(entry.duration || 0) === interaction.latency &&
        Number(entry.startTime || 0) === Number(interaction.entries[0]?.startTime || 0)
      ) {
        interaction.entries.push(entry);
      }
    } else {
      interaction = { id, latency: Number(entry.duration || 0), entries: [entry] };
      interactionMap.set(id, interaction);
      interactions.push(interaction);
    }

    interactions.sort((a, b) => b.latency - a.latency);
    if (interactions.length > MAX_INTERACTIONS) {
      for (const removed of interactions.splice(MAX_INTERACTIONS)) interactionMap.delete(removed.id);
    }
  };

  const observe = (type, callback, extra = {}) => {
    if (!globalThis.PerformanceObserver || !supportedTypes.has(type)) return null;
    try {
      const observer = new PerformanceObserver((list) => callback(list.getEntries()));
      observer.observe({ type, buffered: true, ...extra });
      return observer;
    } catch {
      return null;
    }
  };

  observe('largest-contentful-paint', (entries) => {
    const entry = entries[entries.length - 1];
    if (entry) processLCP(entry);
  });

  observe('layout-shift', (entries) => {
    for (const entry of entries) processCLS(entry);
  });

  if (globalThis.PerformanceEventTiming && 'interactionId' in PerformanceEventTiming.prototype) {
    observe('event', (entries) => {
      for (const entry of entries) processInteraction(entry);
    }, { durationThreshold: INP_DURATION_THRESHOLD });
    observe('first-input', (entries) => {
      for (const entry of entries) processInteraction(entry);
    });
    if (!('interactionCount' in performance)) {
      observe('event', (entries) => {
        for (const entry of entries) updateInteractionCountEstimate(entry);
      }, { durationThreshold: 0 });
    }
  }

  if (supportedTypes.has('soft-navigation')) {
    observe('soft-navigation', (entries) => {
      for (const entry of entries) {
        storeHistory('soft-navigation');
        const next = {
          type: 'soft-navigation',
          id: Number(entry.navigationId || 0),
          interactionId: Number(entry.interactionId || 0),
          url: String(entry.name || location.href),
          startTime: Number(entry.startTime || 0),
        };
        resetNavigationMetrics(next);
        try {
          const paint = entry.getLargestInteractionContentfulPaint?.();
          if (paint) processLCP(paint, next.startTime);
        } catch { /* optional experimental API */ }
      }
    });
    if (supportedTypes.has('interaction-contentful-paint')) {
      observe('interaction-contentful-paint', (entries) => {
        for (const entry of entries) {
          if (navigation.type !== 'soft-navigation') continue;
          if (entry.interactionId && navigation.interactionId && entry.interactionId !== navigation.interactionId) continue;
          const paint = entry.largestContentfulPaint || entry;
          processLCP(paint, navigation.startTime);
        }
      });
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden' && firstHiddenTime === Infinity) firstHiddenTime = performance.now();
  }, { capture: true, passive: true });

  globalThis.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    storeHistory('back-forward-cache');
    const restoreStart = Number(event.timeStamp || performance.now());
    resetNavigationMetrics({
      type: 'back-forward-cache',
      id: navigation.id,
      interactionId: navigation.interactionId,
      url: location.href,
      startTime: restoreStart,
    });
    // web-vitals finalizes BFCache LCP after two animation frames because a
    // restored page does not necessarily emit a fresh LCP entry.
    const raf = globalThis.requestAnimationFrame || ((callback) => setTimeout(callback, 0));
    raf(() => raf(() => {
      if (navigation.type !== 'back-forward-cache' || lcp) return;
      const value = Math.max(performance.now() - restoreStart, 0);
      lcp = { value, rating: ratingLCP(value), entry: null, syntheticReason: 'bfcache_restore_double_raf' };
    }));
  }, { capture: true, passive: true });

  Object.defineProperty(globalThis, '__PRSTUDIO_WEB_VITALS__', {
    value: Object.freeze({ snapshot, source: SOURCE }),
    configurable: false,
    enumerable: false,
    writable: false,
  });
})();
