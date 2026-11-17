<?php

namespace App\Exceptions;

use App\Support\Phrase;
use RuntimeException;

/**
 * A failure whose message the user reads: getMessage() is the text stored and returned as before (Arabic, or the
 * English some messages always had), `phrase` the same message in any language.
 */
class LocalizedException extends RuntimeException
{
    public function __construct(public readonly Phrase $phrase, ?string $message = null)
    {
        parent::__construct($message ?? $phrase->ar());
    }

    /** The phrase of any exception: its own, or its message as it is. */
    public static function phraseOf(\Throwable $e): Phrase
    {
        return $e instanceof self ? $e->phrase : ($e instanceof StructureNotDetected ? $e->phrase() : Phrase::raw($e->getMessage()));
    }
}
