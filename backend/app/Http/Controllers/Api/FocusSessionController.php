<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FocusSession;
use App\Models\Task;
use App\Services\FocusSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FocusSessionController extends Controller
{
    public function __construct(private FocusSessionService $service) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'task_id' => 'required|uuid|exists:tasks,id',
        ]);

        $task = Task::findOrFail($data['task_id']);
        $session = $this->service->start($task);

        return response()->json(
            ['data' => $session],
            $session->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function heartbeat(Request $request, FocusSession $focusSession): JsonResponse
    {
        $data = $request->validate([
            'elapsed_seconds' => 'required|integer|min:0',
        ]);

        return response()->json([
            'data' => $this->service->heartbeat($focusSession, $data['elapsed_seconds']),
        ]);
    }

    public function end(Request $request, FocusSession $focusSession): JsonResponse
    {
        $data = $request->validate([
            'elapsed_seconds' => 'required|integer|min:0',
        ]);

        return response()->json([
            'data' => $this->service->end($focusSession, $data['elapsed_seconds']),
        ]);
    }
}
