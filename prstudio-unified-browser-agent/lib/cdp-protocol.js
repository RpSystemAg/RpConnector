export const DEBUGGER_PROTOCOL_CANDIDATES = Object.freeze(["1.3", "0.1"]);

async function enableBrowserDownloadEvents(debuggerApi, target) {
  try {
    await debuggerApi.sendCommand(target, "Browser.setDownloadBehavior", {
      behavior: "default",
      eventsEnabled: true,
    });
  } catch (error) {
    const wrapped = new Error("Browser download events could not be enabled after debugger attachment.");
    wrapped.code = "cdp_download_events_unavailable";
    wrapped.details = { message: String(error?.message || error).slice(0, 240) };
    throw wrapped;
  }
}

export async function attachWithProtocolFallback(debuggerApi, target, isAttached = async () => false) {
  const errors = [];
  for (const protocolVersion of DEBUGGER_PROTOCOL_CANDIDATES) {
    try {
      await debuggerApi.attach(target, protocolVersion);
      await enableBrowserDownloadEvents(debuggerApi, target);
      return { ok: true, protocolVersion, fallbackUsed: protocolVersion !== DEBUGGER_PROTOCOL_CANDIDATES[0], errors };
    } catch (error) {
      if (error?.code === "cdp_download_events_unavailable") throw error;
      const message = String(error?.message || error);
      let attached;
      try {
        attached = await isAttached();
      } catch (stateError) {
        const verificationError = new Error("Debugger attach state could not be verified after attach failure.");
        verificationError.code = "cdp_attach_state_unverified";
        verificationError.details = { protocolVersion, attachError: message.slice(0, 240), stateError: String(stateError?.message || stateError).slice(0, 240) };
        throw verificationError;
      }
      if (attached) {
        if (/already attached|Another debugger is already attached/i.test(message)) {
          await enableBrowserDownloadEvents(debuggerApi, target);
          return { ok: true, protocolVersion, fallbackUsed: protocolVersion !== DEBUGGER_PROTOCOL_CANDIDATES[0], alreadyAttached: true, errors };
        }
        const ambiguousError = new Error("Debugger attach failed but target reports attached; protocol replay is unsafe without a clean state.");
        ambiguousError.code = "cdp_attach_state_ambiguous";
        ambiguousError.details = { protocolVersion, attachError: message.slice(0, 240) };
        throw ambiguousError;
      }
      errors.push({ protocolVersion, message: message.slice(0, 240) });
    }
  }
  const error = new Error("No compatible Chrome DevTools Protocol version could be negotiated.");
  error.code = "cdp_protocol_incompatible";
  error.details = { candidates: [...DEBUGGER_PROTOCOL_CANDIDATES], errors };
  throw error;
}
