// Step 3 (PR 13a): which statement line each account is on (Q3, Q4, Q32, Q36) and the report's adjusting entries (Q39).
// Overrides and entries are each saved as a whole list (PUT); nothing is moved without the user. Rows left out of the
// statements (a parent by the file's levels, or the user's exclusion with its reason) are listed, and a probable parent is
// excluded or kept by the user.
import { C, el, fill, ui, accountName, runRows, name, box, field, showErrors, numberOf, exact, money, checkMessage, checkTitle } from '../core.js';
import { categoryLabel } from '../describe.js';
import { withHeadings } from '../statements-view.js';


const balanceOf = (r) => {
  if (r.closing_debit !== null && r.closing_debit !== undefined) return (r.closing_debit ?? 0) - (r.closing_credit ?? 0);
  return (r.opening_debit ?? 0) - (r.opening_credit ?? 0) + (r.period_debit ?? 0) - (r.period_credit ?? 0) + (r.adjustment_debit ?? 0) - (r.adjustment_credit ?? 0);
};
const accountText = (r) => accountName(r);

/** Statement lines for a picker: [{key, label, group}]. */
function lineOptions(state) {
  const out = [];
  for (const s of ['financial_position', 'comprehensive_income']) {
    for (const r of state.statements[s]) if (r.type === 'line' && !r.input) out.push({ key: r.key, label: r.label_ar, group: name(`statements.${s}`) });
  }
  return out;
}

function lineSelect(state, value, first = [], attrs = {}) {
  const opts = lineOptions(state);
  const byGroup = {};
  for (const o of opts) (byGroup[o.group] ??= []).push(o);
  return el('select', attrs, el('option', { value: '' }, ui('choose_line')),
    first.length ? el('optgroup', { label: ui('suggested_lines') }, first.map((k) => el('option', { value: k, selected: k === value }, opts.find((o) => o.key === k)?.label ?? ui('unknown')))) : null,
    Object.entries(byGroup).map(([g, list]) => el('optgroup', { label: g }, list.filter((o) => !first.includes(o.key)).map((o) => el('option', { value: o.key, selected: o.key === value }, o.label)))));
}

/** row → the lines it is on now (from the statements' lines). */
function linesOfRows(state) {
  const out = {};
  for (const s of ['financial_position', 'comprehensive_income']) {
    for (const r of state.statements[s]) if (r.type === 'line') for (const n of r.rows ?? []) (out[n] ??= []).push(r.label_ar);
  }
  return out;
}

const assetSchedule = (state, line) => (state.schedules ?? []).find((s) => s.kind === 'asset' && s.line_key === line);

