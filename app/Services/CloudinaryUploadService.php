<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class CloudinaryUploadService
{
    public function upload(UploadedFile $file, string $type, ?int $userId = null): array
    {
        $result = $this->client()->uploadApi()->upload($file->getRealPath(), [
            'resource_type' => 'auto',
            'folder' => $this->folderFor($type, $userId),
            'public_id' => (string) Str::uuid(),
            'overwrite' => false,
        ]);

        $secureUrl = $result['secure_url'] ?? null;

        if (! is_string($secureUrl) || $secureUrl === '') {
            throw new RuntimeException('Cloudinary upload did not return a secure URL.');
        }

        return [
            'disk' => 'cloudinary',
            'path' => $secureUrl,
            'url' => $secureUrl,
            'processed_url' => $this->processedUrlFor($secureUrl, $type),
            'processing_status' => 'completed',
            'width' => isset($result['width']) ? (int) $result['width'] : null,
            'height' => isset($result['height']) ? (int) $result['height'] : null,
            'duration' => isset($result['duration']) ? (float) $result['duration'] : null,
        ];
    }

    protected function client(): Cloudinary
    {
        return new Cloudinary(config('services.cloudinary.url'));
    }

    protected function folderFor(string $type, ?int $userId = null): string
    {
        $baseFolder = trim((string) config('services.cloudinary.folder', 'deymake/uploads'), '/');

        return implode('/', array_filter([
            $baseFolder,
            $type === 'video' ? 'videos' : 'images',
            $userId ? 'user-'.$userId : null,
        ]));
    }

    protected function processedUrlFor(string $secureUrl, string $type): string
    {
        $transformation = $type === 'video'
            ? 'q_auto,f_auto,vc_auto'
            : 'q_auto,f_auto';

        return preg_replace('/\/upload\//', '/upload/'.$transformation.'/', $secureUrl, 1) ?: $secureUrl;
    }
}