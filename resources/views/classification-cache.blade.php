<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('cache_pages.cache.title') }}</title>
@include('partials.theme-head')
<style>
  /* This page's own styles; colours, font and the page frame are in theme.css. */
  section{margin-bottom:30px}
  h2{font-size:19px;margin:0 0 12px}
  .panel{padding:22px;border-radius:20px;background:var(--panel);border:1px solid var(--line)}
  .drop{border:2px dashed var(--line);border-radius:22px;background:linear-gradient(180deg,var(--panel),var(--panel2));
    padding:36px 20px;text-align:center;cursor:pointer;transition:.25s}
  .drop:hover,.drop.over{border-color:var(--brand);background:var(--brand-soft)}
  .drop .ico{width:56px;height:56px;margin:0 auto 12px;border-radius:14px;background:var(--brand-soft);color:var(--brand);display:grid;place-items:center}
  .drop .ico svg{width:26px;height:26px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  .drop h3{margin:0 0 6px;font-size:19px}
  .drop p{margin:0;color:var(--muted);font-size:14px}
  .file-chip{display:inline-flex;gap:8px;align-items:center;margin-top:12px;padding:8px 16px;border-radius:999px;
    background:var(--brand-soft);border:1px solid var(--brand);font-size:14px}
  .btn{border:0;cursor:pointer;font-family:inherit;font-weight:600;font-size:14.5px;border-radius:12px;padding:12px 24px;
    color:var(--on-brand);background:var(--brand);transition:background .15s;box-shadow:var(--shadow-btn);
    text-decoration:none;display:inline-block}
  .btn:hover{background:var(--brand-ink)} .btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
  .btn.ghost{background:var(--panel);border:1px solid var(--line);box-shadow:none;color:var(--text)}
  .btn.sm{font-size:13px;padding:6px 14px;border-radius:10px;box-shadow:none}
  .actions{display:flex;gap:12px;justify-content:center;margin-top:16px;flex-wrap:wrap}
  .msg{display:none;margin-top:16px;padding:14px 18px;border-radius:14px;font-size:14px}
  .msg.on{display:block} .msg ul{margin:6px 0 0;padding-inline-start:20px}
  .msg.bad{background:var(--bad-soft);border:1px solid var(--bad);color:var(--bad-ink)}
  .msg.warn{background:var(--warn-soft);border:1px solid var(--warn);color:var(--warn-ink)}
  .msg.ok{background:var(--ok-soft);border:1px solid var(--ok);color:var(--ok-ink)}
  .progress{display:none;margin-top:18px;padding:22px;border-radius:18px;background:var(--panel2);border:1px solid var(--line);text-align:center}
  .progress.on{display:block}
  .progress b{font-size:16px}
  .bar{height:6px;border-radius:99px;background:var(--line);overflow:hidden;margin:14px auto 0;max-width:420px}
  .bar i{display:block;height:100%;width:40%;border-radius:99px;background:linear-gradient(90deg,var(--brand),var(--brand2));animation:slide 1.2s infinite ease-in-out}
  .bar i.determinate{animation:none;margin:0;transition:width .4s ease}
  @keyframes slide{0%{margin-inline-start:-40%}100%{margin-inline-start:100%}}
  .steps{display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:14px;font-size:13px;color:var(--muted)}
  .steps span.on{color:var(--text);font-weight:700} .steps span.done{color:var(--ok)}
  .table-wrap{overflow:auto;border-radius:18px;border:1px solid var(--line);background:var(--panel)}
  table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:760px}
  thead th{background:var(--panel2);color:var(--muted);font-weight:700;text-align:start;padding:12px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
  tbody td{padding:11px 10px;border-bottom:1px solid var(--line2);vertical-align:middle}
  .num{font-variant-numeric:tabular-nums;direction:ltr;text-align:end;white-space:nowrap}
  .pill{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;white-space:nowrap}
  .s-queued,.s-running{background:var(--brand-soft);color:var(--brand-ink)}
  .s-pending_review{background:var(--warn-soft);color:var(--warn)}
  .s-applied{background:var(--ok-soft);color:var(--ok)}
  .s-failed{background:var(--bad-soft);color:var(--bad)}
  .muted{color:var(--muted);font-size:12.5px}
  .empty{padding:30px;text-align:center;color:var(--muted)}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px}
  .filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:14px}
  .filters label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted)}
  select,input[type=date]{font-family:inherit;font-size:14px;color:var(--text);background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:8px 10px}
  .note{font-size:13px;color:var(--muted);margin:10px 0 0}
  dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:10px 0 0;font-size:14px} dt{color:var(--muted)} dd{margin:0;font-weight:700}
