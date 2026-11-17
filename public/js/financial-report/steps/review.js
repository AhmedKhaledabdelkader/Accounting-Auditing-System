// Step 6 (PR 13c): the report as it will print, and what stands between it and a final version: the errors (each fixed in
// its own step), the warnings to acknowledge, the prior-year cells typed by hand and left empty (counted as 0), what the user
// did to the figures (every adjusting entry, Q39, and every account moved), then a draft or a final version.
import { el, ui, name, box, exact, accountName, accountList, runRows, checkTitle, checkMessage, blockLabel } from '../core.js';
import { lineLabel, cellLabel, categoryLabel, stepForCheck } from '../describe.js';
import { statementsView } from '../statements-view.js';
import { versionButtons } from './versions.js';
import { calculation } from './zakat.js';

function checkText(c, byRow) {
  return [checkMessage(c), c.rows?.length ? ` ${ui('accounts_label')} ${accountList(c.rows, byRow)}` : ''].join('');
}

function stepLink(ctx, c) {
  const step = stepForCheck(c);
  return step === 'review' ? null : el('a', { href: `#${step}` }, ui('go_to_step', { step: ui(`step_${step}`) }));
}

function errorsPanel(ctx, errors, byRow) {
  return el('div', { class: 'panel' }, el('h2', {}, ui('errors_title'), ` (${errors.length})`), el('p', { class: 'note' }, ui('errors_hint')),
    el('table', {}, errors.map((c) => el('tr', { 'data-check': c.key },
      el('td', {}, el('b', {}, checkTitle(c)), c.scope === 'comparative' ? ` (${name('scopes.comparative')})` : ''),
      el('td', {}, checkText(c, byRow)), el('td', {}, stepLink(ctx, c))))));
}

/** Warnings: each acknowledged or not, with a note; saving replaces the whole list (PUT …/acknowledgements). */
function warningsPanel(ctx, warnings, byRow, status) {
  const notes = Object.fromEntries((ctx.state.acknowledgements ?? []).map((a) => [a.key, a.note]));
  const rows = warnings.map((c) => {
    // An answer to confirm: saved in its step (the link), never acknowledged here.
    const check = c.code === 'input_to_confirm' ? null : el('input', { type: 'checkbox', checked: c.acknowledged, 'data-ack': c.key });
    const note = check ? el('input', { value: notes[c.key] ?? '', placeholder: ui('ack_note'), maxlength: 500, 'data-ack-note': c.key, style: 'min-width:200px' }) : null;
    return { c, check, note };
  });
  const save = async () => {
    status.replaceChildren();
    const acknowledgements = rows.filter((r) => r.check?.checked).map((r) => ({ key: r.c.key, ...(r.note.value.trim() ? { note: r.note.value.trim() } : {}) }));
    try {
      await ctx.save('PUT', '/acknowledgements', { acknowledgements });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) {
      status.append(box('bad', err.all().join(' ')));
    }
  };
  return el('div', { class: 'panel' }, el('h2', {}, ui('warnings_title'), ` (${warnings.length})`), el('p', { class: 'note' }, ui('warnings_hint')),
    el('table', {}, rows.map(({ c, check, note }) => el('tr', { class: c.acknowledged ? 'acked' : null },
      el('td', {}, check ? el('label', {}, check, ' ', ui('ack')) : el('span', { class: 'badge b-warn', 'data-to-confirm': c.key }, ui('required_confirm'))),
      el('td', {}, el('b', {}, checkTitle(c)), c.scope === 'comparative' ? ` (${name('scopes.comparative')})` : '',
        c.acknowledged ? el('span', { class: 'badge b-ok' }, name('acknowledged')) : null,
        el('div', { class: 'note' }, checkText(c, byRow)), c.code === 'run_changed' ? el('div', { class: 'note' }, ui('run_changed_ack_hint')) : null),
      el('td', {}, note), el('td', {}, stepLink(ctx, c))))),
    el('button', { class: 'btn', type: 'button', onclick: save }, ui('save_acks')));
}

