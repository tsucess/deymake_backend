<?php

namespace App\Http\Controllers\Api;

use App\Events\LiveAudienceUpdated;
use App\Events\LivePresenceUpdated;
use App\Events\LiveSignalCreated;
use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Comment;
use App\Models\FanTip;
use App\Models\LiveLikeEvent;
use App\Models\LivePresenceSession;
use App\Models\LiveSignal;
use App\Models\Upload;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use App\Services\CloudinaryUploadService;
use App\Services\ContentModerationService;
use App\Support\PaginatedJson;
use App\Support\Username;
use App\Support\UserNotifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use TaylanUnutmaz\AgoraTokenBuilder\RtcTokenBuilder;

class VideoController extends Controller
{
    private const VIEW_DEDUP_MINUTES = 1440;

    private const SHARE_DEDUP_MINUTES = 60;

    private const LIVE_PRESENCE_TTL_SECONDS = 30;

    private const LIVE_ANALYTICS_LEADERBOARD_LIMIT = 5;

    private const LIVE_ACTIVE_AUDIENCE_LIMIT = 30;

    private const LIVE_ANALYTICS_PEAK_MOMENTS_LIMIT = 3;

    private const LIVE_ANALYTICS_MIN_BUCKETS = 4;

    private const LIVE_ANALYTICS_MAX_BUCKETS = 8;

    public function index(Request $request): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        $videos = PaginatedJson::paginate(Video::query()
            ->withApiResourceData($viewer)
            ->discoverable()
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
            ->discoverable()
            ->orderByDesc('views_count')
            ->latest(), $request);

