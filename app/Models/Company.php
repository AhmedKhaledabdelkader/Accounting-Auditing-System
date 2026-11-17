<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company profile, reused across years (Q12). Saudi, under IFRS for SMEs (Q5); a limited liability company (شركة) or a sole
 * proprietorship (مؤسسة فردية), as its commercial registration says or the accountant chooses.
 */
class Company extends Model
{
    use BelongsToOffice, HasUuids;

    /** The legal forms a company can have, with their Arabic name (the notes' wording). */
    public const LEGAL_FORMS = ['llc' => 'شركة ذات مسؤولية محدودة', 'sole_proprietorship' => 'مؤسسة فردية'];

    /** Where the legal form came from (profile `legal_form_source`): the commercial registration as read, or the accountant. Unset: not known yet. */
    public const LEGAL_FORM_SOURCES = ['registration', 'user'];

    /** Fields the API accepts; `profile` is filled by later steps of the generator. */
    public const EDITABLE = [
        'name_ar', 'name_en', 'legal_form', 'country', 'framework', 'cr_number', 'cr_date_hijri', 'cr_date', 'unified_number',
        'city', 'head_office', 'activity', 'branches', 'fiscal_year_end_month', 'fiscal_year_end_day', 'currency',
    ];

    /** The client's own details (the person or firm the office works for) and the fiscal year's start: set by the office's client pages, not the API. */
    public const CLIENT_FIELDS = ['client_name', 'client_email', 'fiscal_year_start_month', 'fiscal_year_start_day', 'period_start', 'period_end'];

    protected $fillable = [...self::EDITABLE, ...self::CLIENT_FIELDS, 'profile', 'office_id'];

    protected $attributes = ['legal_form' => 'llc', 'country' => 'SA', 'framework' => 'ifrs_for_smes', 'currency' => 'SAR',
        'fiscal_year_end_month' => 12, 'fiscal_year_end_day' => 31];

    protected function casts(): array
    {
        return ['branches' => 'array', 'profile' => 'array', 'cr_date' => 'date:Y-m-d',
            'fiscal_year_end_month' => 'integer', 'fiscal_year_end_day' => 'integer', 'fiscal_year_start_month' => 'integer', 'fiscal_year_start_day' => 'integer', 'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d'];
    }

    /**
     * The period the requests of a year are for: the company's own (from, to), with their years, moved to the year the period ends in
     * (so 1/7/2025 – 1/7/2026 is the period of 2026, and of 2027 it is 1/7/2026 – 1/7/2027). Without dates, the fiscal year's end gives it.
     *
     * @return array{start: \DateTimeInterface, end: \DateTimeInterface}
     */
    public function periodEndingIn(int $year): array
    {
        if ($this->period_start && $this->period_end) {
            $shift = $year - $this->period_end->year;

            return ['start' => $this->period_start->toImmutable()->addYearsNoOverflow($shift), 'end' => $this->period_end->toImmutable()->addYearsNoOverflow($shift)];
        }
        $day = min((int) $this->fiscal_year_end_day, (int) cal_days_in_month(CAL_GREGORIAN, (int) $this->fiscal_year_end_month, $year));
        $end = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $this->fiscal_year_end_month, $day));

        return ['start' => $end->modify('-1 year +1 day'), 'end' => $end];
    }

    /** The year the company's current period ends in (the year of its checklist), or null without dates. */
    public function periodYear(): ?int
    {
        return $this->period_end?->year;
    }
    /** The client's name as the office knows them; the company's own when none was given. */
    public function clientName(): string
    {
        return $this->client_name ?: $this->name_ar;
    }

    /** The company of the signed-in office with this registration number (another office's is never found), other than $exceptId. */
    public static function withRegistration(string $number, ?string $exceptId = null): ?self
    {
        return static::where('cr_number', $number)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->first();
    }

    /** Whether the legal form is known: read from the commercial registration or chosen by the accountant (else it is the default, `llc`, unconfirmed). */
    public function legalFormKnown(): bool
    {
        return in_array($this->profile['legal_form_source'] ?? null, self::LEGAL_FORM_SOURCES, true);
    }

    /**
     * Whether the articles of association are expected: a company (شركة) has them, a sole proprietorship (مؤسسة) has none. Null while
     * the legal form isn't known: they are optional then. Never required to work: a missing contract is a request, not a stop.
     */
    public function articlesRequired(): ?bool
    {
        return $this->legalFormKnown() ? $this->legal_form !== 'sole_proprietorship' : null;
    }

    /** The legal form in words, as the notes say it. */
    public function legalFormText(): string
    {
        return self::LEGAL_FORMS[$this->legal_form] ?? self::LEGAL_FORMS['llc'];
    }

    /**
     * The owners, for the zakat share: the partners the articles of association list; a sole proprietorship (no articles) is its
     * owner, holding all of it, and Saudi (an establishment in the Kingdom is a Saudi's): zakat on all of it. Empty when neither.
     *
     * @return list<array{name: string, nationality: ?string, group: string, shares: ?float, share_value: ?float, percentage: ?float}>
     */
    public function owners(): array
    {
        $partners = array_values((array) ($this->profile['articles']['partners'] ?? []));
        if ($partners !== []) {
            return $partners;
        }
        if ($this->legal_form === 'sole_proprietorship') {
            $owner = trim((string) ($this->profile['registration']['owner_name'] ?? ''));

            return [['name' => $owner !== '' ? $owner : $this->name_ar, 'nationality' => null, 'group' => 'saudi',
                'shares' => null, 'share_value' => null, 'percentage' => 100.0]];
        }

        return [];
    }

    /** Where the owners come from: `articles`, `sole_proprietorship` (its Saudi owner holds all of it), or null. */
    public function ownersSource(): ?string
    {
        return ! empty($this->profile['articles']['partners']) ? 'articles' : ($this->legal_form === 'sole_proprietorship' ? 'sole_proprietorship' : null);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(FinancialReport::class);
    }
}
