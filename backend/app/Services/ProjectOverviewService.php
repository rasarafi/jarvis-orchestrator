<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SessionRecap;
use Illuminate\Support\Carbon;

/**
 * Bundles the project-detail page's header stats into one payload so the
 * frontend gets them in a single request instead of four.
 */
class ProjectOverviewService
{
    public function __construct(
        private SessionAnalyticsService $sessionAnalyticsService,
    ) {}

    public function overview(Project $project): array
    {
        $dailyCosts = $this->sessionAnalyticsService->getProjectDailyCosts($project);

        $lastRecapAt = SessionRecap::query()
            ->where('project_id', $project->id)
            ->max('ended_at');

        return [
            'stats' => [
                'open_tasks' => $project->tasks()->open()->notArchived()->count(),
                'total_tasks' => $project->tasks()->notArchived()->count(),
                'cost_7d' => round(collect($dailyCosts)->sum('cost'), 4),
                'today_cost' => collect($dailyCosts)->last()['cost'],
                'last_recap_at' => $lastRecapAt ? Carbon::parse($lastRecapAt)->toISOString() : null,
                'last_session_at' => $project->last_session_at,
            ],
            'daily_costs' => $dailyCosts,
        ];
    }
}
