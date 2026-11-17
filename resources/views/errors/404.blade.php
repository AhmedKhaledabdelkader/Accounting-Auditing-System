@extends('layouts.app')
@section('title', __('auth_pages.not_found.title'))
@section('subtitle', __('auth_pages.not_found.sub'))
@section('content')
  <a class="btn" href="/">{{ __('auth_pages.not_found.home') }}</a>
@endsection
