@extends('layouts.app')
@section('title', __('client_file.prior.title'))
@section('subtitle', __('client_file.prior.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'prior'])
  <div class="panel">
    <h2>{{ __('client_file.prior.files') }}</h2>
    @if ($files->isEmpty())
      <p class="note">{{ __('client_file.prior.none') }}</p>
    @else
      <table>
        <tr><th>{{ __('client_file.prior.name') }}</th><th>{{ __('client_file.prior.request') }}</th><th>{{ __('client_file.prior.review') }}</th></tr>
        @foreach ($files as $f)
          <tr><td><a href="{{ route('clients.files.download', [$company, $f['id']]) }}">{{ $f['name'] }}</a><div class="note">{{ \Illuminate\Support\Carbon::parse($f['uploaded_at'])->diffForHumans() }}</div></td>
            <td>{{ $f['request']['title'] ?? '—' }}</td>
            <td><span class="pill {{ $f['review'] === 'ok' ? 'ok' : ($f['review'] && $f['review'] !== 'unavailable' ? 'warn' : '') }}">{{ __($f['review'] === 'ok' ? 'client_file.prior.fine' : ($f['review'] && $f['review'] !== 'unavailable' ? 'client_file.prior.remarks' : 'client_file.prior.not_reviewed')) }}</span></td></tr>
        @endforeach
      </table>
    @endif
  </div>
  <div class="panel">
    <h2>{{ __('client_file.prior.compare') }}</h2>
    <p class="note">{{ __('client_file.prior.compare_hint') }}</p>
    @if ($selected)
      <p><a class="btn small ghost" href="/financial-reports/{{ $selected->id }}#prior_year">{{ __('client_file.prior.open_prior') }}</a></p>
    @else
      <p class="note">{{ __('client_file.prior.no_statements') }}</p>
    @endif
  </div>
  @include('portal.file._report-picker')
  @if ($selected) @include('portal.file._embed') @endif
  @include('portal.file._close')
@endsection