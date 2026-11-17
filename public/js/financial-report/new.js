// A new report (PR 13a): the run (from the classifier, or picked from the finished ones), the company and the period.
import { C, api, el, ui, url, day, box, field, showErrors } from './core.js';
import { companyPicker } from './company-picker.js';

const app = document.getElementById('app');
let run = C.run;
// The company chosen on the classifier page comes with its run.
let company = C.run?.company ?? null;

/** The last full fiscal year before today, by the company's year end (31 December without one). */
function defaultPeriod() {
  // The period the office gave the client (from a date to a date, with their years) is the period of the report.
  if (company?.period_start && company?.period_end) return [company.period_start, company.period_end];
  const today = new Date();
  const month = company?.fiscal_year_end_month ?? 12;
  const dayOf = company?.fiscal_year_end_day ?? 31;
  const endIn = (y) => new Date(Date.UTC(y, month - 1, Math.min(dayOf, new Date(Date.UTC(y, month, 0)).getUTCDate())));
  let end = endIn(today.getUTCFullYear());
  if (end >= today) end = endIn(today.getUTCFullYear() - 1);
  const start = new Date(endIn(end.getUTCFullYear() - 1).getTime() + 86400000);
  return [start.toISOString().slice(0, 10), end.toISOString().slice(0, 10)];
}

async function render() {
  const parts = [];

  // 1. The trial balance.
  if (!run) {
    let runs;
    try { runs = await api('GET', '/classify/jobs?status=done&per_page=50'); } catch (e) { app.replaceChildren(box('bad', e.message)); return; }
    parts.push(el('div', { class: 'panel' }, el('h2', {}, ui('pick_run')),
      runs.data.length === 0 ? el('p', { class: 'note' }, ui('no_runs'), ' ', el('a', { href: url('classifier') }, ui('go_classifier')))
        : el('table', {}, el('tr', {}, el('th', {}, ui('col_file')), el('th', {}, ui('col_rows')), el('th', {}, ui('col_date')), el('th', {})),
          runs.data.map((r) => el('tr', { class: 'clickable', onclick: () => { location.search = `?run=${encodeURIComponent(r.id)}`; } },
            el('td', {}, r.original_name), el('td', { class: 'num' }, r.rows), el('td', { class: 'num' }, day(r.created_at)),
            el('td', {}, el('button', { class: 'btn small' }, ui('choose'))))))));
    app.replaceChildren(...parts);
    return;
  }
  if (run.unusable) {
    app.replaceChildren(box('bad', run.unusable), el('p', {}, el('a', { href: url('classifier') }, ui('go_classifier'))));
    return;
  }
  parts.push(el('div', { class: 'panel' }, el('h2', {}, ui('the_run')),
    el('p', {}, el('span', { class: 'chip' }, run.original_name), ' ', el('a', { href: url('new') }, ui('change_run'))),
    run.unclassified > 0 ? box('warn', ui('run_unclassified', { count: run.unclassified })) : null));

  // 2. The company.
  const companyPanel = el('div', { class: 'panel' }, el('h2', {}, ui('the_company')));
  if (company) {
    companyPanel.append(el('p', {}, el('span', { class: 'chip' }, company.name_ar), ' ',
      el('a', { href: '#', onclick: (e) => { e.preventDefault(); company = null; render(); } }, ui('change_company'))));
  } else {
    companyPanel.append(el('p', { class: 'note' }, ui('company_optional')), companyPicker((c) => { company = c; render(); }));
  }
  parts.push(companyPanel);

  // 3. The period, then create.
  const [s, e] = defaultPeriod();
  const start = el('input', { type: 'date', value: s, 'data-field': 'period_start', required: true });
  const end = el('input', { type: 'date', value: e, 'data-field': 'period_end', required: true });
  const errors = el('div');
  const form = el('form', { onsubmit: async (ev) => {
    ev.preventDefault();
    if (!start.value || !end.value || end.value <= start.value) {
      showErrors(form, { message: ui('period_invalid'), errors: {} }, errors);
      return;
    }
    try {
      const report = await api('POST', '/financial-reports', { classification_run_id: run.id, company_id: company?.id ?? null,
        period_start: start.value, period_end: end.value });
      location.href = url('report', report.id);
    } catch (err) { showErrors(form, err, errors); }
  } },
  el('h2', {}, ui('the_period')),
  el('div', { class: 'row' }, field(ui('period_start'), start), field(ui('period_end'), end)),
  errors,
  el('button', { class: 'btn', type: 'submit' }, ui('create_report')));
  parts.push(el('div', { class: 'panel' }, form));

  app.replaceChildren(...parts);
}

render();
