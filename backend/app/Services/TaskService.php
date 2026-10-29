<?php

namespace App\Services;

use App\Enums\TaskActor;
use App\Enums\TaskEventType;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private TaskEventService $events,
    ) {}

    public function createTask(?Project $project, array $data, TaskActor $actor = TaskActor::USER): Task
    {
        if ($project) {
            $data['project_id'] = $project->id;
        }

        // The remote sync push rejects a NULL source with a 500 (the remote
        // column is NOT NULL). Every caller that omits it is UI-created, so
        // default to jarvis rather than let it through as NULL.
        $data['source'] ??= TaskSource::JARVIS->value;

        return DB::transaction(function () use ($data, $actor) {
            $task = Task::create($data);
            $this->events->record($task, $actor, TaskEventType::CREATED);
            return $task;
        });
    }

    public function updateTask(Task $task, array $data, TaskActor $actor = TaskActor::USER, ?array $meta = null): Task
    {
        $newStatus = $data['status'] ?? null;

        if ($newStatus === TaskStatus::DONE->value && $task->status !== TaskStatus::DONE->value) {
            $data['completed_at'] ??= now();
        } elseif ($newStatus !== null && $newStatus !== TaskStatus::DONE->value) {
            $data['completed_at'] = null;
        }

        return DB::transaction(function () use ($task, $data, $actor, $meta) {
            $original = $task->getOriginal();
            $task->update($data);

            $changes = $task->getChanges();
            unset($changes['updated_at']);

            if ($changes !== []) {
                $payload = [];
                foreach ($changes as $field => $new) {
                    $payload[$field] = ['old' => $original[$field] ?? null, 'new' => $new];
                }
                if ($meta !== null) {
                    $payload['meta'] = $meta;
                }
                $this->events->record($task, $actor, $this->eventTypeFor($changes, $original), $payload);
            }

            return $task->fresh();
        });
    }

    public function deleteTask(Task $task, TaskActor $actor = TaskActor::USER): void
    {
        DB::transaction(function () use ($task, $actor) {
            $this->events->record($task, $actor, TaskEventType::DELETED);
            $task->delete();
        });
    }

    private function eventTypeFor(array $changes, array $original): TaskEventType
    {
        if (!array_key_exists('status', $changes)) {
            return TaskEventType::EDITED;
        }

        return match (true) {
            $changes['status'] === TaskStatus::DONE->value => TaskEventType::COMPLETED,
            ($original['status'] ?? null) === TaskStatus::DONE->value => TaskEventType::REOPENED,
            default => TaskEventType::STATUS_CHANGED,
        };
    }

    /**
     * Archive = "off my board", not "post-completion cleanup" — open tasks that turned
     * irrelevant can be archived without falsely marking them done. `unarchive` restores
     * them to their original status.
     */
    public function archive(Task $task, TaskActor $actor = TaskActor::USER): Task
    {
        return DB::transaction(function () use ($task, $actor) {
            $task->update(['archived_at' => now()]);
            $this->events->record($task, $actor, TaskEventType::ARCHIVED);
            return $task->fresh();
        });
    }

    public function unarchive(Task $task, TaskActor $actor = TaskActor::USER): Task
    {
        return DB::transaction(function () use ($task, $actor) {
            $task->update(['archived_at' => null]);
            $this->events->record($task, $actor, TaskEventType::UNARCHIVED);
            return $task->fresh();
        });
    }

    public function markComplete(Task $task, TaskActor $actor = TaskActor::USER, ?array $meta = null): Task
    {
        return $this->updateTask($task, [
            'status' => TaskStatus::DONE->value,
            'completed_at' => now(),
        ], $actor, $meta);
    }

    public function markInProgress(Task $task, TaskActor $actor = TaskActor::USER): Task
    {
        return $this->updateTask($task, [
            'status' => TaskStatus::IN_PROGRESS->value,
        ], $actor);
    }

    public function markBlocked(Task $task, string $reason, TaskActor $actor = TaskActor::USER): Task
    {
        return $this->updateTask($task, [
            'status' => TaskStatus::BLOCKED->value,
            'blocker_reason' => $reason,
        ], $actor);
    }

    /**
     * Get open tasks for a project
     * Uses model scopes (SSOT compliance)
     */
    public function getOpenTasks(Project $project): Collection
    {
        return $project->tasks()
            ->open()
            ->byPriority()
            ->orderBy('deadline', 'asc')
            ->get();
    }

    /**
     * Get all open tasks across all projects
     * Uses model scopes (SSOT compliance)
     */
    public function getAllOpenTasks(): Collection
    {
        return Task::with('project')
            ->open()
            ->byPriority()
            ->orderBy('deadline', 'asc')
            ->get();
    }

    /**
     * Get urgent tasks (deadline within 3 days)
     */
    public function getUrgentTasks(): Collection
    {
        return Task::with('project')
            ->open()
            ->urgent()
            ->get();
    }

    /**
     * Get stale tasks (created more than 30 days ago)
     */
    public function getStaleTasks(): Collection
    {
        return Task::with('project')
            ->open()
            ->stale()
            ->get();
    }

    /**
     * Get filtered backlog tasks with sorting
     * SINGLE SOURCE OF TRUTH for backlog filtering
     */
    public function getFilteredBacklog(array $filters = [], string $sort = 'priority'): Collection
    {
        $query = Task::with('project')->notArchived();

        // Explicit status filter replaces the default not-done exclusion
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', TaskStatus::DONE->value);
        }

        if (!empty($filters['project'])) {
            $query->where('project_id', $filters['project']);
        }

        if (!empty($filters['group'])) {
            $query->whereHas('project', fn ($q) => $q->where('group_key', $filters['group']));
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['completed_within'])) {
            $days = match ($filters['completed_within']) {
                '1d' => 1,
                '7d' => 7,
                '30d' => 30,
                default => null,
            };
            if ($days !== null) {
                $query->where('completed_at', '>=', now()->subDays($days));
            }
        }

        // Apply sorting
        return match ($sort) {
            'deadline' => $query->orderByRaw('deadline IS NULL, deadline ASC')->get(),
            'age' => $query->oldest()->get(),
            'completed' => $query->orderByRaw('completed_at DESC NULLS LAST')->get(),
            default => $query->byPriority()->orderBy('deadline', 'asc')->get(),
        };
    }

    /**
     * Find task by title using fuzzy matching
     */
    public function findTaskByTitle(Project $project, string $title): ?Task
    {
        return $project->tasks()
            ->where('title', 'like', "%{$title}%")
            ->first();
    }

    /**
     * Batch mark tasks as done by title (fuzzy match)
     * Returns count of tasks marked complete
     */
    public function syncTasksToDone(Project $project, array $taskTitles): int
    {
        $count = 0;

        foreach ($taskTitles as $taskTitle) {
            $task = $this->findTaskByTitle($project, $taskTitle);

            if ($task && $task->status !== TaskStatus::DONE->value) {
                $this->markComplete($task);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Batch create new tasks if they don't exist
     * Returns count of tasks created
     */
    public function syncTasksToCreate(Project $project, array $taskTitles): int
    {
        $count = 0;

        foreach ($taskTitles as $taskTitle) {
            $exists = $project->tasks()
                ->where('title', 'like', "%{$taskTitle}%")
                ->exists();

            if (!$exists) {
                $this->createTask($project, [
                    'title' => $taskTitle,
                    'status' => TaskStatus::TODO->value,
                    'priority' => 'medium',
                ]);
                $count++;
            }
        }

        return $count;
    }
}
