// The zakat and income tax dialog: the year's zakat base and zakat, and the income tax of the foreign owners' share, from
// the statements' figures (state.zakat_tax: ZakatCalculator, the accountants' sheet under the Zakat Collection
// Regulation of 1445). The statements give the figures; what they can't give (the owners' nationality share, the profit's
// adjustments, the assets deducted, the allocation) is answered here and saved. The result can be charged in the
// statements (Dr zakat / income tax expense, Cr its provision) where nothing else charges it.
import { el, ui, exact, money, box, field, numberOf, showErrors, add } from '../core.js';

const INPUT_AMOUNTS = ['profit_additions', 'profit_deductions', 'loss_carryforward', 'deducted_current_assets', 'noncurrent_as_current', 'extra_additions', 'extra_deductions'];

/** A table of a group's lines with their total; with `edit` (the zakat step), each amount is the accountant's to correct. */
function group(title, rows, total, attrs = {}, edit = null) {
  return el('div', { class: 'panel', ...attrs }, el('h3', {}, title),
    rows.length ? el('table', {}, rows.map((r) => el('tr', { 'data-zakat-line': r.line, class: r.edited ? 'edited' : null },
      el('td', {}, r.label, r.before_charge ? el('div', { class: 'note' }, ui('zakat_before_charge', { reported: money(r.reported) })) : null,
        r.related ? el('div', { class: 'note' }, ui('zakat_related_note')) : null,
        r.edited ? el('div', { class: 'note' }, el('span', { class: 'badge b-warn' }, ui('zakat_edited')), ' ', ui('zakat_computed_was', { amount: exact(r.computed) })) : null),
      el('td', { class: 'num' }, edit ? edit.input(r.line, r.amount, r.computed) : money(r.amount)))),
    el('tr', { class: 'total' }, el('td', {}, el('b', {}, ui('zakat_total'))), el('td', { class: 'num' }, el('b', {}, money(total))))) : el('p', { class: 'note' }, ui('zakat_none_here')));
}

/** One step of the sheet: its wording, the regulation's article and the amount (shown negative where it is taken off). */
const step = (key, amount, strong = false) => el('tr', { 'data-zakat-step': key, class: strong ? 'total' : null },
  el('td', {}, strong ? el('b', {}, ui(`zakat_step_${key}`)) : ui(`zakat_step_${key}`), el('div', { class: 'note' }, ui(`zakat_art_${key}`))),
  el('td', { class: 'num' }, strong ? el('b', {}, exact(amount)) : exact(amount)));

