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
}