<?php

namespace Tests\Feature;

use App\Services\SessionScannerService;
use Mockery;
use Tests\TestCase;

class TriggerSessionScanTest extends TestCase
{
    public function test_command_runs_full_scan(): void
    {
        $mock = Mockery::mock(SessionScannerService::class);
        $mock->shouldReceive('scanAll')
            ->once()
            ->andReturn(['added' => 5, 'updated' => 2, 'skipped' => 10, 'removed' => 0]);

        $this->app->instance(SessionScannerService::class, $mock);

        $this->artisan('jarvis:scan-sessions')
            ->expectsOutputToContain('Added: 5, Updated: 2, Skipped: 10, Removed: 0')
            ->assertSuccessful();
    }

    public function test_command_scans_specific_project(): void
    {
        $mock = Mockery::mock(SessionScannerService::class);
        $mock->shouldReceive('scanProject')
            ->with('-home-dev-projects-foo')
            ->once()
            ->andReturn(['added' => 3, 'updated' => 0, 'skipped' => 1, 'removed' => 0]);

        $this->app->instance(SessionScannerService::class, $mock);

        $this->artisan('jarvis:scan-sessions', ['--project' => '-home-dev-projects-foo'])
            ->expectsOutputToContain('Scanning project: -home-dev-projects-foo')
            ->expectsOutputToContain('Added: 3')
            ->assertSuccessful();
    }

    public function test_command_handles_scan_failure(): void
    {
        $mock = Mockery::mock(SessionScannerService::class);
        $mock->shouldReceive('scanAll')
            ->once()
            ->andThrow(new \RuntimeException('Disk read error'));

        $this->app->instance(SessionScannerService::class, $mock);

        $this->artisan('jarvis:scan-sessions')
            ->expectsOutputToContain('Scan failed: Disk read error')
            ->assertFailed();
    }
}
