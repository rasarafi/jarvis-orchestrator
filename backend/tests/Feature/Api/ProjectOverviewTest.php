<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\SessionRecap;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/projects/{slug}/overview — bundled header stats for the
 * project detail page (task counts, CC cost stats, 7d daily costs,
 * last recap timestamp).
 */
class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function seedCcSession(string $decodedPath, float $cost, int $daysAgo = 0): void
    {
        $ccProjectId = DB::table('sessions.projects')->where('decoded_path', $decodedPath)->value('id')
            ?? DB::table('sessions.projects')->insertGetId([
                'encoded_path' => str_replace('/', '-', $decodedPath),
                'decoded_path' => $decodedPath,
                'display_name' => basename($decodedPath),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('sessions.sessions')->insert([
            'session_id' => 'sess-' . uniqid(),
            'project_id' => $ccProjectId,
            'file_path' => '/tmp/' . uniqid() . '.jsonl',
            'file_size' => 1024,
            'file_mtime' => now()->getTimestampMs(),
            'first_prompt_timestamp' => now()->subDays($daysAgo)->setTime(11, 0),
            'last_activity_timestamp' => now()->subDays($daysAgo)->setTime(12, 0),
            'message_count' => 10,
            'estimated_cost_usd' => $cost,
            'total_input_tokens' => 1000,
            'total_output_tokens' => 500,
            'indexed_at' => now(),
        ]);
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/projects/nope/overview')->assertNotFound();
    }

    public function test_overview_bundles_task_counts_costs_and_last_recap(): void
    {
        $project = Project::factory()->create([
            'slug' => 'demo',
            'local_path' => '/home/dev/projects/demo',
        ]);
        $other = Project::factory()->create(['local_path' => '/home/dev/projects/other']);

        Task::factory()->count(3)->create(['project_id' => $project->id]);
        Task::factory()->create(['project_id' => $project->id, 'status' => 'done', 'completed_at' => now()]);
        Task::factory()->create(['project_id' => $other->id]);

        $recap = SessionRecap::create([
            'project_id' => $project->id,
            'cc_session_id' => 'sess-recap-1',
            'kind' => 'work',
            'status' => 'ok',
            'headline' => 'Did things',
            'ended_at' => now()->subHours(2),
        ]);

        $this->seedCcSession('/home/dev/projects/demo', 2.5, 0);
        $this->seedCcSession('/home/dev/projects/demo', 1.5, 2);
        $this->seedCcSession('/home/dev/projects/other', 9.0, 0);

        $data = $this->getJson('/api/projects/demo/overview')
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $data['stats']['open_tasks']);
        $this->assertSame(4, $data['stats']['total_tasks']);
        $this->assertSame($recap->ended_at->toISOString(), $data['stats']['last_recap_at']);

        // costs come from the project's CC sessions only, rolling 7 days
        $this->assertEqualsWithDelta(4.0, $data['stats']['cost_7d'], 0.001);

        // 7 daily buckets, oldest first, keyed by date
        $this->assertCount(7, $data['daily_costs']);
        $today = collect($data['daily_costs'])->last();
        $this->assertSame(now()->toDateString(), $today['date']);
        $this->assertEqualsWithDelta(2.5, $today['cost'], 0.001);
        $twoDaysAgo = collect($data['daily_costs'])->firstWhere('date', now()->subDays(2)->toDateString());
        $this->assertEqualsWithDelta(1.5, $twoDaysAgo['cost'], 0.001);
    }

    public function test_overview_degrades_gracefully_without_cc_data(): void
    {
        Project::factory()->create(['slug' => 'bare', 'local_path' => '/home/dev/projects/bare']);

        $data = $this->getJson('/api/projects/bare/overview')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['stats']['open_tasks']);
        $this->assertNull($data['stats']['last_recap_at']);
        $this->assertEqualsWithDelta(0.0, $data['stats']['cost_7d'], 0.001);
        $this->assertCount(7, $data['daily_costs']);
        $this->assertEqualsWithDelta(0.0, collect($data['daily_costs'])->sum('cost'), 0.001);
    }
}
