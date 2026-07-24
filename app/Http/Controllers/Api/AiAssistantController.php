<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\GenerateCaptionSuggestionsRequest;
use App\Http\Requests\Ai\GenerateIdeaPromptsRequest;
use App\Services\LiteAiPromptService;
use App\Support\SupportedLocales;
use Illuminate\Support\Facades\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI assistant controller.
 *
 * Lightweight LLM helpers for creators: caption generation and content
 * ideas. Delegates prompt shaping to LiteAiPromptService.
 *
 * Routes: POST /ai/captions, POST /ai/ideas.
 * Frontend consumers: pages/Workspace.jsx via api.generateAiCaptions/Ideas.
 * Related: LiteAiPromptService.
 * See PROJECT_OVERVIEW.md §3.18 for the full data-flow map.
 */
class AiAssistantController extends Controller
{
    public function captions(
        GenerateCaptionSuggestionsRequest $request,
        LiteAiPromptService $liteAiPromptService,
    ): JsonResponse {
        $locale = $this->resolveLocale($request);
        $payload = $liteAiPromptService->generateCaptions($request->user(), $request->validated(), $locale);

        return response()->json([
            'message' => __('messages.ai.captions_generated'),
            'data' => [
                'captions' => $payload['captions'],
                'meta' => $payload['meta'],
            ],
        ]);
    }

    public function ideas(
        GenerateIdeaPromptsRequest $request,
        LiteAiPromptService $liteAiPromptService,
    ): JsonResponse {
        $locale = $this->resolveLocale($request);
        $payload = $liteAiPromptService->generateIdeas($request->user(), $request->validated(), $locale);

        return response()->json([
            'message' => __('messages.ai.ideas_generated'),
            'data' => [
                'ideas' => $payload['ideas'],
                'meta' => $payload['meta'],
            ],
        ]);
    }

    private function resolveLocale(Request $request): string
    {
        $user = auth('sanctum')->user() ?? $request->user();

        $locale = SupportedLocales::fromHeader($request->header('X-Locale'))
            ?? SupportedLocales::fromHeader($request->header('Accept-Language'))
            ?? SupportedLocales::match(data_get($user?->preferences, 'language'))
            ?? SupportedLocales::resolve(config('app.locale'));

        App::setLocale($locale);

        return $locale;
    }
}