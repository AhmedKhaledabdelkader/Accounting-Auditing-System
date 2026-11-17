@extends('layouts.app')
@section('title', __('portal.main.title'))
@section('subtitle', __('portal.main.sub'))
@push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
@section('content')
  @include('partials.flash')
  <div class="code-banner">
    <span>{{ __('portal.main.office') }}: <b>{{ $office?->name() }}</b></span>
    @if ($office)<span>{{ __('portal.main.office_code') }}: <span class="office-code">{{ $office->code }}</span></span>@endif
    <span class="note" style="margin-inline-start:auto">{{ __('portal.main.code_hint') }}</span>
  </div>

  <div class="stats">
    <div class="stat"><small>{{ __('portal.main.clients') }}</small><b>{{ $totals['clients'] }}</b></div>
    <div class="stat warn"><small>{{ __('portal.main.to_review') }}</small><b>{{ $totals['review'] }}</b></div>
    <div class="stat"><small>{{ __('portal.main.open') }}</small><b>{{ $totals['open'] }}</b></div>
    <div class="stat {{ $totals['unread'] ? 'warn' : '' }}"><small>{{ __('portal.main.unread') }}</small><b>{{ $totals['unread'] }}</b></div>
  </div>

  <div class="toolbar">
    <div class="grow"><input type="search" id="q" placeholder="{{ __('portal.main.search') }}" aria-label="{{ __('portal.main.search') }}" autocomplete="off"></div>
    @if ($canManage)<a class="btn" href="{{ route('clients.create') }}">+ {{ __('portal.main.new') }}</a>@endif
  </div>

  <div class="client-grid" id="grid">
    @foreach ($clients as $c)
      @php
        $company = $c['company']; $p = $c['progress']; $by = $p['by'];
        $attention = $by['uploaded'] > 0 || $c['unread'] > 0;
      @endphp
      <a class="client-card {{ $attention ? 'attention' : '' }}" href="{{ route('clients.show', $company) }}" data-name="{{ mb_strtolower($company->clientName().' '.$company->client_email.' '.$company->name_ar.' '.$company->name_en.' '.$company->cr_number.' '.($c['user']?->username)) }}">
        <div class="top">
          <span class="logo">{{ mb_substr($company->clientName(), 0, 1) }}</span>
          <div style="min-width:0"><h3>{{ $company->clientName() }}</h3><div class="meta">{{ $company->name_ar }}@if ($company->cr_number) · <span dir="ltr">{{ $company->cr_number }}</span>@endif</div></div>
        </div>
        @if ($p['total'])
          <div>
            <div class="line"><span>{{ __('portal.main.checklist', ['year' => $c['year']]) }}</span><b>{{ $p['done'] }}/{{ $p['total'] }}</b></div>
            <div class="bar" role="progressbar" aria-valuenow="{{ $p['percent'] }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ $p['percent'] }}%"></i></div>
          </div>
        @else
          <div class="note">{{ __('portal.main.no_requests') }}</div>
        @endif
        <div class="pills">
          @if ($by['uploaded'])<span class="pill warn">{{ __('portal.main.pill_review', ['count' => $by['uploaded']]) }}</span>@endif
          @if ($c['unread'])<span class="pill bad">{{ __('portal.main.pill_unread', ['count' => $c['unread']]) }}</span>@endif
          @if ($by['pending'] + $by['returned'])<span class="pill info">{{ __('portal.main.pill_open', ['count' => $by['pending'] + $by['returned']]) }}</span>@endif
          @if ($p['total'] && $p['percent'] === 100)<span class="pill ok">{{ __('portal.main.pill_complete') }}</span>@endif
          @if ($canManage && $company->clientName() === $company->name_ar)<span class="pill warn" title="{{ __('portal.main.name_missing_hint') }}">{{ __('portal.main.name_missing') }}</span>@endif
          @if (! $c['user'])<span class="pill">{{ __('portal.main.no_login') }}</span>
          @elseif ($c['user']->status !== 'active')<span class="pill bad">{{ __('portal.main.login_off') }}</span>@endif
        </div>
      </a>
    @endforeach
    @if ($canManage)
      <a class="client-card new-card" href="{{ route('clients.create') }}">
        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        <span>{{ __('portal.main.new') }}</span>
        <small class="note">{{ __('portal.main.new_hint') }}</small>
      </a>
    @endif
  </div>
  @if ($clients->isEmpty())
    <div class="panel empty-state"><h3>{{ __('portal.main.empty_title') }}</h3><p>{{ __('portal.main.empty_sub') }}</p></div>
  @endif
@endsection
@push('scripts')
<script>
  // The search box filters the cards by name, commercial register number or the client's username.
  document.getElementById('q')?.addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    document.querySelectorAll('#grid .client-card[data-name]').forEach((c) => { c.hidden = q !== '' && !c.dataset.name.includes(q); });
  });
</script>
@endpush
