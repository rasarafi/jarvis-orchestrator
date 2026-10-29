<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeBlock;
use App\Services\TimeBlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TimeBlockController extends Controller
{
    public function __construct(private TimeBlockService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => TimeBlock::ordered()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'kind' => 'required|in:work,personal',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'weekday_mask' => 'required|integer|min:0|max:127',
            'color_hint' => 'nullable|string|max:32',
            'display_order' => 'nullable|integer|min:0|max:255',
        ]);

        return response()->json(['data' => $this->service->createBlock($data)], Response::HTTP_CREATED);
    }

    public function update(Request $request, TimeBlock $timeBlock): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:64',
            'kind' => 'sometimes|in:work,personal',
            'start_time' => 'sometimes|date_format:H:i:s',
            'end_time' => 'sometimes|date_format:H:i:s',
            'weekday_mask' => 'sometimes|integer|min:0|max:127',
            'color_hint' => 'nullable|string|max:32',
            'display_order' => 'sometimes|integer|min:0|max:255',
        ]);

        return response()->json(['data' => $this->service->updateBlock($timeBlock, $data)]);
    }

    public function destroy(TimeBlock $timeBlock): Response
    {
        $this->service->deleteBlock($timeBlock);
        return response()->noContent();
    }
}
