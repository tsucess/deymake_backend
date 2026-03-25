<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Upload;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VideoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $videos = Video::query()
            ->with(['user', 'category', 'upload'])
            ->where('is_draft', false)
            ->when($request->filled('category'), function ($query) use ($request): void {
                $category = $request->string('category')->toString();

                if (ctype_digit($category)) {
                    $query->where('category_id', (int) $category);

                    return;
                }

                $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('slug', $category));
            })
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Videos retrieved successfully.',
            'data' => [
                'videos' => VideoResource::collection($videos),
            ],
        ]);
    }

    public function trending(): JsonResponse
    {
        $videos = Video::query()
            ->with(['user', 'category', 'upload'])
            ->where('is_draft', false)
            ->orderByDesc('views_count')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Trending videos retrieved successfully.',
            'data' => [
                'videos' => VideoResource::collection($videos),
            ],
        ]);
    }

    public function live(): JsonResponse
    {
        $videos = Video::query()
            ->with(['user', 'category', 'upload'])
            ->where('is_draft', false)
            ->where('is_live', true)
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Live videos retrieved successfully.',
            'data' => [
                'videos' => VideoResource::collection($videos),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:image,gif,video'],
            'categoryId' => ['nullable', 'exists:categories,id'],
            'uploadId' => ['nullable', 'exists:uploads,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'taggedUsers' => ['nullable', 'array'],
            'taggedUsers.*' => ['integer', 'exists:users,id'],
            'mediaUrl' => ['nullable', 'string', 'max:2048'],
            'thumbnailUrl' => ['nullable', 'string', 'max:2048'],
            'isLive' => ['sometimes', 'boolean'],
            'isDraft' => ['sometimes', 'boolean'],
        ]);

        $upload = isset($validated['uploadId']) ? Upload::query()->findOrFail($validated['uploadId']) : null;

        if ($upload && $upload->user_id !== $request->user()->id) {
            abort(403, 'You are not allowed to use this upload.');
        }

        $video = Video::create([
            'user_id' => $request->user()->id,
            'category_id' => $validated['categoryId'] ?? null,
            'upload_id' => $upload?->id,
            'type' => $validated['type'],
            'title' => $validated['title'] ?? null,
            'caption' => $validated['caption'] ?? null,
            'description' => $validated['description'] ?? ($validated['caption'] ?? null),
            'location' => $validated['location'] ?? null,
            'tagged_users' => $validated['taggedUsers'] ?? [],
            'media_url' => $validated['mediaUrl'] ?? $upload?->url,
            'thumbnail_url' => $validated['thumbnailUrl'] ?? null,
            'is_live' => $validated['isLive'] ?? false,
            'is_draft' => $validated['isDraft'] ?? true,
        ]);

        $video->load(['user', 'category', 'upload']);

        return response()->json([
            'message' => 'Video created successfully.',
            'data' => [
                'video' => new VideoResource($video),
            ],
        ], 201);
    }

    public function show(Request $request, Video $video): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        if ($video->is_draft && (! $viewer || $viewer->id !== $video->user_id)) {
            abort(404);
        }

        $video->load(['user', 'category', 'upload']);

        return response()->json([
            'message' => 'Video retrieved successfully.',
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function related(Video $video): JsonResponse
    {
        $related = Video::query()
            ->with(['user', 'category', 'upload'])
            ->where('is_draft', false)
            ->where('id', '!=', $video->id)
            ->where(function ($query) use ($video): void {
                $query->where('user_id', $video->user_id);

                if ($video->category_id) {
                    $query->orWhere('category_id', $video->category_id);
                }
            })
            ->latest()
            ->limit(8)
            ->get();

        return response()->json([
            'message' => 'Related videos retrieved successfully.',
            'data' => [
                'videos' => VideoResource::collection($related),
            ],
        ]);
    }

    public function recordView(Video $video): JsonResponse
    {
        $video->increment('views_count');
        $video->load(['user', 'category', 'upload']);

        return response()->json([
            'message' => 'Video view recorded successfully.',
            'data' => [
                'views' => (int) $video->views_count,
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function report(Request $request, Video $video): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::table('video_reports')->insert([
            'video_id' => $video->id,
            'user_id' => $request->user()->id,
            'reason' => $validated['reason'] ?? null,
            'details' => $validated['details'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Video reported successfully.',
        ], 201);
    }

    public function update(Request $request, Video $video): JsonResponse
    {
        abort_if($video->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'type' => ['sometimes', 'in:image,gif,video'],
            'categoryId' => ['nullable', 'exists:categories,id'],
            'uploadId' => ['nullable', 'exists:uploads,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'taggedUsers' => ['nullable', 'array'],
            'taggedUsers.*' => ['integer', 'exists:users,id'],
            'mediaUrl' => ['nullable', 'string', 'max:2048'],
            'thumbnailUrl' => ['nullable', 'string', 'max:2048'],
            'isLive' => ['sometimes', 'boolean'],
            'isDraft' => ['sometimes', 'boolean'],
        ]);

        $upload = array_key_exists('uploadId', $validated)
            ? Upload::query()->find($validated['uploadId'])
            : $video->upload;

        if ($upload && $upload->user_id !== $request->user()->id) {
            abort(403, 'You are not allowed to use this upload.');
        }

        $video->fill([
            'type' => $validated['type'] ?? $video->type,
            'category_id' => $validated['categoryId'] ?? $video->category_id,
            'upload_id' => $upload?->id,
            'title' => $validated['title'] ?? $video->title,
            'caption' => array_key_exists('caption', $validated) ? $validated['caption'] : $video->caption,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $video->description,
            'location' => array_key_exists('location', $validated) ? $validated['location'] : $video->location,
            'tagged_users' => $validated['taggedUsers'] ?? $video->tagged_users,
            'media_url' => $validated['mediaUrl'] ?? $upload?->url ?? $video->media_url,
            'thumbnail_url' => array_key_exists('thumbnailUrl', $validated) ? $validated['thumbnailUrl'] : $video->thumbnail_url,
            'is_live' => $validated['isLive'] ?? $video->is_live,
            'is_draft' => $validated['isDraft'] ?? $video->is_draft,
        ])->save();

        $video->load(['user', 'category', 'upload']);

        return response()->json([
            'message' => 'Video updated successfully.',
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function publish(Request $request, Video $video): JsonResponse
    {
        abort_if($video->user_id !== $request->user()->id, 403);

        $video->forceFill(['is_draft' => false])->save();
        $video->load(['user', 'category', 'upload']);

        return response()->json([
            'message' => 'Video published successfully.',
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function share(Video $video): JsonResponse
    {
        $video->increment('shares_count');

        return response()->json([
            'message' => 'Video share recorded successfully.',
            'data' => [
                'shares' => (int) $video->shares_count,
                'shareUrl' => rtrim(config('app.url'), '/').'/videos/'.$video->id,
            ],
        ]);
    }
}