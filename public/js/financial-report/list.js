// The reports list (PR 13a): newest first, filtered by company, with paging.
import { api, el, fill, ui, label, url, day, box } from './core.js';

const app = document.getElementById('app');
let companies = {};
let filter = '';
let page = 1;

async function load() {
  try {
    const list = await api('GET', '/companies?per_page=100');
    companies = Object.fromEntries(list.data.map((c) => [c.id, c.name_ar]));
  } catch { companies = {}; }
  await render();
}

async function render() {
  let reports;
  try {
    reports = await api('GET', `/financial-reports?per_page=25&page=${page}${filter ? `&company_id=${encodeURIComponent(filter)}` : ''}`);
  } catch (e) {
    app.replaceChildren(box('bad', e.message));
    return;
  }
  const select = el('select', { onchange: (e) => { filter = e.target.value; page = 1; render(); } },
    el('option', { value: '' }, ui('all_companies')),
    Object.entries(companies).map(([id, n]) => el('option', { value: id, selected: id === filter }, n)));

  const rows = reports.data.map((r) => el('tr', { class: 'clickable', onclick: () => { location.href = url('report', r.id); } },
    el('td', {}, el('a', { href: url('report', r.id) }, companies[r.company_id] ?? ui('no_company'))),
    el('td', {}, `${day(r.period_start)} → ${day(r.period_end)}`),
    el('td', {}, el('span', { class: `badge ${r.status === 'final' ? 'b-ok' : 'b-info'}` }, label(`version_kinds.${r.status}`) ?? ui('unknown'))),
    el('td', { class: 'num' }, day(r.finalized_at) || '—'),
    el('td', { class: 'num' }, day(r.updated_at)),
  ));

  fill(app,
    el('div', { class: 'toolbar' },
      el('a', { class: 'btn', href: url('new') }, ui('new_report')),
      el('label', { class: 'note' }, ui('filter_company')), select),
    el('div', { class: 'panel' }, reports.data.length === 0
      ? el('p', { class: 'note' }, ui('no_reports'))
      : el('table', { 'data-columns': 'reports' },
        el('tr', {}, el('th', {}, ui('col_company')), el('th', {}, ui('col_period')), el('th', {}, ui('col_status')),
          el('th', {}, ui('col_finalized')), el('th', {}, ui('col_updated'))),
        rows)),
    reports.last_page > 1 ? el('div', { class: 'toolbar' },
      el('button', { class: 'btn ghost small', disabled: page <= 1, onclick: () => { page--; render(); } }, ui('prev_page')),
      el('span', { class: 'note' }, ui('page_of', { page: reports.current_page, pages: reports.last_page })),
      el('button', { class: 'btn ghost small', disabled: page >= reports.last_page, onclick: () => { page++; render(); } }, ui('next_page'))) : null,
  );
}

load();
