@extends('layouts.app')
@section('title', __('audit_plan.sampling.title'))
@section('subtitle', __('audit_plan.sampling.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'sampling'])
  @php
    $money = fn ($v) => $v === null ? '—' : number_format($v, 2);
    $sales = $s['sales'];
    $samples = $s['samples'];
    $open = collect($samples)->firstWhere('id', request('sample')) ?? (count($samples) ? end($samples) : null);
    $conclusionClass = ['acceptable' => 'ok', 'above_trivial' => 'warn', 'material' => 'bad', 'partial' => 'warn', 'pending' => 'info'];
  @endphp

  {{-- The materiality the samples rest on, as fixed in step 12 (locked here). --}}
  <div class="panel">
    <form method="GET" action="{{ route('clients.file.sampling', $company) }}" class="row" style="align-items:end">
      <div class="field" style="max-width:160px"><label for="sy">{{ __('audit_plan.year') }}</label>
        <input id="sy" name="year" type="number" min="2000" max="2100" value="{{ $year }}" dir="ltr" onchange="this.form.submit()"></div>
      <div class="field" style="flex:none;min-width:0"><span class="note">{{ \App\Services\Portal\Workspace::periodText($company, $year) }}</span></div>
    </form>
    @if ($m['performance'] === null)
      <div class="msg warn" style="margin-top:12px">{{ __('audit_plan.sampling.materiality_first') }}
        <a href="{{ route('clients.file.materiality', [$company, 'year' => $year]) }}">{{ __('audit_plan.materiality.title') }}</a></div>
    @else
      <div class="lock" data-locked-materiality>
        <span>🔒 {{ __('audit_plan.materiality.overall') }}: <b>{{ $money($m['overall_rounded']) }}</b></span>
        <span>{{ __('audit_plan.materiality.performance') }}: <b>{{ $money($m['performance']) }}</b></span>
        <span>{{ __('audit_plan.materiality.trivial') }}: <b>{{ $money($m['trivial']) }}</b></span>
        @unless ($m['saved'])<span class="pill warn">{{ __('audit_plan.materiality.not_saved') }}</span>@endunless
      </div>
    @endif
  </div>

  @if ($canManage)
    <form class="panel" method="POST" enctype="multipart/form-data" action="{{ route('clients.sampling.sales', $company) }}" data-sales-upload>@csrf
      <h3>{{ __('audit_plan.sampling.upload_title') }}</h3>
      <p class="note">{{ __('audit_plan.sampling.upload_hint') }}</p>
      <input type="hidden" name="year" value="{{ $year }}">
      <div class="row">
        <div class="field"><label for="sf">{{ __('audit_plan.sampling.file') }}</label>
          <input id="sf" type="file" name="file" accept=".xlsx,.xlsm,.xls,.xml,.ods,.csv,.txt,.htm,.html,.pdf"></div>
        @if ($clientFiles['files'] !== [])
          <div class="field"><label for="scf">{{ __('audit_plan.sampling.or_client_file') }}</label>
            <select id="scf" name="client_file_id"><option value="">{{ __('audit_plan.sampling.no_client_file') }}</option>
              @foreach ($clientFiles['files'] as $f)<option value="{{ $f['id'] }}" @selected($clientFiles['suggested'] === $f['id'])>{{ $f['name'] }}@if ($f['request']) — {{ $f['request']['title'] }}@endif</option>@endforeach
            </select></div>
        @endif
      </div>
      @error('file')<div class="msg bad">{{ $message }}</div>@enderror
      @if ($sales)<p class="note">{{ __('audit_plan.sampling.replace_warning') }}</p>@endif
      <button class="btn" type="submit">{{ __('audit_plan.sampling.upload') }}</button>
    </form>
  @endif

  @if ($sales)
    <div class="panel" data-population>
      <h3>{{ __('audit_plan.sampling.population_title') }}</h3>
      <p class="note">{{ __('audit_plan.sampling.current_file', ['name' => $sales['name'], 'kind' => __("audit_plan.sampling.kinds.{$sales['kind']}"), 'at' => \Illuminate\Support\Carbon::parse($sales['read_at'])->format('Y-m-d H:i')]) }}</p>
      @php $p = $s['population']; $rec = $p['count'] > 0 ? $s['reconciliation'] : null; $taken = array_values(array_filter($sales['accounts'] ?? [], fn ($a) => $a['role'] !== null)); @endphp
      @if (($sales['accounts'] ?? []) !== [])
        <div class="msg ok" data-sales-accounts>{{ __('audit_plan.sampling.ledger_accounts', ['count' => count($sales['accounts']), 'rows' => number_format(count($sales['rows']))]) }}
          @if ($taken !== [])<div style="margin-top:6px">{{ __('audit_plan.sampling.revenue_accounts') }}</div>
          <ul style="margin:4px 0 0">@foreach ($taken as $a)<li><span dir="ltr">{{ $a['number'] }}</span> — {{ $a['name'] }} ({{ __("audit_plan.sampling.roles.{$a['role']}") }}، {{ number_format($a['rows']) }})</li>@endforeach</ul>@endif</div>
      @endif
      @if (($p['period'] ?? null) && $p['period']['outside'] > 0)
        <div class="msg bad" data-sales-period>{{ __('audit_plan.sampling.other_period', ['from' => $p['period']['from'], 'to' => $p['period']['to'], 'start' => $p['period']['start'], 'end' => $p['period']['end'],
          'outside' => number_format($p['period']['outside']), 'dated' => number_format($p['period']['dated'])]) }}</div>
      @elseif ($p['period'] ?? null)
        <p class="note">{{ __('audit_plan.sampling.dates', ['from' => $p['period']['from'], 'to' => $p['period']['to']]) }}</p>
      @endif
      @if ($p['count'] > 0)
      <h4>{{ __('audit_plan.sampling.revenue_reconciliation') }}</h4>
      <table>
        <tr><td>{{ __('audit_plan.sampling.population_count') }}</td><td class="num">{{ number_format($p['count']) }}</td></tr>
        <tr><td>{{ __('audit_plan.sampling.population_sales') }}</td><td class="num">{{ $money($p['sales']) }}</td></tr>
        <tr><td>{{ __('audit_plan.sampling.population_returns') }}</td><td class="num">{{ $money($p['returns']) }}</td></tr>
        <tr><td><b>{{ __('audit_plan.sampling.population_net') }}</b></td><td class="num"><b>{{ $money($p['net']) }}</b></td></tr>
        @if ($rec)
          <tr><td>{{ __('audit_plan.sampling.revenue') }}</td><td class="num">{{ $money($rec['revenue']) }}</td></tr>
          <tr><td>{{ __('audit_plan.sampling.difference') }}</td><td class="num" @unless ($rec['agrees']) style="color:var(--bad);font-weight:600" @endunless>{{ $money($rec['difference']) }}</td></tr>
        @endif
      </table>
      @endif
      @if ($rec)
        <div class="msg {{ $rec['agrees'] ? 'ok' : 'warn' }}" style="margin-top:10px" data-reconciliation="{{ $rec['agrees'] ? 'agrees' : 'differs' }}">
          {{ $rec['agrees'] ? __('audit_plan.sampling.agrees') : __('audit_plan.sampling.differs', ['difference' => $money($rec['difference'])]) }}</div>
      @endif
    </div>
  @endif

    {{-- A new sample: the account, the method, the assurance. --}}
    {{-- No sample without an approved materiality (the version is kept on the sample). --}}
    @if ($canManage && ($m['approved'] ?? null) === null)
      <div class="msg warn" data-needs-approved-materiality>{{ __('engagement.sampling.needs_approved') }} <a href="{{ route('clients.file.materiality', [$company, 'year' => $year]) }}">{{ __('engagement.steps.materiality') }} ←</a></div>
    @endif
    @if ($canManage && ($m['approved'] ?? null) !== null)
      <form class="panel" method="POST" enctype="multipart/form-data" action="{{ route('clients.sampling.draw', $company) }}" data-sample-draw>@csrf
        <h3>＋ {{ __('audit_plan.sampling.new_sample') }}</h3>
        <input type="hidden" name="year" value="{{ $year }}">
        <div class="row">
          <div class="field" style="flex:2 1 320px"><label for="sacc">{{ __('audit_plan.sampling.account_tb') }}</label>
            <select id="sacc" name="account" data-account-select>
              @if ($tbAccounts === [] && $s['accounts'] === [])<option value="">{{ __('audit_plan.sampling.no_accounts') }}</option>@endif
              @foreach (collect($tbAccounts)->groupBy(fn ($a) => $a['main_class'] ?? '—') as $class => $accounts)
                <optgroup label="{{ $class }}">
                  @foreach ($accounts as $a)
                    <option value="tb:{{ $a['row'] }}" @selected(old('account') === 'tb:'.$a['row'])>{{ $a['number'] ? $a['number'].' — ' : '' }}{{ $a['name'] }} ({{ __("audit_plan.sampling.sides.{$a['side']}") }} {{ $money($a['movement'] ?? $a['balance']) }})</option>
                  @endforeach
                </optgroup>
              @endforeach
              @if ($tbAccounts === [] && $s['accounts'] !== [])
                <optgroup label="{{ __('audit_plan.sampling.file_populations') }}">
                  @foreach ($s['accounts'] as $a)
                    <option value="{{ $a['key'] }}">{{ $a['name'] ?? __('audit_plan.sampling.whole_list') }} — {{ __("audit_plan.sampling.roles.{$a['role']}") }} ({{ number_format($a['count']) }}، {{ $money($a['total']) }})</option>
                  @endforeach
                </optgroup>
              @endif
            </select>
            <span class="note">{{ __('audit_plan.sampling.account_tb_hint') }}</span></div>
          <div class="field"><label for="sstatement">{{ __('audit_plan.sampling.statement') }}</label>
            <input id="sstatement" type="file" name="statement" accept=".xlsx,.xlsm,.xls,.xml,.ods,.csv,.txt,.htm,.html,.pdf">
            <span class="note">{{ __('audit_plan.sampling.statement_hint') }}</span></div>
          <div class="field"><label for="smethod">{{ __('audit_plan.sampling.method') }}</label>
            <select id="smethod" name="method">@foreach (\App\Services\Audit\RevenueSampling::METHODS as $k)<option value="{{ $k }}">{{ __("audit_plan.sampling.methods.{$k}") }}</option>@endforeach</select></div>
          <div class="field" style="max-width:140px"><label for="sstrata">{{ __('audit_plan.sampling.strata') }}</label>
            <input id="sstrata" name="strata" type="number" min="2" max="10" value="3" dir="ltr"></div>
          <div class="field"><label for="sa">{{ __('audit_plan.sampling.assurance') }}</label>
            <select id="sa" name="assurance">@foreach (config('audit.sampling.factors') as $k => $f)<option value="{{ $k }}" @selected(old('assurance', $defaultAssurance ?? config('audit.sampling.default_assurance')) === $k)>{{ __("audit_plan.assurance.{$k}") }} — {{ $f }}</option>@endforeach</select>
            <span class="note">{{ __('engagement.sampling.assurance_from_risk', ['level' => __('audit_plan.assurance.'.($defaultAssurance ?? 'high'))]) }}</span></div>
          <div class="field" style="max-width:180px"><label for="sexp">{{ __('engagement.sampling.expected') }}</label>
            <input id="sexp" name="expected" type="number" step="0.01" min="0" value="{{ old('expected', 0) }}" dir="ltr"></div>
        </div>
        {{-- Above the cap: the accountant's decision on n, with its justification (asked again when n is above it). --}}
        <div class="row" data-cap-decision @if (! $errors->has('cap_decision') && ! old('cap_decision')) hidden @endif>
          <div class="field"><label for="scap">{{ __('engagement.sampling.cap_decision') }}</label>
            <select id="scap" name="cap_decision"><option value="">—</option>@foreach (config('audit.sampling.cap_decisions') as $k)<option value="{{ $k }}" @selected(old('cap_decision') === $k)>{{ __("engagement.sampling.cap.{$k}") }}</option>@endforeach</select></div>
          <div class="field" style="flex:2 1 320px"><label for="sjust">{{ __('engagement.sampling.cap_justification') }}</label>
            <input id="sjust" name="cap_justification" maxlength="1000" value="{{ old('cap_justification') }}"></div>
        </div>
        <div class="row" data-year-override @if (! $errors->has('year_override')) hidden @endif>
          <div class="field" style="flex:2 1 320px"><label for="syo">{{ __('engagement.sampling.year_override') }}</label>
            <input id="syo" name="year_override" maxlength="500" value="{{ old('year_override') }}"></div>
        </div>
        @if ($errors->hasAny(['cap_decision', 'year_override', 'expected']))<div class="msg bad" data-draw-decision>{{ implode(' ', $errors->only(['cap_decision', 'year_override', 'expected'])) }}</div>@endif
        <p class="note">{{ __('audit_plan.sampling.assurance_hint', config('audit.sampling.limits')) }} {{ __('audit_plan.sampling.methods_hint') }}</p>
        @if ($errors->hasAny(['assurance', 'account', 'method', 'statement']))<div class="msg bad">{{ implode(' ', $errors->all()) }}</div>@endif
        <button class="btn" type="submit">{{ __('audit_plan.sampling.draw') }}</button>
      </form>
    @endif

    {{-- The samples, as the working paper's table has them. --}}
    <div class="panel" data-samples>
      <h3>{{ __('audit_plan.sampling.samples_title') }}</h3>
      @if ($samples === [])
        <p class="note">{{ __('audit_plan.sampling.no_samples') }}</p>
      @else
        <table>
          <tr><th>#</th><th>{{ __('audit_plan.sampling.account') }}</th><th>{{ __('audit_plan.sampling.method') }}</th><th class="num">{{ __('audit_plan.sampling.col.population') }}</th><th class="num">{{ __('audit_plan.sampling.col.tb') }}</th>
            <th class="num">{{ __('audit_plan.sampling.col.size') }}</th><th class="num">{{ __('audit_plan.sampling.col.found') }}</th><th class="num">{{ __('audit_plan.sampling.col.projected') }}</th>
            <th>{{ __('audit_plan.sampling.col.conclusion') }}</th><th></th></tr>
          @foreach ($samples as $x)
            <tr data-sample-row="{{ $x['id'] }}" @class(['on' => $open && $open['id'] === $x['id']])>
              <td>{{ $loop->iteration }}</td>
              <td>{{ $x['account_name'] ?? __('audit_plan.sampling.whole_list') }}</td>
              <td>{{ __('audit_plan.sampling.methods.'.($x['method'] ?? 'mus')) }}</td>
              <td class="num">{{ $money($x['population']['total'] ?? null) }}</td>
              <td class="num">{{ isset($x['tb']) ? $money($x['tb']['movement'] ?? $x['tb']['balance']) : '—' }}</td>
              <td class="num">{{ number_format(count($x['items'])) }}</td>
              <td class="num">{{ $money($x['found']) }}</td>
              <td class="num">{{ $money($x['projected']) }}</td>
              <td><span class="pill {{ $conclusionClass[$x['conclusion']] }}">{{ __("audit_plan.sampling.conclusions.{$x['conclusion']}") }}</span></td>
              <td style="white-space:nowrap">
                <a class="btn small ghost" href="{{ route('clients.file.sampling', [$company, 'year' => $year, 'sample' => $x['id']]) }}">{{ __('audit_plan.sampling.open') }}</a>
                <a class="btn small" href="{{ route('clients.sampling.export', [$company, 'year' => $year, 'sample' => $x['id']]) }}" download>{{ __('audit_plan.sampling.export_short') }}</a>
                @if ($canManage)
                  <form method="POST" action="{{ route('clients.sampling.delete', [$company, $x['id']]) }}" style="display:inline" onsubmit="return confirm('{{ __('audit_plan.sampling.delete_confirm') }}')">@csrf
                    <input type="hidden" name="year" value="{{ $year }}"><button class="btn small danger" type="submit">{{ __('audit_plan.sampling.delete') }}</button></form>
                @endif
              </td>
            </tr>
          @endforeach
        </table>
      @endif
    </div>

    {{-- The sample opened: how it was drawn, and each entry's test. --}}
    @if ($open)
      @php $reasons = array_count_values(array_column($open['items'], 'reason')); @endphp
      <div class="panel" data-sample="{{ $open['id'] }}">
        <h3>{{ __('audit_plan.sampling.sample_title') }} — {{ $open['account_name'] ?? __('audit_plan.sampling.whole_list') }}</h3>
        @if ($open['stale'])<div class="msg warn">{{ __('audit_plan.sampling.stale') }}</div>@endif
        <p>{{ __('audit_plan.sampling.summary_v2', ['count' => count($open['items']), 'total' => $money($open['total']), 'coverage' => $open['coverage'] ?? '—',
          'key' => $reasons['key'] ?? 0, 'rest' => count($open['items']) - ($reasons['key'] ?? 0), 'method' => __('audit_plan.sampling.methods.'.($open['method'] ?? 'mus')),
          'assurance' => __("audit_plan.assurance.{$open['assurance']}"), 'seed' => $open['seed']]) }}
          @if ($open['interval']) {{ __('audit_plan.sampling.interval_is', ['interval' => $money($open['interval'])]) }}@endif</p>
        @if (! empty($open['tb']))
          @php $tbFigure = $open['tb']['movement'] ?? $open['tb']['balance']; $gap = round(($open['population']['total'] ?? 0) - $tbFigure, 2); @endphp
          <p @class(['note' => abs($gap) < 2, 'msg warn' => abs($gap) >= 2]) data-sample-tb>{{ __('audit_plan.sampling.tb_line', ['side' => __("audit_plan.sampling.sides.{$open['tb']['side']}"),
            'tb' => $money($tbFigure), 'population' => $money($open['population']['total'] ?? null), 'difference' => $money($gap), 'source' => $open['source'] ?? '—']) }}</p>
        @endif
        @if (($open['reassess'] ?? false))<div class="msg warn" data-sample-reassess>{{ __('engagement.sampling.reassess', ['version' => $open['materiality_version'] ?? '—', 'current' => $m['approved']['version'] ?? '—']) }}</div>@endif
        @isset($open['calculated_size'])
          {{-- The brief's arithmetic: key items outside the cap, n on the rest, the confidence achieved. --}}
          <p class="note" data-sample-calc>{{ __('engagement.sampling.calc_line', ['keys' => $open['keys']['count'] ?? 0, 'keys_total' => $money($open['keys']['total'] ?? 0),
            'remainder' => $money($open['remainder'] ?? $open['rest_total']), 'factor' => $open['factor'], 'pm' => $money($open['performance']), 'expected' => $money($open['expected'] ?? 0),
            'n' => number_format($open['calculated_size']), 'cap' => $open['cap'], 'executed' => $open['executed_size']]) }}
            @if ($open['cap_decision']) {{ __('engagement.sampling.decided', ['decision' => __('engagement.sampling.cap.'.$open['cap_decision']), 'why' => $open['cap_justification'] ?? '—', 'by' => $open['decided_by'] ?? '—']) }}@endif</p>
          <p @class(['msg', 'ok' => ($open['achieved_level'] ?? null) === 'high', 'warn' => ($open['achieved_level'] ?? null) !== 'high']) data-achieved-cf>{{ __('engagement.sampling.achieved', ['cf' => $open['achieved_cf'] ?? '—', 'level' => __('engagement.sampling.levels.'.($open['achieved_level'] ?? 'below'))]) }}</p>
        @endisset
        @isset($open['limits'])
          <p class="note" data-sample-limits>{{ __('audit_plan.sampling.limits_line', ['asked' => number_format($open['asked']), 'count' => number_format($open['limits']['count']),
            'min' => $open['limits']['min'], 'max' => $open['limits']['max'], 'taken' => count($open['items'])]) }}</p>
        @endisset
        @if (($open['strata'] ?? []) !== [])
          <table data-strata style="margin-bottom:12px">
            <tr><th>{{ __('audit_plan.sampling.col.stratum') }}</th><th class="num">{{ __('audit_plan.sampling.col.range') }}</th><th class="num">{{ __('audit_plan.sampling.col.count') }}</th>
              <th class="num">{{ __('audit_plan.sampling.col.value') }}</th><th class="num">{{ __('audit_plan.sampling.col.size') }}</th></tr>
            @foreach ($open['strata'] as $b)
              <tr><td dir="ltr">{{ $b['label'] }}</td><td class="num">{{ $money($b['from']) }} – {{ $money($b['to']) }}</td><td class="num">{{ number_format($b['count']) }}</td>
                <td class="num">{{ $money($b['total']) }}</td><td class="num">{{ $b['size'] }}</td></tr>
            @endforeach
          </table>
        @endif
        <div class="lock">
          <span>{{ __('audit_plan.sampling.col.found') }}: <b>{{ $money($open['found']) }}</b></span>
          <span>{{ __('audit_plan.sampling.col.projected') }}: <b>{{ $money($open['projected']) }}</b></span>
          <span>{{ __('audit_plan.sampling.tested', ['tested' => $open['tested'], 'count' => count($open['items'])]) }}</span>
          <span class="pill {{ $conclusionClass[$open['conclusion']] }}">{{ __("audit_plan.sampling.conclusions.{$open['conclusion']}") }}</span>
        </div>
        <p class="note">{{ __("audit_plan.sampling.conclusion_text.{$open['conclusion']}", ['pm' => $money($m['performance']), 'trivial' => $money($m['trivial']), 'projected' => $money($open['projected'])]) }}</p>
        <p><a class="btn small" href="{{ route('clients.sampling.export', [$company, 'year' => $year, 'sample' => $open['id']]) }}" download>{{ __('audit_plan.sampling.export') }}</a></p>

        <form method="POST" action="{{ route('clients.sampling.results', [$company, $open['id']]) }}" data-sample-results>@csrf
          <input type="hidden" name="year" value="{{ $year }}">
          <p class="note">{{ __('audit_plan.sampling.results_hint') }}</p>
          <div style="overflow-x:auto"><table class="sample">
            <tr>@foreach (['n', 'date', 'number', 'party', 'amount', 'reason'] as $col)<th @class(['num' => $col === 'amount'])>{{ __("audit_plan.sampling.col.{$col}") }}</th>@endforeach
              <th class="num">{{ __('audit_plan.sampling.col.audited') }}</th><th class="num">{{ __('audit_plan.sampling.col.misstatement') }}</th><th>{{ __('audit_plan.sampling.col.notes') }}</th></tr>
            @foreach ($open['items'] as $n => $it)
              <tr data-sample-item="{{ $it['index'] }}" @class(['key' => $it['reason'] === 'key', 'diff' => ($it['misstatement'] ?? 0) != 0])>
                <td>{{ $n + 1 }}</td><td dir="ltr">{{ $it['date'] ?? '' }}</td><td dir="ltr">{{ $it['number'] ?? '' }}</td><td>{{ $it['party'] ?? '' }}</td>
                <td class="num">{{ $money($it['amount'] ?? null) }}</td>
                <td>{{ __("audit_plan.reasons.{$it['reason']}") }}@isset($it['stratum']) <span class="note" dir="ltr">{{ $it['stratum'] }}</span>@endisset</td>
                <td class="num"><input name="audited[{{ $it['index'] }}]" inputmode="decimal" dir="ltr" value="{{ $it['audited'] === null ? '' : $it['audited'] }}" placeholder="{{ $it['amount'] ?? '' }}" style="width:120px" @disabled(! $canManage)></td>
                <td class="num">{{ $it['misstatement'] === null ? '' : $money($it['misstatement']) }}</td>
                <td><input name="note[{{ $it['index'] }}]" value="{{ $it['note'] ?? '' }}" style="width:200px" @disabled(! $canManage)></td>
              </tr>
            @endforeach
            <tr class="total"><td colspan="4"><b>{{ __('audit_plan.sampling.total') }}</b></td><td class="num"><b>{{ $money($open['total']) }}</b></td><td></td><td></td>
              <td class="num"><b>{{ $money($open['found']) }}</b></td><td></td></tr>
          </table></div>
          @if ($canManage)
            <div class="toolbar">
              <button class="btn" type="submit">{{ __('audit_plan.sampling.save_results') }}</button>
              <button class="btn ghost" type="button" data-fill-book onclick="this.form.querySelectorAll('input[name^=audited]').forEach(i => { if (i.value === '') i.value = i.placeholder; })">{{ __('audit_plan.sampling.fill_book') }}</button>
            </div>
          @endif
        </form>
      </div>
    @endif
  @include('portal.file._close')
@endsection
@push('styles')
<style>
  .lock{display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-top:12px;padding:10px 14px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}
  table.sample tr.key td{background:var(--warn-soft)}
  table.sample tr.diff td{color:var(--bad-ink)}
  tr.on td{background:var(--info-soft)}
</style>
@endpush
