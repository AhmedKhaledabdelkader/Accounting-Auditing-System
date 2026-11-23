{{-- A risk's form: its area, description, assertions, likelihood and magnitude, flags, and the area's procedures answering it. $r (or null), $action, $procs, $areas, $year. --}}
<form method="POST" action="{{ $action }}" style="margin-top:8px">@csrf
  <input type="hidden" name="year" value="{{ $year }}">
  <div class="row">
    <div class="field"><label>{{ __('engagement.risks.area') }}</label>
      <select name="area">@foreach ($areas as $k => $label)<option value="{{ $k }}" @selected(($r?->area ?? old('area')) === $k)>{{ $label }}</option>@endforeach</select></div>
    <div class="field" style="max-width:150px"><label>{{ __('engagement.risks.likelihood') }}</label>
      <select name="likelihood">@foreach ([1, 2, 3] as $v)<option value="{{ $v }}" @selected(($r?->likelihood ?? 2) === $v)>{{ __("engagement.risks.scale.{$v}") }}</option>@endforeach</select></div>
    <div class="field" style="max-width:150px"><label>{{ __('engagement.risks.magnitude') }}</label>
      <select name="magnitude">@foreach ([1, 2, 3] as $v)<option value="{{ $v }}" @selected(($r?->magnitude ?? 2) === $v)>{{ __("engagement.risks.scale.{$v}") }}</option>@endforeach</select></div>
  </div>
  <div class="field"><label>{{ __('engagement.risks.description') }}</label><textarea name="description" rows="2" maxlength="2000" required>{{ $r?->description ?? old('description') }}</textarea></div>
  <div class="field"><label>{{ __('engagement.risks.assertions') }}</label>
    <div class="row">@foreach (config('audit_procedures.assertions') as $a)<label style="font-weight:normal"><input type="checkbox" name="assertions[]" value="{{ $a }}" @checked(in_array($a, $r?->assertions ?? [], true))> {{ __("audit_plan.procedures.assertions.{$a}") }}</label>@endforeach</div></div>
  <div class="row">
    <label><input type="hidden" name="is_significant" value="0"><input type="checkbox" name="is_significant" value="1" @checked($r?->is_significant)> {{ __('engagement.risks.significant') }}</label>
    <label><input type="hidden" name="is_fraud" value="0"><input type="checkbox" name="is_fraud" value="1" @checked($r?->is_fraud)> {{ __('engagement.risks.fraud') }}</label>
    <label><input type="hidden" name="control_reliance" value="0"><input type="checkbox" name="control_reliance" value="1" @checked($r?->control_reliance)> {{ __('engagement.risks.control_reliance') }}</label>
  </div>
  @if ($procs !== [])
    <div class="field" data-risk-procedures><label>{{ __('engagement.risks.procedures') }}</label>
      @foreach ($procs as $code => [$text, $type])
        <label style="display:block;font-weight:normal"><input type="checkbox" name="procedures[]" value="{{ $code }}" @checked(in_array((string) $code, array_map('strval', $r?->procedures ?? []), true))>
          <b>{{ config("audit_procedures.areas.{$r->area}.code") }}-{{ $code }}</b> <span class="pill">{{ __("audit_plan.procedures.types.{$type}") }}</span> {{ \Illuminate\Support\Str::limit($text, 160) }}</label>
      @endforeach
      <span class="note">{{ __('engagement.risks.procedures_hint') }}</span></div>
  @elseif ($r)
    <p class="note">{{ __('engagement.risks.no_procedures') }}</p>
  @endif
  <div class="field"><label>{{ __('engagement.risks.response') }}</label><textarea name="response" rows="2" maxlength="2000">{{ $r?->response }}</textarea></div>
  @if ($r === null && $errors->any())<div class="msg bad">{{ implode(' ', $errors->all()) }}</div>@endif
  <button class="btn small" type="submit">{{ __('audit_plan.procedures.save') }}</button>
</form>
