<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyTrialBalance;
use App\Models\ClassificationRun;
use App\Services\ClassificationService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local only: creates a run waiting for confirmation from a real file and a known mapping (optionally made wrong),
 * without the AI, so the confirmation panel can be tried in the browser.
 */
class DemoConfirmation extends Command
{
    protected $signature = 'classification:demo-confirmation
        {file : xlsx / xls / csv}
        {mapping : JSON file with the correct structure (e.g. tests/Fixtures/mappings/balance.json)}
        {--mutation=swap-opening-closing : swap-opening-closing | swap-closing-sides | notes-as-name | skip-rows | none}';

    protected $description = 'Local only: create a run in needs_confirmation from a file and a (wrong) mapping, without the AI';

    public function handle(ClassificationService $service): int
    {
        if (! app()->environment('local')) {
            $this->error('Local environment only.');
            return self::FAILURE;
        }

        $structure = json_decode((string) file_get_contents($this->argument('mapping')), true, flags: JSON_THROW_ON_ERROR);
        $structure = self::mutate($structure + ['other_sheets' => []], (string) $this->option('mutation'));
        if ($structure === null) {
            $this->error('Unknown mutation, or it does not apply to this mapping.');
            return self::FAILURE;
        }

        $source = $this->argument('file');
        $id     = (string) Str::uuid();
        $path   = 'classification/'.$id.'.'.(pathinfo($source, PATHINFO_EXTENSION) ?: 'xlsx');
        Storage::disk('local')->put($path, (string) file_get_contents($source));

        $detection = $service->withStructure(new UploadedFile(Storage::disk('local')->path($path), basename($source), null, null, true), $structure);
        ClassificationRun::forceCreate([
            'id'               => $id,
            'status'           => 'needs_confirmation',
            'stage'            => 'structure',
            'original_name'    => basename($source),
            'file_path'        => $path,
            'structure'        => $detection['structure'],
            'structure_issues' => ClassifyTrialBalance::shown($detection['issues']),
            'confirmation'     => $service->columnPreview($detection),
            'expires_at'       => now()->addHours((int) config('classification.confirmation_hours', 24)),
        ]);

        $this->table(['severity', 'check', 'message'], array_map(fn ($i) => [$i['severity'], $i['check'], $i['user']], $detection['issues']) ?: [['—', '—', 'no issues: the panel will have nothing to report']]);
        $this->info('Open: '.rtrim((string) config('app.url'), '/').'/classifier?run='.$id);

        return self::SUCCESS;
    }

    /** The wrong mappings of the calibration set. */
    public static function mutate(array $s, string $mutation): ?array
    {
        $c    = $s['columns'];
        $swap = function (string $a, string $b) use (&$c) { [$c[$a], $c[$b]] = [$c[$b] ?? null, $c[$a] ?? null]; };

        switch ($mutation) {
            case 'none':
                break;
            case 'swap-opening-closing':
                if (empty($c['opening_debit']) && empty($c['opening_net'])) {
                    return null;
                }
                foreach (['debit', 'credit', 'net', 'side'] as $k) {
                    $swap("opening_{$k}", "closing_{$k}");
                }
                break;
            case 'swap-closing-sides':
                if (empty($c['closing_debit'])) {
                    return null;
                }
                $swap('closing_debit', 'closing_credit');
                break;
            case 'notes-as-name':
                $letter = $c['notes'] ?? $c['audit_request'] ?? null;
                if (! $letter) {
                    return null;
                }
                $c['account_name'] = $letter;
                break;
            case 'skip-rows':
                $s['first_data_row'] += 3;
                break;
            default:
                return null;
        }
        $s['columns'] = $c;

        return $s;
    }
}
