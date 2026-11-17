@extends('layouts.app')
@section('title', __('portal.offices.title'))
@section('subtitle', __('portal.offices.sub'))
@push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
@section('content')
  @include('partials.flash')
  <div class="panel">
    <table>
      <tr><th>{{ __('portal.offices.name') }}</th><th>{{ __('portal.main.office_code') }}</th><th>{{ __('portal.offices.staff') }}</th><th>{{ __('portal.offices.clients') }}</th><th>{{ __('portal.offices.status') }}</th><th></th></tr>
      @foreach ($offices as $o)
        <tr>
          <td><b>{{ $o->name_ar }}</b>@if ($o->name_en)<div class="note" dir="ltr">{{ $o->name_en }}</div>@endif @if ($o->is_platform)<span class="badge b-info">{{ __('portal.offices.platform') }}</span>@endif</td>
          <td><span class="office-code">{{ $o->code }}</span></td>
          <td class="num">{{ $o->staff_count }}</td>
          <td class="num">{{ $o->clients_count }}</td>
          <td><span class="badge {{ $o->status === 'active' ? 'b-ok' : 'b-bad' }}">{{ __("portal.offices.statuses.{$o->status}") }}</span></td>
          <td>
            @unless ($o->is_platform)
              <form method="POST" action="{{ route('admin.offices.update', $o) }}" class="inline">@csrf @method('PUT')
                <input type="hidden" name="status" value="{{ $o->status === 'active' ? 'disabled' : 'active' }}">
                <button class="btn small ghost" type="submit">{{ __($o->status === 'active' ? 'portal.offices.disable' : 'portal.offices.enable') }}</button></form>
            @endunless
          </td>
        </tr>
      @endforeach
    </table>
  </div>

  <div class="panel">
    <h2>{{ __('portal.offices.new') }}</h2>
    <form method="POST" action="{{ route('admin.offices.store') }}" autocomplete="off">@csrf
      <div class="row">
        <div class="field"><label for="name_ar">{{ __('portal.offices.name_ar') }}</label><input id="name_ar" name="name_ar" value="{{ old('name_ar') }}" required></div>
        <div class="field"><label for="name_en">{{ __('portal.offices.name_en') }}</label><input id="name_en" name="name_en" value="{{ old('name_en') }}" dir="ltr"></div>
        <div class="field"><label for="code">{{ __('portal.main.office_code') }}</label><input id="code" name="code" value="{{ old('code') }}" dir="ltr" required autocapitalize="none" spellcheck="false"><span class="note">{{ __('portal.offices.code_hint') }}</span></div>
      </div>
      <h3>{{ __('portal.offices.first_admin') }}</h3>
      <div class="row">
        <div class="field"><label for="admin_name">{{ __('auth_pages.users.name') }}</label><input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required></div>
        <div class="field"><label for="admin_username">{{ __('auth_pages.users.username') }}</label><input id="admin_username" name="admin_username" value="{{ old('admin_username') }}" dir="ltr" required autocapitalize="none" spellcheck="false"></div>
        <div class="field"><label for="admin_password">{{ __('portal.form.password') }}</label><input id="admin_password" type="password" name="admin_password" dir="ltr" required autocomplete="new-password"></div>
      </div>
      <button class="btn" type="submit">{{ __('portal.offices.create') }}</button>
    </form>
  </div>
@endsection
