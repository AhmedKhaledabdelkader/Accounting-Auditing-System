{{-- One classification rule's fields (classification-rules.blade.php): to edit a rule ($r), or to add one ($r null). Word lists are one per line or comma. --}}
@php $v = fn (string $field, $default = null) => $val($id, $field, $default); @endphp
<form method="POST" action="{{ $r ? route('classification-rules.update', $r) : route('classification-rules.store') }}">
  @csrf
  @if ($r) @method('PUT') @endif
  <input type="hidden" name="form" value="{{ $id }}">
  <fieldset class="rl-form" @disabled(! $canManage)>
    <div class="field"><label>{{ __('rules_page.fields.name') }}</label><input name="name" maxlength="100" required value="{{ $v('name', $r?->name) }}"></div>
    <div class="field"><label>{{ __('rules_page.fields.sort') }}</label><input name="sort" type="number" min="0" required value="{{ $v('sort', $r?->sort ?? 1000) }}"></div>
    @foreach (['name_words', 'also_words', 'exclude_words', 'heading_words', 'ai_classes'] as $f)
      <div class="field"><label>{{ __("rules_page.fields.{$f}") }}</label>
        <textarea name="{{ $f }}_text">{{ $v("{$f}_text", $list($r?->{$f})) }}</textarea>
        <small class="note">{{ __("rules_page.help.{$f}") }}</small>
      </div>
    @endforeach
    @foreach (['debit_class', 'credit_class'] as $f)
      <div class="field"><label>{{ __("rules_page.fields.{$f}") }}</label>
        <select name="{{ $f }}">
          <option value="">{{ __('rules_page.undecided') }}</option>
          @foreach ($subClasses as $c)<option value="{{ $c }}" @selected($v($f, $r?->{$f}) === $c)>{{ $c }}</option>@endforeach
        </select>
      </div>
    @endforeach
    <div class="field"><label>{{ __('rules_page.fields.review_side') }}</label>
      <select name="review_side">
        @foreach (\App\Models\ClassificationRule::REVIEW_SIDES as $side)
          <option value="{{ $side }}" @selected($v('review_side', $r?->review_side ?? 'none') === $side)>{{ __("rules_page.review_sides.{$side}") }}</option>
        @endforeach
      </select>
    </div>
    <div class="field"><label><input type="checkbox" name="enabled" value="1" @checked($v('enabled', $r?->enabled ?? true))> {{ __('rules_page.fields.enabled') }}</label></div>
    @if ($canManage)<div class="field"><button class="btn" type="submit">{{ $r ? __('rules_page.save') : __('rules_page.add') }}</button></div>@endif
  </fieldset>
</form>
