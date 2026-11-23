<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('classifier_page.title') }}</title>
@include('partials.theme-head')
<style>
  /* This page's own styles; colours, font and the page frame are in theme.css. */

  /* dropzone */
  .drop{border:2px dashed var(--line);border-radius:22px;background:linear-gradient(180deg,var(--panel),var(--panel2));
    padding:44px 20px;text-align:center;cursor:pointer;transition:.25s;position:relative;overflow:hidden}
  .drop:hover,.drop.over{border-color:var(--brand);background:var(--brand-soft)}
  .drop .ico{width:56px;height:56px;margin:0 auto 12px;border-radius:14px;background:var(--brand-soft);color:var(--brand);display:grid;place-items:center}
  .drop .ico svg{width:26px;height:26px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  .drop h3{margin:0 0 6px;font-size:20px}
  .drop p{margin:0;color:var(--muted);font-size:14px}
  .file-chip{display:inline-flex;gap:8px;align-items:center;margin-top:14px;padding:8px 16px;border-radius:999px;
    background:var(--brand-soft);border:1px solid var(--brand);font-size:14px}
  .btn{border:0;cursor:pointer;font-family:inherit;font-weight:600;font-size:14.5px;border-radius:12px;padding:13px 28px;
    color:var(--on-brand);background:var(--brand);transition:background .15s;box-shadow:var(--shadow-btn)}
  .btn:hover{background:var(--brand-ink)} .btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
  .btn.ghost{background:var(--panel);border:1px solid var(--line);box-shadow:none;color:var(--text)}
  .actions{display:flex;gap:12px;justify-content:center;margin-top:18px}
  .company-pick{display:flex;gap:10px;align-items:center;justify-content:center;flex-wrap:wrap;margin-top:14px}
  .company-pick select,.company-pick input{padding:8px 10px;border-radius:8px;border:1px solid var(--line,#ddd);font:inherit;min-width:220px}

  /* loader */
  .loader{display:none;margin-top:26px;padding:28px;border-radius:20px;background:var(--panel);border:1px solid var(--line);text-align:center}
  .loader.on{display:block}
  .bar{height:6px;border-radius:99px;background:var(--line);overflow:hidden;margin:16px auto 0;max-width:420px}
  .bar i{display:block;height:100%;width:40%;border-radius:99px;background:linear-gradient(90deg,var(--brand),var(--brand2));
    animation:slide 1.2s infinite ease-in-out}
  @keyframes slide{from{transform:translateX(-100%)}to{transform:translateX(250%)}}
  [dir=rtl] .bar i{animation-name:slide-rtl} @keyframes slide-rtl{from{transform:translateX(100%)}to{transform:translateX(-250%)}}
  #step{font-weight:700}
  .bar i.determinate{animation:none;margin:0;transform:none;transition:width .4s ease}
  /* While it works: a ledger being written, a light passing over its lines (transform only). */
  .ledger-skel{max-width:420px;margin:20px auto 0;display:grid;gap:9px}
  .ledger-skel div{display:grid;grid-template-columns:44px 1fr 72px 72px;gap:10px;animation:rise-in .4s cubic-bezier(.2,.7,.2,1) both}
  .ledger-skel div:nth-child(2){animation-delay:.08s} .ledger-skel div:nth-child(3){animation-delay:.16s} .ledger-skel div:nth-child(4){animation-delay:.24s}
  .ledger-skel i{height:9px;border-radius:99px;background:var(--line2);position:relative;overflow:hidden}
  .ledger-skel i:nth-child(2){width:calc(55% + var(--w, 0) * 1%)}
  .ledger-skel i::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,var(--brand-soft),transparent);
    transform:translateX(-100%);animation:sheen 1.6s ease-in-out infinite}
  .ledger-skel div:nth-child(2) i::after{animation-delay:.15s} .ledger-skel div:nth-child(3) i::after{animation-delay:.3s} .ledger-skel div:nth-child(4) i::after{animation-delay:.45s}
  @keyframes sheen{to{transform:translateX(100%)}}
  .stamp-card{display:none;place-items:center;border-style:dashed}
  .stamp-card.on{display:grid}
  .pct{font-size:40px;font-weight:800;margin:10px 0 0;background:linear-gradient(135deg,var(--brand),var(--brand2));
    -webkit-background-clip:text;background-clip:text;color:transparent;font-variant-numeric:tabular-nums}
  .times{display:flex;justify-content:center;gap:18px;flex-wrap:wrap;margin-top:12px;color:var(--muted);font-size:14px}
  .times b{color:var(--text);font-variant-numeric:tabular-nums}
  .req{color:var(--info);font-size:13px;max-width:220px}

  .error{display:none;margin-top:20px;padding:14px 18px;border-radius:14px;background:var(--bad-soft);
    border:1px solid var(--bad);color:var(--bad-ink)}
  .error.on{display:block}
  .warnings{display:none;margin-bottom:18px;padding:14px 18px;border-radius:14px;background:var(--warn-soft);
    border:1px solid var(--warn);color:var(--warn-ink);font-size:14px}
  .warnings.on{display:block} .warnings ul{margin:6px 0 0;padding-inline-start:20px}
  .warnings.info{background:var(--brand-soft);border-color:var(--brand);color:var(--brand-ink)}
  /* partial result: some rows came back unclassified — separate from the normal warnings */
  .partial{display:none;margin-bottom:18px;padding:16px 18px;border-radius:14px;background:var(--bad-soft);
    border:2px solid var(--bad);color:var(--bad-ink);font-size:15px;font-weight:700}
  .partial.on{display:block} .partial a{color:inherit;margin-inline-start:8px}

  /* results */
  #results{display:none;margin-top:34px}
  #results.on{display:block;animation:up .5s ease}
  @keyframes up{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:22px}
  .card{padding:18px 20px;border-radius:18px;background:var(--panel);border:1px solid var(--line)}
  .card small{color:var(--muted);font-size:13px}
  .card b{display:block;font-size:32px;margin-top:4px;font-weight:800}
  .card.warn b{color:var(--warn)} .card.ok b{color:var(--ok)}
  .card .sub{display:block;color:var(--muted);font-size:12px;margin-top:4px}
  .card .est{font-size:11px;padding:1px 8px;border-radius:999px;border:1px solid var(--line);color:var(--muted);margin-inline-start:6px}

  .toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:14px}
  .toolbar input[type=search]{flex:1;min-width:220px;padding:12px 16px;border-radius:12px;border:1px solid var(--line);
    background:var(--panel);color:var(--text);font-family:inherit;font-size:14px;outline:none}
  .toolbar input[type=search]:focus{border-color:var(--brand)}
  .toggle{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:14px;cursor:pointer;user-select:none}

  .table-wrap{overflow:auto;border-radius:18px;border:1px solid var(--line);background:var(--panel);max-height:70vh}
  table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:1500px}
  thead th{position:sticky;top:0;background:var(--panel2);color:var(--muted);font-weight:700;text-align:start;
    padding:14px 10px;border-bottom:1px solid var(--line);white-space:nowrap;z-index:1}
  tbody td{padding:11px 10px;border-bottom:1px solid var(--line2);vertical-align:middle}
  tbody tr:hover{background:var(--panel2)}
  tr.review{background:var(--bad-soft)} tr.review:hover{background:var(--bad-soft)}
  .num{font-variant-numeric:tabular-nums;direction:ltr;text-align:left;white-space:nowrap}
  .pill{display:inline-block;padding:4px 12px;border-radius:99px;font-size:12.5px;font-weight:700;white-space:nowrap}
  .p-bs{background:var(--brand-soft);color:var(--brand-ink)}
  .p-is{background:var(--info-soft);color:var(--info)}
  .p-none{background:var(--panel2);color:var(--muted)}
  .conf{display:flex;align-items:center;gap:8px;min-width:120px}
  .conf .track{flex:1;height:8px;border-radius:99px;background:var(--line);overflow:hidden}
  .conf .fill{height:100%;border-radius:99px}
  .conf span{font-weight:800;width:38px;direction:ltr;text-align:left}
  .tag{padding:3px 10px;border-radius:8px;font-size:12px;font-weight:700}
  .tag.y{background:var(--bad-soft);color:var(--bad)} .tag.n{background:var(--ok-soft);color:var(--ok)}
  .note{color:var(--muted);font-size:13px;max-width:260px}
  .note .high{display:block;color:var(--bad);font-weight:700;margin-bottom:4px}
  .empty{padding:40px;text-align:center;color:var(--muted)}
  .col-group{color:var(--muted);font-size:11px;text-align:center}

  /* column mapping confirmation */
  .confirm{display:none;margin-top:26px;padding:24px;border-radius:20px;background:var(--panel);border:1px solid var(--warn)}
  .confirm.on{display:block;animation:up .4s ease}
  .confirm h3{margin:0 0 6px;font-size:19px} .confirm .sub{margin:0 0 16px;color:var(--muted);font-size:14px}
  .confirm .problems{margin:0 0 16px;padding:12px 16px;border-radius:12px;background:var(--warn-soft);color:var(--warn-ink);font-size:14px}
  .confirm .problems.flash{animation:flash 1.2s ease 2}
  @keyframes flash{50%{box-shadow:0 0 0 3px var(--bad)}}
  .cf-status{flex-basis:100%;text-align:center;font-size:14px;min-height:20px}
  .cf-status.bad{color:var(--bad);font-weight:700} .cf-status.warn{color:var(--warn);font-weight:700}
  .actions{flex-wrap:wrap}
  .spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;
    animation:rot .8s linear infinite;vertical-align:-2px;margin-inline-end:6px}
  @keyframes rot{to{transform:rotate(360deg)}}
  .warnings li.high{color:var(--bad);font-weight:700}
  .warnings li{margin:8px 0;line-height:1.7}
  .warnings .btn.small{display:inline-block;font-size:12.5px;padding:3px 12px;border-radius:8px;box-shadow:none;margin-inline-start:8px;text-decoration:none}
  .picks{display:none;align-items:center;gap:10px;flex-wrap:wrap;margin:-4px 0 14px;padding:10px 14px;border-radius:14px;
    border:1px solid var(--brand);background:var(--brand-soft);font-size:14px;position:sticky;top:8px;z-index:6}
  .picks.on{display:flex}
  .picks .btn{font-size:13px;padding:7px 14px;border-radius:10px;box-shadow:none}
  .picks select{font:inherit;font-size:13px;border:1px solid var(--line);border-radius:8px;padding:5px 8px;max-width:300px}
  tr.picked td{background:var(--brand-soft)}
  .ok-btn{display:block;margin-top:4px;font:inherit;font-size:11.5px;cursor:pointer;border:1px solid var(--ok);color:var(--ok);background:none;border-radius:8px;padding:1px 8px;white-space:nowrap}
  /* Motion (theme.css keyframes): the result's cards one after another, banners and the first rows of a new result. */
  #results.on .card{animation:rise-in .45s cubic-bezier(.2,.7,.2,1) both}
  #results.on .card:nth-child(2){animation-delay:.05s} #results.on .card:nth-child(3){animation-delay:.1s}
  #results.on .card:nth-child(4){animation-delay:.15s} #results.on .card:nth-child(n+5){animation-delay:.2s}
  .warnings.on,.partial.on,.edits.on,.picks.on,.loader.on,.error.on{animation:rise-in .35s cubic-bezier(.2,.7,.2,1) both}
  tbody.fresh tr{animation:rise-in .35s cubic-bezier(.2,.7,.2,1) both;animation-delay:calc(min(var(--i, 0), 20) * 18ms)}
  tbody tr{transition:background .25s}
  #results .card{transition:transform .2s,box-shadow .2s,border-color .2s}
  #results .card:hover{transform:translateY(-2px);box-shadow:var(--shadow);border-color:var(--brand-soft)}
  @media (prefers-reduced-motion:reduce){#results.on .card,.warnings.on,.partial.on,.edits.on,.picks.on,.loader.on,.error.on,tbody.fresh tr,.ledger-skel div,.ledger-skel i::after{animation:none}#results .card:hover{transform:none}}
  .row-acts{white-space:nowrap} .row-acts button{background:none;border:0;cursor:pointer;font-size:14px;padding:2px 4px;opacity:.6} .row-acts button:hover{opacity:1}
  tr.added td.name::after{content:" ＋";color:var(--ok)}
  .row-dialog{width:min(640px,calc(100vw - 32px))} .row-dialog h3{margin:0 0 12px}
  .rd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:10px}
  .rd-grid label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted)}
  .rd-grid input{font:inherit;padding:7px 9px;border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text)}
  .rd-error{color:var(--bad);font-size:13px;min-height:18px;margin:0 0 8px}
  .sub-cell .src{display:block;font-size:11.5px;color:var(--muted);padding:0 6px}
  .confirm .problems ul{margin:6px 0 0;padding-inline-start:20px}
  .confirm .problems.bad{background:var(--bad-soft);color:var(--bad-ink)}
  .confirm table{min-width:0;font-size:13px} .confirm .table-wrap{max-height:none;margin-bottom:16px}
  .confirm select,.confirm input[type=text],.confirm input[type=number]{background:var(--panel2);color:var(--text);border:1px solid var(--line);
    border-radius:10px;padding:8px 10px;font-family:inherit;font-size:13px}
  .confirm select.set{border-color:var(--brand)}
  .confirm .rowsctl{display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-bottom:16px;font-size:14px}
  .confirm .samples{color:var(--muted);font-size:12.5px}
  .confirm .raw td{white-space:nowrap;max-width:180px;overflow:hidden;text-overflow:ellipsis;color:var(--muted)}
  .confirm .raw td.rn{color:var(--text);font-weight:700}
  /* Row corrections: edited on the page, saved to the run and the classification memory only with the Save edits button. */
  .sub-edit{background:none;border:1px dashed transparent;color:inherit;font:inherit;cursor:pointer;padding:3px 6px;border-radius:8px;text-align:start}
  .sub-edit:hover{border-color:var(--brand)}
  .sub-edit .pen{opacity:.45;font-size:11px;margin-inline-start:4px}
  .sub-select{font:inherit;font-size:13px;background:var(--panel2);color:var(--text);border:1px solid var(--brand);border-radius:8px;padding:4px 6px;max-width:260px}
  tr.edited td{background:var(--warn-soft)}
  tr.edited .sub-edit{color:var(--warn);font-weight:700}
  .edits{display:none;align-items:center;gap:10px;flex-wrap:wrap;margin:-4px 0 14px;padding:10px 14px;border-radius:14px;
    border:1px solid var(--warn-line);background:var(--warn-soft);color:var(--warn-ink);font-size:14px}
  .edits.on{display:flex}
  .edits .btn{font-size:13px;padding:7px 14px;border-radius:10px;box-shadow:none}
  .save-msg{font-size:13.5px;color:var(--ok)}
  .save-msg.bad{color:var(--bad)}
