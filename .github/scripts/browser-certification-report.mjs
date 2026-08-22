import fs from 'node:fs';
import path from 'node:path';

const [tapPath = 'artifacts/browser-certification/browser-tests.tap', outputPath = 'artifacts/browser-certification/browser-extension-certification.json'] = process.argv.slice(2);
if (!fs.existsSync(tapPath)) throw new Error(`TAP evidence missing: ${tapPath}`);
const tap = fs.readFileSync(tapPath, 'utf8');
const summary = Object.fromEntries([...tap.matchAll(/^# (tests|pass|fail|skipped|todo) (\d+)$/gm)].map((m) => [m[1], Number(m[2])]));
for (const key of ['tests', 'pass', 'fail', 'skipped', 'todo']) {
  if (!Number.isInteger(summary[key])) throw new Error(`TAP summary missing ${key}`);
}
const names = [...tap.matchAll(/^# Subtest: (.+)$/gm)].map((m) => m[1]);
const count = (rx) => names.filter((name) => rx.test(name)).length;
const report = {
  schema_version: '1.0.0',
  component: 'prstudio-browser-agent',
  total_checks: summary.tests,
  passed: summary.pass,
  failed: summary.fail,
  skipped: summary.skipped,
  todo: summary.todo,
  unit: count(/^(?!.*(?:real chrome|remote e2e))/i),
  integration: count(/integration|contract|protocol|replacement|message|storage|pair|task/i),
  real_chrome: 0,
  remote_e2e: 0,
  security: count(/security|invalid|forbidden|permission|regex|scheme|sender|CSP/i),
  contracts: count(/contract|protocol|schema|manifest|action maps/i),
  ownership: count(/ownership|replacement|lane|affinity/i),
  cdp: count(/CDP|debugger/i),
  mcp_alignment: 0,
  build_identity: 0,
  source: path.basename(tapPath),
};
const errors = [];
if (report.total_checks < 700) errors.push(`browser_extension_assertions ${report.total_checks} < 700`);
if (report.passed !== report.total_checks) errors.push(`passed ${report.passed} != total ${report.total_checks}`);
if (report.failed !== 0) errors.push(`failed ${report.failed} != 0`);
if (report.skipped !== 0) errors.push(`skipped ${report.skipped} != 0`);
if (report.todo !== 0) errors.push(`todo ${report.todo} != 0`);
report.gate = errors.length ? 'failed' : 'passed';
report.errors = errors;
fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${JSON.stringify(report, null, 2)}\n`);
console.log(JSON.stringify(report, null, 2));
if (errors.length) process.exitCode = 1;