export async function render(ctx) {
  const s = ctx.state;
  const byRow = await runRows();
  const rows = Object.values(byRow);
  let overrides = structuredClone(s.account_overrides ?? []);
  const wrap = el('div');
  const status = el('div');

  /** Saved; a refusal said where it was asked (`at`: next to the account's editor), else at the top. */
  async function saveOverrides(list, at = null) {
    const where = at ?? status;
    status.replaceChildren();
    where.replaceChildren();
    try {
      await ctx.save('PUT', '/account-overrides', { overrides: list });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) {
      where.append(box('bad', err.all().join(' ')));
      where.scrollIntoView({ block: 'center' });
    }
  }
  const withOverride = (row, patch) => {
    const rest = overrides.filter((o) => o.row !== row);
    const current = overrides.find((o) => o.row === row) ?? { row };
    const next = { ...current, ...patch };
    for (const k of Object.keys(next)) if (next[k] === null || (Array.isArray(next[k]) && !next[k].length)) delete next[k];
    if ('line_key' in patch && patch.line_key) delete next.shares;
    if ('shares' in patch && patch.shares) delete next.line_key;
    return Object.keys(next).length > 1 ? [...rest, next] : rest;
  };
  /** Left out of the statements, with the user's reason: nothing else on the row. */
  const excluding = (row, reason) => [...overrides.filter((o) => o.row !== row), { row, exclude: true, reason }];
  const excludedRows = Object.fromEntries((s.mapping.excluded ?? []).map((x) => [x.row, x]));
  wrap.append(status);

  // A. Accounts with no line yet.
  const unmapped = s.mapping.unmapped;
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('unmapped_title'), ` (${unmapped.length})`),
    unmapped.length === 0 ? el('p', { class: 'note' }, ui('unmapped_none')) : el('table', {},
      el('tr', {}, el('th', {}, ui('col_account')), el('th', {}, ui('col_balance')), el('th', {}, ui('col_reason')), el('th', {}, ui('col_line'))),
      unmapped.map((u) => {
        const select = lineSelect(s, null, u.candidates ?? [], { 'data-unmapped-row': u.row });
        return el('tr', {},
          el('td', {}, accountText(u)),
          el('td', { class: 'num' }, money(byRow[u.row] ? balanceOf(byRow[u.row]) : null)),
          el('td', {}, name(`unmapped_reasons.${u.reason}`), u.message ? el('div', { class: 'note' }, u.message_text ?? u.message) : null),
          el('td', {}, select, ' ', el('button', { class: 'btn small', type: 'button', 'data-unmapped-save': u.row, onclick: () => select.value && saveOverrides(withOverride(u.row, { line_key: select.value })) }, ui('save'))));
      }))));

  // B. Periodic stock proposals (Q32b): confirmed or kept, never applied by themselves.
  const proposals = s.mapping.proposals;
  if (proposals.length) {
    wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('proposals_title')),
      proposals.map((p) => el('div', { class: 'entry' },
        el('div', {}, el('b', {}, accountText(byRow[p.row])), ' — ', exact(p.amount)),
        el('p', { class: 'note' }, checkMessage(s.checks.find((c) => c.code === 'periodic_inventory_pair' && c.rows?.[0] === p.row) ?? { message_ar: p.message })),
        el('div', { class: 'toolbar' },
          el('button', { class: 'btn small', type: 'button', onclick: () => saveOverrides(withOverride(p.row, { line_key: p.proposed_line })) },
            ui('proposal_move', { line: lineOptions(s).find((o) => o.key === p.proposed_line)?.label ?? ui('unknown') })),
          el('button', { class: 'btn ghost small', type: 'button', onclick: () => saveOverrides(withOverride(p.row, { line_key: p.current_line })) },
            ui('proposal_keep', { line: lineOptions(s).find((o) => o.key === p.current_line)?.label ?? ui('unknown') })))))));
  }

  // B2. Probable parents: their balances are the rows' below them, and they explain why the file doesn't add up.
  const parents = s.mapping.parent_proposals ?? [];
  if (parents.length) {
    wrap.append(el('div', { class: 'panel', 'data-parents': '' }, el('h2', {}, ui('parents_title'), ` (${parents.length})`), el('p', { class: 'note' }, ui('parents_hint')),
      parents.map((p) => {
        const reason = el('input', { 'data-exclude-reason': p.row, placeholder: ui('exclude_reason'), style: 'min-width:280px' });
        const errors = el('div');
        return el('div', { class: 'entry' },
          el('div', {}, el('b', {}, accountText(p)), ' — ', money(byRow[p.row] ? balanceOf(byRow[p.row]) : null)),
          el('p', { class: 'note' }, p.message_text ?? p.message),
          el('div', { class: 'toolbar' }, reason,
            el('button', { class: 'btn small', type: 'button', 'data-exclude': p.row, onclick: () => {
              if (reason.value.trim().length < 3) { errors.replaceChildren(box('bad', ui('exclude_reason_required'))); return; }
              saveOverrides(excluding(p.row, reason.value.trim()));
            } }, ui('exclude')),
            el('button', { class: 'btn ghost small', type: 'button', 'data-keep': p.row, onclick: () => saveOverrides(withOverride(p.row, { line_key: p.current_line })) },
              ui('keep_account', { line: lineOptions(s).find((o) => o.key === p.current_line)?.label ?? ui('unknown') }))),
          errors);
      })));
  }

  // B3. Rows left out of the statements, and why: never silently.
  const excluded = s.mapping.excluded ?? [];
  if (excluded.length) {
    wrap.append(el('details', { class: 'panel', 'data-excluded': '', open: excluded.some((x) => x.reason === 'user') },
      el('summary', {}, ui('excluded_title'), ` (${excluded.length})`),
      el('table', {}, el('tr', {}, el('th', {}, ui('col_account')), el('th', {}, ui('col_balance')), el('th', {}, ui('col_reason')), el('th')),
        excluded.map((x) => el('tr', {},
          el('td', {}, accountText(x)),
          el('td', { class: 'num' }, money(byRow[x.row] ? balanceOf(byRow[x.row]) : null)),
          el('td', {}, x.reason === 'user' ? ui('excluded_user', { reason: x.note ?? '' }) : ui(`excluded_${x.reason}`)),
          el('td', {}, x.reason === 'user' ? el('button', { class: 'btn ghost small', type: 'button', onclick: () => saveOverrides(overrides.filter((o) => o.row !== x.row)) }, ui('include_again')) : null))))));
  }

  // B4a. Gains or losses on disposal with nothing disposed: the accountant confirms them, or keeps them as other income or
  // expenses (tag `not_disposal`) with one click; kept that way, they stay flagged until confirmed, undone with one click.
  const tagged = (row, on) => {
    const tags = (overrides.find((o) => o.row === row)?.tags ?? []).filter((t) => t !== 'not_disposal');
    return { tags: on ? [...tags, 'not_disposal'] : tags };
  };
  const retag = (rows, on) => {
    for (const row of rows) overrides = withOverride(row, tagged(row, on));
    saveOverrides(overrides);
  };
  for (const c of s.checks.filter((x) => ['disposal_unmatched', 'disposal_pending'].includes(x.code) && x.rows?.length)) {
    const pending = c.code === 'disposal_pending';
    wrap.append(el('div', { class: 'panel', 'data-disposal': c.code },
      el('h2', {}, checkTitle(c)), el('p', { class: 'note' }, checkMessage(c)),
      el('ul', {}, c.rows.map((n) => el('li', {}, accountText(byRow[n] ?? { row: n }), ' — ', money(byRow[n] ? balanceOf(byRow[n]) : null),
        pending ? el('span', { class: 'badge b-warn' }, ui('disposal_pending_badge')) : null))),
      el('button', { class: pending ? 'btn ghost small' : 'btn small', type: 'button', 'data-disposal-action': c.code, onclick: () => retag(c.rows, !pending) },
        ui(pending ? 'disposal_restore' : (c.actual > 0 ? 'disposal_as_other_income' : 'disposal_as_other_expenses')))));
  }

  // B4. Each line's accounts adding up to it (D1), statement by statement, with the totals; the debit and credit balances.
  wrap.append(totalsPanel(s, rows.filter((r) => r.sub_class !== undefined && !excludedRows[r.row])));

  // C. Every account: its line now, and the user's override (a line, shares of lines, a note category, tags).
  const onLines = linesOfRows(s);
  const search = el('input', { type: 'search', placeholder: ui('account_search') });
  const onlyOverrides = el('input', { type: 'checkbox' });
  const table = el('table', { 'data-columns': 'accounts' });
  const editor = el('div');
  function drawTable() {
    const q = search.value.trim();
    const list = rows.filter((r) => r.sub_class !== undefined)
      .filter((r) => !q || accountText(r).includes(q) || (r.sub_class ?? '').includes(q))
      .filter((r) => !onlyOverrides.checked || overrides.some((o) => o.row === r.row));
    fill(table, el('tr', {}, el('th', {}, ui('col_account')), el('th', {}, ui('col_class')), el('th', {}, ui('col_balance')), el('th', {}, ui('col_line')), el('th', {})),
      list.slice(0, 300).map((r) => {
        const o = overrides.find((x) => x.row === r.row);
        return el('tr', { 'data-account-row': r.row },
          el('td', {}, accountText(r)),
          el('td', { class: 'note' }, r.sub_class ?? ui('unclassified')),
          el('td', { class: 'num' }, money(balanceOf(r))),
          el('td', {}, excludedRows[r.row] ? ui('excluded_badge') : (onLines[r.row] ?? [ui('no_line')]).join(ui('list_separator')),
            o ? el('span', { class: 'badge b-info' }, ui('overridden')) : null,
            o?.category ? el('span', { class: 'badge b-ok' }, ui('category_badge', { category: categoryLabel(s, o.category) })) : null,
            (o?.tags ?? []).map((t) => el('span', { class: 'badge b-warn' }, name(`tags.${t}`)))),
          el('td', {}, el('button', { class: 'btn ghost small', type: 'button', onclick: () => edit(r) }, ui('edit'))));
      }),
      list.length > 300 ? el('tr', {}, el('td', { colspan: 5, class: 'note' }, ui('more_accounts', { count: list.length - 300 }))) : null);
  }
  search.addEventListener('input', drawTable);
  onlyOverrides.addEventListener('change', drawTable);

  function edit(r) {
    const o = overrides.find((x) => x.row === r.row) ?? { row: r.row };
    const shares = Object.entries(o.shares ?? {});
    const mode = el('select', { 'data-mode': '' }, el('option', { value: 'rule', selected: !o.line_key && !o.shares && !o.exclude }, ui('mode_rule')),
      el('option', { value: 'line', selected: !!o.line_key }, ui('mode_line')), el('option', { value: 'shares', selected: !!o.shares }, ui('mode_shares')),
      el('option', { value: 'exclude', selected: !!o.exclude }, ui('exclude')));
    const reason = el('textarea', { 'data-field': 'exclude-reason', placeholder: ui('exclude_reason') }, o.reason ?? '');
    const reasonBox = field(ui('exclude_reason'), reason, ui('exclude_hint'));
    // The line the classification gives, shown in «حسب التصنيف»; choosing another one is applied as «سطر واحد», said so.
    const ruleLine = s.statements.financial_position.concat(s.statements.comprehensive_income).find((x) => x.type === 'line' && (x.rows ?? []).includes(r.row))?.key ?? null;
    const line = lineSelect(s, o.line_key ?? (o.shares || o.exclude ? null : ruleLine), [], { 'data-account-line': '' });
    const applied = el('div');
    const shareRows = el('div');
    const addShare = (k = '', v = '') => {
      const sel = lineSelect(s, k);
      const val = el('input', { class: 'num', inputmode: 'decimal', value: v, placeholder: '0.5', style: 'width:90px' });
      const rowEl = el('div', { class: 'row', style: 'margin-bottom:6px' }, sel, val, el('button', { class: 'btn danger small', type: 'button', onclick: () => rowEl.remove() }, ui('remove')));
      rowEl.read = () => [sel.value, numberOf(val)];
      shareRows.append(rowEl);
    };
    (shares.length ? shares : [['', ''], ['', '']]).forEach(([k, v]) => addShare(k, v));
    const sharesBox = el('div', {}, shareRows, el('button', { class: 'btn ghost small', type: 'button', onclick: () => addShare() }, ui('add_share')), el('p', { class: 'note' }, ui('shares_hint')));
    const lineBox = el('div', {}, line, applied);
    const showMode = () => {
      lineBox.style.display = mode.value === 'line' || mode.value === 'rule' ? '' : 'none';
      sharesBox.style.display = mode.value === 'shares' ? '' : 'none';
      reasonBox.style.display = mode.value === 'exclude' ? '' : 'none';
      tagsBox.style.display = mode.value === 'exclude' ? 'none' : '';
      drawCategory();
    };
    mode.addEventListener('change', () => {
      if (mode.value === 'rule') line.value = ruleLine ?? '';
      applied.replaceChildren();
      showMode();
    });
    line.addEventListener('change', () => {
      if (mode.value === 'rule' && line.value && line.value !== ruleLine) {
        mode.value = 'line';
        applied.replaceChildren(el('p', { class: 'note', 'data-line-applied': '' }, ui('line_applied')));
        showMode();
      }
      drawCategory();
    });

    const category = el('select');
    const categoryBox = el('div');
    function drawCategory() {
      // A category of the asset note of the line the account ends up on.
      const target = mode.value === 'line' ? line.value : (mode.value === 'rule' ? ruleLine : null);   // excluded: no note
      const sch = target ? assetSchedule(s, target) : null;
      categoryBox.replaceChildren();
      if (!sch) return;
      category.replaceChildren(el('option', { value: '' }, ui('category_auto')),
        ...(sch.category_options ?? sch.columns.filter((c) => c.key !== 'total')).map((c) => el('option', { value: c.key, selected: c.key === o.category }, c.label_ar)));
      categoryBox.append(field(ui('category_of', { note: sch.title_ar }), category));
    }
    const tagBoxes = Object.keys(C.labels.tags ?? {}).map((t) => [t, el('input', { type: 'checkbox', checked: (o.tags ?? []).includes(t) })]);
    const errors = el('div');
    const tagsBox = el('div', { class: 'field' }, el('label', {}, ui('tags')), tagBoxes.map(([t, cb]) => el('label', { style: 'display:block' }, cb, ' ', name(`tags.${t}`))));
    editor.replaceChildren(el('div', { class: 'panel' },
      el('h3', {}, ui('edit_account', { account: accountText(r) })),
      field(ui('line_choice'), mode), lineBox, sharesBox, reasonBox, categoryBox, tagsBox,
      errors,
      el('div', { class: 'toolbar' },
        el('button', { class: 'btn', type: 'button', 'data-save-account': '', onclick: () => {
          if (mode.value === 'exclude') {
            if (reason.value.trim().length < 3) { errors.replaceChildren(box('bad', ui('exclude_reason_required'))); return; }
            saveOverrides(excluding(r.row, reason.value.trim()), errors);
            return;
          }
          const patch = { line_key: null, shares: null, category: categoryBox.childElementCount ? (category.value || null) : null,
            tags: tagBoxes.filter(([, cb]) => cb.checked).map(([t]) => t) };
          if (mode.value === 'line') {
            if (!line.value) { errors.replaceChildren(box('bad', ui('choose_line'))); return; }
            patch.line_key = line.value;
          }
          if (mode.value === 'shares') {
            const pairs = [...shareRows.children].map((x) => x.read()).filter(([k]) => k);
            const total = pairs.reduce((a, [, v]) => a + (v ?? 0), 0);
            if (pairs.length < 2 || pairs.some(([, v]) => !(v > 0 && v < 1)) || Math.abs(total - 1) > 0.0001) { errors.replaceChildren(box('bad', ui('shares_invalid'))); return; }
            patch.shares = Object.fromEntries(pairs);
          }
          saveOverrides(withOverride(r.row, { ...patch, exclude: null, reason: null }), errors);
        } }, ui('save')),
        overrides.some((x) => x.row === r.row) ? el('button', { class: 'btn danger', type: 'button', onclick: () => saveOverrides(overrides.filter((x) => x.row !== r.row), errors) }, ui('remove_override')) : null,
        el('button', { class: 'btn ghost', type: 'button', onclick: () => editor.replaceChildren() }, ui('cancel')))));
    showMode();
    editor.scrollIntoView({ block: 'start' });
  }
  drawTable();
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('accounts_title')),
    el('div', { class: 'toolbar' }, search, el('label', { class: 'note' }, onlyOverrides, ' ', ui('only_overridden'))),
    editor, el('div', { style: 'overflow-x:auto' }, table)));

  // D. Adjusting entries (Q39).
  wrap.append(entriesEditor(ctx, rows));

  wrap.append(el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', onclick: () => ctx.go('prior_year') }, ui('prev_step')),
    el('button', { class: 'btn', onclick: () => ctx.go('opening') }, ui('next_step'))));
  return wrap;
}

