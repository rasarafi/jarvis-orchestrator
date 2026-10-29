<?php // backend/tests/Feature/Api/RecapControllerTest.php
namespace Tests\Feature\Api;

use App\Enums\RecapKind;
use App\Enums\RecapStatus;
use App\Enums\RecapThreadStatus;
use App\Models\Project;
use App\Models\RecapThread;
use App\Models\SessionRecap;
use App\Services\ClaudeCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RecapControllerTest extends TestCase
{
    use RefreshDatabase;

    private ?int $ccProjectId = null;

    private function ccProjectId(): int
    {
        if ($this->ccProjectId !== null) {
            return $this->ccProjectId;
        }

        return $this->ccProjectId = DB::table('sessions.projects')->insertGetId([
            'encoded_path' => '-home-dev-projects-jarvis-v2',
            'decoded_path' => '/home/dev/projects/jarvis-v2',
            'display_name' => 'jarvis-v2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedCcSession(string $sessionId, array $overrides = []): void
    {
        DB::table('sessions.sessions')->insert(array_merge([
            'session_id' => $sessionId,
            'project_id' => $this->ccProjectId(),
            'file_path' => "/tmp/{$sessionId}.jsonl",
            'file_size' => 100,
            'file_mtime' => now()->timestamp,
            'first_prompt' => 'do some work',
            'first_prompt_timestamp' => now()->subHours(4),
            'last_activity_timestamp' => now()->subHours(2),
            'message_count' => 40,
            'indexed_at' => now(),
        ], $overrides));
    }

    public function test_run_processes_batch_and_reports_remaining(): void
    {
        $this->seedCcSession('s1');
        $this->seedCcSession('s2', ['message_count' => 3, 'first_prompt_timestamp' => now()->subHours(2)->subMinutes(5)]); // chore
        $cli = Mockery::mock(ClaudeCliService::class);
        // Work sessions are now summarized concurrently via the batch method.
        $cli->shouldReceive('promptForJsonBatch')->once()->andReturnUsing(fn (array $prompts) => collect($prompts)
            ->mapWithKeys(fn ($p, $id) => [$id => ['ok' => true, 'data' => [
                'headline' => 'Did some work', 'bullets' => ['a'], 'next_action' => null,
                'decisions' => [], 'open_threads' => [], 'resolved_thread_ids' => [],
            ], 'error' => null]])
            ->all());
        $this->app->instance(ClaudeCliService::class, $cli);

        $response = $this->postJson('/api/recaps/run', ['model' => 'haiku', 'limit' => 5]);

        $response->assertOk()->assertJsonPath('data.processed', 2)->assertJsonPath('data.remaining', 0);
        $this->assertDatabaseHas('session_recaps', ['cc_session_id' => 's1', 'kind' => 'work']);
        $this->assertDatabaseHas('session_recaps', ['cc_session_id' => 's2', 'kind' => 'chore']);
    }

    public function test_run_force_session_bypasses_chore_gate(): void
    {
        $this->seedCcSession('s3', ['message_count' => 3]);
        $cli = Mockery::mock(ClaudeCliService::class);
        $cli->shouldReceive('promptForJson')->once()->andReturn([
            'headline' => 'Tiny but forced', 'bullets' => [], 'next_action' => null,
            'decisions' => [], 'open_threads' => [], 'resolved_thread_ids' => [],
        ]);
        $this->app->instance(ClaudeCliService::class, $cli);

        $this->postJson('/api/recaps/run', ['session_id' => 's3'])->assertOk();

        $this->assertDatabaseHas('session_recaps', ['cc_session_id' => 's3', 'kind' => 'work', 'headline' => 'Tiny but forced']);
    }

    public function test_run_force_session_retries_failed_recap(): void
    {
        $this->seedCcSession('s-retry');
        SessionRecap::create(['cc_session_id' => 's-retry', 'kind' => RecapKind::WORK, 'status' => RecapStatus::FAILED, 'headline' => 'broke', 'error' => 'timeout']);
        $cli = Mockery::mock(ClaudeCliService::class);
        $cli->shouldReceive('promptForJson')->once()->andReturn([
            'headline' => 'Recovered on retry', 'bullets' => [], 'next_action' => null,
            'decisions' => [], 'open_threads' => [], 'resolved_thread_ids' => [],
        ]);
        $this->app->instance(ClaudeCliService::class, $cli);

        $this->postJson('/api/recaps/run', ['session_id' => 's-retry'])->assertOk();

        $this->assertDatabaseHas('session_recaps', ['cc_session_id' => 's-retry', 'status' => 'ok', 'headline' => 'Recovered on retry']);
    }

    public function test_run_returns_404_for_unknown_forced_session(): void
    {
        $this->postJson('/api/recaps/run', ['session_id' => 'no-such-session'])->assertNotFound();
    }

    public function test_run_validates_model(): void
    {
        $this->postJson('/api/recaps/run', ['model' => 'gpt5'])->assertUnprocessable();
    }

    public function test_index_returns_recaps_stats_and_skipped(): void
    {
        $project = Project::factory()->create();
        $work = SessionRecap::create([
            'cc_session_id' => 'w1', 'kind' => RecapKind::WORK, 'project_id' => $project->id,
            'headline' => 'Big work', 'bullets' => ['x'], 'facts' => ['tasks_closed' => 2],
            'duration_minutes' => 90, 'ended_at' => now()->subHours(2),
        ]);
        RecapThread::create(['source_recap_id' => $work->id, 'project_id' => $project->id, 'text' => 'open item', 'status' => RecapThreadStatus::OPEN]);
        SessionRecap::create(['cc_session_id' => 'c1', 'kind' => RecapKind::CHORE, 'headline' => 'git push', 'ended_at' => now()->subHours(3)]);
        SessionRecap::create(['cc_session_id' => 'f1', 'kind' => RecapKind::WORK, 'status' => RecapStatus::FAILED, 'headline' => 'broke', 'error' => 'timeout', 'ended_at' => now()->subHours(1)]);
        SessionRecap::create(['cc_session_id' => 'old', 'kind' => RecapKind::WORK, 'headline' => 'last week', 'ended_at' => now()->subDays(3)]);

        $response = $this->getJson('/api/recaps?range=24h');

        $response->assertOk()
            ->assertJsonCount(1, 'data.recaps')
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonCount(1, 'data.failed')
            ->assertJsonPath('data.stats.meaningful', 1)
            ->assertJsonPath('data.stats.open_threads', 1)
            ->assertJsonPath('data.recaps.0.threads.0.text', 'open item');

        $this->getJson('/api/recaps?range=week')->assertOk()->assertJsonCount(2, 'data.recaps');
        $this->getJson('/api/recaps?range=year')->assertUnprocessable();
    }

    public function test_rollups_merges_multi_session_projects_and_caches(): void
    {
        $busy = Project::factory()->create();
        $quiet = Project::factory()->create();
        SessionRecap::create([
            'cc_session_id' => 'ru1', 'kind' => RecapKind::WORK, 'project_id' => $busy->id,
            'headline' => 'API scaffolding', 'bullets' => ['routes'], 'ended_at' => now()->subHours(3),
        ]);
        SessionRecap::create([
            'cc_session_id' => 'ru2', 'kind' => RecapKind::WORK, 'project_id' => $busy->id,
            'headline' => 'Frontend wiring', 'bullets' => ['hooks'], 'ended_at' => now()->subHours(1),
        ]);
        // single session → no rollup for this project
        SessionRecap::create([
            'cc_session_id' => 'ru3', 'kind' => RecapKind::WORK, 'project_id' => $quiet->id,
            'headline' => 'Solo session', 'ended_at' => now()->subHours(2),
        ]);
        $cli = Mockery::mock(ClaudeCliService::class);
        $cli->shouldReceive('promptForJsonBatch')->once()->andReturnUsing(fn (array $prompts) => collect($prompts)
            ->mapWithKeys(fn ($p, $id) => [$id => ['ok' => true, 'data' => ['summary' => 'Built the feature end to end'], 'error' => null]])
            ->all());
        $this->app->instance(ClaudeCliService::class, $cli);

        $this->getJson('/api/recaps/rollups?range=24h')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project_id', $busy->id)
            ->assertJsonPath('data.0.sessions', 2)
            ->assertJsonPath('data.0.summary', 'Built the feature end to end');

        // same id set → served from cache, no second CLI batch (mock allows only one call)
        $this->getJson('/api/recaps/rollups?range=24h')->assertOk()
            ->assertJsonPath('data.0.summary', 'Built the feature end to end');

        $this->getJson('/api/recaps/rollups?range=year')->assertUnprocessable();
    }

    public function test_rollups_omits_projects_whose_merge_fails(): void
    {
        $project = Project::factory()->create();
        SessionRecap::create(['cc_session_id' => 'rf1', 'kind' => RecapKind::WORK, 'project_id' => $project->id, 'headline' => 'One', 'ended_at' => now()->subHours(2)]);
        SessionRecap::create(['cc_session_id' => 'rf2', 'kind' => RecapKind::WORK, 'project_id' => $project->id, 'headline' => 'Two', 'ended_at' => now()->subHours(1)]);
        $cli = Mockery::mock(ClaudeCliService::class);
        $cli->shouldReceive('promptForJsonBatch')->once()->andReturnUsing(fn (array $prompts) => collect($prompts)
            ->mapWithKeys(fn ($p, $id) => [$id => ['ok' => false, 'data' => null, 'error' => 'timeout']])
            ->all());
        $this->app->instance(ClaudeCliService::class, $cli);

        $this->getJson('/api/recaps/rollups?range=24h')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_threads_endpoints(): void
    {
        $recap = SessionRecap::create(['cc_session_id' => 't1', 'kind' => RecapKind::WORK]);
        $thread = RecapThread::create(['source_recap_id' => $recap->id, 'text' => 'dangling', 'status' => RecapThreadStatus::OPEN]);

        $this->getJson('/api/recaps/threads')->assertOk()->assertJsonCount(1, 'data.active');
        $this->patchJson("/api/recaps/threads/{$thread->id}/dismiss")->assertOk();
        $this->assertSame(RecapThreadStatus::DISMISSED, $thread->fresh()->status);
        $this->getJson('/api/recaps/threads')->assertOk()->assertJsonCount(0, 'data.active');
    }

    public function test_suggestions_returns_latest_next_action_per_project(): void
    {
        $project = Project::factory()->create();
        SessionRecap::create([
            'cc_session_id' => 'sg-old', 'kind' => RecapKind::WORK, 'project_id' => $project->id,
            'next_action' => 'older action', 'ended_at' => now()->subHours(20),
        ]);
        SessionRecap::create([
            'cc_session_id' => 'sg-new', 'kind' => RecapKind::WORK, 'project_id' => $project->id,
            'next_action' => 'newest action', 'ended_at' => now()->subHours(2),
        ]);
        SessionRecap::create([
            'cc_session_id' => 'sg-stale', 'kind' => RecapKind::WORK,
            'next_action' => 'too old', 'ended_at' => now()->subDays(3),
        ]);

        $response = $this->getJson('/api/recaps/suggestions');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.next_action', 'newest action');
    }
}
