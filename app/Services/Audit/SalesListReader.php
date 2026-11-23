<?php

namespace App\Services\Audit;

use App\Services\AmountParser;
use App\Services\Statements\PriorPdf\Llm;
use App\Support\Llm\ToolCall;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * A client's sales list, the population of the revenue sample: a sales account statement (كشف حساب المبيعات: its credits are
 * the sales, its debits returns) or a list of sales invoices (its amount before VAT where it has one), from Excel (read by PHP:
 * the header row found by its words, config audit.sampling.columns) or a PDF (copied row by row by the AI, PHP adds it up).
 * Totals, balances brought forward and empty rows are never items.
 *
 * @phpstan-type Row array{date: ?string, number: ?string, party: ?string, amount: float, kind: string}  kind: sale | return
 */
final class SalesListReader
{
    private const NOT_AN_ITEM = '/إجمالي|اجمالي|الإجمالي|المجموع|مجموع|رصيد\s*(سابق|افتتاحي|أول|اول|مرحل|منقول)|الرصيد|ما\s*قبله|total|balance|opening/iu';

    public function __construct(private readonly Llm $llm) {}

    /** @return array{kind: string, columns: array<string, ?string>, rows: list<array>, usage: ?array} */
    public function read(string $path, string $extension): array
    {
        $ext = strtolower($extension);

        return $ext === 'pdf' ? $this->pdf($path) : self::excel($path);
    }

    /** An Excel or CSV sales list: the sheet with the most amounts, its header row by its words, each row after it an item. */
    public static function excel(string $path): array
    {
        $book = \App\Support\SpreadsheetFile::load($path);
        $best = null;
        foreach ($book->getAllSheets() as $sheet) {
            $grid = $sheet->toArray(null, true, false, false);
            $read = self::grid($grid);
            if ($read !== null && ($best === null || count($read['rows']) > count($best['rows']))) {
                $best = $read;
            }
        }
        if ($best === null) {
            throw new RuntimeException('no_header');
        }

        return $best + ['usage' => null];
    }

