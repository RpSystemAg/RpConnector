(() => {
  if (globalThis.__PRSTUDIO_PAGE_RUNTIME_V3_BOOTSTRAPPED__) return;
  globalThis.__PRSTUDIO_PAGE_RUNTIME_V3_BOOTSTRAPPED__ = true;
  const INTERACTIVE_SELECTOR = "a[href],button,input:not([type='hidden']),textarea,select,summary,[role],[tabindex],[contenteditable='true'],[onclick]";
  const interactive = new Set();
  const observedRoots = new WeakSet();
  const mutationSubscribers = new Set();
  let domVersion = 1;
  let port = null;
  const reconnectBackoff = globalThis.__PRSTUDIO_RECONNECT_BACKOFF_V1__?.create?.({
    baseDelayMs: 250,
    maxDelayMs: 10_000,
    stableConnectionMs: 5_000,
    jitterRatio: 0.2,
  });
  const dirtyNotifier = globalThis.__PRSTUDIO_RUNTIME_DIRTY_NOTIFIER_V1__?.create?.((message) => {
    if (!port) throw new Error('page_runtime_port_unavailable');
    port.postMessage(message);
  });

  const matchesInteractive = (node) => node?.nodeType === 1 && node.matches?.(INTERACTIVE_SELECTOR);
  const scanNode = (node) => {
    if (!node) return;
    if (matchesInteractive(node)) interactive.add(node);
    if (node.querySelectorAll) {
      for (const element of node.querySelectorAll(INTERACTIVE_SELECTOR)) interactive.add(element);
      for (const element of node.querySelectorAll('*')) if (element.shadowRoot) observeRoot(element.shadowRoot);
    }
    if (node.shadowRoot) observeRoot(node.shadowRoot);
  };
  const removeNode = (node) => {
    if (!node) return;
    if (node.nodeType === 1) interactive.delete(node);
    if (node.querySelectorAll) for (const element of node.querySelectorAll(INTERACTIVE_SELECTOR)) interactive.delete(element);
  };
  const emitMutation = () => {
    domVersion += 1;
    for (const listener of [...mutationSubscribers]) queueMicrotask(listener);
    if (dirtyNotifier) dirtyNotifier.notify(domVersion, location.href);
    else try { port?.postMessage({ type: 'dom_mutation', domVersion, url: location.href }); } catch { /* port reconnects below */ }
  };
  const observer = new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === 'childList') {
        for (const node of record.addedNodes) scanNode(node);
        for (const node of record.removedNodes) removeNode(node);
      } else if (record.type === 'attributes') {
        if (matchesInteractive(record.target)) interactive.add(record.target); else interactive.delete(record.target);
      }
    }
    emitMutation();
  });
  function observeRoot(root) {
    if (!root || observedRoots.has(root)) return;
    observedRoots.add(root);
    try { observer.observe(root, { subtree: true, childList: true, attributes: true, characterData: true }); } catch { return; }
    scanNode(root);
  }
  document.addEventListener('__prstudio_shadow_root_attached', (event) => {
    const host = event?.target;
    if (host?.shadowRoot) { observeRoot(host.shadowRoot); emitMutation(); }
  }, true);
  observeRoot(document);
  scanNode(document);

  const runtime = {
    get domVersion() { return domVersion; },
    interactiveElements() {
      if (interactive.size > 2500) for (const element of [...interactive]) if (!element?.isConnected) interactive.delete(element);
      return [...interactive];
    },
    indexSize() { return interactive.size; },
    subscribe(listener) { mutationSubscribers.add(listener); return () => mutationSubscribers.delete(listener); },
  };
  globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__ = runtime;

  const deepSelectorExists = (selector) => {
    if (!selector) return true;
    const roots = [document];
    for (let i = 0; i < roots.length && i < 64; i += 1) {
      const root = roots[i];
      try { if (root.querySelector(selector)) return true; } catch { return false; }
      let all = [];
      try { all = [...root.querySelectorAll('*')]; } catch { all = []; }
      for (const element of all) if (element.shadowRoot && !roots.includes(element.shadowRoot)) roots.push(element.shadowRoot);
    }
    return false;
  };

  const eventWait = async (check, timeoutMs, events = []) => {
    const immediate = await check();
    if (immediate) return immediate;
    return new Promise((resolve, reject) => {
      let settled = false;
      let scheduled = false;
      const cleanup = () => {
        unsubscribe();
        clearTimeout(timer);
        for (const [target, type, fn] of listeners) target.removeEventListener(type, fn);
      };
      const finish = (value, error) => {
        if (settled) return;
        settled = true;
        cleanup();
        if (error) reject(error); else resolve(value);
      };
      const run = () => {
        if (settled || scheduled) return;
        scheduled = true;
        queueMicrotask(async () => {
          scheduled = false;
          if (settled) return;
          try { const value = await check(); if (value) finish(value); } catch (error) { finish(null, error); }
        });
      };
      const unsubscribe = runtime.subscribe(run);
      const listeners = events.map(([target, type]) => {
        const fn = run;
        target.addEventListener(type, fn, { passive: true });
        return [target, type, fn];
      });
      const timer = setTimeout(() => finish(null, new Error('page_runtime_wait_timeout')), Math.max(250, Number(timeoutMs || 30000)));
    });
  };

