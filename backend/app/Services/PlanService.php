<?php

namespace App\Services;

use App\Models\DailyPlan;
use App\Models\WeeklyReview;
use Carbon\Carbon;

class PlanService
{
    public function __construct(private MemoryService $memoryService)
    {
    }

    public function storeDailyPlan(array $data): DailyPlan
    {
        $date = $data['date'] ?? Carbon::today();

        $plan = DailyPlan::updateOrCreate(
            ['date' => $date],
            $data
        );

        if ($plan->actual_outcome) {
            try {
                $this->memoryService->indexDailyPlan($plan);
            } catch (\Throwable $e) {
                // Fire-and-forget
            }
        }

        return $plan;
    }

    public function getTodaysPlan(): ?DailyPlan
    {
        return DailyPlan::whereDate('date', Carbon::today())->first();
    }

    public function updateActualOutcome(DailyPlan $plan, string $outcome): DailyPlan
    {
        $plan->update(['actual_outcome' => $outcome]);
        return $plan->fresh();
    }

    public function storeWeeklyReview(array $data): WeeklyReview
    {
        $weekStart = $data['week_start'] ?? Carbon::now()->startOfWeek();

        $review = WeeklyReview::updateOrCreate(
            ['week_start' => $weekStart],
            $data
        );

        try {
            $this->memoryService->indexWeeklyReview($review);
        } catch (\Throwable $e) {
            // Fire-and-forget
        }

        return $review;
    }

    public function getCurrentWeekReview(): ?WeeklyReview
    {
        return WeeklyReview::where('week_start', Carbon::now()->startOfWeek())->first();
    }

    public function getRecentPlans(int $days = 7)
    {
        return DailyPlan::where('date', '>=', Carbon::today()->subDays($days))
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Count consecutive days (backwards from yesterday) where must_do was completed.
     * Today is excluded — it's still in progress.
     * SINGLE SOURCE OF TRUTH for streak calculation.
     */
    public function calculateStreak(): int
    {
        $streak = 0;
        $date = Carbon::yesterday();

        while (true) {
            $plan = DailyPlan::whereDate('date', $date)->first();

            if (!$plan || !$plan->must_do_completed) {
                break;
            }

            $streak++;
            $date = $date->subDay();
        }

        return $streak;
    }

    /**
     * Mark completion status on a daily plan.
     */
    public function markPlanCompletion(DailyPlan $plan, array $completions): DailyPlan
    {
        $plan->update(array_intersect_key($completions, array_flip([
            'must_do_completed',
            'should_do_completed',
            'could_do_completed',
        ])));

        return $plan->fresh();
    }
}
