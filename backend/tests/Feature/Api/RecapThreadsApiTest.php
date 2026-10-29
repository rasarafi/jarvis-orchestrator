<?php // backend/tests/Feature/Api/RecapThreadsApiTest.php
namespace Tests\Feature\Api;

use App\Enums\RecapKind;
use App\Enums\RecapThreadStatus;
use App\Models\Project;
use App\Models\RecapThread;
use App\Models\SessionRecap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecapThreadsApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeThread(array $overrides = []): RecapThread
    {
        $recap = SessionRecap::create([
            'cc_session_id' => $overrides['cc_session_id'] ?? 'cc-'.fake()->uuid(),
            'kind' => RecapKind::WORK,
        ]);

        return RecapThread::create(array_merge([
            'source_recap_id' => $recap->id,
            'text' => 'a dangling thread',
            'status' => RecapThreadStatus::OPEN,
        ], collect($overrides)->except('cc_session_id')->all()));
    }

    public function test_threads_returns_active_stale_split_with_resume_id(): void
    {
        $project = Project::factory()->create();
        $fresh = $this->makeThread(['cc_session_id' => 'cc-fresh', 'project_id' => $project->id, 'text' => 'fresh thread']);
        $this->makeThread(['text' => 'stale thread', 'created_at' => now()->subDays(20)]);
        $this->makeThread(['text' => 'gone', 'status' => RecapThreadStatus::DISMISSED]);

        $response = $this->getJson('/api/recaps/threads');

        $response->assertOk()
            ->assertJsonCount(1, 'data.active')
            ->assertJsonPath('data.active.0.id', $fresh->id)
            ->assertJsonPath('data.active.0.text', 'fresh thread')
            ->assertJsonPath('data.active.0.project.id', $project->id)
            ->assertJsonPath('data.active.0.source_recap.cc_session_id', 'cc-fresh')
            ->assertJsonPath('data.stale_count', 1)
            ->assertJsonPath('data.total_open', 2);
    }

    public function test_threads_active_sorted_most_recent_first(): void
    {
        $old = $this->makeThread(['text' => 'older', 'created_at' => now()->subDays(3)]);
        $new = $this->makeThread(['text' => 'newer', 'created_at' => now()->subHours(2)]);

        $this->getJson('/api/recaps/threads')
            ->assertOk()
            ->assertJsonPath('data.active.0.id', $new->id)
            ->assertJsonPath('data.active.1.id', $old->id);
    }

    public function test_project_refs_include_group_key_for_group_filtering(): void
    {
        $project = Project::factory()->create(['group_key' => 'fa']);
        $this->makeThread(['project_id' => $project->id]);
        SessionRecap::create([
            'cc_session_id' => 'grp-1', 'kind' => RecapKind::WORK,
            'project_id' => $project->id, 'ended_at' => now()->subHours(1),
        ]);

        $this->getJson('/api/recaps/threads')->assertOk()->assertJsonPath('data.active.0.project.group_key', 'fa');
        $this->getJson('/api/recaps?range=24h')->assertOk()->assertJsonPath('data.recaps.0.project.group_key', 'fa');
    }

    public function test_resolve_thread_marks_it_resolved(): void
    {
        $thread = $this->makeThread();

        $this->patchJson("/api/recaps/threads/{$thread->id}/resolve")->assertOk();

        $this->assertSame(RecapThreadStatus::RESOLVED, $thread->fresh()->status);
    }

    public function test_dismiss_stale_bulk_dismisses_only_stale_open_threads(): void
    {
        $fresh = $this->makeThread(['text' => 'fresh']);
        $stale1 = $this->makeThread(['text' => 'stale1', 'created_at' => now()->subDays(20)]);
        $stale2 = $this->makeThread(['text' => 'stale2', 'created_at' => now()->subDays(40)]);
        $resolved = $this->makeThread(['text' => 'already resolved', 'status' => RecapThreadStatus::RESOLVED, 'created_at' => now()->subDays(30)]);

        $response = $this->postJson('/api/recaps/threads/dismiss-stale');

        $response->assertOk()->assertJsonPath('data.affected', 2);
        $ids = $response->json('data.ids');
        $this->assertEqualsCanonicalizing([$stale1->id, $stale2->id], $ids);
        $this->assertSame(RecapThreadStatus::OPEN, $fresh->fresh()->status);
        $this->assertSame(RecapThreadStatus::DISMISSED, $stale1->fresh()->status);
        $this->assertSame(RecapThreadStatus::DISMISSED, $stale2->fresh()->status);
        $this->assertSame(RecapThreadStatus::RESOLVED, $resolved->fresh()->status);
    }

    public function test_dismiss_stale_accepts_days_override(): void
    {
        $recent = $this->makeThread(['text' => 'two days old', 'created_at' => now()->subDays(2)]);
        $week = $this->makeThread(['text' => 'six days old', 'created_at' => now()->subDays(6)]);

        $this->postJson('/api/recaps/threads/dismiss-stale', ['days' => 3])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);

        $this->assertSame(RecapThreadStatus::OPEN, $recent->fresh()->status);
        $this->assertSame(RecapThreadStatus::DISMISSED, $week->fresh()->status);
        $this->postJson('/api/recaps/threads/dismiss-stale', ['days' => 0])->assertUnprocessable();
    }

    public function test_restore_reopens_dismissed_threads_only(): void
    {
        $dismissed = $this->makeThread(['status' => RecapThreadStatus::DISMISSED]);
        $resolved = $this->makeThread(['status' => RecapThreadStatus::RESOLVED]);

        $response = $this->postJson('/api/recaps/threads/restore', ['ids' => [$dismissed->id, $resolved->id]]);

        $response->assertOk()->assertJsonPath('data.restored', 1);
        $this->assertSame(RecapThreadStatus::OPEN, $dismissed->fresh()->status);
        $this->assertSame(RecapThreadStatus::RESOLVED, $resolved->fresh()->status);
    }

    public function test_restore_validates_ids(): void
    {
        $this->postJson('/api/recaps/threads/restore', ['ids' => 'not-an-array'])->assertUnprocessable();
        $this->postJson('/api/recaps/threads/restore', [])->assertUnprocessable();
    }

    public function test_new_threads_are_aged_from_session_end_not_summarize_time(): void
    {
        // Backfill scenario: a session that ended 20 days ago gets summarized TODAY.
        // Its dangling threads must immediately count as stale, not fresh.
        \Illuminate\Support\Facades\DB::table('sessions.projects')->insertGetId([
            'encoded_path' => '-x', 'decoded_path' => '/x', 'display_name' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ccProjectId = \Illuminate\Support\Facades\DB::table('sessions.projects')->value('id');
        \Illuminate\Support\Facades\DB::table('sessions.sessions')->insert([
            'session_id' => 'old-sess', 'project_id' => $ccProjectId,
            'file_path' => '/tmp/old-sess.jsonl', 'file_size' => 100, 'file_mtime' => now()->timestamp,
            'first_prompt' => 'old work', 'first_prompt_timestamp' => now()->subDays(20)->subHours(2),
            'last_activity_timestamp' => now()->subDays(20), 'message_count' => 40, 'indexed_at' => now(),
        ]);
        $cli = \Mockery::mock(\App\Services\ClaudeCliService::class);
        $cli->shouldReceive('promptForJson')->once()->andReturn([
            'headline' => 'Old work', 'bullets' => [], 'next_action' => null,
            'decisions' => [], 'open_threads' => ['left dangling back then'], 'resolved_thread_ids' => [],
        ]);
        $this->app->instance(\App\Services\ClaudeCliService::class, $cli);

        $this->postJson('/api/recaps/run', ['session_id' => 'old-sess'])->assertOk();

        $thread = RecapThread::where('text', 'left dangling back then')->firstOrFail();
        $this->assertTrue($thread->created_at->lessThan(now()->subDays(19)), 'thread age should anchor to session end');
        $this->getJson('/api/recaps/threads')
            ->assertOk()
            ->assertJsonPath('data.stale_count', 1)
            ->assertJsonCount(0, 'data.active');
    }

    public function test_overview_includes_last_summarized_at(): void
    {
        SessionRecap::create([
            'cc_session_id' => 'sum-old', 'kind' => RecapKind::WORK,
            'ended_at' => now()->subHours(3), 'summarized_at' => now()->subHours(3),
        ]);
        $latest = now()->subMinutes(5)->startOfSecond();
        SessionRecap::create([
            'cc_session_id' => 'sum-new', 'kind' => RecapKind::WORK,
            'ended_at' => now()->subHours(1), 'summarized_at' => $latest,
        ]);

        $this->getJson('/api/recaps?range=24h')
            ->assertOk()
            ->assertJsonPath('data.last_summarized_at', $latest->toIso8601String());
    }
}
