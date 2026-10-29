<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\KiraSyncService;
use Illuminate\Http\JsonResponse;

class ProjectSyncController extends Controller
{
    public function __construct(
        private KiraSyncService $syncService,
    ) {}

    /**
     * POST /api/projects/{slug}/sync-tasks
     * Import tasks from the project's agent (Kira) and return sync results.
     */
    public function syncTasks(string $slug): JsonResponse
    {
        $project = Project::where('slug', $slug)->firstOrFail();

        $results = $this->syncService->importFromProject($project);

        if (isset($results['error'])) {
            return response()->json(['error' => $results['error']], 422);
        }

        if (config('services.jarvis_remote.auto_push') && empty($results['error'])) {
            // Run the push AFTER the response is sent so a slow/unreachable
            // remote never delays the Kira-import response to the caller.
            dispatch(function () {
                try {
                    app(\App\Services\RemoteSyncService::class)->push();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Auto-push after Kira sync failed: ' . $e->getMessage());
                }
            })->afterResponse();
        }

        return response()->json($results);
    }

    /**
     * GET /api/projects/{slug}/tasks
     * Export all open tasks for a project (for Kira to pull from).
     */
    public function exportTasks(string $slug): JsonResponse
    {
        $project = Project::where('slug', $slug)->firstOrFail();

        $tasks = $this->syncService->exportForProject($project);

        return response()->json(['data' => $tasks]);
    }
}
