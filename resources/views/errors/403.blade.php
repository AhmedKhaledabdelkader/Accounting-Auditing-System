@extends('layouts.app')
@section('title', __('auth_pages.forbidden.title'))
@section('subtitle', __('auth_pages.forbidden.sub'))
@section('content')
  <a class="btn" href="/">{{ __('auth_pages.forbidden.home') }}</a>
@endsection
