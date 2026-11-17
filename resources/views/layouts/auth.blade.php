<!DOCTYPE html>
{{-- Sign-in and sign-up: the form beside a panel about the app (public/css/auth.css). --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') — {{ __('financial_reports.app') }}</title>
@include('partials.theme-head')
<link rel="stylesheet" href="/css/auth.css?v={{ @filemtime(public_path('css/auth.css')) }}">
</head>
<body>
<div class="auth">
  <main class="form-side">
    @include('partials.theme-toggle')
    <form class="lang" method="POST" action="/locale">@csrf
      @foreach (__('auth_pages.languages') as $code => $name)
        <button name="locale" value="{{ $code }}" @class(['on' => app()->getLocale() === $code])>{{ $name }}</button>
      @endforeach
    </form>
    <div class="box">
      @yield('form')
    </div>
  </main>
  <aside class="brand-side">
    <a class="logo" href="/login"><span class="mark"><img src="/img/logo-mark.png" alt=""></span><b>{{ __('financial_reports.app') }}</b></a>
    {{-- A ledger's two columns drifting by behind the panel (the same figures twice, for a seamless loop). --}}
    @php $lines = collect(range(1, 16))->map(fn ($i) => [number_format(($i * 7919 % 9781) * 9.73, 2), number_format(($i * 6007 % 8861) * 11.29, 2)]); @endphp
    <div class="ledger" aria-hidden="true"><div>
      @foreach ([1, 2] as $copy)@foreach ($lines as [$d, $c])<span>{{ $d }}</span><span>{{ $c }}</span>@endforeach @endforeach
    </div></div>
    <div class="pitch">
      {{-- The trial balance's scale: tips, swings and settles level, debit equal to credit. --}}
      <svg class="scale" viewBox="0 0 260 180" aria-hidden="true">
        <line class="post" x1="130" y1="36" x2="130" y2="160"/><rect class="foot" x="94" y="160" width="72" height="8" rx="4"/>
        <circle class="settle" cx="130" cy="36" r="11"/>
        <g class="beam">
          <line x1="40" y1="40" x2="220" y2="40"/><circle class="pivot" cx="130" cy="36" r="5"/>
          @foreach ([[40, 'debit', 'l'], [220, 'credit', 'r']] as [$x, $side, $cls])
            <g class="pan {{ $cls }}" style="transform-origin:{{ $x }}px 40px">
              <path class="strings" d="M{{ $x }} 40 L{{ $x - 24 }} 104 M{{ $x }} 40 L{{ $x + 24 }} 104"/>
              <ellipse class="coin" cx="{{ $x }}" cy="100" rx="13" ry="3.6"/><ellipse class="coin" cx="{{ $x }}" cy="95" rx="13" ry="3.6"/><ellipse class="coin" cx="{{ $x }}" cy="90" rx="13" ry="3.6"/>
              <path class="dish" d="M{{ $x - 30 }} 104 Q{{ $x }} 126 {{ $x + 30 }} 104 Z"/>
              <text x="{{ $x }}" y="146">{{ __("auth_pages.side.{$side}") }}</text>
            </g>
          @endforeach
        </g>
      </svg>
      <p class="eyebrow">{{ __('auth_pages.side.eyebrow') }}</p>
      <h2>{!! __('auth_pages.side.title') !!}</h2>
      <p class="sub">{{ __('auth_pages.side.sub') }}</p>
      <ul>
        @foreach (__('auth_pages.side.points') as $point)
          <li><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>{{ $point }}</li>
        @endforeach
      </ul>
    </div>
    <p class="foot">© {{ date('Y') }} {{ __('financial_reports.app') }} · {{ __('auth_pages.side.foot') }}</p>
  </aside>
</div>
</body>
</html>
