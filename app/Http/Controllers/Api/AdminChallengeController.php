<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChallengeResource;
use App\Models\Challenge;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
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
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $challenges = PaginatedJson::paginate(
            $this->challengeQuery($request)
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('title', 'like', '%'.$query.'%')
                            ->orWhere('summary', 'like', '%'.$query.'%')
                            ->orWhere('slug', 'like', '%'.$query.'%');
                    });
                })
                ->when(in_array($status, ['draft', 'published', 'closed'], true), fn (Builder $b) => $b->where('status', $status))
                ->when($sort === 'oldest', fn (Builder $b) => $b->oldest())
                ->when($sort === 'most_submissions', fn (Builder $b) => $b->withCount('submissions')->orderByDesc('submissions_count')->latest())
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
            ],
        ]);
    }

    public function update(Request $request, Challenge $challenge): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'published', 'closed'])],
            'isFeatured' => ['sometimes', 'boolean'],
        ]);

        $updates = [];
        if (array_key_exists('status', $validated)) {
            $updates['status'] = $validated['status'];
            if ($validated['status'] === 'published' && ! $challenge->published_at) {
                $updates['published_at'] = now();
            }
            if ($validated['status'] === 'closed') {
                $updates['closed_at'] = now();
            }
        }
        if (array_key_exists('isFeatured', $validated)) {
            $updates['is_featured'] = (bool) $validated['isFeatured'];
        }

        if (! empty($updates)) {
            $challenge->forceFill($updates)->save();
        }

        $challenge = Challenge::query()
            ->withApiResourceData($request->user())
            ->findOrFail($challenge->id);

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

        $challenge->delete();

        return response()->json([
            'message' => __('messages.admin.challenge_deleted'),
        ]);
    }

    private function challengeQuery(Request $request): Builder
    {
        return Challenge::query()->withApiResourceData($request->user());
    }
}
