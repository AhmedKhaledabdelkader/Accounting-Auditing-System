@extends('layouts.app')
@section('title', __('follow_up.panel.review_title'))
@section('subtitle', $company->clientName())
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'requirements'])
  @php $mailbox = \App\Services\Portal\MailSender::canSend(auth()->user()); $ready = $to && $mailbox; @endphp
  <div class="msg warn"><b>{{ __('follow_up.panel.draft_not_sent') }}</b></div>
  @unless ($mailbox)<div class="msg bad">{{ __('follow_up.no_mailbox') }} <a class="btn small" href="{{ route('account.mail') }}">{{ __('mail_account.open') }}</a></div>@endunless
  <div class="panel">
    <h2>{{ __('follow_up.panel.review_title') }}</h2>
    <form method="POST" action="{{ route('clients.follow-up.send', $company) }}" data-busy>
      @csrf
      <input type="hidden" name="year" value="{{ $year }}">
      <div class="row"><button class="btn" type="submit" @disabled(! $ready)>{{ __('follow_up.panel.send') }}</button></div>
      <p class="note">{{ __('follow_up.panel.to') }}: <b dir="ltr">{{ $to ?: '—' }}</b></p>
      @unless ($to)<div class="msg bad">{{ __('follow_up.no_address') }} <a class="btn small" href="{{ route('clients.edit', $company) }}">{{ __('client_file.nav.steps.details') }}</a></div>@endunless
      <div class="field"><label for="fu-subject">{{ __('follow_up.panel.subject') }}</label>
        <input id="fu-subject" name="subject" value="{{ old('subject', $draft['subject']) }}" required maxlength="200"></div>
      <div class="field"><label for="fu-body">{{ __('follow_up.panel.body') }}</label>
        <textarea id="fu-body" name="body" rows="18" required maxlength="20000" style="width:100%;font:inherit">{{ old('body', $body) }}</textarea></div>
      <h3>{{ __('follow_up.panel.documents') }}</h3>
      <ul class="note">@foreach ($draft['items'] as $item)<li>{{ $item['title'] }}<input type="hidden" name="requests[]" value="{{ $item['id'] }}"></li>@endforeach</ul>
      <div class="row">
        <button class="btn" type="submit" @disabled(! $ready)>{{ __('follow_up.panel.send') }}</button>
        <a class="btn ghost" href="{{ route('clients.show', [$company, 'year' => $year]) }}">{{ __('follow_up.panel.cancel') }}</a>
      </div>
    </form>
  </div>
  @include('portal.file._close')
@endsection
