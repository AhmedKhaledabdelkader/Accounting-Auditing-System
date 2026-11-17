@extends('layouts.app')
@section('title', __('client_file.articles.title'))
@section('subtitle', __('client_file.articles.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'articles'])
  <div class="panel">
    <h2>{{ __('client_file.articles.partners') }}</h2>
    @if ($company->articlesRequired() === false)
      <div class="msg ok" style="margin-bottom:12px">{{ __('client_file.articles.not_needed') }}</div>
    @elseif ($company->articlesRequired() === null && ! ($articles && $articles['partners']))
      <div class="msg warn" style="margin-bottom:12px">{{ __('client_file.articles.optional') }}</div>
    @endif
    @if ($articles && $articles['partners'])
      <table>
        <tr>@foreach (['name', 'nationality', 'group', 'shares', 'ownership'] as $k)<th>{{ __("client_file.articles.{$k}") }}</th>@endforeach</tr>
        @foreach ($articles['partners'] as $p)
          <tr><td>{{ $p['name'] }}</td><td>{{ $p['nationality'] ?? '—' }}</td><td>{{ __('client_file.articles.groups.'.$p['group']) }}</td>
            <td class="num">{{ $p['shares'] === null ? '—' : number_format($p['shares']) }}</td><td class="num">{{ $p['ownership'] === null ? '—' : rtrim(rtrim(number_format($p['ownership'], 2), '0'), '.').'%' }}</td></tr>
        @endforeach
      </table>
      <dl class="facts" style="margin-top:12px">
        <dt>{{ __('client_file.articles.capital') }}</dt><dd>{{ $articles['capital'] === null ? '—' : number_format($articles['capital'], 2) }}</dd>
        <dt>{{ __('client_file.articles.shares_total') }}</dt><dd>{{ $articles['shares_total'] === null ? '—' : number_format($articles['shares_total']) }}</dd>
        <dt>{{ __('client_file.articles.share_value') }}</dt><dd>{{ $articles['share_value'] === null ? '—' : number_format($articles['share_value'], 2) }}</dd>
        <dt>{{ __('client_file.articles.file') }}</dt><dd><a href="{{ route('clients.documents', [$company, 'articles']) }}">{{ $file['name'] }}</a></dd>
        <dt>{{ __('client_file.articles.read_at') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($file['read_at'])->diffForHumans() }}</dd>
      </dl>
      <div class="msg {{ $articles['zakat_share'] === null ? 'warn' : 'ok' }}" style="margin-top:12px">
        @if ($articles['zakat_share'] === null){{ __('client_file.articles.zakat_share_unknown') }}
        @else<b>{{ __('client_file.articles.zakat_share') }}:</b> {{ __('client_file.articles.zakat_share_text', ['share' => $articles['zakat_share'], 'foreign' => round(100 - $articles['zakat_share'], 4)]) }}@endif
      </div>
    @else
      <p class="note">{{ __('client_file.articles.none') }}</p>
    @endif
  </div>
  @can('clients.manage')
    <div class="panel">
      <h2>{{ __('client_file.articles.replace') }}</h2>
      <form method="POST" action="{{ route('clients.file.articles.update', $company) }}" enctype="multipart/form-data" class="row" style="align-items:end" data-reading>
        @csrf
        <div class="field"><input name="articles_file" type="file" accept=".pdf" required aria-label="{{ __('client_file.articles.replace') }}"><span class="note">{{ __('client_file.articles.replace_hint') }}</span></div>
        <div class="field"><button class="btn" type="submit">{{ __('client_file.articles.button') }}</button></div>
      </form>
    </div>
  @endcan
  @include('portal.file._close')
@endsection
@push('scripts')<script>document.querySelectorAll('[data-reading]').forEach((f) => f.addEventListener('submit', () => { if (f.checkValidity()) f.querySelector('button').disabled = true; }));</script>@endpush