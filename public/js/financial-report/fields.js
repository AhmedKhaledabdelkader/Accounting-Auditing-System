// The report's questions (GET …/intake) as a form (PR 13b), for steps 4 and 5: each field by its type, its Arabic label and
// help, a suggested default shown as such, and only the changed answers saved (PATCH …/inputs; empty removes an answer).
import { el, add, ui, label, field, numberOf, showErrors, accountName, box } from './core.js';

/** Keys answered in step 5 (the notes); the rest are step 4's. */
export const NOTE_FIELDS = /^(approval|partners$|capital|policies|first_application|going_concern|useful_life|related_parties|events|supplier_finance|other_provisions)/;

const LONG_TEXT = /(events|effect_text|mitigation|description|terms|reason|reliefs)/;
const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);

export function optionLabel(f, o) {
  return label(`choices.${f.key}.${o}`) ?? f.option_labels?.[o] ?? ui('unknown');
}

/** A table answer (partners, related parties): rows of columns; a `rows` column picks accounts. */
function tableInput(f, value, byRow) {
  const body = el('tbody');
  const accounts = Object.values(byRow ?? {});
  const addRow = (r = {}) => {
    const cells = f.columns.map((c) => {
      let input;
      if (c.type === 'rows') {
        input = el('select', { multiple: true, size: 4, style: 'min-width:220px' },
          accounts.map((a) => el('option', { value: a.row, selected: (r[c.key] ?? []).includes(a.row) }, accountName(a))));
        input.read = () => [...input.selectedOptions].map((o) => Number(o.value));
      } else {
        input = el('input', { class: c.type === 'number' ? 'num' : null, inputmode: c.type === 'number' ? 'decimal' : null, value: r[c.key] ?? '' });
        input.read = () => (c.type === 'number' ? numberOf(input) : (input.value.trim() || null));
      }
      return [c, input];
    });
    const tr = el('tr', {}, cells.map(([, i]) => el('td', {}, i)),
      el('td', {}, el('button', { class: 'btn danger small', type: 'button', onclick: () => tr.remove() }, ui('remove'))));
    tr.read = () => Object.fromEntries(cells.map(([c, i]) => [c.key, i.read()]).filter(([, v]) => v !== null && !(Array.isArray(v) && !v.length)));
    body.append(tr);
  };
  (value ?? []).forEach(addRow);
  const node = el('div', { 'data-field': `inputs.${f.key}` },
    el('div', { style: 'overflow-x:auto' }, el('table', {}, el('thead', {}, el('tr', {}, f.columns.map((c) => el('th', {}, c.label ?? c.label_ar, c.required ? ' *' : '')), el('th'))), body)),
    el('button', { class: 'btn ghost small', type: 'button', onclick: () => addRow() }, ui('add_row')));
  node.read = () => {
    const rows = [...body.children].map((tr) => tr.read()).filter((r) => Object.keys(r).length);
    return rows.length ? rows : null;
  };
  return node;
}

function input(f, value, byRow) {
  const attrs = { 'data-field': `inputs.${f.key}` };
  let i;
  switch (f.type) {
    case 'boolean':
      i = el('select', attrs, el('option', { value: '' }, ui('not_answered')),
        el('option', { value: '1', selected: value === true }, ui('yes')), el('option', { value: '0', selected: value === false }, ui('no')));
      i.read = () => (i.value === '' ? null : i.value === '1');
      return i;
    case 'number':
      i = el('input', { ...attrs, class: 'num', inputmode: 'decimal', value: value ?? '' });
      i.read = () => numberOf(i);
      return i;
    case 'date':
      i = el('input', { ...attrs, type: 'date', value: value ?? '' });
      i.read = () => i.value || null;
      return i;
    case 'choice':
      i = el('select', attrs, el('option', { value: '' }, ui('not_answered')), (f.options ?? []).map((o) => el('option', { value: o, selected: o === value }, optionLabel(f, o))));
      i.read = () => i.value || null;
      return i;
    case 'choices': {
      const boxes = (f.options ?? []).map((o) => [o, el('input', { type: 'checkbox', checked: (value ?? []).includes(o) })]);
      i = el('div', attrs, boxes.map(([o, b]) => el('label', { style: 'display:inline-block;margin-inline-end:16px' }, b, ' ', optionLabel(f, o))));
      i.read = () => { const v = boxes.filter(([, b]) => b.checked).map(([o]) => o); return v.length ? v : null; };
      return i;
    }
    case 'table':
      return tableInput(f, value, byRow);
    default:
      i = LONG_TEXT.test(f.key) ? el('textarea', attrs, value ?? '') : el('input', { ...attrs, value: value ?? '' });
      i.read = () => (i.value.trim() === '' ? null : i.value.trim());
      return i;
  }
}

