<?php

namespace App\Services\Statements;

/** What the movement schedules read from a report: its rows as mapped, its layout, the user's postings and choices. */
final class ScheduleContext
{
    /**
     * @param array<int, array>         $rows        the run's rows by row number
     * @param array                     $mapping     LineMapper::map()
     * @param list<array>               $postings    LineAdjustments::apply()['postings']
     * @param array<int, string>        $categories  the user's category per row (account overrides)
     * @param array<int, list<string>>  $tags        the user's tags per row
     * @param ?array<string, Figure>    $comparative the comparative balance sheet, null in a first year
     * @param array<string, array>      $aggregated  the lines with the report's postings (LineAdjustments)
     */
    public function __construct(
        public readonly array $rows,
        public readonly LayoutProfile $profile,
        public readonly array $mapping,
        public readonly LineCatalogue $catalogue,
        private readonly LineAggregator $aggregator,
        public readonly array $postings,
        public readonly array $categories,
        public readonly array $tags,
        public readonly ?array $comparative,
        public readonly array $aggregated,
        /** the year's PPE revaluation surplus (OCI), when entered */
        public readonly ?Figure $revaluation = null,
    ) {
    }

    public function movements(): bool
    {
        return $this->profile->has('period');
    }

    /** @return array<int, float> row => its share of the line (closing balance) */
    /** The accounts of these rows as the user reads them (AccountNames). */
    public function accounts(array $rows): \App\Support\Phrase
    {
        return AccountNames::phrase($rows, $this->rows);
    }

    public function rowsOn(string $line): array
    {
        $out = [];
        foreach ($this->mapping['rows'] as $n => $m) {
            if (isset($m['lines']['closing'][$line])) {
                $out[$n] = $m['lines']['closing'][$line];
            }
        }

        return $out;
    }

    /** @return array<int, float> mapped rows of these sub-classes or main classes, whole */
    public function rowsOf(array $subClasses = [], array $mainClasses = []): array
    {
        $out = [];
        foreach (array_keys($this->mapping['rows']) as $n) {
            $r = $this->rows[$n];
            if (in_array($r['sub_class'], $subClasses, true) || in_array($r['main_class'], $mainClasses, true)) {
                $out[$n] = 1.0;
            }
        }

        return $out;
    }

    /** @return array<int, float> mapped rows the user tagged */
    public function tagged(string $tag): array
    {
        return array_map(fn () => 1.0, array_filter(array_intersect_key($this->tags, $this->mapping['rows']), fn ($t) => in_array($tag, $t, true)));
    }

    public function isContra(int $row): bool
    {
        return ($this->mapping['rows'][$row]['contra'] ?? false) || $this->aggregator->isContra($this->rows[$row]['sub_class'] ?? null);
    }

    /**
     * The rows' balances, signed for a side (debit: debit − credit; credit: credit − debit), with their refs:
     * opening (null without opening columns), increase and decrease (the movement columns, period and adjustments;
     * null without them: on a debit side the debits increase), closing.
     *
     * @param array<int, float> $rows row => share
     * @return array{opening: ?float, increase: ?float, decrease: ?float, closing: float, refs: list<array>}
     */
    public function sum(array $rows, string $side): array
    {
        $sign = $side === 'debit' ? 1 : -1;
        $out  = ['opening' => $this->profile->has('opening') ? 0.0 : null, 'increase' => $this->movements() ? 0.0 : null,
            'decrease' => $this->movements() ? 0.0 : null, 'closing' => 0.0, 'refs' => []];
        foreach ($rows as $n => $share) {
            $row = $this->rows[$n];
            if ($out['opening'] !== null) {
                $out['opening'] += $sign * $this->profile->opening($row) * $share;
            }
            foreach (['period', 'adjustment'] as $group) {
                if ($movement = $this->profile->movement($row, $group)) {
                    $out['increase'] += ($side === 'debit' ? $movement['debit'] : $movement['credit']) * $share;
                    $out['decrease'] += ($side === 'debit' ? $movement['credit'] : $movement['debit']) * $share;
                }
            }
            $out['closing'] += $sign * ($this->profile->closing($row) ?? 0.0) * $share;
            $out['refs'][]   = ['type' => 'row', 'row' => $n] + ($share < 1.0 ? ['share' => $share] : []);
        }
        foreach (['opening', 'increase', 'decrease', 'closing'] as $k) {
            $out[$k] = $out[$k] === null ? null : round($out[$k], 2) + 0.0;
        }

        return $out;
    }

    /**
     * The report's postings on these rows (entries by account) and, with $lines, on these whole lines (entries by line,
     * inputs): {increase: [Figure], decrease: [Figure]} on the side given.
     *
     * @param array<int, float> $rows
     */
    public function postingsOn(array $rows, string $side, ?string $rowLine = null, array $lines = []): array
    {
        $out = ['increase' => [], 'decrease' => []];
        foreach ($this->postings as $p) {
            $onRow  = $p['row'] !== null && isset($rows[$p['row']]) && ($rowLine === null || $p['line'] === $rowLine);
            $onLine = $p['row'] === null && in_array($p['line'], $lines, true);
            if (! $onRow && ! $onLine) {
                continue;
            }
            $signed = $side === 'debit' ? $p['net'] : -$p['net'];
            $out[$signed >= 0 ? 'increase' : 'decrease'][] = new Figure(round(abs($signed), 2), $p['origin'], 'ok', [$p['ref']]);
        }

        return $out;
    }

    /** Postings by line (not by account) on a line. */
    public function linePostings(string $line): array
    {
        return array_values(array_filter($this->postings, fn ($p) => $p['row'] === null && $p['line'] === $line));
    }
}
