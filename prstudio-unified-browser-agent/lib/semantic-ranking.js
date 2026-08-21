const clean = (value = "") => String(value || "")
  .normalize("NFD")
  .replace(/[\u0300-\u036f]/g, "")
  .toLowerCase()
  .replace(/[^\p{L}\p{N}]+/gu, " ")
  .replace(/\s+/g, " ")
  .trim();

// The server-side routers use PRSTUDIO_UC_Action_Lexicon. The extension cannot
// load PHP, so this bounded browser vocabulary mirrors only concepts that can
// occur in visible control labels. It lets an Italian instruction ground an
// English page (and vice versa) without translating technical selectors, roles
// or target refs.
const CONTROL_CONCEPTS = Object.freeze({
  save: ["save", "salva", "salvare"],
  cancel: ["cancel", "annulla", "annullare"],
  add: ["add", "aggiungi", "aggiungere", "inserisci", "inserire"],
  cart: ["cart", "basket", "carrello"],
  buy: ["buy", "purchase", "acquista", "acquistare", "compra", "comprare"],
  checkout: ["checkout", "cassa"],
  submit: ["submit", "send", "invia", "inviare", "manda", "mandare"],
  continue: ["continue", "continua", "continuare", "prosegui", "proseguire"],
  confirm: ["confirm", "conferma", "confermare"],
  next: ["next", "avanti", "successivo", "successiva"],
  back: ["back", "previous", "indietro", "precedente"],
  close: ["close", "chiudi", "chiudere"],
  open: ["open", "apri", "aprire"],
  login: ["login", "signin", "accedi", "accedere", "entra", "entrare"],
  logout: ["logout", "signout", "esci", "uscire", "disconnetti"],
  search: ["search", "find", "cerca", "cercare", "trova", "trovare"],
  select: ["select", "choose", "seleziona", "selezionare", "scegli", "scegliere"],
  check: ["check", "tick", "spunta", "spuntare", "seleziona"],
  fill: ["fill", "enter", "compila", "compilare", "riempi", "riempire"],
  write: ["type", "write", "digita", "digitare", "scrivi", "scrivere"],
  click: ["click", "press", "clicca", "cliccare", "premi", "premere"],
  upload: ["upload", "carica", "caricare"],
  download: ["download", "scarica", "scaricare"],
  remove: ["remove", "delete", "rimuovi", "rimuovere", "elimina", "eliminare"],
  edit: ["edit", "modify", "modifica", "modificare"],
  apply: ["apply", "applica", "applicare"],
  coupon: ["coupon", "voucher", "buono", "sconto"],
  filter: ["filter", "filtra", "filtrare"],
  sort: ["sort", "ordina", "ordinare"],
  menu: ["menu"],
  details: ["details", "detail", "dettagli", "dettaglio"],
  more: ["more", "altro", "altri", "piu"],
  name: ["name", "nome"],
  address: ["address", "indirizzo"],
  phone: ["phone", "telephone", "telefono"],
  company: ["company", "business", "azienda", "societa"],
  quantity: ["quantity", "qty", "quantita"],
  payment: ["payment", "pagamento"],
  shipping: ["shipping", "delivery", "spedizione", "consegna"],
});

const CONTROL_WORD_TO_CONCEPT = (() => {
  const out = new Map();
  for (const [concept, forms] of Object.entries(CONTROL_CONCEPTS)) {
    out.set(concept, concept);
    for (const form of forms) out.set(clean(form).replace(/\s+/g, ""), concept);
  }
  return out;
})();

const CONTROL_STOP_WORDS = new Set([
  "a", "an", "the", "to", "of", "for", "in", "on", "and", "or", "your",
  "al", "allo", "alla", "ai", "agli", "alle", "il", "lo", "la", "i", "gli", "le",
  "un", "uno", "una", "di", "del", "della", "dei", "delle", "per", "nel", "nella", "e", "o",
]);

const tokens = (value = "") => [...new Set(clean(value).split(" ").filter((part) => part.length > 1))];

const conceptTokens = (value = "") => [...new Set(tokens(value)
  .filter((token) => !CONTROL_STOP_WORDS.has(token))
  .map((token) => CONTROL_WORD_TO_CONCEPT.get(token) || `raw:${token}`))];

const tokenSimilarity = (actual = "", expected = "") => {
  const at = conceptTokens(actual);
  const bt = conceptTokens(expected);
  if (!at.length || !bt.length) return 0;
  const intersection = bt.filter((token) => at.includes(token)).length;
  if (!intersection) return 0;
  const precision = intersection / at.length;
  const recall = intersection / bt.length;
  return (2 * precision * recall) / Math.max(0.0001, precision + recall);
};

function similarity(actual = "", expected = "") {
  const a = clean(actual);
  const b = clean(expected);
  if (!a || !b) return 0;
  if (a === b) return 1;
  if (a.startsWith(b) || b.startsWith(a)) return 0.9;
  if (a.includes(b) || b.includes(a)) return 0.82;
  return tokenSimilarity(a, b);
}

