@extends('layouts.app')
@section('title', __('financial_reports.titles.synonyms'))
@section('subtitle', __('financial_reports.subtitles.synonyms'))
@section('content')
  <div id="app"><div class="loading">{{ __('financial_reports.ui.loading') }}</div></div>
@endsection
@push('scripts')
<script type="module" src="/js/financial-report/synonyms.js?v={{ @filemtime(public_path('js/financial-report/synonyms.js')) }}"></script>
@endpush
