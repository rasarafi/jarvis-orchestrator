<?php

namespace Tests\Unit\Services;

use App\Enums\FocusSessionStatus;
use App\Enums\TaskStatus;
use App\Models\FocusSession;
use App\Models\Task;
use App\Services\FocusSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FocusSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FocusSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FocusSessionService::class);
    }

    public function test_start_creates_active_session_and_marks_task_in_progress(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::TODO->value]);

        $session = $this->service->start($task);

        $this->assertSame(FocusSessionStatus::ACTIVE, $session->status);
        $this->assertSame($task->id, $session->task_id);
        $this->assertSame(TaskStatus::IN_PROGRESS->value, $task->fresh()->status);
    }

    public function test_start_returns_existing_open_session_for_same_task(): void
    {
        $task = Task::factory()->create();
        $first = $this->service->start($task);

        $second = $this->service->start($task);

        $this->assertSame($first->id, $second->id);
    }

    public function test_heartbeat_updates_elapsed_seconds(): void
    {
        $task = Task::factory()->create();
        $session = $this->service->start($task);

        $updated = $this->service->heartbeat($session, 600);

        $this->assertSame(600, $updated->elapsed_seconds);
    }

    public function test_end_closes_session(): void
    {
        $task = Task::factory()->create();
        $session = $this->service->start($task);

        $ended = $this->service->end($session, elapsedSeconds: 1500);

        $this->assertSame(FocusSessionStatus::ENDED, $ended->status);
        $this->assertNotNull($ended->ended_at);
        $this->assertSame(1500, $ended->elapsed_seconds);
    }

    public function test_active_session_returns_null_when_none(): void
    {
        $this->assertNull($this->service->activeSession());
    }

    public function test_active_session_returns_open_session(): void
    {
        $task = Task::factory()->create();
        $session = $this->service->start($task);

        $this->assertSame($session->id, $this->service->activeSession()->id);
    }
}
