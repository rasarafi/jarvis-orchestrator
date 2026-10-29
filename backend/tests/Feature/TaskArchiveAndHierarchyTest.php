<?php

namespace Tests\Feature;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskArchiveAndHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TaskService
    {
        return app(TaskService::class);
    }

    public function test_subtask_relations_and_orphan_on_parent_delete(): void
    {
        $parent = Task::factory()->create();
        $child = Task::factory()->create(['parent_task_id' => $parent->id]);

        $this->assertTrue($parent->subtasks->contains('id', $child->id));
        $this->assertSame($parent->id, $child->parent->id);

        $parent->forceDelete();
        $this->assertNull($child->fresh()->parent_task_id);
    }

    public function test_archive_works_on_open_tasks_without_marking_them_done(): void
    {
        $task = Task::factory()->create(); // todo

        $archived = $this->service()->archive($task);

        $this->assertNotNull($archived->archived_at);
        $this->assertSame('todo', $archived->status);
        $this->assertFalse(
            $this->service()->getFilteredBacklog()->contains('id', $task->id)
        );
    }

    public function test_archive_sets_timestamp_emits_event_and_hides_from_backlog(): void
    {
        $task = Task::factory()->done()->create();
        $archived = $this->service()->archive($task);

        $this->assertNotNull($archived->archived_at);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event_type' => TaskEventType::ARCHIVED->value,
        ]);
        $this->assertFalse(
            $this->service()->getFilteredBacklog(['status' => 'done'])->contains('id', $task->id)
        );

        $unarchived = $this->service()->unarchive($archived);
        $this->assertNull($unarchived->archived_at);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event_type' => TaskEventType::UNARCHIVED->value,
        ]);
    }
}
