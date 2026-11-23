@extends('layouts.app')
@section('title', __("engagement.steps.{$active}"))
@section('subtitle', __('engagement.locked_sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => $active])
  <div class="panel" data-locked="{{ $active }}">
    <h3>🔒 {{ __('engagement.locked_title', ['step' => __("engagement.steps.{$active}")]) }}</h3>
    <p class="note">{{ __('engagement.locked_hint') }}</p>
    <ul>
      @foreach ($blockers as $b)
        <li data-blocker="{{ $b['code'] }}">{{ app()->getLocale() === 'ar' ? $b['message_ar'] : $b['message_en'] }} <a class="btn small" href="{{ $b['link'] }}">{{ __('engagement.go') }}</a></li>
      @endforeach
    </ul>
  </div>
  @include('portal.file._close')
@endsection
