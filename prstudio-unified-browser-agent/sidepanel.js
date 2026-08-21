const $ = (id) => document.getElementById(id);
const send = (type, extra = {}) => chrome.runtime.sendMessage({ type, ...extra });

function show(message, error = false) {
  $("message").textContent = message || "";
  $("message").style.color = error ? "#a32020" : "#1f2328";
}

function bridgeSummary(bridge) {
  if (!bridge?.ok) return "offline";
  return `${bridge.version || "online"} · ${Array.isArray(bridge.providers) ? bridge.providers.length : 0} provider`;
}

async function refresh() {
  const status = await send("status");
  const paired = Boolean(status?.paired);
  $("connected").hidden = !paired;
  $("pairing").hidden = paired;
  $("connectionText").textContent = paired ? (status.authExpired ? "chiave scaduta" : "associato") : "non associato";
  $("deviceId").textContent = status?.deviceId || "—";
  $("site").textContent = status?.siteUrl || "—";
  $("executor").textContent = `${status?.backend || "—"} · protocollo ${status?.executorProtocolVersion || "—"}`;
  $("bridgeState").textContent = bridgeSummary(status?.bridge);
  $("providers").textContent = Array.isArray(status?.bridge?.providers) ? status.bridge.providers.join(", ") : "—";
  $("pollingState").textContent = status?.polling ? "attivo" : "fermo";
  $("taskState").textContent = status?.activeTask ? `${status.activeTask.action} · ${status.activeTask.taskId}` : "nessuno";
  $("authWarning").hidden = !status?.authExpired;
  $("cancelButton").disabled = !status?.activeTask;
  $("bridgeBadge").textContent = status?.bridge?.ok ? "MCP ON" : "MCP OFF";
  $("bridgeDetail").textContent = JSON.stringify(status?.bridge || {}, null, 2);
  if (status?.state?.lastError) show(`${status.state.lastError.code}: ${status.state.lastError.message}`, true);
}

$("pairButton").addEventListener("click", async () => {
  $("pairButton").disabled = true;
  show("Associazione in corso…");
  try {
    const result = await send("pair", {
      siteUrl: $("siteUrl").value.trim(),
      code: $("pairCode").value.trim(),
      name: $("deviceName").value.trim()
    });
    if (!result?.ok) throw new Error(result?.error?.message || result?.error || "Pairing non riuscito");
    $("pairCode").value = "";
    show("Dispositivo associato. Il pairing WordPress è invariato.");
    await refresh();
  } catch (error) {
    show(error?.message || String(error), true);
  } finally {
    $("pairButton").disabled = false;
  }
});

$("refreshButton").addEventListener("click", refresh);
$("bridgeCheckButton").addEventListener("click", async () => {
  const result = await send("bridge_health");
  $("bridgeDetail").textContent = JSON.stringify(result || {}, null, 2);
  show(result?.ok ? "Bridge MCP raggiungibile." : "Bridge MCP non raggiungibile.", !result?.ok);
  await refresh();
});
$("cancelButton").addEventListener("click", async () => {
  const result = await send("cancel");
  show(result?.cancelled ? "Task annullato." : "Nessun task attivo.");
  await refresh();
});
$("forgetButton").addEventListener("click", async () => {
  await send("unpair");
  show("Pairing rimosso solo da questa estensione.");
  await refresh();
});

refresh().catch((error) => show(error?.message || String(error), true));
setInterval(() => refresh().catch(() => {}), 5000);
