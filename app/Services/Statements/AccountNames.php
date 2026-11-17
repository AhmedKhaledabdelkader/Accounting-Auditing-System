<?php

namespace App\Services\Statements;

use App\Support\Phrase;

/**
 * How an account is named wherever the user sees it (PR 13b): «account number — account name», never an internal row
 * number; a difference from last year's approved statements (Step B) is said to be one. A list shows the first few and
 * counts the rest.
 */
final class AccountNames
{
    public const SHOWN = 5;

    public static function one(?array $row): string
    {
        if (isset($row['row']) && ApprovedOpening::isRow($row['row'])) {
            return __('report_messages.accounts.approved_opening', [], 'ar');
        }
        $number = trim((string) ($row['account_number'] ?? ''));
        $name   = trim((string) ($row['account_name'] ?? ''));

        return match (true) {
            $number !== '' && $name !== '' => "{$number} — {$name}",
            $number !== '' || $name !== '' => $number.$name,
            default                        => __('report_messages.accounts.unnamed', [], 'ar'),
        };
    }

    /** @param list<int> $rows row numbers; @param array<int, array> $byRow the run's rows by row number */
    public static function list(array $rows, array $byRow): string
    {
        return self::phrase($rows, $byRow)->in('ar');
    }

    /** The list as a message part, in any language (the account names themselves are the file's). */
    public static function phrase(array $rows, array $byRow): Phrase
    {
        // The approved openings' differences once, after the accounts.
        $approved = array_filter(array_unique($rows), fn ($n) => ApprovedOpening::isRow($n));
        $rows  = [...array_values(array_diff(array_unique($rows), $approved)), ...array_slice(array_values($approved), 0, 1)];
        $names = array_map(fn ($n) => ApprovedOpening::isRow($n)
            ? Phrase::text(['ar' => '«'.__('report_messages.accounts.approved_opening', [], 'ar').'»', 'en' => '«'.__('report_messages.accounts.approved_opening', [], 'en').'»'])
            : self::named($byRow[$n] ?? null), array_slice($rows, 0, self::SHOWN));
        $rest  = count($rows) - self::SHOWN;

        return Phrase::make('report_messages.accounts.'.match (true) { $rest === 1 => 'list_one_more', $rest > 1 => 'list_more', default => 'list' },
            ['names' => $names, 'count' => $rest]);
    }

    private static function named(?array $row): Phrase
    {
        $number = trim((string) ($row['account_number'] ?? ''));
        $name   = trim((string) ($row['account_name'] ?? ''));

        return $number === '' && $name === ''
            ? Phrase::text(['ar' => '«'.self::one($row).'»', 'en' => '«'.__('report_messages.accounts.unnamed', [], 'en').'»'])
            : Phrase::text(['ar' => '«'.self::one($row).'»']);
    }
}
