/**
 * The ordered Page.captureScreenshot parameter sets to try.
 *
 * WHY THIS IS ITS OWN MODULE
 * --------------------------
 * The chain used to be built inline, and every variant in it captured from the
 * compositor surface. `fromSurface` defaults to true when omitted, so the two
 * entries that left it out were surface captures as well: the fallback degraded
 * format, then quality, then clip, and never once changed where the pixels came
 * from.
 *
 * That matters because the Browser Agent creates its tabs with active:false. A
 * tab that is not in the foreground has no compositor surface, and a surface
 * capture of one returns a blank or stale frame. Chrome states the condition
 * outright when it happens -- the page is not compositing frames -- and no
 * amount of retrying with a different image format changes it.
 *
 * `fromSurface:false` reads the renderer instead, which is the documented way to
 * capture a target that is not in the foreground. A background tab must try
 * that path first: a compositor request can return a syntactically valid but
 * blank/stale PNG, and a non-empty payload is not proof that it photographed
 * the requested page. Foreground tabs still prefer the compositor surface.
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
    surface.push({ format: imageFormat, ...withQuality, clip: { ...clip, scale: 1 } });
  } else {
    surface.push({ format: imageFormat, ...withQuality, fromSurface: true });
  }

  surface.push({ format: "png", fromSurface: true });
  surface.push({ format: "png" });

  // Renderer capture. Keep the requested clip (including its scale), but never
  // combine it with captureBeyondViewport: that combination is not consistently
  // implemented by Chrome. Retaining the clip is essential for element/region
  // screenshots; dropping it silently photographs the whole viewport instead.
  const renderer = { format: "png", fromSurface: false };
  if (clip) renderer.clip = { ...clip };

  return preferRenderer ? [renderer, ...surface] : [...surface, renderer];
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
