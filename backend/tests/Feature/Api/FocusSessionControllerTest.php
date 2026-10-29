<?php

namespace Tests\Feature\Api;

use App\Enums\FocusSessionStatus;
use App\Models\FocusSession;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FocusSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_session(): void
    {
        $task = Task::factory()->create();

        $response = $this->postJson('/api/focus-sessions', [
            'task_id' => $task->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.task_id', $task->id)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_start_requires_existing_task(): void
    {
        $response = $this->postJson('/api/focus-sessions', [
            'task_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertStatus(422);
    }

    public function test_heartbeat_updates_elapsed(): void
    {
        $session = FocusSession::factory()->create();

        $response = $this->patchJson("/api/focus-sessions/{$session->id}/heartbeat", [
            'elapsed_seconds' => 1200,
        ]);

        $response->assertOk()->assertJsonPath('data.elapsed_seconds', 1200);
    }

    public function test_end_closes_session(): void
    {
        $session = FocusSession::factory()->create();

        $response = $this->patchJson("/api/focus-sessions/{$session->id}/end", [
            'elapsed_seconds' => 1500,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', FocusSessionStatus::ENDED->value)
            ->assertJsonPath('data.elapsed_seconds', 1500);
    }

    public function test_start_returns_200_on_idempotent_resume(): void
    {
        $task = Task::factory()->create();

        $this->postJson('/api/focus-sessions', ['task_id' => $task->id])
            ->assertCreated();

        $this->postJson('/api/focus-sessions', ['task_id' => $task->id])
            ->assertOk();
    }
}
