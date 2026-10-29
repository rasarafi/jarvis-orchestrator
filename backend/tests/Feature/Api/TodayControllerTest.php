<?php

namespace Tests\Feature\Api;

use App\Models\FocusSession;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Task;
use App\Models\TimeBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_returns_complete_payload_for_default_date(): void
    {
        $project = Project::factory()->create(['slug' => 'fa']);
        $task = Task::factory()->create(['project_id' => $project->id]);
        TimeBlock::factory()->create();
        ScheduledTask::factory()->forDate(now()->toDateString())->create(['task_id' => $task->id]);

        $response = $this->getJson('/api/today');

        $response->assertOk()->assertJsonStructure([
            'date',
            'blocks',
            'scheduled_tasks',
            'active_focus_session',
            'project_mix',
        ]);
    }

    public function test_today_accepts_date_param(): void
    {
        $task = Task::factory()->create();
        ScheduledTask::factory()->forDate('2026-04-19')->create(['task_id' => $task->id]);

        $response = $this->getJson('/api/today?date=2026-04-19');

        $response->assertOk()->assertJsonPath('date', '2026-04-19');
    }

    public function test_today_returns_active_focus_session_when_present(): void
    {
        $task = Task::factory()->create();
        $session = FocusSession::factory()->create(['task_id' => $task->id]);

        $response = $this->getJson('/api/today');

        $response->assertOk()->assertJsonPath('active_focus_session.id', $session->id);
    }

    public function test_today_returns_null_focus_session_when_none_open(): void
    {
        $response = $this->getJson('/api/today');

        $response->assertOk()->assertJsonPath('active_focus_session', null);
    }

    public function test_project_mix_includes_all_active_projects(): void
    {
        Project::factory()->create(['slug' => 'a', 'status' => 'active']);
        Project::factory()->create(['slug' => 'b', 'status' => 'active']);

        $response = $this->getJson('/api/today');

        $data = $response->json('project_mix');
        $slugs = array_column($data, 'slug');
        $this->assertContains('a', $slugs);
        $this->assertContains('b', $slugs);
    }

    public function test_project_mix_marks_projects_with_today_tasks(): void
    {
        $a = Project::factory()->create(['slug' => 'a']);
        $b = Project::factory()->create(['slug' => 'b']);
        $task = Task::factory()->create(['project_id' => $a->id]);
        ScheduledTask::factory()->forDate(now()->toDateString())->create(['task_id' => $task->id]);

        $response = $this->getJson('/api/today');

        $mix = collect($response->json('project_mix'))->keyBy('slug');
        $this->assertSame(1, $mix['a']['scheduled_count']);
        $this->assertSame(0, $mix['b']['scheduled_count']);
    }
}
