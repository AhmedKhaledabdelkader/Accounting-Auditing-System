// Step 2 (PR 13a): where the comparatives come from (Q7, Q9, Q10). For a prior trial balance or manual entry, the grids of what
// can be typed from last year's issued statements and notes (state.comparative.entry). A cell left empty is not entered.
// PR 17: or the Excel file of last year's issued statements, read by the server and reviewed here row by row
// (state.comparative.upload) before it is used. A PDF goes through its statements' pages first: found by the system,
// confirmed or corrected here, then read; the page follows the server while it works.
import { clientFilePicker } from '../../client-file-picker.js';
import { C, api, upload, el, ui, name, url, day, field, showErrors, numberOf, money, box, checkTitle, checkMessage } from '../core.js';
import { cellLabel } from '../describe.js';

const SOURCES = ['none', 'first_year', 'report', 'run', 'manual', 'statements'];

/**
 * What was typed in the step and not saved yet, by each input's `data-draft` key, per source: another save in the step
 * (a page, a move, the company's details) draws the step again from the server, and these come back into their inputs
 * instead of being lost. Cleared once the step is saved.
 */
let drafts = { report: null, values: {} };

/** A number input bound to `values[key]`, its errors under `data-field`; `draftKey`: kept while unsaved. */
function amount(errorKey, value, draftKey = null) {
  return el('input', { class: 'num', inputmode: 'decimal', 'data-field': errorKey, value: value ?? '', placeholder: '—', 'data-draft': draftKey });
}

/** Reads the inputs of a grid: key → number; a bad number is reported against its input; a figure shown, unchanged, isn't. */
function read(inputs, bad) {
  const out = {};
  for (const [key, input] of inputs) {
    const v = numberOf(input);
    if (v === null) continue;
    if (Number.isNaN(v)) { bad.push(input); continue; }
    if (input.dataset.prefill !== undefined && v === Number(input.dataset.prefill)) continue;
    out[key] = v;
  }
  return out;
}

/**
 * Last year's PPE movement as read from the PDF (R13), by its own categories and rows, read-only: the note's comparative.
 * Its categories and accounts are settled in step 5.
 */
function ppeFromPdf(s, page) {
  const cats = s.ppe_prior?.categories ?? [];
  const rows = ['cost_opening', 'additions', 'disposals', 'cost_closing', 'acc_opening', 'depreciation', 'acc_disposals', 'acc_closing', 'nbv_closing'];
  return el('div', { 'data-ppe-from-pdf': '' }, el('h3', {}, name('notes.ppe')),
    el('p', { class: 'note' }, ui('grid_ppe_from_pdf', { page: page ?? '' }), ' ', el('a', { href: '#notes' }, ui('go_notes'))),
    el('div', { style: 'overflow-x:auto' }, el('table', { class: 'grid-table' },
      el('tr', {}, el('th'), cats.map((c) => el('th', {}, c.label))),
      rows.filter((r) => cats.some((c) => Math.abs(c.last?.[r] ?? 0) >= 0.005)).map((r) => el('tr', {}, el('td', {}, name(`ppe_rows.${r}`)),
        cats.map((c) => el('td', { class: 'num' }, money(c.last?.[r] ?? 0))))))));
}

function linesGrid(rows, keys, values, prefix) {
  const inputs = [];
  const allowed = new Set(keys);
  const body = rows.filter((r) => r.type === 'heading' || (r.type === 'line' && allowed.has(r.key))).map((r) => {
    if (r.type === 'heading') return el('tr', {}, el('td', { colspan: 2, class: 'note' }, r.label_ar));
    const input = amount(`${prefix}.${r.key}`, values?.[r.key], `${prefix}.${r.key}`);
    inputs.push([r.key, input]);
    return el('tr', {}, el('td', {}, r.label_ar), el('td', {}, input));
  });
  return { node: el('table', { class: 'grid-table' }, body), inputs };
}

/**
 * The file of last year's issued statements (PR 17): each statement read, its year column, and each row with an amount
 * placed on a line (or a total to check, or ignored). Exact matches come placed; the rest wait for a choice. A figure the
 * file couldn't read stays missing.
 */
/** The PDF of last year's statements, open at a page. */
const pdfPage = (page) => `${C.api}/financial-reports/${encodeURIComponent(C.report_id)}/prior-year/statements-file#page=${page || 1}`;
const pageRange = (r) => (r.from === r.to ? ui('pdf_pages_range', { from: r.from }) : ui('pdf_pages_range_many', { from: r.from, to: r.to }));
const PDF_STATEMENTS = ['financial_position', 'comprehensive_income', 'changes_in_equity', 'cash_flows'];
let poll = null;   // the next look at a PDF the server is still working on

/**
 * A PDF's statements and their pages: what the system found, each page a link to the file, corrected here and read with
 * «اقرا القوائم». While the server looks for the pages or reads them, a note, and the page asks again shortly.
 */
