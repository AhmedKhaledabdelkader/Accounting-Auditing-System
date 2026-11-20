<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Portal\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The accountant's side of the client portal: the main portal (the office's clients), a client's workspace (the audit
 * checklist, the files, the chat) and the client's login. Every company here belongs to the signed-in user's office:
 * another office's company is a 404.
 */
class ClientController extends Controller
{
    public function __construct(private readonly Workspace $workspace) {}

    /** البوابة الرئيسية: the office's clients, how far each one's checklist is and what waits for the accountant. */
    public function index(): View
    {
        $companies = Company::orderByDesc('updated_at')->orderBy('id')->get();
        $portalUsers = User::whereIn('company_id', $companies->pluck('id'))->get()->keyBy('company_id');
        $latest = DocumentRequest::selectRaw('company_id, max(year) as year')->groupBy('company_id')->pluck('year', 'company_id');
        $requests = DocumentRequest::get(['id', 'company_id', 'year', 'status'])->groupBy('company_id');
        $unread = ClientMessage::where('from_client', true)->where('kind', ClientMessage::TEXT)->whereNull('read_at')
            ->selectRaw('company_id, count(*) as n')->groupBy('company_id')->pluck('n', 'company_id');

        $clients = $companies->map(function (Company $c) use ($portalUsers, $latest, $requests, $unread) {
            $year = $latest[$c->id] ?? null;

            return ['company' => $c, 'user' => $portalUsers[$c->id] ?? null, 'year' => $year, 'unread' => (int) ($unread[$c->id] ?? 0),
                'progress' => Workspace::progress(($requests[$c->id] ?? collect())->where('year', $year))];
        });

        return view('portal.clients', [
            'clients' => $clients, 'office' => auth()->user()->office, 'canManage' => auth()->user()->can('clients.manage'),
            'totals' => [
                'clients' => $clients->count(),
                'review' => $clients->sum(fn ($c) => $c['progress']['by'][DocumentRequest::UPLOADED]),
                'open' => $clients->sum(fn ($c) => $c['progress']['by'][DocumentRequest::PENDING] + $c['progress']['by'][DocumentRequest::RETURNED]),
                'unread' => $clients->sum('unread'),
            ],
        ]);
    }

    public function create(): View
    {
        return view('portal.client-form', ['office' => auth()->user()->office, 'year' => (int) now()->year - 1, 'company' => null, 'user' => null]);
    }

