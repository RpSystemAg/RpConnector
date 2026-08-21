/**
 * The ordered Page.captureScreenshot parameter sets to try.
 *
 * WHY THIS IS ITS OWN MODULE
 * --------------------------
 * Surface capture is preferred when a tab is active because it preserves the
 * compositor result. Inactive tabs have no reliable compositor surface, so the
 * runtime passes preferRenderer=true and starts with fromSurface:false instead.
 *
 * Keeping the two chains state-aware matters for failure latency too. A generic
 * CDP transport failure on an active tab is not a parameter compatibility
 * signal, so cycling into a renderer variant only burns another CDP attempt
 * before the visible-tab fallback. Active tabs therefore stay surface-only;
 * inactive tabs explicitly opt into the renderer-capable chain.
 *
 * captureBeyondViewport is deliberately absent from the renderer variant. The
 * two are not reliably supported together, and a full-page capture that fails
 * outright is worse than a viewport capture that succeeds.
 */

/**
 * Build the capture chain.
 *
 * @param {object} options
 * @param {"png"|"jpeg"} options.format Preferred image format.
 * @param {number} [options.quality] JPEG quality, ignored for png.
 * @param {boolean} [options.fullPage] Whether the caller asked for a full page.
 * @param {object} [options.clip] Preferred clip, already computed.
 * @param {boolean} [options.preferRenderer] Put renderer capture first for an inactive tab.
 * @returns {Array<object>} Parameter sets, best first for the tab's active state.
 */
export function buildScreenshotCandidates({ format = "png", quality = 82, fullPage = false, clip = null, preferRenderer = false } = {}) {
  const imageFormat = format === "jpeg" ? "jpeg" : "png";
  const withQuality = imageFormat === "jpeg" ? { quality } : {};

  const base = { format: imageFormat, ...withQuality, fromSurface: true, captureBeyondViewport: Boolean(fullPage) };
  if (clip) base.clip = clip;

  const surface = [base];

  if (fullPage && clip) {
    surface.push({ format: imageFormat, ...withQuality, fromSurface: true, clip: { ...clip, scale: 1 } });
    surface.push({ format: imageFormat, ...withQuality, fromSurface: true, clip: { ...clip, scale: 1 } });
  } else {
    surface.push({ format: imageFormat, ...withQuality, fromSurface: true });
  }

  surface.push({ format: "png", fromSurface: true });
  surface.push({ format: "png", fromSurface: true });

  // Renderer capture. Keep the requested clip (including its scale), but never
  // combine it with captureBeyondViewport: that combination is not consistently
  // implemented by Chrome. Retaining the clip is essential for element/region
  // screenshots; dropping it silently photographs the whole viewport instead.
  const renderer = { format: "png", fromSurface: false };
  if (clip) renderer.clip = { ...clip };

  return preferRenderer ? [renderer, ...surface] : surface;
}

/**
 * Whether a chain can capture a tab that is not in the foreground.
 *
 * A chain without a renderer variant cannot photograph a background tab at all,
 * however many entries it has.
 *
 * @param {Array<object>} chain Capture chain.
 * @returns {boolean}
 */
export function canCaptureBackgroundTab(chain = []) {
  return (Array.isArray(chain) ? chain : []).some((entry) => entry && entry.fromSurface === false);
}
