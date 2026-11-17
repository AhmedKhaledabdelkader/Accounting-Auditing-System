// The statements and notes as they will print (PR 13c): the review step draws the report as it stands, the versions step a
// frozen version's payload, which has the same shape. Read-only. Whole riyals; «[ناقص]» for a missing figure, never 0.
import { el, ui, name, money, day, accountName, accountRows } from './core.js';

const badge = (cls, text) => el('span', { class: `badge ${cls}` }, text);

/** A figure still waiting for a confirmation (a missing one already reads «[ناقص]»). */
function figureBadge(f) {
  return f?.status === 'pending_confirmation' ? badge('b-warn', name('statuses.pending_confirmation')) : null;
}

const isZero = (f, shown) => !!f && f.amount !== null && f.amount !== undefined && Math.round(shown ?? f.amount) === 0;
/** The whole riyals a line or total shows: the state's settled figure (totals foot, WholeRiyals), else the amount. */
const shownAmount = (f, shown) => (f?.amount === null || f?.amount === undefined ? f?.amount : (shown ?? f.amount));

/**
 * Which rows show: the rule of the Excel file and the PDF (app/Services/Exports/ShownRows.php). A line or total zero in
 * both years is hidden (accounts under it don't keep it; a missing prior year isn't zero, so the line shows «[ناقص]»);
 * a heading shows when a line under it shows, up to the total that closes it.
 */
function shownRows(rows, hasCmp) {
  return withHeadings(rows, (r) => !(isZero(r.current, r.shown?.current) && (!hasCmp || isZero(r.comparative, r.shown?.comparative))));
}

/** Rows shown: lines and totals by `shows`, a heading when a line under it shows (up to the total that closes it). */
export function withHeadings(rows, shows) {
  const shown = [];
  const open = [];
  const scopes = {};
  rows.forEach((r, i) => {
    if (r.type === 'heading') {
      open.push(i);
      scopes[i] = [];
      shown[i] = false;
      return;
    }
    shown[i] = !!shows(r);
    if (r.type === 'line') open.forEach((h) => scopes[h].push(i));
    else if (r.type === 'total' && open.length) open.pop();
  });
  Object.entries(scopes).forEach(([h, lines]) => { shown[h] = lines.some((i) => shown[i]); });
  return shown;
}

function lineStatement(state, key, byRow) {
  const rows = state.statements[key] ?? [];
  const hasCmp = state.comparative.source !== 'first_year';
  const hasNotes = key !== 'cash_flows';
  const cols = 2 + (hasCmp ? 1 : 0) + (hasNotes ? 1 : 0);
  const shown = shownRows(rows, hasCmp);
  const table = el('table', { class: 'fs-table' },
    el('tr', {}, el('th'), hasNotes ? el('th', {}, ui('col_note')) : null,
      el('th', { class: 'num' }, day(state.period_end)),
      hasCmp ? el('th', { class: 'num' }, state.comparative_end ? day(state.comparative_end) : name('scopes.comparative'),
        state.comparative.restated ? ` ${ui('restated_short')}` : '') : null),
    rows.map((r, i) => {
      const zero = !shown[i];
      if (r.type === 'heading') return el('tr', { class: `heading${zero ? ' zero' : ''}` }, el('td', { colspan: cols }, r.label_ar));
      const accounts = r.type === 'line' && (r.rows ?? []).length
        ? el('details', {}, el('summary', {}, r.label_ar, figureBadge(r.current), (r.check_codes ?? []).map((c) => badge('b-bad', name(`checks.${c}`)))),
          el('ul', { class: 'note' }, accountRows(r.rows).map((n) => el('li', {}, accountName(byRow[n])))))
        : [r.label_ar, figureBadge(r.current), (r.check_codes ?? []).map((c) => badge('b-bad', name(`checks.${c}`)))];
      return el('tr', { class: `${r.type}${zero ? ' zero' : ''}`, 'data-line': r.key },
        el('td', {}, accounts),
        hasNotes ? el('td', {}, r.note ?? '') : null,
        el('td', { class: 'num' }, money(shownAmount(r.current, r.shown?.current))),
        hasCmp ? el('td', { class: 'num' }, money(shownAmount(r.comparative, r.shown?.comparative)), figureBadge(r.comparative)) : null);
    }));
  return section(key, name(`statements.${key}`), table);
}

function equityTable(soce, rows) {
  return el('div', { style: 'overflow-x:auto' }, el('table', { class: 'fs-table' },
    el('tr', {}, el('th'), soce.columns.map((c) => el('th', { class: 'num' }, c.label_ar))),
    rows.map((r) => el('tr', { class: ['opening', 'total_comprehensive_income', 'closing'].includes(r.key) ? 'total' : null },
      el('td', {}, r.label_ar),
      soce.columns.map((c) => el('td', { class: 'num' }, money(shownAmount(r.cells[c.key], r.shown?.[c.key])), figureBadge(r.cells[c.key])))))));
}

function equityStatement(state) {
  const soce = state.statements.changes_in_equity;
  if (!soce) return null;
  return section('changes_in_equity', name('statements.changes_in_equity'),
    el('h3', {}, name('scopes.current')), equityTable(soce, soce.current),
    soce.comparative ? [el('h3', {}, name('scopes.comparative')), equityTable(soce, soce.comparative)] : null);
}

/** The visible notes, numbered as printed; the hidden ones named underneath. */
function notesView(state) {
  const notes = state.notes ?? [];
  if (!notes.length) return null;
  const hidden = notes.filter((n) => !n.visible);
  return section('notes', ui('notes_title'),
    notes.filter((n) => n.visible).map((n) => el('div', { class: 'fs-note', 'data-note': n.key },
      el('h3', {}, `${n.number}. ${n.title_ar}`),
      n.blocks.map((b) => (b.type === 'table'
        ? el('div', { style: 'overflow-x:auto' }, el('table', { class: 'fs-table' }, b.rows.map((row) => el('tr', {},
          row.cells.map((c) => (c.amount ? el('td', { class: 'num' }, money(c.amount.amount), figureBadge(c.amount)) : el('td', {}, c.text_ar)))))))
        : el('p', { class: `fs-p ${b.style ?? ''}` }, b.number ? `${b.number} ${b.text_ar}` : b.text_ar))))),
    hidden.length ? el('p', { class: 'note' }, ui('notes_hidden', { notes: hidden.map((n) => n.title_ar).join(ui('list_separator')) })) : null);
}

function section(key, title, ...body) {
  const zeroToggle = key !== 'notes' && key !== 'changes_in_equity';
  const panel = el('section', { class: 'panel fs', 'data-statement': key }, el('div', { class: 'toolbar', style: 'margin:0 0 8px' }, el('h2', { style: 'margin:0;flex:1' }, title),
    zeroToggle ? el('label', { class: 'note' }, el('input', { type: 'checkbox', onchange: (e) => panel.classList.toggle('show-zero', e.target.checked) }), ' ', ui('show_zero')) : null), body);
  return panel;
}

/** @returns {HTMLElement} the four statements and the notes of a state (the report now, or a version's payload) */
export function statementsView(state, byRow) {
  return el('div', {},
    el('p', { class: 'note' }, ui('amounts_hint')),
    lineStatement(state, 'financial_position', byRow),
    lineStatement(state, 'comprehensive_income', byRow),
    equityStatement(state),
    lineStatement(state, 'cash_flows', byRow),
    notesView(state));
}
