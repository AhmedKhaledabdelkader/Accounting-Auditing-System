<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('cache_pages.review.title') }}</title>
@include('partials.theme-head')
<style>
  /* This page's own styles; colours, font and the page frame are in theme.css. */
  .btn{border:0;cursor:pointer;font-family:inherit;font-weight:600;font-size:14.5px;border-radius:12px;padding:12px 24px;
    color:var(--on-brand);background:var(--brand);transition:background .15s;box-shadow:var(--shadow-btn)}
  .btn:hover{background:var(--brand-ink)} .btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
  .btn.ghost{background:var(--panel);border:1px solid var(--line);box-shadow:none;color:var(--text)}
  .btn.sm{font-size:13px;padding:6px 12px;border-radius:10px;box-shadow:none}
  .btn.ok{background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok-line)}
  .btn.skip{background:transparent;color:var(--muted);border:1px solid var(--line)}
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px}
  .card{padding:18px 20px;border-radius:18px;background:var(--panel);border:1px solid var(--line)}
  .card small{color:var(--muted);font-size:13px}
  .card b{display:block;font-size:30px;margin-top:4px;font-weight:800;font-variant-numeric:tabular-nums}
  .card.warn b{color:var(--warn)} .card.ok b{color:var(--ok)} .card.bad b{color:var(--bad)}
  .card .sub{display:block;color:var(--muted);font-size:12px;margin-top:4px}
  .banner{margin-bottom:18px;padding:14px 18px;border-radius:14px;background:var(--warn-soft);border:1px solid var(--warn);color:var(--warn-ink);font-size:14px}
  .banner b{color:var(--text)} .banner code{direction:ltr;unicode-bidi:embed;background:var(--panel2);padding:2px 6px;border-radius:6px;font-size:12.5px}
  .error{display:none;margin-bottom:18px;padding:14px 18px;border-radius:14px;background:var(--bad-soft);border:1px solid var(--bad);color:var(--bad-ink)}
  .error.on{display:block}
  .notice{display:none;margin-bottom:18px;padding:14px 18px;border-radius:14px;background:var(--ok-soft);border:1px solid var(--ok);color:var(--ok-ink)}
  .notice.on{display:block}
  .tabs{display:flex;gap:8px;margin-bottom:14px;border-bottom:1px solid var(--line)}
  .tab{background:none;border:0;border-bottom:3px solid transparent;color:var(--muted);font-family:inherit;font-size:16px;font-weight:700;
    padding:10px 18px;cursor:pointer;margin-bottom:-1px}
  .tab.on{color:var(--text);border-bottom-color:var(--brand)}
  .tab span{font-size:12px;color:var(--muted);margin-inline-start:6px}
  .toolbar{display:flex;flex-wrap:wrap;gap:12px 18px;align-items:center;margin-bottom:14px}
  .toolbar input[type=search]{flex:1;min-width:220px;padding:11px 16px;border-radius:12px;border:1px solid var(--line);
    background:var(--panel);color:var(--text);font-family:inherit;font-size:14px;outline:none}
  .toolbar input[type=search]:focus{border-color:var(--brand)}
  .toggle{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:14px;cursor:pointer;user-select:none}
  .table-wrap{overflow:auto;border-radius:18px;border:1px solid var(--line);background:var(--panel);max-height:70vh}
  table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:1250px}
  thead th{position:sticky;top:0;background:var(--panel2);color:var(--muted);font-weight:700;text-align:start;
    padding:12px 10px;border-bottom:1px solid var(--line);white-space:nowrap;z-index:1}
  tbody td{padding:10px;border-bottom:1px solid var(--line2);vertical-align:top}
  tbody tr:hover{background:var(--panel2)}
  tr.flagged{background:var(--warn-soft)}
  tr.done{opacity:.62}
  .num{font-variant-numeric:tabular-nums;direction:ltr;text-align:end;white-space:nowrap;font-weight:700}
  .val{font-weight:700;max-width:240px}
  .muted{color:var(--muted);font-size:12.5px}
  .pill{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;white-space:nowrap}
  .p-map{background:var(--brand-soft);color:var(--brand-ink)} .p-route{background:var(--info-soft);color:var(--info)}
  .p-skip{background:var(--panel2);color:var(--muted)} .p-policy{background:var(--warn-soft);color:var(--warn)}
  .conf{display:flex;align-items:center;gap:8px;min-width:110px}
  .conf .track{flex:1;height:8px;border-radius:99px;background:var(--line);overflow:hidden}
  .conf .fill{height:100%;border-radius:99px}
  .conf span{font-weight:800;width:38px;direction:ltr;text-align:end}
  .samples{color:var(--muted);font-size:12.5px;max-width:230px}
  .flag{color:var(--warn);font-size:12.5px;font-weight:700;max-width:260px}
  .reason{color:var(--muted);font-size:12.5px;max-width:260px}
  .state{font-size:12px;font-weight:700;white-space:nowrap}
  .state.y{color:var(--ok)} .state.n{color:var(--muted)}
  .acts{display:flex;flex-direction:column;gap:6px;min-width:220px}
  .acts .row{display:flex;gap:6px}
  select.fix{font-family:inherit;font-size:12.5px;color:var(--text);background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:6px 8px;width:100%}
  .empty{padding:40px;text-align:center;color:var(--muted)}
  .pager{display:flex;gap:12px;align-items:center;justify-content:center;margin-top:14px;color:var(--muted);font-size:14px}
  .bottom{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-top:26px;padding:18px 20px;
    border-radius:18px;background:var(--panel);border:1px solid var(--line)}
  .bottom p{margin:0;color:var(--muted);font-size:14px}
