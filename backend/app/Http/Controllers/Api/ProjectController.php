<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityService;
use App\Services\ProjectOverviewService;
use App\Services\ProjectService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectService $projectService,
        private TaskService $taskService,
        private ActivityService $activityService,
    ) {}

    public function index(): JsonResponse
    {
        $projects = $this->projectService->getAllProjectsWithOpenTasks();

        return response()->json(['data' => $projects]);
    }

    public function show(string $slug): JsonResponse
    {
        $project = $this->projectService->findBySlug($slug);

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $tasks = $this->taskService->getOpenTasks($project);
        $activity = $this->activityService->getProjectActivity($project);

        return response()->json([
            'data' => $project,
            'tasks' => $tasks,
            'activity' => $activity,
        ]);
    }

    public function overview(string $slug, ProjectOverviewService $overviewService): JsonResponse
    {
        $project = $this->projectService->findBySlug($slug);

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        return response()->json(['data' => $overviewService->overview($project)]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $project = $this->projectService->findBySlug($slug);

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'nullable|string|in:active,paused,maintenance,archived',
            'current_focus' => 'nullable|string',
            'blockers' => 'nullable|string',
            'environments' => 'nullable|array',
            'environments.*' => 'string',
        ]);

        $project = $this->projectService->updateProject($project, $validated);

        return response()->json(['data' => $project]);
    }
}
