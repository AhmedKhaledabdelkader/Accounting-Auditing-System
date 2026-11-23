@extends('layouts.app')
@section('title', __('engagement.entries.title'))
@section('subtitle', __('engagement.entries.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'adjustments'])
  @php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $stClass = ['proposed' => 'warn', 'accepted' => 'info', 'posted' => 'ok', 'rejected' => 'bad'];
    $moves = ['proposed' => ['accept', 'post', 'reject'], 'accepted' => ['post', 'reject'], 'posted' => ['unpost'], 'rejected' => ['reopen']];
  @endphp
  @if ($run === null)
    <div class="panel"><div class="msg warn">{{ __('engagement.entries.errors.no_trial_balance') }}</div></div>
  @else
  {{-- The adjusting entries: the adjusted trial balance = the approved one + the posted entries. --}}
  <div class="panel" data-entries>
    <h3>{{ __('engagement.entries.list') }}</h3>
    <p class="note">{{ __('engagement.entries.hint') }}</p>
    @if ($entries->isEmpty())
      <p>{{ __('engagement.entries.none') }}</p>
    @else
      <table>
        <tr><th>#</th><th>{{ __('engagement.entries.col.description') }}</th><th>{{ __('engagement.entries.col.account') }}</th><th class="num">{{ __('engagement.entries.col.debit') }}</th>
          <th class="num">{{ __('engagement.entries.col.credit') }}</th><th>{{ __('engagement.entries.col.status') }}</th><th></th></tr>
        @foreach ($entries as $en)
          @foreach ($en->lines as $l)
            <tr data-entry="{{ $en->id }}" data-entry-status="{{ $en->status }}">
              @if ($loop->first)
                <td rowspan="{{ $en->lines->count() }}">{{ $en->number }}</td>
                <td rowspan="{{ $en->lines->count() }}">{{ $en->description }}<br><small>{{ __("engagement.entries.types.{$en->type}") }} · {{ __("engagement.entries.sources.{$en->source_type}") }}@if ($en->source_file) · {{ $en->source_file }}@if ($en->source_page) ({{ __('engagement.entries.line_no', ['n' => $en->source_page]) }})@endif @endif</small></td>
              @endif
              <td>{{ trim(($l->account_code ? $l->account_code.' — ' : '').$l->account_name) }}</td>
              <td class="num">{{ $l->debit ? $money($l->debit) : '' }}</td><td class="num">{{ $l->credit ? $money($l->credit) : '' }}</td>
              @if ($loop->first)
                <td rowspan="{{ $en->lines->count() }}"><span class="pill {{ $stClass[$en->status] }}">{{ __("engagement.entries.statuses.{$en->status}") }}</span></td>
                <td rowspan="{{ $en->lines->count() }}" style="white-space:nowrap">
                  @if ($canManage)
                    @foreach ($moves[$en->status] as $mv)
                      <form method="POST" action="{{ route('clients.entries.move', [$company, $en->id]) }}" style="display:inline">@csrf<input type="hidden" name="action" value="{{ $mv }}">
                        <button class="btn small {{ $mv === 'post' ? 'ok' : ($mv === 'reject' ? 'danger' : 'ghost') }}" type="submit" data-move="{{ $mv }}">{{ __("engagement.entries.actions.{$mv}") }}</button></form>
                    @endforeach
                  @endif
                </td>
              @endif
            </tr>
          @endforeach
        @endforeach
      </table>
    @endif
    @error('entry')<div class="msg bad" data-entry-error>{{ $message }}</div>@enderror
    @if (session('entries_refused'))
      <div class="msg warn" data-entries-refused><b>{{ __('engagement.entries.refused_title') }}</b>
        <ul>@foreach (session('entries_refused') as $x)<li>{{ __('engagement.entries.entry_no', ['n' => $x['entry']]) }}: {{ $x['reason'] }}</li>@endforeach</ul></div>
    @endif
  </div>

  @if ($canManage)
    <details class="panel" @if ($errors->has('entry')) open @endif>
      <summary><b>＋ {{ __('engagement.entries.new') }}</b></summary>
      <form method="POST" action="{{ route('clients.entries.store', $company) }}" data-entry-form>@csrf
        <input type="hidden" name="year" value="{{ $year }}">
        <div class="row">
          <div class="field" style="flex:2 1 320px"><label>{{ __('engagement.entries.col.description') }}</label><input name="description" maxlength="1000" required value="{{ old('description') }}"></div>
          <div class="field"><label>{{ __('engagement.entries.type') }}</label><select name="type">@foreach (['adjusting', 'reclassification'] as $t)<option value="{{ $t }}">{{ __("engagement.entries.types.{$t}") }}</option>@endforeach</select></div>
        </div>
        @for ($i = 0; $i < 4; $i++)
          <div class="row">
            <div class="field" style="flex:2 1 320px"><select name="lines[{{ $i }}][row]" aria-label="{{ __('engagement.entries.col.account') }}"><option value="">—</option>
              @foreach ($accounts as $a)<option value="{{ $a['row'] }}" @selected((string) old("lines.{$i}.row") === (string) $a['row'])>{{ trim(($a['number'] ? $a['number'].' — ' : '').$a['name']) }} ({{ $money($a['balance']) }})</option>@endforeach</select></div>
            <div class="field" style="max-width:160px"><input name="lines[{{ $i }}][debit]" type="number" step="0.01" min="0" dir="ltr" placeholder="{{ __('engagement.entries.col.debit') }}" value="{{ old("lines.{$i}.debit") }}" aria-label="{{ __('engagement.entries.col.debit') }}"></div>
            <div class="field" style="max-width:160px"><input name="lines[{{ $i }}][credit]" type="number" step="0.01" min="0" dir="ltr" placeholder="{{ __('engagement.entries.col.credit') }}" value="{{ old("lines.{$i}.credit") }}" aria-label="{{ __('engagement.entries.col.credit') }}"></div>
          </div>
        @endfor
        <p class="note">{{ __('engagement.entries.balanced_hint') }}</p>
        <button class="btn" type="submit">{{ __('engagement.entries.save') }}</button>
      </form>
      <form method="POST" enctype="multipart/form-data" action="{{ route('clients.entries.import', $company) }}" style="margin-top:12px" data-entry-import>@csrf
        <input type="hidden" name="year" value="{{ $year }}">
        <label>{{ __('engagement.entries.import') }}</label>
        <input type="file" name="file" required accept=".xlsx,.xls,.csv,.ods">
        <button class="btn small" type="submit">{{ __('engagement.entries.import_go') }}</button>
        <p class="note">{{ __('engagement.entries.import_hint') }}</p>
      </form>
    </details>
  @endif
  @endif

  {{-- The summary of misstatements (ISA 450). --}}
  <div class="panel" data-misstatements>
    <h3>{{ __('engagement.misstatements.title') }}</h3>
    <div class="row">
      <span class="pill">{{ __('engagement.misstatements.overall') }}: {{ $money($version?->overall) }}</span>
      <span class="pill">{{ __('engagement.misstatements.trivial') }}: {{ $money($version?->trivial) }}</span>
      <span class="pill {{ $totals['above_materiality'] ? 'bad' : 'ok' }}" data-uncorrected>{{ __('engagement.misstatements.uncorrected') }}: {{ $money($totals['uncorrected']) }}</span>
      <span class="pill">{{ __('engagement.misstatements.pl_effect') }}: {{ $money($totals['pl_effect']) }}</span>
    </div>
    @if ($version === null)<p class="msg warn">{{ __('engagement.sampling.needs_approved') }}</p>@endif
    @if ($totals['above_materiality'])<p class="msg bad">{{ __('engagement.blockers.MISSTATEMENTS_ABOVE_MATERIALITY', ['total' => $money($totals['uncorrected']), 'overall' => $money($version?->overall)]) }}</p>@endif
    <table>
      <tr><th>{{ __('engagement.misstatements.col.kind') }}</th><th>{{ __('engagement.misstatements.col.description') }}</th><th class="num">{{ __('engagement.misstatements.col.amount') }}</th>
        <th class="num">{{ __('engagement.misstatements.col.assets') }}</th><th class="num">{{ __('engagement.misstatements.col.liabilities') }}</th><th class="num">{{ __('engagement.misstatements.col.equity') }}</th>
        <th class="num">{{ __('engagement.misstatements.col.pl') }}</th><th>{{ __('engagement.misstatements.col.state') }}</th><th></th></tr>
      @forelse ($misstatements as $ms)
        <tr data-misstatement="{{ $ms['id'] ?? $ms['source'].':'.$ms['source_id'].':'.$ms['kind'] }}" @if ($ms['trivial']) style="opacity:.6" @endif>
          <td>{{ __("engagement.misstatements.kinds.{$ms['kind']}") }}</td><td>{{ $ms['description'] }}</td><td class="num">{{ $money($ms['amount']) }}</td>
          <td class="num">{{ $money($ms['effect_assets']) }}</td><td class="num">{{ $money($ms['effect_liabilities']) }}</td><td class="num">{{ $money($ms['effect_equity']) }}</td><td class="num">{{ $money($ms['effect_pl']) }}</td>
          <td>@if ($ms['trivial'])<span class="pill">{{ __('engagement.misstatements.below_trivial') }}</span>@endif
            <span class="pill {{ $ms['corrected'] ? 'ok' : 'warn' }}">{{ __($ms['corrected'] ? 'engagement.misstatements.corrected' : 'engagement.misstatements.not_corrected') }}</span>
            @if ($ms['prior_period'])<span class="pill">{{ __('engagement.misstatements.prior_period') }}</span>@endif</td>
          <td style="white-space:nowrap">@if ($canManage && $ms['id'])
            <form method="POST" action="{{ route('clients.misstatements.update', [$company, $ms['id']]) }}" style="display:inline">@csrf<input type="hidden" name="corrected" value="{{ $ms['corrected'] ? 0 : 1 }}">
              <button class="btn small ghost" type="submit">{{ __($ms['corrected'] ? 'engagement.misstatements.mark_uncorrected' : 'engagement.misstatements.mark_corrected') }}</button></form>
            @if ($ms['editable'])<form method="POST" action="{{ route('clients.misstatements.delete', [$company, $ms['id']]) }}" style="display:inline">@csrf<button class="btn small danger" type="submit">×</button></form>@endif
          @endif</td>
        </tr>
      @empty
        <tr><td colspan="9">{{ __('engagement.misstatements.none') }}</td></tr>
      @endforelse
    </table>
    @if ($canManage)
      <details style="margin-top:10px"><summary>＋ {{ __('engagement.misstatements.add') }}</summary>
        <form method="POST" action="{{ route('clients.misstatements.store', $company) }}" class="row" style="align-items:end">@csrf
          <input type="hidden" name="year" value="{{ $year }}">
          <div class="field"><select name="kind">@foreach (\App\Models\Misstatement::KINDS as $k)<option value="{{ $k }}">{{ __("engagement.misstatements.kinds.{$k}") }}</option>@endforeach</select></div>
          <div class="field" style="flex:2 1 260px"><input name="description" required maxlength="1000" placeholder="{{ __('engagement.misstatements.col.description') }}"></div>
          @foreach (['amount', 'effect_assets', 'effect_liabilities', 'effect_equity', 'effect_pl'] as $fld)
            <div class="field" style="max-width:130px"><input name="{{ $fld }}" type="number" step="0.01" dir="ltr" placeholder="{{ __("engagement.misstatements.fields.{$fld}") }}" @if ($fld === 'amount') required @endif aria-label="{{ __("engagement.misstatements.fields.{$fld}") }}"></div>
          @endforeach
          <label><input type="checkbox" name="prior_period" value="1"> {{ __('engagement.misstatements.prior_period') }}</label>
          <button class="btn small" type="submit">{{ __('engagement.misstatements.add') }}</button>
        </form>
      </details>
    @endif
  </div>
  @include('portal.file._close')
@endsection
