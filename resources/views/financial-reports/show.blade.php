@extends('layouts.app')
@section('title', __('financial_reports.titles.wizard'))
@section('subtitle', __('financial_reports.subtitles.wizard'))
@section('content')
  <div id="app"><div class="loading">{{ __('financial_reports.ui.loading') }}</div></div>
@endsection
@push('scripts')
<script type="module" src="/js/financial-report/wizard.js?v={{ @filemtime(public_path('js/financial-report/wizard.js')) }}"></script>
@endpush