</style>
</head>
<body>
<x-shell :title="__('classifier_page.title')" :subtitle="__('classifier_page.subtitle')">

  <!-- upload -->
  <div class="drop" id="drop">
    <div class="ico"><svg viewBox="0 0 24 24"><path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/></svg></div>
    <h3>{{ __('classifier_page.drop.heading') }}</h3>
    <p>{{ __('classifier_page.drop.formats') }}</p>
    <div id="fileChip"></div>
    <input type="file" id="file" accept=".xlsx,.xlsm,.xls,.xml,.ods,.csv,.txt,.htm,.html" hidden>
  </div>

  @can('companies.view')
  <!-- The company the trial balance belongs to: its code-group marks apply, and a report from the run starts with it. -->
  <div class="company-pick">
    <label for="company">{{ __('classifier_page.company.label') }}</label>
    <select id="company">
      <option value="">{{ __('classifier_page.company.none') }}</option>
      @foreach (\App\Models\Company::orderBy('name_ar')->get(['id', 'name_ar']) as $c)
        <option value="{{ $c->id }}">{{ $c->name_ar }}</option>
      @endforeach
      @can('companies.manage')<option value="__new">{{ __('classifier_page.company.new') }}</option>@endcan
    </select>
    <input id="companyNew" placeholder="{{ __('classifier_page.company.new_name') }}" hidden>
  </div>
  <!-- What the chosen client already uploaded in the portal: pick the trial balance instead of uploading it again. -->
  <div id="clientFiles" class="company-pick" hidden></div>
  @endcan

  <div class="actions">
    <button class="btn" id="go" disabled>{{ __('classifier_page.actions.start') }}</button>
    <button class="btn ghost" id="reset" style="display:none">{{ __('classifier_page.actions.new_file') }}</button>
  </div>

  <div class="loader" id="loader">
    <div id="step">{{ __('classifier_page.loader.uploading') }}</div>
    <div class="pct" id="pct">0%</div>
    <div class="bar"><i id="barFill"></i></div>
    <div class="times">
      <span>{{ __('classifier_page.loader.elapsed') }} <b id="elapsed">0</b></span>
      <span id="etaWrap">{{ __('classifier_page.loader.remaining') }} <b id="eta">{{ __('classifier_page.js.eta_calculating') }}</b></span>
    </div>
    <div class="ledger-skel" aria-hidden="true">@foreach ([30, 12, 40, 20] as $w)<div style="--w:{{ $w }}"><i></i><i></i><i></i><i></i></div>@endforeach</div>
    <p id="stepHint" style="color:var(--muted);font-size:13px;margin:14px 0 0">{{ __('classifier_page.loader.hint') }}</p>
    <button class="btn ghost" id="cancelQueued" style="display:none;margin-top:14px">{{ __('classifier_page.loader.cancel') }}</button>
  </div>

  <!-- the run waits: confirm or correct the column mapping -->
  <section class="confirm" id="confirm">
    <h3>{{ __('classifier_page.confirm.heading') }}</h3>
    <p class="sub" id="confirmSub"></p>
    <div class="problems" id="confirmIssues"></div>
    <div class="rowsctl">
      <label>{{ __('classifier_page.confirm.header_rows') }} <input type="text" id="cfHeaders" size="8" placeholder="{{ __('classifier_page.confirm.header_rows_placeholder') }}"></label>
      <label>{{ __('classifier_page.confirm.first_row') }} <input type="number" id="cfFirst" min="1" style="width:90px"></label>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>{{ __('classifier_page.confirm.column') }}</th><th>{{ __('classifier_page.confirm.header') }}</th><th>{{ __('classifier_page.confirm.samples') }}</th><th>{{ __('classifier_page.confirm.read_as') }}</th></tr></thead>
      <tbody id="cfColumns"></tbody>
    </table></div>
    <p class="sub">{{ __('classifier_page.confirm.raw_rows') }}</p>
    <div class="table-wrap"><table class="raw"><tbody id="cfRows"></tbody></table></div>
    <div class="problems" id="cfNewDoubts" style="display:none"></div>
    <div class="actions">
      <button class="btn" id="cfConfirm">{{ __('classifier_page.confirm.confirm') }}</button>
      <button class="btn" id="cfAnyway" style="display:none">{{ __('classifier_page.confirm.confirm_anyway') }}</button>
      <button class="btn ghost" id="cfCancel">{{ __('classifier_page.confirm.cancel') }}</button>
      <div class="cf-status" id="cfStatus" role="status" aria-live="polite"></div>
    </div>
  </section>

  <div class="error" id="error"></div>

  <!-- results -->
  <section id="results">
    <div class="partial" id="partial"></div>
    <div class="warnings" id="warnings"></div>
    <div class="warnings info" id="info"></div>
    <!-- Code groups worth marking as one kind of account: a link to the rules page, the user confirms there. -->
    <div class="warnings info" id="proposals"></div>
    <div class="cards">
      <div class="card"><small>{{ __('classifier_page.cards.total') }}</small><b id="cTotal">0</b></div>
      <div class="card warn"><small>{{ __('classifier_page.cards.review') }}</small><b id="cReview">0</b></div>
      <div class="card ok"><small>{{ __('classifier_page.cards.avg') }}</small><b id="cAvg">0%</b></div>
      <div class="card" id="cTimeCard" style="display:none"><small>{{ __('classifier_page.cards.time') }}</small><b id="cTime">—</b></div>
      <div class="card" id="cCostCard" style="display:none"><small>{{ __('classifier_page.cards.cost') }}</small><b id="cCost" dir="ltr">—</b><span class="sub" id="cCostSub"></span></div>
      <div class="card"><small>{{ __('classifier_page.cards.sources') }}</small><b id="cSources">—</b><span class="sub" id="cSourcesSub"></span></div>
      <div class="card stamp-card" id="stampCard"><span class="stamp"><b>✓ {{ __('classifier_page.cards.balanced') }}</b><small>{{ __('classifier_page.cards.balanced_sub') }}</small></span></div>
      <div class="card" id="cCacheCard" style="display:none"><small>{{ __('classifier_page.cards.cache') }}</small><b id="cCache">—</b><span class="sub" id="cCacheSub"></span></div>
      <div class="card ok" id="cSavedCard" style="display:none"><small>{{ __('classifier_page.cards.saved') }} <span class="est">{{ __('classifier_page.cards.estimate') }}</span></small><b id="cSaved" dir="ltr">—</b><span class="sub" id="cSavedSub"></span></div>
    </div>

    <div class="toolbar">
      <input type="search" id="q" placeholder="{{ __('classifier_page.toolbar.search') }}">
      <label class="toggle"><input type="checkbox" id="onlyReview"> {{ __('classifier_page.toolbar.only_review') }}</label>
      <button class="btn ghost" id="csv">{{ __('classifier_page.toolbar.csv') }}</button>
      <a class="btn" id="fsNew" style="display:none;text-decoration:none">{{ __('classifier_page.toolbar.fs_new') }}</a>
      @can('classify.correct')<button class="btn ghost" id="addRow">+ {{ __('classifier_page.rows.add') }}</button>@endcan
    </div>
    <!-- After a row was added, edited or removed: the trial balance's totals, when they no longer balance. -->
    <div class="warnings" id="balanceWarn"></div>
    <div class="edits" id="edits">
      <span>✏️ {!! str_replace(':count', '<b id="nEdits">0</b>', e(__('classifier_page.edits.pending'))) !!}</span>
      <button class="btn" id="saveEdits">{{ __('classifier_page.edits.save') }}</button>
      <button class="btn ghost" id="undoEdits">{{ __('classifier_page.edits.undo') }}</button>
    </div>
    <span class="save-msg" id="saveMsg"></span>
    <!-- Several rows at once: one class for all of them, or their current class confirmed (both saved with Save edits). -->
    <div class="picks" id="picks">
      <span>☑️ {!! str_replace(':count', '<b id="nPicked">0</b>', e(__('classifier_page.picks.selected'))) !!}</span>
      @can('classify.correct')<button class="btn" id="pickOk">{{ __('classifier_page.picks.confirm_all') }}</button>@endcan
      <button class="btn" id="pickApply">{{ __('classifier_page.picks.apply') }}</button>
      <button class="btn ghost" id="pickClear">{{ __('classifier_page.picks.clear') }}</button>
    </div>

    <div class="table-wrap">
      <table data-columns="classifier">
        <thead id="thead"></thead>
        <tbody id="tbody"></tbody>
      </table>
    </div>
  </section>
