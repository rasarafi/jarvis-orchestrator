<?php

namespace Tests\Unit\Models;

use App\Models\ScheduledTask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_task_and_block(): void
    {
        $st = ScheduledTask::factory()->create();

        $this->assertNotNull($st->task);
        $this->assertNotNull($st->timeBlock);
    }

    public function test_for_date_filters_correctly(): void
    {
        ScheduledTask::factory()->forDate('2026-04-18')->create();
        ScheduledTask::factory()->forDate('2026-04-19')->create();

        $this->assertCount(1, ScheduledTask::forDate('2026-04-18')->get());
    }

    public function test_ordered_sorts_by_start_then_position(): void
    {
        $task = Task::factory()->create();
        ScheduledTask::factory()->create(['task_id' => $task->id, 'start_time' => '10:00:00', 'position' => 0]);
        ScheduledTask::factory()->create(['task_id' => $task->id, 'start_time' => '09:00:00', 'position' => 1]);
        ScheduledTask::factory()->create(['task_id' => $task->id, 'start_time' => '09:00:00', 'position' => 0]);

        $sorted = ScheduledTask::ordered()->get()->map(fn($s) => $s->start_time . '/' . $s->position)->all();

        $this->assertSame(['09:00:00/0', '09:00:00/1', '10:00:00/0'], $sorted);
    }

    public function test_cascade_delete_when_task_deleted(): void
    {
        $st = ScheduledTask::factory()->create();
        $taskId = $st->task_id;

        Task::find($taskId)->delete();

        $this->assertSoftDeleted('tasks', ['id' => $taskId]);
        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $st->id]);
    }
}
