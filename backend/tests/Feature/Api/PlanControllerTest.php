<?php

namespace Tests\Feature\Api;

use App\Models\DailyPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_returns_todays_plan(): void
    {
        $plan = DailyPlan::factory()->forDate(now()->toDateString())->create();

        $response = $this->getJson('/api/plans/today');

        $response->assertOk()
            ->assertJsonPath('data.id', $plan->id);
    }

    public function test_today_returns_null_when_no_plan(): void
    {
        $response = $this->getJson('/api/plans/today');

        $response->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_store_creates_plan(): void
    {
        $response = $this->postJson('/api/plans', [
            'date' => now()->toDateString(),
            'must_do' => 'Fix auth bug',
            'should_do' => 'Review PRs',
            'could_do' => 'Refactor tests',
            'raw_output' => 'Morning briefing output',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('daily_plans', ['must_do' => 'Fix auth bug']);
    }

    public function test_streak_returns_count(): void
    {
        $response = $this->getJson('/api/plans/streak');

        $response->assertOk()
            ->assertJsonStructure(['streak']);
    }

    public function test_recent_returns_last_n_days(): void
    {
        DailyPlan::factory()->forDate(now()->subDays(1)->toDateString())->create();
        DailyPlan::factory()->forDate(now()->subDays(2)->toDateString())->create();

        $response = $this->getJson('/api/plans/recent?days=7');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_update_outcome(): void
    {
        $plan = DailyPlan::factory()->forDate(now()->toDateString())->create();

        $response = $this->patchJson("/api/plans/{$plan->id}/outcome", [
            'actual_outcome' => 'Completed auth fix and reviews',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('daily_plans', [
            'id' => $plan->id,
            'actual_outcome' => 'Completed auth fix and reviews',
        ]);
    }

    public function test_update_completion_sets_booleans(): void
    {
        $plan = DailyPlan::factory()->forDate(now()->toDateString())->create(['must_do' => 'Ship it']);

        $this->patchJson("/api/plans/{$plan->id}/completion", ['must_do_completed' => true])
            ->assertOk();

        $fresh = $plan->fresh();
        $this->assertTrue($fresh->must_do_completed);
        $this->assertFalse($fresh->should_do_completed);
    }

    public function test_update_completion_requires_at_least_one_field(): void
    {
        $plan = DailyPlan::factory()->forDate(now()->toDateString())->create(['must_do' => 'Ship it']);

        $this->patchJson("/api/plans/{$plan->id}/completion", [])
            ->assertUnprocessable();
    }

    public function test_update_completion_ignores_unknown_fields(): void
    {
        $plan = DailyPlan::factory()->forDate(now()->toDateString())->create(['must_do' => 'Ship it']);

        $this->patchJson("/api/plans/{$plan->id}/completion", [
            'must_do_completed' => true,
            'actual_outcome' => 'sneaky',
        ])->assertOk();

        $this->assertNull($plan->fresh()->actual_outcome);
    }
}
