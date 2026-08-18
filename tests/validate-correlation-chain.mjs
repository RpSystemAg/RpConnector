import fs from "node:fs";
import path from "node:path";
import assert from "node:assert/strict";

// fileURLToPath, not manual pathname surgery: a URL pathname is percent-encoded,
// so a checkout under a directory containing a space resolved to a literal
// "%20" and every read threw ENOENT.
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");
const mcp = read("prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php");
const bridge = read("prstudio-unified-control/includes/class-prstudio-uc-bridge.php");
const worker = read("prstudio-unified-browser-agent/service-worker.js");

assert.match(mcp, /corr_.*hash_hmac/s, "MCP must create opaque correlation identifiers");
assert.match(mcp, /\$args\['_prstudio_correlation_id'\]\s*=\s*\$correlation_id/, "MCP must inject correlation after public schema validation");
assert.match(mcp, /'correlation_id'=>self::str\(/, "MCP output schema must publish correlation_id");
assert.match(bridge, /\$arguments\['correlation_id'\]\s*=\s*\$correlation_id/, "bridge must persist correlation in task arguments");
assert.match(bridge, /\$task\['arguments'\]\['correlation_id'\]/, "bridge task response must recover persisted correlation");
assert.match(worker, /state\.arguments\?\.correlation_id/, "Browser Agent must bind correlation from claimed task state");
assert.match(worker, /last_result:\s*securedResult[\s\S]*correlation_id:\s*correlationId/, "Browser Agent checkpoint must retain correlation");
assert.match(worker, /"task\.completed"[\s\S]*correlationId/, "Browser Agent completion log must carry correlation");

console.log("PASS correlation chain: MCP -> durable task -> Browser Agent checkpoint/log -> MCP response");
