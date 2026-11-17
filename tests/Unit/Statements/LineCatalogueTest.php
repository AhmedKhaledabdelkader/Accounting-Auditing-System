<?php

namespace Tests\Unit\Statements;

use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Services\Statements\LineCatalogue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** config/financial_statements.php: complete over the taxonomy, consistent with itself and with the template. */
class LineCatalogueTest extends TestCase
{
    private function catalogue(): LineCatalogue
    {
        return new LineCatalogue;
    }

    public function test_every_taxonomy_sub_class_has_a_rule(): void
    {
        $catalogue = $this->catalogue();
        foreach (app(TaxonomyRepositoryInterface::class)->map() as $sub => [, $main]) {
            $this->assertNotNull($catalogue->rule($sub, $main), "{$main} / {$sub}");
        }
    }

    public function test_every_rule_points_to_a_known_line_or_function(): void
    {
        $catalogue = $this->catalogue();
        $rules = $catalogue->rules();
        foreach (['sub_classes', 'main_classes'] as $table) {
            foreach ($rules[$table] as $class => $rule) {
                $rule = is_string($rule) ? ['line' => $rule] : $rule;
                foreach (array_merge(isset($rule['line']) ? [$rule['line']] : [], $rule['choose'] ?? []) as $line) {
                    $this->assertTrue($catalogue->has($line), "{$class} → {$line}");
                }
                if (isset($rule['function'])) {
                    $this->assertTrue($catalogue->has($catalogue->functionLine($rule['function'])), $class);
                }
                $this->assertTrue(isset($rule['line']) || isset($rule['function']) || isset($rule['choose']) || isset($rule['partners_current']), $class);
            }
        }
        foreach ($rules['side_rules'] as $rule) {
            $this->assertTrue($catalogue->has($rule['line']), $rule['line']);
            $this->assertContains($rule['main_class'], app(TaxonomyRepositoryInterface::class)->mainClasses());
        }
        foreach (['liability', 'equity'] as $choice) {
            $this->assertTrue($catalogue->has($catalogue->partnersCurrentLine($choice)));
        }
        // Rules name taxonomy classes that exist.
        $taxonomy = app(TaxonomyRepositoryInterface::class);
        $this->assertSame([], array_diff(array_keys($rules['main_classes']), $taxonomy->mainClasses()));
        $this->assertSame([], array_diff(array_keys($rules['sub_classes']), $taxonomy->subClasses()));
    }

    /** The catalogue's lines are exactly the template's input lines (keyed rows without formulas). */
    public function test_lines_match_the_template_keys(): void
    {
        $book = IOFactory::load(resource_path('templates/'.$this->catalogue()->version.'/statements.xlsx'));
        $keys = [];
        foreach (['المركز المالى', 'قائمة الدخل الشامل'] as $name) {
            $sheet = $book->getSheetByName($name);
            foreach ($sheet->getRowIterator() as $r) {
                $n   = $r->getRowIndex();
                $key = $sheet->getCell("J{$n}")->getValue();
                if (is_string($key) && preg_match('/^(sofp|is)\.[a-z_]+$/', $key) && $sheet->getCell("E{$n}")->getValue() === null && ! str_starts_with($key, 'is.oci_') && ! str_ends_with($key, '.footer')) {
                    $keys[] = $key;
                }
            }
        }
        sort($keys);
        $lines = array_keys($this->catalogue()->lines());
        sort($lines);
        $this->assertSame($keys, $lines);
    }

    public static function functionLabels(): array
    {
        return [
            'cost'            => ['تكلفة المبيعات', 'cost'],
            'cost of revenue' => ['تكاليف الإيرادات', 'cost'],
            'finance first'   => ['تكاليف التمويل', 'finance'],
            'interest'        => ['فوائد بنكية', 'finance'],
            'g&a'             => ['مصاريف إدارية وعمومية', 'general_admin'],
            'g&a reversed'    => ['المصروفات العمومية والادارية', 'general_admin'],
            'selling'         => ['مصروفات بيع وتوزيع', 'selling'],
            'marketing'       => ['مصاريف تسويقية', 'selling'],
            'depreciation'    => ['الإهلاكات', 'depreciation'],
            'amortisation'    => ['اطفاء', 'amortisation'],   // its own line (R5)
            'not a function'  => ['المخزون', null],
            'sales revenue'   => ['المبيعات', null],
            'empty'           => ['', null],
        ];
    }

