// Step 1 (PR 13a): the company (picked, or its profile edited: the notes read it) and the period; the template edition is fixed.
import { api, upload, el, ui, label, day, box, field, showErrors, exact } from '../core.js';
import { clientFilePicker } from '../../client-file-picker.js';
import { companyPicker } from '../company-picker.js';

// Every UI word a literal (the words test reads them).
const GROUP_WORDS = { saudi: 'articles_group_saudi', gulf: 'articles_group_gulf', foreign: 'articles_group_foreign', unknown: 'articles_group_unknown' };
const TEXT = ['name_ar', 'name_en', 'cr_number', 'cr_date_hijri', 'unified_number', 'city', 'head_office'];

function profileForm(ctx, company) {
  const inputs = {};
  for (const k of TEXT) inputs[k] = el('input', { 'data-field': k, value: company[k] ?? '' });
  inputs.cr_date = el('input', { type: 'date', 'data-field': 'cr_date', value: company.cr_date ?? '' });
  inputs.activity = el('textarea', { 'data-field': 'activity' }, company.activity ?? '');
  inputs.branches = el('textarea', { 'data-field': 'branches' }, (company.branches ?? []).join('\n'));
  inputs.fiscal_year_end_month = el('select', { 'data-field': 'fiscal_year_end_month' },
    Array.from({ length: 12 }, (_, i) => el('option', { value: i + 1, selected: company.fiscal_year_end_month === i + 1 }, ui(`month_${i + 1}`))));
  inputs.fiscal_year_end_day = el('input', { type: 'number', min: 1, max: 31, 'data-field': 'fiscal_year_end_day', value: company.fiscal_year_end_day });

  const errors = el('div');
  const form = el('form', { onsubmit: async (e) => {
    e.preventDefault();
    if (!inputs.name_ar.value.trim()) { showErrors(form, { message: ui('company_name_required'), errors: {} }, errors); return; }
    const body = {};
    for (const k of [...TEXT, 'cr_date']) body[k] = inputs[k].value.trim() || null;
    body.activity = inputs.activity.value.trim() || null;
    body.branches = inputs.branches.value.split('\n').map((b) => b.trim()).filter(Boolean);
    body.fiscal_year_end_month = Number(inputs.fiscal_year_end_month.value);
    body.fiscal_year_end_day = Number(inputs.fiscal_year_end_day.value);
    try {
      await api('PATCH', `/companies/${encodeURIComponent(company.id)}`, body);
      await ctx.reload();
      ctx.flash = ui('saved');
      ctx.redraw();
    } catch (err) { showErrors(form, err, errors); }
  } },
  el('div', { class: 'row' }, field(ui('company_name_ar'), inputs.name_ar), field(ui('company_name_en'), inputs.name_en)),
  el('div', { class: 'row' }, field(ui('company_cr_number'), inputs.cr_number), field(ui('company_cr_date_hijri'), inputs.cr_date_hijri, ui('company_cr_date_hijri_hint')),
    field(ui('company_cr_date'), inputs.cr_date)),
  el('div', { class: 'row' }, field(ui('company_unified_number'), inputs.unified_number), field(ui('company_city'), inputs.city), field(ui('company_head_office'), inputs.head_office)),
  field(ui('company_activity'), inputs.activity),
  field(ui('company_branches'), inputs.branches, ui('company_branches_hint')),
  el('div', { class: 'row' }, field(ui('company_year_end_month'), inputs.fiscal_year_end_month), field(ui('company_year_end_day'), inputs.fiscal_year_end_day)),
  errors,
  el('button', { class: 'btn', type: 'submit' }, ui('save_company')));
  return form;
}

