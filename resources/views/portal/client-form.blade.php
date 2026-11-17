@php
  $editing = $company !== null;
  $val = fn (string $field, $default = null) => old($field, $default);
  $months = __('portal.months');
@endphp
@extends('layouts.app')
@section('title', __($editing ? 'portal.form.edit_title' : 'portal.form.title'))
@section('subtitle', __($editing ? 'portal.form.edit_sub' : 'portal.form.sub'))
@push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
@section('content')
  @if ($editing) @include('portal.file._open', ['company' => $company, 'active' => 'details']) @else @include('partials.flash') @endif
  <form method="POST" action="{{ $editing ? route('clients.update', $company) : route('clients.store') }}" enctype="multipart/form-data" autocomplete="off" id="client-form">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="panel">
      <h2>{{ __('portal.form.client') }}</h2>
      <div class="row">
        <div class="field"><label for="client_name">{{ __('portal.form.client_name') }}</label>
          <input id="client_name" name="client_name" value="{{ $val('client_name', $company?->client_name) }}" required maxlength="255" autofocus>
          <span class="note">{{ __('portal.form.client_name_hint') }}</span></div>
        <div class="field"><label for="client_email">{{ __('portal.form.client_email') }}</label>
          <input id="client_email" name="client_email" type="email" value="{{ $val('client_email', $company?->client_email) }}" required maxlength="255" dir="ltr"></div>
      </div>
    </div>

    <div class="panel">
      <h2>{{ __('portal.form.company') }}</h2>
      <div class="row">
        <div class="field"><label for="name_ar">{{ __('portal.form.name_ar') }}</label><input id="name_ar" name="name_ar" value="{{ $val('name_ar', $company?->name_ar) }}" required maxlength="255"></div>
        <div class="field"><label for="name_en">{{ __('portal.form.name_en') }}</label><input id="name_en" name="name_en" value="{{ $val('name_en', $company?->name_en) }}" required maxlength="255" dir="ltr"></div>
      </div>

      <div class="field">
        <label>{{ __('portal.form.period') }}</label>
        <div class="row" style="align-items:end">
          <div class="field"><label for="period_start" class="note">{{ __('portal.form.period_from') }}</label>
            <input id="period_start" name="period_start" type="date" value="{{ $val('period_start', $company?->period_start?->format('Y-m-d')) }}" required dir="ltr"></div>
          <div class="field"><label for="period_end" class="note">{{ __('portal.form.period_to') }}</label>
            <input id="period_end" name="period_end" type="date" value="{{ $val('period_end', $company?->period_end?->format('Y-m-d')) }}" required dir="ltr"></div>
        </div>
        <span class="note">{{ __('portal.form.period_hint') }}</span>
      </div>

      <div class="field">
        <label for="registration_file">{{ __('portal.form.registration_file') }}</label>
        <input id="registration_file" name="registration_file" type="file" accept=".pdf,.jpg,.jpeg,.png" @required(! $editing)>
        <span class="note">{{ __('portal.form.registration_hint') }}</span>
        @if ($editing)
          <div class="row" style="margin-top:8px">
            <div class="field"><label for="cr_number">{{ __('portal.form.cr_number') }}</label>
              <input id="cr_number" name="cr_number" value="{{ $val('cr_number', $company->cr_number) }}" maxlength="10" inputmode="numeric" dir="ltr">
              <span class="note">{{ __('portal.form.cr_number_hint') }}</span></div>
            @if (! empty($company->profile['registration']['file']))
              <div class="field"><label>&nbsp;</label><a href="{{ route('clients.documents', [$company, 'registration']) }}">{{ __('portal.form.current_file') }}: {{ $company->profile['registration']['name'] }}</a></div>
            @endif
          </div>
        @endif
      </div>

      <div class="field">
        <label for="articles_file">{{ __('portal.form.articles_file') }}</label>
        <input id="articles_file" name="articles_file" type="file" accept=".pdf">
        <span class="note">{{ __('portal.form.articles_hint') }}</span>
        @if ($editing && ! empty($company->profile['articles']['file']))
          <a href="{{ route('clients.documents', [$company, 'articles']) }}">{{ __('portal.form.current_file') }}: {{ $company->profile['articles']['name'] }}</a>
        @endif
      </div>
    </div>

    @unless ($editing)
      <div class="panel">
        <h2>{{ __('portal.form.login') }}</h2>
        <p class="note">{{ __('portal.form.login_hint', ['code' => $office?->code]) }}</p>
        <div class="row">
          <div class="field"><label for="username">{{ __('portal.form.username') }}</label>
            <input id="username" name="username" value="{{ old('username') }}" dir="ltr" required autocapitalize="none" spellcheck="false" autocomplete="off">
            <span class="note">{{ __('auth_pages.register.username_hint') }}</span></div>
          <div class="field"><label for="password">{{ __('portal.form.password') }}</label>
            <div class="pwgen"><input id="password" name="password" value="{{ old('password') }}" dir="ltr" required autocomplete="new-password" spellcheck="false">
              <button class="btn ghost small" type="button" id="gen">{{ __('portal.form.generate') }}</button></div>
            <span class="note">{{ __('portal.form.password_hint') }}</span></div>
        </div>
      </div>

      <div class="panel">
        <h2>{{ __('portal.form.requests') }}</h2>
        <label style="display:flex;gap:10px;align-items:center;margin-bottom:12px"><input type="checkbox" name="checklist" value="1" @checked(old('checklist', '1'))> {{ __('portal.form.apply_checklist', ['count' => collect(config('audit_requirements'))->flatten(1)->count()]) }}</label>
        <p class="note" id="period-text" data-months='@json($months)' data-template="{{ __('portal.period_text', ['from' => ':from', 'to' => ':to']) }}" data-lead="{{ __('portal.form.requests_for') }}"></p>
        <p class="note">{{ __('portal.form.requests_hint') }}</p>
      </div>
    @endunless

    <div class="toolbar">
      <button class="btn" type="submit" id="submit">{{ __($editing ? 'portal.form.save' : 'portal.form.create') }}</button>
      <a class="btn ghost" href="{{ $editing ? route('clients.show', $company) : route('clients.index') }}">{{ __('portal.cancel') }}</a>
      <span class="note" id="reading" hidden>{{ __('portal.form.reading') }}</span>
    </div>
  </form>
  @if ($editing) @include('portal.file._close') @endif
