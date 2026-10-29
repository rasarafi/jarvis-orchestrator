<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyPlan;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct(
        private PlanService $planService,
    ) {}

    public function today(): JsonResponse
    {
        $plan = $this->planService->getTodaysPlan();

        return response()->json(['data' => $plan]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'must_do' => 'nullable|string',
            'should_do' => 'nullable|string',
            'could_do' => 'nullable|string',
            'alerts' => 'nullable|array',
            'raw_output' => 'nullable|string',
        ]);

        $plan = $this->planService->storeDailyPlan($validated);

        return response()->json(['data' => $plan], 201);
    }

    public function updateOutcome(Request $request, DailyPlan $plan): JsonResponse
    {
        $validated = $request->validate([
            'actual_outcome' => 'required|string',
        ]);

        $this->planService->updateActualOutcome($plan, $validated['actual_outcome']);

        return response()->json(['data' => $plan->fresh()]);
    }

    public function updateCompletion(Request $request, DailyPlan $plan): JsonResponse
    {
        $validated = $request->validate([
            'must_do_completed' => ['boolean', 'required_without_all:should_do_completed,could_do_completed'],
            'should_do_completed' => ['boolean', 'required_without_all:must_do_completed,could_do_completed'],
            'could_do_completed' => ['boolean', 'required_without_all:must_do_completed,should_do_completed'],
        ]);

        $this->planService->markPlanCompletion($plan, $validated);

        return response()->json(['data' => $plan->fresh()]);
    }

    public function streak(): JsonResponse
    {
        return response()->json([
            'streak' => $this->planService->calculateStreak(),
        ]);
    }

    public function recent(Request $request): JsonResponse
    {
        $days = $request->input('days', 7);
        $plans = $this->planService->getRecentPlans($days);

        return response()->json(['data' => $plans]);
    }
}
