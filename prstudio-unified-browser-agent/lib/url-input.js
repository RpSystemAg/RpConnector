/**
 * Accept the URL a human actually types.
 *
 * WHY
 * ---
 * Every URL entry point in this extension went straight to `new URL(value)`.
 * That constructor throws a bare TypeError on anything without a scheme, so
 * "google.com" and "miosito.it" - which is how people and models write a site -
 * failed with "Invalid URL" before any of the real logic ran.
 *
 * Two separate user-visible failures came from this one omission:
 *
 *   browser_open   validateNavigationUrl("google.com") threw url_invalid, which
 *                  the plugin reported as a bare technical_error with no code.
 *   pairing        normalizeSiteUrl("miosito.it") threw a raw TypeError, so the
 *                  side panel showed "Errore: Invalid URL" for what was simply a
 *                  missing "https://".
 *
 * Browser-automation tooling that people find usable does not do this. The
 * reference behaviour is a navigation input that documents itself as accepting a
 * URL "with or without protocol, defaults to https://", and that is what this
 * implements. Defaulting to https rather than http matters: silently downgrading
 * a typed host to cleartext would be a worse bug than the one being fixed.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It does not turn arbitrary text into a URL. A value that already carries a
 * scheme is never rewritten - "javascript:alert(1)" stays a javascript: URL and
 * is rejected by the protocol check rather than being laundered into
 * "https://javascript:alert(1)". A value with whitespace, or with no dot and no
 * port, is not a host and is refused. The coercion applies to exactly one
 * shape: a bare authority that a person would recognise as a website.
 */

/** Anything with a scheme already parses; this spots values that have one. */
const HAS_SCHEME = /^[a-z][a-z0-9+.-]*:/i;

/**
 * "localhost:8080" matches HAS_SCHEME - "localhost" is a scheme-shaped token
 * followed by a colon - and new URL() duly reads it as the scheme "localhost:"
 * with path "8080". A host and port is not a scheme, and this is the shape a
 * person types most often for a local site, so it has to be excluded before the
 * scheme branch. A scheme whose remainder is nothing but digits is a port.
 *
 * Found by the test below, not by inspection: the first version of this file
 * turned "localhost:8080" into "localhost://8080".
 */
const HOST_AND_PORT = /^[a-z][a-z0-9+.-]*:\d{1,5}(?:[/?#]|$)/i;

/**
 * A bare authority: host[:port][/path][?query][#fragment], no spaces.
 * Requires either a dot in the host or an explicit port, so "localhost:8080"
 * and "example.com" both qualify while "open the site" does not.
 */
const BARE_AUTHORITY = /^(?:[^\s/?#@:]+(?:\.[^\s/?#@:]+)+|localhost|[^\s/?#@:]+:\d{1,5})(?::\d{1,5})?(?:[/?#]\S*)?$/i;

/** A hostname must contain at least one letter or digit; punctuation-only
 * authorities such as "." or "..." are not meaningful navigation targets. */
const HOST_HAS_LABEL_DATA = /[a-z0-9]/i;

/**
 * Turn user input into a parsed URL, supplying https:// when the scheme is the
 * only thing missing.
 *
 * @param {string} value Raw input from a person, a model, or a stored config.
 * @returns {{url: URL, coerced: boolean} | null} Parsed URL and whether a scheme
 *   was added, or null when the value cannot be read as a URL at all.
 */
export function parseUserUrl(value) {
  const raw = String(value == null ? "" : value).trim();
  if (!raw) return null;

  if (HAS_SCHEME.test(raw) && !HOST_AND_PORT.test(raw)) {
    try {
      return { url: new URL(raw), coerced: false };
    } catch {
      return null;
    }
  }

  // Protocol-relative ("//example.com") is a scheme-less absolute URL and is
  // the same missing-https case.
  const candidate = raw.startsWith("//") ? raw.slice(2) : raw;
  if (!BARE_AUTHORITY.test(candidate)) return null;

  try {
    const url = new URL(`https://${candidate}`);
    // A host that survives the regex but produces an empty or punctuation-only
    // hostname is not a host. This closes values such as "..." which WHAT IT
    // DELIBERATELY DOES NOT DO promises not to guess into a website.
    return url.hostname && HOST_HAS_LABEL_DATA.test(url.hostname) ? { url, coerced: true } : null;
  } catch {
    return null;
  }
}

/**
 * Describe what was accepted, for logs and error messages.
 *
 * @param {string} original Raw input.
 * @param {{url: URL, coerced: boolean}} parsed Result of parseUserUrl.
 * @returns {string}
 */
export function describeUrlInput(original, parsed) {
  if (!parsed) return `"${String(original).slice(0, 120)}"`;
  return parsed.coerced
    ? `"${String(original).slice(0, 80)}" read as ${parsed.url.href}`
    : parsed.url.href;
}
