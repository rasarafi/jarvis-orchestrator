<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

class TaskEventController extends Controller
{
    public function index(Task $task): JsonResponse
    {
        $events = $task->events()->latest('created_at')->paginate(50);

        return response()->json($events);
    }
}