</x-shell>

<!-- A row's number, name and amounts, added or edited after the classification. -->
<dialog class="ask row-dialog" id="rowDialog">
  <h3 id="rdTitle"></h3>
  <div class="rd-grid">
    <label>{{ __('classifier_page.js.cols.account_number') }}<input id="rdNumber" dir="ltr"></label>
    <label>{{ __('classifier_page.js.cols.account_name') }}<input id="rdName"></label>
  </div>
  <div class="rd-grid" id="rdAmounts"></div>
  <div class="rd-grid" id="rdClassWrap"><label>{{ __('classifier_page.js.cols.sub_class') }}<button type="button" class="btn ghost" id="rdClass" data-value=""></button></label></div>
  <p class="rd-error" id="rdError"></p>
  <div class="ask-btns"><button class="ask-yes" id="rdSave">{{ __('classifier_page.rows.save') }}</button><button class="ask-no" id="rdCancel">{{ __('classifier_page.rows.cancel') }}</button></div>
</dialog>

<!-- Blade-generated configuration is kept outside JavaScript so editors parse the script as pure JavaScript. -->
@inject('taxonomy', 'App\Repositories\Contracts\TaxonomyRepositoryInterface')
<div id="app-config" data-submit-url="{{ route('classify.jobs.submit', [], false) }}" data-can-mark="{{ auth()->user()?->can('taxonomy.manage') ? '1' : '' }}" data-can-correct="{{ auth()->user()?->can('classify.correct') ? '1' : '' }}"
     data-taxonomy="{{ json_encode($taxonomy->map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
     data-groups="{{ json_encode(config('classification.display_groups'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
     data-t="{{ json_encode(__('classifier_page.js'), JSON_UNESCAPED_UNICODE) }}" hidden></div>

@include('partials.ask-dialog')
<script src="/js/class-picker.js?v={{ @filemtime(public_path('js/class-picker.js')) }}"></script>

<script>
// Same-origin relative URLs: follow whatever host/port the app is served on.
const SUBMIT_URL = document.getElementById('app-config').dataset.submitUrl;
// sub_class => [statement, main_class, side]: the choices when correcting a row
const TAXONOMY = JSON.parse(document.getElementById('app-config').dataset.taxonomy);
// main class => the group shown in its place when the main class is the sub class's own name (config classification.display_groups)
const GROUPS = JSON.parse(document.getElementById('app-config').dataset.groups || '{}');
const shownMain = r => (r.main_class && r.main_class === r.sub_class && GROUPS[r.main_class]) || r.main_class;
// The page's words in its language (lang/*/classifier_page.php «js»): t(key, {name: value}) fills «:name»; nested keys are dotted.
const T = JSON.parse(document.getElementById('app-config').dataset.t);
function t(key, vars = {}){
  let s = key.split('.').reduce((o, k) => o?.[k], T) ?? key;
  for(const k of Object.keys(vars).sort((a, b) => b.length - a.length)) s = s.split(':' + k).join(vars[k]);
  return s;
}
// Dates in the page's language, Western digits.
const LOCALE = document.documentElement.lang + '-u-nu-latn';
const POLL_MS = 2000;
const $ = id => document.getElementById(id);
let rows = [], file = null, clientFile = null, hasAdj = false, polling = null, job = null;
const edits = new Map();   // row → the row as the server returned it, before the page's edit
const CAN_CORRECT = document.getElementById('app-config').dataset.canCorrect === '1';
const CONFIRM = '__confirm';   // the picker's «this class is right»: the row's class kept, saved as a correction

const STAGES = T.stages;

/* ---------- timer: elapsed time, estimated time left, and the real time a run took ----------
   Counts only the time the run is working: the wait for the user to confirm the columns is excluded. */
