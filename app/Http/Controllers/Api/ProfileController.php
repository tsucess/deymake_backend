<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Models\Video;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\Username;
use App\Support\UserDefaults;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $profile = User::query()->withProfileAggregates($request->user())->findOrFail($request->user()->id);

        return response()->json([
            'message' => __('messages.profile.retrieved'),
            'data' => [
                'profile' => new ProfileResource($profile),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'fullName' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'regex:'.Username::VALIDATION_REGEX, Rule::unique('users', 'username')->ignore($request->user()->id)],
            'bio' => ['nullable', 'string', 'max:1000'],
            'avatarUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $request->user()->forceFill([
            'name' => $validated['fullName'] ?? $request->user()->name,
            'username' => array_key_exists('username', $validated)
                ? Username::normalize($validated['username'], $request->user()->name)
                : $request->user()->username,
            'bio' => array_key_exists('bio', $validated) ? $validated['bio'] : $request->user()->bio,
            'avatar_url' => array_key_exists('avatarUrl', $validated) ? $validated['avatarUrl'] : $request->user()->avatar_url,
        ])->save();

        $profile = User::query()->withProfileAggregates($request->user())->findOrFail($request->user()->id);

        return response()->json([
            'message' => __('messages.profile.updated'),
            'data' => [
                'profile' => new ProfileResource($profile),
            ],
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        return $this->videoResponse($request, __('messages.feeds.posts_retrieved'), PaginatedJson::paginate(Video::query()
            ->withApiResourceData($request->user())
            ->where('user_id', $request->user()->id)
            ->where('is_draft', false)
            ->latest(), $request));
    }

    public function liked(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $videoIds = DB::table('video_interactions')
            ->where('user_id', $request->user()->id)
            ->where('type', 'like')
            ->pluck('video_id');

        return $this->videoResponse($request, __('messages.feeds.liked_retrieved'), PaginatedJson::paginate(Video::query()
            ->withApiResourceData($request->user())
            ->whereIn('id', $videoIds)
            ->where('is_draft', false)
            ->latest(), $request));
    }

    public function saved(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $videoIds = DB::table('video_interactions')
            ->where('user_id', $request->user()->id)
            ->where('type', 'save')
            ->pluck('video_id');

        return $this->videoResponse($request, __('messages.feeds.saved_retrieved'), PaginatedJson::paginate(Video::query()
            ->withApiResourceData($request->user())
            ->whereIn('id', $videoIds)
            ->where('is_draft', false)
            ->latest(), $request));
    }

    public function drafts(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        return $this->videoResponse($request, __('messages.feeds.drafts_retrieved'), PaginatedJson::paginate(Video::query()
            ->withApiResourceData($request->user())
            ->where('user_id', $request->user()->id)
            ->where('is_draft', true)
            ->latest(), $request));
    }

    public function preferences(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        return response()->json([
            'message' => __('messages.preferences.retrieved'),
            'data' => [
                'preferences' => array_replace_recursive(UserDefaults::preferences(), $request->user()->preferences ?? []),
            ],
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'notificationSettings' => ['sometimes', 'array'],
            'language' => ['sometimes', 'string', 'max:20', Rule::in(SupportedLocales::all())],
            'displayPreferences' => ['sometimes', 'array'],
            'accessibilityPreferences' => ['sometimes', 'array'],
        ], [
            'language.in' => __('messages.validation.language_supported'),
        ]);

        $preferences = array_replace_recursive(
            UserDefaults::preferences(),
            $request->user()->preferences ?? [],
            $validated,
        );

        $request->user()->forceFill(['preferences' => $preferences])->save();

        return response()->json([
            'message' => __('messages.preferences.updated'),
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