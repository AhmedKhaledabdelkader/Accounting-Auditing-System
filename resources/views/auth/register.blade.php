@extends('layouts.auth')
@section('title', __('auth_pages.register.title'))
@section('form')
  <p class="eyebrow">{{ __('auth_pages.register.eyebrow') }}</p>
  <h1>{{ __('auth_pages.register.title') }}</h1>
  <p class="sub">{{ __('auth_pages.register.sub') }}</p>
  @if ($roles->isEmpty())
    <div class="note-bad">{{ __('auth_pages.register.no_roles') }}</div>
  @else
  <form method="POST" action="/register" novalidate>
    @csrf
    <div class="f">
      <label for="office_code">{{ __('auth_pages.login.office_code') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/></svg>
        <input id="office_code" name="office_code" dir="ltr" value="{{ old('office_code', request('office')) }}" placeholder="{{ __('auth_pages.login.office_code_ph') }}" autocomplete="organization" autocapitalize="none" spellcheck="false" required @if (! old('office_code') && ! request('office')) autofocus @endif></div>
      <div class="hint">{{ __('auth_pages.register.office_code_hint') }}</div>
      @error('office_code')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="f">
      <label for="name">{{ __('auth_pages.register.name') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <input id="name" name="name" value="{{ old('name') }}" placeholder="{{ __('auth_pages.register.name_ph') }}" autocomplete="name" required></div>
      @error('name')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="f">
      <label for="username">{{ __('auth_pages.register.username') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/></svg>
        <input id="username" name="username" dir="ltr" value="{{ old('username') }}" placeholder="ahmed.mohamed" autocomplete="username" autocapitalize="none" spellcheck="false" required></div>
      <div class="hint">{{ __('auth_pages.register.username_hint') }}</div>
      @error('username')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="f">
      <label for="password">{{ __('auth_pages.register.password') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <input id="password" name="password" type="password" dir="ltr" placeholder="{{ __('auth_pages.register.password_ph') }}" autocomplete="new-password" required
          oninput="document.getElementById('r-len').classList.toggle('ok', this.value.length >= 8); document.getElementById('r-mix').classList.toggle('ok', /\p{L}/u.test(this.value) && /\d/.test(this.value));"></div>
      <div class="rules"><span id="r-len">✓ {{ __('auth_pages.register.rule_length') }}</span><span id="r-mix">✓ {{ __('auth_pages.register.rule_mix') }}</span></div>
      @error('password')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="f">
      <label for="password_confirmation">{{ __('auth_pages.register.password_confirmation') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <input id="password_confirmation" name="password_confirmation" type="password" dir="ltr" autocomplete="new-password" required></div>
    </div>
    <div class="f">
      <label for="role_id">{{ __('auth_pages.register.role') }}</label>
      <div class="inp"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <select id="role_id" name="role_id" required>
          @foreach ($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->name() }}</option>@endforeach
        </select></div>
      <div class="hint">{{ __('auth_pages.register.role_hint') }}</div>
      @error('role_id')<div class="err">{{ $message }}</div>@enderror
    </div>
    <button class="go" type="submit">{{ __('auth_pages.register.submit') }} <svg class="arrow" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg></button>
  </form>
  @endif
  <p class="alt">{{ __('auth_pages.register.have_account') }} <a href="/login">{{ __('auth_pages.register.login') }}</a></p>
@endsection
