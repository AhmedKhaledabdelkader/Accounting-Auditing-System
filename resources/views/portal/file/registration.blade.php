@extends('layouts.app')
@section('title', __('client_file.registration.title'))
@section('subtitle', __('client_file.registration.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'registration'])
  <div class="panel">
    <h2>{{ __('client_file.registration.read') }}</h2>
    @if ($registration)
      @php $expired = ! empty($registration['expiry_date']) && $registration['expiry_date'] < now()->toDateString(); @endphp
      <dl class="facts">
        <dt>{{ __('client_file.registration.number') }}</dt><dd dir="ltr">{{ $registration['number'] ?? '—' }}</dd>
        <dt>{{ __('client_file.registration.company_name') }}</dt><dd>{{ $registration['company_name'] ?? '—' }}</dd>
        <dt>{{ __('client_file.registration.activity') }}</dt><dd>{{ $registration['activity'] ?? '—' }}</dd>
        <dt>{{ __('client_file.registration.city') }}</dt><dd>{{ $registration['city'] ?? '—' }}</dd>
        <dt>{{ __('client_file.registration.legal_form') }}</dt><dd>{{ $registration['legal_form_text'] ?? '—' }}@if (! empty($registration['legal_form'])) <span class="pill {{ $registration['legal_form'] === 'other' ? 'warn' : 'ok' }}">{{ __('client_file.registration.legal_forms.'.$registration['legal_form']) }}</span>@endif</dd>
        @if (! empty($registration['owner_name']))
          <dt>{{ __('client_file.registration.owner') }}</dt><dd>{{ $registration['owner_name'] }}</dd>
        @endif
        <dt>{{ __('client_file.registration.unified') }}</dt><dd dir="ltr">{{ $registration['unified_number'] ?? '—' }}</dd>
        <dt>{{ __('client_file.registration.issue_date') }}</dt><dd dir="ltr">{{ $registration['issue_date'] ?? '—' }}</dd>
        <dt>{{ __('client_file.registration.issue_hijri') }}</dt><dd dir="ltr">{{ $registration['issue_hijri'] ?? $registration['issue_date_hijri'] ?? '—' }}</dd>
        <dt>{{ __('client_file.registration.expiry_date') }}</dt>
        <dd><span dir="ltr">{{ $registration['expiry_date'] ?? '—' }}</span> @if (! empty($registration['expiry_date']))<span class="pill {{ $expired ? 'bad' : 'ok' }}">{{ __($expired ? 'client_file.registration.expired' : 'client_file.registration.valid') }}</span>@endif</dd>
        <dt>{{ __('client_file.registration.file') }}</dt><dd><a href="{{ route('clients.documents', [$company, 'registration']) }}">{{ $registration['name'] }}</a></dd>
        <dt>{{ __('client_file.registration.read_at') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($registration['read_at'])->diffForHumans() }}</dd>
      </dl>
    @else
      <p class="note">{{ __('client_file.registration.none') }}</p>
    @endif
  </div>
  @can('clients.manage')
    <div class="panel">
      <h2>{{ __('client_file.registration.replace') }}</h2>
      <form method="POST" action="{{ route('clients.file.registration.update', $company) }}" enctype="multipart/form-data" class="row" style="align-items:end" data-reading>
        @csrf
        <div class="field"><input name="registration_file" type="file" accept=".pdf,.jpg,.jpeg,.png" required aria-label="{{ __('client_file.registration.replace') }}"><span class="note">{{ __('client_file.registration.replace_hint') }}</span></div>
        <div class="field"><button class="btn" type="submit">{{ __('client_file.registration.button') }}</button></div>
      </form>
    </div>
  @endcan
  @include('portal.file._close')
@endsection
@push('scripts')<script>document.querySelectorAll('[data-reading]').forEach((f) => f.addEventListener('submit', () => { if (f.checkValidity()) f.querySelector('button').disabled = true; }));</script>@endpush