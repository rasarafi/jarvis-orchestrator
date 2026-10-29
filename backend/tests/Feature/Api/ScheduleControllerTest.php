<?php

namespace Tests\Feature\Api;

use App\Models\ScheduledTask;
use App\Models\Task;
use App\Models\TimeBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_creates_scheduled_task(): void
    {
        $task = Task::factory()->create();
        $block = TimeBlock::factory()->create();

        $response = $this->postJson("/api/tasks/{$task->id}/schedule", [
            'time_block_id' => $block->id,
            'scheduled_date' => '2026-04-18',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $response->assertCreated()->assertJsonPath('data.task_id', $task->id);
        $this->assertDatabaseHas('scheduled_tasks', [
            'task_id' => $task->id,
            'scheduled_date' => '2026-04-18',
        ]);
    }

    public function test_reschedule_push_1hr(): void
    {
        $st = ScheduledTask::factory()->create([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $response = $this->patchJson("/api/scheduled-tasks/{$st->id}/reschedule", [
            'action' => 'push_1hr',
        ]);

        $response->assertOk()->assertJsonPath('data.start_time', '10:00:00');
    }

    public function test_reschedule_skip_returns_no_data(): void
    {
        $st = ScheduledTask::factory()->create();

        $response = $this->patchJson("/api/scheduled-tasks/{$st->id}/reschedule", [
            'action' => 'skip',
        ]);

        $response->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $st->id]);
    }

    public function test_reschedule_to_time_with_payload(): void
    {
        $st = ScheduledTask::factory()->create();

        $response = $this->patchJson("/api/scheduled-tasks/{$st->id}/reschedule", [
            'action' => 'to_time',
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.start_time', '14:00:00')
            ->assertJsonPath('data.end_time', '15:00:00');
    }

    public function test_reschedule_invalid_action_returns_422(): void
    {
        $st = ScheduledTask::factory()->create();

        $response = $this->patchJson("/api/scheduled-tasks/{$st->id}/reschedule", [
            'action' => 'something_made_up',
        ]);

        $response->assertStatus(422);
    }

    public function test_reschedule_to_time_rejects_end_before_start(): void
    {
        $st = ScheduledTask::factory()->create();

        $response = $this->patchJson("/api/scheduled-tasks/{$st->id}/reschedule", [
            'action' => 'to_time',
            'start_time' => '15:00:00',
            'end_time' => '14:00:00',
        ]);

        $response->assertStatus(422);
    }
}
