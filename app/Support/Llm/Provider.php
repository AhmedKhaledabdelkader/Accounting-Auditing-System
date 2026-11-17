<?php

namespace App\Support\Llm;

/** The configured provider and its model, as recorded on runs and imports (config only: the driver is the binding's). */
final class Provider
{
    public static function name(): string
    {
        return (string) config('classification.provider');
    }

    public static function model(): ?string
    {
        return config('classification.providers.'.self::name().'.model');
    }
}