/** The manual prior-year cells left empty, which the statements count as 0 (state.comparative.defaulted). */
function defaultedPanel(state) {
  const d = state.comparative.defaulted;
  if (!d) return null;
  const soce = state.statements.changes_in_equity;
  // A line with a figure this year is marked: its prior year is the likeliest to have been forgotten.
  const current = Object.fromEntries(['financial_position', 'comprehensive_income', 'cash_flows']
    .flatMap((st) => state.statements[st].filter((r) => r.key).map((r) => [r.key, r.current?.amount])));
  const lines = (keys) => keys.map((k) => [lineLabel(state, k) ?? ui('unknown'), Math.round(current[k] ?? 0) !== 0]).sort((x, y) => y[1] - x[1]);
  const plain = (labels) => labels.map((l) => [l, false]);
  const groups = [
    [ui('defaulted_figures'), lines(d.figures)],
    [ui('defaulted_equity'), plain(d.changes_in_equity.map((x) => `${soce.current.find((r) => r.key === x.row)?.label_ar ?? ui('unknown')} — ${soce.columns.find((c) => c.key === x.column)?.label_ar ?? ui('unknown')}`))],
    [ui('defaulted_cash_flows'), lines(d.cash_flows)],
    [ui('defaulted_notes'), plain(Object.entries(d.notes).flatMap(([note, keys]) => (note.endsWith('_build_up')
      ? keys.map((k) => `${name(`notes.${note.slice(0, -'_build_up'.length)}`)} — ${name(`build_up.${k}`)}`)
      : keys.map((k) => `${name(`notes.${note}`)} — ${k}`))))],
    [ui('defaulted_schedules'), plain(d.schedules.map((k) => cellLabel(state, k) ?? ui('unknown')))],
  ].filter(([, items]) => items.length);
  if (!groups.length) return null;
  const count = groups.reduce((n, [, items]) => n + items.length, 0);
  return el('div', { class: 'panel', 'data-defaulted': '' }, el('h2', {}, ui('defaulted_title'), ` (${count})`), el('p', { class: 'note' }, ui('defaulted_hint')),
    groups.map(([title, items]) => el('details', { open: items.length <= 10 || items.some(([, hot]) => hot) }, el('summary', {}, `${title} (${items.length})`),
      el('ul', {}, items.map(([text, hot]) => el('li', {}, text, hot ? el('span', { class: 'badge b-warn' }, ui('defaulted_has_current')) : null))))),
    el('a', { class: 'btn ghost', href: '#prior_year' }, ui('go_to_step', { step: ui('step_prior_year') })));
}

/** A line, or shares of lines: «line» / «line 25%، line 75%». */
function linesText(state, lines) {
  const entries = Object.entries(lines ?? {});
  if (entries.length === 1) return lineLabel(state, entries[0][0]) ?? ui('unknown');
  return entries.map(([k, share]) => `${lineLabel(state, k) ?? ui('unknown')} ${Math.round(share * 10000) / 100}%`).join(ui('list_separator'));
}