        return $this->videoResponse($request, __('messages.videos.trending_retrieved'), $videos);
    }

    public function live(Request $request): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        $videos = PaginatedJson::paginate(Video::query()
            ->withApiResourceData($viewer)
            ->discoverable()
            ->where('is_live', true)
            ->orderByDesc('live_started_at')
            ->latest(), $request);

        return $this->videoResponse($request, __('messages.videos.live_retrieved'), $videos);
    }

    public function store(Request $request, ContentModerationService $moderationService): JsonResponse
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

        $caption = $validated['caption'] ?? null;
        $description = $validated['description'] ?? ($caption ?: null);
        $taggedUsers = $this->resolveTaggedUserIds($caption, $description, $validated['taggedUsers'] ?? []);
        $mediaUrl = $validated['mediaUrl'] ?? $upload?->url;
        $thumbnailUrl = $this->resolveThumbnailUrl(
            $validated['type'],
            $validated,
            $upload,
            $mediaUrl,
        );

        $video = Video::create([
            'user_id' => $request->user()->id,
            'category_id' => $validated['categoryId'] ?? null,
            'upload_id' => $upload?->id,
            'type' => $validated['type'],
            'title' => $validated['title'] ?? null,
            'caption' => $caption,
            'description' => $description,
            'location' => $validated['location'] ?? null,
            'tagged_users' => $taggedUsers,
            'media_url' => $mediaUrl,
            'thumbnail_url' => $thumbnailUrl,
            'is_live' => $isLiveRequested,
            'is_draft' => $validated['isDraft'] ?? ! $isLiveRequested,
        ]);

        if ($isLiveRequested) {
            $this->startLiveSession($video);
        }

        $moderationService->scanVideo($video);

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

        if (! $video->isVisibleTo($viewer)) {
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
            ->discoverable()
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

    public function report(Request $request, Video $video, ContentModerationService $moderationService): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = VideoReport::query()->create([
            'video_id' => $video->id,
            'user_id' => $request->user()->id,
            'reason' => $validated['reason'] ?? null,
            'details' => $validated['details'] ?? null,
            'status' => 'pending',
        ]);

        $moderationService->registerVideoReport($report);

        return response()->json([
            'message' => __('messages.videos.reported'),
        ], 201);
    }

    public function update(Request $request, Video $video, ContentModerationService $moderationService): JsonResponse
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

        $caption = array_key_exists('caption', $validated) ? $validated['caption'] : $video->caption;
        $description = array_key_exists('description', $validated) ? $validated['description'] : $video->description;
        $taggedUsers = $this->resolveTaggedUserIds($caption, $description, $validated['taggedUsers'] ?? []);
        $mediaUrl = $validated['mediaUrl'] ?? $upload?->url ?? $video->media_url;
        $thumbnailShouldRefreshFromVideo = array_key_exists('uploadId', $validated)
            || array_key_exists('mediaUrl', $validated)
            || array_key_exists('type', $validated);
        $thumbnailUrl = $this->resolveThumbnailUrl(
            $nextType,
            $validated,
            $upload,
            $mediaUrl,
            $video->thumbnail_url,
            $thumbnailShouldRefreshFromVideo,
        );

        $video->fill([
            'type' => $validated['type'] ?? $video->type,
            'category_id' => $validated['categoryId'] ?? $video->category_id,
            'upload_id' => $upload?->id,
            'title' => $validated['title'] ?? $video->title,
            'caption' => $caption,
            'description' => $description,
            'location' => array_key_exists('location', $validated) ? $validated['location'] : $video->location,
            'tagged_users' => $taggedUsers,
            'media_url' => $mediaUrl,
            'thumbnail_url' => $thumbnailUrl,
            'is_draft' => $validated['isDraft'] ?? $video->is_draft,
        ])->save();

        if ($requestedLive === true) {
            $this->startLiveSession($video);
        }

        if ($requestedLive === false && $video->is_live) {
            $this->stopLiveSession($video);
        }

        $moderationService->scanVideo($video);

        $video = $this->loadVideoForResource($video->id, $request->user());

        return response()->json([
            'message' => __('messages.videos.updated'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function publish(Request $request, Video $video, ContentModerationService $moderationService): JsonResponse
    {
        abort_if($video->user_id !== $request->user()->id, 403);

        $video->forceFill(['is_draft' => false])->save();
        $moderationService->scanVideo($video);
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

    public function liveSession(Request $request, Video $video): JsonResponse
    {
        abort_if($video->type !== 'video', 422, __('messages.videos.only_video_can_go_live'));

        $viewer = $request->user();
        $isCreator = $viewer->id === $video->user_id;
        $requestedRole = strtolower((string) $request->query('role', 'audience'));
        $agoraRole = $requestedRole === 'host' ? 'host' : 'audience';

        abort_if(! $video->is_live && ! $isCreator, 409, __('messages.videos.live_not_active'));
        abort_if(
            $agoraRole === 'host' && ! $this->canJoinLiveStageAsHost($video, $viewer),
            403,
            __('messages.videos.live_stage_access_denied')
        );

        $appId = (string) config('services.agora.app_id');
        $appCertificate = (string) config('services.agora.app_certificate');
        $ttlSeconds = max((int) config('services.agora.token_ttl', 3600), 60);

        abort_if($appId === '' || $appCertificate === '', 503, 'Agora live streaming is not configured.');

        $channelName = $this->buildLiveChannelName($video);
        $userAccount = sprintf('user-%s', $viewer->id);
        $expiresAt = now()->addSeconds($ttlSeconds);
        $token = RtcTokenBuilder::buildTokenWithUserAccount(
            $appId,
            $appCertificate,
            $channelName,
            $userAccount,
            $agoraRole === 'host' ? RtcTokenBuilder::RolePublisher : RtcTokenBuilder::RoleSubscriber,
            $expiresAt->timestamp,
        );

        return response()->json([
            'message' => __('messages.videos.live_session_retrieved'),
            'data' => [
                'session' => [
                    'appId' => $appId,
                    'channelName' => $channelName,
                    'token' => $token,
                    'uid' => $userAccount,
                    'role' => $agoraRole,
                    'expiresAt' => $expiresAt->toISOString(),
                ],
            ],
        ]);
    }

    public function liveEngagements(Request $request, Video $video): JsonResponse
    {
        $viewer = $request->user();
        $isCreator = $viewer->id === $video->user_id;

        abort_if($video->type !== 'video', 422, __('messages.videos.only_video_can_go_live'));
        abort_if(! $video->is_live && ! $isCreator, 409, __('messages.videos.live_not_active'));

        $limit = min(max((int) $request->query('limit', 12), 1), 25);

        $likeEvents = $this->liveLikeEventsQuery($video)
            ->with('user')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (LiveLikeEvent $event): array => [
                'id' => 'like-'.$event->id,
                'type' => 'like',
                'body' => null,
                'createdAt' => $event->created_at?->toISOString(),
                'actor' => $this->formatLiveEngagementActor($event->user),
            ]);

        $commentEvents = $this->liveCommentsQuery($video)
            ->with('user')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Comment $comment): array => [
                'id' => 'comment-'.$comment->id,
                'type' => 'comment',
                'body' => $comment->body,
                'createdAt' => $comment->created_at?->toISOString(),
                'actor' => $this->formatLiveEngagementActor($comment->user),
            ]);

        $tipEvents = $this->liveFanTipsQuery($video)
            ->with('fan')
            ->latest('tipped_at')
            ->limit($limit)
            ->get()
            ->map(fn (FanTip $tip): array => $this->formatLiveTipEngagement($tip, ! $isCreator));

        $engagements = $likeEvents
            ->concat($commentEvents)
            ->concat($tipEvents)
            ->sortByDesc('createdAt')
            ->take($limit)
            ->values();

        $data = [
            'engagements' => $engagements,
        ];

        if ($isCreator && $request->boolean('includeSummary')) {
            $data['summary'] = $this->buildLiveEngagementSummary($video);
        }

        return response()->json([
            'message' => __('messages.videos.retrieved'),
            'data' => $data,
        ]);
    }

    public function recordPresence(Request $request, Video $video): JsonResponse
    {
        $validated = $request->validate([
            'sessionKey' => ['required', 'string', 'max:120'],
            'role' => ['nullable', 'in:host,audience'],
        ]);

        abort_if($video->type !== 'video', 422, __('messages.videos.only_video_can_go_live'));
        $this->ensureVideoIsLive($video);
        abort_if(
            ($validated['role'] ?? 'audience') === 'host' && ! $this->canJoinLiveStageAsHost($video, $request->user()),
            403,
            __('messages.videos.live_stage_access_denied')
        );

        $presence = LivePresenceSession::query()->firstOrNew([
            'video_id' => $video->id,
            'session_key' => $validated['sessionKey'],
        ]);

        if (! $presence->exists) {
            $presence->joined_at = now();
        }

        $presence->user_id = $request->user()->id;
        $presence->role = $validated['role'] ?? 'audience';
        $presence->last_seen_at = now();
        $presence->left_at = null;
        $presence->save();

        $currentViewers = $this->activePresenceCount($video);

        if ($currentViewers > (int) $video->live_peak_viewers_count) {
            $video->forceFill(['live_peak_viewers_count' => $currentViewers])->save();
        }

        $analytics = $this->buildLivePresenceAnalytics($video, $currentViewers);

        LivePresenceUpdated::dispatch($video->id, $analytics);
        LiveAudienceUpdated::dispatch($video->id, $this->buildLiveAudiencePayload($video), $analytics);

        return response()->json([
            'message' => __('messages.videos.updated'),
            'data' => [
                'analytics' => $analytics,
            ],
        ]);
    }

    public function leavePresence(Request $request, Video $video): JsonResponse
    {
        $validated = $request->validate([
            'sessionKey' => ['required', 'string', 'max:120'],
        ]);

        abort_if($video->type !== 'video', 422, __('messages.videos.only_video_can_go_live'));

        LivePresenceSession::query()
            ->where('video_id', $video->id)
            ->where('session_key', $validated['sessionKey'])
            ->where('user_id', $request->user()->id)
            ->update([
                'left_at' => now(),
                'last_seen_at' => now(),
            ]);

        $analytics = $this->buildLivePresenceAnalytics($video);

        LivePresenceUpdated::dispatch($video->id, $analytics);
        LiveAudienceUpdated::dispatch($video->id, $this->buildLiveAudiencePayload($video), $analytics);

        return response()->json([
            'message' => __('messages.videos.updated'),
            'data' => [
                'analytics' => $analytics,
            ],
        ]);
    }

    public function sendSignal(Request $request, Video $video): JsonResponse
    {
        $viewer = $request->user();
        $this->ensureVideoIsLive($video);

        $validated = $request->validate([
            'recipientId' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:offer,answer,candidate,join_request,join_request_accepted,join_request_rejected,join_invite,join_invite_accepted,join_invite_rejected,cohost_left'],
            'sdp' => ['nullable', 'string'],
            'candidate' => ['nullable', 'array'],
        ]);

        $isCreator = $viewer->id === $video->user_id;
        $recipientId = $isCreator ? ($validated['recipientId'] ?? null) : $video->user_id;
        $type = $validated['type'];
        $creatorAllowedTypes = ['answer', 'candidate', 'join_invite', 'join_request_accepted', 'join_request_rejected', 'cohost_left'];
        $viewerAllowedTypes = ['offer', 'candidate', 'join_request', 'join_invite_accepted', 'join_invite_rejected', 'cohost_left'];

        abort_if($isCreator && ! $recipientId, 422, __('messages.videos.live_signal_recipient_required'));
        abort_if($isCreator && ! in_array($type, $creatorAllowedTypes, true), 422, __('messages.videos.live_signal_type_not_allowed'));
        abort_if(! $isCreator && ! in_array($type, $viewerAllowedTypes, true), 422, __('messages.videos.live_signal_type_not_allowed'));
        abort_if($recipientId === $viewer->id, 422, __('messages.videos.live_signal_recipient_invalid'));
        abort_if(in_array($type, ['offer', 'answer'], true) && ! filled($validated['sdp'] ?? null), 422, __('messages.videos.live_signal_payload_required'));
        abort_if($type === 'candidate' && empty($validated['candidate']), 422, __('messages.videos.live_signal_payload_required'));

        $signal = LiveSignal::query()->create([
            'video_id' => $video->id,
            'sender_id' => $viewer->id,
            'recipient_id' => $recipientId,
            'kind' => $type,
            'payload' => array_filter([
                'sdp' => $validated['sdp'] ?? null,
                'candidate' => $validated['candidate'] ?? null,
            ], static fn ($value) => $value !== null),
        ])->load(['sender', 'recipient']);

        LiveSignalCreated::dispatch($video->id, (int) $recipientId, $this->formatLiveSignal($signal));

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
            ->with(['sender', 'recipient'])
            ->where('video_id', $video->id)
            ->where(function ($query) use ($viewer): void {
                $query
                    ->where('recipient_id', $viewer->id)
                    ->orWhere(function ($stageQuery) use ($viewer): void {
                        $stageQuery
                            ->where('sender_id', $viewer->id)
                            ->whereIn('kind', [
                                'join_request',
                                'join_request_accepted',
                                'join_request_rejected',
                                'join_invite',
                                'join_invite_accepted',
                                'join_invite_rejected',
                                'cohost_left',
                            ]);
                    });
            })
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

    public function liveAudience(Request $request, Video $video): JsonResponse
    {
        $this->ensureVideoIsLive($video);
        abort_unless($request->user()->id === $video->user_id, 403);

        $audience = $this->activeAudienceMembers($video)
            ->map(fn (LivePresenceSession $presence): array => $this->formatLiveAudienceMember($presence))
            ->values();

        return response()->json([
            'message' => __('messages.videos.live_audience_retrieved'),
            'data' => [
                'audience' => $audience,
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
        $path = ($video->is_live ? '/live/' : '/video/').($video->public_id ?: $video->id);

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

    private function resolveThumbnailUrl(
        string $type,
        array $validated,
        ?Upload $upload,
        ?string $mediaUrl,
        ?string $existingThumbnailUrl = null,
        bool $refreshFallback = false,
    ): ?string {
        if (array_key_exists('thumbnailUrl', $validated)) {
            return $validated['thumbnailUrl'];
        }

        if ($type !== 'video') {
            return $existingThumbnailUrl;
        }

        if (! $refreshFallback && $existingThumbnailUrl) {
            return $existingThumbnailUrl;
        }

        return $this->deriveThumbnailUrlFromVideo($upload?->path ?? $mediaUrl) ?? $existingThumbnailUrl;
    }

    private function deriveThumbnailUrlFromVideo(?string $sourceUrl): ?string
    {
        if (! is_string($sourceUrl) || $sourceUrl === '') {
            return null;
        }

        if (strtolower((string) parse_url($sourceUrl, PHP_URL_HOST)) !== 'res.cloudinary.com') {
            return null;
        }

        try {
            $cloudinary = app(CloudinaryUploadService::class);

            if (! $cloudinary->isManagedUrl($sourceUrl)) {
                return null;
            }

            return $cloudinary->thumbnailUrlFor($sourceUrl);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function startLiveSession(Video $video): void
    {
        $wasLive = (bool) $video->is_live;
        $shouldNotify = ! $wasLive && $video->live_notified_at === null;

        if (! $wasLive) {
            $video->liveSignals()->delete();
        }

        $video->livePresenceSessions()->update([
            'left_at' => now(),
        ]);

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
        $video->livePresenceSessions()->update([
            'left_at' => now(),
        ]);

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

    private function buildLiveChannelName(Video $video): string
    {
        return sprintf('live-video-%s', $video->id);
    }

    private function canJoinLiveStageAsHost(Video $video, User $viewer): bool
    {
        if ($viewer->id === $video->user_id) {
            return true;
        }

        $latestStageSignal = LiveSignal::query()
            ->where('video_id', $video->id)
            ->whereIn('kind', [
                'join_request',
                'join_request_accepted',
                'join_request_rejected',
                'join_invite',
                'join_invite_accepted',
                'join_invite_rejected',
                'cohost_left',
            ])
            ->where(function ($query) use ($video, $viewer): void {
                $query
                    ->where(function ($nestedQuery) use ($video, $viewer): void {
                        $nestedQuery
                            ->where('sender_id', $viewer->id)
                            ->where('recipient_id', $video->user_id);
                    })
                    ->orWhere(function ($nestedQuery) use ($video, $viewer): void {
                        $nestedQuery
                            ->where('sender_id', $video->user_id)
                            ->where('recipient_id', $viewer->id);
                    });
            })
            ->latest('id')
            ->first();

        return in_array($latestStageSignal?->kind, ['join_request_accepted', 'join_invite_accepted'], true);
    }

    private function activePresenceQuery(Video $video)
    {
        return LivePresenceSession::query()
            ->where('video_id', $video->id)
            ->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subSeconds(self::LIVE_PRESENCE_TTL_SECONDS));
    }

    private function activePresenceCount(Video $video): int
    {
        return (int) $this->activeAudiencePresenceQuery($video)
            ->distinct()
            ->count('user_id');
    }

    private function activeAudiencePresenceQuery(Video $video)
    {
        return $this->activePresenceQuery($video)
            ->where('role', 'audience');
    }

    private function activeAudienceMembers(Video $video)
    {
        return $this->activeAudiencePresenceQuery($video)
            ->with('user')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('joined_at')
            ->get()
            ->unique('user_id')
            ->take(self::LIVE_ACTIVE_AUDIENCE_LIMIT)
            ->values();
    }

    private function liveLikeEventsQuery(Video $video)
    {
        return LiveLikeEvent::query()
            ->where('video_id', $video->id)
            ->when($video->live_started_at, fn ($query) => $query->where('created_at', '>=', $video->live_started_at))
            ->when($video->live_ended_at, fn ($query) => $query->where('created_at', '<=', $video->live_ended_at));
    }

    private function liveCommentsQuery(Video $video)
    {
        return Comment::query()
            ->where('video_id', $video->id)
            ->when($video->live_started_at, fn ($query) => $query->where('created_at', '>=', $video->live_started_at))
            ->when($video->live_ended_at, fn ($query) => $query->where('created_at', '<=', $video->live_ended_at));
    }

    private function liveFanTipsQuery(Video $video)
    {
        return FanTip::query()
            ->where('video_id', $video->id)
            ->where('status', 'posted')
            ->when($video->live_started_at, fn ($query) => $query->where('tipped_at', '>=', $video->live_started_at))
            ->when($video->live_ended_at, fn ($query) => $query->where('tipped_at', '<=', $video->live_ended_at));
    }

    private function buildLiveEngagementSummary(Video $video): array
    {
        [$startedAt, $endedAt] = $this->resolveLiveAnalyticsWindow($video);

        $likeCounts = $this->liveLikeEventsQuery($video)
            ->select('user_id', DB::raw('COUNT(*) as likes_count'), DB::raw('MAX(created_at) as last_liked_at'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $commentCounts = $this->liveCommentsQuery($video)
            ->select('user_id', DB::raw('COUNT(*) as comments_count'), DB::raw('MAX(created_at) as last_commented_at'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $tipCounts = $this->liveFanTipsQuery($video)
            ->select('fan_id', DB::raw('COUNT(*) as tips_count'), DB::raw('SUM(amount) as tips_amount'), DB::raw('MAX(tipped_at) as last_tipped_at'))
            ->whereNotNull('fan_id')
            ->groupBy('fan_id')
            ->get()
            ->keyBy('fan_id');

        $userIds = $likeCounts->keys()
            ->merge($commentCounts->keys())
            ->merge($tipCounts->keys())
            ->filter()
            ->unique()
            ->values();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $topFans = $userIds
            ->map(function ($userId) use ($users, $likeCounts, $commentCounts, $tipCounts): ?array {
                $user = $users->get($userId);

                if (! $user) {
                    return null;
                }

                $likesCount = (int) ($likeCounts->get($userId)?->likes_count ?? 0);
                $commentsCount = (int) ($commentCounts->get($userId)?->comments_count ?? 0);
                $tipsCount = (int) ($tipCounts->get($userId)?->tips_count ?? 0);
                $tipsAmount = (int) ($tipCounts->get($userId)?->tips_amount ?? 0);
                $lastEngagedAt = max(
                    (string) ($likeCounts->get($userId)?->last_liked_at ?? ''),
                    (string) ($commentCounts->get($userId)?->last_commented_at ?? ''),
                    (string) ($tipCounts->get($userId)?->last_tipped_at ?? '')
                );

                return [
                    'actor' => $this->formatLiveEngagementActor($user),
                    'likesCount' => $likesCount,
                    'commentsCount' => $commentsCount,
                    'tipsCount' => $tipsCount,
                    'tipsAmount' => $tipsAmount,
                    'engagementCount' => $likesCount + $commentsCount + $tipsCount,
                    'lastEngagedAt' => $lastEngagedAt !== '' ? Carbon::parse($lastEngagedAt)->toISOString() : null,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                return [
                    $right['engagementCount'],
                    $right['tipsAmount'],
                    $right['likesCount'],
                    $right['commentsCount'],
                    $right['lastEngagedAt'] ?? '',
                ] <=> [
                    $left['engagementCount'],
                    $left['tipsAmount'],
                    $left['likesCount'],
                    $left['commentsCount'],
                    $left['lastEngagedAt'] ?? '',
                ];
            })
            ->take(self::LIVE_ANALYTICS_LEADERBOARD_LIMIT)
            ->values();

        $topCommenters = $commentCounts
            ->map(function ($row, $userId) use ($users): ?array {
                $user = $users->get($userId);

                if (! $user) {
                    return null;
                }

                return [
                    'actor' => $this->formatLiveEngagementActor($user),
                    'commentsCount' => (int) ($row->comments_count ?? 0),
                    'lastCommentedAt' => $row->last_commented_at ? Carbon::parse((string) $row->last_commented_at)->toISOString() : null,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                return [
                    $right['commentsCount'],
                    $right['lastCommentedAt'] ?? '',
                ] <=> [
                    $left['commentsCount'],
                    $left['lastCommentedAt'] ?? '',
                ];
            })
            ->take(self::LIVE_ANALYTICS_LEADERBOARD_LIMIT)
            ->values();

        $topLikers = $likeCounts
            ->map(function ($row, $userId) use ($users): ?array {
                $user = $users->get($userId);

                if (! $user) {
                    return null;
                }

                return [
                    'actor' => $this->formatLiveEngagementActor($user),
                    'likesCount' => (int) ($row->likes_count ?? 0),
                    'lastLikedAt' => $row->last_liked_at ? Carbon::parse((string) $row->last_liked_at)->toISOString() : null,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                return [
                    $right['likesCount'],
                    $right['lastLikedAt'] ?? '',
                ] <=> [
                    $left['likesCount'],
                    $left['lastLikedAt'] ?? '',
                ];
            })
            ->take(self::LIVE_ANALYTICS_LEADERBOARD_LIMIT)
            ->values();

        $topGifters = $tipCounts
            ->map(function ($row, $userId) use ($users): ?array {
                $user = $users->get($userId);

                if (! $user) {
                    return null;
                }

                return [
                    'actor' => $this->formatLiveEngagementActor($user),
                    'tipsCount' => (int) ($row->tips_count ?? 0),
                    'tipsAmount' => (int) ($row->tips_amount ?? 0),
                    'lastTippedAt' => $row->last_tipped_at ? Carbon::parse((string) $row->last_tipped_at)->toISOString() : null,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                return [
                    $right['tipsAmount'],
                    $right['tipsCount'],
                    $right['lastTippedAt'] ?? '',
                ] <=> [
                    $left['tipsAmount'],
                    $left['tipsCount'],
                    $left['lastTippedAt'] ?? '',
                ];
            })
            ->take(self::LIVE_ANALYTICS_LEADERBOARD_LIMIT)
            ->values();

        [$timeline, $viewerTrend, $peakMoments, $retention] = $this->buildLiveAnalyticsTimeline($video, $startedAt, $endedAt);

        $totalLikes = (int) $likeCounts->sum('likes_count');
        $totalComments = (int) $commentCounts->sum('comments_count');
        $totalTips = (int) $tipCounts->sum('tips_count');
        $totalTipsAmount = (int) $tipCounts->sum('tips_amount');

        return [
            'topFans' => $topFans,
            'topCommenters' => $topCommenters,
            'topLikers' => $topLikers,
            'topGifters' => $topGifters,
            'timeline' => $timeline,
            'viewerTrend' => $viewerTrend,
            'peakMoments' => $peakMoments,
            'retention' => $retention,
            'totals' => [
                'likes' => $totalLikes,
                'comments' => $totalComments,
                'tips' => $totalTips,
                'tipsAmount' => $totalTipsAmount,
                'engagements' => $totalLikes + $totalComments + $totalTips,
                'uniqueFans' => $users->count(),
            ],
        ];
    }

    private function buildLiveAnalyticsTimeline(Video $video, Carbon $startedAt, Carbon $endedAt): array
    {
        $durationSeconds = max(1, $startedAt->diffInSeconds($endedAt));
        $bucketCount = max(
            self::LIVE_ANALYTICS_MIN_BUCKETS,
            min(self::LIVE_ANALYTICS_MAX_BUCKETS, (int) ceil($durationSeconds / 90))
        );
        $bucketSizeSeconds = max(1, (int) ceil($durationSeconds / $bucketCount));

        $likeEvents = $this->liveLikeEventsQuery($video)
            ->get(['created_at']);

        $commentEvents = $this->liveCommentsQuery($video)
            ->get(['created_at']);

        $tipEvents = $this->liveFanTipsQuery($video)
            ->get(['created_at', 'amount']);

        $presenceSessions = LivePresenceSession::query()
            ->where('video_id', $video->id)
            ->where('role', 'audience')
            ->get(['user_id', 'joined_at', 'last_seen_at', 'left_at', 'created_at']);

        $timeline = collect(range(0, $bucketCount - 1))
            ->map(function (int $index) use ($startedAt, $endedAt, $bucketCount, $bucketSizeSeconds, $likeEvents, $commentEvents, $tipEvents, $presenceSessions): array {
                $bucketStart = $startedAt->copy()->addSeconds($index * $bucketSizeSeconds);
                $bucketEnd = $index === $bucketCount - 1
                    ? $endedAt->copy()
                    : $startedAt->copy()->addSeconds(($index + 1) * $bucketSizeSeconds);

                if ($bucketEnd->greaterThan($endedAt)) {
                    $bucketEnd = $endedAt->copy();
                }

                if ($bucketEnd->lessThan($bucketStart)) {
                    $bucketEnd = $bucketStart->copy();
                }

                $midpoint = $bucketStart->copy()->addSeconds((int) floor($bucketStart->diffInSeconds($bucketEnd) / 2));
                $inclusiveEnd = $index === $bucketCount - 1;

                $likesCount = $likeEvents->filter(fn ($event) => $this->eventFallsWithinBucket($event->created_at, $bucketStart, $bucketEnd, $inclusiveEnd))->count();
                $commentsCount = $commentEvents->filter(fn ($event) => $this->eventFallsWithinBucket($event->created_at, $bucketStart, $bucketEnd, $inclusiveEnd))->count();
                $tipsInBucket = $tipEvents->filter(fn ($event) => $this->eventFallsWithinBucket($event->created_at, $bucketStart, $bucketEnd, $inclusiveEnd));
                $tipsCount = $tipsInBucket->count();
                $tipsAmount = (int) $tipsInBucket->sum('amount');
                $viewersCount = $presenceSessions
                    ->filter(fn (LivePresenceSession $session) => $this->presenceSessionIsActiveAt($session, $midpoint, $endedAt))
                    ->pluck('user_id')
                    ->filter()
                    ->unique()
                    ->count();

                return [
                    'label' => $this->formatLiveAnalyticsOffsetLabel($startedAt->diffInSeconds($bucketStart)),
                    'startedAt' => $bucketStart->toISOString(),
                    'endedAt' => $bucketEnd->toISOString(),
                    'midpointAt' => $midpoint->toISOString(),
                    'likesCount' => $likesCount,
                    'commentsCount' => $commentsCount,
                    'tipsCount' => $tipsCount,
                    'tipsAmount' => $tipsAmount,
                    'engagementCount' => $likesCount + $commentsCount + $tipsCount,
                    'viewersCount' => $viewersCount,
                ];
            })
            ->values();

        $viewerTrend = $timeline
            ->map(fn (array $bucket): array => [
                'label' => $bucket['label'],
                'timestamp' => $bucket['midpointAt'],
                'viewersCount' => $bucket['viewersCount'],
            ])
            ->values();

        $peakMoments = $timeline
            ->filter(fn (array $bucket): bool => $bucket['engagementCount'] > 0 || $bucket['viewersCount'] > 0)
            ->sort(function (array $left, array $right): int {
                return [
                    $right['engagementCount'],
                    $right['viewersCount'],
                    $right['startedAt'],
                ] <=> [
                    $left['engagementCount'],
                    $left['viewersCount'],
                    $left['startedAt'],
                ];
            })
            ->take(self::LIVE_ANALYTICS_PEAK_MOMENTS_LIMIT)
            ->values();

        $viewerCounts = $viewerTrend->pluck('viewersCount');
        $startViewers = (int) ($viewerCounts->first() ?? 0);
        $endViewers = (int) ($viewerCounts->last() ?? 0);
        $peakViewers = (int) ($viewerCounts->max() ?? 0);

        $retention = [
            'startViewers' => $startViewers,
            'endViewers' => $endViewers,
            'averageViewers' => (int) round((float) ($viewerCounts->avg() ?? 0)),
            'peakViewers' => $peakViewers,
            'retentionRate' => $peakViewers > 0 ? (int) round(($endViewers / $peakViewers) * 100) : 0,
        ];

        return [$timeline, $viewerTrend, $peakMoments, $retention];
    }

    private function resolveLiveAnalyticsWindow(Video $video): array
    {
        $startedAt = $video->live_started_at?->copy() ?? $video->created_at?->copy() ?? now();
        $endedAt = $video->live_ended_at?->copy() ?? now();

        if ($endedAt->lessThan($startedAt)) {
            $endedAt = $startedAt->copy();
        }

        return [$startedAt, $endedAt];
    }

    private function eventFallsWithinBucket($timestamp, Carbon $bucketStart, Carbon $bucketEnd, bool $inclusiveEnd = false): bool
    {
        if (! $timestamp instanceof Carbon) {
            return false;
        }

        if ($timestamp->lessThan($bucketStart)) {
            return false;
        }

        return $inclusiveEnd
            ? $timestamp->lessThanOrEqualTo($bucketEnd)
            : $timestamp->lessThan($bucketEnd);
    }

    private function presenceSessionIsActiveAt(LivePresenceSession $session, Carbon $point, Carbon $streamEndedAt): bool
    {
        $joinedAt = $session->joined_at?->copy() ?? $session->created_at?->copy();

        if (! $joinedAt || $joinedAt->greaterThan($point)) {
            return false;
        }

        $leftAt = $session->left_at?->copy()
            ?? $session->last_seen_at?->copy()?->addSeconds(self::LIVE_PRESENCE_TTL_SECONDS)
            ?? $streamEndedAt->copy();

        return $leftAt->greaterThanOrEqualTo($point);
    }

    private function formatLiveAnalyticsOffsetLabel(int $offsetSeconds): string
    {
        if ($offsetSeconds >= 3600) {
            return sprintf('%dh', (int) floor($offsetSeconds / 3600));
        }

        if ($offsetSeconds >= 60) {
            return sprintf('%dm', (int) floor($offsetSeconds / 60));
        }

        return sprintf('%ds', $offsetSeconds);
    }

    private function formatLiveEngagementActor(?User $user): array
    {
        return [
            'id' => $user?->id,
            'fullName' => $user?->name,
            'username' => $user?->username,
            'avatarUrl' => $user?->avatar_url,
        ];
    }

    private function formatLiveTipEngagement(FanTip $tip, bool $maskPrivateActor = false): array
    {
        return [
            'id' => 'tip-'.$tip->id,
            'type' => 'tip',
            'body' => $tip->message,
            'createdAt' => $tip->tipped_at?->toISOString() ?? $tip->created_at?->toISOString(),
            'actor' => $maskPrivateActor && $tip->is_private
                ? ['id' => null, 'fullName' => 'Anonymous fan', 'username' => null, 'avatarUrl' => null]
                : $this->formatLiveEngagementActor($tip->fan),
            'metadata' => [
                'amount' => (int) $tip->amount,
                'currency' => $tip->currency,
                'giftName' => data_get($tip->metadata, 'giftName'),
                'giftType' => data_get($tip->metadata, 'giftType'),
                'giftCount' => (int) data_get($tip->metadata, 'giftCount', 1),
                'isPrivate' => (bool) $tip->is_private,
            ],
        ];
    }

    private function formatLiveAudienceMember(LivePresenceSession $presence): array
    {
        return [
            'sessionId' => $presence->id,
            'role' => $presence->role,
            'actor' => $this->formatLiveEngagementActor($presence->user),
            'joinedAt' => $presence->joined_at?->toISOString(),
            'lastSeenAt' => $presence->last_seen_at?->toISOString(),
        ];
    }

    private function buildLiveAudiencePayload(Video $video): array
    {
        return $this->activeAudienceMembers($video)
            ->map(fn (LivePresenceSession $presence): array => $this->formatLiveAudienceMember($presence))
            ->values()
            ->all();
    }

    private function buildLivePresenceAnalytics(Video $video, ?int $currentViewers = null): array
    {
        $resolvedCurrentViewers = $currentViewers ?? $this->activePresenceCount($video);

        return [
            'currentViewers' => $resolvedCurrentViewers,
            'peakViewers' => max($resolvedCurrentViewers, (int) $video->fresh()->live_peak_viewers_count),
        ];
    }

    private function formatLiveSignal(LiveSignal $signal): array
    {
        return [
            'id' => $signal->id,
            'type' => $signal->kind,
            'payload' => $signal->payload ?? [],
            'senderId' => $signal->sender_id,
            'recipientId' => $signal->recipient_id,
            'sender' => $this->formatLiveEngagementActor($signal->sender),
            'recipient' => $this->formatLiveEngagementActor($signal->recipient),
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

    private function resolveTaggedUserIds(?string $caption, ?string $description, array $taggedUsers = []): array
    {
        $mentionedHandles = Username::extractMentionedHandles($caption, $description);
        $mentionedUserIds = $mentionedHandles === []
            ? []
            : User::query()
                ->whereIn('username', $mentionedHandles)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();

        $resolvedUserIds = array_values(array_unique(array_map('intval', array_filter([
            ...$taggedUsers,
            ...$mentionedUserIds,
        ], static fn ($value) => $value !== null && $value !== 0))));

        sort($resolvedUserIds);

        return $resolvedUserIds;
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