/**
 * Asks what to do with suggested answers the user left as they were: accept them with the rest, or save only the user's
 * own changes. Resolves 'accept' | 'mine' | null (back to the form). Nothing suggested is saved without this answer.
 */
function askAboutSuggestions(labels) {
  return new Promise((resolve) => {
    const done = (answer) => { dialog.close(); dialog.remove(); resolve(answer); };
    const dialog = el('dialog', { class: 'panel', style: 'max-width:560px;color:var(--text)' },
      el('h3', {}, ui('suggestions_dialog_title')),
      el('p', {}, ui('suggestions_dialog_text')),
      el('ul', {}, labels.map((l) => el('li', {}, l))),
      el('div', { class: 'toolbar' },
        el('button', { class: 'btn', type: 'button', 'data-dialog': 'accept', onclick: () => done('accept') }, ui('suggestions_accept')),
        el('button', { class: 'btn ghost', type: 'button', 'data-dialog': 'mine', onclick: () => done('mine') }, ui('suggestions_mine')),
        el('button', { class: 'btn ghost', type: 'button', 'data-dialog': 'cancel', onclick: () => done(null) }, ui('cancel'))));
    dialog.addEventListener('cancel', (e) => { e.preventDefault(); done(null); });
    document.body.append(dialog);
    dialog.showModal();
  });
}

/**
 * A form of these fields; `onSave(patch)` sends the changed answers and returns a promise (rejects with an ApiError).
 * A suggested answer the user didn't touch is saved only if they accept it in the box that lists them.
 * @returns {HTMLElement}
 */
export function fieldsForm(fields, byRow, onSave, submitLabel) {
  const errors = el('div');
  const rows = fields.map((f) => {
    const suggested = (f.value === null || f.value === undefined) && f.default !== null && f.default !== undefined;
    const start = suggested ? f.default : f.value;
    const i = input(f, start, byRow);
    const empty = f.required && (f.value === null || f.value === undefined);
    const wrap = field(f.label ?? f.label_ar, i, f.help ?? f.help_ar);
    // A required question with a suggestion shown is to confirm, not empty: one badge says so.
    add(wrap.querySelector('label'),
      f.required && !(empty && suggested) ? el('span', { class: `badge ${empty ? 'b-bad' : 'b-ok'}` }, ui(empty ? 'required_empty' : 'required_answered')) : null,
      suggested ? el('span', { class: 'badge b-warn', 'data-suggested': '' }, ui(f.required ? 'required_confirm' : 'suggested_answer')) : null);
    // What the input reads before the user touches it: a suggestion left as it is reads the same.
    return { f, i, wrap, suggested, initial: i.read() };
  });
  const form = el('form', {}, rows.map((r) => r.wrap), errors, el('button', { class: 'btn', type: 'submit' }, submitLabel ?? ui('save_answers')));
  // One save at a time: a second click while the box or the request is open does nothing (no second box).
  let busy = false;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return;
    busy = true;
    try { await submit(); } finally { busy = false; }
  });
  async function submit() {
    const patch = {};
    const untouched = {};
    let bad = false;
    for (const { f, i, suggested, initial } of rows) {
      const v = i.read();
      if (typeof v === 'number' && Number.isNaN(v)) { i.classList.add('invalid'); bad = true; continue; }
      if (Array.isArray(v) && v.some((r) => Object.values(r).some((x) => typeof x === 'number' && Number.isNaN(x)))) { bad = true; continue; }
      if (suggested && v !== null && same(v, initial)) untouched[f.key] = { value: v, label: f.label ?? f.label_ar };
      else if (!same(v, f.value)) patch[f.key] = v;
    }
    if (bad) { showErrors(form, { message: ui('numbers_invalid'), errors: {} }, errors); return; }
    if (Object.keys(untouched).length) {
      const answer = await askAboutSuggestions(Object.values(untouched).map((u) => u.label));
      if (answer === null) return;
      if (answer === 'accept') for (const [k, u] of Object.entries(untouched)) patch[k] = u.value;
    }
    if (!Object.keys(patch).length) { errors.replaceChildren(box('warn', ui('nothing_changed'))); return; }
    try { await onSave(patch); } catch (err) { showErrors(form, err, errors); }
  }
  return form;
}
