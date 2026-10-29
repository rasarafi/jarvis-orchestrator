<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeTask(): Task
    {
        $project = Project::create([
            'slug' => 'soft-delete-test', 'name' => 'SD', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => '/tmp/x', 'status' => 'active',
        ]);

        return Task::create([
            'project_id' => $project->id, 'title' => 'to delete',
            'status' => 'todo', 'priority' => 'medium',
        ]);
    }

    public function test_delete_is_soft_and_excluded_from_default_queries(): void
    {
        $task = $this->makeTask();
        $task->delete();

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertNull(Task::find($task->id));
        $this->assertNotNull(Task::withTrashed()->find($task->id));
    }

    public function test_api_destroy_soft_deletes(): void
    {
        $task = $this->makeTask();

        $this->deleteJson('/api/tasks/' . $task->id)->assertSuccessful();

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_deleting_task_removes_its_scheduled_tasks(): void
    {
        $task = $this->makeTask();
        $scheduledTask = ScheduledTask::factory()->create(['task_id' => $task->id]);

        $task->delete();

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('scheduled_tasks', ['task_id' => $task->id]);
        $this->assertNull(ScheduledTask::find($scheduledTask->id));
    }
}
