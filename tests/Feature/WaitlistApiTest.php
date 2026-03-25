<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_status(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_waitlist_entry_can_be_created(): void
    {
        $response = $this->postJson('/api/v1/waitlist', [
            'firstName' => 'Rise Network',
            'email' => 'waitlist@example.com',
            'phone' => '+2348000000000',
            'country' => 'Nigeria',
            'describes' => 'Creator',
            'loveToSee' => 'Creator monetization tools',
            'agreed' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'You have been added to the waitlist.')
            ->assertJsonPath('data.waitlistEntry.email', 'waitlist@example.com')
            ->assertJsonPath('data.waitlistEntry.status', 'pending');

        $this->assertDatabaseHas('waitlist_entries', [
            'email' => 'waitlist@example.com',
            'full_name' => 'Rise Network',
            'country' => 'Nigeria',
            'status' => 'pending',
        ]);
    }

    public function test_waitlist_requires_unique_email(): void
    {
        $payload = [
            'firstName' => 'Rise Network',
            'email' => 'waitlist@example.com',
            'phone' => '+2348000000000',
            'country' => 'Nigeria',
            'describes' => 'Creator',
            'loveToSee' => 'Creator monetization tools',
            'agreed' => true,
        ];

        $this->postJson('/api/v1/waitlist', $payload)->assertCreated();

        $this->postJson('/api/v1/waitlist', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}