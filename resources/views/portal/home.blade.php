@extends('layouts.app')
@section('title', __('portal.home.title'))
@section('subtitle', __('portal.home.sub', ['office' => $office?->name()]))
@push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
@section('content')
  @include('partials.flash')
  @if (session('error_status'))<div class="msg bad">{{ session('error_status') }}</div>@endif
  @php $by = $progress['by']; @endphp

  <div class="hero">
    <div>
      <h2>{{ $company->name_ar }}</h2>
      <div class="meta">{{ __('portal.home.year', ['year' => $year]) }}@if ($company) · {{ App\Services\Portal\Workspace::periodText($company, $year) }}@endif</div>
      @if (count($years) > 1)
        <div class="years" style="margin-top:12px">@foreach ($years as $y)<a href="{{ route('portal.home', ['year' => $y]) }}" @class(['on' => $y === $year])>{{ $y }}</a>@endforeach</div>
      @endif
    </div>
    <div class="mini-stats">
      <div class="s bad"><small>{{ __('portal.home.to_send') }}</small><b>{{ $inbox }}</b></div>
      <div class="s warn"><small>{{ __('portal.home.in_review') }}</small><b>{{ $by['uploaded'] }}</b></div>
      <div class="s ok"><small>{{ __('portal.status.accepted') }}</small><b>{{ $by['accepted'] }}</b></div>
    </div>
    <div class="ring" style="--p:{{ $progress['percent'] }}" role="img" aria-label="{{ $progress['percent'] }}%"><b>{{ $progress['percent'] }}%</b></div>
  </div>

  @if ($progress['total'] && $inbox === 0)<div class="msg ok">{{ __('portal.home.all_sent') }}</div>@endif

  <div class="ws">
    <div>@include('portal._archive', ['action' => route('portal.archive'), 'years' => $years, 'year' => $year, 'archives' => $archives])
      @include('portal._requests', ['mode' => 'client', 'canManage' => false])</div>
    <aside class="ws-side">
      @include('portal._chat', ['messagesUrl' => route('portal.messages', [], false), 'sendUrl' => route('portal.messages.send', [], false), 'generalHint' => __('portal.chat.general_hint_client', ['office' => $office?->name()])])
    </aside>
  </div>
@endsection
