<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Portal\ClientDocuments;
use App\Services\Statements\ArticlesOfAssociation;
use App\Support\Llm\ProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Company profiles for the financial statements (Q12), reused across years. */
class CompanyController extends Controller
{
    private const DAYS_IN_MONTH = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

    /** ?search= in the names, CR and unified numbers; most recently changed first. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:200'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = Company::query();
        if (($search = trim((string) $request->input('search'))) !== '') {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)
                ->orWhere('cr_number', 'like', $like)->orWhere('unified_number', 'like', $like));
        }
        $page = $query->orderByDesc('updated_at')->orderBy('id')->paginate((int) $request->input('per_page', 25))->withQueryString();
        $page->getCollection()->transform(fn (Company $c) => self::present($c));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $company = new Company($data);
        $this->chosenLegalForm($company, $data);
        if ($taken = $this->registrationTaken($company->cr_number, null)) {
            return $taken;
        }
        if ($error = $this->badYearEnd($company)) {
            return $error;
        }
        $company->save();

        return response()->json(self::present($company->fresh()), 201);
    }

    public function show(string $id): JsonResponse
    {
        $company = Company::find($id);

        return $company ? response()->json(self::present($company)) : $this->notFound();
    }

    /** Partial update: only the fields sent change. */
    public function update(Request $request, string $id): JsonResponse
    {
        $data    = $request->validate($this->rules(true));
        $company = Company::find($id);
        if (! $company) {
            return $this->notFound();
        }
        $company->fill($data);
        $this->chosenLegalForm($company, $data);
        if ($company->isDirty('cr_number') && ($taken = $this->registrationTaken($company->cr_number, $company->id))) {
            return $taken;
        }
        if ($error = $this->badYearEnd($company)) {
            return $error;
        }
        $company->save();
        \App\Services\Portal\Workspace::articlesNotNeeded($company);

        return response()->json(self::present($company->fresh()));
    }

