@extends('layouts.app')
@section('title', __('engagement.risks.title'))
@section('subtitle', __('engagement.risks.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'risks'])
  @php
    $ratingClass = ['high' => 'bad', 'medium' => 'warn', 'low' => 'ok'];
    $assertions = config('audit_procedures.assertions');
  @endphp
  <div class="panel">
    <p class="note">{{ __('engagement.risks.hint') }}</p>
    @if ($canManage)
      <form method="POST" action="{{ route('clients.risks.suggest', $company) }}" style="display:inline">@csrf<input type="hidden" name="year" value="{{ $year }}">
        <button class="btn" type="submit" data-suggest-risks>{{ __('engagement.risks.suggest') }}</button></form>
    @endif
  </div>

  @forelse ($risks as $r)
    <details class="panel" data-risk="{{ $r->id }}" data-risk-status="{{ $r->status }}" @if ($r->status === 'suggested' || in_array($r->id, $unanswered, true)) open @endif>
      <summary>
        <span class="pill {{ $ratingClass[$r->inherent_rating] }}">{{ __("engagement.risks.ratings.{$r->inherent_rating}") }}</span>
        @if ($r->is_significant)<span class="pill bad">{{ __('engagement.risks.significant') }}</span>@endif
        @if ($r->is_fraud)<span class="pill bad">{{ __('engagement.risks.fraud') }}</span>@endif
        <span class="pill {{ $r->status === 'suggested' ? 'warn' : ($r->status === 'accepted' ? 'ok' : '') }}">{{ __("engagement.risks.statuses.{$r->status}") }}</span>
        <b>{{ $areas[$r->area] ?? $r->area }}</b> — {{ \Illuminate\Support\Str::limit($r->description, 140) }}
        @if (in_array($r->id, $unanswered, true))<span class="pill bad" data-unanswered>{{ __('engagement.risks.unanswered') }}</span>@endif
      </summary>
      @if ($canManage)
        @if ($r->status === 'suggested')
          <div class="row" style="margin:8px 0">
            <form method="POST" action="{{ route('clients.risks.update', [$company, $r->id]) }}">@csrf<input type="hidden" name="status" value="accepted"><button class="btn small ok" type="submit" data-accept-risk>{{ __('engagement.risks.accept') }}</button></form>
            <form method="POST" action="{{ route('clients.risks.update', [$company, $r->id]) }}">@csrf<input type="hidden" name="status" value="dismissed"><button class="btn small ghost" type="submit">{{ __('engagement.risks.dismiss') }}</button></form>
          </div>
        @endif
        @include('portal.file._risk-form', ['r' => $r, 'action' => route('clients.risks.update', [$company, $r->id]), 'procs' => $procedures[$r->area] ?? []])
        <form method="POST" action="{{ route('clients.risks.delete', [$company, $r->id]) }}" onsubmit="return confirm(@js(__('engagement.risks.delete_confirm')))">@csrf
          <button class="btn small danger" type="submit">{{ __('engagement.risks.delete') }}</button></form>
      @else
        <p>{{ $r->description }}</p>
      @endif
    </details>
  @empty
    <div class="panel"><p>{{ __('engagement.risks.none') }}</p></div>
  @endforelse

  @if ($canManage)
    <details class="panel" @if ($errors->any()) open @endif>
      <summary><b>＋ {{ __('engagement.risks.add') }}</b></summary>
      @include('portal.file._risk-form', ['r' => null, 'action' => route('clients.risks.store', $company), 'procs' => []])
    </details>
  @endif
  @include('portal.file._close')
@endsection
