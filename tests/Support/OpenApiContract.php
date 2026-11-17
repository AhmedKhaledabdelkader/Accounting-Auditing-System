<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks real responses against public/docs/openapi.yaml: the body must validate against the schema documented for that
 * path, method and status, and must not carry fields the schema doesn't document. Records which (operation, status)
 * pairs were checked, so a test can prove every documented status is exercised.
 */
final class OpenApiContract
{
    private const URI = 'https://contract.local/openapi.json';

    private static ?self $instance = null;

    private object $spec;
    private Validator $validator;

    /** @var array<string, array<string, true>> "METHOD /path" => status => checked */
    public static array $checked = [];

    public static function get(): self
    {
        return self::$instance ??= new self;
    }

    private function __construct()
    {
        $this->spec = Yaml::parseFile(dirname(__DIR__, 2).'/public/docs/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
        $this->validator = new Validator;
        $this->validator->setMaxErrors(20);
        $this->validator->resolver()->registerRaw($this->spec, self::URI);
    }

    /** @return array<string, list<string>> "METHOD /path" => documented statuses */
    public function documented(): array
    {
        $out = [];
        foreach ($this->spec->paths as $path => $ops) {
            foreach ($ops as $method => $op) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $out[strtoupper($method).' '.$path] = array_map('strval', array_keys((array) $op->responses));
                }
            }
        }

