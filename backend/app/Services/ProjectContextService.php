<?php

namespace App\Services;

use App\Enums\ContextSection;
use App\Models\Project;
use App\Models\ProjectContext;
use Illuminate\Support\Collection;

class ProjectContextService
{
    /**
     * Get all context sections for a project.
     */
    public function getProjectContext(Project $project): Collection
    {
        return $project->context()->get();
    }

    /**
     * Get a single section for a project. Returns null if not yet populated.
     */
    public function getSection(Project $project, ContextSection $section): ?ProjectContext
    {
        return $project->context()->where('section', $section)->first();
    }

    /**
     * Get one section across all projects (e.g. all todos).
     */
    public function getCrossProject(ContextSection $section): Collection
    {
        return ProjectContext::where('section', $section)
            ->with('project:id,slug,name')
            ->get();
    }

    /**
     * Upsert a section — create if missing, update if exists.
     */
    public function updateSection(Project $project, ContextSection $section, string $content): ProjectContext
    {
        return ProjectContext::updateOrCreate(
            [
                'project_id' => $project->id,
                'section' => $section,
            ],
            [
                'content' => $content,
            ]
        );
    }

    /**
     * Get projects with stale or missing context.
     * Returns projects whose context hasn't been updated in $days days,
     * or that have no context at all.
     */
    public function getStaleProjects(int $days = 14): Collection
    {
        $threshold = now()->subDays($days);

        return Project::where('status', 'active')
            ->where(function ($query) use ($threshold) {
                $query->whereDoesntHave('context')
                    ->orWhereHas('context', function ($q) use ($threshold) {
                        $q->where('updated_at', '<', $threshold);
                    }, '=', $query->getModel()->context()->count());
            })
            ->get();
    }
}
