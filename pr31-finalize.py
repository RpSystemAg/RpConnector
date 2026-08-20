from pathlib import Path
import re

ROOT = Path(__file__).resolve().parent

def read(path):
    return (ROOT / path).read_text(encoding="utf-8")

def write(path, text):
    (ROOT / path).write_text(text, encoding="utf-8")

def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f"PR31 missing expected {label}")
    return text.replace(old, new, 1)

def sub_once(text, pattern, repl, label, flags=0):
    out, n = re.subn(pattern, repl, text, count=1, flags=flags)
    if n != 1:
        raise SystemExit(f"PR31 expected exactly one {label}, found {n}")
    return out

# MCP: remove Browser LIVE resource, tools, dispatch and priority/profile references.
p = "prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php"
s = read(p)
s = replace_once(s, "    private const BROWSER_LIVE_URI = 'ui://prstudio/browser-live-v2.html';\n", "", "MCP live URI")
s = sub_once(s, r"^\s*array\('uri'=>self::BROWSER_LIVE_URI,'name'=>'PR STUDIO Browser LIVE'.*$\n?", "", "MCP resources/list live row", re.M)
s = sub_once(s, r"^\s*if\(\$uri===self::BROWSER_LIVE_URI\).*browser_live_html\(\).*?$\n?", "", "MCP resources/read live row", re.M)
s = sub_once(
    s,
    r"\n    private static function browser_live_html\(\): string \{.*?\n    \}\n\n    /\*\*\n     \* Compact model-facing operating contract\.",
    "\n\n    /**\n     * Compact model-facing operating contract.",
    "MCP Browser LIVE HTML",
    re.S,
)
s = s.replace("        if('browser_live_attach'===$name){$tool['_meta']=array('ui'=>array('resourceUri'=>self::BROWSER_LIVE_URI,'visibility'=>array('model','app')),'openai/outputTemplate'=>self::BROWSER_LIVE_URI,'openai/widgetAccessible'=>true);}\n", "")
s = s.replace("        if(in_array($name,array('browser_live_signal','browser_live_stop'),true)){$tool['_meta']=array('ui'=>array('visibility'=>array('app')),'openai/widgetAccessible'=>true);}\n", "")
s, n = re.subn(r"^\s*\$tools\[\]=self::tool\('browser_live_(?:attach|signal|stop|status)'.*$\n?", "", s, flags=re.M)
if n != 4:
    raise SystemExit(f"PR31 expected four browser_live tool definitions, found {n}")
s = sub_once(s, r"\n\s*case 'browser_live_attach': \{.*?\n\s*case 'browser_live_status': return PRSTUDIO_UC_Browser_Live::status\(\);", "", "MCP Browser LIVE dispatch", re.S)
s = s.replace("        'browser_find','browser_live_attach','browser_live_signal','browser_live_stop',\n", "        'browser_find',\n")
s = s.replace("        'browser' => array( 'browser_open', 'browser_navigate', 'browser_snapshot', 'browser_click', 'browser_fill', 'browser_screenshot', 'browser_tabs', 'browser_live_attach', 'browser_live_status' ),", "        'browser' => array( 'browser_open', 'browser_navigate', 'browser_snapshot', 'browser_click', 'browser_fill', 'browser_screenshot', 'browser_tabs' ),")
s = s.replace("Open prstudio_context_open only for browser/live concurrency", "Open prstudio_context_open only for browser concurrency")
s = s.replace("distinguish browser-live, API, cache and memory evidence", "distinguish browser-agent, API, cache and memory evidence")
s = s.replace(
    "        // Recording a run so a person can watch it back. The extension already\n        // streams a live session over WebRTC, which is a different thing: a\n        // stream is for watching now, a recording is for reviewing what\n        // happened. The catalogue action existed; nothing could ask for it.\n",
    "        // Recording remains an explicit review artifact, independent from the removed live-streaming surface.\n",
)
s = s.replace("Start or stop a video recording of the controlled tab, so a run can be reviewed after it finishes rather than only watched live.", "Start or stop a video recording of the controlled tab so a run can be reviewed after it finishes.")
s = re.sub(r"\n\s*// Browser LIVE tools must be listed.*?\n", "\n", s)
s = re.sub(r"\n\s*// browser_batch remains searchable\. LIVE attach/signaling/stop are not\n\s*// replaceable because the widget needs all three in its WebRTC loop\.\n", "\n", s)
for needle in ("browser_live_", "BROWSER_LIVE_URI", "PRSTUDIO_UC_Browser_Live", "browser_live_html"):
    if needle in s:
        raise SystemExit(f"PR31 MCP still contains {needle}")
write(p, s)

