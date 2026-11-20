<?php

namespace App\Services\Audit;

/**
 * A document's balances (as the AI copied them: a bank statement's closing balance, a customer's confirmation, a loan statement…)
 * against the year's trial balance. Pure: the same balances and book always give the same result. Never sent to the client: a
 * difference is the accountant's finding (Findings), not a fault of the document.
 *
 * Each result: `kind`, `label`, `reference`, `counterparty`, `as_of`, `document` (the amount, on the account's natural side),
 * `account` ({number, name} or null), `by` (reference | name | only | none), `book` (the account's closing balance, natural side),
 * `difference` (document − book), `status`:
 *   matched     the same within the tolerance (under 2 riyals)
 *   differs     a difference
 *   other_date  the document is at another date than the period's end: shown, not compared
 *   not_found   no account of the trial balance could be told to be this one
 *   no_tb       no trial balance yet
 */
final class DocumentMatch
{
    public static function compare(?TrialBalanceBook $book, array $balances, string $periodEnd): array
    {
        $kinds = (array) config('tb_matching.documents');
        $tolerance = (float) config('tb_matching.tolerance', 2) - 0.005;
        $out = [];
        foreach ($balances as $b) {
            $kind = isset($kinds[$b['kind']]) ? $b['kind'] : 'other';
            $spec = $kinds[$kind];
            // The amount as the AI gives it: positive the usual way (money in the bank, a customer owing the client, the client owing a
            // supplier or a lender), negative the other way (an overdraft, a customer in credit). A kind with no usual side (a
            // related party, VAT) takes the side the document shows: debit owed to the client, credit owed by it.
            $side = $spec['side'] ?? ($b['side'] ?? 'debit');
            $amount = $spec['side'] === null ? abs($b['amount']) : $b['amount'];
            $row = ['kind' => $kind, 'label' => $b['label'] !== '' ? $b['label'] : $spec['label'], 'reference' => $b['reference'], 'counterparty' => $b['counterparty'],
                'as_of' => $b['as_of'], 'document' => round($amount, 2), 'side' => $side, 'account' => null, 'by' => 'none', 'book' => null, 'difference' => null];
            if ($book === null) {
                $out[] = $row + ['status' => 'no_tb'];
                continue;
            }
            $candidates = $book->in($spec['classes'] ?? []);
            if ($candidates === [] && $kind === 'other') {
                $candidates = $book->accounts;   // a balance of no known kind: any account its reference or name points to
            }
            $pick = TrialBalanceBook::pick($candidates, $b['reference'], $b['counterparty'] ?? $b['label'], in_array($kind, ['bank', 'cash', 'loan', 'zakat', 'vat', 'gosi', 'inventory'], true));
            if ($pick['account'] === null) {
                $out[] = $row + ['status' => 'not_found', 'candidates' => count($candidates)];
                continue;
            }
            $a = $pick['account'];
            $natural = TrialBalanceBook::natural($a['balance'], $side);
            $row = array_merge($row, ['account' => ['number' => $a['number'], 'name' => $a['name'], 'row' => $a['row']], 'by' => $pick['by'], 'book' => $natural,
                'difference' => round($row['document'] - $natural, 2)]);
            if ($b['as_of'] !== null && $b['as_of'] !== $periodEnd) {
                $out[] = $row + ['status' => 'other_date'];
                continue;
            }
            $out[] = $row + ['status' => abs($row['difference']) > $tolerance ? 'differs' : 'matched'];
        }

        return $out;
    }
}