/** D1: every line's accounts total next to the line, the totals added up the same way, and the trial balance's sides. */
function totalsPanel(s, accounts) {
  const lineTotals = s.mapping.line_totals ?? {};
  const statements = ['financial_position', 'comprehensive_income'].map((st) => {
    const items = s.statements[st];
    const byKey = Object.fromEntries(items.filter((r) => r.key).map((r) => [r.key, r]));
    const sumOf = (field) => {
      const of = (key) => {
        const r = byKey[key];
        if (r?.type === 'total') return (r.sum ?? []).reduce((a, k) => a + of(k), 0);
        return lineTotals[key]?.[field] ?? 0;
      };
      return of;
    };
    const accountsOf = sumOf('amount');
    // With approved openings: each line's part from last year's approved closing, apart from its accounts (Step B).
    const approvedOf = sumOf('approved_opening');
    const approved = Object.values(lineTotals).some((t) => t.approved_opening);
    // A line with accounts or a figure; a total when one of its lines shows; a heading when a line under it shows (no
    // heading left empty once its accounts moved away).
    const lineShows = (r) => !!(lineTotals[r.key] || Math.round(r.current?.amount ?? 0) !== 0);
    const totalShows = (key) => { const r = byKey[key]; return r?.type === 'total' ? (r.sum ?? []).some(totalShows) : !!r && lineShows(r); };
    const flags = withHeadings(items, (r) => (r.type === 'total' ? totalShows(r.key) : lineShows(r)));
    const shown = items.filter((r, i) => flags[i]);
    return el('details', { open: true, 'data-totals': st },
      el('summary', {}, name(`statements.${st}`)),
      el('div', { style: 'overflow-x:auto' }, el('table', { 'data-columns': `totals_${st}` },
        el('tr', {}, el('th', {}, ui('col_line')), el('th', { 'data-col': 'count' }, ui('col_accounts_count')), el('th', { 'data-col': 'accounts' }, ui('col_accounts_total')),
          approved ? el('th', { 'data-col': 'approved_opening' }, ui('col_approved_opening')) : null,
          el('th', { 'data-col': 'statement' }, ui('col_in_statement')), el('th', { 'data-col': 'difference' }, ui('col_difference'))),
        shown.map((r) => {
          if (r.type === 'heading') return el('tr', {}, el('td', { colspan: approved ? 6 : 5 }, el('b', {}, r.label_ar)));
          const accounts = accountsOf(r.key);
          const fromApproved = approvedOf(r.key);
          const inStatement = r.current?.amount ?? null;
          const diff = inStatement === null ? null : Math.round((inStatement - accounts - fromApproved) * 100) / 100;
          return el('tr', { 'data-line-total': r.key, class: r.type === 'total' ? 'total' : '' },
            el('td', {}, r.type === 'total' ? el('b', {}, r.label_ar) : r.label_ar),
            el('td', { class: 'num' }, r.type === 'line' ? String(lineTotals[r.key]?.accounts ?? 0) : ''),
            el('td', { class: 'num' }, exact(accounts)),
            approved ? el('td', { class: 'num', 'data-approved-opening': r.key }, exact(fromApproved)) : null,
            el('td', { class: 'num' }, exact(inStatement)),
            el('td', { class: `num ${diff ? 'balance-bad' : ''}` }, diff === null ? '' : exact(diff)));
        }))));
  });
  let debit = 0; let credit = 0;
  for (const r of accounts) { const b = balanceOf(r); if (b > 0) debit += b; else credit -= b; }
  const diff = Math.round((debit - credit) * 100) / 100;
  return el('div', { class: 'panel', 'data-line-totals': '' }, el('h2', {}, ui('totals_title')), el('p', { class: 'note' }, ui('totals_hint')),
    el('p', { 'data-tb-totals': '' }, el('span', { class: diff === 0 ? 'balance-ok' : 'balance-bad' }, ui('tb_totals', { debit: exact(debit), credit: exact(credit), diff: exact(diff) }))),
    statements);
}

