<?php

namespace App\Http\Controllers;

use App\Models\ClassificationRule;
use App\Models\Company;
use App\Models\CompanyCodeGroup;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The classification rules page (docs/17-classification-rules.md): the accountant's rules, and the companies' code groups
 * marked as one kind of account. Viewing and editing follow the taxonomy's permissions.
 */
class ClassificationRuleController extends Controller
{
    public function __construct(private TaxonomyRepositoryInterface $taxonomy)
    {
    }

    public function index(Request $request): View
    {
        return view('classification-rules', [
            'rules'      => ClassificationRule::orderBy('sort')->orderBy('id')->get(),
            'marks'      => CompanyCodeGroup::with(['company', 'rule'])->orderBy('company_id')->orderBy('prefix')->get(),
            'companies'  => Company::orderBy('name_ar')->get(['id', 'name_ar']),
            'subClasses' => $this->taxonomy->subClasses(),
            'canManage'  => (bool) $request->user()?->can('taxonomy.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ClassificationRule::create($this->validated($request));

        return $this->done('added');
    }

    public function update(Request $request, ClassificationRule $rule): RedirectResponse
    {
        $rule->update($this->validated($request, $rule->id));

        return $this->done('saved');
    }

    public function destroy(ClassificationRule $rule): RedirectResponse
    {
        $rule->delete();   // its code groups go with it

        return $this->done('deleted');
    }

    public function storeMark(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'prefix'     => ['required', 'regex:/^\d{1,40}$/'],
            'rule_id'    => ['required', 'integer', 'exists:classification_rules,id'],
        ]);
        $back = self::back($request);
        $existing = CompanyCodeGroup::with('rule')->where('company_id', $data['company_id'])->where('prefix', $data['prefix'])->first();
        if ($existing && $existing->rule_id === (int) $data['rule_id']) {
            return $this->done('mark_exists', $back, 'marks');
        }
        if ($existing) {
            return back()->withInput()->withErrors(['prefix' => __('rules_page.marks.taken', ['rule' => $existing->rule?->name])]);
        }
        CompanyCodeGroup::create($data);

        return $this->done('mark_added', $back, 'marks');
    }

    public function destroyMark(CompanyCodeGroup $mark): RedirectResponse
    {
        $mark->delete();

        return $this->done('deleted');
    }

    /** A rule from the form: word lists as text, one entry per line or comma. */
    private function validated(Request $request, ?int $id = null): array
    {
        $lists = ['name_words', 'also_words', 'exclude_words', 'heading_words', 'ai_classes'];
        $request->merge(array_combine($lists, array_map(fn ($f) => array_values(array_filter(array_map('trim',
            preg_split('/[،,\n]+/u', (string) $request->input("{$f}_text"))), fn ($w) => $w !== '')), $lists)));
        $classes = $this->taxonomy->subClasses();
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:100', Rule::unique('classification_rules', 'name')->ignore($id)],
            'sort'            => ['required', 'integer', 'min:0', 'max:100000'],
            'name_words'      => ['array'],
            'name_words.*'    => ['string', 'max:100'],
            'also_words'      => ['array'],
            'also_words.*'    => ['string', 'max:100'],
            'exclude_words'   => ['array'],
            'exclude_words.*' => ['string', 'max:100'],
            'heading_words'   => ['array'],
            'heading_words.*' => ['string', 'max:100'],
            'ai_classes'      => ['array'],
            'ai_classes.*'    => [Rule::in($classes)],
            'debit_class'     => ['nullable', Rule::in($classes)],
            'credit_class'    => ['nullable', Rule::in($classes)],
            'review_side'     => ['required', Rule::in(ClassificationRule::REVIEW_SIDES)],
        ]);

        return $data + ['enabled' => $request->boolean('enabled')];
    }

    /** Back to the classifier's run the mark came from (its «apply to the group» link); only a classifier page address. */
    private static function back(Request $request): ?string
    {
        $back = (string) $request->input('back');

        return preg_match('#^/classifier\?run=[\w-]+$#', $back) ? $back : null;
    }

    private function done(string $message, ?string $back = null, ?string $anchor = null): RedirectResponse
    {
        return redirect()->to(route('classification-rules').($anchor ? "#{$anchor}" : ''))
            ->with('status', __("rules_page.{$message}"))->with('back', $back);
    }
}
