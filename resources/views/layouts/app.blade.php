<!DOCTYPE html>
{{-- Shared layout of the financial-report pages (PR 13): head, styles, the page frame and the page's config for its scripts. --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') — {{ __('financial_reports.app') }}</title>
@include('partials.theme-head')
<link rel="stylesheet" href="/css/app.css?v={{ @filemtime(public_path('css/app.css')) }}">
@stack('styles')
</head>
<body>
{{-- The sections are already escaped: HtmlString keeps the frame from escaping them twice. --}}
<x-shell :title="new Illuminate\Support\HtmlString($__env->yieldContent('title'))" :subtitle="$__env->hasSection('subtitle') ? new Illuminate\Support\HtmlString($__env->yieldContent('subtitle')) : null">
  @yield('content')
</x-shell>
@isset($config)
<script>window.PageConfig = @json($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);</script>
@endisset
@stack('scripts')
</body>
</html>
