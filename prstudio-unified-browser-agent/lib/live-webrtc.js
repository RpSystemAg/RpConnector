/*
 * Browser LIVE/WebRTC was removed from PR STUDIO.
 *
 * This inert compatibility module remains temporarily because service-worker.js
 * from already-installed extension builds imports these symbols during an
 * in-place update. It owns no media, signaling, context-menu, offscreen or
 * tab-capture behavior and can be deleted once that compatibility window ends.
 */

const removed = () => ({
  ok: false,
  removed: true,
  available: false,
  error: {
    code: 'BROWSER_LIVE_REMOVED',
    message: 'Browser LIVE streaming is not part of PR STUDIO.',
  },
});

export async function livePrepare() { return { ok: true, removed: true, available: false }; }
export async function liveSetupMenus() { return { ok: true, removed: true, available: false }; }
export async function liveHandleContextMenu() { return removed(); }
export async function liveHandleRuntimeMessage() { return removed(); }
export async function liveHandleInternalMessage() { return removed(); }
export async function liveOnCaptureStatusChanged() { return undefined; }
export const LIVE_CONTEXT_MENU_ID = '';
