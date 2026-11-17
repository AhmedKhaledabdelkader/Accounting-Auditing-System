// The opening balances' reconciliation: this year's opening balances in the trial balance against last year's approved
// closing balances (the statements the file of last year's issued statements gives, with the independent auditor's
// report). Between the trial balance (step 3) and the statements: what differs, how the differences relate to each other
// (the accounting equation, retained earnings, cash), and what they do to the statements. The setting (start from the
// approved balances or keep the trial balance's) is the accountant's, saved here as in the prior year's step.
import { el, ui, money, box } from '../core.js';

const PARTS = { assets: () => ui('approved_opening_part_assets'), liabilities: () => ui('approved_opening_part_liabilities'), equity: () => ui('approved_opening_part_equity') };
const KINDS = { equal: ['b-ok', 'opening_check_equal_badge'], rounding: ['b-warn', 'opening_check_rounding'], difference: ['b-bad', 'opening_check_difference_badge'] };

const badge = (kind) => (KINDS[kind] ? el('span', { class: `badge ${KINDS[kind][0]}` }, ui(KINDS[kind][1])) : null);

/** The relations: each with its meaning in words and whether it holds; a nil difference is said so. */
function relations(a) {
  const list = (a.relations ?? []).filter((r) => r.difference !== null);
  if (!list.length) return null;
  return el('div', { 'data-opening-relations': '' }, el('h3', {}, ui('opening_relations_title')), el('p', { class: 'note' }, ui('opening_relations_hint')),
    el('table', {}, list.map((r) => el('tr', { 'data-opening-relation': r.code },
      el('td', {}, ui(`opening_rel_${r.code}`)),
      el('td', { class: 'num' }, r.ok && Math.abs(r.difference) < 0.005 ? '-' : money(r.difference)),
      el('td', {}, el('span', { class: `badge ${r.ok ? 'b-ok' : 'b-bad'}` }, ui(r.ok ? 'opening_rel_ok' : 'opening_rel_off')),
        r.ok ? null : el('div', { class: 'note' }, ui(`opening_rel_${r.code}_why`)))))));
}

/** What the differences do to the statements: the lines and totals as presented against the trial balance alone, and the statements reached. */
function impact(a) {
  const effects = (a.impact ?? []).filter((i) => Math.abs(i.effect) >= 0.5);
  return el('div', { 'data-opening-impact': '' }, el('h3', {}, ui('opening_impact_title')),
    a.applied ? null : box('warn', ui(a.enabled ? 'opening_impact_none' : 'opening_impact_off')),
    a.applied && effects.length ? el('table', {}, el('tr', {}, el('th', {}, ui('file_line')), el('th', { class: 'num' }, ui('approved_opening_tb_closing')),
      el('th', { class: 'num' }, ui('approved_opening_presented')), el('th', { class: 'num' }, ui('opening_effect'))),
    effects.map((i) => el('tr', { 'data-opening-impact-line': i.line }, el('td', {}, i.label), el('td', { class: 'num' }, money(i.tb_closing)),
      el('td', { class: 'num' }, money(i.presented)), el('td', { class: 'num' }, money(i.effect))))) : null,
    (a.affects ?? []).length ? el('p', {}, ui('opening_affects'), ' ', (a.affects ?? []).map((k) => el('span', { class: 'badge b-info', style: 'margin-inline-end:6px', 'data-opening-affects': k }, ui(`opening_affects_${k}`)))) : null);
}

