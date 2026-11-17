@extends('layouts.app')
@section('title', __('auth_pages.roles.title'))
@section('subtitle', __('auth_pages.roles.sub'))
@section('content')
  @include('partials.flash')
  <div class="toolbar"><a class="btn" href="{{ route('admin.roles.create') }}">+ {{ __('auth_pages.roles.new') }}</a></div>
  <div class="cards-grid">
    @foreach ($roles as $role)
      <a class="role-card" href="{{ route('admin.roles.edit', $role) }}" data-role="{{ $role->name_ar }}">
        <div class="rc-head">
          <span class="rc-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg></span>
          <b>{{ $role->name() }}</b>
        </div>
        <div class="rc-meta">
          <span>{{ __('auth_pages.roles.users_count') }}: <b>{{ $role->users_count }}</b></span>
          <span>{{ __('auth_pages.roles.perm_count') }}: <b>{{ $role->is_admin ? __('auth_pages.roles.all') : count($role->permissions()) }}</b></span>
        </div>
        <div>
          @if ($role->is_admin)<span class="badge b-ok">{{ __('auth_pages.roles.admin_badge') }}</span>@endif
          @if ($role->open_for_signup)<span class="badge b-info">{{ __('auth_pages.roles.signup_yes') }}</span>@endif
        </div>
      </a>
    @endforeach
  </div>
@endsection
