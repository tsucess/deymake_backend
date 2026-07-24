<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Models\Video;
use App\Support\PaginatedJson;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User (public profile) controller.
 *
 * Serves the public-facing profile view for any user by id/username,
 * plus that user's posts and search endpoint. Personal (self) settings
 * live in ProfileController.
 *
 * Routes: GET /users/{user}, GET /users/{user}/posts, GET /users/search.
 * Frontend consumers: pages/UserProfile.jsx, SearchResults.jsx.
 * Related: User, Video models; UserResource, VideoResource.
 * See PROJECT_OVERVIEW.md §3.8 for the full data-flow map.
 */
class UserController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();
        $user = User::query()->withProfileAggregates($viewer)->findOrFail($user->id);

        return response()->json([
            'message' => __('messages.users.profile_retrieved'),
            'data' => [
                'user' => new ProfileResource($user),
            ],
        ]);
    }

    public function posts(Request $request, User $user): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        $videos = PaginatedJson::paginate(Video::query()
            ->withApiResourceData($viewer)
            ->where('user_id', $user->id)
            ->discoverable()
            ->latest(), $request);

        return $this->videoResponse($request, __('messages.users.posts_retrieved'), $videos);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $this->normalizedQuery($request);
        $usernameQuery = ltrim($query, '@#');
        $usernameQuery = $usernameQuery === '' ? $query : $usernameQuery;
        $viewer = auth('sanctum')->user() ?? $request->user();

        $users = $query === ''
            ? PaginatedJson::empty($request, 10, 25)
            : PaginatedJson::paginate(User::query()
                ->withProfileAggregates($viewer)
                ->when($query !== '', function ($builder) use ($query, $usernameQuery): void {
                    $builder->where(function ($nested) use ($query, $usernameQuery): void {
                        $nested->where('name', 'like', '%'.$query.'%')
                            ->orWhere('email', 'like', '%'.$query.'%')
                            ->orWhere('username', 'like', '%'.$usernameQuery.'%');
                    });
                })
                ->orderBy('name'), $request, 10, 25);

        return $this->userResponse($request, __('messages.users.retrieved'), $users);
    }

    private function normalizedQuery(Request $request): string
    {
        return preg_replace('/\s+/', ' ', trim($request->string('q')->toString())) ?? '';
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

    private function userResponse(Request $request, string $message, LengthAwarePaginator $users): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                'users' => PaginatedJson::items($request, $users, ProfileResource::class),
            ],
            'meta' => [
                'users' => PaginatedJson::meta($users),
            ],
        ]);
    }
}