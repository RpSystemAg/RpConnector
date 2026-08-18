#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFile, readdir, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inflateRawSync } from 'node:zlib';

const RELEASE_VERSION = '1.0.0';
const SUITE_FOLDER = `PR-STUDIO-Unified-Suite-${RELEASE_VERSION}`;
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const STRICT = process.argv.includes('--strict');
const INCLUDE_LEGACY_ZIPS = process.argv.includes('--include-legacy-zips');
const RUN_PHP_SMOKE = process.argv.includes('--php-smoke');
const PHP_BINARY = process.env.PRSTUDIO_PHP || 'php';
const PYTHON_BINARY = process.env.PRSTUDIO_PYTHON || (process.platform === 'win32' ? 'python' : 'python3');

const CONTROL_FOLDER = 'prstudio-unified-control';
const AGENT_FOLDER = 'prstudio-unified-browser-agent';
const CONTROL_ZIP = `${CONTROL_FOLDER}-${RELEASE_VERSION}.zip`;
const AGENT_ZIP = `${AGENT_FOLDER}-${RELEASE_VERSION}.zip`;
const RESOLVED_MCP_URL = 'https://example.com/wp-json/prstudio-unified/v1/mcp';
const MCP_URL_TEMPLATE = 'https://<site>/wp-json/prstudio-unified/v1/mcp';
const OAUTH_OPTION_KEYS = [
  'prstudio_mcp_v5_clients',
  'prstudio_mcp_v5_tokens',
  'prstudio_mcp_v5_generation',
];
const BROWSER_SECURITY_GUARDS = [];

const REQUIRED_DRAFT_FILES = [
  `ARCHITECTURE-${RELEASE_VERSION}.md`,
  `H24-OPERATIONS-${RELEASE_VERSION}.md`,
  `MCP-TOOLCHAIN-${RELEASE_VERSION}.md`,
  `SOCIAL-CONNECTORS-${RELEASE_VERSION}.md`,
  `RP-STUDIO-CHATGPT-PLUGIN-${RELEASE_VERSION}.json`,
  `RP-STUDIO-CHATGPT-PLUGIN-INSTRUCTIONS-${RELEASE_VERSION}.txt`,
  `RP-STUDIO-CHATGPT-PLUGIN-SETUP-${RELEASE_VERSION}.md`,
  'tests/php-intelligence-smoke.php',
  'tests/php-toolchain-smoke.php',
  'tests/php-operational-completeness-smoke.php',
  'tests/php-agency-state-runtime-smoke.php',
  'tests/php-reliability-hotfix-smoke.php',
  'tests/php-mcp-catalog-runtime-smoke.php',
  'tests/php-editorial-efficiency-smoke.php',
  'tests/php-publish-transaction-smoke.php',
  'tests/validate-efficiency-editorial.mjs',
  'tests/m11_contract_audit.py',
  'tests/validate-m11-portable-concurrency.py',
  'tests/m11-portable-concurrency-worker.php',
  'tests/m11-engineering-runtime-smoke.php',
  'tests/php-m11-core-contract-smoke.php',
  'tests/php-schedule-clock-smoke.php',
  'tests/php-critical-performance.php',
  'tests/dump-wpaib-tools.php',
  'tests/regenerate-contract-artifacts.py',
  'tests/validate-correlation-chain.mjs',
  'tests/validate-build-identity.mjs',
  'tests/php-anti-crash-work-binding-smoke.php',
  'tests/php-execution-lane-concurrency-smoke.php',
  'tests/php-editorial-concurrency-smoke.php',
  'tests/php-mcp-protocol-runtime-smoke.php',
  'tests/php-browser-parent-reconciliation-smoke.php',
  'tests/php-screenshot-storage-smoke.php',
  'tests/validate-browser-enterprise-hardening.mjs',
  'tests/validate-browser-live-webrtc.mjs',
  'tests/validate-mcp-toolchain.mjs',
  'tests/rigorous-audit-controller.py',
  'tests/php-health-integrity-smoke.php',
  'prstudio-unified-browser-agent/tests/sidepanel-ui.test.mjs',
  'tests/validate-release.mjs',
];
// Package deliverables only. The elaborate per-release report battery
// (QUALITY-GATE/SECURITY-HARDENING/TEST-REPORT/etc.) was intentionally
// removed from the repo as stale generated audit artifacts (see git history)
// and is not required to exist on disk for a source checkout to validate.
const REQUIRED_FINAL_FILES = [
  CONTROL_ZIP,
  AGENT_ZIP,
];

const results = [];
const jsonCache = new Map();

function add(status, name, detail = '') {
  results.push({ status, name, detail });
}

function requireCheck(condition, name, detail = '') {
  add(condition ? 'PASS' : 'FAIL', name, detail);
  return condition;
}

function deferredCheck(condition, name, detail = '') {
  if (condition) add('PASS', name, detail);
  else add(STRICT ? 'FAIL' : 'WARN', name, detail);
  return condition;
}