    /** The statements' rows follow the template: same keys in the same order, and each total adds the rows its formula adds. */
    public function test_statements_follow_the_template_rows_and_totals(): void
    {
        $book = IOFactory::load(resource_path('templates/sa-ifrs-smes-3/statements.xlsx'));
        $placed = [];
        foreach (['financial_position' => 'المركز المالى', 'comprehensive_income' => 'قائمة الدخل الشامل'] as $name => $sheetName) {
            $sheet = $book->getSheetByName($sheetName);
            $keyOf = $template = [];
            for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
                $key = (string) $sheet->getCell("J{$r}")->getValue();
                if (str_starts_with($key, 'sofp.') || str_starts_with($key, 'is.')) {
                    $keyOf[$r] = $key;
                    $template[$key] = (string) $sheet->getCell("E{$r}")->getValue();
                }
            }
            $structure = $this->catalogue()->statement($name);
            $keys = array_column(array_filter($structure, fn ($i) => $i['type'] !== 'heading'), 'key');
            $this->assertSame($keys, array_values(array_intersect(array_keys($template), $keys)), "{$name}: order");
            // Rows of the template left out: the footer and the balance check only.
            $left = array_values(array_diff(array_keys($template), $keys));
            $this->assertSame([], array_values(array_diff($left, ['is.footer', 'sofp.footer', 'sofp.check_balance'])), $name);

            foreach (array_filter($structure, fn ($i) => $i['type'] === 'total') as $total) {
                preg_match_all('/E(\d+)(?::E(\d+))?/', $template[$total['key']], $m, PREG_SET_ORDER);
                $parts = [];
                foreach ($m as $ref) {
                    foreach (range((int) $ref[1], (int) ($ref[2] ?? $ref[1])) as $r) {
                        if (isset($keyOf[$r])) {
                            $parts[] = $keyOf[$r];
                        }
                    }
                }
                $this->assertSame($parts, $total['sum'], $total['key']);
            }
            $placed = [...$placed, ...array_column(array_filter($structure, fn ($i) => $i['type'] === 'line' && ! $i['input']), 'key')];
        }
        // Every line of the catalogue is on exactly one statement.
        sort($placed);
        $lines = array_keys($this->catalogue()->lines());
        sort($lines);
        $this->assertSame($lines, $placed);
    }

    /** The cash flow and the changes in equity follow the template too: rows, order, totals, columns (PR 10). */
    public function test_cash_flow_and_changes_in_equity_follow_the_template(): void
    {
        $book  = IOFactory::load(resource_path('templates/sa-ifrs-smes-3/statements.xlsx'));
        $sheet = $book->getSheetByName('قائمة التدفقات النقدية');
        $keyOf = $template = [];
        for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
            $key = (string) $sheet->getCell("J{$r}")->getValue();
            if (str_starts_with($key, 'cf.')) {
                $keyOf[$r] = $key;
                $template[$key] = (string) $sheet->getCell("E{$r}")->getValue();
            }
        }
        $structure = $this->catalogue()->statement('cash_flows');
        $keys = array_column(array_filter($structure, fn ($i) => $i['type'] !== 'heading'), 'key');
        $this->assertSame(array_values(array_diff(array_keys($template), ['cf.footer', 'cf.check_sofp_cash', 'cf.check_difference'])), $keys);
        foreach (array_filter($structure, fn ($i) => $i['type'] === 'total') as $total) {
            preg_match_all('/E(\d+)(?::E(\d+))?/', $template[$total['key']], $m, PREG_SET_ORDER);
            $parts = [];
            foreach ($m as $ref) {
                foreach (range((int) $ref[1], (int) ($ref[2] ?? $ref[1])) as $r) {
                    if (isset($keyOf[$r])) {
                        $parts[] = $keyOf[$r];
                    }
                }
            }
            $this->assertSame($parts, $total['sum'], $total['key']);
        }

        $soce   = $book->getSheetByName('قائمة التغيرات');
        $cfg    = $this->catalogue()->changesInEquity();
        $rows   = [];
        $header = [];
        for ($r = 1; $r <= $soce->getHighestRow(); $r++) {
            if (preg_match('/^soce\.current\.(\w+)$/', (string) $soce->getCell("A{$r}")->getValue(), $m)) {
                $rows[] = $m[1];
            }
        }
        foreach (['E', 'G', 'I', 'K', 'M', 'O'] as $c) {
            $header[] = $soce->getCell("{$c}5")->getValue();
        }
        $this->assertSame($rows, array_keys($cfg['rows']));
        $this->assertSame($header, array_keys($cfg['columns']));
    }

    #[DataProvider('functionLabels')]
    public function test_function_from_the_client_label(string $label, ?string $function): void
    {
        $this->assertSame($function, $this->catalogue()->functionOf($label));
    }

    public function test_a_class_respelled_after_the_run_keeps_its_rule(): void
    {
        // The accruals class renamed to fix its spacing, and a sub class's «ه» written «ة»: the run's names, not the rules'.
        $catalogue = $this->catalogue();
        $this->assertSame(['line' => 'sofp.accruals_other_payables', 'source' => 'main_class'],
            $catalogue->rule('عهد وسلف دائنة', 'مصروفات مستحقة و ارصدة دائنة اخرى'));
        $this->assertSame('sofp.accruals_other_payables', $catalogue->rule('مصروفات مستحقة', 'مصروفات  مستحقة و أرصدة دائنة أخرى')['line']);
        $this->assertSame('sofp.bank_overdraft', $catalogue->sideRule('النقد وما في حكمه', 'credit'));
        $this->assertNull($catalogue->rule('حساب لا يعرفه أحد', 'تصنيف لا يعرفه أحد'));
    }

    public function test_two_rules_spelled_alike_are_never_guessed_between(): void
    {
        $version = config('financial_statements.template_version');
        config(["financial_statements.templates.{$version}.main_classes.مصروفات مستحقه و ارصده دائنه اخري" => 'sofp.trade_payables']);

        $this->assertNull($this->catalogue()->rule(null, 'مصروفات مستحقة وارصدة دائنة اخرى'));
    }

    public function test_unknown_version_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LineCatalogue('no-such-version');
    }
}
