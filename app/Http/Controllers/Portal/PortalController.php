<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequest;
use App\Services\Portal\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The client's side: their company's checklist (what the accountant asked for), uploads, «doesn't apply» and the chat. */
class PortalController extends Controller
{
    public function __construct(private readonly Workspace $workspace) {}

    public function home(Request $request): View
    {
        $company = $request->user()->company;
        abort_unless($company, 403);
        $years = Workspace::years($company);
        $year = (int) $request->query('year', $years[0]);
        $requests = DocumentRequest::where('company_id', $company->id)->where('year', $year)->with('files')->orderBy('position')->orderBy('id')->get();

        return view('portal.home', [
            'archives' => \App\Models\ClientArchive::where('company_id', $company->id)->orderByDesc('id')->limit(6)->get(),
            'company' => $company, 'office' => $request->user()->office, 'years' => $years, 'year' => $year,
            'sections' => $requests->groupBy('section'), 'progress' => Workspace::progress($requests),
            'unread' => $this->workspace->unread($company->id, true),
            'inbox' => $requests->whereIn('status', DocumentRequest::OPEN)->count(),
        ]);
    }

    /** The client sends everything in one compressed file: it is unpacked and each document put in the request it answers (the AI reads the names). */
    public function archive(Request $request): RedirectResponse
    {
        $company = $request->user()->company;
        abort_unless($company, 403);
        $data = $request->validate(['archive' => Workspace::archiveRules(), 'year' => ['required', 'integer', Rule::in(Workspace::years($company))]]);
        if (! DocumentRequest::where('company_id', $company->id)->where('year', $data['year'])->exists()) {
            return back()->withErrors(['year' => __('portal.archive.no_requests')]);
        }
        $this->workspace->receiveArchive($company, $request->file('archive'), (int) $data['year'], $request->user());

        return back()->with('status', __('portal.archive.queued'));
    }

    /** «This doesn't apply to us» on a request, or taking it back. */
    public function declare(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        abort_unless($documentRequest->company_id === $request->user()->company_id, 404);
        $data = $request->validate(['not_applicable' => ['required', 'boolean']]);
        $ok = $this->workspace->declare($documentRequest, (bool) $data['not_applicable'], $request->user());

        return back()->with($ok ? 'status' : 'error_status', __($ok ? 'portal.declared' : 'portal.already_accepted'));
    }
}