async function domExecutor(action, args) {
  const normalize = (value) => String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, " ")
    .replace(/\s+/g, " ")
    .trim();
  // Keep this serialisable page-runtime mirror aligned with the browser-side
  // semantic ranker: visible controls may be in the other supported language.
  const controlConcepts = {
    save:["save","salva","salvare"], cancel:["cancel","annulla","annullare"],
    add:["add","aggiungi","aggiungere","inserisci","inserire"], cart:["cart","basket","carrello"],
    buy:["buy","purchase","acquista","acquistare","compra","comprare"], checkout:["checkout","cassa"],
    submit:["submit","send","invia","inviare","manda","mandare"], continue:["continue","continua","continuare","prosegui","proseguire"],
    confirm:["confirm","conferma","confermare"], next:["next","avanti","successivo","successiva"], back:["back","previous","indietro","precedente"],
    close:["close","chiudi","chiudere"], open:["open","apri","aprire"], login:["login","signin","accedi","accedere","entra","entrare"],
    logout:["logout","signout","esci","uscire","disconnetti"], search:["search","find","cerca","cercare","trova","trovare"],
    select:["select","choose","seleziona","selezionare","scegli","scegliere"], check:["check","tick","spunta","spuntare"],
    fill:["fill","enter","compila","compilare","riempi","riempire"], write:["type","write","digita","digitare","scrivi","scrivere"],
    click:["click","press","clicca","cliccare","premi","premere"], upload:["upload","carica","caricare"], download:["download","scarica","scaricare"],
    remove:["remove","delete","rimuovi","rimuovere","elimina","eliminare"], edit:["edit","modify","modifica","modificare"],
    apply:["apply","applica","applicare"], coupon:["coupon","voucher","buono","sconto"], filter:["filter","filtra","filtrare"],
    sort:["sort","ordina","ordinare"], details:["details","detail","dettagli","dettaglio"], more:["more","altro","altri","piu"],
    name:["name","nome"], address:["address","indirizzo"], phone:["phone","telephone","telefono"], company:["company","business","azienda","societa"],
    quantity:["quantity","qty","quantita"], payment:["payment","pagamento"], shipping:["shipping","delivery","spedizione","consegna"],
  };
  const conceptByWord = new Map();
  for (const [concept, forms] of Object.entries(controlConcepts)) for (const form of [concept, ...forms]) conceptByWord.set(normalize(form), concept);
  const stopWords = new Set(["a","an","the","to","of","for","in","on","and","or","your","al","allo","alla","ai","agli","alle","il","lo","la","i","gli","le","un","uno","una","di","del","della","dei","delle","per","nel","nella","e","o"]);
  const tokenSet = (value) => [...new Set(normalize(value).split(" ").filter((part) => part.length > 1 && !stopWords.has(part)).map((part) => conceptByWord.get(part) || `raw:${part}`))];
  const similarity = (actual, expected) => {
    const a = normalize(actual), b = normalize(expected);
    if (!a || !b) return 0;
    if (a === b) return 1;
    if (a.startsWith(b) || b.startsWith(a)) return 0.9;
    if (a.includes(b) || b.includes(a)) return 0.82;
    const at = tokenSet(a), bt = tokenSet(b);
    if (!at.length || !bt.length) return 0;
    const intersection = bt.filter((token) => at.includes(token)).length;
    if (!intersection) return 0;
    const precision = intersection / at.length;
    const recall = intersection / bt.length;
    return (2 * precision * recall) / Math.max(0.0001, precision + recall);
  };
  const visible = (element) => {
    if (!(element instanceof Element) || !element.isConnected) return false;
    if (element.closest?.("[hidden],[inert],[aria-hidden='true']")) return false;
    const style = getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    return style.display !== "none" && style.visibility !== "hidden" && Number(style.opacity || 1) > 0.05
      && style.contentVisibility !== "hidden" && rect.width > 0 && rect.height > 0;
  };
  const deepQueryAll = (selector) => {
    const output = [];
    const seen = new Set();
    const roots = [document];
    for (let index = 0; index < roots.length && index < 64; index += 1) {
      const root = roots[index];
      let matches = [];
      try { matches = [...root.querySelectorAll(selector)]; } catch { matches = []; }
      for (const element of matches) if (!seen.has(element)) { seen.add(element); output.push(element); }
      let descendants = [];
      try { descendants = [...root.querySelectorAll("*")]; } catch { descendants = []; }
      for (const element of descendants) if (element.shadowRoot && !roots.includes(element.shadowRoot)) roots.push(element.shadowRoot);
    }
    return output;
  };
  const labelledByText = (element) => {
    const ids = String(element.getAttribute?.("aria-labelledby") || "").split(/\s+/).filter(Boolean);
    if (!ids.length) return "";
    const root = element.getRootNode?.() || document;
    return ids.map((id) => root.getElementById?.(id) || document.getElementById(id))
      .filter(Boolean).map((node) => node.innerText || node.textContent || "").join(" ");
  };
  const labelText = (element) => [
    labelledByText(element),
    element.labels ? [...element.labels].map((label) => label.innerText || label.textContent).join(" ") : "",
    element.closest?.("label")?.innerText || "",
  ].filter(Boolean).join(" ");
  const accessibleName = (element) => {
    const values = [
      element.getAttribute?.("aria-label"), labelledByText(element), labelText(element),
      element.getAttribute?.("alt"), element.getAttribute?.("title"), element.getAttribute?.("placeholder"),
      ["button", "submit", "reset"].includes(String(element.getAttribute?.("type") || "").toLowerCase()) ? element.value : "",
      element.innerText, element.textContent,
    ];
    const seen = new Set();
    const parts = [];
    for (const value of values) {
      const normalized = normalize(value);
      if (normalized && !seen.has(normalized)) { seen.add(normalized); parts.push(normalized); }
    }
    return parts.join(" ");
  };
  const inferredRole = (element) => {
    const explicit = normalize(element.getAttribute?.("role"));
    if (explicit) return explicit;
    if (element.tagName === "A" && element.hasAttribute("href")) return "link";
    if (element.tagName === "BUTTON") return "button";
    if (element.tagName === "TEXTAREA") return "textbox";
    if (element.tagName === "SELECT") return "combobox";
    if (element.tagName === "SUMMARY") return "button";
    if (element.tagName === "INPUT") {
      const type = String(element.type || "text").toLowerCase();
      if (type === "checkbox") return "checkbox";
      if (type === "radio") return "radio";
      if (["button", "submit", "reset", "image"].includes(type)) return "button";
      if (type === "range") return "slider";
      if (type === "search") return "searchbox";
      return "textbox";
    }
    return normalize(element.tagName);
  };
  const cssPath = (element) => {
    if (element.id) return `#${CSS.escape(element.id)}`;
    const testId = element.getAttribute?.("data-testid") || element.getAttribute?.("data-test") || element.getAttribute?.("data-qa");
    if (testId) {
      const attr = element.hasAttribute("data-testid") ? "data-testid" : element.hasAttribute("data-test") ? "data-test" : "data-qa";
      return `[${attr}="${CSS.escape(testId)}"]`;
    }
    const parts = [];
    let current = element;
    while (current && current.nodeType === 1 && parts.length < 6) {
      let part = current.tagName.toLowerCase();
      const stableClasses = [...current.classList].filter((value) => /^[a-zA-Z][\w-]{1,50}$/.test(value) && !/^(active|selected|focus|hover|open|closed)$/i.test(value)).slice(0, 2);
      if (stableClasses.length) part += `.${stableClasses.map((value) => CSS.escape(value)).join(".")}`;
      const parent = current.parentElement;
      const siblings = parent ? [...parent.children].filter((item) => item.tagName === current.tagName) : [];
      if (siblings.length > 1) part += `:nth-of-type(${siblings.indexOf(current) + 1})`;
      parts.unshift(part);
      current = parent;
    }
    return parts.join(" > ");
  };
  const isDisabled = (element) => Boolean(element.matches?.(":disabled,[aria-disabled='true']"));
  const isFocusable = (element) => !isDisabled(element) && (
    element.matches?.("a[href],button,input,textarea,select,summary,[contenteditable='true']")
    || Number(element.getAttribute?.("tabindex")) >= 0
    || ["button", "link", "textbox", "combobox", "checkbox", "radio", "switch", "menuitem", "option", "tab"].includes(inferredRole(element))
  );
  const isClickable = (element) => {
    const role = inferredRole(element);
    const type = String(element.getAttribute?.("type") || "").toLowerCase();
    return ["button", "link", "menuitem", "option", "tab", "checkbox", "radio", "switch"].includes(role)
      || element.matches?.("a[href],button,summary,[onclick]")
      || (element.tagName === "INPUT" && ["button", "submit", "reset", "checkbox", "radio", "image"].includes(type));
  };
  const interactionPoint = (element) => {
    const rect = element.getBoundingClientRect();
    if (rect.bottom <= 0 || rect.right <= 0 || rect.top >= innerHeight || rect.left >= innerWidth) return null;
    const fractions = [0.5, 0.35, 0.65, 0.2, 0.8];
    for (const fy of fractions) for (const fx of fractions) {
      const x = Math.min(innerWidth - 1, Math.max(0, rect.left + rect.width * fx));
      const y = Math.min(innerHeight - 1, Math.max(0, rect.top + rect.height * fy));
      const top = document.elementFromPoint(x, y);
      if (top && (top === element || element.contains(top) || top.contains?.(element))) return { x, y, fx, fy, verified: true };
    }
    return null;
  };
  const topmostState = (element) => Boolean(interactionPoint(element));
  const frameOffset = () => {
    let x = 0, y = 0, win = window;
    try {
      while (win !== win.top) {
        const frame = win.frameElement;
        if (!frame) break;
        const rect = frame.getBoundingClientRect();
        x += rect.left;
        y += rect.top;
        win = win.parent;
      }
    } catch { /* cross-origin ancestor: keep whatever offset was accumulated so far */ }
    return { x, y };
  };
  const contextText = (element) => {
    const parts = [labelText(element)];
    let current = element.parentElement;
    for (let depth = 0; current && depth < 3; depth += 1, current = current.parentElement) {
      const heading = current.querySelector?.(":scope > h1,:scope > h2,:scope > h3,:scope > [role='heading']");
      if (heading) parts.push(heading.innerText || heading.textContent || "");
      if (current.matches?.("[role='dialog'],dialog,form,li,tr,section,article")) parts.push(current.getAttribute("aria-label") || current.innerText || "");
    }
    return normalize(parts.join(" ")).slice(0, 700);
  };
  const semanticRegistry = () => {
    const key = "__PRSTUDIO_SEMANTIC_TARGETS_V2__";
    const current = globalThis[key];
    if (current && current.url === location.href && current.document === document) return current;
    const created = {
      url: location.href,
      document,
      token: (globalThis.crypto?.randomUUID?.() || `${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}`).replace(/[^a-zA-Z0-9_-]/g, ""),
      generation: 0,
      next: 1,
      refs: new WeakMap(),
      targets: new Map(),
    };
    globalThis[key] = created;
    return created;
  };
  const resetSemanticRegistry = () => {
    const registry = semanticRegistry();
    registry.generation += 1;
    for (const [ref, element] of registry.targets) if (!element?.isConnected) registry.targets.delete(ref);
    return registry;
  };
  const targetRefFor = (element) => {
    const registry = semanticRegistry();
    let ref = registry.refs.get(element);
    if (!ref) {
      ref = `prst_${registry.token}_e${registry.next++}`;
      registry.refs.set(element, ref);
    }
    registry.targets.set(ref, element);
    return ref;
  };
  const describe = (element) => {
    const rect = element.getBoundingClientRect();
    const inViewport = rect.bottom > 0 && rect.right > 0 && rect.top < innerHeight && rect.left < innerWidth;
    const topmost = topmostState(element);
    const localHitPoint = interactionPoint(element);
    const offset = frameOffset();
    const dialog = element.closest?.("dialog[open],[role='dialog'],[aria-modal='true']");
    const style = getComputedStyle(element);
    const dx = (rect.left + rect.width / 2 - innerWidth / 2) / Math.max(1, innerWidth);
    const dy = (rect.top + rect.height / 2 - innerHeight / 2) / Math.max(1, innerHeight);
    return {
      targetRef: targetRefFor(element),
      tag: element.tagName.toLowerCase(),
      inputType: String(element.getAttribute?.("type") || "").toLowerCase(),
      fieldName: String(element.getAttribute?.("name") || "").slice(0, 160),
      role: inferredRole(element),
      accessibleName: accessibleName(element).slice(0, 500),
      label: normalize(labelText(element)).slice(0, 400),
      text: normalize(element.innerText || element.textContent).slice(0, 500),
      context: contextText(element),
      clickable: isClickable(element),
      focusable: isFocusable(element),
      contentEditable: Boolean(element.isContentEditable),
      disabled: isDisabled(element),
      ariaHidden: element.getAttribute?.("aria-hidden") === "true",
      pointerEventsNone: style.pointerEvents === "none",
      inViewport,
      occluded: inViewport ? !topmost : undefined,
      hitPoint: localHitPoint ? { ...localHitPoint, x: localHitPoint.x + offset.x, y: localHitPoint.y + offset.y } : null,
      inDialog: Boolean(dialog && visible(dialog)),
      centerDistance: Math.sqrt(dx * dx + dy * dy),
      hasValue: "value" in element ? String(element.value || "").length > 0 : undefined,
      valueLength: "value" in element ? String(element.value || "").length : undefined,
      checked: "checked" in element ? Boolean(element.checked) : undefined,
      selector: cssPath(element),
      boundingBox: { x: rect.x + offset.x, y: rect.y + offset.y, pageX: rect.x + scrollX, pageY: rect.y + scrollY, width: rect.width, height: rect.height },
    };
  };
  const allInteractive = () => {
    const indexed = globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__?.interactiveElements?.();
    if (Array.isArray(indexed) && indexed.length) return indexed.filter((element) => element?.isConnected && visible(element)).slice(0, 1500);
    const selector = "a[href],button,input:not([type='hidden']),textarea,select,summary,[role],[tabindex],[contenteditable='true'],[onclick]";
    return deepQueryAll(selector).filter(visible).slice(0, 1500);
  };
  const rankCandidate = (element) => {
    const descriptor = describe(element);
    let score = 0;
    const expectedRole = normalize(args.role);
    const actualRole = normalize(descriptor.role);
    const nameStrength = args.name ? Math.max(similarity(descriptor.accessibleName, args.name), similarity(descriptor.text, args.name)) : 0;
    const textStrength = args.text ? Math.max(similarity(descriptor.text, args.text), similarity(descriptor.accessibleName, args.text)) : 0;
    const labelStrength = args.label ? Math.max(
      similarity(descriptor.label, args.label),
      similarity(descriptor.context, args.label),
      similarity(descriptor.accessibleName, args.label),
    ) : 0;
    if (args.selector && descriptor.selector === args.selector) score += 320;
    if (expectedRole) score += actualRole === expectedRole ? 150 : -95;
    if (args.name) {
      score += similarity(descriptor.accessibleName, args.name) * 300;
      score += similarity(descriptor.text, args.name) * 95;
    }
    if (args.text) {
      score += similarity(descriptor.text, args.text) * 245;
      score += similarity(descriptor.accessibleName, args.text) * 110;
    }
    if (args.label) score += labelStrength * 270;
    const intended = normalize(args.intendedAction || action);
    const intendedConcepts = tokenSet(intended);
    const editable = ["input", "textarea", "select"].includes(descriptor.tag)
      || ["textbox", "combobox", "searchbox", "spinbutton"].includes(descriptor.role)
      || descriptor.contentEditable;
    if (["fill", "write", "select", "check"].some((name) => intendedConcepts.includes(name)) || ["fill", "type text", "type_text", "select", "check"].includes(intended)) score += editable ? 80 : -140;
    else if (intendedConcepts.includes("click") || ["click", "double click", "double_click", "hover"].includes(intended)) score += descriptor.clickable ? 55 : (editable ? 8 : -20);
    else if (intended === "press") score += (descriptor.focusable || descriptor.clickable || editable) ? 30 : 0;
    if (descriptor.disabled) score -= 500;
    if (descriptor.ariaHidden) score -= 300;
    if (descriptor.pointerEventsNone) score -= 180;
    if (descriptor.occluded === false) score += 35;
    if (descriptor.occluded === true) score -= 120;
    if (descriptor.inDialog) score += 35;
    if (descriptor.inViewport) score += 22;
    if (descriptor.focusable) score += 10;
    const area = Number(descriptor.boundingBox?.width || 0) * Number(descriptor.boundingBox?.height || 0);
    if (area > 0 && area < 144) score -= 45;
    score -= Math.min(20, Number(descriptor.centerDistance || 0) * 8);
    return { element, descriptor, score, semanticStrength: Math.max(nameStrength, textStrength, labelStrength) };
  };
  let lastMatch = null;
  const find = () => {
    if (args.targetRef) {
      const target = semanticRegistry().targets.get(String(args.targetRef));
      if (target?.isConnected && visible(target)) {
        lastMatch = { strategy: "target_ref", score: 1000 };
        return target;
      }
    }

    let selectorCandidate = null;
    if (args.selector && !/:has-text\(|:text\(|text=|>>/.test(args.selector)) {
      try {
        selectorCandidate = deepQueryAll(args.selector).find(visible) || null;
        const semanticSignals = Boolean(args.role || args.name || args.text || args.label);
        if (selectorCandidate && !semanticSignals) {
          lastMatch = { strategy: "css", score: 320 };
          return selectorCandidate;
        }
      } catch (error) {
        if (!args.role && !args.name && !args.text && !args.label && !args.xpath) throw new Error(`selector_invalid_css:${error.message}`);
      }
    }

    const candidates = allInteractive();
    const hasSemantic = Boolean(args.role || args.name || args.text || args.label);
    if (hasSemantic) {
      let ranked = candidates.map(rankCandidate).sort((a, b) => b.score - a.score);
      const hasLexicalSignal = Boolean(args.name || args.text || args.label);
      if (hasLexicalSignal) ranked = ranked.filter((row) => row.semanticStrength >= 0.32);
      const roleOnly = Boolean(args.role && !args.name && !args.text && !args.label && !args.selector);
      if (roleOnly) {
        const matchingRole = ranked.filter((row) => normalize(row.descriptor.role) === normalize(args.role));
        const dialogMatches = matchingRole.filter((row) => row.descriptor.inDialog);
        ranked = dialogMatches.length === 1 ? dialogMatches : matchingRole.length === 1 ? matchingRole : [];
      }
      if (ranked[0] && ranked[0].score >= 75) {
        lastMatch = { strategy: "semantic_rank", score: ranked[0].score, runnerUp: ranked[1]?.score ?? null };
        return ranked[0].element;
      }
    }

    if (args.label) {
      const wanted = normalize(args.label);
      const label = deepQueryAll("label").find((item) => visible(item) && similarity(item.innerText || item.textContent, wanted) >= 0.82);
      const control = label?.control || (label?.htmlFor ? document.getElementById(label.htmlFor) : label?.querySelector("input,textarea,select,button"));
      if (control && visible(control)) { lastMatch = { strategy: "label_control", score: 210 }; return control; }
    }

    if (selectorCandidate) { lastMatch = { strategy: "css_fallback", score: 200 }; return selectorCandidate; }

    if (args.xpath || args.selectorType === "xpath") {
      const xpath = args.xpath || args.selector;
      try {
        const found = document.evaluate(xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;
        if (found && visible(found)) { lastMatch = { strategy: "xpath", score: 160 }; return found; }
      } catch { /* coordinate fallback below */ }
    }

    if (args.text) {
      const wanted = normalize(args.text);
      const exactText = deepQueryAll("body *").find((element) => visible(element) && normalize(element.innerText || element.textContent) === wanted);
      if (exactText) {
        const interactiveAncestor = exactText.closest?.("a[href],button,summary,[role],[tabindex],[onclick],[contenteditable='true']");
        if (interactiveAncestor && visible(interactiveAncestor)) { lastMatch = { strategy: "text_interactive_ancestor", score: 140 }; return interactiveAncestor; }
        if (getComputedStyle(exactText).cursor === "pointer") { lastMatch = { strategy: "text_pointer", score: 120 }; return exactText; }
      }
    }

    if (args.coordinates && Number.isFinite(Number(args.coordinates.x)) && Number.isFinite(Number(args.coordinates.y))) {
      const found = document.elementFromPoint(Number(args.coordinates.x), Number(args.coordinates.y));
      if (found && visible(found)) { lastMatch = { strategy: "coordinates", score: 100 }; return found; }
    }
    return null;
  };
  const pageSnapshot = () => {
    const registry = args.includeInteractive ? resetSemanticRegistry() : semanticRegistry();
    const interactive = args.includeInteractive ? allInteractive().slice(0, 700).map(describe) : [];
    return {
      ok: true,
      url: location.href,
      frame: { isTop: window === window.top },
      title: document.title,
      text: String(document.body?.innerText || "").replace(/\s+/g, " ").trim().slice(0, Math.max(32_768, Math.min(1_000_000, Number(args.maxChars || 200_000)))),
      viewport: { width: innerWidth, height: innerHeight },
      scroll: { x: scrollX, y: scrollY, maxY: Math.max(0, document.documentElement.scrollHeight - innerHeight) },
      page: { width: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight },
      runtime: globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__ ? {
        mode: "persistent_incremental",
        domVersion: Number(globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__.domVersion || 0),
        indexedInteractive: Number(globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__.indexSize?.() || 0),
      } : { mode: "injected_fallback", domVersion: 0, indexedInteractive: 0 },
      interactionMap: args.includeInteractive ? {
        version: "3.0.0",
        generation: registry.generation,
        count: interactive.length,
        strategyOrder: ["target_ref", "semantic_rank", "css", "label", "xpath", "text_ancestor", "coordinates"],
        descriptors: ["role", "accessibleName", "label", "text", "context", "clickable", "focusable", "disabled", "inViewport", "occluded", "inDialog", "boundingBox"],
      } : null,
      interactive,
    };
  };
  try {
    if (action === "dom_snapshot") {
      const clone = document.documentElement.cloneNode(true);
      for (const script of clone.querySelectorAll("script,noscript,template")) script.remove();
      for (const control of clone.querySelectorAll("input,textarea,select,option")) {
        control.removeAttribute("value");
        control.removeAttribute("checked");
        control.removeAttribute("selected");
        if (["TEXTAREA", "OPTION"].includes(control.tagName)) control.textContent = "";
      }
      for (const element of clone.querySelectorAll("[name],[id],[autocomplete],meta[name]")) {
        const marker = [element.getAttribute("name"), element.id, element.getAttribute("autocomplete")].filter(Boolean).join(" ");
        if (/password|passwd|pwd|passcode|otp|verification|token|secret|csrf|xsrf/i.test(marker)) {
          if (element.hasAttribute("content")) element.setAttribute("content", "[REDACTED]");
          if (element.hasAttribute("value")) element.setAttribute("value", "[REDACTED]");
          element.textContent = "";
        }
      }
      return { ok: true, html: clone.outerHTML.slice(0, 1000000), scriptsOmitted: true, formValuesOmitted: true, url: location.href, title: document.title };
    }
    if (action === "page_snapshot") return pageSnapshot();
    if (action === "scroll") {
      if (args.to === "top") scrollTo({ top: 0, left: 0, behavior: "instant" });
      else if (args.to === "bottom") scrollTo({ top: document.documentElement.scrollHeight, left: 0, behavior: "instant" });
      else scrollBy({ left: args.x, top: args.y, behavior: "instant" });
      return { ok: true, action, scroll: { x: scrollX, y: scrollY, maxY: Math.max(0, document.documentElement.scrollHeight - innerHeight) } };
    }
    if (action === "accessibility_scan") {
      const issues = [];
      for (const image of deepQueryAll("img")) if (visible(image) && !image.alt) issues.push({ type: "image_missing_alt", selector: cssPath(image) });
      for (const input of deepQueryAll("input,textarea,select")) {
        if (!visible(input)) continue;
        const labelled = input.labels?.length || input.getAttribute("aria-label") || input.getAttribute("aria-labelledby") || input.title;
        if (!labelled) issues.push({ type: "control_missing_label", selector: cssPath(input) });
      }
      return { ok: true, issues: issues.slice(0, 1000), count: issues.length, url: location.href };
    }
    const element = find() || (action === "extract_text" && !args.selector ? document.body : null);
    if (!element || !visible(element)) return { ok: false, error: "element_not_found", message: "Nessun elemento visibile corrisponde al target richiesto." };
    const before = describe(element);
    const beforeRect = element.getBoundingClientRect();
    const needsScroll = beforeRect.bottom <= 0 || beforeRect.right <= 0 || beforeRect.top >= innerHeight || beforeRect.left >= innerWidth;
    if (needsScroll) {
      element.scrollIntoView({ block: "center", inline: "center", behavior: "instant" });
      await new Promise((resolve) => requestAnimationFrame(() => resolve()));
    }
    if (action === "locate") return { ok: true, matched: true, element: describe(element), match: lastMatch };
    if (action === "click") {
      const clickCount = Number(args.clickCount ?? args.click_count ?? 1);
      if (!Number.isSafeInteger(clickCount) || clickCount < 1 || clickCount > 3) {
        return { ok: false, error: "click_count_invalid", message: "clickCount deve essere un intero tra 1 e 3." };
      }
      if (isDisabled(element)) {
        return { ok: false, error: "element_disabled", message: "Il target del click è disabilitato." };
      }
      if (!(element instanceof HTMLElement) || typeof HTMLElement.prototype.click !== "function") {
        return { ok: false, error: "element_click_unsupported", message: "Il target non supporta HTMLElement.click()." };
      }
      for (let i = 0; i < clickCount; i += 1) HTMLElement.prototype.click.call(element);
      return {
        ok: true,
        matched: true,
        action,
        element: before,
        after: describe(element),
        match: lastMatch,
        dispatch: { transport: "dom_click", dispatched: clickCount, trusted: false },
        url: location.href,
        title: document.title,
      };
    } else if (action === "hover") {
      element.dispatchEvent(new MouseEvent("mouseover", { bubbles: true }));
      element.dispatchEvent(new MouseEvent("mouseenter", { bubbles: true }));
    } else if (action === "focus") element.focus();
    else if (action === "blur") element.blur();
    else if (action === "type_text") {
      element.focus();
      const text = String(args.value ?? "");
      const proto = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      const setter = Object.getOwnPropertyDescriptor(proto, "value")?.set;
      let currentValue = args.append ? String(element.value || "") : "";
      if (!args.append) {
        if (setter) setter.call(element, ""); else element.value = "";
        element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "deleteContentBackward", data: null }));
      }
      const delay = Math.max(0, Math.min(100, Number(args.keyDelayMs || 0)));
      for (const character of text) {
        currentValue += character;
        if (setter) setter.call(element, currentValue); else element.value = currentValue;
        element.dispatchEvent(new KeyboardEvent("keydown", { key: character, bubbles: true }));
        element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: character }));
        element.dispatchEvent(new KeyboardEvent("keyup", { key: character, bubbles: true }));
        if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
      }
      element.dispatchEvent(new Event("change", { bubbles: true }));
    } else if (action === "fill") {
      element.focus();
      const next = args.append ? String(element.value || "") + String(args.value) : String(args.value);
      const proto = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      const setter = Object.getOwnPropertyDescriptor(proto, "value")?.set;
      if (setter) setter.call(element, next); else element.value = next;
      element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: String(args.value) }));
      element.dispatchEvent(new Event("change", { bubbles: true }));
    } else if (action === "press") {
      element.focus();
      const options = { key: args.key, code: args.key, bubbles: true, cancelable: true };
      element.dispatchEvent(new KeyboardEvent("keydown", options));
      element.dispatchEvent(new KeyboardEvent("keypress", options));
      element.dispatchEvent(new KeyboardEvent("keyup", options));
    } else if (action === "select") {
      const values = Array.isArray(args.value) ? args.value.map(String) : [String(args.value)];
      for (const option of element.options || []) option.selected = values.includes(option.value) || values.includes(option.label);
      element.dispatchEvent(new Event("input", { bubbles: true }));
      element.dispatchEvent(new Event("change", { bubbles: true }));
    } else if (action === "check") {
      if (Boolean(element.checked) !== Boolean(args.value ?? true)) element.click();
    } else if (action === "extract_text") {
      return { ok: true, matched: true, action, element: before, match: lastMatch, text: String(element.innerText || element.textContent || "").slice(0, 1000000), url: location.href };
    } else if (action === "computed_styles") {
      const style = getComputedStyle(element);
      const properties = args.properties.length ? args.properties : ["display", "visibility", "position", "color", "background-color", "font-size", "width", "height"];
      return { ok: true, matched: true, element: before, match: lastMatch, styles: Object.fromEntries(properties.map((name) => [name, style.getPropertyValue(name)])) };
    } else return { ok: false, error: "contract_dom_action_missing", message: `Azione DOM ${action} non implementata.` };
    return { ok: true, matched: true, action, element: before, after: describe(element), match: lastMatch, url: location.href, title: document.title };
  } catch (error) {
    return { ok: false, error: String(error?.message || error), message: String(error?.message || error) };
  }
}
  const handle = async (payload = {}) => {
    if (payload.kind === 'ping') return { pong: true, domVersion, indexSize: interactive.size, url: location.href };
    if (payload.kind === 'dom_action') return domExecutor(String(payload.action || ''), payload.args || {});
    if (payload.kind === 'wait_selector') {
      return eventWait(async () => {
        const result = await domExecutor('locate', payload.args || {});
        return result?.ok && result?.matched ? result : null;
      }, payload.timeoutMs || 30000, [[document, 'readystatechange'], [window, 'pageshow']]);
    }
    if (payload.kind === 'wait_absent') {
      return eventWait(async () => {
        const result = await domExecutor('locate', payload.args || {});
        return !result?.ok || !result?.matched ? { ok: true, absent: true, url: location.href } : null;
      }, payload.timeoutMs || 30000, [[document, 'readystatechange'], [window, 'pageshow']]);
    }
    if (payload.kind === 'wait_ready') {
      const selector = String(payload.selector || '');
      return eventWait(async () => {
        const ready = document.readyState === 'interactive' || document.readyState === 'complete';
        if (!ready || !deepSelectorExists(selector)) return null;
        return { ready: true, selectorReady: true, readyState: document.readyState, bodyLength: String(document.body?.innerText || document.body?.textContent || '').length, url: location.href, domVersion };
      }, payload.timeoutMs || 30000, [[document, 'DOMContentLoaded'], [document, 'readystatechange'], [window, 'pageshow']]);
    }
    if (payload.kind === 'read_value') {
      let element = null;
      const ref = String(payload.targetRef || '');
      if (ref) element = globalThis.__PRSTUDIO_SEMANTIC_TARGETS_V2__?.targets?.get(ref) || null;
      if ((!element || !element.isConnected) && payload.selector) {
        const selector = String(payload.selector || '');
        const roots = [document];
        for (let i = 0; i < roots.length && i < 64 && !element; i += 1) {
          const root = roots[i];
          try { element = root.querySelector(selector); } catch { element = null; break; }
          if (element) break;
          let all = []; try { all = [...root.querySelectorAll('*')]; } catch { all = []; }
          for (const candidate of all) if (candidate.shadowRoot && !roots.includes(candidate.shadowRoot)) roots.push(candidate.shadowRoot);
        }
      }
      if (!element) return { supported: false, value: null, valueLength: null };
      const supported = 'value' in element;
      const value = supported ? String(element.value ?? '') : String(element.textContent ?? '');
      return { supported: true, value, valueLength: value.length, tag: String(element.tagName || '').toLowerCase(), domVersion };
    }
    if (payload.kind === 'batch') {
      const results = [];
      for (const item of Array.isArray(payload.actions) ? payload.actions : []) {
        const result = await domExecutor(String(item?.action || ''), item?.args || {});
        results.push(result);
        if (!result?.ok) break;
      }
      return { ok: results.every((item) => item?.ok), results, count: results.length, domVersion, url: location.href };
    }
    throw new Error(`page_runtime_kind_unknown:${String(payload.kind || '')}`);
  };

  const respondMessage = (message, _sender, sendResponse) => {
    if (message?.channel !== 'prstudio-page-runtime') return undefined;
    handle(message).then((result) => sendResponse({ ok: true, result })).catch((error) => sendResponse({ ok: false, error: String(error?.message || error), message: String(error?.message || error) }));
    return true;
  };
  chrome.runtime.onMessage.addListener(respondMessage);

  const connect = () => {
    try {
      port = chrome.runtime.connect({ name: 'prstudio-page-runtime' });
      reconnectBackoff?.markConnected();
      dirtyNotifier?.reset();
      port.onMessage.addListener((message = {}) => {
        reconnectBackoff?.markActivity();
        if (message?.type !== 'runtime_request' || !message.id) return;
        dirtyNotifier?.synchronize(domVersion, location.href);
        handle(message.payload || {}).then((result) => {
          try { port.postMessage({ type: 'runtime_response', id: message.id, ok: true, result }); } catch { /* disconnected */ }
        }).catch((error) => {
          try { port.postMessage({ type: 'runtime_response', id: message.id, ok: false, error: String(error?.message || error), message: String(error?.message || error) }); } catch { /* disconnected */ }
        });
      });
      port.onDisconnect.addListener(() => {
        port = null;
        if (reconnectBackoff) reconnectBackoff.schedule(connect);
        else setTimeout(connect, 250);
      });
      port.postMessage({ type: 'runtime_ready', domVersion, url: location.href, frameTop: window === window.top });
    } catch {
      port = null;
      if (reconnectBackoff) reconnectBackoff.schedule(connect);
      else setTimeout(connect, 250);
    }
  };
  connect();
})();
