<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\OpenApiContract;
use Tests\TestCase;

/** public/docs/openapi.yaml is what the frontend integrates against: it must list exactly the API routes, and resolve. */
class OpenApiSpecTest extends TestCase
{
    private function spec(): array
    {
        return Yaml::parseFile(public_path('docs/openapi.yaml'));
    }

    public function test_every_api_route_is_in_the_spec_and_nothing_else(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $routes[] = strtoupper($method).' /'.$route->uri();
                }
            }
        }
        $documented = [];
        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach (array_intersect(array_keys($operations), ['get', 'post', 'put', 'patch', 'delete']) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
        sort($routes);
        sort($documented);

        $this->assertSame($routes, $documented, 'Routes and public/docs/openapi.yaml differ: update the spec with the route.');
    }

    public function test_every_reference_resolves_and_every_operation_is_described(): void
    {
        $spec = $this->spec();
        $refs = [];
        array_walk_recursive($spec, function ($v, $k) use (&$refs) {
            $k === '$ref' && $refs[] = $v;
        });
        foreach (array_unique($refs) as $ref) {
            $node = $spec;
            foreach (explode('/', substr($ref, 2)) as $part) {
                $this->assertArrayHasKey($part, $node, "Unresolved \$ref {$ref}");
                $node = $node[$part];
            }
        }

        $ids = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $op) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }
                $this->assertNotEmpty($op['summary'] ?? null, "{$method} {$path} has no summary");
                $this->assertNotEmpty($op['responses'] ?? null, "{$method} {$path} has no responses");
                $this->assertNotEmpty($op['operationId'] ?? null, "{$method} {$path} has no operationId");
                $ids[] = $op['operationId'];
            }
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'operationId must be unique (client generators use it).');
    }

    /** Examples are what frontend developers copy: each must validate against the schema it illustrates. */
    public function test_every_example_matches_its_schema(): void
    {
        $spec = Yaml::parseFile(public_path('docs/openapi.yaml'), Yaml::PARSE_OBJECT_FOR_MAP);
        $esc  = fn ($s) => str_replace(['~', '/'], ['~0', '~1'], $s);
        $problems = [];
        $count    = 0;
        $check = function ($media, string $pointer) use (&$problems, &$count, $spec) {
            if (! isset($media->schema)) {
                return;
            }
            $examples = isset($media->example) ? ['example' => $media->example] : [];
            foreach ((array) ($media->examples ?? []) as $name => $ex) {
                if (isset($ex->{'$ref'})) {
                    $node = $spec;
                    foreach (array_slice(explode('/', $ex->{'$ref'}), 1) as $part) {
                        $node = $node->{$part};
                    }
                    $ex = $node;
                }
                isset($ex->value) && $examples["examples/{$name}"] = $ex->value;
            }
            foreach ($examples as $name => $value) {
                $count++;
                foreach (OpenApiContract::get()->validate($value, $pointer.'/schema') as $p) {
                    $problems[] = "{$pointer} ({$name}): {$p}";
                }
            }
        };
        $resolve = function ($node, string $pointer) use ($spec) {
            while (isset($node->{'$ref'})) {
                $pointer = substr($node->{'$ref'}, 1);
                $node    = $spec;
                foreach (array_slice(explode('/', $pointer), 1) as $part) {
                    $node = $node->{str_replace(['~1', '~0'], ['/', '~'], $part)};
                }
            }

            return [$node, $pointer];
        };
        foreach ($spec->paths as $path => $ops) {
            foreach ($ops as $method => $op) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }
                $base = '/paths/'.$esc($path).'/'.$method;
                if (isset($op->requestBody)) {
                    [$body, $ptr] = $resolve($op->requestBody, $base.'/requestBody');
                    foreach ((array) ($body->content ?? []) as $type => $media) {
                        $check($media, $ptr.'/content/'.$esc($type));
                    }
                }
                foreach ((array) $op->responses as $status => $response) {
                    [$response, $ptr] = $resolve($response, $base.'/responses/'.$status);
                    foreach ((array) ($response->content ?? []) as $type => $media) {
                        $check($media, $ptr.'/content/'.$esc($type));
                    }
                }
            }
        }

        $this->assertGreaterThan(10, $count);
        $this->assertSame([], $problems);
    }
}
