// One-way persisted-state migration only. This file is intentionally isolated
// from runtime routing: legacy execution-governance states are deleted here and
// are never interpreted as executable states by the Browser Agent.
const LEGACY_KEYS = Object.freeze([
  "prstudioPendingTakeovers",
  "prstudioTakeoverCleanupQueue",
]);

export async function migrateOneGuardLegacyState(storage, suiteVersion, details = {}) {
  const markerKey = "prstudioSuiteMigration";
  const activeKey = "prstudioActiveTask";
  const stored = await storage.get([markerKey, activeKey, ...LEGACY_KEYS]).catch(() => ({}));
  const marker = stored?.[markerKey] || {};
  if (String(marker.oneGuardCompletedFor || "") === String(suiteVersion || "")) {
    return { skipped: true, reason: "already_migrated" };
  }
  const active = stored?.[activeKey] || null;
  const legacyActive = Boolean(active?.takeover || ["takeover", "human_takeover", "resuming"].includes(String(active?.phase || "")));
  const remove = [...LEGACY_KEYS, ...(legacyActive ? [activeKey] : [])];
  await storage.remove(remove);
  const migration = {
    ...marker,
    oneGuardCompletedFor: String(suiteVersion || ""),
    previousVersion: String(details.previousVersion || ""),
    completedAt: new Date().toISOString(),
    legacyExecutionStateDeleted: true,
    legacyActiveDeleted: legacyActive,
  };
  await storage.set({ [markerKey]: migration });
  return { ok: true, migration, removed: remove.length };
}
