<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientFile;
use App\Models\ClientMessage;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Services\Portal\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files and chat, which the office (on /clients/{company}/…) and the client (on /portal/…) share. A client's company is
 * always their own, whatever the URL says; the office's is the one in the URL, and only if it is its own (a 404 else).
 */
class WorkspaceController extends Controller
{
    public function __construct(private readonly Workspace $workspace) {}

    /** The company this call is about, for the signed-in side. */
    private function company(Request $request, ?Company $company): Company
    {
        $user = $request->user();
        if ($user->isClient()) {
            $own = $user->company;
            abort_unless($own && (! $company || $company->id === $own->id), 404);

            return $own;
        }
        abort_unless($company, 404);

        return $company;
    }

    private function request(Company $company, DocumentRequest $documentRequest): DocumentRequest
    {
        abort_unless($documentRequest->company_id === $company->id, 404);

        return $documentRequest;
    }

    private function file(Company $company, ClientFile $file): ClientFile
    {
        abort_unless($file->company_id === $company->id, 404);

        return $file;
    }

    /** One or more files to a request. A client's upload puts the request back in the accountant's tray. */
    public function upload(Request $request, Company $company, DocumentRequest $documentRequest): RedirectResponse
    {
        return $this->storeFiles($request, $company, $documentRequest);
    }

    public function portalUpload(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        return $this->storeFiles($request, null, $documentRequest);
    }

    private function storeFiles(Request $request, ?Company $company, DocumentRequest $documentRequest): RedirectResponse
    {
        $company = $this->company($request, $company);
        $this->request($company, $documentRequest);
        $validator = Validator::make($request->all(), ['files' => ['required', 'array', 'min:1', 'max:20'], 'files.*' => Workspace::uploadRules()]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->with('open_request', $documentRequest->id);
        }
        $count = $this->workspace->upload($documentRequest, $request->file('files'), $request->user());

        return back()->with('status', trans_choice('portal.uploaded', $count, ['count' => $count]))->with('open_request', $documentRequest->id);
    }

    public function download(Request $request, Company $company, ClientFile $file): StreamedResponse
    {
        return $this->sendFile($request, $company, $file);
    }

    public function portalDownload(Request $request, ClientFile $file): StreamedResponse
    {
        return $this->sendFile($request, null, $file);
    }

    private function sendFile(Request $request, ?Company $company, ClientFile $file): StreamedResponse
    {
        $company = $this->company($request, $company);
        $this->file($company, $file);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    /** The accountant may delete any file; a client only their own, and not once the document is accepted. */
    public function destroy(Request $request, Company $company, ClientFile $file): RedirectResponse
    {
        return $this->removeFile($request, $company, $file);
    }

    public function portalDestroy(Request $request, ClientFile $file): RedirectResponse
    {
        return $this->removeFile($request, null, $file);
    }

    private function removeFile(Request $request, ?Company $company, ClientFile $file): RedirectResponse
    {
        $company = $this->company($request, $company);
        $this->file($company, $file);
        if ($request->user()->isClient()) {
            abort_unless($file->from_client && $file->documentRequest?->status !== DocumentRequest::ACCEPTED, 403);
        }
        $this->workspace->deleteFile($file);

        return back()->with('status', __('portal.file_deleted'));
    }

    /** A thread's new messages: ?request= (a request's id; none = the general chat) and ?after= (the last id the page has). */
    public function messages(Request $request, Company $company): JsonResponse
    {
        return $this->thread($request, $company);
    }

    public function portalMessages(Request $request): JsonResponse
    {
        return $this->thread($request, null);
    }

    private function thread(Request $request, ?Company $company): JsonResponse
    {
        $company = $this->company($request, $company);
        $data = $request->validate(['request' => ['nullable', 'integer'], 'after' => ['nullable', 'integer', 'min:0']]);
        $requestId = isset($data['request']) ? $this->request($company, DocumentRequest::findOrFail($data['request']))->id : null;

        return response()->json([
            'messages' => $this->workspace->thread($company, $requestId, (int) ($data['after'] ?? 0), $request->user()->isClient()),
            'unread' => $this->workspace->unread($company->id, $request->user()->isClient()),
        ]);
    }

    public function send(Request $request, Company $company): JsonResponse
    {
        return $this->post($request, $company);
    }

    public function portalSend(Request $request): JsonResponse
    {
        return $this->post($request, null);
    }

    private function post(Request $request, ?Company $company): JsonResponse
    {
        $company = $this->company($request, $company);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000'], 'request' => ['nullable', 'integer']]);
        $documentRequest = isset($data['request']) ? $this->request($company, DocumentRequest::findOrFail($data['request'])) : null;
        $body = trim($data['body']);
        abort_if($body === '', 422);
        $message = $this->workspace->say($documentRequest, $request->user(), $request->user()->isClient(), ClientMessage::TEXT, $body, $company->id);

        return response()->json(['id' => $message->id], 201);
    }
}
