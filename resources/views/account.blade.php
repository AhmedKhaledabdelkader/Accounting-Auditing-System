@extends('layouts.app')
@section('title', __('auth_pages.account.title'))
@section('subtitle', __('auth_pages.account.sub'))
@section('content')
  @include('partials.flash')
  @if (session('token'))
    <div class="msg warn">{{ __('auth_pages.account.token_created') }} <code class="token" dir="ltr">{{ session('token') }}</code></div>
  @endif
  <div class="two">
    <div class="panel">
      <h2>{{ __('auth_pages.account.profile') }}</h2>
      <div class="who"><span class="avatar">{{ mb_substr($user->name, 0, 1) }}</span><div><b>{{ $user->name }}</b><div class="note" dir="ltr">{{ $user->username }}</div></div></div>
      <p class="note">{{ __('auth_pages.account.role') }}: <b>{{ $user->role?->name() ?? '—' }}</b></p>
      <h3>{{ __('auth_pages.account.language') }}</h3>
      <form class="inline" method="POST" action="{{ route('locale') }}">@csrf
        <select name="locale">@foreach (__('auth_pages.languages') as $code => $name)<option value="{{ $code }}" @selected($user->locale === $code)>{{ $name }}</option>@endforeach</select>
        <button class="btn small" type="submit">{{ __('auth_pages.account.save_language') }}</button>
      </form>
    </div>
    <div class="panel">
      <h2>{{ __('auth_pages.account.password') }}</h2>
      <form method="POST" action="{{ route('account.password') }}">@csrf @method('PUT')
        <div class="field"><label for="current_password">{{ __('auth_pages.account.current_password') }}</label><input id="current_password" type="password" name="current_password" dir="ltr" autocomplete="current-password" required></div>
        <div class="field"><label for="password">{{ __('auth_pages.account.new_password') }}</label><input id="password" type="password" name="password" dir="ltr" autocomplete="new-password" required></div>
        <div class="field"><label for="password_confirmation">{{ __('auth_pages.account.password_confirmation') }}</label><input id="password_confirmation" type="password" name="password_confirmation" dir="ltr" autocomplete="new-password" required></div>
        <button class="btn" type="submit">{{ __('auth_pages.account.save_password') }}</button>
      </form>
    </div>
  </div>
  @can('clients.manage')
    <div class="panel">
      <h2>{{ __('mail_account.title') }}</h2>
      <p class="note">{{ __('mail_account.sub') }}</p>
      <a class="btn ghost" href="{{ route('account.mail') }}">{{ __('mail_account.open') }}</a>
    </div>
  @endcan
  <div class="panel">
    <h2>{{ __('auth_pages.account.columns') }}</h2>
    <p class="note">{{ __('auth_pages.account.columns_hint') }}</p>
    @php $arranged = count($user->preferences['columns'] ?? []); @endphp
    @if ($arranged)
      <form class="inline" method="POST" action="{{ route('account.columns.reset') }}">@csrf @method('DELETE')
        <span>{{ __('auth_pages.account.columns_saved', ['count' => $arranged]) }}</span>
        <button class="btn ghost small" type="submit">{{ __('auth_pages.account.columns_reset') }}</button>
      </form>
    @else
      <p class="note">{{ __('auth_pages.account.columns_none') }}</p>
    @endif
  </div>
  @can('api_tokens.manage')
    <div class="panel">
      <h2>{{ __('auth_pages.account.tokens') }}</h2>
      <p class="note">{{ __('auth_pages.account.tokens_hint') }}</p>
      <form class="inline" method="POST" action="{{ route('account.tokens.store') }}">@csrf
        <input name="name" placeholder="{{ __('auth_pages.account.token_name') }}" required>
        <button class="btn small" type="submit">{{ __('auth_pages.account.create_token') }}</button>
      </form>
      @if ($tokens->isEmpty())
        <p class="note">{{ __('auth_pages.account.no_tokens') }}</p>
      @else
        <table>
          <tr><th>{{ __('auth_pages.account.token_name') }}</th><th>{{ __('auth_pages.account.last_used') }}</th><th></th></tr>
          @foreach ($tokens as $t)
            <tr><td>{{ $t->name }}</td><td class="num">{{ $t->last_used_at?->format('Y-m-d H:i') ?? __('auth_pages.account.never') }}</td>
              <td><form method="POST" action="{{ route('account.tokens.destroy', $t->id) }}">@csrf @method('DELETE')<button class="btn danger small" type="submit">{{ __('auth_pages.account.revoke') }}</button></form></td></tr>
          @endforeach
        </table>
      @endif
    </div>
  @endcan
@endsection
