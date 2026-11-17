@extends('layouts.app')
@section('title', __('financial_reports.titles.error'))
@section('content')
  <div class="error-box">{{ $message }}</div>
  <p><a href="/financial-reports">{{ __('financial_reports.ui.back_to_list') }}</a></p>
@endsection