    /** The company as the API returns it: every column, `branches` a list and `profile` an object. */
    public static function present(Company $c): array
    {
        return [
            'id' => $c->id, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'legal_form' => $c->legal_form,
            'legal_form_known' => $c->legalFormKnown(), 'articles_required' => $c->articlesRequired(), 'country' => $c->country,
            'framework' => $c->framework, 'cr_number' => $c->cr_number, 'cr_date_hijri' => $c->cr_date_hijri,
            'cr_date' => $c->cr_date?->format('Y-m-d'), 'unified_number' => $c->unified_number, 'city' => $c->city,
            'head_office' => $c->head_office, 'activity' => $c->activity, 'branches' => array_values($c->branches ?? []),
            'fiscal_year_end_month' => $c->fiscal_year_end_month, 'fiscal_year_end_day' => $c->fiscal_year_end_day,
            'period_start' => $c->period_start?->format('Y-m-d'), 'period_end' => $c->period_end?->format('Y-m-d'),
            'currency' => $c->currency, 'profile' => (object) array_diff_key($c->profile ?? [], ['articles' => 1]),
            'articles' => self::articles($c),
            'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The articles of association (a PDF, always): kept on the private disk, read by the AI (it only copies the partners,
     * their nationality and shares) and kept in the profile, where the capital note and the zakat dialog read them.
     * Reading again replaces the earlier file.
     */
    public function uploadArticles(Request $request, string $id, ArticlesOfAssociation $articles): JsonResponse
    {
        $company = Company::find($id);
        if (! $company) {
            return $this->notFound();
        }
        \App\Support\ClientFileSource::attach($request);   // or the file the client uploaded in the portal (`client_file_id`)
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('financial_statements.prior_pdf.max_kb', 20480)]]);
        set_time_limit(300);
        try {
            $articles->store($company, $request->file('file'));
        } catch (ProviderException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 502);
        }

        return response()->json(self::present($company->fresh()));
    }

    /**
     * The commercial registration (PDF or image), read by the AI as the client's file page reads it: the number (the company's identity,
     * refused when another company of the office has it), the legal form (a company or a sole proprietorship) and a sole proprietorship's
     * owner, and the facts still empty on the company. 422 when the file isn't a readable registration.
     */
    public function uploadRegistration(Request $request, string $id, ClientDocuments $documents): JsonResponse
    {
        $company = Company::find($id);
        if (! $company) {
            return $this->notFound();
        }
        \App\Support\ClientFileSource::attach($request);   // or the file the client uploaded in the portal (`client_file_id`)
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        // The reading's own refusals (not a registration, no number, another client's) come back on `registration_file`.
        $read = $documents->readRegistration($request->file('file'), $company->id);
        $documents->keepRegistration($company, $request->file('file'), $read);
        $company = $company->fresh();

        return response()->json(self::present($company) + ['warning' => $documents->nameWarning($company, $read)]);
    }

    /** Forgets the articles of association: the file and what was read from it. */
    public function deleteArticles(string $id): JsonResponse
    {
        $company = Company::find($id);
        if (! $company) {
            return $this->notFound();
        }
        $profile = $company->profile ?? [];
        if ($file = $profile['articles']['file'] ?? null) {
            Storage::disk('local')->delete($file);
        }
        unset($profile['articles']);
        $company->profile = $profile;
        $company->save();

        return response()->json(self::present($company->fresh()));
    }

    /** What was read from the articles of association, with each partner's share of the capital and the Saudi and Gulf share; null without them. */
    public static function articles(Company $c): ?array
    {
        $a = $c->profile['articles'] ?? null;
        if (! is_array($a)) {
            return null;
        }
        $partners = array_values((array) ($a['partners'] ?? []));
        $shares = ArticlesOfAssociation::percentages($partners);

        return ['name' => $a['name'] ?? null, 'read_at' => $a['read_at'] ?? null, 'capital' => $a['capital'] ?? null,
            'shares_total' => $a['shares_total'] ?? null, 'share_value' => $a['share_value'] ?? null,
            'partners' => array_map(fn ($p, $i) => $p + ['ownership' => $shares[$i]], $partners, array_keys($partners)),
            'zakat_share' => ArticlesOfAssociation::zakatShare($partners)];
    }

    /** A registration number is one company's, in an office: another company with it is that client already (422), never a second one. */
    private function registrationTaken(?string $number, ?string $exceptId): ?JsonResponse
    {
        if ($number === null || $number === '' || ! ($other = Company::withRegistration($number, $exceptId))) {
            return null;
        }
        $message = "رقم السجل التجاري {$number} تابع للشركة «{$other->name_ar}» (العميل {$other->clientName()}).";

        return response()->json(['message' => $message, 'errors' => ['cr_number' => [$message]]], 422);
    }

    /** A legal form sent by the accountant is theirs: known from now on, whatever the registration said before. */
    private function chosenLegalForm(Company $company, array $data): void
    {
        if (array_key_exists('legal_form', $data)) {
            $company->profile = array_merge($company->profile ?? [], ['legal_form_source' => 'user']);
        }
    }

    /** The year-end day must exist in its month (29 February allowed); a changed month is checked against the stored day. */
    private function badYearEnd(Company $c): ?JsonResponse
    {
        if ($c->fiscal_year_end_day <= self::DAYS_IN_MONTH[$c->fiscal_year_end_month]) {
            return null;
        }
        $message = "الشهر {$c->fiscal_year_end_month} لا يحتوي على اليوم {$c->fiscal_year_end_day}.";

        return response()->json(['message' => $message, 'errors' => ['fiscal_year_end_day' => [$message]]], 422);
    }

    private function rules(bool $partial): array
    {
        $some = $partial ? ['sometimes'] : [];
        $text = fn (int $max) => [...$some, 'nullable', 'string', "max:{$max}"];

        return [
            'name_ar'               => [...$some, 'required', 'string', 'max:255'],
            'name_en'               => $text(255),
            // Saudi, under IFRS for SMEs, in SAR, for now (Q5); a company (llc) or a sole proprietorship.
            'legal_form'            => [...$some, 'string', 'in:'.implode(',', array_keys(Company::LEGAL_FORMS))],
            'country'               => [...$some, 'string', 'in:SA'],
            'framework'             => [...$some, 'string', 'in:ifrs_for_smes'],
            'currency'              => [...$some, 'string', 'in:SAR'],
            'cr_number'             => $text(50),
            'cr_date_hijri'         => $text(50),
            'cr_date'               => [...$some, 'nullable', 'date_format:Y-m-d'],
            'unified_number'        => $text(50),
            'city'                  => $text(255),
            'head_office'           => $text(255),
            'activity'              => $text(2000),
            'branches'              => [...$some, 'nullable', 'array', 'max:100'],
            'branches.*'            => ['string', 'max:255'],
            'fiscal_year_end_month' => [...$some, 'integer', 'between:1,12'],
            'fiscal_year_end_day'   => [...$some, 'integer', 'between:1,31'],
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'الشركة غير موجودة.'], 404);
    }
}
