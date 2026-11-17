<?php

namespace Tests\Support;

/**
 * The wire format of each AI provider, for fakes: reads a request in either format (told apart by its shape) and writes
 * an answer in the format of a provider. Lets the same tests run with CLASSIFIER_PROVIDER=anthropic or gemini.
 * Plain PHP (no Laravel), so tests/Support/contract/fake-ai-server.php can use it too.
 */
final class FakeWire
{
    public const URLS = ['anthropic' => 'api.anthropic.com/*', 'gemini' => 'generativelanguage.googleapis.com/*'];

    public static function providerOf(array $request): string
    {
        return isset($request['contents']) ? 'gemini' : 'anthropic';
    }

    public static function toolName(array $request): ?string
    {
        return $request['tool_choice']['name'] ?? $request['toolConfig']['functionCallingConfig']['allowedFunctionNames'][0] ?? null;
    }

    /** The tool's input schema as sent. */
    public static function schema(array $request): array
    {
        return $request['tools'][0]['input_schema'] ?? $request['tools'][0]['functionDeclarations'][0]['parametersJsonSchema'] ?? [];
    }

    /** The user message (its text, when a PDF goes with it). */
    public static function userText(array $request): string
    {
        foreach (self::userParts($request) as $part) {
            if (isset($part['text'])) {
                return $part['text'];
            }
        }

        return '';
    }

    /** The PDF sent with the user message (its bytes), or null. */
    public static function pdf(array $request): ?string
    {
        foreach (self::userParts($request) as $part) {
            $data = $part['source']['data'] ?? $part['inlineData']['data'] ?? null;
            if ($data !== null) {
                return base64_decode($data);
            }
        }

        return null;
    }

    /** The user message's parts in either format: text ({text}) and documents ({source} or {inlineData}). */
    private static function userParts(array $request): array
    {
        $content = $request['messages'][0]['content'] ?? null;
        if (is_string($content)) {
            return [['text' => $content]];
        }

        return $content ?? $request['contents'][0]['parts'] ?? [];
    }

    /** @return list<string> the system prompt parts */
    public static function systemParts(array $request): array
    {
        $system = $request['system'] ?? null;
        if ($system !== null) {
            return is_string($system) ? [$system] : array_column($system, 'text');
        }

        return array_column($request['systemInstruction']['parts'] ?? [], 'text');
    }

    /** The minimal call that writes Anthropic's prompt cache (Gemini caches implicitly: never sent). */
    public static function isWarm(array $request): bool
    {
        return ($request['max_tokens'] ?? null) === 1;
    }

    /** @param  array{input?: int, output?: int, cached?: int, cache_write?: int, thoughts?: int}  $usage */
    public static function answer(string $provider, string $tool, array $input, array $usage = ['input' => 100, 'output' => 20]): array
    {
        return $provider === 'gemini'
            ? ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $tool, 'args' => $input]]]], 'finishReason' => 'STOP']],
                'usageMetadata' => array_filter([
                    'promptTokenCount'        => ($usage['input'] ?? 0) + ($usage['cached'] ?? 0),
                    'cachedContentTokenCount' => $usage['cached'] ?? null,
                    'candidatesTokenCount'    => $usage['output'] ?? 0,
                    'thoughtsTokenCount'      => $usage['thoughts'] ?? null,
                ], fn ($v) => $v !== null)]
            : ['id' => 'msg_fake', 'type' => 'message', 'role' => 'assistant', 'model' => 'fake',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_fake', 'name' => $tool, 'input' => $input]],
                'stop_reason' => 'tool_use', 'usage' => array_filter([
                    'input_tokens'                => $usage['input'] ?? 0,
                    'output_tokens'               => ($usage['output'] ?? 0) + ($usage['thoughts'] ?? 0),
                    'cache_read_input_tokens'     => $usage['cached'] ?? null,
                    'cache_creation_input_tokens' => $usage['cache_write'] ?? null,
                ], fn ($v) => $v !== null)];
    }

    /** A provider error body with this HTTP status. */
    public static function error(string $provider, int $status, string $message = 'Internal server error'): array
    {
        return $provider === 'gemini'
            ? ['error' => ['code' => $status, 'message' => $message, 'status' => $status >= 500 ? 'INTERNAL' : 'INVALID_ARGUMENT']]
            : ['type' => 'error', 'error' => ['type' => $status >= 500 ? 'api_error' : 'invalid_request_error', 'message' => $message]];
    }
}
