<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Challenge\StoreChallengeRequest;
use App\Http\Requests\Challenge\UpdateChallengeRequest;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\ChallengeSubmissionResource;
use App\Models\Challenge;
use App\Models\ChallengeSubmission;
use App\Support\AuditLogger;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin challenge controller.
 *
 * Operator listing / feature / close / delete controls for challenges
 * across the platform, complementing the public ChallengeController.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/Challenges.jsx.
 * Related: Challenge model, ChallengeResource, ChallengeController.
 * See PROJECT_OVERVIEW.md §3.27 for the full data-flow map.
 */
class AdminChallengeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $category = trim($request->string('category')->toString());
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $challenges = PaginatedJson::paginate(
            $this->challengeQuery($request)
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('title', 'like', '%'.$query.'%')
                            ->orWhere('summary', 'like', '%'.$query.'%')
                            ->orWhere('slug', 'like', '%'.$query.'%')
                            ->orWhereHas('host', function (Builder $hostQuery) use ($query): void {
                                $hostQuery->where('name', 'like', '%'.$query.'%')
                                    ->orWhere('username', 'like', '%'.$query.'%');
                            });
                    });
                })
                ->when(in_array($status, ['draft', 'published', 'closed'], true), fn (Builder $b) => $b->where('status', $status))
                ->when($category !== '', function (Builder $builder) use ($category): void {
                    $builder->where(function (Builder $inner) use ($category): void {
                        $inner->where('category', $category)
                            ->orWhere('requirements', 'like', '%Category: '.$category.'%');
                    });
                })
                ->when($sort === 'oldest', fn (Builder $b) => $b->oldest())
                ->when($sort === 'most_submissions', fn (Builder $b) => $b->orderByDesc('submissions_count')->latest())
                ->when(! in_array($sort, ['oldest', 'most_submissions'], true), fn (Builder $b) => $b->latest()),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.challenges_retrieved'),
            'data' => [
                'challenges' => PaginatedJson::items($request, $challenges, ChallengeResource::class),
            ],
            'meta' => [
                'challenges' => PaginatedJson::meta($challenges),
                'summary' => [
                    'totalChallenges' => Challenge::query()->count(),
                    'publishedChallenges' => Challenge::query()->where('status', 'published')->count(),
                    'draftChallenges' => Challenge::query()->where('status', 'draft')->count(),
                    'closedChallenges' => Challenge::query()->where('status', 'closed')->count(),
                    'featuredChallenges' => Challenge::query()->where('is_featured', true)->count(),
                    'totalSubmissions' => ChallengeSubmission::query()->where('status', '!=', 'withdrawn')->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $challenge = Challenge::query()
            ->withApiResourceData($request->user())
            ->findOrFail($challenge->id);

        return response()->json([
            'message' => __('messages.admin.challenge_retrieved'),
            'data' => [
                'challenge' => new ChallengeResource($challenge),
                'submissionStats' => $this->submissionStats($challenge->id),
            ],
        ]);
    }

    public function store(StoreChallengeRequest $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $admin = $request->user();

        $challenge = Challenge::query()->create([
            'host_id' => $admin->id,
            'title' => $validated['title'],
            'slug' => $validated['slug'] ?? null,
            'summary' => $validated['summary'] ?? null,
            'category' => $validated['category'] ?? null,
            'description' => $validated['description'] ?? null,
            'banner_url' => $validated['bannerUrl'] ?? null,
            'thumbnail_url' => $validated['thumbnailUrl'] ?? null,
            'rules' => $validated['rules'] ?? null,
            'prizes' => $validated['prizes'] ?? null,
            'requirements' => $validated['requirements'] ?? null,
            'judging_criteria' => $validated['judgingCriteria'] ?? null,
            'submission_starts_at' => $validated['submissionStartsAt'],
            'submission_ends_at' => $validated['submissionEndsAt'] ?? null,
            'max_submissions_per_user' => $validated['maxSubmissionsPerUser'] ?? 1,
            'is_featured' => $validated['isFeatured'] ?? false,
            'status' => 'draft',
        ]);

        AuditLogger::record('admin.challenge_created', $challenge, (int) $admin->id, [
            'title' => $challenge->title,
            'category' => $challenge->category,
        ], $request->ip());

        $challenge = Challenge::query()->withApiResourceData($admin)->findOrFail($challenge->id);

        return response()->json([
            'message' => __('messages.admin.challenge_created'),
            'data' => [
                'challenge' => new ChallengeResource($challenge),
            ],
        ], 201);
    }

    public function update(UpdateChallengeRequest $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $admin = $request->user();
        $previousStatus = $challenge->status;

        $updates = [
            'title' => $validated['title'] ?? $challenge->title,
            'summary' => array_key_exists('summary', $validated) ? $validated['summary'] : $challenge->summary,
            'category' => array_key_exists('category', $validated) ? $validated['category'] : $challenge->category,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $challenge->description,
            'banner_url' => array_key_exists('bannerUrl', $validated) ? $validated['bannerUrl'] : $challenge->banner_url,
            'thumbnail_url' => array_key_exists('thumbnailUrl', $validated) ? $validated['thumbnailUrl'] : $challenge->thumbnail_url,
            'rules' => array_key_exists('rules', $validated) ? $validated['rules'] : $challenge->rules,
            'prizes' => array_key_exists('prizes', $validated) ? $validated['prizes'] : $challenge->prizes,
            'requirements' => array_key_exists('requirements', $validated) ? $validated['requirements'] : $challenge->requirements,
            'judging_criteria' => array_key_exists('judgingCriteria', $validated) ? $validated['judgingCriteria'] : $challenge->judging_criteria,
            'submission_starts_at' => $validated['submissionStartsAt'] ?? $challenge->submission_starts_at,
            'submission_ends_at' => array_key_exists('submissionEndsAt', $validated) ? $validated['submissionEndsAt'] : $challenge->submission_ends_at,
            'max_submissions_per_user' => $validated['maxSubmissionsPerUser'] ?? $challenge->max_submissions_per_user,
            'is_featured' => array_key_exists('isFeatured', $validated) ? (bool) $validated['isFeatured'] : $challenge->is_featured,
        ];

        if (array_key_exists('slug', $validated)) {
            $updates['slug'] = $validated['slug'] ?: Challenge::generateUniqueSlug($validated['title'] ?? $challenge->title, $challenge->id);
        }

        $nextStatus = $validated['status'] ?? $challenge->status;
        $updates['status'] = $nextStatus;
        if ($nextStatus === 'published' && ! $challenge->published_at) {
            $updates['published_at'] = now();
        }
        if ($nextStatus === 'closed') {
            $updates['closed_at'] = $challenge->closed_at ?? now();
        } elseif ($previousStatus === 'closed' && $nextStatus !== 'closed') {
            $updates['closed_at'] = null;
        }

        $challenge->forceFill($updates)->save();

        $this->recordChallengeUpdate($request, $challenge, $previousStatus, $nextStatus);

        $challenge = Challenge::query()->withApiResourceData($admin)->findOrFail($challenge->id);

        return response()->json([
            'message' => __('messages.admin.challenge_updated'),
            'data' => [
                'challenge' => new ChallengeResource($challenge),
            ],
        ]);
    }

    public function destroy(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $admin = $request->user();
        $hostId = (int) $challenge->host_id;
        $title = $challenge->title;

        AuditLogger::record('admin.challenge_deleted', $challenge, (int) $admin->id, [
            'title' => $title,
        ], $request->ip());

        $challenge->delete();

        UserNotifier::sendTranslated(
            $hostId,
            (int) $admin->id,
            'challenge',
            'messages.notifications.challenge_removed_title',
            'messages.notifications.challenge_removed_body',
            ['title' => $title ?: __('messages.admin.uncategorized')],
            [],
        );

        return response()->json([
            'message' => __('messages.admin.challenge_deleted'),
        ]);
    }

    public function submissions(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());

        $submissions = PaginatedJson::paginate(
            ChallengeSubmission::query()
                ->withApiResourceData($request->user())
                ->where('challenge_id', $challenge->id)
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('title', 'like', '%'.$query.'%')
                            ->orWhereHas('user', function (Builder $userQuery) use ($query): void {
                                $userQuery->where('name', 'like', '%'.$query.'%')
                                    ->orWhere('username', 'like', '%'.$query.'%');
                            });
                    });
                })
                ->when(in_array($status, ['submitted', 'approved', 'rejected', 'changes_requested', 'withdrawn'], true), fn (Builder $b) => $b->where('status', $status))
                ->when($status === 'winners', fn (Builder $b) => $b->where('is_winner', true))
                ->orderByDesc('is_winner')
                ->orderBy('winner_rank')
                ->latest('submitted_at'),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.challenge_submissions_retrieved'),
            'data' => [
                'submissions' => PaginatedJson::items($request, $submissions, ChallengeSubmissionResource::class),
            ],
            'meta' => [
                'submissions' => PaginatedJson::meta($submissions),
                'summary' => $this->submissionStats($challenge->id),
            ],
        ]);
    }

    public function reviewSubmission(Request $request, ChallengeSubmission $challengeSubmission): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'request_changes', 'withdraw', 'reset'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin = $request->user();
        $previousStatus = $challengeSubmission->status;

        $status = match ($validated['action']) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'request_changes' => 'changes_requested',
            'withdraw' => 'withdrawn',
            default => 'submitted',
        };

        $updates = [
            'status' => $status,
            'review_notes' => $validated['notes'] ?? $challengeSubmission->review_notes,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ];
        if ($status === 'withdrawn') {
            $updates['withdrawn_at'] = $challengeSubmission->withdrawn_at ?? now();
        }
        if ($validated['action'] === 'reset') {
            $updates['is_winner'] = false;
            $updates['winner_rank'] = null;
            $updates['withdrawn_at'] = null;
        }

        $challengeSubmission->forceFill($updates)->save();

        AuditLogger::record('admin.challenge_submission_reviewed', $challengeSubmission, (int) $admin->id, [
            'from' => $previousStatus,
            'to' => $status,
            'notes' => $validated['notes'] ?? null,
        ], $request->ip());

        if ($status === 'approved') {
            UserNotifier::sendTranslated(
                (int) $challengeSubmission->user_id,
                (int) $admin->id,
                'challenge',
                'messages.notifications.challenge_submission_approved_title',
                'messages.notifications.challenge_submission_approved_body',
                [],
                ['submissionId' => $challengeSubmission->id, 'challengeId' => $challengeSubmission->challenge_id],
            );
        } elseif ($status === 'rejected') {
            UserNotifier::sendTranslated(
                (int) $challengeSubmission->user_id,
                (int) $admin->id,
                'challenge',
                'messages.notifications.challenge_submission_rejected_title',
                'messages.notifications.challenge_submission_rejected_body',
                [],
                ['submissionId' => $challengeSubmission->id, 'challengeId' => $challengeSubmission->challenge_id],
            );
        } elseif ($status === 'changes_requested') {
            UserNotifier::sendTranslated(
                (int) $challengeSubmission->user_id,
                (int) $admin->id,
                'challenge',
                'messages.notifications.challenge_submission_changes_requested_title',
                'messages.notifications.challenge_submission_changes_requested_body',
                [],
                ['submissionId' => $challengeSubmission->id, 'challengeId' => $challengeSubmission->challenge_id],
            );
        }

        $challengeSubmission = ChallengeSubmission::query()->withApiResourceData($admin)->findOrFail($challengeSubmission->id);

        return response()->json([
            'message' => __('messages.admin.challenge_submission_reviewed'),
            'data' => [
                'submission' => new ChallengeSubmissionResource($challengeSubmission),
            ],
        ]);
    }

    public function selectWinner(Request $request, ChallengeSubmission $challengeSubmission): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'isWinner' => ['sometimes', 'boolean'],
            'rank' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $admin = $request->user();
        $isWinner = array_key_exists('isWinner', $validated) ? (bool) $validated['isWinner'] : true;
        $rank = $isWinner ? ($validated['rank'] ?? $challengeSubmission->winner_rank) : null;

        $challengeSubmission->forceFill([
            'is_winner' => $isWinner,
            'winner_rank' => $rank,
        ])->save();

        AuditLogger::record('admin.challenge_winner_updated', $challengeSubmission, (int) $admin->id, [
            'isWinner' => $isWinner,
            'rank' => $rank,
        ], $request->ip());

        if ($isWinner) {
            UserNotifier::sendTranslated(
                (int) $challengeSubmission->user_id,
                (int) $admin->id,
                'challenge',
                'messages.notifications.challenge_winner_title',
                'messages.notifications.challenge_winner_body',
                [],
                ['submissionId' => $challengeSubmission->id, 'challengeId' => $challengeSubmission->challenge_id],
            );
        }

        $challengeSubmission = ChallengeSubmission::query()->withApiResourceData($admin)->findOrFail($challengeSubmission->id);

        return response()->json([
            'message' => __('messages.admin.challenge_winner_updated'),
            'data' => [
                'submission' => new ChallengeSubmissionResource($challengeSubmission),
            ],
        ]);
    }

    public function analytics(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $participants = (int) ChallengeSubmission::query()
            ->where('challenge_id', $challenge->id)
            ->where('status', '!=', 'withdrawn')
            ->distinct()
            ->count('user_id');

        $timeline = ChallengeSubmission::query()
            ->where('challenge_id', $challenge->id)
            ->whereNotNull('submitted_at')
            ->selectRaw('DATE(submitted_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['date' => (string) $row->day, 'count' => (int) $row->total])
            ->all();

        $winners = ChallengeSubmission::query()
            ->withApiResourceData($request->user())
            ->where('challenge_id', $challenge->id)
            ->where('is_winner', true)
            ->orderBy('winner_rank')
            ->limit(20)
            ->get();

        return response()->json([
            'message' => __('messages.admin.challenge_analytics_retrieved'),
            'data' => [
                'analytics' => [
                    'submissions' => $this->submissionStats($challenge->id),
                    'participants' => $participants,
                    'timeline' => $timeline,
                    'winners' => ChallengeSubmissionResource::collection($winners)->toArray($request),
                ],
            ],
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $categories = Challenge::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->category, 'count' => (int) $row->total])
            ->all();

        return response()->json([
            'message' => __('messages.admin.challenge_categories_retrieved'),
            'data' => [
                'categories' => $categories,
            ],
        ]);
    }

    private function recordChallengeUpdate(Request $request, Challenge $challenge, string $previousStatus, string $nextStatus): void
    {
        $admin = $request->user();

        if ($previousStatus === $nextStatus) {
            AuditLogger::record('admin.challenge_updated', $challenge, (int) $admin->id, [
                'status' => $nextStatus,
            ], $request->ip());

            return;
        }

        $action = match ($nextStatus) {
            'published' => 'admin.challenge_published',
            'closed' => 'admin.challenge_closed',
            'draft' => 'admin.challenge_unpublished',
            default => 'admin.challenge_updated',
        };

        AuditLogger::record($action, $challenge, (int) $admin->id, [
            'from' => $previousStatus,
            'to' => $nextStatus,
        ], $request->ip());

        if ($nextStatus === 'published') {
            UserNotifier::sendTranslated(
                (int) $challenge->host_id,
                (int) $admin->id,
                'challenge',
                'messages.notifications.challenge_published_title',
                'messages.notifications.challenge_published_body',
                ['title' => $challenge->title],
                ['challengeId' => $challenge->id],
            );
        } elseif ($nextStatus === 'closed') {
            UserNotifier::sendTranslated(
                (int) $challenge->host_id,
                (int) $admin->id,
                'challenge',
                'messages.notifications.challenge_closed_title',
                'messages.notifications.challenge_closed_body',
                ['title' => $challenge->title],
                ['challengeId' => $challenge->id],
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function submissionStats(int $challengeId): array
    {
        $base = ChallengeSubmission::query()->where('challenge_id', $challengeId);

        return [
            'total' => (int) (clone $base)->count(),
            'submitted' => (int) (clone $base)->where('status', 'submitted')->count(),
            'approved' => (int) (clone $base)->where('status', 'approved')->count(),
            'rejected' => (int) (clone $base)->where('status', 'rejected')->count(),
            'changesRequested' => (int) (clone $base)->where('status', 'changes_requested')->count(),
            'withdrawn' => (int) (clone $base)->where('status', 'withdrawn')->count(),
            'winners' => (int) (clone $base)->where('is_winner', true)->count(),
        ];
    }

    private function challengeQuery(Request $request): Builder
    {
        return Challenge::query()->withApiResourceData($request->user());
    }
}