</style>
</head>
<body>
<x-shell :title="__('cache_pages.review.title')" :subtitle="__('cache_pages.review.subtitle', ['file' => $import->file_name])">

  <div class="error" id="error"></div>
  <div class="notice" id="notice"></div>
  <div id="decisions"></div>

  <!-- Blade-generated configuration is kept outside JavaScript (like the classifier page). Escaped once by Blade; the browser decodes it. -->
  <div id="app-config"
       data-api="{{ route('classification-cache.imports.show', $import->id, false) }}"
       data-review-url="{{ route('classification-cache.imports', [], false) }}/mappings/"
       data-taxonomy="{{ json_encode($taxonomy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
       data-policies="{{ json_encode($policies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
       data-t="{{ json_encode(__('cache_pages.review.js'), JSON_UNESCAPED_UNICODE) }}"
       hidden></div>

  <div class="cards">
    <div class="card"><small>{{ __('cache_pages.review.cards.total') }}</small><b id="cTotal">—</b><span class="sub" id="cCompanies"></span></div>
    <div class="card ok"><small>{{ __('cache_pages.review.cards.ready') }}</small><b id="cReady">—</b><span class="sub" id="cApplied"></span></div>
    <div class="card warn"><small>{{ __('cache_pages.review.cards.pending') }}</small><b id="cPending">—</b><span class="sub" id="cLow"></span></div>
    <div class="card bad"><small>{{ __('cache_pages.review.cards.flagged') }}</small><b id="cFlagged">—</b><span class="sub" id="cFlaggedRows"></span></div>
    <div class="card"><small>{{ __('cache_pages.review.cards.reviewed') }}</small><b id="cReviewed">—</b><span class="sub">{{ __('cache_pages.review.cards.reviewed_sub') }}</span></div>
  </div>

  <div class="tabs">
    <button class="tab on" data-kind="budget">{{ __('cache_pages.review.js.tabs.budget') }}<span id="tBudget"></span></button>
    <button class="tab" data-kind="note">{{ __('cache_pages.review.js.tabs.note') }}<span id="tNote"></span></button>
  </div>

  <div class="toolbar">
    <input type="search" id="q" placeholder="🔎 {{ __('cache_pages.review.search') }}">
    <label class="toggle"><input type="checkbox" id="fUnreviewed" checked> {{ __('cache_pages.review.only_unreviewed') }}</label>
    <label class="toggle"><input type="checkbox" id="fFlagged"> {{ __('cache_pages.review.only_flagged') }}</label>
    <label class="toggle"><input type="checkbox" id="fLow"> {{ __('cache_pages.review.only_low') }}</label>
    <button class="btn sm ok" id="bulk" disabled>✓ {{ __('cache_pages.review.approve_selected') }} (<span id="nSel">0</span>)</button>
    <button class="btn sm ok" id="approveAll" disabled title="{{ __('cache_pages.review.approve_all_title') }}">✓✓ {{ __('cache_pages.review.js.approve_all') }} (<span id="nPending">…</span>)</button>
    <button class="btn sm ghost" id="otherTab" hidden></button>
  </div>

  <div class="table-wrap">
    <table data-columns="import-review">
      <thead id="thead"></thead>
      <tbody id="tbody"><tr><td class="empty">{{ __('cache_pages.review.loading') }}</td></tr></tbody>
    </table>
  </div>
  <div class="pager" id="pager"></div>

  <div class="bottom">
    <p id="applyHint"></p>
    <button class="btn" id="apply" disabled>{{ __('cache_pages.review.apply_button') }}</button>
  </div>
