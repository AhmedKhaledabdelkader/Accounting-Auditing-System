@extends('layouts.app')
@section('title', __('taxonomy_page.title'))
@section('subtitle', __('taxonomy_page.sub'))
@section('content')
  @include('partials.flash')
  @php
    // A form's own values come back after a validation error, and its section stays open; other forms start clean.
    $form = old('form');
    $val = fn (string $id, string $field, $default = null) => $form === $id ? old($field, $default) : $default;
    $search = fn (...$names) => mb_strtolower(implode(' ', array_filter($names)));
  @endphp

  <div class="stats">
    <div class="stat"><small>{{ __('taxonomy_page.counts.mains') }}</small><b>{{ $counts['mains'] }}</b></div>
    <div class="stat"><small>{{ __('taxonomy_page.counts.subs') }}</small><b>{{ $counts['subs'] }}</b></div>
    <div class="stat ok"><small>{{ __('taxonomy_page.counts.added') }}</small><b>{{ $counts['added'] }}</b></div>
  </div>

  <div class="toolbar tx-bar">
    <div class="tabs" role="tablist">
      @foreach ($statements as $statement => $s)
        <button type="button" role="tab" @class(['tab', 'on' => $loop->index === $tab]) data-tab="{{ $loop->index }}" aria-selected="{{ $loop->index === $tab ? 'true' : 'false' }}">
          {{ $statement }} <span class="badge b-info">{{ $s['mains']->sum(fn ($m) => $m->subClasses->count()) }}</span>
        </button>
      @endforeach
    </div>
    <input type="search" id="tx-search" class="tx-search" placeholder="{{ __('taxonomy_page.search') }}" aria-label="{{ __('taxonomy_page.search') }}">
    @unless ($canManage)<span class="note">{{ __('taxonomy_page.view_only') }}</span>@endunless
  </div>

  @foreach ($statements as $statement => $s)
    <section class="tx-statement" data-panel="{{ $loop->index }}" @if ($loop->index !== $tab) hidden @endif>
      @if ($canManage)
        @php $id = 'main-new-'.$loop->index; @endphp
        <details class="panel tx-form" @if ($form === $id) open @endif>
          <summary>+ {{ __('taxonomy_page.add_main') }}</summary>
          <form method="POST" action="{{ route('taxonomy.mains.store') }}" class="row">
            @csrf
            <input type="hidden" name="form" value="{{ $id }}">
            <input type="hidden" name="statement" value="{{ $statement }}">
            <div class="field"><label>{{ __('taxonomy_page.fields.name') }}</label><input name="name" maxlength="100" required value="{{ $val($id, 'name') }}"></div>
            <div class="field"><label>{{ __('taxonomy_page.fields.family') }}</label>
              <select name="family" required>
                <option value="">{{ __('taxonomy_page.fields.choose') }}</option>
                @foreach (App\Repositories\Eloquents\TaxonomyRepository::STATEMENTS[$statement]['families'] as $f)
                  <option value="{{ $f }}" @selected($val($id, 'family') === $f)>{{ __("taxonomy_page.families.{$f}") }}</option>
                @endforeach
              </select>
            </div>
            <div class="field tx-submit"><button class="btn" type="submit">{{ __('taxonomy_page.add') }}</button></div>
          </form>
        </details>
      @endif

      @foreach ($s['mains'] as $main)
        <article class="panel tx-main" data-main="{{ $main->name }}"
                 data-search="{{ $search($main->name, ...$main->aliases->pluck('name'), ...$main->subClasses->flatMap(fn ($sub) => [$sub->name, ...$sub->aliases->pluck('name')])) }}">
          <header class="tx-head">
            <div class="tx-title">
              <span class="rc-icon"><svg viewBox="0 0 24 24"><rect x="9" y="2" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/></svg></span>
              <div>
                <h3>{{ $main->name }}</h3>
                <div class="tx-meta">
                  <span class="badge b-info">{{ __("taxonomy_page.families.{$main->family}") }}</span>
                  <span class="badge b-muted">{{ __('taxonomy_page.subs_count', ['count' => $main->subClasses->count()]) }}</span>
                  @unless ($main->builtIn())<span class="badge b-warn">{{ __('taxonomy_page.added_badge') }}</span>@endunless
                  @if ($main->aliases->isNotEmpty())<span class="note">{{ __('taxonomy_page.former_names', ['names' => $main->aliases->pluck('name')->implode(__('taxonomy_page.list_separator'))]) }}</span>@endif
                </div>
              </div>
            </div>
            @if ($canManage)
              <div class="tx-actions">
                @php $id = 'main-'.$main->id; @endphp
                <details class="tx-edit" @if ($form === $id) open @endif>
                  <summary class="btn ghost small">{{ __('taxonomy_page.edit') }}</summary>
                  <form method="POST" action="{{ route('taxonomy.mains.update', $main) }}" class="tx-pop">
                    @csrf @method('PUT')
                    <input type="hidden" name="form" value="{{ $id }}">
                    <div class="field"><label>{{ __('taxonomy_page.fields.name') }}</label><input name="name" maxlength="100" required value="{{ $val($id, 'name', $main->name) }}"></div>
                    @unless ($main->builtIn())
                      <div class="field"><label>{{ __('taxonomy_page.fields.family') }}</label>
                        <select name="family" required>
                          @foreach (App\Repositories\Eloquents\TaxonomyRepository::STATEMENTS[$statement]['families'] as $f)
                            <option value="{{ $f }}" @selected($val($id, 'family', $main->family) === $f)>{{ __("taxonomy_page.families.{$f}") }}</option>
                          @endforeach
                        </select>
                      </div>
                    @endunless
                    <p class="note">{{ __('taxonomy_page.rename_note') }}</p>
                    <button class="btn small" type="submit">{{ __('taxonomy_page.save') }}</button>
                  </form>
                </details>
                <form method="POST" action="{{ route('taxonomy.mains.destroy', $main) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('taxonomy_page.confirm_delete_main', ['name' => $main->name]) }}">
                  @csrf @method('DELETE')
                  <button class="btn danger small" type="submit">{{ __('taxonomy_page.delete') }}</button>
                </form>
              </div>
            @endif
          </header>

          @if ($main->subClasses->isEmpty())
            <p class="note">{{ __('taxonomy_page.no_subs') }}</p>
          @else
            <table class="tx-subs">
              <tr><th>{{ __('taxonomy_page.cols.sub') }}</th><th>{{ __('taxonomy_page.cols.side') }}</th><th>{{ __('taxonomy_page.cols.line') }}</th>@if ($canManage)<th></th>@endif</tr>
              @foreach ($main->subClasses as $sub)
                <tr class="tx-sub" data-sub="{{ $sub->name }}" data-search="{{ $search($sub->name, ...$sub->aliases->pluck('name')) }}">
                  <td><b>{{ $sub->name }}</b>
                    @unless ($sub->builtIn())<span class="badge b-warn">{{ __('taxonomy_page.added_badge') }}</span>@endunless
                    @if ($sub->aliases->isNotEmpty())<div class="note">{{ __('taxonomy_page.former_names', ['names' => $sub->aliases->pluck('name')->implode(__('taxonomy_page.list_separator'))]) }}</div>@endif
                  </td>
                  <td><span @class(['badge', 'b-ok' => $sub->normal_side === 'debit', 'b-info' => $sub->normal_side === 'credit'])>{{ __("taxonomy_page.sides.{$sub->normal_side}") }}</span></td>
                  <td>{{ $sub->builtIn() ? __('taxonomy_page.built_in_rule') : ($labels[$sub->line_key]['label_ar'] ?? '—') }}</td>
                  @if ($canManage)
                    <td class="tx-cell"><div class="tx-actions">
                      @php $id = 'sub-'.$sub->id; @endphp
                      <details class="tx-edit" @if ($form === $id) open @endif>
                        <summary class="btn ghost small">{{ __('taxonomy_page.edit') }}</summary>
                        <form method="POST" action="{{ route('taxonomy.subs.update', $sub) }}" class="tx-pop">
                          @csrf @method('PUT')
                          <input type="hidden" name="form" value="{{ $id }}">
                          <div class="field"><label>{{ __('taxonomy_page.fields.name') }}</label><input name="name" maxlength="100" required value="{{ $val($id, 'name', $sub->name) }}"></div>
                          <div class="field"><label>{{ __('taxonomy_page.fields.normal_side') }}</label>
                            <select name="normal_side" required>
                              @foreach (['debit', 'credit'] as $side)<option value="{{ $side }}" @selected($val($id, 'normal_side', $sub->normal_side) === $side)>{{ __("taxonomy_page.sides.{$side}") }}</option>@endforeach
                            </select>
                          </div>
                          @unless ($sub->builtIn())
                            <div class="field"><label>{{ __('taxonomy_page.fields.line_key') }}</label>
                              <select name="line_key" required>
                                @foreach ($s['lines'] as $key => $label)<option value="{{ $key }}" @selected($val($id, 'line_key', $sub->line_key) === $key)>{{ $label }}</option>@endforeach
                              </select>
                            </div>
                          @endunless
                          <p class="note">{{ __('taxonomy_page.rename_note') }}</p>
                          <button class="btn small" type="submit">{{ __('taxonomy_page.save') }}</button>
                        </form>
                      </details>
                      <form method="POST" action="{{ route('taxonomy.subs.destroy', $sub) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('taxonomy_page.confirm_delete_sub', ['name' => $sub->name]) }}">
                        @csrf @method('DELETE')
                        <button class="btn danger small" type="submit">{{ __('taxonomy_page.delete') }}</button>
                      </form>
                    </div></td>
                  @endif
                </tr>
              @endforeach
            </table>
          @endif

          @if ($canManage)
            @php $id = 'sub-new-'.$main->id; @endphp
            <details class="tx-form" @if ($form === $id) open @endif>
              <summary>+ {{ __('taxonomy_page.add_sub') }}</summary>
              <form method="POST" action="{{ route('taxonomy.subs.store', $main) }}" class="row">
                @csrf
                <input type="hidden" name="form" value="{{ $id }}">
                <div class="field"><label>{{ __('taxonomy_page.fields.name') }}</label><input name="name" maxlength="100" required value="{{ $val($id, 'name') }}"></div>
                <div class="field"><label>{{ __('taxonomy_page.fields.normal_side') }}</label>
                  <select name="normal_side" required>
                    @foreach (['debit', 'credit'] as $side)<option value="{{ $side }}" @selected($val($id, 'normal_side', $main->subClasses->first()?->normal_side) === $side)>{{ __("taxonomy_page.sides.{$side}") }}</option>@endforeach
                  </select>
                </div>
                <div class="field"><label>{{ __('taxonomy_page.fields.line_key') }}</label>
                  <select name="line_key" required title="{{ __('taxonomy_page.line_note') }}">
                    <option value="">{{ __('taxonomy_page.fields.choose') }}</option>
                    @foreach ($s['lines'] as $key => $label)<option value="{{ $key }}" @selected($val($id, 'line_key') === $key)>{{ $label }}</option>@endforeach
                  </select>
                </div>
                <div class="field tx-submit"><button class="btn" type="submit">{{ __('taxonomy_page.add') }}</button></div>
              </form>
            </details>
          @endif
        </article>
      @endforeach
      <p class="note tx-empty" hidden>{{ __('taxonomy_page.no_match') }}</p>
    </section>
  @endforeach
