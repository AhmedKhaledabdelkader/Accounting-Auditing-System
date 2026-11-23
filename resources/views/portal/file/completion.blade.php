@extends('layouts.app')
@section('title', __('engagement.completion.title'))
@section('subtitle', __('engagement.completion.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'completion'])
  @php $ro = ! $canManage; @endphp
  <form class="panel" method="POST" action="{{ route('clients.completion.save', $company) }}" data-completion>@csrf
    <input type="hidden" name="year" value="{{ $year }}">
    @foreach (['subsequent_events' => \App\Http\Controllers\Portal\CompletionController::SUBSEQUENT, 'going_concern' => \App\Http\Controllers\Portal\CompletionController::GOING_CONCERN,
      'representations' => \App\Http\Controllers\Portal\CompletionController::REPRESENTATIONS] as $field => $options)
      <fieldset style="border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin-bottom:12px">
        <legend><b>{{ __("engagement.completion.{$field}.title") }}</b> <small>{{ __("engagement.completion.{$field}.isa") }}</small></legend>
        <p class="note">{{ __("engagement.completion.{$field}.hint") }}</p>
        @foreach ($options as $o)
          <label style="display:block;font-weight:normal"><input type="radio" name="{{ $field }}" value="{{ $o }}" @checked(($c[$field] ?? null) === $o) @disabled($ro)> {{ __("engagement.completion.{$field}.options.{$o}") }}</label>
        @endforeach
        @if ($field === 'subsequent_events')
          <div class="field" style="max-width:220px"><label>{{ __('engagement.completion.subsequent_events.to') }}</label><input type="date" name="subsequent_to" value="{{ $c['subsequent_to'] ?? '' }}" @disabled($ro)></div>
        @elseif ($field === 'representations')
          <div class="field" style="max-width:220px"><label>{{ __('engagement.completion.representations.date') }}</label><input type="date" name="representations_date" value="{{ $c['representations_date'] ?? '' }}" @disabled($ro)></div>
        @endif
        @php $noteField = ['subsequent_events' => 'subsequent_note', 'going_concern' => 'going_concern_note', 'representations' => 'representations_note'][$field]; @endphp
        <div class="field"><label>{{ __('engagement.completion.note') }}</label><textarea name="{{ $noteField }}" rows="2" maxlength="3000" @disabled($ro)>{{ $c[$noteField] ?? '' }}</textarea></div>
      </fieldset>
    @endforeach
    @if ($canManage)<button class="btn" type="submit">{{ __('audit_plan.procedures.save') }}</button>@endif
  </form>
  @include('portal.file._close')
@endsection
