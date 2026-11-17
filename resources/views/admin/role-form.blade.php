@extends('layouts.app')
@section('title', $role->exists ? __('auth_pages.roles.edit') : __('auth_pages.roles.new'))
@section('subtitle', __('auth_pages.roles.sub'))
@section('content')
  @include('partials.flash')
  <form method="POST" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}">
    @csrf
    @if ($role->exists) @method('PUT') @endif
    <div class="panel">
      <div class="row">
        <div class="field"><label for="name_ar">{{ __('auth_pages.roles.name_ar') }}</label><input id="name_ar" name="name_ar" value="{{ old('name_ar', $role->name_ar) }}" required></div>
        <div class="field"><label for="name_en">{{ __('auth_pages.roles.name_en') }}</label><input id="name_en" name="name_en" dir="ltr" value="{{ old('name_en', $role->name_en) }}"></div>
      </div>
      <div class="row">
        <label class="check"><input type="checkbox" name="is_admin" value="1" id="is_admin" @checked(old('is_admin', $role->is_admin))
          onchange="document.getElementById('perms').classList.toggle('off', this.checked)"> {{ __('auth_pages.roles.is_admin') }}</label>
        <label class="check"><input type="checkbox" name="open_for_signup" value="1" @checked(old('open_for_signup', $role->open_for_signup))> {{ __('auth_pages.roles.open_for_signup') }}</label>
      </div>
    </div>
    @php $granted = old('permissions', $granted); @endphp
    <div id="perms" @class(['perm-groups', 'off' => old('is_admin', $role->is_admin)])>
      <h2>{{ __('auth_pages.roles.permissions') }}</h2>
      @foreach (config('permissions') as $group => $keys)
        <div class="panel perm-group">
          <div class="pg-head">
            <h3>{{ __("permissions.groups.{$group}") }}</h3>
            <label class="check note"><input type="checkbox" onchange="this.closest('.perm-group').querySelectorAll('input[name=\'permissions[]\']').forEach((c) => { c.checked = this.checked; })"> {{ __('auth_pages.roles.select_all') }}</label>
          </div>
          <div class="perm-list">
            @foreach ($keys as $key)
              <label class="perm"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $granted, true))> {{ __("permissions.keys.{$key}") }}</label>
            @endforeach
          </div>
        </div>
      @endforeach
    </div>
    <div class="toolbar">
      <button class="btn" type="submit">{{ __('auth_pages.roles.save') }}</button>
      <a class="btn ghost" href="{{ route('admin.roles') }}">{{ __('auth_pages.roles.back') }}</a>
    </div>
  </form>
  @if ($role->exists)
    <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('auth_pages.roles.confirm_delete') }}">
      @csrf @method('DELETE')
      <button class="btn danger small" type="submit">{{ __('auth_pages.roles.delete') }}</button>
    </form>
  @endif
@endsection
