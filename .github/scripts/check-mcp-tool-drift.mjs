#!/usr/bin/env node
// Fails if the ChatGPT connector descriptor (tool_names) and the MCP plugin's
// actual registered tools (self::tool('name', ...) calls) ever drift apart.
import { readFileSync } from 'node:fs';

const descriptor = JSON.parse(readFileSync('RP-STUDIO-CHATGPT-PLUGIN-1.0.0.json', 'utf8'));
const mcpSource = readFileSync('prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php', 'utf8');

const declared = new Set(descriptor.tool_names);
const real = new Set([...mcpSource.matchAll(/self::tool\('([a-z_]+)'/g)].map((m) => m[1]));

const missingFromReal = [...declared].filter((t) => !real.has(t));
const notDeclared = [...real].filter((t) => !declared.has(t));

if (descriptor.expected_tools !== descriptor.tool_names.length) {
  console.error(`expected_tools (${descriptor.expected_tools}) != tool_names.length (${descriptor.tool_names.length})`);
}
if (missingFromReal.length) console.error('Declared in connector descriptor but not implemented:', missingFromReal);
if (notDeclared.length) console.error('Implemented in MCP plugin but not declared to ChatGPT:', notDeclared);

const ok = descriptor.expected_tools === descriptor.tool_names.length && !missingFromReal.length && !notDeclared.length;
console.log(ok
  ? `OK: ${declared.size} tools match exactly between the connector descriptor and the MCP plugin.`
  : 'MISMATCH: see above.');
process.exit(ok ? 0 : 1);