const timer = {
  activeMs: 0, since: null, tick: null, pct: 0,
  cls: null,   // {at, done}: when the first classification batch was seen, to measure the batch rate
  etaAt: null, // Date.now() at which the run should finish, re-estimated at every poll
  start(){ this.activeMs = 0; this.pct = 0; this.upload = 0; this.cap = null; this.cls = null; this.etaAt = null; this.resume(); },
  resume(){ this.since = Date.now(); this.cls = null; this.etaAt = null; this.run(); },
  pause(){ this.activeMs = this.elapsed(); this.since = null; this.halt(); },
  stop(){ const ms = this.elapsed(); this.pause(); return ms; },
  halt(){ clearInterval(this.tick); this.tick = null; },
  run(){ this.halt(); this.render(); this.tick = setInterval(() => this.render(), 1000); },
  elapsed(){ return this.activeMs + (this.since !== null ? Date.now() - this.since : 0); },
  progress(stage, done, total, pct){
    this.pct = Math.min(99, Math.max(this.pct, pct));
    if(stage !== 'classifying' || !total){ if(stage === 'checks') this.etaAt = Date.now() + 5000; this.render(); return; }
    const now = Date.now();
    if(!this.cls || done < this.cls.done) this.cls = { at: now, done };
    const finished = done - this.cls.done;
    if(finished > 0){
      const perBatch = (now - this.cls.at) / finished;
      this.etaAt = now + (total - done) * perBatch + 5000;   // + the final checks
    }
    this.render();
  },
  render(){
    // Between two polls the percentage eases toward the next stage's start (never reaching it), so the bar never looks stuck.
    if(this.cap && this.pct < this.cap - 1){
      this.pct = Math.min(this.cap - 1, this.pct + (this.cap - this.pct) * 0.06);
      $('pct').textContent = Math.round(this.pct) + '%';
      $('barFill').style.width = Math.max(3, this.pct) + '%';
    }
    $('elapsed').textContent = fmtDuration(this.elapsed());
    if(this.etaAt === null){ $('eta').textContent = t('eta_calculating'); return; }
    const left = this.etaAt - Date.now();
    $('eta').textContent = left <= 0 ? t('eta_almost') : t('eta_about', { time: fmtDuration(Math.ceil(left / 5000) * 5000) });
  },
};

function fmtDuration(ms){
  const s = Math.max(0, Math.round(ms / 1000));
  if(s < 60) return t('seconds', { s });
  const m = Math.floor(s / 60), r = s % 60;
  return r ? t('minutes_seconds', { m, s: r }) : t('minutes', { m });
}

