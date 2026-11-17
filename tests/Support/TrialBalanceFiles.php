<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait TrialBalanceFiles
{
    protected const FULL_HEADER = [
        'رقم الحساب', 'اسم الحساب',
        'مدين اول المدة', 'دائن اول المدة',
        'مدين الحركة', 'دائن الحركة',
        'مدين اخر المدة', 'دائن اخر المدة',
    ];

    /* ---------- files ---------- */

    /** Real .xlsx built with PhpSpreadsheet. $sheets = [title => rows]. */
    /**
     * The same sheets always give the same bytes: an xlsx stores its save time, so two saves a second apart would hash
     * differently (duplicate-upload checks compare hashes).
     */
    protected function workbook(array $sheets, string $name = 'trial-balance.xlsx'): UploadedFile
    {
        static $saved = [];
        $key = md5(serialize($sheets));

        if (! isset($saved[$key]) || ! is_file($saved[$key])) {
            $book = new Spreadsheet;
            $book->removeSheetByIndex(0);
            foreach ($sheets as $title => $rows) {
                $sheet = $book->createSheet();
                $sheet->setTitle((string) $title);
                $sheet->fromArray($rows, null, 'A1', true);
            }
            $saved[$key] = tempnam(sys_get_temp_dir(), 'tb').'.xlsx';
            (new Xlsx($book))->save($saved[$key]);
        }

        $path = tempnam(sys_get_temp_dir(), 'tb').'.xlsx';
        copy($saved[$key], $path);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** One sheet with cell styles set by $style(sheet) (indentation, …); not cached. */
    protected function styledTrialBalance(array $accounts, Closure $style): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([self::FULL_HEADER, ...$accounts], null, 'A1', true);
        $style($sheet);
        $path = tempnam(sys_get_temp_dir(), 'tb').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'trial-balance.xlsx', null, null, true);
    }

    protected function xlsxUpload(array $rows): UploadedFile
    {
        return $this->workbook(['Sheet1' => $rows]);
    }

    /** Standard layout: header in row 1, accounts from row 2. Each account: [number, name, oD, oC, pD, pC, cD, cC]. */
    protected function trialBalance(array $accounts): UploadedFile
    {
        return $this->xlsxUpload([self::FULL_HEADER, ...$accounts]);
    }

    protected function csvUpload(string $content, string $name = 'trial-balance.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /* ---------- AI structure / labels ---------- */

    protected function standardStructure(array $overrides = []): array
    {
        return array_replace_recursive([
            'sheet'          => 1,
            'header_rows'    => [1],
            'first_data_row' => 2,
            'columns'        => [
                'account_number' => 'A', 'account_name' => 'B',
                'opening_debit'  => 'C', 'opening_credit' => 'D',
                'period_debit'   => 'E', 'period_credit'  => 'F',
                'closing_debit'  => 'G', 'closing_credit' => 'H',
            ],
        ], $overrides);
    }

    protected function account(int $row, string $subClass, int $confidence = 95, string $reason = 'الاسم واضح'): array
    {
        return ['row' => $row, 'row_type' => 'account', 'sub_class' => $subClass, 'confidence' => $confidence, 'reason' => $reason];
    }

    protected function rowOfType(int $row, string $type): array
    {
        return ['row' => $row, 'row_type' => $type];
    }

    /** An answer with this tool call, in the wire format of the provider in use. */
    protected function toolResponse(string $tool, array $input): array
    {
        return FakeWire::answer(config('classification.provider'), $tool, $input);
    }

    /** The Http::fake URL pattern of the AI provider in use. */
    protected function aiUrl(): string
    {
        return FakeWire::URLS[config('classification.provider')];
    }

    /** The user message of an AI request, in any provider's format. */
    protected function userText(Request $request): string
    {
        return FakeWire::userText($request->data());
    }

    /** The system prompt of an AI request (all parts, or part $i). */
    protected function systemText(Request $request, ?int $i = null): string
    {
        $parts = FakeWire::systemParts($request->data());

        return $i === null ? implode("\n\n", $parts) : $parts[$i];
    }

    /** The tool's input schema as sent (Gemini's after its conversion). */
    protected function toolSchema(Request $request): array
    {
        return FakeWire::schema($request->data());
    }

    /** Row numbers Laravel asked the AI to label in this request (the "Rows to classify:" section). */
    protected function requestedRows(Request $request): array
    {
        $content = FakeWire::userText($request->data());
        if (! str_contains($content, "Rows to classify:\n")) {
            return [];   // warm or matching call
        }
        $section = substr($content, strpos($content, "Rows to classify:\n") + strlen("Rows to classify:\n"));
        $section = explode("\n\n", $section)[0];
        preg_match_all('/^R(\d+) \|/m', $section, $m);

        return array_map('intval', $m[1]);
    }

    /** The cached, file-level part of a classification request. */
    protected function fileBlock(Request $request): string
    {
        return FakeWire::systemParts($request->data())[1];
    }

    protected function isStructureCall(Request $request): bool
    {
        return FakeWire::toolName($request->data()) === 'submit_structure';
    }

    /** The dedicated call that matches adjustment lines / existing classification values (no rows). */
    protected function isMatchCall(Request $request): bool
    {
        return str_contains(FakeWire::userText($request->data()), 'No rows to classify. Return only ');
    }

    /** The minimal call that writes the prompt cache before several requests (its answer is ignored). */
    protected function isWarmCall(Request $request): bool
    {
        return FakeWire::isWarm($request->data());
    }

    /**
     * Fakes both AI calls.
     *  $structure: array, or Closure(int $attempt, Request): array
     *  $labels:    list of labels, or Closure(int $call, array $requestedRows, Request): list
     *              Only labels for rows requested in each call are returned (so batching works).
     *  $matches:   adjustment_matches returned with every classification call
     */
    protected function fakeLlm(array|Closure $structure, array|Closure $labels, array $matches = []): void
    {
        $structureCalls = 0;
        $rowCalls       = 0;

        Http::fake([$this->aiUrl() => function (Request $request) use ($structure, $labels, $matches, &$structureCalls, &$rowCalls) {
            if ($this->isStructureCall($request)) {
                $structureCalls++;
                $input = $structure instanceof Closure ? $structure($structureCalls, $request) : $structure;

                return Http::response($this->toolResponse('submit_structure', $input));
            }
            if ($this->isWarmCall($request)) {
                return Http::response($this->toolResponse('submit_rows', ['rows' => []]));
            }

            $rowCalls++;
            $requested = $this->requestedRows($request);
            $all       = $labels instanceof Closure ? $labels($rowCalls, $requested, $request) : $labels;
            $rows      = array_values(array_filter($all, fn ($l) => in_array($l['row'], $requested, true)));

            return Http::response($this->toolResponse('submit_rows', ['rows' => $rows, 'adjustment_matches' => $matches]));
        }]);
    }
}
