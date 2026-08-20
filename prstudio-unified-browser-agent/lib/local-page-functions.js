/**
 * Functions exported from this file are passed directly to chrome.scripting.executeScript.
 * They MUST remain self-contained: Chrome serializes the function body and does not carry
 * module-scope closures into the target page's isolated world.
 */

export function collectLocalPageHealth() {
  const clean = (value, max = 500) => String(value || "").replace(/\s+/g, " ").trim().slice(0, max);
  const description = document.querySelector('meta[name="description"]')?.content || "";
  const canonical = document.querySelector('link[rel="canonical"]')?.href || "";
  const robots = document.querySelector('meta[name="robots"]')?.content || "";
  const viewport = document.querySelector('meta[name="viewport"]')?.content || "";
  const images = [...document.images];
  const controls = [...document.querySelectorAll('input:not([type="hidden"]),select,textarea,button')];
  const unlabeledControls = controls.filter((item) => {
    const text = clean(item.innerText || item.value || "", 120);
    return !item.getAttribute("aria-label") && !item.getAttribute("aria-labelledby") && !item.getAttribute("title") && !item.labels?.length && !text;
  }).length;
  const ids = [...document.querySelectorAll("[id]")].map((item) => item.id).filter(Boolean);
  const duplicateIdCount = ids.length - new Set(ids).size;
  const schemas = [...document.querySelectorAll('script[type="application/ld+json"]')];
  let schemaParseErrors = 0;
  for (const script of schemas) { try { JSON.parse(script.textContent || "{}"); } catch { schemaParseErrors += 1; } }
  const allLinks = [...document.querySelectorAll("a")];
  const badLinkCount = allLinks.filter((link) => {
    const href = String(link.getAttribute("href") || "").trim();
    return !href || /^javascript:/i.test(href);
  }).length;
  const mixedContentCount = location.protocol === "https:"
    ? [...document.querySelectorAll("[src],[href]")].filter((node) => /^http:\/\//i.test(node.src || node.href || "")).length
    : 0;
  const resources = performance.getEntriesByType("resource").slice(-500).map((entry) => ({
    name: clean(entry.name, 800), duration: Math.round(entry.duration), transferSize: Number(entry.transferSize || 0), initiatorType: entry.initiatorType || "",
  }));
  resources.sort((a, b) => b.duration - a.duration);
  const nav = performance.getEntriesByType("navigation")[0];
  return {
    url: location.href,
    title: document.title || "",
    titleLength: (document.title || "").length,
    description,
    descriptionLength: description.length,
    canonical,
    robots,
    viewport,
    lang: document.documentElement.lang || "",
    h1Count: document.querySelectorAll("h1").length,
    headingCount: document.querySelectorAll("h1,h2,h3,h4,h5,h6").length,
    imageCount: images.length,
    imagesMissingAlt: images.filter((image) => !image.hasAttribute("alt") || !String(image.alt || "").trim()).length,
    linkCount: allLinks.length,
    badLinkCount,
    formCount: document.forms.length,
    unlabeledControls,
    duplicateIdCount,
    schemaCount: schemas.length,
    schemaParseErrors,
    mixedContentCount,
    resourceCount: resources.length,
    slowResources: resources.slice(0, 12),
    navigation: nav ? {
      domContentLoadedMs: Math.round(nav.domContentLoadedEventEnd),
      loadMs: Math.round(nav.loadEventEnd),
      responseMs: Math.round(nav.responseEnd - nav.requestStart),
      transferSize: Number(nav.transferSize || 0),
    } : null,
    readyState: document.readyState,
    observedAt: Date.now(),
  };
}


export function collectLocalSemanticSnapshot() {
  const clean = (value, max = 1000) => String(value || "").replace(/\s+/g, " ").trim().slice(0, max);
  const visibleText = clean(document.body?.innerText || "", 120000);
  const headings = [...document.querySelectorAll("h1,h2,h3,h4,h5,h6")].slice(0, 300).map((node) => ({ level: Number(node.tagName.slice(1)), text: clean(node.innerText || node.textContent, 500) }));
  const links = [...document.querySelectorAll("a[href]")].slice(0, 600).map((node) => {
    let href = "";
    try { const url = new URL(node.href, location.href); url.username = ""; url.password = ""; url.hash = ""; href = url.href; } catch { href = clean(node.getAttribute("href"), 1000); }
    return { href, text: clean(node.innerText || node.textContent || node.getAttribute("aria-label"), 300) };
  });
  const controls = [...document.querySelectorAll('input:not([type="hidden"]),select,textarea,button')].slice(0, 400).map((node) => ({
    tag: node.tagName.toLowerCase(),
    type: clean(node.getAttribute("type"), 80),
    name: clean(node.getAttribute("name"), 160),
    id: clean(node.id, 160),
    ariaLabel: clean(node.getAttribute("aria-label"), 240),
    // Never collect live form values here.
  }));
  const landmarks = [...document.querySelectorAll("header,nav,main,aside,footer,section,article,[role]")].slice(0, 600).map((node) => ({
    tag: node.tagName.toLowerCase(), role: clean(node.getAttribute("role"), 100), id: clean(node.id, 160), className: clean(node.className, 240),
  }));
  return {
    url: location.href,
    title: document.title || "",
    text: visibleText,
    headings,
    links,
    controls,
    landmarks,
    counts: {
      elements: document.getElementsByTagName("*").length,
      headings: headings.length,
      links: links.length,
      forms: document.forms.length,
      controls: controls.length,
      images: document.images.length,
    },
    observedAt: Date.now(),
  };
}

export function collectLocalResponsiveSnapshot() {
  const root = document.documentElement;
  const body = document.body;
  const candidates = [...document.querySelectorAll("body *")].slice(0, 5000);
  let horizontalOverflowElements = 0;
  for (const node of candidates) {
    if (!(node instanceof Element)) continue;
    const rect = node.getBoundingClientRect();
    if (rect.width > 0 && (rect.left < -2 || rect.right > innerWidth + 2)) horizontalOverflowElements += 1;
  }
  return {
    url: location.href,
    viewport: { width: innerWidth, height: innerHeight, devicePixelRatio },
    document: {
      scrollWidth: Math.max(root?.scrollWidth || 0, body?.scrollWidth || 0),
      scrollHeight: Math.max(root?.scrollHeight || 0, body?.scrollHeight || 0),
      clientWidth: root?.clientWidth || innerWidth,
      clientHeight: root?.clientHeight || innerHeight,
    },
    horizontalOverflow: Math.max(root?.scrollWidth || 0, body?.scrollWidth || 0) > (root?.clientWidth || innerWidth) + 2,
    horizontalOverflowElements,
    observedAt: Date.now(),
  };
}

export function installLocalInspector() {
  const clean = (value, max = 500) => String(value || "").replace(/\s+/g, " ").trim().slice(0, max);
  const esc = (value) => globalThis.CSS?.escape ? CSS.escape(String(value)) : String(value).replace(/[^a-zA-Z0-9_-]/g, (char) => `\\${char.codePointAt(0).toString(16)} `);
  const xpath = (element) => {
    if (!element || element.nodeType !== 1) return "";
    if (element.id) return `//*[@id=${JSON.stringify(element.id)}]`;
    const parts = []; let node = element;
    while (node && node.nodeType === 1 && parts.length < 8) {
      const tag = node.tagName.toLowerCase(); let index = 1; let sibling = node.previousElementSibling;
      while (sibling) { if (sibling.tagName === node.tagName) index += 1; sibling = sibling.previousElementSibling; }
      parts.unshift(`${tag}[${index}]`); node = node.parentElement;
    }
    return `/${parts.join("/")}`;
  };
  const locator = (element) => {
    const css = []; const tag = element.tagName.toLowerCase();
    const testId = element.getAttribute("data-testid") || element.getAttribute("data-test") || element.getAttribute("data-qa");
    if (testId) {
      if (element.hasAttribute("data-testid")) css.push(`[data-testid="${esc(testId)}"]`);
      if (element.hasAttribute("data-test")) css.push(`[data-test="${esc(testId)}"]`);
      if (element.hasAttribute("data-qa")) css.push(`[data-qa="${esc(testId)}"]`);
    }
    if (element.id) css.push(`#${esc(element.id)}`);
    const nameAttr = element.getAttribute("name"); if (nameAttr) css.push(`${tag}[name="${esc(nameAttr)}"]`);
    const aria = element.getAttribute("aria-label"); if (aria) css.push(`${tag}[aria-label="${esc(aria)}"]`);
    const type = element.getAttribute("type"); if (type && ["button", "submit", "checkbox", "radio"].includes(type.toLowerCase())) css.push(`${tag}[type="${esc(type)}"]`);
    if (!css.length) {
      const parts = []; let node = element;
      while (node && node.nodeType === 1 && parts.length < 5) {
        const nodeTag = node.tagName.toLowerCase(); const parent = node.parentElement;
        if (!parent) { parts.unshift(nodeTag); break; }
        const siblings = [...parent.children].filter((item) => item.tagName === node.tagName);
        parts.unshift(`${nodeTag}${siblings.length > 1 ? `:nth-of-type(${siblings.indexOf(node) + 1})` : ""}`); node = parent;
      }
      if (parts.length) css.push(parts.join(" > "));
    }
    const role = element.getAttribute("role") || ({ BUTTON: "button", A: element.hasAttribute("href") ? "link" : "", INPUT: element.type === "checkbox" ? "checkbox" : (element.type === "radio" ? "radio" : "textbox"), SELECT: "combobox", TEXTAREA: "textbox" }[element.tagName] || "");
    const name = clean(aria || element.getAttribute("title") || element.innerText || element.value || element.getAttribute("placeholder"), 240);
    return { css: [...new Set(css)].slice(0, 8), xpath: xpath(element), role, name, text: clean(element.innerText || element.textContent, 240), tag, confidence: css.length && (testId || element.id) ? 0.98 : (aria || nameAttr ? 0.9 : 0.7) };
  };
  if (globalThis.__prStudioLocalInspector?.cleanup) globalThis.__prStudioLocalInspector.cleanup();
  const overlay = document.createElement("div");
  Object.assign(overlay.style, { position: "fixed", zIndex: "2147483647", pointerEvents: "none", border: "2px solid #176b32", background: "rgba(23,107,50,.08)", display: "none" });
  document.documentElement.appendChild(overlay);
  const onMove = (event) => {
    const target = event.target; if (!(target instanceof Element) || target === overlay) return;
    const rect = target.getBoundingClientRect(); Object.assign(overlay.style, { display: "block", left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px` });
  };
  let onClick; let onKey;
  const cleanup = () => { document.removeEventListener("mousemove", onMove, true); document.removeEventListener("click", onClick, true); document.removeEventListener("keydown", onKey, true); overlay.remove(); delete globalThis.__prStudioLocalInspector; };
  onClick = (event) => {
    const target = event.target; if (!(target instanceof Element) || target === overlay) return;
    event.preventDefault(); event.stopPropagation(); const rect = target.getBoundingClientRect();
    chrome.runtime.sendMessage({ type: "local_inspector_result", result: {
      url: location.href, locator: locator(target), tag: target.tagName.toLowerCase(), id: target.id || "", classes: [...target.classList].slice(0, 20),
      attributes: Object.fromEntries([...target.attributes].slice(0, 30).map((attr) => [attr.name, clean(attr.value, 500)])),
      text: clean(target.innerText || target.textContent, 2000), rect: { x: Math.round(rect.x), y: Math.round(rect.y), width: Math.round(rect.width), height: Math.round(rect.height) }, observedAt: Date.now(),
    } }).catch(() => {}); cleanup();
  };
  onKey = (event) => { if (event.key === "Escape") cleanup(); };
  document.addEventListener("mousemove", onMove, true); document.addEventListener("click", onClick, true); document.addEventListener("keydown", onKey, true);
  globalThis.__prStudioLocalInspector = { cleanup };
  return { ok: true };
}

export function installLocalRecorder(sessionId) {
  const clean = (value, max = 500) => String(value || "").replace(/\s+/g, " ").trim().slice(0, max);
  const esc = (value) => globalThis.CSS?.escape ? CSS.escape(String(value)) : String(value).replace(/[^a-zA-Z0-9_-]/g, (char) => `\\${char.codePointAt(0).toString(16)} `);
  const xpath = (element) => {
    if (!element || element.nodeType !== 1) return ""; if (element.id) return `//*[@id=${JSON.stringify(element.id)}]`;
    const parts = []; let node = element;
    while (node && node.nodeType === 1 && parts.length < 8) { const tag = node.tagName.toLowerCase(); let index = 1; let sibling = node.previousElementSibling; while (sibling) { if (sibling.tagName === node.tagName) index += 1; sibling = sibling.previousElementSibling; } parts.unshift(`${tag}[${index}]`); node = node.parentElement; }
    return `/${parts.join("/")}`;
  };
  const locator = (element) => {
    const css = []; const tag = element.tagName.toLowerCase(); const testId = element.getAttribute("data-testid") || element.getAttribute("data-test") || element.getAttribute("data-qa");
    if (testId) { if (element.hasAttribute("data-testid")) css.push(`[data-testid="${esc(testId)}"]`); if (element.hasAttribute("data-test")) css.push(`[data-test="${esc(testId)}"]`); if (element.hasAttribute("data-qa")) css.push(`[data-qa="${esc(testId)}"]`); }
    if (element.id) css.push(`#${esc(element.id)}`); const nameAttr = element.getAttribute("name"); if (nameAttr) css.push(`${tag}[name="${esc(nameAttr)}"]`); const aria = element.getAttribute("aria-label"); if (aria) css.push(`${tag}[aria-label="${esc(aria)}"]`);
    if (!css.length) { const parts = []; let node = element; while (node && node.nodeType === 1 && parts.length < 5) { const nodeTag = node.tagName.toLowerCase(); const parent = node.parentElement; if (!parent) { parts.unshift(nodeTag); break; } const siblings = [...parent.children].filter((item) => item.tagName === node.tagName); parts.unshift(`${nodeTag}${siblings.length > 1 ? `:nth-of-type(${siblings.indexOf(node) + 1})` : ""}`); node = parent; } if (parts.length) css.push(parts.join(" > ")); }
    const role = element.getAttribute("role") || ({ BUTTON: "button", A: element.hasAttribute("href") ? "link" : "", INPUT: element.type === "checkbox" ? "checkbox" : (element.type === "radio" ? "radio" : "textbox"), SELECT: "combobox", TEXTAREA: "textbox" }[element.tagName] || "");
    const name = clean(aria || element.getAttribute("title") || element.innerText || element.value || element.getAttribute("placeholder"), 240);
    return { css: [...new Set(css)].slice(0, 8), xpath: xpath(element), role, name, text: clean(element.innerText || element.textContent, 240), tag, confidence: css.length && (testId || element.id) ? 0.98 : (aria || nameAttr ? 0.9 : 0.7) };
  };
  const descriptor = (element) => ({ type: element.getAttribute("type") || "", autocomplete: element.getAttribute("autocomplete") || "", name: element.getAttribute("name") || "", id: element.id || "", label: clean(element.labels?.[0]?.innerText || element.closest("label")?.innerText || "", 200), placeholder: element.getAttribute("placeholder") || "", ariaLabel: element.getAttribute("aria-label") || "" });
  const sensitive = (d) => String(d.type).toLowerCase() === "password" || /one-time-code|current-password|new-password/i.test(String(d.autocomplete)) || /(?:password|passwd|passphrase|secret|token|otp|one[-_ ]?time|auth(?:entication|orization)?)/i.test([d.name,d.id,d.label,d.placeholder,d.ariaLabel].join(" "));
  if (globalThis.__prStudioLocalRecorder?.cleanup) globalThis.__prStudioLocalRecorder.cleanup();
  let lastEventAt = 0;
  const send = (step) => { const now = Date.now(); if (now - lastEventAt < 80 && step.type === "click") return; lastEventAt = now; chrome.runtime.sendMessage({ type: "local_recorder_event", sessionId, step: { ...step, recordedAt: now, url: location.href } }).catch(() => {}); };
  const geometry = (element) => { const r=element.getBoundingClientRect(); return { x:r.x,y:r.y,width:r.width,height:r.height,viewport:{width:innerWidth,height:innerHeight},dpr:devicePixelRatio||1 }; };
  const click = (event) => {
    const target = event.target instanceof Element ? event.target.closest("button,a,input,select,textarea,[role='button'],[role='link'],[role='checkbox'],[role='radio'],[role='tab'],[role='menuitem']") || event.target : null;
    if (!target || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName)) return;
    const demonstrateOnly = Boolean(event.altKey);
    if (demonstrateOnly) { event.preventDefault(); event.stopImmediatePropagation(); }
    send({ type: "click", locator: locator(target), geometry: geometry(target), demonstrateOnly, track: "behavior", label: clean(target.innerText || target.getAttribute("aria-label") || "Click", 160) });
  };
  let lastPointerAt=0;
  const pointer = (event) => { const now=Date.now(); if(now-lastPointerAt<50)return; lastPointerAt=now; chrome.runtime.sendMessage({type:"local_recorder_pointer",sessionId,point:{x:event.clientX,y:event.clientY,at:now,url:location.href}}).catch(()=>{}); };
  const change = (event) => {
    const target = event.target; if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) return;
    const d = descriptor(target); const loc = locator(target);
    if (target instanceof HTMLSelectElement) send({ type: "select", locator: loc, value: target.value, valuePolicy: "local_plaintext", label: `Seleziona ${loc.name || d.name || "campo"}` });
    else if (target instanceof HTMLInputElement && ["checkbox", "radio"].includes(target.type)) send({ type: "check", locator: loc, checked: target.checked, label: `${target.checked ? "Seleziona" : "Deseleziona"} ${loc.name || d.name || "campo"}` });
    else { const isSecret = sensitive(d); send({ type: "fill", locator: loc, value: isSecret ? null : String(target.value || "").slice(0, 4000), valuePolicy: isSecret ? "redacted" : "local_plaintext", label: `Compila ${loc.name || d.name || "campo"}` }); }
  };
  const keydown = (event) => { if (event.key !== "Enter") return; const target = event.target instanceof Element ? event.target : null; if (!target) return; send({ type: "press", locator: locator(target), key: "Enter", label: `Premi Enter su ${clean(target.getAttribute("aria-label") || target.getAttribute("name") || target.tagName, 100)}` }); };
  const cleanup = () => { document.removeEventListener("click", click, true); document.removeEventListener("change", change, true); document.removeEventListener("keydown", keydown, true); document.removeEventListener("pointermove", pointer, true); delete globalThis.__prStudioLocalRecorder; };
  document.addEventListener("click", click, true); document.addEventListener("change", change, true); document.addEventListener("keydown", keydown, true); document.addEventListener("pointermove", pointer, {capture:true,passive:true}); globalThis.__prStudioLocalRecorder = { cleanup, sessionId };
  return { ok: true, sessionId, url: location.href };
}

export function uninstallLocalRecorder() {
  if (globalThis.__prStudioLocalRecorder?.cleanup) globalThis.__prStudioLocalRecorder.cleanup();
  return { ok: true };
}

export function resolveLocalWorkflowTarget(step) {
  const clean=(v,m=500)=>String(v||"").replace(/\s+/g," ").trim().slice(0,m);
  const locate=(locator={})=>{
    for(const selector of locator.css||[]){try{const node=document.querySelector(selector);if(node)return node;}catch{}}
    if(locator.xpath){try{const node=document.evaluate(locator.xpath,document,null,XPathResult.FIRST_ORDERED_NODE_TYPE,null).singleNodeValue;if(node)return node;}catch{}}
    const candidates=[...document.querySelectorAll(locator.tag||"button,a,input,select,textarea,[role]")];const expectedName=clean(locator.name||"",240).toLowerCase(),expectedText=clean(locator.text||"",240).toLowerCase();
    return candidates.find((node)=>{const role=node.getAttribute("role")||"";const name=clean(node.getAttribute("aria-label")||node.getAttribute("title")||node.innerText||node.value||node.getAttribute("placeholder"),240).toLowerCase();const text=clean(node.innerText||node.textContent,240).toLowerCase();return(!locator.role||role===locator.role||node.tagName.toLowerCase()===locator.role)&&(!expectedName||name===expectedName||name.includes(expectedName))&&(!expectedText||text===expectedText||text.includes(expectedText));})||null;
  };
  const element=locate(step.locator||{});if(!element)return{ok:false,error:"local_target_not_found",locator:step.locator||{}};
  element.scrollIntoView({block:"center",inline:"center",behavior:"instant"});
  const r=element.getBoundingClientRect();const fractions=[.5,.35,.65,.2,.8];let hitPoint=null;
  for(const fy of fractions){for(const fx of fractions){const x=Math.min(innerWidth-1,Math.max(0,r.left+r.width*fx)),y=Math.min(innerHeight-1,Math.max(0,r.top+r.height*fy));const top=document.elementFromPoint(x,y);if(top&&(top===element||element.contains(top)||top.contains?.(element))){hitPoint={x,y,fx,fy,verified:true};break;}}if(hitPoint)break;}
  return{ok:true,hitPoint,boundingBox:{x:r.x,y:r.y,width:r.width,height:r.height},tag:element.tagName.toLowerCase(),type:String(element.getAttribute("type")||""),value:"value" in element?String(element.value??""):null,checked:"checked" in element?Boolean(element.checked):null,requiresRegrounding:!hitPoint};
}

export function executeLocalWorkflowStep(step) {
  const clean = (value, max = 500) => String(value || "").replace(/\s+/g, " ").trim().slice(0, max);
  const locate = (locator = {}) => {
    for (const selector of locator.css || []) { try { const node = document.querySelector(selector); if (node) return node; } catch { /* next */ } }
    if (locator.xpath) { try { const node = document.evaluate(locator.xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue; if (node) return node; } catch { /* next */ } }
    const candidates = [...document.querySelectorAll(locator.tag || "button,a,input,select,textarea,[role]")]; const expectedName = clean(locator.name || "", 240).toLowerCase(); const expectedText = clean(locator.text || "", 240).toLowerCase();
    return candidates.find((node) => { const role = node.getAttribute("role") || ""; const name = clean(node.getAttribute("aria-label") || node.getAttribute("title") || node.innerText || node.value || node.getAttribute("placeholder"), 240).toLowerCase(); const text = clean(node.innerText || node.textContent, 240).toLowerCase(); return (!locator.role || role === locator.role || node.tagName.toLowerCase() === locator.role) && (!expectedName || name === expectedName || name.includes(expectedName)) && (!expectedText || text === expectedText || text.includes(expectedText)); }) || null;
  };
  const element = locate(step.locator || {}); if (!element) return { ok: false, error: "local_target_not_found", locator: step.locator || {} };
  element.scrollIntoView({ block: "center", inline: "center" });
  if (step.type === "click") element.click();
  else if (step.type === "fill") {
    if (step.valuePolicy === "redacted" || step.value == null) return { ok: false, error: "local_value_redacted", requiresHumanInput: true };
    const proto = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype; const setter = Object.getOwnPropertyDescriptor(proto, "value")?.set; if (setter) setter.call(element, String(step.value)); else element.value = String(step.value); element.dispatchEvent(new Event("input", { bubbles: true })); element.dispatchEvent(new Event("change", { bubbles: true }));
  } else if (step.type === "select") { element.value = String(step.value ?? ""); element.dispatchEvent(new Event("input", { bubbles: true })); element.dispatchEvent(new Event("change", { bubbles: true })); }
  else if (step.type === "check") { if (Boolean(element.checked) !== Boolean(step.checked)) element.click(); }
  else if (step.type === "press") { const key = String(step.key || "Enter"); for (const type of ["keydown", "keypress", "keyup"]) element.dispatchEvent(new KeyboardEvent(type, { key, code: key === "Enter" ? "Enter" : key, bubbles: true, cancelable: true })); }
  return { ok: true, url: location.href, tag: element.tagName.toLowerCase() };
}
