#!/usr/bin/env node
// Fails if BUILD-INFO.json's declared counts ever drift from the generated
// registry/catalog/contract files they claim to describe. Run
// tests/regenerate-contract-artifacts.py first if this fails after a real
// source change -- BUILD-INFO.json's numbers are meant to be recomputed, not
// hand-edited.
import { readFileSync } from 'node:fs';

const readJson = (path) => JSON.parse(readFileSync(path, 'utf8'));

const mcpSource = readFileSync('prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php', 'utf8');
const mcpToolCount = new Set([...mcpSource.matchAll(/self::tool\('([a-z_]+)'/g)].map((m) => m[1])).size;

const registry = readJson('prstudio-unified-control/capabilities/capability-registry.json');
const overlay = readJson('prstudio-unified-control/capabilities/agency-capabilities.json');
const catalog = readJson('prstudio-unified-control/connector/action-catalog.json');
const contract = readJson('prstudio-unified-control/contract/capability-contract.json');
const buildInfo = readJson('prstudio-unified-control/BUILD-INFO.json');

const expected = {
  mcp_tool_count: mcpToolCount,
  base_capability_count: registry.counts.capabilities,
  agency_capability_overlay_count: overlay.capabilities.length,
  capability_count: registry.counts.capabilities + overlay.capabilities.length,
  legacy_action_count: catalog.count,
  legacy_direct_tool_count: contract.counts.direct_tools,
};

const mismatches = Object.entries(expected).filter(([key, value]) => buildInfo[key] !== value);

if (mismatches.length) {
  console.error('BUILD-INFO.json counts do not match the generated registry/catalog/contract:');
  for (const [key, value] of mismatches) console.error(`  ${key}: declared ${buildInfo[key]}, real ${value}`);
  process.exit(1);
}
console.log(`OK: BUILD-INFO.json counts match the generated registry/catalog/contract (${Object.keys(expected).length} fields checked).`);
