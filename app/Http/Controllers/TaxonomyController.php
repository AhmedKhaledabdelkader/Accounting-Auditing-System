<?php

namespace App\Http\Controllers;

use App\Models\TaxonomyAlias;
use App\Models\TaxonomyMainClass;
use App\Models\TaxonomySubClass;
use App\Repositories\Eloquents\TaxonomyMapperRepository;
use App\Repositories\Eloquents\TaxonomyRepository;
use App\Services\Statements\LineCatalogue;
use App\Services\TaxonomyManager;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The taxonomy page (docs/16-taxonomy.md): the two statements' main and sub classes, and — with taxonomy.manage — adding,
 * renaming and deleting them (TaxonomyManager). Category names are Arabic data; the page's own words are bilingual.
 */
class TaxonomyController extends Controller
{
    public function __construct(private TaxonomyManager $manager)
    {
    }

    public function index(Request $request): View
    {
        $mains = TaxonomyMainClass::with(['subClasses.aliases', 'aliases'])->orderBy('sort')->orderBy('id')->get();
        $statements = [];
        foreach (array_keys(TaxonomyRepository::STATEMENTS) as $statement) {
            $statements[$statement] = ['mains' => $mains->where('statement', $statement)->values(), 'lines' => $this->lines($statement)];
        }
        $tab = (int) $request->query('tab', 0);

        return view('taxonomy', [
            'statements' => $statements,
            'tab'        => array_key_exists($tab, array_keys($statements)) ? $tab : 0,
            'labels'     => (new LineCatalogue)->lines(),
            'canManage'  => (bool) $request->user()?->can('taxonomy.manage'),
            'counts'     => ['mains' => $mains->count(), 'subs' => $mains->sum(fn ($m) => $m->subClasses->count()),
                'added' => $mains->whereNull('origin_name')->count() + $mains->sum(fn ($m) => $m->subClasses->whereNull('origin_name')->count())],
        ]);
    }

    public function storeMain(Request $request): RedirectResponse
    {
        $statement = (string) $request->input('statement');
        $data = $request->validate([
            'statement' => ['required', Rule::in(array_keys(TaxonomyRepository::STATEMENTS))],
            'name'      => $this->nameRules('main'),
            'family'    => ['required', Rule::in(TaxonomyRepository::STATEMENTS[$statement]['families'] ?? [])],
        ]);
        $this->manager->createMain($data);

        return $this->done($statement, 'main_added');
    }

    public function updateMain(Request $request, TaxonomyMainClass $main): RedirectResponse
    {
        $data = $request->validate([
            'name'   => $this->nameRules('main', $main->id),
            'family' => [$main->builtIn() ? 'nullable' : 'required', Rule::in(TaxonomyRepository::STATEMENTS[$main->statement]['families'])],
        ]);
        $this->manager->updateMain($main, $data);

        return $this->done($main->statement, 'saved');
    }

    public function destroyMain(TaxonomyMainClass $main): RedirectResponse
    {
        $this->manager->deleteMain($main);

        return $this->done($main->statement, 'deleted');
    }

    public function storeSub(Request $request, TaxonomyMainClass $main): RedirectResponse
    {
        $data = $request->validate([
            'name'        => $this->nameRules('sub'),
            'normal_side' => ['required', Rule::in(['debit', 'credit'])],
            'line_key'    => ['required', Rule::in(array_keys($this->lines($main->statement)))],
        ]);
        $this->manager->createSub($main, $data);

        return $this->done($main->statement, 'sub_added');
    }

    public function updateSub(Request $request, TaxonomySubClass $sub): RedirectResponse
    {
        $statement = $sub->mainClass->statement;
        $data = $request->validate([
            'name'        => $this->nameRules('sub', $sub->id),
            'normal_side' => ['required', Rule::in(['debit', 'credit'])],
            'line_key'    => [$sub->builtIn() ? 'nullable' : 'required', Rule::in(array_keys($this->lines($statement)))],
        ]);
        $this->manager->updateSub($sub, $data);

        return $this->done($statement, 'saved');
    }

    public function destroySub(TaxonomySubClass $sub): RedirectResponse
    {
        $statement = $sub->mainClass->statement;
        $this->manager->deleteSub($sub);

        return $this->done($statement, 'deleted');
    }

    /** The statement's lines that take account balances: [key => Arabic label] (the current template). */
    private function lines(string $statement): array
    {
        $prefix = TaxonomyRepository::STATEMENTS[$statement]['lines'];

        return array_map(fn ($l) => $l['label_ar'], array_filter((new LineCatalogue)->lines(), fn ($k) => str_starts_with($k, $prefix), ARRAY_FILTER_USE_KEY));
    }

    /**
     * A name unique among the categories of its kind, current and former (the AI and the memory know a class by its name
     * alone; an old run's name must point to one category), except the category's own former names.
     */
    private function nameRules(string $kind, ?int $except = null): array
    {
        $model = $kind === 'main' ? TaxonomyMainClass::class : TaxonomySubClass::class;
        $owner = $kind === 'main' ? 'main_class_id' : 'sub_class_id';

        return ['required', 'string', 'max:100', Rule::notIn([TaxonomyMapperRepository::ROUTE]),
            function (string $attribute, mixed $value, Closure $fail) use ($model, $owner, $kind, $except) {
                $taken = $model::where('name', $value)->when($except, fn ($q) => $q->whereKeyNot($except))->exists()
                    || TaxonomyAlias::where('kind', $kind)->where('name', $value)->where(fn ($q) => $q->whereNull($owner)->orWhere($owner, '!=', $except ?? 0))->exists();
                if ($taken) {
                    $fail(__('taxonomy_page.name_taken'));
                }
            }];
    }

    private function done(string $statement, string $message): RedirectResponse
    {
        return redirect()->route('taxonomy', ['tab' => array_search($statement, array_keys(TaxonomyRepository::STATEMENTS), true) ?: 0])
            ->with('status', __("taxonomy_page.{$message}"));
    }
}
