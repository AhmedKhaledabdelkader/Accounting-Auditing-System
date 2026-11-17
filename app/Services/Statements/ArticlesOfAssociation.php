<?php

namespace App\Services\Statements;

use App\Services\Statements\PriorPdf\Llm;
use App\Support\Llm\ToolCall;

/**
 * The company's articles of association (عقد التأسيس), a PDF the accountant uploads once for the company (accountant's
 * review, notes 10–12). The AI only copies: the partners as the contract lists them, each with the nationality as
 * printed, the number of shares, the share value and the percentage printed. PHP decides what the copy means: which
 * nationalities are Saudi or Gulf (zakat) and which foreign (income tax), the share of each owner, the Saudi and Gulf
 * share of the capital. A partner whose nationality can't be placed makes the share unknown: it is asked, never guessed.
 */
final class ArticlesOfAssociation
{
    public const GROUPS = ['saudi', 'gulf', 'foreign', 'unknown'];

    /** Nationality words, after normalising: Saudi, then the other Gulf states (the zakat payers' side). */
    private const SAUDI = ['سعودي', 'سعوديه', 'السعوديه', 'المملكه العربيه السعوديه', 'سعودية'];
    private const GULF  = ['اماراتي', 'اماراتيه', 'الامارات', 'كويتي', 'كويتيه', 'الكويت', 'بحريني', 'بحرينيه', 'البحرين', 'قطري', 'قطريه', 'قطر',
        'عماني', 'عمانيه', 'عمان', 'خليجي', 'خليجيه', 'دول مجلس التعاون'];

    public function __construct(private Llm $llm) {}

    /**
     * @return array{partners: list<array{name: string, nationality: ?string, group: string, shares: ?float, share_value: ?float, percentage: ?float}>,
     *               capital: ?float, shares_total: ?float, share_value: ?float, usage: ?array}
     */
    public function read(string $path): array
    {
        $answer = $this->llm->ask($path, 'articles', 0, 0, fn () => new ToolCall([['text' => self::RULES]], self::TOOL,
            'Copy the partners of this company from its articles of association.', 8000, false, (string) file_get_contents($path)));

        return self::normalise($answer->input) + ['usage' => $answer->usage];
    }

