<?php

namespace App\Exceptions;

use App\Support\Phrase;
use RuntimeException;

/** The AI's column mapping still failed the structure checks after the retry. */
class StructureNotDetected extends RuntimeException
{
    /**
     * @param  list<string>  $problems  user-facing (Arabic) messages of the checks that failed
     * @param  array|null  $structure  the rejected mapping
     * @param  list<?array>  $problemPhrases  the same messages as stored phrases (parallel to $problems), for any language
     */
    public function __construct(public readonly array $problems, public readonly ?array $structure = null, public readonly array $problemPhrases = [])
    {
        parent::__construct($this->phrase()->ar());
    }

    /** @return list<Phrase> */
    public function problemPhrases(): array
    {
        return array_map(fn ($text, $i) => is_array($this->problemPhrases[$i] ?? null) ? Phrase::fromArray($this->problemPhrases[$i]) : Phrase::raw($text),
            $this->problems, array_keys($this->problems));
    }

    public function phrase(): Phrase
    {
        return Phrase::make('classification_messages.errors.structure_not_detected', ['problems' => Phrase::join($this->problemPhrases(), "\n- ")]);
    }
}
