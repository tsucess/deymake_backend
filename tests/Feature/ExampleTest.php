<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_returns_api_metadata_response(): void
    {
        $response = $this->getJson('/');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'DeyMake API')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.health', url('/api/v1/health'));
    }
}