</x-shell>

@include('partials.ask-dialog')

<script>
const CONFIG = document.getElementById('app-config').dataset;
const API = CONFIG.api;
const REVIEW_URL = CONFIG.reviewUrl;
// sub_class => [statement, main_class, side]
const TAXONOMY = JSON.parse(CONFIG.taxonomy);
const POLICIES = JSON.parse(CONFIG.policies);
const T = JSON.parse(CONFIG.t);
// The page language with Western digits.
const LOCALE = document.documentElement.lang + '-u-nu-latn';
const ARROW = document.documentElement.dir === 'rtl' ? '←' : '→';
const PER_PAGE = 50;
const $ = id => document.getElementById(id);
let kind = 'budget', page = 1, list = [], selected = new Set(), report = null, timer = null;

const MAINS = [...new Set(Object.values(TAXONOMY).map(t => t[1]))];
const subsOf = main => Object.keys(TAXONOMY).filter(s => TAXONOMY[s][1] === main);

/* ---------- data ---------- */
async function api(url, opts = {}){
  const res  = await fetch(url, { ...opts, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...(opts.headers || {}) } });
  const json = await res.json().catch(() => ({}));
  if(!res.ok) throw new Error((json.errors && Object.values(json.errors)[0][0]) || json.message || t('error'));
  return json;
}

async function loadReport(){ showReport((await api(API)).report); }

/** The tab and filters of the list: the same ones «Approve all» approves by. */
function filterParams(){
  const p = new URLSearchParams({ kind });
  if($('q').value.trim()) p.set('q', $('q').value.trim());
  if($('fUnreviewed').checked) p.set('unreviewed', 1);
  if($('fFlagged').checked) p.set('flagged', 1);
  if($('fLow').checked) p.set('low', 1);
  return p;
}

async function loadList(){
  const p = filterParams();
  p.set('page', page); p.set('per_page', PER_PAGE);
  try{
    const json = await api(`${API}/mappings?${p}`);
    list = json.data; selected.clear();
    render(); renderPager(json);
  }catch(e){ showError(e.message); }
  refreshPending();
}

let pending = 0;

/** «Approve all» shows how many it would approve; when this tab has none left, a button points to the other tab's. */
async function refreshPending(){
  try{
    const p = filterParams();
    p.set('unreviewed', 1); p.set('per_page', 1);
    pending = (await api(`${API}/mappings?${p}`)).total;
    $('nPending').textContent = fmt(pending);
    $('approveAll').disabled = pending === 0;

    const other = kind === 'budget' ? 'note' : 'budget';
    const left  = pending ? 0 : (await api(`${API}/mappings?${new URLSearchParams({ kind: other, unreviewed: 1, per_page: 1 })}`)).total;
    $('otherTab').hidden = !left;
    $('otherTab').textContent = t('other_tab', { n: fmt(left), tab: t('tabs.' + other) }) + ' ' + ARROW;
    $('otherTab').onclick = () => document.querySelector(`.tab[data-kind="${other}"]`).click();
  }catch(e){ /* the count is a hint only */ }
}

