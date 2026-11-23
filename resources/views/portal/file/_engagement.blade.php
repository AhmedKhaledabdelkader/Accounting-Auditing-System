{{-- The step bar of a work page: the step's status in the engagement file, what blocks it, its sign-offs and the reviewer's notes.
     All decided by App\Services\Engagement\Engagement; shown here only. $company, $year, $eng, $es (the step). --}}
@php
  $key = $es['step_key'];
  $blockers = array_values(array_filter($eng['blockers'], fn ($b) => $b['step_key'] === $key));
  usort($blockers, fn ($a, $b) => $a['soft'] <=> $b['soft']);
  $notes = \App\Models\ReviewNote::where('company_id', $company->id)->where('year', $year)->where('step_key', $key)->orderByRaw("case status when 'open' then 0 when 'answered' then 1 else 2 end")->orderByDesc('id')->limit(20)->get();
  $statusClass = ['not_started' => '', 'in_progress' => 'info', 'waiting_client' => 'warn', 'ready_for_review' => 'ok', 'approved' => 'ok'];
  $canManage = auth()->user()?->can('clients.manage');
  $msg = fn ($b) => app()->getLocale() === 'ar' ? $b['message_ar'] : $b['message_en'];
@endphp
@if ($key !== 'engagement')
<section class="eng-bar" data-engagement-bar="{{ $key }}" data-status="{{ $es['status'] }}">
  <div class="top">
    <b>{{ __('engagement.step_n', ['n' => $es['n']]) }} — {{ __("engagement.phases.{$es['phase']}") }}</b>
    <span class="pill {{ $statusClass[$es['status']] }}" data-step-status>{{ __("engagement.status.{$es['status']}") }}</span>
    @if ($eng['next_step'] && $eng['next_step'] !== $key)
      <a class="note" href="{{ route(config("engagement.steps.{$eng['next_step']}.route"), [$company, 'year' => $year]) }}">{{ __('engagement.next_step', ['step' => __("engagement.steps.{$eng['next_step']}")]) }}</a>
    @endif
    <a class="note" href="{{ route('clients.file.engagement', [$company, 'year' => $year]) }}">{{ __('engagement.board_link') }}</a>
  </div>
  @if ($blockers !== [])
    <ul data-blockers>
      @foreach ($blockers as $b)
        <li data-blocker="{{ $b['code'] }}" @class(['bad' => ! $b['soft']])><span class="pill {{ $b['soft'] ? 'warn' : 'bad' }}">{{ __($b['soft'] ? 'engagement.soft' : 'engagement.hard') }}</span>
          {{ $msg($b) }} <a href="{{ $b['link'] }}">←</a></li>
      @endforeach
    </ul>
  @endif
  <div class="signs" data-signoffs>
    @foreach (['prepared', 'reviewed'] + ($key === 'release' ? [2 => 'approved', 3 => 'eqr'] : []) as $role)
      @php $sg = $es['signoffs'][$role] ?? null; @endphp
      <span class="pill {{ $sg ? ($sg['valid'] ? 'ok' : 'warn') : '' }}" data-sign="{{ $role }}">{{ __("engagement.roles.{$role}") }}:
        @if ($sg) {{ $sg['name'] }} · {{ \Illuminate\Support\Str::limit($sg['at'], 10, '') }}@if (! $sg['valid']) — {{ __('engagement.sign_void') }}@endif @else — @endif</span>
      @if ($canManage)
        <form method="POST" action="{{ route('clients.engagement.sign', [$company, $key]) }}">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="role" value="{{ $role }}">
          @if ($sg && $sg['valid'])<input type="hidden" name="off" value="1"><button class="btn small ghost" type="submit">{{ __('engagement.unsign') }}</button>
          @else<button class="btn small" type="submit">{{ __('engagement.sign_as', ['role' => __("engagement.roles.{$role}")]) }}</button>@endif
        </form>
      @endif
    @endforeach
  </div>
  @error('sign')<div class="msg bad" data-sign-error>{{ $message }}</div>@enderror
  <details @if ($notes->where('status', '!=', 'closed')->isNotEmpty()) open @endif data-review-notes>
    <summary>{{ __('engagement.notes.title') }} ({{ $notes->where('status', '!=', 'closed')->count() }})</summary>
    @foreach ($notes as $n)
      <div class="note-return" data-note="{{ $n->id }}" data-note-status="{{ $n->status }}" style="margin:6px 0">
        <span class="pill {{ $n->status === 'open' ? 'bad' : ($n->status === 'answered' ? 'warn' : 'ok') }}">{{ __("engagement.notes.status.{$n->status}") }}</span>
        {{ $n->text }} <small>— {{ $n->raiser?->name }} · {{ $n->created_at?->toDateString() }}</small>
        @if ($n->response)<div><b>{{ __('engagement.notes.response') }}:</b> {{ $n->response }}</div>@endif
        @if ($canManage && $n->status !== 'closed')
          <form method="POST" action="{{ route('clients.notes.act', [$company, $n->id, 'answer']) }}" class="row">@csrf
            <input name="response" maxlength="3000" required placeholder="{{ __('engagement.notes.response_ph') }}" aria-label="{{ __('engagement.notes.response') }}">
            <button class="btn small" type="submit">{{ __('engagement.notes.answer') }}</button></form>
          <form method="POST" action="{{ route('clients.notes.act', [$company, $n->id, 'close']) }}">@csrf<button class="btn small ok" type="submit">{{ __('engagement.notes.close') }}</button></form>
        @elseif ($canManage)
          <form method="POST" action="{{ route('clients.notes.act', [$company, $n->id, 'reopen']) }}">@csrf<button class="btn small ghost" type="submit">{{ __('engagement.notes.reopen') }}</button></form>
        @endif
      </div>
    @endforeach
    @if ($canManage)
      <form method="POST" action="{{ route('clients.notes.store', [$company, $key]) }}" class="row" style="align-items:end">@csrf
        <input type="hidden" name="year" value="{{ $year }}">
        <div class="field" style="flex:1 1 320px"><textarea name="text" rows="2" maxlength="3000" required placeholder="{{ __('engagement.notes.text_ph') }}" aria-label="{{ __('engagement.notes.title') }}"></textarea></div>
        <button class="btn small" type="submit">{{ __('engagement.notes.raise') }}</button>
      </form>
    @endif
  </details>
</section>
@endif