    /** A sheet's rows read as a sales list, or null when no row reads as its header. */
    public static function grid(array $grid): ?array
    {
        $patterns = (array) config('audit.sampling.columns');
        $headerAt = null;
        $map = [];
        foreach (array_slice($grid, 0, 40, true) as $r => $cells) {
            $found = [];
            foreach ($cells as $c => $v) {
                $text = trim((string) $v);
                if ($text === '' || is_numeric($text) || mb_strlen($text) > 60) {
                    continue;
                }
                foreach ($patterns as $key => $pattern) {
                    if (! isset($found[$key]) && preg_match($pattern, $text) === 1) {
                        $found[$key] = $c;
                        break;
                    }
                }
            }
            $hasAmount = isset($found['net']) || isset($found['credit']) || isset($found['amount']);
            if ($hasAmount && count($found) >= 2 && count($found) > count($map)) {
                [$headerAt, $map] = [$r, $found];
            }
        }
        if ($headerAt === null) {
            return null;
        }
        $statement = isset($map['credit']);
        $amountCol = $map['net'] ?? ($statement ? $map['credit'] : $map['amount']);
        $skip = array_filter([$map['date'] ?? null, $map['number'] ?? null, $amountCol, $map['debit'] ?? null], fn ($c) => $c !== null);

        // A file of several accounts' statements (a block per account under «: الحساب», كشوفات حساب الحسابات): every account is
        // kept with its entries (their debit and credit), so a sample can be drawn on any account of the trial balance; the
        // revenue accounts are named (their credits sales, their debits returns; the sales returns accounts' debits returns) for
        // the reconciliation with the revenue.
        $blocks = self::accountBlocks($grid);
        $accounts = [];
        if (count(array_unique(array_column($blocks, 'number'))) >= 2) {
            foreach ($blocks as $b) {
                $accounts[$b['number']] ??= ['number' => $b['number'], 'name' => $b['name'], 'role' => self::salesRole($b['name']), 'rows' => 0];
            }
            // A chart numbered by class (revenue under 4): the sales and returns accounts are the revenue class's only.
            if (array_filter($accounts, fn ($a) => $a['role'] === 'sales' && str_starts_with($a['number'], '4')) !== []) {   // other revenue under 4 too
                foreach ($accounts as $n => $a) {
                    if ($a['role'] !== null && ! str_starts_with($a['number'], '4')) {
                        $accounts[$n]['role'] = null;
                    }
                }
            }
        }
        $blockAt = function (int $r) use ($blocks): ?array {
            $found = null;
            foreach ($blocks as $b) {
                if ($b['row'] > $r) {
                    break;
                }
                $found = $b;
            }

            return $found;
        };

        $rows = [];
        $max = (int) config('audit.sampling.max_rows', 20000);
        foreach (array_slice($grid, $headerAt + 1, null, true) as $r => $cells) {
            $account = null;
            if ($accounts !== []) {
                $account = $accounts[$blockAt($r)['number'] ?? ''] ?? null;
                if ($account === null) {
                    continue;   // before the first account
                }
            }
            // A row is judged by every word in it (a description may sit away from its heading's column): an opening balance,
            // an account's total and the amount in words («فقط … لا غير») are never items.
            $texts = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $cells), fn ($t) => $t !== '' && ! is_numeric($t)));
            if (preg_match(self::NOT_AN_ITEM, implode(' ', $texts)) === 1 || preg_grep('/^فقط\s|لا\s*غير$/u', $texts) !== []) {
                continue;
            }
            // The row's two sides as printed (an account statement), for a sample on any account by its nature.
            $debit = $statement && isset($map['debit']) ? round(abs((float) (AmountParser::parse($cells[$map['debit']] ?? null) ?? 0)), 2) : null;
            $credit = $statement ? round(abs((float) (AmountParser::parse($cells[$map['credit']] ?? null) ?? 0)), 2) : null;
            $amount = AmountParser::parse($cells[$amountCol] ?? null);
            $kind = 'sale';
            if ($statement && ($amount === null || abs($amount) < 0.005) && isset($map['debit'])) {
                $amount = AmountParser::parse($cells[$map['debit']] ?? null);   // a debit to sales: a return
                $kind = 'return';
            }
            if ($amount === null || abs($amount) < 0.005) {
                continue;
            }
            if ($amount < 0) {
                $kind = $kind === 'sale' ? 'return' : 'sale';
            }
            if ($account !== null && $account['role'] === null) {
                $kind = 'entry';   // not revenue: an entry of the account, sampled by its own side
            } elseif ($account !== null && $account['role'] === 'returns') {
                $kind = 'return';   // the returns account: a debit is a return, a credit a return reversed (taken off)
                $amount = $statement && isset($map['debit']) ? (float) (AmountParser::parse($cells[$map['debit']] ?? null) ?? 0) - (float) (AmountParser::parse($cells[$amountCol] ?? null) ?? 0) : $amount;
                if (abs($amount) < 0.005 || $amount < 0) {
                    continue;
                }
            }
            $party = isset($map['party']) ? self::text($cells[$map['party']] ?? null) : null;
            if ($party === null) {
                // The description where it sits: the longest words of the row outside the date, number and amounts.
                $others = array_filter(array_map(fn ($c) => in_array($c, $skip, true) ? '' : trim((string) $cells[$c]), array_keys($cells)), fn ($t) => $t !== '' && ! is_numeric($t));
                usort($others, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                $party = self::text($others[0] ?? null);
            }
            $rows[] = ['date' => isset($map['date']) ? self::date($cells[$map['date']] ?? null) : null,
                'number' => isset($map['number']) ? self::text($cells[$map['number']] ?? null) : null,
                'party' => $party, 'amount' => round(abs($amount), 2), 'kind' => $kind, 'account' => $account['number'] ?? null, 'debit' => $debit, 'credit' => $credit];
            if ($account !== null) {
                $accounts[$account['number']]['rows']++;
            }
            if (count($rows) >= $max) {
                break;
            }
        }
        $header = $grid[$headerAt];

        return ['kind' => $statement ? 'statement' : 'invoices', 'rows' => $rows,
            // The accounts of a file of several accounts: each with whether it was taken (sales, returns) or left out (null).
            'accounts' => array_values($accounts),
            'columns' => array_map(fn ($c) => self::text($header[$c] ?? null), array_filter(['date' => $map['date'] ?? null, 'number' => $map['number'] ?? null,
                'party' => $map['party'] ?? null, 'amount' => $amountCol], fn ($c) => $c !== null))];
    }

    /**
     * The account blocks of a statements file: each row that names an account («: الحساب» with its number and name), in order.
     *
     * @return list<array{row: int, number: string, name: string}>
     */
    private static function accountBlocks(array $grid): array
    {
        $out = [];
        foreach ($grid as $r => $cells) {
            $texts = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $cells), fn ($t) => $t !== ''));
            if (preg_grep('/^[:：]?\s*(رقم\s+)?الحساب\s*[:：]?$/u', $texts) === []) {
                continue;
            }
            $number = preg_grep('/^\d{4,}$/', array_map(fn ($t) => AmountParser::asciiDigits($t), $texts));
            $names = array_filter($texts, fn ($t) => ! preg_match('/^[:：]?\s*(رقم\s+)?الحساب\s*[:：]?$/u', $t) && ! preg_match('/^\d+$/', AmountParser::asciiDigits($t)));
            usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            if ($number !== [] && $names !== []) {
                $out[] = ['row' => $r, 'number' => (string) reset($number), 'name' => (string) $names[0]];
            }
        }

        return $out;
    }

    /** What an account is to the revenue sample, by its name: `sales`, `returns` (sales returns, allowances, discounts given), or null. */
    public static function salesRole(string $name): ?string
    {
        if (preg_match('/مرتجع|مردود|مسموحات|خصم\s*(مسموح|ممنوح)/u', $name) === 1 && preg_match('/مشتريات|مكتسب/u', $name) !== 1) {
            return 'returns';
        }
        // Other revenue (إيرادات أخرى): a population of its own, sampled on its own (not the sales).
        if (preg_match('/(إيراد|ايراد)(ات)?\s*(أخرى|اخرى|متنوع)/u', $name) === 1 && preg_match('/خصم|تسوي|فوائد|ضريب/u', $name) !== 1) {
            return 'other_revenue';
        }
        // Not revenue though sales are named: costs, staff (a sales manager's receivable), the output VAT on sales, other income.
        if (preg_match('/تكلف|مصروف|مصاريف|حوافز|عمول|مناديب|رواتب|راتب|خصم\s*مكتسب|تسوي|أخرى|اخرى|غير\s*تشغيلي|فوائد|عوائد|أرباح|ارباح|ضريب|قيم[ةه]\s*(ال)?مضاف|موظف|ذمم|مدير|مستحق|عهد|سلف|دائن|مدين/u', $name) === 1) {
            return null;
        }

        return preg_match('/مبيعات|إيرادات|ايرادات|إيراد|ايراد|revenue|sales/iu', $name) === 1 ? 'sales' : null;
    }

    /** A PDF sales list, copied by the AI row by row (its words and figures as printed); PHP places the amounts and adds them up. */
    private function pdf(string $path): array
    {
        $answer = $this->llm->ask($path, 'sales-list-1', 0, 0, fn () => new ToolCall([['text' => self::RULES]], self::TOOL,
            'Copy the sales in this document.', 32000, false, (string) file_get_contents($path)));
        $in = $answer->input;
        if (! ($in['is_sales_list'] ?? false)) {
            throw new RuntimeException('not_sales');
        }
        $rows = [];
        foreach ((array) ($in['rows'] ?? []) as $r) {
            $amount = AmountParser::parse($r['amount'] ?? null);
            if ($amount === null || abs($amount) < 0.005 || preg_match(self::NOT_AN_ITEM, (string) (($r['number'] ?? '').' '.($r['party'] ?? ''))) === 1) {
                continue;
            }
            $rows[] = ['date' => self::date($r['date'] ?? null), 'number' => self::text($r['number'] ?? null), 'party' => self::text($r['party'] ?? null),
                'amount' => round(abs($amount), 2), 'kind' => ($r['kind'] ?? 'sale') === 'return' || $amount < 0 ? 'return' : 'sale'];
        }

        return ['kind' => ($in['kind'] ?? 'invoices') === 'statement' ? 'statement' : 'invoices', 'columns' => [], 'accounts' => [], 'rows' => $rows, 'usage' => $answer->usage];
    }

    private static function text(mixed $v): ?string
    {
        $t = trim((string) $v);

        return $t === '' ? null : mb_substr($t, 0, 120);
    }

    /** A date as Y-m-d where it reads as one (an Excel serial number too), else as written. */
    private static function date(mixed $v): ?string
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        $s = AmountParser::asciiDigits(trim((string) $v));
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})#', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})#', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        return mb_substr($s, 0, 20);
    }

    private const TOOL = [
        'name' => 'submit_sales', 'description' => 'The sales listed in the document, row by row.', 'strict' => true,
        'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['is_sales_list', 'kind', 'rows'],
            'properties' => [
                'is_sales_list' => ['type' => 'boolean'],
                'kind' => ['type' => 'string', 'enum' => ['statement', 'invoices']],
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['date', 'number', 'party', 'amount', 'kind'],
                    'properties' => [
                        'date' => ['type' => ['string', 'null']], 'number' => ['type' => ['string', 'null']], 'party' => ['type' => ['string', 'null']],
                        'amount' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['sale', 'return']],
                    ]]],
            ]],
    ];

    private const RULES = <<<'TXT'
        You are given a document, usually Arabic and Saudi: a sales account statement (كشف حساب المبيعات / الإيرادات) or a list of
        sales invoices (فواتير المبيعات), often several pages. Copy every sale in it, row by row, exactly as printed, never corrected,
        never added up:
        - is_sales_list: false when the document lists no sales at all.
        - kind: «statement» for an account statement (columns debit / credit / balance), «invoices» for a list of invoices.
        - rows: one per sale or return line, in the document's order, on every page. date as printed; number: the invoice or entry
          number; party: the customer or the description; amount: the sale's amount as printed (for a statement the credit, for an
          invoice its amount before VAT when the list prints one, else its total); kind: «return» for a return or a debit to sales,
          else «sale».
        Leave out totals, page totals, balances brought forward and opening balances: they are not sales.
        TXT;
}