/* ---------- report ---------- */
function showReport(r){
  report = r;
  const n = fmt;
  $('cTotal').textContent = n(r.rows.total);
  $('cCompanies').textContent = t('companies', { n: n(r.rows.companies) });
  $('cReady').textContent = n(r.rows.ready);
  $('cApplied').textContent = t('applied_rows', { written: n(r.rows.written), entries: n(r.rows.cache_entries) })
    + (r.rows.kept_existing ? t('kept_rows', { n: n(r.rows.kept_existing) }) : '');
  $('cPending').textContent = n(r.rows.pending_review);
  $('cLow').textContent = t('below_cutoff', { n: n(r.review.pending_below_cutoff), cutoff: r.review.cutoff });
  $('cFlagged').textContent = n(r.review.flagged_unreviewed);
  $('cFlaggedRows').textContent = t('rows', { n: n(r.review.flagged_rows) });
  $('cReviewed').textContent = `${n(r.review.reviewed)} / ${n(r.review.mappings)}`;
  $('tBudget').textContent = `(${n(r.budget_mapping.distinct_normalized)})`;
  $('tNote').textContent = `(${n(r.note_mapping.distinct_pairs)})`;
  $('decisions').innerHTML = (r.open_decisions || []).map(d => {
    const other = d.mode === 'function' ? 'nature' : 'function';
    return `<div class="banner">⚖️ <b>${t('open_decision', { label: esc(d.label) })}</b> (${t('rows', { n: n(d.rows) })})<br>${esc(d.decision)}<br>
      ${t('current')} <b>${t('modes.' + d.mode)}</b>. ${t('if_switched', { other: t('modes.' + other), n: n(d['rows_changing_if_switched_to_' + other]) })}
      ${t('switch_command')} <code>${esc(d.switch_text ?? d.switch)}</code></div>`;
  }).join('');
  $('apply').disabled = r.rows.approved === 0;
  $('applyHint').textContent = (r.rows.ready
      ? t('apply_ready', { n: n(r.rows.ready) })
      : '✓ ' + t('apply_all_written', { n: n(r.rows.written) }))
    + (r.rows.pending_review ? t('apply_pending', { n: n(r.rows.pending_review) }) : '.')
    + ' ' + t('apply_kept');
}

/* ---------- table ---------- */
function renderHead(){
  const first = kind === 'budget'
    ? ['budget_item', 'rows', 'mapping']
    : ['note', 'under_item', 'rows', 'sub_class'];
  const th = keys => keys.map(k => `<th>${t('th.' + k)}</th>`).join('');
  $('thead').innerHTML = `<tr><th><input type="checkbox" id="all" title="${t('th.select_all')}"></th>${th(first)}
    ${th(['method', 'confidence', 'samples', 'remark', 'state', 'action'])}</tr>`;
  $('all').onchange = e => { list.forEach(m => e.target.checked ? selected.add(m.id) : selected.delete(m.id)); render(); };
}

function render(){
  renderHead();
  $('tbody').innerHTML = list.length ? list.map(m => `
    <tr class="${m.flag && !m.reviewed ? 'flagged' : ''} ${m.reviewed ? 'done' : ''}">
      <td><input type="checkbox" class="sel" data-id="${m.id}" ${selected.has(m.id) ? 'checked' : ''}></td>
      ${kind === 'budget' ? budgetCells(m) : noteCells(m)}
      <td class="muted">${esc(t('methods.' + m.method))}</td>
      <td>${confBar(m.confidence)}</td>
      <td class="samples">${m.samples.map(s => esc(s.name) + (s.company ? ` <span class="muted">(${esc(s.company)})</span>` : '')).join('<br>')}</td>
      <td>${m.flag ? `<div class="flag">⚠️ ${esc(m.flag_text ?? m.flag)}</div>` : ''}<div class="reason">${esc(m.reason_text ?? m.reason ?? '')}</div></td>
      <td class="state ${m.reviewed ? 'y' : 'n'}">${m.reviewed ? '✓ ' + t('reviewed') : t('waiting')}</td>
      <td>${actions(m)}</td>
    </tr>`).join('')
    : `<tr><td colspan="11" class="empty">${t('no_match')}</td></tr>`;
  document.querySelectorAll('.sel').forEach(c => c.onchange = () => { c.checked ? selected.add(+c.dataset.id) : selected.delete(+c.dataset.id); updateBulk(); });
  updateBulk();
}

