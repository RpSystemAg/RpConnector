/**
 * Screenshot candidate policy is state-aware.
 *
 * Background tabs need a renderer capture because they may have no compositor
 * surface. Foreground tabs prefer compositor capture and should not burn an
 * unrelated renderer retry on generic CDP transport failures before the visible
 * tab fallback takes over.
 */

import { test } from "node:test";
import assert from "node:assert/strict";

import { buildScreenshotCandidates, canCaptureBackgroundTab } from "../lib/screenshot-candidates.js";

test("every background chain can capture a tab with no compositor surface", () => {
  for (const options of [
    { format: "png" },
    { format: "jpeg", quality: 70 },
    { format: "png", fullPage: true, clip: { x: 0, y: 0, width: 1280, height: 4000, scale: 0.5 } },
    { format: "jpeg", fullPage: true, clip: { x: 0, y: 0, width: 800, height: 600, scale: 1 } },
    {},
  ]) {
    const chain = buildScreenshotCandidates({ ...options, preferRenderer: true });
    assert.ok(
      canCaptureBackgroundTab(chain),
      `no renderer capture for ${JSON.stringify(options)}: this chain cannot photograph a background tab`,
    );
  }
});

test("foreground capture stays surface-only before visible-tab fallback", () => {
  const chain = buildScreenshotCandidates({ format: "png" });
  assert.equal(chain[0].fromSurface, true, "the first attempt should still be surface capture");
  assert.equal(
    chain.filter((entry) => entry.fromSurface === false).length,
    0,
    "a generic foreground CDP failure must not spend an extra renderer attempt",
  );
  assert.equal(canCaptureBackgroundTab(chain), false);
});

test("background capture tries the renderer before a surface can return a blank frame", () => {
  const chain = buildScreenshotCandidates({ format: "png", preferRenderer: true });
  assert.equal(chain[0].fromSurface, false);
  assert.equal(chain[1].fromSurface, true);
  assert.equal(chain.filter((entry) => entry.fromSurface === false).length, 1);
});

test("the renderer variant does not ask for something it cannot have", () => {
  // captureBeyondViewport and fromSurface:false are not reliably supported
  // together. A full-page capture that fails outright is worse than a viewport
  // capture that succeeds.
  const chain = buildScreenshotCandidates({ format: "png", fullPage: true, clip: { x: 0, y: 0, width: 1280, height: 4000, scale: 1 }, preferRenderer: true });
  const renderer = chain.find((entry) => entry.fromSurface === false);
  assert.ok(renderer);
  assert.equal(renderer.captureBeyondViewport, undefined);
  assert.equal(renderer.format, "png", "png avoids a quality parameter the renderer path may reject");
  assert.deepEqual(renderer.clip, { x: 0, y: 0, width: 1280, height: 4000, scale: 1 });
});

test("renderer fallback retains an element or region clip", () => {
  const clip = { x: 12, y: 30, width: 240, height: 80, scale: 2 };
  const chain = buildScreenshotCandidates({ format: "png", fullPage: true, clip, preferRenderer: true });
  assert.equal(chain[0].fromSurface, false);
  assert.deepEqual(chain[0].clip, clip);
});

test("a full-page request keeps its clip on the preferred attempts", () => {
  const clip = { x: 0, y: 0, width: 1280, height: 4000, scale: 0.5 };
  const chain = buildScreenshotCandidates({ format: "png", fullPage: true, clip });
  assert.deepEqual(chain[0].clip, clip);
  assert.equal(chain[0].captureBeyondViewport, true);
  // The retry drops the scale rather than the clip: a smaller image is still
  // the right region, whereas no clip is the wrong picture.
  assert.equal(chain[1].clip.scale, 1);
  assert.equal(chain[1].clip.width, clip.width);
});

test("jpeg quality rides along only where jpeg is asked for", () => {
  const chain = buildScreenshotCandidates({ format: "jpeg", quality: 55 });
  assert.equal(chain[0].quality, 55);
  for (const entry of chain.filter((row) => row.format === "png")) {
    assert.equal(entry.quality, undefined, "a png entry carrying a jpeg quality is rejected by CDP");
  }
});

test("the detector recognises an unusable chain", () => {
  assert.equal(canCaptureBackgroundTab([{ format: "png", fromSurface: true }, { format: "png" }]), false);
  assert.equal(canCaptureBackgroundTab([]), false);
  assert.equal(canCaptureBackgroundTab(null), false);
  assert.equal(canCaptureBackgroundTab([{ format: "png", fromSurface: false }]), true);
});
