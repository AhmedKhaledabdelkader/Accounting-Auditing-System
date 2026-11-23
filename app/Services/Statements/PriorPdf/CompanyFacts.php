<?php

namespace App\Services\Statements\PriorPdf;

use App\Services\AmountParser;
/**
 * The company as last year's notes describe it (Phase 5, W10): its name, legal form, commercial registration (number,
 * Hijri date, where issued), city, head office (its address note), activity and framework from the note about the company (by its title, else note 1),
 * and who approved the statements from the approval note. Each fact with its page; a fact the text doesn't state is
 * left out (never guessed). The approval date is this year's, so it stays the accountant's.
 */
final class CompanyFacts
{
    /** @return array<string, array{value: string, page: int}> company field (Company::EDITABLE) or `approval.body` → fact */
    public static function of(array $doc, array $corrections = [], array $choices = []): array
    {
        $out = [];
        $smes = null;
        // The note about the company: by its title (التكوين والنشاط، نبذة عن الشركة، معلومات عامة …), else note 1.
        $about = '/التكوين|التأسيس|تأسيس|نبذة|معلومات\s+عامة|معلومات\s+عن|عن\s+الشركة|الشركة\s+و\s*?نشاطها|التنظيم\s+والنشاط|الوضع\s+القانوني/u';
        $tops = array_filter($doc['sections'] ?? [], fn ($s) => $s['parent'] === null && $s['number'] !== null);
        $company = (collect($tops)->first(fn ($s) => preg_match($about, $s['title']) === 1) ?? collect($tops)->first(fn ($s) => $s['number'] === '1'))['id'] ?? null;
        $put = function (string $field, ?string $value, int $page) use (&$out) {
            $value = (string) preg_replace('/^[\s.،:\-"\'«»]+|[\s.،:\-"\'«»]+$/u', '', (string) $value);
            if ($value !== '' && ! isset($out[$field])) {
                $out[$field] = ['value' => $value, 'page' => $page];
            }
        };
        foreach ($doc['sections'] ?? [] as $s) {
            $top = $s['parent'] === null ? $s : $doc['sections'][$s['parent']];
            $text = Wording::apply(implode("\n", $s['paragraphs']), $corrections, $choices);
            $digits = AmountParser::asciiDigits($text);
            if ($top['id'] === $company) {
                if (preg_match('/^(.{3,120}?)\s+(?:شركة\s+)?(?:ذات|ذو)\s+مس[ئؤ]?و?لي[ةه]\s+محدود[ةه]/um', $text, $m)) {
                    $put('name_ar', self::name($m[1]), $s['page']);
                    $put('legal_form', 'llc', $s['page']);
                }
                if (preg_match('/السجل\s+التجاري\s*(?:رقم|برقم)?\s*\(?\s*(\d{10})/u', $digits, $m)) {
                    $put('cr_number', $m[1], $s['page']);
                }
                // «هـ», or «ه» with its stroke lost.
                if (preg_match('/بتاريخ\s*(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(1[34]\d\d)\s*هـ?(?![ء-ي])/u', $digits, $m)) {
                    $put('cr_date_hijri', sprintf('%s/%02d/%02d', $m[3], $m[2], $m[1]), $s['page']);
                } elseif (preg_match('/بتاريخ\s*(1[34]\d\d)\s*\/\s*(\d{1,2})\s*\/\s*(\d{1,2})\s*هـ?(?![ء-ي])/u', $digits, $m)) {
                    $put('cr_date_hijri', sprintf('%s/%02d/%02d', $m[1], $m[2], $m[3]), $s['page']);   // year first: «١٤٣٩/٠٢/١٣ هـ»
                }
                if (preg_match('/الصادر\s+من\s+(?:مدينة\s+)?([^\s،.]+)/u', $text, $m) && ! str_contains($m[1], 'وزار')) {
                    $put('city', $m[1], $s['page']);
                }
                // A sole proprietorship: «مؤسسة لؤلؤة الطب - مؤسسة فردية سعودية …».
                if (preg_match('/^(.{3,120}?)\s*[-–،,]?\s*(?:هي\s+)?مؤسس[ةه]\s+فردي[ةه]/um', $text, $m)) {
                    $put('name_ar', self::name($m[1]), $s['page']);
                    $put('legal_form', 'sole_proprietorship', $s['page']);
                }
                if (preg_match('/(?:حدد|يتمثل|ويتمثل|يتمثل)\s+نشاط\s+الشركة\s+(?:في|فى)\s*:?-?\s*(.+)/u', $text, $m)) {
                    $put('activity', $m[1], $s['page']);
                }
                // «ويتمثل غرض الشركة في :-» (or «نشاط المؤسسة»), the activity on the line after it.
                if (preg_match('/(?:غرض|نشاط|أغراض|اغراض)\s+(?:ال)?(?:شركة|مؤسس[ةه]|منشأ[ةه])\s+(?:في|فى)\s*:?\s*-?\s*(.*)(?:\n(.+))?/u', $text, $m)) {
                    $put('activity', trim($m[1]) !== '' ? $m[1] : ($m[2] ?? null), $s['page']);
                }
                // «عنوان الشركة :-» with the address on the line after it: the head office, and its first part the city.
                if (preg_match('/(?:عنوان|مقر|المركز\s+الرئيسي\s+ل)\s*(?:ال)?(?:شركة|مؤسس[ةه]|منشأ[ةه])\s*(?:هو|في|فى)?\s*:?\s*-?\s*(.*)(?:\n(.+))?/u', $text, $m)) {
                    $address = trim($m[1]) !== '' ? $m[1] : ($m[2] ?? '');
                    if (trim($address) !== '' && mb_strlen($address) <= 120) {
                        $put('head_office', $address, $s['page']);
                        $city = trim((string) preg_split('/\s*[-–،,]\s*/u', trim($address))[0]);
                        if ($city !== '' && mb_strlen($city) <= 30) {
                            $put('city', $city, $s['page']);
                        }
                    }
                }
                if ($s['parent'] !== null && preg_match('/عنوان|المركز\s+الرئيسي|مقر/u', $s['title']) && mb_strlen($text) <= 120) {
                    // The note's own address: the head office, and the city over where the registry was issued.
                    $put('head_office', $text, $s['page']);
                    $out['city'] = ['value' => trim($text), 'page' => $s['page']];
                }
            }
            // The framework: as the statement of compliance says it (its first words naming one); else any text naming
            // the SMEs' (a transition note may name the framework left behind, so the compliance note comes first).
            if (preg_match(self::COMPLIANCE, $s['title']) && preg_match(self::FRAMEWORKS, $text, $m)) {
                $out['framework'] ??= ['value' => preg_match(self::SMES, $m[0]) ? 'ifrs_for_smes' : 'ifrs', 'page' => $s['page'], 'from' => 'compliance'];
            }
            if (preg_match(self::SMES, $text)) {
                $smes ??= $s['page'];
            }
            if (preg_match('/(?:قامت|قام|اعتمدت|اعتمد)\s+(.{2,40}?)\s+(?:ب?الموافقة|باعتماد|اعتماد|على\s+القوائم|القوائم)/u', $text, $m) && preg_match('/اعتماد|الموافقة/u', $top['title'])) {
                $put('approval.body', $m[1], $s['page']);
            }
        }

        if (! isset($out['framework']) && isset($smes)) {
            $out['framework'] = ['value' => 'ifrs_for_smes', 'page' => $smes];
        }

        return $out;
    }