    /**
     * Keeps a company's articles of association: the PDF on the private disk, what the AI copied from it in the profile (where the
     * capital note and the zakat dialog read it). A second one replaces the first; a provider failure keeps nothing.
     *
     * @throws \App\Support\Llm\ProviderException
     */
    public function store(\App\Models\Company $company, \Illuminate\Http\UploadedFile $file): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $stored = $file->storeAs("articles/{$company->id}", hash_file('sha256', $file->getRealPath()).'.pdf', 'local');
        try {
            $read = $this->read($disk->path($stored));
        } catch (\Throwable $e) {
            $disk->delete($stored);

            throw $e;
        }
        $old = $company->profile['articles']['file'] ?? null;
        if ($old && $old !== $stored) {
            $disk->delete($old);
        }
        $company->profile = array_merge($company->profile ?? [], ['articles' => ['file' => $stored, 'name' => $file->getClientOriginalName(),
            'read_at' => now()->toIso8601String(), 'partners' => $read['partners'], 'capital' => $read['capital'], 'shares_total' => $read['shares_total'],
            'share_value' => $read['share_value'], 'usage' => $read['usage']]]);
        $company->save();
    }

    /** The AI's answer as the partners with their group and their share of the capital (by shares, else by the percentage printed). */
    public static function normalise(array $input): array
    {
        $partners = [];
        foreach ((array) ($input['partners'] ?? []) as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $nationality = ($n = trim((string) ($p['nationality'] ?? ''))) === '' ? null : $n;
            $num = fn ($v) => is_numeric($v) ? (float) $v : null;
            $partners[] = ['name' => $name, 'nationality' => $nationality, 'group' => self::group($nationality),
                'shares' => $num($p['shares'] ?? null), 'share_value' => $num($p['share_value'] ?? null), 'percentage' => $num($p['percentage'] ?? null)];
        }
        $num = fn ($v) => is_numeric($v) ? (float) $v : null;

        return ['partners' => $partners, 'capital' => $num($input['capital'] ?? null), 'shares_total' => $num($input['shares_total'] ?? null),
            'share_value' => $num($input['share_value'] ?? null)];
    }

    /** `saudi`, `gulf` (another GCC state), `foreign`, or `unknown` when none is printed. */
    public static function group(?string $nationality): string
    {
        $text = self::normalize((string) $nationality);
        if ($text === '') {
            return 'unknown';
        }
        foreach ([['saudi', self::SAUDI], ['gulf', self::GULF]] as [$group, $words]) {
            foreach ($words as $w) {
                if (preg_match('/(^|\s)'.preg_quote(self::normalize($w), '/').'($|\s)/u', $text)) {
                    return $group;
                }
            }
        }

        return 'foreign';
    }

    /**
     * Each partner's share of the capital in percent (shares over the shares of all, else the percentage printed); null
     * where neither is known.
     *
     * @param list<array> $partners
     * @return list<?float>
     */
    public static function percentages(array $partners): array
    {
        $total = array_sum(array_map(fn ($p) => (float) ($p['shares'] ?? 0), $partners));

        return array_map(fn ($p) => $total > 0 && ($p['shares'] ?? null) !== null ? round($p['shares'] * 100 / $total, 4) : ($p['percentage'] ?? null), $partners);
    }

    /**
     * The Saudi and Gulf owners' share of the capital in percent (what zakat is charged on); the rest is the foreign
     * owners' (income tax). Null when it can't be told: no partners, a nationality not printed, or shares unknown.
     */
    public static function zakatShare(array $partners): ?float
    {
        if ($partners === []) {
            return null;
        }
        $shares = self::percentages($partners);
        $sum = 0.0;
        foreach ($partners as $i => $p) {
            if ($shares[$i] === null || ($p['group'] ?? 'unknown') === 'unknown') {
                return null;
            }
            $sum += in_array($p['group'], ['saudi', 'gulf'], true) ? $shares[$i] : 0.0;
        }

        return round($sum, 4);
    }

    private static function normalize(string $s): string
    {
        $s = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ؤ', 'ئ'], ['ا', 'ا', 'ا', 'ي', 'ه', 'و', 'ي'], trim($s));
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $s);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)));
    }

    private const TOOL = [
        'name' => 'submit_partners', 'description' => 'The partners of the company as its articles of association list them.', 'strict' => true,
        'schema' => ['type' => 'object', 'required' => ['partners', 'capital', 'shares_total', 'share_value'], 'additionalProperties' => false, 'properties' => [
            'partners' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['name', 'nationality', 'shares', 'share_value', 'percentage'], 'properties' => [
                    'name'        => ['type' => 'string'],
                    'nationality' => ['type' => ['string', 'null']],
                    'shares'      => ['type' => ['number', 'null']],
                    'share_value' => ['type' => ['number', 'null']],
                    'percentage'  => ['type' => ['number', 'null']],
                ]]],
            'capital'      => ['type' => ['number', 'null']],
            'shares_total' => ['type' => ['number', 'null']],
            'share_value'  => ['type' => ['number', 'null']],
        ]],
    ];

    private const RULES = <<<'TXT'
        You are given a company's articles of association (عقد تأسيس، النظام الأساسي) as a PDF, usually in Arabic, often scanned.
        Copy, exactly as printed and never correct or infer:
        - partners: every partner or shareholder named as an owner of the capital, in the order printed. name as printed;
          nationality as printed for that partner (such as «سعودي الجنسية», «مصري»; for a company, the country it is
          from if printed); shares: the number of shares (حصص) the partner holds; share_value: the value of one share;
          percentage: the percentage of the capital printed for the partner. Null for anything the contract doesn't say.
        - capital: the company's capital as printed; shares_total: the total number of shares; share_value: the value of one share.
        Do not decide anything and do not add anyone: only what the contract lists.
        TXT;
}
