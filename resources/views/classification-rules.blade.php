@extends('layouts.app')
@section('title', __('rules_page.title'))
@section('subtitle', __('rules_page.sub'))
@section('content')
  @include('partials.flash')
  @if (session('back'))
    <p><a class="btn small" href="{{ session('back') }}">{{ __('rules_page.back_to_run') }}</a></p>
  @endif
  @php
    // A form's own values come back after a validation error, and its section stays open; other forms start clean.
    $form = old('form');
    $val = fn (string $id, string $field, $default = null) => $form === $id ? old($field, $default) : $default;
    $list = fn (?array $words) => implode('، ', $words ?? []);
  @endphp

  <div class="panel">
    <p class="note">{{ __('rules_page.how') }}</p>
    @unless ($canManage)<p class="note">{{ __('rules_page.view_only') }}</p>@endunless
  </div>

  @foreach ($rules as $r)
    <article class="panel rl-rule">
      <header class="rl-head">
        <h3>{{ $r->name }}</h3>
        <span class="badge b-muted">{{ __('rules_page.sort_badge', ['sort' => $r->sort]) }}</span>
        @unless ($r->enabled)<span class="badge b-warn">{{ __('rules_page.disabled') }}</span>@endunless
        <span class="note">{{ __('rules_page.summary', ['debit' => $r->debit_class ?? __('rules_page.undecided'), 'credit' => $r->credit_class ?? __('rules_page.undecided')]) }}</span>
      </header>
      <details @if ($form === 'rule-'.$r->id) open @endif>
        <summary class="btn ghost small">{{ $canManage ? __('rules_page.edit') : __('rules_page.details') }}</summary>
        @include('partials.classification-rule-form', ['id' => 'rule-'.$r->id, 'r' => $r])
        @if ($canManage)
          <form method="POST" action="{{ route('classification-rules.destroy', $r) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('rules_page.confirm_delete', ['name' => $r->name]) }}">
            @csrf @method('DELETE')
            <button class="btn danger small" type="submit">{{ __('rules_page.delete') }}</button>
          </form>
        @endif
      </details>
    </article>
  @endforeach

  @if ($canManage)
    <details class="panel" @if ($form === 'rule-new') open @endif>
      <summary>+ {{ __('rules_page.add') }}</summary>
      @include('partials.classification-rule-form', ['id' => 'rule-new', 'r' => null])
    </details>
  @endif

  <h2 id="marks">{{ __('rules_page.marks.title') }}</h2>
  @php
    // From the classifier's «apply to the group» link: the mark filled in, the user confirms.
    $pre = fn (string $field) => $val('mark-new', $field, request()->query($field));
  @endphp
  <div class="panel">
    <p class="note">{{ __('rules_page.marks.how') }}</p>
    <table class="rl-marks">
      <tr><th>{{ __('rules_page.marks.company') }}</th><th>{{ __('rules_page.marks.prefix') }}</th><th>{{ __('rules_page.marks.rule') }}</th><th></th></tr>
      @forelse ($marks as $m)
        <tr>
          <td>{{ $m->company?->name_ar }}</td><td dir="ltr">{{ $m->prefix }}…</td><td>{{ $m->rule?->name }}</td>
          <td>
            @if ($canManage)
              <form method="POST" action="{{ route('classification-rules.marks.destroy', $m) }}">
                @csrf @method('DELETE')
                <button class="btn ghost small" type="submit">{{ __('rules_page.delete') }}</button>
              </form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="note">{{ __('rules_page.marks.none') }}</td></tr>
      @endforelse
    </table>
    @if ($canManage)
      <form method="POST" action="{{ route('classification-rules.marks.store') }}" class="row">
        @csrf
        <input type="hidden" name="form" value="mark-new">
        <input type="hidden" name="back" value="{{ $pre('back') }}">
        <div class="field"><label>{{ __('rules_page.marks.company') }}</label>
          <select name="company_id" required>
            <option value="">{{ __('rules_page.choose') }}</option>
            @foreach ($companies as $c)<option value="{{ $c->id }}" @selected($pre('company_id') === $c->id)>{{ $c->name_ar }}</option>@endforeach
          </select>
        </div>
        <div class="field"><label>{{ __('rules_page.marks.prefix') }}</label><input name="prefix" dir="ltr" inputmode="numeric" pattern="\d+" required value="{{ $pre('prefix') }}"></div>
        <div class="field"><label>{{ __('rules_page.marks.rule') }}</label>
          <select name="rule_id" required>
            <option value="">{{ __('rules_page.choose') }}</option>
            @foreach ($rules as $r)<option value="{{ $r->id }}" @selected((string) $pre('rule_id') === (string) $r->id)>{{ $r->name }}</option>@endforeach
          </select>
        </div>
        <div class="field"><button class="btn" type="submit">{{ __('rules_page.marks.add') }}</button></div>
      </form>
    @endif
  </div>

  <style>
    .rl-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .rl-head h3{margin:0}
    .rl-rule details{margin-top:8px}
    .rl-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:10px}
    .rl-form textarea{min-height:60px}
    .rl-marks{width:100%;margin-bottom:12px}
  </style>
@endsection
