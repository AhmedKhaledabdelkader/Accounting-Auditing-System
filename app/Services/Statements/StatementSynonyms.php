<?php

namespace App\Services\Statements;

use App\Models\StatementSynonym;
use App\Support\ImportNormalizer;

/**
 * Other wordings of the statements' lines and totals (PR 17), for reading files of issued statements: a row whose label
 * has a synonym's wording (normalised: spelling variants, «ال», «و», word order don't matter) is placed on its line, as
 * an exact match on the line's own label is. The list lives in the database and the user edits it (/statement-synonyms);
 * INITIAL only starts it. No AI: a wording matches or it doesn't.
 */
final class StatementSynonyms
{
    public const STATEMENTS = ['sofp' => 'financial_position', 'is' => 'comprehensive_income', 'cf' => 'cash_flows'];

    /** Common wordings of issued Saudi statements, line or total key → wordings. The starting list only. */
    private const INITIAL = [
        'sofp.ppe' => ['الأصول الثابتة', 'الممتلكات والمعدات', 'ممتلكات ومعدات، صافي'],
        'sofp.inventory' => ['البضاعة', 'مخزون البضاعة'],
        'sofp.trade_receivables' => ['ذمم مدينة', 'المدينون التجاريون', 'العملاء'],
        'sofp.prepayments_other_receivables' => ['مصروفات مدفوعة مقدماً وأرصدة مدينة أخرى', 'مدفوعات مقدمة وذمم مدينة أخرى', 'مدينون وأرصدة مدينة أخرى'],
        'sofp.due_from_related_parties' => ['مستحق من جهات ذات علاقة'],
        'sofp.cash' => ['نقدية وأرصدة لدى البنوك', 'النقد والأرصدة لدى البنوك', 'النقدية وما في حكمها', 'النقد وما في حكمه', 'النقد لدى البنوك وفي الصندوق', 'النقدية بالصندوق ولدى البنوك'],
        'sofp.total_current_assets' => ['مجموع الأصول المتداولة'],
        'sofp.total_non_current_assets' => ['مجموع الأصول غير المتداولة'],
        'sofp.total_assets' => ['مجموع الأصول', 'مجموع الموجودات', 'إجمالي الموجودات'],
        'sofp.share_capital' => ['رأس المال المدفوع'],
        'sofp.retained_earnings' => ['الأرباح المبقاة', 'الأرباح المحتجزة', 'الخسائر المتراكمة', 'أرباح مبقاة (خسائر متراكمة)'],
        'sofp.total_equity' => ['مجموع حقوق الملكية', 'إجمالي حقوق الشركاء'],
        'sofp.employee_benefits' => ['مخصص مكافأة نهاية الخدمة', 'مكافأة نهاية الخدمة', 'التزامات منافع الموظفين'],
        'sofp.total_non_current_liabilities' => ['إجمالي المطلوبات غير المتداولة', 'مجموع الالتزامات غير المتداولة'],
        'sofp.trade_payables' => ['ذمم دائنة', 'الدائنون التجاريون', 'الموردون'],
        'sofp.accruals_other_payables' => ['مصروفات مستحقة وأرصدة دائنة أخرى', 'مستحقات وأرصدة دائنة أخرى', 'دائنون وأرصدة دائنة أخرى'],
        'sofp.due_to_related_parties' => ['مستحق لجهات ذات علاقة'],
        'sofp.zakat_provision' => ['الزكاة المستحقة'],
        'sofp.total_current_liabilities' => ['إجمالي المطلوبات المتداولة', 'مجموع الالتزامات المتداولة'],
        'sofp.total_liabilities' => ['إجمالي المطلوبات', 'مجموع الالتزامات'],
        'sofp.total_equity_and_liabilities' => ['مجموع حقوق الملكية والالتزامات', 'إجمالي المطلوبات وحقوق الملكية', 'إجمالي الالتزامات وحقوق الملكية'],
        'is.revenue' => ['الإيرادات', 'المبيعات', 'صافي المبيعات', 'الإيرادات من العقود مع العملاء'],
        'is.cost_of_revenue' => ['تكاليف التشغيل', 'تكلفة التشغيل', 'تكلفة الإيرادات', 'تكلفة المبيعات', 'تكلفة البضاعة المباعة'],
        'is.gross_profit' => ['مجمل الربح', 'إجمالي الربح'],
        'is.selling_marketing' => ['مصاريف بيع وتوزيع', 'مصروفات البيع والتوزيع'],
        'is.general_admin' => ['مصاريف عمومية وإدارية', 'مصروفات عمومية وإدارية'],
        'is.depreciation' => ['الإهلاك', 'الاستهلاك', 'مصروف الاستهلاك', 'إهلاك الموجودات الثابتة', 'استهلاك ممتلكات وآلات ومعدات', 'إهلاك ممتلكات وآلات ومعدات'],
        'is.amortisation' => ['الإطفاء', 'إطفاء أصول أخرى', 'إطفاء الأصول غير الملموسة', 'إطفاء موجودات غير ملموسة'],
        'is.operating_profit' => ['الربح من العمليات', 'ربح التشغيل', 'الدخل من العمليات الرئيسية'],
        'is.finance_cost' => ['أعباء تمويلية', 'مصاريف تمويلية', 'تكلفة التمويل'],
        'is.profit_before_zakat' => ['صافي الخسارة قبل الزكاة', 'صافي خسارة قبل الزكاة', 'صافي الربح (الخسارة) قبل الزكاة', 'صافي الربح قبل الزكاة', 'الربح قبل الزكاة', 'صافي الدخل قبل الزكاة'],
        'is.zakat' => ['الزكاة', 'مصروف الزكاة'],
        'is.net_profit' => ['صافي خسارة السنة', 'صافي الخسارة للسنة', 'صافي ربح (خسارة) السنة', 'صافي الربح للسنة', 'صافي ربح السنة', 'صافي الدخل للسنة', 'ربح السنة'],
        'is.total_comprehensive_income' => ['إجمالي الخسائر الشاملة للسنة', 'إجمالي الخسارة الشاملة للسنة', 'إجمالي الدخل الشامل للسنة', 'إجمالي الدخل الشامل'],
        'cf.profit_before_zakat' => ['صافي ربح (خسارة) العام قبل الزكاة', 'صافي الربح قبل الزكاة', 'صافي الخسارة قبل الزكاة'],
        'cf.depreciation_ppe' => ['الاستهلاك', 'استهلاكات'],
        'cf.eosb_charge' => ['مخصص مكافأة نهاية الخدمة المكون'],
        'cf.net_operating' => ['صافي التدفق النقدي من النشاط التشغيلي', 'صافي التدفق النقدي من الأنشطة التشغيلية', 'صافي النقد من الأنشطة التشغيلية', 'صافي النقد الناتج من الأنشطة التشغيلية'],
        'cf.ppe_purchases' => ['شراء ممتلكات وآلات ومعدات', 'إضافات ممتلكات وآلات ومعدات'],
        'cf.net_investing' => ['صافي النقد المستخدم في الأنشطة الاستثمارية', 'صافي النقد من الأنشطة الاستثمارية'],
        'cf.net_financing' => ['صافي التدفق النقدي من الأنشطة التمويلية', 'صافي النقد من الأنشطة التمويلية', 'صافي النقد المستخدم في الأنشطة التمويلية'],
        'cf.net_change' => ['صافي التغير في النقد', 'صافي التغير في النقد وما في حكمه', 'صافي الزيادة (النقص) في النقد وما في حكمه'],
        'cf.opening_cash' => ['رصيد النقد في بداية السنة', 'رصيد النقدية في بداية السنة', 'النقد وما في حكمه في بداية السنة', 'النقدية في أول السنة'],
        'cf.closing_cash' => ['رصيد النقد في نهاية السنة', 'رصيد النقدية في نهاية السنة', 'النقد وما في حكمه في نهاية السنة', 'النقدية في آخر السنة'],
    ];