/** The charge into the statements, one row each for the zakat and the income tax. */
function charges(ctx, z) {
  const status = el('div');
  const send = async (apply) => {
    try {
      await ctx.save('PUT', '/zakat-tax', { apply });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
  };
  const row = (kind, calculated, booked, source, applied) => {
    const locked = source && source !== 'dialog';
    const stale = source === 'dialog' && applied !== null && Math.abs(applied - calculated) >= 0.005;
    return el('tr', { 'data-zakat-charge': kind },
      el('td', {}, ui(`zakat_charge_${kind}`)), el('td', { class: 'num' }, exact(calculated)), el('td', { class: 'num' }, source ? exact(booked) : '-'),
      el('td', {}, source ? el('span', { class: `badge ${locked ? 'b-warn' : 'b-ok'}` }, ui(`zakat_source_${source}`)) : el('span', { class: 'badge b-info' }, ui('zakat_source_none')),
        locked && Math.abs(booked - calculated) >= 0.005 ? el('div', { class: 'note' }, ui('zakat_booked_differs', { booked: exact(booked), calculated: exact(calculated), difference: exact(booked - calculated) })) : null,
        stale ? el('div', { class: 'note' }, ui('zakat_stale', { applied: exact(applied), calculated: exact(calculated) })) : null),
      el('td', {}, z.can_apply[kind] && (!source || stale) ? el('button', { class: 'btn small', type: 'button', [`data-zakat-apply-${kind}`]: '', onclick: () => send({ [kind]: true }) }, ui(stale ? 'zakat_update' : 'zakat_apply')) : null,
        source === 'dialog' ? el('button', { class: 'btn ghost small', type: 'button', [`data-zakat-remove-${kind}`]: '', style: 'margin-inline-start:6px', onclick: () => send({ [kind]: false }) }, ui('zakat_remove')) : null));
  };
  return el('div', { class: 'panel', 'data-zakat-charges': '' }, el('h3', {}, ui('zakat_charges_title')), el('p', { class: 'note' }, ui('zakat_charges_hint')),
    el('table', {}, el('tr', {}, el('th'), el('th', { class: 'num' }, ui('zakat_calculated')), el('th', { class: 'num' }, ui('zakat_in_statements')), el('th')),
      row('zakat', z.zakat.amount, z.charged.zakat, z.charged.zakat_source, z.applied.zakat),
      row('income_tax', z.income_tax.amount, z.charged.income_tax, z.charged.tax_source, z.applied.income_tax)), status);
}

/**
 * The calculation as the sheet has it, read-only: what the statements give group by group, the base, the minimum and the
 * maximum, the zakat, and the foreign owners' income tax. The zakat step shows it with its answers and charges; the review
 * step shows it under the statements, to be read apart from them.
 */
export function calculation(z, edit = null) {
  const out = [];
  // What the statements give, group by group, as the sheet has them.
  out.push(group(ui('zakat_equity_title'), z.equity.rows, z.equity.total, { 'data-zakat-group': 'equity' }, edit),
    group(ui('zakat_ncl_title'), z.non_current_liabilities.rows, z.non_current_liabilities.total, { 'data-zakat-group': 'non_current_liabilities' }, edit),
    group(ui('zakat_cl_title'), z.current_liabilities.rows, z.current_liabilities.total, { 'data-zakat-group': 'current_liabilities' }, edit),
    group(ui('zakat_fa_title'), z.non_current_assets.rows.filter((r) => r.deducted), z.non_current_assets.deducted, { 'data-zakat-group': 'deducted_assets' }, edit),
    group(ui('zakat_ca_title'), z.current_assets.rows, z.current_assets.total, { 'data-zakat-group': 'current_assets' }, edit));

  // The base, the minimum, the maximum and the zakat.
  const b = z.base;
  out.push(el('div', { class: 'panel', 'data-zakat-calc': z.binding }, el('h3', {}, ui('zakat_calc_title')),
    z.allocation.current_liabilities_added || z.allocation.non_current_liabilities_removed
      ? el('p', { class: 'note', 'data-zakat-allocation': '' }, ui('zakat_allocation', { added: exact(z.allocation.current_liabilities_added), removed: exact(z.allocation.non_current_liabilities_removed) })) : null,
    el('table', {},
      step('equity', b.equity), step('non_current_liabilities', b.non_current_liabilities), step('profit_difference', b.profit_difference),
      step('current_excess', b.current_excess), step('extra_additions', b.extra_additions), step('deducted_assets', b.deducted_assets),
      step('extra_deductions', b.extra_deductions), step('base', b.total, true),
      step('minimum', z.minimum.total), step('maximum', z.maximum.total), step('final_base', z.final_base, true)),
    el('p', {}, el('span', { class: `badge ${z.binding === 'none' ? 'b-warn' : 'b-info'}`, 'data-zakat-binding': z.binding }, ui(`zakat_binding_${z.binding}`)), ' ', ui(`zakat_binding_${z.binding}_why`)),
    el('table', {},
      el('tr', { 'data-zakat-rate': '' }, el('td', {}, ui('zakat_rate_line', { days: z.period.days, hijri: z.period.hijri_days }), el('div', { class: 'note' }, ui('zakat_art_rate'))),
        el('td', { class: 'num' }, `${(z.zakat.rate * 100).toFixed(4)}%`)),
      el('tr', {}, el('td', {}, ui('zakat_full')), el('td', { class: 'num' }, exact(z.zakat.full))),
      el('tr', { class: 'total', 'data-zakat-amount': '' }, el('td', {}, el('b', {}, ui('zakat_amount', { share: z.zakat.saudi_share }))), el('td', { class: 'num' }, el('b', {}, exact(z.zakat.amount)))))));

  // The income tax of the foreign owners' share.
  const t = z.income_tax;
  out.push(el('div', { class: 'panel', 'data-zakat-tax': '' }, el('h3', {}, ui('zakat_tax_title')), el('p', { class: 'note' }, ui('zakat_tax_hint')),
    el('table', {},
      el('tr', {}, el('td', {}, ui('zakat_tax_adjusted_profit')), el('td', { class: 'num' }, exact(z.profit.adjusted))),
      el('tr', {}, el('td', {}, ui('zakat_tax_foreign_profit', { share: t.foreign_share })), el('td', { class: 'num' }, exact(t.profit))),
      el('tr', {}, el('td', {}, ui('zakat_tax_loss_offset'), el('div', { class: 'note' }, ui('zakat_art_loss'))), el('td', { class: 'num' }, exact(-t.loss_offset))),
      el('tr', {}, el('td', {}, ui('zakat_tax_taxable')), el('td', { class: 'num' }, exact(t.taxable))),
      el('tr', { class: 'total', 'data-zakat-tax-amount': '' }, el('td', {}, el('b', {}, ui('zakat_tax_amount', { rate: t.rate * 100 }))), el('td', { class: 'num' }, el('b', {}, exact(t.amount)))))));
  if (z.entry) out.push(entryPanel(z.entry));
  return out;
}

/**
 * The entry after the zakat (القيد بعد الزكاة): the year's charge as a journal entry on the trial balance's accounts, what the
 * books already carry and the adjusting entry the statements post; then the result before and after it and the provision.
 */
export function entryPanel(e) {
  const acc = (a) => [a.number ? `${a.number} — ` : '', a.name, a.row === null ? '' : ` (${a.line_label})`].join('');
  const journal = (debit, credit, amount, attrs) => el('table', { class: 'grid-table', ...attrs },
    el('tr', {}, el('th', {}, ui('zakat_entry_account')), el('th', { class: 'num' }, ui('zakat_entry_debit')), el('th', { class: 'num' }, ui('zakat_entry_credit'))),
    el('tr', {}, el('td', {}, ui('zakat_entry_from'), ' ', acc(debit)), el('td', { class: 'num' }, exact(amount)), el('td')),
    el('tr', {}, el('td', { style: 'padding-inline-start:28px' }, ui('zakat_entry_to'), ' ', acc(credit)), el('td'), el('td', { class: 'num' }, exact(amount))));
  const body = [];
  for (const l of e.lines) {
    body.push(el('h4', {}, l.kind === 'zakat' ? ui('zakat_charge_zakat') : ui('zakat_charge_income_tax')),
      el('p', { class: 'note' }, ui('zakat_entry_full_hint')), journal(l.full.debit, l.full.credit, l.full.amount, { 'data-zakat-entry-full': l.kind }),
      el('p', {}, ui('zakat_entry_booked', { booked: exact(l.booked), carried: exact(l.carried) })),
      Math.abs(l.adjustment) < 0.005 ? box('ok', ui('zakat_entry_none'))
        : [el('p', { class: 'note' }, ui(l.adjustment > 0 ? 'zakat_entry_adjust_hint' : 'zakat_entry_reverse_hint')), journal(l.debit, l.credit, l.amount, { 'data-zakat-entry-adjust': l.kind })]);
  }
  if (!e.lines.length) body.push(el('p', { class: 'note' }, ui('zakat_entry_nothing')));
  const p = e.profit;
  body.push(el('h4', {}, ui('zakat_entry_effect')), el('table', { 'data-zakat-entry-effect': '' },
    el('tr', {}, el('td', {}, ui('zakat_entry_profit_before')), el('td', { class: 'num' }, exact(p.before))),
    el('tr', {}, el('td', {}, ui('zakat_charge_zakat')), el('td', { class: 'num' }, exact(p.zakat))),
    p.income_tax ? el('tr', {}, el('td', {}, ui('zakat_charge_income_tax')), el('td', { class: 'num' }, exact(p.income_tax))) : null,
    el('tr', { class: 'total' }, el('td', {}, el('b', {}, ui('zakat_entry_profit_after'))), el('td', { class: 'num' }, el('b', {}, exact(p.after))))));
  if (e.provision) {
    const v = e.provision;
    body.push(el('h4', {}, ui('zakat_entry_provision')), el('table', { 'data-zakat-entry-provision': '' },
      el('tr', {}, el('td', {}, ui('zakat_entry_prov_opening')), el('td', { class: 'num' }, exact(v.opening))),
      el('tr', {}, el('td', {}, ui('zakat_entry_prov_charge')), el('td', { class: 'num' }, exact(v.charge))),
      v.prior_year_adjustments ? el('tr', {}, el('td', {}, ui('zakat_entry_prov_adjust')), el('td', { class: 'num' }, exact(v.prior_year_adjustments))) : null,
      el('tr', {}, el('td', {}, ui('zakat_entry_prov_paid')), el('td', { class: 'num' }, exact(v.paid === null ? null : -v.paid))),
      el('tr', { class: 'total' }, el('td', {}, el('b', {}, ui('zakat_entry_prov_closing'))), el('td', { class: 'num' }, el('b', {}, exact(v.closing))))));
  }
  return el('div', { class: 'panel', 'data-zakat-entry': '' }, el('h3', {}, ui('zakat_entry_title')), el('p', { class: 'note' }, ui('zakat_entry_hint')), ...body);
}

export async function render(ctx) {
  const z = ctx.state.zakat_tax;
  const wrap = el('div', { 'data-zakat-step': '' });
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('zakat_step_title')), el('p', { class: 'note' }, ui('zakat_step_intro'))));
  const nav = el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', onclick: () => ctx.go('movements') }, ui('prev_step')),
    el('button', { class: 'btn', onclick: () => ctx.go('notes') }, ui('next_step')));
  for (const w of z.warnings ?? []) wrap.append(box('warn', ui(`zakat_warning_${w}`)));

  // The answers.
  const form = el('form', { class: 'panel', 'data-zakat-form': '' });
  const errors = el('div');
  const status = el('div');
  const inputs = {};
  const number = (key, value) => {
    const input = el('input', { class: 'num', inputmode: 'decimal', 'data-field': `inputs.${key}`, value: value === 0 ? '' : String(value), placeholder: '0' });
    inputs[key] = input;
    return field(ui(`zakat_in_${key}`), input, ui(`zakat_in_${key}_hint`));
  };
  const related = el('input', { type: 'checkbox', checked: z.inputs.related_as_liability, 'data-zakat-related': '' });
  const deduct = z.non_current_assets.rows.map((r) => ({ r, box: el('input', { type: 'checkbox', checked: r.deducted, 'data-zakat-deduct': r.line }) }));
  add(form, el('h3', {}, ui('zakat_inputs_title')),
    field(ui('zakat_in_saudi_share'), (inputs.saudi_share = el('input', { class: 'num', inputmode: 'decimal', 'data-field': 'inputs.saudi_share', value: String(z.inputs.saudi_share) })), ui('zakat_in_saudi_share_hint')),
    z.saudi_share_from === 'articles' ? box('info', ui('zakat_share_from_articles')) : z.saudi_share_from === 'sole_proprietorship' ? box('info', ui('zakat_share_from_sole_proprietorship')) : null,
    deduct.length ? el('div', { class: 'field' }, el('label', {}, ui('zakat_in_deduct_lines')),
      deduct.map((d) => el('label', { style: 'display:block;margin:4px 0' }, d.box, ' ', d.r.label, ' — ', money(d.r.amount))), el('div', { class: 'note' }, ui('zakat_in_deduct_lines_hint'))) : null,
    INPUT_AMOUNTS.map((k) => number(k, z.inputs[k])),
    el('label', { style: 'display:block;margin:8px 0' }, related, ' ', ui('zakat_in_related_as_liability')), el('div', { class: 'note' }, ui('zakat_in_related_as_liability_hint')),
    errors, status, el('button', { class: 'btn', type: 'submit' }, ui('zakat_calculate')));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    status.replaceChildren();
    const body = {};
    for (const key of ['saudi_share', ...INPUT_AMOUNTS]) {
      const v = numberOf(inputs[key]);
      if (Number.isNaN(v)) { showErrors(form, { message: ui('numbers_invalid'), errors: {} }, errors); inputs[key].classList.add('invalid'); return; }
      body[key] = v === null || (key !== 'saudi_share' && v === 0) ? null : v;
    }
    body.related_as_liability = related.checked || null;
    body.deduct_lines = deduct.filter((d) => d.box.checked).map((d) => d.r.line);
    if (JSON.stringify(body.deduct_lines) === JSON.stringify(z.defaults.deduct_lines)) body.deduct_lines = null;
    if (body.saudi_share === z.defaults.saudi_share) body.saudi_share = null;
    if (['articles', 'sole_proprietorship'].includes(z.saudi_share_from) && body.saudi_share === z.inputs.saudi_share) body.saudi_share = null;   // the owners' share, not an answer of the accountant's
    try {
      await ctx.save('PUT', '/zakat-tax', { inputs: body });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { showErrors(form, err, errors); }
  });
  wrap.append(form);

  wrap.append(adoption(ctx, z));
  const edit = editor(ctx, z);
  wrap.append(edit.panel, ...calculation(z, edit), edit.bar);
  wrap.append(charges(ctx, z), nav);
  return wrap;
}

