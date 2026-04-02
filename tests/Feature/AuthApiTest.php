<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SendEmailVerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_a_verification_code(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Rise Network',
            'username' => 'rise.network',
            'email' => 'rise@example.com',
            'password' => 'Password1',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.auth.verification_code_sent'))
            ->assertJsonPath('data.verification.required', true)
            ->assertJsonPath('data.verification.email', 'rise@example.com')
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => ['id', 'fullName', 'username', 'email', 'createdAt'],
                    'verification' => ['required', 'email', 'expiresInMinutes'],
                ],
            ]);

        $response->assertJsonMissingPath('data.token');

        $user = User::query()->where('email', 'rise@example.com')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'email' => 'rise@example.com',
            'name' => 'Rise Network',
            'username' => 'rise.network',
        ]);
        $this->assertDatabaseHas('email_verification_codes', [
            'email' => 'rise@example.com',
            'user_id' => $user->id,
        ]);

        Notification::assertSentTo($user, SendEmailVerificationCode::class);
    }

    public function test_user_can_login_and_receive_a_token(): void
    {
        $user = User::factory()->create([
            'name' => 'Rise Network',
            'username' => 'rise.network',
            'email' => 'rise@example.com',
            'password' => 'Password1',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->username,
            'password' => 'Password1',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.login_success'))
            ->assertJsonStructure([
                'message',
                'data' => ['user' => ['id', 'fullName', 'username', 'email', 'createdAt'], 'token', 'tokenType'],
            ]);

        $response->assertJsonPath('data.user.username', $user->username);
    }

    public function test_unverified_user_login_requires_email_verification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'name' => 'Rise Network',
            'username' => 'rise.network',
            'email' => 'rise@example.com',
            'password' => 'Password1',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => 'Password1',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('message', trans('messages.auth.verification_required'))
            ->assertJsonPath('data.verification.required', true)
            ->assertJsonPath('data.verification.email', $user->email)
            ->assertJsonPath('data.user.username', $user->username);

        $this->assertDatabaseHas('email_verification_codes', [
            'email' => $user->email,
            'user_id' => $user->id,
        ]);

        Notification::assertSentTo($user, SendEmailVerificationCode::class);
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.me_retrieved'))
            ->assertJsonPath('data.user.email', $user->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.logout_success'));

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_auth_endpoints_honor_locale_headers_for_messages(): void
    {
        $user = User::factory()->create([
            'name' => 'Rise Network',
            'username' => 'rise.network',
            'email' => 'rise@example.com',
            'password' => 'Password1',
        ]);

        $this->withHeaders(['X-Locale' => 'yo'])
            ->postJson('/api/v1/auth/login', [
                'identifier' => $user->email,
                'password' => 'Password1',
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.login_success', [], 'yo'));

        $this->withHeaders(['X-Locale' => 'ha'])
            ->postJson('/api/v1/auth/login', [
                'identifier' => $user->email,
                'password' => 'WrongPassword1',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', trans('messages.auth.invalid_credentials', [], 'ha'))
            ->assertJsonPath('errors.identifier.0', trans('messages.auth.invalid_credentials_detail', [], 'ha'));

        $forgotPassword = $this->withHeaders(['X-Locale' => 'ig'])
            ->postJson('/api/v1/auth/forgot-password', [
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.password_reset_token_generated', [], 'ig'));

        $this->withHeaders(['X-Locale' => 'ig'])
            ->postJson('/api/v1/auth/reset-password', [
                'email' => $user->email,
                'token' => $forgotPassword->json('data.resetToken'),
                'password' => 'NewPassword1',
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.password_reset_success', [], 'ig'));
    }

    public function test_oauth_configuration_errors_are_localized(): void
    {
        config()->set('services.google.client_id', '');
        config()->set('services.google.client_secret', '');
        config()->set('services.google.redirect', '');

        $this->withHeaders(['X-Locale' => 'yo'])
            ->getJson('/api/v1/auth/oauth/google/redirect')
            ->assertStatus(503)
            ->assertJsonPath('message', trans('messages.auth.oauth.provider_not_configured', ['provider' => 'Google'], 'yo'))
            ->assertJsonPath('data.provider', 'google')
            ->assertJsonPath('data.configured', false);
    }
}