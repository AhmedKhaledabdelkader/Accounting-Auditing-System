<?php

namespace App\Services\Exports;

use App\Services\Statements\Figure;
use App\Services\Statements\NarrativeRenderer;
use App\Services\Statements\NotesTemplate;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * The notes as a docx (PR 15, plan §4.7): the template's notes.docx filled from a version's payload, so the same version
 * always gives the same file (Q16), and showing what the wizard shows:
 *
 * - the notes the version shows, in the template's order: a hidden note goes, and Word's own numbering renumbers the
 *   rest exactly as the version numbered them (NotesBuilder numbers shown notes 1…N in the same order);
 * - each shown paragraph with its placeholders filled from the version's values (in the run holding them, so the
 *   template's formatting stays), or the user's own wording where they replaced it; dropped blocks and paragraphs go;
 * - each table laid out by NarrativeRenderer::layout(), the wizard's own rules: item rows repeated, rows and category
 *   columns zero in both years hidden (Q13), a first year's prior-year columns gone (§5);
 * - whole riyals, negatives in parentheses, zero «-», Western digits (Q27); a missing figure reads «[ناقص]», never 0.
 *
 * The file is edited as XML (DOM), which keeps every paragraph's right-to-left layout, the fonts and the landscape PPE
 * section; that section goes when nothing is left in it. Page numbers run on from the template's first page. The
 * template's «[review]» comments are dropped. A draft says so on every page and starts with what it still lacks.
 * Every word and number comes from the version: nothing is computed here but the layout.
 */
