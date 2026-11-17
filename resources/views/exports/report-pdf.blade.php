{{-- The statements and notes as printed (PR 16): drawn from a version's payload by App\Services\Exports\StatementsPdf, which
     decides every figure and what is hidden; mPDF renders it. mPDF tags: htmlpageheader / htmlpagefooter, pagebreak. --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    body { font-family: xbriyaz; font-size: 11pt; direction: rtl; color: #111; }
    h1 { font-size: 20pt; text-align: center; margin: 0 0 6pt; }
    h2 { font-size: 15pt; margin: 0 0 8pt; border-bottom: 1px solid #002060; color: #002060; }
    h3 { font-size: 12.5pt; margin: 12pt 0 4pt; }
    p { margin: 3pt 0; line-height: 1.6; text-align: justify; }
    p.heading { font-weight: bold; margin-top: 8pt; }
    p.item { padding-right: 14pt; }
    table.fs { width: 100%; border-collapse: collapse; margin: 4pt 0 10pt; }
    table.fs th { font-weight: bold; border-bottom: 1px solid #333; padding: 3pt 4pt; text-align: right; }
    table.fs th.num { text-align: left; }
    table.fs td { padding: 2pt 4pt; vertical-align: top; }
    table.equity { font-size: 9.5pt; }
    table.equity td, table.equity th { padding: 1pt 3pt; }
    td.num { text-align: left; direction: ltr; white-space: nowrap; }
    {{-- Every amount column one width, in the statements and the notes alike, so the figures line up page to page. --}}
    .amount-col { width: 30mm; }
    .note-col { width: 14mm; text-align: center; }
    td.note-no { text-align: center; }
    td.num-head { text-align: left; font-weight: bold; white-space: nowrap; }
    tr.heading td { font-weight: bold; padding-top: 7pt; }
    tr.total td { font-weight: bold; border-top: 1px solid #333; }
    tr.head td { font-weight: bold; border-bottom: 1px solid #333; }
    table.medium { font-size: 9pt; }
    table.wide { font-size: 7.5pt; }
    table.medium td, table.wide td { padding: 2pt 1.5pt; }
    table.medium td.num-head, table.wide td.num-head { white-space: normal; }
    .missing { color: #c00000; }
    .flag { color: #b45f06; font-size: 8.5pt; }
    .bad { color: #c00000; font-size: 8.5pt; }
    .cover { text-align: center; padding-top: 70mm; }
    .cover .company { font-size: 26pt; font-weight: bold; }
    .cover .title { font-size: 18pt; margin-top: 14pt; }
    .cover .sub { font-size: 12pt; margin-top: 8pt; }
    .draft-mark { color: #c00000; font-size: 22pt; font-weight: bold; margin-top: 30mm; }
    .draft-title { color: #c00000; font-size: 18pt; font-weight: bold; text-align: center; }
    .draft-heading { font-weight: bold; font-size: 13pt; margin-top: 10pt; }
    .header { text-align: right; font-size: 10pt; border-bottom: 1px solid #555; padding-bottom: 2pt; }
    .header .draft { color: #c00000; font-weight: bold; text-align: center; font-size: 12pt; }
    .footer { text-align: center; font-size: 10pt; }
</style>
</head>
<body>
<htmlpageheader name="main">
    <div class="header">
        @if ($draft)<div class="draft">{{ __('exports.draft.header', [], 'ar') }}</div>@endif
        <div><b>{{ $company }} ({{ $legalForm }})</b></div>
        <div>{{ $t('header', ['date' => $periodEnd]) }}</div>
        <div>{{ $t('amounts') }}</div>
    </div>
</htmlpageheader>
<htmlpagefooter name="main"><div class="footer">- {PAGENO} -</div></htmlpagefooter>

{{-- Cover --}}
<div class="cover">
    <div class="company">{{ $company }}</div>
    <div class="sub">{{ $legalForm }}</div>
    <div class="title">{{ $t('title') }}</div>
    <div class="sub">{{ $t('header', ['date' => $periodEnd]) }}</div>
    <div class="sub">{{ $t('amounts') }}</div>
    <div class="sub">{{ $version }}</div>
    @if ($draft)<div class="draft-mark">{{ __('exports.draft.mark', [], 'ar') }}</div>@endif
</div>

{{-- A draft's own page: what it still lacks --}}
@if ($draft)
    <pagebreak />
    @foreach ($draftLines as [$text, $style])
        <div class="{{ $style === 'title' ? 'draft-title' : ($style === 'heading' ? 'draft-heading' : '') }}">{{ $text }}</div>
    @endforeach
@endif

<pagebreak resetpagenum="1" />
<sethtmlpageheader name="main" value="on" show-this-page="1" />
<sethtmlpagefooter name="main" value="on" />

{{-- The statements, each on its own page --}}
@foreach ($pages as $i => $s)
    @if ($i > 0)<pagebreak orientation="{{ $s['orientation'] }}" />@endif
    @if ($s['kind'] === 'equity')
        <h2 data-statement="changes_in_equity">{{ $s['title'] }}</h2>
        @foreach ($s['years'] as $year)
            <h3>{{ $year['title'] }}</h3>
            <table class="fs equity">
                <thead><tr><th></th>@foreach ($s['columns'] as $c)<th class="num">{{ $c }}</th>@endforeach</tr></thead>
                @foreach ($year['rows'] as $r)
                    <tr class="{{ $r['total'] ? 'total' : '' }}">
                        <td>{{ $r['label'] }}</td>
                        @foreach ($r['cells'] as $c)<td class="num{{ $c['missing'] ? ' missing' : '' }}">{{ $c['text'] }}</td>@endforeach
                    </tr>
                @endforeach
            </table>
        @endforeach
    @else
        <h2 data-statement="{{ $s['key'] }}">{{ $s['title'] }}</h2>
        <table class="fs">
            <thead>
            <tr>
                <th></th>
                @if ($s['notes'])<th class="note-col">{{ $t('col_note') }}</th>@endif
                <th class="num amount-col">{{ $currentLabel }}</th>
                @if ($comparative)<th class="num amount-col">{{ $comparativeLabel }}</th>@endif
            </tr>
            </thead>
            @foreach ($s['rows'] as $r)
                @if ($r['type'] === 'heading')
                    <tr class="heading"><td colspan="{{ 2 + ($s['notes'] ? 1 : 0) + ($comparative ? 1 : 0) }}">{{ $r['label'] }}</td></tr>
                @else
                    <tr class="{{ $r['type'] }}" data-line="{{ $r['key'] }}">
                        <td>{{ $r['label'] }}@if ($r['current']['pending']) <span class="flag">({{ $t('pending') }})</span>@endif @foreach ($r['checks'] as $c) <span class="bad">({{ $c }})</span>@endforeach</td>
                        @if ($s['notes'])<td class="note-no">{{ $r['note'] }}</td>@endif
                        <td class="num{{ $r['current']['missing'] ? ' missing' : '' }}">{{ $r['current']['text'] }}</td>
                        @if ($comparative)<td class="num{{ $r['comparative']['missing'] ? ' missing' : '' }}">{{ $r['comparative']['text'] }}</td>@endif
                    </tr>
                @endif
            @endforeach
        </table>
    @endif
@endforeach

{{-- The notes: on a page of their own, then running on in portrait --}}
@foreach ($notes as $i => $n)
    @if ($i === 0)<pagebreak orientation="P" /><h2>{{ $t('notes') }}</h2>@endif
    <h3 data-note="{{ $n['number'] }}">{{ $n['number'] }}- {{ $n['title'] }}</h3>
    @foreach ($n['blocks'] as $b)
        @if ($b['type'] === 'table')
            <table class="fs {{ $b['size'] }}">
                @foreach ($b['rows'] as $row)
                    <tr class="{{ $row['head'] ? 'head' : ($row['total'] ? 'total' : '') }}">
                        @foreach ($row['cells'] as $c)
                            <td class="{{ $c['num'] ? ($c['amount'] ? 'num' : 'num-head') : '' }}{{ ($c['missing'] ?? false) ? ' missing' : '' }}"@if ($c['num']) style="width: {{ $b['amountWidth'] }}mm"@endif>{{ $c['text'] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @else
            <p class="{{ $b['style'] }}">{{ $b['number'] ? $b['number'].' ' : '' }}@foreach ($b['lines'] as $k => $line)@if ($k > 0)<br>@endif{{ $line }}@endforeach</p>
        @endif
    @endforeach
@endforeach
</body>
</html>
