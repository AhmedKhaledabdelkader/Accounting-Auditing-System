<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CacheTransfer;
use App\Services\ClassificationCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exceptions\LocalizedException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export of the classification cache (Excel for the accountant, JSON to move it elsewhere) and import of a JSON export.
 * They read and write the whole cache: the `cache.transfer` permission only.
 */
class ClassificationTransferController extends Controller
{
    public function __construct(private CacheTransfer $transfer) {}

    public function exportXlsx(Request $request): BinaryFileResponse
    {
        return response()->download($this->transfer->exportXlsx($this->filters($request)), 'classification-cache-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function exportJson(Request $request): StreamedResponse
    {
        $data = $this->transfer->exportJson($this->filters($request));

        return response()->streamDownload(fn () => print json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'classification-cache-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function importJson(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:51200']], [
            'file.required' => __('classification_messages.transfer.file_required'),
            'file.max'      => __('classification_messages.transfer.file_max'),
        ]);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($data)) {
            return response()->json(['message' => __('classification_messages.transfer.not_json')], 422);
        }
        try {
            return response()->json($this->transfer->importJson($data, $request->file('file')->getClientOriginalName()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => LocalizedException::phraseOf($e)->current()], 422);
        }
    }

    /** @return array{source: ?string, from: ?string, to: ?string} */
    private function filters(Request $request): array
    {
        $request->validate([
            'source' => ['nullable', Rule::in([ClassificationCache::AI, ClassificationCache::MANUAL, ClassificationCache::IMPORTED])],
            'from'   => ['nullable', 'date_format:Y-m-d'],
            'to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return ['source' => $request->input('source'), 'from' => $request->input('from'), 'to' => $request->input('to')];
    }
}
