<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class InfoController extends Controller
{
    public function help(): JsonResponse
    {
        return response()->json([
            'message' => 'Help content retrieved successfully.',
            'data' => [
                'title' => 'Help Center',
                'content' => 'For support, contact the DeyMake team or consult the in-app guidance for uploads, engagement, and messaging.',
            ],
        ]);
    }

    public function privacy(): JsonResponse
    {
        return response()->json([
            'message' => 'Privacy policy retrieved successfully.',
            'data' => [
                'title' => 'Privacy Policy',
                'content' => 'DeyMake stores account, profile, content, and engagement data to deliver the platform experience. Avoid uploading sensitive personal information.',
            ],
        ]);
    }

    public function terms(): JsonResponse
    {
        return response()->json([
            'message' => 'Terms and conditions retrieved successfully.',
            'data' => [
                'title' => 'Terms of Service',
                'content' => 'By using DeyMake, you agree to comply with community rules, respect creators, and only upload content you own or are authorized to share.',
            ],
        ]);
    }
}