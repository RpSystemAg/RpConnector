import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

test("Browser Agent component manifest path exists and remains builder-owned", async () => {
  const manifest = JSON.parse(await readFile(new URL("../COMPONENT-MANIFEST.json", import.meta.url), "utf8"));
  assert.equal(manifest.component, "browser_agent");
  assert.equal(manifest.version, "1.0.0");
  assert.equal(manifest.generated_by, "tests/build-release.py");
});
