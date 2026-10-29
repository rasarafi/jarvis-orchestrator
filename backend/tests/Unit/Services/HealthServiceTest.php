<?php

namespace Tests\Unit\Services;

use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkSession;
use App\Services\HealthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private HealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HealthService::class);
    }

    public function test_must_do_completion_rate(): void
    {
        // 3 days with must_do completed, 2 without
        for ($i = 1; $i <= 3; $i++) {
            DailyPlan::factory()
                ->mustDoCompleted()
                ->forDate(now()->subDays($i)->toDateString())
                ->create();
        }
        for ($i = 4; $i <= 5; $i++) {
            DailyPlan::factory()
                ->forDate(now()->subDays($i)->toDateString())
                ->create();
        }

        $insights = $this->service->getWeeklyInsights();
        $mustLine = collect($insights)->first(fn ($i) => str_contains($i, 'Must Do'));

        $this->assertNotNull($mustLine);
        $this->assertStringContainsString('3/5', $mustLine);
        $this->assertStringContainsString('60%', $mustLine);
    }

    public function test_should_do_and_could_do_rates(): void
    {
        DailyPlan::factory()
            ->completed()
            ->forDate(now()->subDay()->toDateString())
            ->create();

        $insights = $this->service->getWeeklyInsights();
        $shouldLine = collect($insights)->first(fn ($i) => str_contains($i, 'Should Do'));
        $couldLine = collect($insights)->first(fn ($i) => str_contains($i, 'Could Do'));

        $this->assertNotNull($shouldLine);
        $this->assertStringContainsString('1/1', $shouldLine);
        $this->assertNotNull($couldLine);
        $this->assertStringContainsString('1/1', $couldLine);
    }

    public function test_task_throughput(): void
    {
        $project = Project::factory()->create();

        // 3 completed this week
        Task::factory()->count(3)->done()->create([
            'project_id' => $project->id,
            'completed_at' => now()->subDay(),
        ]);

        // 2 created this week
        Task::factory()->count(2)->create(['project_id' => $project->id]);

        $insights = $this->service->getWeeklyInsights();
        $throughputLine = collect($insights)->first(fn ($i) => str_contains($i, 'throughput'));

        $this->assertNotNull($throughputLine);
        $this->assertStringContainsString('3 completed', $throughputLine);
        // created count includes done tasks too (5 total created this week)
        $this->assertStringContainsString('5 created', $throughputLine);
    }

    public function test_avg_task_age_at_completion(): void
    {
        $project = Project::factory()->create();

        // Task created 5 days ago, completed today
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'done',
            'created_at' => now()->subDays(5),
            'completed_at' => now(),
        ]);

        $insights = $this->service->getWeeklyInsights();
        $ageLine = collect($insights)->first(fn ($i) => str_contains($i, 'Avg task age'));

        $this->assertNotNull($ageLine);
        $this->assertStringContainsString('5', $ageLine);
    }

    public function test_aging_tasks_detected(): void
    {
        $project = Project::factory()->create();

        // 2 open tasks older than 14 days
        Task::factory()->count(2)->create([
            'project_id' => $project->id,
            'status' => 'todo',
            'created_at' => now()->subDays(20),
        ]);

        $insights = $this->service->getWeeklyInsights();
        $agingLine = collect($insights)->first(fn ($i) => str_contains($i, 'Aging'));

        $this->assertNotNull($agingLine);
        $this->assertStringContainsString('2 open tasks', $agingLine);
    }

    public function test_no_aging_tasks_when_all_fresh(): void
    {
        $project = Project::factory()->create();
        Task::factory()->create([
            'project_id' => $project->id,
            'created_at' => now()->subDays(3),
        ]);

        $insights = $this->service->getWeeklyInsights();
        $agingLine = collect($insights)->first(fn ($i) => str_contains($i, 'Aging'));

        $this->assertNull($agingLine);
    }

    public function test_empty_db_returns_throughput_only(): void
    {
        $insights = $this->service->getWeeklyInsights();

        // Should still return throughput line (0 completed, 0 created)
        $throughputLine = collect($insights)->first(fn ($i) => str_contains($i, 'throughput'));
        $this->assertNotNull($throughputLine);
        $this->assertStringContainsString('0 completed', $throughputLine);

        // No plan lines
        $mustLine = collect($insights)->first(fn ($i) => str_contains($i, 'Must Do'));
        $this->assertNull($mustLine);
    }

    // --- Health Warnings tests ---

    public function test_long_days_warning_triggers_at_3_days(): void
    {
        $project = Project::factory()->create();

        // 3 days with >8h sessions
        for ($i = 1; $i <= 3; $i++) {
            WorkSession::factory()->create([
                'project_id' => $project->id,
                'status' => 'completed',
                'started_at' => now()->subDays($i)->setTime(8, 0),
                'ended_at' => now()->subDays($i)->setTime(17, 0), // 9 hours
            ]);
        }

        $warnings = $this->service->getHealthWarnings();
        $longDay = collect($warnings)->first(fn ($w) => str_contains($w, 'Long days'));

        $this->assertNotNull($longDay);
        $this->assertStringContainsString('3 days', $longDay);
    }

    public function test_long_days_no_warning_under_threshold(): void
    {
        $project = Project::factory()->create();

        // 2 long days (under threshold of 3)
        for ($i = 1; $i <= 2; $i++) {
            WorkSession::factory()->create([
                'project_id' => $project->id,
                'status' => 'completed',
                'started_at' => now()->subDays($i)->setTime(8, 0),
                'ended_at' => now()->subDays($i)->setTime(17, 0),
            ]);
        }

        $warnings = $this->service->getHealthWarnings();
        $longDay = collect($warnings)->first(fn ($w) => str_contains($w, 'Long days'));

        $this->assertNull($longDay);
    }

    public function test_no_rest_days_warning(): void
    {
        $project = Project::factory()->create();

        // Sessions on every day for last 7 days
        for ($i = 0; $i < 7; $i++) {
            WorkSession::factory()->create([
                'project_id' => $project->id,
                'started_at' => now()->subDays($i)->setTime(10, 0),
                'ended_at' => now()->subDays($i)->setTime(11, 0),
            ]);
        }

        $warnings = $this->service->getHealthWarnings();
        $noRest = collect($warnings)->first(fn ($w) => str_contains($w, 'No rest'));

        $this->assertNotNull($noRest);
    }

    public function test_overcommitment_warning(): void
    {
        $project = Project::factory()->create();
        Task::factory()->count(21)->create([
            'project_id' => $project->id,
            'status' => 'todo',
        ]);

        $warnings = $this->service->getHealthWarnings();
        $overcommit = collect($warnings)->first(fn ($w) => str_contains($w, 'Overcommitment'));

        $this->assertNotNull($overcommit);
        $this->assertStringContainsString('21', $overcommit);
    }

    public function test_overcommitment_no_warning_at_threshold(): void
    {
        $project = Project::factory()->create();
        Task::factory()->count(20)->create([
            'project_id' => $project->id,
            'status' => 'todo',
        ]);

        $warnings = $this->service->getHealthWarnings();
        $overcommit = collect($warnings)->first(fn ($w) => str_contains($w, 'Overcommitment'));

        $this->assertNull($overcommit);
    }

    public function test_streak_at_risk_warning(): void
    {
        // Build a 3-day streak before yesterday, then yesterday broke it
        for ($i = 2; $i <= 4; $i++) {
            DailyPlan::factory()
                ->mustDoCompleted()
                ->forDate(now()->subDays($i)->toDateString())
                ->create();
        }
        // Yesterday: plan exists but must_do NOT completed
        DailyPlan::factory()
            ->forDate(Carbon::yesterday()->toDateString())
            ->create(['must_do_completed' => false]);

        $warnings = $this->service->getHealthWarnings();
        $streak = collect($warnings)->first(fn ($w) => str_contains($w, 'Streak at risk'));

        $this->assertNotNull($streak);
        $this->assertStringContainsString('3-day streak broken', $streak);
    }

    public function test_streak_at_risk_no_warning_for_short_streak(): void
    {
        // 2-day streak before yesterday (below threshold of 3)
        for ($i = 2; $i <= 3; $i++) {
            DailyPlan::factory()
                ->mustDoCompleted()
                ->forDate(now()->subDays($i)->toDateString())
                ->create();
        }
        // Yesterday broke it
        DailyPlan::factory()
            ->forDate(Carbon::yesterday()->toDateString())
            ->create(['must_do_completed' => false]);

        $warnings = $this->service->getHealthWarnings();
        $streak = collect($warnings)->first(fn ($w) => str_contains($w, 'Streak at risk'));

        $this->assertNull($streak);
    }

    public function test_no_warnings_when_healthy(): void
    {
        // Just a single short session and few tasks
        $project = Project::factory()->create();
        Task::factory()->count(3)->create(['project_id' => $project->id]);
        WorkSession::factory()->create([
            'project_id' => $project->id,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
        ]);

        $warnings = $this->service->getHealthWarnings();

        $this->assertEmpty($warnings);
    }

    public function test_empty_db_returns_no_warnings(): void
    {
        $warnings = $this->service->getHealthWarnings();

        $this->assertEmpty($warnings);
    }
}
