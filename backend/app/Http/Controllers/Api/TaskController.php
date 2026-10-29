<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectGroup;
use App\Http\Concerns\ResolvesTaskActor;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class TaskController extends Controller
{
    use ResolvesTaskActor;

    public function __construct(
        private TaskService $taskService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'group' => ['nullable', new Enum(ProjectGroup::class)],
            'completed_within' => 'nullable|string|in:1d,7d,30d',
        ]);

        $filters = $request->only(['project', 'group', 'priority', 'status', 'completed_within']);
        $sort = $request->input('sort', 'priority');

        $tasks = $this->taskService->getFilteredBacklog($filters, $sort);

        return response()->json(['data' => $tasks]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|string|in:urgent,high,medium,low',
            'deadline' => 'nullable|date',
            'status' => 'nullable|string|in:todo,in_progress,blocked,review,done',
            'source' => 'nullable|string|in:jarvis,kira,remote',
            'parent_task_id' => 'nullable|uuid|exists:tasks,id',
            'start_date' => 'nullable|date',
            'estimate_minutes' => 'nullable|integer|min:1',
        ]);

        $project = isset($validated['project_id'])
            ? Project::findOrFail($validated['project_id'])
            : null;
        $task = $this->taskService->createTask($project, $validated, $this->resolveActor($request));

        return response()->json(['data' => $task->load('project')], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:todo,in_progress,blocked,review,done',
            'priority' => 'nullable|string|in:urgent,high,medium,low',
            'deadline' => 'nullable|date',
            'blocker_reason' => 'nullable|string',
            'parent_task_id' => 'nullable|uuid|exists:tasks,id',
            'start_date' => 'nullable|date',
            'estimate_minutes' => 'nullable|integer|min:1',
        ]);

        $task = $this->taskService->updateTask($task, $validated, $this->resolveActor($request));

        return response()->json(['data' => $task->load('project')]);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->taskService->deleteTask($task, $this->resolveActor($request));

        return response()->json(['message' => 'Task deleted']);
    }

    public function archive(Request $request, Task $task): JsonResponse
    {
        $task = $this->taskService->archive($task, $this->resolveActor($request));

        return response()->json(['data' => $task->load('project')]);
    }

    public function unarchive(Request $request, Task $task): JsonResponse
    {
        $task = $this->taskService->unarchive($task, $this->resolveActor($request));

        return response()->json(['data' => $task->load('project')]);
    }

    public function urgent(): JsonResponse
    {
        return response()->json([
            'urgent' => $this->taskService->getUrgentTasks(),
            'stale' => $this->taskService->getStaleTasks(),
        ]);
    }
}
