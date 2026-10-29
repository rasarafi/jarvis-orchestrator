<?php

namespace Tests\Feature\Api;

use App\Enums\TaskEventType;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskSsotApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_with_kira_actor_and_source_records_attribution(): void
    {
        $response = $this->withHeader('X-Jarvis-Actor', 'kira')
            ->postJson('/api/tasks', ['title' => 'From Kira', 'source' => 'kira']);

        $response->assertCreated();
        $taskId = $response->json('data.id');
        $this->assertDatabaseHas('task_events', [
            'task_id' => $taskId, 'actor' => 'kira',
            'event_type' => TaskEventType::CREATED->value,
        ]);
    }

    public function test_invalid_actor_header_is_422(): void
    {
        $this->withHeader('X-Jarvis-Actor', 'mallory')
            ->postJson('/api/tasks', ['title' => 'x'])
            ->assertStatus(422);
    }

    public function test_events_endpoint_lists_newest_first(): void
    {
        $task = Task::factory()->create();
        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done'])->assertOk();

        $response = $this->getJson("/api/tasks/{$task->id}/events")->assertOk();
        $this->assertSame(TaskEventType::COMPLETED->value, $response->json('data.0.event_type'));
    }

    public function test_dependency_endpoints(): void
    {
        [$a, $b] = Task::factory()->count(2)->create();

        $this->postJson("/api/tasks/{$a->id}/dependencies", ['depends_on_task_id' => $b->id])
            ->assertCreated();
        $this->postJson("/api/tasks/{$b->id}/dependencies", ['depends_on_task_id' => $a->id])
            ->assertStatus(422); // cycle
        $this->deleteJson("/api/tasks/{$a->id}/dependencies/{$b->id}")->assertOk();
    }

    public function test_archive_guard_and_flow(): void
    {
        // Open tasks archive too — the tasks board's bulk Archive acts on the open list.
        $todo = Task::factory()->create();
        $this->patchJson("/api/tasks/{$todo->id}/archive")->assertOk();
        $this->assertNotNull($todo->fresh()->archived_at);
        $this->assertSame('todo', $todo->fresh()->status);

        $done = Task::factory()->done()->create();
        $this->patchJson("/api/tasks/{$done->id}/archive")->assertOk();
        $this->assertNotNull($done->fresh()->archived_at);
        $this->patchJson("/api/tasks/{$done->id}/unarchive")->assertOk();
        $this->assertNull($done->fresh()->archived_at);
    }

    public function test_new_fields_writable(): void
    {
        $parent = Task::factory()->create();
        $response = $this->postJson('/api/tasks', [
            'title' => 'Child', 'parent_task_id' => $parent->id,
            'start_date' => '2026-08-01', 'estimate_minutes' => 90,
        ])->assertCreated();

        $this->assertSame(90, $response->json('data.estimate_minutes'));
        $this->assertSame($parent->id, $response->json('data.parent_task_id'));
    }
}
