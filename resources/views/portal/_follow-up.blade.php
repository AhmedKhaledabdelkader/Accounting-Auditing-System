{{-- The email follow-up on the requirements page: the AI's draft of what is missing, what was sent and what came back. $company, $year, $emails. --}}
@php
  $missing = app(\App\Services\Portal\FollowUp::class)->gaps($company, $year)->count();
  $outs = $emails->where('direction', 'out')->whereNull('parent_id');
@endphp
<div class="panel" data-follow-up>
  <h2>{{ __('follow_up.panel.title') }}</h2>
  <p class="note">{{ __('follow_up.panel.hint') }}</p>
  @unless (\App\Services\Portal\MailSender::canSend(auth()->user()))<div class="msg warn">{{ __('follow_up.no_mailbox') }} <a href="{{ route('account.mail') }}">{{ __('mail_account.open') }}</a></div>@endunless
  @error('follow_up')<div class="msg bad">{{ $message }}</div>@enderror
  <form method="POST" action="{{ route('clients.follow-up.draft', $company) }}" class="row" data-busy data-busy-title="{{ __('follow_up.panel.draft') }}">
    @csrf <input type="hidden" name="year" value="{{ $year }}">
    <span class="pill {{ $missing ? 'warn' : 'ok' }}">{{ __('follow_up.panel.missing', ['count' => $missing]) }}</span>
    <button class="btn" type="submit" @disabled(! $missing)>{{ __('follow_up.panel.draft') }}</button>
  </form>

  @if ($emails->isNotEmpty())
    <h3>{{ __('follow_up.panel.sent_list') }}</h3>
    <table>
      @foreach ($emails as $e)
        <tr>
          <td style="white-space:nowrap"><span class="pill {{ $e->isOut() ? ($e->status === 'failed' ? 'bad' : 'info') : ($e->status === 'unmatched' ? 'bad' : 'ok') }}">
            {{ __('follow_up.panel.'.($e->isOut() ? ($e->status === 'failed' ? 'failed' : 'out') : ($e->status === 'unmatched' ? 'unmatched' : 'in'))) }}</span>
            <div class="note">{{ $e->created_at?->diffForHumans() }}</div></td>
          <td><b>{{ $e->subject }}</b>
            @if ($e->isOut())<div class="note">{{ $e->address }}@if ($e->reminders) · {{ __('follow_up.panel.reminders', ['count' => $e->reminders]) }}@endif</div>
            @else
              <div class="note">{{ $e->analysis['summary'] ?? (\Illuminate\Support\Str::limit($e->body, 160) ?: '—') }}</div>
              @foreach ($e->analysis['files'] ?? [] as $f)<div class="note">📎 {{ $f['name'] }} → <b>{{ $f['title'] ?? __('follow_up.unsorted') }}</b></div>@endforeach
              @if (! empty($e->analysis['needs_accountant']))<span class="pill warn">{{ __('follow_up.panel.needs_you') }}</span>@endif
            @endif
          </td>
        </tr>
      @endforeach
    </table>
  @endif

  <details class="paper-help"><summary>{{ __('follow_up.panel.enter_reply') }}</summary>
    <p class="note">{{ __('follow_up.panel.enter_reply_hint') }}</p>
    <form method="POST" action="{{ route('clients.follow-up.reply', $company) }}" enctype="multipart/form-data" data-busy>
      @csrf <input type="hidden" name="year" value="{{ $year }}">
      <div class="field"><label for="fu-reply">{{ __('follow_up.panel.reply_text') }}</label><textarea id="fu-reply" name="text" rows="5" required style="width:100%;font:inherit"></textarea></div>
      <div class="field"><label for="fu-files">{{ __('follow_up.panel.reply_files') }}</label><input id="fu-files" name="files[]" type="file" multiple></div>
      <button class="btn small" type="submit">{{ __('follow_up.panel.reply_send') }}</button>
    </form>
  </details>
</div>
