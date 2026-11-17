<?php

namespace App\Services\Portal;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Unpacks a compressed file a client sent (zip, rar, 7z, tar, gz) into a folder of the app's own, harmlessly: nothing leaves the
 * folder (a name with «..», an absolute path or a link is dropped), the count and the unpacked size are capped before and while
 * writing, only the kinds of file the portal takes stay, and nested archives, hidden and system files are left. A zip is read by PHP;
 * the rest by 7-Zip (config `portal.archive_tool`), which lists the sizes first, so a bomb is refused before anything is written.
 *
 * A name PHP can't read as UTF-8 is taken as Windows Arabic (CP1256), where old zip tools put it.
 */
final class ArchiveExtractor
{
    /**
     * @return array{files: list<array{path: string, name: string, abs: string, ext: string, size: int}>, skipped: list<array{name: string, reason: string}>}
     *         path: where the file sat in the archive (folders included), abs: where it is now
     *
     * @throws RuntimeException with a lang key of portal.archive.error.* as its message
     */
    public function extract(string $archive, string $ext, string $dir): array
    {
        @mkdir($dir, 0777, true);
        $ext = strtolower($ext);
        $entries = $ext === 'zip' ? $this->zip($archive, $dir) : $this->tool($archive, $dir);
        $files = [];
        $skipped = [];
        foreach ($entries as $e) {
            if (($reason = $this->refusal($e['path'], $e['abs'], $dir)) !== null) {
                $skipped[] = ['name' => $e['path'], 'reason' => $reason];
                is_file($e['abs']) && @unlink($e['abs']);
                continue;
            }
            $files[] = $e + ['name' => basename(str_replace('\\', '/', $e['path'])), 'ext' => strtolower(pathinfo($e['path'], PATHINFO_EXTENSION)), 'size' => (int) filesize($e['abs'])];
        }
        if (count($files) > (int) config('portal.archive_max_files')) {
            throw new RuntimeException('too_many_files');
        }

        return ['files' => $files, 'skipped' => $skipped];
    }

    /** @return list<array{path: string, abs: string}> */
    private function zip(string $archive, string $dir): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('unreadable');
        }
        $this->guardSize(array_map(fn ($i) => (int) ($zip->statIndex($i)['size'] ?? 0), range(0, max(0, $zip->numFiles - 1))), $zip->numFiles);
        $out = [];
        $budget = (int) config('portal.archive_max_unpacked_mb') * 1048576;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $raw = (string) $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
            $name = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'CP1256');
            if (str_ends_with($name, '/')) {
                continue;   // a folder
            }
            $abs = $dir.DIRECTORY_SEPARATOR.$i.'.part';
            $in = $zip->getStreamIndex($i);
            if ($in === false) {
                if (($zip->statIndex($i)['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('encrypted');
                }
                $out[] = ['path' => $name, 'abs' => $abs];   // an entry that can't be read: refused below (it was not written)
                continue;
            }
            $w = fopen($abs, 'wb');
            $written = 0;
            while (! feof($in) && ($chunk = fread($in, 1048576)) !== false && $chunk !== '') {
                $written += strlen($chunk);
                if ($written > $budget) {   // the header said less than it holds
                    fclose($in);
                    fclose($w);
                    throw new RuntimeException('too_big');
                }
                fwrite($w, $chunk);
            }
            fclose($in);
            fclose($w);
            $out[] = ['path' => $name, 'abs' => $abs];
        }
        $zip->close();

        return $out;
    }

    /** @return list<array{path: string, abs: string}> */
    private function tool(string $archive, string $dir): array
    {
        $bin = $this->binary();
        if ($bin === null) {
            throw new RuntimeException('no_tool');
        }
        // The sizes first (a listing): a bomb or a huge archive is refused before a byte is written.
        $list = new Process([$bin, 'l', '-slt', '-ba', $archive]);
        $list->setTimeout(120)->run();
        if (! $list->isSuccessful()) {
            throw new RuntimeException(str_contains($list->getOutput().$list->getErrorOutput(), 'ncrypted') || str_contains($list->getErrorOutput(), 'password') ? 'encrypted' : 'unreadable');
        }
        preg_match_all('/^Size = (\d+)/m', $list->getOutput(), $m);
        $this->guardSize(array_map('intval', $m[1]), count($m[1]));

        $run = new Process([$bin, 'x', '-y', '-bd', '-snl-', '-o'.$dir, $archive]);
        $run->setTimeout(300)->run();
        if (! $run->isSuccessful()) {
            throw new RuntimeException(str_contains($run->getOutput().$run->getErrorOutput(), 'ncrypted') || str_contains($run->getErrorOutput(), 'password') ? 'encrypted' : 'unreadable');
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY) as $f) {
            $out[] = ['path' => ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/'), 'abs' => $f->getPathname()];   // a link is refused below
        }

        return $out;
    }

    private function guardSize(array $sizes, int $count): void
    {
        if ($count > (int) config('portal.archive_max_files') * 4) {   // folders and skipped files count too, but not without end
            throw new RuntimeException('too_many_files');
        }
        if (array_sum($sizes) > (int) config('portal.archive_max_unpacked_mb') * 1048576) {
            throw new RuntimeException('too_big');
        }
    }

    /** Why a file is not kept (null: it is): outside the folder, hidden or system, an archive inside, a kind the portal doesn't take, empty. */
    private function refusal(string $path, string $abs, string $dir): ?string
    {
        $norm = str_replace('\\', '/', $path);
        if (str_starts_with($norm, '/') || preg_match('#(^|/)\.\.(/|$)#', $norm) || preg_match('#^[A-Za-z]:#', $norm)) {
            return 'unsafe_path';
        }
        if (! is_file($abs) || is_link($abs)) {
            return 'unreadable';
        }
        $real = realpath($abs);
        if ($real === false || ! str_starts_with($real, (string) realpath($dir))) {
            return 'unsafe_path';
        }
        $base = basename($norm);
        if (str_starts_with($base, '.') || str_starts_with($norm, '__MACOSX/') || in_array(strtolower($base), ['thumbs.db', 'desktop.ini'], true) || str_starts_with($base, '~$')) {
            return 'system_file';
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($ext, (array) config('portal.archive_extensions'), true)) {
            return 'nested_archive';
        }
        if (! in_array($ext, (array) config('portal.extensions'), true)) {
            return 'not_allowed';
        }
        if (filesize($abs) === 0) {
            return 'empty';
        }

        return null;
    }

    /** The unpacking tool (7-Zip), or null when none is found. */
    public function binary(): ?string
    {
        $named = config('portal.archive_tool');
        $candidates = $named ? [$named] : (array) config('portal.archive_tools');
        foreach ($candidates as $c) {
            if (str_contains($c, '/') || str_contains($c, '\\')) {
                if (is_file($c)) {
                    return $c;
                }
            } elseif (($found = (new ExecutableFinder())->find($c)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
