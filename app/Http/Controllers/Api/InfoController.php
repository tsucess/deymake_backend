<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Static info-pages controller.
 *
 * Serves help, privacy, and terms payloads to the frontend info screens.
 *
 * Routes: GET /help, GET /legal/privacy, GET /legal/terms.
 * See PROJECT_OVERVIEW.md §3.28 for the full data-flow map.
 */
class InfoController extends Controller
{
    public function help(): JsonResponse
    {
        return response()->json([
            'message' => __('messages.info.help_retrieved'),
            'data' => [
                'title' => __('messages.info.help_title'),
                'content' => __('messages.info.help_content'),
            ],
        ]);
    }

    public function privacy(): JsonResponse
    {
        return response()->json([
            'message' => __('messages.info.privacy_retrieved'),
            'data' => [
                'title' => __('messages.info.privacy_title'),
                'content' => __('messages.info.privacy_content'),
            ],
        ]);
    }

    public function terms(): JsonResponse
    {
        return response()->json([
            'message' => __('messages.info.terms_retrieved'),
            'data' => [
                'title' => __('messages.info.terms_title'),
                'content' => __('messages.info.terms_content'),
            ],
        ]);
    }
}