function pdfPanel(ctx, up) {
  if (up.status === 'locating' || up.status === 'reading') {
    // Asks for the state quietly; the step is drawn again only once the file's status changes.
    const check = async () => {
      if (!location.hash.startsWith('#prior_year')) return;
      let state;
      try {
        state = await api('GET', `/financial-reports/${encodeURIComponent(C.report_id)}`);
      } catch {
        poll = setTimeout(check, 5000);   // a network hiccup: ask again later
        return;
      }
      if (state.comparative.upload?.status === up.status && state.comparative.upload?.uploaded_at === up.uploaded_at) {
        poll = setTimeout(check, 2500);
        return;
      }
      ctx.apply(state);
      ctx.redraw();
    };
    poll = setTimeout(check, 2500);
    return el('div', { class: 'panel', 'data-pdf-status': up.status }, box('warn', ui(up.status === 'locating' ? 'pdf_locating' : 'pdf_reading'), el('div', { class: 'busy-line', role: 'progressbar' })));
  }
  // What each page is (brief §8.1): one row per page, its kind and turn corrected here; the statements' pages follow.
  const kinds = up.page_kinds ?? [];
  const kindInputs = kinds.map((p) => {
    const kind = el('select', { 'data-field': `page_kinds.${p.page - 1}.kind`, 'data-page-kind': p.page },
      C.ui.pdf_kinds ? Object.entries(C.ui.pdf_kinds).map(([k, label]) => el('option', { value: k, selected: k === p.kind }, label)) : []);
    const rotation = el('select', { 'data-field': `page_kinds.${p.page - 1}.rotation`, 'data-page-rotation': p.page },
      [0, 90, 180, 270].map((r) => el('option', { value: r, selected: r === p.rotation }, ui(`pdf_rotation_${r}`))));
    return { page: p.page, kind, rotation, row: el('tr', { 'data-pdf-page': p.page },
      el('td', { class: 'num' }, el('a', { href: pdfPage(p.page), target: '_blank', rel: 'noopener' }, String(p.page))),
      el('td', { class: 'num' }, p.printed_page ?? ''), el('td', {}, kind), el('td', {}, rotation),
      el('td', { class: 'note' }, (p.notes ?? []).map((n) => el('div', {}, n.number ? `${n.number} ${n.title}` : n.title)))) };
  });
  const inputs = {};
  const rows = PDF_STATEMENTS.map((st) => {
    const at = up.pages[st] ?? null;
    const found = up.found[st] ?? null;
    const attrs = { type: 'number', min: 1, max: up.pages_total, class: 'num', style: 'width:90px' };
    const from = el('input', { ...attrs, value: at?.from ?? '', 'data-field': `pages.${st}.from` });
    const to = el('input', { ...attrs, value: at?.to ?? '', 'data-field': `pages.${st}.to` });
    const open = el('a', { href: pdfPage(at?.from), target: '_blank', rel: 'noopener', 'data-pdf-open': st }, ui('pdf_open'));
    from.addEventListener('input', () => { open.href = pdfPage(Number(from.value)); });
    inputs[st] = [from, to];
    return el('tr', { 'data-pdf-statement': st },
      el('td', {}, name(`statements.${st}`), st === 'changes_in_equity' ? el('div', { class: 'note' }, ui('pdf_not_read')) : null),
      el('td', {}, from), el('td', {}, to), el('td', {}, open),
      el('td', { class: 'note' }, found ? ui('pdf_found_at', { pages: pageRange(found) }) : ui('pdf_not_found')));
  });
  const status = el('div');
  const body = el('div', {},
    up.status === 'failed' && up.error ? box('bad', up.error) : null,
    up.rejected ? el('div', { 'data-pdf-rejected': '' }, box('bad', up.rejected)) : null,
    Object.keys(up.found).length ? null : box('warn', ui('pdf_pages_none_found')),
    el('p', { class: 'note' }, ui(kinds.length ? 'pdf_page_kinds_hint' : 'pdf_pages_hint', { pages: up.pages_total })),
    kinds.length
      ? el('div', { style: 'overflow-x:auto' }, el('table', { 'data-page-kinds': '' },
        el('tr', {}, el('th', {}, ui('pdf_page')), el('th', {}, ui('pdf_printed_page')), el('th', {}, ui('pdf_kind')), el('th', {}, ui('pdf_rotation')), el('th', {}, ui('pdf_notes_on_page'))),
        kindInputs.map((k) => k.row)))
      : el('div', { style: 'overflow-x:auto' }, el('table', {}, el('tr', {}, el('th', {}, ui('syn_statement')), el('th', {}, ui('pdf_from')), el('th', {}, ui('pdf_to')), el('th'), el('th')), rows)),
    // Last year's auditor's report: named so it is never read (R9).
    Object.entries(up.skipped ?? {}).map(([part, at]) => el('p', { class: 'note', 'data-pdf-skipped': part }, ui(`pdf_skipped_${part}`, { pages: pageRange(at) }))),
    el('button', { class: 'btn', type: 'button', 'data-pdf-read': '', onclick: async () => {
      const pages = {};
      for (const [st, [from, to]] of Object.entries(inputs)) {
        if (from.value) pages[st] = { from: Number(from.value), to: Number(to.value || from.value) };
      }
      const body = kinds.length
        ? { page_kinds: kindInputs.map((k) => ({ page: k.page, kind: k.kind.value, rotation: Number(k.rotation.value) })) }
        : { pages };
      status.replaceChildren(box('warn', ui('pdf_reading')));
      try {
        await ctx.save('PUT', '/prior-year/statements-file/pages', body);
        ctx.redraw();
      } catch (err) {
        showErrors(body, err, status);
      }
    } }, ui(up.status === 'ready' ? 'pdf_read_again' : 'pdf_read')),
    status,
    up.cost_usd != null ? el('p', { class: 'note' }, ui('pdf_cost', { cost: `$${up.cost_usd.toFixed(3)}` })) : null);
  // Once read, the pages fold away under the review.
  return up.status === 'ready'
    ? el('details', { class: 'panel', 'data-pdf-pages': '' }, el('summary', {}, ui('pdf_pages_title')), body)
    : el('div', { class: 'panel', 'data-pdf-pages': '' }, el('h2', {}, ui('pdf_pages_title')), body);
}

/** A column's letters as its number in the sheet (A → 1): the page shows no codes. */
const columnNumber = (letters) => [...letters].reduce((n, ch) => n * 26 + ch.charCodeAt(0) - 64, 0);

/**
 * A wording issue's one click: a corrected word kept as printed (or corrected again); a word flagged marked as right,
 * never flagged again (the known words); a total differing from its columns' sum switched to the other figure.
 */
function wordingButtons(ctx, issue) {
  const send = async (body) => {
    await ctx.save('PUT', '/prior-year/statements-file/wording', body);
    ctx.redraw();
  };
  const button = (label, body, attrs = {}) => el('button', { type: 'button', class: 'btn ghost small', style: 'margin-inline-start:6px', ...attrs, onclick: () => send(body) }, label);
  if (issue.total_group) {
    // A year end's balances: the other combinations that agree, one click each.
    const g = issue.total_group;
    return g.options.map((o, i) => i === g.used ? null : el('button', { type: 'button', class: 'btn ghost small', style: 'margin-inline-start:6px', 'data-total-group': `${g.id}:${i}`,
      onclick: async () => { await ctx.save('PUT', '/prior-year/statements-file/totals', { use: { [g.id]: String(i) } }); ctx.redraw(); } },
      ui('total_group_use', { option: o.text })));
  }
  if (issue.total) {
    // A total differing from its columns' sum: the other figure, one click (the choice goes back and forth).
    const t = issue.total;
    const other = t.used === 'printed' ? 'sum' : 'printed';
    return el('button', { type: 'button', class: 'btn ghost small', style: 'margin-inline-start:6px', 'data-total-choice': t.id,
      onclick: async () => { await ctx.save('PUT', '/prior-year/statements-file/totals', { use: { [t.id]: other } }); ctx.redraw(); } },
      other === 'printed' ? ui('total_use_printed', { amount: money(t.printed) }) : ui('total_use_sum', { amount: money(t.sum) }));
  }
  if (issue.correction) {
    const c = issue.correction;
    return button(ui(c.kept_original ? 'wording_correct_again' : 'wording_keep_original', { word: c.original, corrected: c.corrected }),
      { keep_original: { [c.id]: !c.kept_original } }, { 'data-wording-correction': c.id });
  }
  return (issue.words ?? []).map((w) => button(ui('wording_known', { word: w }), { known_words: [w] }, { 'data-wording-known': w }));
}

