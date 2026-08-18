import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const sourceUrl = new URL("../lib/observation-security.js", import.meta.url);
const source = await readFile(sourceUrl, "utf8");
const security = await import(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);

const {
  OBSERVATION_TRUST,
  REDACTED_VALUE,
  createObservationEnvelope,
  isSensitiveObservationKey,
  redactObservation,
} = security;

test("recursively redacts credentials, headers, bodies, form values and console handles", () => {
  const input = {
    password: "hunter2",
    nested: { otp: "123456", accessToken: "abc" },
    request: {
      headers: {
        Authorization: "Bearer secret",
        Cookie: "sid=secret",
        "Set-Cookie": "sid=secret",
        "X-API-Key": "api-secret",
        Accept: "application/json",
      },
      body: "credit-card-or-private-content",
    },
    form: [
      { tagName: "INPUT", type: "email", name: "email", value: "a@example.test" },
      { tagName: "INPUT", type: "password", name: "accountPassword", value: "secret" },
    ],
    console: { args: [{ type: "object", objectId: "remote.42", description: "Object" }] },
  };
  const snapshot = structuredClone(input);
  const result = redactObservation(input);

  assert.deepEqual(input, snapshot, "redaction must not mutate caller-owned observations");
  assert.equal(result.value.password, REDACTED_VALUE);
  assert.equal(result.value.nested.otp, REDACTED_VALUE);
  assert.equal(result.value.nested.accessToken, REDACTED_VALUE);
  assert.equal(result.value.request.headers.Authorization, REDACTED_VALUE);
  assert.equal(result.value.request.headers.Cookie, REDACTED_VALUE);
  assert.equal(result.value.request.headers["Set-Cookie"], REDACTED_VALUE);
  assert.equal(result.value.request.headers["X-API-Key"], REDACTED_VALUE);
  assert.equal(result.value.request.headers.Accept, "application/json");
  assert.equal(result.value.request.body, REDACTED_VALUE);
  assert.equal(result.value.form[0].value, REDACTED_VALUE);
  assert.equal(result.value.form[1].value, REDACTED_VALUE);
  assert.equal(result.value.console.args[0].objectId, REDACTED_VALUE);
  assert.equal(result.redactionCount, 11);
  assert.equal(result.truncated, false);
});

test("redacts secret URL query parameters without hiding safe parameters or fragments", () => {
  const result = redactObservation({
    url: "https://example.test/callback?code=abc&view=compact&access_token=def#done",
  });
  assert.equal(
    result.value.url,
    "https://example.test/callback?code=%5BREDACTED%5D&view=compact&access_token=%5BREDACTED%5D#done",
  );
  assert.equal(result.redactionCount, 2);
});

test("envelope fixes trust, preserves provenance and reports truncation", () => {
  const envelope = createObservationEnvelope({
    kind: "network_report",
    observedAt: "2026-08-08T10:00:00.000Z",
    provenance: {
      origin: "https://example.test",
      frameId: 7,
      documentId: "doc-1",
      url: "https://example.test/page?session_id=private",
    },
    data: { title: "report", samples: [1, 2], responseBody: "private" },
  }, { maxArrayLength: 1 });

  assert.equal(envelope.trust, OBSERVATION_TRUST);
  assert.equal(envelope.contentPolicy.instructionAuthority, "none");
  assert.equal(envelope.contentPolicy.executableInstructions, false);
  assert.equal(envelope.kind, "network_report");
  assert.equal(envelope.provenance.frameId, 7);
  assert.match(envelope.provenance.url, /session_id=%5BREDACTED%5D/);
  assert.equal(envelope.data.responseBody, REDACTED_VALUE);
  assert.equal(envelope.truncated, true);
  assert.ok(envelope.truncationCount >= 1);
  assert.equal(envelope.redactionCount, 2);
});

test("redacts inline bearer/JWT/OTP labels and accessibility textbox values", () => {
  const result = redactObservation({
    text: "authorization: Bearer abc.def-123 password=hunter2 otp: 123456",
    axNode: { role: { value: "textbox" }, value: { type: "string", value: "private-form-value" } },
  });
  assert.doesNotMatch(result.value.text, /abc\.def-123|hunter2|123456/);
  assert.equal(result.value.axNode.value, REDACTED_VALUE);
  assert.ok(result.redactionCount >= 4);
});

test("sensitive-key classifier covers externally supplied field names", () => {
  assert.equal(isSensitiveObservationKey("Authorization"), true);
  assert.equal(isSensitiveObservationKey("requestBody"), true);
  assert.equal(isSensitiveObservationKey("refreshToken"), true);
  assert.equal(isSensitiveObservationKey("contentType"), false);
});
