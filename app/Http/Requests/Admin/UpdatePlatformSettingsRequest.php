<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform_name' => ['sometimes', 'string', 'max:120'],
            'platform_logo' => ['nullable', 'string', 'max:2048'],
            'supported_languages' => ['sometimes', 'array'],
            'supported_languages.*' => ['string', 'max:10'],
            'registration_enabled' => ['sometimes', 'boolean'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'upload_limits' => ['sometimes', 'array'],
            'upload_limits.max_file_size_mb' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'upload_limits.max_files_per_upload' => ['nullable', 'integer', 'min:1', 'max:100'],
            'live_stream_limits' => ['sometimes', 'array'],
            'live_stream_limits.max_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'live_stream_limits.max_viewers' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'monetization' => ['sometimes', 'array'],
            'monetization.creator_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'monetization.platform_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'monetization.payout_minimum_naira' => ['nullable', 'integer', 'min:0'],
            'moderation_thresholds' => ['sometimes', 'array'],
            'moderation_thresholds.auto_hide_risk_level' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'moderation_thresholds.manual_review_risk_level' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'notification_defaults' => ['sometimes', 'array'],
            'notification_defaults.push' => ['nullable', 'boolean'],
            'notification_defaults.email' => ['nullable', 'boolean'],
            'notification_defaults.sms' => ['nullable', 'boolean'],
            'feature_flags' => ['sometimes', 'array'],
            'feature_flags.*' => ['nullable', 'boolean'],
            'oauth_providers' => ['sometimes', 'array'],
            'oauth_providers.*.enabled' => ['nullable', 'boolean'],
            'oauth_providers.*.client_id' => ['nullable', 'string', 'max:255'],
            'oauth_providers.*.client_secret' => ['nullable', 'string', 'max:255'],
            'payment_providers' => ['sometimes', 'array'],
            'payment_providers.*.enabled' => ['nullable', 'boolean'],
            'payment_providers.*.public_key' => ['nullable', 'string', 'max:255'],
            'payment_providers.*.secret_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
