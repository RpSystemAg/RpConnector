import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const sourceUrl = new URL("../lib/horizon-stability.js", import.meta.url);
const source = await readFile(sourceUrl, "utf8");
const horizon = await import(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);

const {
  evidenceDigest,
  pageSignature,
  createHorizonSession,
  evidenceStable,
  planHorizonStep,
  replayHorizonJournal,
} = horizon;

const PLAN_EVIDENCE = {
  url: "https://example.com/prodotto",
  title: "Prodotto 42",
  text: "Aggiungi al carrello, quantità 1, prezzo 19.90",
};

test("evidence digest is stable and content-sensitive", () => {
  assert.equal(evidenceDigest(PLAN_EVIDENCE), evidenceDigest(PLAN_EVIDENCE));
  assert.notEqual(
    evidenceDigest(PLAN_EVIDENCE),
    evidenceDigest({ ...PLAN_EVIDENCE, text: "Aggiungi al carrello, quantità 2" }),
    "a text mutation changes the digest"
  );
  assert.notEqual(
    evidenceDigest(PLAN_EVIDENCE),
    evidenceDigest({ ...PLAN_EVIDENCE, url: "https://example.com/altro" }),
    "a url mutation changes the digest"
  );
});

test("page signature is order-independent across keys", () => {
  const a = pageSignature({ text: "x", url: "y", title: "z" });
  const b = pageSignature({ title: "z", url: "y", text: "x" });
  assert.equal(a, b);
});

test("stable evidence keeps the multi-step plan on track", () => {
  const session = createHorizonSession({ previousEvidence: PLAN_EVIDENCE });
  const decision = planHorizonStep(
    { type: "click", selector: "#add-to-cart" },
    { session, liveEvidence: PLAN_EVIDENCE, multiStepRemaining: 3 }
  );
  assert.equal(decision.singleStepFallback, false);
  assert.equal(decision.reason, "evidence_stable");
});

test("page mutation mid-task degrades a multi-step flow to a fresh observation", () => {
  const session = createHorizonSession({ previousEvidence: PLAN_EVIDENCE });
  const mutated = { ...PLAN_EVIDENCE, text: "prodotto esaurito, torna al catalogo" };
  const decision = planHorizonStep(
    { type: "click", selector: "#add-to-cart", tabId: 3 },
    { session, liveEvidence: mutated, multiStepRemaining: 2 }
  );
  assert.equal(decision.singleStepFallback, true);
  assert.equal(decision.reason, "page_mutated_multi_step_fallback");
  assert.equal(decision.step.type, "observation_bundle");
  assert.equal(decision.step._prstudio_horizon_refresh, true);
});

test("single remaining step resteps against fresh evidence instead of replaying stale selectors", () => {
  const session = createHorizonSession({ previousEvidence: PLAN_EVIDENCE });
  const mutated = { ...PLAN_EVIDENCE, text: "contenuto completamente cambiato" };
  const decision = planHorizonStep(
    { type: "click", selector: "#old-selector" },
    { session, liveEvidence: mutated, multiStepRemaining: 0 }
  );
  assert.equal(decision.singleStepFallback, true);
  assert.equal(decision.reason, "page_mutated_single_step_restep");
  assert.equal(decision.step._prstudio_horizon_restep, true);
  assert.equal(decision.step.selector, "#old-selector", "the action survives, only the evidence is refreshed");
});

test("without plan evidence no fallback is forced", () => {
  const decision = planHorizonStep({ type: "click", selector: "#x" }, { session: null, liveEvidence: null });
  assert.equal(decision.singleStepFallback, false);
  assert.equal(decision.reason, "no_plan_evidence");
});

test("missing live evidence is treated as unstable", () => {
  const session = createHorizonSession({ previousEvidence: PLAN_EVIDENCE });
  assert.equal(evidenceStable(session, null), false);
  assert.equal(evidenceStable(session, PLAN_EVIDENCE), true);
});

test("deterministic replay: identical journals produce identical decisions", () => {
  const journal = [
    { step: { type: "click", value: "#a" }, planEvidence: PLAN_EVIDENCE, liveEvidence: PLAN_EVIDENCE, multiStepRemaining: 2 },
    { step: { type: "click", value: "#b" }, planEvidence: PLAN_EVIDENCE, liveEvidence: { ...PLAN_EVIDENCE, text: "mutato" }, multiStepRemaining: 1 },
    { step: { type: "fill", value: "#c" }, planEvidence: PLAN_EVIDENCE, liveEvidence: PLAN_EVIDENCE, multiStepRemaining: 0 },
  ];
  const session = createHorizonSession({ previousEvidence: PLAN_EVIDENCE });
  const replayA = replayHorizonJournal(journal, session);
  const replayB = replayHorizonJournal(journal, session);
  assert.deepEqual(replayA, replayB, "replay is deterministic");
  assert.equal(replayA[0].verdict, "proceed");
  assert.equal(replayA[1].verdict, "fallback");
  assert.equal(replayA[1].fallbackType, "observation_bundle");
  assert.equal(replayA[2].verdict, "proceed");
});
