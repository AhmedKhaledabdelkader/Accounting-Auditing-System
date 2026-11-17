// The synonyms of the statements' lines (PR 17): other wordings a file of last year's issued statements may use. The user
// sees them by statement, adds, edits (wording or line) and deletes them. Matching a file against them is exact, server-side.
import { api, el, fill, ui, name, box, field, showErrors } from './core.js';

const app = document.getElementById('app');
let list = [];
let lines = {};
let query = '';
let flash = null;
let last = { statement: null, line: null };   // the add form keeps the last statement and line chosen

async function load() {
  try {
    ({ data: list, lines } = await api('GET', '/statement-synonyms'));
  } catch (e) {
    app.replaceChildren(box('bad', e.message));
    return;
  }
  render();
}

/** A select of a statement's lines and totals. */
function lineSelect(statement, value, attrs = {}) {
  return el('select', { style: 'width:100%;max-width:100%', ...attrs }, (lines[statement] ?? []).map((l) => el('option', { value: l.key, selected: l.key === value },
    l.type === 'total' ? ui('syn_total', { line: l.label_ar }) : l.label_ar)));
}

function addForm() {
  const statements = Object.keys(lines);
  const first = statements.includes(last.statement) ? last.statement : statements[0];
  const statement = el('select', {}, statements.map((s) => el('option', { value: s, selected: s === first }, name(`statements.${s}`))));
  const lineHolder = el('div', { style: 'width:100%;min-width:0' });
  let line = lineSelect(first, first === last.statement ? last.line : null, { 'data-field': 'line_key' });
  lineHolder.append(line);
  statement.addEventListener('change', () => { line = lineSelect(statement.value, null, { 'data-field': 'line_key' }); lineHolder.replaceChildren(line); });
  const label = el('input', { 'data-field': 'label', placeholder: ui('syn_label') });
  const errors = el('div');
  const form = el('form', { class: 'panel', 'data-syn-add': '' }, el('h2', {}, ui('syn_add_title')),
    el('div', { class: 'row' }, field(ui('syn_statement'), statement), field(ui('syn_line'), lineHolder), field(ui('syn_label'), label)),
    el('button', { class: 'btn', type: 'submit' }, ui('syn_add')), errors);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('POST', '/statement-synonyms', { line_key: line.value, label: label.value });
      last = { statement: statement.value, line: line.value };
      flash = box('ok', ui('syn_added'));
      await load();
    } catch (err) {
      showErrors(form, err, errors);
    }
  });
  return form;
}

function row(s) {
  const label = el('input', { value: s.label, 'data-field': 'label', style: 'min-width:260px' });
  const line = lineSelect(s.statement, s.line_key, { 'data-field': 'line_key' });
  const errors = el('td');
  const tr = el('tr', { 'data-synonym': s.id });
  const save = async () => {
    try {
      await api('PUT', `/statement-synonyms/${s.id}`, { line_key: line.value, label: label.value });
      flash = box('ok', ui('syn_saved'));
      await load();
    } catch (err) {
      showErrors(tr, err, errors);
    }
  };
  const remove = async () => {
    if (!window.confirm(ui('syn_delete_confirm', { label: s.label }))) return;
    try {
      await api('DELETE', `/statement-synonyms/${s.id}`);
      flash = box('ok', ui('syn_deleted'));
      await load();
    } catch (err) {
      showErrors(tr, err, errors);
    }
  };
  return fill(tr, el('td', {}, label), el('td', {}, line),
    el('td', {}, el('div', { class: 'toolbar', style: 'margin:0' },
      el('button', { class: 'btn ghost small', type: 'button', 'data-syn-save': '', onclick: save }, ui('syn_save')),
      el('button', { class: 'btn ghost small', type: 'button', 'data-syn-delete': '', onclick: remove }, ui('syn_delete')))),
    errors);
}

// The search box stays while the lists below it redraw, so typing keeps its place.
const search = el('input', { placeholder: ui('syn_search'), 'data-syn-search': '', oninput: (e) => { query = e.target.value; drawLists(); } });
const lists = el('div');

function drawLists() {
  const q = query.trim();
  fill(lists, Object.keys(lines).map((statement) => {
    // In the statement's order of lines.
    const order = Object.fromEntries((lines[statement] ?? []).map((l, i) => [l.key, i]));
    const items = list.filter((s) => s.statement === statement && (!q || s.label.includes(q) || s.line_label_ar.includes(q)))
      .sort((a, b) => (order[a.line_key] ?? 999) - (order[b.line_key] ?? 999) || a.id - b.id);
    return el('div', { class: 'panel', 'data-syn-statement': statement }, el('h2', {}, name(`statements.${statement}`), el('span', { class: 'note' }, ` — ${ui('syn_count', { n: items.length })}`)),
      items.length
        ? el('table', {}, el('tr', {}, el('th', {}, ui('syn_label')), el('th', {}, ui('syn_line')), el('th'), el('th')), items.map(row))
        : el('p', { class: 'note' }, ui('syn_none')));
  }));
}

function render() {
  fill(app, flash, el('p', { class: 'note' }, ui('syn_hint')), addForm(), el('div', { class: 'toolbar' }, field(ui('syn_search'), search)), lists);
  drawLists();
  flash = null;
}

load();
