<?php

namespace Tests\Support;

/**
 * A stand-in for the AI provider, in the wire format of the request (Anthropic or Gemini), used by the contract tests in
 * process (Http::fake) and by tests/Support/contract/fake-ai-server.php over real HTTP. Markers in the uploaded file pick the scenario:
 * «FAIL-AI» → 500 every time (the run fails, the sync endpoint gives 502); «PAUSE-ME» → a mapping with an amount column
 * on the names (structure errors: a queued run pauses, the sync endpoint gives 422). Otherwise: the standard layout, and
 * every requested row is an account «نقدية بالصندوق» at 95. A PDF of last year's statements: the auditor's report on page 1,
 * its statement of financial position on page 2, with cash 80 and capital 80.
 */
final class FakeAi
{
    /** @return array{0: int, 1: array} status, JSON body */
    public static function respond(array $request): array
    {
        $text     = json_encode($request, JSON_UNESCAPED_UNICODE);
        $provider = FakeWire::providerOf($request);
        if (str_contains($text, 'FAIL-AI')) {
            return [500, FakeWire::error($provider, 500)];
        }
        if (FakeWire::toolName($request) === 'submit_structure') {
            $columns = ['account_number' => 'A', 'account_name' => 'B', 'opening_debit' => 'C', 'opening_credit' => 'D',
                'period_debit' => 'E', 'period_credit' => 'F', 'closing_debit' => 'G', 'closing_credit' => 'H'];
            str_contains($text, 'PAUSE-ME') && $columns['closing_debit'] = 'B';   // names as amounts: a structure error

            return [200, FakeWire::answer($provider, 'submit_structure', ['sheet' => 1, 'header_rows' => [1], 'first_data_row' => 2, 'columns' => $columns, 'other_sheets' => []])];
        }
        // Last year's statements from a PDF: the auditor's report on page 1, the statement of financial position on page 2,
        // cash 80 = capital 80 in 2024.
        if (FakeWire::toolName($request) === 'submit_pages') {
            return [200, FakeWire::answer($provider, 'submit_pages', ['pages' => [['page' => 1, 'kind' => 'auditor_report', 'printed_page' => null, 'rotation' => 0, 'notes' => []],
                ['page' => 2, 'kind' => 'financial_position', 'printed_page' => '1', 'rotation' => 0, 'notes' => []]]])];
        }
        // The articles of association: one Saudi and one foreign partner.
        if (FakeWire::toolName($request) === 'submit_registration') {
            return [200, FakeWire::answer($provider, 'submit_registration', ['readable' => true, 'is_commercial_registration' => ! str_contains($text, 'NOT-CR'),
                'registration_number' => '1010765432', 'unified_number' => null, 'company_name' => 'مؤسسة المثال', 'activity' => 'تجارة', 'city' => 'الرياض',
                'issue_date' => '2020-01-01', 'issue_date_hijri' => '1441/05/06', 'expiry_date' => '2099-01-01', 'legal_form' => 'مؤسسة فردية',
                'owner_name' => 'أحمد السالم'])];
        }
        if (FakeWire::toolName($request) === 'submit_partners') {
            return [200, FakeWire::answer($provider, 'submit_partners', ['partners' => [
                ['name' => 'أحمد السالم', 'nationality' => 'سعودي الجنسية', 'shares' => 60, 'share_value' => 1000, 'percentage' => null],
                ['name' => 'جون سميث', 'nationality' => 'بريطاني', 'shares' => 40, 'share_value' => 1000, 'percentage' => null]],
                'capital' => 100000, 'shares_total' => 100, 'share_value' => 1000])];
        }
        if (FakeWire::toolName($request) === 'submit_page') {
            $row = fn ($label, $amount) => ['label' => $label, 'note' => null, 'cells' => [$amount]];

            return [200, FakeWire::answer($provider, 'submit_page', ['blocks' => [
                ['type' => 'heading', 'number' => null, 'text' => 'قائمة المركز المالي', 'columns' => [], 'rows' => []],
                ['type' => 'table', 'number' => null, 'text' => null, 'columns' => [['header' => '2024']],
                    'rows' => [$row('النقد ومعادلات النقد', '80'), $row('إجمالي الأصول', '80'), $row('رأس المال', '80'), $row('إجمالي حقوق الملكية والالتزامات', '80')]],
            ]])];
        }
        if (FakeWire::isWarm($request)) {
            return [200, FakeWire::answer($provider, 'submit_rows', ['rows' => []])];   // cache warming
        }
        $content = FakeWire::userText($request);
        $rows    = [];
        if (str_contains($content, "Rows to classify:\n")) {
            $section = explode("\n\n", substr($content, strpos($content, "Rows to classify:\n") + 18))[0];
            preg_match_all('/^R(\d+) \|/m', $section, $m);
            $rows = array_map(fn ($n) => ['row' => (int) $n, 'row_type' => 'account', 'sub_class' => 'نقدية بالصندوق',
                'confidence' => 95, 'reason' => 'الاسم واضح'], $m[1]);
        }

        return [200, FakeWire::answer($provider, 'submit_rows', ['rows' => $rows, 'adjustment_matches' => [], 'existing_class_matches' => []])];
    }
}
