import { installBrowserParityRuntime } from './lib/browser-parity-runtime.js';
import { installWordPressRestTransport } from './lib/wordpress-rest-transport.js';
import './service-worker.js';

// Install the WordPress REST compatibility transport before any runtime message
// can reach service-worker.js. This keeps pairing and every post-pair API call on
// the server-authoritative REST route for both pretty and plain permalinks.
const restTransport = installWordPressRestTransport(globalThis);
globalThis.__PRSTUDIO_WORDPRESS_REST_TRANSPORT__ = restTransport;

// MV3 wake events must see extension listeners during synchronous module
// evaluation. Keep service-worker.js as a static dependency: its top-level
// chrome.*.on* registrations are therefore installed before Chrome dispatches
// the wake event, instead of being delayed behind a top-level await/dynamic
// import. The runtime hardening is installed in this same evaluation job before
// service-worker.js queued startup reconciliation runs; service-worker functions
// resolve globalThis.chrome at call time and therefore use the hardened APIs.
const parity = installBrowserParityRuntime(globalThis.chrome);
globalThis.__PRSTUDIO_BROWSER_PARITY__ = parity;
