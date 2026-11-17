// The zakat and income tax dialog: the year's zakat base and zakat, and the income tax of the foreign owners' share, from
// the statements' figures (state.zakat_tax: ZakatCalculator, the accountants' sheet under the Zakat Collection
// Regulation of 1445). The statements give the figures; what they can't give (the owners' nationality share, the profit's
// adjustments, the assets deducted, the allocation) is answered here and saved. The result can be charged in the
// statements (Dr zakat / income tax expense, Cr its provision) where nothing else charges it.
import { el, ui, exact, money, box, field, numberOf, showErrors, add } from '../core.js';

const INPUT_AMOUNTS = ['profit_additions', 'profit_deductions', 'loss_carryforward', 'deducted_current_assets', 'noncurrent_as_current', 'extra_additions', 'extra_deductions'];

/** A table of a group's lines with their total. */
function group(title, rows, total, attrs = {}) {
  return el('div', { class: 'panel', ...attrs }, el('h3', {}, title),
    rows.length ? el('table', {}, rows.map((r) => el('tr', { 'data-zakat-line': r.line },
      el('td', {}, r.label, r.before_charge ? el('div', { class: 'note' }, ui('zakat_before_charge', { reported: money(r.reported) })) : null,
        r.related ? el('div', { class: 'note' }, ui('zakat_related_note')) : null),
      el('td', { class: 'num' }, money(r.amount)))),
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
export function calculation(z) {
  const out = [];
  // What the statements give, group by group, as the sheet has them.
  out.push(group(ui('zakat_equity_title'), z.equity.rows, z.equity.total, { 'data-zakat-group': 'equity' }),
    group(ui('zakat_ncl_title'), z.non_current_liabilities.rows, z.non_current_liabilities.total, { 'data-zakat-group': 'non_current_liabilities' }),
    group(ui('zakat_cl_title'), z.current_liabilities.rows, z.current_liabilities.total, { 'data-zakat-group': 'current_liabilities' }),
    group(ui('zakat_fa_title'), z.non_current_assets.rows.filter((r) => r.deducted), z.non_current_assets.deducted, { 'data-zakat-group': 'deducted_assets' }),
    group(ui('zakat_ca_title'), z.current_assets.rows, z.current_assets.total, { 'data-zakat-group': 'current_assets' }));

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
  return out;
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

  wrap.append(...calculation(z));
  wrap.append(charges(ctx, z), nav);
  return wrap;
}
