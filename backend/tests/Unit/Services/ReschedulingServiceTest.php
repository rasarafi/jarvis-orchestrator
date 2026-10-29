<?php

namespace Tests\Unit\Services;

use App\Enums\RescheduleAction;
use App\Models\ScheduledTask;
use App\Models\TimeBlock;
use App\Services\ReschedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReschedulingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReschedulingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReschedulingService::class);
    }

    public function test_push_1hr_shifts_start_and_end(): void
    {
        $st = ScheduledTask::factory()->create([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $updated = $this->service->reschedule($st, RescheduleAction::PUSH_1HR);

        $this->assertSame('10:00:00', $updated->start_time);
        $this->assertSame('11:00:00', $updated->end_time);
    }

    public function test_push_evening_moves_to_evening_block(): void
    {
        $eveningBlock = TimeBlock::factory()->evening()->create();
        $st = ScheduledTask::factory()->create([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $updated = $this->service->reschedule($st, RescheduleAction::PUSH_EVENING);

        $this->assertSame($eveningBlock->id, $updated->time_block_id);
        $this->assertSame('16:00:00', $updated->start_time);
    }

    public function test_push_evening_throws_when_no_evening_block(): void
    {
        $st = ScheduledTask::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->reschedule($st, RescheduleAction::PUSH_EVENING);
    }

    public function test_push_tomorrow_advances_date(): void
    {
        $st = ScheduledTask::factory()->forDate('2026-04-18')->create();

        $updated = $this->service->reschedule($st, RescheduleAction::PUSH_TOMORROW);

        $this->assertSame('2026-04-19', $updated->scheduled_date->toDateString());
    }

    public function test_skip_deletes_the_scheduled_record(): void
    {
        $st = ScheduledTask::factory()->create();

        $this->service->reschedule($st, RescheduleAction::SKIP);

        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $st->id]);
    }

    public function test_to_backlog_deletes_the_scheduled_record(): void
    {
        $st = ScheduledTask::factory()->create();

        $this->service->reschedule($st, RescheduleAction::TO_BACKLOG);

        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $st->id]);
    }

    public function test_to_time_with_payload_sets_explicit_time(): void
    {
        $st = ScheduledTask::factory()->create([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $updated = $this->service->reschedule($st, RescheduleAction::TO_TIME, [
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
        ]);

        $this->assertSame('14:00:00', $updated->start_time);
        $this->assertSame('15:30:00', $updated->end_time);
    }
}
