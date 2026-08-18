import fs from "node:fs";
import path from "node:path";
import assert from "node:assert/strict";

// fileURLToPath, not manual pathname surgery: a URL pathname is percent-encoded,
// so a checkout under a directory containing a space resolved to a literal
// "%20" and every read threw ENOENT.
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const meta = read("prstudio-unified-browser-agent/lib/executor-meta.js");
const worker = read("prstudio-unified-browser-agent/service-worker.js");
const store = read("prstudio-unified-control/includes/class-prstudio-uc-store.php");
const rest = read("prstudio-unified-control/includes/class-prstudio-uc-rest.php");

assert.match(meta, /CAPABILITY_CONTRACT_SHA256\s*=\s*"[a-f0-9]{64}"/);
assert.match(meta, /EXECUTOR_BUILD_TIMESTAMP\s*=\s*"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z"/);
for (const field of ["componentVersion", "suiteVersion", "agentBuild", "buildTimestamp", "capabilityHash", "runtimeOperationCount"]) {
  assert.match(worker, new RegExp(`\\b${field}\\b`), `missing Browser identity field ${field}`);
}
assert.match(store, /connection_status'\]\s*=\s*'stale'/);
assert.doesNotMatch(store, /revoke_device[\s\S]{0,300}last_seen/, "offline/stale devices must not be auto-revoked");
for (const lane of ["typed_mcp_tool", "local_studio", "browser_agent_contract", "generic_browser_action", "legacy_compatibility"]) {
  assert.match(rest, new RegExp(`'lane'=>'${lane}'`), `routing precedence misses ${lane}`);
}
for (const file of [
  "prstudio-unified-control/includes/class-prstudio-uc-execution-lanes.php",
  "prstudio-unified-control/includes/class-prstudio-uc-procedural-skills.php",
  "prstudio-unified-control/includes/class-prstudio-uc-sequential-thinking.php",
]) {
  const source = read(file);
  assert.match(source, /component_version/);
  assert.match(source, /suite_version/);
}

console.log("PASS build identity, device freshness and routing precedence contracts");
