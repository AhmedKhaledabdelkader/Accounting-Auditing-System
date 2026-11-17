<?php

namespace App\Services\Statements;

/**
 * The kinds of revenue the company has, found from what the company does and from its revenue accounts' own names
 * instead of asked (accountant's review, note 14). The result is a default, shown as suggested (never silently): last
 * year's policy note, when read, comes first; this is the fallback. Streams are those of `ReportIntake::REVENUE_STREAMS`.
 */
final class RevenueStreams
{
    /** stream → word stems; property sales are checked first and, when found, keep «goods» from matching their «بيع». */
    private const WORDS = [
        'property_sales' => ['تطوير عقاري', 'بيع عقار', 'مبيعات عقار', 'بيع وحدات', 'وحدات سكنية', 'بيع اراضي', 'بيع أراضي'],
        'contracting'    => ['مقاولات', 'مقاول', 'إنشاء', 'انشاء', 'تشييد', 'تركيب', 'عقود'],
        'leasing'        => ['إيجار', 'ايجار', 'تأجير', 'تاجير'],
        'hospitality'    => ['فندق', 'فنادق', 'ضيافة', 'مطعم', 'مطاعم', 'إعاشة', 'اعاشة'],
        'murabaha'       => ['مرابحة', 'تمويل'],
        'education'      => ['تعليم', 'مدرسة', 'مدارس', 'تدريب', 'أكاديمية', 'اكاديمية'],
        'services'       => ['خدمات', 'خدمة', 'استشارات', 'صيانة', 'تشغيل', 'نقل', 'شحن', 'دعاية', 'اعلان', 'إعلان'],
        'goods'          => ['بيع', 'مبيعات', 'تجارة', 'تجاري', 'توريد', 'تصنيع', 'صناعة', 'صناعي', 'مصنع', 'إنتاج', 'انتاج', 'منتجات', 'استيراد', 'تسويق'],
    ];

    /**
     * @param list<string> $accountNames the names of the accounts on the revenue line
     * @return list<string> the streams, in the template's order; empty when nothing says
     */
    public static function infer(?string $activity, array $accountNames): array
    {
        $text = self::normalise(trim(($activity ?? '').' '.implode(' ', $accountNames)));
        if ($text === '') {
            return [];
        }
        $found = [];
        foreach (self::WORDS as $stream => $stems) {
            foreach ($stems as $stem) {
                if (str_contains($text, self::normalise($stem))) {
                    $found[$stream] = true;
                    break;
                }
            }
        }
        // «بيع» in a property sale is not a sale of goods, unless the company says goods too.
        if (isset($found['property_sales'], $found['goods']) && ! preg_match('/تصنيع|صناعة|مصنع|منتجات|توريد|تجارة/u', $text)) {
            unset($found['goods']);
        }

        return array_values(array_intersect(ReportIntake::REVENUE_STREAMS, array_keys($found)));
    }

    private static function normalise(string $s): string
    {
        return str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], mb_strtolower($s));
    }
}
