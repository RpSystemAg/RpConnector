import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const manifest = JSON.parse(await readFile(new URL("../manifest.json", import.meta.url), "utf8"));

test("chrome.windows API does not require a synthetic windows permission", () => {
  assert.equal(manifest.permissions.includes("windows"), false);
  for (const required of ["tabs", "storage", "alarms", "scripting", "debugger", "downloads", "notifications", "webNavigation", "sidePanel", "tabGroups"]) {
    assert.equal(manifest.permissions.includes(required), true, required);
  }
});
