import assert from "node:assert/strict";
import { test } from "node:test";
import {
  LOCAL_STUDIO_FEATURES,
  featureAdvertisement,
  isRestrictedLocalUrl,
  isSensitiveFieldDescriptor,
  localStepMutates,
  normalizeWorkflowStep,
  pageHealthScore,
  validateLocalWorkflow,
} from "../lib/local-studio.js";

test("local studio is zero-account additive capability surface", () => {
  const ad = featureAdvertisement();
  assert.equal(ad.noExternalAccounts, true);
  assert.equal(ad.noApiKeys, true);
  assert.equal(ad.installationContractUnchanged, true);
  assert.ok(LOCAL_STUDIO_FEATURES.includes("standalone_mode"));
  assert.ok(LOCAL_STUDIO_FEATURES.includes("visual_recorder"));
  assert.ok(LOCAL_STUDIO_FEATURES.includes("scheduled_local_checks"));
});

test("restricted Chrome/internal URLs never enter local automation", () => {
  for (const url of ["chrome://settings", "about:blank", "devtools://devtools", "file:///tmp/test.html"]) {
    assert.equal(isRestrictedLocalUrl(url), true, url);
  }
  assert.equal(isRestrictedLocalUrl("https://example.com/test"), false);
});

test("recorder marks credential-like inputs sensitive", () => {
  assert.equal(isSensitiveFieldDescriptor({ type: "password" }), true);
  assert.equal(isSensitiveFieldDescriptor({ autocomplete: "one-time-code" }), true);
  assert.equal(isSensitiveFieldDescriptor({ name: "api_token" }), true);
  assert.equal(isSensitiveFieldDescriptor({ name: "company" }), false);
});

test("workflow validation is bounded and forbids arbitrary step types", () => {
  const workflow = validateLocalWorkflow({ name: "Smoke", steps: [
    { type: "navigate", url: "https://example.com" },
    { type: "click", locator: { css: ["#save"], role: "button", name: "Salva" } },
  ] });
  assert.equal(workflow.localOnly, true);
  assert.equal(workflow.steps.length, 2);
  assert.throws(() => normalizeWorkflowStep({ type: "evaluate", script: "alert(1)" }), /local_step_type_forbidden/);
  assert.equal(localStepMutates({ type: "click" }), true);
  assert.equal(localStepMutates({ type: "navigate" }), false);
});


test("imported workflows cannot persist secret-looking fill values", () => {
  const step = normalizeWorkflowStep({ type: "fill", locator: { name: "API token", css: ["#api_token"] }, label: "API token", value: "super-secret" });
  assert.equal(step.value, null);
  assert.equal(step.valuePolicy, "redacted");
});

test("page health score remains bounded", () => {
  assert.equal(pageHealthScore({ title: "ok", description: "ok", canonical: "x", viewport: "x", h1Count: 1 }), 100);
  const bad = pageHealthScore({ h1Count: 0, imagesMissingAlt: 99, unlabeledControls: 99, duplicateIdCount: 99, schemaParseErrors: 2, mixedContentCount: 1, badLinkCount: 99 });
  assert.ok(bad >= 0 && bad <= 100);
});
