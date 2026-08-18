export const LOCAL_STUDIO_VERSION = "1.0.0";

export const LOCAL_STUDIO_FEATURES = Object.freeze([
  "standalone_mode",
  "visual_recorder",
  "workflow_library",
  "smart_element_inspector",
  "selector_fallbacks",
  "flight_recorder",
  "page_health_audit",
  "debug_capture",
  "diagnostic_report_builder",
  "responsive_matrix",
  "semantic_diff",
  "bounded_site_scan",
  "visual_baseline",
  "workspace_manager",
  "command_palette",
  "scheduled_local_checks",
  "origin_permission_profiles",
  "local_recovery_console",
  "workflow_import_export",
]);

export const LOCAL_PROFILE_MODES = Object.freeze(["automation", "debug"]);
export const LOCAL_WORKFLOW_STEP_TYPES = Object.freeze([
  "navigate", "click", "fill", "select", "check", "press", "wait",
]);

const restrictedSchemes = /^(?:chrome|chrome-extension|edge|about|devtools|view-source):/i;
const sensitiveName = /(?:password|passwd|passphrase|secret|token|otp|one[-_ ]?time|auth(?:entication|orization)?)/i;

export function isRestrictedLocalUrl(url) {
  const value = String(url || "").trim();
  return !value || restrictedSchemes.test(value) || /^file:/i.test(value);
}

export function sanitizeLocalName(value, fallback = "Elemento") {
  return String(value || "")
    .replace(/[\u0000-\u001f\u007f]/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 160) || fallback;
}

export function isSensitiveFieldDescriptor(input = {}) {
  const type = String(input.type || "").toLowerCase();
  const autocomplete = String(input.autocomplete || "").toLowerCase();
  const joined = [input.name, input.id, input.label, input.placeholder, input.ariaLabel]
    .map((value) => String(value || ""))
    .join(" ");
  return type === "password"
    || autocomplete.includes("one-time-code")
    || autocomplete.includes("current-password")
    || autocomplete.includes("new-password")
    || sensitiveName.test(joined);
}

export function normalizeLocator(input = {}) {
  const css = Array.isArray(input.css)
    ? input.css.map((value) => String(value || "").trim()).filter(Boolean).slice(0, 8)
    : [];
  return {
    css,
    xpath: String(input.xpath || "").slice(0, 2048),
    role: String(input.role || "").slice(0, 120),
    name: sanitizeLocalName(input.name || input.accessibleName || "", ""),
    text: sanitizeLocalName(input.text || "", ""),
    tag: String(input.tag || "").toLowerCase().slice(0, 60),
    confidence: Math.max(0, Math.min(1, Number(input.confidence ?? 0.5) || 0.5)),
  };
}

export function normalizeWorkflowStep(input = {}) {
  const type = String(input.type || "").trim();
  if (!LOCAL_WORKFLOW_STEP_TYPES.includes(type)) throw new Error(`local_step_type_forbidden:${type || "missing"}`);
  const step = { type };
  if (type === "navigate") {
    const url = String(input.url || "").trim();
    if (!/^https?:\/\//i.test(url) || url.length > 8192) throw new Error("local_step_url_invalid");
    step.url = url;
  }
  if (["click", "fill", "select", "check", "press"].includes(type)) step.locator = normalizeLocator(input.locator || {});
  if (type === "fill" || type === "select") {
    const sensitive = type === "fill" && isSensitiveFieldDescriptor({
      name: step.locator?.name || "",
      id: step.locator?.css?.join(" ") || "",
      label: input.label || step.locator?.text || "",
      placeholder: step.locator?.text || "",
      ariaLabel: step.locator?.name || "",
    });
    step.value = sensitive || input.value == null ? null : String(input.value).slice(0, 4000);
    step.valuePolicy = sensitive || input.valuePolicy === "redacted" ? "redacted" : "local_plaintext";
  }
  if (type === "check") step.checked = Boolean(input.checked);
  if (type === "press") step.key = sanitizeLocalName(input.key || "Enter", "Enter").slice(0, 80);
  if (type === "wait") step.ms = Math.max(0, Math.min(60000, Number(input.ms || 1000)));
  step.label = sanitizeLocalName(input.label || type, type);
  step.recordedAt = Number(input.recordedAt || Date.now());
  return step;
}

export function validateLocalWorkflow(input = {}) {
  if (!input || typeof input !== "object" || Array.isArray(input)) throw new Error("local_workflow_invalid");
  const steps = Array.isArray(input.steps) ? input.steps : [];
  if (!steps.length) throw new Error("local_workflow_steps_required");
  if (steps.length > 200) throw new Error("local_workflow_step_limit");
  const normalized = steps.map(normalizeWorkflowStep);
  const serialized = JSON.stringify(normalized);
  if (serialized.length > 512000) throw new Error("local_workflow_too_large");
  return {
    id: String(input.id || "").replace(/[^a-zA-Z0-9_-]/g, "").slice(0, 80) || `wf_${Date.now().toString(36)}`,
    name: sanitizeLocalName(input.name || "Workflow locale", "Workflow locale"),
    createdAt: Number(input.createdAt || Date.now()),
    updatedAt: Date.now(),
    localOnly: true,
    schemaVersion: LOCAL_STUDIO_VERSION,
    steps: normalized,
  };
}

export function localStepMutates(step = {}) {
  return ["click", "fill", "select", "check", "press"].includes(String(step.type || ""));
}

export function normalizeOriginProfile(mode) {
  const value = String(mode || "automation");
  if (!LOCAL_PROFILE_MODES.includes(value)) throw new Error("local_profile_invalid");
  return value;
}

export function pageHealthScore(input = {}) {
  let score = 100;
  const subtract = (condition, points) => { if (condition) score -= points; };
  subtract(!input.title, 10);
  subtract(!input.description, 8);
  subtract(!input.canonical, 5);
  subtract(!input.viewport, 5);
  subtract(Number(input.h1Count || 0) !== 1, 8);
  subtract(Number(input.imagesMissingAlt || 0) > 0, Math.min(12, Number(input.imagesMissingAlt || 0) * 2));
  subtract(Number(input.unlabeledControls || 0) > 0, Math.min(12, Number(input.unlabeledControls || 0) * 2));
  subtract(Number(input.duplicateIdCount || 0) > 0, Math.min(10, Number(input.duplicateIdCount || 0) * 2));
  subtract(Number(input.schemaParseErrors || 0) > 0, 8);
  subtract(Number(input.mixedContentCount || 0) > 0, 12);
  subtract(Number(input.badLinkCount || 0) > 0, Math.min(10, Number(input.badLinkCount || 0)));
  return Math.max(0, Math.min(100, score));
}

export function featureAdvertisement() {
  return {
    version: LOCAL_STUDIO_VERSION,
    localOnly: true,
    noExternalAccounts: true,
    noApiKeys: true,
    installationContractUnchanged: true,
    features: [...LOCAL_STUDIO_FEATURES],
  };
}
