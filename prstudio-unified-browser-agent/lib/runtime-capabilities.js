export const SENSITIVE_RUNTIME_CONTRACT_ACTIONS = Object.freeze([
  "playwright_evaluate",
  "playwright_add_init_script",
  "playwright_get_cookies",
  "playwright_set_cookies",
  "playwright_clear_cookies",
  "playwright_get_storage_state",
  "playwright_set_storage_state",
  "playwright_clear_storage",
  "playwright_login_with_storage_state",
  "playwright_set_permissions",
  "playwright_clear_permissions",
]);

/**
 * Advanced Browser Agent actions that are executed by executeKnownContractAction.
 * Keep this set exact: contract-parity tests compare it to the actions emitted as
 * `contract_action` by protocol.js. Low-level security-restricted operations live
 * outside this registry and are exposed only through typed technical executors.
 */
export const RUNTIME_CONTRACT_ACTIONS = Object.freeze([
  "create_visual_baseline",
  "fetch",
  "html_diff",
  "playwright_abort_request",
  "playwright_cdp_subscribe",
  "playwright_checkout_smoke_test",
  "playwright_close_browser",
  "playwright_close_context",
  "playwright_connect_browser",
  "playwright_connect_over_cdp",
  "playwright_continue_request",
  "playwright_download_wait",
  "playwright_drag_and_drop",
  "playwright_export_trace",
  "playwright_find_elements",
  "playwright_form_smoke_test",
  "playwright_generate_test",
  "playwright_launch_chrome",
  "playwright_launch_chromium",
  "playwright_lighthouse_audit",
  "playwright_link_crawl",
  "playwright_list_contexts",
  "playwright_mask_dynamic_regions",
  "playwright_mock_response",
  "playwright_modify_response",
  "playwright_navigation_smoke_test",
  "playwright_new_context",
  "playwright_replay_har",
  "playwright_responsive_matrix",
  "playwright_route",
  "playwright_run_test",
  "playwright_run_test_suite",
  "playwright_search_smoke_test",
  "playwright_set_content",
  "playwright_set_input_files",
  "playwright_sitemap_crawl",
  "playwright_start_css_coverage",
  "playwright_start_har",
  "playwright_start_js_coverage",
  "playwright_start_trace",
  "playwright_start_video",
  "playwright_stop_css_coverage",
  "playwright_stop_har",
  "playwright_stop_js_coverage",
  "playwright_stop_trace",
  "playwright_stop_video",
  "playwright_unroute",
  "playwright_upload_file",
  "playwright_wait_for_request",
  "playwright_wait_for_response",
  "visual_diff",
  ...SENSITIVE_RUNTIME_CONTRACT_ACTIONS,
]);

const runtimeSet = new Set(RUNTIME_CONTRACT_ACTIONS);
const sensitiveRuntimeSet = new Set(SENSITIVE_RUNTIME_CONTRACT_ACTIONS);
export function hasRuntimeContractAction(action) {
  return typeof action === "string" && runtimeSet.has(action);
}
export function isSensitiveRuntimeContractAction(action) {
  return typeof action === "string" && sensitiveRuntimeSet.has(action);
}
