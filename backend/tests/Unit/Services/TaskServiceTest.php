<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TaskService::class);
    }

    public function test_create_task_defaults_source_to_jarvis_when_omitted(): void
    {
        // The remote's `tasks.source` column is NOT NULL — a task pushed
        // with source=NULL 500s the sync. The frontend UI never sends
        // `source`, so this default is what makes every UI-created task
        // syncable instead of poisoning the push.
        $task = $this->service->createTask(null, [
            'title' => 'UI-created task', 'status' => 'todo', 'priority' => 'medium',
        ]);

        $this->assertSame('jarvis', $task->source->value);
    }

    public function test_create_task_respects_explicit_source(): void
    {
        $task = $this->service->createTask(null, [
            'title' => 'Kira task', 'status' => 'todo', 'priority' => 'medium',
            'source' => 'kira',
        ]);

        $this->assertSame('kira', $task->source->value);
    }

    public function test_mark_complete_sets_status_and_timestamp(): void
    {
        $task = Task::factory()->create(['status' => 'todo']);

        $this->service->markComplete($task);

        $task->refresh();
        $this->assertEquals('done', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_mark_in_progress(): void
    {
        $task = Task::factory()->create(['status' => 'todo']);

        $this->service->markInProgress($task);

        $this->assertEquals('in_progress', $task->fresh()->status);
    }

    public function test_mark_blocked_sets_reason(): void
    {
        $task = Task::factory()->create(['status' => 'todo']);

        $this->service->markBlocked($task, 'Need API keys');

        $task->refresh();
        $this->assertEquals('blocked', $task->status);
        $this->assertEquals('Need API keys', $task->blocker_reason);
    }

    public function test_review_is_an_open_status_and_clears_completion(): void
    {
        // Review sits between working and done: the task is still open, and moving a
        // completed task back into review must drop its completed_at.
        $project = Project::factory()->create();
        $task = Task::factory()->done()->create(['project_id' => $project->id]);

        $this->service->updateTask($task, ['status' => 'review']);

        $task->refresh();
        $this->assertEquals('review', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertCount(1, $this->service->getOpenTasks($project));
    }

    public function test_completing_from_review_sets_completed_at(): void
    {
        $task = Task::factory()->create(['status' => 'review']);

        $this->service->updateTask($task, ['status' => 'done']);

        $task->refresh();
        $this->assertEquals('done', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_get_open_tasks_excludes_done(): void
    {
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id, 'status' => 'todo']);
        Task::factory()->done()->create(['project_id' => $project->id]);

        $open = $this->service->getOpenTasks($project);

        $this->assertCount(1, $open);
    }

    public function test_get_open_tasks_sorted_by_priority(): void
    {
        $project = Project::factory()->create();
        $low = Task::factory()->low()->create(['project_id' => $project->id]);
        $urgent = Task::factory()->urgent()->create(['project_id' => $project->id]);

        $open = $this->service->getOpenTasks($project);

        $this->assertEquals($urgent->id, $open->first()->id);
    }

    public function test_get_all_open_tasks_spans_projects(): void
    {
        $p1 = Project::factory()->create();
        $p2 = Project::factory()->create();
        Task::factory()->create(['project_id' => $p1->id]);
        Task::factory()->create(['project_id' => $p2->id]);

        $all = $this->service->getAllOpenTasks();

        $this->assertCount(2, $all);
    }

    public function test_create_task_sets_project_id(): void
    {
        $project = Project::factory()->create();

        $task = $this->service->createTask($project, [
            'title' => 'New task',
            'priority' => 'high',
        ]);

        $this->assertEquals($project->id, $task->project_id);
        $this->assertEquals('high', $task->priority);
    }

    public function test_create_task_without_project(): void
    {
        $task = $this->service->createTask(null, [
            'title' => 'Unassigned task',
            'priority' => 'medium',
        ]);

        $this->assertNull($task->project_id);
        $this->assertEquals('Unassigned task', $task->title);
        $this->assertDatabaseHas('tasks', ['title' => 'Unassigned task', 'project_id' => null]);
    }
}