function budgetCells(m){
  const target = m.decision === 'map' ? `<span class="pill p-map">${esc(m.main_class)}</span>${m.sub_class ? `<div class="muted">${esc(m.sub_class)}</div>` : ''}`
    : m.decision === 'route' ? `<span class="pill p-route">${t('by_notes')}</span>` : `<span class="pill p-skip">${t('skip')}</span>`;
  return `<td class="val">${esc(m.value)}${m.policy ? ` <span class="pill p-policy">${t('open_policy')}</span>` : ''}</td><td class="num">${fmt(m.rows)}</td><td>${target}</td>`;
}

function noteCells(m){
  const parent = m.parent === 'route' ? `<span class="pill p-route">${t('by_notes')}</span>` : esc(m.parent);
  const target = m.decision === 'map' && m.active_sub
    ? `<span class="pill p-map">${esc(m.active_sub)}</span><div class="muted">${esc(m.active_main || '')}</div>`
    : `<span class="pill p-skip">${t('unmapped')}</span>`;
  const policy = m.policy ? `<div class="muted">⚖️ ${t('policy_other', { mode: t(m.policy_mode === 'function' ? 'by_function' : 'by_nature'),
    other: esc((m.policy_mode === 'function' ? m.sub_class : m.function_sub_class) || '—') })}</div>` : '';
  return `<td class="val">${esc(m.value)}</td><td class="muted">${parent}</td><td class="num">${fmt(m.rows)}</td><td>${target}${policy}</td>`;
}

function actions(m){
  let opts;
  if(kind === 'budget'){
    opts = `<option value="">${t('change_mapping')}</option><option value="route">${t('by_notes')}</option>` +
      MAINS.map(x => `<option value="main:${esc(x)}">${esc(x)}</option>`).join('');
  }else{
    const fn = m.policy && m.policy_mode === 'function';
    const groups = fn ? { [POLICIES[m.policy].function_main]: subsOf(POLICIES[m.policy].function_main) }
      : MAINS.reduce((g, x) => (g[x] = subsOf(x), g), {});
    opts = `<option value="">${t('change_sub')}</option>` + Object.entries(groups).map(([g, subs]) =>
      `<optgroup label="${esc(g)}">${subs.map(s => `<option value="sub:${esc(s)}">${esc(s)}</option>`).join('')}</optgroup>`).join('');
  }
  return `<div class="acts">
    <div class="row">
      <button class="btn sm ok" onclick="review(${m.id}, {})" ${m.decision === 'skip' && !m.reviewed ? `title="${t('approve_skip')}"` : ''}>✓ ${t('approve')}</button>
      <button class="btn sm skip" onclick="review(${m.id}, {decision:'skip'})">${t('skip')}</button>
    </div>
    <select class="fix" onchange="change(${m.id}, this.value)">${opts}</select>
  </div>`;
}

function change(id, v){
  if(!v) return;
  if(v === 'route') return review(id, { decision: 'route' });
  if(v.startsWith('main:')) return review(id, { decision: 'map', main_class: v.slice(5) });
  if(v.startsWith('sub:')) return review(id, { decision: 'map', sub_class: v.slice(4) });
}

async function review(id, body){
  hideMessages();
  try{
    const json = await api(REVIEW_URL + id, { method: 'PATCH', body: JSON.stringify(body) });
    showReport(json.report);
    await loadList();
  }catch(e){ showError(e.message); }
}

function updateBulk(){ $('nSel').textContent = selected.size; $('bulk').disabled = selected.size === 0; }

$('bulk').onclick = async () => {
  hideMessages();
  $('bulk').disabled = true;
  try{
    const json = await api(`${API}/mappings/approve`, { method: 'POST', body: JSON.stringify({ ids: [...selected] }) });
    showReport(json.report);
    showNotice(t('approved', { n: fmt(json.approved) }));
    await loadList();
  }catch(e){ showError(e.message); updateBulk(); }
};

