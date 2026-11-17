@extends('layouts.app')
@section('title', __("client_file.nav.steps.{$step}"))
@section('subtitle', __('client_file.choose.sub'))
@push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
@section('content')
  @include('partials.flash')
  <div class="panel" data-choose-client>
    <h2>{{ __('client_file.choose.title') }}</h2>
    <p class="note">{{ __('client_file.choose.hint', ['step' => __("client_file.nav.steps.{$step}")]) }}</p>
    @if ($clients->isEmpty())
      <p class="note">{{ __('client_file.choose.none') }}</p>
      @can('clients.manage')<a class="btn" href="{{ route('clients.create') }}">+ {{ __('portal.main.new') }}</a>@endcan
    @else
      <div class="toolbar"><div class="grow"><input type="search" id="q" placeholder="{{ __('client_file.choose.search') }}" aria-label="{{ __('client_file.choose.search') }}" autocomplete="off"></div></div>
      <div class="client-grid" id="grid">
        @foreach ($clients as $c)
          <a class="client-card" href="{{ route($route, ['client' => $c->id]) }}" data-name="{{ mb_strtolower($c->clientName().' '.$c->name_ar.' '.$c->cr_number) }}">
            <div class="top"><span class="logo">{{ mb_substr($c->clientName(), 0, 1) }}</span>
              <div style="min-width:0"><h3>{{ $c->clientName() }}</h3><div class="meta">{{ $c->name_ar }}@if ($c->cr_number) · <span dir="ltr">{{ $c->cr_number }}</span>@endif</div></div></div>
          </a>
        @endforeach
      </div>
    @endif
  </div>
@endsection
@push('scripts')
<script>
  document.getElementById('q')?.addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    document.querySelectorAll('#grid .client-card').forEach((c) => { c.hidden = q !== '' && !c.dataset.name.includes(q); });
  });
</script>
@endpush