# REST: delete Browser LIVE signaling routes/handlers and capability advertisement.
p = "prstudio-unified-control/includes/class-prstudio-uc-rest.php"
s = read(p)
s = sub_once(
    s,
    r"\n\s*register_rest_route\(\n\s*self::NS,\n\s*'/stream/session',.*?(?=\n\s*register_rest_route\(\n\s*self::NS,\n\s*'/artifact/screenshot')",
    "\n",
    "REST streaming routes",
    re.S,
)
s = sub_once(s, r"\n\tpublic static function stream_session_create\(.*?(?=\n\tpublic static function screenshot_status\(\): array)", "\n", "REST streaming handlers", re.S)
s = re.sub(r"^\s*'browser_live_webrtc'\s*=>.*$\n?", "", s, flags=re.M)
for needle in ("/stream/session", "PRSTUDIO_UC_Browser_Live", "browser_live_webrtc"):
    if needle in s:
        raise SystemExit(f"PR31 REST still contains {needle}")
write(p, s)

# Public schema refinements: removed tool is no longer a public target.
p = "prstudio-unified-control/includes/class-prstudio-uc-public-tool-contracts.php"
s = read(p)
s = s.replace("        'browser_live_status',\n", "")
if "case 'browser_live_status':" in s:
    s = sub_once(s, r"\n\s*case 'browser_live_status':\n.*?\n\s*break;", "", "public Browser LIVE contract", re.S)
if "browser_live_status" in s:
    raise SystemExit("PR31 public contracts still contain browser_live_status")
write(p, s)

# Browser Agent service worker: remove every LIVE import/hook/message path while leaving normal automation intact.
p = "prstudio-unified-browser-agent/service-worker.js"
s = read(p)
live_import = '''import {
  livePrepare,
  liveSetupMenus,
  liveHandleContextMenu,
  liveHandleRuntimeMessage,
  liveHandleInternalMessage,
  liveOnCaptureStatusChanged,
} from "./lib/live-webrtc.js";
'''
s = replace_once(s, live_import, "", "worker Browser LIVE import")
s = s.replace("  await liveSetupMenus().catch(logError);\n", "")
s = s.replace("  await livePrepare().catch(logError);\n", "")
s = s.replace("  liveSetupMenus().catch(logError);\n", "")
s = s.replace("  livePrepare().catch(logError);\n", "")
s = sub_once(s, r"\nchrome\.contextMenus\?\.onClicked\?\.addListener\(\(info, tab\) => \{\n\s*liveHandleContextMenu\(info, tab\)\.catch\(logError\);\n\}\);", "", "worker live context menu")
s = sub_once(s, r"\n// action\.onClicked is a real activeTab invocation.*?\nchrome\.action\?\.onClicked\?\.addListener\(\(tab\) => \{.*?\n\}\);", "", "worker live action grant", re.S)
s = sub_once(s, r"\nchrome\.tabCapture\?\.onStatusChanged\?\.addListener\(\(info\) => \{\n\s*liveOnCaptureStatusChanged\(info\)\.catch\(logError\);\n\}\);", "", "worker tabCapture hook")
s = sub_once(s, r"\n\s*if \(message\?\.target === \"prstudio-live-runtime\"\) \{\n\s*sendResponse\(await liveHandleRuntimeMessage\(message, sender\)\);\n\s*return;\n\s*\}", "", "worker live runtime message")
s = sub_once(s, r"\n\s*if \(message\?\.target === \"prstudio-live-runtime-internal\"\) \{\n\s*sendResponse\(await liveHandleInternalMessage\(message, sender\)\);\n\s*return;\n\s*\}", "", "worker live internal message")
s = s.replace('    // Panel state notifications are consumed by sidepanel.js. The worker must\n    // not race it with an "unknown_message" response.\n    if (message?.target === "prstudio-live-panel") return;\n', "")
for needle in ("livePrepare", "liveSetupMenus", "liveHandleContextMenu", "liveHandleRuntimeMessage", "liveHandleInternalMessage", "liveOnCaptureStatusChanged", "prstudio-live-runtime", "prstudio-live-panel", "chrome.tabCapture", "chrome.contextMenus"):
    if needle in s:
        raise SystemExit(f"PR31 worker still contains {needle}")
write(p, s)