    /** What a client's form asks (the same fields to make a client and to change one). */
    private function rules(?Company $company, ?User $login): array
    {
        $files = fn (bool $required, string $mimes, int $kb) => [$required ? 'required' : 'nullable', 'file', "mimes:{$mimes}", "max:{$kb}"];

        return [
            'client_name' => ['required', 'string', 'max:255'],
            'client_email' => ['required', 'email:rfc', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after:period_start'],
            'registration_file' => $files($company === null, 'pdf,jpg,jpeg,png', 10240),
            // A company's, never a sole proprietorship's (the registration tells which): never required to make the client.
            'articles_file' => $files(false, 'pdf', (int) config('financial_statements.prior_pdf.max_kb', 20480)),
        ];
    }

    /** The period is from one date to a later one, about a year (a first or a changed year may be a little more or less, never more than 400 days). */
    private function checkPeriod(array $data): void
    {
        $days = (new \DateTimeImmutable($data['period_start']))->diff(new \DateTimeImmutable($data['period_end']))->days;
        if ($days > 400) {
            throw ValidationException::withMessages(['period_end' => __('portal.form.period_invalid')]);
        }
    }

    /** What the period fills on the company: the dates themselves, and the recurring day-and-month of its start and end. */
    private function periodFields(array $data): array
    {
        $start = new \DateTimeImmutable($data['period_start']);
        $end = new \DateTimeImmutable($data['period_end']);

        return ['period_start' => $data['period_start'], 'period_end' => $data['period_end'],
            'fiscal_year_start_month' => (int) $start->format('n'), 'fiscal_year_start_day' => (int) $start->format('j'),
            'fiscal_year_end_month' => (int) $end->format('n'), 'fiscal_year_end_day' => (int) $end->format('j')];
    }
    /** The registration and the articles (read by the AI): one service for the new-client form and the client's file pages. */
    private function documents(): \App\Services\Portal\ClientDocuments
    {
        return app(\App\Services\Portal\ClientDocuments::class);
    }

    /** A new client: the person, the company (identified by its commercial registration, read by the AI), the login and the checklist. */
    public function store(Request $request): RedirectResponse
    {
        $office = $request->user()->office_id;
        $request->merge(['username' => mb_strtolower(trim((string) $request->input('username'))), 'client_email' => mb_strtolower(trim((string) $request->input('client_email')))]);
        $data = $request->validate($this->rules(null, null) + [
            'username' => ['required', User::USERNAME_RULE, Rule::unique('users', 'username')->where('office_id', $office)],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'checklist' => ['nullable', 'boolean'],
        ]);
        $this->checkPeriod($data);
        $read = $this->documents()->readRegistration($request->file('registration_file'));

        $company = DB::transaction(function () use ($data, $request, $read) {
            $company = Company::create(['name_ar' => $data['name_ar'], 'name_en' => $data['name_en'], 'cr_number' => $read['number'], 'client_name' => $data['client_name'],
                'client_email' => $data['client_email']] + $this->periodFields($data));
            $this->documents()->keepRegistration($company, $request->file('registration_file'), $read);
            $this->makeLogin($company, $data['username'], $data['password']);
            if ($request->boolean('checklist')) {
                $this->workspace->applyChecklist($company, (int) substr($data['period_end'], 0, 4), $request->user());   // the year the period ends in
            }

            return $company;
        });
        $warnings = array_filter([$this->documents()->nameWarning($company, $read),
            $request->hasFile('articles_file') ? $this->documents()->keepArticles($company->fresh(), $request->file('articles_file')) : null]);

        return redirect()->route('clients.show', $company)->with('status', __('portal.created'))->with('warnings', $warnings)
            ->with('credentials', ['username' => $data['username'], 'password' => $data['password']]);
    }

    public function edit(Company $company): View
    {
        return view('portal.client-form', ['office' => auth()->user()->office, 'year' => null, 'company' => $company, 'user' => User::where('company_id', $company->id)->first()]);
    }

    /** Changes a client's details: the person, the company, the fiscal year, and (read again) the registration and the articles. */
    public function update(Request $request, Company $company): RedirectResponse
    {
        $login = User::where('company_id', $company->id)->first();
        $request->merge(['client_email' => mb_strtolower(trim((string) $request->input('client_email')))]);
        $data = $request->validate($this->rules($company, $login) + ['cr_number' => ['nullable', 'regex:/^\d{10}$/']]);
        $this->checkPeriod($data);
        $read = null;
        if ($request->hasFile('registration_file')) {
            $read = $this->documents()->readRegistration($request->file('registration_file'), $company->id);   // the file's number wins over a typed one
        } elseif (! empty($data['cr_number']) && $data['cr_number'] !== $company->cr_number) {
            if ($taken = Company::withRegistration($data['cr_number'], $company->id)) {
                throw ValidationException::withMessages(['cr_number' => __('portal.form.cr_taken', ['number' => $data['cr_number'], 'client' => $taken->clientName(), 'company' => $taken->name_ar])]);
            }
        }
        DB::transaction(function () use ($company, $data, $login, $request, $read) {
            $company->update(['name_ar' => $data['name_ar'], 'name_en' => $data['name_en'], 'client_name' => $data['client_name'], 'client_email' => $data['client_email']] + $this->periodFields($data)
                + (! $read && ! empty($data['cr_number']) ? ['cr_number' => $data['cr_number']] : []));
            $read && $this->documents()->keepRegistration($company, $request->file('registration_file'), $read);
            $login?->update(['name' => $data['client_name'], 'email' => $this->loginEmail($data['client_email'], $login)]);
        });
        $warnings = array_filter([$read ? $this->documents()->nameWarning($company->fresh(), $read) : null,
            $request->hasFile('articles_file') ? $this->documents()->keepArticles($company->fresh(), $request->file('articles_file')) : null]);

        return redirect()->route('clients.show', $company)->with('status', __('portal.updated'))->with('warnings', $warnings);
    }

    /** The office sends a client's files in one compressed file: unpacked and sorted like the client's own. */
    public function archive(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['archive' => Workspace::archiveRules(), 'year' => ['required', 'integer', Rule::in(Workspace::years($company))]]);
        if (! DocumentRequest::where('company_id', $company->id)->where('year', $data['year'])->exists()) {
            return back()->withErrors(['year' => __('portal.archive.no_requests')]);
        }
        $this->workspace->receiveArchive($company, $request->file('archive'), (int) $data['year'], $request->user());

        return back()->with('status', __('portal.archive.queued'));
    }

