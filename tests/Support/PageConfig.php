<?php

namespace Tests\Support;

use DOMDocument;
use DOMXPath;

/** Reads a page's #app-config data- attributes the way the browser does (entities decoded once, as `dataset`). */
trait PageConfig
{
    /** @return array<string, string> attribute name without «data-» => decoded value */
    protected function appConfig(string $html): array
    {
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $node = (new DOMXPath($dom))->query('//*[@id="app-config"]')->item(0);
        $this->assertNotNull($node, 'the page has no #app-config element');

        $out = [];
        foreach ($node->attributes as $attr) {
            str_starts_with($attr->name, 'data-') && $out[substr($attr->name, 5)] = $attr->value;
        }

        return $out;
    }
}