/**
 * Which amount the statements carry, for the zakat and for the income tax: the system's calculation, what the trial balance books,
 * or the accountant's own. The statements carry it (the difference from the books posted Dr expense, Cr provision); «auto» keeps
 * the rule (the calculation where nothing books it).
 */
function adoption(ctx, z) {
  const status = el('div');
  const card = (charge) => {
    const c = z.candidates[charge];
    const chosen = z.adopt[charge];
    const name = `adopt-${charge}`;
    const manual = el('input', { class: 'num', inputmode: 'decimal', 'data-zakat-manual': charge, value: c.manual === null ? '' : String(c.manual), placeholder: '0' });
    const option = (value, label, amount, extra = null) => el('label', { class: `zakat-option${chosen === value ? ' on' : ''}`, 'data-zakat-option': `${charge}.${value}` },
      el('input', { type: 'radio', name, value, checked: chosen === value }), el('span', { class: 'opt-label' }, label),
      amount !== null ? el('b', { class: 'num' }, exact(amount)) : null, extra);
    const pick = () => (wrap.querySelector(`input[name=${name}]:checked`) || {}).value || null;
    const wrap = el('div', { class: 'zakat-adopt', 'data-zakat-adopt': charge },
      el('h4', {}, ui(`zakat_charge_${charge}`), ' ', el('span', { class: 'note' }, ui('zakat_adopt_now', { amount: exact(z.in_statements[charge]) }))),
      el('div', { class: 'zakat-options' },
        option('calculated', ui('zakat_adopt_calculated'), c.calculated),
        option('booked', ui('zakat_adopt_booked'), c.booked),
        option('manual', ui('zakat_adopt_manual'), null, manual)),
      el('div', { class: 'toolbar' },
        el('button', { class: 'btn small', type: 'button', [`data-zakat-adopt-save`]: charge, onclick: async () => {
          const choice = pick();
          const amount = numberOf(manual);
          if (choice === 'manual' && (amount === null || Number.isNaN(amount) || amount < 0)) { manual.classList.add('invalid'); status.replaceChildren(box('bad', ui('zakat_manual_required'))); return; }
          await send({ adopt: { [charge]: choice }, manual: { [charge]: choice === 'manual' ? amount : (c.manual ?? null) } });
        } }, ui('zakat_adopt_save')),
        chosen !== 'auto' ? el('button', { class: 'btn ghost small', type: 'button', [`data-zakat-adopt-reset`]: charge, onclick: () => send({ adopt: { [charge]: null } }) }, ui('zakat_adopt_reset')) : null,
        el('span', { class: 'note' }, ui(chosen === 'auto' ? 'zakat_adopt_auto' : `zakat_adopt_is_${chosen}`))));
    wrap.addEventListener('change', (e) => { if (e.target.name === name) wrap.querySelectorAll('.zakat-option').forEach((o) => o.classList.toggle('on', o.querySelector('input').checked)); });
    manual.addEventListener('focus', () => { wrap.querySelector(`input[value=manual]`).checked = true; wrap.dispatchEvent(new Event('change')); });
    return wrap;
  };
  const send = async (body) => {
    try {
      await ctx.save('PUT', '/zakat-tax', body);
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
  };
  return el('div', { class: 'panel', 'data-zakat-adoption': '' }, el('h3', {}, ui('zakat_adopt_title')), el('p', { class: 'note' }, ui('zakat_adopt_hint')),
    card('zakat'), card('income_tax'), status);
}

/**
 * The calculation's lines as the accountant's to correct: each amount an input (the statements' figure kept and shown when it
 * differs), the profit before zakat too; one save sends every correction, a line set back to its figure drops its correction.
 */
function editor(ctx, z) {
  const inputs = {};
  const status = el('div');
  const input = (line, amount, computed) => {
    const i = el('input', { class: 'num', inputmode: 'decimal', 'data-zakat-edit': line, value: String(amount), style: 'width:140px' });
    inputs[line] = { input: i, computed };
    return i;
  };
  const profit = el('input', { class: 'num', inputmode: 'decimal', 'data-zakat-edit': 'is.profit_before_zakat', value: z.profit.before_charges === null ? '' : String(z.profit.before_charges), style: 'width:160px' });
  inputs['is.profit_before_zakat'] = { input: profit, computed: z.profit.before_charges_computed };
  const save = el('button', { class: 'btn', type: 'button', 'data-zakat-edit-save': '', onclick: async () => {
    const overrides = {};
    for (const [line, { input: i, computed }] of Object.entries(inputs)) {
      const v = numberOf(i);
      if (Number.isNaN(v)) { i.classList.add('invalid'); status.replaceChildren(box('bad', ui('numbers_invalid'))); return; }
      if (v !== null && (computed === null || Math.abs(v - computed) >= 0.005)) overrides[line] = v;
    }
    try {
      await ctx.save('PUT', '/zakat-tax', { inputs: { overrides } });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
  } }, ui('zakat_edit_save'));
  const edited = Object.keys(z.inputs.overrides ?? {}).length;
  const reset = edited ? el('button', { class: 'btn ghost', type: 'button', 'data-zakat-edit-reset': '', onclick: async () => {
    await ctx.save('PUT', '/zakat-tax', { inputs: { overrides: null } });
    ctx.flash = ui('saved');
    ctx.redraw();
  } }, ui('zakat_edit_reset')) : null;
  const panel = el('div', { class: 'panel', 'data-zakat-editor': '' }, el('h3', {}, ui('zakat_edit_title')), el('p', { class: 'note' }, ui('zakat_edit_hint')),
    edited ? box('warn', ui('zakat_edit_count', { count: edited })) : null,
    field(ui('zakat_profit_before'), profit, z.profit.before_charges_edited ? ui('zakat_computed_was', { amount: exact(z.profit.before_charges_computed) }) : ui('zakat_profit_before_hint')));
  const bar = el('div', { class: 'toolbar', 'data-zakat-edit-bar': '' }, save, reset, status);
  return { input, panel, bar, save };
}
