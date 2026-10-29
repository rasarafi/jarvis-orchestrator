<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesTaskActor;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TaskDependencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskDependencyController extends Controller
{
    use ResolvesTaskActor;

    public function __construct(
        private TaskDependencyService $dependencyService,
    ) {}

    public function store(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'depends_on_task_id' => 'required|uuid|exists:tasks,id',
        ]);

        $dependsOn = Task::findOrFail($validated['depends_on_task_id']);
        $dependency = $this->dependencyService->add($task, $dependsOn, $this->resolveActor($request));

        return response()->json(['data' => $dependency], 201);
    }

    public function destroy(Request $request, Task $task, Task $dependsOn): JsonResponse
    {
        $this->dependencyService->remove($task, $dependsOn, $this->resolveActor($request));

        return response()->json(['message' => 'Dependency removed']);
    }
}
