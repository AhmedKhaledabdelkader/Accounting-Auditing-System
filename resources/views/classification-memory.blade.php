@extends('layouts.app')
@section('title', __('memory_page.title'))
@section('subtitle', __('memory_page.sub'))
@inject('taxonomy', 'App\Repositories\Contracts\TaxonomyRepositoryInterface')
@php
  // A side-pair member is stored as the account's kind; each file's balance decides the class: [debit class, credit class].
  // A class in two pairs flips by the first (ClassificationCache::pairOf).
  $pairs = [];
  foreach (config('classification.side_pairs') as $p) {
    foreach (($p['enabled'] ?? false) ? ['debit' => $p['debit'], 'credit' => $p['credit']] : [] as $side => $members) {
      foreach ($members as $m) {
        $pairs[$m] ??= $side === 'debit' ? [$m, $p['credit'][0]] : [$p['debit'][0], $m];
      }
    }
  }
  $config = [
    'entriesUrl' => route('classification-cache.entries', [], false),
    'reclassifyUrl' => route('classification-cache.entries.reclassify', [], false),
    'deleteUrl' => route('classification-cache.entries.destroy-many', [], false),
    'storeUrl' => route('classification-cache.entries.store', [], false),
    // A side-pair member is stored as the account's kind: each file's balance decides the side (shown under the class).
    'pairs' => $pairs,
    'canEdit' => (bool) auth()->user()?->can('cache.review'),
    'canDelete' => (bool) auth()->user()?->can('cache.delete'),
    'taxonomy' => $taxonomy->map(),
    't' => __('memory_page.js'),
  ];
@endphp
@section('content')
  <div class="panel"><p class="note">{{ __('memory_page.how') }}</p></div>

  <div class="toolbar mm-bar">
    <input type="search" id="q" placeholder="{{ __('memory_page.search') }}">
    <select id="source">
      <option value="">{{ __('memory_page.sources.all') }}</option>
      <option value="ai">{{ __('memory_page.js.sources.ai') }}</option>
      <option value="manual_correction">{{ __('memory_page.js.sources.manual_correction') }}</option>
      <option value="imported">{{ __('memory_page.js.sources.imported') }}</option>
    </select>
    <button type="button" class="btn ghost small" id="sub" data-value="">{{ __('memory_page.all_classes') }}</button>
    <button type="button" class="btn ghost small" id="subClear" hidden>✕</button>
    @can('cache.review')<button type="button" class="btn small" id="addOpen">+ {{ __('memory_page.add.open') }}</button>@endcan
  </div>

  @can('cache.review')
  <div class="panel" id="addBox" hidden>
    <p class="note">{{ __('memory_page.add.how') }}</p>
    <div class="mm-add">
      <label>{{ __('memory_page.cols.code') }}<input id="aCode" dir="ltr" placeholder="{{ __('memory_page.add.optional') }}"></label>
      <label>{{ __('memory_page.cols.name') }}<input id="aName" required></label>
      <label>{{ __('memory_page.cols.budget_item') }}<input id="aBudget" placeholder="{{ __('memory_page.add.optional') }}"></label>
      <label>{{ __('memory_page.add.parent') }}<input id="aParent" placeholder="{{ __('memory_page.add.parent_hint') }}"></label>
      <label>{{ __('memory_page.cols.class') }}<button type="button" class="btn ghost small" id="aSub" data-value="">{{ __('memory_page.js.choose_class') }}</button></label>
    </div>
    <div class="mm-add-actions"><button class="btn small" id="addSave">{{ __('memory_page.add.save') }}</button><button class="btn ghost small" id="addCancel">{{ __('memory_page.add.cancel') }}</button></div>
  </div>
  @endcan

  <div class="msg" id="msg"></div>

  <div class="panel mm-bulk" id="bulk" hidden>
    <b id="nSel">0</b> {{ __('memory_page.selected') }}
    <button class="btn small" id="bulkSet">{{ __('memory_page.set_class') }}…</button>
    <button class="btn danger small" id="bulkDelete">{{ __('memory_page.delete') }}</button>
    <button class="btn ghost small" id="bulkClear">{{ __('memory_page.clear') }}</button>
  </div>

  <div class="panel mm-table">
    <table data-columns="memory">
      <thead><tr>
        <th><input type="checkbox" id="all" aria-label="{{ __('memory_page.select_all') }}"></th>
        <th>{{ __('memory_page.cols.code') }}</th><th>{{ __('memory_page.cols.name') }}</th><th>{{ __('memory_page.cols.budget_item') }}</th>
        <th>{{ __('memory_page.cols.class') }}</th><th>{{ __('memory_page.cols.source') }}</th><th>{{ __('memory_page.cols.confidence') }}</th>
        <th>{{ __('memory_page.cols.in_use') }}</th><th>{{ __('memory_page.cols.hits') }}</th><th>{{ __('memory_page.cols.updated') }}</th><th></th>
      </tr></thead>
      <tbody id="rows"><tr><td colspan="11" class="note">{{ __('memory_page.loading') }}</td></tr></tbody>
    </table>
  </div>
  <div class="toolbar mm-pages"><button class="btn ghost small" id="prev">{{ __('memory_page.prev') }}</button><span id="pageInfo"></span><button class="btn ghost small" id="next">{{ __('memory_page.next') }}</button></div>

  @include('partials.ask-dialog')

  <style>
    .mm-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .mm-bar input[type=search]{flex:1;min-width:240px}
    .mm-bulk{display:flex;gap:10px;align-items:center;flex-wrap:wrap;position:sticky;top:8px;z-index:5}
    .mm-table{overflow:auto;max-height:70vh;padding:0}
    .mm-table table{width:100%;border-collapse:collapse;font-size:13.5px}
    .mm-table th{position:sticky;top:0;background:var(--panel2);text-align:start;padding:10px 8px;white-space:nowrap}
    .mm-table td{padding:8px;border-top:1px solid var(--line);vertical-align:middle}
    .mm-table tr.sel td{background:var(--brand-soft)}
    .mm-table .main{display:block;color:var(--muted);font-size:12px}
    .mm-sub{max-width:280px}
    .mm-pages{display:flex;gap:12px;justify-content:center;align-items:center;margin-top:12px}
    .mm-add{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:10px}
    .mm-add label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted)}
    .mm-add-actions{display:flex;gap:10px;margin-top:12px}
    .mm-table .pair{display:block;color:var(--brand-ink);font-size:11.5px}
    .mm-table .num{direction:ltr;text-align:end;white-space:nowrap}
  </style>
