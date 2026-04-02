<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\LiveSignal;
use App\Models\Upload;
use App\Models\User;
use App\Models\Video;
use App\Support\PaginatedJson;
use App\Support\UserNotifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VideoController extends Controller
{
    private const VIEW_DEDUP_MINUTES = 1440;

    private const SHARE_DEDUP_MINUTES = 60;

    public function index(Request $request): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        $videos = PaginatedJson::paginate(Video::query()
            ->withApiResourceData($viewer)
            ->where('is_draft', false)
            ->when($request->filled('category'), function ($query) use ($request): void {
                $category = $request->string('category')->toString();

                if (ctype_digit($category)) {
                    $query->where('category_id', (int) $category);

                    return;
                }

                $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('slug', $category));
            })
            ->latest(), $request);

        return $this->videoResponse($request, __('messages.videos.list_retrieved'), $videos);
    }

    public function trending(Request $request): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        $videos = PaginatedJson::paginate(Video::query()
            ->withApiResourceData($viewer)
            ->where('is_draft', false)
            ->orderByDesc('views_count')
            ->latest(), $request);

        return $this->videoResponse($request, __('messages.videos.trending_retrieved'), $videos);
    }

    public function live(Request $request): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        $videos = PaginatedJson::paginate(Video::query()
            ->withApiResourceData($viewer)
            ->where('is_draft', false)
            ->where('is_live', true)
            ->orderByDesc('live_started_at')
            ->latest(), $request);

        return $this->videoResponse($request, __('messages.videos.live_retrieved'), $videos);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:image,gif,video'],
            'categoryId' => ['nullable', 'exists:categories,id'],
            'uploadId' => ['nullable', 'exists:uploads,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'taggedUsers' => ['nullable', 'array'],
            'taggedUsers.*' => ['integer', 'exists:users,id'],
            'mediaUrl' => ['nullable', 'string', 'max:2048'],
            'thumbnailUrl' => ['nullable', 'string', 'max:2048'],
            'isLive' => ['sometimes', 'boolean'],
            'isDraft' => ['sometimes', 'boolean'],
        ]);

        $isLiveRequested = (bool) ($validated['isLive'] ?? false);

        abort_if($isLiveRequested && $validated['type'] !== 'video', 422, __('messages.videos.only_video_can_go_live'));

        $upload = isset($validated['uploadId']) ? Upload::query()->findOrFail($validated['uploadId']) : null;

        if ($upload && $upload->user_id !== $request->user()->id) {
            abort(403, __('messages.videos.upload_forbidden'));
        }

        if ($isLiveRequested) {
            $this->ensureUploadReadyForLive($upload);
        }

        $video = Video::create([
            'user_id' => $request->user()->id,
            'category_id' => $validated['categoryId'] ?? null,
            'upload_id' => $upload?->id,
            'type' => $validated['type'],
            'title' => $validated['title'] ?? null,
            'caption' => $validated['caption'] ?? null,
            'description' => $validated['description'] ?? ($validated['caption'] ?? null),
            'location' => $validated['location'] ?? null,
            'tagged_users' => $validated['taggedUsers'] ?? [],
            'media_url' => $validated['mediaUrl'] ?? $upload?->url,
            'thumbnail_url' => $validated['thumbnailUrl'] ?? null,
            'is_live' => $isLiveRequested,
            'is_draft' => $validated['isDraft'] ?? ! $isLiveRequested,
        ]);

        if ($isLiveRequested) {
            $this->startLiveSession($video);
        }

        $video = $this->loadVideoForResource($video->id, $request->user());

        return response()->json([
            'message' => __('messages.videos.created'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ], 201);
    }

    public function show(Request $request, Video $video): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        if ($video->is_draft && (! $viewer || $viewer->id !== $video->user_id)) {
            abort(404);
        }

        $video = $this->loadVideoForResource($video->id, $viewer);

        return response()->json([
            'message' => __('messages.videos.retrieved'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function related(Request $request, Video $video): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        $related = PaginatedJson::paginate(Video::query()
            ->withApiResourceData($viewer)
            ->where('is_draft', false)
            ->where('id', '!=', $video->id)
            ->where(function ($query) use ($video): void {
                $query->where('user_id', $video->user_id);

                if ($video->category_id) {
                    $query->orWhere('category_id', $video->category_id);
                }
            })
            ->latest(), $request, 8, 24);

        return $this->videoResponse($request, __('messages.videos.related_retrieved'), $related);
    }

    public function recordView(Request $request, Video $video): JsonResponse
    {
        if ($this->shouldRecordEngagement($request, $video, 'view', self::VIEW_DEDUP_MINUTES)) {
            $video->increment('views_count');
        }

        $video = $this->loadVideoForResource($video->id, auth('sanctum')->user() ?? $request->user());

        return response()->json([
            'message' => __('messages.videos.view_recorded'),
            'data' => [
                'views' => (int) $video->views_count,
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function report(Request $request, Video $video): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::table('video_reports')->insert([
            'video_id' => $video->id,
            'user_id' => $request->user()->id,
            'reason' => $validated['reason'] ?? null,
            'details' => $validated['details'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => __('messages.videos.reported'),
        ], 201);
    }

    public function update(Request $request, Video $video): JsonResponse
    {
        abort_if($video->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'type' => ['sometimes', 'in:image,gif,video'],
            'categoryId' => ['nullable', 'exists:categories,id'],
            'uploadId' => ['nullable', 'exists:uploads,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'taggedUsers' => ['nullable', 'array'],
            'taggedUsers.*' => ['integer', 'exists:users,id'],
            'mediaUrl' => ['nullable', 'string', 'max:2048'],
            'thumbnailUrl' => ['nullable', 'string', 'max:2048'],
            'isLive' => ['sometimes', 'boolean'],
            'isDraft' => ['sometimes', 'boolean'],
        ]);

        $requestedLive = array_key_exists('isLive', $validated) ? (bool) $validated['isLive'] : null;
        $nextType = $validated['type'] ?? $video->type;

        abort_if(($requestedLive === true || $video->is_live) && $nextType !== 'video', 422, __('messages.videos.only_video_can_go_live'));

        $upload = array_key_exists('uploadId', $validated)
            ? Upload::query()->find($validated['uploadId'])
            : $video->upload;

        if ($upload && $upload->user_id !== $request->user()->id) {
            abort(403, __('messages.videos.upload_forbidden'));
        }

        if ($requestedLive === true) {
            $this->ensureUploadReadyForLive($upload);
        }

        $video->fill([
            'type' => $validated['type'] ?? $video->type,
            'category_id' => $validated['categoryId'] ?? $video->category_id,
            'upload_id' => $upload?->id,
            'title' => $validated['title'] ?? $video->title,
            'caption' => array_key_exists('caption', $validated) ? $validated['caption'] : $video->caption,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $video->description,
            'location' => array_key_exists('location', $validated) ? $validated['location'] : $video->location,
            'tagged_users' => $validated['taggedUsers'] ?? $video->tagged_users,
            'media_url' => $validated['mediaUrl'] ?? $upload?->url ?? $video->media_url,
            'thumbnail_url' => array_key_exists('thumbnailUrl', $validated) ? $validated['thumbnailUrl'] : $video->thumbnail_url,
            'is_draft' => $validated['isDraft'] ?? $video->is_draft,
        ])->save();

        if ($requestedLive === true) {
            $this->startLiveSession($video);
        }

        if ($requestedLive === false && $video->is_live) {
            $this->stopLiveSession($video);
        }

        $video = $this->loadVideoForResource($video->id, $request->user());

        return response()->json([
            'message' => __('messages.videos.updated'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function publish(Request $request, Video $video): JsonResponse
    {
        abort_if($video->user_id !== $request->user()->id, 403);

        $video->forceFill(['is_draft' => false])->save();
        $video = $this->loadVideoForResource($video->id, $request->user());

        return response()->json([
            'message' => __('messages.videos.published'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function startLive(Request $request, Video $video): JsonResponse
    {
        abort_if($video->user_id !== $request->user()->id, 403);
        abort_if($video->type !== 'video', 422, __('messages.videos.only_video_can_go_live'));

        $this->ensureUploadReadyForLive($video->upload);

        $this->startLiveSession($video);
        $video = $this->loadVideoForResource($video->id, $request->user());

        return response()->json([
            'message' => __('messages.videos.live_started'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function stopLive(Request $request, Video $video): JsonResponse
    {
        abort_if($video->user_id !== $request->user()->id, 403);

        $this->stopLiveSession($video);
        $video = $this->loadVideoForResource($video->id, $request->user());

        return response()->json([
            'message' => __('messages.videos.live_stopped'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function sendSignal(Request $request, Video $video): JsonResponse
    {
        $viewer = $request->user();
        $this->ensureVideoIsLive($video);

        $validated = $request->validate([
            'recipientId' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:offer,answer,candidate'],
            'sdp' => ['nullable', 'string'],
            'candidate' => ['nullable', 'array'],
        ]);

        $isCreator = $viewer->id === $video->user_id;
        $recipientId = $isCreator ? ($validated['recipientId'] ?? null) : $video->user_id;

        abort_if($isCreator && ! $recipientId, 422, __('messages.videos.live_signal_recipient_required'));
        abort_if(! $isCreator && $validated['type'] === 'answer', 422, __('messages.videos.live_signal_type_not_allowed'));
        abort_if($isCreator && $validated['type'] === 'offer', 422, __('messages.videos.live_signal_type_not_allowed'));
        abort_if($recipientId === $viewer->id, 422, __('messages.videos.live_signal_recipient_invalid'));
        abort_if(in_array($validated['type'], ['offer', 'answer'], true) && ! filled($validated['sdp'] ?? null), 422, __('messages.videos.live_signal_payload_required'));
        abort_if($validated['type'] === 'candidate' && empty($validated['candidate']), 422, __('messages.videos.live_signal_payload_required'));

        $signal = LiveSignal::query()->create([
            'video_id' => $video->id,
            'sender_id' => $viewer->id,
            'recipient_id' => $recipientId,
            'kind' => $validated['type'],
            'payload' => array_filter([
                'sdp' => $validated['sdp'] ?? null,
                'candidate' => $validated['candidate'] ?? null,
            ], static fn ($value) => $value !== null),
        ]);

        return response()->json([
            'message' => __('messages.videos.live_signal_sent'),
            'data' => [
                'signal' => $this->formatLiveSignal($signal),
            ],
        ], 201);
    }

    public function getSignals(Request $request, Video $video): JsonResponse
    {
        $viewer = $request->user();
        $this->ensureVideoIsLive($video);

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $after = (int) ($validated['after'] ?? 0);

        $signals = LiveSignal::query()
            ->where('video_id', $video->id)
            ->where('recipient_id', $viewer->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->get();

        $latestSignalId = $signals->last()?->id ?? $after;

        return response()->json([
            'message' => __('messages.videos.live_signals_retrieved'),
            'data' => [
                'signals' => $signals->map(fn (LiveSignal $signal) => $this->formatLiveSignal($signal))->values(),
                'latestSignalId' => $latestSignalId,
            ],
        ]);
    }

    public function share(Request $request, Video $video): JsonResponse
    {
        if ($this->shouldRecordEngagement($request, $video, 'share', self::SHARE_DEDUP_MINUTES)) {
            $video->increment('shares_count');
        }

        return response()->json([
            'message' => __('messages.videos.share_recorded'),
            'data' => [
                'shares' => (int) $video->shares_count,
                'shareUrl' => $this->buildFrontendVideoUrl($video),
            ],
        ]);
    }

    private function buildFrontendVideoUrl(Video $video): string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $path = ($video->is_live ? '/live/' : '/video/').$video->id;

        return $baseUrl.$path;
    }

    private function videoResponse(Request $request, string $message, LengthAwarePaginator $videos): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                'videos' => PaginatedJson::items($request, $videos, VideoResource::class),
            ],
            'meta' => [
                'videos' => PaginatedJson::meta($videos),
            ],
        ]);
    }

    private function loadVideoForResource(int $videoId, ?User $viewer): Video
    {
        return Video::query()
            ->withApiResourceData($viewer)
            ->findOrFail($videoId);
    }

    private function startLiveSession(Video $video): void
    {
        $wasLive = (bool) $video->is_live;
        $shouldNotify = ! $wasLive && $video->live_notified_at === null;

        if (! $wasLive) {
            $video->liveSignals()->delete();
        }

        $video->forceFill([
            'is_live' => true,
            'is_draft' => false,
            'live_started_at' => $wasLive ? ($video->live_started_at ?? now()) : now(),
            'live_ended_at' => null,
        ])->save();

        if (! $shouldNotify) {
            return;
        }

        $this->notifySubscribersAboutLive($video->fresh(['user']));

        $video->forceFill([
            'live_notified_at' => now(),
        ])->save();
    }

    private function ensureUploadReadyForLive(?Upload $upload): void
    {
        if (! $upload) {
            return;
        }

        abort_if(
            $upload->processing_status !== 'completed',
            422,
            __('messages.videos.upload_must_finish_processing_for_live')
        );
    }

    private function stopLiveSession(Video $video): void
    {
        $video->liveSignals()->delete();

        $video->forceFill([
            'is_live' => false,
            'is_draft' => true,
            'live_ended_at' => now(),
            'live_notified_at' => null,
        ])->save();
    }

    private function ensureVideoIsLive(Video $video): void
    {
        abort_if(! $video->is_live, 409, __('messages.videos.live_not_active'));
    }

    private function formatLiveSignal(LiveSignal $signal): array
    {
        return [
            'id' => $signal->id,
            'type' => $signal->kind,
            'payload' => $signal->payload ?? [],
            'senderId' => $signal->sender_id,
            'recipientId' => $signal->recipient_id,
            'createdAt' => $signal->created_at?->toISOString(),
        ];
    }

    private function notifySubscribersAboutLive(Video $video): void
    {
        $subscriberIds = DB::table('subscriptions')
            ->where('creator_id', $video->user_id)
            ->pluck('user_id');

        $creatorName = $video->user?->name ?: 'Creator';

        foreach ($subscriberIds as $subscriberId) {
            UserNotifier::sendTranslated(
                (int) $subscriberId,
                $video->user_id,
                'live',
                'messages.notifications.live_now_title',
                'messages.notifications.live_now_body',
                ['name' => $creatorName],
                ['creatorId' => $video->user_id, 'videoId' => $video->id],
            );
        }
    }

    private function shouldRecordEngagement(Request $request, Video $video, string $metric, int $ttlMinutes): bool
    {
        return Cache::add($this->engagementCacheKey($request, $video, $metric), true, now()->addMinutes($ttlMinutes));
    }

    private function engagementCacheKey(Request $request, Video $video, string $metric): string
    {
        return sprintf(
            'video-engagement:%s:%d:%s',
            $metric,
            $video->id,
            sha1($this->engagementActorFingerprint($request))
        );
    }

    private function engagementActorFingerprint(Request $request): string
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        if ($viewer) {
            return 'user:'.$viewer->getAuthIdentifier();
        }

        return 'guest:'.($request->ip() ?? 'unknown-ip').':'.sha1((string) $request->userAgent());
    }
}