<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProjectService
{
    public function createProject(array $data): Project
    {
        if (!isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        return Project::create($data);
    }

    public function updateProject(Project $project, array $data): Project
    {
        $project->update($data);
        return $project->fresh();
    }

    public function findByPath(string $path): ?Project
    {
        return Project::where('local_path', $path)->first();
    }

    public function findBySlug(string $slug): ?Project
    {
        return Project::where('slug', $slug)->first();
    }

    public function updateLastSession(Project $project): void
    {
        $project->update(['last_session_at' => now()]);
    }

    /**
     * Get all projects with open tasks
     * Uses model scopes (SSOT compliance)
     */
    public function getAllProjects(): Collection
    {
        return Project::with(['tasks' => function ($query) {
            $query->open();
        }])->orderBy('last_session_at', 'desc')->get();
    }

    /**
     * Get active projects
     */
    public function getActiveProjects(): Collection
    {
        return Project::where('status', ProjectStatus::ACTIVE->value)
            ->orderBy('last_session_at', 'desc')
            ->get();
    }

    /**
     * Get all projects with open tasks, ordered by priority
     * SINGLE SOURCE OF TRUTH for project status overview
     */
    public function getAllProjectsWithOpenTasks(): Collection
    {
        return Project::with(['tasks' => function ($query) {
            $query->open()
                  ->byPriority()
                  ->orderBy('deadline', 'asc');
        }])->orderBy('last_session_at', 'desc')->get();
    }

    /**
     * Get active projects with no activity for N+ days
     * SINGLE SOURCE OF TRUTH for dormant project detection
     */
    public function getDormantProjects(int $days = 10): Collection
    {
        return Project::dormant($days)
            ->orderBy('last_session_at', 'asc')
            ->get();
    }

    /**
     * Get project context with tasks and recent sessions
     * SINGLE SOURCE OF TRUTH for project context retrieval
     */
    public function getProjectContext(string $slug): ?Project
    {
        return Project::where('slug', $slug)
            ->with([
                'tasks' => function ($query) {
                    $query->open()
                          ->byPriority()
                          ->orderBy('deadline', 'asc')
                          ->limit(10);
                },
                'workSessions' => function ($query) {
                    $query->latest('started_at')->limit(5);
                }
            ])
            ->first();
    }
}
