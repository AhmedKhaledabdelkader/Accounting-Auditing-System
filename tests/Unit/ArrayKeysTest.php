<?php

namespace Tests\Unit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * No array literal in app/ or config/ gives the same key twice: PHP keeps the last silently, so a copy edited on one side
 * only would be lost, and both sides are computed.
 */
class ArrayKeysTest extends TestCase
{
    public function test_no_array_literal_repeats_a_key(): void
    {
        $parser = (new ParserFactory)->createForHostVersion();
        $finder = new NodeFinder;
        $repeated = [];
        foreach ([app_path(), config_path()] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach ($finder->findInstanceOf($parser->parse((string) file_get_contents($file->getPathname())) ?? [], Node\Expr\Array_::class) as $array) {
                    $seen = [];
                    foreach ($array->items as $item) {
                        $key = $item?->key;
                        if (! $key instanceof Node\Scalar\String_ && ! $key instanceof Node\Scalar\Int_) {
                            continue;
                        }
                        if (isset($seen[$key->value])) {
                            $repeated[] = str_replace(base_path().'/', '', $file->getPathname()).":{$key->getStartLine()} '{$key->value}'";
                        }
                        $seen[$key->value] = true;
                    }
                }
            }
        }

        $this->assertSame([], $repeated);
    }
}
