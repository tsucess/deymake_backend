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
            ->assertJsonPath('message', 'Registration successful.')
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
            ->assertJsonPath('message', 'Login successful.')
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
            ->assertJsonPath('data.user.email', $user->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}