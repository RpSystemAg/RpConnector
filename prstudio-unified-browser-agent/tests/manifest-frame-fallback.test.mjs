import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const manifest = JSON.parse(await readFile(new URL("../manifest.json", import.meta.url), "utf8"));

test("MAIN and ISOLATED runtimes cover related-origin fallback frames", () => {
  assert.equal(manifest.content_scripts.length, 2);
  for (const script of manifest.content_scripts) {
    assert.equal(script.all_frames, true);
    assert.equal(script.match_about_blank, true);
    assert.equal(script.match_origin_as_fallback, true);
  }
});
