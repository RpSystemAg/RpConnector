import fs from 'node:fs';
const manifest=JSON.parse(fs.readFileSync(new URL('../prstudio-unified-browser-agent/manifest.json',import.meta.url),'utf8'));
const perms=new Set(manifest.permissions||[]);
for (const p of ['tabCapture','offscreen','contextMenus']) if (perms.has(p)) throw new Error(`removed Browser LIVE permission still present: ${p}`);
const panel=fs.readFileSync(new URL('../prstudio-unified-browser-agent/sidepanel.html',import.meta.url),'utf8');
for (const needle of ['Browser LIVE','WebRTC','liveStartButton','liveWebRtc']) if (panel.includes(needle)) throw new Error(`removed streaming UI remains: ${needle}`);
for (const f of ['offscreen-live.html','offscreen-live.js','lib/live-webrtc.js']) if (fs.existsSync(new URL(`../prstudio-unified-browser-agent/${f}`,import.meta.url))) throw new Error(`removed live runtime remains: ${f}`);
console.log('OK Browser LIVE/WebRTC extension surface removed');
