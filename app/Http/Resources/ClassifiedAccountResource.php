<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClassifiedAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        $r = $this->resource;

        return [
            'row'                       => $r['row'] ?? null,   // Excel row in the source sheet — rows are keyed by this, not by account number
            'account_number'            => $r['account_number'],
            'account_name'              => $r['account_name'],
            'financial_statement'       => $r['financial_statement'],
            'main_class'                => $r['main_class'],
            'sub_class'                 => $r['sub_class'],
            'opening_debit'             => $r['opening_debit'],
            'opening_credit'            => $r['opening_credit'],
            'period_debit'              => $r['period_debit'],
            'period_credit'             => $r['period_credit'],
            'adjustment_debit'          => $r['adjustment_debit'],     // 0 when the file has no adjustment columns
            'adjustment_credit'         => $r['adjustment_credit'],
            'closing_debit'             => $r['closing_debit'],
            'closing_credit'            => $r['closing_credit'],
            // From the AI only.
            'classification_confidence' => $r['classification_confidence'],
            'classification_reason'     => $r['classification_reason'],
            // Stored only (ClassificationResponse::present turns the *_phrase fields into *_text for the API).
            'classification_reason_phrase' => $r['classification_reason_phrase'] ?? null,
            // From PHP only: [{type, difference, message}]
            'data_flags'                => $r['data_flags'],
            // From the file's remarks column.
            'source_note'               => $r['source_note'],
            // The file's own classification columns, and the main classes Claude mapped that value to.
            'existing_classification'      => $r['existing_classification'] ?? null,
            'existing_note_classification' => $r['existing_note_classification'] ?? null,
            'existing_main_classes'        => $r['existing_main_classes'] ?? null,
            // Auditor requests / PBC items from the file. Informational: never affects needs_review.
            'audit_request'             => $r['audit_request'] ?? null,
            'note_reference'            => $r['note_reference'] ?? null,
            // The heading above the account in the file (a section, a parent or a named zero row with no number; null: none). For the
            // classification rules' heading words.
            'heading'                   => $r['heading'] ?? null,
            // Who gave the class (ai, cache, rule, a cache source) and the classification rule the account matched.
            'classification_source'     => $r['classification_source'] ?? null,
            'classification_rule'       => $r['classification_rule'] ?? null,
            // The classification cache's key for this row (ClassificationKey); null when the row can't be cached.
            'cache_key'                 => $r['cache_key'] ?? null,
            'needs_review'              => $r['needs_review'],
            // Kept for existing consumers: same value as classification_confidence / all notes joined.
            'confidence'                => $r['classification_confidence'],
            'confidence_label'          => $r['classification_confidence'].'%',
            'note'                      => $r['note'],
            'note_phrase'               => $r['note_phrase'] ?? null,
        ];
    }
}
