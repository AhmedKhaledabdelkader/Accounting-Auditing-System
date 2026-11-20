@extends('layouts.app')
@section('title', __('audit.title'))
@section('subtitle', __('audit.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'findings'])
  @php
    $c = $built['counts'];
    $list = $openOnly ? array_values(array_filter($built['findings'], fn ($f) => $f['status'] === 'open')) : $built['findings'];
    $pill = ['difference' => 'bad', 'warning' => 'warn', 'info' => 'info'];
    $money = fn ($v) => $v === null ? '—' : number_format($v, 2);
  @endphp
  <div class="panel">
    <form method="GET" action="{{ route('clients.file.findings', $company) }}" class="row" style="align-items:end">
      <div class="field" style="max-width:160px"><label for="fy">{{ __('audit.year') }}</label>
        <input id="fy" name="year" type="number" min="2000" max="2100" value="{{ $year }}" dir="ltr" onchange="this.form.submit()"></div>
      <div class="field" style="flex:none;min-width:0"><span class="note">{{ \App\Services\Portal\Workspace::periodText($company, $year) }}</span></div>
      <div class="field" style="flex:none;min-width:0">
        <a class="btn small ghost" href="{{ route('clients.file.findings', [$company, 'year' => $year, 'open' => $openOnly ? 0 : 1]) }}">{{ __($openOnly ? 'audit.filter_all' : 'audit.filter_open') }}</a>
        <a class="btn small" href="{{ route('clients.findings.export', [$company, 'year' => $year]) }}" download>{{ __('audit.export') }}</a>
      </div>
    </form>
    @if ($built['trial_balance'] === null)
      <div class="msg warn" style="margin-top:12px">{{ __('audit.tb_none') }}</div>
    @elseif ($built['trial_balance']['latest'])
      <p class="note" style="margin-top:12px">{{ __('audit.tb_latest') }}</p>
    @endif
    <p style="margin-top:10px"><b>{{ __('audit.counts', ['open' => $c['open'], 'total' => $c['total'], 'differences' => $c['differences']]) }}</b></p>
    <p class="note">{{ __('audit.how') }}</p>
  </div>

  @if ($list === [])
    <div class="panel"><p>{{ __('audit.none') }}</p></div>
  @else
    <div class="panel">
      <table class="findings">
        <tr><th>{{ __('audit.col.n') }}</th><th>{{ __('audit.col.finding') }}</th><th class="num">{{ __('audit.col.document') }}</th><th class="num">{{ __('audit.col.book') }}</th>
          <th class="num">{{ __('audit.col.difference') }}</th><th>{{ __('audit.col.status') }}</th></tr>
        @foreach ($list as $n => $f)
          <tr data-finding="{{ $f['key'] }}" @class(['done' => $f['status'] !== 'open'])>
            <td>{{ $n + 1 }}</td>
            <td>
              <span class="pill {{ $pill[$f['severity']] }}">{{ __("audit.severity.{$f['severity']}") }}</span>
              <span class="note">{{ __("audit.source.{$f['source']['type']}") }}: {{ $f['source']['name'] }}</span>
              <div style="margin-top:4px"><b>{{ $f['title'] }}</b></div>
              @if ($f['account'])<div class="note">{{ __('audit.col.account') }}: {{ $f['account'] }}</div>@endif
              <div class="note">{{ $f['detail'] }}</div>
              @if ($f['entry'])
                <div class="entry">{{ __('audit.col.entry') }}: {{ __('audit.entry', ['debit' => $f['entry']['debit'], 'credit' => $f['entry']['credit'], 'amount' => number_format($f['entry']['amount'], 2)]) }}</div>
              @endif
              <div class="note">💡 {{ $f['recommendation'] }}</div>
            </td>
            <td class="num">{{ $money($f['amounts']['document']) }}</td>
            <td class="num">{{ $money($f['amounts']['book']) }}</td>
            <td class="num" @if ($f['severity'] === 'difference') style="color:var(--bad);font-weight:600" @endif>{{ $money($f['amounts']['difference']) }}</td>
            <td style="min-width:220px">
              @if ($canManage)
                <form method="POST" action="{{ route('clients.findings.decide', $company) }}">@csrf
                  <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="key" value="{{ $f['key'] }}">
                  <select name="status">@foreach (\App\Models\AuditFinding::STATUSES as $s)<option value="{{ $s }}" @selected($f['status'] === $s)>{{ __("audit.status.{$s}") }}</option>@endforeach</select>
                  <textarea name="comment" rows="2" placeholder="{{ __('audit.comment_placeholder') }}" style="margin-top:6px">{{ $f['comment'] }}</textarea>
                  <button class="btn small" type="submit" style="margin-top:6px">{{ __('audit.save') }}</button>
                </form>
              @else
                <span class="pill">{{ __("audit.status.{$f['status']}") }}</span>
                @if ($f['comment'])<div class="note">{{ $f['comment'] }}</div>@endif
              @endif
            </td>
          </tr>
        @endforeach
      </table>
    </div>
  @endif
  @include('portal.file._close')
@endsection
@push('styles')
<style>
  table.findings td{vertical-align:top}
  table.findings tr.done{opacity:.6}
  table.findings .entry{margin-top:6px;padding:6px 10px;border-radius:8px;background:var(--info-soft);font-size:13px}
  table.findings textarea,table.findings select{width:100%}
</style>
@endpush
