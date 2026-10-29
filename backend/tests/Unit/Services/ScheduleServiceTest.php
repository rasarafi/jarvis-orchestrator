<?php

namespace Tests\Unit\Services;

use App\Models\ScheduledTask;
use App\Models\Task;
use App\Models\TimeBlock;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ScheduleService::class);
    }

    public function test_schedule_creates_record(): void
    {
        $task = Task::factory()->create();
        $block = TimeBlock::factory()->create();

        $st = $this->service->schedule($task, [
            'time_block_id' => $block->id,
            'scheduled_date' => '2026-04-18',
            'start_time' => '09:00:00',
            'end_time' => '10:30:00',
        ]);

        $this->assertSame($task->id, $st->task_id);
        $this->assertSame($block->id, $st->time_block_id);
        $this->assertSame('09:00:00', $st->start_time);
    }

    public function test_get_schedule_for_date_returns_ordered(): void
    {
        $task1 = Task::factory()->create();
        $task2 = Task::factory()->create();
        ScheduledTask::factory()->forDate('2026-04-18')->create(['task_id' => $task1->id, 'start_time' => '10:00:00']);
        ScheduledTask::factory()->forDate('2026-04-18')->create(['task_id' => $task2->id, 'start_time' => '08:00:00']);
        ScheduledTask::factory()->forDate('2026-04-19')->create();

        $items = $this->service->getScheduleForDate('2026-04-18');

        $this->assertCount(2, $items);
        $this->assertSame('08:00:00', $items->first()->start_time);
    }

    public function test_unschedule_removes_record(): void
    {
        $st = ScheduledTask::factory()->create();

        $this->service->unschedule($st);

        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $st->id]);
    }
}
