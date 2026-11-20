@extends('layouts.app')
@section('title', __('mail_account.title'))
@section('subtitle', __('mail_account.sub'))
@push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
@section('content')
  @include('partials.flash')
  @if (session('mail_error'))<div class="msg bad">{{ session('mail_error') }}</div>@endif
  @php $p = old('provider', $account?->provider ?? 'gmail'); @endphp
  <div class="panel">
    @if ($account)
      <p>
        <span class="pill {{ $account->last_test_ok === null ? 'warn' : ($account->last_test_ok ? 'ok' : 'bad') }}">
          {{ $account->last_test_ok === null ? __('mail_account.not_tested') : ($account->last_test_ok ? __('mail_account.works') : __('mail_account.fails')) }}</span>
        <b dir="ltr">{{ $account->from_address }}</b>
        @if ($account->last_tested_at)<span class="note"> · {{ $account->last_tested_at->diffForHumans() }}</span>@endif
      </p>
      @if ($account->last_error)<div class="msg bad">{{ $account->last_error }}</div>@endif
      @if ($account->imap_enabled)
        <p class="note">{{ $account->imap_checked_at ? __('mail_account.imap_checked', ['when' => $account->imap_checked_at->diffForHumans()]) : __('mail_account.imap_never') }}</p>
        @if ($account->imap_error)<div class="msg bad">{{ $account->imap_error }}</div>@endif
      @endif
    @elseif ($systemMailer === 'log')
      <div class="msg warn">{{ __('mail_account.none') }}</div>
    @endif

    <form method="POST" action="{{ route('account.mail.save') }}" autocomplete="off" data-busy data-busy-title="{{ __('mail_account.testing') }}">
      @csrf
      <div class="field"><label for="provider">{{ __('mail_account.provider') }}</label>
        <select id="provider" name="provider">@foreach ($providers as $key => $meta)<option value="{{ $key }}" @selected($p === $key)>{{ $meta[0] }}</option>@endforeach</select></div>
      <div class="row">
        <div class="field"><label for="from_address">{{ __('mail_account.address') }}</label>
          <input id="from_address" name="from_address" type="email" dir="ltr" value="{{ old('from_address', $account?->from_address) }}" required autocomplete="off"></div>
        <div class="field"><label for="from_name">{{ __('mail_account.name') }}</label>
          <input id="from_name" name="from_name" value="{{ old('from_name', $account?->from_name ?? auth()->user()->name) }}" maxlength="120"></div>
      </div>
      <div class="field"><label for="password">{{ __('mail_account.password') }}</label>
        <input id="password" name="password" type="password" dir="ltr" autocomplete="new-password" spellcheck="false" @if (! $account) required @endif placeholder="{{ $account ? '••••••••••••••••' : '' }}">
        <span class="note">{{ __('mail_account.password_hint') }} <a id="app-pw-link" href="#" target="_blank" rel="noopener">{{ __('mail_account.make_password') }}</a></span></div>
      <div class="field"><label for="username">{{ __('mail_account.username') }}</label>
        <input id="username" name="username" dir="ltr" value="{{ old('username', $account?->username) }}" required autocomplete="off">
        <span class="note">{{ __('mail_account.username_hint') }}</span></div>
      <details class="paper-help" @if ($p === 'custom' || $errors->hasAny(['host', 'port', 'security'])) open @endif><summary>{{ __('mail_account.server') }}</summary>
        <div class="row">
          <div class="field"><label for="host">{{ __('mail_account.host') }}</label><input id="host" name="host" dir="ltr" value="{{ old('host', $account?->host ?? $providers[$p][1]) }}" required></div>
          <div class="field" style="max-width:130px"><label for="port">{{ __('mail_account.port') }}</label>
            <select id="port" name="port" dir="ltr">@foreach ([587, 465, 2525, 25] as $n)<option value="{{ $n }}" @selected((int) old('port', $account?->port ?? $providers[$p][2]) === $n)>{{ $n }}</option>@endforeach</select></div>
          <div class="field" style="max-width:200px"><label for="security">{{ __('mail_account.security') }}</label>
            <select id="security" name="security">@foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS'] as $k => $label)<option value="{{ $k }}" @selected(old('security', $account?->security ?? $providers[$p][3]) === $k)>{{ $label }}</option>@endforeach</select></div>
        </div>
      </details>
      <h3>{{ __('mail_account.imap_title') }}</h3>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="imap_enabled" value="1" @checked(old('imap_enabled', $account?->imap_enabled ?? true))> {{ __('mail_account.imap_on') }}</label>
      <p class="note">{{ __('mail_account.imap_hint') }}</p>
      <div class="field" style="max-width:340px"><label for="imap_host">{{ __('mail_account.imap_host') }}</label>
        <input id="imap_host" name="imap_host" dir="ltr" value="{{ old('imap_host', $account?->imap_host ?? ($providers[$p][5] ?? '')) }}" placeholder="imap.example.com"></div>
      @if ($errors->any())<div class="msg bad">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
      <div class="row">
        <button class="btn" type="submit">{{ __('mail_account.save') }}</button>
      </div>
      <p class="note">{{ __('mail_account.safe') }}</p>
    </form>

    @if ($account)
      <div class="row">
        @if ($account->imap_enabled)
          <form method="POST" action="{{ route('account.mail.check') }}" data-busy data-busy-title="{{ __('mail_account.check_now') }}">@csrf<button class="btn ghost" type="submit">{{ __('mail_account.check_now') }}</button></form>
        @endif
        <form method="POST" action="{{ route('account.mail.test') }}" data-busy data-busy-title="{{ __('mail_account.testing') }}">@csrf<button class="btn ghost" type="submit">{{ __('mail_account.test') }}</button></form>
        <form method="POST" action="{{ route('account.mail.destroy') }}" onsubmit="return confirm(@js(__('mail_account.remove_confirm')))">@csrf @method('DELETE')<button class="btn danger" type="submit">{{ __('mail_account.remove') }}</button></form>
      </div>
    @endif
  </div>

  <div class="panel">
    <h2>{{ __('mail_account.how_title') }}</h2>
    <ol class="note">@foreach (__('mail_account.how') as $step)<li>{{ $step }}</li>@endforeach</ol>
  </div>
@endsection
@push('scripts')
<script>
  // Picking a provider fills in its server and where its app password is made; «SMTP» leaves the server for the accountant to type.
  (() => {
    const providers = @js($providers);
    const $ = (id) => document.getElementById(id);
    const apply = (fill) => {
      const m = providers[$('provider').value];
      if (fill && m[1]) { $('host').value = m[1]; $('port').value = String(m[2]); $('security').value = m[3]; $('imap_host').value = m[5] || ''; }
      const link = $('app-pw-link');
      link.style.display = m[4] ? '' : 'none';
      m[4] && (link.href = m[4]);
      if (fill) $('host').closest('details').open = !m[1];
    };
    $('provider').addEventListener('change', () => apply(true));
    $('from_address').addEventListener('input', () => { if (!$('username').dataset.touched) $('username').value = $('from_address').value; });
    $('username').addEventListener('input', () => { $('username').dataset.touched = '1'; });
    apply(false);
  })();
</script>
@endpush
