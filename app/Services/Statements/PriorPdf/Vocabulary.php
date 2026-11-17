<?php

namespace App\Services\Statements\PriorPdf;

use App\Models\ClassificationRun;
use App\Models\KnownWord;
use App\Services\Statements\StatementSynonyms;
use ZipArchive;

/**
 * The words known outside a PDF, for checking the wording read from it (Wording): the trial balance's account names,
 * the output templates (the notes' Word file, the statements' Excel file), the taxonomy (the classification's classes
 * and rules, the statements' names), the synonyms the users keep, and the words the accountant marked as right (KnownWord:
 * a correct word or name flagged once is never flagged again). Apart: general Arabic words (general()), never flagged.
 */
final class Vocabulary
{
    /** @var array<string, list<string>> the templates' and taxonomy's texts, by template version */
    private static array $fixed = [];

    /**
     * @param iterable<string> $accountNames the trial balance's account names
     * @return array<string, true>
     */
    public static function of(iterable $accountNames, string $templateVersion): array
    {
        return Wording::words(self::texts($accountNames, $templateVersion));
    }

    /**
     * The known words' printed forms, by normalised form (to correct a misprint to: Wording::check).
     *
     * @param iterable<string> $accountNames
     * @return array<string, string>
     */
    public static function surfaces(iterable $accountNames, string $templateVersion): array
    {
        return Wording::surfaces(self::texts($accountNames, $templateVersion));
    }

    /** @return list<string> every text the known words come from; the words the accountant kept (KnownWord) first */
    private static function texts(iterable $accountNames, string $templateVersion): array
    {
        $synonyms = [];
        foreach (StatementSynonyms::map() as $byWording) {
            $synonyms = [...$synonyms, ...array_keys($byWording)];
        }

        return [...KnownWord::query()->pluck('label')->all(), ...self::fixed($templateVersion), ...$accountNames, ...$synonyms];
    }

    /**
     * General Arabic words (resources/wordlists): words known to be words, so never flagged as unknown; never a
     * correction's target (a misprint is corrected only to a word of the sources above).
     *
     * @return array<string, true> normalised (Wording::normalised)
     */
    public static function general(): array
    {
        static $words = null;
        if ($words === null) {
            $words = array_fill_keys(array_filter(file(resource_path('wordlists/ar-common.txt'), FILE_IGNORE_NEW_LINES) ?: []), true);
            foreach (file(resource_path('wordlists/ar-accounting.txt'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $words += array_fill_keys(Wording::normalised($line), true);
                }
            }
        }

        return $words;
    }

    /** @return list<string> a run's account names */
    public static function accountNames(?ClassificationRun $run): array
    {
        return array_values(array_filter(array_map(fn ($r) => $r['account_name'] ?? null, $run?->result['data'] ?? []), 'is_string'));
    }

    private static function fixed(string $version): array
    {
        if (isset(self::$fixed[$version])) {
            return self::$fixed[$version];
        }
        $texts = [];
        // A word may be split over runs of text: only a paragraph's or a string's end separates words.
        foreach (['notes.docx' => ['word/document.xml', '</w:p>'], 'statements.xlsx' => ['xl/sharedStrings.xml', '</si>']] as $file => [$part, $end]) {
            $zip = new ZipArchive;
            if ($zip->open(resource_path("templates/{$version}/{$file}")) === true) {
                $texts[] = strip_tags(str_replace($end, ' '.$end, (string) $zip->getFromName($part)));
                $zip->close();
            }
        }
        $config = config('classification');
        array_walk_recursive($config, function ($v, $k) use (&$texts) {
            $texts[] = is_string($v) ? $v : '';
            $texts[] = is_string($k) ? $k : '';
        });
        $lang = (array) trans('statements', [], 'ar');
        array_walk_recursive($lang, function ($v) use (&$texts) {
            $texts[] = is_string($v) ? $v : '';
        });

        return self::$fixed[$version] = array_values(array_filter($texts));
    }
}
