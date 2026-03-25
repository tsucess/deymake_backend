<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthExtensionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_and_complete_password_reset(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'OldPassword1',
        ]);

        $forgot = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $forgot->assertOk()->assertJsonPath('data.email', $user->email);

        $token = $forgot->json('data.resetToken');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword1',
        ])->assertOk()->assertJsonPath('message', 'Password reset successful.');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewPassword1',
        ])->assertOk()->assertJsonPath('message', 'Login successful.');
    }

    public function test_oauth_placeholder_endpoints_are_exposed(): void
    {
        $this->getJson('/api/v1/auth/oauth/google/redirect')
            ->assertStatus(501)
            ->assertJsonPath('data.provider', 'google')
            ->assertJsonPath('data.configured', false);

        $this->getJson('/api/v1/auth/oauth/facebook/callback')
            ->assertStatus(501)
            ->assertJsonPath('data.provider', 'facebook')
            ->assertJsonPath('data.configured', false);
    }
}