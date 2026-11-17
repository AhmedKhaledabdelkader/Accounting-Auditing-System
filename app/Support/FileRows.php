<?php

namespace App\Support;

/**
 * How a row of the uploaded file is named in the messages the user reads (PR 13b): «الصف 10 في الملف», or for several
 * «الصفوف 10، 12 في الملف» (the first few, then how many more). Never «R10». Worded in any language by phrase().
 */
final class FileRows
{
    /** @param list<int> $rows */
    public static function text(array $rows, int $shown = 10): string
    {
        return self::phrase($rows, $shown)->ar();
    }

    /** @param list<int> $rows */
    public static function phrase(array $rows, int $shown = 10): Phrase
    {
        $rows = array_values(array_unique(array_map('intval', $rows)));
        if (count($rows) === 1) {
            return Phrase::make('classification_messages.file_rows.one', ['row' => $rows[0]]);
        }
        $rest = count($rows) - $shown;

        return $rest > 0
            ? Phrase::make('classification_messages.file_rows.many_more', ['rows' => array_slice($rows, 0, $shown), 'rest' => $rest])
            : Phrase::make('classification_messages.file_rows.many', ['rows' => $rows]);
    }
}
