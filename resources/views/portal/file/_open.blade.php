{{-- The page of a client. Steps 1 to 3 (the client's file) sit in a side menu of the client; the work steps (4 to 10, the requirements among them)
     sit under a bar naming the client, with the steps as chips. $company, $active. --}}
@push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
@php
  $steps = \App\Support\ClientSteps::of($company);
  $work = array_key_exists($active ?? '', \App\Support\ClientSteps::WORK);
  // The engagement file of the year shown: each step's status, number and lock (App\Services\Engagement\Engagement decides).
  $eYear = $year ?? \App\Services\Engagement\Engagement::year(request(), $company);
  $eng = $work ? app(\App\Services\Engagement\Engagement::class)->status($company, $eYear) : null;
  $eSteps = $eng ? collect($eng['steps'])->keyBy('step_key') : collect();
  $mark = ['not_started' => '○', 'in_progress' => '◐', 'waiting_client' => '⏳', 'ready_for_review' => '✓', 'approved' => '✔'];
@endphp
@if ($work)
<div class="cfw">
  <header class="cf-bar">
    <div class="who"><b>{{ $company->clientName() }}</b><small>{{ $company->name_ar }}@if ($company->cr_number) · <span dir="ltr">{{ $company->cr_number }}</span>@endif</small></div>
    <ol class="cf-chips" aria-label="{{ __('client_file.nav.label') }}">
      @foreach (\App\Support\ClientSteps::WORK as $key => $route)
        @php $es = $eSteps[$key] ?? null; $st = $es['status'] ?? 'not_started'; @endphp
        <li><a href="{{ route($route, [$company, 'year' => $eYear]) }}" @class(['on' => $active === $key, 'locked' => $es['locked'] ?? false]) @if ($active === $key) aria-current="page" @endif data-step-chip="{{ $key }}" data-status="{{ $st }}">
          <span class="n">{{ $es['n'] ?? '◆' }}</span>{{ __("client_file.nav.steps.{$key}") }}
          @if ($es)<span class="st {{ in_array($st, ['ready_for_review', 'approved'], true) ? 'done' : 'todo' }}" title="{{ __("engagement.status.{$st}") }}">{{ ($es['locked'] ?? false) ? '🔒' : $mark[$st] }}</span>@endif</a></li>
      @endforeach
    </ol>
    <div class="acts">
      <a href="{{ route('clients.edit', $company) }}">{{ __('client_file.nav.client_file') }}</a>
      <a href="{{ route(\App\Http\Controllers\Portal\WorkController::ROUTES[$active], ['change' => 1]) }}">{{ __('client_file.nav.change_client') }}</a>
    </div>
  </header>
  <div class="cf-main">
    @include('partials.flash')
    @if ($eng && isset($eSteps[$active]))@include('portal.file._engagement', ['company' => $company, 'year' => $eYear, 'eng' => $eng, 'es' => $eSteps[$active]])@endif
@else
<div class="cf">
  <nav class="cf-nav" aria-label="{{ __('client_file.nav.label') }}">
    <div class="cf-head">
      <a class="note" href="{{ route('clients.index') }}">← {{ __('client_file.nav.all_clients') }}</a>
      <b>{{ $company->clientName() }}</b>
      <small>{{ $company->name_ar }}@if ($company->cr_number) · <span dir="ltr">{{ $company->cr_number }}</span>@endif</small>
    </div>
    <ol class="cf-steps">
      @foreach (\App\Support\ClientSteps::ORDER as $key => $route)
        @php $s = $steps[$key]; @endphp
        <li><a href="{{ route($route, $company) }}" @class(['on' => ($active ?? null) === $key]) @if (($active ?? null) === $key) aria-current="page" @endif>
          <span class="n">{{ $loop->iteration }}</span>
          <span class="t">{{ __("client_file.nav.steps.{$key}") }}</span>
          <span class="st {{ $s['done'] ? 'done' : 'todo' }}" title="{{ __($s['done'] ? 'client_file.nav.done' : 'client_file.nav.todo') }}">{{ $s['done'] ? '✓' : '○' }}</span>
        </a></li>
      @endforeach
    </ol>
  </nav>
  <div class="cf-main">
    @include('partials.flash')
@endif