    /** The statement a line or total belongs to, by its key. */
    public static function statementOf(string $key): ?string
    {
        return self::STATEMENTS[explode('.', $key)[0]] ?? null;
    }

    /**
     * Words that name the same thing in issued statements (R6), read as the template's: the file's word on the left.
     * Applied to every wording, the lines' own and the synonyms', so «إجمالي الموجودات» is «إجمالي الأصول».
     */
    private const EQUIVALENT = [
        'الموجودات الثابتة' => 'الممتلكات والآلات والمعدات',
        'الموجودات'         => 'الأصول',
        'المطلوبات'         => 'الالتزامات',
        'حقوق الشركاء'      => 'حقوق الملكية',
        'استهلاك'           => 'إهلاك',
        'لأطراف'            => 'إلى أطراف',
        'لجهات'             => 'إلى جهات',
        'غيرالمتداولة'      => 'غير المتداولة',
        'الزكاة الشرعية'    => 'الزكاة',
    ];

    /** Words of the statements that start with «و» themselves (normalised). */
    public const WAW_WORDS = ['ودائع', 'وديعه', 'وسائل', 'وعاء', 'وكاله', 'وكلاء', 'وحدات', 'وثائق', 'وزاره', 'وقف', 'ورثه', 'وراق', 'وضع'];

