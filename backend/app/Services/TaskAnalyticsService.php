<?php

namespace App\Services;

use App\Enums\ProjectGroup;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregates for the task-analysis page: backlog pressure, flow, age
 * distribution and deadline coverage. Read-only — every figure is derived from
 * the tasks table, which is the SSOT.
 */
class TaskAnalyticsService
{
    /** Pressure weights per priority. Urgent dominates; low is noise. */
    private const PRESSURE_WEIGHT = [
        TaskPriority::URGENT->value => 4,
        TaskPriority::HIGH->value => 2,
        TaskPriority::MEDIUM->value => 1,
        TaskPriority::LOW->value => 0,
    ];

    /** A project with nothing above medium and at most this many open tasks is "quiet". */
    private const QUIET_MAX_OPEN = 3;

    public function __construct(private ProjectColorService $projectColorService) {}

    /**
     * @param  ProjectGroup|null  $group  Restrict every figure to one project group.
     * @param  int  $weeks  How many weeks of flow history to return.
     */
    public function summary(?ProjectGroup $group = null, int $weeks = 12): array
    {
        $open = $this->openTasks($group);
        $flow = $this->flow($group, $weeks);

        return [
            'stats' => $this->stats($open, $flow),
            'pressure' => $this->pressure($open),
            'flow' => $flow,
            'age' => $this->age($open),
            'deadlines' => $this->deadlines($open),
        ];
    }

    /** Every open (non-done, non-archived, non-deleted) task with its project. */
    private function openTasks(?ProjectGroup $group): \Illuminate\Support\Collection
    {
        return Task::query()
            ->notArchived()
            ->open()
            ->with('project:id,slug,name,group_key')
            ->when($group, fn (Builder $q) => $q->whereHas(
                'project',
                fn (Builder $p) => $p->where('group_key', $group->value)
            ))
            ->get();
    }

    private function stats(\Illuminate\Support\Collection $open, array $flow): array
    {
        $now = CarbonImmutable::now();
        $oldest = $open->sortByDesc(fn (Task $t) => $t->created_at?->diffInSeconds($now) ?? 0)->first();
        $thisWeek = end($flow) ?: ['created' => 0, 'completed' => 0];

        return [
            'open' => $open->count(),
            'urgent' => $open->where('priority', TaskPriority::URGENT->value)->count(),
            'blocked' => $open->where('status', TaskStatus::BLOCKED->value)->count(),
            'in_progress' => $open->where('status', TaskStatus::IN_PROGRESS->value)->count(),
            'review' => $open->where('status', TaskStatus::REVIEW->value)->count(),
            'projects_with_open' => $open->pluck('project_id')->filter()->unique()->count(),
            'created_this_week' => $thisWeek['created'],
            'completed_this_week' => $thisWeek['completed'],
            'net_this_week' => $thisWeek['created'] - $thisWeek['completed'],
            'urgent_top_project' => $this->urgentTopProject($open),
            'oldest' => $oldest ? [
                'id' => $oldest->id,
                'title' => $oldest->title,
                'project' => $oldest->project?->name,
                'age_days' => $oldest->created_at ? (int) $oldest->created_at->diffInDays($now) : null,
            ] : null,
        ];
    }

    /** Project holding the most urgent tasks — the "5 of them in X" footnote. */
    private function urgentTopProject(\Illuminate\Support\Collection $open): ?array
    {
        $top = $open
            ->where('priority', TaskPriority::URGENT->value)
            ->groupBy(fn (Task $t) => $t->project?->name ?? 'Unassigned')
            ->map->count()
            ->sortDesc()
            ->take(1);

        return $top->isEmpty() ? null : ['name' => $top->keys()->first(), 'count' => $top->first()];
    }

