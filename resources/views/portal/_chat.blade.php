{{-- The chat widget (public/js/portal/chat.js): the general thread, or one request's when a request's chat button is pressed. --}}
@php
  $config = [
    'messages' => $messagesUrl, 'send' => $sendUrl, 'start' => null, 'startTitle' => '',
    'ui' => [
      'general' => __('portal.chat.general'), 'general_hint' => $generalHint, 'request_hint' => __('portal.chat.request_hint'),
      'empty' => __('portal.chat.empty'), 'failed' => __('portal.chat.failed'),
    ],
  ];
@endphp
<section class="chat" id="chat" data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}" aria-label="{{ __('portal.chat.title') }}">
  <header>
    <div><b data-title>{{ __('portal.chat.general') }}</b><small data-sub></small></div>
  </header>
  <div class="chat-tabs">
    <button type="button" data-tab="general" class="on">{{ __('portal.chat.general') }}@if (($unread['general'] ?? 0) > 0)<span class="dot">{{ $unread['general'] }}</span>@endif</button>
    <button type="button" data-tab="request" hidden>{{ __('portal.chat.this_request') }}</button>
  </div>
  <div class="log" role="log" aria-live="polite"></div>
  <form>
    <textarea rows="1" maxlength="4000" placeholder="{{ __('portal.chat.placeholder') }}" aria-label="{{ __('portal.chat.placeholder') }}"></textarea>
    <button class="btn send" type="submit">{{ __('portal.chat.send') }}</button>
  </form>
</section>
@push('scripts')<script src="/js/portal/chat.js?v={{ @filemtime(public_path('js/portal/chat.js')) }}"></script>@endpush
