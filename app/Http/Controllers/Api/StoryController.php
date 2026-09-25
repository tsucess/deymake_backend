<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\StoryResource;
use App\Models\Story;
use App\Models\Upload;
use App\Services\CloudinaryUploadService;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stories controller.
 *
 * Ephemeral vertical clips shown on the home-feed rail. Handles feed,
 * create, view-count increment, and delete.
 *
 * Routes: GET /stories/feed, POST /stories, POST /stories/{story}/view,
 * DELETE /stories/{story}.
 * Frontend consumers: components/Homepage/* (story rail).
 * Related: Story model, StoryResource.
 * See PROJECT_OVERVIEW.md §3.11 for the full data-flow map.
 */
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
            'type' => 'nullable|string|in:text,image,video',
            'mediaUrl' => 'nullable|string|max:2048',
            'thumbnailUrl' => 'nullable|string|max:2048',
            'caption' => 'nullable|string|max:500',
            'backgroundColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'uploadId' => 'nullable|integer|exists:uploads,id',
        ]);

        $type = $validated['type'] ?? 'image';
        abort_if($type !== 'text' && blank($validated['mediaUrl'] ?? null), 422, 'Media is required for image and video stories.');

        $story = Story::query()->create([
            'user_id' => $request->user()->id,
            'upload_id' => $validated['uploadId'] ?? null,
            'type' => $type,
            'media_url' => $validated['mediaUrl'] ?? null,
            'thumbnail_url' => $validated['thumbnailUrl'] ?? null,
            'caption' => $validated['caption'] ?? null,
            'background_color' => $validated['backgroundColor'] ?? null,
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
            if ($story->user_id !== $request->user()->id) {
                UserNotifier::send($story->user_id, $request->user()->id, 'story_view', 'Your story was viewed', $request->user()->name.' viewed your story.', ['storyId' => $story->id, 'viewerId' => $request->user()->id]);
            }
        }

        $story = Story::query()->withViewerData($request->user())->findOrFail($story->id);

        return response()->json([
            'message' => __('messages.stories.viewed'),
            'data' => ['story' => new StoryResource($story)],
        ]);
    }

    public function viewers(Request $request, Story $story): JsonResponse
    {
        abort_unless($story->user_id === $request->user()->id, 403);

        $viewers = $story->viewers()->withProfileAggregates($request->user())->latest('story_views.created_at')->get();

        return response()->json([
            'message' => 'Story viewers retrieved.',
            'data' => ['viewers' => ProfileResource::collection($viewers)],
        ]);
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        abort_unless($story->user_id === $request->user()->id, 403, __('messages.stories.not_owner'));

        $upload = $story->upload;
        $story->delete();
        $this->deleteOrphanedUpload($upload);

        return response()->json([
            'message' => __('messages.stories.deleted'),
        ]);
    }

    private function deleteOrphanedUpload(?Upload $upload): void
    {
        if (! $upload || $upload->videos()->exists() || $upload->stories()->exists()) {
            return;
        }

        try {
            if (app(CloudinaryUploadService::class)->deleteAsset($upload)) {
                $upload->delete();
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
