@extends('layouts.app')
@section('title', $company->clientName())
@section('subtitle', __('portal.ws.sub'))
@push('styles')<link rel="stylesheet" href="/css/portal.css?v={{ @filemtime(public_path('css/portal.css')) }}">@endpush
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'requirements'])
  @php $by = $progress['by']; @endphp
  <p><a href="{{ route('clients.index') }}">← {{ __('portal.ws.back') }}</a></p>

  {{-- The login the accountant just made or changed: shown once, since the password is kept only hashed. --}}
  @if (session('credentials'))
    @php $cred = session('credentials'); @endphp
    <div class="cred-box">
      <strong>{{ __('portal.ws.cred_title') }}</strong>
      <span>{{ __('portal.ws.cred_once') }}</span>
      <dl class="cred">
        <dt>{{ __('portal.ws.cred_link') }}</dt><dd>{{ url('/login') }}?office={{ $office?->code }}</dd>
        <dt>{{ __('portal.main.office_code') }}</dt><dd>{{ $office?->code }}</dd>
        <dt>{{ __('portal.form.username') }}</dt><dd>{{ $cred['username'] }}</dd>
        <dt>{{ __('portal.form.password') }}</dt><dd>{{ $cred['password'] }}</dd>
      </dl>
    </div>
  @endif

  <div class="hero">
    <div>
      <h2>{{ $company->clientName() }}</h2>
      <div class="meta">{{ $company->name_ar }}@if ($company->name_en) · {{ $company->name_en }}@endif @if ($company->cr_number) · {{ __('portal.client_info.cr') }} <span dir="ltr">{{ $company->cr_number }}</span>@endif</div>
      <div class="meta">@if ($company->client_email)<span dir="ltr">{{ $company->client_email }}</span> · @endif{{ __('portal.client_info.period') }}: {{ App\Services\Portal\Workspace::periodText($company, $company->periodYear() ?? $year) }}</div>
      <div class="meta">
        @if (! empty($company->profile['registration']['file']))<a href="{{ route('clients.documents', [$company, 'registration']) }}">{{ __('portal.client_info.registration') }}</a>@endif
        @if (! empty($company->profile['articles']['file'])) · <a href="{{ route('clients.documents', [$company, 'articles']) }}">{{ __('portal.client_info.articles') }}</a>@endif
        @if ($canManage) · <a href="{{ route('clients.edit', $company) }}">{{ __('portal.client_info.edit') }}</a>@endif
      </div>
      <div class="meta"><b>{{ __('portal.period') }}:</b> {{ App\Services\Portal\Workspace::periodText($company, $year) }}</div>
      <div class="years" style="margin-top:12px">
        @foreach ($years as $y)<a href="{{ route('clients.show', [$company, 'year' => $y]) }}" @class(['on' => $y === $year])>{{ $y }}</a>@endforeach
      </div>
    </div>
    <div class="mini-stats">
      <div class="s warn"><small>{{ __('portal.status.uploaded') }}</small><b>{{ $by['uploaded'] }}</b></div>
      <div class="s bad"><small>{{ __('portal.ws.waiting_client') }}</small><b>{{ $by['pending'] + $by['returned'] }}</b></div>
      <div class="s ok"><small>{{ __('portal.status.accepted') }}</small><b>{{ $by['accepted'] }}</b></div>
    </div>
    <div class="ring" style="--p:{{ $progress['percent'] }}" role="img" aria-label="{{ $progress['percent'] }}%"><b>{{ $progress['percent'] }}%</b></div>
  </div>

  <div class="ws">
    <div>
      {{-- The client's login --}}
      <details class="panel" @if (! $portalUser) open @endif>
        <summary>{{ __('portal.ws.access') }}
          @if ($portalUser)<span class="pill {{ $portalUser->status === 'active' ? 'ok' : 'bad' }}">{{ __($portalUser->status === 'active' ? 'portal.ws.login_on' : 'portal.main.login_off') }}</span>
          @else<span class="pill">{{ __('portal.main.no_login') }}</span>@endif</summary>
        @if ($portalUser)
          <dl class="cred">
            <dt>{{ __('portal.ws.cred_link') }}</dt><dd>{{ url('/login') }}?office={{ $office?->code }}</dd>
            <dt>{{ __('portal.main.office_code') }}</dt><dd>{{ $office?->code }}</dd>
            <dt>{{ __('portal.form.username') }}</dt><dd>{{ $portalUser->username }}</dd>
          </dl>
          @if ($canManage)
            <form method="POST" action="{{ route('clients.access', $company) }}" class="row" autocomplete="off">@csrf @method('PUT')
              <div class="field"><label for="pw">{{ __('portal.ws.new_password') }}</label>
                <div class="pwgen"><input id="pw" name="password" dir="ltr" autocomplete="new-password" spellcheck="false" required><button class="btn ghost small" type="button" data-gen="pw">{{ __('portal.form.generate') }}</button></div></div>
              <div class="field" style="flex:none;min-width:0"><label>&nbsp;</label><button class="btn small" type="submit">{{ __('portal.ws.set_password') }}</button></div>
            </form>
            <form method="POST" action="{{ route('clients.access', $company) }}">@csrf @method('PUT')
              <input type="hidden" name="active" value="{{ $portalUser->status === 'active' ? 0 : 1 }}">
              <button class="btn small {{ $portalUser->status === 'active' ? 'danger' : '' }}" type="submit">{{ __($portalUser->status === 'active' ? 'portal.ws.disable' : 'portal.ws.enable') }}</button>
            </form>
          @endif
        @elseif ($canManage)
          <p class="note">{{ __('portal.ws.no_login_hint') }}</p>
          <form method="POST" action="{{ route('clients.access', $company) }}" class="row" autocomplete="off">@csrf @method('PUT')
            <div class="field"><label for="un">{{ __('portal.form.username') }}</label><input id="un" name="username" dir="ltr" required autocapitalize="none" spellcheck="false"></div>
            <div class="field"><label for="pw2">{{ __('portal.form.password') }}</label>
              <div class="pwgen"><input id="pw2" name="password" dir="ltr" required spellcheck="false" autocomplete="new-password"><button class="btn ghost small" type="button" data-gen="pw2">{{ __('portal.form.generate') }}</button></div></div>
            <div class="field" style="flex:none;min-width:0"><label>&nbsp;</label><button class="btn small" type="submit">{{ __('portal.ws.create_login') }}</button></div>
          </form>
        @endif
      </details>

      @if ($canManage)
        <details class="panel" @if ($sections->isEmpty()) open @endif>
          <summary>{{ __('portal.ws.manage_requests') }}</summary>
          <form method="POST" action="{{ route('clients.checklist', $company) }}" class="row" style="margin-bottom:14px">@csrf
            <div class="field" style="max-width:160px"><label for="cy">{{ __('portal.form.year') }}</label><input id="cy" name="year" type="number" min="2000" max="2100" value="{{ $year }}" dir="ltr" required></div>
            <div class="field" style="flex:none;min-width:0"><label>&nbsp;</label><button class="btn small" type="submit">{{ __('portal.ws.apply_checklist', ['count' => $checklistSize]) }}</button></div>
          </form>
          <p class="note">{{ __('portal.ws.checklist_hint') }}</p>
          <h3>{{ __('portal.ws.add_request') }}</h3>
          <form method="POST" action="{{ route('clients.requests.store', $company) }}" class="row">@csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <div class="field"><label for="sec">{{ __('portal.ws.section') }}</label><input id="sec" name="section" list="sections" required maxlength="255">
              <datalist id="sections">@foreach (array_keys(config('audit_requirements')) as $s)<option value="{{ $s }}">@endforeach</datalist></div>
            <div class="field" style="flex:2"><label for="ttl">{{ __('portal.ws.request_title') }}</label><input id="ttl" name="title" required maxlength="500"></div>
            <div class="field" style="max-width:160px"><label for="fmt">{{ __('portal.ws.format') }}</label>
              <select id="fmt" name="format"><option value="">{{ __('portal.format.any') }}</option><option value="excel">{{ __('portal.format.excel') }}</option><option value="pdf">{{ __('portal.format.pdf') }}</option></select></div>
            <label style="display:flex;gap:8px;align-items:center;margin-bottom:14px"><input type="checkbox" name="optional" value="1"> {{ __('portal.optional') }}</label>
            <div class="field" style="flex:none;min-width:0"><label>&nbsp;</label><button class="btn small" type="submit">{{ __('portal.ws.add') }}</button></div>
          </form>
        </details>
      @endif

      @if ($canManage)@include('portal._follow-up', ['year' => $year, 'emails' => $emails])@endif

      @if ($canManage)@include('portal._archive', ['action' => route('clients.archive', $company), 'years' => $years, 'year' => $year, 'archives' => $archives])@endif

      @include('portal._requests', ['mode' => 'office'])
    </div>

    <aside class="ws-side">
      @include('portal._chat', ['messagesUrl' => route('clients.messages', $company, false), 'sendUrl' => route('clients.messages.send', $company, false), 'generalHint' => __('portal.chat.general_hint_office', ['name' => $company->name_ar])])
    </aside>
  </div>
  @can('clients.delete')
    <div class="panel" style="border-color:var(--bad-line,#d99)" data-delete-client>
      <h2>{{ __('portal.erase.title') }}</h2>
      <p class="note">{{ __('portal.erase.warning') }}</p>
      <form method="POST" action="{{ route('clients.destroy', $company) }}" onsubmit="return confirm(@js(__('portal.erase.confirm')))">
        @csrf @method('DELETE')
        <div class="row" style="align-items:end">
          <div class="field"><label for="confirm">{{ __('portal.erase.type_name', ['name' => $company->clientName()]) }}</label>
            <input id="confirm" name="confirm" autocomplete="off" required></div>
          <div class="field"><button class="btn danger" type="submit">{{ __('portal.erase.button') }}</button></div>
        </div>
      </form>
    </div>
  @endcan
  @include('portal.file._close')
@endsection
@push('scripts')
<script>
  document.querySelectorAll('[data-gen]').forEach((b) => b.addEventListener('click', () => {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const buf = new Uint32Array(12); crypto.getRandomValues(buf);
    let pw = Array.from(buf, (n) => chars[n % chars.length]).join('');
    if (!/\d/.test(pw)) pw = pw.slice(0, 11) + '7';
    if (!/[a-z]/i.test(pw)) pw = 'k' + pw.slice(1);
    document.getElementById(b.dataset.gen).value = pw;
  }));
</script>
@endpush