    /** Deletes the client for good, with everything of theirs (ClientEraser). The client's name is typed to confirm. */
    public function destroy(Request $request, Company $company): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        if (trim((string) $request->input('confirm')) !== $company->clientName()) {
            return back()->withErrors(['confirm' => __('portal.erase.mismatch')]);
        }
        $name = $company->clientName();
        $gone = app(\App\Services\Portal\ClientEraser::class)->erase($company);

        return redirect()->route('clients.index')->with('status', __('portal.erase.done', ['name' => $name, 'files' => $gone['files'], 'statements' => $gone['statements']]));
    }

    /** The registration or the articles the office gave for the client, as uploaded. */
    public function document(Company $company, string $kind): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(in_array($kind, ['registration', 'articles'], true), 404);
        $doc = $company->profile[$kind] ?? null;
        abort_unless($doc && \Illuminate\Support\Facades\Storage::disk('local')->exists($doc['file']), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($doc['file'], $doc['name'] ?? basename($doc['file']));
    }
    /** The client's uploaded files that can serve a purpose (?purpose=trial_balance|prior_statements|articles), the likeliest first. */
    public function pickFiles(Request $request, Company $company): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['purpose' => ['required', Rule::in(array_keys(\App\Support\ClientFileSource::PURPOSES))], 'year' => ['nullable', 'integer', 'between:2000,2100']]);

        return response()->json(\App\Support\ClientFileSource::forPurpose($company->id, $data['purpose'], isset($data['year']) ? (int) $data['year'] : null));
    }

    public function show(Request $request, Company $company): View
    {
        $years = Workspace::years($company);
        $year = (int) $request->query('year', $years[0]);
        $requests = DocumentRequest::where('company_id', $company->id)->where('year', $year)->with('files')->orderBy('position')->orderBy('id')->get();

        return view('portal.client', [
            'company' => $company, 'office' => $request->user()->office, 'portalUser' => User::where('company_id', $company->id)->first(),
            'years' => array_values(array_unique(array_merge($years, [$year]))), 'year' => $year,
            'sections' => $requests->groupBy('section'), 'progress' => Workspace::progress($requests),
            'unread' => $this->workspace->unread($company->id, false), 'canManage' => $request->user()->can('clients.manage'),
            'emails' => \App\Models\ClientEmail::where('company_id', $company->id)->where('year', $year)->latest('id')->limit(15)->get(),
            'checklistSize' => collect(config('audit_requirements'))->flatten(1)->count(),
            'archives' => \App\Models\ClientArchive::where('company_id', $company->id)->orderByDesc('id')->limit(6)->get(),
        ]);
    }

    /** The client's login: set a new password, switch it off or on, or make it when the company has none yet. */
    public function access(Request $request, Company $company): RedirectResponse
    {
        $portalUser = User::where('company_id', $company->id)->first();
        if (! $portalUser) {
            $request->merge(['username' => mb_strtolower(trim((string) $request->input('username')))]);
            $data = $request->validate([
                'username' => ['required', User::USERNAME_RULE, Rule::unique('users', 'username')->where('office_id', $request->user()->office_id)],
                'password' => ['required', Password::min(8)->letters()->numbers()],
            ]);
            $this->makeLogin($company, $data['username'], $data['password']);

            return back()->with('status', __('portal.login_created'))->with('credentials', $data);
        }
        $data = $request->validate(['password' => ['nullable', Password::min(8)->letters()->numbers()], 'active' => ['nullable', 'boolean']]);
        if (! empty($data['password'])) {
            $portalUser->update(['password' => $data['password']]);
            $portalUser->tokens()->delete();
        }
        if ($request->has('active')) {
            $portalUser->update(['status' => $request->boolean('active') ? User::ACTIVE : User::DISABLED]);
        }

        return back()->with('status', __('portal.login_saved'))->with('credentials', ! empty($data['password']) ? ['username' => $portalUser->username, 'password' => $data['password']] : null);
    }

    /** The checklist for a year: lines already requested stay as they are, the rest are added. */
    public function checklist(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']]);
        $added = $this->workspace->applyChecklist($company, (int) $data['year'], $request->user());

        return redirect()->route('clients.show', [$company, 'year' => $data['year']])->with('status', trans_choice('portal.checklist_applied', $added, ['count' => $added]));
    }

    public function storeRequest(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'], 'section' => ['required', 'string', 'max:255'], 'title' => ['required', 'string', 'max:500'],
            'format' => ['nullable', Rule::in(['excel', 'pdf'])], 'optional' => ['nullable', 'boolean'],
        ]);
        $this->workspace->addRequest($company, (int) $data['year'], $data['section'], $data['title'], $data['format'] ?? null, $request->boolean('optional'), $request->user());

        return redirect()->route('clients.show', [$company, 'year' => $data['year']])->with('status', __('portal.request_added'));
    }

    /** Accept, send back (with what to change), mark not applicable or reopen. */
    public function review(Request $request, Company $company, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->ownRequest($company, $documentRequest);
        $data = $request->validate([
            'status' => ['required', Rule::in(DocumentRequest::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['status'] === DocumentRequest::UPLOADED) {
            throw ValidationException::withMessages(['status' => __('portal.review.bad_status')]);
        }
        if ($data['status'] === DocumentRequest::RETURNED && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => __('portal.review.note_required')]);
        }
        $this->workspace->review($documentRequest, $data['status'], isset($data['note']) ? trim($data['note']) : null, $request->user());

        return back()->with('status', __('portal.review.saved'));
    }

    public function destroyRequest(Company $company, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->ownRequest($company, $documentRequest);
        foreach ($documentRequest->files as $file) {
            $this->workspace->deleteFile($file);
        }
        $documentRequest->delete();

        return back()->with('status', __('portal.request_deleted'));
    }

    private function ownRequest(Company $company, DocumentRequest $documentRequest): void
    {
        abort_unless($documentRequest->company_id === $company->id, 404);
    }

    /** The client's portal user: active at once, with the client role. */
    /** The client's email on their login only where no other login has it (one person can own several companies); the company keeps it always. */
    private function loginEmail(?string $email, ?User $own = null): ?string
    {
        return $email && ! User::withoutGlobalScopes()->where('email', $email)->when($own, fn ($q) => $q->where('id', '<>', $own->id))->exists() ? $email : null;
    }

    private function makeLogin(Company $company, string $username, string $password): User
    {
        $role = Role::client() ?? Role::create(['name_ar' => 'عميل', 'name_en' => 'Client', 'is_client' => true, 'open_for_signup' => false]);
        if (! $role->allows('portal.use')) {
            $role->syncPermissions(['portal.use']);
        }

        return User::create(['office_id' => $company->office_id, 'company_id' => $company->id, 'name' => $company->clientName(), 'email' => $this->loginEmail($company->client_email), 'username' => $username,
            'password' => $password, 'role_id' => $role->id, 'status' => User::ACTIVE, 'locale' => 'ar']);
    }
}
