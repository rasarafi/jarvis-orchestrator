<?php

namespace App\Services;

use App\Models\CcProject;
use App\Models\CcSession;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SessionAnalyticsService
{
    /**
     * Resolve JARVIS project → CC project IDs via path matching.
     * SSOT for the JARVIS↔CC project link. A project owns every CC project
     * whose decoded_path is its local_path or a subdirectory of it (sessions
     * started in e.g. backend/ get their own CC project row).
     */
    public function resolveCcProjectIds(Project $project): array
    {
        if (!$project->local_path) {
            return [];
        }

        return CcProject::query()
            ->pluck('decoded_path', 'id')
            ->filter(fn ($path) => $project->ownsPath($path))
            ->keys()
            ->all();
    }

    // =========================================================
    // Global Cost Analytics (all CC projects, not just onboarded)
    // =========================================================

    /**
     * Global cost summary across ALL CC session data.
     */
    public function getGlobalCostSummary(): array
    {
        $totals = CcSession::selectRaw('
            COALESCE(SUM(estimated_cost_usd), 0) as total_cost,
            COUNT(*) FILTER (WHERE kind = \'main\') as total_sessions
        ')->first();

        $today = CcSession::today()->selectRaw('
            COALESCE(SUM(estimated_cost_usd), 0) as cost,
            COUNT(*) FILTER (WHERE kind = \'main\') as sessions
        ')->first();

        $week = CcSession::thisWeek()->selectRaw('
            COALESCE(SUM(estimated_cost_usd), 0) as cost,
            COUNT(*) FILTER (WHERE kind = \'main\') as sessions
        ')->first();

        $topProjects = DB::table('sessions.sessions as s')
            ->join('sessions.projects as p', 's.project_id', '=', 'p.id')
            ->selectRaw('
                p.display_name,
                SUM(s.estimated_cost_usd) as cost,
                COUNT(s.id) FILTER (WHERE s.kind = \'main\') as sessions
            ')
            ->groupBy('p.display_name')
            ->orderByDesc('cost')
            ->limit(5)
            ->get();

        return [
            'total_cost' => (float) ($totals->total_cost ?? 0),
            'total_sessions' => (int) ($totals->total_sessions ?? 0),
            'today_cost' => (float) ($today->cost ?? 0),
            'today_sessions' => (int) ($today->sessions ?? 0),
            'week_cost' => (float) ($week->cost ?? 0),
            'week_sessions' => (int) ($week->sessions ?? 0),
            'top_projects' => $topProjects,
        ];
    }

    // =========================================================
    // Per-Project Analytics (onboarded JARVIS projects only)
    // =========================================================

    /**
     * Cost analytics for a specific JARVIS project.
     * Returns null if no CC project match found.
     */
    public function getProjectCostStats(Project $project): ?array
    {
        $ccProjectIds = $this->resolveCcProjectIds($project);
        if (!$ccProjectIds) {
            return null;
        }

        $totals = CcSession::forCcProject($ccProjectIds)->selectRaw('
            COALESCE(SUM(estimated_cost_usd), 0) as total_cost,
            COUNT(*) FILTER (WHERE kind = \'main\') as total_sessions
        ')->first();

        $week = CcSession::forCcProject($ccProjectIds)->thisWeek()->selectRaw('
            COALESCE(SUM(estimated_cost_usd), 0) as cost,
            COUNT(*) FILTER (WHERE kind = \'main\') as sessions
        ')->first();

        $today = CcSession::forCcProject($ccProjectIds)->today()->selectRaw('
            COALESCE(SUM(estimated_cost_usd), 0) as cost,
            COUNT(*) FILTER (WHERE kind = \'main\') as sessions
        ')->first();

        return [
            'total_cost' => (float) $totals->total_cost,
            'total_sessions' => (int) $totals->total_sessions,
            'week_cost' => (float) $week->cost,
            'week_sessions' => (int) $week->sessions,
            'today_cost' => (float) $today->cost,
            'today_sessions' => (int) $today->sessions,
        ];
    }

    /**
     * Recent CC sessions for a JARVIS project (for project-detail page).
     * Returns null if no CC project match found.
     */
    public function getProjectRecentSessions(Project $project, int $limit = 15): ?Collection
    {
        $ccProjectIds = $this->resolveCcProjectIds($project);
        if (!$ccProjectIds) {
            return null;
        }

        return CcSession::forCcProject($ccProjectIds)
            ->mainOnly()
            ->nonTrivial()
            ->latestActivity()
            ->limit($limit)
            ->get();
    }

    /**
     * Rolling per-day cost buckets for a JARVIS project (oldest first,
     * zero-filled so charts always get exactly $days points).
     */
    public function getProjectDailyCosts(Project $project, int $days = 7): array
    {
        $ccProjectIds = $this->resolveCcProjectIds($project);
        $start = now()->subDays($days - 1)->startOfDay();

        $byDate = $ccProjectIds
            ? CcSession::forCcProject($ccProjectIds)
                ->where('first_prompt_timestamp', '>=', $start)
                ->selectRaw('DATE(first_prompt_timestamp) as date, COALESCE(SUM(estimated_cost_usd), 0) as cost')
                ->groupBy('date')
                ->pluck('cost', 'date')
            : collect();

        return collect(range($days - 1, 0))
            ->map(fn (int $i) => now()->subDays($i)->toDateString())
            ->map(fn (string $date) => [
                'date' => $date,
                'cost' => (float) ($byDate[$date] ?? 0),
            ])
            ->values()
            ->all();
    }

    // =========================================================
    // Weekly Review Analytics
    // =========================================================

    /**
     * Weekly cost breakdown by project for the week view.
     */
    public function getWeeklyCostBreakdown(?Carbon $weekStart = null): Collection
    {
        $start = $weekStart ?? now()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $rows = DB::table('sessions.sessions as s')
            ->join('sessions.projects as p', 's.project_id', '=', 'p.id')
            ->whereBetween('s.first_prompt_timestamp', [$start, $end])
            ->selectRaw('
                p.display_name,
                p.decoded_path,
                SUM(s.estimated_cost_usd) as cost,
                COUNT(s.id) FILTER (WHERE s.kind = \'main\') as sessions,
                SUM(s.total_input_tokens + s.total_output_tokens) as total_tokens
            ')
            ->groupBy('p.display_name', 'p.decoded_path')
            ->orderByDesc('cost')
            ->get();

        $projects = Project::query()->whereNotNull('local_path')->get();

        // Re-merge rows sharing a display_name (grouping by decoded_path above
        // can split them); the group comes from whichever path matches a project.
        return $rows
            ->groupBy('display_name')
            ->map(fn ($group) => [
                'display_name' => $group->first()->display_name,
                'group_key' => $group
                    ->map(fn ($row) => Project::resolveFromCollection($projects, $row->decoded_path)?->group_key)
                    ->filter()
                    ->first(),
                'cost' => $group->sum('cost'),
                'sessions' => $group->sum('sessions'),
                'total_tokens' => $group->sum('total_tokens'),
            ])
            ->sortByDesc('cost')
            ->values();
    }

    /**
     * Today's (or a given date's) CC sessions as flat spans, mapped to Jarvis
     * projects via CcProject.decoded_path <-> Project.local_path. The "actual"
     * half of the planner's planned-vs-actual view.
     */
    public function getTodaySessionSpans(?string $date = null): array
    {
        $date = $date ?? now()->toDateString();

        $rows = DB::table('sessions.sessions as s')
            ->join('sessions.projects as p', 'p.id', '=', 's.project_id')
            ->whereDate('s.first_prompt_timestamp', $date)
            ->where('s.kind', 'main')
            ->where('s.message_count', '>', 3)
            ->orderBy('s.first_prompt_timestamp')
            ->get([
                's.first_prompt_timestamp',
                's.last_activity_timestamp',
                's.estimated_cost_usd',
                'p.display_name',
                'p.decoded_path',
            ]);

        $projects = Project::query()->whereNotNull('local_path')->get();

        return $rows->map(function ($row) use ($projects) {
            $start = Carbon::parse($row->first_prompt_timestamp);
            $end = Carbon::parse($row->last_activity_timestamp);

            return [
                'project_name' => $row->display_name,
                'project_slug' => Project::resolveFromCollection($projects, $row->decoded_path)?->slug,
                'started_at' => $start->toIso8601String(),
                'ended_at' => $end->toIso8601String(),
                'seconds' => max(0, $start->diffInSeconds($end)),
                'cost_usd' => (float) $row->estimated_cost_usd,
            ];
        })->values()->all();
    }

    /**
     * Week-over-week cost comparison.
     */
    public function getWeekOverWeekCost(): array
    {
        $lastWeekStart = now()->subWeek()->startOfWeek();
        $lastWeekEnd = $lastWeekStart->copy()->endOfWeek();

        $thisWeek = CcSession::thisWeek()
            ->selectRaw('COALESCE(SUM(estimated_cost_usd), 0) as cost')
            ->value('cost');

        $lastWeek = CcSession::between(
            $lastWeekStart->toDateTimeString(),
            $lastWeekEnd->toDateTimeString()
        )->selectRaw('COALESCE(SUM(estimated_cost_usd), 0) as cost')
         ->value('cost');

        return [
            'this_week' => (float) $thisWeek,
            'last_week' => (float) $lastWeek,
        ];
    }

    // =========================================================
    // JarvisStatus Command Support
    // =========================================================

    /**
     * Compact cost summary line for the status command.
     */
    public function getCostSummaryLine(): string
    {
        $summary = $this->getGlobalCostSummary();

        return sprintf(
            'Today: $%s (%d sessions) | Week: $%s (%d sessions) | All-time: $%s (%d sessions)',
            number_format($summary['today_cost'], 2),
            $summary['today_sessions'],
            number_format($summary['week_cost'], 2),
            $summary['week_sessions'],
            number_format($summary['total_cost'], 2),
            $summary['total_sessions']
        );
    }
}