/* ---------- file selection ---------- */
const drop = $('drop'), input = $('file');
drop.onclick = () => input.click();
input.onchange = () => setFile(input.files[0]);
['dragenter','dragover'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.add('over'); }));
['dragleave','drop'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', ev => setFile(ev.dataTransfer.files[0]));

function setFile(f){
  if(!f) return;
  file = f; clientFile = null;
  $('fileChip').innerHTML = `<span class="file-chip">📄 ${esc(f.name)} · ${(f.size/1024).toFixed(0)} KB</span>`;
  $('go').disabled = false;
  hideError();
}

/* ---------- the company: an existing one, or a new one created by its name ---------- */
const companySelect = $('company'), companyNew = $('companyNew');
if(companySelect) companySelect.onchange = () => { companyNew.hidden = companySelect.value !== '__new'; if(!companyNew.hidden) companyNew.focus(); showClientFiles(); };
// The last company chosen stays chosen (its code-group marks apply only to runs uploaded with it).
const COMPANY_KEY = 'classifier.company';
function pickCompany(id){
  if(companySelect && id && [...companySelect.options].some(o => o.value === id)) companySelect.value = id;
}
try{ pickCompany(localStorage.getItem(COMPANY_KEY)); }catch(e){}

/* ---------- the client's own uploads: the accountant picks the trial balance instead of uploading it again ---------- */
async function showClientFiles(){
  const box = $('clientFiles');
  if(!box) return;
  const id = companySelect && companySelect.value && companySelect.value !== '__new' ? companySelect.value : null;
  if(clientFile && clientFile.company !== id){ clientFile = null; $('fileChip').innerHTML = ''; $('go').disabled = !file; }
  box.hidden = true; box.replaceChildren();
  if(!id) return;
  const { clientFilePicker } = await import('/js/client-file-picker.js');
  const node = await clientFilePicker({ companyId: id, purpose: 'trial_balance', selectedId: clientFile ? clientFile.id : null, labels: { title: t('pick.title'), loading: t('pick.loading'), none: t('pick.none'), error: t('pick.error'), fine: t('pick.fine'), remarks: t('pick.remarks'), suggested: t('pick.suggested') },
    onPick: f => { clientFile = { id: f.id, company: id }; file = null; input.value = ''; $('fileChip').innerHTML = `<span class="file-chip">📄 ${esc(f.name)} · ${t('pick.from_client')}</span>`; $('go').disabled = false; hideError(); } });
  if(companySelect.value === id){ box.replaceChildren(node); box.hidden = false; }
}
showClientFiles();

async function chosenCompany(){
  if(!companySelect || !companySelect.value) return null;
  if(companySelect.value !== '__new') return companySelect.value;
  const name = companyNew.value.trim();
  if(!name) throw new Error(t('company_name_required'));
  const res  = await fetch('/api/v1/companies', { method:'POST', body: JSON.stringify({ name_ar: name }), headers:{ 'Accept':'application/json', 'Content-Type':'application/json' } });
  const json = await res.json().catch(() => ({}));
  if(!res.ok) throw new Error((json.errors ? Object.values(json.errors)[0][0] : null) || json.message || t('unexpected_error'));
  companySelect.add(new Option(json.name_ar, json.id, true, true), companySelect.options.length - 1);
  companyNew.hidden = true; companyNew.value = '';
  return json.id;
}

/* ---------- classify: submit a background job, then poll ---------- */
$('go').onclick = async () => {
  if(!file && !clientFile) return;
  hideError();
  $('results').classList.remove('on');
  $('fsNew').style.display = 'none';
  $('go').disabled = true;
  timer.start();
  showProgress({ status: 'uploading' });

  const fd = new FormData();
  if(clientFile) fd.append('client_file_id', clientFile.id); else fd.append('file', file);

  try{
    const company = await chosenCompany();
    if(company) fd.append('company_id', company);
    try{ company ? localStorage.setItem(COMPANY_KEY, company) : localStorage.removeItem(COMPANY_KEY); }catch(e){}
    // The upload is the first part of the run's percentage (real bytes sent); then the server queues the run and the polling takes over.
    const res  = await Busy.request(SUBMIT_URL, { method:'POST', body:fd, headers:{ 'Accept':'application/json' }, overlay:false, processing:false,
      onUpload: f => { timer.upload = f; showProgress({ status: 'uploading' }); } });
    const json = res.json || {};
    if(!res.ok){
      const firstErr = json.errors ? Object.values(json.errors)[0][0] : null;
      throw new Error(firstErr || json.message || t('unexpected_error'));
    }
    job = json;
    history.replaceState(null, '', `?run=${encodeURIComponent(json.id)}`);
    poll(json.status_url, Date.now());
  }catch(e){
    fail(e.message);
  }
};

function poll(url, startedAt){
  polling = setTimeout(async () => {
    try{
      const res  = await fetch(url, { headers:{ 'Accept':'application/json' } });
      const json = await res.json().catch(() => ({}));
      if(!res.ok) throw new Error(json.message || t('unexpected_error'));

      job = json;
      if(json.status === 'done'){ stopLoader(); showResult(json.result, timer.stop()); showCost(json); showReportLink(json.id); return; }
      if(json.status === 'needs_confirmation'){ timer.pause(); stopLoader(); showConfirmation(json); return; }
      if(json.status === 'failed' || json.status === 'expired'){ throw new Error(json.error_text ?? json.error ?? t('failed')); }
      if(json.status === 'cancelled'){ stopLoader(); showError(t('cancelled')); $('go').disabled = false; return; }

      showProgress(json, Date.now() - startedAt);
      poll(url, startedAt);
    }catch(e){
      fail(e.message);
    }
  }, POLL_MS);
}

/* Share of the whole run each stage starts at: classifying (the AI batches) takes most of the time. */
const STAGE_PCT = { uploading: 0, queued: 10, reading: 12, structure: 16, classifying: 22, checks: 96 };

function showProgress(job, waitedMs = 0){
  $('loader').classList.add('on');
  const stage = job.status === 'uploading' ? 'uploading' : (job.status === 'queued' ? 'queued' : (job.stage || 'reading'));
  const { done = 0, total = 0 } = job.progress || {};
  $('step').textContent = STAGES[stage] || STAGES.reading;

  let pct = STAGE_PCT[stage] ?? 3;
  if(stage === 'uploading') pct = Math.round((timer.upload || 0) * STAGE_PCT.queued);   // the upload is the first tenth
  if(stage === 'classifying' && total) pct += Math.round(done / total * (STAGE_PCT.checks - STAGE_PCT.classifying));
  timer.progress(stage, done, total, pct);
  timer.cap = { reading: STAGE_PCT.structure, structure: STAGE_PCT.classifying, checks: 99 }[stage] ?? null;
  const shown = Math.round(timer.pct);   // never goes backwards
  $('pct').textContent = shown + '%';

  const fill = $('barFill');
  if(stage === 'queued' && shown < 1){
    fill.classList.remove('determinate');
    fill.style.width = '';
  }else{
    fill.classList.add('determinate');
    fill.style.width = Math.max(3, shown) + '%';
  }
  $('cancelQueued').style.display = job.cancel_url ? 'inline-block' : 'none';
  $('stepHint').textContent = job.status === 'queued' && waitedMs > 15000
    ? t('queued_stuck')
    : t('hint_large');
}

function showResult(json, tookMs = null){
  editsReady = false;
  rows   = json.data || [];
  edits.clear(); picked.clear(); showEdits(); $('saveMsg').textContent = '';
  $('cTimeCard').style.display = tookMs ? '' : 'none';
  if(tookMs) $('cTime').textContent = fmtDuration(tookMs);
  hasAdj = (json.meta?.groups || []).includes('adjustment');
  $('cTotal').textContent  = json.meta?.total ?? rows.length;
  $('cReview').textContent = json.meta?.needs_review ?? rows.filter(r => r.needs_review).length;
  $('cAvg').textContent    = (json.meta?.avg_confidence ?? 0) + '%';
  showPartial(json.meta?.unclassified);
  // The API's messages come in the page's language (`*_text`); a run classified before those fields has only the Arabic.
  showWarnings(json.meta?.warnings_text ?? json.meta?.warnings ?? []);
  showBanner('info', esc(t('file_info')), json.meta?.info_text ?? json.meta?.info ?? []);
  showProposals(json.meta?.code_group_proposals ?? []);
  showSources();
  showBalance(json.meta?.edited ? json.meta.balance : null);
  showStamp(json.meta?.balance);
  if(job?.id){ store.set(LAST_RUN, job.id); history.replaceState(null, '', `?run=${encodeURIComponent(job.id)}`); restoreEdits(); }
  editsReady = true; reopening = false; keepEdits();
  pickCompany(job?.company_id);
  renderHead();
  $('tbody').classList.add('fresh');   // the first rows rise in once; later redraws (edits, search) don't
  render();
  setTimeout(() => $('tbody').classList.remove('fresh'), 900);
  $('results').classList.add('on');
  countUp($('cTotal')); countUp($('cReview')); countUp($('cAvg'));
  $('reset').style.display = 'inline-block';
}

/* Cost of the run and what the classification cache answered (background runs only). Savings are an estimate. */
const usd = v => v === null || v === undefined ? '—' : '$' + (v > 0 && v < 1 ? v.toFixed(4) : v.toFixed(2));   // AI cents matter
function showReportLink(id){
  $('fsNew').href = `/financial-reports/new?run=${encodeURIComponent(id)}`;
  $('fsNew').style.display = '';
}

function showCost(job){
  const cost = job.cost, cache = job.cache;
  $('cCostCard').style.display = cost ? '' : 'none';
  if(cost){
    $('cCost').textContent = cost.cost_usd === null ? t('unknown') : usd(cost.cost_usd);
    $('cCostSub').textContent = cost.cost_usd === null
      ? t('price_missing')
      : t('llm_requests', { count: cost.llm_requests });
  }
  $('cCacheCard').style.display = cache ? '' : 'none';
  $('cSavedCard').style.display = cache ? '' : 'none';
  if(!cache) return;
  $('cCache').textContent = `${cache.rows_from_cache} / ${cache.rows_sent_to_llm}`;
  $('cCacheSub').textContent = t('cache_sub', { cached: cache.rows_from_cache, sent: cache.rows_sent_to_llm });
  $('cSaved').textContent = cache.estimated_savings_usd === null ? t('unknown') : usd(cache.estimated_savings_usd);
  $('cSavedSub').textContent = (cache.savings_basis !== 'default' && T.savings[cache.savings_basis]) || t('savings.default');
}

function fail(message){
  stopLoader();
  if(reopening){ reopening = false; store.set(LAST_RUN, null); history.replaceState(null, '', location.pathname); return; }
  showError(message);
  $('go').disabled = false;
}

$('reset').onclick = async () => {
  if(edits.size && !await ask(t('rows.forget_edits', { count: edits.size }), t('rows.forget'))) return;
  forgetRun(); job = null;
  clearTimeout(polling);
  $('confirm').classList.remove('on');
  file = null; clientFile = null; rows = []; input.value = ''; edits.clear(); picked.clear(); showEdits(); showPicks();
  $('fileChip').innerHTML = ''; $('go').disabled = true;
  $('results').classList.remove('on'); $('reset').style.display = 'none';
  history.replaceState(null, '', location.pathname);
  showPartial(null);
  hideError();
};

/* ---------- confirmation: the user checks / corrects the column mapping ---------- */
const COMBINED = 'account_number+account_name';   // number and name in one cell

function showConfirmation(j){
  const c = j.confirmation, st = c.structure, errors = c.issues.filter(i => i.severity === 'error');
  $('confirmSub').textContent = c.expires_at
    ? t('confirm_sub_expires', { sheet: c.sheet, time: new Date(c.expires_at).toLocaleString(LOCALE) })
    : t('confirm_sub', { sheet: c.sheet });
  $('confirmIssues').className = 'problems' + (errors.length ? ' bad' : '');
  $('confirmIssues').innerHTML = esc(errors.length ? t('issues_errors') : t('issues_doubts')) +
    `<ul>${c.issues.map(i => `<li>${esc(i.user_text ?? i.user)}</li>`).join('')}</ul>`;
  $('cfHeaders').value = (st.header_rows || []).join(t('list_sep'));
  $('cfFirst').value = st.first_data_row || '';

  const fieldLabels = c.fields_text ?? c.fields;
  const options = `<option value="">${esc(t('not_used'))}</option><option value="${COMBINED}">${esc(t('combined'))}</option>` +
    Object.entries(fieldLabels).map(([f, label]) => `<option value="${f}">${esc(label)}</option>`).join('');
  $('cfColumns').innerHTML = c.columns.map(col => {
    const fields = col.fields.includes('account_number') && col.fields.includes('account_name') ? [COMBINED] : col.fields;
    return `<tr>
      <td class="num">${esc(col.letter)}</td><td>${esc(col.header) || '—'}</td>
      <td class="samples">${col.samples.map(esc).join(' · ') || '—'}</td>
      <td><select data-letter="${esc(col.letter)}" class="${fields.length ? 'set' : ''}">${options}</select>
        ${fields.length > 1 ? `<div class="samples">+ ${fields.slice(1).map(f => esc(fieldLabels[f] || '—')).join(t('list_sep'))}</div>` : ''}</td>
    </tr>`;
  }).join('');
  c.columns.forEach(col => {
    const sel = document.querySelector(`#cfColumns select[data-letter="${col.letter}"]`);
    sel.value = col.fields.includes('account_number') && col.fields.includes('account_name') ? COMBINED : (col.fields[0] || '');
    sel.onchange = () => { sel.classList.toggle('set', sel.value !== ''); hideNewDoubts(); };
  });
  $('cfHeaders').oninput = hideNewDoubts;
  $('cfFirst').oninput = hideNewDoubts;
  hideNewDoubts();
  const letters = c.columns.map(col => col.letter);
  $('cfRows').innerHTML = `<tr><td class="rn">#</td>${letters.map(l => `<td class="rn">${esc(l)}</td>`).join('')}</tr>` +
    c.rows.map(r => `<tr><td class="rn">${r.row}</td>${letters.map(l => `<td>${esc(r.cells[l] ?? '')}</td>`).join('')}</tr>`).join('');
  $('confirm').classList.add('on');
  // The question waits below the progress bar: brought into view, so the upload doesn't look stuck.
  $('confirm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// "Confirm anyway" applies only to the exact mapping the new doubts were raised for: any edit takes it back.
function hideNewDoubts(){ $('cfNewDoubts').style.display = 'none'; $('cfAnyway').style.display = 'none'; cfStatus('', ''); }

$('cfConfirm').onclick = () => sendConfirmation(false);
$('cfAnyway').onclick  = () => sendConfirmation(true);

async function sendConfirmation(acknowledge){
  const columns = {};
  document.querySelectorAll('#cfColumns select').forEach(sel => {
    if(sel.value === COMBINED){ columns.account_number = sel.dataset.letter; columns.account_name = sel.dataset.letter; }
    else if(sel.value){ columns[sel.value] = sel.dataset.letter; }
  });
  // Fields mapped to a second column in the detection stay as they were unless the user remapped them.
  job.confirmation.columns.forEach(col => col.fields.slice(1).forEach(f => { if(!(f in columns)) columns[f] = col.letter; }));
  const body = {
    header_rows: $('cfHeaders').value.split(/[،,\s]+/).filter(Boolean).map(Number),
    first_data_row: Number($('cfFirst').value),
    columns,
    ...(acknowledge ? { acknowledge_doubts: true } : {}),
  };
  const btn = acknowledge ? $('cfAnyway') : $('cfConfirm'), label = btn.textContent;
  $('cfConfirm').disabled = $('cfAnyway').disabled = true;
  btn.innerHTML = '<span class="spin"></span>' + esc(t('confirming'));
  cfStatus('', '');
  try{
    const res  = await fetch(job.confirmation.confirm_url, { method:'POST', body:JSON.stringify(body), headers:{ 'Accept':'application/json', 'Content-Type':'application/json' } });
    const json = await res.json().catch(() => ({}));
    if(res.status === 422 && json.needs_acknowledgement){
      $('cfNewDoubts').className = 'problems';
      $('cfNewDoubts').innerHTML = esc(t('new_doubts')) +
        `<ul>${(json.doubts_text ?? json.doubts).map(d => `<li>${esc(d)}</li>`).join('')}</ul>`;
      $('cfNewDoubts').style.display = 'block';
      $('cfAnyway').style.display = 'inline-block';
      reveal($('cfNewDoubts'));
      cfStatus('warn', t('new_doubts_status'));
      return;
    }
    if(res.status === 422){
      hideNewDoubts();
      const list = json.problems_text ?? json.problems ?? Object.values(json.errors || {}).flat();
      $('confirmIssues').className = 'problems bad';
      $('confirmIssues').innerHTML = `${esc(t('still_problems'))}<ul>${list.map(p => `<li>${esc(p)}</li>`).join('')}</ul>`;
      reveal($('confirmIssues'));
      cfStatus('bad', t('still_problems_status'));
      return;
    }
    if(!res.ok) throw new Error(json.message || t('unexpected_error'));
    $('confirm').classList.remove('on');
    timer.resume();
    showProgress(json);
    poll(json.status_url, Date.now());
  }catch(e){
    // Keep the panel: the mapping the user built is still there to send again.
    cfStatus('bad', '⚠️ ' + (e.message === 'Failed to fetch' ? t('network_failed') : e.message));
  }finally{
    btn.textContent = label;
    $('cfConfirm').disabled = $('cfAnyway').disabled = false;
  }
}

function cfStatus(kind, text){ $('cfStatus').className = 'cf-status' + (kind ? ' ' + kind : ''); $('cfStatus').textContent = text; }
/* The result of Confirm can land far above the button: bring it into view and make it blink. */
function reveal(el){
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash');
}

async function cancelRun(){
  if(!job?.cancel_url) return;
  clearTimeout(polling);
  try{
    const res = await fetch(job.cancel_url, { method:'POST', headers:{ 'Accept':'application/json' } });
    const json = await res.json().catch(() => ({}));
    if(!res.ok) throw new Error(json.message || t('unexpected_error'));
    $('confirm').classList.remove('on'); stopLoader();
    showError(t('cancelled'));
    $('go').disabled = false;
  }catch(e){
    fail(e.message);
  }
}
$('cfCancel').onclick = cancelRun;
$('cancelQueued').onclick = cancelRun;

/* ---------- table ---------- */
['q','onlyReview'].forEach(id => $(id).addEventListener('input', render));

function groups(){
  // [title, field prefix, debit header, credit header]
  return ['opening', 'period', ...(hasAdj ? ['adjustment'] : []), 'closing']
    .map(g => [t(`groups.${g}`), g, t(`groups.${g}_debit`), t(`groups.${g}_credit`)]);
}
function columnCount(){ return 6 + groups().length * 2 + 4 + (CAN_CORRECT ? 1 : 0); }

function renderHead(){
  $('thead').innerHTML = `
    <tr>
      <th rowspan="2"><input type="checkbox" id="pickAll" title="${esc(t('pick_all'))}"></th>
      ${CAN_CORRECT ? '<th rowspan="2"></th>' : ''}
      ${['account_number', 'account_name', 'statement', 'main_class', 'sub_class'].map(k => `<th rowspan="2" data-col="${k}">${esc(t('cols.' + k))}</th>`).join('')}
      ${groups().map(g => `<th colspan="2" class="col-group" data-col="${g[1]}">${esc(g[0])}</th>`).join('')}
      ${['confidence', 'needs_review', 'note', 'audit_request'].map(k => `<th rowspan="2" data-col="${k}">${esc(t('cols.' + k))}</th>`).join('')}
    </tr>
    <tr>${groups().map(g => `<th>${esc(g[2])}</th><th>${esc(g[3])}</th>`).join('')}</tr>`;
}

function render(){
  const wrap = $('tbody').closest('.table-wrap'), top = wrap.scrollTop, y = window.scrollY;
  draw();
  wrap.scrollTop = top; window.scrollTo(0, y);
}
function draw(){
  const q = $('q').value.trim().toLowerCase();
  const only = $('onlyReview').checked;
  const list = rows.filter(r =>
    (!only || r.needs_review) &&
    (!q || [r.account_number, r.account_name, r.sub_class, r.main_class, shownMain(r), r.audit_request].join(' ').toLowerCase().includes(q))
  );

  shown = list;
  $('tbody').innerHTML = list.length ? list.map((r, i) => `
    <tr style="--i:${i}" class="${r.needs_review ? 'review' : ''}${edits.has(r.row) ? ' edited' : ''}${picked.has(r.row) ? ' picked' : ''}${r.added ? ' added' : ''}" data-row="${r.row}">
      <td><input type="checkbox" class="pick" ${picked.has(r.row) ? 'checked' : ''}></td>
      ${CAN_CORRECT ? `<td class="row-acts"><button data-edit-row="${r.row}" title="${esc(t('rows.edit'))}">✎</button><button data-delete-row="${r.row}" title="${esc(t('rows.delete'))}">🗑</button></td>` : ''}
      <td class="num">${esc(r.account_number)}</td>
      <td class="name">${esc(r.account_name)}</td>
      <td>${pill(r.financial_statement)}</td>
      <td>${esc(shownMain(r) ?? '—')}</td>
      <td class="sub-cell">${subCell(r)}</td>
      ${groups().map(g => `<td class="num">${money(r[g[1] + '_debit'])}</td><td class="num">${money(r[g[1] + '_credit'])}</td>`).join('')}
      <td>${confBar(r.classification_confidence ?? r.confidence)}</td>
      <td><span class="tag ${r.needs_review ? 'y' : 'n'}">${r.needs_review ? t('yes') : t('no')}</span>${okButton(r)}</td>
      <td class="note">${noteHtml(r)}</td>
      <td class="req">${esc(r.audit_request || '')}</td>
    </tr>`).join('')
    : `<tr><td colspan="${columnCount()}" class="empty">${esc(t('no_results'))}</td></tr>`;
  if($('pickAll')) $('pickAll').checked = list.length > 0 && list.every(r => picked.has(r.row));
  showPicks();
}

/* A card's number counts up to its value (Western digits; a % stays). Instant with «reduce motion». */
function countUp(el){
  const text = el.textContent, m = text.match(/^(\d+(?:\.\d+)?)(%?)$/);
  if(!m || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const to = Number(m[1]), decimals = (m[1].split('.')[1] || '').length, start = performance.now(), ms = 600;
  const step = now => {
    const p = Math.min(1, (now - start) / ms), eased = 1 - Math.pow(1 - p, 3);
    el.textContent = (to * eased).toFixed(decimals) + m[2];
    if(p < 1) requestAnimationFrame(step); else el.textContent = text;
  };
  requestAnimationFrame(step);
}

/* ---------- picking rows: one class for several, or «this answer is right» ---------- */
const picked = new Set();   // rows ticked
let shown = [];             // the rows the table shows now (search / review filter)
// A row the memory won't keep by itself (an AI answer under its confidence floor, a rule's answer), or one still needing
// review, a corrected one too: one click confirms its class, saved as a correction with Save edits; the server checks
// the row again against that class (and later files get it from the memory).
function okButton(r){
  const kept = ['manual_correction', 'cache'].includes(r.classification_source) || (r.classification_source ?? '').startsWith('imported');
  if(!r.sub_class || edits.has(r.row) || (kept && !r.needs_review)) return '';
  return `<button class="ok-btn" data-ok="${r.row}" title="${esc(t('confirm_current'))}">${esc(t('ok_button'))}</button>`;
}
function showPicks(){
  $('nPicked').textContent = picked.size;
  $('picks').classList.toggle('on', picked.size > 0);
}
$('tbody').addEventListener('change', ev => {
  if(!ev.target.classList.contains('pick')) return;
  const n = Number(ev.target.closest('tr').dataset.row);
  ev.target.checked ? picked.add(n) : picked.delete(n);
  ev.target.closest('tr').classList.toggle('picked', ev.target.checked);
  $('pickAll').checked = shown.length > 0 && shown.every(r => picked.has(r.row));
  showPicks();
});
$('thead').addEventListener('change', ev => {
  if(ev.target.id !== 'pickAll') return;
  for(const r of shown) ev.target.checked ? picked.add(r.row) : picked.delete(r.row);
  render();
});
$('tbody').addEventListener('click', ev => {
  const n = ev.target.dataset?.ok;
  if(n === undefined) return;
  const r = rows.find(x => x.row === Number(n));
  if(r){ setSub(r, CONFIRM); render(); }
});
$('pickApply').onclick = async () => {
  const sub = await pickClass($('pickApply'), null, true);
  if(!sub) return;
  for(const r of rows) if(picked.has(r.row) && (sub !== CONFIRM || r.sub_class)) setSub(r, sub);
  picked.clear(); render();
};
$('pickClear').onclick = () => { picked.clear(); render(); };
// Their current classes are right: saved at once to the run and the memory (with any other edit waiting), so later
// files get them from the memory. Rows without a class are left out.
if($('pickOk')) $('pickOk').onclick = async () => {
  const sure = rows.filter(r => picked.has(r.row) && r.sub_class);
  if(!sure.length) return;
  for(const r of sure) setSub(r, CONFIRM);
  picked.clear(); render();
  await saveEdits();
};

function pill(s){
  if(!s) return `<span class="pill p-none">${esc(t('unclassified'))}</span>`;
  // the statement name is data (the taxonomy's, in Arabic): «الدخل» marks the income statement
  return `<span class="pill ${s.includes('الدخل') ? 'p-is' : 'p-bs'}">${esc(s)}</span>`;
}
function confBar(c){
  const color = c >= 85 ? 'var(--ok)' : c >= 70 ? 'var(--warn)' : 'var(--bad)';
  return `<div class="conf"><div class="track"><div class="fill" style="width:${c}%;background:${color}"></div></div><span>${c}%</span></div>`;
}
// Two decimals (the file's own amounts), «-» for zero, a negative as the statements write it (App\Support\Amounts).
function money(n){
  const v = Number(n || 0);
  if (Math.abs(v) < 0.005) return '-';
  const s = Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return v > 0 ? s : (window.AmountsConfig?.negatives === 'minus' ? '-' + s : '(' + s + ')');
}
function esc(s){ const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

/* ---------- row corrections ---------- */
// Click a sub-class to change it. The change stays on the page (and in the CSV) until Save edits saves it to the run
// and the classification memory, so the same account comes back with it in later files.
function subCell(r){
  const was = edits.get(r.row);
  return `<button class="sub-edit" onclick="editSub(${r.row})" title="${was ? esc(t('was', { value: was.sub_class ?? t('unclassified') })) : esc(t('click_to_change'))}">`
    + `${esc(r.sub_class ?? '—')}<span class="pen">✎</span></button>`
    + (r.classification_source ? `<span class="src">${esc(sourceLabel(r))}</span>` : '');
}
function sourceKind(r){
  const s = r.classification_source;
  return s && s.startsWith('imported') ? 'imported' : s;
}
function sourceLabel(r){
  const kind = sourceKind(r);
  return kind === 'rule' && r.classification_rule ? t('sources.rule_named', { rule: r.classification_rule }) : t('sources.' + kind);
}
/* How many accounts each source classified: the AI, the memory, a rule, a manual correction. */
function showSources(){
  const counts = {};
  for(const r of rows) if(r.classification_source) counts[sourceKind(r)] = (counts[sourceKind(r)] ?? 0) + 1;
  const order = ['cache', 'rule', 'ai', 'manual_correction', 'imported'].filter(k => counts[k]);
  $('cSources').textContent = order.map(k => counts[k]).join(' / ') || '—';
  $('cSourcesSub').textContent = order.map(k => `${counts[k]} ${t('sources.' + k)}`).join(' · ');
}

/* The class picker (public/js/class-picker.js): search over the taxonomy; «this class is right» first when confirmable. */
function pickClass(anchor, current, confirmable){
  return ClassPicker.open(anchor, { taxonomy: TAXONOMY, current, words: T.picker,
    extra: confirmable ? [{ value: CONFIRM, label: t('confirm_current') }] : [] });
}

function editSub(n){
  const r = rows.find(x => x.row === n), td = document.querySelector(`tr[data-row="${n}"] .sub-cell`);
  if(!r || !td) return;
  const before = edits.get(r.row) ?? r;
  // «this class is right» unless it is a saved correction with nothing left to review.
  const confirmable = !!r.sub_class && (before.classification_source !== 'manual_correction' || !!before.needs_review);
  pickClass(td.querySelector('.sub-edit') ?? td, r.sub_class, confirmable)
    .then(sub => { if(sub){ setSub(r, sub); render(); } });
}

function setSub(r, sub){
  const orig = edits.get(r.row) ?? { ...r };
  if(sub === CONFIRM){ edits.set(r.row, orig); showEdits(); return; }   // kept as it is, saved as a correction
  if(sub === orig.sub_class){
    // Back to what was saved: no edit, unless the row still needs review, where choosing its class again confirms it.
    Object.assign(r, orig);
    if(orig.needs_review) edits.set(r.row, orig); else edits.delete(r.row);
  }
  else {
    edits.set(r.row, orig);
    const [statement, main] = TAXONOMY[sub];
    Object.assign(r, { sub_class: sub, main_class: main, financial_statement: statement });
  }
  showEdits();
}

function showEdits(){
  $('nEdits').textContent = edits.size;
  $('edits').classList.toggle('on', edits.size > 0);
  keepEdits();
}

/* ---------- the work stays: the last run reopens, its unsaved class edits with it, until «خلاص» ---------- */
const LAST_RUN = 'classifier.lastRun', EDITS_OF = id => `classifier.edits.${id}`;
const store = { get: k => { try{ return localStorage.getItem(k); }catch(e){ return null; } },
  set: (k, v) => { try{ v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); }catch(e){} } };
let editsReady = false;   // false while a result is being shown: its saved edits are restored first, never overwritten
let reopening  = false;   // the page reopened the last run by itself: if it's gone, forget it quietly
function keepEdits(){
  if(!editsReady || !job?.id || !rows.length) return;
  store.set(EDITS_OF(job.id), edits.size ? JSON.stringify([...edits.keys()].map(n => [n, rows.find(r => r.row === n)?.sub_class])) : null);
}
function restoreEdits(){
  let saved = [];
  try{ saved = JSON.parse(store.get(EDITS_OF(job.id)) || '[]'); }catch(e){}
  for(const [n, sub] of saved){
    const r = rows.find(x => x.row === n);
    if(r && sub && (TAXONOMY[sub] || sub === r.sub_class)) setSub(r, sub === r.sub_class ? CONFIRM : sub);
  }
}
function forgetRun(){
  if(job?.id) store.set(EDITS_OF(job.id), null);
  store.set(LAST_RUN, null);
}

$('undoEdits').onclick = () => {
  for(const r of rows) if(edits.has(r.row)) Object.assign(r, edits.get(r.row));
  edits.clear(); showEdits(); render();
};

$('saveEdits').onclick = () => saveEdits();
async function saveEdits(){
  if(!edits.size || !job?.id) return;
  const changed = rows.filter(r => edits.has(r.row));
  if(!await ask(t('save_confirm', { count: changed.length }), t('save_edits'))) return;
  $('saveEdits').disabled = true; $('saveMsg').className = 'save-msg'; $('saveMsg').textContent = '';
  try{
    const res  = await fetch(`${SUBMIT_URL}/${encodeURIComponent(job.id)}/corrections`, { method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ corrections: changed.map(r => ({ row: r.row, sub_class: r.sub_class })) }) });
    const json = await res.json().catch(() => ({}));
    if(!res.ok) throw new Error((json.errors && Object.values(json.errors)[0][0]) || json.message || t('error'));
    for(const saved of json.rows){ const i = rows.findIndex(r => r.row === saved.row); if(i >= 0) rows[i] = saved; }
    edits.clear(); showEdits(); showSources();
    $('cReview').textContent = rows.filter(r => r.needs_review).length;
    $('cAvg').textContent = (Math.round(rows.reduce((s, r) => s + (r.classification_confidence ?? 0), 0) / (rows.length || 1) * 10) / 10) + '%';
    render();
    $('saveMsg').textContent = t('saved', { corrected: json.corrected, remembered: json.remembered })
      + (json.not_remembered.length ? t('not_remembered', { count: json.not_remembered.length }) : '');
  }catch(e){ $('saveMsg').className = 'save-msg bad'; $('saveMsg').textContent = '⚠️ ' + t('save_failed', { message: e.message }); }
  finally{ $('saveEdits').disabled = false; }
}

/* ---------- rows added, edited or removed after the classification (saved at once) ---------- */
// The audit stamp when every group balances; a run kept before the balance was given shows none. Pressed again only
// when it comes back (a fresh element restarts the animation).
function showStamp(balance){
  const on = !!balance && Object.keys(balance).length > 0 && Object.values(balance).every(d => Math.abs(d) < 0.01);
  const card = $('stampCard'), was = card.classList.contains('on');
  card.classList.toggle('on', on);
  if(on && !was){ const st = card.querySelector('.stamp'); st.replaceWith(st.cloneNode(true)); }
}
function showBalance(balance){
  const off = Object.entries(balance || {}).filter(([, d]) => Math.abs(d) >= 0.01);
  $('balanceWarn').innerHTML = off.length ? `${esc(t('rows.unbalanced'))}<ul>${off.map(([g, d]) => `<li>${esc(t('rows.unbalanced_group', { group: t('groups.' + g), diff: money(Math.abs(d)) }))}</li>`).join('')}</ul>` : '';
  $('balanceWarn').classList.toggle('on', off.length > 0);
}
async function rowCall(method, path, body){
  const res = await fetch(`${SUBMIT_URL}/${encodeURIComponent(job.id)}/rows${path}`, { method,
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const json = await res.json().catch(() => ({}));
  if(!res.ok) throw new Error((json.errors && Object.values(json.errors)[0][0]) || json.message || t('error'));
  $('cTotal').textContent = json.meta.total;
  $('cReview').textContent = json.meta.needs_review;
  $('cAvg').textContent = json.meta.avg_confidence + '%';
  showBalance(json.meta.balance);
  showStamp(json.meta.balance);
  return json.row;
}
/* The row dialog: number, name, the amounts of the file's groups, and (a new row) its class. Resolves with the fields or null. */
function rowDialog(r){
  const d = $('rowDialog'), adding = !r;
  $('rdTitle').textContent = adding ? t('rows.add_title') : t('rows.edit_title', { name: r.account_name });
  $('rdNumber').value = r?.account_number ?? ''; $('rdName').value = r?.account_name ?? ''; $('rdError').textContent = '';
  $('rdAmounts').innerHTML = groups().flatMap(g => [[g[1] + '_debit', g[2]], [g[1] + '_credit', g[3]]])
    .map(([k, label]) => `<label>${esc(label)}<input data-k="${k}" type="number" min="0" step="0.01" dir="ltr" value="${r ? (r[k] || 0) : 0}"></label>`).join('');
  $('rdClassWrap').hidden = !adding;
  $('rdClass').dataset.value = ''; $('rdClass').textContent = t('choose_sub');
  $('rdClass').onclick = async () => { const sub = await pickClass($('rdClass'), $('rdClass').dataset.value || null, false); if(sub){ $('rdClass').dataset.value = sub; $('rdClass').textContent = sub; } };
  return new Promise(resolve => {
    const done = v => { d.onclose = null; d.close(); resolve(v); };
    $('rdCancel').onclick = () => done(null);
    d.onclose = () => resolve(null);
    $('rdSave').onclick = () => {
      const name = $('rdName').value.trim();
      if(!name){ $('rdError').textContent = t('rows.name_required'); return; }
      const out = { account_number: $('rdNumber').value.trim(), account_name: name };
      for(const i of $('rdAmounts').querySelectorAll('input')) out[i.dataset.k] = Number(i.value || 0);
      if(adding && $('rdClass').dataset.value) out.sub_class = $('rdClass').dataset.value;
      done(out);
    };
    d.showModal(); $('rdName').focus();
  });
}
if($('addRow')) $('addRow').onclick = async () => {
  if(!job?.id) return;
  const body = await rowDialog(null);
  if(!body) return;
  try{ rows.push(await rowCall('POST', '', body)); showSources(); render(); $('tbody').lastElementChild?.scrollIntoView({ block: 'center' }); }
  catch(e){ showError(e.message); }
};
$('tbody').addEventListener('click', async ev => {
  const editN = ev.target.dataset?.editRow, delN = ev.target.dataset?.deleteRow;
  if(editN === undefined && delN === undefined) return;
  const n = Number(editN ?? delN), r = rows.find(x => x.row === n);
  if(!r || !job?.id) return;
  try{
    if(delN !== undefined){
      if(!await ask(t('rows.delete_confirm', { name: r.account_name }), t('rows.delete'))) return;
      await rowCall('DELETE', `/${n}`);
      rows = rows.filter(x => x.row !== n); edits.delete(n); picked.delete(n); showEdits(); showSources();
    }else{
      const body = await rowDialog(r);
      if(!body) return;
      const saved = await rowCall('PUT', `/${n}/values`, body);
      const keep = edits.has(n) ? { sub_class: r.sub_class, main_class: r.main_class, financial_statement: r.financial_statement } : {};
      rows[rows.findIndex(x => x.row === n)] = Object.assign(saved, keep);
    }
    render();
  }catch(e){ showError(e.message); }
});

/* ---------- csv (opens correctly in Excel with Arabic) ---------- */
$('csv').onclick = () => {
  const col = k => t('cols.' + k);
  const head = [col('account_number'), col('account_name'), col('statement'), col('main_class'), col('sub_class'),
    ...groups().flatMap(g => [g[2], g[3]]), col('confidence_pct'), col('needs_review'), col('note'), col('audit_request'), col('note_reference'), col('manual_edit')];
  const cell = v => `"${String(v ?? '').replace(/"/g,'""')}"`;
  const lines = rows.map(r => [
    r.account_number, r.account_name, r.financial_statement, shownMain(r), r.sub_class,
    ...groups().flatMap(g => [r[g[1] + '_debit'], r[g[1] + '_credit']]),
    r.classification_confidence ?? r.confidence, r.needs_review ? t('yes') : t('no'), r.note_text ?? r.note, r.audit_request, r.note_reference,
    // «تصحيح يدوي» is the reason the API stores for a saved correction (data, not a page word).
    edits.has(r.row) ? t('csv_unsaved') : (r.classification_reason === 'تصحيح يدوي' ? t('csv_saved') : ''),
  ].map(cell).join(','));
  const blob = new Blob(['﻿' + [head.map(cell).join(','), ...lines].join('\n')], {type:'text/csv;charset=utf-8'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = 'classified-trial-balance.csv'; a.click();
};

/* ---------- partial result: rows that came back unclassified ---------- */
function showPartial(u){
  const box = $('partial');
  if(!u || !u.rows){ box.classList.remove('on'); box.innerHTML = ''; return; }
  const causes = [
    u.failed_batches ? t('partial.failed_batches', { count: u.failed_batches }) : null,
    u.missed_by_ai ? t('partial.missed_by_ai', { count: u.missed_by_ai }) : null,
  ].filter(Boolean).join(t('partial.causes_sep'));
  // The API's ranges are «R3–R5»: shown as the file's row numbers («rows 3–5, 9 in the file»).
  const list = u.ranges.slice(0, 10).map(r => r.replace(/R/g, '')).join(t('list_sep'))
    + (u.ranges.length > 10 ? t('partial.ranges_more', { count: u.ranges.length - 10 }) : '');
  box.innerHTML = esc(t('partial.message', { rows: u.rows, ranges: t('partial.ranges', { list }), causes })) +
    `<a href="#" id="showUnclassified">${esc(t('partial.show'))}</a>`;
  box.classList.add('on');
  $('showUnclassified').onclick = e => { e.preventDefault(); $('onlyReview').checked = true; render(); $('tbody').scrollIntoView({ behavior:'smooth' }); };
}

/* ---------- loader / errors ---------- */
/* High-priority flags (moving an account between statements) lead the note: shown in red, apart from the rest. */
function noteHtml(r){
  const high = (r.data_flags || []).filter(f => f.severity === 'high').map(f => f.message_text ?? f.message);
  let rest = r.note_text ?? r.note ?? '';
  high.forEach(m => { rest = rest.replace(m, '').replace(/^\s*\|\s*/, ''); });
  return high.map(m => `<span class="high">${esc(m)}</span>`).join('') + esc(rest);
}

function stopLoader(){ clearTimeout(polling); timer.halt(); $('loader').classList.remove('on'); }
function showError(m){ $('error').textContent = '⚠️ ' + m; $('error').classList.add('on'); }
function hideError(){ $('error').classList.remove('on'); }
function showWarnings(list){ showBanner('warnings', esc(t('file_warnings')), list); }
/* «Apply to the group»: each proposal links to the rules page with the mark filled in, for the run's company. */
const CAN_MARK = document.getElementById('app-config').dataset.canMark === '1';
function showProposals(list){
  const company = job?.company_id;
  $('proposals').innerHTML = list.length ? `${esc(t('proposals.title'))}<ul>${list.map(p => {
    const text = esc(t('proposals.item', { prefix: p.prefix, rule: p.rule, count: p.rows.length, share: Math.round(p.share * 100) }));
    const href = `/classification-rules?company_id=${encodeURIComponent(company)}&prefix=${encodeURIComponent(p.prefix)}&rule_id=${encodeURIComponent(p.rule_id)}&back=${encodeURIComponent('/classifier?run=' + (job?.id ?? ''))}#marks`;
    const act = !company ? `<span class="note">${esc(t('proposals.no_company'))}</span>`
      : CAN_MARK ? `<a class="btn small" href="${href}">${esc(t('proposals.apply'))}</a>` : '';
    return `<li>${text} ${act}</li>`;
  }).join('')}</ul>` : '';
  $('proposals').classList.toggle('on', list.length > 0);
}

function showBanner(id, title, list){
  $(id).innerHTML = list.length ? `${title}<ul>${list.map(w => `<li${w.startsWith('⛔') ? ' class="high"' : ''}>${esc(w)}</li>`).join('')}</ul>` : '';
  $(id).classList.toggle('on', list.length > 0);
}

/* ---------- ?run=<id>: open an existing run (e.g. one waiting for confirmation) ---------- */
// Last, once every const it uses (STAGE_PCT…) is initialized: earlier, it threw at load and the run was never polled.
const openRun = new URLSearchParams(location.search).get('run') || store.get(LAST_RUN);
reopening = !new URLSearchParams(location.search).get('run') && !!openRun;
if(openRun){
  timer.start();
  showProgress({ status: 'running' });
  poll(SUBMIT_URL + '/' + encodeURIComponent(openRun), Date.now());
}
</script>
</body>
</html>