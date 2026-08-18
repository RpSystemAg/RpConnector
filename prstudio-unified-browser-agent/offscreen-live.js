/* PR STUDIO 17.0 offscreen MediaStream/WebRTC producer.
 * Media never passes through WordPress storage: only SDP/ICE/state metadata is
 * exchanged with the control plane.
 */
(() => {
  'use strict';
  const captures = new Map();

  function safeError(error) {
    return { code: String(error?.code || 'OFFSCREEN_ERROR').slice(0, 96), message: String(error?.message || error || 'Errore offscreen').slice(0, 500) };
  }

  async function runtime(message) {
    const result = await chrome.runtime.sendMessage({ target: 'prstudio-live-runtime-internal', ...message });
    if (!result?.ok && result?.error) {
      const error = new Error(result.error?.message || result.error);
      error.code = result.error?.code || 'LIVE_RUNTIME_ERROR';
      throw error;
    }
    return result;
  }

  async function emitDiagnostic(capture, gate, ok, detail = {}, phase = '') {
    return runtime({ type: 'agent_diagnostic', sessionId: capture.sessionId, tabId: capture.tabId, gate, ok, detail, phase }).catch(() => null);
  }

  async function emitState(capture, status, detail = {}) {
    return runtime({ type: 'agent_state', sessionId: capture.sessionId, tabId: capture.tabId, status, detail }).catch(() => null);
  }

  async function signal(capture, events = []) {
    const result = await runtime({ type: 'agent_exchange', sessionId: capture.sessionId, after: capture.after || 0, events });
    capture.after = Math.max(capture.after || 0, Number(result?.seq || 0));
    return Array.isArray(result?.events) ? result.events : [];
  }

  async function stopCapture(tabId, reason = 'stop', notifyServer = true) {
    tabId = Number(tabId || 0);
    const capture = captures.get(tabId);
    if (!capture) return { ok: true, stopped: false };
    capture.stopping = true;
    clearTimeout(capture.pollTimer);
    clearInterval(capture.statsTimer);
    try { capture.pc?.close(); } catch {}
    for (const track of capture.stream?.getTracks?.() || []) { try { track.stop(); } catch {} }
    captures.delete(tabId);
    if (notifyServer) await runtime({ type: 'agent_close', sessionId: capture.sessionId, tabId, reason }).catch(() => {});
    await emitState(capture, 'stopped', { reason }).catch(() => {});
    return { ok: true, stopped: true };
  }

  async function applyRemoteEvents(capture, events) {
    for (const event of events || []) {
      const type = String(event?.type || '');
      const payload = event?.payload || {};
      if (type === 'answer' && payload?.sdp && capture.pc.signalingState !== 'closed') {
        if (!capture.remoteAnswerApplied) {
          await capture.pc.setRemoteDescription({ type: 'answer', sdp: String(payload.sdp) });
          capture.remoteAnswerApplied = true;
          await emitDiagnostic(capture, '10_answer', true, {}, 'answer_applied');
          for (const candidate of capture.pendingRemoteIce.splice(0)) await capture.pc.addIceCandidate(candidate).catch(() => {});
        }
      } else if (type === 'ice' && payload?.candidate) {
        const candidate = {
          candidate: String(payload.candidate),
          sdpMid: payload.sdpMid == null ? null : String(payload.sdpMid),
          sdpMLineIndex: payload.sdpMLineIndex == null ? null : Number(payload.sdpMLineIndex),
          usernameFragment: payload.usernameFragment == null ? undefined : String(payload.usernameFragment),
        };
        if (capture.remoteAnswerApplied) await capture.pc.addIceCandidate(candidate).catch(() => {});
        else capture.pendingRemoteIce.push(candidate);
      } else if (type === 'stop') {
        await stopCapture(capture.tabId, 'viewer_stop');
        return;
      } else if (type === 'restart') {
        await restartIce(capture);
      }
    }
  }

  async function poll(capture) {
    if (capture.stopping || capture.pc?.connectionState === 'closed') return;
    try {
      const events = await signal(capture, []);
      await applyRemoteEvents(capture, events);
      capture.failures = 0;
    } catch (error) {
      capture.failures = (capture.failures || 0) + 1;
      if (capture.failures >= 8) {
        await emitDiagnostic(capture, '09_signaling_exchange', false, { message: error?.message || String(error) });
        await stopCapture(capture.tabId, 'signaling_failed');
        return;
      }
    }
    const connected = ['connected', 'completed'].includes(capture.pc?.iceConnectionState) || capture.pc?.connectionState === 'connected';
    capture.pollTimer = setTimeout(() => poll(capture), connected ? 2000 : 450);
  }

  async function restartIce(capture) {
    if (!capture?.pc || capture.pc.signalingState === 'closed') return;
    try {
      capture.pc.restartIce?.();
      const offer = await capture.pc.createOffer({ iceRestart: true });
      await capture.pc.setLocalDescription(offer);
      await signal(capture, [{ type: 'offer', payload: { sdp: capture.pc.localDescription?.sdp || offer.sdp || '', restart: true } }]);
      await emitState(capture, 'ice_restarting');
    } catch (error) {
      await emitDiagnostic(capture, '11_ice_connected', false, { message: error?.message || String(error) });
    }
  }

  function startStats(capture) {
    let lastBytes = 0, lastAt = performance.now();
    capture.statsTimer = setInterval(async () => {
      if (capture.stopping || capture.pc?.connectionState === 'closed') return;
      try {
        const reports = await capture.pc.getStats();
        let outbound = null;
        reports.forEach((report) => { if (report.type === 'outbound-rtp' && report.kind === 'video' && !report.isRemote) outbound = report; });
        if (!outbound) return;
        const now = performance.now();
        const bytes = Number(outbound.bytesSent || 0);
        const seconds = Math.max(0.001, (now - lastAt) / 1000);
        const kbps = lastBytes ? Math.round(((bytes - lastBytes) * 8) / 1000 / seconds) : 0;
        lastBytes = bytes; lastAt = now;
        const track = capture.stream?.getVideoTracks?.()?.[0];
        const settings = track?.getSettings?.() || {};
        const detail = {
          kbps,
          fps: Math.round(Number(outbound.framesPerSecond || settings.frameRate || 0)),
          width: Number(outbound.frameWidth || settings.width || 0),
          height: Number(outbound.frameHeight || settings.height || 0),
          framesEncoded: Number(outbound.framesEncoded || 0),
          framesSent: Number(outbound.framesSent || 0),
        };
        await emitState(capture, capture.pc.connectionState === 'connected' ? 'connected' : 'capture_active', detail);
        if (!capture.mediaGatePassed && detail.framesSent > 0 && bytes > 0) {
          capture.mediaGatePassed = true;
          await emitDiagnostic(capture, '12_media_stats', true, detail, 'connected');
        }
      } catch {}
    }, 2000);
  }

  async function startCapture({ tabId, sessionId, streamId }) {
    tabId = Number(tabId || 0); sessionId = String(sessionId || ''); streamId = String(streamId || '');
    if (!tabId || !sessionId || !streamId) throw Object.assign(new Error('Parametri MediaStream incompleti.'), { code: 'OFFSCREEN_START_ARGS' });
    if (captures.has(tabId)) return { ok: true, alreadyActive: true, phase: 'capture_active' };

    const capture = { tabId, sessionId, stream: null, pc: null, after: 0, pendingRemoteIce: [], failures: 0, stopping: false, remoteAnswerApplied: false, mediaGatePassed: false };
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: {
          mandatory: {
            chromeMediaSource: 'tab',
            chromeMediaSourceId: streamId,
            maxWidth: 2560,
            maxHeight: 1440,
            maxFrameRate: 60,
          },
        },
      });
      capture.stream = stream;
      captures.set(tabId, capture); // Own immediately so every later failure can clean up the track.
      await emitDiagnostic(capture, '06_get_user_media', true, {}, 'media_acquired');

      const track = stream.getVideoTracks()[0];
      if (!track || track.readyState !== 'live') throw Object.assign(new Error('La traccia video tabCapture non è live.'), { code: 'VIDEO_TRACK_NOT_LIVE' });
      try { track.contentHint = 'text'; } catch {}
      track.onended = () => stopCapture(tabId, 'track_ended').catch(() => {});
      await emitDiagnostic(capture, '07_video_track', true, { readyState: track.readyState }, 'video_track_live');

      const pc = new RTCPeerConnection({ iceServers: [] });
      capture.pc = pc;
      const sender = pc.addTrack(track, stream);
      try {
        const parameters = sender.getParameters();
        parameters.degradationPreference = 'maintain-resolution';
        parameters.encodings = parameters.encodings?.length ? parameters.encodings : [{}];
        parameters.encodings[0].maxBitrate = 12_000_000;
        parameters.encodings[0].maxFramerate = 60;
        await sender.setParameters(parameters);
      } catch {}

      pc.onicecandidate = (event) => {
        const c = event.candidate;
        if (!c) return;
        signal(capture, [{ type: 'ice', payload: { candidate: c.candidate, sdpMid: c.sdpMid, sdpMLineIndex: c.sdpMLineIndex, usernameFragment: c.usernameFragment || null } }]).catch(() => {});
      };
      pc.onconnectionstatechange = () => {
        const status = String(pc.connectionState || '');
        emitState(capture, status).catch(() => {});
        if (status === 'connected') emitDiagnostic(capture, '11_ice_connected', true, { connectionState: status, iceConnectionState: pc.iceConnectionState }, 'connected').catch(() => {});
        if (['failed', 'closed'].includes(status) && !capture.stopping) {
          if (status === 'failed') restartIce(capture).catch(() => {});
          else stopCapture(tabId, `pc_${status}`).catch(() => {});
        }
      };
      pc.oniceconnectionstatechange = () => {
        const ice = String(pc.iceConnectionState || '');
        if (ice === 'failed') restartIce(capture).catch(() => {});
      };

      const offer = await pc.createOffer({ offerToReceiveAudio: false, offerToReceiveVideo: false });
      await pc.setLocalDescription(offer);
      await signal(capture, [{ type: 'offer', payload: { sdp: pc.localDescription?.sdp || offer.sdp || '' } }]);
      await emitDiagnostic(capture, '09_offer', true, {}, 'offer_sent');
      startStats(capture);
      poll(capture).catch(() => {});
      return { ok: true, phase: 'offer_sent' };
    } catch (error) {
      await emitDiagnostic(capture, capture.stream ? '09_offer' : '06_get_user_media', false, { message: error?.message || String(error) });
      if (capture.stream || captures.has(tabId)) await stopCapture(tabId, 'start_failed');
      throw error;
    }
  }

  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message?.target !== 'prstudio-live-offscreen') return false;
    (async () => {
      if (message.type === 'start') return startCapture(message);
      if (message.type === 'stop') return stopCapture(Number(message.tabId || 0), String(message.reason || 'stop'));
      if (message.type === 'status') return { ok: true, captures: [...captures.values()].map((c) => ({ tabId: c.tabId, sessionId: c.sessionId, connectionState: c.pc?.connectionState || 'new', iceConnectionState: c.pc?.iceConnectionState || 'new', trackState: c.stream?.getVideoTracks?.()?.[0]?.readyState || 'ended' })) };
      return { ok: false, error: { code: 'OFFSCREEN_MESSAGE_UNKNOWN', message: 'Messaggio offscreen sconosciuto.' } };
    })().then((result) => sendResponse(result)).catch((error) => sendResponse({ ok: false, error: safeError(error) }));
    return true;
  });
})();
