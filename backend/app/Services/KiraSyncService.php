<?php

namespace App\Services;

use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;

class KiraSyncService
{
    public function __construct(private TaskService $taskService) {}

    /**
     * Import tasks from Kira's sync manifest into Jarvis DB.
     * Writes back updated manifest with Jarvis UUIDs.
     */
    public function importFromProject(Project $project): array
    {
        $relative = trim($project->assistant_data_path ?: 'assistant-data', '/');
        $filePath = $project->local_path . '/' . $relative . '/tasks.md';

        if (!file_exists($filePath)) {
            return ['error' => 'No assistant-data/tasks.md found at ' . $filePath];
        }

        $content = file_get_contents($filePath);
        $manifest = $this->parseManifest($content);

        if ($manifest === null) {
            return ['error' => 'No sync manifest found in tasks.md. Kira needs to add one first.'];
        }

        $results = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'tasks' => []];

        foreach ($manifest as &$entry) {
            // A tombstone can go stale if the task came back alive via a
            // remote edit (planner/phone) after Kira flagged it deleted but
            // before Kira removed the markdown line. Detect that divergence
            // and un-tombstone the entry so it's processed as a normal live
            // entry below instead of being skipped forever.
            if (!empty($entry['deleted']) && !empty($entry['id'])) {
                $revived = Task::withTrashed()->find($entry['id']);
                if ($revived && !$revived->trashed()) {
                    unset($entry['deleted']);
                }
            }

            // Entries still flagged deleted are tombstones awaiting Kira cleanup — never import.
            if (!empty($entry['deleted'])) {
                $results['deleted']++;
                continue;
            }

            if (!empty($entry['id'])) {
                $task = Task::withTrashed()->find($entry['id']);

                if (!$task || $task->trashed()) {
                    // Deleted centrally (planner/phone/local) or hard-vanished:
                    // flag as tombstone so Kira removes the markdown line, and
                    // so this import never recreates it.
                    $entry['deleted'] = true;
                    $results['deleted']++;
                    continue;
                }

                $changed = $this->updateIfChanged($task, $entry);
                $results[$changed ? 'updated' : 'unchanged']++;
                $results['tasks'][] = $this->formatTask($task);
            } else {
                $task = $this->taskService->createTask($project, [
                    'title' => $entry['title'],
                    'priority' => $this->mapPriority($entry['priority'] ?? 'medium'),
                    'status' => $this->mapStatus($entry['status'] ?? 'todo'),
                    'source' => TaskSource::KIRA->value,
                ]);
                $entry['id'] = $task->id;
                $results['created']++;
                $results['tasks'][] = $this->formatTask($task);
            }
        }
        unset($entry);

        // Write manifest back with UUIDs filled in
        $this->writeManifest($filePath, $content, $manifest);

        // Find Jarvis-only tasks (in DB for this project, not in manifest)
        $manifestIds = collect($manifest)->pluck('id')->filter()->all();
        $jarvisOnly = $project->tasks()
            ->open()
            ->when(!empty($manifestIds), fn ($q) => $q->whereNotIn('id', $manifestIds))
            ->byPriority()
            ->get();

        $results['jarvis_only'] = $jarvisOnly->map(fn (Task $t) => $this->formatTask($t))->all();

        return $results;
    }

    /**
     * Get all open tasks for a project, formatted for Kira to consume.
     */
    public function exportForProject(Project $project): array
    {
        return $project->tasks()
            ->open()
            ->byPriority()
            ->get()
            ->map(fn (Task $t) => $this->formatTask($t))
            ->all();
    }

    private function parseManifest(string $content): ?array
    {
        if (!preg_match('/<!-- sync-manifest\s*\n(.*?)-->/s', $content, $matches)) {
            return null;
        }

        $json = trim($matches[1]);

        return json_decode($json, true);
    }

    private function writeManifest(string $filePath, string $originalContent, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $block = "<!-- sync-manifest\n{$json}\n-->";

        if (preg_match('/<!-- sync-manifest\s*\n.*?-->/s', $originalContent)) {
            // $block is used as a preg_replace REPLACEMENT string. Task titles
            // can legitimately contain `$0`/`$1`/backreference-looking text or
            // backslashes, which preg_replace would otherwise interpret and
            // corrupt tasks.md. Escape them.
            $newContent = preg_replace('/<!-- sync-manifest\s*\n.*?-->/s', addcslashes($block, '\\$'), $originalContent);
        } else {
            $newContent = rtrim($originalContent) . "\n\n" . $block . "\n";
        }

        file_put_contents($filePath, $newContent);
    }

    private function updateIfChanged(Task $task, array $entry): bool
    {
        $updates = [];

        $currentPriority = $task->priority instanceof \BackedEnum ? $task->priority->value : $task->priority;
        $currentStatus = $task->status instanceof \BackedEnum ? $task->status->value : $task->status;

        $newPriority = $this->mapPriority($entry['priority'] ?? $currentPriority);
        $newStatus = $this->mapStatus($entry['status'] ?? $currentStatus);

        if ($currentPriority !== $newPriority) {
            $updates['priority'] = $newPriority;
        }

        if ($currentStatus !== $newStatus) {
            $updates['status'] = $newStatus;
            if ($newStatus === TaskStatus::DONE->value) {
                $updates['completed_at'] = now();
            }
        }

        if (!empty($entry['title']) && $task->title !== $entry['title']) {
            $updates['title'] = $entry['title'];
        }

        if (empty($updates)) {
            return false;
        }

        $this->taskService->updateTask($task, $updates);

        return true;
    }

    private function mapPriority(string $priority): string
    {
        return match (strtolower($priority)) {
            'urgent' => 'urgent',
            'high' => 'high',
            'normal', 'medium' => 'medium',
            'low' => 'low',
            default => 'medium',
        };
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'todo' => 'todo',
            'in_progress' => 'in_progress',
            'blocked' => 'blocked',
            'done' => 'done',
            default => 'todo',
        };
    }

    private function formatTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'priority' => $task->priority instanceof \BackedEnum ? $task->priority->value : $task->priority,
            'status' => $task->status instanceof \BackedEnum ? $task->status->value : $task->status,
            'deadline' => $task->deadline?->toDateString(),
            'source' => $task->source instanceof \BackedEnum ? $task->source->value : $task->source,
            'updated_at' => $task->updated_at->toIso8601String(),
        ];
    }
}
