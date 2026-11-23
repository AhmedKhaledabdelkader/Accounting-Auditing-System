@extends('layouts.app')
@section('title', __('engagement.release.title'))
@section('subtitle', __('engagement.release.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'release'])
  @php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $issued = ! empty($r['issued_at']);
    $hard = array_values(array_filter($e['blockers'], fn ($b) => ! $b['soft'] && $b['step_key'] !== 'release'));
  @endphp
  <div class="panel" data-release-summary>
    <h3>{{ __('engagement.release.summary') }}</h3>
    <div class="row">
      <span class="pill">{{ __('engagement.facts.materiality') }}: {{ $version ? 'v'.$version->version.' — '.$money($version->overall) : '—' }}</span>
      <span class="pill {{ $totals['above_materiality'] ? 'bad' : 'ok' }}">{{ __('engagement.misstatements.uncorrected') }}: {{ $money($totals['uncorrected']) }}</span>
      <span class="pill">{{ __('engagement.completion.going_concern.title') }}: {{ isset($c['going_concern']) ? __("engagement.completion.going_concern.options.{$c['going_concern']}") : '—' }}</span>
      <span class="pill {{ $notes->isEmpty() ? 'ok' : 'bad' }}">{{ __('engagement.notes.open_count', ['count' => $notes->count()]) }}</span>
    </div>
    @if ($hard !== [])
      <p class="msg warn">{{ __('engagement.release.other_blockers', ['count' => count($hard)]) }} <a href="{{ route('clients.file.engagement', [$company, 'year' => $year]) }}">{{ __('engagement.board_link') }}</a></p>
    @endif
    @if ($notes->isNotEmpty())
      <ul data-open-notes>@foreach ($notes as $n)<li>{{ __("engagement.steps.{$n->step_key}") }}: {{ $n->text }}</li>@endforeach</ul>
    @endif
  </div>

  <form class="panel" method="POST" action="{{ route('clients.release.save', $company) }}" data-opinion>@csrf
    <input type="hidden" name="year" value="{{ $year }}">
    <h3>{{ __('engagement.release.opinion') }}</h3>
    @foreach (config('engagement.opinions') as $o)
      <label style="display:block;font-weight:normal"><input type="radio" name="opinion" value="{{ $o }}" @checked(($r['opinion'] ?? null) === $o) @disabled($issued || ! $canManage)> {{ __("engagement.release.opinions.{$o}") }}</label>
    @endforeach
    @foreach (['basis', 'emphasis', 'other_matter'] as $fld)
      <div class="field"><label>{{ __("engagement.release.{$fld}") }}</label><textarea name="{{ $fld }}" rows="2" maxlength="3000" @disabled($issued || ! $canManage)>{{ old($fld, $r[$fld] ?? '') }}</textarea></div>
    @endforeach
    @error('release')<div class="msg bad" data-release-error>{{ $message }}</div>@enderror
    @if ($canManage && ! $issued)<button class="btn" type="submit">{{ __('audit_plan.procedures.save') }}</button>@endif
  </form>

  <div class="panel" data-issue>
    <h3>{{ __('engagement.release.issue_title') }}</h3>
    @if ($issued)
      <p class="msg ok" data-issued>{{ __('engagement.release.issued_line', ['name' => $r['issued_name'] ?? '—', 'at' => \Illuminate\Support\Str::limit($r['issued_at'], 16, ''),
        'version' => $r['snapshot']['materiality_version'] ?? '—']) }}</p>
    @else
      <p class="note">{{ __('engagement.release.issue_hint') }}</p>
      @if ($canManage)
        <form method="POST" action="{{ route('clients.release.issue', $company) }}">@csrf<input type="hidden" name="year" value="{{ $year }}">
          <button class="btn ok" type="submit">{{ __('engagement.release.issue') }}</button></form>
      @endif
    @endif
    @if ($report)<p style="margin-top:8px"><a class="btn ghost small" href="{{ route('clients.file.statements', [$company, 'year' => $year]) }}">{{ __('engagement.release.to_statements') }}</a></p>@endif
  </div>
  @include('portal.file._close')
@endsection
