<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_endpoint_returns_successful_response(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'service',
                'version',
                'timestamp',
                'checks' => [
                    'database' => [
                        'status',
                        'message',
                    ],
                ],
            ])
            ->assertJson([
                'status' => 'ok',
                'version' => 'v1',
            ]);
    }
}
