// The report wizard (plan §4.6, PR 13): nine steps (the opening balances' reconciliation after the accounts, the zakat and income tax after the movements) with free navigation (#company, #prior_year, …, #versions/<n> for one
// version) and a side panel of what stands between the report and a final version. Each step module renders from the state and saves through ctx.save.
import { C, api, upload, el, ui, label, url, day, box, ApiError, runRows, accountList, checkTitle, checkMessage, blockLabel } from './core.js';
import { missingLabel, stepForCheck, stepForMissing } from './describe.js';
import * as company from './steps/company.js';
import * as priorYear from './steps/prior-year.js';
import * as accounts from './steps/accounts.js';
import * as opening from './steps/opening.js';
import * as movements from './steps/movements.js';
import * as zakat from './steps/zakat.js';
import * as notes from './steps/notes.js';
import * as review from './steps/review.js';
import * as versions from './steps/versions.js';

const STEPS = { company, prior_year: priorYear, accounts, opening, movements, zakat, notes, review, versions };
let byRow = {};
const app = document.getElementById('app');
const header = el('div', { class: 'toolbar' });
const nav = el('div', { class: 'steps' });
const body = el('div');
const side = el('aside', { class: 'side panel' });

const ctx = {
  state: null,
  step: null,
  sub: null,     // what follows the step in the address (#versions/3 → '3')
  flash: null,   // a message shown once at the top of the step, e.g. after a save
  /** Sends a change; the new state replaces the old one and the panel follows. Throws the ApiError for the step to show. */
  async save(method, path, payload, file) {
    const url = `/financial-reports/${encodeURIComponent(C.report_id)}${path}`;
    const state = file ? await upload(url, file) : await api(method, url, payload);
    ctx.state = state;
    drawFrame();
    return state;
  },
  /** A state fetched by the step itself: kept, and the frame follows. */
  apply(state) {
    ctx.state = state;
    drawFrame();
  },
  async reload() {
    ctx.state = await api('GET', `/financial-reports/${encodeURIComponent(C.report_id)}`);
    drawFrame();
  },
  go(step) {
    const elsewhere = C.step_urls?.[step];   // a step that is not in this wizard: the client's file page or the wizard that holds it
    if (elsewhere && !C.steps.includes(step)) { location.href = elsewhere; return; }
    location.hash = step;
  },
  redraw() { drawStep(); },
};

function currentStep() {
  const [h, ...rest] = location.hash.slice(1).split('/');
  return C.steps.includes(h) ? [h, rest.join('/') || null] : [C.steps[0], null];
}

function drawFrame() {
  const s = ctx.state;
  header.replaceChildren(
    el('span', { class: 'chip' }, s.company?.name_ar ?? ui('no_company')),
    el('span', { class: 'chip' }, `${day(s.period_start)} → ${day(s.period_end)}`),
    el('span', { class: `badge ${s.status === 'final' ? 'b-ok' : 'b-info'}` }, label(`version_kinds.${s.status}`) ?? ui('unknown')),
    el('a', { href: url('list') }, ui('back_to_list')),
  );
  nav.replaceChildren(...C.steps.map((k, i) => el('a', { href: `#${k}`, class: k === ctx.step ? 'on' : null }, el('span', { class: 'n' }, i + 1), ui(`step_${k}`))));
  drawSide();
}

function item(cls, title, text, step, extra) {
  return el('li', { class: cls, title: ui('go_to_step', { step: ui(`step_${step}`) }), onclick: () => ctx.go(step) },
    el('div', { class: 't' }, title, extra), text ? el('div', { class: 'note' }, text) : null);
}

function drawSide() {
  const s = ctx.state;
  const parts = [el('h3', {}, ui('panel_title'))];
  if (s.run_changed) parts.push(box('warn', ui('run_changed_banner')));
  parts.push(s.can_finalize ? box('ok', ui('panel_ready'))
    : el('div', {}, el('p', { class: 'note' }, ui('panel_blocked')),
      el('ul', {}, s.blocking.map((b) => el('li', { class: 'sev-error' }, el('span', { class: 't' }, blockLabel(b)), ` (${b.count})`)))));

  const groups = [['error', ui('panel_errors')], ['warning', ui('panel_warnings')], ['info', ui('panel_info')]];
  for (const [sev, title] of groups) {
    const list = s.checks.filter((c) => c.severity === sev);
    if (!list.length) continue;
    parts.push(el('details', { open: sev === 'error' }, el('summary', {}, `${title} (${list.length})`),
      el('ul', {}, list.map((c) => item(`sev-${sev}${c.acknowledged ? ' acked' : ''}`, checkTitle(c),
        [checkMessage(c), c.rows?.length ? ` ${ui('accounts_label')} ${accountList(c.rows, byRow)}` : ''].join(''), stepForCheck(c),
        c.acknowledged ? el('span', { class: 'badge b-ok' }, label('acknowledged')) : null)))));
  }
  if (s.missing.length) {
    parts.push(el('details', {}, el('summary', {}, `${ui('panel_missing')} (${s.missing.length})`),
      el('ul', {}, s.missing.map((m) => { const d = missingLabel(s, m); return item('sev-missing', d.what, d.why, stepForMissing(m)); }))));
  }
  const pending = s.suggestions.filter((x) => x.status === 'pending').length;
  if (pending) parts.push(el('ul', {}, item('sev-warning', ui('panel_pending_suggestions', { count: pending }), null, 'movements')));
  side.replaceChildren(...parts);
}

async function drawStep() {
  [ctx.step, ctx.sub] = currentStep();
  drawFrame();
  const module = STEPS[ctx.step];
  body.replaceChildren(el('div', { class: 'loading' }, ui('loading')));
  try {
    const node = await module.render(ctx);
    body.replaceChildren(...(ctx.flash ? [box('ok', ctx.flash)] : []), node);
    ctx.flash = null;
  } catch (e) {
    console.error(e);
    body.replaceChildren(box('bad', e instanceof ApiError ? e.message : ui('error_generic')));
  }
  window.scrollTo({ top: 0 });
}

async function start() {
  try {
    byRow = await runRows();
    await ctx.reload();
  } catch (e) {
    app.replaceChildren(box('bad', e.message));
    return;
  }
  app.replaceChildren(...(C.embedded ? [] : [header, nav]), el('div', { class: 'wizard' }, body, side));
  window.addEventListener('hashchange', drawStep);
  drawStep();
}

start();
