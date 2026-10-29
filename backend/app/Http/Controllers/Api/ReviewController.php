<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(
        private PlanService $planService,
    ) {}

    public function current(): JsonResponse
    {
        $review = $this->planService->getCurrentWeekReview();

        return response()->json(['data' => $review]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'week_start' => 'required|date',
            'summary' => 'required|string',
            'client_digests' => 'nullable|array',
            'behavior_insights' => 'nullable|array',
            'next_week_plan' => 'nullable|string',
        ]);

        $review = $this->planService->storeWeeklyReview($validated);

        return response()->json(['data' => $review], 201);
    }
}
