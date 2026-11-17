@extends('layouts.app')
@section('title', __('financial_reports.titles.list'))
@section('subtitle', __('financial_reports.subtitles.list'))
@section('content')
  <div id="app"><div class="loading">{{ __('financial_reports.ui.loading') }}</div></div>
@endsection
@push('scripts')
<script type="module" src="/js/financial-report/list.js?v={{ @filemtime(public_path('js/financial-report/list.js')) }}"></script>
@endpush
