// Shared helpers of the financial-report pages (PR 13). Every word shown comes from PageConfig: `ui` (lang/ar/financial_reports.php)
// and `labels` (lang/ar/statements.php, the codes' names). A code is never shown: a name that can't be found shows ui('unknown').

export const C = window.PageConfig;

/** A page string; `:name` placeholders filled from vars. */
export function ui(key, vars = {}) {
  let s = C.ui[key];
  if (typeof s !== 'string') {
    console.error('missing ui string', key);
    return C.ui.unknown;
  }
  for (const [k, v] of Object.entries(vars)) s = s.replaceAll(':' + k, String(v));
  return s;
}

/** The Arabic name of a code (`checks.tb_partial`, `notes.cash`, …); null when there is none. */
export function label(path) {
  const v = path.split('.').reduce((o, p) => (o && typeof o === 'object' ? o[p] : undefined), C.labels);
  return typeof v === 'string' ? v : null;
}
export const name = (path) => label(path) ?? ui('unknown');

// Interface wording the API builds comes in the page's language (`title`, `message`, `label`, `help`, `basis`, sent by
// Accept-Language); a version saved before those fields existed has only the Arabic `*_ar`, the fallback.
export const checkTitle = (c) => c.title ?? label(`checks.${c.code}`) ?? c.title_ar;
export const checkMessage = (c) => c.message ?? c.message_ar;
export const blockLabel = (b) => b.label ?? label(`blocking.${b.reason}`) ?? b.label_ar;

/** Joins a list with the page language's separator. */
export const joinList = (items) => items.join(ui('list_separator'));

export const url = (key, id = '') => C.urls[key].replace(':id', encodeURIComponent(id));

/** Replaces a node's children, skipping null / false (DOM's replaceChildren would print them as text). */
export function fill(node, ...kids) {
  node.replaceChildren(...kids.flat(Infinity).filter((k) => k !== null && k !== undefined && k !== false));
  return node;
}

/** Appends children, skipping null / false (DOM's append would print them as text). */
export function add(node, ...kids) {
  node.append(...kids.flat(Infinity).filter((k) => k !== null && k !== undefined && k !== false));
  return node;
}

/** Builds an element: el('div', {class: 'x', onclick: fn}, 'text', child, …). Text is always set as text, never as HTML. */
export function el(tag, attrs = {}, ...kids) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs ?? {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else if (k === 'class') e.className = v;
    else if (k === 'value') e.value = v;
    else if (k === 'checked' || k === 'selected' || k === 'disabled') e[k] = !!v;
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const kid of kids.flat(Infinity)) {
    if (kid === null || kid === undefined || kid === false) continue;
    e.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
  }
  return e;
}

/** A message fit for the page: on an Arabic page only one with Arabic in it (the framework's own English becomes a generic one). */
const readable = (s) => (typeof s === 'string' && s.trim() !== '' && (document.documentElement.lang !== 'ar' || /[؀-ۿ]/.test(s)) ? s : null);

/** An API failure: its message and field errors in the page's language (a message that isn't becomes a generic one). */
export class ApiError extends Error {
  constructor(status, data) {
    super(readable(data?.message) ?? (status === 422 ? ui('error_invalid') : status === 404 ? ui('error_not_found') : ui('error_generic')));
    this.status = status;
    this.data = data;
    this.errors = {};
    for (const [field, list] of Object.entries(data?.errors ?? {})) {
      this.errors[field] = (list ?? []).map((m) => readable(m) ?? ui('error_field'));
    }
  }
  /** Every message, the general one first, without repeats. */
  all() {
    return [...new Set([this.message, ...Object.values(this.errors).flat()])];
  }
}

