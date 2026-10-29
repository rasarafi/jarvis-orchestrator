<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_costs_returns_summary(): void
    {
        $response = $this->getJson('/api/analytics/costs');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_health_returns_warnings_and_insights(): void
    {
        $response = $this->getJson('/api/analytics/health');

        $response->assertOk()
            ->assertJsonStructure(['warnings', 'insights']);
    }

    public function test_sessions_returns_breakdown(): void
    {
        $response = $this->getJson('/api/analytics/sessions');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }
}
