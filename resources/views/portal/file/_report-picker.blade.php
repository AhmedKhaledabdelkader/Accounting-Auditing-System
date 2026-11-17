{{-- Which statements the page is about, when the company has several. $reports, $selected, $route, $company. --}}
@if ($reports->count() > 1)
  <div class="panel" data-report-picker>
    <h2>{{ __('client_file.pick_statements') }}</h2>
    @foreach ($reports as $r)
      <a class="btn small {{ $selected && $selected->id === $r->id ? '' : 'ghost' }}" style="margin:3px" href="{{ route($route, [$company, 'report' => $r->id]) }}">
        {{ \App\Services\Portal\Workspace::dateText($r->period_start) }} — {{ \App\Services\Portal\Workspace::dateText($r->period_end) }} ({{ __('client_file.statements.statuses.'.$r->status) }})</a>
    @endforeach
  </div>
@endif