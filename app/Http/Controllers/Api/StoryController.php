<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoryResource;
use App\Models\Story;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoryController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = $request->user();
        $subscribedCreatorIds = $viewer->subscribedCreators()->pluck('users.id')->all();
        $visibleAuthorIds = array_values(array_unique(array_merge([$viewer->id], $subscribedCreatorIds)));

        $stories = Story::query()
            ->active()
            ->withViewerData($viewer)
            ->whereIn('user_id', $visibleAuthorIds)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'message' => __('messages.stories.feed_retrieved'),
            'data' => [
                'stories' => StoryResource::collection($stories),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|string|in:image,video',
            'mediaUrl' => 'required|string|max:2048',
            'thumbnailUrl' => 'nullable|string|max:2048',
            'caption' => 'nullable|string|max:500',
            'uploadId' => 'nullable|integer|exists:uploads,id',
        ]);

        $story = Story::query()->create([
            'user_id' => $request->user()->id,
            'upload_id' => $validated['uploadId'] ?? null,
            'type' => $validated['type'] ?? 'image',
            'media_url' => $validated['mediaUrl'],
            'thumbnail_url' => $validated['thumbnailUrl'] ?? null,
            'caption' => $validated['caption'] ?? null,
            'expires_at' => now()->addHours(24),
        ]);

        $story = Story::query()->withViewerData($request->user())->findOrFail($story->id);

        return response()->json([
            'message' => __('messages.stories.created'),
            'data' => ['story' => new StoryResource($story)],
        ], 201);
    }

    public function view(Request $request, Story $story): JsonResponse
    {
        abort_if($story->expires_at?->isPast(), 410, __('messages.stories.expired'));

        $existing = DB::table('story_views')
            ->where('story_id', $story->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $existing) {
            DB::table('story_views')->insert([
                'story_id' => $story->id,
                'user_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $story->increment('views_count');
        }

        $story = Story::query()->withViewerData($request->user())->findOrFail($story->id);

        return response()->json([
            'message' => __('messages.stories.viewed'),
            'data' => ['story' => new StoryResource($story)],
        ]);
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        abort_unless($story->user_id === $request->user()->id, 403, __('messages.stories.not_owner'));

        $story->delete();

        return response()->json([
            'message' => __('messages.stories.deleted'),
        ]);
    }
}