@endsection

@push('scripts')
<script>
(() => {
  // Tabs: one statement at a time; the URL keeps the open one (?tab=), so a saved form comes back to it.
  const tabs = document.querySelectorAll('.tabs .tab');
  const panels = document.querySelectorAll('.tx-statement');
  tabs.forEach((tab) => tab.addEventListener('click', () => {
    tabs.forEach((t) => { t.classList.toggle('on', t === tab); t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
    panels.forEach((p) => { p.hidden = p.dataset.panel !== tab.dataset.tab; });
    const url = new URL(location.href); url.searchParams.set('tab', tab.dataset.tab); history.replaceState(null, '', url);
  }));

  // Search: main classes whose name, sub classes or former names hold the words; within them, the matching sub classes.
  const box = document.getElementById('tx-search');
  box.addEventListener('input', () => {
    const q = box.value.trim().toLowerCase();
    panels.forEach((panel) => {
      let shown = 0;
      panel.querySelectorAll('.tx-main').forEach((main) => {
        const hit = q === '' || main.dataset.search.includes(q);
        main.hidden = ! hit;
        shown += hit ? 1 : 0;
        const own = q !== '' && main.dataset.main.toLowerCase().includes(q);
        main.querySelectorAll('.tx-sub').forEach((row) => { row.hidden = q !== '' && ! own && ! row.dataset.search.includes(q); });
      });
      panel.querySelector('.tx-empty').hidden = shown > 0;
    });
  });
})();
</script>
@endpush
