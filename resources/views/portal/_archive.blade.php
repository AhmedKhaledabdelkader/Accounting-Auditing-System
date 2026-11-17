{{-- The third way in: everything in one compressed file. $action: where it is sent; $years, $year; $archives: the latest ones. --}}
<div class="panel" data-archive-card>
  <h2>{{ __('portal.archive.title') }}</h2>
  <p class="note">{{ __('portal.archive.hint') }}</p>
  <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="row" style="align-items:end" id="archive-form">
    @csrf
    <div class="field"><label for="archive">{{ __('portal.archive.file') }}</label>
      <input id="archive" name="archive" type="file" accept=".{{ implode(',.', config('portal.archive_extensions')) }}" required>
      <span class="note">{{ __('portal.archive.formats', ['types' => implode(' / ', config('portal.archive_extensions')), 'size' => round(config('portal.max_archive_kb') / 1024)]) }}</span></div>
    <div class="field" style="max-width:180px"><label for="archive_year">{{ __('portal.archive.year') }}</label>
      <select id="archive_year" name="year">@foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>@endforeach</select></div>
    <div class="field"><button class="btn" type="submit" id="archive-send">{{ __('portal.archive.send') }}</button></div>
  </form>

  @if ($archives->isNotEmpty())
    <table style="margin-top:12px">
      @foreach ($archives as $a)
        @php $s = $a->summary ?? []; @endphp
        <tr data-archive="{{ $a->id }}" data-status="{{ $a->status }}">
          <td><b>{{ $a->original_name }}</b><div class="note">{{ $a->year }} · {{ $a->created_at?->diffForHumans() }}</div></td>
          <td>
            <span class="pill {{ ['done' => 'ok', 'failed' => 'bad'][$a->status] ?? 'info' }}">{{ __('portal.archive.status.'.$a->status) }}</span>
            @if (in_array($a->status, ['queued', 'sorting'], true))<div class="busy-line" role="progressbar" aria-label="{{ __('portal.archive.status.'.$a->status) }}"></div>@endif
            @if ($a->status === 'done')
              <div class="note">{{ __('portal.archive.result', ['total' => $s['total'] ?? 0, 'placed' => $s['placed'] ?? 0, 'unsorted' => $s['unsorted'] ?? 0]) }}@if (! empty($s['skipped'])) · {{ __('portal.archive.skipped', ['count' => count($s['skipped'])]) }}@endif</div>
              @if (! empty($s['skipped']))
                <details class="paper-help"><summary>{{ __('portal.archive.skipped_list') }}</summary>
                  @foreach ($s['skipped'] as $k)<div class="note">{{ $k['name'] }} — {{ __('portal.archive.why.'.$k['reason']) }}</div>@endforeach</details>
              @endif
            @elseif ($a->status === 'failed')
              <div class="note">{{ __('portal.archive.error.'.($a->error ?? 'unreadable')) }}</div>
            @endif
          </td>
        </tr>
      @endforeach
    </table>
  @endif
</div>
@push('scripts')
<script>
  (() => {
    const form = document.getElementById('archive-form');
    form?.addEventListener('submit', () => { if (form.checkValidity()) document.getElementById('archive-send').disabled = true; });
    // While an archive is being unpacked and sorted, the page looks again now and then.
    if (document.querySelector('[data-archive][data-status="queued"], [data-archive][data-status="sorting"]')) setTimeout(() => location.reload(), 8000);
  })();
</script>
@endpush
