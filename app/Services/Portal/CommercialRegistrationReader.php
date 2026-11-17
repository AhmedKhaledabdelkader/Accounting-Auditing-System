<?php

namespace App\Services\Portal;

use App\Services\Statements\PriorPdf\Llm;
use App\Support\Llm\ToolCall;

/**
 * The commercial registration (السجل التجاري) of a new client, read by the AI: it recognises the document by its words, and
 * copies the registration number, the company's name, its activity and city, the dates, the legal form (the entity type) and
 * a sole proprietorship's owner as printed. It never decides: PHP checks that it is a registration at all and that the number is a
 * Saudi registration number (ten digits), and tells a company (llc) from a sole proprietorship by the words of the legal form. The
 * number is the company's identity: one company, one client, per office.
 */
final class CommercialRegistrationReader
{
    public function __construct(private Llm $llm) {}

    /**
     * @return array{is_registration: bool, readable: bool, number: ?string, unified_number: ?string, company_name: ?string, activity: ?string, city: ?string,
     *               issue_date: ?string, issue_date_hijri: ?string, expiry_date: ?string, legal_form_text: ?string, legal_form: ?string,
     *               owner_name: ?string, usage: ?array}
     */
    public function read(string $path, string $extension): array
    {
        $ext = strtolower($extension);
        $mime = match ($ext) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', default => null,
        };
        // `-2`: the reading with the legal form and the owner (a registration read before them is read again, not taken from the cache).
        $answer = $this->llm->ask($path, 'commercial-registration-2', 0, 0, fn () => new ToolCall([['text' => self::RULES]], self::TOOL,
            'Read this document.', 2000, false, (string) file_get_contents($path), $mime));

        return self::normalise($answer->input) + ['usage' => $answer->usage];
    }

    /** The AI's copy, cleaned: the number as ten digits (the registration number, else the unified national number; null when neither), dates as Y-m-d where they are Gregorian. */
    public static function normalise(array $in): array
    {
        $text = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $ten = function ($v): ?string {
            $d = preg_replace('/\D+/', '', strtr((string) ($v ?? ''), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));

            return strlen($d) === 10 ? $d : null;
        };
        // The certificates issued under the 2025 commercial register law print only the unified national number (700…), which took
        // the place of the registration number: it is the number then.
        $number = $ten($in['registration_number'] ?? null) ?? $ten($in['unified_number'] ?? null);
        $date = fn ($v) => is_string($v) && ($t = strtotime($v)) !== false ? date('Y-m-d', $t) : null;

        return [
            'is_registration' => (bool) ($in['is_commercial_registration'] ?? false),
            'readable' => (bool) ($in['readable'] ?? false),
            'number' => $number,
            'unified_number' => $text($in['unified_number'] ?? null),
            'company_name' => $text($in['company_name'] ?? null),
            'activity' => $text($in['activity'] ?? null),
            'city' => $text($in['city'] ?? null),
            'issue_date' => $date($in['issue_date'] ?? null),
            'issue_date_hijri' => $text($in['issue_date_hijri'] ?? null),
            'expiry_date' => $date($in['expiry_date'] ?? null),
            'legal_form_text' => $text($in['legal_form'] ?? null),
            'legal_form' => self::legalForm($text($in['legal_form'] ?? null)),
            'owner_name' => $text($in['owner_name'] ?? null),
        ];
    }

    /**
     * The legal form as the registration prints it, placed: `sole_proprietorship` (مؤسسة), `llc` (a limited liability company, one
     * person's too), `other` (a joint stock or partnership company: not one the statements are made for yet), null when not printed.
     */
    public static function legalForm(?string $printed): ?string
    {
        if ($printed === null) {
            return null;
        }
        $t = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $printed);

        return match (true) {
            (bool) preg_match('/مسؤوليه\s+محدود|مسئوليه\s+محدود|مسوليه\s+محدود|شخص\s+واحد|limited\s+liability|\bllc\b/iu', $t) => 'llc',
            (bool) preg_match('/مؤسس|موسس|sole\s*proprietor|establishment/iu', $t) => 'sole_proprietorship',
            (bool) preg_match('/شركه|company/iu', $t) => 'other',
            default => null,
        };
    }

    private const TOOL = [
        'name' => 'submit_registration', 'description' => 'What the document is and what a commercial registration shows.', 'strict' => true,
        'schema' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['readable', 'is_commercial_registration', 'registration_number', 'unified_number', 'company_name', 'activity', 'city', 'issue_date', 'issue_date_hijri', 'expiry_date',
                'legal_form', 'owner_name'],
            'properties' => [
                'readable' => ['type' => 'boolean'],
                'is_commercial_registration' => ['type' => 'boolean'],
                'registration_number' => ['type' => ['string', 'null']],
                'unified_number' => ['type' => ['string', 'null']],
                'company_name' => ['type' => ['string', 'null']],
                'activity' => ['type' => ['string', 'null']],
                'city' => ['type' => ['string', 'null']],
                'issue_date' => ['type' => ['string', 'null']],
                'issue_date_hijri' => ['type' => ['string', 'null']],
                'expiry_date' => ['type' => ['string', 'null']],
                'legal_form' => ['type' => ['string', 'null']],
                'owner_name' => ['type' => ['string', 'null']],
            ]],
    ];

    private const RULES = <<<'TXT'
        You are given a document, usually Arabic and Saudi, often a scan or a photograph. Say whether it is a commercial registration
        certificate (سجل تجاري, شهادة سجل تجاري), recognised by the words and headings printed on it, never by guessing, and copy what
        it shows, exactly as printed and never corrected:
        - readable: false when the text cannot be read.
        - is_commercial_registration: true only for a commercial registration certificate (not a licence, a contract or an invoice).
        - registration_number: the commercial registration number (رقم السجل التجاري، Commercial Registration No.), digits as printed.
        - unified_number: the unified national number (الرقم الوطني الموحد، الرقم الوطني، National Number, Unified Number), if printed.
          The newer certificates print only this number (it begins with 7) and no separate registration number: give it here and
          leave registration_number null.
        - company_name: the establishment's name as printed. activity: its activity. city: its city.
        - issue_date: the Gregorian issue date, YYYY-MM-DD; issue_date_hijri: the Hijri issue date as printed; expiry_date: the Gregorian
          expiry date, YYYY-MM-DD.
        - legal_form: the entity type or legal form as printed (نوع الكيان، الشكل القانوني، نوع السجل), such as «شركة ذات مسؤولية
          محدودة», «شركة شخص واحد ذات مسؤولية محدودة», «مؤسسة فردية», «شركة مساهمة مبسطة».
        - owner_name: for a sole proprietorship (مؤسسة), its owner (المالك، اسم التاجر) as printed; null for a company (its
          partners are in its articles of association, not here).
        Null for anything not printed.
        TXT;
}