// Every not-yet-reviewed mapping of this tab that matches the filters, on every page, after a confirmation with the count.
$('approveAll').onclick = async () => {
  hideMessages();
  const p = filterParams();
  p.set('unreviewed', 1); p.set('per_page', 1);
  $('approveAll').disabled = true;
  try{
    const total = (await api(`${API}/mappings?${p}`)).total;
    p.set('flagged', 1);
    const flagged = (await api(`${API}/mappings?${p}`)).total;
    if(!total) return;
    if(!await ask(t('approve_all_q', { n: fmt(total), tab: t('tabs.' + kind) })
      + (flagged ? t('approve_all_flagged', { n: fmt(flagged) }) : '') + '.', t('approve_all'))) return;

    const body = Object.fromEntries(filterParams());
    delete body.unreviewed;
    ['flagged', 'low'].forEach(k => { if(body[k]) body[k] = true; });
    const json = await api(`${API}/mappings/approve`, { method: 'POST', body: JSON.stringify({ ...body, all: true }) });
    showReport(json.report);
    showNotice(t('approved', { n: fmt(json.approved) }));
    page = 1;
    await loadList();
  }catch(e){ showError(e.message); }
  finally{ refreshPending(); }
};

$('apply').onclick = async () => {
  if(!report || !await ask(report.rows.ready
      ? t('apply_q', { n: fmt(report.rows.ready) })
      : t('apply_again_q'), t('apply'))) return;
  hideMessages();
  $('apply').disabled = true;
  try{
    const json = await api(`${API}/apply`, { method: 'POST' });
    showReport(json.report);
    const a = json.applied;
    showNotice('✓ ' + t('applied', { entries: fmt(a.cache_entries), kept: fmt(a.kept_existing), conflicts: fmt(a.key_conflicts), pending: fmt(a.pending_review), names: fmt(a.name_layer) }));
  }catch(e){ showError(e.message); $('apply').disabled = false; }
};

/* ---------- tabs, filters, pages ---------- */
document.querySelectorAll('.tab').forEach(tab => tab.onclick = () => {
  document.querySelectorAll('.tab').forEach(x => x.classList.toggle('on', x === tab));
  kind = tab.dataset.kind; page = 1; loadList();
});
['fUnreviewed', 'fFlagged', 'fLow'].forEach(id => $(id).onchange = () => { page = 1; loadList(); });
$('q').oninput = () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; loadList(); }, 300); };

function renderPager(p){
  $('pager').innerHTML = p.last_page > 1 ? `
    <button class="btn sm ghost" ${p.current_page <= 1 ? 'disabled' : ''} onclick="go(${p.current_page - 1})">${t('prev')}</button>
    <span>${t('page', { page: fmt(p.current_page), last: fmt(p.last_page), total: fmt(p.total) })}</span>
    <button class="btn sm ghost" ${p.current_page >= p.last_page ? 'disabled' : ''} onclick="go(${p.current_page + 1})">${t('next')}</button>`
    : (p.total ? `<span>${t('total', { n: fmt(p.total) })}</span>` : '');
}
function go(n){ page = n; loadList(); $('thead').scrollIntoView({ behavior: 'smooth' }); }

/* ---------- helpers ---------- */
function confBar(c){
  const color = c >= 90 ? 'var(--ok)' : c >= 70 ? 'var(--warn)' : 'var(--bad)';
  return `<div class="conf"><div class="track"><div class="fill" style="width:${c}%;background:${color}"></div></div><span>${c}%</span></div>`;
}
function fmt(v){ return Number(v ?? 0).toLocaleString(LOCALE); }
/** A string of the page language: `key` may be dotted ('methods.llm'); :name placeholders are filled from vars. Never the raw key. */
function t(key, vars = {}){
  const s = key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), T);
  return typeof s === 'string' ? s.replace(/:([a-z_]+)/g, (m, k) => k in vars ? vars[k] : m) : '—';
}
function esc(s){ const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function showError(m){ $('error').textContent = '⚠️ ' + m; $('error').classList.add('on'); window.scrollTo({ top: 0, behavior: 'smooth' }); }
function showNotice(m){ $('notice').textContent = m; $('notice').classList.add('on'); window.scrollTo({ top: 0, behavior: 'smooth' }); }
function hideMessages(){ $('error').classList.remove('on'); $('notice').classList.remove('on'); }

loadReport().catch(e => showError(e.message));
loadList();
</script>
</body>
</html>
