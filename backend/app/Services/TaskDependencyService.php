<?php

namespace App\Services;

use App\Enums\TaskActor;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskDependencyService
{
    public function __construct(
        private TaskEventService $events,
    ) {}

    public function add(Task $task, Task $dependsOn, TaskActor $actor = TaskActor::USER): TaskDependency
    {
        if ($task->id === $dependsOn->id) {
            throw ValidationException::withMessages([
                'depends_on_task_id' => 'A task cannot depend on itself.',
            ]);
        }

        $exists = TaskDependency::where('task_id', $task->id)
            ->where('depends_on_task_id', $dependsOn->id)
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'depends_on_task_id' => 'Dependency already exists.',
            ]);
        }

        if ($this->dependsOnTransitively($dependsOn, $task)) {
            throw ValidationException::withMessages([
                'depends_on_task_id' => "Adding this dependency would create a cycle ({$dependsOn->title} already depends on {$task->title}).",
            ]);
        }

        return DB::transaction(function () use ($task, $dependsOn, $actor) {
            $dependency = TaskDependency::create([
                'task_id' => $task->id,
                'depends_on_task_id' => $dependsOn->id,
            ]);
            $this->events->record($task, $actor, TaskEventType::DEPENDENCY_ADDED, [
                'depends_on_task_id' => $dependsOn->id,
            ]);
            return $dependency;
        });
    }

    public function remove(Task $task, Task $dependsOn, TaskActor $actor = TaskActor::USER): void
    {
        DB::transaction(function () use ($task, $dependsOn, $actor) {
            $deleted = TaskDependency::where('task_id', $task->id)
                ->where('depends_on_task_id', $dependsOn->id)
                ->delete();
            if ($deleted > 0) {
                $this->events->record($task, $actor, TaskEventType::DEPENDENCY_REMOVED, [
                    'depends_on_task_id' => $dependsOn->id,
                ]);
            }
        });
    }

    /**
     * BFS over depends_on edges: does $start (transitively) depend on $target?
     */
    private function dependsOnTransitively(Task $start, Task $target): bool
    {
        $frontier = [$start->id];
        $seen = [];

        while ($frontier !== []) {
            $next = TaskDependency::whereIn('task_id', $frontier)
                ->pluck('depends_on_task_id')
                ->all();

            if (in_array($target->id, $next, true)) {
                return true;
            }

            $frontier = array_diff(array_unique($next), $seen);
            $seen = array_merge($seen, $frontier);
        }

        return false;
    }
}