export async function api(method, path, body) {
  let r;
  try {
    r = await fetch(C.api + path, {
      method,
      headers: { Accept: 'application/json', 'Accept-Language': document.documentElement.lang, ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, { message: ui('error_network') });
  }
  let data = null;
  try { data = await r.json(); } catch { /* empty body */ }
  if (!r.ok) throw new ApiError(r.status, data);
  return data;
}

/** Sends a file (multipart, field `file`); the answer and errors as api() gives them. */
export async function upload(path, file) {
  const body = new FormData();
  body.append('file', file);
  let r;
  try {
    // With the loader: the file's upload percentage, then the server's work on it (reading a PDF can take a while).
    r = await window.Busy.request(C.api + path, { method: 'POST', headers: { Accept: 'application/json' }, body });
  } catch {
    throw new ApiError(0, { message: ui('error_network') });
  }
  if (!r.ok) throw new ApiError(r.status, r.json);
  return r.json;
}

/** A negative as the files write it (App\Support\Amounts): (1,234) or -1,234. */
export const negative = (s) => (window.AmountsConfig?.negatives === 'minus' ? `-${s}` : `(${s})`);

/** Whole riyals, «-» for zero, Western digits (Q27); a missing figure is ui('missing_figure'). */
export function money(amount) {
  if (amount === null || amount === undefined) return ui('missing_figure');
  const v = Math.round(amount);
  if (v === 0) return '-';
  const s = Math.abs(v).toLocaleString('en-US');
  return v < 0 ? negative(s) : s;
}

/** Two decimals, Western digits, «-» for zero: for amounts the user types and checks against. */
export function exact(amount) {
  if (amount === null || amount === undefined) return ui('missing_figure');
  const v = Number(amount);
  if (Math.abs(v) < 0.005) return '-';
  const s = Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return v < 0 ? negative(s) : s;
}

export const day = (iso) => (iso ? String(iso).slice(0, 10) : '');

/** A moment in local time, `YYYY-MM-DD HH:MM`, Western digits. */
export function when(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** A message box: kind ok | bad | warn. */
export const box = (kind, ...kids) => el('div', { class: `msg ${kind}` }, ...kids);

/** Shows an API error under a form: the messages, and each field's under its input when the input has data-field. */
export function showErrors(form, err, target) {
  form.querySelectorAll('.err').forEach((e) => e.remove());
  form.querySelectorAll('.invalid').forEach((e) => e.classList.remove('invalid'));
  target.replaceChildren();
  if (!err) return;
  const placed = new Set();
  for (const [field, msgs] of Object.entries(err.errors ?? {})) {
    const input = form.querySelector(`[data-field="${CSS.escape(field)}"]`);
    if (input) {
      input.classList.add('invalid');
      input.after(el('div', { class: 'err' }, msgs.join(' ')));
      placed.add(field);
    }
  }
  const rest = Object.entries(err.errors ?? {}).filter(([f]) => !placed.has(f)).flatMap(([, m]) => m);
  target.append(box('bad', [...new Set([err.message, ...rest])].join(' ')));
}

/** A labelled input. */
export function field(labelText, input, hint) {
  return el('div', { class: 'field' }, el('label', {}, labelText), input, hint ? el('div', { class: 'note' }, hint) : null);
}

/** Reads a number input: null when empty. */
export function numberOf(input) {
  // As the statements print them: Arabic-Indic digits and separators, a negative in brackets «(1,234)», zero as «-».
  const v = input.value.trim()
    .replace(/[\u0660-\u0669]/g, (d) => String(d.charCodeAt(0) - 0x660)).replace(/[\u06F0-\u06F9]/g, (d) => String(d.charCodeAt(0) - 0x6F0))
    .replace(/[,\u066C\s]/g, '').replace('\u066B', '.').replace(/^[\u2212\u2013]/, '-');
  if (v === '') return null;
  if (/^[-\u2013\u2014]$/.test(v)) return 0;
  const bracketed = /^\((.+)\)$/.exec(v);
  return bracketed ? -Number(bracketed[1]) : Number(v);
}

/** An account as the user reads it: «number — name» (never an internal row number); a difference from last year's
 * approved statements (Step B) is no account: said so. */
export function accountName(row) {
  if (row?.approved_opening) return ui('item_by_approved_opening');
  const number = String(row?.account_number ?? '').trim();
  const n = String(row?.account_name ?? '').trim();
  if (number && n) return `${number} — ${n}`;
  return number || n || ui('account_unnamed');
}

/** The rows of the differences from last year's approved statements start here (ApprovedOpening::FIRST_ROW). */
const APPROVED_OPENING_ROW = 1000000;
let rowsCache = null;
/** The run's rows (row number → row), loaded once per page; an approved-opening row number reads as one. */
export async function runRows() {
  if (!rowsCache) {
    const data = (await api('GET', `/classify/jobs/${encodeURIComponent(C.run_id)}`)).result?.data ?? [];
    rowsCache = new Proxy(Object.fromEntries(data.map((r) => [r.row, r])), {
      get: (rows, n) => rows[n] ?? (Number(n) >= APPROVED_OPENING_ROW ? { row: Number(n), approved_opening: true } : undefined),
    });
  }
  return rowsCache;
}

/** Row numbers once each, the approved openings' differences once after the accounts. */
export function accountRows(rows) {
  const unique = [...new Set(rows)];
  const approved = unique.filter((n) => Number(n) >= APPROVED_OPENING_ROW);
  return [...unique.filter((n) => Number(n) < APPROVED_OPENING_ROW), ...approved.slice(0, 1)];
}

/** Accounts of a list of row numbers, named: the first few, then how many more. */
export function accountList(rows, byRow, shown = 5) {
  const all = accountRows(rows);
  const names = all.slice(0, shown).map((n) => `«${accountName(byRow[n])}»`);
  const rest = all.length - shown;
  return joinList(names) + (rest > 0 ? ' ' + ui('and_more_accounts', { count: rest }) : '');
}
