@extends('layouts.app')
@section('title', __('client_file.statements.title'))
@section('subtitle', __('client_file.statements.sub'))
@section('content')
  @include('portal.file._open', ['company' => $company, 'active' => 'statements'])
  <div class="panel">
    <h2>{{ __('client_file.statements.title') }}</h2>
    @if ($reports->isEmpty())
      <p class="note">{{ __('client_file.statements.none') }}</p>
    @else
      <table>
        <tr><th>{{ __('client_file.statements.period') }}</th><th>{{ __('client_file.statements.status') }}</th><th>{{ __('client_file.statements.updated') }}</th><th></th></tr>
        @foreach ($reports as $r)
          <tr data-statements="{{ $r->id }}"><td>{{ \App\Services\Portal\Workspace::dateText($r->period_start) }} — {{ \App\Services\Portal\Workspace::dateText($r->period_end) }}</td>
            <td><span class="pill {{ $r->status === 'final' ? 'ok' : 'info' }}">{{ __('client_file.statements.statuses.'.$r->status) }}</span></td>
            <td>{{ $r->updated_at?->diffForHumans() }}</td>
            <td><a class="btn small" href="/financial-reports/{{ $r->id }}">{{ __('client_file.statements.open') }}</a></td></tr>
        @endforeach
      </table>
    @endif
  </div>
  @can('reports.create')
    <div class="panel">
      <h2>{{ __('client_file.statements.new') }}</h2>
      <p class="note">{{ __('client_file.statements.new_hint') }}</p>
      @if ($runs->isEmpty())
        <p class="note">{{ __('client_file.statements.no_runs') }}</p>
      @else
        @foreach ($runs as $r)
          <a class="btn ghost small" style="margin:4px" href="/financial-reports/new?run={{ $r->id }}">{{ __('client_file.statements.from_run') }}: {{ $r->original_name }}</a>
        @endforeach
      @endif
    </div>
  @endcan
  @include('portal.file._close')
@endsection