/** What changes the figures: the adjusting entries in full (Q39), and each account the user moved or re-categorised. */
function changesPanel(state, byRow) {
  const edit = el('a', { href: '#accounts' }, ui('go_to_step', { step: ui('step_accounts') }));
  const target = (l) => (l.row !== null && l.row !== undefined ? accountName(byRow[l.row]) : lineLabel(state, l.line_key) ?? ui('unknown'));
  const entries = state.adjustments ?? [];
  const entriesTable = entries.length
    ? el('table', { 'data-entries': '' },
      el('tr', {}, el('th', {}, ui('col_entry')), el('th', {}, ui('col_target')), el('th', { class: 'num' }, ui('col_debit')), el('th', { class: 'num' }, ui('col_credit'))),
      entries.map((e) => [
        e.lines.map((l, i) => el('tr', {}, el('td', {}, i === 0 ? ui('entry_n', { n: e.number }) : ''), el('td', {}, target(l)),
          el('td', { class: 'num' }, l.debit > 0 ? exact(l.debit) : ''), el('td', { class: 'num' }, l.credit > 0 ? exact(l.credit) : ''))),
        el('tr', { class: 'entry-reason' }, el('td'), el('td', { colspan: 3, class: 'note' }, ui('entry_reason_is', { reason: e.reason }), ' — ',
          el('a', { href: '#accounts' }, ui('edit')))),
      ]))
    : el('p', { class: 'note' }, ui('no_entries'));

  // Every override: a line or shares (with where the classification puts the account), a note category, tags.
  const moved = Object.fromEntries((state.mapping.overridden ?? []).map((m) => [m.row, m]));
  const excluded = Object.fromEntries((state.mapping.excluded ?? []).map((m) => [m.row, m]));
  const overrides = state.account_overrides ?? [];
  const lineNow = (row) => ['financial_position', 'comprehensive_income'].flatMap((st) => state.statements[st])
    .find((r) => r.type === 'line' && (r.rows ?? []).includes(row))?.label_ar ?? ui('no_line');
  const original = (m) => (m.original.lines ? linesText(state, m.original.lines)
    : (m.original.reason === 'possible_parent' ? ui('original_excluded') : `${ui('no_line')} (${name(`unmapped_reasons.${m.original.reason}`)})`));
  const overridesTable = overrides.length
    ? el('table', { 'data-overrides': '' },
      el('tr', {}, el('th', {}, ui('col_account')), el('th', {}, ui('col_line_by_rule')), el('th', {}, ui('col_line_now')), el('th')),
      overrides.map((o) => {
        const m = moved[o.row] ?? (o.exclude ? excluded[o.row] : null);
        if (o.exclude) {
          return el('tr', { 'data-override-row': o.row },
            el('td', {}, accountName(byRow[o.row])),
            el('td', {}, m?.original ? original(m) : ui('unknown')),
            el('td', {}, el('span', { class: 'badge b-warn' }, ui('excluded_badge')), ' ', ui('excluded_user', { reason: o.reason })),
            el('td', {}, el('a', { href: '#accounts' }, ui('edit'))));
        }
        return el('tr', { 'data-override-row': o.row },
          el('td', {}, accountName(byRow[o.row])),
          el('td', {}, m ? original(m) : lineNow(o.row)),
          el('td', {}, m ? linesText(state, m.lines) : ui('line_unchanged'),
            o.category ? el('span', { class: 'badge b-ok' }, ui('category_badge', { category: categoryLabel(state, o.category) })) : null,
            (o.tags ?? []).map((t) => el('span', { class: 'badge b-info' }, name(`tags.${t}`)))),
          el('td', {}, el('a', { href: '#accounts' }, ui('edit'))));
      }))
    : el('p', { class: 'note' }, ui('no_overrides'));

  return el('div', { class: 'panel', 'data-changes': '' }, el('h2', {}, ui('changes_title')), el('p', { class: 'note' }, ui('changes_hint')),
    el('h3', {}, ui('entries_title'), ` (${entries.length})`), entriesTable,
    el('h3', {}, ui('overrides_title'), ` (${overrides.length})`), overridesTable,
    el('div', { class: 'toolbar' }, edit));
}

export async function render(ctx) {
  const s = ctx.state;
  const byRow = await runRows();
  const status = el('div');
  const wrap = el('div', {}, status);

  // What blocks a final, and the versions' buttons.
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('review_status')),
    s.can_finalize ? box('ok', ui('panel_ready'))
      : el('div', {}, el('p', {}, ui('panel_blocked')), el('ul', {}, s.blocking.map((b) => el('li', {}, `${blockLabel(b)} (${b.count})`)))),
    versionButtons(ctx, status)));

  const errors = s.checks.filter((c) => c.severity === 'error');
  const warnings = s.checks.filter((c) => c.severity === 'warning');
  if (errors.length) wrap.append(errorsPanel(ctx, errors, byRow));
  if (warnings.length) wrap.append(warningsPanel(ctx, warnings, byRow, status));
  wrap.append(defaultedPanel(s) ?? '', changesPanel(s, byRow));

  wrap.append(statementsView(s, byRow));
  // The zakat calculation, in a section of its own under the statements (read-only: the zakat step has the answers).
  if (s.zakat_tax) {
    wrap.append(el('div', { class: 'panel', 'data-zakat-review': '' }, el('h2', {}, ui('zakat_review_title')), el('p', { class: 'note' }, ui('zakat_review_hint', { zakat: exact(s.zakat_tax.zakat.amount), charged: exact(s.zakat_tax.charged.zakat) })),
      el('button', { class: 'btn ghost small', type: 'button', onclick: () => ctx.go('zakat') }, ui('zakat_review_open'))), ...calculation(s.zakat_tax));
  }
  wrap.append(el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', onclick: () => ctx.go('notes') }, ui('prev_step')),
    el('button', { class: 'btn', onclick: () => ctx.go('versions') }, ui('next_step'))));
  return wrap;
}
