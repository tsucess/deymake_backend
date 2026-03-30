<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Models\Video;
use App\Support\PaginatedJson;
use App\Support\UserDefaults;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = User::query()->withProfileAggregates()->findOrFail($request->user()->id);

        return response()->json([
            'message' => 'Profile retrieved successfully.',
            'data' => [
                'profile' => new ProfileResource($profile),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fullName' => ['sometimes', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'avatarUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $request->user()->forceFill([
            'name' => $validated['fullName'] ?? $request->user()->name,
            'bio' => array_key_exists('bio', $validated) ? $validated['bio'] : $request->user()->bio,
            'avatar_url' => array_key_exists('avatarUrl', $validated) ? $validated['avatarUrl'] : $request->user()->avatar_url,
        ])->save();

        $profile = User::query()->withProfileAggregates()->findOrFail($request->user()->id);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => [
                'profile' => new ProfileResource($profile),
            ],
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        return $this->videoResponse($request, 'Posts retrieved successfully.', PaginatedJson::paginate(Video::query()
            ->withApiResourceData($request->user())
            ->where('user_id', $request->user()->id)
            ->where('is_draft', false)
            ->latest(), $request));
    }

    public function liked(Request $request): JsonResponse
    {
        $videoIds = DB::table('video_interactions')
            ->where('user_id', $request->user()->id)
            ->where('type', 'like')
            ->pluck('video_id');

        return $this->videoResponse($request, 'Liked videos retrieved successfully.', PaginatedJson::paginate(Video::query()
            ->withApiResourceData($request->user())
            ->whereIn('id', $videoIds)
            ->where('is_draft', false)
            ->latest(), $request));
    }

    public function saved(Request $request): JsonResponse
    {
        $videoIds = DB::table('video_interactions')
            ->where('user_id', $request->user()->id)
            ->where('type', 'save')
            ->pluck('video_id');

        return $this->videoResponse($request, 'Saved videos retrieved successfully.', PaginatedJson::paginate(Video::query()
            ->withApiResourceData($request->user())
            ->whereIn('id', $videoIds)
            ->where('is_draft', false)
            ->latest(), $request));
    }

    public function drafts(Request $request): JsonResponse
    {
        return $this->videoResponse($request, 'Draft videos retrieved successfully.', PaginatedJson::paginate(Video::query()
            ->withApiResourceData($request->user())
            ->where('user_id', $request->user()->id)
            ->where('is_draft', true)
            ->latest(), $request));
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Preferences retrieved successfully.',
            'data' => [
                'preferences' => array_replace_recursive(UserDefaults::preferences(), $request->user()->preferences ?? []),
            ],
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notificationSettings' => ['sometimes', 'array'],
            'language' => ['sometimes', 'string', 'max:20'],
            'displayPreferences' => ['sometimes', 'array'],
            'accessibilityPreferences' => ['sometimes', 'array'],
        ]);

        $preferences = array_replace_recursive(
            UserDefaults::preferences(),
            $request->user()->preferences ?? [],
            $validated,
        );

        $request->user()->forceFill(['preferences' => $preferences])->save();

        return response()->json([
            'message' => 'Preferences updated successfully.',
            'data' => [
                'preferences' => $preferences,
            ],
        ]);
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
}