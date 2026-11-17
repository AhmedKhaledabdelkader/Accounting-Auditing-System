<?php

namespace App\Console\Commands;

use App\Models\ClassificationRun;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Services\ClassificationCache;
use App\Services\ClassificationRules;
use Illuminate\Console\Command;

/**
 * What the classification rules would do to classified runs, nothing changed (docs/17-classification-rules.md): per rule,
 * the accounts it catches, how, their class now and the rule's; then the code groups worth marking. Markdown, for the
 * accountant's review. The heading above an account isn't stored with a run, so heading words aren't tried here.
 */
class PreviewClassificationRules extends Command
{
    protected $signature = 'classification:rules-preview {runs* : run ids} {--company= : the company whose code groups apply (default: each run\'s)} {--out= : write the report to this file}';

    protected $description = 'Report what the classification rules would change in classified runs, without changing them';

    private const SIDES = ['debit' => 'مدين', 'credit' => 'دائن'];
    private const BY = ['name' => 'الاسم', 'heading' => 'العنوان', 'mark' => 'مجموعة كود'];

    public function handle(): int
    {
        $md = ["# تجربة قواعد التبويب (من غير تغيير)\n"];
        foreach ($this->argument('runs') as $id) {
            $run = ClassificationRun::find($id);
            if (! $run || $run->status !== 'done') {
                $this->error("Run {$id}: not found or not done.");

                return self::FAILURE;
            }
            $rows  = $run->result['data'];
            $rules = ClassificationRules::load($this->option('company') ?: $run->company_id);
            $hits  = $rules->preview($rows);
            $md[] = "## {$run->original_name}\n\nتشغيل {$run->id} ({$run->created_at}): ".count($rows).' حساب، القواعد مسكت '.count($hits).".\n";

            foreach (collect($hits)->groupBy('rule') as $rule => $group) {
                $changed = $group->filter(fn ($h) => $h['class'] !== null && $h['class'] !== $h['current'])->count();
                $md[] = "### {$rule} — ".$group->count()." حساب (هيتغير {$changed})\n";
                $md[] = '| الصف | رقم الحساب | اسم الحساب | اتمسك بـ | الرصيد | التصنيف الحالي | تصنيف القاعدة | للمراجعة |';
                $md[] = '|---|---|---|---|---|---|---|---|';
                foreach ($group as $h) {
                    $class = $h['class'] ?? ($h['side'] === null ? '— (رصيد صفر)' : '— (لم يُحدَّد بعد)');
                    $mark  = $h['class'] !== null && $h['class'] !== $h['current'] ? ' ⟵' : '';
                    $md[] = "| {$h['row']} | {$h['account_number']} | ".self::cell($h['account_name']).' | '.self::BY[$h['by']].' | '
                        .(self::SIDES[$h['side']] ?? 'صفر').' | '.($h['current'] ?? '—')." | {$class}{$mark} | ".($h['review'] ? 'نعم' : '').' |';
                }
                $md[] = '';
            }

            $caught = array_flip(array_column($hits, 'row'));
            $rest = array_filter($rows, fn ($r) => ! isset($caught[$r['row']]) && ($r['financial_statement'] ?? null) === ClassificationRules::BALANCE_SHEET);
            $md[] = '### حسابات ميزانية ماتمسكتش بأي قاعدة — '.count($rest)." (بيصنّفها الـ AI)\n";
            $md[] = implode('، ', array_map(fn ($r) => "{$r['row']}: ".self::cell($r['account_name']).' ('.($r['sub_class'] ?? '—').')', $rest))."\n";

            // The accountant's principle: a class with a counterpart flips; any other balance-sheet account on the side
            // opposite its class's nature is flagged for review, not moved.
            $taxonomy = app(TaxonomyRepositoryInterface::class);
            $wrong = array_filter($rest, function ($r) use ($taxonomy) {
                $side = ClassificationCache::rowSide($r);
                $normal = $taxonomy->find((string) ($r['sub_class'] ?? ''))[2] ?? null;

                return $side !== null && $normal !== null && $side !== $normal && ClassificationCache::pairOf($r['sub_class']) === null;
            });
            $md[] = '### حسابات رصيدها عكس طبيعة تصنيفها ومالهاش مقابل — '.count($wrong)." (هتتعلّم للمراجعة من غير عكس)\n";
            foreach ($wrong as $r) {
                $md[] = "- {$r['row']}: ".self::cell($r['account_name'])." — {$r['sub_class']}، رصيده ".self::SIDES[ClassificationCache::rowSide($r)];
            }
            $md[] = '';

            $proposals = $rules->proposals($rows);
            $md[] = "### اقتراحات «طبّق على المجموعة» — ".count($proposals)."\n";
            foreach ($proposals as $p) {
                $md[] = "- أكواد بتبدأ بـ {$p['prefix']}: «{$p['rule']->name}» (".count($p['rows']).' حساب، '.round($p['share'] * 100).'% منهم الـ AI صنّفهم كده)';
            }
            $md[] = '';
        }

        $report = implode("\n", $md);
        if ($out = $this->option('out')) {
            file_put_contents($out, $report);
            $this->info("Written to {$out}");
        } else {
            $this->line($report);
        }

        return self::SUCCESS;
    }

    private static function cell(string $text): string
    {
        return str_replace('|', '/', trim(preg_replace('/^[\s.]+/u', '', $text)));
    }
}
