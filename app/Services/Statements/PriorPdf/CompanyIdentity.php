<?php

namespace App\Services\Statements\PriorPdf;

use App\Models\Company;
use App\Services\AmountParser;
use App\Support\ImportNormalizer;

/**
 * Whether last year's statements are the report's company's: its name and commercial registration as the notes print them
 * against the company's profile, and the other companies of the app that last year's file names (the same registration
 * number, or the same name). Only said, never acted on: which company a report is for is the user's choice.
 */
final class CompanyIdentity
{
    /**
     * @param array<string, array{value: string, page: int}> $facts CompanyFacts::of()
     * @return array{differs: list<array{field: string, file: string, company: string, page: int}>, matches: list<array{id: string, name_ar: string, by: string}>}
     */
    public static function of(?Company $company, array $facts): array
    {
        $name = fn (?string $s) => ImportNormalizer::text($s);
        $cr = fn (?string $s) => preg_replace('/\D+/', '', AmountParser::asciiDigits((string) $s));
        $differs = [];
        foreach (['name_ar' => $name, 'cr_number' => $cr] as $field => $norm) {
            $file = $facts[$field]['value'] ?? null;
            $own = $company?->{$field};
            if ($file !== null && $own !== null && trim((string) $own) !== '' && $norm($file) !== $norm($own)) {
                $differs[] = ['field' => $field, 'file' => $file, 'company' => (string) $own, 'page' => $facts[$field]['page']];
            }
        }
        $matches = [];
        if (isset($facts['name_ar']) || isset($facts['cr_number'])) {
            foreach (Company::query()->when($company, fn ($q) => $q->whereKeyNot($company->id))->get(['id', 'name_ar', 'cr_number']) as $other) {
                $by = match (true) {
                    isset($facts['cr_number']) && $cr($other->cr_number) !== '' && $cr($other->cr_number) === $cr($facts['cr_number']['value']) => 'cr_number',
                    isset($facts['name_ar']) && $name($other->name_ar) === $name($facts['name_ar']['value']) => 'name_ar',
                    default => null,
                };
                if ($by !== null) {
                    $matches[] = ['id' => $other->id, 'name_ar' => $other->name_ar, 'by' => $by];
                }
            }
        }

        return ['differs' => $differs, 'matches' => $matches];
    }
}
