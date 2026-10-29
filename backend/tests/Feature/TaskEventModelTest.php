<?php

namespace Tests\Feature;

use App\Enums\TaskActor;
use App\Enums\TaskEventType;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskEventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_belongs_to_task_and_casts_enums(): void
    {
        $task = Task::factory()->create();

        $event = $task->events()->create([
            'actor' => TaskActor::KIRA,
            'event_type' => TaskEventType::CREATED,
            'payload' => ['title' => ['old' => null, 'new' => 'X']],
        ]);

        $this->assertSame(TaskActor::KIRA, $event->fresh()->actor);
        $this->assertSame(TaskEventType::CREATED, $event->fresh()->event_type);
        $this->assertEquals(['title' => ['old' => null, 'new' => 'X']], $event->fresh()->payload);
        $this->assertNotNull($event->created_at);
        $this->assertCount(1, $task->events);
    }
}
