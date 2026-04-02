<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SendEmailVerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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
            'identifier' => $user->email,
            'password' => 'NewPassword1',
        ])->assertOk()->assertJsonPath('message', trans('messages.auth.login_success'));
    }

    public function test_unverified_user_can_verify_email_with_a_four_digit_code_and_receive_a_token(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verify@example.com',
            'password' => 'Password1',
        ]);

        DB::table('email_verification_codes')->insert([
            'user_id' => $user->id,
            'email' => $user->email,
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/verify-email-code', [
            'email' => $user->email,
            'code' => '1234',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.email_verified'))
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.tokenType', 'Bearer');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('email_verification_codes', [
            'email' => $user->email,
        ]);
    }

    public function test_unverified_user_can_resend_a_verification_code(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'email' => 'verify@example.com',
        ]);

        $this->postJson('/api/v1/auth/resend-verification-code', [
            'email' => $user->email,
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.verification_code_resent'))
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.expiresInMinutes', 10);

        $this->assertDatabaseHas('email_verification_codes', [
            'email' => $user->email,
            'user_id' => $user->id,
        ]);

        Notification::assertSentTo($user, SendEmailVerificationCode::class);
    }

    public function test_oauth_endpoints_report_when_provider_credentials_are_missing(): void
    {
        $this->getJson('/api/v1/auth/oauth/google/redirect')
            ->assertStatus(503)
            ->assertJsonPath('data.provider', 'google')
            ->assertJsonPath('data.configured', false);

        $this->getJson('/api/v1/auth/oauth/facebook/callback')
            ->assertStatus(503)
            ->assertJsonPath('data.provider', 'facebook')
            ->assertJsonPath('data.configured', false);
    }

    public function test_google_oauth_redirects_to_provider_when_configured(): void
    {
        Config::set('services.google.client_id', 'google-client-id');
        Config::set('services.google.client_secret', 'google-client-secret');
        Config::set('services.google.redirect', 'http://localhost:8000/api/v1/auth/oauth/google/callback');

        $response = $this->get('/api/v1/auth/oauth/google/redirect');

        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('client_id=google-client-id', $location);
        $this->assertStringContainsString('response_type=code', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_google_oauth_callback_creates_user_and_redirects_back_to_frontend(): void
    {
        Config::set('app.frontend_url', 'http://localhost:5173');
        Config::set('services.google.client_id', 'google-client-id');
        Config::set('services.google.client_secret', 'google-client-secret');
        Config::set('services.google.redirect', 'http://localhost:8000/api/v1/auth/oauth/google/callback');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'token_type' => 'Bearer',
            ]),
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'id' => 'google-user-123',
                'name' => 'OAuth Example',
                'email' => 'oauth@example.com',
                'picture' => 'https://cdn.example.com/avatar.png',
            ]),
        ]);

        $redirect = $this->get('/api/v1/auth/oauth/google/redirect');

        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);

        $callback = $this->get('/api/v1/auth/oauth/google/callback?code=test-code&state='.$redirectQuery['state']);

        $callback->assertRedirect();

        $location = (string) $callback->headers->get('Location');

        $this->assertStringStartsWith('http://localhost:5173/auth/callback#', $location);

        parse_str((string) parse_url($location, PHP_URL_FRAGMENT), $fragment);

        $this->assertSame('google', $fragment['provider'] ?? null);
        $this->assertNotEmpty($fragment['token'] ?? null);

        $this->assertDatabaseHas('users', [
            'email' => 'oauth@example.com',
            'provider' => 'google',
            'provider_id' => 'google-user-123',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$fragment['token'])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'oauth@example.com');
    }
}