    /**
     * Open counts per project split by priority, ordered by pressure score.
     * Projects with only a couple of low-heat tasks are flagged `quiet` so the
     * UI can collapse them instead of rendering 20 near-empty bars.
     */
    private function pressure(\Illuminate\Support\Collection $open): array
    {
        $colors = $this->projectColorService->allColors();

        return $open
            ->groupBy(fn (Task $t) => $t->project?->slug ?? 'unassigned')
            ->map(function ($tasks, $slug) use ($colors) {
                $project = $tasks->first()->project;
                $counts = collect(TaskPriority::cases())
                    ->mapWithKeys(fn (TaskPriority $p) => [
                        $p->value => $tasks->where('priority', $p->value)->count(),
                    ])
                    ->all();
                $score = 0;
                foreach ($counts as $priority => $n) {
                    $score += $n * self::PRESSURE_WEIGHT[$priority];
                }

                return [
                    'slug' => $slug,
                    'display_name' => $project?->name ?? 'Unassigned',
                    'group_key' => $project?->group_key,
                    'color' => $colors[$slug] ?? null,
                    'counts' => $counts,
                    'total' => $tasks->count(),
                    'score' => $score,
                    'quiet' => $counts[TaskPriority::URGENT->value] === 0
                        && $counts[TaskPriority::HIGH->value] === 0
                        && $tasks->count() <= self::QUIET_MAX_OPEN,
                ];
            })
            ->sortByDesc(fn (array $row) => [$row['score'], $row['total']])
            ->values()
            ->all();
    }

    /**
     * Weekly created vs completed for the last N weeks, plus the running open
     * total. Archived tasks are excluded throughout so the last `open_after`
     * lands on the same number the "Open" stat tile shows — a cumulative line
     * that disagrees with the headline reads as a bug.
     */
    private function flow(?ProjectGroup $group, int $weeks): array
    {
        $start = CarbonImmutable::now()->startOfWeek()->subWeeks($weeks - 1);

        $created = $this->weeklyCounts('created_at', $start, $group);
        $completed = $this->weeklyCounts('completed_at', $start, $group);

        // Backlog at the start of the window: everything created before it that
        // was not yet completed by then.
        $backlog = $this->backlogAt($start, $group);

        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $week = $start->addWeeks($i);
            $key = $week->toDateString();
            $c = $created[$key] ?? 0;
            $d = $completed[$key] ?? 0;
            $backlog += $c - $d;
            $out[] = [
                'week' => $key,
                'created' => $c,
                'completed' => $d,
                'open_after' => max(0, $backlog),
            ];
        }

        return $out;
    }

    /** @return array<string,int> week-start date => count */
    private function weeklyCounts(string $column, CarbonImmutable $start, ?ProjectGroup $group): array
    {
        return Task::query()
            ->notArchived()
            ->whereNotNull($column)
            ->where($column, '>=', $start)
            ->when($group, fn (Builder $q) => $q->whereHas(
                'project',
                fn (Builder $p) => $p->where('group_key', $group->value)
            ))
            ->selectRaw("date_trunc('week', {$column})::date as week, count(*) as n")
            ->groupBy('week')
            ->pluck('n', 'week')
            ->mapWithKeys(fn ($n, $week) => [(string) $week => (int) $n])
            ->all();
    }

    private function backlogAt(CarbonImmutable $moment, ?ProjectGroup $group): int
    {
        return Task::query()
            ->notArchived()
            ->where('created_at', '<', $moment)
            ->where(fn (Builder $q) => $q
                ->whereNull('completed_at')
                ->orWhere('completed_at', '>=', $moment))
            ->when($group, fn (Builder $q) => $q->whereHas(
                'project',
                fn (Builder $p) => $p->where('group_key', $group->value)
            ))
            ->count();
    }

    /** One row per open task: age in days against priority, for the scatter. */
    private function age(\Illuminate\Support\Collection $open): array
    {
        $now = CarbonImmutable::now();

        return $open
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'project' => $t->project?->name ?? 'Unassigned',
                'slug' => $t->project?->slug,
                'group_key' => $t->project?->group_key,
                'priority' => $t->priority,
                'status' => $t->status,
                'age_days' => $t->created_at ? (int) $t->created_at->diffInDays($now) : 0,
            ])
            ->sortByDesc('age_days')
            ->values()
            ->all();
    }

    /**
     * Deadline coverage. The headline here is usually the *gap* — how many open
     * tasks carry no deadline at all.
     */
    private function deadlines(\Illuminate\Support\Collection $open): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $dated = $open->filter(fn (Task $t) => $t->deadline !== null);

        return [
            'total_open' => $open->count(),
            'with_deadline' => $dated->count(),
            'without_deadline' => $open->count() - $dated->count(),
            'overdue' => $dated->filter(fn (Task $t) => $t->deadline->lt($today))->count(),
            'items' => $dated
                ->sortBy('deadline')
                ->map(fn (Task $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'project' => $t->project?->name ?? 'Unassigned',
                    'priority' => $t->priority,
                    'deadline' => $t->deadline->toDateString(),
                    'days_out' => (int) $today->diffInDays($t->deadline, false),
                ])
                ->values()
                ->all(),
        ];
    }
}
