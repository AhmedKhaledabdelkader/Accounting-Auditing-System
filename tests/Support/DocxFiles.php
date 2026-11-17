<?php

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/** Reading a docx export in tests (PR 15), and what a version shows, to compare the two. */
trait DocxFiles
{
    protected const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @return array<string, string> part name → content */
    protected function docxParts(string $file): array
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-test');
        file_put_contents($path, $file);
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            }
            $zip->close();

            return $parts;
        } finally {
            unlink($path);
        }
    }

    protected function docxXpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_PARSEHUGE);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::WORD);

        return $xp;
    }

    protected function docxText(DOMXPath $xp, DOMElement $node): string
    {
        return trim(implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($xp->query('.//w:t', $node)))));
    }

    /** The body's paragraphs (not in tables) with text, and the tables as rows of cell texts, in order. */
    protected function docxBody(string $file): array
    {
        $xp = $this->docxXpath($this->docxParts($file)['word/document.xml']);
        $paragraphs = $tables = [];
        foreach ($xp->query('/w:document/w:body/*') as $node) {
            if ($node->localName === 'p' && ($t = $this->docxText($xp, $node)) !== '') {
                $paragraphs[] = $t;
            } elseif ($node->localName === 'tbl') {
                $tables[] = array_map(fn ($tr) => array_map(fn ($tc) => $this->docxText($xp, $tc), iterator_to_array($xp->query('./w:tc', $tr))),
                    iterator_to_array($xp->query('./w:tr', $node)));
            }
        }

        return ['paragraphs' => $paragraphs, 'tables' => $tables, 'xp' => $xp];
    }

    /**
     * What the version shows: its visible notes' titles, paragraphs and tables (cells in reading order); `sequence`:
     * titles and paragraphs in order, as the file's body paragraphs should read from the first note on (Word numbers
     * the headings itself); `printed`: the paragraphs with their headings' numbers, «2-1 …», as the PDF prints them.
     */
    protected function versionShows(string $id, int $version = 1): array
    {
        $payload = $this->getJson("/api/v1/financial-reports/{$id}/versions/{$version}")->assertOk()->json('payload');
        $titles = $paragraphs = $printed = $tables = $sequence = [];
        foreach ($payload['notes'] as $n) {
            if (! $n['visible']) {
                continue;
            }
            $titles[] = [$n['number'], $n['title_ar']];
            $sequence[] = $n['title_ar'];
            foreach ($n['blocks'] as $b) {
                if ($b['type'] === 'text') {
                    $paragraphs[] = $b['text_ar'];
                    $printed[] = ($b['number'] ?? null) !== null ? "{$b['number']} {$b['text_ar']}" : $b['text_ar'];
                    $sequence[] = $b['text_ar'];
                } else {
                    $tables[] = array_map(fn ($r) => array_map(fn ($c) => isset($c['amount']) ? $this->docxMoney($c['amount']['amount']) : $c['text_ar'], $r['cells']), $b['rows']);
                }
            }
        }

        return ['titles' => $titles, 'paragraphs' => $paragraphs, 'printed' => $printed, 'tables' => $tables, 'sequence' => $sequence, 'payload' => $payload];
    }

    protected function docxMoney(?float $v): string
    {
        return match (true) {
            $v === null         => '[ناقص]',
            round($v) == 0      => '-',
            default             => $v < 0 ? '('.number_format(abs(round($v))).')' : number_format(round($v)),
        };
    }

    /** A table's cells in reading order (right to left), however the template laid it out. */
    protected function docxReadingOrder(DOMXPath $xp, int $index, array $rows): array
    {
        $tbl = $xp->query('/w:document/w:body/w:tbl')->item($index);
        return $xp->query('./w:tblPr/w:bidiVisual', $tbl)->length ? $rows : array_map('array_reverse', $rows);
    }

    /** The file's body paragraphs from the first note's title on (a draft's own page comes before). */
    protected function docxFromFirstNote(array $docx, array $shown): array
    {
        $first = array_search($shown['sequence'][0], $docx['paragraphs'], true);

        return $first === false ? $docx['paragraphs'] : array_slice($docx['paragraphs'], $first);
    }
}
