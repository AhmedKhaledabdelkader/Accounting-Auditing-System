@extends('layouts.app')
@section('title', __('client_file.zakat.title'))
@section('subtitle', __('client_file.zakat.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'zakat'])
  <div class="panel">
    <h2>{{ __('client_file.zakat.share') }}</h2>
    @if ($articles && $articles['zakat_share'] !== null)
      <div class="msg ok">{{ __('client_file.zakat.share_text', ['share' => $articles['zakat_share'], 'foreign' => round(100 - $articles['zakat_share'], 4)]) }}</div>
    @else
      <div class="msg warn">{{ __('client_file.zakat.share_unknown') }}</div>
    @endif
  </div>
  @if (! $selected)
    <div class="panel"><p class="note">{{ __('client_file.zakat.none') }}</p></div>
  @else
    @include('portal.file._report-picker')
    @include('portal.file._embed')
  @endif
  @include('portal.file._close')
@endsection