</style>
</head>
<body>
<x-shell :title="__('cache_pages.cache.title')" :subtitle="__('cache_pages.cache.subtitle')">

  <!-- Strings the script shows, in the page language; escaped once by Blade, the browser decodes them. -->
  <div id="app-config" data-t="{{ json_encode(__('cache_pages.cache.js'), JSON_UNESCAPED_UNICODE) }}" hidden></div>

  <!-- 1. upload -->
  <section>
    <h2>{{ __('cache_pages.cache.upload.heading') }}</h2>
    <div class="drop" id="drop">
      <div class="ico"><svg viewBox="0 0 24 24"><path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/></svg></div>
      <h3>{{ __('cache_pages.cache.upload.drop') }}</h3>
      <p>{{ __('cache_pages.cache.upload.columns') }}</p>
      <div id="fileChip"></div>
      <input type="file" id="file" accept=".xlsx,.xls,.csv" hidden>
    </div>
    <div class="actions"><button class="btn" id="go" disabled>{{ __('cache_pages.cache.upload.start') }} ✨</button></div>
    <div class="msg bad" id="upError"></div>
    <div class="msg warn" id="upWarn"></div>
    <div class="progress" id="progress">
      <b id="stage">{{ __('cache_pages.cache.js.stages.queued') }}…</b>
      <div class="bar"><i id="barFill"></i></div>
      <div class="steps" id="steps"></div>
      <p class="note" id="hint"></p>
      <div class="actions" id="doneActions"></div>
    </div>
  </section>

  <!-- 2. previous imports -->
  <section>
    <h2>{{ __('cache_pages.cache.imports.heading') }}</h2>
    <div class="table-wrap">
      <table data-columns="cache-imports">
        <thead><tr><th>#</th><th>{{ __('cache_pages.cache.imports.file') }}</th><th>{{ __('cache_pages.cache.imports.date') }}</th><th>{{ __('cache_pages.cache.imports.status') }}</th><th>{{ __('cache_pages.cache.imports.rows') }}</th><th>{{ __('cache_pages.cache.imports.ready_pending') }}</th><th>{{ __('cache_pages.cache.imports.written') }}</th><th></th></tr></thead>
        <tbody id="imports"><tr><td colspan="8" class="empty">{{ __('cache_pages.cache.imports.loading') }}</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- 3. export / import -->
  <section class="grid">
    <div class="panel">
      <h2>{{ __('cache_pages.cache.export.heading') }}</h2>
      <div class="filters">
        <label>{{ __('cache_pages.cache.export.source') }}
          <select id="xSource">
            <option value="">{{ __('cache_pages.cache.export.all') }}</option>
            <option value="ai">{{ __('cache_pages.cache.export.ai') }}</option>
            <option value="manual_correction">{{ __('cache_pages.cache.export.manual_correction') }}</option>
            <option value="imported">{{ __('cache_pages.cache.export.imported') }}</option>
          </select>
        </label>
        <label>{{ __('cache_pages.cache.export.from') }} <input type="date" id="xFrom"></label>
        <label>{{ __('cache_pages.cache.export.to') }} <input type="date" id="xTo"></label>
      </div>
      <div class="actions" style="justify-content:flex-start">
        <a class="btn" id="xXlsx" href="#">⬇ {{ __('cache_pages.cache.export.xlsx') }}</a>
        <a class="btn ghost" id="xJson" href="#">⬇ {{ __('cache_pages.cache.export.json') }}</a>
      </div>
      <p class="note">{{ __('cache_pages.cache.export.note') }}</p>
    </div>
    <div class="panel">
      <h2>{{ __('cache_pages.cache.import_export.heading') }}</h2>
      <input type="file" id="jFile" accept=".json,application/json">
      <div class="actions" style="justify-content:flex-start"><button class="btn" id="jGo" disabled>{{ __('cache_pages.cache.import_export.submit') }}</button></div>
      <p class="note">{{ __('cache_pages.cache.import_export.note') }}</p>
      <div class="msg bad" id="jError"></div>
      <div class="msg ok" id="jReport"></div>
    </div>
  </section>
</x-shell>

@include('partials.ask-dialog')

<script>
const API = '/api/v1/classification-cache';
const POLL_MS = 2000;
const ARROW = document.documentElement.dir === 'rtl' ? '←' : '→';
const $ = id => document.getElementById(id);
const T = JSON.parse($('app-config').dataset.t);
// The page language with Western digits.
const LOCALE = document.documentElement.lang + '-u-nu-latn';
const STAGES = ['queued', 'reading', 'budget', 'notes', 'pending_review'];
let file = null, polling = null;

