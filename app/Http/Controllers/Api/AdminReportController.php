<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminReportRequest;
use App\Http\Resources\ContentModerationCaseResource;
use App\Http\Resources\VideoReportResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin content-report controller.
 *
 * Operator triage of user-filed content reports: search / filter listing,
 * per-report detail with the reported content and target creator, and a single
 * review action that can change the report status, act on the reported content
 * (restrict / remove / restore), notify the reporter, and record admin notes.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/Reports.jsx, Reports/ReportDetailsSidebar.jsx.
 * Related: VideoReport model, VideoReportResource, AdminVideoController.
 * See PROJECT_OVERVIEW.md §3.27 for the full data-flow map.
 */
class AdminReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $reason = trim($request->string('reason')->toString());
        $severity = mb_strtolower(trim($request->string('severity')->toString()));
        $type = mb_strtolower(trim($request->string('type')->toString()));
        $from = $request->date('from');
        $to = $request->date('to');

        $highReasons = VideoReport::SEVERITY_REASONS['high'];
        $mediumReasons = VideoReport::SEVERITY_REASONS['medium'];

        $reports = PaginatedJson::paginate(
            $this->reportQuery()
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('details', 'like', '%'.$query.'%')
                            ->orWhere('reason', 'like', '%'.$query.'%')
                            ->orWhereHas('user', function (Builder $userQuery) use ($query): void {
                                $userQuery->where('name', 'like', '%'.$query.'%')
                                    ->orWhere('username', 'like', '%'.$query.'%')
                                    ->orWhere('email', 'like', '%'.$query.'%');
                            })
                            ->orWhereHas('video', function (Builder $videoQuery) use ($query): void {
                                $videoQuery->where('title', 'like', '%'.$query.'%')
                                    ->orWhere('caption', 'like', '%'.$query.'%')
                                    ->orWhere('public_id', 'like', '%'.$query.'%');
                            });

                        if (ctype_digit($query)) {
                            $inner->orWhere('id', (int) $query);
                        }
                    });
                })
                ->when(in_array($status, ['pending', 'reviewed', 'dismissed', 'escalated'], true), fn (Builder $b) => $b->where('status', $status))
                ->when($reason !== '', fn (Builder $b) => $b->whereRaw('LOWER(reason) = ?', [mb_strtolower($reason)]))
                ->when($severity === 'high', fn (Builder $b) => $b->whereIn('reason', $highReasons))
                ->when($severity === 'medium', fn (Builder $b) => $b->whereIn('reason', $mediumReasons))
                ->when($severity === 'low', fn (Builder $b) => $b->where(fn (Builder $inner) => $inner->whereNull('reason')->orWhereNotIn('reason', array_merge($highReasons, $mediumReasons))))
                ->when($type === 'live', fn (Builder $b) => $b->whereHas('video', fn (Builder $v) => $v->where('is_live', true)))
                ->when($type === 'video', fn (Builder $b) => $b->whereHas('video', fn (Builder $v) => $v->where('is_live', false)))
                ->when($from, fn (Builder $b) => $b->where('created_at', '>=', $from->copy()->startOfDay()))
                ->when($to, fn (Builder $b) => $b->where('created_at', '<=', $to->copy()->endOfDay()))
                ->latest(),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.reports_retrieved'),
            'data' => [
                'reports' => PaginatedJson::items($request, $reports, VideoReportResource::class),
            ],
            'meta' => [
                'reports' => PaginatedJson::meta($reports),
                'summary' => [
                    'totalReports' => VideoReport::query()->count(),
                    'pendingReports' => VideoReport::query()->where('status', 'pending')->count(),
                    'reviewedReports' => VideoReport::query()->where('status', 'reviewed')->count(),
                    'dismissedReports' => VideoReport::query()->where('status', 'dismissed')->count(),
                    'escalatedReports' => VideoReport::query()->where('status', 'escalated')->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, VideoReport $videoReport): JsonResponse
    {
        SupportedLocales::apply($request);

        $videoReport->load([
            'user',
            'reviewer',
            'video.user' => fn ($query) => $query->withProfileAggregates($request->user()),
            'video.moderationCase' => fn ($query) => $query->with('reviewer'),
        ]);

        $video = $videoReport->video;
        $moderationCase = $video?->moderationCase;

        return response()->json([
            'message' => __('messages.admin.report_retrieved'),
            'data' => [
                'report' => new VideoReportResource($videoReport),
                'targetUser' => $video?->user ? [
                    'id' => $video->user->id,
                    'fullName' => $video->user->name,
                    'username' => $video->user->username,
                    'avatarUrl' => $video->user->avatar_url,
                    'accountStatus' => $video->user->account_status,
                    'isVerifiedCreator' => (bool) ($video->user->creator_verification_status === 'approved'),
                ] : null,
                'moderationCase' => $moderationCase
                    ? new ContentModerationCaseResource($moderationCase)
                    : null,
                'relatedReports' => [
                    'total' => $video ? VideoReport::query()->where('video_id', $video->id)->count() : 0,
                    'pending' => $video ? VideoReport::query()->where('video_id', $video->id)->where('status', 'pending')->count() : 0,
                ],
            ],
        ]);
    }

    public function update(UpdateAdminReportRequest $request, VideoReport $videoReport): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $admin = $request->user();

        if ($request->exists('adminNotes')) {
            $videoReport->resolution_notes = $validated['adminNotes'] ?? null;
        }

        if (array_key_exists('status', $validated)) {
            $previousStatus = $videoReport->status;
            $nextStatus = $validated['status'];

            $videoReport->status = $nextStatus;
            $videoReport->reviewed_by = $nextStatus === 'pending' ? null : $admin->id;
            $videoReport->reviewed_at = $nextStatus === 'pending' ? null : now();

            if ($nextStatus !== $previousStatus) {
                $this->recordAudit($request, $admin, $videoReport->getMorphClass(), $videoReport->id, $this->reportAction($nextStatus), [
                    'from' => $previousStatus,
                    'to' => $nextStatus,
                    'notes' => $videoReport->resolution_notes,
                ]);
            }
        }

        $videoReport->save();

        if (isset($validated['contentAction'])) {
            $this->applyContentAction($request, $admin, $videoReport, $validated['contentAction']);
        }

        if ($request->boolean('notifyReporter')) {
            $this->notifyReporter($request, $admin, $videoReport);
        }

        $videoReport->load([
            'user',
            'reviewer',
            'video.user' => fn ($query) => $query->withProfileAggregates($admin),
            'video.moderationCase' => fn ($query) => $query->with('reviewer'),
        ]);

        return response()->json([
            'message' => __('messages.admin.report_updated'),
            'data' => [
                'report' => new VideoReportResource($videoReport),
                'moderationCase' => $videoReport->video?->moderationCase
                    ? new ContentModerationCaseResource($videoReport->video->moderationCase)
                    : null,
            ],
        ]);
    }

    private function applyContentAction(Request $request, User $admin, VideoReport $videoReport, string $action): void
    {
        $video = $videoReport->video;

        if (! $video instanceof Video) {
            return;
        }

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
        ])->save();

        if ($nextStatus !== $previousStatus) {
            $this->recordAudit($request, $admin, $video->getMorphClass(), $video->id, $this->contentAction($action), [
                'from' => $previousStatus,
                'to' => $nextStatus,
                'reportId' => $videoReport->id,
            ]);
        }
    }

    private function notifyReporter(Request $request, User $admin, VideoReport $videoReport): void
    {
        if (! $videoReport->user_id) {
            return;
        }

        UserNotifier::sendTranslated(
            $videoReport->user_id,
            $admin->id,
            'content_report_update',
            'messages.notifications.report_update_title',
            'messages.notifications.report_update_body',
            ['status' => __('messages.report_status.'.$videoReport->status)],
            ['reportId' => $videoReport->id, 'status' => $videoReport->status, 'videoId' => $videoReport->video_id],
        );

        $this->recordAudit($request, $admin, $videoReport->getMorphClass(), $videoReport->id, 'admin.report_reporter_notified', [
            'reporterId' => $videoReport->user_id,
            'status' => $videoReport->status,
        ]);
    }

    private function reportAction(string $status): string
    {
        return match ($status) {
            'reviewed' => 'admin.report_reviewed',
            'dismissed' => 'admin.report_dismissed',
            'escalated' => 'admin.report_escalated',
            default => 'admin.report_reopened',
        };
    }

    private function contentAction(string $action): string
    {
        return match ($action) {
            'restrict' => 'admin.video_restricted',
            'remove' => 'admin.video_removed',
            default => 'admin.video_restored',
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordAudit(Request $request, User $admin, string $auditableType, int $auditableId, string $action, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }

    private function reportQuery(): Builder
    {
        return VideoReport::query()->with([
            'user',
            'reviewer',
            'video.user',
        ]);
    }
}