/**
 * Accounts on another line than last year: each proposal (a line short of last year's figure, the accounts that explain
 * it) accepted with one click, or all together; the accounts a line didn't have last year sent with a choice of line.
 * Accepting moves the account (an override, kept with the others).
 */
function comparable(ctx, s, up) {
  const { proposals = [], strays = [] } = up.comparable ?? {};
  if (!proposals.length && !strays.length) return null;
  const status = el('div');
  const move = async (moves) => {
    const kept = (s.account_overrides ?? []).filter((o) => !moves.some((m) => m.row === o.row));
    try {
      await ctx.save('PUT', '/account-overrides', { overrides: [...kept, ...moves.map((m) => ({ row: m.row, line_key: m.line }))] });
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
  };
  // Matched by the amount alone (the names differ): never moved with everything, only by their own click (an account,
  // or the set adding up to a note line together).
  const all = proposals.flatMap((p) => p.accounts.filter((a) => !a.amount_only).map((a) => ({ row: a.row, line: p.line })));
  const byAmount = (p) => Object.values(p.accounts.filter((a) => a.amount_only).reduce((groups, a) => {
    (groups[`${a.by}:${a.item ?? ''}`] ??= []).push(a);
    return groups;
  }, {}));
  const strayInputs = [];
  return el('div', { 'data-comparable': '' }, el('h3', {}, ui('comparable_title')), el('p', { class: 'note' }, ui('comparable_hint')),
    proposals.map((p) => el('div', { 'data-comparable-line': p.line }, el('b', {}, p.short > 0 ? ui('comparable_line', { line: p.label, short: money(p.short) }) : ui('comparable_line_note', { line: p.label })),
      el('ul', {}, p.accounts.map((a) => el('li', { 'data-comparable-account': a.row }, ui('comparable_account', { name: a.name, amount: money(a.amount), from: a.from_label }),
        a.item ? el('span', { class: 'note' }, ` — ${ui('comparable_item', { item: a.item })}`) : null,
        a.amount_only ? el('span', { class: 'badge b-warn', 'data-comparable-amount-only': '', style: 'margin-inline-start:6px' }, ui('comparable_amount_only')) : null))),
      p.accounts.some((a) => !a.amount_only) ? el('button', { class: 'btn ghost small', type: 'button', 'data-comparable-accept': p.line,
        onclick: () => move(p.accounts.filter((a) => !a.amount_only).map((a) => ({ row: a.row, line: p.line }))) }, ui('comparable_accept')) : null,
      byAmount(p).map((group) => el('button', { class: 'btn ghost small', type: 'button', 'data-comparable-accept-amount': group.map((a) => a.row).join(','),
        style: 'margin-inline-start:6px', onclick: () => move(group.map((a) => ({ row: a.row, line: p.line }))) },
        ui('comparable_accept_amount', { names: group.map((a) => a.name).join('، ') }))))),
    proposals.filter((p) => p.accounts.some((a) => !a.amount_only)).length > 1 ? el('button', { class: 'btn small', type: 'button', 'data-comparable-all': '', onclick: () => move(all) }, ui('comparable_accept_all')) : null,
    strays.map((st) => el('div', { 'data-comparable-strays': st.from }, el('b', {}, ui('comparable_strays', { line: st.from_label, kept: money(st.kept) })),
      el('table', {}, st.accounts.map((a) => {
        const sel = el('select', { 'data-stray-row': a.row }, el('option', { value: '' }, ui('comparable_stay')),
          st.targets.map((t) => el('option', { value: t.line }, ui('comparable_target', { line: t.label, short: money(t.short) }))));
        strayInputs.push([a, sel]);
        return el('tr', {}, el('td', {}, a.name), el('td', { class: 'num' }, money(a.amount)), el('td', {}, sel));
      })),
      el('button', { class: 'btn ghost small', type: 'button', onclick: () => {
        const moves = strayInputs.filter(([, sel]) => sel.value).map(([a, sel]) => ({ row: a.row, line: sel.value }));
        if (moves.length) move(moves); else status.replaceChildren(box('warn', ui('nothing_changed')));
      } }, ui('comparable_send')))),
    status);
}

/** The fields of the company's profile last year's notes can give. */
const COMPANY_FACTS = { name_ar: () => ui('company_name_ar'), cr_number: () => ui('company_cr_number'), cr_date_hijri: () => ui('company_cr_date_hijri'),
  city: () => ui('company_city'), head_office: () => ui('company_head_office'), activity: () => ui('company_activity') };

/**
 * The company as last year's notes describe it (note 1), each with its page; one click puts them in the company's
 * profile where it is still empty (nothing typed is overwritten).
 */
function companyFacts(ctx, s, up) {
  const facts = Object.keys(COMPANY_FACTS).filter((k) => up.company?.[k]);
  if (!facts.length) return null;
  const empty = s.company ? facts.filter((k) => !s.company[k]) : [];
  const status = el('div');
  // Another company's file (its name or registration differs from the profile): said, with the app's companies it names;
  // the report moves to one only by the user's click, and the facts fill this company only once confirmed.
  const identity = up.company_identity ?? { differs: [], matches: [] };
  const confirm = identity.differs.length ? el('input', { type: 'checkbox', 'data-company-same': '' }) : null;
  const apply = empty.length ? el('button', { class: 'btn ghost small', type: 'button', 'data-company-facts-apply': '', disabled: !!confirm, onclick: async () => {
    try {
      await api('PATCH', `/companies/${encodeURIComponent(s.company.id)}`, Object.fromEntries(empty.map((k) => [k, up.company[k].value])));
      await ctx.reload();
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
  } }, ui('company_facts_apply', { count: empty.length })) : null;
  confirm?.addEventListener('change', () => { if (apply) apply.disabled = !confirm.checked; });
  const mismatch = identity.differs.length ? el('div', { 'data-company-mismatch': '' },
    box('warn', ui('company_mismatch', { company: s.company?.name_ar ?? '' }), ' ',
      identity.differs.map((d) => `${COMPANY_FACTS[d.field]()}: «${d.file}» / «${d.company}»`).join('، ')),
    identity.matches.map((m) => el('p', { class: 'note' }, ui('company_match', { name: m.name_ar }), ' ',
      el('button', { class: 'btn ghost small', type: 'button', 'data-company-switch': m.id, onclick: async () => {
        try { await ctx.save('PATCH', '', { company_id: m.id }); ctx.flash = ui('saved'); ctx.redraw(); } catch (err) { status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
      } }, ui('company_switch', { name: m.name_ar })))),
    empty.length ? el('label', { style: 'display:block;margin:6px 0' }, confirm, ' ', ui('company_same_confirm')) : null) : null;
  return el('div', { 'data-company-facts': '' }, el('h3', {}, ui('company_facts_title')), mismatch,
    el('table', {}, facts.map((k) => el('tr', { 'data-company-fact': k }, el('td', {}, COMPANY_FACTS[k]()), el('td', {}, up.company[k].value),
      el('td', {}, el('a', { href: pdfPage(up.company[k].page), target: '_blank', rel: 'noopener' }, ui('pdf_open_page', { page: up.company[k].page })),
        s.company && s.company[k] && s.company[k] !== up.company[k].value ? el('span', { class: 'badge b-warn', style: 'margin-inline-start:6px' }, ui('company_fact_differs', { value: s.company[k] })) : null)))),
    !s.company ? el('p', { class: 'note' }, ui('company_facts_no_company'))
      : apply ?? el('p', { class: 'note' }, ui('company_facts_all_set')),
    status);
}

/**
 * This year's opening balances against the file's closing figures, line by line: every difference shown, a riyal either
 * way labelled probably rounding; the lines that agree counted.
 */
function openingCheck(up, pdf) {
  const lines = up.opening_check ?? [];
  if (!lines.length) return null;
  const differ = lines.filter((l) => l.kind !== 'equal');
  return el('div', { 'data-opening-check': '' }, el('h3', {}, ui('opening_check_title')),
    differ.length ? el('table', {}, el('tr', {}, el('th', {}, ui('file_line')), el('th', { class: 'num' }, ui('opening_check_opening')),
      el('th', { class: 'num' }, ui('opening_check_prior')), el('th', { class: 'num' }, ui('opening_check_difference')), el('th', {})),
      differ.map((l) => el('tr', { 'data-opening-line': l.line },
        el('td', {}, l.label), el('td', { class: 'num' }, money(l.opening)), el('td', { class: 'num' }, money(l.prior)), el('td', { class: 'num' }, money(l.difference)),
        el('td', {}, el('span', { class: `badge ${l.kind === 'rounding' ? 'b-warn' : 'b-bad'}` }, ui(l.kind === 'rounding' ? 'opening_check_rounding' : 'opening_check_difference_badge')),
          pdf ? l.pages.map((p) => el('a', { href: pdfPage(p), target: '_blank', rel: 'noopener', style: 'margin-inline-start:6px' }, ui('pdf_open_page', { page: p }))) : null)))) : null,
    el('p', { class: 'note' }, ui('opening_check_equal', { count: lines.length - differ.length, total: lines.length })));
}

/** The parts of the balance sheet the approved openings' totals are given for. */
const APPROVED_PARTS = { assets: () => ui('approved_opening_part_assets'), equity: () => ui('approved_opening_part_equity'),
  liabilities: () => ui('approved_opening_part_liabilities') };

/**
 * Step B: this year's openings from last year's approved statements (the report's setting, on by default) and the
 * reconciliation with the trial balance: the totals apart, then each line (opening, approved, difference, closing in
 * the trial balance and in the statements) and each PPE category.
 */
function approvedOpening(ctx, s) {
  const a = s.comparative.approved_opening;
  if (!a || !a.status) return null;
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
  const differ = (a.lines ?? []).filter((l) => l.kind && l.kind !== 'equal');
  const badge = (kind) => el('span', { class: `badge ${kind === 'rounding' ? 'b-warn' : 'b-bad'}` }, ui(kind === 'rounding' ? 'opening_check_rounding' : 'opening_check_difference_badge'));
  return el('div', { 'data-approved-opening-panel': a.status }, el('h3', {}, ui('approved_opening_title')),
    el('label', { style: 'display:block;margin:8px 0' }, toggle, ' ', ui('approved_opening_toggle')),
    message ? box(message[0], message[1]) : null,
    a.status !== 'no_opening' && (a.totals ?? []).some((t) => Math.abs(t.difference) >= 0.5) ? [
      el('h4', {}, ui('approved_opening_totals')),
      el('table', { 'data-approved-opening-totals': '' }, el('tr', {}, el('th', {}), el('th', { class: 'num' }, ui('approved_opening_tb_opening')),
        el('th', { class: 'num' }, ui('approved_opening_approved')), el('th', { class: 'num' }, ui('approved_opening_difference'))),
        a.totals.map((t) => el('tr', { 'data-approved-opening-total': t.part }, el('td', {}, APPROVED_PARTS[t.part]()),
          el('td', { class: 'num' }, money(t.tb_opening)), el('td', { class: 'num' }, money(t.approved)), el('td', { class: 'num' }, money(t.difference))))),
    ] : null,
    differ.length ? [
      el('h4', {}, ui('approved_opening_lines')),
      el('table', { 'data-approved-opening-lines': '' }, el('tr', {}, el('th', {}, ui('file_line')), el('th', { class: 'num' }, ui('approved_opening_tb_opening')),
        el('th', { class: 'num' }, ui('approved_opening_approved')), el('th', { class: 'num' }, ui('approved_opening_difference')),
        el('th', { class: 'num' }, ui('approved_opening_tb_closing')), el('th', { class: 'num' }, ui('approved_opening_presented')), el('th', {})),
        differ.map((l) => el('tr', { 'data-approved-opening-line': l.line }, el('td', {}, l.label,
          l.remainder ? el('div', { class: 'note' }, ui('approved_opening_remainder', { amount: money(l.remainder.amount), item: l.remainder.item })) : null),
          el('td', { class: 'num' }, money(l.tb_opening)), el('td', { class: 'num' }, money(l.approved)), el('td', { class: 'num' }, money(l.difference)),
          el('td', { class: 'num' }, money(l.tb_closing)), el('td', { class: 'num' }, money(l.presented)), el('td', {}, badge(l.kind))))),
    ] : null,
    (a.ppe ?? []).some((p) => p.kind !== 'equal') ? [
      el('h4', {}, ui('approved_opening_ppe')),
      el('table', { 'data-approved-opening-ppe': '' }, a.ppe.filter((p) => p.kind !== 'equal').map((p) => el('tr', {},
        el('td', {}, `${p.label} — ${ui(p.side === 'cost' ? 'ppe_side_cost' : 'ppe_side_accumulated')}`),
        el('td', { class: 'num' }, money(p.tb_opening)), el('td', { class: 'num' }, money(p.approved)), el('td', { class: 'num' }, money(p.difference)), el('td', {}, badge(p.kind))))),
    ] : null,
    status);
}

function fileReview(ctx, s, up) {
  const pdf = up.kind === 'pdf';
  const choices = up.choices ?? { rows: {}, columns: {}, expenses_shown_positive: null };
  const selects = [];   // [ref, select, row]
  const columnSelects = [];
  const blocks = up.blocks.map((b, i) => {
    const targets = (s.statements[b.statement] ?? []).filter((r) => r.type === 'line' || r.type === 'total');
    const colSelect = el('select', { 'data-field': `columns.${i}`, 'data-draft': `columns.${i}` }, b.columns.map((c) => el('option', { value: c.column, selected: (choices.columns?.[i] ?? b.column) === c.column },
      c.header ? `${c.header} (${ui('file_column', { column: columnNumber(c.column) })})` : ui('file_column', { column: columnNumber(c.column) }))));
    if (!b.column_certain && !choices.columns?.[i]) colSelect.prepend(el('option', { value: '', selected: true }, ui('choose_year_column')));
    columnSelects.push([i, colSelect]);
    const amountCells = [];
    const table = el('table', { 'data-file-block': i }, el('tr', {}, el('th', {}, ui(pdf ? 'file_page' : 'file_row')), el('th', {}, ui('file_label')), el('th', { class: 'num' }, ui('file_amount')), el('th', {}, ui('file_line'))),
      b.rows.map((r) => {
        const ref = `${i}:${r.id}`;
        const amountCell = el('td', { class: 'num' });
        const checkCell = el('div');
        amountCells.push([r, amountCell, checkCell]);
        const placed = r.certainty === 'exact' || r.certainty === 'synonym';
        const chosen = choices.rows?.[ref] ?? (placed ? r.match : (r.match ?? ''));
        const select = el('select', { 'data-field': `rows.${ref}`, 'data-file-row': ref, 'data-draft': `rows.${ref}` },
          el('option', { value: '' }, ui('choose_line')),
          targets.map((t) => el('option', { value: t.key, selected: chosen === t.key }, t.type === 'total' ? ui('file_total', { line: t.label_ar }) : t.label_ar)),
          el('option', { value: 'ignore', selected: chosen === 'ignore' }, ui('file_ignore')));
        selects.push([ref, select, r]);
        // A total by its arithmetic the file doesn't place: a check, not an item (R10); it needs no line.
        const badge = r.kind === 'total' && !placed ? ['b-info', 'match_total_check']
          : { exact: ['b-ok', 'match_exact'], synonym: ['b-info', 'match_synonym'], probable: ['b-warn', 'match_probable'], none: ['b-bad', 'match_none'] }[r.certainty];
        return el('tr', { 'data-file-label': r.label },
          el('td', {}, pdf ? el('a', { href: pdfPage(r.page), target: '_blank', rel: 'noopener' }, r.page ?? '') : r.row),
          el('td', {}, r.label, checkCell), amountCell,
          el('td', {}, select, ' ', el('span', { class: `badge ${badge[0]}` }, ui(badge[1]))));
      }));
    const paint = () => {
      const col = colSelect.value || b.column;
      for (const [r, cell, check] of amountCells) {
        const v = r.values[col] ?? { status: 'empty' };
        // A total that doesn't equal the rows the system says it adds up (a PDF): look at the page.
        check.replaceChildren(...(r.check ?? []).filter((c) => c.column === col)
          .map((c) => el('span', { class: 'badge b-warn', 'data-file-check': '' }, ui('file_check', { sum: money(c.sum), printed: money(c.printed) }))));
        cell.replaceChildren(v.status === 'ok' ? money(v.amount) : v.status === 'unread' ? el('span', { class: 'badge b-bad' }, ui('file_unread', { raw: v.raw })) : '');
        cell.closest('tr').classList.toggle('muted', v.status === 'empty');
        cell.closest('tr').querySelector('select').disabled = v.status === 'empty';
      }
    };
    colSelect.addEventListener('change', paint);
    paint();
    const where = pdf ? (up.pages[b.statement] ? pageRange(up.pages[b.statement]) : '') : ui('file_sheet', { sheet: b.sheet_name });
    return el('div', { 'data-file-statement': b.statement }, el('h3', {}, name(`statements.${b.statement}`), where ? el('span', { class: 'note' }, ` — ${where}`) : null),
      field(ui('year_column'), colSelect, b.column_certain ? null : ui('year_column_hint')), table);
  });
  const expenses = up.expenses_shown_positive === true
    ? el('input', { type: 'checkbox', 'data-field': 'expenses_shown_positive', checked: choices.expenses_shown_positive !== false }) : null;
  const node = el('div', { class: 'panel', 'data-file-review': '' },
    el('h2', {}, ui('file_review_title')),
    box(up.in_use ? 'ok' : 'warn', ui(up.in_use ? 'file_in_use' : 'file_waiting', { name: up.name, year: up.year })),
    el('p', { class: 'note' }, ui('file_review_hint'), ' ', el('a', { href: url('synonyms'), target: '_blank' }, ui('edit_synonyms'))),
    // What the reading of the PDF couldn't settle, each with its pages to open (P3).
    (up.issues ?? []).length ? el('div', { 'data-pdf-issues': '' }, el('h3', {}, ui('pdf_issues_title')),
      el('ul', {}, up.issues.map((i) => el('li', { 'data-pdf-issue': i.code },
        el('span', { class: `badge ${i.severity === 'error' ? 'b-bad' : 'b-warn'}` }, name(`severities.${i.severity}`)), ' ', i.message, ' ',
        i.pages.map((p) => el('a', { href: pdfPage(p), target: '_blank', rel: 'noopener', style: 'margin-inline-start:6px' }, ui('pdf_open_page', { page: p }))),
        wordingButtons(ctx, i))))) : null,
    openingCheck(up, pdf),
    up.in_use ? approvedOpening(ctx, s) : null,
    comparable(ctx, s, up),
    companyFacts(ctx, s, up),
    expenses ? el('label', { style: 'display:block;margin:8px 0' }, expenses, ' ', ui('expenses_positive')) : null,
    blocks);
  const collect = () => {
    const out = { rows: {}, columns: {} };
    for (const [ref, select, r] of selects) {
      if (select.disabled || !select.value) continue;
      if ((r.certainty === 'exact' || r.certainty === 'synonym') && select.value === r.match && !(ref in (choices.rows ?? {}))) continue;
      out.rows[ref] = select.value;
    }
    for (const [i, select] of columnSelects) if (select.value) out.columns[i] = select.value;
    if (expenses) out.expenses_shown_positive = expenses.checked;
    return out;
  };
  return { node, collect };
}

export async function render(ctx) {
  clearTimeout(poll);
  const s = ctx.state;
  const cmp = s.comparative;
  const entry = cmp.entry;
  // A file waiting for review keeps its choice open (PR 17).
  let source = cmp.upload && !cmp.upload.in_use ? 'statements' : cmp.source;
  const wrap = el('div');

  // Differences between this year's opening and last year's closing, and other comparative checks.
  const issues = s.checks.filter((c) => c.scope === 'comparative');
  if (issues.length) {
    wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('prior_issues')),
      el('table', {}, issues.map((c) => el('tr', {},
        el('td', {}, el('span', { class: `badge ${c.severity === 'error' ? 'b-bad' : 'b-warn'}` }, name(`severities.${c.severity}`))),
        el('td', {}, checkTitle(c)), el('td', {}, checkMessage(c)))))));
  }

  // Choices loaded once: earlier reports of the company, finished runs.
  let reports = [];
  let runs = [];
  try {
    const list = await api('GET', `/financial-reports?per_page=100${s.company_id ? `&company_id=${encodeURIComponent(s.company_id)}` : ''}`);
    reports = list.data.filter((r) => r.id !== s.id && r.period_end < s.period_start);
    runs = (await api('GET', '/classify/jobs?status=done&per_page=100')).data.filter((r) => r.id !== s.classification_run_id);
  } catch { /* the selects stay empty */ }

  const form = el('form');
  const errors = el('div');
  const saved = el('div');
  // Why the prior year is asked for, or that it can't be told (R8).
  const why = cmp.opening_balances === 'present' ? ui(`opening_signal_${cmp.opening_signal}`) : (cmp.opening_balances === 'unknown' ? ui('opening_signal_unknown') : null);
  const choices = el('div', { class: 'panel' }, el('h2', {}, ui('prior_source')),
    why ? el('p', { class: 'note', 'data-opening-signal': cmp.opening_signal ?? 'unknown' }, why) : null,
    SOURCES.map((k) => el('label', { style: 'display:block;margin:6px 0;cursor:pointer' },
      el('input', { type: 'radio', name: 'source', value: k, checked: k === source, onchange: () => { source = k; draw(); } }), ' ',
      el('b', {}, name(`prior_sources.${k}`)), ' — ', el('span', { class: 'note' }, ui(`source_hint_${k}`)))));
  const details = el('div');
  form.append(choices, details, errors, saved, el('button', { class: 'btn', type: 'submit' }, ui('save_prior_year')));
  // R8: no opening balances in the trial balance and no prior year chosen: a first year, nothing to give; the form only
  // when the user says there is a prior year.
  if (cmp.automatic) {
    form.hidden = true;
    wrap.append(el('div', { class: 'panel', 'data-prior-not-needed': '' }, box('ok', ui('prior_not_needed')),
      el('button', { class: 'btn ghost', type: 'button', 'data-prior-anyway': '', onclick: (e) => { form.hidden = false; e.currentTarget.remove(); } }, ui('prior_anyway'))));
  }
  wrap.append(form);

  let collect = () => ({});

  function draw() {
    details.replaceChildren();
    saved.replaceChildren();
    const parts = [];
    const grids = [];   // [prefix, inputs]
    const kept = {};    // prefix → cells saved before and not shown, sent back as they were

    if (source === 'report') {
      const select = el('select', { 'data-field': 'prior_report_id' }, el('option', { value: '' }, ui('choose_report')),
        reports.map((r) => el('option', { value: r.id, selected: r.id === cmp.prior_report_id }, `${day(r.period_start)} → ${day(r.period_end)} (${name(`version_kinds.${r.status}`)})`)));
      parts.push(el('div', { class: 'panel' }, field(ui('prior_report'), select, reports.length ? null : ui('no_prior_reports'))));
      collect = () => ({ prior_report_id: select.value || null });
    }

    // A file of last year's issued statements (PR 17): uploaded, then reviewed below.
    let review = null;
    if (source === 'statements') {
      const input = el('input', { type: 'file', accept: '.xlsx,.xls,.pdf', 'data-field': 'file' });
      const status = el('div');
      const send = el('button', { class: 'btn ghost', type: 'button', 'data-upload-prior': '', onclick: async () => {
        if (!input.files.length) return;
        status.replaceChildren(box('warn', ui('file_reading')));
        try {
          const state = await ctx.save('POST', '/prior-year/statements-file', undefined, input.files[0]);
          ctx.flash = state.comparative.upload?.kind === 'pdf' ? null : ui('file_read');   // a PDF's pages come first
          ctx.redraw();
        } catch (err) {
          showErrors(form, err, status);
        }
      } }, ui('upload_file'));
      // Or the statements the client already uploaded in the portal (the accountant picks, nothing is uploaded again).
      const pickHolder = el('div');
      let picked = null;
      const useFile = el('button', { class: 'btn ghost small', type: 'button', 'data-use-client-file': '', hidden: true, onclick: async () => {
        status.replaceChildren(box('warn', ui('file_reading')));
        try {
          const state = await ctx.save('POST', '/prior-year/statements-file', { client_file_id: picked.id });
          ctx.flash = state.comparative.upload?.kind === 'pdf' ? null : ui('file_read');
          ctx.redraw();
        } catch (err) {
          showErrors(form, err, status);
        }
      } }, ui('pick_use'));
      if (s.company) {
        clientFilePicker({ companyId: s.company.id, purpose: 'prior_statements', selectedId: null,
          year: s.comparative_end ? Number(s.comparative_end.slice(0, 4)) : Number(s.period_start.slice(0, 4)) - 1,   // the period the statements are of
          labels: { title: ui('pick_title'), loading: ui('pick_loading'), none: ui('pick_none_statements'), error: ui('pick_error'), fine: ui('pick_fine'), remarks: ui('pick_remarks'), suggested: ui('pick_suggested') },
          onPick: (f) => { picked = f; useFile.hidden = false; } }).then((node) => pickHolder.replaceChildren(node, useFile));
      }
      parts.push(el('div', { class: 'panel' }, field(ui('prior_file'), input, ui('prior_file_hint')), send, pickHolder, status));
      if (cmp.upload?.kind === 'pdf' && !cmp.upload.in_use) parts.push(pdfPanel(ctx, cmp.upload));
      if (cmp.upload?.blocks.length) {
        review = fileReview(ctx, s, cmp.upload);
        parts.push(review.node);
      }
    }

    if (source === 'run' || source === 'manual' || source === 'statements') {
      const panel = el('div', { class: 'panel' });
      if (source === 'run') {
        const select = el('select', { 'data-field': 'prior_run_id' }, el('option', { value: '' }, ui('choose_run')),
          runs.map((r) => el('option', { value: r.id, selected: r.id === cmp.prior_run_id }, `${r.original_name} — ${day(r.created_at)}`)));
        panel.append(field(ui('prior_run'), select, runs.length ? null : ui('no_runs')));
      }
      const cs = el('input', { type: 'date', 'data-field': 'comparative_start', 'data-draft': 'comparative_start', value: cmp.source === source ? s.comparative_start ?? '' : '' });
      const ce = el('input', { type: 'date', 'data-field': 'comparative_end', 'data-draft': 'comparative_end', value: cmp.source === source ? s.comparative_end ?? '' : '' });
      panel.append(el('div', { class: 'row' }, field(ui('comparative_start'), cs), field(ui('comparative_end'), ce)), el('p', { class: 'note' }, ui('comparative_dates_hint')));
      parts.push(panel);

      const fp = s.statements.financial_position;
      const is = s.statements.comprehensive_income;
      const sameSource = cmp.source === source;
      // Statement lines: all of them typed (manual); OCI only with a prior trial balance.
      if (source !== 'statements') {
        const lineKeys = source === 'manual' ? entry.figures : entry.figures.filter((k) => k.startsWith('is.oci_'));
        const g1 = linesGrid([...fp, ...is], lineKeys, sameSource ? cmp.figures : {}, 'figures');
        grids.push(['figures', g1.inputs]);
        parts.push(el('details', { class: 'panel', open: source === 'manual' }, el('summary', {}, ui(source === 'manual' ? 'grid_statements' : 'grid_oci')), g1.node));
      }

      // Changes in equity: row × column.
      const soce = s.statements.changes_in_equity;
      const soceInputs = [];
      const rowLabel = (k) => soce.current.find((r) => r.key === k)?.label_ar ?? ui('unknown');
      const colLabel = (k) => soce.columns.find((c) => c.key === k)?.label_ar ?? ui('unknown');
      const cols = entry.changes_in_equity.columns;
      const fromPdf = source === 'statements' && cmp.upload?.equity && !(sameSource && Object.keys(cmp.changes_in_equity ?? {}).length);
      parts.push(el('details', { class: 'panel', open: !!fromPdf, 'data-equity-grid': '' }, el('summary', {}, ui('grid_changes_in_equity')),
        fromPdf ? el('p', { class: 'note', 'data-equity-from-pdf': '' }, ui('equity_from_pdf', { pages: cmp.upload.equity.pages.join('، ') })) : null,
        el('div', { style: 'overflow-x:auto' }, el('table', { class: 'grid-table' },
          el('tr', {}, el('th', {}), cols.map((c) => el('th', {}, colLabel(c)))),
          entry.changes_in_equity.rows.map((r) => el('tr', {}, el('td', {}, rowLabel(r)), cols.map((c) => {
            // As typed; else, with a PDF read, last year's block read from it (the accountant checks and saves it).
            const typed = sameSource && Object.keys(cmp.changes_in_equity ?? {}).length ? cmp.changes_in_equity?.[r]?.[c] : null;
            const read = source === 'statements' && !(sameSource && Object.keys(cmp.changes_in_equity ?? {}).length) ? cmp.upload?.equity?.rows?.[r]?.[c] : null;
            const input = amount(`changes_in_equity.${r}.${c}`, typed ?? read, `changes_in_equity.${r}.${c}`);
            soceInputs.push([[r, c], input]);
            return el('td', {}, input);
          })))))));
      grids.push(['changes_in_equity', soceInputs]);

      // The cash flow is typed unless the file has it.
      if (!(source === 'statements' && cmp.upload?.blocks.some((b) => b.statement === 'cash_flows'))) {
        const g3 = linesGrid(s.statements.cash_flows, entry.cash_flows, sameSource ? cmp.cash_flows : {}, 'cash_flows');
        grids.push(['cash_flows', g3.inputs]);
        parts.push(el('details', { class: 'panel' }, el('summary', {}, ui('grid_cash_flows')), g3.node));
      }

      // Notes' item tables (manual, or with a file): this year's classes, plus others typed by name.
      if (source === 'manual' || source === 'statements') {
        const noteParts = [];
        const noteInputs = [];
        for (const [note, classes] of Object.entries(entry.notes)) {
          // Last year's figures the note uses: read from the PDF (shown, not typed: the PDF's lines are the note's), else
          // typed, or from the approved line, to check or correct.
          const inUse = entry.notes_prior?.[note] ?? { read_only: false, page: null, values: {} };
          if (inUse.read_only) {
            noteParts.push(el('h3', {}, name(`notes.${note}`)), el('p', { class: 'note', 'data-note-from-pdf': note }, ui('grid_note_from_pdf', { page: inUse.page ?? '' })),
              el('table', { class: 'grid-table' }, classes.map((k) => el('tr', {}, el('td', {}, k), el('td', { 'data-note-value': '' }, k in inUse.values ? money(inUse.values[k]) : '')))));
            continue;
          }
          const typed = (sameSource ? cmp.notes?.[note] : null) ?? {};
          const keys = [...new Set([...classes, ...Object.keys(typed)])];
          const table = el('table', { class: 'grid-table' }, keys.map((k) => {
            const input = amount(`notes.${note}`, typed[k] ?? inUse.values[k] ?? null, `notes.${note}.${k}`);
            // A figure shown from what's in use (not typed) is saved only when the user changes it: it never becomes
            // «typed» by a save of the step, which would put it above last year's approved figures.
            if (!(k in typed) && k in inUse.values) input.dataset.prefill = String(inUse.values[k]);
            noteInputs.push([[note, k], input]);
            return el('tr', {}, el('td', {}, k), el('td', {}, input));
          }));
          const extraName = el('input', { placeholder: ui('other_class_name'), 'data-draft': `notes.${note}.@name` });
          const extraAmount = amount(`notes.${note}`, null, `notes.${note}.@amount`);
          noteInputs.push([[note, () => extraName.value.trim()], extraAmount]);
          table.append(el('tr', {}, el('td', {}, extraName), el('td', {}, extraAmount)));
          noteParts.push(el('h3', {}, name(`notes.${note}`)), table);
          if (note === 'cost_of_revenue') {
            const up = (sameSource ? cmp.notes?.cost_of_revenue_build_up : null) ?? {};
            noteParts.push(el('p', { class: 'note' }, ui('build_up_hint')), el('table', { class: 'grid-table' }, entry.build_up.map((p) => {
              const input = amount('notes.cost_of_revenue_build_up', up[p], `notes.cost_of_revenue_build_up.${p}`);
              noteInputs.push([['cost_of_revenue_build_up', p], input]);
              return el('tr', {}, el('td', {}, name(`build_up.${p}`)), el('td', {}, input));
            })));
          }
        }
        grids.push(['notes', noteInputs]);
        parts.push(el('details', { class: 'panel', 'data-notes-grid': '' }, el('summary', {}, ui('grid_notes')), el('p', { class: 'note' }, ui('grid_notes_hint')), noteParts));
      }

      // Movement tables (run / manual): every row but the closing ones (this year's opening), each with the figure in use
      // (typed, or last year's PPE note), saved only when changed. Last year's PPE movement read from the PDF by its own
      // categories: shown as read, not typed (a cell typed before is kept as it was).
      if (entry.schedules.length) {
        const typed = (sameSource ? cmp.schedules : null) ?? {};
        const inUse = entry.schedules_prior ?? {};
        const pdfPpe = source === 'statements' && entry.ppe_from_pdf && s.ppe_prior;
        const schInputs = entry.schedules.filter((k) => !(pdfPpe && k.startsWith('ppe.'))).map((k) => {
          const input = amount(`schedules.${k}`, typed[k] ?? inUse[k] ?? null, `schedules.${k}`);
          if (!(k in typed) && k in inUse) input.dataset.prefill = String(inUse[k]);
          return [k, input];
        });
        grids.push(['schedules', schInputs]);
        if (pdfPpe) kept.schedules = Object.fromEntries(Object.entries(typed).filter(([k]) => k.startsWith('ppe.')));
        parts.push(el('details', { class: 'panel', 'data-schedules-grid': '', open: !!pdfPpe }, el('summary', {}, ui('grid_schedules')), el('p', { class: 'note' }, ui('grid_schedules_hint')),
          pdfPpe ? ppeFromPdf(s, entry.ppe_from_pdf.page) : null,
          schInputs.length ? el('table', { class: 'grid-table' }, schInputs.map(([k, input]) => el('tr', {}, el('td', {}, cellLabel(s, k) ?? ui('unknown')), el('td', {}, input)))) : null));
      }

      collect = (bad) => {
        const out = { comparative_start: cs.value || null, comparative_end: ce.value || null, ...(review ? review.collect() : {}) };
        if (source === 'run') out.prior_run_id = form.querySelector('[data-field=prior_run_id]').value || null;
        for (const [prefix, inputs] of grids) {
          if (prefix === 'changes_in_equity' || prefix === 'notes') {
            const nested = {};
            for (const [[a, b], input] of inputs) {
              const v = numberOf(input);
              if (v === null) continue;
              if (Number.isNaN(v)) { bad.push(input); continue; }
              if (input.dataset.prefill !== undefined && v === Number(input.dataset.prefill)) continue;
              const key = typeof b === 'function' ? b() : b;
              if (!key) { bad.push(input); continue; }
              (nested[a] ??= {})[key] = v;
            }
            if (Object.keys(nested).length) out[prefix] = nested;
          } else {
            const flat = { ...(kept[prefix] ?? {}), ...read(inputs, bad) };
            if (Object.keys(flat).length || (prefix === 'figures' && source === 'manual')) out[prefix] = flat;
          }
        }
        return out;
      };
    }

    if (source === 'none') collect = () => ({});
    // A first year although the trial balance has opening balances: said, and sent as the user's override (R8).
    if (source === 'first_year') {
      collect = () => (cmp.opening_balances === 'present' ? { skip_opening: true } : {});
      if (cmp.opening_balances === 'present') parts.push(box('warn', ui('first_year_with_opening')));
    }

    // Restated comparatives (Q9): with any source that gives them.
    if (['report', 'run', 'manual', 'statements'].includes(source)) {
      const restated = el('input', { type: 'checkbox', checked: cmp.restated });
      const reason = el('textarea', { 'data-field': 'restatement_note' }, cmp.restatement_note ?? '');
      const reasonField = field(ui('restatement_note'), reason, ui('restatement_hint'));
      reasonField.style.display = cmp.restated ? '' : 'none';
      restated.addEventListener('change', () => { reasonField.style.display = restated.checked ? '' : 'none'; });
      parts.push(el('div', { class: 'panel' }, el('label', {}, restated, ' ', ui('restated')), reasonField));
      const inner = collect;
      collect = (bad) => ({ ...inner(bad), restated: restated.checked, ...(restated.checked ? { restatement_note: reason.value.trim() } : {}) });
    }
    details.replaceChildren(...parts);
    // What was typed and not saved yet comes back (a save elsewhere in the step drew it again).
    const unsaved = drafts.values[source] ?? {};
    let restored = 0;
    for (const input of form.querySelectorAll('[data-draft]')) {
      if (input.dataset.draft in unsaved && input.value !== unsaved[input.dataset.draft]) {
        input.value = unsaved[input.dataset.draft];
        input.dispatchEvent(new Event('change'));
        restored++;
      }
    }
    if (restored) saved.replaceChildren(box('warn', ui('draft_unsaved', { count: restored })));
  }

  if (drafts.report !== C.report_id) drafts = { report: C.report_id, values: {} };
  const remember = (e) => {
    const key = e.target.dataset?.draft;
    if (key && e.isTrusted) (drafts.values[source] ??= {})[key] = e.target.value;
  };
  form.addEventListener('input', remember);
  form.addEventListener('change', remember);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    saved.replaceChildren();
    const bad = [];
    const body = { source, ...collect(bad) };
    if (bad.length) {
      showErrors(form, { message: ui('numbers_invalid'), errors: {} }, errors);
      bad.forEach((i) => i.classList.add('invalid'));
      return;
    }
    try {
      await ctx.save('PUT', '/prior-year', body);
      drafts.values = {};
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) {
      showErrors(form, err, errors);
    }
  });

  draw();
  wrap.append(el('p', { class: 'note' }, ui('prior_current', { source: name(`prior_sources.${cmp.source}`) }),
    s.comparative_end ? ` (${day(s.comparative_start)} → ${day(s.comparative_end)})` : ''));
  wrap.append(el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', onclick: () => ctx.go('company') }, ui('prev_step')),
    el('button', { class: 'btn', onclick: () => ctx.go('accounts') }, ui('next_step'))));
  return wrap;
}