function actionCompatibility(target = {}, intendedAction = "") {
  const actionConcepts = conceptTokens(intendedAction);
  const action = clean(intendedAction);
  const tag = clean(target.tag);
  const role = clean(target.role);
  const type = clean(target.inputType);
  const editable = ["input", "textarea", "select"].includes(tag)
    || ["textbox", "combobox", "searchbox", "spinbutton"].includes(role)
    || Boolean(target.contentEditable);
  const clickable = ["button", "link", "menuitem", "option", "tab", "checkbox", "radio", "switch"].includes(role)
    || ["button", "a", "summary"].includes(tag)
    || (tag === "input" && ["button", "submit", "reset", "checkbox", "radio", "image"].includes(type))
    || Boolean(target.clickable);

  if (["fill", "write", "select", "check"].some((intent) => actionConcepts.includes(intent))
    || ["fill", "type text", "type_text", "select", "check"].includes(action)) {
    return editable ? 80 : -140;
  }
  if (actionConcepts.includes("click") || ["click", "double click", "double_click", "hover"].includes(action)) {
    return clickable ? 55 : (editable ? 8 : -20);
  }
  if (action === "press") return (editable || clickable || target.focusable) ? 30 : 0;
  return 0;
}

function scoreTarget(target = {}, query = {}) {
  let score = 0;
  const reasons = [];
  const add = (value, reason) => { score += value; if (value) reasons.push([reason, Number(value.toFixed?.(2) ?? value)]); };

  if (query.targetRef && String(target.targetRef || "") === String(query.targetRef)) add(1000, "target_ref");
  if (query.selector && target.selector && String(query.selector) === String(target.selector)) add(320, "selector_exact");

  const expectedRole = clean(query.role);
  const actualRole = clean(target.role);
  if (expectedRole) add(actualRole === expectedRole ? 150 : -95, actualRole === expectedRole ? "role_exact" : "role_mismatch");

  const nameScore = similarity(target.accessibleName, query.name);
  if (query.name) add(nameScore * 300, "accessible_name");
  const textScore = similarity(target.text, query.text);
  if (query.text) add(textScore * 245, "text");
  const labelScore = Math.max(
    similarity(target.label, query.label),
    similarity(target.context, query.label),
    similarity(target.accessibleName, query.label),
  );
  if (query.label) add(labelScore * 270, "label_context");

  // Cross-signal rescue: a model may put visible button copy in `name` or `text` interchangeably.
  if (query.name) add(similarity(target.text, query.name) * 95, "name_to_text");
  if (query.text) add(similarity(target.accessibleName, query.text) * 110, "text_to_name");

  add(actionCompatibility(target, query.intendedAction), "action_compatibility");
  if (target.disabled) add(-500, "disabled");
  if (target.ariaHidden) add(-300, "aria_hidden");
  if (target.pointerEventsNone) add(-180, "pointer_events_none");
  if (target.occluded === false) add(35, "topmost");
  if (target.occluded === true) add(-120, "occluded");
  if (target.inDialog) add(35, "active_dialog");
  if (target.inViewport) add(22, "in_viewport");
  if (target.focusable) add(10, "focusable");

  const box = target.boundingBox || {};
  const area = Number(box.width || 0) * Number(box.height || 0);
  if (area > 0 && area < 144) add(-45, "tiny_target");
  if (Number.isFinite(Number(target.centerDistance))) add(-Math.min(20, Number(target.centerDistance) * 8), "center_distance");

  return {
    target, score, reasons,
    semanticStrength: Math.max(nameScore, textScore, labelScore),
    identityExact: reasons.some(([reason]) => reason === "target_ref" || reason === "selector_exact"),
  };
}

export function rankSemanticTargets(targets = [], query = {}, { limit = 8 } = {}) {
  const hasSignal = Boolean(query?.targetRef || query?.selector || query?.role || query?.name || query?.text || query?.label);
  if (!hasSignal) return [];
  const hasLexicalSignal = Boolean(query?.name || query?.text || query?.label);
  return (Array.isArray(targets) ? targets : [])
    .map((target, index) => ({ ...scoreTarget(target, query), index }))
    .filter((row) => Number.isFinite(row.score))
    .filter((row) => !hasLexicalSignal || row.identityExact || row.semanticStrength >= 0.32)
    .sort((a, b) => (b.score - a.score) || (b.semanticStrength - a.semanticStrength) || (a.index - b.index))
    .slice(0, Math.max(1, Math.min(50, Number(limit || 8))));
}

export function bestSemanticTarget(targets = [], query = {}) {
  return rankSemanticTargets(targets, query, { limit: 1 })[0] || null;
}

export const __semanticRankingTest = Object.freeze({ clean, tokens, conceptTokens, similarity, actionCompatibility, scoreTarget });
