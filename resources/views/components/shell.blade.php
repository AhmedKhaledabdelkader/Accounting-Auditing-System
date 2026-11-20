{{-- The frame of every signed-in page (public/css/theme.css): side menu (the links the user's role allows), top bar and the page heading; the page's content goes in the slot. --}}
@props(['title', 'subtitle' => null, 'actions' => null])
@php
  $user = auth()->user();
  $counts = App\Support\Access\Navigation::counts($user);
  $initials = collect(preg_split('/\s+/u', trim($user?->name ?? '')))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
@endphp
<div class="shell" id="shell">
  {{-- The menu stays as the reader left it (folded or open); set before the first paint. --}}
  <script>try { if (localStorage.getItem('shell.folded') === '1') document.getElementById('shell').classList.add('folded'); } catch (e) {}</script>
  <div class="side-frame">
  <aside class="sidebar">
    <div class="brand">
      <a href="/"><span class="mark"><img src="/img/logo-mark.png" alt=""></span><b class="label">{{ __('financial_reports.app') }}</b></a>
      <button class="fold" type="button" title="{{ __('financial_reports.nav.collapse') }}" onclick="const s = document.getElementById('shell'); s.classList.toggle('folded'); try { localStorage.setItem('shell.folded', s.classList.contains('folded') ? '1' : '0'); } catch (e) {}"><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg></button>
    </div>
    @php $active = App\Support\Access\Navigation::activeHref($user); $current = App\Support\Access\Navigation::currentClient($user); @endphp
    @foreach (App\Support\Access\Navigation::for($user) as $section => $items)
      <div class="sec"><span class="label">{{ __($section) }}</span></div>
      @if ($current && $loop->first)
        <a class="cur-client" href="{{ route('clients.show', $current['id']) }}" title="{{ $current['company'] }}"><small class="label">{{ __('nav.current_client') }}</small><b class="label">{{ $current['name'] }}</b></a>
      @endif
      <nav>
        @foreach ($items as [$href, $patterns, $key, $icon, , $hint])
          <a href="{{ $href }}" title="{{ __($key) }}" @class(['on' => $active === $href])><svg viewBox="0 0 24 24">{!! $icon !!}</svg><span class="label">{{ __($key) }}<small class="hint">{{ __($hint) }}</small></span>@isset($counts[$href])<span class="nav-count" title="{{ __($href === '/admin/users' ? 'auth_pages.nav.pending' : 'portal.nav.waiting', ['count' => $counts[$href]]) }}">{{ $counts[$href] }}</span>@endisset</a>
        @endforeach
      </nav>
    @endforeach
    @if ($user)
      @php $aiBudget = App\Support\Llm\AiBudget::of($user->id); @endphp
      @if ($aiBudget)
        @php $aiPct = min(100, round($aiBudget['spent'] / max($aiBudget['limit'], 0.0001) * 100)); @endphp
        <a class="ai-meter {{ $aiPct >= 100 ? 'out' : ($aiPct >= 80 ? 'low' : '') }}" href="{{ route('ai-limit') }}" title="{{ __('auth_pages.ai_limit.meter') }}">
          <span class="label">{{ __('auth_pages.ai_limit.meter') }} <b dir="ltr">${{ number_format(max(0, $aiBudget['limit'] - $aiBudget['spent']), 2) }}</b></span>
          <span class="bar"><i style="width:{{ $aiPct }}%"></i></span>
        </a>
      @endif
      <div class="foot">
        <a href="/account" title="{{ __('auth_pages.nav.account') }}" @class(['me', 'on' => request()->is('account')])>
          <span class="avatar">{{ $initials }}</span>
          <span class="label"><b>{{ $user->name }}</b><small>{{ $user->role?->name() }}</small></span>
        </a>
        <form method="POST" action="/logout">@csrf<button type="submit" class="out" title="{{ __('auth_pages.nav.logout') }}"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg><span class="label">{{ __('auth_pages.nav.logout') }}</span></button></form>
      </div>
    @endif
  </aside>
  </div>
  <div class="scrim" onclick="document.getElementById('shell').classList.remove('open')"></div>
  <div class="main">
    <div class="topbar">
      <button class="menu" type="button" title="{{ __('financial_reports.nav.menu') }}" onclick="document.getElementById('shell').classList.toggle('open')"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
      <div class="ctx"><b>{{ __('financial_reports.app') }}</b> · {{ $title }}</div>
      @include('partials.theme-toggle')
      <form class="lang" method="POST" action="/locale">@csrf
        @foreach (__('auth_pages.languages') as $code => $name)<button name="locale" value="{{ $code }}" @class(['on' => app()->getLocale() === $code])>{{ $name }}</button>@endforeach
      </form>
      @if ($user)<a class="avatar sm" href="/account" title="{{ $user->name }}">{{ $initials }}</a>@endif
    </div>
    <main class="content">
      <div class="page-head">
        <div>
          <p class="eyebrow">{{ __('financial_reports.app') }}</p>
          <h1>{{ $title }}</h1>
          @if ($subtitle)<p class="sub">{{ $subtitle }}</p>@endif
        </div>
        @if ($actions)<div class="acts">{{ $actions }}</div>@endif
      </div>
      {{ $slot }}
    </main>
  </div>
</div>
