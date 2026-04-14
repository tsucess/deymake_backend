<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiEditingProjectResource;
use App\Models\AiEditingProject;
use App\Models\Upload;
use App\Models\Video;
use App\Services\AiEditingStudioService;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiEditingStudioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $projects = AiEditingProject::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.ai_studio.projects_retrieved'),
            'data' => [
                'projects' => AiEditingProjectResource::collection($projects),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'sourceVideoId' => ['nullable', 'exists:videos,id'],
            'sourceUploadId' => ['nullable', 'exists:uploads,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'operations' => ['nullable', 'array'],
            'operations.*' => ['string', 'max:60'],
        ]);

        abort_if(! ($validated['sourceVideoId'] ?? null) && ! ($validated['sourceUploadId'] ?? null), 422, __('messages.ai_studio.source_required'));

        if (($validated['sourceVideoId'] ?? null) !== null) {
            $video = Video::query()->findOrFail($validated['sourceVideoId']);
            abort_if($video->user_id !== $request->user()->id, 403);
        }

        if (($validated['sourceUploadId'] ?? null) !== null) {
            $upload = Upload::query()->findOrFail($validated['sourceUploadId']);
            abort_if($upload->user_id !== $request->user()->id, 403);
        }

        $project = AiEditingProject::query()->create([
            'user_id' => $request->user()->id,
            'source_video_id' => $validated['sourceVideoId'] ?? null,
            'source_upload_id' => $validated['sourceUploadId'] ?? null,
            'title' => $validated['title'] ?? null,
            'operations' => $validated['operations'] ?? ['hooks', 'captions', 'cutdowns', 'thumbnails'],
            'status' => 'draft',
        ]);

        return response()->json([
            'message' => __('messages.ai_studio.project_created'),
            'data' => [
                'project' => new AiEditingProjectResource($project),
            ],
        ], 201);
    }

    public function show(Request $request, AiEditingProject $aiEditingProject): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($aiEditingProject->user_id !== $request->user()->id, 403);

        return response()->json([
            'message' => __('messages.ai_studio.project_retrieved'),
            'data' => [
                'project' => new AiEditingProjectResource($aiEditingProject),
            ],
        ]);
    }

    public function generate(Request $request, AiEditingProject $aiEditingProject, AiEditingStudioService $aiEditingStudioService): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($aiEditingProject->user_id !== $request->user()->id, 403);
        $aiEditingProject->load(['sourceVideo', 'sourceUpload']);

        $aiEditingProject->forceFill([
            'output' => $aiEditingStudioService->generate($aiEditingProject),
            'status' => 'generated',
            'generated_at' => now(),
        ])->save();

        return response()->json([
            'message' => __('messages.ai_studio.generated'),
            'data' => [
                'project' => new AiEditingProjectResource($aiEditingProject->fresh()),
            ],
        ]);
    }
}