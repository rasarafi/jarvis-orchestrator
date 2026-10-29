<?php

namespace App\Http\Controllers\Api;

use App\Enums\RescheduleAction;
use App\Http\Controllers\Controller;
use App\Models\ScheduledTask;
use App\Models\Task;
use App\Services\ReschedulingService;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ScheduleController extends Controller
{
    public function __construct(
        private ScheduleService $scheduleService,
        private ReschedulingService $reschedulingService,
    ) {}

    public function schedule(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'time_block_id' => 'nullable|uuid|exists:time_blocks,id',
            'scheduled_date' => 'required|date',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'position' => 'nullable|integer|min:0',
        ]);

        return response()->json(
            ['data' => $this->scheduleService->schedule($task, $data)],
            Response::HTTP_CREATED,
        );
    }

    public function reschedule(Request $request, ScheduledTask $scheduledTask): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:push_1hr,push_evening,push_tomorrow,skip,to_backlog,to_time'],
            'start_time' => 'required_if:action,to_time|date_format:H:i:s',
            'end_time' => 'required_if:action,to_time|date_format:H:i:s|after:start_time',
        ]);

        $action = RescheduleAction::from($validated['action']);
        $payload = array_intersect_key($validated, array_flip(['start_time', 'end_time']));

        $result = $this->reschedulingService->reschedule($scheduledTask, $action, $payload);

        return response()->json(['data' => $result]);
    }
}
