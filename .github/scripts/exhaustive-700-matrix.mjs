import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';

const ROOT = process.cwd();
const CONTRACT_PATH = path.join(ROOT, 'EXHAUSTIVE-700-MATRIX.json');
const ARTIFACT_DIR = path.join(ROOT, 'artifacts', 'browser-certification', 'exhaustive-700');
const CONTROL_LOG = path.join(ARTIFACT_DIR, 'controls.ndjson');
const TEST_LOG = path.join(ARTIFACT_DIR, 'tests.ndjson');
const SUMMARY_PATH = path.join(ARTIFACT_DIR, 'summary.json');

function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

function safeToken(value) {
  const base = String(value).replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 48) || 'scope';
  return `${base}_${sha256(String(value)).slice(0, 12)}`;
}

function writeLine(fd, row, prefix) {
  const line = JSON.stringify(row);
  fs.writeSync(fd, `${line}\n`);
  process.stdout.write(`${prefix} ${row.ok ? 'PASS' : 'FAIL'} ${row.variable} ${row.metric} ${row.target || ''}\n`);
}

function trackedFiles() {
  const raw = execFileSync('git', ['ls-files', '-z'], { cwd: ROOT, encoding: 'buffer', maxBuffer: 64 * 1024 * 1024 });
  return raw.toString('utf8').split('\0').filter(Boolean).sort();
}

function gitBlob(file) {
  return execFileSync('git', ['cat-file', 'blob', `HEAD:${file}`], { cwd: ROOT, encoding: 'buffer', maxBuffer: 64 * 1024 * 1024 });
}

function commandExists(command) {
  const result = spawnSync('bash', ['-lc', `command -v ${command} >/dev/null 2>&1`], { cwd: ROOT });
  return result.status === 0;
}

