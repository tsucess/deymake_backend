<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_non_admin_cannot_manage_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->patchJson('/api/v1/admin/settings', ['platform_name' => 'Blocked'])->assertForbidden();
    }

    public function test_admin_can_read_and_update_settings_without_leaking_secrets(): void
    {
        $this->admin();

        $response = $this->patchJson('/api/v1/admin/settings', [
            'platform_name' => 'DeyMake Studio',
            'registration_enabled' => true,
            'maintenance_mode' => false,
            'supported_languages' => ['en', 'fr', 'ig'],
            'upload_limits' => [
                'max_file_size_mb' => 150,
                'max_files_per_upload' => 5,
            ],
            'live_stream_limits' => [
                'max_duration_minutes' => 120,
                'max_viewers' => 2500,
            ],
            'monetization' => [
                'creator_commission_rate' => 12.5,
                'platform_commission_rate' => 7.5,
                'payout_minimum_naria' => 2000,
            ],
            'notification_defaults' => [
                'push' => true,
                'email' => true,
                'sms' => false,
            ],
            'oauth_providers' => [
                'google' => [
                    'enabled' => true,
                    'client_id' => 'google-client-id',
                    'client_secret' => 'google-client-secret',
                ],
            ],
            'payment_providers' => [
                'paystack' => [
                    'enabled' => true,
                    'public_key' => 'pk_test_123',
                    'secret_key' => 'sk_test_456',
                ],
            ],
        ]);

        $response->assertOk();

        $response->assertJsonPath('data.settings.platform_name', 'DeyMake Studio');
        $response->assertJsonPath('data.settings.registration_enabled', true);
        $response->assertJsonPath('data.settings.supported_languages.0', 'en');
        $response->assertJsonMissingPath('data.settings.oauth_providers.google.client_secret');
        $response->assertJsonMissingPath('data.settings.payment_providers.paystack.secret_key');

        $this->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.platform_name', 'DeyMake Studio');
    }
}
