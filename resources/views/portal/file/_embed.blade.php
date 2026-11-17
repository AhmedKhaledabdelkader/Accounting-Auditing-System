{{-- The wizard's step of the chosen statements, inside the client's file ($config comes from the controller). --}}
<div id="app" class="cf-embed"><div class="loading">{{ __('financial_reports.ui.loading') }}</div></div>
@push('scripts')
<script type="module" src="/js/financial-report/wizard.js?v={{ @filemtime(public_path('js/financial-report/wizard.js')) }}"></script>
@endpush