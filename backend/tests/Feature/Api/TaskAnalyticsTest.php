<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_pressure_flow_age_and_deadlines(): void
    {
        $fa = Project::factory()->create(['slug' => 'fa-demo', 'name' => 'FA Demo', 'group_key' => 'fa']);

        Task::factory()->count(2)->urgent()->create(['project_id' => $fa->id]);
        Task::factory()->high()->create(['project_id' => $fa->id]);
        Task::factory()->create(['project_id' => $fa->id, 'deadline' => now()->addDays(3)]);
        Task::factory()->create([
            'project_id' => $fa->id,
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $response = $this->getJson('/api/analytics/tasks');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'stats' => ['open', 'urgent', 'blocked', 'oldest', 'urgent_top_project'],
                'pressure' => [['slug', 'display_name', 'counts', 'total', 'score', 'quiet']],
                'flow' => [['week', 'created', 'completed', 'open_after']],
                'age' => [['id', 'title', 'priority', 'age_days']],
                'deadlines' => ['total_open', 'with_deadline', 'without_deadline', 'overdue', 'items'],
            ],
        ]);

        // Done tasks are excluded from every open-side figure.
        $this->assertSame(4, $response->json('data.stats.open'));
        $this->assertSame(2, $response->json('data.stats.urgent'));
        $this->assertCount(4, $response->json('data.age'));

        // urgent×4 + high×2 + medium×1 = 8 + 2 + 1
        $this->assertSame(11, $response->json('data.pressure.0.score'));

        $this->assertSame(1, $response->json('data.deadlines.with_deadline'));
        $this->assertSame(3, $response->json('data.deadlines.without_deadline'));
    }

    public function test_cumulative_backlog_ends_on_the_open_count(): void
    {
        $project = Project::factory()->create();
        Task::factory()->count(3)->create(['project_id' => $project->id]);
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $data = $this->getJson('/api/analytics/tasks')->json('data');
        $lastWeek = end($data['flow']);

        $this->assertSame($data['stats']['open'], $lastWeek['open_after']);
    }

    public function test_archived_tasks_are_excluded(): void
    {
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id]);
        Task::factory()->create(['project_id' => $project->id, 'archived_at' => now()]);

        $data = $this->getJson('/api/analytics/tasks')->json('data');

        $this->assertSame(1, $data['stats']['open']);
        $this->assertCount(1, $data['age']);
    }

    public function test_group_filter_scopes_every_figure(): void
    {
        $fa = Project::factory()->create(['group_key' => 'fa']);
        $mfb = Project::factory()->create(['group_key' => 'mfb']);
        Task::factory()->count(2)->create(['project_id' => $fa->id]);
        Task::factory()->count(5)->create(['project_id' => $mfb->id]);

        $data = $this->getJson('/api/analytics/tasks?group=fa')->json('data');

        $this->assertSame(2, $data['stats']['open']);
        $this->assertCount(1, $data['pressure']);
        $this->assertSame((string) $fa->slug, $data['pressure'][0]['slug']);
    }

    public function test_rejects_an_unknown_group(): void
    {
        $this->getJson('/api/analytics/tasks?group=nope')->assertStatus(422);
    }

    public function test_handles_an_empty_backlog(): void
    {
        $data = $this->getJson('/api/analytics/tasks')->json('data');

        $this->assertSame(0, $data['stats']['open']);
        $this->assertNull($data['stats']['oldest']);
        $this->assertNull($data['stats']['urgent_top_project']);
        $this->assertSame([], $data['pressure']);
        $this->assertSame([], $data['age']);
    }
}
