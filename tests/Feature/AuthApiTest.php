<?php

namespace Tests\Feature;

use App\Contracts\SmsSender;
use App\Models\User;
use App\Notifications\SendEmailVerificationCode;
use App\Notifications\SendPasswordResetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Mockery\MockInterface;
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

    public function test_suspended_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'username' => 'suspended.user',
            'password' => 'Password1',
            'account_status' => 'suspended',
            'suspended_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => 'Password1',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.auth.account_suspended'))
            ->assertJsonPath('errors.account.0', trans('messages.auth.account_suspended_detail'));
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
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.auth.verification_required'));

        $response->assertJsonMissingPath('data.token');
        $response->assertJsonMissingPath('data.verification');

        $this->assertDatabaseMissing('email_verification_codes', [
            'email' => $user->email,
        ]);

        Notification::assertNothingSent();
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

    public function test_authenticated_requests_record_last_user_activity_from_header(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');

        $user = User::factory()->create([
            'is_online' => false,
            'last_active_at' => null,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        $activityAt = now()->subMinutes(2)->toIso8601String();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-User-Activity-At' => $activityAt,
        ])->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.isOnline', true);

        $freshUser = $user->fresh();

        $this->assertTrue($freshUser->is_online);
        $this->assertTrue($freshUser->last_active_at?->equalTo(Carbon::parse($activityAt)));

        Carbon::setTestNow();
    }

    public function test_auth_endpoints_honor_locale_headers_for_messages(): void
    {
        Notification::fake();

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

        $this->withHeaders(['X-Locale' => 'ig'])
            ->postJson('/api/v1/auth/forgot-password', [
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.password_reset_link_sent', [], 'ig'));

        $token = null;

        Notification::assertSentTo(
            $user,
            SendPasswordResetLink::class,
            function ($notification) use (&$token) {
                $token = $notification->token;

                return true;
            }
        );

        $this->withHeaders(['X-Locale' => 'ig'])
            ->postJson('/api/v1/auth/reset-password', [
                'email' => $user->email,
                'token' => $token,
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

    public function test_user_can_request_a_phone_verification_code(): void
    {
        $this->mock(SmsSender::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once();
        });

        $response = $this->postJson('/api/v1/auth/send-phone-code', [
            'phone' => '+2348012345678',
            'countryCode' => 'NG +234',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.phone_code_sent'))
            ->assertJsonPath('data.phone', '+2348012345678');

        $this->assertDatabaseHas('phone_verification_codes', [
            'phone' => '+2348012345678',
        ]);
    }

    public function test_user_can_register_with_phone_after_verifying_code(): void
    {
        $this->mock(SmsSender::class)->shouldReceive('send')->zeroOrMoreTimes();

        DB::table('phone_verification_codes')->insert([
            'phone' => '+2348012345678',
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/register-with-phone', [
            'fullName' => 'Rise Network',
            'username' => 'rise.phone',
            'phone' => '+2348012345678',
            'countryCode' => 'NG +234',
            'code' => '1234',
            'password' => 'Password1',
            'dateOfBirth' => '1995-05-20',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.phone', '+2348012345678')
            ->assertJsonPath('data.user.countryCode', 'NG +234')
            ->assertJsonPath('data.user.dateOfBirth', '1995-05-20')
            ->assertJsonStructure(['data' => ['user', 'token', 'tokenType']]);

        $this->assertDatabaseHas('users', [
            'phone' => '+2348012345678',
            'username' => 'rise.phone',
            'country_code' => 'NG +234',
        ]);
        $this->assertDatabaseMissing('phone_verification_codes', [
            'phone' => '+2348012345678',
        ]);
    }

    public function test_registering_with_invalid_phone_code_fails(): void
    {
        DB::table('phone_verification_codes')->insert([
            'phone' => '+2348012345678',
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/register-with-phone', [
            'fullName' => 'Rise Network',
            'username' => 'rise.phone',
            'phone' => '+2348012345678',
            'countryCode' => 'NG +234',
            'code' => '9999',
            'password' => 'Password1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('messages.auth.phone_code_invalid'));

        $this->assertDatabaseMissing('users', ['phone' => '+2348012345678']);
    }

    public function test_registering_with_expired_phone_code_fails(): void
    {
        DB::table('phone_verification_codes')->insert([
            'phone' => '+2348012345678',
            'code' => '1234',
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        $this->postJson('/api/v1/auth/register-with-phone', [
            'fullName' => 'Rise Network',
            'username' => 'rise.phone',
            'phone' => '+2348012345678',
            'countryCode' => 'NG +234',
            'code' => '1234',
            'password' => 'Password1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('messages.auth.phone_code_invalid'));
    }

    public function test_email_registration_persists_optional_date_of_birth(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Rise Network',
            'username' => 'rise.dob',
            'email' => 'rise.dob@example.com',
            'password' => 'Password1',
            'dateOfBirth' => '2000-01-15',
        ])->assertCreated();

        $user = User::query()->where('email', 'rise.dob@example.com')->firstOrFail();
        $this->assertSame('2000-01-15', $user->date_of_birth->toDateString());
    }

    public function test_login_returns_reason_code_for_unverified_email(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => 'Password1',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => 'Password1',
        ])
            ->assertForbidden()
            ->assertJsonPath('errors.reason.0', 'email_not_verified')
            ->assertJsonPath('errors.email.0', $user->email);
    }

    public function test_login_returns_reason_code_for_suspended_account(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended-reason@example.com',
            'password' => 'Password1',
            'account_status' => 'suspended',
            'suspended_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => 'Password1',
        ])
            ->assertForbidden()
            ->assertJsonPath('errors.reason.0', 'account_suspended');
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        RateLimiter::clear('login:ip:127.0.0.1');
        RateLimiter::clear('login:id:throttle@example.com');

        User::factory()->create([
            'email' => 'throttle@example.com',
            'password' => 'Password1',
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => 'throttle@example.com',
                'password' => 'WrongPassword1',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'throttle@example.com',
            'password' => 'Password1',
        ])
            ->assertStatus(429)
            ->assertJsonPath('errors.reason.0', 'too_many_attempts')
            ->assertHeader('Retry-After');
    }

    public function test_login_success_clears_stale_email_verification_code(): void
    {
        $user = User::factory()->create([
            'email' => 'stale@example.com',
            'password' => 'Password1',
        ]);

        DB::table('email_verification_codes')->insert([
            'user_id' => $user->id,
            'email' => $user->email,
            'code' => '9999',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => 'Password1',
        ])->assertOk();

        $this->assertDatabaseMissing('email_verification_codes', [
            'email' => $user->email,
        ]);
    }

    public function test_user_can_request_a_phone_login_code(): void
    {
        $this->mock(SmsSender::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once();
        });

        User::factory()->create([
            'phone' => '+2348011112222',
            'phone_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/send-phone-login-code', [
            'phone' => '+2348011112222',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.phone_login_code_sent'))
            ->assertJsonPath('data.phone', '+2348011112222');

        $this->assertDatabaseHas('phone_verification_codes', [
            'phone' => '+2348011112222',
            'purpose' => 'login',
        ]);
    }

    public function test_phone_login_code_request_returns_generic_response_for_unknown_phone(): void
    {
        $this->mock(SmsSender::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });

        $this->postJson('/api/v1/auth/send-phone-login-code', [
            'phone' => '+2340000000000',
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '+2340000000000');

        $this->assertDatabaseMissing('phone_verification_codes', [
            'phone' => '+2340000000000',
            'purpose' => 'login',
        ]);
    }

    public function test_user_can_login_with_phone_and_receive_a_token(): void
    {
        $user = User::factory()->create([
            'phone' => '+2348022223333',
            'phone_verified_at' => now(),
        ]);

        DB::table('phone_verification_codes')->insert([
            'phone' => '+2348022223333',
            'purpose' => 'login',
            'code' => '4321',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login-with-phone', [
            'phone' => '+2348022223333',
            'code' => '4321',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.auth.login_success'))
            ->assertJsonPath('data.user.phone', '+2348022223333')
            ->assertJsonStructure(['data' => ['user', 'token', 'tokenType']]);

        $this->assertDatabaseMissing('phone_verification_codes', [
            'phone' => '+2348022223333',
            'purpose' => 'login',
        ]);

        $this->assertNotNull($user->fresh()->last_active_at);
    }

    public function test_phone_login_rejects_invalid_code(): void
    {
        User::factory()->create([
            'phone' => '+2348033334444',
            'phone_verified_at' => now(),
        ]);

        DB::table('phone_verification_codes')->insert([
            'phone' => '+2348033334444',
            'purpose' => 'login',
            'code' => '4321',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login-with-phone', [
            'phone' => '+2348033334444',
            'code' => '0000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'invalid_code');
    }

    public function test_user_can_login_with_phone_identifier_and_password(): void
    {
        User::factory()->create([
            'phone' => '+2348044445555',
            'phone_verified_at' => now(),
            'password' => 'Password1',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '+2348044445555',
            'password' => 'Password1',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.phone', '+2348044445555');
    }
}