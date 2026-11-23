@extends('layouts.app')
@section('title', __('engagement.title'))
@section('subtitle', __('engagement.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'engagement'])
  @php
    $steps = collect($e['steps']);
    $statusClass = ['not_started' => '', 'in_progress' => 'info', 'waiting_client' => 'warn', 'ready_for_review' => 'ok', 'approved' => 'ok'];
    $msg = fn ($b) => app()->getLocale() === 'ar' ? $b['message_ar'] : $b['message_en'];
    $f = $e['facts'];
  @endphp
  <div class="panel">
    <form method="GET" action="{{ route('clients.file.engagement', $company) }}" class="row" style="align-items:end">
      <div class="field" style="max-width:140px"><label for="ey">{{ __('audit_plan.year') }}</label><input id="ey" name="year" type="number" min="2000" max="2100" value="{{ $year }}" dir="ltr"></div>
      <button class="btn ghost" type="submit">{{ __('engagement.show') }}</button>
      <a class="btn ghost" href="{{ route('clients.engagement.status', [$company, 'year' => $year]) }}" target="_blank" rel="noopener">JSON</a>
    </form>
    <div class="row" style="margin-top:10px" data-engagement-summary>
      <span class="pill info">{{ __('engagement.current_phase') }}: {{ $e['current_phase'] ? __("engagement.phases.{$e['current_phase']}") : __('engagement.all_done') }}</span>
      @if ($e['next_step'])<a class="btn small" href="{{ collect($e['steps'])->firstWhere('step_key', $e['next_step'])['link'] }}" data-next-step="{{ $e['next_step'] }}">{{ __('engagement.next_step', ['step' => __("engagement.steps.{$e['next_step']}")]) }}</a>@endif
      <span class="pill">{{ __('engagement.facts.materiality') }}: {{ $f['materiality_version'] ? 'v'.$f['materiality_version'].' — '.number_format($f['overall'], 2) : '—' }}</span>
      <span class="pill {{ $f['misstatements']['above_materiality'] ? 'bad' : '' }}">{{ __('engagement.facts.misstatements') }}: {{ number_format($f['misstatements']['uncorrected'], 2) }}</span>
      <span class="pill">{{ __('engagement.facts.risks', ['total' => $f['risks']['total'], 'significant' => $f['risks']['significant']]) }}</span>
    </div>
  </div>

  <div class="eng-phases" data-phases>
    @foreach (config('engagement.phases') as $phase)
      <div @class(['ph', 'cur' => $e['current_phase'] === $phase]) data-phase="{{ $phase }}">
        <h3>{{ __("engagement.phases.{$phase}") }}</h3>
        <ol>
          @foreach ($steps->where('phase', $phase) as $s)
            <li data-step="{{ $s['step_key'] }}" data-status="{{ $s['status'] }}">
              <a href="{{ $s['link'] }}">{{ $s['n'] }}. {{ __("engagement.steps.{$s['step_key']}") }}</a>
              @if ($s['locked'])<span class="pill bad">🔒 {{ __('engagement.locked') }}</span>@endif
              <span class="pill {{ $statusClass[$s['status']] }}">{{ __("engagement.status.{$s['status']}") }}</span>
              @if ($s['prepared_by'])<small>· {{ __('engagement.roles.prepared') }}: {{ $s['prepared_by'] }}</small>@endif
              @if ($s['reviewed_by'])<small>· {{ __('engagement.roles.reviewed') }}: {{ $s['reviewed_by'] }}</small>@endif
              @if ($s['open_notes'])<span class="pill warn">{{ __('engagement.notes.open_count', ['count' => $s['open_notes']]) }}</span>@endif
            </li>
          @endforeach
        </ol>
      </div>
    @endforeach
  </div>

  <div class="panel" data-all-blockers>
    <h3>{{ __('engagement.blockers_title') }} ({{ count($e['blockers']) }})</h3>
    @if ($e['blockers'] === [])
      <p>{{ __('engagement.no_blockers') }}</p>
    @else
      <table>
        <tr><th>{{ __('engagement.col.step') }}</th><th>{{ __('engagement.col.blocker') }}</th><th></th></tr>
        @foreach ($e['blockers'] as $b)
          <tr data-blocker="{{ $b['code'] }}"><td>{{ __("engagement.steps.{$b['step_key']}") }}</td>
            <td><span class="pill {{ $b['soft'] ? 'warn' : 'bad' }}">{{ __($b['soft'] ? 'engagement.soft' : 'engagement.hard') }}</span> {{ $msg($b) }}</td>
            <td><a class="btn small ghost" href="{{ $b['link'] }}">{{ __('engagement.go') }}</a></td></tr>
        @endforeach
      </table>
    @endif
  </div>

  <details class="panel" data-audit-log>
    <summary>{{ __('engagement.log_title') }}</summary>
    <table>
      <tr><th>{{ __('engagement.col.when') }}</th><th>{{ __('engagement.col.who') }}</th><th>{{ __('engagement.col.what') }}</th><th>{{ __('engagement.col.before') }}</th><th>{{ __('engagement.col.after') }}</th></tr>
      @foreach ($log as $l)
        <tr><td dir="ltr">{{ $l->created_at?->format('Y-m-d H:i') }}</td><td>{{ $l->user_name }}</td><td>{{ $l->action }} · {{ $l->target_type }} {{ $l->target_id }}</td>
          <td><small dir="ltr">{{ \Illuminate\Support\Str::limit(json_encode($l->before, JSON_UNESCAPED_UNICODE), 140) }}</small></td>
          <td><small dir="ltr">{{ \Illuminate\Support\Str::limit(json_encode($l->after, JSON_UNESCAPED_UNICODE), 140) }}</small></td></tr>
      @endforeach
    </table>
  </details>
  @include('portal.file._close')
@endsection
