@extends('layouts.auth')
@section('title', __('auth_pages.login.title'))
@section('form')
  <p class="eyebrow">{{ __('auth_pages.login.eyebrow') }}</p>
  <h1>{{ __('auth_pages.login.title') }}</h1>
  <p class="sub">{{ __('auth_pages.login.sub') }}</p>
  @if (session('status'))<div class="note-ok">{{ session('status') }}</div>@endif
  <form method="POST" action="/login" novalidate>
    @csrf
    <div class="f">
      <label for="office_code">{{ __('auth_pages.login.office_code') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/></svg>
        <input id="office_code" name="office_code" dir="ltr" value="{{ old('office_code', request('office')) }}" placeholder="{{ __('auth_pages.login.office_code_ph') }}" autocomplete="organization" autocapitalize="none" spellcheck="false" required @if (! old('office_code') && ! request('office')) autofocus @endif></div>
      <div class="hint">{{ __('auth_pages.login.office_code_hint') }}</div>
      @error('office_code')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="f">
      <label for="username">{{ __('auth_pages.login.username') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <input id="username" name="username" dir="ltr" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required @if (old('office_code') || request('office')) autofocus @endif></div>
      @error('username')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="f">
      <label for="password">{{ __('auth_pages.login.password') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <input id="password" name="password" type="password" dir="ltr" autocomplete="current-password" required>
        <button type="button" class="peek" data-show="{{ __('auth_pages.login.show') }}" data-hide="{{ __('auth_pages.login.hide') }}"
          onclick="const i = document.getElementById('password'); i.type = i.type === 'password' ? 'text' : 'password'; this.textContent = i.type === 'password' ? this.dataset.show : this.dataset.hide;">{{ __('auth_pages.login.show') }}</button></div>
      @error('password')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="row2"><label><input type="checkbox" name="remember" value="1" checked> {{ __('auth_pages.login.remember') }}</label></div>
    <button class="go" type="submit">{{ __('auth_pages.login.submit') }} <svg class="arrow" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg></button>
  </form>
  <p class="alt">{{ __('auth_pages.login.no_account') }} <a href="/register">{{ __('auth_pages.login.register') }}</a></p>
@endsection
