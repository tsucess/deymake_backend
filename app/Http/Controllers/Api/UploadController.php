<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use App\Services\CloudinaryUploadService;
use Cloudinary\Api\Exception\ApiError;
use Cloudinary\Exception\ConfigurationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class UploadController extends Controller
{
    public function store(Request $request, CloudinaryUploadService $cloudinaryUploadService): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimetypes:image/jpeg,image/png,image/gif,video/mp4,video/quicktime,video/x-msvideo'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $this->detectType($file);

        try {
            $uploadedMedia = $cloudinaryUploadService->upload($file, $type, $request->user()?->id);
        } catch (ConfigurationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Media upload service is not configured.',
                'errors' => [
                    'file' => ['Set CLOUDINARY_URL before uploading media.'],
                ],
            ], 503);
        } catch (ApiError|RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to upload file at the moment.',
                'errors' => [
                    'file' => ['The media upload failed. Please try again.'],
                ],
            ], 502);
        }

        $upload = Upload::create([
            'user_id' => $request->user()->id,
            'type' => $type,
            'disk' => $uploadedMedia['disk'],
            'path' => $uploadedMedia['path'],
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
        $path = trim((string) config('services.cloudinary.folder', 'deymake/uploads'), '/')
            .'/'.Str::uuid().'.'.$extension;

        return response()->json([
            'message' => 'Presign configuration generated successfully.',
            'data' => [
                'strategy' => 'server-upload',
                'provider' => 'cloudinary',
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