<?php

namespace App\Services\Statements;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * The notes template (resources/templates/<version>/notes.docx) read as text (PR 11): its numbered notes in order, each
 * with its paragraphs and tables, and the blocks around them. The wording lives in the template only; nothing of it is
 * written in code. NarrativeRenderer fills it; the docx export (PR 15) fills the file itself.
 *
 * A note starts at a paragraph of the notes' numbering (level 0). Block markers (`${name}` … `${/name}`, own paragraphs)
 * are not items: each item lists the blocks it sits in, outermost first.
 */
final class NotesTemplate
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @var array<string, array> path|mtime → parsed */
    private static array $cache = [];

    /**
     * @return array{notes: list<array{title: string, block: ?string, items: list<array>}>, blocks: list<string>}
     *         item: {type: paragraph, text, style: title|heading|item|text, sub, blocks} (sub: numbered «N-k» under its note) | {type: table, rows: list<list<string>>, rtl: bool, blocks}
     */
    public static function load(string $version): array
    {
        $path = resource_path("templates/{$version}/notes.docx");
        $key  = $path.'|'.@filemtime($path);

        return self::$cache[$key] ??= self::parse($path);
    }

    private static function parse(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw new RuntimeException("Can't read the notes template {$path}");
        }
        $zip->close();
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $xp = self::xpath($dom);
        ['nodes' => $nodes, 'blocks' => $blocks] = self::walk($xp);

        $notes = [];
        foreach ($nodes as $n) {
            if ($n['kind'] === 'title') {
                // A note's own block is the innermost one open at its title.
                $notes[] = ['title' => $n['text'], 'block' => $n['blocks'] !== [] ? end($n['blocks']) : null, 'items' => []];
            } elseif ($n['kind'] === 'table' && $n['note'] !== null) {
                $notes[$n['note']]['items'][] = ['type' => 'table', 'rows' => self::cells($xp, $n['node']),
                    'rtl' => $xp->query('./w:tblPr/w:bidiVisual', $n['node'])->length > 0, 'blocks' => $n['blocks']];
            } elseif ($n['kind'] === 'paragraph') {
                $t = $n['text'];
                $style = match (true) {
                    $n['numbered'] && $n['level'] >= 1 => 'heading',
                    mb_strlen($t) < 60 && ! str_contains($t, '${') && ! preg_match('/([.:،؛]| أو)$/u', $t) => 'heading',
                    $n['numbered'] => 'item',
                    default => 'text',
                };
                $notes[$n['note']]['items'][] = ['type' => 'paragraph', 'text' => $t, 'style' => $style, 'sub' => $n['sub'], 'blocks' => $n['blocks']];
            }
        }

        return ['notes' => $notes, 'blocks' => $blocks];
    }

    public static function xpath(DOMDocument $dom): DOMXPath
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W);

        return $xp;
    }

    public static function text(DOMXPath $xp, DOMElement $node): string
    {
        return implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($xp->query('.//w:t', $node))));
    }

    /**
     * The body's paragraphs and tables in order, as the notes see them (the docx export, PR 15, walks the same way):
     * kind `title` (a note's title: its numbering, level 0), `paragraph` (the note's n-th, `p`), `table`, `marker` (a
     * block's own paragraph), `empty` (no text), `before` (text before the first note). `note`: the note's index (null
     * before the first); `blocks`: the blocks open around it, outermost first; `sub`: a heading the notes' numbering
     * numbers under its note (level 1: «5-1»).
     *
     * @return array{nodes: list<array{node: DOMElement, kind: string, note: ?int, p: ?int, blocks: list<string>, text: string, numbered: bool, level: int, sub: bool}>, blocks: list<string>}
     */
    public static function walk(DOMXPath $xp): array
    {
        $body = $xp->query('/w:document/w:body')->item(0);
        $children = [];
        foreach ($body->childNodes as $node) {
            if ($node instanceof DOMElement && in_array($node->localName, ['p', 'tbl'], true)) {
                $children[] = $node;
            }
        }

        // Blocks: the names that have a closing marker.
        $blocks = [];
        foreach ($children as $node) {
            if ($node->localName === 'p' && preg_match('/^\$\{\/([a-z0-9_]+)\}$/', trim(self::text($xp, $node)), $m)) {
                $blocks[] = $m[1];
            }
        }
        $numbering = null;   // the notes' numbering: the first numbered paragraph's
        $note  = null;
        $p     = 0;
        $stack = [];
        $nodes = [];
        foreach ($children as $node) {
            $item = ['node' => $node, 'note' => $note, 'p' => null, 'blocks' => $stack, 'text' => '', 'numbered' => false, 'level' => 0, 'sub' => false];
            if ($node->localName === 'tbl') {
                $nodes[] = ['kind' => 'table'] + $item;
                continue;
            }
            $t = trim(self::text($xp, $node));
            if (preg_match('/^\$\{(\/?)([a-z0-9_]+)\}$/', $t, $m) && in_array($m[2], $blocks, true)) {
                if ($m[1] === '/') {
                    array_pop($stack);
                } else {
                    $stack[] = $m[2];
                }
                $nodes[] = ['kind' => 'marker'] + $item;
                continue;
            }
            if ($t === '') {
                $nodes[] = ['kind' => 'empty'] + $item;
                continue;
            }
            $num = $xp->query('./w:pPr/w:numPr/w:numId/@w:val', $node)->item(0)?->value;
            $lvl = (int) ($xp->query('./w:pPr/w:numPr/w:ilvl/@w:val', $node)->item(0)?->value ?? 0);
            $numbering ??= $num;
            $item = ['text' => $t, 'numbered' => $num !== null, 'level' => $lvl, 'sub' => $num !== null && $num === $numbering && $lvl === 1] + $item;
            if ($num !== null && $num === $numbering && $lvl === 0) {
                $note = $note === null ? 0 : $note + 1;
                $p = 0;
                $nodes[] = ['kind' => 'title', 'note' => $note] + $item;
                continue;
            }
            $nodes[] = $note === null ? ['kind' => 'before'] + $item : ['kind' => 'paragraph', 'p' => ++$p] + $item;
        }

        return ['nodes' => $nodes, 'blocks' => $blocks];
    }

    /** @return list<list<string>> a table's cell texts, row by row; a cell's paragraphs joined by a line break */
    public static function cells(DOMXPath $xp, DOMElement $table): array
    {
        $rows = [];
        foreach ($xp->query('./w:tr', $table) as $tr) {
            $cells = [];
            foreach ($xp->query('./w:tc', $tr) as $tc) {
                $cells[] = trim(implode("\n", array_filter(array_map(fn ($p) => trim(self::text($xp, $p)), iterator_to_array($xp->query('.//w:p', $tc))), fn ($s) => $s !== '')));
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
