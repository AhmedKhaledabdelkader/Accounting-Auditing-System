// Step 5 (PR 13b): the questions the notes ask (approval, partners, policies, useful lives, related parties, events, going
// concern, …), each note shown or hidden (Q13), the policies forced on or off, and any paragraph reworded (Q11). The notes
// read as they will print: numbered, «[ناقص]» where a value is still missing.
import { C, api, el, ui, label, name, box, money, runRows, showErrors, checkMessage } from '../core.js';
import { fieldsForm, NOTE_FIELDS } from '../fields.js';

function visibilitySelect(current, attrs = {}) {
  const value = current === 'forced_on' ? 'on' : current === 'forced_off' ? 'off' : 'auto';
  return el('select', attrs, ['auto', 'on', 'off'].map((v) => el('option', { value: v, selected: v === value }, ui(`visibility_${v}`))));
}

function noteTable(b) {
  return el('div', { style: 'overflow-x:auto' }, el('table', { class: 'grid-table' }, b.rows.map((r) => el('tr', {},
    r.cells.map((c) => (c.amount ? el('td', { class: 'num' }, money(c.amount.amount)) : el('td', {}, c.text_ar)))))));
}

export async function render(ctx) {
  const s = ctx.state;
  const byRow = await runRows();
  const intake = (await api('GET', `/financial-reports/${encodeURIComponent(C.report_id)}/intake`)).fields;
  const wrap = el('div');
  const status = el('div');
  wrap.append(status);

  /** Saved; a refusal said next to what was saved (`at`), else at the top. */
  async function saveNotes(body, at = null) {
    const where = at ?? status;
    status.replaceChildren();
    where.replaceChildren();
    try {
      await ctx.save('PATCH', '/notes', body);
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) {
      where.append(box('bad', err.all().join(' ')));
      where.scrollIntoView({ block: 'center' });
    }
  }
  const near = () => el('div', { 'data-save-errors': '' });

  // Going concern (Q14): the indicators hit; the paragraph's decision is among the questions below.
  if (s.going_concern.length) {
    wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('going_concern_title')),
      el('ul', {}, s.going_concern.map((g) => el('li', {}, name(`going_concern.${g.key}`), ': ', money(g.amount)))),
      el('p', { class: 'note' }, ui('going_concern_hint'))));
  }

  // A. Questions.
  const fields = intake.filter((f) => NOTE_FIELDS.test(f.key));
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('note_questions')),
    fields.length ? fieldsForm(fields, byRow, async (patch) => {
      await ctx.save('PATCH', '/inputs', { inputs: patch });
      ctx.flash = ui('saved');
      ctx.redraw();
    }) : el('p', { class: 'note' }, ui('no_questions'))));

  // Last year's note lines (R11, R12): each account of this year on one of them, or a new item («-» last year).
  const itemNotes = Object.entries(s.note_items ?? {});
  if (itemNotes.length) {
    const byLabel = { choice: () => ui('item_by_choice'), name: () => ui('item_by_name'), amount: () => ui('item_by_amount'), words: () => ui('item_by_words'), new: () => ui('item_by_new') };
    const why = (by) => el('span', { class: `badge ${by === 'new' || by === 'words' ? 'b-warn' : 'b-info'}` }, byLabel[by]());
    const choices = [];
    const itemErrors = near();
    wrap.append(el('details', { class: 'panel', 'data-note-items': '' }, el('summary', {}, ui('note_items_title')), el('p', { class: 'note' }, ui('note_items_hint')),
      itemNotes.map(([key, n]) => el('div', { 'data-note-items-of': key }, el('h3', {}, ui('note_items_note', { note: n.note ?? '', title: n.title, page: n.page })),
        el('table', {}, el('tr', {}, el('th', {}, ui('item_account')), el('th', { class: 'num' }, ui('item_current')), el('th', {}, ui('item_line')), el('th', {})),
          n.accounts.map((a) => {
            // A difference from last year's approved closing (Step B): placed by it, not an account to move.
            if (a.approved_opening) return el('tr', { 'data-approved-opening-row': a.ref }, el('td', {}, a.label), el('td', { class: 'num' }, money(a.current)),
              el('td', {}, n.items.find((i) => i.key === a.item)?.label ?? ui('item_new')), el('td', {}, el('span', { class: 'badge b-info' }, ui('item_by_approved_opening'))));
            const sel = el('select', { 'data-item-account': a.ref },
              n.items.map((i) => el('option', { value: i.key, selected: a.item === i.key }, `${i.label} (${money(i.prior)})`)),
              el('option', { value: 'new', selected: a.item === 'new' }, ui('item_new')));
            choices.push([key, a, sel]);
            return el('tr', {}, el('td', {}, a.label), el('td', { class: 'num' }, money(a.current)), el('td', {}, sel), el('td', {}, why(a.by)));
          })),
        el('p', { class: 'note' }, ui('note_items_lines', { lines: n.items.map((i) => i.label).join('، ') })))),
      el('button', { class: 'btn', type: 'button', 'data-save-items': '', onclick: () => {
        const items = {};
        for (const [note, a, sel] of choices) if (sel.value !== a.item) (items[note] ??= {})[a.ref] = sel.value;
        if (Object.keys(items).length) saveNotes({ items }, itemErrors); else itemErrors.replaceChildren(box('warn', ui('nothing_changed')));
      } }, ui('save')), itemErrors));
  }

  // The PPE note by last year's categories (R13): each with its rate and life, this year's opening against last year's
  // closing; each account on a category (searchable), saved together.
  if (s.ppe_prior) {
    const pp = s.ppe_prior;
    const sides = { cost: () => ui('ppe_side_cost'), accumulated: () => ui('ppe_side_accumulated') };
    const byLabel = { choice: () => ui('item_by_choice'), name: () => ui('item_by_name'), amount: () => ui('item_by_amount'), words: () => ui('item_by_words'), none: () => ui('ppe_by_none') };
    const kinds = { equal: () => ui('ppe_check_equal'), rounding: () => ui('opening_check_rounding'), difference: () => ui('opening_check_difference_badge') };
    const choices = [];
    const ppeErrors = near();
    wrap.append(el('details', { class: 'panel', 'data-ppe-prior': '' }, el('summary', {}, ui('ppe_prior_title')), el('p', { class: 'note' }, ui('ppe_prior_hint')),
      el('table', {}, el('tr', {}, el('th', {}, ui('ppe_category')), el('th', { class: 'num' }, ui('ppe_rate')), el('th', { class: 'num' }, ui('ppe_life')), el('th', {}, ui('ppe_opening_check'))),
        pp.categories.map((c) => el('tr', { 'data-ppe-category': c.key }, el('td', {}, c.label), el('td', { class: 'num' }, c.rate ?? '—'), el('td', { class: 'num' }, c.life ?? '—'),
          el('td', {}, c.opening_check.map((x) => el('span', { class: `badge ${x.kind === 'equal' ? 'b-ok' : x.kind === 'rounding' ? 'b-ok' : 'b-bad'}`, style: 'margin-inline-end:6px' },
            `${sides[x.side]()}: ${kinds[x.kind]()}${x.kind === 'equal' ? '' : ` (${money(x.difference)})`}`)))))),
      el('h3', {}, ui('ppe_accounts')),
      el('table', {}, pp.accounts.map((a) => {
        if (a.approved_opening) return el('tr', { 'data-approved-opening-row': a.row }, el('td', {}, a.name), el('td', {}, sides[a.side]()), el('td', {}, a.label ?? ui('ppe_no_category')),
          el('td', {}, el('span', { class: 'badge b-info' }, ui('item_by_approved_opening'))));
        const sel = el('select', { 'data-ppe-account': a.row }, pp.categories.map((c) => el('option', { value: c.key, selected: a.category === c.key }, c.label)),
          el('option', { value: 'none', selected: a.category === null }, ui('ppe_no_category')));
        choices.push([a, sel]);
        return el('tr', {}, el('td', {}, a.name), el('td', {}, sides[a.side]()), el('td', {}, sel),
          el('td', {}, el('span', { class: `badge ${a.by === 'none' || a.by === 'words' ? 'b-warn' : 'b-info'}` }, byLabel[a.by]())));
      })),
      el('button', { class: 'btn', type: 'button', 'data-save-ppe': '', onclick: () => {
        const ppe = {};
        for (const [a, sel] of choices) if (sel.value !== (a.category ?? 'none')) ppe[a.row] = sel.value;
        if (Object.keys(ppe).length) saveNotes({ ppe }, ppeErrors); else ppeErrors.replaceChildren(box('warn', ui('nothing_changed')));
      } }, ui('save')), ppeErrors));
  }

  // The zakat base as last year's zakat note computed it: this year's figures, the ones to type among the questions.
  if (s.zakat_base) {
    const from = { statements: () => ui('zakat_from_statements'), schedules: () => ui('zakat_from_schedules'), input: () => ui('zakat_from_input'), total: () => ui('zakat_from_total'),
      derived: () => ui('zakat_from_derived') };
    wrap.append(el('details', { class: 'panel', 'data-zakat-base': '' }, el('summary', {}, ui('zakat_base_title', { note: s.zakat_base.note ?? '', page: s.zakat_base.page })),
      el('table', {}, el('tr', {}, el('th', {}), el('th', { class: 'num' }, ui('item_current')), el('th', { class: 'num' }, ui('zakat_prior')), el('th', {})),
        s.zakat_base.rows.map((r) => el('tr', { style: r.kind === 'total' ? 'font-weight:700' : '' }, el('td', {}, r.label),
          el('td', { class: 'num' }, r.current.status === 'missing' ? ui('missing_figure') : money(r.current.amount)), el('td', { class: 'num' }, money(r.prior)),
          el('td', {}, el('span', { class: 'note' }, from[r.from](), r.components ? ` (${r.components.join(' + ')})` : '')))))));
  }

  // B. Policies forced on or off.
  const forced = s.note_options.visibility ?? {};
  const policyErrors = near();
  const policies = Object.keys(C.labels.policies ?? {}).map((k) => [k, visibilitySelect(forced[k] === 'on' ? 'forced_on' : forced[k] === 'off' ? 'forced_off' : 'auto', { 'data-policy': k })]);
  wrap.append(el('details', { class: 'panel' }, el('summary', {}, ui('policies_title')), el('p', { class: 'note' }, ui('policies_hint')),
    el('table', {}, policies.map(([k, sel]) => el('tr', {}, el('td', {}, name(`policies.${k}`)), el('td', {}, sel)))),
    el('button', { class: 'btn', type: 'button', onclick: () => {
      const visibility = {};
      for (const [k, sel] of policies) {
        const before = forced[k] ?? 'auto';
        if (sel.value !== before) visibility[k] = sel.value;
      }
      if (Object.keys(visibility).length) saveNotes({ visibility }, policyErrors); else policyErrors.replaceChildren(box('warn', ui('nothing_changed')));
    } }, ui('save_policies')), policyErrors));

  // C. The notes, in order.
  const notes = el('div', { class: 'panel' }, el('h2', {}, ui('notes_title')), el('p', { class: 'note' }, ui('notes_hint')));
  for (const n of s.notes) {
    const head = el('div', { class: 'toolbar', style: 'margin:0' },
      el('b', {}, n.visible ? `${n.number}. ` : '', n.title_ar || name(`notes.${n.key}`)),
      (s.checks ?? []).some((c) => c.note === n.key && c.severity === 'error') ? el('span', { class: 'badge b-bad' }, ui('note_has_error')) : null,
      el('span', { class: `badge ${n.visible ? 'b-ok' : 'b-info'}` }, n.visible ? ui('note_shown') : ui('note_hidden')),
      el('span', { class: 'note' }, label(`visibility.${n.visibility}`) ?? ''));
    const body = el('div');
    // The note's own errors and warnings, in it: next to the problem, not only on the review.
    const own = (s.checks ?? []).filter((c) => c.note === n.key && c.severity !== 'info');
    if (own.length) body.append(el('div', { 'data-note-checks': '' }, own.map((c) => box(c.severity === 'error' ? 'bad' : 'warn', checkMessage(c)))));
    const noteErrors = near();
    if (n.visibility !== 'always') {
      const sel = visibilitySelect(n.visibility, { 'data-note': n.key });
      body.append(el('div', { class: 'toolbar' }, el('label', { class: 'note' }, ui('note_visibility')), sel,
        el('button', { class: 'btn ghost small', type: 'button', onclick: () => saveNotes({ visibility: { [n.key]: sel.value } }, noteErrors) }, ui('save'))), noteErrors);
    }
    for (const b of n.blocks) {
      if (b.type === 'table') { body.append(noteTable(b)); continue; }
      const p = el('p', { style: `margin:6px 0;line-height:1.9;${b.style === 'heading' ? 'font-weight:800' : ''}` }, b.number ? `${b.number} ${b.text_ar}` : b.text_ar,
        b.edited ? el('span', { class: 'badge b-info' }, ui('text_edited')) : null,
        b.outdated ? el('span', { class: 'badge b-warn' }, ui('text_outdated')) : null);
      const edit = el('button', { class: 'btn ghost small', type: 'button', 'data-paragraph': b.key, onclick: () => {
        const area = el('textarea', { 'data-field': `texts.${b.key}`, style: 'width:100%;min-height:90px' }, b.text_ar);
        const errors = el('div');
        const form = el('form', {}, area,
          b.outdated ? el('p', { class: 'note' }, ui('template_now'), ' ', b.template_ar) : null,
          errors,
          el('div', { class: 'toolbar' }, el('button', { class: 'btn small', type: 'submit' }, ui('save')),
            b.edited ? el('button', { class: 'btn ghost small', type: 'button', onclick: () => saveNotes({ texts: { [b.key]: null } }, errors) }, ui('back_to_template')) : null,
            el('button', { class: 'btn ghost small', type: 'button', onclick: () => form.replaceWith(row) }, ui('cancel'))));
        form.addEventListener('submit', (e) => {
          e.preventDefault();
          if (area.value.trim().length < 3) { showErrors(form, { message: ui('text_too_short'), errors: {} }, errors); return; }
          saveNotes({ texts: { [b.key]: area.value.trim() } }, errors);
        });
        row.replaceWith(form);
        area.focus();
      } }, ui('reword'));
      const row = el('div', { class: 'toolbar', style: 'align-items:flex-start;flex-wrap:nowrap;margin:0' }, el('div', { style: 'flex:1' }, p), edit);
      body.append(row);
    }
    if (n.visible && !n.blocks.length) body.append(el('p', { class: 'note' }, ui('note_empty')));
    notes.append(el('details', { open: false, 'data-note-key': n.key }, el('summary', {}, head), body));
  }
  wrap.append(notes);

  wrap.append(el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', onclick: () => ctx.go('zakat') }, ui('prev_step')),
    el('button', { class: 'btn', onclick: () => ctx.go('review') }, ui('next_step'))));
  return wrap;
}
