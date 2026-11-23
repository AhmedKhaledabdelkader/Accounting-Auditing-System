{{-- The checklist grouped by section, for the office ($mode 'office': review, delete) or the client ($mode 'client': upload, «doesn't apply»). --}}
@php
  $office = $mode === 'office';
  $manage = $office && $canManage;
  $open = (array) session('open_request', []);
  // What needs this side: the accountant reviews uploads; the client has documents to send or to correct.
  $needs = $office ? [App\Models\DocumentRequest::UPLOADED] : App\Models\DocumentRequest::OPEN;
  $statusClass = ['pending' => 'warn', 'uploaded' => 'info', 'returned' => 'bad', 'accepted' => 'ok', 'not_applicable' => ''];
@endphp
<div class="filters" data-filters>
  <button type="button" class="on" data-filter="all">{{ __('portal.filter.all') }}</button>
  <button type="button" data-filter="needs">{{ __($office ? 'portal.filter.office_needs' : 'portal.filter.client_needs') }}</button>
  <button type="button" data-filter="waiting">{{ __($office ? 'portal.filter.office_waiting' : 'portal.filter.client_waiting') }}</button>
  <button type="button" data-filter="done">{{ __('portal.filter.done') }}</button>
</div>

@forelse ($sections as $section => $items)
  @php
    $done = $items->whereIn('status', [App\Models\DocumentRequest::ACCEPTED, App\Models\DocumentRequest::NOT_APPLICABLE])->count();
    $attention = $items->whereIn('status', $needs)->count();
    $isOpen = $attention > 0 || $items->pluck('id')->intersect($open)->isNotEmpty();
  @endphp
  <details class="section" data-section @if ($isOpen) open @endif>
    <summary>
      <span>{{ $section }}</span>
      @if ($attention)<span class="pill warn">{{ __($office ? 'portal.section.to_review' : 'portal.section.to_send', ['count' => $attention]) }}</span>@endif
      <span class="count">{{ $done }}/{{ $items->count() }}</span>
      <span class="mini" aria-hidden="true"><i style="width:{{ round($done * 100 / max(1, $items->count())) }}%"></i></span>
      <svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
    </summary>
    @foreach ($items as $r)
      @php
        $group = in_array($r->status, $needs, true) ? 'needs' : (in_array($r->status, [App\Models\DocumentRequest::ACCEPTED, App\Models\DocumentRequest::NOT_APPLICABLE], true) ? 'done' : 'waiting');
      @endphp
      <div class="req" id="req-{{ $r->id }}" data-group="{{ $group }}">
        <div class="no">{{ $r->number ?? '·' }}</div>
        <div>
          <p class="title">{{ $r->title }}</p>
          <div class="tags">
            <span class="pill {{ $statusClass[$r->status] }}">{{ __("portal.status.{$r->status}") }}</span>
            @if ($r->format)<span class="pill">{{ __("portal.format.{$r->format}") }}</span>@endif
            @if ($r->optional)<span class="pill">{{ __('portal.optional') }}</span>@endif
            {{-- The audit program's areas the document is evidence for (step 14): for the office only. --}}
            @if ($office)
              @foreach (App\Services\Audit\AuditProcedures::areasOf($r) as $pa)
                <a class="pill info" data-procedure-area="{{ $pa['key'] }}" href="{{ route('clients.file.procedures', [$company, 'year' => $r->year, 'area' => $pa['key']]) }}#area-{{ $pa['key'] }}" title="{{ __('audit_plan.procedures.title') }}">{{ $pa['code'] }} {{ $pa['title'] }}</a>
              @endforeach
            @endif
          </div>
          @if ($r->status === App\Models\DocumentRequest::RETURNED && $r->note)
            <div class="note-return"><b>{{ __('portal.returned_note') }}</b>{{ $r->note }}</div>
          @endif
          @if ($r->instructions)
            <details class="paper-help"><summary>{{ __('portal.paper.instructions_title') }}</summary><p style="white-space:pre-line;margin:6px 0">{{ $r->instructions }}</p></details>
          @endif
          @if ($r->analysis)
            <div class="note-return" style="border-color:var(--line,#ccc);background:transparent"><b>{{ __('portal.paper.analysis_title') }}: </b>{{ App\Services\WorkingPapers\Papers::summary($r->analysis) }}</div>
          @endif
          @if ($r->files->isNotEmpty())
            <div class="files">
              @foreach ($r->files as $f)
                @php $mine = $f->from_client === ! $office; @endphp
                <div class="file">
                  <a href="{{ $office ? route('clients.files.download', [$company, $f]) : route('portal.files.download', $f) }}">{{ $f->original_name }}</a>
                  <span class="by {{ $mine ? 'me' : '' }}">{{ __($f->from_client ? 'portal.by_client' : 'portal.by_office') }}</span>
                  <small>{{ App\Services\Portal\Workspace::fileSize($f->size) }}</small>
                  @if ($f->review)
                    @php
                      $rv = $f->review;
                      $rs = $rv['status'] ?? 'unavailable';
                      $read = $rv['read'] ?? null;
                    @endphp
                    <details class="paper-help" style="display:inline-block;vertical-align:top">
                      <summary><span class="pill {{ $rs === 'ok' ? 'ok' : ($rs === 'unavailable' ? '' : 'warn') }}">{{ __('portal.ai.reviewer') }}: {{ __('portal.ai.status.'.$rs) }}</span></summary>
                      <div style="margin:6px 0">
                        @if ($read && $read['document_type'] !== '')<div>{{ __('portal.ai.is', ['type' => $read['document_type']]) }} {{ $read['summary'] }}</div>@endif
                        @if ($read && ($read['company_name'] || $read['registration_number'] || $read['period_year'] || $read['expiry_date']))
                          <div class="mini">{{ collect([$read['company_name'], $read['registration_number'], $read['period_year'], $read['expiry_date'] ? '→ '.$read['expiry_date'] : null])->filter()->implode(' · ') }}</div>
                        @endif
                        @foreach ($rv['issues'] ?? [] as $issue)<div>• {{ App\Services\Portal\DocumentReviewer::issueText($issue) }}</div>@endforeach
                      </div>
                    </details>
                  @endif
                  @if (($manage) || (! $office && $f->from_client && $r->status !== App\Models\DocumentRequest::ACCEPTED))
                    <form method="POST" action="{{ $office ? route('clients.files.destroy', [$company, $f]) : route('portal.files.destroy', $f) }}" onsubmit="return confirm(@js(__('portal.file_confirm_delete')))">@csrf @method('DELETE')
                      <button class="x" type="submit" title="{{ __('portal.delete') }}" aria-label="{{ __('portal.delete') }}">×</button></form>
                  @endif
                </div>
              @endforeach
            </div>
          @endif
          @if ($manage || (! $office && $r->status !== App\Models\DocumentRequest::ACCEPTED && $r->status !== App\Models\DocumentRequest::NOT_APPLICABLE))
            <form class="upload" method="POST" enctype="multipart/form-data" action="{{ $office ? route('clients.requests.upload', [$company, $r]) : route('portal.requests.upload', $r) }}">@csrf
              <input type="file" name="files[]" multiple required accept=".{{ implode(',.', config('portal.extensions')) }}" aria-label="{{ __('portal.upload.choose') }}">
              <button class="btn small" type="submit">{{ __($office ? 'portal.upload.office_send' : 'portal.upload.send') }}</button>
              <span class="hint">{{ __('portal.upload.hint', ['size' => round(config('portal.max_file_kb') / 1024), 'types' => implode(' ', config('portal.extensions'))]) }}</span>
            </form>
          @endif
        </div>
        <div class="acts-col">
          <button type="button" class="btn ghost small chat-btn" data-chat-for="{{ $r->id }}" data-chat-title="{{ ($r->number ? $r->number.' · ' : '').\Illuminate\Support\Str::limit($r->title, 60) }}">{{ __('portal.chat.open') }}@if (($unread[$r->id] ?? 0) > 0)<span class="dot">{{ $unread[$r->id] }}</span>@endif</button>
          @if ($manage)
            @if ($r->status !== App\Models\DocumentRequest::ACCEPTED)
              <form method="POST" action="{{ route('clients.requests.review', [$company, $r]) }}">@csrf @method('PUT')<input type="hidden" name="status" value="accepted">
                <button class="btn small ok" type="submit" style="width:100%">{{ __('portal.review.accept') }}</button></form>
            @endif
            <details class="more"><summary>{{ __('portal.review.more') }}</summary>
              <form method="POST" action="{{ route('clients.requests.review', [$company, $r]) }}">@csrf @method('PUT')<input type="hidden" name="status" value="returned">
                <textarea name="note" rows="2" maxlength="2000" placeholder="{{ __('portal.review.note_ph') }}" required></textarea>
                <button class="btn small warnb" type="submit">{{ __('portal.review.return') }}</button></form>
              @if ($r->status !== App\Models\DocumentRequest::NOT_APPLICABLE)
                <form method="POST" action="{{ route('clients.requests.review', [$company, $r]) }}">@csrf @method('PUT')<input type="hidden" name="status" value="not_applicable">
                  <button class="btn small ghost" type="submit">{{ __('portal.review.na') }}</button></form>
              @endif
              @if (in_array($r->status, ['accepted', 'not_applicable', 'returned'], true))
                <form method="POST" action="{{ route('clients.requests.review', [$company, $r]) }}">@csrf @method('PUT')<input type="hidden" name="status" value="{{ $r->files->isNotEmpty() ? 'uploaded' : 'pending' }}">
                  <button class="btn small ghost" type="submit">{{ __('portal.review.reopen') }}</button></form>
              @endif
              <form method="POST" action="{{ route('clients.requests.destroy', [$company, $r]) }}" onsubmit="return confirm(@js(__('portal.review.confirm_delete')))">@csrf @method('DELETE')
                <button class="btn small danger" type="submit">{{ __('portal.review.delete') }}</button></form>
            </details>
          @elseif (! $office && $r->status !== App\Models\DocumentRequest::ACCEPTED)
            <form method="POST" action="{{ route('portal.requests.declare', $r) }}">@csrf
              <input type="hidden" name="not_applicable" value="{{ $r->status === App\Models\DocumentRequest::NOT_APPLICABLE ? 0 : 1 }}">
              <button class="btn small ghost" type="submit" style="width:100%">{{ __($r->status === App\Models\DocumentRequest::NOT_APPLICABLE ? 'portal.undo_na' : 'portal.declare_na') }}</button></form>
          @endif
        </div>
      </div>
    @endforeach
  </details>
@empty
  <div class="panel empty-state">
    <h3>{{ __($office ? 'portal.empty.office_title' : 'portal.empty.client_title') }}</h3>
    <p>{{ __($office ? 'portal.empty.office_sub' : 'portal.empty.client_sub') }}</p>
  </div>
@endforelse

@push('scripts')
<script>
  // The filter buttons show only the requests of one group; a section with none left is hidden.
  document.querySelectorAll('[data-filters] button').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('[data-filters] button').forEach((x) => x.classList.toggle('on', x === b));
    const want = b.dataset.filter;
    document.querySelectorAll('[data-section]').forEach((s) => {
      let shown = 0;
      s.querySelectorAll('.req').forEach((r) => { const ok = want === 'all' || r.dataset.group === want; r.hidden = !ok; if (ok) shown++; });
      s.hidden = shown === 0;
      if (want !== 'all' && shown) s.open = true;
    });
  }));
  // A request sent back from the chat's link, or just uploaded to, is scrolled to.
  if (location.hash.startsWith('#req-')) document.querySelector(location.hash)?.scrollIntoView({ block: 'center' });
</script>
@endpush
