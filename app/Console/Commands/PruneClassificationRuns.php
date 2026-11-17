<?php

namespace App\Console\Commands;

use App\Models\ClassificationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes what abandoned runs leave behind. Scheduled hourly (routes/console.php): in production the scheduler must
 * run (`* * * * * php artisan schedule:run`), otherwise unconfirmed files and previews are never deleted.
 */
class PruneClassificationRuns extends Command
{
    protected $signature = 'classification:prune
        {--stalled : only fail stalled runs (scheduled every 5 minutes; the full prune runs hourly)}';

    protected $description = 'Expire runs left waiting for confirmation, fail stalled runs, and delete orphaned uploads';

    public function handle(): int
    {
        if ($this->option('stalled')) {
            $this->info('Failed '.$this->failStalled().' stalled run(s).');
            return self::SUCCESS;
        }

        $expired = 0;
        foreach (ClassificationRun::where('status', 'needs_confirmation')->where('expires_at', '<', now())->get() as $run) {
            // One step, so a confirm arriving at the same moment wins or loses cleanly.
            if (ClassificationRun::whereKey($run->id)->where('status', 'needs_confirmation')->update([
                'status' => 'expired', 'stage' => null, 'file_path' => null,
                'error'  => 'انتهت مهلة تأكيد أعمدة الملف فتم حذفه. يرجى رفعه مرة أخرى.',
                'error_phrase' => json_encode(['key' => 'classification_messages.errors.expired', 'params' => []]),
            ] + ClassificationRun::WAITING_DATA)) {
                if ($run->file_path) {
                    Storage::disk('local')->delete($run->file_path);
                }
                $expired++;
            }
        }

        $stalled = $this->failStalled();

        // Safety net: uploads older than the confirmation window that no active run refers to (crashed workers).
        $active  = ClassificationRun::whereIn('status', ['queued', 'running', 'needs_confirmation'])->whereNotNull('file_path')->pluck('file_path')->all();
        $cutoff  = now()->subHours((int) config('classification.confirmation_hours', 24))->getTimestamp();
        $orphans = 0;
        foreach (Storage::disk('local')->files('classification') as $path) {
            if (! in_array($path, $active, true) && Storage::disk('local')->lastModified($path) < $cutoff) {
                Storage::disk('local')->delete($path);
                $orphans++;
            }
        }

        $this->info("Expired {$expired} run(s), failed {$stalled} stalled run(s), deleted {$orphans} orphaned upload(s).");

        return self::SUCCESS;
    }

    /**
     * Running runs whose worker is gone (crash, killed process): the heartbeat went stale. Only status running:
     * a run waiting for confirmation has no worker by design and ends by expiry only.
     */
    private function failStalled(): int
    {
        $stale   = now()->subMinutes((int) config('classification.stale_minutes', 10));
        $stalled = 0;
        $isStale = fn ($q) => $q->where('status', 'running')->where(fn ($q) => $q->where('heartbeat_at', '<', $stale)
            ->orWhere(fn ($q) => $q->whereNull('heartbeat_at')->where('updated_at', '<', $stale)));
        foreach (ClassificationRun::where($isStale)->get() as $run) {
            if (ClassificationRun::whereKey($run->id)->where($isStale)->update([
                'status' => 'failed', 'stage' => null, 'file_path' => null, 'lease' => null,
                'error'  => 'توقف التصنيف في منتصفه (توقف العامل الذي كان يعمل عليه). يرجى رفع الملف مرة أخرى.',
                'error_phrase' => json_encode(['key' => 'classification_messages.errors.stalled', 'params' => []]),
            ] + ClassificationRun::WAITING_DATA)) {
                if ($run->file_path) {
                    Storage::disk('local')->delete($run->file_path);
                }
                $stalled++;
            }
        }

        return $stalled;
    }
}
