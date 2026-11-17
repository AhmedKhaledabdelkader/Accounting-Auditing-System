<?php

namespace App\Support\Llm;

/** One forced tool call, provider-neutral: what ClassifierDriver::classify() sends. */
final class ToolCall
{
    /**
     * @param  list<array{text: string, cache?: bool}>  $system  system prompt parts; `cache` marks the end of a prefix
     *                                                          identical for every request of a file
     * @param  array{name: string, description: string, schema: array, strict?: bool}  $tool  the tool the model must call
     *                                                          (JSON Schema input); strict = enforce the schema where the provider can
     * @param  bool  $warmUp  only writes the prompt cache (its answer is ignored); providers that cache implicitly send nothing
     * @param  string|null  $pdf  a PDF's bytes, sent before the user message (last year's issued statements); with $mime an image's
     * @param  string|null  $mime  the file's media type (default application/pdf; image/jpeg and image/png for a photographed document)
     */
    public function __construct(
        public readonly array $system,
        public readonly array $tool,
        public readonly string $content,
        public readonly int $maxTokens = 16000,
        public readonly bool $warmUp = false,
        public readonly ?string $pdf = null,
        public readonly ?string $mime = null,
    ) {}
}