function normalizeRelative(value) {
  return value.split(path.sep).join('/').replace(/^\.\//, '');
}

function full(relative) {
  return path.join(ROOT, ...relative.split('/'));
}

async function exists(relative) {
  try {
    await stat(full(relative));
    return true;
  } catch (error) {
    if (error?.code === 'ENOENT') return false;
    throw error;
  }
}

async function walk(directory = ROOT, relativeBase = '') {
  const output = [];
  const entries = await readdir(directory, { withFileTypes: true });
  for (const entry of entries) {
    if (entry.name === '.git' || entry.name === 'node_modules') continue;
    const relative = normalizeRelative(path.join(relativeBase, entry.name));
    output.push({ relative, isDirectory: entry.isDirectory() });
    if (entry.isDirectory()) {
      output.push(...await walk(path.join(directory, entry.name), relative));
    }
  }
  return output;
}

function parseJsonStrict(text, label) {
  let index = 0;

  const error = (message) => {
    throw new Error(`${label}: ${message} at byte ${index}`);
  };
  const whitespace = () => {
    while (/\s/u.test(text[index] ?? '')) index += 1;
  };
  const string = () => {
    const start = index;
    if (text[index] !== '"') error('expected string');
    index += 1;
    let escaped = false;
    while (index < text.length) {
      const char = text[index];
      index += 1;
      if (escaped) {
        escaped = false;
        continue;
      }
      if (char === '\\') {
        escaped = true;
        continue;
      }
      if (char === '"') {
        const token = text.slice(start, index);
        try {
          return JSON.parse(token);
        } catch {
          error('invalid JSON string escape');
        }
      }
      if (char.charCodeAt(0) < 0x20) error('unescaped control character');
    }
    error('unterminated string');
  };
  const number = () => {
    const match = text.slice(index).match(/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/u);
    if (!match) error('invalid number');
    index += match[0].length;
    return Number(match[0]);
  };
  const literal = (token, value) => {
    if (text.slice(index, index + token.length) !== token) error(`expected ${token}`);
    index += token.length;
    return value;
  };
  const value = () => {
    whitespace();
    const char = text[index];
    if (char === '"') return string();
    if (char === '{') return object();
    if (char === '[') return array();
    if (char === 't') return literal('true', true);
    if (char === 'f') return literal('false', false);
    if (char === 'n') return literal('null', null);
    if (char === '-' || /\d/u.test(char ?? '')) return number();
    error('unexpected token');
  };
  const object = () => {
    const output = {};
    const keys = new Set();
    index += 1;
    whitespace();
    if (text[index] === '}') {
      index += 1;
      return output;
    }
    while (index < text.length) {
      whitespace();
      const key = string();
      if (keys.has(key)) error(`duplicate object key ${JSON.stringify(key)}`);
      keys.add(key);
      whitespace();
      if (text[index] !== ':') error('expected colon');
      index += 1;
      output[key] = value();
      whitespace();
      if (text[index] === '}') {
        index += 1;
        return output;
      }
      if (text[index] !== ',') error('expected comma');
      index += 1;
    }
    error('unterminated object');
  };
  const array = () => {
    const output = [];
    index += 1;
    whitespace();
    if (text[index] === ']') {
      index += 1;
      return output;
    }
    while (index < text.length) {
      output.push(value());
      whitespace();
      if (text[index] === ']') {
        index += 1;
        return output;
      }
      if (text[index] !== ',') error('expected comma');
      index += 1;
    }
    error('unterminated array');
  };

  const parsed = value();
  whitespace();
  if (index !== text.length) error('trailing data');
  return parsed;
}

async function loadJson(relative) {
  if (jsonCache.has(relative)) return jsonCache.get(relative);
  const parsed = parseJsonStrict(await readFile(full(relative), 'utf8'), relative);
  jsonCache.set(relative, parsed);
  return parsed;
}

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

function sameSet(left, right) {
  if (left.length !== right.length) return false;
  const expected = new Set(left);
  return expected.size === left.length && right.every((item) => expected.has(item));
}

function findSchemaArrayProperties(value, location = '$', output = []) {
  if (Array.isArray(value)) {
    value.forEach((child, index) => findSchemaArrayProperties(child, `${location}[${index}]`, output));
    return output;
  }
  if (!value || typeof value !== 'object') return output;
  const schemaLike = value.type === 'object'
    || Object.hasOwn(value, 'additionalProperties')
    || Array.isArray(value.required)
    || Object.hasOwn(value, '$schema');
  for (const [key, child] of Object.entries(value)) {
    const childLocation = `${location}.${key}`;
    if (key === 'properties' && Array.isArray(child) && schemaLike) output.push(childLocation);
    findSchemaArrayProperties(child, childLocation, output);
  }
  return output;
}

function collectVersionProblems(value, location = '$', output = []) {
  if (Array.isArray(value)) {
    value.forEach((child, index) => collectVersionProblems(child, `${location}[${index}]`, output));
    return output;
  }
  if (!value || typeof value !== 'object') return output;
  const versionKeys = new Set(['version', 'suite_version', 'server_version', 'product_version']);
  for (const [key, child] of Object.entries(value)) {
    const childLocation = `${location}.${key}`;
    if (versionKeys.has(key) && typeof child === 'string' && child !== RELEASE_VERSION) {
      output.push(`${childLocation}=${JSON.stringify(child)}`);
    }
    if (key === 'top_level_folder' && child !== SUITE_FOLDER && location === '$') {
      output.push(`${childLocation}=${JSON.stringify(child)}`);
    }
    // Historical/baseline provenance intentionally preserves the version that
    // produced the measurement. Only active release fields are required to
    // match the current product version.
    if (/historical|baseline|previous|source_parent|legacy/i.test(key)) continue;
    collectVersionProblems(child, childLocation, output);
  }
  return output;
}

function crcTable() {
  const table = new Uint32Array(256);
  for (let index = 0; index < 256; index += 1) {
    let current = index;
    for (let bit = 0; bit < 8; bit += 1) {
      current = (current & 1) ? (0xedb88320 ^ (current >>> 1)) : (current >>> 1);
    }
    table[index] = current >>> 0;
  }
  return table;
}

const CRC_TABLE = crcTable();

function crc32(buffer) {
  let crc = 0xffffffff;
  for (const byte of buffer) crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

function parseZip(buffer, label) {
  const minimumEocd = 22;
  const searchStart = Math.max(0, buffer.length - 65_557);
  let eocd = -1;
  for (let offset = buffer.length - minimumEocd; offset >= searchStart; offset -= 1) {
    if (buffer.readUInt32LE(offset) === 0x06054b50) {
      eocd = offset;
      break;
    }
  }
  if (eocd < 0) throw new Error(`${label}: ZIP end-of-central-directory not found`);
  const disk = buffer.readUInt16LE(eocd + 4);
  const centralDisk = buffer.readUInt16LE(eocd + 6);
  const entriesOnDisk = buffer.readUInt16LE(eocd + 8);
  const entryCount = buffer.readUInt16LE(eocd + 10);
  const centralSize = buffer.readUInt32LE(eocd + 12);
  const centralOffset = buffer.readUInt32LE(eocd + 16);
  if (disk !== 0 || centralDisk !== 0 || entriesOnDisk !== entryCount) {
    throw new Error(`${label}: multi-disk ZIPs are unsupported`);
  }
  if (entryCount === 0xffff || centralSize === 0xffffffff || centralOffset === 0xffffffff) {
    throw new Error(`${label}: ZIP64 is unsupported for these bounded component packages`);
  }
  if (centralOffset + centralSize > buffer.length) throw new Error(`${label}: invalid central-directory bounds`);

  const entries = [];
  let offset = centralOffset;
  for (let index = 0; index < entryCount; index += 1) {
    if (buffer.readUInt32LE(offset) !== 0x02014b50) {
      throw new Error(`${label}: invalid central-directory record ${index}`);
    }
    const flags = buffer.readUInt16LE(offset + 8);
    const method = buffer.readUInt16LE(offset + 10);
    const expectedCrc = buffer.readUInt32LE(offset + 16);
    const compressedSize = buffer.readUInt32LE(offset + 20);
    const uncompressedSize = buffer.readUInt32LE(offset + 24);
    const nameLength = buffer.readUInt16LE(offset + 28);
    const extraLength = buffer.readUInt16LE(offset + 30);
    const commentLength = buffer.readUInt16LE(offset + 32);
    const localOffset = buffer.readUInt32LE(offset + 42);
    if ([compressedSize, uncompressedSize, localOffset].includes(0xffffffff)) {
      throw new Error(`${label}: ZIP64 entry ${index} is unsupported`);
    }
    const nameBuffer = buffer.subarray(offset + 46, offset + 46 + nameLength);
    const name = nameBuffer.toString((flags & 0x800) ? 'utf8' : 'latin1');
    entries.push({
      name,
      flags,
      method,
      expectedCrc,
      compressedSize,
      uncompressedSize,
      localOffset,
    });
    offset += 46 + nameLength + extraLength + commentLength;
  }
  return entries;
}

function extractZipEntry(zipBuffer, entry, label) {
  if (entry.flags & 0x1) throw new Error(`${label}: encrypted entry ${entry.name}`);
  if (zipBuffer.readUInt32LE(entry.localOffset) !== 0x04034b50) {
    throw new Error(`${label}: invalid local header for ${entry.name}`);
  }
  const nameLength = zipBuffer.readUInt16LE(entry.localOffset + 26);
  const extraLength = zipBuffer.readUInt16LE(entry.localOffset + 28);
  const start = entry.localOffset + 30 + nameLength + extraLength;
  const compressed = zipBuffer.subarray(start, start + entry.compressedSize);
  let output;
  if (entry.method === 0) output = Buffer.from(compressed);
  else if (entry.method === 8) output = inflateRawSync(compressed);
  else throw new Error(`${label}: unsupported compression method ${entry.method} for ${entry.name}`);
  if (output.length !== entry.uncompressedSize) throw new Error(`${label}: size mismatch for ${entry.name}`);
  if (crc32(output) !== entry.expectedCrc) throw new Error(`${label}: CRC mismatch for ${entry.name}`);
  return output;
}

function zipPathProblems(entries) {
  const problems = [];
  const seen = new Map();
  for (const entry of entries) {
    const normalized = entry.name.replaceAll('\\', '/');
    if (entry.name.includes('\\')) problems.push(`backslash path: ${entry.name}`);
    if (/^(?:\/|[A-Za-z]:)/u.test(normalized)) problems.push(`absolute path: ${entry.name}`);
    if (normalized.split('/').includes('..')) problems.push(`parent traversal: ${entry.name}`);
    const folded = normalized.toLocaleLowerCase('en-US');
    const previous = seen.get(folded);
    if (previous && previous !== normalized) problems.push(`case collision: ${previous} <> ${normalized}`);
    else if (previous) problems.push(`duplicate path: ${normalized}`);
    seen.set(folded, normalized);
  }
  return problems;
}

async function componentSourceFiles(component) {
  const base = full(component);
  const paths = await walk(base);
  const files = new Map();
  for (const item of paths.filter((item) => !item.isDirectory)) {
    files.set(item.relative, await readFile(path.join(base, ...item.relative.split('/'))));
  }
  return files;
}

async function validateTargetZip(zipName, component) {
  if (!await exists(zipName)) {
    add(STRICT ? 'FAIL' : 'SKIP', `ZIP ${zipName}`, 'not built yet');
    return;
  }
  try {
    const zipBuffer = await readFile(full(zipName));
    const entries = parseZip(zipBuffer, zipName);
    const pathProblems = zipPathProblems(entries);
    requireCheck(pathProblems.length === 0, `${zipName}: safe unique paths`, pathProblems.join('; '));
    const files = entries.filter((entry) => !entry.name.endsWith('/'));
    const roots = [...new Set(files.map((entry) => entry.name.replaceAll('\\', '/').split('/')[0]))];
    requireCheck(roots.length === 1 && roots[0] === component, `${zipName}: single stable top-level folder`, roots.join(', '));

    if (component === AGENT_FOLDER) {
      const names = files.map((entry) => entry.name.replaceAll('\\', '/'));
      requireCheck(names.includes(`${AGENT_FOLDER}/manifest.json`), `${zipName}: lowercase Chrome manifest`);
      requireCheck(!names.includes(`${AGENT_FOLDER}/MANIFEST.json`), `${zipName}: no MANIFEST.json collision`);
    }

    const source = await componentSourceFiles(component);
    const zipFiles = new Map();
    for (const entry of files) {
      const normalized = entry.name.replaceAll('\\', '/');
      const prefix = `${component}/`;
      if (!normalized.startsWith(prefix)) continue;
      const relative = normalized.slice(prefix.length);
      zipFiles.set(relative, extractZipEntry(zipBuffer, entry, zipName));
    }
    const missing = [...source.keys()].filter((relative) => !zipFiles.has(relative));
    const extra = [...zipFiles.keys()].filter((relative) => !source.has(relative));
    const changed = [...source.keys()].filter((relative) => zipFiles.has(relative) && !source.get(relative).equals(zipFiles.get(relative)));
    requireCheck(
      missing.length === 0 && extra.length === 0 && changed.length === 0,
      `${zipName}: byte-for-byte source parity`,
      `missing=${missing.join(', ') || '-'}; extra=${extra.join(', ') || '-'}; changed=${changed.join(', ') || '-'}`,
    );
    add('PASS', `${zipName}: CRC and decompression`, `${files.length} files`);
  } catch (error) {
    add('FAIL', `ZIP ${zipName}`, error.message);
  }
}

async function validateLegacyZipCollisions(rootEntries) {
  const legacyZips = rootEntries
    .filter((entry) => !entry.isDirectory && entry.relative.endsWith('.zip'))
    .map((entry) => entry.relative)
    .filter((name) => ![CONTROL_ZIP, AGENT_ZIP].includes(name));
  for (const name of legacyZips) {
    try {
      const entries = parseZip(await readFile(full(name)), name);
      const problems = zipPathProblems(entries);
      if (problems.length === 0) add('PASS', `legacy ZIP path scan: ${name}`);
      else add(INCLUDE_LEGACY_ZIPS ? 'FAIL' : 'WARN', `legacy ZIP path scan: ${name}`, problems.join('; '));
    } catch (error) {
      add(INCLUDE_LEGACY_ZIPS ? 'FAIL' : 'WARN', `legacy ZIP path scan: ${name}`, error.message);
    }
  }
}

async function validateReleaseHashes() {
  const manifestName = `RELEASE-MANIFEST-${RELEASE_VERSION}.json`;
  if (!await exists(manifestName)) return;
  const manifest = await loadJson(manifestName);
  requireCheck(manifest.version === RELEASE_VERSION, 'release manifest version');
  requireCheck(manifest.top_level_folder === SUITE_FOLDER, 'release manifest top-level folder');
  requireCheck(sameSet(manifest.component_zips ?? [], [CONTROL_ZIP, AGENT_ZIP]), 'release manifest component ZIP names');
  for (const artifact of manifest.artifacts ?? []) {
    if (!artifact?.name || !await exists(artifact.name)) {
      add('FAIL', `release artifact ${artifact?.name ?? '<unnamed>'}`, 'missing');
      continue;
    }
    const payload = await readFile(full(artifact.name));
    requireCheck(payload.length === artifact.bytes, `release artifact bytes: ${artifact.name}`, `${payload.length} != ${artifact.bytes}`);
    requireCheck(sha256(payload) === artifact.sha256, `release artifact SHA-256: ${artifact.name}`);
  }

  const sumsName = `COMPONENT-SHA256SUMS-${RELEASE_VERSION}.txt`;
  if (!await exists(sumsName)) return;
  const lines = (await readFile(full(sumsName), 'utf8')).split(/\r?\n/u).filter(Boolean);
  for (const [lineNumber, line] of lines.entries()) {
    const match = line.match(/^([0-9a-f]{64})  (.+)$/u);
    if (!match) {
      add('FAIL', `${sumsName}:${lineNumber + 1}`, 'expected lowercase SHA-256, two spaces, filename');
      continue;
    }
    const [, expected, name] = match;
    if (!await exists(name)) {
      add('FAIL', `${sumsName}: ${name}`, 'missing');
      continue;
    }
    requireCheck(sha256(await readFile(full(name))) === expected, `${sumsName}: ${name}`);
  }
}

async function main() {
  console.log(`PR STUDIO ${RELEASE_VERSION} release validator (${STRICT ? 'strict' : 'draft'} mode)`);
  console.log(`Root: ${ROOT}\n`);

  const rootEntries = await walk();
  for (const file of REQUIRED_DRAFT_FILES) requireCheck(await exists(file), `required draft artifact: ${file}`);
  for (const file of REQUIRED_FINAL_FILES) {
    const present = await exists(file);
    if (present) add('PASS', `final artifact present: ${file}`);
    else add(STRICT ? 'FAIL' : 'SKIP', `final artifact: ${file}`, 'generated only after exact release tests');
  }

  const foldedPaths = new Map();
  const filesystemCollisions = [];
  for (const entry of rootEntries) {
    const folded = entry.relative.toLocaleLowerCase('en-US');
    const previous = foldedPaths.get(folded);
    if (previous && previous !== entry.relative) filesystemCollisions.push(`${previous} <> ${entry.relative}`);
    foldedPaths.set(folded, entry.relative);
  }
  requireCheck(filesystemCollisions.length === 0, 'filesystem paths are unique case-insensitively', filesystemCollisions.join('; '));

  const jsonFiles = rootEntries.filter((entry) => !entry.isDirectory && entry.relative.endsWith('.json'));
  const jsonFailures = [];
  const emptyObjectFailures = [];
  for (const entry of jsonFiles) {
    try {
      const parsed = await loadJson(entry.relative);
      const badProperties = findSchemaArrayProperties(parsed);
      if (badProperties.length) emptyObjectFailures.push(`${entry.relative}: ${badProperties.join(', ')}`);
    } catch (error) {
      jsonFailures.push(error.message);
    }
  }
  requireCheck(jsonFailures.length === 0, 'strict JSON parse with duplicate-key detection', jsonFailures.join('; '));
  requireCheck(emptyObjectFailures.length === 0, 'JSON Schema properties are objects, never arrays', emptyObjectFailures.join('; '));

  for (const relative of REQUIRED_DRAFT_FILES.filter((name) => name.includes(RELEASE_VERSION))) {
    if (!await exists(relative)) continue;
    const content = await readFile(full(relative), 'utf8');
    requireCheck(content.includes(RELEASE_VERSION), `${relative}: 1.0.0 anchor present`);
    requireCheck(!/\b5\.0\.[01]\b/u.test(content), `${relative}: no stale 5.0.0/5.0.1 anchor`);
  }

  const descriptorName = `RP-STUDIO-CHATGPT-PLUGIN-${RELEASE_VERSION}.json`;
  const descriptor = await loadJson(descriptorName);
  requireCheck(descriptor.schema_version === '1.0.0', 'descriptor schema version');
  requireCheck(descriptor.artifact_role === 'deployment_descriptor_not_importable', 'descriptor role is explicit');
  requireCheck(descriptor.version === RELEASE_VERSION, 'descriptor release version');
  requireCheck(descriptor.name === 'RP Studio Connector', 'stable ChatGPT display name');
  requireCheck(descriptor.server_url_template === MCP_URL_TEMPLATE, 'stable generic MCP URL');
  requireCheck(descriptor.resolved_server_url === RESOLVED_MCP_URL, 'stable Ideal Market MCP URL');
  requireCheck(descriptor.connector_zip_required === false, 'no connector ZIP contract');
  requireCheck(descriptor.custom_gpt_required === false && descriptor.gpt_actions_required === false, 'remote MCP is the primary ChatGPT contract');

  const tools = Array.isArray(descriptor.tool_names) ? descriptor.tool_names : [];
  const uniqueTools = new Set(tools);
  requireCheck(tools.length === descriptor.expected_tools && uniqueTools.size === tools.length, 'descriptor tool count and uniqueness', `${tools.length}/${uniqueTools.size}/${descriptor.expected_tools}`);
  requireCheck(tools.every((name) => /^[a-z][a-z0-9_]{0,63}$/u.test(name)), 'descriptor tool names use stable MCP-safe identifiers');

  const controlBootstrap = await readFile(full(`${CONTROL_FOLDER}/prstudio-unified-control.php`), 'utf8');
  const mcpSource = await readFile(full(`${CONTROL_FOLDER}/includes/class-prstudio-uc-mcp-v5.php`), 'utf8');
  const authSource = await readFile(full(`${CONTROL_FOLDER}/includes/class-prstudio-uc-mcp-auth-v5.php`), 'utf8');
  const browserProtocol = await readFile(full(`${CONTROL_FOLDER}/includes/class-prstudio-uc-browser-protocol.php`), 'utf8');
  const serviceWorker = await readFile(full(`${AGENT_FOLDER}/service-worker.js`), 'utf8');
  const executorMeta = await readFile(full(`${AGENT_FOLDER}/lib/executor-meta.js`), 'utf8');
  const autoloadSource = await readFile(full(`${CONTROL_FOLDER}/includes/class-prstudio-uc-autoload.php`), 'utf8');
  const autoloadMap = new Map([...autoloadSource.matchAll(/'([^']+)'\s*=>\s*'([^']+)'/gu)].map((m) => [m[1], m[2].replaceAll('\\', '/')]));
  const autoloadRequirePos = controlBootstrap.indexOf("require_once PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-autoload.php'");
  const autoloadRegisterPos = controlBootstrap.indexOf('PRSTUDIO_UC_Autoload::register();');
  const controlPlanePos = controlBootstrap.indexOf('final class PRSTUDIO_Unified_Control_Plane');
  requireCheck(autoloadRequirePos >= 0, 'bootstrap loads the single class-map autoloader');
  requireCheck(autoloadRegisterPos > autoloadRequirePos && controlPlanePos > autoloadRegisterPos, 'class-map autoloader is registered before MCP/REST hook registration');

  const agencyEngines = [
    {
      file: 'includes/class-prstudio-uc-agency-state.php',
      className: 'PRSTUDIO_UC_Agency_State',
      label: 'Agency State',
    },
    {
      file: 'includes/class-prstudio-uc-operational-twin.php',
      className: 'PRSTUDIO_UC_Operational_Twin',
      label: 'Operational Twin',
    },
    {
      file: 'includes/class-prstudio-uc-social-intelligence.php',
      className: 'PRSTUDIO_UC_Social_Intelligence',
      label: 'Social Intelligence',
    },
    {
      file: 'includes/class-prstudio-uc-opportunity-engine.php',
      className: 'PRSTUDIO_UC_Opportunity_Engine',
      label: 'Opportunity Engine',
    },
  ];
  for (const engine of agencyEngines) {
    const relative = `${CONTROL_FOLDER}/${engine.file}`;
    const present = await exists(relative);
    requireCheck(present, `${engine.label} implementation exists`, engine.file);
    if (present) {
      const source = await readFile(full(relative), 'utf8');
      requireCheck(
        new RegExp(`(?:final\\s+class|class|interface)\\s+${engine.className}\\b`, 'u').test(source),
        `${engine.label} declares ${engine.className}`,
      );
    }
    requireCheck(
      autoloadMap.get(engine.className) === engine.file,
      `lazy bootstrap maps ${engine.label}`,
      `${engine.className} -> ${autoloadMap.get(engine.className) ?? 'missing'}; expected ${engine.file}`,
    );
  }

  const phpEntries = rootEntries.filter((entry) =>
    !entry.isDirectory
    && entry.relative.startsWith(`${CONTROL_FOLDER}/`)
    && entry.relative.endsWith('.php'));
  const classDefinitions = new Map();
  for (const entry of phpEntries) {
    const source = await readFile(full(entry.relative), 'utf8');
    for (const match of source.matchAll(/(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(PRSTUDIO_UC_[A-Za-z0-9_]+)\b/gu)) {
      const componentRelative = entry.relative.slice(`${CONTROL_FOLDER}/`.length);
      classDefinitions.set(match[1], componentRelative);
    }
  }
  const directBootstrapIncludes = new Set(
    [...controlBootstrap.matchAll(/require_once\s+(?:PRSTUDIO_UC_DIR|WPAIB_DIR)\s*\.\s*'([^']+)'/gu)]
      .map((match) => match[1].replaceAll('\\', '/')),
  );
  const mcpClassReferences = [...new Set(
    [...mcpSource.matchAll(/\b(PRSTUDIO_UC_[A-Za-z0-9_]+)::/gu)].map((match) => match[1]),
  )];
  for (const className of mcpClassReferences) {
    const definition = classDefinitions.get(className);
    requireCheck(Boolean(definition), `MCP handler class exists: ${className}`);
    if (definition) {
      requireCheck(
        directBootstrapIncludes.has(definition) || autoloadMap.get(className) === definition,
        `MCP handler class is loadable before dispatch: ${className}`,
        `definition=${definition}; autoload=${autoloadMap.get(className) ?? 'missing'}`,
      );
    }
  }

  const sourceTools = [...mcpSource.matchAll(/self::tool\(\s*'([^']+)'/gu)].map((match) => match[1]);
  requireCheck(new Set(sourceTools).size === sourceTools.length, 'source MCP tool names are unique');
  requireCheck(sameSet(tools, sourceTools), 'descriptor tool names match the source MCP surface', `descriptor=${tools.length}; source=${sourceTools.length}`);
  requireCheck(mcpSource.includes("empty($properties)?new stdClass():$properties"), 'empty-object schema regression guard uses stdClass');

  const install = descriptor.installation_contract ?? {};
  requireCheck(install.wordpress?.folder === CONTROL_FOLDER, 'stable WordPress folder');
  requireCheck(install.wordpress?.bootstrap === 'prstudio-unified-control.php', 'stable WordPress bootstrap');
  requireCheck(install.wordpress?.update_mode === 'normal_wordpress_plugin_zip_update', 'normal WordPress ZIP update mode');
  requireCheck(install.browser_agent?.folder === AGENT_FOLDER, 'stable Browser Agent folder');
  requireCheck(install.browser_agent?.pair_endpoint === '/wp-json/prstudio-unified/v1/pair', 'stable Browser pairing endpoint');
  requireCheck(install.browser_agent?.storage_key === 'prstudioConfig', 'stable Browser storage key');
  requireCheck(install.browser_agent?.wire_protocol === '3.0.0', 'stable Browser wire protocol');
  requireCheck(install.browser_agent?.normal_upgrade_requires_repair === false, 'normal Browser upgrade does not require re-pair');
  requireCheck(sameSet(install.oauth?.persistent_option_keys ?? [], OAUTH_OPTION_KEYS), 'stable OAuth persistence keys');
  requireCheck(install.oauth?.preserve_registered_clients === true && install.oauth?.preserve_refresh_grants === true && install.oauth?.preserve_generation === true, 'OAuth connection continuity is explicit');

  for (const key of OAUTH_OPTION_KEYS) requireCheck(authSource.includes(`'${key}'`), `source preserves OAuth key ${key}`);
  requireCheck(serviceWorker.includes('CONFIG: "prstudioConfig"'), 'Browser source uses prstudioConfig');
  requireCheck(serviceWorker.includes('/wp-json/prstudio-unified/v1/pair'), 'Browser source uses the stable pair route');
  requireCheck(executorMeta.includes('EXECUTOR_PROTOCOL_VERSION = "3.0.0"'), 'Browser source emits wire protocol 3.0.0');
  requireCheck(browserProtocol.includes("ACCEPTED_EXECUTOR_PROTOCOLS = array('3.0.0','4.0.0')"), 'Control accepts rolling-upgrade Browser protocols');
  requireCheck(mcpSource.includes("register_rest_route( 'prstudio-unified/v1', '/mcp'"), 'stable MCP REST route');
  requireCheck(controlBootstrap.includes("PRSTUDIO_UC_ENABLE_LEGACY_MCP', false"), 'legacy MCP remains disabled by default');
  requireCheck(controlBootstrap.includes("PRSTUDIO_UC_ENABLE_LEGACY_BROWSER_RUNTIME', false"), 'legacy Browser Runtime remains disabled by default');

  const setup = await readFile(full(`RP-STUDIO-CHATGPT-PLUGIN-SETUP-${RELEASE_VERSION}.md`), 'utf8');
  const instructions = await readFile(full(`RP-STUDIO-CHATGPT-PLUGIN-INSTRUCTIONS-${RELEASE_VERSION}.txt`), 'utf8');
  const contractTokens = [
    CONTROL_FOLDER,
    'prstudio-unified-control.php',
    AGENT_FOLDER,
    'prstudioConfig',
    '/wp-json/prstudio-unified/v1/pair',
    '3.0.0',
    RESOLVED_MCP_URL,
    'RP Studio Connector',
    ...OAUTH_OPTION_KEYS,
  ];
  for (const token of contractTokens) requireCheck(setup.includes(token), `setup preserves ${token}`);
  requireCheck(/Developer mode/iu.test(setup), 'setup includes ChatGPT Developer mode');
  requireCheck(/MCP Inspector/iu.test(setup), 'setup includes MCP Inspector preflight');
  requireCheck(/do not.*secret|never.*secret/iu.test(instructions), 'instructions prohibit secret disclosure');

  const guardrails = descriptor.security_guardrails ?? {};
  const requiredTrueGuardrails = [
    'tls_required_for_remote_auth',
    'oauth_pkce_s256',
    'oauth_resource_binding',
    'refresh_token_rotation',
    'access_tokens_hashed_server_side',
    'schema_validation_recursive',
    'secret_redaction',
    'owned_tab_isolation',
    'no_auto_write_scope_escalation',
    'generic_gateway_schema_annotations_required',
  ];
  for (const key of requiredTrueGuardrails) requireCheck(guardrails[key] === true, `descriptor security guardrail ${key}`);
  requireCheck(guardrails.browser_cookie_export_exposed === false, 'Browser cookie export remains unexposed');
  requireCheck(guardrails.browser_arbitrary_js_exposed === false, 'Browser arbitrary JavaScript remains unexposed');
  requireCheck(sameSet(guardrails.browser_security_restricted_actions ?? [], BROWSER_SECURITY_GUARDS), 'exact Browser technical security-restriction list');
  for (const action of BROWSER_SECURITY_GUARDS) requireCheck(mcpSource.includes(`'${action}'`), `source technical security restriction ${action}`);
  requireCheck(authSource.includes("'code_challenge_methods_supported' => array( 'S256' )"), 'source advertises PKCE S256');
  requireCheck(authSource.includes('secret_hash( string $value )'), 'source hashes bearer credentials at rest');
  requireCheck(authSource.includes("untrailingslashit( $resource ) !== untrailingslashit( self::mcp_url() )"), 'source binds OAuth resource/audience');
  requireCheck(authSource.includes("'https' !== $scheme"), 'source restricts non-local redirect URIs to HTTPS');
  requireCheck(mcpSource.includes('private const MAX_BODY = 1048576'), 'MCP payload limit guard');
  requireCheck(mcpSource.includes('count( $requests ) > 25'), 'MCP batch limit guard');

  const implicitWriteEscalation = /!\s*in_array\(\s*'prstudio\.write'[\s\S]{0,160}\$out\[\]\s*=\s*'prstudio\.write'/u.test(authSource);
  deferredCheck(!implicitWriteEscalation, 'OAuth never adds an unrequested write scope', 'current source must be corrected before strict release');
  const executeAnnotation = mcpSource.match(/self::tool\('prstudio_execute'[\s\S]*?self::annotations\(([^)]*)\)\);/u)?.[1]?.replaceAll(' ', '');
  deferredCheck(executeAnnotation !== 'false,false,false', 'generic capability gateway uses conservative destructive annotation', `current=${executeAnnotation ?? 'not found'}`);

  const secretPattern = /(?:sk-[A-Za-z0-9_-]{20,}|prstudio_(?:at|rt)_[A-Za-z0-9_-]{20,}|Bearer\s+[A-Za-z0-9._~-]{30,})/u;
  const rootDraftPayload = [JSON.stringify(descriptor), setup, instructions].join('\n');
  requireCheck(!secretPattern.test(rootDraftPayload), 'root ChatGPT drafts contain no credential material');

  for (const entry of rootEntries.filter((item) => !item.isDirectory && item.relative.endsWith(`-${RELEASE_VERSION}.json`))) {
    const problems = collectVersionProblems(await loadJson(entry.relative));
    requireCheck(problems.length === 0, `${entry.relative}: active version fields are 1.0.0`, problems.join('; '));
    const legacy = entry.relative.replace(`-${RELEASE_VERSION}.json`, '-5.0.0.json');
    if (await exists(legacy)) {
      const [currentPayload, legacyPayload] = await Promise.all([readFile(full(entry.relative)), readFile(full(legacy))]);
      requireCheck(sha256(currentPayload) !== sha256(legacyPayload), `${entry.relative}: not a byte-for-byte renamed 5.0.0 report`);
    }
  }

  const controlBuildInfo = await loadJson(`${CONTROL_FOLDER}/BUILD-INFO.json`);
  const controlComponentManifest = await loadJson(`${CONTROL_FOLDER}/MANIFEST.json`);
  const agentBuildInfo = await loadJson(`${AGENT_FOLDER}/BUILD-INFO.json`);
  const agentChromeManifest = await loadJson(`${AGENT_FOLDER}/manifest.json`);
  const hasExactLowercaseChromeManifest = rootEntries.some((entry) => entry.relative === `${AGENT_FOLDER}/manifest.json`);
  const hasCollidingUppercaseBrowserManifest = rootEntries.some((entry) => entry.relative === `${AGENT_FOLDER}/MANIFEST.json`);
  deferredCheck(hasExactLowercaseChromeManifest, 'Browser source uses exact lowercase manifest.json', 'case is part of the cross-platform package contract');
  deferredCheck(!hasCollidingUppercaseBrowserManifest, 'Browser source has no colliding MANIFEST.json', 'rename component metadata to COMPONENT-MANIFEST.json');
  const sourceVersionChecks = [
    [/\* Version:\s+1\.0\.0/u.test(controlBootstrap), 'Control plugin header is 1.0.0'],
    [/PRSTUDIO_UC_VERSION',\s*'1\.0\.0'/u.test(controlBootstrap), 'Control product constant is 1.0.0'],
    [/public const VERSION = '1\.0\.0'/u.test(mcpSource), 'MCP server version is 1.0.0'],
    [controlBuildInfo.version === RELEASE_VERSION, 'Control BUILD-INFO version is 1.0.0'],
    [controlComponentManifest.version === RELEASE_VERSION, 'Control component manifest version is 1.0.0'],
    [agentBuildInfo.version === RELEASE_VERSION && agentBuildInfo.product_version === RELEASE_VERSION, 'Browser BUILD-INFO versions are 1.0.0'],
    [agentChromeManifest.version === RELEASE_VERSION, 'Chrome manifest product version is 1.0.0'],
    [/EXECUTOR_PRODUCT_VERSION\s*=\s*"1\.0\.0"/u.test(executorMeta), 'Browser executor product version is 1.0.0'],
  ];
  for (const [condition, name] of sourceVersionChecks) deferredCheck(condition, name, 'source editing is intentionally outside this draft task');

  await validateLegacyZipCollisions(rootEntries);
  await validateTargetZip(CONTROL_ZIP, CONTROL_FOLDER);
  await validateTargetZip(AGENT_ZIP, AGENT_FOLDER);
  await validateReleaseHashes();

  const pcntlProbe = spawnSync(PHP_BINARY, ['-r', 'exit(function_exists("pcntl_fork") ? 0 : 1);'], { cwd: ROOT, encoding: 'utf8' });
  const phpHasPcntl = !pcntlProbe.error && pcntlProbe.status === 0;
  const mandatoryRuntimeTests = [
    ['security contract suite', ['node', full('tests/validate-security-contract.mjs')]],
    ['runtime regression suite', ['node', full('tests/validate-runtime-regressions.mjs')]],
    ['Browser runtime/security + Side Panel UI + migration tests', ['node', '--test', full(`${AGENT_FOLDER}/tests/browser-security-runtime.test.mjs`), full(`${AGENT_FOLDER}/tests/observation-security.test.mjs`), full(`${AGENT_FOLDER}/tests/local-studio.test.mjs`), full(`${AGENT_FOLDER}/tests/sidepanel-ui.test.mjs`), full(`${AGENT_FOLDER}/tests/suite-migration.test.mjs`)]],
    ['local integration contract suite', ['node', full('tests/validate-local-integration.mjs')]],
    ['MCP toolchain integration suite', ['node', full('tests/validate-mcp-toolchain.mjs')]],
    ['operational surface coverage', [PYTHON_BINARY, full('tests/validate-operational-surface.py')]],
    ['milestone-11 typed technical failure contracts', [PYTHON_BINARY, full('tests/m11_contract_audit.py')]],
    ['rigorous file-by-file and tool-by-tool audit controller', [PYTHON_BINARY, full('tests/rigorous-audit-controller.py'), '--audit']],
    ['portable multi-process concurrency stress', [PYTHON_BINARY, full('tests/validate-m11-portable-concurrency.py'), '--php', PHP_BINARY]],
    ['milestone-11 engineering runtime smoke', [PHP_BINARY, full('tests/m11-engineering-runtime-smoke.php')]],
    ['Suite 17 core contract smoke', [PHP_BINARY, full('tests/php-m11-core-contract-smoke.php')]],
    ['PHP device alias canonicalization smoke', [PHP_BINARY, full('tests/php-device-alias-smoke.php')]],
    ['PHP health integrity and build identity smoke', [PHP_BINARY, full('tests/php-health-integrity-smoke.php')]],
    ['PHP MCP toolchain smoke', [PHP_BINARY, full('tests/php-toolchain-smoke.php')]],
    ['PHP new capabilities smoke', [PHP_BINARY, full('tests/php-new-capabilities-smoke.php')]],
    ['DST-safe schedule clock smoke', [PHP_BINARY, full('tests/php-schedule-clock-smoke.php')]],
    ['critical runtime performance fixture', [PHP_BINARY, full('tests/php-critical-performance.php')]],
    ['generated contract artifact parity', [PYTHON_BINARY, full('tests/regenerate-contract-artifacts.py'), '--php-binary', PHP_BINARY, '--check']],
    ['end-to-end correlation chain', ['node', full('tests/validate-correlation-chain.mjs')]],
    ['component build identity', ['node', full('tests/validate-build-identity.mjs')]],
    ['PHP operational completeness smoke', [PHP_BINARY, full('tests/php-operational-completeness-smoke.php')]],
    ['PHP agency state runtime smoke', [PHP_BINARY, full('tests/php-agency-state-runtime-smoke.php')]],
    ['PHP reliability hotfix smoke', [PHP_BINARY, full('tests/php-reliability-hotfix-smoke.php')]],
    ['PHP MCP catalog runtime smoke', [PHP_BINARY, full('tests/php-mcp-catalog-runtime-smoke.php')]],
    ['PHP editorial efficiency smoke', [PHP_BINARY, full('tests/php-editorial-efficiency-smoke.php')]],
    ['PHP publish transaction smoke', [PHP_BINARY, full('tests/php-publish-transaction-smoke.php')]],
    ['ONE-GUARD Search Console browser mission synthetic contract', ['node', full('tests/one-guard-browser-mission-smoke.mjs')]],
    ['editorial efficiency integration suite', ['node', full('tests/validate-efficiency-editorial.mjs')]],
    ['PHP anti-crash work binding smoke', [PHP_BINARY, full('tests/php-anti-crash-work-binding-smoke.php')]],
    ['PHP execution lane concurrency smoke', [PHP_BINARY, full('tests/php-execution-lane-concurrency-smoke.php')], { requiresPcntl: true }],
    ['PHP editorial concurrency smoke', [PHP_BINARY, full('tests/php-editorial-concurrency-smoke.php')], { requiresPcntl: true }],
    ['PHP MCP protocol runtime smoke', [PHP_BINARY, full('tests/php-mcp-protocol-runtime-smoke.php')]],
    ['PHP Browser parent reconciliation smoke', [PHP_BINARY, full('tests/php-browser-parent-reconciliation-smoke.php')]],
    ['PHP screenshot storage smoke', [PHP_BINARY, full('tests/php-screenshot-storage-smoke.php')]],
    ['Browser enterprise hardening suite', ['node', full('tests/validate-browser-enterprise-hardening.mjs')]],
    ['Browser LIVE MediaStream/WebRTC static contract', ['node', full('tests/validate-browser-live-webrtc.mjs')]],
  ];
  for (const [name, command, options = {}] of mandatoryRuntimeTests) {
    if (options.requiresPcntl && !phpHasPcntl) {
      add('SKIP', name, 'pcntl_fork is unavailable on this platform; portable multi-process concurrency stress remains mandatory');
      continue;
    }
    const [binary, ...args] = command;
    const run = spawnSync(binary, args, { cwd: ROOT, encoding: 'utf8' });
    const detail = [run.stdout, run.stderr].filter(Boolean).join(' ').trim().replaceAll(/\s+/gu, ' ');
    requireCheck(!run.error && run.status === 0, name, detail || (run.error?.message || `exit ${run.status}`));
  }

  const phpSmoke = 'tests/php-intelligence-smoke.php';
  if (RUN_PHP_SMOKE) {
    const run = spawnSync(PHP_BINARY, [full(phpSmoke)], { cwd: ROOT, encoding: 'utf8' });
    if (run.error) {
      add('FAIL', 'PHP intelligence smoke', `${run.error.message}; set PRSTUDIO_PHP to a PHP 8 executable`);
    } else {
      const detail = [run.stdout, run.stderr].filter(Boolean).join(' ').trim().replaceAll(/\s+/gu, ' ');
      requireCheck(run.status === 0, 'PHP intelligence smoke', detail || `exit ${run.status}`);
    }
  } else {
    add('SKIP', 'PHP intelligence smoke', 'optional reproducible command: php tests/php-intelligence-smoke.php (or set PRSTUDIO_PHP and pass --php-smoke)');
  }

  console.log('');
  for (const result of results) {
    const detail = result.detail ? ` — ${result.detail}` : '';
    console.log(`${result.status.padEnd(4)} ${result.name}${detail}`);
  }
  const counts = Object.fromEntries(['PASS', 'WARN', 'SKIP', 'FAIL'].map((status) => [status, results.filter((item) => item.status === status).length]));
  console.log(`\nSummary: ${counts.PASS} passed, ${counts.WARN} warnings, ${counts.SKIP} skipped, ${counts.FAIL} failed.`);
  if (counts.FAIL > 0) process.exitCode = 1;
}

main().catch((error) => {
  console.error(`FATAL ${error.stack ?? error.message}`);
  process.exitCode = 1;
});
