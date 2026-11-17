<link rel="icon" type="image/png" href="/img/favicon.png">
{{-- Font and shared look of every page (public/css/theme.css). --}}
<script>
// Dark or light: the reader's choice (localStorage 'theme'), else the device's; set before the first paint.
(() => {
  const root = document.documentElement, media = matchMedia('(prefers-color-scheme: dark)');
  const saved = () => { try { return localStorage.getItem('theme'); } catch (e) { return null; } };
  const apply = t => { root.dataset.theme = t; };
  apply(saved() || (media.matches ? 'dark' : 'light'));
  media.addEventListener('change', e => { if (!saved()) apply(e.matches ? 'dark' : 'light'); });
  // The new look fades in over the old (View Transitions); at once without them or with «reduce motion».
  window.toggleTheme = () => {
    const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('theme', next); } catch (e) {}
    if (!document.startViewTransition || matchMedia('(prefers-reduced-motion: reduce)').matches) return apply(next);
    document.startViewTransition(() => apply(next));
  };
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/theme.css?v={{ @filemtime(public_path('css/theme.css')) }}">
{{-- How an amount is written (App\Support\Amounts, R1): the screens follow the files. --}}
<script>window.AmountsConfig = {!! json_encode(['negatives' => \App\Support\Amounts::negatives()]) !!};</script>
<script src="/js/http.js?v={{ @filemtime(public_path('js/http.js')) }}"></script>
{{-- The loader with a percentage for everything that takes time: uploads, files being read, downloads being made (public/js/busy.js). --}}
<script>window.BusyConfig = {!! json_encode(['words' => __('busy')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};</script>
<script src="/js/busy.js?v={{ @filemtime(public_path('js/busy.js')) }}"></script>
{{-- The user's own column order for the tables (public/js/table-columns.js), saved on the account. --}}
@php
  $tableColumns = ['prefs' => (object) (auth()->user()?->preferences ?? []), 'words' => __('auth_pages.columns'),
    'url' => auth()->check() ? route('account.preferences', [], false) : null];
@endphp
<script>window.TableColumnsConfig = {!! json_encode($tableColumns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};</script>
<script src="/js/table-columns.js?v={{ @filemtime(public_path('js/table-columns.js')) }}" defer></script>
{{-- Every dropdown can be searched (public/js/select-search.js). --}}
<script>window.SelectSearchConfig = {!! json_encode(['words' => __('auth_pages.select_search')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};</script>
<script src="/js/select-search.js?v={{ @filemtime(public_path('js/select-search.js')) }}" defer></script>
