@extends('layouts.app')
@section('title', __('auth_pages.users.title'))
@section('subtitle', __('auth_pages.users.sub'))
@section('content')
  @include('partials.flash')
  @if ($office)
    @push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
    <div class="code-banner"><span>{{ $office->name() }}</span><span>{{ __('portal.main.office_code') }}: <span class="office-code">{{ $office->code }}</span></span><span class="note" style="margin-inline-start:auto">{{ __('portal.main.code_hint') }}</span></div>
  @endif
  <div class="stats">
    <div class="stat"><small>{{ __('auth_pages.users.total') }}</small><b>{{ $users->count() }}</b></div>
    <div class="stat warn"><small>{{ __('auth_pages.users.pending_count') }}</small><b>{{ $users->where('status', 'pending')->count() }}</b></div>
    <div class="stat ok"><small>{{ __('auth_pages.users.active_count') }}</small><b>{{ $users->where('status', 'active')->count() }}</b></div>
  </div>
  <div class="panel">
    @if ($users->isEmpty())
      <p class="note">{{ __('auth_pages.users.empty') }}</p>
    @else
    <table data-columns="users">
      <tr><th>{{ __('auth_pages.users.name') }}</th><th>{{ __('auth_pages.users.requested') }}</th><th colspan="2">{{ __('auth_pages.users.role_status') }}</th>
        <th>{{ __('auth_pages.users.ai_limit') }}</th><th>{{ __('auth_pages.users.joined') }}</th><th></th></tr>
      @foreach ($users as $u)
        <tr data-user="{{ $u->username }}">
          <td><div class="who"><span class="avatar sm">{{ mb_substr($u->name, 0, 1) }}</span><div><b>{{ $u->name }}</b>
            @if ($u->is(auth()->user()))<span class="badge b-info">{{ __('auth_pages.users.you') }}</span>@endif
            <div class="note" dir="ltr">{{ $u->username }}</div></div></div></td>
          <td>{{ $u->requestedRole?->name() ?? '—' }}</td>
          <td colspan="2">
            <form class="inline" method="POST" action="{{ route('admin.users.update', $u) }}">@csrf @method('PUT')
              <select name="role_id" aria-label="{{ __('auth_pages.users.role') }}">
                <option value="">{{ __('auth_pages.users.no_role') }}</option>
                @foreach ($roles as $r)<option value="{{ $r->id }}" @selected(($u->role_id ?? ($u->status === 'pending' ? $u->requested_role_id : null)) == $r->id)>{{ $r->name() }}</option>@endforeach
              </select>
              <select name="status" aria-label="{{ __('auth_pages.users.status') }}">
                {{-- A pending account is offered as active: one click approves it. --}}
                @foreach (__('auth_pages.users.statuses') as $k => $label)<option value="{{ $k }}" @selected(($u->status === 'pending' ? 'active' : $u->status) === $k)>{{ $label }}</option>@endforeach
              </select>
              <button class="btn small" type="submit">{{ $u->status === 'pending' ? __('auth_pages.users.approve') : __('auth_pages.users.save') }}</button>
              <span class="badge {{ ['pending' => 'b-warn', 'active' => 'b-ok', 'disabled' => 'b-bad'][$u->status] }}">{{ __("auth_pages.users.statuses.{$u->status}") }}</span>
            </form>
          </td>
          <td>
            @php $used = (float) ($spent[$u->id] ?? 0); $limit = $u->ai_limit_usd === null ? null : (float) $u->ai_limit_usd; @endphp
            <div class="note" dir="ltr">${{ number_format($used, 2) }} / {{ $limit === null ? '∞' : '$'.number_format($limit, 2) }}</div>
            @if ($limit !== null)<span class="badge {{ $used >= $limit ? 'b-bad' : ($used >= 0.8 * $limit ? 'b-warn' : 'b-ok') }}">{{ $used >= $limit ? __('auth_pages.users.ai_used_up') : __('auth_pages.users.ai_left', ['left' => number_format(max(0, $limit - $used), 2)]) }}</span>@endif
            <form class="inline" method="POST" action="{{ route('admin.users.ai-limit', $u) }}">@csrf @method('PUT')
              <input type="number" name="ai_limit_usd" step="0.01" min="0" dir="ltr" style="width:90px" value="{{ $limit === null ? '' : $limit }}" placeholder="{{ __('auth_pages.users.ai_no_limit') }}" aria-label="{{ __('auth_pages.users.ai_limit') }}">
              <button class="btn small ghost" type="submit">{{ __('auth_pages.users.save') }}</button>
            </form>
          </td>
          <td class="num">{{ $u->created_at?->format('Y-m-d') }}</td>
          <td>
            <details class="pw"><summary class="btn ghost small">{{ __('auth_pages.users.reset_password') }}</summary>
              <form class="inline" method="POST" action="{{ route('admin.users.password', $u) }}">@csrf @method('PUT')
                <input type="password" name="password" dir="ltr" autocomplete="new-password" required>
                <button class="btn small" type="submit">{{ __('auth_pages.users.set_password') }}</button>
              </form>
            </details>
          </td>
        </tr>
      @endforeach
    </table>
    @endif
  </div>
@endsection
