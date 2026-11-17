// Step 4 (PR 13b): the questions on figures (zakat, other comprehensive income, loans' current portion, disposal proceeds, …),
// the movement suggestions to confirm, edit or reject (asset notes, provisions, changes in equity, cash flow), the cells only
// the user can give, and the movement tables as they stand. Nothing is accepted without the user (Q19, rule: no silent change).
import { C, api, el, ui, box, money, exact, numberOf, runRows, accountList, showErrors, checkTitle, checkMessage } from '../core.js';
import { cellLabel } from '../describe.js';
import { fieldsForm, NOTE_FIELDS, optionLabel } from '../fields.js';

const STATUS_BADGE = { pending: 'b-warn', confirmed: 'b-ok', edited: 'b-info', rejected: 'b-bad' };

export async function render(ctx) {
  const s = ctx.state;
  const byRow = await runRows();
  const intake = (await api('GET', `/financial-reports/${encodeURIComponent(C.report_id)}/intake`)).fields;
  const wrap = el('div');
  const status = el('div');
  wrap.append(status);

  async function decide(decisions) {
    status.replaceChildren();
    try {
      await ctx.save('POST', '/suggestions', { decisions });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) {
      status.append(box('bad', err.all().join(' ')));
      status.scrollIntoView({ block: 'center' });
    }
  }

  // A0. Retained earnings lost more than the result and the transfers: the user names it with one click (never a dividend
  // by guess); the choice stays editable below with the other questions.
  const retained = intake.find((f) => f.key === 'soce.retained_movement');
  // Named a prior-year adjustment: the panel stays with its flag (pending the accountant), the choice still one click.
  const unexplained = s.checks.find((c) => c.code === 'retained_unexplained' || c.code === 'prior_year_adjustment_pending');
  if (retained && unexplained) {
    wrap.append(el('div', { class: 'panel', 'data-retained': unexplained.code }, el('h2', {}, checkTitle(unexplained)),
      el('p', { class: 'note' }, checkMessage(unexplained), unexplained.rows?.length ? ` ${ui('accounts_label')} ${accountList(unexplained.rows, byRow)}` : ''),
      el('div', { class: 'toolbar' }, retained.options.map((o) => el('button', { class: 'btn small', type: 'button', 'data-retained-choice': o, onclick: async () => {
        status.replaceChildren();
        try {
          await ctx.save('PATCH', '/inputs', { inputs: { 'soce.retained_movement': o } });
          ctx.flash = ui('saved');
          ctx.redraw();
        } catch (err) {
          status.append(box('bad', err.all().join(' ')));
        }
      } }, optionLabel(retained, o))))));
  }

  // A. Questions on figures.
  const fields = intake.filter((f) => !NOTE_FIELDS.test(f.key));
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('figure_questions')),
    fields.length ? fieldsForm(fields, byRow, async (patch) => {
      await ctx.save('PATCH', '/inputs', { inputs: patch });
      ctx.flash = ui('saved');
      ctx.redraw();
    }) : el('p', { class: 'note' }, ui('no_questions'))));

  // B. Movement suggestions, by table.
  const groups = {};
  for (const x of s.suggestions) (groups[x.schedule] ??= []).push(x);
  const pendingAll = s.suggestions.filter((x) => x.status === 'pending');
  const sugPanel = el('div', { class: 'panel' }, el('h2', {}, ui('suggestions_title')), el('p', { class: 'note' }, ui('suggestions_hint')));
  if (!s.suggestions.length) sugPanel.append(el('p', { class: 'note' }, ui('no_suggestions')));
  if (pendingAll.length > 1) {
    sugPanel.append(el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', type: 'button',
      onclick: () => window.confirm(ui('confirm_all_question', { count: pendingAll.length })) && decide(pendingAll.map((x) => ({ key: x.key, action: 'confirm' }))) },
    ui('confirm_all', { count: pendingAll.length }))));
  }
  for (const [schedule, list] of Object.entries(groups)) {
    const title = s.schedules.find((x) => x.key === schedule)?.title_ar ?? ui(`group_${schedule}`);
    sugPanel.append(el('h3', {}, title), el('table', {},
      el('tr', {}, el('th', {}, ui('col_item')), el('th', {}, ui('col_suggested')), el('th', {}, ui('col_basis')), el('th', {}, ui('col_status')), el('th', {})),
      list.map((x) => {
        const amount = el('input', { class: 'num', inputmode: 'decimal', value: x.value ?? x.amount, style: 'width:130px', 'data-suggestion': x.key });
        const editBtn = el('button', { class: 'btn ghost small', type: 'button', onclick: () => {
          const v = numberOf(amount);
          if (v === null || Number.isNaN(v)) { amount.classList.add('invalid'); return; }
          decide([{ key: x.key, action: 'edit', amount: v }]);
        } }, ui('use_amount'));
        return el('tr', {},
          el('td', {}, x.label_ar.split(' — ').slice(1).join(' — ') || x.label_ar),
          el('td', { class: 'num' }, exact(x.amount)),
          el('td', { class: 'note' }, x.basis ?? x.basis_ar, x.rows?.length ? el('div', {}, ui('accounts_label'), ' ', accountList(x.rows, byRow)) : null),
          el('td', {}, el('span', { class: `badge ${STATUS_BADGE[x.status]}` }, ui(`suggestion_${x.status}`)), x.status === 'edited' ? el('div', { class: 'num' }, exact(x.value)) : null),
          el('td', {}, el('div', { class: 'toolbar', style: 'margin:0' },
            x.status !== 'confirmed' ? el('button', { class: 'btn small', type: 'button', 'data-confirm': x.key, onclick: () => decide([{ key: x.key, action: 'confirm' }]) }, ui('confirm')) : null,
            amount, editBtn,
            x.status !== 'rejected' ? el('button', { class: 'btn danger small', type: 'button', onclick: () => decide([{ key: x.key, action: 'reject' }]) }, ui('reject')) : null,
            x.status !== 'pending' ? el('button', { class: 'btn ghost small', type: 'button', onclick: () => decide([{ key: x.key, action: 'reset' }]) }, ui('reset_decision')) : null)));
      })));
  }
  wrap.append(sugPanel);

  // C. Cells only the user can give (no movement columns, or a suggestion rejected).
  const cells = s.missing.filter((m) => ['movement_required', 'suggestion_rejected'].includes(m.reason));
  if (cells.length) {
    const errors = el('div');
    const inputs = cells.map((m) => [m.key, el('input', { class: 'num', inputmode: 'decimal', 'data-field': `decisions.${m.key}`, style: 'width:150px' })]);
    const form = el('form', {}, el('h2', {}, ui('cells_title')), el('p', { class: 'note' }, ui('cells_hint')),
      el('table', {}, inputs.map(([k, i], n) => el('tr', {}, el('td', {}, cellLabel(s, k) ?? ui('unknown')),
        el('td', { class: 'note' }, ui(`missing_${cells[n].reason}`)), el('td', {}, i)))),
      errors, el('button', { class: 'btn', type: 'submit' }, ui('save_cells')));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const decisions = [];
      for (const [k, i] of inputs) {
        const v = numberOf(i);
        if (v === null) continue;
        if (Number.isNaN(v)) { showErrors(form, { message: ui('numbers_invalid'), errors: {} }, errors); return; }
        decisions.push({ key: k, action: 'edit', amount: v });
      }
      if (!decisions.length) { errors.replaceChildren(box('warn', ui('nothing_changed'))); return; }
      decide(decisions);
    });
    wrap.append(el('div', { class: 'panel' }, form));
  }

  // D. Accumulated depreciation by category: the user's split, made in step 3.
  if (s.missing.some((m) => m.reason === 'category_split_required')) {
    wrap.append(box('warn', ui('category_split_hint'), ' ', el('a', { href: '#accounts' }, ui('go_accounts'))));
  }

  // E. The movement tables as they stand.
  const tables = el('div', { class: 'panel' }, el('h2', {}, ui('schedules_title')));
  for (const sch of s.schedules) {
    const cols = sch.columns;
    tables.append(el('details', {}, el('summary', {}, sch.title_ar),
      el('div', { style: 'overflow-x:auto' }, el('table', {},
        el('tr', {}, el('th'), cols.map((c) => el('th', {}, c.label_ar))),
        sch.rows.map((r) => el('tr', {}, el('td', {}, r.label_ar), cols.map((c) => {
          const f = r.cells[c.key];
          if (!f) return el('td');
          return el('td', { class: 'num' }, money(f.amount),
            f.status === 'pending_confirmation' ? el('span', { class: 'badge b-warn' }, ui('suggestion_pending')) : null,
            f.status === 'missing' ? el('span', { class: 'badge b-bad' }, ui('missing_badge')) : null);
        })))))));
  }
  wrap.append(tables);

  wrap.append(el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', onclick: () => ctx.go('opening') }, ui('prev_step')),
    el('button', { class: 'btn', onclick: () => ctx.go('zakat') }, ui('next_step'))));
  return wrap;
}
