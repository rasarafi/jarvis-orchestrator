<?php

namespace Tests\Feature\Api;

use App\Services\SessionScannerService;
use Mockery;
use Tests\TestCase;

class SessionControllerTest extends TestCase
{
    public function test_scan_calls_scanner_service(): void
    {
        $mock = Mockery::mock(SessionScannerService::class);
        $mock->shouldReceive('scanAll')
            ->once()
            ->andReturn(['added' => 3, 'updated' => 1, 'skipped' => 5, 'removed' => 0]);

        $this->app->instance(SessionScannerService::class, $mock);

        $response = $this->postJson('/api/sessions/scan');

        $response->assertOk()
            ->assertJson([
                'status' => 'completed',
                'added' => 3,
                'updated' => 1,
                'skipped' => 5,
                'removed' => 0,
            ]);
    }

    public function test_scan_with_project_parameter(): void
    {
        $mock = Mockery::mock(SessionScannerService::class);
        $mock->shouldReceive('scanProject')
            ->with('-home-dev-projects-foo')
            ->once()
            ->andReturn(['added' => 2, 'updated' => 0, 'skipped' => 3, 'removed' => 0]);

        $this->app->instance(SessionScannerService::class, $mock);

        $response = $this->postJson('/api/sessions/scan', ['project' => '-home-dev-projects-foo']);

        $response->assertOk()
            ->assertJson([
                'status' => 'completed',
                'added' => 2,
            ]);
    }

    public function test_scan_returns_500_on_failure(): void
    {
        $mock = Mockery::mock(SessionScannerService::class);
        $mock->shouldReceive('scanAll')
            ->once()
            ->andThrow(new \RuntimeException('Something went wrong'));

        $this->app->instance(SessionScannerService::class, $mock);

        $response = $this->postJson('/api/sessions/scan');

        $response->assertStatus(500)
            ->assertJson([
                'status' => 'failed',
                'error' => 'Something went wrong',
            ]);
    }

    public function test_recent_returns_recent_sessions(): void
    {
        // This queries the sessions schema directly — may return empty if no data
        $response = $this->getJson('/api/sessions/recent');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_search_returns_matching_sessions(): void
    {
        $response = $this->getJson('/api/sessions/search?q=test');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }
}
