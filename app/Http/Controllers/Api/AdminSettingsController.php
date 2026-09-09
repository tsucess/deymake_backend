<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Models\PlatformSetting;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->user()?->isAdmin() || abort(403, __('messages.admin.access_denied'));

        $settings = PlatformSetting::appSettings();

        return response()->json([
            'message' => 'Platform settings loaded successfully.',
            'data' => [
                'settings' => $this->sanitizeForFrontend($settings),
            ],
        ]);
    }

    public function update(UpdatePlatformSettingsRequest $request): JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin?->isAdmin(), 403, __('messages.admin.access_denied'));

        $current = PlatformSetting::appSettings();
        $next = $this->mergeSettings($current, $request->validated());

        $sanitizedNext = $this->sanitizeForStorage($next);
        PlatformSetting::updateAppSettings($sanitizedNext);

        AuditLogger::record('admin.platform_settings_updated', null, (int) $admin->id, [
            'changed_keys' => array_keys($request->validated()),
            'previous_values' => $this->filterSensitive($current),
            'new_values' => $this->filterSensitive($sanitizedNext),
        ], $request->ip());

        return response()->json([
            'message' => 'Platform settings updated successfully.',
            'data' => [
                'settings' => $this->sanitizeForFrontend($sanitizedNext),
            ],
        ]);
    }

    private function mergeSettings(array $current, array $payload): array
    {
        $current = is_array($current) ? $current : [];

        foreach ($payload as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $current[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $current[$key] = array_replace_recursive($current[$key] ?? [], $value);
                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }

    private function sanitizeForStorage(array $settings): array
    {
        $safe = $settings;

        foreach (['oauth_providers', 'payment_providers'] as $section) {
            if (! isset($safe[$section]) || ! is_array($safe[$section])) {
                continue;
            }

            foreach ($safe[$section] as $providerName => $providerConfig) {
                if (! is_array($providerConfig)) {
                    continue;
                }

                $safe[$section][$providerName] = [
                    'enabled' => (bool) ($providerConfig['enabled'] ?? false),
                    'client_id' => $providerConfig['client_id'] ?? null,
                    'client_secret' => $providerConfig['client_secret'] ?? null,
                    'public_key' => $providerConfig['public_key'] ?? null,
                    'secret_key' => $providerConfig['secret_key'] ?? null,
                ];
            }
        }

        return $safe;
    }

    private function sanitizeForFrontend(array $settings): array
    {
        $safe = $settings;

        foreach (['oauth_providers', 'payment_providers'] as $section) {
            if (! isset($safe[$section]) || ! is_array($safe[$section])) {
                continue;
            }

            foreach ($safe[$section] as $providerName => $providerConfig) {
                if (! is_array($providerConfig)) {
                    continue;
                }

                $filtered = [
                    'enabled' => (bool) ($providerConfig['enabled'] ?? false),
                ];

                if (isset($providerConfig['client_id'])) {
                    $filtered['client_id'] = $providerConfig['client_id'];
                }

                if (isset($providerConfig['public_key'])) {
                    $filtered['public_key'] = $providerConfig['public_key'];
                }

                foreach (['client_secret', 'secret_key'] as $secretKey) {
                    unset($providerConfig[$secretKey]);
                }

                $safe[$section][$providerName] = $filtered;
            }
        }

        return $safe;
    }

    private function filterSensitive(array $settings): array
    {
        $safe = $settings;

        foreach (['oauth_providers', 'payment_providers'] as $section) {
            if (! isset($safe[$section]) || ! is_array($safe[$section])) {
                continue;
            }

            foreach ($safe[$section] as $providerName => $providerConfig) {
                if (! is_array($providerConfig)) {
                    continue;
                }

                foreach (['client_secret', 'secret_key'] as $secretKey) {
                    unset($safe[$section][$providerName][$secretKey]);
                }
            }
        }

        return $safe;
    }
}
