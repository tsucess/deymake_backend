<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentModerationCaseResource;
use App\Http\Resources\VideoResource;
use App\Models\LivePresenceSession;
use App\Models\Video;
use App\Models\VideoReport;
use App\Support\AuditLogger;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Admin live stream controller.
 *
 * Admin actions on live streams: list active/recent lives, force-stop a
 * runaway session when the creator is gone or unreachable.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/LiveStream.jsx.
 * Related: VideoController (creator-side live lifecycle), EnsureAdmin middleware.
 * See PROJECT_OVERVIEW.md §3.27 for the full data-flow map.
 */
class AdminLiveStreamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $streams = PaginatedJson::paginate(
            $this->liveQuery($request->user())
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $streamId = ltrim(str_ireplace('live-', '', $query), '-');

                    $builder->where(function (Builder $searchQuery) use ($query, $streamId): void {
                        $searchQuery
                            ->where('title', 'like', '%'.$query.'%')
                            ->orWhere('public_id', 'like', '%'.$query.'%')
                            ->orWhere('public_id', 'like', '%'.$streamId.'%')
                            ->orWhereHas('user', function (Builder $userQuery) use ($query): void {
                                $userQuery
                                    ->where('name', 'like', '%'.$query.'%')
                                    ->orWhere('username', 'like', '%'.$query.'%')
                                    ->orWhere('email', 'like', '%'.$query.'%');
                            });
                    });
                })
                ->when($status === 'live', fn (Builder $builder) => $builder->where('is_live', true))
                ->when($status === 'ended', fn (Builder $builder) => $builder->where('is_live', false)->whereNotNull('live_ended_at'))
                ->when($status === 'flagged', fn (Builder $builder) => $builder->whereIn('moderation_status', ['restricted', 'removed']))
                ->when($sort === 'oldest', fn (Builder $builder) => $builder->oldest('live_started_at'))
                ->when($sort !== 'oldest', fn (Builder $builder) => $builder->latest('live_started_at')),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.live_streams_retrieved'),
            'data' => [
                'liveStreams' => PaginatedJson::items($request, $streams, VideoResource::class),
            ],
            'meta' => [
                'liveStreams' => PaginatedJson::meta($streams),
                'summary' => [
                    'liveNow' => Video::query()->where('is_live', true)->count(),
                    'endedTotal' => Video::query()->where('is_live', false)->whereNotNull('live_ended_at')->count(),
                    'flaggedTotal' => Video::query()
                        ->whereNotNull('live_started_at')
                        ->whereIn('moderation_status', ['restricted', 'removed'])
                        ->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $video = $this->liveQuery($request->user())->whereKey($video->id)->firstOrFail();

        return response()->json([
            'message' => __('messages.admin.live_stream_retrieved'),
            'data' => [
                'stream' => new VideoResource($video),
                'audience' => $this->audiencePayload($video),
                'coHosts' => $this->coHostPayload($video),
                'violations' => $this->violationsPayload($video),
            ],
        ]);
    }

    public function stop(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        abort_if(! $video->is_live, 409, __('messages.videos.live_not_active'));

        $admin = $request->user();
        $disconnected = (int) $video->livePresenceSessions()->whereNull('left_at')->count();
        $peakViewers = (int) ($video->live_peak_viewers_count ?? 0);
        $durationSeconds = $video->live_started_at
            ? max(0, $video->live_started_at->diffInSeconds(now()))
            : 0;

        // Disconnect viewers safely and clear signalling; analytics counters
        // (peak viewers, live comments/likes/tips) are intentionally preserved.
        $video->liveSignals()->delete();
        $video->livePresenceSessions()->whereNull('left_at')->update([
            'left_at' => now(),
            'last_seen_at' => now(),
        ]);

        $video->forceFill([
            'is_live' => false,
            'is_draft' => true,
            'live_ended_at' => now(),
            'live_notified_at' => null,
        ])->save();

        UserNotifier::sendTranslated(
            (int) $video->user_id,
            (int) $admin->id,
            'moderation',
            'messages.notifications.live_stopped_title',
            'messages.notifications.live_stopped_body',
            ['title' => $video->title ?: __('messages.admin.uncategorized')],
            ['videoId' => $video->id, 'reason' => $validated['reason'] ?? null],
        );

        AuditLogger::record('admin.live_stream_stopped', $video, (int) $admin->id, [
            'viewersDisconnected' => $disconnected,
            'peakViewers' => $peakViewers,
            'durationSeconds' => $durationSeconds,
            'reason' => $validated['reason'] ?? null,
        ], $request->ip());

        $video = $this->liveQuery($admin)->whereKey($video->id)->firstOrFail();

        return response()->json([
            'message' => __('messages.admin.live_stream_stopped'),
            'data' => [
                'video' => new VideoResource($video),
                'viewersDisconnected' => $disconnected,
            ],
        ]);
    }

    public function removeViewer(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'sessionId' => ['required', 'integer'],
        ]);

        $session = $video->livePresenceSessions()
            ->where('id', $validated['sessionId'])
            ->where('role', 'audience')
            ->firstOrFail();

        $session->forceFill(['left_at' => now(), 'last_seen_at' => now()])->save();

        AuditLogger::record('admin.live_viewer_removed', $video, (int) $request->user()->id, [
            'sessionId' => $session->id,
            'viewerId' => $session->user_id,
        ], $request->ip());

        $video = $this->liveQuery($request->user())->whereKey($video->id)->firstOrFail();

        return response()->json([
            'message' => __('messages.admin.live_viewer_removed'),
            'data' => [
                'audience' => $this->audiencePayload($video),
            ],
        ]);
    }

    public function removeCoHost(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'sessionId' => ['required', 'integer'],
        ]);

        $session = $video->livePresenceSessions()
            ->where('id', $validated['sessionId'])
            ->where('role', 'host')
            ->firstOrFail();

        abort_if((int) $session->user_id === (int) $video->user_id, 422, __('messages.admin.live_cohost_is_creator'));

        $session->forceFill(['left_at' => now(), 'last_seen_at' => now()])->save();

        // Revoke their stage access so they cannot be treated as an approved host.
        $video->liveSignals()
            ->where(function (Builder $builder) use ($session): void {
                $builder->where('sender_id', $session->user_id)
                    ->orWhere('recipient_id', $session->user_id);
            })
            ->delete();

        AuditLogger::record('admin.live_cohost_removed', $video, (int) $request->user()->id, [
            'sessionId' => $session->id,
            'coHostId' => $session->user_id,
        ], $request->ip());

        $video = $this->liveQuery($request->user())->whereKey($video->id)->firstOrFail();

        return response()->json([
            'message' => __('messages.admin.live_cohost_removed'),
            'data' => [
                'coHosts' => $this->coHostPayload($video),
            ],
        ]);
    }

    public function restrictCreator(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = $request->user();
        $creator = $video->user()->firstOrFail();

        abort_if((int) $creator->id === (int) $admin->id, 422, __('messages.admin.live_cannot_restrict_self'));
        abort_if($creator->isAdmin(), 422, __('messages.admin.live_cannot_restrict_admin'));

        $previousStatus = $creator->account_status;

        $creator->forceFill([
            'account_status' => 'suspended',
            'account_status_notes' => $validated['notes'] ?? $creator->account_status_notes,
            'suspended_at' => now(),
            'suspended_by' => $admin->id,
            'is_online' => false,
        ])->save();

        $creator->tokens()->delete();

        // End any live session the restricted creator still has running.
        if ($video->is_live) {
            $video->liveSignals()->delete();
            $video->livePresenceSessions()->whereNull('left_at')->update(['left_at' => now(), 'last_seen_at' => now()]);
            $video->forceFill(['is_live' => false, 'is_draft' => true, 'live_ended_at' => now(), 'live_notified_at' => null])->save();
        }

        UserNotifier::send(
            (int) $creator->id,
            (int) $admin->id,
            'moderation',
            __('messages.notifications.account_restricted_title'),
            __('messages.notifications.account_restricted_body'),
            ['videoId' => $video->id],
        );

        AuditLogger::record('admin.live_creator_restricted', $creator, (int) $admin->id, [
            'from' => $previousStatus,
            'to' => 'suspended',
            'videoId' => $video->id,
            'notes' => $validated['notes'] ?? null,
        ], $request->ip());

        return response()->json([
            'message' => __('messages.admin.live_creator_restricted'),
            'data' => [
                'creator' => [
                    'id' => $creator->id,
                    'fullName' => $creator->name,
                    'username' => $creator->username,
                    'accountStatus' => $creator->account_status,
                ],
            ],
        ]);
    }

    public function reviewViolations(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'action' => ['nullable', 'in:none,restrict,remove,restore'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'resolveReports' => ['nullable', 'boolean'],
        ]);

        $admin = $request->user();
        $action = $validated['action'] ?? 'none';
        $resolveReports = (bool) ($validated['resolveReports'] ?? false);

        if ($resolveReports) {
            $resolved = $video->reports()->where('status', 'pending')->get();

            foreach ($resolved as $report) {
                $report->forceFill([
                    'status' => 'reviewed',
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                    'resolution_notes' => $validated['notes'] ?? $report->resolution_notes,
                ])->save();
            }

            if ($resolved->isNotEmpty()) {
                AuditLogger::record('admin.live_violations_reviewed', $video, (int) $admin->id, [
                    'resolvedReports' => $resolved->pluck('id')->all(),
                    'notes' => $validated['notes'] ?? null,
                ], $request->ip());
            }
        }

        if ($action !== 'none') {
            $previousStatus = $video->moderation_status;
            $nextStatus = match ($action) {
                'restrict' => 'restricted',
                'remove' => 'removed',
                default => 'visible',
            };

            $video->forceFill([
                'moderation_status' => $nextStatus,
                'moderated_by' => $admin->id,
                'moderated_at' => now(),
                'moderation_notes' => $validated['notes'] ?? $video->moderation_notes,
            ])->save();

            if ($nextStatus !== $previousStatus) {
                AuditLogger::record($this->moderationAction($action), $video, (int) $admin->id, [
                    'from' => $previousStatus,
                    'to' => $nextStatus,
                    'notes' => $validated['notes'] ?? null,
                ], $request->ip());

                UserNotifier::sendTranslated(
                    (int) $video->user_id,
                    (int) $admin->id,
                    'moderation',
                    'messages.notifications.live_moderated_title',
                    'messages.notifications.live_moderated_body',
                    ['title' => $video->title ?: __('messages.admin.uncategorized')],
                    ['videoId' => $video->id, 'status' => $nextStatus],
                );
            }
        }

        $video = $this->liveQuery($admin)->whereKey($video->id)->firstOrFail();

        return response()->json([
            'message' => __('messages.admin.live_violations_reviewed'),
            'data' => [
                'stream' => new VideoResource($video),
                'violations' => $this->violationsPayload($video),
            ],
        ]);
    }

    private function liveQuery($viewer): Builder
    {
        return Video::query()
            ->where(function (Builder $builder): void {
                $builder->where('is_live', true)->orWhereNotNull('live_started_at');
            })
            ->withApiResourceData($viewer)
            ->withCount('reports as report_count')
            ->with(['moderationCase' => fn ($query) => $query->with('reviewer')]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function audiencePayload(Video $video): array
    {
        return $this->activeSessions($video, 'audience')
            ->map(fn (LivePresenceSession $session) => $this->formatMember($session))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function coHostPayload(Video $video): array
    {
        return $this->activeSessions($video, 'host')
            ->reject(fn (LivePresenceSession $session) => (int) $session->user_id === (int) $video->user_id)
            ->map(fn (LivePresenceSession $session) => $this->formatMember($session))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, LivePresenceSession>
     */
    private function activeSessions(Video $video, string $role)
    {
        return $video->livePresenceSessions()
            ->where('role', $role)
            ->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subSeconds(30))
            ->with('user')
            ->orderByDesc('last_seen_at')
            ->get()
            ->unique('user_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMember(LivePresenceSession $session): array
    {
        $user = $session->user;

        return [
            'sessionId' => $session->id,
            'role' => $session->role,
            'joinedAt' => $session->joined_at?->toISOString(),
            'lastSeenAt' => $session->last_seen_at?->toISOString(),
            'user' => $user ? [
                'id' => $user->id,
                'fullName' => $user->name,
                'username' => $user->username,
                'avatarUrl' => $user->avatar_url,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function violationsPayload(Video $video): array
    {
        $reports = $video->reports()
            ->with('user')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (VideoReport $report) => [
                'id' => $report->id,
                'reason' => $report->reason,
                'severity' => $report->severity,
                'status' => $report->status,
                'details' => $report->details,
                'createdAt' => $report->created_at?->toISOString(),
                'reporter' => $report->user ? [
                    'id' => $report->user->id,
                    'fullName' => $report->user->name,
                    'username' => $report->user->username,
                ] : null,
            ])
            ->values()
            ->all();

        return [
            'moderationStatus' => $video->moderation_status,
            'moderationCase' => $video->moderationCase
                ? new ContentModerationCaseResource($video->moderationCase)
                : null,
            'reports' => $reports,
            'counts' => [
                'total' => $video->reports()->count(),
                'pending' => $video->reports()->where('status', 'pending')->count(),
            ],
        ];
    }

    private function moderationAction(string $action): string
    {
        return match ($action) {
            'restrict' => 'admin.video_restricted',
            'remove' => 'admin.video_removed',
            default => 'admin.video_restored',
        };
    }
}
