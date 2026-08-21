import test from "node:test";
import assert from "node:assert/strict";
import { validateCdpCommand } from "../lib/policy.js";

test("raw Browser domain stays blocked while typed download behavior is internal-only", () => {
  assert.equal(validateCdpCommand("Browser.setDownloadBehavior", {
    behavior: "allow",
    downloadPath: "/tmp/downloads",
    eventsEnabled: true,
  }, "raw").ok, false);

  assert.equal(validateCdpCommand("Browser.setDownloadBehavior", {
    behavior: "allow",
    downloadPath: "/tmp/downloads",
    eventsEnabled: true,
  }, "internal").ok, true);

  assert.equal(validateCdpCommand("Browser.setDownloadBehavior", {
    behavior: "allow",
    downloadPath: "/tmp/downloads",
    eventsEnabled: false,
  }, "internal").ok, false);
});
