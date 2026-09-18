<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicIdRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_resolves_route_binding_by_public_id_and_numeric_id(): void
    {
        $user = User::factory()->create(['username' => 'route.binding.user']);

        $this->assertIsString($user->public_id);
        $this->assertNotSame('', $user->public_id);
        $this->assertSame('public_id', $user->getRouteKeyName());

        $byPublicId = (new User())->resolveRouteBinding($user->public_id);
        $this->assertNotNull($byPublicId);
        $this->assertTrue($user->is($byPublicId));

        $byNumericId = (new User())->resolveRouteBinding((string) $user->id);
        $this->assertNotNull($byNumericId);
        $this->assertTrue($user->is($byNumericId));

        $byUsername = (new User())->resolveRouteBinding('@'.$user->username);
        $this->assertNotNull($byUsername);
        $this->assertTrue($user->is($byUsername));

        $this->assertNull((new User())->resolveRouteBinding('@nonexistentuser'));
        $this->assertNull((new User())->resolveRouteBinding('nonexistentid'));
    }

    public function test_challenge_resolves_route_binding_by_public_id_and_numeric_id(): void
    {
        $host = User::factory()->create(['username' => 'route.binding.host']);

        $challenge = Challenge::query()->create([
            'host_id' => $host->id,
            'title' => 'Route Binding Challenge',
            'summary' => 'Testing route model binding',
            'submission_starts_at' => now()->subDay(),
            'submission_ends_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        $this->assertIsString($challenge->public_id);
        $this->assertNotSame('', $challenge->public_id);
        $this->assertSame('public_id', $challenge->getRouteKeyName());

        $byPublicId = (new Challenge())->resolveRouteBinding($challenge->public_id);
        $this->assertNotNull($byPublicId);
        $this->assertTrue($challenge->is($byPublicId));

        $byNumericId = (new Challenge())->resolveRouteBinding((string) $challenge->id);
        $this->assertNotNull($byNumericId);
        $this->assertTrue($challenge->is($byNumericId));

        $this->assertNull((new Challenge())->resolveRouteBinding('nonexistentid'));
    }
}
