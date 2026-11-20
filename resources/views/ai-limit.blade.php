@extends('layouts.app')
@section('title', __('auth_pages.ai_limit.title'))
@section('subtitle', __('auth_pages.ai_limit.sub'))
@push('styles')
<style>
  .ail{--ail-hot:var(--warn);--ail-cool:var(--brand);max-width:1040px;margin:0 auto}
  .ail.spent{--ail-hot:var(--bad)}
  /* The hero: the month's tank, drained */
  .ail-hero{position:relative;overflow:hidden;display:grid;grid-template-columns:240px 1fr;gap:34px;align-items:center;padding:38px 40px;border-radius:26px;
    background:radial-gradient(120% 140% at 0% 0%,color-mix(in srgb,var(--ail-hot) 16%,var(--panel)) 0%,var(--panel) 55%),var(--panel);
    border:1px solid color-mix(in srgb,var(--ail-hot) 28%,var(--line));box-shadow:0 22px 60px -30px color-mix(in srgb,var(--ail-hot) 55%,transparent)}
  .ail-hero::after{content:'';position:absolute;inset-inline-end:-80px;top:-80px;width:260px;height:260px;border-radius:50%;
    background:radial-gradient(circle,color-mix(in srgb,var(--ail-cool) 18%,transparent),transparent 70%);pointer-events:none}
  .ail-dial{position:relative;width:240px;height:240px}
  .ail-dial svg{width:100%;height:100%;transform:rotate(135deg)}
  .ail-dial .track{fill:none;stroke:var(--line);stroke-width:14;stroke-linecap:round}
  .ail-dial .fill{fill:none;stroke:url(#ailGrad);stroke-width:14;stroke-linecap:round;transition:stroke-dashoffset 1.6s cubic-bezier(.2,.8,.2,1)}
  .ail-dial .tick{stroke:var(--muted);stroke-width:2;opacity:.35}
  .ail-core{position:absolute;inset:0;display:grid;place-items:center;text-align:center}
  .ail-core .spark{width:62px;height:62px;margin:0 auto 6px;display:block}
  .ail-core .spark path{fill:none;stroke:var(--ail-hot);stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
  .ail.spent .ail-core .spark{animation:ail-flicker 3.2s ease-in-out infinite}
  .ail-core b{display:block;font-size:30px;font-weight:800;font-variant-numeric:tabular-nums;letter-spacing:-.5px}
  .ail-core small{color:var(--muted);font-size:12.5px}
  .ail-zz{position:absolute;inset-inline-end:34px;top:38px;font-weight:800;color:var(--ail-hot);opacity:0}
  .ail.spent .ail-zz{animation:ail-zz 3.6s ease-in-out infinite}
  .ail.spent .ail-zz:nth-of-type(2){animation-delay:1.2s;inset-inline-end:18px;top:20px;font-size:13px}
  .ail-copy h1{font-size:30px;line-height:1.35;margin:0 0 10px}
  .ail-copy p{color:var(--muted);font-size:15.5px;line-height:1.9;margin:0}
  .ail-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:99px;margin-bottom:14px;font-size:13px;font-weight:700;
    background:color-mix(in srgb,var(--ail-hot) 14%,transparent);color:var(--ail-hot)}
  .ail-pill i{width:8px;height:8px;border-radius:50%;background:currentColor;box-shadow:0 0 0 0 currentColor;animation:ail-pulse 2s infinite}
  /* The numbers */
  .ail-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:18px 0}
  .ail-stat{padding:18px 20px;border-radius:18px;background:var(--panel);border:1px solid var(--line)}
  .ail-stat small{display:block;color:var(--muted);font-size:12.5px;margin-bottom:6px}
  .ail-stat b{font-size:24px;font-weight:800;font-variant-numeric:tabular-nums}
  .ail-count{display:flex;gap:8px;margin-top:4px}
  .ail-count span{min-width:54px;padding:8px 6px;border-radius:12px;background:var(--panel2);text-align:center}
  .ail-count b{display:block;font-size:20px}
  .ail-count small{margin:0;font-size:11px}
  /* What it went on, what waits, what still works */
  .ail-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:14px}
  .ail-card{padding:22px 24px;border-radius:20px;background:var(--panel);border:1px solid var(--line)}
  .ail-card h3{margin:0 0 14px;font-size:16px}
  .ail-bar{margin:12px 0}
  .ail-bar .row{display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:6px}
  .ail-bar .row span{color:var(--muted);font-variant-numeric:tabular-nums}
  .ail-bar .line{height:8px;border-radius:99px;background:var(--line2);overflow:hidden}
  .ail-bar .line i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--ail-cool),var(--ail-hot));width:0;transition:width 1.2s cubic-bezier(.2,.8,.2,1)}
  .ail-list{list-style:none;padding:0;margin:0;display:grid;gap:10px}
  .ail-list li{display:flex;gap:10px;align-items:flex-start;font-size:14px;line-height:1.6}
  .ail-list li svg{flex:none;width:20px;height:20px;margin-top:2px;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .ail-list.paused li svg{stroke:var(--ail-hot)} .ail-list.works li svg{stroke:var(--ok)}
  .ail-two{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  .ail-two h4{margin:0 0 10px;font-size:13.5px;color:var(--muted)}
  /* Who can help */
  .ail-help{display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;margin-top:14px;padding:20px 24px;border-radius:20px;
    background:linear-gradient(135deg,color-mix(in srgb,var(--ail-cool) 12%,var(--panel)),var(--panel));border:1px solid var(--line)}
  .ail-help .people{display:flex;gap:10px;flex-wrap:wrap}
  .ail-help .person{display:flex;align-items:center;gap:8px;padding:6px 12px 6px 6px;border-radius:99px;background:var(--panel);border:1px solid var(--line);font-size:13.5px}
  .ail-help .person .avatar{width:28px;height:28px;font-size:12px}
  .ail-help .acts{display:flex;gap:10px}
  @keyframes ail-pulse{0%{box-shadow:0 0 0 0 color-mix(in srgb,currentColor 55%,transparent)}70%{box-shadow:0 0 0 9px transparent}100%{box-shadow:0 0 0 0 transparent}}
  @keyframes ail-flicker{0%,100%{opacity:.35;transform:scale(.96)}8%{opacity:1}12%{opacity:.4}18%{opacity:.9;transform:scale(1)}40%{opacity:.45}}
  @keyframes ail-zz{0%{opacity:0;transform:translateY(8px) scale(.8)}30%{opacity:.9}100%{opacity:0;transform:translateY(-26px) scale(1.15)}}
  @keyframes ail-rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
  .ail > *{animation:ail-rise .55s cubic-bezier(.2,.7,.2,1) both}
  .ail > *:nth-child(2){animation-delay:.08s} .ail > *:nth-child(3){animation-delay:.16s} .ail > *:nth-child(4){animation-delay:.24s}
  @media (max-width:860px){.ail-hero{grid-template-columns:1fr;justify-items:center;text-align:center;padding:28px 20px}.ail-stats,.ail-grid,.ail-two{grid-template-columns:1fr}.ail-copy h1{font-size:24px}}
  @media (prefers-reduced-motion:reduce){.ail *,.ail > *{animation:none!important;transition:none!important}}
</style>
@endpush
@section('content')
  @php
    $pct = $limit ? min(100, round($spent / max($limit, 0.0001) * 100)) : 0;
    $arc = 2 * M_PI * 100 * 0.75;   // a 270° dial
    $left = $limit === null ? null : max(0, $limit - $spent);
    $kinds = __('auth_pages.ai_limit.kinds');
    $top = $byKind ? max(array_column($byKind, 'usd')) : 0;
  @endphp
  <div @class(['ail', 'spent' => $used_up]) data-ai-limit="{{ $used_up ? 'used-up' : 'ok' }}">
    <section class="ail-hero">
      <div class="ail-dial" role="img" aria-label="{{ $pct }}%">
        <svg viewBox="0 0 240 240" aria-hidden="true">
          <defs><linearGradient id="ailGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--ail-cool)"/><stop offset="1" stop-color="var(--ail-hot)"/></linearGradient></defs>
          @for ($t = 0; $t <= 10; $t++)
            @php $a = deg2rad($t * 27); @endphp
            <line class="tick" x1="{{ 120 + 112 * cos($a) }}" y1="{{ 120 + 112 * sin($a) }}" x2="{{ 120 + 118 * cos($a) }}" y2="{{ 120 + 118 * sin($a) }}"/>
          @endfor
          <circle class="track" cx="120" cy="120" r="100" stroke-dasharray="{{ $arc }} 1000"/>
          <circle class="fill" cx="120" cy="120" r="100" stroke-dasharray="{{ $arc }} 1000" stroke-dashoffset="{{ $arc }}" data-offset="{{ $arc * (1 - $pct / 100) }}"/>
        </svg>
        <div class="ail-core"><div>
          <svg class="spark" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v3M12 18v3M4.2 7.5l2.6 1.5M17.2 15l2.6 1.5M4.2 16.5 6.8 15M17.2 9l2.6-1.5"/><path d="M12 8.5 13.6 12 12 15.5 10.4 12Z"/></svg>
          <b>{{ $pct }}%</b><small>{{ __('auth_pages.ai_limit.used_of_month') }}</small>
        </div></div>
        <span class="ail-zz">z</span><span class="ail-zz">z</span>
      </div>
      <div class="ail-copy">
        <span class="ail-pill"><i></i>{{ $used_up ? __('auth_pages.ai_limit.pill_spent') : ($limit === null ? __('auth_pages.ai_limit.pill_free') : __('auth_pages.ai_limit.pill_ok')) }}</span>
        <h1>{{ $used_up ? __('auth_pages.ai_limit.headline_spent', ['name' => $user->name]) : __('auth_pages.ai_limit.headline_ok', ['name' => $user->name]) }}</h1>
        <p>{{ $used_up ? __('auth_pages.ai_limit.lead_spent') : __('auth_pages.ai_limit.lead_ok') }}</p>
      </div>
    </section>

    <section class="ail-stats">
      <div class="ail-stat"><small>{{ __('auth_pages.ai_limit.limit') }}</small><b dir="ltr">{{ $limit === null ? '∞' : '$'.number_format($limit, 2) }}</b></div>
      <div class="ail-stat"><small>{{ __('auth_pages.ai_limit.spent') }}</small><b dir="ltr">${{ number_format($spent, 2) }}</b>
        @if ($left !== null)<small style="margin-top:6px">{{ __('auth_pages.ai_limit.left', ['left' => number_format($left, 2)]) }}</small>@endif</div>
      <div class="ail-stat"><small>{{ __('auth_pages.ai_limit.renews', ['date' => App\Services\Portal\Workspace::dateText($renews)]) }}</small>
        <div class="ail-count" data-renews="{{ $renews->toIso8601String() }}">
          <span><b data-d>–</b><small>{{ __('auth_pages.ai_limit.days') }}</small></span>
          <span><b data-h>–</b><small>{{ __('auth_pages.ai_limit.hours') }}</small></span>
          <span><b data-m>–</b><small>{{ __('auth_pages.ai_limit.minutes') }}</small></span>
        </div></div>
    </section>

    <section class="ail-grid">
      <div class="ail-card">
        <h3>{{ __('auth_pages.ai_limit.where_title') }}</h3>
        @forelse ($byKind as $k => $v)
          <div class="ail-bar">
            <div class="row"><b>{{ $kinds[$k] ?? $kinds['other'] }}</b><span dir="ltr">${{ number_format($v['usd'], 2) }} · {{ __('auth_pages.ai_limit.calls', ['count' => $v['calls']]) }}</span></div>
            <div class="line"><i data-width="{{ $top > 0 ? round($v['usd'] / $top * 100) : 0 }}"></i></div>
          </div>
        @empty
          <p class="note">{{ __('auth_pages.ai_limit.nothing_yet') }}</p>
        @endforelse
      </div>
      <div class="ail-card">
        <div class="ail-two">
          <div><h4>{{ __('auth_pages.ai_limit.paused_title') }}</h4>
            <ul class="ail-list paused">@foreach (__('auth_pages.ai_limit.paused') as $line)<li><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/></svg>{{ $line }}</li>@endforeach</ul></div>
          <div><h4>{{ __('auth_pages.ai_limit.works_title') }}</h4>
            <ul class="ail-list works">@foreach (__('auth_pages.ai_limit.works') as $line)<li><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>{{ $line }}</li>@endforeach</ul></div>
        </div>
      </div>
    </section>

    <section class="ail-help">
      <div>
        <b>{{ __('auth_pages.ai_limit.help_title') }}</b>
        <div class="note" style="margin:4px 0 10px">{{ __('auth_pages.ai_limit.help_text') }}</div>
        <div class="people">
          @forelse ($admins as $a)
            <span class="person"><span class="avatar sm">{{ mb_substr($a->name, 0, 1) }}</span>{{ $a->name }}</span>
          @empty
            <span class="note">{{ __('auth_pages.ai_limit.no_admin') }}</span>
          @endforelse
        </div>
      </div>
      <div class="acts">
        <a class="btn ghost" href="javascript:history.length > 1 ? history.back() : location.assign('/')">{{ __('auth_pages.ai_limit.back') }}</a>
        <a class="btn" href="/">{{ __('auth_pages.ai_limit.home') }}</a>
      </div>
    </section>
  </div>
@endsection
@push('scripts')
<script>
  // The dial and the bars fill in once the page is shown; the countdown to the renewal ticks every minute.
  (() => {
    requestAnimationFrame(() => setTimeout(() => {
      document.querySelectorAll('.ail-dial .fill').forEach((c) => { c.style.strokeDashoffset = c.dataset.offset; });
      document.querySelectorAll('.ail-bar .line i').forEach((i) => { i.style.width = i.dataset.width + '%'; });
    }, 120));
    const box = document.querySelector('.ail-count');
    if (!box) return;
    const end = new Date(box.dataset.renews).getTime();
    const digits = (n) => (document.documentElement.lang === 'ar' ? String(n).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]) : String(n));
    const tick = () => {
      const s = Math.max(0, Math.floor((end - Date.now()) / 1000));
      box.querySelector('[data-d]').textContent = digits(Math.floor(s / 86400));
      box.querySelector('[data-h]').textContent = digits(Math.floor((s % 86400) / 3600));
      box.querySelector('[data-m]').textContent = digits(Math.floor((s % 3600) / 60));
    };
    tick();
    setInterval(tick, 30000);
  })();
</script>
@endpush
