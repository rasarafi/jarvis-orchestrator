<?php

namespace App\Services;

use App\Enums\NoteStatus;
use App\Enums\TaskActor;
use App\Enums\TaskSource;
use App\Models\Knowledge;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NoteService
{
    public function __construct(
        private TaskService $taskService,
        private MemoryService $memoryService,
    ) {}

    public function capture(array $data): Note
    {
        if (!empty($data['remote_id'])) {
            $existing = Note::where('remote_id', $data['remote_id'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $note = Note::create($data);
        $this->memoryService->indexNote($note);

        return $note;
    }

    public function update(Note $note, array $data): Note
    {
        $bodyChanged = isset($data['body']) && $data['body'] !== $note->body;
        $note->update($data);

        if ($bodyChanged) {
            $this->memoryService->indexNote($note->fresh());
        }

        return $note->fresh();
    }

    public function keep(Note $note): Note
    {
        $note->update(['status' => NoteStatus::KEPT]);
        return $note->fresh();
    }

    public function archive(Note $note): Note
    {
        $note->update(['status' => NoteStatus::ARCHIVED]);
        return $note->fresh();
    }

    public function restore(Note $note): Note
    {
        $note->update(['status' => NoteStatus::INBOX]);
        return $note->fresh();
    }

    public function pin(Note $note, bool $pinned): Note
    {
        $note->update(['pinned' => $pinned]);
        return $note->fresh();
    }

    public function convertToTask(Note $note, array $taskData): Task
    {
        $this->assertTriageable($note);

        return DB::transaction(function () use ($note, $taskData) {
            $projectId = $taskData['project_id'] ?? $note->project_id;
            $project = $projectId ? Project::find($projectId) : null;

            $title = $taskData['title'] ?? Str::limit($note->body, 250);

            $task = $this->taskService->createTask($project, [
                'title' => $title,
                'description' => $title === $note->body ? null : $note->body,
                'priority' => $taskData['priority'] ?? 'medium',
                'status' => 'todo',
                'source' => TaskSource::JARVIS,
            ], TaskActor::USER);

            $note->update(['status' => NoteStatus::ARCHIVED, 'converted_task_id' => $task->id]);

            return $task;
        });
    }

    public function promoteToKnowledge(Note $note, array $data): Knowledge
    {
        $this->assertTriageable($note);

        return DB::transaction(function () use ($note, $data) {
            $knowledge = Knowledge::create([
                'project_id' => $note->project_id,
                'type' => $data['type'],
                'title' => $data['title'],
                'content' => $note->body,
                'tags' => $data['tags'] ?? [],
                'scope' => $note->project_id ? 'project' : 'shared',
            ]);

            $this->memoryService->index("knowledge-{$knowledge->id}", $knowledge->content, [
                'type' => $knowledge->type,
                'project_slug' => $note->project?->slug ?? '',
            ]);

            $note->update(['status' => NoteStatus::ARCHIVED, 'promoted_knowledge_id' => $knowledge->id]);

            return $knowledge;
        });
    }

    public function counts(): array
    {
        $rows = Note::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'inbox' => (int) ($rows[NoteStatus::INBOX->value] ?? 0),
            'kept' => (int) ($rows[NoteStatus::KEPT->value] ?? 0),
            'archived' => (int) ($rows[NoteStatus::ARCHIVED->value] ?? 0),
        ];
    }

    private function assertTriageable(Note $note): void
    {
        if ($note->status === NoteStatus::ARCHIVED) {
            throw ValidationException::withMessages(['status' => 'Note is already triaged (archived).']);
        }
    }
}