/** The articles of association (a PDF): uploaded once for the company, read by the AI (it only copies), shown here for review. */
function articlesPanel(ctx, company) {
  const panel = el('div', { class: 'panel', 'data-articles': '' }, el('h2', {}, ui('articles_title')), el('p', { class: 'note' }, ui('articles_hint')));
  const status = el('div');
  const a = company.articles;
  const file = el('input', { type: 'file', accept: 'application/pdf,.pdf', 'data-articles-file': '' });
  const send = el('button', { class: 'btn', type: 'button', onclick: async () => {
    if (!file.files[0]) { status.replaceChildren(box('warn', ui('articles_choose_first'))); return; }
    send.disabled = true;
    status.replaceChildren(box('info', ui('articles_reading')));
    try {
      await upload(`/companies/${encodeURIComponent(company.id)}/articles`, file.files[0]);
      await ctx.reload();
      ctx.flash = ui('articles_read');
      ctx.redraw();
    } catch (err) { send.disabled = false; status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
  } }, ui(a ? 'articles_replace' : 'articles_upload'));
  panel.append(el('div', { class: 'toolbar' }, file, send));
  // Or the contract the client already uploaded in the portal.
  const pickHolder = el('div');
  panel.append(pickHolder);
  let picked = null;
  const use = el('button', { class: 'btn ghost small', type: 'button', 'data-use-client-file': '', hidden: true, onclick: async () => {
    use.disabled = true;
    status.replaceChildren(box('info', ui('articles_reading')));
    try {
      await api('POST', `/companies/${encodeURIComponent(company.id)}/articles`, { client_file_id: picked.id });
      await ctx.reload();
      ctx.flash = ui('articles_read');
      ctx.redraw();
    } catch (err) { use.disabled = false; status.replaceChildren(box('bad', err.all ? err.all().join(' ') : err.message)); }
  } }, ui('pick_use'));
  clientFilePicker({ companyId: company.id, purpose: 'articles', selectedId: null,
    labels: { title: ui('pick_title'), loading: ui('pick_loading'), none: ui('pick_none_articles'), error: ui('pick_error'), fine: ui('pick_fine'), remarks: ui('pick_remarks'), suggested: ui('pick_suggested') },
    onPick: (f) => { picked = f; use.hidden = false; } }).then((node) => pickHolder.replaceChildren(node, use));
  if (a) {
    panel.append(
      el('p', { class: 'note' }, ui('articles_file', { name: a.name ?? '' })),
      a.partners.length ? el('table', { 'data-articles-partners': '' },
        el('tr', {}, ['articles_partner', 'articles_nationality', 'articles_group', 'articles_shares', 'articles_ownership'].map((k) => el('th', {}, ui(k)))),
        a.partners.map((p) => el('tr', {}, el('td', {}, p.name), el('td', {}, p.nationality ?? '-'), el('td', {}, ui(GROUP_WORDS[p.group] ?? 'articles_group_unknown')),
          el('td', { class: 'num' }, p.shares === null ? '-' : exact(p.shares)), el('td', { class: 'num' }, p.ownership === null ? '-' : `${exact(p.ownership)}%`)))) : box('warn', ui('articles_no_partners')),
      a.zakat_share === null ? box('warn', ui('articles_share_unknown')) : box('ok', ui('articles_zakat_share', { share: exact(a.zakat_share), foreign: exact(100 - a.zakat_share) })),
      el('button', { class: 'btn ghost small', type: 'button', onclick: async () => {
        if (!window.confirm(ui('articles_confirm_remove'))) return;
        try { await api('DELETE', `/companies/${encodeURIComponent(company.id)}/articles`); await ctx.reload(); ctx.redraw(); } catch (err) { status.replaceChildren(box('bad', err.message)); }
      } }, ui('articles_remove')));
  }
  panel.append(status);
  return panel;
}

export function render(ctx) {
  const s = ctx.state;
  const wrap = el('div');

  // The company.
  const companyPanel = el('div', { class: 'panel' }, el('h2', {}, ui('the_company')));
  const pick = async (c) => {
    try { await ctx.save('PATCH', '', { company_id: c.id }); ctx.flash = ui('saved'); ctx.redraw(); } catch (e) { companyPanel.append(box('bad', e.message)); }
  };
  if (s.company) {
    companyPanel.append(
      el('div', { class: 'toolbar' }, el('span', { class: 'chip' }, s.company.name_ar),
        el('button', { class: 'btn ghost small', onclick: () => companyPanel.replaceChildren(el('h2', {}, ui('the_company')), companyPicker(pick)) }, ui('change_company'))),
      el('p', { class: 'note' }, ui('company_profile_hint')),
      profileForm(ctx, s.company));
  } else {
    companyPanel.append(box('warn', ui('company_missing')), companyPicker(pick));
  }
  wrap.append(companyPanel);
  if (s.company) wrap.append(articlesPanel(ctx, s.company));

  // The period.
  const start = el('input', { type: 'date', value: s.period_start, 'data-field': 'period_start' });
  const end = el('input', { type: 'date', value: s.period_end, 'data-field': 'period_end' });
  const errors = el('div');
  const saved = el('div');
  const form = el('form', { onsubmit: async (e) => {
    e.preventDefault();
    saved.replaceChildren();
    if (!start.value || !end.value || end.value <= start.value) { showErrors(form, { message: ui('period_invalid'), errors: {} }, errors); return; }
    try {
      await ctx.save('PATCH', '', { period_start: start.value, period_end: end.value });
      showErrors(form, null, errors);
      saved.append(box('ok', ui('saved')));
    } catch (err) { showErrors(form, err, errors); }
  } },
  el('div', { class: 'row' }, field(ui('period_start'), start), field(ui('period_end'), end)),
  s.comparative_end ? el('p', { class: 'note' }, ui('comparative_period', { start: day(s.comparative_start), end: day(s.comparative_end) })) : null,
  errors, saved,
  el('button', { class: 'btn', type: 'submit' }, ui('save_period')));
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('the_period')), form));

  // The edition: fixed for now (Q5, Q6).
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('the_template')),
    el('p', {}, label(`template_versions.${s.template_version}`) ?? ui('unknown')),
    el('p', { class: 'note' }, ui('template_fixed'))));

  wrap.append(el('div', { class: 'toolbar' }, el('button', { class: 'btn', onclick: () => ctx.go('prior_year') }, ui('next_step'))));
  return wrap;
}