# Side panel logic: delete only the Browser LIVE block and polling/listeners.
p = "prstudio-unified-browser-agent/sidepanel.js"
s = read(p)
s = replace_once(s, "const sendLive = (type, extra = {}) => chrome.runtime.sendMessage({ target: 'prstudio-live-runtime', type, ...extra });\n", "", "sidepanel sendLive")
s = sub_once(s, r"\n// Chrome grants activeTab only to the tab that was active at a recognized.*?function tabIsGranted\(tabId\) \{.*?\}\n", "\n", "sidepanel LIVE grant state", re.S)
s = sub_once(s, r"\nasync function activeLiveTab\(\) \{.*?(?=\n// Remote/pairing contract: intentionally unchanged\.)", "\n", "sidepanel Browser LIVE block", re.S)
s = re.sub(r"^activeLiveTab\(\).*?$\n?", "", s, flags=re.M)
s = s.replace("  createRefreshLoop(refreshLive, { intervalMs: 4_000 }),\n", "")
for needle in ("sendLive", "activeLiveTab", "refreshLive", "liveStartButton", "liveStopButton", "liveWebRtc", "prstudio-live"):
    if needle in s:
        raise SystemExit(f"PR31 sidepanel.js still contains {needle}")
write(p, s)

# Visible/hidden streaming panel is removed entirely.
p = "prstudio-unified-browser-agent/sidepanel.html"
s = read(p)
s = sub_once(s, r"\n<section id=\"liveWebRtc\" hidden aria-hidden=\"true\">.*?</section>", "", "sidepanel Browser LIVE section", re.S)
for needle in ("Browser LIVE", "WebRTC", "liveStartButton", "liveWebRtc"):
    if needle in s:
        raise SystemExit(f"PR31 sidepanel.html still contains {needle}")
write(p, s)

# No runtime implementation survives.
for rel in (
    "prstudio-unified-browser-agent/lib/live-webrtc.js",
    "prstudio-unified-browser-agent/offscreen-live.js",
    "prstudio-unified-browser-agent/offscreen-live.html",
    "prstudio-unified-control/includes/class-prstudio-uc-browser-live.php",
):
    (ROOT / rel).unlink(missing_ok=True)

# Regression guards keep the historical test paths registered but invert their contract.
write("tests/php-browser-live-signaling-smoke.php", '''<?php
define('PRSTUDIO_UC_TESTING', true);
$root=dirname(__DIR__);
$mcp=file_get_contents($root.'/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php');
$rest=file_get_contents($root.'/prstudio-unified-control/includes/class-prstudio-uc-rest.php');
$autoload=file_get_contents($root.'/prstudio-unified-control/includes/class-prstudio-uc-autoload.php');
foreach (array('browser_live_attach','browser_live_signal','browser_live_stop','browser_live_status','BROWSER_LIVE_URI','PRSTUDIO_UC_Browser_Live') as $needle) {
    if (strpos($mcp,$needle)!==false) { fwrite(STDERR,"FAIL MCP still contains $needle\\n"); exit(1); }
}
if (strpos($rest,'/stream/session')!==false || strpos($rest,'PRSTUDIO_UC_Browser_Live')!==false) { fwrite(STDERR,"FAIL REST streaming surface remains\\n"); exit(1); }
if (strpos($autoload,'PRSTUDIO_UC_Browser_Live')!==false) { fwrite(STDERR,"FAIL autoload mapping remains\\n"); exit(1); }
if (is_file($root.'/prstudio-unified-control/includes/class-prstudio-uc-browser-live.php')) { fwrite(STDERR,"FAIL Browser LIVE server class remains\\n"); exit(1); }
fwrite(STDOUT,"OK Browser LIVE server surface removed\\n");
''')
write("tests/validate-browser-live-webrtc.mjs", '''import fs from 'node:fs';
const manifest=JSON.parse(fs.readFileSync(new URL('../prstudio-unified-browser-agent/manifest.json',import.meta.url),'utf8'));
const perms=new Set(manifest.permissions||[]);
for (const p of ['tabCapture','offscreen','contextMenus']) if (perms.has(p)) throw new Error(`removed Browser LIVE permission still present: ${p}`);
const panel=fs.readFileSync(new URL('../prstudio-unified-browser-agent/sidepanel.html',import.meta.url),'utf8');
for (const needle of ['Browser LIVE','WebRTC','liveStartButton','liveWebRtc']) if (panel.includes(needle)) throw new Error(`removed streaming UI remains: ${needle}`);
for (const f of ['offscreen-live.html','offscreen-live.js','lib/live-webrtc.js']) if (fs.existsSync(new URL(`../prstudio-unified-browser-agent/${f}`,import.meta.url))) throw new Error(`removed live runtime remains: ${f}`);
console.log('OK Browser LIVE/WebRTC extension surface removed');
''')

# Final whole-repo active-surface sanity checks. Historical prose outside runtime/tests is not rewritten here.
manifest = __import__('json').loads(read("prstudio-unified-browser-agent/manifest.json"))
for perm in ("tabCapture", "offscreen", "contextMenus"):
    if perm in manifest.get("permissions", []):
        raise SystemExit(f"PR31 manifest still requests {perm}")

print("PR31 Browser LIVE/WebRTC removal patch prepared")
