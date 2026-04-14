<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfflineUploadItemResource;
use App\Models\OfflineUploadItem;
use App\Models\Upload;
use App\Models\Video;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfflineUploadQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $items = OfflineUploadItem::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->get();

        return response()->json([
            'message' => __('messages.offline_uploads.retrieved'),
            'data' => [
                'items' => OfflineUploadItemResource::collection($items),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'clientReference' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in(['image', 'gif', 'video'])],
            'title' => ['nullable', 'string', 'max:255'],
            'uploadId' => ['nullable', 'exists:uploads,id'],
            'videoId' => ['nullable', 'exists:videos,id'],
            'status' => ['sometimes', Rule::in(['queued', 'uploading', 'synced', 'failed', 'cancelled'])],
            'failureReason' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $this->ensureOwnedReferences($request, $validated['uploadId'] ?? null, $validated['videoId'] ?? null);

        $item = OfflineUploadItem::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'client_reference' => $validated['clientReference']],
            [
                'upload_id' => $validated['uploadId'] ?? null,
                'video_id' => $validated['videoId'] ?? null,
                'type' => $validated['type'],
                'title' => $validated['title'] ?? null,
                'status' => $validated['status'] ?? 'queued',
                'failure_reason' => $validated['failureReason'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
                'last_synced_at' => now(),
            ],
        );

        return response()->json([
            'message' => __('messages.offline_uploads.saved'),
            'data' => [
                'item' => new OfflineUploadItemResource($item),
            ],
        ], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, OfflineUploadItem $offlineUploadItem): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($offlineUploadItem->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['queued', 'uploading', 'synced', 'failed', 'cancelled'])],
            'failureReason' => ['nullable', 'string'],
            'uploadId' => ['nullable', 'exists:uploads,id'],
            'videoId' => ['nullable', 'exists:videos,id'],
            'metadata' => ['nullable', 'array'],
        ]);

        $this->ensureOwnedReferences($request, $validated['uploadId'] ?? null, $validated['videoId'] ?? null);

        $offlineUploadItem->fill([
            'title' => $validated['title'] ?? $offlineUploadItem->title,
            'status' => $validated['status'] ?? $offlineUploadItem->status,
            'failure_reason' => array_key_exists('failureReason', $validated) ? $validated['failureReason'] : $offlineUploadItem->failure_reason,
            'upload_id' => array_key_exists('uploadId', $validated) ? $validated['uploadId'] : $offlineUploadItem->upload_id,
            'video_id' => array_key_exists('videoId', $validated) ? $validated['videoId'] : $offlineUploadItem->video_id,
            'metadata' => $validated['metadata'] ?? $offlineUploadItem->metadata,
            'last_synced_at' => now(),
        ])->save();

        return response()->json([
            'message' => __('messages.offline_uploads.updated'),
            'data' => [
                'item' => new OfflineUploadItemResource($offlineUploadItem),
            ],
        ]);
    }

    private function ensureOwnedReferences(Request $request, ?int $uploadId, ?int $videoId): void
    {
        if ($uploadId) {
            abort_if(Upload::query()->where('user_id', $request->user()->id)->find($uploadId) === null, 403);
        }

        if ($videoId) {
            abort_if(Video::query()->where('user_id', $request->user()->id)->find($videoId) === null, 403);
        }
    }
}