@endsection
@push('scripts')
<script>
  const $ = (id) => document.getElementById(id);
  // The period is typed by the accountant, both dates with their years; nothing is proposed. The checklist is for this period.
  const showPeriod = () => {
    const out = $('period-text');
    const start = $('period_start').value, end = $('period_end').value;
    if (!out) return;
    if (!start || !end) { out.textContent = ''; return; }
    const months = JSON.parse(out.dataset.months);
    const text = (iso) => { const [y, m, d] = iso.split('-').map(Number); return `${d} ${months[m]} ${y}`; };
    out.textContent = out.dataset.lead + ' ' + out.dataset.template.replace(':from', text(start)).replace(':to', text(end));
  };
  $('period_start').addEventListener('change', showPeriod);
  $('period_end').addEventListener('change', showPeriod);
  showPeriod();
  // Reading the documents takes a little while: say so, and don't send twice.
  $('client-form').addEventListener('submit', () => {
    if (!$('client-form').checkValidity()) return;
    $('submit').disabled = true;
    $('reading').hidden = false;
  });

  @unless ($editing)
  // A strong random password for the client (letters and digits, no look-alike characters).
  $('gen').addEventListener('click', () => {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const buf = new Uint32Array(12); crypto.getRandomValues(buf);
    let pw = Array.from(buf, (n) => chars[n % chars.length]).join('');
    if (!/\d/.test(pw)) pw = pw.slice(0, 11) + '7';
    if (!/[a-z]/i.test(pw)) pw = 'k' + pw.slice(1);
    $('password').value = pw;
  });
  @endunless
</script>
@endpush
