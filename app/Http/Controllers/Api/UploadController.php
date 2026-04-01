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
use RuntimeException;

class UploadController extends Controller
{
    public function store(Request $request, CloudinaryUploadService $cloudinaryUploadService): JsonResponse
    {
        if (! $request->hasFile('file')) {
            return $this->storeDirectUpload($request, $cloudinaryUploadService);
        }

        return $this->storeServerUpload($request, $cloudinaryUploadService);
    }

    public function presign(Request $request, CloudinaryUploadService $cloudinaryUploadService): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:image,gif,video'],
            'originalName' => ['required', 'string', 'max:255'],
        ]);

        try {
            $signedUpload = $cloudinaryUploadService->createDirectUploadSignature(
                $validated['type'],
                $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => __('messages.upload.service_not_configured'),
                'errors' => [
                    'file' => [__('messages.upload.service_not_configured_detail')],
                ],
            ], 503);
        }

        return response()->json([
            'message' => __('messages.upload.presign_generated'),
            'data' => [
                'strategy' => 'client-direct-upload',
                'provider' => $signedUpload['provider'],
                'method' => 'POST',
                'endpoint' => $signedUpload['endpoint'],
                'fields' => $signedUpload['fields'],
                'resourceType' => $signedUpload['resource_type'],
            ],
        ]);
    }

    private function storeServerUpload(Request $request, CloudinaryUploadService $cloudinaryUploadService): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/gif,video/mp4,video/quicktime,video/x-msvideo'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $this->detectType($file);

        try {
            $uploadedMedia = $cloudinaryUploadService->upload($file, $type, $request->user()?->id);
        } catch (ConfigurationException $exception) {
            report($exception);

            return response()->json([
                'message' => __('messages.upload.service_not_configured'),
                'errors' => [
                    'file' => [__('messages.upload.service_not_configured_detail')],
                ],
            ], 503);
        } catch (ApiError|RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => __('messages.upload.failed'),
                'errors' => [
                    'file' => [__('messages.upload.failed_detail')],
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
            'processing_status' => $uploadedMedia['processing_status'] ?? 'completed',
            'width' => $uploadedMedia['width'] ?? null,
            'height' => $uploadedMedia['height'] ?? null,
            'duration' => $uploadedMedia['duration'] ?? null,
            'processed_url' => $uploadedMedia['processed_url'] ?? $uploadedMedia['url'] ?? null,
        ]);

        return response()->json([
            'message' => __('messages.upload.stored'),
            'data' => [
                'upload' => new UploadResource($upload),
            ],
        ], 201);
    }

    private function storeDirectUpload(Request $request, CloudinaryUploadService $cloudinaryUploadService): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:image,gif,video'],
            'path' => ['required', 'url', 'max:2048'],
            'originalName' => ['required', 'string', 'max:255'],
            'mimeType' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:0'],
            'processingStatus' => ['nullable', 'in:processing,completed,failed'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'duration' => ['nullable', 'numeric', 'min:0'],
            'processedUrl' => ['nullable', 'url', 'max:2048'],
        ]);

        abort_unless(
            $cloudinaryUploadService->isManagedUrl($validated['path']),
            422,
            __('messages.upload.failed')
        );

        if (isset($validated['processedUrl'])) {
            abort_unless(
                $cloudinaryUploadService->isManagedUrl($validated['processedUrl']),
                422,
                __('messages.upload.failed')
            );
        }

        $upload = Upload::create([
            'user_id' => $request->user()->id,
            'type' => $validated['type'],
            'disk' => 'cloudinary',
            'path' => $validated['path'],
            'original_name' => $validated['originalName'],
            'mime_type' => $validated['mimeType'] ?? 'application/octet-stream',
            'size' => (int) ($validated['size'] ?? 0),
            'processing_status' => $validated['processingStatus'] ?? 'completed',
            'width' => $validated['width'] ?? null,
            'height' => $validated['height'] ?? null,
            'duration' => isset($validated['duration']) ? (float) $validated['duration'] : null,
            'processed_url' => $validated['processedUrl']
                ?? $cloudinaryUploadService->processedUrlFor($validated['path'], $validated['type']),
        ]);

        return response()->json([
            'message' => __('messages.upload.stored'),
            'data' => [
                'upload' => new UploadResource($upload),
            ],
        ], 201);
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