@endsection

@push('scripts')
<script src="/js/class-picker.js?v={{ @filemtime(public_path('js/class-picker.js')) }}"></script>
<script>
(() => {
  const C = window.PageConfig, $ = id => document.getElementById(id);
  const t = (key, vars = {}) => {
    let s = key.split('.').reduce((o, k) => o?.[k], C.t) ?? key;
    for(const [k, v] of Object.entries(vars)) s = s.split(':' + k).join(v);
    return s;
  };
  const esc = s => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
  const LOCALE = document.documentElement.lang + '-u-nu-latn';
  let page = 1, last = 1, entries = [];
  const selected = new Set();

  // The class picker with search (public/js/class-picker.js).
  const pick = (anchor, current = null) => ClassPicker.open(anchor, { taxonomy: C.taxonomy, current, words: C.t.picker });
  if(!C.canEdit) $('bulkSet').hidden = true;
  if(!C.canDelete) $('bulkDelete').hidden = true;

  function say(text, kind = 'ok'){ $('msg').className = `msg ${kind}`; $('msg').textContent = text; $('msg').style.display = text ? 'block' : 'none'; }

  async function call(url, opts = {}){
    const res = await fetch(url, { headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, ...opts });
    const json = await res.json().catch(() => ({}));
    if(!res.ok) throw new Error((json.errors && Object.values(json.errors)[0][0]) || json.message || t('error'));
    return json;
  }

  async function load(){
    const q = new URLSearchParams({ page, per_page: 50 });
    for(const f of ['q', 'source']) if($(f).value.trim()) q.set(f, $(f).value.trim());
    if($('sub').dataset.value) q.set('sub_class', $('sub').dataset.value);
    try{
      const json = await call(`${C.entriesUrl}?${q}`);
      entries = json.data; last = json.last_page || 1;
      $('pageInfo').textContent = t('page', { page: json.current_page, last, total: json.total });
      $('prev').disabled = page <= 1; $('next').disabled = page >= last;
      draw();
    }catch(e){ say('⚠️ ' + e.message, 'bad'); }
  }

  function draw(){
    $('rows').innerHTML = entries.length ? entries.map(e => `
      <tr data-id="${e.id}" class="${selected.has(e.id) ? 'sel' : ''}">
        <td><input type="checkbox" class="pick" ${selected.has(e.id) ? 'checked' : ''}></td>
        <td class="num">${esc(e.account_code ?? '')}</td>
        <td>${esc(e.original_name)}${e.row_type !== 'account' ? ` <span class="badge b-muted">${esc(t('not_account'))}</span>` : ''}</td>
        <td>${esc(e.budget_item ?? '')}</td>
        <td class="cls">${C.pairs[e.sub_class] && !e.side_locked
          ? `${esc(t('pair_debit', { class: C.pairs[e.sub_class][0] }))}<br>${esc(t('pair_credit', { class: C.pairs[e.sub_class][1] }))}<span class="pair">${esc(t('pair'))}</span>`
          : `${esc(e.sub_class ?? '—')}<span class="main">${esc(e.main_class ?? '')}</span>`}</td>
        <td>${esc(t('sources.' + e.source))}</td>
        <td class="num">${e.confidence ?? ''}%</td>
        <td>${e.in_use ? `<span class="badge b-ok">${esc(t('yes'))}</span>` : `<span class="badge b-muted">${esc(t('no'))}</span>`}</td>
        <td class="num">${e.hit_count}</td>
        <td class="num">${e.updated_at ? new Date(e.updated_at).toLocaleDateString(LOCALE) : ''}</td>
        <td>${C.canEdit ? `<button class="btn ghost small edit">${esc(t('edit'))}</button>` : ''}</td>
      </tr>`).join('') : `<tr><td colspan="11" class="note">${esc(t('none'))}</td></tr>`;
    $('all').checked = entries.length > 0 && entries.every(e => selected.has(e.id));
    showBulk();
  }

  function showBulk(){ $('bulk').hidden = selected.size === 0; $('nSel').textContent = selected.size; }

  $('rows').addEventListener('change', ev => {
    if(!ev.target.classList.contains('pick')) return;
    const id = Number(ev.target.closest('tr').dataset.id);
    ev.target.checked ? selected.add(id) : selected.delete(id);
    ev.target.closest('tr').classList.toggle('sel', ev.target.checked);
    $('all').checked = entries.every(e => selected.has(e.id));
    showBulk();
  });
  $('all').onchange = () => { for(const e of entries) $('all').checked ? selected.add(e.id) : selected.delete(e.id); draw(); };
  $('bulkClear').onclick = () => { selected.clear(); draw(); };

  // One row: its class picked in place, saved as soon as it's chosen.
  $('rows').addEventListener('click', ev => {
    if(!ev.target.classList.contains('edit')) return;
    const tr = ev.target.closest('tr'), e = entries.find(x => x.id === Number(tr.dataset.id));
    pick(ev.target, e.sub_class).then(sub => sub && sub !== e.sub_class && reclassify([e.id], sub));
  });

  async function reclassify(ids, sub){
    if(!sub) return;
    try{
      const json = await call(C.reclassifyUrl, { method: 'POST', body: JSON.stringify({ ids, sub_class: sub }) });
      for(const saved of json.data){ const i = entries.findIndex(x => x.id === saved.id); if(i >= 0) entries[i] = saved; }
      say(t('saved', { count: json.data.length, class: sub }));
      draw();
    }catch(e){ say('⚠️ ' + e.message, 'bad'); }
  }

  $('bulkSet').onclick = async () => {
    const sub = await pick($('bulkSet'));
    if(!sub) return;
    if(!await ask(t('confirm_set', { count: selected.size, class: sub }), t('set_class'))) return;
    await reclassify([...selected], sub);
    selected.clear(); draw();
  };
  $('bulkDelete').onclick = async () => {
    if(!await ask(t('confirm_delete', { count: selected.size }), t('delete'))) return;
    try{
      const json = await call(C.deleteUrl, { method: 'POST', body: JSON.stringify({ ids: [...selected] }) });
      selected.clear(); say(t('deleted', { count: json.deleted })); load();
    }catch(e){ say('⚠️ ' + e.message, 'bad'); }
  };

  let typing = null;
  $('q').oninput = () => { clearTimeout(typing); typing = setTimeout(() => { page = 1; load(); }, 300); };
  $('source').onchange = () => { page = 1; load(); };
  const allClasses = $('sub').textContent;
  const filterBy = sub => { $('sub').dataset.value = sub ?? ''; $('sub').textContent = sub ?? allClasses; $('subClear').hidden = !sub; page = 1; load(); };
  $('sub').onclick = async () => { const sub = await pick($('sub'), $('sub').dataset.value || null); if(sub) filterBy(sub); };
  $('subClear').onclick = () => filterBy(null);

  // A new account, saved as a manual correction.
  if($('addOpen')){
    const chooseLabel = $('aSub').textContent;
    $('addOpen').onclick = () => { $('addBox').hidden = false; $('aName').focus(); };
    $('addCancel').onclick = () => { $('addBox').hidden = true; };
    $('aSub').onclick = async () => { const sub = await pick($('aSub'), $('aSub').dataset.value || null); if(sub){ $('aSub').dataset.value = sub; $('aSub').textContent = sub; } };
    $('addSave').onclick = async () => {
      const body = { account_code: $('aCode').value.trim() || null, account_name: $('aName').value.trim(), budget_item: $('aBudget').value.trim() || null,
        parent: $('aParent').value.trim() || null, sub_class: $('aSub').dataset.value };
      if(!body.account_name || !body.sub_class){ say(t('add_missing'), 'warn'); return; }
      try{
        const json = await call(C.storeUrl, { method: 'POST', body: JSON.stringify(body) });
        say(t(json.replaced ? 'add_replaced' : 'add_saved', { name: json.data.original_name, class: json.data.sub_class }));
        for(const id of ['aCode', 'aName', 'aBudget', 'aParent']) $(id).value = '';
        $('aSub').dataset.value = ''; $('aSub').textContent = chooseLabel;
        $('q').value = json.data.original_name; page = 1; load();
      }catch(e){ say('⚠️ ' + e.message, 'bad'); }
    };
  }
  $('prev').onclick = () => { if(page > 1){ page--; load(); } };
  $('next').onclick = () => { if(page < last){ page++; load(); } };
  // ?q=: opened from elsewhere on an account.
  $('q').value = new URLSearchParams(location.search).get('q') ?? '';
  load();
})();
</script>
@endpush