export async function render(ctx) {
  const s = ctx.state;
  const a = s.comparative.approved_opening;
  const wrap = el('div', { 'data-opening-step': '' });
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('opening_step_title')), el('p', { class: 'note' }, ui('opening_step_intro'))));
  const nav = el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', onclick: () => ctx.go('accounts') }, ui('prev_step')),
    el('button', { class: 'btn', onclick: () => ctx.go('movements') }, ui('next_step')));

  // Nothing to reconcile with: no approved statements in use (a first year, or none given yet).
  if (!a || !a.status) {
    const first = s.comparative.source === 'first_year' || s.comparative.automatic;
    wrap.append(el('div', { class: 'panel', 'data-opening-none': '' }, box(first ? 'ok' : 'warn', ui(first ? 'opening_step_first_year' : 'opening_step_no_prior')),
      el('button', { class: 'btn ghost', type: 'button', onclick: () => ctx.go('prior_year') }, ui('opening_step_go_prior'))), nav);
    return wrap;
  }

  const status = el('div');
  const toggle = el('input', { type: 'checkbox', 'data-approved-opening': '', checked: a.enabled, onchange: async () => {
    try {
      await ctx.save('PUT', '/prior-year/approved-opening', { enabled: toggle.checked });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
  } });
  const message = { applied: ['warn', ui('approved_opening_applied')], equal: ['ok', ui('approved_opening_equal')],
    no_opening: ['warn', ui('approved_opening_no_opening')], off: ['warn', ui('approved_opening_off')] }[a.status];
  wrap.append(el('div', { class: 'panel', 'data-opening-decision': a.status }, el('h3', {}, ui('opening_decision_title')),
    message ? box(message[0], message[1]) : null,
    el('label', { style: 'display:block;margin:8px 0' }, toggle, ' ', ui('approved_opening_toggle')),
    el('p', { class: 'note' }, ui('opening_decision_hint')), status));
  if (a.status === 'no_opening') {
    wrap.append(nav);
    return wrap;
  }

  // The balance sheet's three parts, the opening against the approved closing.
  const totals = a.totals ?? [];
  wrap.append(el('div', { class: 'panel', 'data-opening-totals': '' }, el('h3', {}, ui('approved_opening_totals')),
    el('table', {}, el('tr', {}, el('th'), el('th', { class: 'num' }, ui('approved_opening_tb_opening')), el('th', { class: 'num' }, ui('approved_opening_approved')),
      el('th', { class: 'num' }, ui('approved_opening_difference')), el('th')),
    totals.map((t) => el('tr', { 'data-opening-total': t.part }, el('td', {}, PARTS[t.part]()), el('td', { class: 'num' }, money(t.tb_opening)),
      el('td', { class: 'num' }, money(t.approved)), el('td', { class: 'num' }, money(t.difference)),
      el('td', {}, badge(Math.abs(t.difference) < 0.5 ? 'equal' : Math.abs(t.difference) <= 1 ? 'rounding' : 'difference'))))),
    relations(a)));

  // Each line: show only what differs, or every line.
  const lines = a.lines ?? [];
  const differ = lines.filter((l) => l.kind && l.kind !== 'equal');
  const all = el('input', { type: 'checkbox', 'data-opening-show-all': '' });
  const table = el('div');
  const draw = () => {
    const shown = all.checked ? lines : differ;
    table.replaceChildren(shown.length ? el('div', { style: 'overflow-x:auto' }, el('table', { 'data-opening-lines': '' },
      el('tr', {}, el('th', {}, ui('file_line')), el('th', { class: 'num' }, ui('approved_opening_tb_opening')), el('th', { class: 'num' }, ui('approved_opening_approved')),
        el('th', { class: 'num' }, ui('approved_opening_difference')), el('th', { class: 'num' }, ui('approved_opening_tb_closing')),
        el('th', { class: 'num' }, ui('approved_opening_presented')), el('th', {})),
      shown.map((l) => el('tr', { 'data-opening-line': l.line },
        el('td', {}, l.label,
          l.remainder ? el('div', { class: 'note' }, ui('approved_opening_remainder', { amount: money(l.remainder.amount), item: l.remainder.item })) : null,
          (l.moved ?? []).map((m) => el('div', { class: 'note' }, ui('opening_moved', { name: m.name, amount: money(m.amount) }))),
          (l.reclassified ?? []).map((m) => el('div', { class: 'note' }, ui('opening_reclassified', { name: m.name, amount: money(m.opening) })))),
        el('td', { class: 'num' }, money(l.tb_opening)), el('td', { class: 'num' }, money(l.approved)),
        el('td', { class: 'num' }, l.difference === null ? ui('missing_figure') : money(l.difference)),
        el('td', { class: 'num' }, money(l.tb_closing)), el('td', { class: 'num' }, money(l.presented)), el('td', {}, badge(l.kind))))))
      : box('ok', ui('opening_lines_all_equal')));
  };
  all.addEventListener('change', draw);
  draw();
  wrap.append(el('div', { class: 'panel', 'data-opening-lines-panel': '' }, el('h3', {}, ui('approved_opening_lines')),
    el('label', { style: 'display:block;margin:6px 0' }, all, ' ', ui('opening_show_all', { count: lines.length })), table));

  const ppe = (a.ppe ?? []).filter((p) => p.kind !== 'equal');
  if (ppe.length) {
    wrap.append(el('div', { class: 'panel', 'data-opening-ppe': '' }, el('h3', {}, ui('approved_opening_ppe')),
      el('table', {}, ppe.map((p) => el('tr', {}, el('td', {}, `${p.label} — ${ui(p.side === 'cost' ? 'ppe_side_cost' : 'ppe_side_accumulated')}`),
        el('td', { class: 'num' }, money(p.tb_opening)), el('td', { class: 'num' }, money(p.approved)), el('td', { class: 'num' }, money(p.difference)), el('td', {}, badge(p.kind)))))));
  }
  wrap.append(el('div', { class: 'panel' }, impact(a)), nav);
  return wrap;
}
