<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Rise Network',
            'email' => 'rise@example.com',
            'password' => 'Password1',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.auth.registered'))
            ->assertJsonStructure([
                'message',
                'data' => ['user' => ['id', 'fullName', 'email', 'createdAt'], 'token', 'tokenType'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'rise@example.com',
            'name' => 'Rise Network',
        ]);
    }

    public function test_user_can_login_and_receive_a_token(): void
    {
        $user = User::factory()->create([
            'name' => 'Rise Network',
            'email' => 'rise@example.com',
            'password' => 'Password1',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.login_success'))
            ->assertJsonStructure([
                'message',
                'data' => ['user' => ['id', 'fullName', 'email', 'createdAt'], 'token', 'tokenType'],
            ]);
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
            'email' => 'rise@example.com',
            'password' => 'Password1',
        ]);

        $this->withHeaders(['X-Locale' => 'yo'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Password1',
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.login_success', [], 'yo'));

        $this->withHeaders(['X-Locale' => 'ha'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'WrongPassword1',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', trans('messages.auth.invalid_credentials', [], 'ha'))
            ->assertJsonPath('errors.email.0', trans('messages.auth.invalid_credentials_detail', [], 'ha'));

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