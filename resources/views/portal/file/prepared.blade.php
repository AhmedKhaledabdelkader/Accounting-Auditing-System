@extends('layouts.app')
@section('title', __("client_file.prepared.{$kind}.title"))
@section('subtitle', __("client_file.prepared.{$kind}.sub"))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => $active])
  @php $anyDocs = $items->sum('documents') > 0; @endphp
  <div class="panel">
    <form method="GET" action="{{ route($route, $company) }}" class="row" style="align-items:end">
      <div class="field" style="max-width:160px"><label for="py">{{ __('client_file.prepared.year') }}</label>
        <input id="py" name="year" type="number" min="2000" max="2100" value="{{ $year }}" dir="ltr" onchange="this.form.submit()"></div>
      <div class="field" style="flex:none;min-width:0"><span class="note">{{ \App\Services\Portal\Workspace::periodText($company, $year) }}</span></div>
    </form>
    @if ($canManage)
      <form method="POST" action="{{ route('clients.papers.prepare', $company) }}" style="margin-top:12px">@csrf
        <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="key" value="all">
        <button class="btn" type="submit" @disabled(! $anyDocs)>{{ __('client_file.prepared.prepare_all') }}</button>
      </form>
    @endif
  </div>

  <div class="panel">
    <table id="prepared-table" data-status-url="{{ route('clients.papers.status', $company) }}?year={{ $year }}">
      <tr><th>{{ __('client_file.prepared.name') }}</th><th>{{ __('client_file.prepared.documents') }}</th><th>{{ __('client_file.prepared.status') }}</th><th>{{ __('client_file.prepared.result') }}</th><th></th></tr>
      @foreach ($items as $item)
        @php $p = $item['paper']; @endphp
        <tr data-paper="{{ $item['key'] }}" data-id="{{ $p?->id }}">
          <td><b>{{ $item['title'] }}</b></td>
          <td>{{ $item['documents'] ? __('client_file.prepared.n_docs', ['count' => $item['documents']]) : __('client_file.prepared.no_docs') }}</td>
          <td>
            @if ($p)
              <span class="pill {{ ['done' => 'ok', 'failed' => 'bad', 'blocked' => 'warn'][$p->status] ?? 'info' }}">{{ __("client_file.prepared.status_names.{$p->status}") }}</span>
              @if ($p->isActive())
                <div class="busy-line" role="progressbar"></div>
                <div class="note prog">{{ $p->progress_total ? __('client_file.prepared.progress', ['done' => $p->progress_done, 'total' => $p->progress_total]) : '' }}</div>
              @endif
            @else
              <span class="note">{{ __('client_file.prepared.none') }}</span>
            @endif
          </td>
          <td>
            @if ($p?->status === 'done' && $p->analysis)
              <div class="note">{{ \App\Services\WorkingPapers\Papers::summary($p->analysis) }}</div>
              @if ($p->sources)<details class="paper-help"><summary>{{ __('client_file.prepared.sources') }}</summary>@foreach ($p->sources as $s)<div class="note">{{ $s }}</div>@endforeach</details>@endif
              @if ($p->notes)<details class="paper-help"><summary>{{ __('client_file.prepared.notes') }}</summary>@foreach ($p->notes as $n)<div class="note">{{ $n }}</div>@endforeach</details>@endif
            @elseif ($p?->status === 'blocked' || (! $p && ! $item['documents']))
              <div class="note">{{ __('client_file.prepared.blocked') }} <a href="{{ route('clients.show', ['company' => $company, 'year' => $year]) }}">{{ __('client_file.prepared.open_requirements') }}</a></div>
            @elseif ($p?->status === 'failed')
              <div class="note">{{ __('client_file.prepared.failed', ['error' => $p->error]) }}</div>
            @endif
          </td>
          <td style="white-space:nowrap">
            @if ($p?->status === 'done' && $p->path)<a class="btn small ghost" href="{{ route('clients.papers.download', [$company, $p->id]) }}" download>{{ __('client_file.prepared.download') }}</a>@endif
            @if ($canManage && $item['documents'] && ! $p?->isActive())
              <form method="POST" action="{{ route('clients.papers.prepare', $company) }}" style="display:inline">@csrf
                <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="key" value="{{ $item['key'] }}">
                <button class="btn small" type="submit">{{ __($p ? 'client_file.prepared.again' : 'client_file.prepared.prepare') }}</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
    </table>
  </div>
  @include('portal.file._close')
@endsection
@push('scripts')
<script>
  // While the AI is preparing papers the page looks again by itself, shows where each one is, and reloads when all are done.
  (() => {
    const table = document.getElementById('prepared-table');
    if (!table || !table.querySelector('.busy-line')) return;
    const words = @js(__('client_file.prepared.progress'));
    const tick = async () => {
      try {
        const r = await fetch(table.dataset.statusUrl, { headers: { Accept: 'application/json' } });
        const j = await r.json();
        if (j.active === 0) { location.reload(); return; }
        j.papers.forEach((p) => {
          const note = table.querySelector(`tr[data-paper="${p.key}"] .prog`);
          if (note && p.total) note.textContent = words.replace(':done', p.done).replace(':total', p.total);
        });
      } catch (e) { /* the next look tries again */ }
      setTimeout(tick, 3000);
    };
    setTimeout(tick, 3000);
  })();
</script>
@endpush
