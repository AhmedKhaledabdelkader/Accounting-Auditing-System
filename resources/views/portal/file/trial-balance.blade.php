@extends('layouts.app')
@section('title', __('client_file.trial_balance.title'))
@section('subtitle', __('client_file.trial_balance.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'trial_balance'])
  @foreach ($periods as $p)
    <div class="panel" data-period="{{ $p['year'] }}">
      <h2>{{ __($p['current'] ? 'client_file.trial_balance.current' : 'client_file.trial_balance.previous') }} <small class="note">{{ $p['text'] }}</small></h2>
      @if ($p['files']->isEmpty())
        <p class="note">{{ __('client_file.trial_balance.none_files') }}</p>
      @else
        <table>
          <tr><th>{{ __('client_file.trial_balance.name') }}</th><th>{{ __('client_file.trial_balance.request') }}</th><th>{{ __('client_file.trial_balance.review') }}</th><th></th></tr>
          @foreach ($p['files'] as $f)
            <tr><td><a href="{{ route('clients.files.download', [$company, $f['id']]) }}">{{ $f['name'] }}</a><div class="note">{{ \Illuminate\Support\Carbon::parse($f['uploaded_at'])->diffForHumans() }}</div></td>
              <td>{{ $f['request']['title'] ?? '—' }}</td>
              <td><span class="pill {{ $f['review'] === 'ok' ? 'ok' : ($f['review'] && $f['review'] !== 'unavailable' ? 'warn' : '') }}">{{ __($f['review'] === 'ok' ? 'client_file.trial_balance.fine' : ($f['review'] && $f['review'] !== 'unavailable' ? 'client_file.trial_balance.remarks' : 'client_file.trial_balance.not_reviewed')) }}</span></td>
              <td>@if ($canManage)<button class="btn small" type="button" data-classify="{{ $f['id'] }}">{{ __('client_file.trial_balance.classify') }}</button>@endif</td></tr>
          @endforeach
        </table>
      @endif
    </div>
  @endforeach
  <div class="panel">
    <h2>{{ __('client_file.trial_balance.runs') }}</h2>
    @if ($runs->isEmpty())
      <p class="note">{{ __('client_file.trial_balance.no_runs') }}</p>
    @else
      <table>
        <tr><th>{{ __('client_file.trial_balance.run_file') }}</th><th>{{ __('client_file.trial_balance.run_status') }}</th><th>{{ __('client_file.trial_balance.run_date') }}</th><th></th></tr>
        @foreach ($runs as $r)
          <tr><td>{{ $r->original_name }}</td><td><span class="pill {{ ['done' => 'ok', 'failed' => 'bad', 'cancelled' => 'bad', 'expired' => 'bad'][$r->status] ?? 'info' }}">{{ __('client_file.trial_balance.status.'.$r->status) }}</span></td>
            <td>{{ $r->created_at?->diffForHumans() }}</td>
            <td style="white-space:nowrap">@if ($r->status === 'done')<a class="btn small ghost" href="/classifier?run={{ $r->id }}">{{ __('client_file.trial_balance.view_result') }}</a>@endif
              @if ($r->status === 'done' && auth()->user()->can('reports.create'))<a class="btn small" href="/financial-reports/new?run={{ $r->id }}">{{ __('client_file.trial_balance.make_statements') }}</a>@endif</td></tr>
        @endforeach
      </table>
    @endif
    <p class="note" style="margin-top:10px">{{ __('client_file.trial_balance.upload_hint') }} <label class="btn ghost small" style="cursor:pointer">{{ __('client_file.trial_balance.upload_own') }}<input id="own-file" type="file" accept=".xlsx,.xls,.csv" hidden></label></p>
    <div id="classify-status"></div>
  </div>
  @include('portal.file._close')
@endsection
@push('scripts')
<script>
  // A trial balance (one the client uploaded, or one from this device) is classified with this client: the loader shows the real
  // upload percentage, then the run's own stages (reading, structure, classifying, checks) until it is done.
  const STAGES = @js(__('classifier_page.js.stages'));
  const STAGE_PCT = { uploading: 0, queued: 10, reading: 12, structure: 16, classifying: 22, checks: 96 };
  const out = document.getElementById('classify-status');
  const fail = (msg) => { out.textContent = msg || @js(__('client_file.trial_balance.error')); out.className = 'msg bad'; document.querySelectorAll('[data-classify],#own-file').forEach((x) => { x.disabled = false; }); };

  async function classify(fields) {
    document.querySelectorAll('[data-classify],#own-file').forEach((x) => { x.disabled = true; });
    out.textContent = ''; out.className = '';
    const h = Busy.start({ title: @js(__('client_file.trial_balance.classifying')), text: STAGES.uploading });
    const body = new FormData();
    Object.entries(fields).forEach(([k, v]) => body.append(k, v));
    body.append('company_id', @js($company->id));
    try {
      const r = await Busy.request('/api/v1/classify/jobs', { method: 'POST', body, headers: { Accept: 'application/json' }, overlay: false, processing: false,
        onUpload: (f) => h.set(f * STAGE_PCT.queued, STAGES.uploading) });
      if (!r.ok) throw new Error(r.json?.errors ? Object.values(r.json.errors)[0][0] : (r.json?.message || ''));
      let shown = STAGE_PCT.queued, job = r.json;
      for (;;) {
        const stage = job.status === 'queued' ? 'queued' : (job.stage || 'reading');
        const { done = 0, total = 0 } = job.progress || {};
        let pct = STAGE_PCT[stage] ?? 12;
        if (stage === 'classifying' && total) pct += (done / total) * (STAGE_PCT.checks - STAGE_PCT.classifying);
        shown = Math.max(shown, pct);
        h.set(shown, STAGES[stage] + (stage === 'classifying' && total ? ` (${done}/${total})` : ''));
        if (job.status === 'done') { h.done(); setTimeout(() => { location.href = `/classifier?run=${encodeURIComponent(job.id)}`; }, 350); return; }
        if (['failed', 'expired', 'cancelled'].includes(job.status)) throw new Error(job.error_text ?? job.error ?? '');
        await new Promise((ok) => setTimeout(ok, 2000));
        const res = await fetch(job.status_url ?? `/api/v1/classify/jobs/${job.id}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('');
        job = await res.json();
      }
    } catch (e) { h.hide(); fail(e.message); }
  }

  document.querySelectorAll('[data-classify]').forEach((b) => b.addEventListener('click', () => classify({ client_file_id: b.dataset.classify })));
  document.getElementById('own-file')?.addEventListener('change', (e) => { if (e.target.files[0]) classify({ file: e.target.files[0] }); });
</script>
@endpush