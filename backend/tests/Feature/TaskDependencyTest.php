<?php

namespace Tests\Feature;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Services\TaskDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskDependencyTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TaskDependencyService
    {
        return app(TaskDependencyService::class);
    }

    public function test_add_and_remove_dependency_with_events(): void
    {
        [$a, $b] = Task::factory()->count(2)->create();

        $this->service()->add($a, $b);
        $this->assertTrue($a->fresh()->dependencies->contains('id', $b->id));
        $this->assertTrue($b->fresh()->dependents->contains('id', $a->id));
        $this->assertDatabaseHas('task_events', [
            'task_id' => $a->id, 'event_type' => TaskEventType::DEPENDENCY_ADDED->value,
        ]);

        $this->service()->remove($a, $b);
        $this->assertCount(0, $a->fresh()->dependencies);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $a->id, 'event_type' => TaskEventType::DEPENDENCY_REMOVED->value,
        ]);
    }

    public function test_self_dependency_rejected(): void
    {
        $a = Task::factory()->create();
        $this->expectException(ValidationException::class);
        $this->service()->add($a, $a);
    }

    public function test_duplicate_rejected(): void
    {
        [$a, $b] = Task::factory()->count(2)->create();
        $this->service()->add($a, $b);
        $this->expectException(ValidationException::class);
        $this->service()->add($a, $b);
    }

    public function test_cycle_rejected(): void
    {
        [$a, $b, $c] = Task::factory()->count(3)->create();
        $this->service()->add($a, $b); // a depends on b
        $this->service()->add($b, $c); // b depends on c

        $this->expectException(ValidationException::class);
        $this->service()->add($c, $a); // c depends on a -> cycle a>b>c>a
    }
}
