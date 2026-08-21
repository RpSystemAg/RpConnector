import { installBrowserParityRuntime } from './lib/browser-parity-runtime.js';

// Install bounded Chrome API semantics before the main Browser Agent module is
// evaluated. This keeps the production worker Chrome-native: the original
// service-worker owns orchestration/state, while this layer only hardens the
// browser primitives it consumes.
const parity = installBrowserParityRuntime(globalThis.chrome);
globalThis.__PRSTUDIO_BROWSER_PARITY__ = parity;

await import('./service-worker.js');
