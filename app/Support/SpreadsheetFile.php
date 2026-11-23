<?php

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Throwable;

/**
 * Any spreadsheet a trial balance (or a statement, a sales list) comes in, whatever made it: Excel 2007+ (xlsx, xlsm), Excel
 * 97–2003 (xls), the «XML Spreadsheet 2003» and HTML tables that accounting systems export under an .xls name (FastReport,
 * Crystal, …), OpenDocument (ods), CSV and text. Accepted by its extension and read by its real content (never refused
 * because a `.xls` holds XML). A reading of XML first drops the file's document properties: they are metadata only, and the
 * exporters write dates there the reader can't parse («04/02/2026 12:00:00 AMT13:16:39Z»).
 */
final class SpreadsheetFile
{
    public const EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'xml', 'ods', 'csv', 'txt', 'htm', 'html'];

    /** The validation rule of an uploaded spreadsheet: one of the extensions, and a content the reader knows. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! $value instanceof UploadedFile) {
                return;
            }
            $ext = strtolower($value->getClientOriginalExtension());
            if (! in_array($ext, self::EXTENSIONS, true)) {
                $fail(__('validation.spreadsheet_type', ['types' => implode('، ', self::EXTENSIONS)]));

                return;
            }
            try {
                IOFactory::identify((string) $value->getRealPath());
            } catch (Throwable) {
                $fail(__('validation.spreadsheet_unreadable'));
            }
        };
    }

    /**
     * A reader for the file, with the path to read it from: the file itself, or for «XML Spreadsheet 2003» a cleaned copy
     * (kept until the request ends). CSV with its encoding guessed (Arabic files are often Windows-1256).
     *
     * @return array{0: IReader, 1: string}
     */
    public static function open(string $path): array
    {
        $type = IOFactory::identify($path);
        if ($type === 'Xml' && ($xlsx = self::xmlToXlsx($path)) !== null) {
            return [IOFactory::createReader('Xlsx'), $xlsx];
        }
        $reader = IOFactory::createReader($type);
        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
        }

        return [$reader, $type === 'Xml' ? self::cleanXml($path) : $path];
    }

    /**
     * An «XML Spreadsheet 2003» as an xlsx: its cells streamed (XMLReader: the values, the column skips `ss:Index`, each
     * worksheet with its name) into a new workbook, written once per request. The library's own XML reader takes most of a
     * minute on an exported trial balance of a few thousand rows; this, a few seconds. Null when it can't be read so (the
     * library's reader then, on the cleaned file).
     */
    public static function xmlToXlsx(string $path): ?string
    {
        static $done = [];
        if (array_key_exists($path, $done)) {
            return $done[$path];
        }
        try {
            $sheets = [];
            $xml = new \XMLReader;
            if (! $xml->open($path, null, LIBXML_NONET | LIBXML_PARSEHUGE)) {
                return $done[$path] = null;
            }
            $sheet = null;
            $row = $col = 0;
            $inData = false;
            $text = '';
            $type = 'String';
            while ($xml->read()) {
                $name = $xml->localName;
                if ($xml->nodeType === \XMLReader::ELEMENT) {
                    if ($name === 'Worksheet') {
                        $sheet = count($sheets);
                        $sheets[$sheet] = ['name' => (string) ($xml->getAttribute('ss:Name') ?? 'Sheet'.($sheet + 1)), 'rows' => []];
                        $row = 0;
                    } elseif ($name === 'Row' && $sheet !== null) {
                        $index = $xml->getAttribute('ss:Index');
                        $row = $index !== null ? (int) $index : $row + 1;
                        $col = 0;
                    } elseif ($name === 'Cell' && $sheet !== null) {
                        $index = $xml->getAttribute('ss:Index');
                        $col = $index !== null ? (int) $index : $col + 1;
                        $across = (int) ($xml->getAttribute('ss:MergeAcross') ?? 0);
                        // A cell merged down over rows ends on the record's last row: its value goes there, so a record a
                        // report writer prints on rows of its own (FastReport: the name on the first, the closing debit on the
                        // second, the credit on the third, all merged down to the same bottom) comes back as one row.
                        $down = (int) ($xml->getAttribute('ss:MergeDown') ?? 0);
                        if ($xml->isEmptyElement) {
                            $col += $across;
                        } else {
                            $pendingAcross = $across;
                        }
                    } elseif ($name === 'Data' && $sheet !== null) {
                        $inData = ! $xml->isEmptyElement;
                        $text = '';
                        $type = (string) ($xml->getAttribute('ss:Type') ?? 'String');
                    }
                } elseif (($xml->nodeType === \XMLReader::TEXT || $xml->nodeType === \XMLReader::CDATA || $xml->nodeType === \XMLReader::SIGNIFICANT_WHITESPACE) && $inData) {
                    $text .= $xml->value;
                } elseif ($xml->nodeType === \XMLReader::END_ELEMENT) {
                    if ($name === 'Data' && $inData) {
                        $inData = false;
                        $value = $type === 'Number' && is_numeric(trim($text)) ? (float) trim($text) : $text;
                        if (! ($value === '' && isset($sheets[$sheet]['rows'][$row + $down][$col]))) {
                            $sheets[$sheet]['rows'][$row + $down][$col] = $value;
                        }
                    } elseif ($name === 'Cell') {
                        $col += $pendingAcross ?? 0;
                        $pendingAcross = 0;
                    }
                }
            }
            $xml->close();
            if ($sheets === []) {
                return $done[$path] = null;
            }
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $book->removeSheetByIndex(0);
            foreach ($sheets as $i => $s) {
                $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($book, mb_substr(preg_replace('#[\\\\/?*\[\]:]#u', ' ', $s['name']) ?: 'Sheet'.($i + 1), 0, 31));
                $book->addSheet($ws);
                foreach ($s['rows'] as $r => $cells) {
                    foreach ($cells as $c => $v) {
                        $address = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$r;
                        is_float($v) ? $ws->setCellValue($address, $v)
                            : $ws->setCellValueExplicit($address, $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                }
            }
            $copy = tempnam(sys_get_temp_dir(), 'xmlss').'.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($copy);
            register_shutdown_function(fn () => @unlink($copy));

            return $done[$path] = $copy;
        } catch (Throwable) {
            return $done[$path] = null;
        }
    }

    /** The XML spreadsheet without its document properties (metadata the reader may fail on); the file itself when none. */
    public static function cleanXml(string $path): string
    {
        static $done = [];
        if (isset($done[$path])) {
            return $done[$path];
        }
        $xml = (string) file_get_contents($path);
        $clean = preg_replace('#<(DocumentProperties|CustomDocumentProperties)\b[^>]*>.*?</\1>#s', '', $xml, -1, $n);
        if (! is_string($clean) || $n === 0) {
            return $done[$path] = $path;
        }
        $copy = tempnam(sys_get_temp_dir(), 'xmlss').'.xml';
        file_put_contents($copy, $clean);
        register_shutdown_function(fn () => @unlink($copy));

        return $done[$path] = $copy;
    }

    /** The whole workbook, read by its content. */
    public static function load(string $path, bool $dataOnly = false): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        [$reader, $readable] = self::open($path);
        if ($dataOnly) {
            $reader->setReadDataOnly(true);
        }

        return $reader->load($readable);
    }
}
