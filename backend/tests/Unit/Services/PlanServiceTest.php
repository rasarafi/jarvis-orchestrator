<?php

namespace Tests\Unit\Services;

use App\Models\DailyPlan;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PlanService::class);
    }

    public function test_store_daily_plan_creates_record(): void
    {
        $plan = $this->service->storeDailyPlan([
            'date' => '2026-02-05',
            'must_do' => 'Ship dashboard',
            'should_do' => 'Write tests',
            'could_do' => 'Refactor auth',
            'raw_output' => 'Full output',
        ]);

        $this->assertDatabaseHas('daily_plans', [
            'date' => '2026-02-05',
            'must_do' => 'Ship dashboard',
        ]);
    }

    public function test_store_daily_plan_upserts_same_date(): void
    {
        $this->service->storeDailyPlan([
            'date' => '2026-02-05',
            'must_do' => 'Original',
            'should_do' => 'x',
            'could_do' => 'x',
            'raw_output' => 'x',
        ]);

        $this->service->storeDailyPlan([
            'date' => '2026-02-05',
            'must_do' => 'Updated',
            'should_do' => 'x',
            'could_do' => 'x',
            'raw_output' => 'x',
        ]);

        $this->assertEquals(1, DailyPlan::whereDate('date', '2026-02-05')->count());
        $this->assertEquals('Updated', DailyPlan::whereDate('date', '2026-02-05')->first()->must_do);
    }

    public function test_get_todays_plan_returns_correct_day(): void
    {
        DailyPlan::factory()->create(['date' => now()->toDateString(), 'must_do' => 'Today']);
        DailyPlan::factory()->forDate(now()->subDay()->toDateString())->create(['must_do' => 'Yesterday']);

        $plan = $this->service->getTodaysPlan();

        $this->assertNotNull($plan);
        $this->assertEquals('Today', $plan->must_do);
    }

    public function test_get_todays_plan_returns_null_when_missing(): void
    {
        $this->assertNull($this->service->getTodaysPlan());
    }

    public function test_update_actual_outcome(): void
    {
        $plan = DailyPlan::factory()->create();

        $this->service->updateActualOutcome($plan, 'Shipped everything!');

        $this->assertEquals('Shipped everything!', $plan->fresh()->actual_outcome);
    }

    public function test_get_recent_plans_respects_days(): void
    {
        DailyPlan::factory()->create(['date' => now()->toDateString()]);
        DailyPlan::factory()->forDate(now()->subDays(10)->toDateString())->create();

        $plans = $this->service->getRecentPlans(7);

        $this->assertCount(1, $plans);
    }

    public function test_store_weekly_review(): void
    {
        $review = $this->service->storeWeeklyReview([
            'week_start' => now()->startOfWeek()->toDateString(),
            'summary' => 'Good week',
            'client_digests' => ['Client A' => 'All good'],
        ]);

        $this->assertDatabaseHas('weekly_reviews', [
            'summary' => 'Good week',
        ]);
    }

    public function test_get_current_week_review(): void
    {
        $this->service->storeWeeklyReview([
            'week_start' => now()->startOfWeek()->toDateString(),
            'summary' => 'This week',
            'client_digests' => [],
        ]);

        $review = $this->service->getCurrentWeekReview();

        $this->assertNotNull($review);
        $this->assertEquals('This week', $review->summary);
    }

    public function test_calculate_streak_consecutive_days(): void
    {
        // 3 consecutive completed days before today
        DailyPlan::factory()->mustDoCompleted()->forDate(now()->subDays(1)->toDateString())->create();
        DailyPlan::factory()->mustDoCompleted()->forDate(now()->subDays(2)->toDateString())->create();
        DailyPlan::factory()->mustDoCompleted()->forDate(now()->subDays(3)->toDateString())->create();

        $this->assertEquals(3, $this->service->calculateStreak());
    }

    public function test_calculate_streak_gap_breaks_chain(): void
    {
        // Yesterday completed, day before NOT, day before that completed
        DailyPlan::factory()->mustDoCompleted()->forDate(now()->subDays(1)->toDateString())->create();
        DailyPlan::factory()->forDate(now()->subDays(2)->toDateString())->create(); // not completed
        DailyPlan::factory()->mustDoCompleted()->forDate(now()->subDays(3)->toDateString())->create();

        $this->assertEquals(1, $this->service->calculateStreak());
    }

    public function test_calculate_streak_zero_when_no_history(): void
    {
        $this->assertEquals(0, $this->service->calculateStreak());
    }

    public function test_calculate_streak_ignores_today(): void
    {
        // Only today completed — streak should be 0
        DailyPlan::factory()->mustDoCompleted()->forDate(now()->toDateString())->create();

        $this->assertEquals(0, $this->service->calculateStreak());
    }

    public function test_mark_plan_completion(): void
    {
        $plan = DailyPlan::factory()->forDate(now()->subDay()->toDateString())->create();

        $updated = $this->service->markPlanCompletion($plan, [
            'must_do_completed' => true,
            'should_do_completed' => true,
        ]);

        $this->assertTrue($updated->must_do_completed);
        $this->assertTrue($updated->should_do_completed);
        $this->assertFalse($updated->could_do_completed);
    }

    public function test_mark_plan_completion_ignores_extra_keys(): void
    {
        $plan = DailyPlan::factory()->forDate(now()->subDays(2)->toDateString())->create();

        $updated = $this->service->markPlanCompletion($plan, [
            'must_do_completed' => true,
            'bogus_field' => 'should be ignored',
        ]);

        $this->assertTrue($updated->must_do_completed);
    }
}
