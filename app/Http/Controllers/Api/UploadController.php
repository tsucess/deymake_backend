<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimetypes:image/jpeg,image/png,image/gif,video/mp4,video/quicktime,video/x-msvideo'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        $upload = Upload::create([
            'user_id' => $request->user()->id,
            'type' => $this->detectType($file),
            'disk' => 'public',
            'path' => $file->store('uploads', 'public'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
        ]);

        return response()->json([
            'message' => 'Upload stored successfully.',
            'data' => [
                'upload' => new UploadResource($upload),
            ],
        ], 201);
    }

    public function presign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:image,gif,video'],
            'originalName' => ['required', 'string', 'max:255'],
        ]);

        $extension = pathinfo($validated['originalName'], PATHINFO_EXTENSION) ?: 'bin';
        $path = 'uploads/'.Str::uuid().'.'.$extension;

        return response()->json([
            'message' => 'Presign configuration generated successfully.',
            'data' => [
                'strategy' => 'local-multipart-upload',
                'method' => 'POST',
                'endpoint' => url('/api/v1/uploads'),
                'path' => $path,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ],
        ]);
    }

    private function detectType(UploadedFile $file): string
    {
        $mimeType = $file->getMimeType() ?: '';

        if ($file->getClientOriginalExtension() === 'gif' || $mimeType === 'image/gif') {
            return 'gif';
        }

        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        return 'video';
    }
}