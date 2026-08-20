/**
 * Keep the agent's tabs together, and out of the way.
 *
 * WHY
 * ---
 * The Browser Agent attaches to the Chrome the operator is already using -- that
 * part is correct and deliberate, because the value of this product is acting in
 * a real logged-in browser rather than a sterile one. What was missing is the
 * other half: the agent's tabs were created loose in whatever window it found,
 * interleaved with the operator's own tabs.
 *
 * Reported plainly while watching it work: the reference tooling attaches to the
 * same Chrome, but works in its own group, in the background, without getting in
 * the way. Both people should be able to work at once. That is the model, and
 * the reference implementation does it by putting every tab it opens into a
 * dedicated tab group.
 *
 * This extension had neither the tabGroups permission nor a single line of
 * grouping code, so there was nothing to fix in the logic -- the capability was
 * simply absent.
 *
 * WHAT THIS CHANGES, AND WHAT IT DOES NOT
 * ---------------------------------------
 * It changes where a tab is filed. It does not change ownership, lanes,
 * navigation, input dispatch or any verification. Grouping cannot fail an
 * action: under LAW 1 nothing here is allowed to stop a mutation, and every
 * grouping call is best-effort. A Chrome that refuses to group a tab must still
 * let the task run in it.
 *
 * The group is deliberately NOT collapsed. A collapsed group hides what the
 * agent is doing, and this suite has spent a week removing places where work
 * happened invisibly.
 */

/** Shown on the tab group so the operator can tell whose tabs these are. */
export const AGENT_GROUP_TITLE = "PR STUDIO Agent";

/**
 * Chrome accepts one of a fixed set of colour names for a tab group.
 * Green matches the pointer overlay drawn during input, so the two visual
 * signals of "the agent is working here" agree with each other.
 */
export const AGENT_GROUP_COLOR = "green";

/**
 * Whether a stored group reference can still be used.
 *
 * A group id survives in storage across service-worker restarts, but the group
 * itself does not survive the operator closing its last tab, and it cannot be
 * used from a different window. Both cases have to send us back to creating one,
 * and neither is an error.
 *
 * @param {unknown} storedGroupId Group id from storage.
 * @param {unknown} liveGroup The group Chrome returned, or null when it is gone.
 * @param {unknown} windowId Window the tab being filed lives in.
 * @returns {boolean}
 */
export function isReusableGroup(storedGroupId, liveGroup, windowId) {
  const id = Number(storedGroupId || 0);
  if (!id || id < 0) return false;
  if (!liveGroup || typeof liveGroup !== "object") return false;
  if (Number(liveGroup.id || 0) !== id) return false;
  // A tab can only join a group in its own window.
  return Number(liveGroup.windowId || 0) === Number(windowId || 0);
}

/**
 * Tabs that should be filed into the agent group.
 *
 * Only tabs the agent owns. An operator tab that happens to sit in the same
 * window is never touched -- moving somebody else's tab into a group is exactly
 * the "getting in the way" this exists to prevent.
 *
 * @param {Array<{tabId?: number, windowId?: number}>} owned Owned tab records.
 * @param {unknown} windowId Window to file into.
 * @returns {number[]} Tab ids, de-duplicated, in a stable order.
 */
export function groupableTabIds(owned = [], windowId = 0) {
  const target = Number(windowId || 0);
  const seen = new Set();
  const ids = [];
  for (const record of Array.isArray(owned) ? owned : []) {
    const id = Number(record?.tabId || 0);
    if (!id || seen.has(id)) continue;
    if (target && Number(record?.windowId || 0) !== target) continue;
    seen.add(id);
    ids.push(id);
  }
  return ids;
}