/* ---------- upload ---------- */
const drop = $('drop'), input = $('file');
drop.onclick = () => input.click();
input.onchange = () => setFile(input.files[0]);
['dragenter','dragover'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.add('over'); }));
['dragleave','drop'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', ev => setFile(ev.dataTransfer.files[0]));

function setFile(f){
  if(!f) return;
  file = f;
  $('fileChip').innerHTML = `<span class="file-chip">📄 ${esc(f.name)} · ${(f.size/1024).toFixed(0)} KB</span>`;
  $('go').disabled = false;
  msg('upError'); msg('upWarn');
}

$('go').onclick = () => upload(false);

async function upload(force){
  if(!file) return;
  msg('upError'); msg('upWarn');
  $('go').disabled = true;
  const fd = new FormData();
  fd.append('file', file);
  if(force) fd.append('force', '1');
  try{
    const res  = await fetch(`${API}/import`, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
    const json = await res.json().catch(() => ({}));
    if(res.status === 409 && json.duplicate_of){
      if(await ask(json.message + '\n\n' + t('upload_again_q'), t('upload_again'))) return upload(true);
      $('go').disabled = false;
      return;
    }
    if(!res.ok){
      const errs = json.errors ? Object.values(json.errors).flat() : [json.message || t('unexpected')];
      msg('upError', '⚠️ ' + t('cannot_import') + list(errs));
      $('go').disabled = false;
      return;
    }
    // The API's messages in the page's language (`*_text`), else the Arabic it always sent.
    const warnings = json.warnings_text ?? json.warnings;
    if(warnings && warnings.length) msg('upWarn', '⚠️ ' + t('warnings') + list(warnings));
    watch(json);
    loadImports();
  }catch(e){
    msg('upError', '⚠️ ' + e.message);
    $('go').disabled = false;
  }
}

/* ---------- progress ---------- */
function watch(job){
  clearTimeout(polling);
  $('progress').classList.add('on');
  $('doneActions').innerHTML = '';
  showJob(job);
  const tick = async () => {
    try{
      const res = await fetch(job.status_url, { headers: { 'Accept': 'application/json' } });
      const json = await res.json();
      showJob(json);
      if(['queued', 'running'].includes(json.status)) polling = setTimeout(tick, POLL_MS);
      else { loadImports(); $('go').disabled = !file; }
    }catch(e){ polling = setTimeout(tick, POLL_MS * 2); }
  };
  polling = setTimeout(tick, POLL_MS);
}

function showJob(j){
  const current = j.status === 'running' ? (j.stage || 'reading') : j.status;
  const at = STAGES.indexOf(current);
  $('steps').innerHTML = STAGES.map((k, i) => `<span class="${i < at || j.status === 'applied' ? 'done' : i === at ? 'on' : ''}">${i < at ? '✓ ' : ''}${t('stages.' + k)}</span>`).join('<span>›</span>');
  const fill = $('barFill');
  if(j.status === 'running' && j.stage === 'notes' && j.progress.total){
    fill.classList.add('determinate');
    fill.style.width = Math.round(100 * j.progress.done / j.progress.total) + '%';
    $('stage').textContent = t('notes_batch', { done: fmt(j.progress.done), total: fmt(j.progress.total) });
  }else if(['queued', 'running'].includes(j.status)){
    fill.classList.remove('determinate'); fill.style.width = '';
    $('stage').textContent = (STAGES.includes(current) ? t('stages.' + current) : t('running')) + '…';
  }else{
    fill.classList.add('determinate'); fill.style.width = '100%';
  }
  $('hint').textContent = j.status === 'queued' ? t('queued_hint') : '';
  if(j.status === 'pending_review' || j.status === 'applied'){
    $('stage').textContent = '✓ ' + t('ready');
    const r = j.rows || {};
    $('hint').textContent = r.total ? t('rows_summary', { total: fmt(r.total), approved: fmt(r.approved), pending: fmt(r.pending_review) }) : '';
    $('doneActions').innerHTML = `<a class="btn" href="${j.review_url}">${t('open_review')} ${ARROW}</a>`;
  }
  if(j.status === 'failed'){
    $('stage').textContent = '⚠️ ' + t('failed');
    $('hint').textContent = j.error_text ?? j.error ?? '';
  }
}

/* ---------- previous imports ---------- */
async function loadImports(){
  try{
    const res = await fetch(`${API}/imports`, { headers: { 'Accept': 'application/json' } });
    const items = await res.json();
    $('imports').innerHTML = items.length ? items.map(i => `
      <tr>
        <td class="num">${i.id}</td>
        <td>${esc(i.file_name)}${i.error ? `<div class="muted">${esc(i.error_text ?? i.error)}</div>` : ''}</td>
        <td class="num">${(i.created_at || '').slice(0, 16).replace('T', ' ')}</td>
        <td><span class="pill s-${i.status}">${t('statuses.' + i.status)}</span></td>
        <td class="num">${i.rows ? fmt(i.rows.total) : '—'}</td>
        <td class="num">${i.rows ? `${fmt(i.rows.approved)} / ${fmt(i.rows.pending_review)}` : '—'}</td>
        <td class="num">${i.rows ? fmt(i.rows.written ?? i.rows.applied) : '—'}</td>
        <td>${['pending_review', 'applied'].includes(i.status) ? `<a class="btn sm ghost" href="${i.review_url}">${t('review')}</a>`
          : ['queued', 'running'].includes(i.status) ? `<button class="btn sm ghost" onclick='watch(${JSON.stringify({ status_url: i.status_url, status: i.status, stage: i.stage, progress: i.progress })})'>${t('follow')}</button>` : ''}</td>
      </tr>`).join('') : `<tr><td colspan="8" class="empty">${t('no_imports')}</td></tr>`;
  }catch(e){ $('imports').innerHTML = `<tr><td colspan="8" class="empty">⚠️ ${esc(e.message)}</td></tr>`; }
}

/* ---------- export ---------- */
function exportUrl(ext){
  const p = new URLSearchParams();
  [['source', 'xSource'], ['from', 'xFrom'], ['to', 'xTo']].forEach(([k, id]) => $(id).value && p.set(k, $(id).value));
  return `${API}/export.${ext}${p.toString() ? '?' + p : ''}`;
}
['xSource', 'xFrom', 'xTo'].forEach(id => $(id).onchange = links);
function links(){ $('xXlsx').href = exportUrl('xlsx'); $('xJson').href = exportUrl('json'); }
links();

/* ---------- import an export ---------- */
$('jFile').onchange = () => { $('jGo').disabled = !$('jFile').files[0]; msg('jError'); msg('jReport'); };
$('jGo').onclick = async () => {
  const f = $('jFile').files[0];
  if(!f) return;
  msg('jError'); msg('jReport');
  $('jGo').disabled = true;
  const fd = new FormData();
  fd.append('file', f);
  try{
    const res  = await Busy.request(`${API}/import-export`, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
    const json = res.json || {};
    if(!res.ok) throw new Error((json.errors && Object.values(json.errors)[0][0]) || json.message || t('error'));
    const e = json.entries;
    const unknown = e.skipped_unknown_sub_class.slice(0, 10).map(x => `${x.name} → ${x.sub_class}`);
    $('jReport').innerHTML = `✓ ${t('imported')}<dl>
      <dt>${t('added')}</dt><dd>${fmt(e.added)}</dd>
      <dt>${t('updated')}</dt><dd>${fmt(e.updated)}</dd>
      <dt>${t('kept_manual')}</dt><dd>${fmt(e.kept_manual)}</dd>
      <dt>${t('kept_existing')}</dt><dd>${fmt(e.kept_existing)}</dd>
      <dt>${t('skipped_unknown')}</dt><dd>${fmt(e.skipped_unknown_sub_class_count)}</dd>
      <dt>${t('mappings')}</dt><dd>${fmt(json.budget_mappings.stored)} / ${fmt(json.note_mappings.stored)}</dd>
      <dt>${t('name_layer')}</dt><dd>${t('name_layer_added', { n: fmt(json.name_layer.added) })}</dd>
    </dl>${e.ai_other_taxonomy_version ? `<p class="note">${t('other_taxonomy', { n: fmt(e.ai_other_taxonomy_version), export: esc(json.taxonomy_version.export), here: esc(json.taxonomy_version.here) })}</p>` : ''}
      ${unknown.length ? `<p class="note">${t('skipped_examples')}${list(unknown)}</p>` : ''}`;
    $('jReport').classList.add('on');
    loadImports();
  }catch(err){ msg('jError', '⚠️ ' + err.message); }
  $('jGo').disabled = false;
};

/* ---------- helpers ---------- */
function msg(id, html){ $(id).innerHTML = html || ''; $(id).classList.toggle('on', !!html); }
function list(items){ return `<ul>${items.map(x => `<li>${esc(x)}</li>`).join('')}</ul>`; }
function fmt(n){ return Number(n || 0).toLocaleString(LOCALE); }
/** A string of the page language: `key` may be dotted ('stages.queued'); :name placeholders are filled from vars. Never the raw key. */
function t(key, vars = {}){
  const s = key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), T);
  return typeof s === 'string' ? s.replace(/:([a-z_]+)/g, (m, k) => k in vars ? vars[k] : m) : '—';
}
function esc(s){ const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

loadImports();
</script>
</body>
</html>
