#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { join, dirname, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

// scripts/ -> prstudio-extension-verify/ -> skills/ -> .claude/ -> extension root
const scriptDir = dirname(fileURLToPath(import.meta.url));
const extDir = join(scriptDir, '..', '..', '..', '..');

console.log(`Cartella estensione: ${extDir}\n`);

let manifestOk = true;
try {
  JSON.parse(readFileSync(join(extDir, 'manifest.json'), 'utf8'));
  console.log('manifest.json: JSON valido');
} catch (error) {
  manifestOk = false;
  console.log(`manifest.json: NON VALIDO — ${error.message}`);
}

function listJsFiles(dir, out = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (['tests', 'node_modules', '.claude', 'icons'].includes(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) listJsFiles(full, out);
    else if (entry.isFile() && extname(entry.name) === '.js') out.push(full);
  }
  return out;
}

const jsFiles = listJsFiles(extDir);
const syntaxFailures = [];
for (const file of jsFiles) {
  try {
    execFileSync(process.execPath, ['--check', file], { stdio: 'pipe' });
  } catch (error) {
    syntaxFailures.push({ file, error: String(error.stderr || error.message) });
  }
}
const syntaxOk = syntaxFailures.length === 0;
console.log(`Sintassi: ${jsFiles.length} file .js controllati, ${syntaxFailures.length} con errori`);
for (const failure of syntaxFailures) {
  console.log(`  ERRORE in ${failure.file}`);
  console.log(`  ${failure.error.trim().split('\n').slice(0, 4).join('\n  ')}`);
}

let testsOk = true;
let testOutput = '';
const testFiles = readdirSync(join(extDir, 'tests'))
  .filter((f) => f.endsWith('.test.mjs'))
  .map((f) => join('tests', f));
try {
  testOutput = execFileSync(process.execPath, ['--test', ...testFiles], { cwd: extDir, encoding: 'utf8' });
} catch (error) {
  testsOk = false;
  testOutput = String(error.stdout || '') + String(error.stderr || '');
}
const pass = Number((testOutput.match(/ℹ pass (\d+)/) || [])[1] ?? NaN);
const fail = Number((testOutput.match(/ℹ fail (\d+)/) || [])[1] ?? NaN);
const total = Number((testOutput.match(/ℹ tests (\d+)/) || [])[1] ?? NaN);
if (Number.isFinite(fail) && fail > 0) testsOk = false;

console.log(`\nTest: ${Number.isFinite(pass) ? pass : '?'}/${Number.isFinite(total) ? total : '?'} passati${fail ? `, ${fail} falliti` : ''}`);
if (!testsOk) {
  for (const line of testOutput.split('\n')) if (line.startsWith('✖')) console.log(`  ${line}`);
}

const allOk = manifestOk && syntaxOk && testsOk;
console.log(`\n${'='.repeat(40)}`);
console.log(allOk
  ? 'TUTTO PULITO: manifest valido, sintassi ok, tutti i test passano.'
  : 'PROBLEMA TROVATO: vedi sopra prima di dire che e\' pronto.');
console.log("Prossimo passo (sempre manuale): ricarica l'estensione da chrome://extensions.");
console.log('='.repeat(40));

process.exit(allOk ? 0 : 1);
