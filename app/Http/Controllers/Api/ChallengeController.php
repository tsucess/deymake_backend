<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Challenge\StoreChallengeRequest;
use App\Http\Requests\Challenge\StoreChallengeSubmissionRequest;
use App\Http\Requests\Challenge\UpdateChallengeRequest;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\ChallengeSubmissionResource;
use App\Models\Challenge;
use App\Models\ChallengeSubmission;
use App\Models\Video;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = auth('sanctum')->user() ?? $request->user();
        $status = trim($request->string('status')->toString());
        $query = preg_replace('/\s+/', ' ', trim($request->string('q')->toString())) ?? '';

        $challenges = PaginatedJson::paginate(
            Challenge::query()
                ->withApiResourceData($viewer)
                ->visibleTo($viewer)
                ->when($query !== '', function ($builder) use ($query): void {
                    $builder->where(function ($nested) use ($query): void {
                        $nested->where('title', 'like', '%'.$query.'%')
                            ->orWhere('summary', 'like', '%'.$query.'%')
                            ->orWhere('description', 'like', '%'.$query.'%');
                    });
                })
                ->when($status === 'featured', fn ($builder) => $builder->where('is_featured', true))
                ->when($status === 'draft', fn ($builder) => $builder->where('status', 'draft'))
                ->when($status === 'active', fn ($builder) => $builder
                    ->where('status', 'published')
                    ->where('submission_starts_at', '<=', now())
                    ->where(fn ($nested) => $nested->whereNull('submission_ends_at')->orWhere('submission_ends_at', '>=', now())))
                ->when($status === 'upcoming', fn ($builder) => $builder
                    ->where('status', 'published')
                    ->where('submission_starts_at', '>', now()))
                ->when($status === 'closed', fn ($builder) => $builder->where(function ($nested): void {
                    $nested->where('status', 'closed')
                        ->orWhere(function ($published): void {
                            $published->where('status', 'published')
                                ->whereNotNull('submission_ends_at')
                                ->where('submission_ends_at', '<', now());
                        });
                }))
                ->orderByDesc('is_featured')
                ->orderBy('submission_starts_at')
                ->latest('id'),
            $request
        );

        return $this->challengeResponse($request, __('messages.challenges.retrieved'), $challenges);
    }

    public function show(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = auth('sanctum')->user() ?? $request->user();
        $challenge = $this->visibleChallengeOrFail($challenge, $viewer);
        $challenge = Challenge::query()->withApiResourceData($viewer)->findOrFail($challenge->id);

        return response()->json([
            'message' => __('messages.challenges.retrieved'),
            'data' => [
                'challenge' => new ChallengeResource($challenge),
            ],
        ]);
    }

    public function myChallenges(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $challenges = PaginatedJson::paginate(
            Challenge::query()
                ->withApiResourceData($request->user())
                ->where('host_id', $request->user()->id)
                ->latest(),
            $request
        );

        return $this->challengeResponse($request, __('messages.challenges.my_retrieved'), $challenges, 'challenges');
    }

    public function store(StoreChallengeRequest $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();

        $challenge = Challenge::query()->create([
            'host_id' => $request->user()->id,
            'title' => $validated['title'],
            'slug' => $validated['slug'] ?? null,
            'summary' => $validated['summary'] ?? null,
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

        $challenge->loadMissing('host');
        $challenge = Challenge::query()->withApiResourceData($request->user())->findOrFail($challenge->id);

        return response()->json([
            'message' => __('messages.challenges.created'),
            'data' => [
                'challenge' => new ChallengeResource($challenge),
            ],
        ], 201);
    }

    public function update(UpdateChallengeRequest $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($challenge->host_id !== $request->user()->id, 403);

        $validated = $request->validated();
        $challenge->forceFill([
            'title' => $validated['title'] ?? $challenge->title,
            'slug' => array_key_exists('slug', $validated)
                ? ($validated['slug'] ?: Challenge::generateUniqueSlug($validated['title'] ?? $challenge->title, $challenge->id))
                : $challenge->slug,
            'summary' => array_key_exists('summary', $validated) ? $validated['summary'] : $challenge->summary,
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
            'is_featured' => $validated['isFeatured'] ?? $challenge->is_featured,
            'status' => $validated['status'] ?? $challenge->status,
            'closed_at' => ($validated['status'] ?? $challenge->status) === 'closed'
                ? ($challenge->closed_at ?? now())
                : null,
        ])->save();

        $challenge = Challenge::query()->withApiResourceData($request->user())->findOrFail($challenge->id);

        return response()->json([
            'message' => __('messages.challenges.updated'),
            'data' => [
                'challenge' => new ChallengeResource($challenge),
            ],
        ]);
    }

    public function publish(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($challenge->host_id !== $request->user()->id, 403);

        $challenge->forceFill([
            'status' => 'published',
            'published_at' => $challenge->published_at ?? now(),
            'closed_at' => null,
        ])->save();

        $challenge = Challenge::query()->withApiResourceData($request->user())->findOrFail($challenge->id);

        return response()->json([
            'message' => __('messages.challenges.published'),
            'data' => [
                'challenge' => new ChallengeResource($challenge),
            ],
        ]);
    }

    public function destroy(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($challenge->host_id !== $request->user()->id, 403);
        $challenge->delete();

        return response()->json([
            'message' => __('messages.challenges.deleted'),
        ]);
    }

    public function submissions(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = auth('sanctum')->user() ?? $request->user();
        $challenge = $this->visibleChallengeOrFail($challenge, $viewer);
        $isHost = $viewer && $viewer->id === $challenge->host_id;

        $submissions = PaginatedJson::paginate(
            ChallengeSubmission::query()
                ->withApiResourceData($viewer)
                ->where('challenge_id', $challenge->id)
                ->when(! $isHost, fn ($builder) => $builder->where('status', '!=', 'withdrawn'))
                ->latest(),
            $request
        );

        return $this->submissionResponse($request, __('messages.challenges.submissions_retrieved'), $submissions, 'submissions');
    }

    public function mySubmissions(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $submissions = PaginatedJson::paginate(
            ChallengeSubmission::query()
                ->withApiResourceData($request->user())
                ->where('user_id', $request->user()->id)
                ->latest(),
            $request
        );

        return $this->submissionResponse($request, __('messages.challenges.my_submissions_retrieved'), $submissions, 'submissions');
    }

    public function mySubmissionsForChallenge(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $challenge = $this->visibleChallengeOrFail($challenge, $request->user());

        $submissions = PaginatedJson::paginate(
            ChallengeSubmission::query()
                ->withApiResourceData($request->user())
                ->where('challenge_id', $challenge->id)
                ->where('user_id', $request->user()->id)
                ->latest(),
            $request
        );

        return $this->submissionResponse($request, __('messages.challenges.my_submissions_retrieved'), $submissions, 'submissions');
    }

    public function storeSubmission(StoreChallengeSubmissionRequest $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $challenge = $this->visibleChallengeOrFail($challenge, $request->user());
        abort_unless($challenge->isOpenForSubmissions(), 422, __('messages.challenges.not_open'));

        $activeSubmissionCount = ChallengeSubmission::query()
            ->where('challenge_id', $challenge->id)
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'withdrawn')
            ->count();

        abort_if($activeSubmissionCount >= $challenge->max_submissions_per_user, 422, __('messages.challenges.submission_limit_reached'));

        $validated = $request->validated();
        $video = null;

        if (! empty($validated['videoId'])) {
            $video = Video::query()->findOrFail($validated['videoId']);
            abort_if($video->user_id !== $request->user()->id, 403, __('messages.challenges.video_not_owned'));
            abort_if($video->is_draft, 422, __('messages.challenges.video_must_be_published'));
        }

        $submission = ChallengeSubmission::query()->create([
            'challenge_id' => $challenge->id,
            'user_id' => $request->user()->id,
            'video_id' => $video?->id,
            'title' => $validated['title'] ?? $video?->title,
            'caption' => $validated['caption'] ?? $video?->caption,
            'description' => $validated['description'] ?? $video?->description,
            'media_url' => $validated['mediaUrl'] ?? $video?->media_url,
            'thumbnail_url' => $validated['thumbnailUrl'] ?? $video?->thumbnail_url,
            'external_url' => $validated['externalUrl'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $submission = ChallengeSubmission::query()->withApiResourceData($request->user())->findOrFail($submission->id);

        return response()->json([
            'message' => __('messages.challenges.submission_created'),
            'data' => [
                'submission' => new ChallengeSubmissionResource($submission),
            ],
        ], 201);
    }

    public function withdrawSubmission(Request $request, ChallengeSubmission $submission): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($submission->user_id !== $request->user()->id, 403);

        $submission->forceFill([
            'status' => 'withdrawn',
            'withdrawn_at' => $submission->withdrawn_at ?? now(),
        ])->save();

        $submission = ChallengeSubmission::query()->withApiResourceData($request->user())->findOrFail($submission->id);

        return response()->json([
            'message' => __('messages.challenges.submission_withdrawn'),
            'data' => [
                'submission' => new ChallengeSubmissionResource($submission),
            ],
        ]);
    }

    private function visibleChallengeOrFail(Challenge $challenge, $viewer): Challenge
    {
        if ($challenge->status === 'draft' && (! $viewer || $viewer->id !== $challenge->host_id)) {
            abort(404);
        }

        return $challenge;
    }

    private function challengeResponse(Request $request, string $message, LengthAwarePaginator $challenges, string $key = 'challenges'): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                $key => PaginatedJson::items($request, $challenges, ChallengeResource::class),
            ],
            'meta' => [
                $key => PaginatedJson::meta($challenges),
            ],
        ]);
    }

    private function submissionResponse(Request $request, string $message, LengthAwarePaginator $submissions, string $key = 'submissions'): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                $key => PaginatedJson::items($request, $submissions, ChallengeSubmissionResource::class),
            ],
            'meta' => [
                $key => PaginatedJson::meta($submissions),
            ],
        ]);
    }
}