final class NotesDocx
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const RED = 'C00000';

    private array $values = [];

    public function __construct(private NarrativeRenderer $renderer)
    {
    }

    /**
     * @param array  $payload a version's payload (the report state, plus version and kind)
     * @param string $savedAt when the version was saved (ISO 8601): the file's dates
     * @return string the docx file
     */
    public function build(array $payload, string $savedAt): string
    {
        $path = resource_path("templates/{$payload['template_version']}/notes.docx");
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Can't read the notes template {$path}");
        }
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        $narrative = $payload['narrative'];
        $this->values = self::figures($narrative['values']);
        $items = array_map(fn ($sets) => array_map(fn ($set) => self::figures($set), $sets), $narrative['items'] ?? []);
        $draft = ($payload['kind'] ?? 'draft') !== 'final';

        $parts['word/document.xml'] = $this->document($parts['word/document.xml'], $payload, $narrative['blocks'] ?? [], $items, $draft, $savedAt);
        foreach (array_keys($parts) as $name) {
            if (preg_match('#^word/header\d+\.xml$#', $name)) {
                $parts[$name] = $this->header($parts[$name], $draft);
            }
        }

        // The template's «[review]» comments are for whoever reviews the template, never in a file (template README).
        unset($parts['word/comments.xml']);
        $parts['word/_rels/document.xml.rels'] = preg_replace('#<Relationship [^>]*Target="comments\.xml"[^>]*/>#', '', $parts['word/_rels/document.xml.rels']);
        $parts['[Content_Types].xml'] = preg_replace('#<Override PartName="/word/comments\.xml"[^>]*/>#', '', $parts['[Content_Types].xml']);

        $at = CarbonImmutable::parse($savedAt)->utc();
        $parts['docProps/core.xml'] = $this->core($parts['docProps/core.xml'], $at->format('Y-m-d\TH:i:s\Z'));

        return $this->write($parts, $at->getTimestamp());
    }

    /* ---------- the body ---------- */

    private function document(string $xml, array $payload, array $blocks, array $items, bool $draft, string $savedAt): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $xp = NotesTemplate::xpath($dom);
        $notes = $payload['notes'];
        $firstYear = ($payload['comparative']['source'] ?? null) === 'first_year';
        // The shown paragraphs: key → the version's text (the user's wording when edited).
        $texts = [];
        foreach ($notes as $n) {
            foreach ($n['blocks'] as $b) {
                if (($b['type'] ?? null) === 'text') {
                    $texts[$b['key']] = $b;
                }
            }
        }
        $shown = fn (?int $note, array $open) => ($note === null || ($notes[$note]['visible'] ?? false))
            && array_reduce($open, fn ($ok, $b) => $ok && ($blocks[$b] ?? false), true);

        $remove = [];
        $walked = NotesTemplate::walk($xp)['nodes'];
        foreach ($walked as $n) {
            $node = $n['node'];
            $key  = $n['note'] !== null ? ($notes[$n['note']]['key'] ?? throw new RuntimeException('The version and the notes template differ in number of notes.')) : null;
            switch ($n['kind']) {
                case 'marker':
                    $remove[] = $node;
                    break;
                case 'title':
                    if (! $notes[$n['note']]['visible']) {
                        $remove[] = $node;
                    } else {
                        $this->fill($xp, $node, $this->values);
                    }
                    break;
                case 'paragraph':
                    $text = $texts["{$key}.{$n['p']}"] ?? null;
                    if ($text === null) {
                        $remove[] = $node;
                    } elseif ($text['edited'] ?? false) {
                        $this->replaceText($dom, $xp, $node, $text['text_ar']);
                    } else {
                        $this->fill($xp, $node, $this->values);
                    }
                    break;
                case 'empty':
                    if (! $this->sectionBreak($xp, $node) && ! $shown($n['note'], $n['blocks'])) {
                        $remove[] = $node;
                    }
                    break;
                case 'before':
                    $this->fill($xp, $node, $this->values);
                    break;
                case 'table':
                    if (! $shown($n['note'], $n['blocks']) || ! $this->table($xp, $node, $items, $firstYear)) {
                        $remove[] = $node;
                    }
                    break;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode->removeChild($node);
        }
        $this->reorder($xp, $walked, $notes);

        // The template's yellow marks (where its author was to fill or check) are not the statements' formatting.
        foreach (iterator_to_array($xp->query('//w:rPr/w:highlight')) as $node) {
            $node->parentNode->removeChild($node);
        }
        // Comments: their anchors go with them.
        foreach (iterator_to_array($xp->query('//w:commentRangeStart | //w:commentRangeEnd | //w:r[w:commentReference]')) as $node) {
            $node->parentNode->removeChild($node);
        }
        $this->sections($xp);
        if ($draft) {
            $this->draftPage($dom, $xp, $payload, $savedAt);
        }

        return $dom->saveXML();
    }

    /**
     * The notes in the accountant's order (NotesBuilder::order; the shown notes' numbers say it): each note's part of the body
     * (its title and all that follows it up to the next note's) moved into that order; Word's numbering follows the document.
     * Left in the template's order when the order is the template's, or when a section break sits among the notes (moving it
     * would move the pages' layout with it).
     */
    private function reorder(DOMXPath $xp, array $walked, array $notes): void
    {
        $shown = array_keys(array_filter($notes, fn ($n) => $n['visible'] ?? false));
        $wanted = $shown;
        usort($wanted, fn ($a, $b) => (int) $notes[$a]['number'] <=> (int) $notes[$b]['number']);
        if ($wanted === $shown) {
            return;
        }
        $noteOf = new \SplObjectStorage;
        foreach ($walked as $n) {
            if ($n['note'] !== null && $n['node']->parentNode !== null) {
                $noteOf[$n['node']] = $n['note'];
            }
        }
        $body = $xp->query('/w:document/w:body')->item(0);
        $groups = [];
        $current = null;
        $end = null;
        foreach (iterator_to_array($body->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'sectPr') {
                $end = $child;
                break;
            }
            $current = $noteOf->contains($child) ? $noteOf[$child] : $current;
            if ($current === null) {
                continue;   // before the first note
            }
            $groups[$current][] = $child;
        }
        $breaks = fn (array $g) => $g !== [] && $xp->query('./w:pPr/w:sectPr', end($g))->length > 0;
        $template = array_keys($groups);
        $order = [...array_values(array_filter($wanted, fn ($n) => isset($groups[$n]))), ...array_values(array_diff($template, $wanted))];
        $previous = null;
        foreach ($order as $note) {
            // A note that is a section of its own (the PPE note, landscape: the note before it in the template ends with a
            // section break, and so does it) needs a break before it wherever it goes: the one that came before it, copied.
            $i = array_search($note, $template, true);
            $before = $i > 0 ? $groups[$template[$i - 1]] : [];
            if ($breaks($groups[$note]) && $breaks($before) && $previous !== null && ! $breaks($groups[$previous])) {
                $copy = end($before)->cloneNode(true);
                $end === null ? $body->appendChild($copy) : $body->insertBefore($copy, $end);
            }
            foreach ($groups[$note] as $child) {
                $end === null ? $body->appendChild($child) : $body->insertBefore($child, $end);
            }
            $previous = $note;
        }
    }

    /**
     * A table laid out as the wizard shows it: its rows built from the template's (repeated per item, hidden at zero),
     * its cells filled, the hidden columns gone and the remaining ones widened to the table's width.
     *
     * @return bool whether anything is left of it
     */
    private function table(DOMXPath $xp, DOMElement $tbl, array $items, bool $firstYear): bool
    {
        $grown = $this->repeatColumns($xp, $tbl);
        $item = ['rows' => NotesTemplate::cells($xp, $tbl), 'rtl' => $xp->query('./w:tblPr/w:bidiVisual', $tbl)->length > 0];
        $rows = $this->renderer->layout($item, $this->values, $items, $firstYear)['rows'];
        if ($rows === []) {
            return false;
        }
        $template = iterator_to_array($xp->query('./w:tr', $tbl));
        $dropped = false;
        foreach ($rows as $r) {
            $tr = $template[$r['source']]->cloneNode(true);
            foreach (iterator_to_array($xp->query('./w:tc', $tr)) as $c => $tc) {
                if (! array_key_exists($c, $r['cells'])) {
                    $tr->removeChild($tc);
                    $dropped = true;
                    continue;
                }
                $this->fill($xp, $tc, $r['set'] + $this->values);
            }
            $tbl->appendChild($tr);
        }
        foreach ($template as $tr) {
            $tbl->removeChild($tr);
        }
        if ($dropped || $grown) {
            $this->regrid($xp, $tbl, $grown);
        }
        $this->oneType($xp, $tbl);

        return true;
    }

    /**
     * One size and weight for a table's words and figures alike (the accountant's review): the template's cells mix
     * sizes (12, 13 and 14 pt in one table) and a heading or two not bold. Every run takes the size its figures mostly
     * have, and the weight most of its runs have; Arabic sizes by w:szCs, so both sizes are set.
     */
    private function oneType(DOMXPath $xp, DOMElement $tbl): void
    {
        $runs = array_filter(iterator_to_array($xp->query('.//w:r[w:t]', $tbl)), fn ($r) => trim($r->textContent) !== '');
        if ($runs === []) {
            return;
        }
        $size = fn (DOMElement $r, string $tag) => $xp->query("./w:rPr/w:{$tag}/@w:val", $r)->item(0)?->value;
        $figures = array_filter($runs, fn ($r) => preg_match('/^[\d,.()\-–%\s]+$/u', $r->textContent) === 1);
        $sizes = array_count_values(array_filter(array_map(fn ($r) => $size($r, 'szCs') ?? $size($r, 'sz'), $figures ?: $runs)));
        if ($sizes === []) {
            return;
        }
        arsort($sizes);
        $to = (string) array_key_first($sizes);
        $bold = fn (DOMElement $r) => $xp->query('./w:rPr/w:b[not(@w:val) or @w:val="1" or @w:val="true"]', $r)->length > 0;
        $isBold = count(array_filter($runs, $bold)) * 2 >= count($runs);

        foreach ($runs as $r) {
            $rPr = $xp->query('./w:rPr', $r)->item(0);
            if ($rPr === null) {
                $rPr = $r->insertBefore($r->ownerDocument->createElementNS(self::W, 'w:rPr'), $r->firstChild);
            }
            foreach (['b', 'bCs'] as $tag) {
                foreach (iterator_to_array($xp->query("./w:{$tag}", $rPr)) as $old) {
                    $rPr->removeChild($old);
                }
            }
            if ($isBold) {
                // Right after the style and fonts, where the schema has them.
                $at = $xp->query('./w:*[not(self::w:rStyle or self::w:rFonts)]', $rPr)->item(0);
                foreach (['b', 'bCs'] as $tag) {
                    $rPr->insertBefore($r->ownerDocument->createElementNS(self::W, "w:{$tag}"), $at);
                }
            }
            foreach (['sz', 'szCs'] as $tag) {
                $el = $xp->query("./w:{$tag}", $rPr)->item(0);
                if ($el === null) {
                    $el = $r->ownerDocument->createElementNS(self::W, "w:{$tag}");
                    $after = $tag === 'sz' ? $xp->query('./w:szCs', $rPr)->item(0) : null;
                    $after ??= $xp->query('./w:highlight | ./w:u | ./w:effect | ./w:bdr | ./w:shd | ./w:fitText | ./w:vertAlign | ./w:rtl | ./w:cs | ./w:em | ./w:lang | ./w:eastAsianLayout | ./w:specVanish | ./w:oMath', $rPr)->item(0);
                    $rPr->insertBefore($el, $after);
                }
                $el->setAttributeNS(self::W, 'w:val', $to);
            }
        }
    }

    /**
     * A repeated column (NarrativeRenderer::repeats, the PPE categories) made in the file: its cell cloned per set, each
     * copy's placeholders numbered, in the run that holds them.
     *
     * @return bool whether a column was repeated
     */
    private function repeatColumns(DOMXPath $xp, DOMElement $tbl): bool
    {
        $plan = NarrativeRenderer::repeats(NotesTemplate::cells($xp, $tbl), $this->values);
        $grown = false;
        foreach (iterator_to_array($xp->query('./w:tr', $tbl)) as $r => $tr) {
            if (! array_filter($plan[$r], fn ($p) => $p[1] !== null)) {
                continue;
            }
            $tcs = iterator_to_array($xp->query('./w:tc', $tr));
            foreach ($plan[$r] as [$c, $copy]) {
                $tc = $copy === null ? $tcs[$c] : $tcs[$c]->cloneNode(true);
                foreach ($copy === null ? [] : $xp->query('.//w:t', $tc) as $t) {
                    $t->textContent = NarrativeRenderer::numbered($t->textContent, $copy);
                }
                $tr->appendChild($tc);   // in the plan's order (a kept cell moves to its place)
            }
            foreach ($plan[$r] as [$c, $copy]) {
                if ($copy !== null && $tcs[$c]->parentNode === $tr) {
                    $tr->removeChild($tcs[$c]);   // the template's cell, now copied
                }
            }
            $grown = true;
        }

        return $grown;
    }

    /**
     * After columns went or were repeated: the grid rebuilt from the widest row, the cells resized in proportion to keep
     * the table's width, and a cell that spanned the whole table spanning the new grid.
     */
    private function regrid(DOMXPath $xp, DOMElement $tbl, bool $grown = false): void
    {
        $grid = $xp->query('./w:tblGrid', $tbl)->item(0);
        $oldCols = $xp->query('./w:gridCol', $grid)->length;
        $total = array_sum(array_map(fn ($g) => (int) $g->getAttributeNS(self::W, 'w'), iterator_to_array($xp->query('./w:gridCol', $grid))));
        $trs = iterator_to_array($xp->query('./w:tr', $tbl));
        $full = null;
        foreach ($trs as $tr) {
            $n = $xp->query('./w:tc[not(w:tcPr/w:gridSpan)]', $tr)->length;
            if ($full === null || $n > $xp->query('./w:tc[not(w:tcPr/w:gridSpan)]', $full)->length) {
                $full = $tr;
            }
        }
        // The empty grid columns some rows leave before and after their cells.
        $edge = fn (DOMElement $tr, string $grid, string $w) => $xp->query("./w:trPr/w:{$grid}", $tr)->length
            ? (int) ($xp->query("./w:trPr/w:{$w}/@w:w", $tr)->item(0)?->value ?? 0) : null;
        [$before, $after] = [$edge($full, 'gridBefore', 'wBefore'), $edge($full, 'gridAfter', 'wAfter')];
        $width = fn (DOMElement $tc) => (int) ($xp->query('./w:tcPr/w:tcW/@w:w', $tc)->item(0)?->value ?? 0);
        $cells = array_map($width, iterator_to_array($xp->query('./w:tc', $full)));
        $sum = array_sum($cells);
        $room = $total - (int) $before - (int) $after;
        // Columns gone: the rest widened; columns repeated: all narrowed to the table's width.
        $scale = $sum > 0 && ($room > $sum || $grown) ? $room / $sum : 1.0;

        foreach ($trs as $tr) {
            foreach (iterator_to_array($xp->query('./w:tc', $tr)) as $tc) {
                $tcW = $xp->query('./w:tcPr/w:tcW', $tc)->item(0);
                $span = $xp->query('./w:tcPr/w:gridSpan', $tc)->item(0);
                if ($span !== null && (int) $span->getAttributeNS(self::W, 'val') >= $oldCols) {
                    $span->setAttributeNS(self::W, 'w:val', (string) (count($cells) + ($before !== null) + ($after !== null)));
                    $tcW?->setAttributeNS(self::W, 'w:w', (string) $total);
                    continue;
                }
                $tcW?->setAttributeNS(self::W, 'w:w', (string) (int) round($width($tc) * $scale));
            }
        }
        while ($grid->firstChild) {
            $grid->removeChild($grid->firstChild);
        }
        foreach ([...($before !== null ? [$before] : []), ...array_map(fn ($w) => (int) round($w * $scale), $cells), ...($after !== null ? [$after] : [])] as $w) {
            $col = $grid->ownerDocument->createElementNS(self::W, 'w:gridCol');
            $col->setAttributeNS(self::W, 'w:w', (string) $w);
            $grid->appendChild($col);
        }
    }

    /**
     * Sections: one left empty (the landscape PPE section without the PPE note) goes, its break with it; page numbers
     * run on from the first section's start.
     */
    private function sections(DOMXPath $xp): void
    {
        $body = $xp->query('/w:document/w:body')->item(0);
        $content = false;
        $first = true;
        foreach (iterator_to_array($body->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $content = $content || $node->localName === 'tbl' || ($node->localName === 'p' && trim(NotesTemplate::text($xp, $node)) !== '');
            $sect = $node->localName === 'sectPr' ? $node : ($node->localName === 'p' ? $xp->query('./w:pPr/w:sectPr', $node)->item(0) : null);
            if ($sect === null) {
                continue;
            }
            if (! $content && $node->localName === 'p') {
                $body->removeChild($node);
                continue;
            }
            if (! $first) {
                $xp->query('./w:pgNumType', $sect)->item(0)?->removeAttributeNS(self::W, 'start');
            }
            $first = false;
            $content = false;
        }
    }

    private function sectionBreak(DOMXPath $xp, DOMElement $p): bool
    {
        return $xp->query('./w:pPr/w:sectPr', $p)->length > 0;
    }

    /* ---------- text ---------- */

    /** Placeholders filled where they stand (each sits whole in one run, template README), so the run's format stays. */
    private function fill(DOMXPath $xp, DOMElement $node, array $values): void
    {
        foreach ($xp->query('.//w:t', $node) as $t) {
            if (! str_contains($t->textContent, '${')) {
                continue;
            }
            $t->textContent = preg_replace_callback('/\$\{([a-z0-9_]+)\}/', fn ($m) => self::shown($values[$m[1]] ?? null), $t->textContent);
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        }
    }

    private static function shown(mixed $v): string
    {
        return match (true) {
            $v instanceof Figure => NarrativeRenderer::money($v),
            $v === null          => NarrativeRenderer::MISSING,
            $v === false         => '',
            default              => (string) $v,
        };
    }

    /** The user's own wording in place of the paragraph's runs, in its first run's format; a line break stays one. */
    private function replaceText(DOMDocument $dom, DOMXPath $xp, DOMElement $p, string $text): void
    {
        $rPr = $xp->query('.//w:r[w:t]/w:rPr', $p)->item(0)?->cloneNode(true);
        foreach (iterator_to_array($p->childNodes) as $child) {
            if (! ($child instanceof DOMElement && $child->localName === 'pPr')) {
                $p->removeChild($child);
            }
        }
        $r = $p->appendChild($dom->createElementNS(self::W, 'w:r'));
        if ($rPr) {
            $r->appendChild($rPr);
        }
        foreach (preg_split('/\R/u', $text) as $i => $line) {
            if ($i > 0) {
                $r->appendChild($dom->createElementNS(self::W, 'w:br'));
            }
            $t = $r->appendChild($dom->createElementNS(self::W, 'w:t'));
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $t->appendChild($dom->createTextNode($line));
        }
    }

    /* ---------- the draft ---------- */

    /** A draft's first page (DraftNotes), a section of its own with no header or page number. */
    private function draftPage(DOMDocument $dom, DOMXPath $xp, array $p, string $savedAt): void
    {
        $lines = DraftNotes::lines($p, $savedAt);

        $body = $xp->query('/w:document/w:body')->item(0);
        $first = $body->firstChild;
        foreach ($lines as [$text, $style]) {
            $body->insertBefore($this->paragraph($dom, $text, $style), $first);
        }
        // The section's break: the notes' first section's page, without its header, footer or page numbering.
        $sect = $xp->query('//w:sectPr', $body)->item(0)->cloneNode(true);
        foreach (iterator_to_array($xp->query('./w:headerReference | ./w:footerReference | ./w:pgNumType', $sect)) as $child) {
            $sect->removeChild($child);
        }
        $break = $dom->createElementNS(self::W, 'w:p');
        $pPr = $break->appendChild($dom->createElementNS(self::W, 'w:pPr'));
        $pPr->appendChild($dom->createElementNS(self::W, 'w:bidi'));
        $pPr->appendChild($sect);
        $body->insertBefore($break, $first);
    }

    /** «مسودة» at the top of every page that has a header. */
    private function header(string $xml, bool $draft): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET);
        $xp = NotesTemplate::xpath($dom);
        $this->fill($xp, $dom->documentElement, $this->values);
        if ($draft) {
            $dom->documentElement->insertBefore($this->paragraph($dom, __('exports.draft.header', [], 'ar'), 'mark'), $dom->documentElement->firstChild);
        }

        return $dom->saveXML();
    }

    /** A right-to-left paragraph in the template's font: title (red, large), mark (red, centred), heading (bold), text. */
    private function paragraph(DOMDocument $dom, string $text, string $style): DOMElement
    {
        $p = $dom->createElementNS(self::W, 'w:p');
        $pPr = $p->appendChild($dom->createElementNS(self::W, 'w:pPr'));
        $pPr->appendChild($dom->createElementNS(self::W, 'w:bidi'));
        $spacing = $pPr->appendChild($dom->createElementNS(self::W, 'w:spacing'));
        $spacing->setAttributeNS(self::W, 'w:before', $style === 'heading' ? '240' : '0');
        $spacing->setAttributeNS(self::W, 'w:after', '60');
        $jc = $pPr->appendChild($dom->createElementNS(self::W, 'w:jc'));
        $jc->setAttributeNS(self::W, 'w:val', in_array($style, ['title', 'mark'], true) ? 'center' : 'both');
        $r = $p->appendChild($dom->createElementNS(self::W, 'w:r'));
        $rPr = $r->appendChild($dom->createElementNS(self::W, 'w:rPr'));
        $fonts = $rPr->appendChild($dom->createElementNS(self::W, 'w:rFonts'));
        foreach (['ascii', 'hAnsi', 'cs'] as $a) {
            $fonts->setAttributeNS(self::W, "w:{$a}", 'Sakkal Majalla');
        }
        if ($style !== 'text') {
            $rPr->appendChild($dom->createElementNS(self::W, 'w:b'));
            $rPr->appendChild($dom->createElementNS(self::W, 'w:bCs'));
        }
        if (in_array($style, ['title', 'mark'], true)) {
            $color = $rPr->appendChild($dom->createElementNS(self::W, 'w:color'));
            $color->setAttributeNS(self::W, 'w:val', self::RED);
        }
        $size = ['title' => 40, 'mark' => 28, 'heading' => 30, 'text' => 26][$style];
        foreach (['w:sz', 'w:szCs'] as $tag) {
            $sz = $rPr->appendChild($dom->createElementNS(self::W, $tag));
            $sz->setAttributeNS(self::W, 'w:val', (string) $size);
        }
        $rPr->appendChild($dom->createElementNS(self::W, 'w:rtl'));
        $t = $r->appendChild($dom->createElementNS(self::W, 'w:t'));
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($dom->createTextNode($text));

        return $p;
    }

    /* ---------- the file ---------- */

    private function core(string $xml, string $at): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xp->registerNamespace('dcterms', 'http://purl.org/dc/terms/');
        $xp->query('//dc:title')->item(0)->textContent = __('exports.notes_filename', [], 'ar');
        foreach (['created', 'modified'] as $k) {
            $el = $xp->query("//dcterms:{$k}")->item(0) ?? $dom->documentElement->appendChild($dom->createElementNS('http://purl.org/dc/terms/', "dcterms:{$k}"));
            $el->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:type', 'dcterms:W3CDTF');
            $el->textContent = $at;
        }

        return $dom->saveXML();
    }

    /** The parts in the template's order, with fixed times, so the same version always gives the same bytes (Q16). */
    private function write(array $parts, int $at): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fs-docx-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            foreach ($parts as $name => $content) {
                $zip->addFromString($name, $content);
                $zip->setMtimeName($name, $at);
                $zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /** Values as stored ({amount, origin, status, refs} for a figure) back to figures. */
    private static function figures(array $values): array
    {
        return array_map(fn ($v) => is_array($v) && array_key_exists('amount', $v) && isset($v['origin'], $v['status'])
            ? new Figure($v['amount'] === null ? null : (float) $v['amount'], $v['origin'], $v['status'], $v['refs'] ?? [])
            : $v, $values);
    }
}
