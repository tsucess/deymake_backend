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

    public function createDirectUploadSignature(string $type, ?int $userId = null): array
    {
        $configuration = $this->configuration();
        $resourceType = $this->resourceTypeFor($type);
        $timestamp = time();
        $parameters = [
            'folder' => $this->folderFor($type, $userId),
            'overwrite' => 'false',
            'public_id' => (string) Str::uuid(),
            'timestamp' => $timestamp,
        ];

        return [
            'provider' => 'cloudinary',
            'resource_type' => $resourceType,
            'endpoint' => sprintf(
                'https://api.cloudinary.com/v1_1/%s/%s/upload',
                $configuration['cloud_name'],
                $resourceType,
            ),
            'fields' => [
                ...$parameters,
                'api_key' => $configuration['api_key'],
                'signature' => $this->signParameters($parameters, $configuration['api_secret']),
            ],
        ];
    }

    public function isManagedUrl(string $url): bool
    {
        $configuration = $this->configuration();
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');

        return $host === 'res.cloudinary.com'
            && str_starts_with($path, trim($configuration['cloud_name'], '/').'/');
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

    public function processedUrlFor(string $secureUrl, string $type): string
    {
        $transformation = $type === 'video'
            ? 'q_auto:best,f_auto,vc_auto'
            : 'q_auto,f_auto';

        return $this->transformedUrlFor($secureUrl, $transformation);
    }

    public function streamUrlFor(string $secureUrl): string
    {
        return $this->transformedUrlFor($secureUrl, 'sp_auto', '.m3u8');
    }

    public function thumbnailUrlFor(string $secureUrl): string
    {
        return $this->transformedUrlFor($secureUrl, 'so_0,f_jpg,q_auto', '.jpg');
    }

    protected function resourceTypeFor(string $type): string
    {
        return $type === 'video' ? 'video' : 'image';
    }

    protected function configuration(): array
    {
        $configuredUrl = (string) config('services.cloudinary.url');
        $parts = parse_url($configuredUrl);
        $cloudName = trim((string) ($parts['host'] ?? ''), '/');
        $apiKey = rawurldecode((string) ($parts['user'] ?? ''));
        $apiSecret = rawurldecode((string) ($parts['pass'] ?? ''));

        if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Cloudinary service is not configured.');
        }

        return [
            'cloud_name' => $cloudName,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ];
    }

    protected function signParameters(array $parameters, string $apiSecret): string
    {
        $parameters = array_filter(
            $parameters,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );

        ksort($parameters);

        $signatureBase = implode('&', array_map(
            static fn (string $key, mixed $value): string => $key.'='.(string) $value,
            array_keys($parameters),
            $parameters,
        ));

        return sha1($signatureBase.$apiSecret);
    }

    protected function transformedUrlFor(string $secureUrl, string $transformation, ?string $extension = null): string
    {
        $transformedUrl = preg_replace('/\/upload\//', '/upload/'.$transformation.'/', $secureUrl, 1) ?: $secureUrl;

        if ($extension === null) {
            return $transformedUrl;
        }

        $withExtension = preg_replace('/\.[^.\/?#]+(?=($|[?#]))/', $extension, $transformedUrl, 1);

        return $withExtension ?: $transformedUrl;
    }
}