function entriesEditor(ctx, rows) {
  const s = ctx.state;
  const list = el('div');
  const errors = el('div');
  const form = el('form', {}, el('h2', {}, ui('entries_title')), el('p', { class: 'note' }, ui('entries_hint')), list, errors);
  const targets = () => el('select', {},
    el('option', { value: '' }, ui('choose_target')),
    el('optgroup', { label: ui('accounts_group') }, rows.map((r) => el('option', { value: `row:${r.row}` }, accountText(r)))),
    lineOptions(s).length ? el('optgroup', { label: ui('lines_group') }, lineOptions(s).map((o) => el('option', { value: `line:${o.key}` }, o.label))) : null);

  function entryBox(entry, i) {
    const reason = el('textarea', { 'data-field': `entries.${i}.reason` }, entry?.reason ?? '');
    const lines = el('tbody');
    const sum = el('div', { class: 'note' });
    const refresh = () => {
      let d = 0; let c = 0;
      for (const tr of lines.children) { d += numberOf(tr.debit) || 0; c += numberOf(tr.credit) || 0; }
      const diff = Math.round((d - c) * 100) / 100;
      sum.replaceChildren(el('span', { class: diff === 0 ? 'balance-ok' : 'balance-bad' },
        diff === 0 ? ui('entry_balanced', { amount: exact(d) }) : ui('entry_unbalanced', { amount: exact(diff) })));
    };
    const addLine = (l = {}) => {
      const target = targets();
      target.value = l.row ? `row:${l.row}` : (l.line_key ? `line:${l.line_key}` : '');
      const debit = el('input', { class: 'num', inputmode: 'decimal', value: l.debit || '', oninput: refresh });
      const credit = el('input', { class: 'num', inputmode: 'decimal', value: l.credit || '', oninput: refresh });
      const tr = el('tr', {}, el('td', {}, target), el('td', {}, debit), el('td', {}, credit),
        el('td', {}, el('button', { class: 'btn danger small', type: 'button', onclick: () => { tr.remove(); refresh(); } }, ui('remove'))));
      Object.assign(tr, { target, debit, credit });
      lines.append(tr);
    };
    (entry?.lines?.length ? entry.lines : [{}, {}]).forEach(addLine);
    const boxEl = el('div', { class: 'entry' },
      el('div', { class: 'toolbar' }, el('b', {}, ui('entry_n', { n: i + 1 })), el('button', { class: 'btn danger small', type: 'button', onclick: () => { boxEl.remove(); renumber(); } }, ui('remove_entry'))),
      field(ui('entry_reason'), reason),
      el('table', {}, el('thead', {}, el('tr', {}, el('th', {}, ui('col_target')), el('th', {}, ui('col_debit')), el('th', {}, ui('col_credit')), el('th', {}))), lines),
      el('button', { class: 'btn ghost small', type: 'button', onclick: () => addLine() }, ui('add_line')), sum);
    boxEl.read = (bad) => ({
      reason: reason.value.trim(),
      lines: [...lines.children].map((tr) => {
        const [kind, key] = tr.target.value.split(/:(.*)/s);
        const d = numberOf(tr.debit) ?? 0; const c = numberOf(tr.credit) ?? 0;
        if (!tr.target.value || Number.isNaN(d) || Number.isNaN(c)) bad.push(tr);
        return { ...(kind === 'row' ? { row: Number(key) } : { line_key: key }), debit: d, credit: c };
      }),
    });
    refresh();
    return boxEl;
  }
  const renumber = () => [...list.children].forEach((b, i) => { b.querySelector('b').textContent = ui('entry_n', { n: i + 1 }); });
  (s.adjustments ?? []).forEach((e, i) => list.append(entryBox(e, i)));
  form.append(el('div', { class: 'toolbar' },
    el('button', { class: 'btn ghost', type: 'button', onclick: () => list.append(entryBox(null, list.childElementCount)) }, ui('add_entry')),
    el('button', { class: 'btn', type: 'submit' }, ui('save_entries'))));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const bad = [];
    const entries = [...list.children].map((b) => b.read(bad));
    if (bad.length || entries.some((x) => x.reason.length < 3)) { showErrors(form, { message: ui('entries_incomplete'), errors: {} }, errors); return; }
    try {
      await ctx.save('PUT', '/adjustments', { entries });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { showErrors(form, err, errors); }
  });
  return el('div', { class: 'panel' }, form);
}
