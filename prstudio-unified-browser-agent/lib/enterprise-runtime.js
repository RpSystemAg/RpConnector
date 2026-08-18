/** Technical transport retry cadence only. No mission budget or duplicate-action veto. */
function boundedCount(value, max) {
  if (typeof value !== "number" || !Number.isFinite(value) || value <= 0) return 0;
  return Math.min(max, Math.ceil(value));
}

export function adaptivePollDelay(input = {}) {
  const source = input && typeof input === "object" ? input : {};
  const errors = boundedCount(source.errorCount, 6);
  if (errors > 0) return Math.min(30000, 500 * (2 ** errors));
  const idle = boundedCount(source.idleCount, 7);
  if (idle <= 0) return 0;
  const schedule = [100, 150, 250, 400, 550, 700, 750];
  return schedule[idle - 1];
}
