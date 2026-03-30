<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

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