{{-- A page's message after a form was sent: what was saved, or why it wasn't. --}}
@if (session('status'))<div class="msg ok">{{ session('status') }}</div>@endif
@foreach ((array) session('warnings', []) as $w)<div class="msg warn">{{ $w }}</div>@endforeach
@if ($errors->any())<div class="msg bad">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
