import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeGscDimensions, unsupportedGscDimensions, gscDimensionAliases, headerMatchesGscDimension, mergeGscDimensionCollections } from '../lib/gsc-session.js';

test('GSC Browser dimensions match Search Console table dimensions exposed in 2026', () => {
  assert.deepEqual(normalizeGscDimensions(['query','page','country','device','date','searchAppearance']), ['query','page','country','device','date','searchAppearance']);
  assert.deepEqual(normalizeGscDimensions(['dates','Search appearance']), ['date','searchAppearance']);
  assert.equal(headerMatchesGscDimension('Aspetto nella ricerca', 'searchAppearance'), true);
  assert.equal(headerMatchesGscDimension('Date', 'date'), true);
  assert.equal(gscDimensionAliases('searchAppearance').includes('search appearance'), true);
});

test('hour is explicitly detected as unsupported by the Browser table collector', () => {
  assert.deepEqual(unsupportedGscDimensions(['hour']), ['hour']);
  assert.deepEqual(unsupportedGscDimensions(['query','date']), []);
});


test("GSC collections expose row exhaustiveness and totals provenance explicitly", () => {
  const merged = mergeGscDimensionCollections([{
    dimension: "query",
    rows: [{ query: "example", clicks: 1 }],
    row_count: 1,
    dimension_integrity: { status: "verified" },
  }], ["query"]);
  assert.equal(merged.collection_completeness, "bounded_by_search_console_and_observed_ui");
  assert.equal(merged.row_exhaustiveness, "not_guaranteed");
  assert.equal(merged.totals_scope, "active_search_console_report_and_dimension");
});