    private const COMPLIANCE = '/بيان\s+(ال)?(التزام|الالتزام|التوافق|الامتثال)|الالتزام\s+بالمعايير|أسس\s+الإعداد|اسس\s+الاعداد|أساس\s+الإعداد|المعايير\s+المطبقة|الإطار\s+المحاسبي|الاطار\s+المحاسبي/u';

    /** The SMEs' standard; the full IFRS (named without it). */
    private const SMES = '/منشآت\s+الصغيرة\s+و?(ال)?متوسطة\s+الحجم|للمنشآت\s+الصغيرة/u';

    private const FRAMEWORKS = '/(ال|لل)معيار\s+الدولي\s+للتقرير\s+المالي\s+للمنشآت\s+الصغيرة\s+و?(ال)?متوسطة\s+الحجم|(ال|لل)معايير\s+الدولية\s+(للتقرير\s+المالي|للتقارير\s+المالية)/u';

    /** A company's name as the note's sentence holds it: without «إن» before it, its short name «("الشركة")» and «هي» after. */
    private static function name(string $text): string
    {
        $text = preg_replace('/^\s*(إن|ان)\s+/u', '', $text);
        $text = preg_replace('/\s*[،,]?\s*(هي|هى)\s*$/u', '', (string) $text);
        $text = preg_replace('/\s*\(\s*["«“]?\s*(ال)?شركة\s*["»”]?\s*\)\s*[،,]?\s*$/u', '', (string) $text);

        return (string) preg_replace('/^[\s،,]+|[\s،,]+$/u', '', (string) $text);
    }
}
