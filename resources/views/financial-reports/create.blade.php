@extends('layouts.app')
@section('title', __('financial_reports.titles.new'))
@section('subtitle', __('financial_reports.subtitles.new'))
@section('content')
  <div id="app"><div class="loading">{{ __('financial_reports.ui.loading') }}</div></div>
@endsection
@push('scripts')
<script type="module" src="/js/financial-report/new.js?v={{ @filemtime(public_path('js/financial-report/new.js')) }}"></script>
@endpush
