<?php

namespace Tests\Feature;

use App\Enums\TaskActor;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TaskService
    {
        return app(TaskService::class);
    }

    public function test_create_emits_created_event_with_actor(): void
    {
        $task = $this->service()->createTask(null, [
            'title' => 'New task', 'status' => 'todo', 'priority' => 'medium',
        ], TaskActor::KIRA);

        $this->assertCount(1, $task->events);
        $event = $task->events->first();
        $this->assertSame(TaskEventType::CREATED, $event->event_type);
        $this->assertSame(TaskActor::KIRA, $event->actor);
    }

    public function test_complete_emits_completed_event_with_diff(): void
    {
        $task = Task::factory()->create();
        $this->service()->markComplete($task);

        $event = $task->events()->where('event_type', TaskEventType::COMPLETED->value)->first();
        $this->assertNotNull($event);
        $this->assertSame(TaskActor::USER, $event->actor);
        $this->assertSame('todo', $event->payload['status']['old']);
        $this->assertSame('done', $event->payload['status']['new']);
    }

    public function test_reopen_emits_reopened_event(): void
    {
        $task = Task::factory()->done()->create();
        $this->service()->updateTask($task, ['status' => 'todo']);

        $this->assertNotNull(
            $task->events()->where('event_type', TaskEventType::REOPENED->value)->first()
        );
    }

    public function test_edit_without_status_emits_edited_event(): void
    {
        $task = Task::factory()->create();
        $this->service()->updateTask($task, ['title' => 'Renamed']);

        $event = $task->events()->where('event_type', TaskEventType::EDITED->value)->first();
        $this->assertNotNull($event);
        $this->assertSame('Renamed', $event->payload['title']['new']);
    }

    public function test_noop_update_emits_no_event(): void
    {
        $task = Task::factory()->create(['title' => 'Same']);
        $this->service()->updateTask($task, ['title' => 'Same']);

        $this->assertCount(0, $task->events);
    }

    public function test_delete_emits_deleted_event_and_soft_deletes(): void
    {
        $task = Task::factory()->create();
        $this->service()->deleteTask($task, TaskActor::JARVIS);

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event_type' => TaskEventType::DELETED->value,
            'actor' => TaskActor::JARVIS->value,
        ]);
    }
}