    /** A label's wording: its normalised words, sorted, so spelling, «ال», «و», word order and the EQUIVALENT words don't matter. */
    public static function wording(string $label): string
    {
        // «و» joined to a word («وآلات ومعدات») is the conjunction, unless the word starts with it (ودائع، وسائل، وعاء، …):
        // taken off before the equivalent words («وحقوق الشركاء») and after (the words they bring).
        $waw = fn (string $text) => ' '.implode(' ', array_map(fn ($w) => mb_substr($w, 0, 1) === 'و' && mb_strlen($w) > 3 && ! in_array($w, self::WAW_WORDS, true) ? mb_substr($w, 1) : $w,
            explode(' ', trim($text)))).' ';
        $text = $waw(ImportNormalizer::text($label));
        foreach (self::equivalents() as $from => $to) {
            $text = str_replace(" {$from} ", " {$to} ", $text);
        }
        $words = array_values(array_unique(array_filter(explode(' ', $waw($text)), fn ($w) => $w !== '')));
        sort($words);

        return implode(' ', $words);
    }

    /** @return array<string, string> EQUIVALENT normalised, as the labels are. */
    private static function equivalents(): array
    {
        static $map = null;

        return $map ??= array_combine(array_map(ImportNormalizer::text(...), array_keys(self::EQUIVALENT)), array_map(ImportNormalizer::text(...), self::EQUIVALENT));
    }

    /** @return array<string, string> the statement's lines and totals: key → wording of its own label */
    public static function own(LineCatalogue $catalogue, string $statement): array
    {
        $out = [];
        foreach ($catalogue->statement($statement) as $i) {
            if (in_array($i['type'], ['line', 'total'], true)) {
                $out[$i['key']] = self::wording($i['label_ar']);
            }
        }

        return $out;
    }

    /** @return array<string, array<string, string>> statement → wording → line key, from the database */
    public static function map(): array
    {
        $out = [];
        foreach (StatementSynonym::query()->get(['statement', 'wording', 'line_key']) as $s) {
            $out[$s->statement][$s->wording] = $s->line_key;
        }

        return $out;
    }

    /**
     * Why a synonym can't be saved, or null: [field, Arabic message] for an unknown line, a label with no Arabic word, a
     * wording that is a line's own label in that statement, or one another synonym of the statement already has.
     *
     * @return ?array{0: string, 1: string}
     */
    public static function problem(LineCatalogue $catalogue, string $key, string $label, ?int $except = null): ?array
    {
        $statement = self::statementOf($key);
        $own = $statement ? self::own($catalogue, $statement) : [];
        if (! isset($own[$key])) {
            return ['line_key', 'اختر سطرًا أو إجماليًا من قائمة المركز المالي أو الدخل أو التدفقات النقدية.'];
        }
        $wording = self::wording($label);
        if ($wording === '' || ! ImportNormalizer::isArabic($label)) {
            return ['label', 'اكتب البند بالعربية كما يظهر في القوائم.'];
        }
        if (($line = array_search($wording, $own, true)) !== false) {
            return ['label', 'هذا النص هو اسم السطر «'.self::label($catalogue, $line).'» نفسه، ولا يحتاج إلى مرادف.'];
        }
        $taken = StatementSynonym::where('statement', $statement)->where('wording', $wording)->when($except, fn ($q) => $q->where('id', '!=', $except))->first();
        if ($taken) {
            return ['label', 'هذا المرادف موجود بالفعل للسطر «'.self::label($catalogue, $taken->line_key).'» («'.$taken->label.'»).'];
        }

        return null;
    }

    public static function label(LineCatalogue $catalogue, string $key): string
    {
        foreach (self::STATEMENTS as $statement) {
            foreach ($catalogue->statement($statement) as $i) {
                if (($i['key'] ?? null) === $key) {
                    return $i['label_ar'];
                }
            }
        }

        return $key;
    }

    /** The starting list as rows: each wording once per statement, none that is a line's own label. */
    public static function initial(): array
    {
        $catalogue = new LineCatalogue;
        $rows = [];
        $seen = [];
        foreach (self::INITIAL as $key => $labels) {
            $statement = self::statementOf($key);
            $own = self::own($catalogue, $statement);
            foreach ($labels as $label) {
                $wording = self::wording($label);
                if (! isset($own[$key]) || in_array($wording, $own, true) || isset($seen[$statement][$wording])) {
                    continue;
                }
                $seen[$statement][$wording] = true;
                $rows[] = ['statement' => $statement, 'line_key' => $key, 'label' => $label, 'wording' => $wording];
            }
        }

        return $rows;
    }
}
