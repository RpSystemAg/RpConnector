import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
const worker = fs.readFileSync(new URL("../service-worker.js", import.meta.url), "utf8");
const migration = fs.readFileSync(new URL("../lib/legacy-one-guard-migration.js", import.meta.url), "utf8");
test("ONE_GUARD migration deletes legacy parked execution state without runtime routing", () => {
  assert.match(worker, /migrateOneGuardLegacyState\(chrome\.storage\.local, SUITE_VERSION/);
  assert.doesNotMatch(worker, /prstudioPendingTakeovers|prstudioTakeoverCleanupQueue|human_takeover|resuming/);
  assert.match(migration, /prstudioPendingTakeovers/);
  assert.match(migration, /prstudioTakeoverCleanupQueue/);
  assert.match(migration, /legacyExecutionStateDeleted:\s*true/);
});
