@extends('layouts.app')
@section('title', __('audit_plan.materiality.title'))
@section('subtitle', __('audit_plan.materiality.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'materiality'])
  @php
    $money = fn ($v) => $v === null ? '—' : number_format($v, 2);
    $c = $m['chosen'];
    $py = $m['report']?->comparative_end?->year ?? ($year - 1);
    $ro = ! $canManage;
    $fromTb = $m['source'] === 'trial_balance';
    $hasData = array_filter($m['figures'], fn ($v) => $v !== null) !== [];
  @endphp
  <div class="panel">
    <form method="GET" action="{{ route('clients.file.materiality', $company) }}" class="row" style="align-items:end">
      <div class="field" style="max-width:160px"><label for="my">{{ __('audit_plan.year') }}</label>
        <input id="my" name="year" type="number" min="2000" max="2100" value="{{ $year }}" dir="ltr" onchange="this.form.submit()"></div>
      <div class="field" style="flex:none;min-width:0"><span class="note">{{ \App\Services\Portal\Workspace::periodText($company, $year) }}</span></div>
    </form>
    {{-- Where the measures come from: the financial statements, or the trial balance as it is. --}}
    <div class="toolbar" style="margin-top:12px" data-materiality-source="{{ $m['source'] }}">
      <span class="note">{{ __('audit_plan.materiality.source') }}</span>
      @foreach (\App\Services\Audit\Materiality::SOURCES as $src)
        <a @class(['btn small', 'ghost' => $m['source'] !== $src]) href="{{ route('clients.file.materiality', [$company, 'year' => $year, 'source' => $src]) }}">{{ __("audit_plan.materiality.sources.{$src}") }}</a>
      @endforeach
    </div>
    @if (! $fromTb && $m['report'] === null)
      <div class="msg warn" style="margin-top:12px">{{ __('audit_plan.no_report') }}
        <a href="{{ route('clients.file.materiality', [$company, 'year' => $year, 'source' => 'trial_balance']) }}">{{ __('audit_plan.materiality.use_tb') }}</a></div>
    @elseif ($fromTb && $m['run'] === null)
      <div class="msg warn" style="margin-top:12px">{{ __('audit_plan.materiality.no_tb') }}</div>
    @elseif ($fromTb)
      <p class="note" style="margin-top:10px">{{ __('audit_plan.materiality.from_tb', ['name' => $m['run']->original_name ?? '', 'at' => $m['run']->created_at?->format('Y-m-d')]) }}</p>
    @else
      <p class="note" style="margin-top:10px">{{ __('audit_plan.materiality.from_report', ['end' => $m['report']->period_end->format('Y-m-d')]) }}</p>
    @endif
  </div>

  @if ($hasData)
    {{-- 1. The measures, this year and last. --}}
    <div class="panel">
      <h3><span class="mt-step">1</span>{{ __('audit_plan.materiality.step1') }}</h3>
      <table data-measures>
        <tr><th>{{ __('audit_plan.materiality.col_measure') }}</th><th class="num">{{ $year }}</th><th class="num">{{ $py }}</th></tr>
        @foreach (\App\Services\Audit\Materiality::MEASURES as $key => $line)
          <tr data-measure="{{ $key }}"><td>{{ __("audit_plan.benchmarks.{$key}") }}</td><td class="num">{{ $money($m['figures'][$key]) }}</td><td class="num">{{ $money($m['prior'][$key]) }}</td></tr>
        @endforeach
      </table>
      @if ($fromTb)<p class="note">{{ __('audit_plan.materiality.tb_prior_note') }}</p>@endif
    </div>

    <form method="POST" action="{{ route('clients.materiality.save', $company) }}" data-materiality-form>@csrf
      <input type="hidden" name="year" value="{{ $year }}">
      <input type="hidden" name="source" value="{{ $m['source'] }}">
      {{-- 2. A percentage on each basis, its materiality; the highest and the average. --}}
      <div class="panel">
        <h3><span class="mt-step">2</span>{{ __('audit_plan.materiality.step2') }}</h3>
        <p class="note">{{ __('audit_plan.materiality.step2_hint') }}</p>
        <table data-bases>
          <tr><th>{{ __('audit_plan.materiality.col_taken') }}</th><th>{{ __('audit_plan.materiality.col_benchmark') }}</th><th class="num">{{ __('audit_plan.materiality.col_percent') }}</th>
            <th class="num">{{ __('audit_plan.materiality.col_materiality', ['year' => $year]) }}</th><th class="num">{{ __('audit_plan.materiality.col_materiality', ['year' => $py]) }}</th></tr>
          @foreach ($m['benchmarks'] as $b)
            @php $range = config("audit.materiality.bases.{$b['key']}"); @endphp
            <tr data-basis="{{ $b['key'] }}" @class(['muted' => ! $b['taken']])>
              <td><input type="checkbox" name="bases[]" value="{{ $b['key'] }}" @checked($b['taken']) @disabled($ro)></td>
              <td>{{ __("audit_plan.benchmarks.{$b['key']}") }}
                @if ($m['proposed'] === $b['key'])<span class="pill info">{{ __('audit_plan.materiality.proposed') }}</span>@endif
                <div class="note">{{ __('audit_plan.materiality.usual', ['low' => $range['low'], 'high' => $range['high']]) }}@unless ($b['usable']) — {{ __('audit_plan.materiality.not_usable') }}@endunless</div></td>
              <td class="num"><input name="percents[{{ $b['key'] }}]" inputmode="decimal" dir="ltr" value="{{ old("percents.{$b['key']}", $b['percent']) }}" style="width:80px" @disabled($ro)> %</td>
              <td class="num">{{ $money($b['materiality']) }}</td><td class="num">{{ $money($b['prior_materiality']) }}</td>
            </tr>
          @endforeach
          @foreach (\App\Services\Audit\Materiality::AGGREGATES as $agg)
            <tr class="total" data-aggregate="{{ $agg }}"><td></td><td colspan="2"><b>{{ __("audit_plan.materiality.{$agg}") }}</b></td>
              <td class="num"><b>{{ $money($m['aggregates'][$agg]['materiality']) }}</b></td><td class="num"><b>{{ $money($m['aggregates'][$agg]['prior_materiality']) }}</b></td></tr>
          @endforeach
        </table>
      </div>

      {{-- 3. The preliminary materiality: one row. --}}
      <div class="panel">
        <h3><span class="mt-step">3</span>{{ __('audit_plan.materiality.step3') }}</h3>
        <div class="row" style="align-items:end">
          <div class="field" style="max-width:340px"><label for="mc">{{ __('audit_plan.materiality.choice') }}</label>
            <select id="mc" name="choice" @disabled($ro)>
              @foreach ($m['benchmarks'] as $b)<option value="{{ $b['key'] }}" @selected($c['choice'] === $b['key'])>{{ __("audit_plan.benchmarks.{$b['key']}") }}</option>@endforeach
              @foreach (\App\Services\Audit\Materiality::AGGREGATES as $agg)<option value="{{ $agg }}" @selected($c['choice'] === $agg)>{{ __("audit_plan.materiality.{$agg}") }}</option>@endforeach
            </select></div>
          <label style="margin-bottom:10px"><input type="checkbox" name="round" value="1" @checked($c['round']) @disabled($ro)> {{ __('audit_plan.materiality.round') }}</label>
        </div>
        <div class="mt-cards" data-materiality-result>
          <div class="mt-card"><small>{{ __('audit_plan.materiality.overall') }} — {{ $year }}</small><b data-overall>{{ $money($m['overall_rounded']) }}</b>
            @if ($c['round'] && $m['overall'] !== null)<span class="note">{{ __('audit_plan.materiality.overall_exact', ['amount' => $money($m['overall'])]) }}</span>@endif</div>
          <div class="mt-card"><small>{{ __('audit_plan.materiality.overall') }} — {{ $py }}</small><b>{{ $money($m['prior_overall']) }}</b></div>
        </div>
        @unless ($m['saved'])<div class="msg warn">{{ __('audit_plan.materiality.not_saved') }}</div>@endunless
      </div>

      {{-- 4. The levels under it. --}}
      <div class="panel">
        <h3><span class="mt-step">4</span>{{ __('audit_plan.materiality.step4') }}</h3>
        <table data-levels>
          <tr><th></th><th class="num">{{ __('audit_plan.materiality.col_percent') }}</th><th class="num">{{ __('audit_plan.materiality.col_amount') }}</th></tr>
          <tr><td>{{ __('audit_plan.materiality.performance') }}<div class="note">{{ __('audit_plan.materiality.performance_hint') }}</div></td>
            <td class="num"><input name="performance_percent" inputmode="decimal" dir="ltr" value="{{ old('performance_percent', $c['performance_percent']) }}" style="width:80px" @disabled($ro)> %</td>
            <td class="num"><b data-performance>{{ $money($m['performance']) }}</b></td></tr>
          <tr><td>{{ __('audit_plan.materiality.trivial') }}<div class="note">{{ __('audit_plan.materiality.trivial_hint') }}</div></td>
            <td class="num"><input name="trivial_percent" inputmode="decimal" dir="ltr" value="{{ old('trivial_percent', $c['trivial_percent']) }}" style="width:80px" @disabled($ro)> %</td>
            <td class="num"><b data-trivial>{{ $money($m['trivial']) }}</b></td></tr>
        </table>
        <div class="field" style="margin-top:14px"><label for="mreason">{{ __('audit_plan.materiality.reason') }}</label>
          <textarea id="mreason" name="reason" rows="3" placeholder="{{ __('audit_plan.materiality.reason_placeholder') }}" @disabled($ro)>{{ old('reason', $c['reason']) }}</textarea></div>
        @if ($errors->any())<div class="msg bad">{{ implode(' ', $errors->all()) }}</div>@endif
        @if ($canManage)<button class="btn" type="submit">{{ __('audit_plan.materiality.save') }}</button>@endif
      </div>
    </form>

    {{-- The approved versions (ISA 320): what the samples, the plan and the misstatements rest on. Never changed; a change is a new version. --}}
    <div class="panel" data-materiality-versions>
      <h3>{{ __('engagement.materiality.title') }}</h3>
      <p class="note">{{ __('engagement.materiality.hint') }}</p>
      @if ($versions->isEmpty())
        <div class="msg warn">{{ __('engagement.materiality.none') }}</div>
      @else
        <table>
          <tr><th>#</th><th>{{ __('engagement.materiality.col.benchmark') }}</th><th class="num">{{ __('audit_plan.materiality.overall') }}</th><th class="num">{{ __('audit_plan.materiality.performance') }}</th>
            <th class="num">{{ __('audit_plan.materiality.trivial') }}</th><th>{{ __('engagement.materiality.col.rationale') }}</th><th>{{ __('engagement.materiality.col.approved') }}</th></tr>
          @foreach ($versions as $v)
            <tr data-version="{{ $v->version }}" @class(['on' => $loop->first])>
              <td>{{ $v->version }}@if ($loop->first) <span class="pill ok">{{ __('engagement.materiality.in_force') }}</span>@endif</td>
              <td>{{ __("audit_plan.benchmarks.{$v->benchmark}") }} {{ rtrim(rtrim(number_format($v->pct, 2), '0'), '.') }}% × {{ $money($v->benchmark_amount) }}</td>
              <td class="num">{{ $money($v->overall) }}</td><td class="num">{{ $money($v->performance) }}</td><td class="num">{{ $money($v->trivial) }}</td>
              <td>{{ $v->rationale }}@if ($v->change_reason)<br><small>{{ __('engagement.materiality.change_reason') }}: {{ $v->change_reason }}</small>@endif
                @foreach ($v->specific ?? [] as $sp)<br><small>{{ __('engagement.materiality.specific') }}: {{ config("audit_procedures.areas.{$sp['area']}.title") }} {{ $money($sp['amount']) }}@if ($sp['reason']) — {{ $sp['reason'] }}@endif</small>@endforeach</td>
              <td>{{ $v->approved_name }}<br><small>{{ $v->approved_at?->toDateString() }} · {{ __("engagement.materiality.stages.{$v->stage}") }}</small></td>
            </tr>
          @endforeach
        </table>
      @endif
      @if ($canManage && $m['saved'])
        <form method="POST" action="{{ route('clients.materiality.approve', $company) }}" style="margin-top:12px" data-approve-materiality>@csrf
          <input type="hidden" name="year" value="{{ $year }}">
          @if (in_array($m['chosen']['choice'], \App\Services\Audit\Materiality::AGGREGATES, true))<div class="msg warn">{{ __('engagement.materiality.errors.aggregate_choice') }}</div>@endif
          <div class="row">
            <div class="field" style="flex:2 1 360px"><label for="arat">{{ __('engagement.materiality.rationale') }}</label>
              <textarea id="arat" name="rationale" rows="2" required>{{ old('rationale', $c['reason']) }}</textarea></div>
            @if ($versions->isNotEmpty())
              <div class="field" style="flex:2 1 260px"><label for="achg">{{ __('engagement.materiality.change_reason') }}</label>
                <textarea id="achg" name="change_reason" rows="2" required>{{ old('change_reason') }}</textarea></div>
            @endif
            <div class="field" style="max-width:170px"><label for="astage">{{ __('engagement.materiality.stage') }}</label>
              <select id="astage" name="stage">@foreach (['planning', 'final'] as $st)<option value="{{ $st }}">{{ __("engagement.materiality.stages.{$st}") }}</option>@endforeach</select></div>
          </div>
          <details><summary>{{ __('engagement.materiality.specific_title') }}</summary>
            @for ($i = 0; $i < 3; $i++)
              <div class="row">
                <div class="field"><select name="specific[{{ $i }}][area]" aria-label="{{ __('engagement.materiality.specific') }}"><option value="">—</option>
                  @foreach (config('audit_procedures.areas') as $k => $a)<option value="{{ $k }}">{{ $a['code'] }} {{ $a['title'] }}</option>@endforeach</select></div>
                <div class="field" style="max-width:170px"><input name="specific[{{ $i }}][amount]" type="number" step="0.01" min="0" dir="ltr" placeholder="0.00" aria-label="{{ __('audit_plan.materiality.col_amount') }}"></div>
                <div class="field" style="flex:2 1 260px"><input name="specific[{{ $i }}][reason]" maxlength="500" placeholder="{{ __('engagement.materiality.specific_reason') }}"></div>
              </div>
            @endfor
          </details>
          @if ($errors->approve->any())<div class="msg bad" data-approve-error>{{ implode(' ', $errors->approve->all()) }}</div>@endif
          <button class="btn ok" type="submit">{{ __($versions->isEmpty() ? 'engagement.materiality.approve' : 'engagement.materiality.approve_new') }}</button>
        </form>
      @endif
    </div>

    <div class="panel">
      <h3>{{ __('audit_plan.materiality.significant_title') }}</h3>
      <p class="note">{{ __('audit_plan.materiality.significant_hint') }}</p>
      @if ($m['significant'] === [])
        <p>{{ __('audit_plan.materiality.none_significant') }}</p>
      @else
        <table data-significant>
          <tr><th>{{ __('audit_plan.materiality.col_line') }}</th><th class="num">{{ __('audit_plan.materiality.col_amount') }}</th><th class="num">{{ __('audit_plan.materiality.col_times') }}</th></tr>
          @foreach ($m['significant'] as $l)
            <tr><td>{{ $l['label'] }}</td><td class="num">{{ $money($l['amount']) }}</td><td class="num">× {{ $l['times'] }}</td></tr>
          @endforeach
        </table>
      @endif
      <p style="margin-top:10px"><a class="btn small ghost" href="{{ route('clients.file.sampling', [$company, 'year' => $year]) }}">{{ __('audit_plan.materiality.to_sampling') }}</a></p>
    </div>
  @endif
  @include('portal.file._close')
@endsection
@push('styles')
<style>
  .mt-cards{display:flex;gap:12px;flex-wrap:wrap;margin:10px 0}
  .mt-card{flex:1 1 220px;border:1px solid var(--line);border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:4px}
  .mt-card b{font-size:22px}
  .mt-step{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--accent, #1f4e79);color:#fff;font-size:13px;margin-inline-end:8px}
  tr.muted td{opacity:.55}
  tr.total td{border-top:2px solid var(--line)}
</style>
@endpush
