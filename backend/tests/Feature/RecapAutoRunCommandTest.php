<?php // backend/tests/Feature/RecapAutoRunCommandTest.php
namespace Tests\Feature;

use App\Enums\RecapModel;
use App\Services\RecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RecapAutoRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_recaps_command_drains_pending_via_service(): void
    {
        $service = Mockery::mock(RecapService::class);
        $service->shouldReceive('run')
            ->once()
            ->with(RecapModel::HAIKU, null, null)
            ->andReturn(['processed' => 3, 'failed' => 0, 'remaining' => 0]);
        $this->app->instance(RecapService::class, $service);

        $this->artisan('jarvis:run-recaps')
            ->expectsOutputToContain('processed 3')
            ->assertExitCode(0);
    }

    public function test_run_recaps_command_reports_failures_without_erroring(): void
    {
        $service = Mockery::mock(RecapService::class);
        $service->shouldReceive('run')
            ->once()
            ->andReturn(['processed' => 2, 'failed' => 1, 'remaining' => 4]);
        $this->app->instance(RecapService::class, $service);

        $this->artisan('jarvis:run-recaps')
            ->expectsOutputToContain('1 failed')
            ->assertExitCode(0);
    }

    public function test_run_recaps_is_scheduled_every_fifteen_minutes(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'jarvis:run-recaps'));

        $this->assertCount(1, $events, 'jarvis:run-recaps should be scheduled exactly once');
        $this->assertSame('*/15 * * * *', $events->first()->expression);
    }
}
