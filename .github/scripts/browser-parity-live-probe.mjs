import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const path = resolve('artifacts/browser-parity/browser-parity-live-probe.json');
const report = JSON.parse(await readFile(path, 'utf8'));
const required = [
  'exact_mv3_worker_loaded',
  'required_chrome_api_surface',
  'three_tabs_same_window',
  'tabs_query_sees_all_controlled_tabs',
  'wait_ready_real_page',
  'fill_through_page_runtime',
  'click_through_page_runtime',
  'chrome_scripting_observes_real_mutation',
  'background_tab_remains_controllable',
  'tab_group_roundtrip',
  'page_runtime_reconnects_after_navigation',
  'page_runtime_reconnects_after_reload',
  'tab_go_back_keeps_runtime',
  'tab_go_forward_keeps_runtime',
  'visible_tab_screenshot_real',
  'service_worker_local_health_real',
  'mv3_worker_restarts_after_termination',
  'worker_accepts_messages_after_restart',
  'existing_page_runtime_survives_worker_restart',
  'service_worker_pipeline_recovers_after_restart',
  'closed_tab_is_really_gone',
  'closing_one_tab_does_not_break_others',
  'can_open_and_control_new_tab_after_close_restart',
  'final_controlled_tabs_healthy',
];
const byName = new Map((report.checks || []).map((row) => [row.name, row]));
const missing = required.filter((name) => !byName.get(name)?.ok);
if (report.ok !== true || missing.length) {
  throw new Error(`browser_parity_evidence_failed: report_ok=${report.ok} missing_or_failed=${missing.join(',')}`);
}
console.log(`PASS Browser parity evidence: ${required.length}/${required.length} critical live checks green on ${report.browser_version || 'unknown browser'}`);