        return $out;
    }

    /**
     * Problems of one response (empty = matches the spec).
     *
     * @param  string  $operation  "METHOD /api/v1/…" as in the spec (path template)
     * @return list<string>
     */
    public function problems(string $operation, int $status, ?string $contentType, string $body): array
    {
        [$method, $path] = explode(' ', $operation, 2);
        $op = $this->spec->paths->{$path}->{strtolower($method)} ?? null;
        if (! $op) {
            throw new RuntimeException("Not in the spec: {$operation}");
        }
        self::$checked[$operation][(string) $status] = true;

        $responses = (array) $op->responses;
        if (! isset($responses[(string) $status])) {
            return ["status {$status} is not documented (documented: ".implode(', ', array_keys($responses)).')'];
        }
        [$response, $pointer] = $this->deref($responses[(string) $status], '/paths/'.self::escape($path).'/'.strtolower($method).'/responses/'.$status);

        $content = (array) ($response->content ?? []);
        if (! $content) {
            return trim($body) === '' ? [] : ["status {$status} documents no body, but the response has one: ".mb_substr($body, 0, 120)];
        }
        $type = strtolower(trim(explode(';', (string) $contentType)[0]));
        if (! isset($content[$type])) {
            return ["content type '{$type}' is not documented for {$status} (documented: ".implode(', ', array_keys($content)).')'];
        }
        if ($type !== 'application/json') {
            return trim($body) === '' ? ["status {$status} {$type}: empty body"] : [];   // a download: the documented type is enough
        }
        if (! isset($content[$type]->schema)) {
            return [];
        }

        $data = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['body is not JSON: '.mb_substr($body, 0, 120)];
        }
        $schemaPointer = $pointer.'/content/'.self::escape($type).'/schema';
        $problems = $this->validate($data, $schemaPointer);

        return array_merge($problems, array_map(fn ($p) => "undocumented field: {$p}", $this->undocumented($data, $this->node($schemaPointer), '$')));
    }

    /** Validates any value against a spec node given by JSON pointer (e.g. a request example against its schema). */
    public function validate(mixed $data, string $pointer): array
    {
        // A JSON pointer in a URI fragment is percent-encoded ({id} → %7Bid%7D).
        $result = $this->validator->validate($data, self::URI.'#'.implode('/', array_map('rawurlencode', explode('/', $pointer))));
        if ($result->isValid()) {
            return [];
        }
        $out = [];
        foreach ((new ErrorFormatter)->format($result->error(), true) as $at => $messages) {
            foreach ($messages as $m) {
                $out[] = "schema: {$at}: {$m}";
            }
        }

        return $out;
    }

    /**
     * Fields present in $data that no (sub)schema documents. Only objects whose schema lists `properties` and doesn't
     * allow free extra keys are checked; oneOf/anyOf use the branch the value validates against.
     *
     * @return list<string>
     */
    private function undocumented(mixed $data, ?object $schema, string $at): array
    {
        if ($schema === null) {
            return [];
        }
        $schema = $this->resolve($schema);
        $out    = [];

        foreach (['oneOf', 'anyOf'] as $k) {
            if (isset($schema->{$k})) {
                foreach ($schema->{$k} as $branch) {
                    if ($this->validator->validate($data, $this->inline($branch))->isValid()) {
                        return $this->undocumented($data, $branch, $at);
                    }
                }

                return [];
            }
        }

        if (is_object($data)) {
            [$props, $free] = $this->properties($schema);
            if ($props === null) {
                return [];
            }
            foreach (get_object_vars($data) as $key => $value) {
                if (! array_key_exists($key, $props)) {
                    $free || $out[] = "{$at}.{$key}";
                    continue;
                }
                array_push($out, ...$this->undocumented($value, $props[$key], "{$at}.{$key}"));
            }
        } elseif (is_array($data) && isset($schema->items)) {
            foreach ($data as $i => $item) {
                array_push($out, ...$this->undocumented($item, $schema->items, "{$at}[{$i}]"));
                if ($i >= 50) {
                    break;   // the shape repeats; keep reports short
                }
            }
        }

        return $out;
    }

    /** @return array{0: ?array<string, object>, 1: bool} properties of the object schema (allOf merged), and whether other keys are allowed */
    private function properties(object $schema): array
    {
        $props = null;
        $free  = isset($schema->additionalProperties) && $schema->additionalProperties !== false;
        foreach (array_merge([$schema], $schema->allOf ?? []) as $part) {
            $part = $this->resolve($part);
            if ($part !== $schema && isset($part->allOf)) {
                [$p, $f] = $this->properties($part);
                $p !== null && $props = ($props ?? []) + $p;
                $free = $free || $f;
                continue;
            }
            if (isset($part->properties)) {
                $props = ($props ?? []) + (array) $part->properties;
            }
            if (isset($part->additionalProperties) && $part->additionalProperties !== false) {
                $free = true;
            }
        }

        return [$props, $free];
    }

    private function resolve(object $schema): object
    {
        while (isset($schema->{'$ref'})) {
            $schema = $this->node(substr($schema->{'$ref'}, 1));
        }

        return $schema;
    }

    /** A sub-schema as a standalone schema that still resolves the spec's #/components refs. */
    private function inline(object $schema): object
    {
        return json_decode(json_encode(['$id' => 'https://contract.local/inline/'.spl_object_id($schema).'.json'] + (array) $this->rebase($schema)));
    }

    private function rebase(mixed $v): mixed
    {
        if (is_object($v)) {
            $o = new \stdClass;
            foreach (get_object_vars($v) as $k => $x) {
                $o->{$k} = $k === '$ref' && is_string($x) && str_starts_with($x, '#') ? self::URI.$x : $this->rebase($x);
            }

            return $o;
        }

        return is_array($v) ? array_map(fn ($x) => $this->rebase($x), $v) : $v;
    }

    /** @return array{0: object, 1: string} the node and its pointer, following a $ref */
    private function deref(object $node, string $pointer): array
    {
        while (isset($node->{'$ref'})) {
            $pointer = substr($node->{'$ref'}, 1);
            $node    = $this->node($pointer);
        }

        return [$node, $pointer];
    }

    private function node(string $pointer): ?object
    {
        $node = $this->spec;
        foreach (array_slice(explode('/', $pointer), 1) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);
            $node = is_array($node) ? ($node[(int) $part] ?? null) : ($node->{$part} ?? null);
            if ($node === null) {
                return null;
            }
        }

        return is_object($node) ? $node : null;
    }

    private static function escape(string $s): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $s);
    }
}
