@extends('layouts.app')
@section('title', __('audit_plan.procedures.title'))
@section('subtitle', __('audit_plan.procedures.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'procedures'])
  @php
    $money = fn ($v) => $v === null ? '—' : number_format($v, 2);
    $m = $p['m'];
    $tot = $p['totals'];
    $ro = ! $canManage;
    $statusClass = ['na' => '', 'reviewed' => 'ok', 'prepared' => 'ok', 'completed' => 'info', 'in_progress' => 'warn', 'not_started' => ''];
    $reqClass = ['accepted' => 'ok', 'not_applicable' => '', 'uploaded' => 'info', 'returned' => 'bad', 'pending' => 'warn'];
    $openKey = $open ?? old('area');
  @endphp

  <div class="panel">
    <form method="GET" action="{{ route('clients.file.procedures', $company) }}" class="row" style="align-items:end">
      <div class="field" style="max-width:160px"><label for="py">{{ __('audit_plan.year') }}</label>
        <input id="py" name="year" type="number" min="2000" max="2100" value="{{ $year }}" dir="ltr" onchange="this.form.submit()"></div>
      <div class="field" style="flex:none;min-width:0"><span class="note">{{ \App\Services\Portal\Workspace::periodText($company, $year) }}</span></div>
      <div class="field" style="flex:none;min-width:0;margin-inline-start:auto">
        <a class="btn small ghost" href="{{ route('clients.procedures.export', [$company, 'year' => $year]) }}" data-export-program>{{ __('audit_plan.procedures.export') }}</a></div>
    </form>
    @if ($m['performance'] === null)
      <div class="msg warn" style="margin-top:12px">{{ __('audit_plan.procedures.materiality_first') }}
        <a href="{{ route('clients.file.materiality', [$company, 'year' => $year]) }}">{{ __('audit_plan.materiality.title') }}</a></div>
    @else
      <div class="pr-lock" data-locked-materiality>
        <span>🔒 {{ __('audit_plan.materiality.overall') }}: <b>{{ $money($m['overall_rounded']) }}</b></span>
        <span>{{ __('audit_plan.materiality.performance') }}: <b>{{ $money($m['performance']) }}</b></span>
        <span>{{ __('audit_plan.materiality.trivial') }}: <b>{{ $money($m['trivial']) }}</b></span>
      </div>
    @endif
    <div class="pr-cards" data-program-totals>
      <div class="pr-card"><small>{{ __('audit_plan.procedures.progress') }}</small><b>{{ $tot['progress'] }}%</b>
        <span class="pr-bar"><i style="width:{{ $tot['progress'] }}%"></i></span></div>
      <div class="pr-card"><small>{{ __('audit_plan.procedures.col.procedures') }}</small><b>{{ $tot['procedures'] - $tot['pending'] }} / {{ $tot['procedures'] }}</b></div>
      <div class="pr-card"><small>{{ __('audit_plan.procedures.col.exception') }}</small><b @class(['pr-bad' => $tot['exception'] > 0])>{{ $tot['exception'] }}</b></div>
      <div class="pr-card"><small>{{ __('audit_plan.procedures.signed_areas') }}</small><b>{{ $tot['prepared'] }} / {{ $tot['areas'] - $tot['areas_na'] }}</b>
        <span class="note">{{ __('audit_plan.procedures.reviewed_count', ['count' => $tot['reviewed']]) }}</span></div>
      <div class="pr-card"><small>{{ __('audit_plan.procedures.awaited_card') }}</small><b @class(['pr-bad' => $tot['awaited'] > 0])>{{ $tot['awaited'] }}</b>
        <span class="note">{{ __('audit_plan.procedures.of_requirements', ['count' => $tot['requirements']]) }}</span></div>
    </div>
    <p class="note">{{ __('audit_plan.procedures.hint') }}</p>
  </div>

  @if ($errors->has('sign'))<div class="msg bad">{{ $errors->first('sign') }}</div>@endif

  @foreach ($p['areas'] as $a)
    <details class="panel pr-area" id="area-{{ $a['key'] }}" data-area="{{ $a['key'] }}" data-status="{{ $a['status'] }}" @if ($openKey === $a['key']) open @endif>
      <summary>
        <span class="pr-code">{{ $a['code'] }}</span>
        <span class="pr-title">{{ $a['title'] }}
          @if ($a['significant'])<span class="pill bad">{{ __('audit_plan.procedures.significant') }}</span>@endif
          @if ($a['count']['exception'] > 0 && ! $a['na'])<span class="pill bad">{{ __('audit_plan.procedures.exceptions', ['count' => $a['count']['exception']]) }}</span>@endif
          @if ($a['awaited'] > 0 && ! $a['na'])<span class="pill warn" data-awaited>{{ __('audit_plan.procedures.awaited_pill', ['count' => $a['awaited']]) }}</span>@endif
        </span>
        @if ($a['has_lines'])<span class="pr-bal num">{{ $money($a['balance']) }}</span>@endif
        <span class="pr-prog">@unless ($a['na'])<span class="pr-bar"><i style="width:{{ $a['progress'] }}%"></i></span> {{ $a['answered'] }}/{{ count($a['items']) }}@endunless</span>
        <span class="pill {{ $statusClass[$a['status']] }}">{{ __("audit_plan.procedures.status.{$a['status']}") }}</span>
      </summary>

      @if ($a['lines'] !== [])
        <table class="pr-lines">
          <tr><th>{{ __('audit_plan.procedures.col.line') }}</th><th class="num">{{ $year }}</th><th class="num">{{ $year - 1 }}</th></tr>
          @foreach ($a['lines'] as $l)<tr><td>{{ $l['label'] }}</td><td class="num">{{ $money($l['amount']) }}</td><td class="num">{{ $money($l['prior']) }}</td></tr>@endforeach
        </table>
      @elseif ($a['has_lines'])
        <p class="note">{{ __('audit_plan.procedures.no_balance') }}</p>
      @endif

      {{-- The standards the area applies: the auditing standards and the framework's sections of its balances. --}}
      @if ($a['standards'] !== [] || $a['framework'] !== [])
        <div class="pr-std" data-standards>
          @if ($a['standards'] !== [])<span class="note">{{ __('audit_plan.procedures.standards') }}:</span>
            @foreach ($a['standards'] as $isa)<span class="pr-isa" title="{{ __("audit_plan.procedures.isa.{$isa}") }}">{{ $isa }} — {{ __("audit_plan.procedures.isa.{$isa}") }}</span>@endforeach
          @endif
          @if ($a['framework'] !== [])<span class="note">{{ __('audit_plan.procedures.framework') }}:</span>
            @foreach ($a['framework'] as $sec)<span class="pr-isa">{{ $sec }} — {{ __("audit_plan.procedures.sections.{$sec}") }}</span>@endforeach
          @endif
        </div>
      @endif

      {{-- The requirements (step 8) the area rests on: the documents asked of the client, with their status. --}}
      <div class="pr-reqs" data-requirements>
        <h4>{{ __('audit_plan.procedures.requirements') }}
          @if ($a['requirements'] !== [])<span class="note">{{ __('audit_plan.procedures.requirements_count', ['received' => $a['received'], 'count' => count($a['requirements'])]) }}</span>@endif</h4>
        @if ($a['requirements'] === [])
          <p class="note">{{ __('audit_plan.procedures.no_requirements') }} <a href="{{ route('clients.show', [$company, 'year' => $year]) }}">{{ __('audit_plan.procedures.to_requirements') }}</a></p>
        @else
          <ul>
            @foreach ($a['requirements'] as $r)
              <li data-requirement="{{ $r['id'] }}"><span class="pill {{ $reqClass[$r['status']] ?? '' }}">{{ __("audit_plan.procedures.request_status.{$r['status']}") }}</span>
                <a href="{{ route('clients.show', [$company, 'year' => $year]) }}#req-{{ $r['id'] }}">@if ($r['number']){{ $r['number'] }}. @endif{{ $r['title'] }}</a>
                @if ($r['optional'])<span class="note">({{ __('audit_plan.procedures.optional') }})</span>@endif
                @if ($r['files'] > 0)<span class="note">📎 {{ $r['files'] }}</span>@endif</li>
            @endforeach
          </ul>
          @if ($a['awaited'] > 0 && ! $a['na'])<p class="note pr-warn">{{ __('audit_plan.procedures.awaited_hint', ['count' => $a['awaited']]) }}</p>@endif
        @endif
      </div>

      <form method="POST" action="{{ route('clients.procedures.save', [$company, $a['key']]) }}" data-area-form>@csrf
        <input type="hidden" name="year" value="{{ $year }}">
        <label class="pr-na"><input type="checkbox" name="na" value="1" @checked($a['na']) @disabled($ro)
          onchange="this.closest('form').querySelector('[data-na-reason]').hidden=!this.checked"> {{ __('audit_plan.procedures.area_na') }}</label>
        <div class="field" data-na-reason @if (! $a['na']) hidden @endif><input name="na_reason" value="{{ $a['na_reason'] }}" placeholder="{{ __('audit_plan.procedures.na_reason') }}" maxlength="500" @disabled($ro)></div>

        <div class="pr-scroll">
          <table class="pr-table">
            <tr><th style="width:70px">{{ __('audit_plan.procedures.col.code') }}</th><th>{{ __('audit_plan.procedures.col.procedure') }}</th>
              <th style="width:150px">{{ __('audit_plan.procedures.col.status') }}</th><th style="width:120px">{{ __('audit_plan.procedures.col.ref') }}</th><th style="width:220px">{{ __('audit_plan.procedures.col.note') }}</th></tr>
            @foreach ($a['items'] as $i)
              <tr data-procedure="{{ $i['code'] }}" class="pr-st-{{ $i['status'] }}">
                <td class="pr-num">{{ $a['code'] }}-{{ $i['code'] }}</td>
                <td>{{ $i['text'] }}
                  <div class="pr-tags">
                    <span class="pr-type">{{ __("audit_plan.procedures.types.{$i['type']}") }}</span>
                    @foreach ($i['assertions'] as $as)<span class="pr-as" title="{{ __("audit_plan.procedures.assertions.{$as}") }}">{{ __("audit_plan.procedures.assertions.{$as}") }}</span>@endforeach
                    @if ($i['type'] === 'sample')<a class="pr-link" href="{{ route('clients.file.sampling', [$company, 'year' => $year]) }}">{{ __('audit_plan.procedures.to_sampling') }}</a>@endif
                    @if ($i['custom'])<span class="pr-own">{{ __('audit_plan.procedures.own') }}</span>
                      @if ($canManage)<button class="pr-del" type="submit" form="del-{{ $a['key'] }}-{{ $i['code'] }}" title="{{ __('audit_plan.procedures.delete') }}">✕</button>@endif
                    @endif
                    @if ($i['by'])<span class="note">{{ $i['by'] }} — {{ $i['at'] }}</span>@endif
                  </div></td>
                <td><select name="status[{{ $i['code'] }}]" @disabled($ro) class="pr-sel" data-status-select>
                  @foreach (config('audit_procedures.statuses') as $st)<option value="{{ $st }}" @selected($i['status'] === $st)>{{ __("audit_plan.procedures.statuses.{$st}") }}</option>@endforeach
                </select></td>
                <td><input name="ref[{{ $i['code'] }}]" value="{{ $i['ref'] }}" maxlength="80" dir="auto" placeholder="{{ __('audit_plan.procedures.ref_placeholder') }}" @disabled($ro)>
                  @if ($a['requirements'] !== [])
                    <select name="request[{{ $i['code'] }}]" class="pr-ev" title="{{ __('audit_plan.procedures.evidence') }}" @disabled($ro) data-evidence>
                      <option value="">{{ __('audit_plan.procedures.evidence_none') }}</option>
                      @foreach ($a['requirements'] as $r)<option value="{{ $r['id'] }}" @selected($i['request'] === $r['id'])>@if ($r['number']){{ $r['number'] }}. @endif{{ \Illuminate\Support\Str::limit($r['title'], 60) }}</option>@endforeach
                    </select>
                  @endif
                  @if ($i['evidence_unsettled'])<div class="pr-warn note" data-evidence-unsettled>{{ __('audit_plan.procedures.evidence_unsettled', ['status' => __("audit_plan.procedures.request_status.{$i['evidence']['status']}")]) }}</div>@endif
                </td>
                <td><textarea name="note[{{ $i['code'] }}]" rows="1" maxlength="1000" @disabled($ro)>{{ $i['note'] }}</textarea></td>
              </tr>
            @endforeach
          </table>
        </div>
        @if ($canManage)
          <div class="toolbar" style="margin:8px 0">
            <button class="btn small ghost" type="button" data-all-done>{{ __('audit_plan.procedures.mark_rest_done') }}</button>
          </div>
        @endif
        <div class="field"><label for="c-{{ $a['key'] }}">{{ __('audit_plan.procedures.conclusion') }}</label>
          <textarea id="c-{{ $a['key'] }}" name="conclusion" rows="2" maxlength="3000" placeholder="{{ __('audit_plan.procedures.conclusion_placeholder') }}" @disabled($ro)>{{ $a['conclusion'] }}</textarea></div>
        @if ($canManage)<button class="btn" type="submit">{{ __('audit_plan.procedures.save') }}</button>@endif
        @if ($a['prepared'])<span class="note">{{ __('audit_plan.procedures.save_unsigns') }}</span>@endif
      </form>

      @if ($canManage)
        @foreach ($a['items'] as $i)
          @if ($i['custom'])
            <form id="del-{{ $a['key'] }}-{{ $i['code'] }}" method="POST" action="{{ route('clients.procedures.delete', [$company, $a['key'], $i['code']]) }}"
              onsubmit="return confirm(@js(__('audit_plan.procedures.delete_confirm')))" hidden>@csrf<input type="hidden" name="year" value="{{ $year }}"></form>
          @endif
        @endforeach
      @endif

      {{-- The sign-offs: the preparer once nothing is pending, then the reviewer. --}}
      <div class="pr-sign" data-signoff>
        @foreach (['prepared', 'reviewed'] as $role)
          <div class="pr-signbox">
            <small>{{ __("audit_plan.procedures.col.{$role}") }}</small>
            @if ($a[$role])
              <b>✔ {{ $a[$role]['name'] }}</b><span class="note">{{ $a[$role]['at'] }}</span>
              @if ($canManage)
                <form method="POST" action="{{ route('clients.procedures.sign', [$company, $a['key']]) }}">@csrf
                  <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="role" value="{{ $role }}"><input type="hidden" name="off" value="1">
                  <button class="btn small ghost" type="submit">{{ __('audit_plan.procedures.unsign') }}</button></form>
              @endif
            @elseif ($canManage)
              <form method="POST" action="{{ route('clients.procedures.sign', [$company, $a['key']]) }}">@csrf
                <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="role" value="{{ $role }}">
                <button class="btn small" type="submit" @disabled($role === 'reviewed' && ! $a['prepared'])>{{ __("audit_plan.procedures.sign_{$role}") }}</button></form>
            @else
              <span class="note">—</span>
            @endif
          </div>
        @endforeach
        <a class="btn small ghost" href="{{ route('clients.procedures.export', [$company, 'year' => $year, 'area' => $a['key']]) }}">{{ __('audit_plan.procedures.export_area') }}</a>
      </div>

      @if ($canManage)
        <details class="pr-add">
          <summary>{{ __('audit_plan.procedures.add_title') }}</summary>
          <form method="POST" action="{{ route('clients.procedures.add', [$company, $a['key']]) }}" data-add-procedure>@csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <div class="field"><label>{{ __('audit_plan.procedures.col.procedure') }}</label><textarea name="text" rows="2" maxlength="1000" required></textarea></div>
            <div class="row" style="align-items:end">
              <div class="field" style="max-width:220px"><label>{{ __('audit_plan.procedures.col.type') }}</label>
                <select name="type">@foreach (config('audit_procedures.types') as $ty)<option value="{{ $ty }}" @selected($ty === 'detail')>{{ __("audit_plan.procedures.types.{$ty}") }}</option>@endforeach</select></div>
              <div class="field"><label>{{ __('audit_plan.procedures.col.assertions') }}</label>
                <div class="pr-checks">@foreach (config('audit_procedures.assertions') as $as)<label><input type="checkbox" name="assertions[]" value="{{ $as }}"> {{ __("audit_plan.procedures.assertions.{$as}") }}</label>@endforeach</div></div>
            </div>
            <button class="btn small" type="submit">{{ __('audit_plan.procedures.add') }}</button>
          </form>
        </details>
      @endif
    </details>
  @endforeach
  @include('portal.file._close')
@endsection
@push('styles')
<style>
  .pr-lock{display:flex;gap:18px;flex-wrap:wrap;margin-top:12px;padding:10px 14px;border:1px dashed var(--line);border-radius:10px}
  .pr-cards{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0 6px}
  .pr-card{flex:1 1 180px;border:1px solid var(--line);border-radius:12px;padding:10px 14px;display:flex;flex-direction:column;gap:4px}
  .pr-card b{font-size:20px}
  .pr-bad{color:var(--bad-ink)}
  .pr-bar{display:inline-block;width:90px;height:7px;border-radius:9px;background:var(--panel2);overflow:hidden;vertical-align:middle}
  .pr-card .pr-bar{width:100%}
  .pr-bar i{display:block;height:100%;background:var(--accent, #1f4e79)}
  .pr-area>summary{display:flex;align-items:center;gap:12px;cursor:pointer;list-style:none;flex-wrap:wrap}
  .pr-area>summary::-webkit-details-marker{display:none}
  .pr-area>summary::before{content:'◂';transition:transform .15s;color:var(--muted)}
  .pr-area[open]>summary::before{transform:rotate(-90deg)}
  .pr-area[open]>summary{margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--line)}
  .pr-code{font-weight:700;color:var(--accent, #1f4e79);min-width:44px}
  .pr-title{flex:1 1 260px;font-weight:600;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .pr-bal{font-variant-numeric:tabular-nums;min-width:120px;text-align:end}
  .pr-prog{min-width:130px;font-size:12px;color:var(--muted)}
  .pr-lines{margin-bottom:12px}
  .pr-na{display:inline-flex;gap:6px;align-items:center;margin-bottom:8px}
  .pr-scroll{overflow-x:auto}
  .pr-table{min-width:760px}
  .pr-table td{vertical-align:top}
  .pr-table textarea,.pr-table input,.pr-table select{width:100%}
  .pr-num{font-weight:600;white-space:nowrap;color:var(--muted)}
  .pr-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;align-items:center}
  .pr-type,.pr-as,.pr-own{font-size:11px;padding:1px 8px;border-radius:99px;border:1px solid var(--line);color:var(--muted)}
  .pr-type{background:var(--panel2);color:var(--ink, inherit)}
  .pr-own{border-color:var(--info);color:var(--info)}
  .pr-link{font-size:12px}
  .pr-del{border:0;background:none;color:var(--bad-ink);cursor:pointer;font-size:12px}
  tr.pr-st-done td:first-child{box-shadow:inset -3px 0 0 var(--ok-ink)}
  tr.pr-st-exception td:first-child{box-shadow:inset -3px 0 0 var(--bad-ink)}
  tr.pr-st-exception{background:var(--bad-soft)}
  tr.pr-st-na td{opacity:.6}
  .pr-sign{display:flex;gap:12px;flex-wrap:wrap;align-items:stretch;margin-top:14px}
  .pr-signbox{flex:0 1 230px;border:1px solid var(--line);border-radius:10px;padding:8px 12px;display:flex;flex-direction:column;gap:4px}
  .pr-sign>.btn{align-self:center}
  .pr-add{margin-top:14px}
  .pr-add>summary{cursor:pointer;color:var(--accent, #1f4e79);font-weight:600}
  .pr-std{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
  .pr-isa{font-size:12px;padding:2px 9px;border-radius:8px;background:var(--panel2);border:1px solid var(--line)}
  .pr-reqs{border:1px solid var(--line);border-radius:10px;padding:8px 12px;margin-bottom:12px}
  .pr-reqs h4{margin:0 0 6px;display:flex;gap:8px;align-items:center}
  .pr-reqs ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:5px}
  .pr-reqs li{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:13px}
  .pr-warn{color:var(--warn-ink)}
  .pr-ev{margin-top:4px;font-size:12px}
  .pr-checks{display:flex;gap:10px;flex-wrap:wrap}
  .pr-checks label{display:inline-flex;gap:4px;align-items:center;font-size:13px}
</style>
@endpush
@push('scripts')
<script>
  // «Mark the rest done»: every procedure still pending in this area becomes done (saved with the area's button).
  document.querySelectorAll('[data-all-done]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.closest('form').querySelectorAll('[data-status-select]').forEach(function (s) { if (s.value === 'pending') { s.value = 'done'; } });
    });
  });
</script>
@endpush
