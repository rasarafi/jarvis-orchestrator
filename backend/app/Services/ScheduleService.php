<?php

namespace App\Services;

use App\Models\ScheduledTask;
use App\Models\Task;
use Illuminate\Support\Collection;

class ScheduleService
{
    public function schedule(Task $task, array $slot): ScheduledTask
    {
        return ScheduledTask::create(array_merge(['task_id' => $task->id], $slot));
    }

    public function unschedule(ScheduledTask $scheduled): void
    {
        $scheduled->delete();
    }

    /**
     * All scheduled tasks for a given calendar date, ordered by start time.
     */
    public function getScheduleForDate(string $date): Collection
    {
        return ScheduledTask::query()
            ->with(['task.project', 'timeBlock'])
            ->forDate($date)
            ->ordered()
            ->get();
    }
}
