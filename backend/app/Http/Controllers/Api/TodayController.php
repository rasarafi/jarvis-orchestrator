<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Services\FocusSessionService;
use App\Services\ProjectColorService;
use App\Services\ScheduleService;
use App\Services\TimeBlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    public function __construct(
        private TimeBlockService $blocks,
        private ScheduleService $schedule,
        private FocusSessionService $focus,
        private ProjectColorService $colors,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        return response()->json([
            'date' => $date,
            'blocks' => $this->blocks->blocksForDate($date),
            'scheduled_tasks' => $this->schedule->getScheduleForDate($date),
            'active_focus_session' => $this->focus->activeSession(),
            'project_mix' => $this->projectMixForDate($date),
        ]);
    }

    private function projectMixForDate(string $date): array
    {
        $countsBySlug = ScheduledTask::query()
            ->forDate($date)
            ->with('task.project')
            ->get()
            ->groupBy(fn ($st) => $st->task?->project?->slug)
            ->map->count();

        return Project::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Project $p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'color' => $this->colors->colorForSlug($p->slug),
                'scheduled_count' => $countsBySlug[$p->slug] ?? 0,
            ])
            ->all();
    }
}