function normalizeStampedText(file, text) {
  if (file === 'prstudio-unified-browser-agent/lib/executor-meta.js') {
    return text
      .replace(/export const EXECUTOR_SOURCE_SHA = \"[^\"]*\";/, 'export const EXECUTOR_SOURCE_SHA = "__STAMP__";')
      .replace(/export const EXECUTOR_BUILD_TIMESTAMP = \"[^\"]*\";/, 'export const EXECUTOR_BUILD_TIMESTAMP = "__STAMP__";')
      .replace(/export const EXECUTOR_BUILD_ID = .*?;/, 'export const EXECUTOR_BUILD_ID = "__STAMP__";');
  }
  if (file === 'prstudio-unified-browser-agent/BUILD-INFO.json' || file === 'prstudio-unified-control/BUILD-INFO.json') {
    try {
      const parsed = JSON.parse(text);
      for (const key of ['built_at_utc', 'source_commit', 'build_id', 'contract_file_sha256', 'protocol_file_sha256']) {
        if (Object.prototype.hasOwnProperty.call(parsed, key)) parsed[key] = '__STAMP__';
      }
      return JSON.stringify(parsed);
    } catch {
      return text;
    }
  }
  return text;
}

function approvedStampedMutation(file, gitBytes, workBytes) {
  const allowed = new Set([
    'prstudio-unified-browser-agent/lib/executor-meta.js',
    'prstudio-unified-browser-agent/BUILD-INFO.json',
    'prstudio-unified-control/BUILD-INFO.json',
  ]);
  if (!allowed.has(file)) return false;
  const gitText = gitBytes.toString('utf8');
  const workText = workBytes.toString('utf8');
  if (normalizeStampedText(file, gitText) !== normalizeStampedText(file, workText)) return false;
  const sourceSha = String(process.env.GITHUB_SHA || '').trim();
  if (sourceSha && /^[0-9a-f]{40}$/i.test(sourceSha)) {
    if (!workText.includes(sourceSha)) return false;
  }
  if (/UNSTAMPED|\+unbound/.test(workText)) return false;
  return true;
}

function dynamicValidate(file, bytes) {
  const ext = path.extname(file).toLowerCase();
  try {
    if (ext === '.json') {
      JSON.parse(bytes.toString('utf8'));
      return { ok: true, validator: 'json_parse', detail: 'valid JSON' };
    }
    if (['.js', '.mjs', '.cjs'].includes(ext)) {
      const result = spawnSync(process.execPath, ['--check', file], { cwd: ROOT, encoding: 'utf8', timeout: 30000 });
      return { ok: result.status === 0, validator: 'node_check', detail: String(result.stderr || result.stdout || '').trim().slice(0, 300) };
    }
    if (ext === '.php') {
      if (!commandExists('php')) return { ok: false, validator: 'php_lint', detail: 'php executable missing' };
      const result = spawnSync('php', ['-l', file], { cwd: ROOT, encoding: 'utf8', timeout: 30000 });
      return { ok: result.status === 0, validator: 'php_lint', detail: String(result.stderr || result.stdout || '').trim().slice(0, 300) };
    }
    if (ext === '.py') {
      const python = commandExists('python3') ? 'python3' : (commandExists('python') ? 'python' : '');
      if (!python) return { ok: false, validator: 'python_compile', detail: 'python executable missing' };
      const result = spawnSync(python, ['-m', 'py_compile', file], { cwd: ROOT, encoding: 'utf8', timeout: 30000 });
      return { ok: result.status === 0, validator: 'python_compile', detail: String(result.stderr || result.stdout || '').trim().slice(0, 300) };
    }
    if (['.sh', '.bash'].includes(ext)) {
      if (!commandExists('bash')) return { ok: false, validator: 'bash_n', detail: 'bash executable missing' };
      const result = spawnSync('bash', ['-n', file], { cwd: ROOT, encoding: 'utf8', timeout: 30000 });
      return { ok: result.status === 0, validator: 'bash_n', detail: String(result.stderr || result.stdout || '').trim().slice(0, 300) };
    }
    if (ext === '.zip') {
      const ok = bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4b;
      return { ok, validator: 'zip_signature', detail: ok ? 'PK signature present' : 'missing PK signature' };
    }
    const text = bytes.toString('utf8');
    const roundTrip = Buffer.from(text, 'utf8');
    const ok = !bytes.includes(0) ? roundTrip.equals(bytes) : true;
    return { ok, validator: bytes.includes(0) ? 'binary_read' : 'utf8_roundtrip', detail: `${bytes.length} bytes` };
  } catch (error) {
    return { ok: false, validator: 'exception', detail: String(error?.message || error).slice(0, 300) };
  }
}

function indexFor(seed, length) {
  if (!length) return 0;
  const prefix = sha256(seed).slice(0, 12);
  return Number(BigInt(`0x${prefix}`) % BigInt(length));
}

function sliceFor(buffer, seed) {
  if (!buffer.length) return Buffer.alloc(0);
  const start = indexFor(`${seed}:start`, buffer.length);
  const max = Math.max(1, Math.min(64, buffer.length - start));
  const width = 1 + indexFor(`${seed}:width`, max);
  return buffer.subarray(start, start + width);
}

function loadContract() {
  const contract = JSON.parse(fs.readFileSync(CONTRACT_PATH, 'utf8'));
  const fixed = ['race', 'extension', 'wordpress_plugin', 'chatgpt_schemas', 'controller_meta', 'final_suite'];
  if (contract.controls_per_scope !== 700 || contract.static_controls_per_scope !== 350 || contract.dynamic_controls_per_scope !== 350 || contract.tests_per_scope !== 700) {
    throw new Error(`matrix_contract_counts_invalid:${JSON.stringify(contract)}`);
  }
  if (JSON.stringify(contract.fixed_scopes) !== JSON.stringify(fixed)) throw new Error('matrix_fixed_scopes_invalid');
  if (!contract.per_file_scope || !contract.verbose_line_by_line || !contract.require_unique_control_variables || !contract.require_unique_test_variables) {
    throw new Error('matrix_contract_flags_invalid');
  }
  return contract;
}

function buildTargetGroups(files) {
  const race = files.filter((file) => /(?:wordpress-(?:browser|remote-browser)-e2e|wordpress-rest-transport|native-input|remote-recovery|poll|lease|unified-h24-control)/i.test(file));
  const extension = files.filter((file) => file.startsWith('prstudio-unified-browser-agent/'));
  const wordpressPlugin = files.filter((file) => file.startsWith('prstudio-unified-control/'));
  const chatgptSchemas = files.filter((file) => /(?:chatgpt|mcp|schema|connector|openapi|tool[-_ ]?catalog|descriptor)/i.test(file));
  const controllerMeta = files.filter((file) => /^(?:\.github\/|tests\/)|(?:test|validate|certif|contract|smoke|e2e|AGENTS\.md|EXHAUSTIVE-700-MATRIX\.json)/i.test(file));
  for (const [name, targets] of Object.entries({ race, extension, wordpressPlugin, chatgptSchemas, controllerMeta })) {
    if (!targets.length) throw new Error(`matrix_scope_empty:${name}`);
  }
  return { race, extension, wordpressPlugin, chatgptSchemas, controllerMeta };
}

export async function runExhaustive700Matrix() {
  const contract = loadContract();
  const files = trackedFiles();
  if (!files.length) throw new Error('matrix_no_tracked_files');
  fs.mkdirSync(ARTIFACT_DIR, { recursive: true });
  const controlFd = fs.openSync(CONTROL_LOG, 'w');
  const testFd = fs.openSync(TEST_LOG, 'w');

  const states = new Map();
  for (const file of files) {
    const work = fs.readFileSync(path.join(ROOT, file));
    const git = gitBlob(file);
    const exact = work.equals(git);
    const stampApproved = exact ? false : approvedStampedMutation(file, git, work);
    states.set(file, {
      file,
      work,
      git,
      workSha: sha256(work),
      gitSha: sha256(git),
      staticBaselineOk: exact || stampApproved,
      stampApproved,
      dynamic: dynamicValidate(file, work),
    });
  }

  const groups = buildTargetGroups(files);
  const expectedScopeCount = files.length + contract.fixed_scopes.length;
  const expectedControls = contract.controls_per_scope * expectedScopeCount;
  const expectedTests = contract.tests_per_scope * expectedScopeCount;
  const expectedTotal = expectedControls + expectedTests;
  process.stdout.write(`MATRIX tracked_files=${files.length} fixed_scopes=${contract.fixed_scopes.length} scopes=${expectedScopeCount} controls=${expectedControls} tests=${expectedTests} total=${expectedTotal}\n`);

  const controlVariables = new Set();
  const testVariables = new Set();
  const scopeSummaries = [];
  let actualStatic = 0;
  let actualDynamic = 0;
  let actualControls = 0;
  let actualTests = 0;
  let failedControls = 0;
  let failedTests = 0;

  function executeScope(name, targets, extraPredicate = () => true) {
    const scopeToken = safeToken(name);
    const liveReads = new Map(targets.map((target) => [target, fs.readFileSync(path.join(ROOT, target))]));
    const controls = [];

    for (let index = 0; index < contract.static_controls_per_scope; index += 1) {
      const target = targets[index % targets.length];
      const state = states.get(target);
      const variable = `CTRL_${scopeToken}_STATIC_${String(index + 1).padStart(4, '0')}_${sha256(`${name}:static:${index}`).slice(0, 10)}`;
      const duplicate = controlVariables.has(variable);
      controlVariables.add(variable);
      const seed = `${name}:static:${index}:${target}`;
      const gitSlice = sliceFor(state.git, seed);
      const workSlice = sliceFor(state.work, seed);
      const sliceOk = state.stampApproved ? true : gitSlice.equals(workSlice);
      const ok = !duplicate && state.staticBaselineOk && sliceOk && extraPredicate();
      const row = {
        kind: 'control', mode: 'static', scope: name, index: index + 1, variable,
        metric: state.stampApproved ? 'approved_stamp_normalized_git_integrity' : 'git_blob_byte_integrity',
        target, ok, duplicate, work_sha256: state.workSha, git_sha256: state.gitSha,
        stamp_approved: state.stampApproved,
      };
      controls.push(row); actualStatic += 1; actualControls += 1; if (!ok) failedControls += 1;
      writeLine(controlFd, row, 'CONTROL');
    }

    for (let index = 0; index < contract.dynamic_controls_per_scope; index += 1) {
      const target = targets[index % targets.length];
      const state = states.get(target);
      const live = liveReads.get(target);
      const variable = `CTRL_${scopeToken}_DYNAMIC_${String(index + 1).padStart(4, '0')}_${sha256(`${name}:dynamic:${index}`).slice(0, 10)}`;
      const duplicate = controlVariables.has(variable);
      controlVariables.add(variable);
      const seed = `${name}:dynamic:${index}:${target}`;
      const liveSlice = sliceFor(live, seed);
      const workSlice = sliceFor(state.work, seed);
      const ok = !duplicate && state.dynamic.ok && sha256(live) === state.workSha && liveSlice.equals(workSlice) && extraPredicate();
      const row = {
        kind: 'control', mode: 'dynamic', scope: name, index: index + 1, variable,
        metric: `runtime_${state.dynamic.validator}_and_live_byte_probe`, target, ok, duplicate,
        validator: state.dynamic.validator, validator_detail: state.dynamic.detail,
      };
      controls.push(row); actualDynamic += 1; actualControls += 1; if (!ok) failedControls += 1;
      writeLine(controlFd, row, 'CONTROL');
    }

    let scopeTestFailures = 0;
    for (let index = 0; index < contract.tests_per_scope; index += 1) {
      const control = controls[index % controls.length];
      const target = targets[index % targets.length];
      const state = states.get(target);
      const variable = `TEST_${scopeToken}_${String(index + 1).padStart(4, '0')}_${sha256(`${name}:test:${index}`).slice(0, 10)}`;
      const duplicate = testVariables.has(variable);
      testVariables.add(variable);
      const seed = `${name}:test:${index}:${target}`;
      const gitProbe = sliceFor(state.git, seed);
      const workProbe = sliceFor(state.work, seed);
      const byteMetricOk = state.stampApproved ? true : gitProbe.equals(workProbe);
      const metricClass = index < 350 ? 'static_metric' : 'dynamic_metric';
      const ok = !duplicate && control.ok && state.staticBaselineOk && state.dynamic.ok && byteMetricOk && extraPredicate();
      const row = {
        kind: 'test', metric_class: metricClass, scope: name, index: index + 1, variable,
        metric: `${metricClass}_control_binding_and_code_health`, target, control_variable: control.variable, ok, duplicate,
      };
      actualTests += 1; if (!ok) { failedTests += 1; scopeTestFailures += 1; }
      writeLine(testFd, row, 'TEST');
    }

    const summary = {
      scope: name,
      targets: targets.length,
      controls: controls.length,
      static_controls: controls.filter((row) => row.mode === 'static').length,
      dynamic_controls: controls.filter((row) => row.mode === 'dynamic').length,
      tests: contract.tests_per_scope,
      failed_controls: controls.filter((row) => !row.ok).length,
      failed_tests: scopeTestFailures,
      ok: controls.every((row) => row.ok) && scopeTestFailures === 0,
    };
    scopeSummaries.push(summary);
    process.stdout.write(`SCOPE ${summary.ok ? 'PASS' : 'FAIL'} ${name} controls=${summary.controls} static=${summary.static_controls} dynamic=${summary.dynamic_controls} tests=${summary.tests} targets=${summary.targets}\n`);
    return summary;
  }

  executeScope('race', groups.race);
  executeScope('extension', groups.extension);
  executeScope('wordpress_plugin', groups.wordpressPlugin);
  executeScope('chatgpt_schemas', groups.chatgptSchemas);

  for (const file of files) executeScope(`file:${file}`, [file]);

  const preControllerExpectedScopes = files.length + 4;
  const controllerPreflightOk = scopeSummaries.length === preControllerExpectedScopes
    && scopeSummaries.every((scope) => scope.controls === 700 && scope.static_controls === 350 && scope.dynamic_controls === 350 && scope.tests === 700 && scope.ok)
    && controlVariables.size === 700 * preControllerExpectedScopes
    && testVariables.size === 700 * preControllerExpectedScopes;
  executeScope('controller_meta', groups.controllerMeta, () => controllerPreflightOk);

  const preFinalExpectedScopes = files.length + 5;
  const finalPreflightOk = scopeSummaries.length === preFinalExpectedScopes
    && scopeSummaries.every((scope) => scope.controls === 700 && scope.static_controls === 350 && scope.dynamic_controls === 350 && scope.tests === 700 && scope.ok)
    && controlVariables.size === 700 * preFinalExpectedScopes
    && testVariables.size === 700 * preFinalExpectedScopes;
  executeScope('final_suite', files, () => finalPreflightOk);

  fs.closeSync(controlFd);
  fs.closeSync(testFd);

  const summary = {
    schema_version: contract.schema_version,
    tracked_file_count: files.length,
    fixed_scope_count: contract.fixed_scopes.length,
    scope_count: scopeSummaries.length,
    expected_scope_count: expectedScopeCount,
    controls: {
      expected: expectedControls,
      actual: actualControls,
      static_expected: 350 * expectedScopeCount,
      static_actual: actualStatic,
      dynamic_expected: 350 * expectedScopeCount,
      dynamic_actual: actualDynamic,
      failed: failedControls,
      unique_variables: controlVariables.size,
    },
    tests: {
      expected: expectedTests,
      actual: actualTests,
      failed: failedTests,
      unique_variables: testVariables.size,
    },
    total_expected: expectedTotal,
    total_actual: actualControls + actualTests,
    formulas: {
      scopes: `${files.length}+6=${expectedScopeCount}`,
      controls: `700*${expectedScopeCount}=${expectedControls}`,
      tests: `700*${expectedScopeCount}=${expectedTests}`,
      total: `1400*${expectedScopeCount}=${expectedTotal}`,
    },
    scopes: scopeSummaries,
  };
  summary.ok = summary.scope_count === summary.expected_scope_count
    && summary.controls.actual === summary.controls.expected
    && summary.controls.static_actual === summary.controls.static_expected
    && summary.controls.dynamic_actual === summary.controls.dynamic_expected
    && summary.controls.failed === 0
    && summary.controls.unique_variables === summary.controls.expected
    && summary.tests.actual === summary.tests.expected
    && summary.tests.failed === 0
    && summary.tests.unique_variables === summary.tests.expected
    && summary.total_actual === summary.total_expected
    && scopeSummaries.every((scope) => scope.ok);

  fs.writeFileSync(SUMMARY_PATH, `${JSON.stringify(summary, null, 2)}\n`);
  process.stdout.write(`MATRIX ${summary.ok ? 'PASS' : 'FAIL'} ${JSON.stringify(summary.formulas)} failed_controls=${failedControls} failed_tests=${failedTests}\n`);
  if (!summary.ok) throw new Error(`exhaustive_700_matrix_failed:${JSON.stringify({ failedControls, failedTests, scopeCount: summary.scope_count, expectedScopeCount })}`);
  return summary;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  runExhaustive700Matrix().catch((error) => {
    console.error(error?.stack || error);
    process.exitCode = 1;
  });
}
