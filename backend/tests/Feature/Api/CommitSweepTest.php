<?php // backend/tests/Feature/Api/CommitSweepTest.php
namespace Tests\Feature\Api;

use App\Enums\RecapModel;
use App\Enums\SweepMatchStatus;
use App\Enums\TaskActor;
use App\Models\Project;
use App\Models\SweepMatch;
use App\Models\SweepRun;
use App\Models\Task;
use App\Services\ClaudeCliService;
use App\Services\CommitSweepService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class CommitSweepTest extends TestCase
{
    use RefreshDatabase;

    /** git log output: sha US subject US iso-date RS — matches --pretty=format:%H%x1f%s%x1f%cI%x1e */
    private function gitLog(array $commits): string
    {
        return collect($commits)
            ->map(fn (array $c) => implode("\x1f", $c) . "\x1e")
            ->implode('');
    }

    private function mockCli(): Mockery\MockInterface
    {
        $cli = Mockery::mock(ClaudeCliService::class);
        $this->app->instance(ClaudeCliService::class, $cli);

        return $cli;
    }

    private function sweep(): CommitSweepService
    {
        return app(CommitSweepService::class);
    }

    public function test_mark_complete_with_meta_writes_evidence_into_task_event_payload(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'status' => 'todo']);

        app(TaskService::class)->markComplete($task, TaskActor::JARVIS, [
            'source' => 'commit_sweep',
            'commits' => ['a3f21c9'],
        ]);

        $event = $task->events()->where('event_type', 'completed')->firstOrFail();

        $this->assertSame('jarvis', $event->actor->value);
        $this->assertSame('commit_sweep', $event->payload['meta']['source']);
        $this->assertSame(['a3f21c9'], $event->payload['meta']['commits']);
    }

    public function test_run_matches_commits_to_tasks_and_drops_invalid_llm_entries(): void
    {
        $alpha = Project::factory()->create(['slug' => 'alpha', 'local_path' => '/repo/alpha']);
        $beta = Project::factory()->create(['slug' => 'beta', 'local_path' => '/repo/beta']);
        $t1 = Task::factory()->create(['project_id' => $alpha->id, 'status' => 'todo']);
        $t2 = Task::factory()->create(['project_id' => $alpha->id, 'status' => 'in_progress']);
        $t3 = Task::factory()->create(['project_id' => $beta->id, 'status' => 'todo']);

        Process::fake([
            '*repo/alpha*' => Process::result($this->gitLog([
                ['sha1aaa', 'fix: retry backoff', '2026-07-15T10:00:00+06:00'],
                ['sha2bbb', 'feat: unrelated', '2026-07-14T10:00:00+06:00'],
            ])),
            '*repo/beta*' => Process::result($this->gitLog([
                ['sha3ccc', 'feat: presigned uploads', '2026-07-13T10:00:00+06:00'],
            ])),
        ]);

        $this->mockCli()->shouldReceive('promptForJsonBatch')->once()
            ->andReturnUsing(fn (array $prompts) => [
                $alpha->id => ['ok' => true, 'data' => ['matches' => [
                    ['task_id' => $t1->id, 'commit_shas' => ['sha1aaa'], 'confidence' => 'high', 'rationale' => 'Direct match.'],
                    ['task_id' => 'not-a-real-task', 'commit_shas' => ['sha1aaa'], 'confidence' => 'high', 'rationale' => 'x'],
                    ['task_id' => $t2->id, 'commit_shas' => ['unknown-sha'], 'confidence' => 'low', 'rationale' => 'x'],
                ]], 'error' => null],
                $beta->id => ['ok' => true, 'data' => ['matches' => [
                    ['task_id' => $t3->id, 'commit_shas' => ['sha3ccc'], 'confidence' => 'medium', 'rationale' => 'Related series.'],
                ]], 'error' => null],
            ]);

        $run = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);

        $this->assertSame('completed', $run->status);
        $this->assertCount(2, $run->matches);
        $this->assertSame(3, $run->stats['tasks_scanned']);
        $this->assertSame(2, $run->stats['repos_scanned']);
        $this->assertSame(3, $run->stats['commits_read']);
        $this->assertSame(2, $run->stats['matched']);
        $this->assertSame(2, $run->stats['invalid_matches']);

        $match = $run->matches->firstWhere('task_id', $t1->id);
        $this->assertSame('high', $match->confidence->value);
        $this->assertSame('sha1aaa', $match->commits[0]['sha']);
        $this->assertSame('fix: retry backoff', $match->commits[0]['subject']);
    }

    public function test_run_skips_repos_without_path_or_git_and_sends_no_prompt_for_empty_logs(): void
    {
        $noPath = Project::factory()->create(['slug' => 'no-path', 'local_path' => '']);
        $notGit = Project::factory()->create(['slug' => 'not-git', 'local_path' => '/repo/not-git']);
        $quiet = Project::factory()->create(['slug' => 'quiet', 'local_path' => '/repo/quiet']);
        Task::factory()->create(['project_id' => $noPath->id, 'status' => 'todo']);
        Task::factory()->create(['project_id' => $notGit->id, 'status' => 'todo']);
        Task::factory()->create(['project_id' => $quiet->id, 'status' => 'todo']);

        Process::fake([
            '*repo/not-git*' => Process::result('', 'fatal: not a git repository', 128),
            '*repo/quiet*' => Process::result(''),
        ]);

        $this->mockCli()->shouldReceive('promptForJsonBatch')->never();

        $run = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);

        $this->assertSame('completed', $run->status);
        $this->assertCount(0, $run->matches);
        $this->assertSame(1, $run->stats['repos_scanned']);
        $skippedReasons = collect($run->stats['skipped'])->pluck('reason', 'project');
        $this->assertSame('no local_path', $skippedReasons['no-path']);
        $this->assertSame('not a git repository', $skippedReasons['not-git']);
    }

    public function test_run_respects_dismissal_memory(): void
    {
        $alpha = Project::factory()->create(['slug' => 'alpha', 'local_path' => '/repo/alpha']);
        $t1 = Task::factory()->create(['project_id' => $alpha->id, 'status' => 'todo']);

        $oldRun = SweepRun::create(['model' => 'haiku', 'window_days' => 7]);
        SweepMatch::create([
            'sweep_run_id' => $oldRun->id, 'task_id' => $t1->id, 'project_id' => $alpha->id,
            'commits' => [['sha' => 'sha1aaa', 'subject' => 'fix: retry backoff', 'committed_at' => '2026-07-15T10:00:00+06:00']],
            'confidence' => 'high', 'status' => SweepMatchStatus::DISMISSED, 'resolved_at' => now(),
        ]);

        Process::fake([
            '*repo/alpha*' => Process::result($this->gitLog([
                ['sha1aaa', 'fix: retry backoff', '2026-07-15T10:00:00+06:00'],
            ])),
        ]);

        $this->mockCli()->shouldReceive('promptForJsonBatch')->once()
            ->andReturnUsing(fn (array $prompts) => [
                $alpha->id => ['ok' => true, 'data' => ['matches' => [
                    ['task_id' => $t1->id, 'commit_shas' => ['sha1aaa'], 'confidence' => 'high', 'rationale' => 'same again'],
                ]], 'error' => null],
            ]);

        $run = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);

        $this->assertCount(0, $run->matches);
        $this->assertSame(0, $run->stats['matched']);
    }

    public function test_run_marks_partial_when_a_project_llm_call_fails(): void
    {
        $alpha = Project::factory()->create(['slug' => 'alpha', 'local_path' => '/repo/alpha']);
        $beta = Project::factory()->create(['slug' => 'beta', 'local_path' => '/repo/beta']);
        $t1 = Task::factory()->create(['project_id' => $alpha->id, 'status' => 'todo']);
        Task::factory()->create(['project_id' => $beta->id, 'status' => 'todo']);

        Process::fake([
            '*repo/alpha*' => Process::result($this->gitLog([['sha1aaa', 'fix: it', '2026-07-15T10:00:00+06:00']])),
            '*repo/beta*' => Process::result($this->gitLog([['sha3ccc', 'feat: other', '2026-07-13T10:00:00+06:00']])),
        ]);

        $this->mockCli()->shouldReceive('promptForJsonBatch')->once()
            ->andReturnUsing(fn (array $prompts) => [
                $alpha->id => ['ok' => true, 'data' => ['matches' => [
                    ['task_id' => $t1->id, 'commit_shas' => ['sha1aaa'], 'confidence' => 'high', 'rationale' => 'ok'],
                ]], 'error' => null],
                $beta->id => ['ok' => false, 'data' => null, 'error' => 'timeout'],
            ]);

        $run = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);

        $this->assertSame('partial', $run->status);
        $this->assertCount(1, $run->matches);
        $this->assertSame('beta', $run->stats['errors'][0]['project']);
    }

    public function test_run_fences_commit_and_task_data_in_prompt(): void
    {
        $alpha = Project::factory()->create(['slug' => 'alpha', 'local_path' => '/repo/alpha']);
        $t1 = Task::factory()->create(['project_id' => $alpha->id, 'status' => 'todo', 'title' => 'Fix retry backoff']);

        Process::fake([
            '*repo/alpha*' => Process::result($this->gitLog([['sha1aaa', 'fix: retry backoff', '2026-07-15T10:00:00+06:00']])),
        ]);

        $this->mockCli()->shouldReceive('promptForJsonBatch')->once()
            ->withArgs(function (array $prompts, string $model) use ($alpha, $t1) {
                $p = $prompts[$alpha->id];

                return $model === 'haiku'
                    && str_starts_with($p, '[JARVIS-SWEEP]')
                    && str_contains($p, '<<<TASKS START>>>') && str_contains($p, '<<<TASKS END>>>')
                    && str_contains($p, '<<<COMMITS START>>>') && str_contains($p, '<<<COMMITS END>>>')
                    && str_contains($p, $t1->id) && str_contains($p, 'sha1aaa');
            })
            ->andReturn([$alpha->id => ['ok' => true, 'data' => ['matches' => []], 'error' => null]]);

        $this->sweep()->run(null, null, 7, RecapModel::HAIKU);
    }

    public function test_rerun_with_identical_inputs_serves_project_from_fingerprint_cache(): void
    {
        $alpha = Project::factory()->create(['slug' => 'alpha', 'local_path' => '/repo/alpha']);
        $t1 = Task::factory()->create(['project_id' => $alpha->id, 'status' => 'todo']);

        Process::fake([
            '*repo/alpha*' => Process::result($this->gitLog([['sha1aaa', 'fix: it', '2026-07-15T10:00:00+06:00']])),
        ]);

        // First run: one LLM call. Second run (same tasks, commits, model): zero.
        $this->mockCli()->shouldReceive('promptForJsonBatch')->once()
            ->andReturn([$alpha->id => ['ok' => true, 'data' => ['matches' => [
                ['task_id' => $t1->id, 'commit_shas' => ['sha1aaa'], 'confidence' => 'high', 'rationale' => 'yes'],
            ]], 'error' => null]]);

        $first = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);
        $this->assertCount(1, $first->matches);
        $this->assertArrayHasKey($alpha->id, $first->fingerprints);

        $second = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);

        $this->assertSame(1, $second->stats['cached_projects']);
        $this->assertCount(1, $second->matches);
        $match = $second->matches->first();
        $this->assertSame($t1->id, $match->task_id);
        $this->assertSame('high', $match->confidence->value);
        $this->assertSame(SweepMatchStatus::PENDING, $match->status);
        // fingerprint carried forward so a third run can still cache-hit
        $this->assertSame($first->fingerprints[$alpha->id], $second->fingerprints[$alpha->id]);
    }

    public function test_rerun_recomputes_when_commits_task_set_or_model_change(): void
    {
        $alpha = Project::factory()->create(['slug' => 'alpha', 'local_path' => '/repo/alpha']);
        Task::factory()->create(['project_id' => $alpha->id, 'status' => 'todo']);

        Process::fake([
            '*repo/alpha*' => Process::result($this->gitLog([['sha1aaa', 'fix: it', '2026-07-15T10:00:00+06:00']])),
        ]);

        // haiku run, then sonnet run with identical inputs → model is part of
        // the fingerprint, so the second run must call the LLM again.
        $this->mockCli()->shouldReceive('promptForJsonBatch')->twice()
            ->andReturnUsing(fn (array $prompts) => collect($prompts)->mapWithKeys(
                fn ($p, $id) => [$id => ['ok' => true, 'data' => ['matches' => []], 'error' => null]],
            )->all());

        $this->sweep()->run(null, null, 7, RecapModel::HAIKU);
        $second = $this->sweep()->run(null, null, 7, RecapModel::SONNET);

        $this->assertSame(0, $second->stats['cached_projects']);
    }

    public function test_cached_rerun_still_honors_dismissals_made_after_the_first_run(): void
    {
        $alpha = Project::factory()->create(['slug' => 'alpha', 'local_path' => '/repo/alpha']);
        $t1 = Task::factory()->create(['project_id' => $alpha->id, 'status' => 'todo']);

        Process::fake([
            '*repo/alpha*' => Process::result($this->gitLog([['sha1aaa', 'fix: it', '2026-07-15T10:00:00+06:00']])),
        ]);

        $this->mockCli()->shouldReceive('promptForJsonBatch')->once()
            ->andReturn([$alpha->id => ['ok' => true, 'data' => ['matches' => [
                ['task_id' => $t1->id, 'commit_shas' => ['sha1aaa'], 'confidence' => 'high', 'rationale' => 'yes'],
            ]], 'error' => null]]);

        $first = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);
        $this->sweep()->dismiss($first->matches->first());

        $second = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);

        $this->assertSame(1, $second->stats['cached_projects']);
        $this->assertCount(0, $second->matches);
    }

    public function test_llm_batches_are_chunked_to_the_concurrency_cap(): void
    {
        config(['services.claude.concurrency' => 2]);

        $fakes = [];
        $projects = [];
        foreach (['p1', 'p2', 'p3'] as $slug) {
            $projects[$slug] = Project::factory()->create(['slug' => $slug, 'local_path' => "/repo/{$slug}"]);
            Task::factory()->create(['project_id' => $projects[$slug]->id, 'status' => 'todo']);
            $fakes["*repo/{$slug}*"] = Process::result($this->gitLog([["sha-{$slug}", 'fix: it', '2026-07-15T10:00:00+06:00']]));
        }
        Process::fake($fakes);

        // 3 projects with a cap of 2 → two batch calls (2 prompts, then 1).
        $sizes = [];
        $this->mockCli()->shouldReceive('promptForJsonBatch')->twice()
            ->andReturnUsing(function (array $prompts) use (&$sizes) {
                $sizes[] = count($prompts);

                return collect($prompts)->mapWithKeys(
                    fn ($p, $id) => [$id => ['ok' => true, 'data' => ['matches' => []], 'error' => null]],
                )->all();
            });

        $run = $this->sweep()->run(null, null, 7, RecapModel::HAIKU);

        sort($sizes);
        $this->assertSame([1, 2], $sizes);
        $this->assertSame(3, $run->stats['repos_scanned']);
        $this->assertSame('completed', $run->status);
    }

    // ── HTTP endpoints ─────────────────────────────────────────────

    private function seedPendingMatch(array $taskOverrides = []): SweepMatch
    {
        $project = Project::factory()->create(['local_path' => '/repo/seeded']);
        $task = Task::factory()->create(array_merge(['project_id' => $project->id, 'status' => 'in_progress'], $taskOverrides));
        $run = SweepRun::create(['model' => 'haiku', 'window_days' => 7]);

        return SweepMatch::create([
            'sweep_run_id' => $run->id, 'task_id' => $task->id, 'project_id' => $project->id,
            'commits' => [['sha' => 'sha1aaa', 'subject' => 'fix: it', 'committed_at' => '2026-07-15T10:00:00+06:00']],
            'confidence' => 'high',
        ]);
    }

    public function test_post_sweep_validates_input(): void
    {
        $this->postJson('/api/tasks/sweep', ['model' => 'gpt-5'])->assertUnprocessable();
        $this->postJson('/api/tasks/sweep', ['window_days' => 0])->assertUnprocessable();
        $this->postJson('/api/tasks/sweep', ['window_days' => 31])->assertUnprocessable();
        $this->postJson('/api/tasks/sweep', ['group' => 'nope'])->assertUnprocessable();
        $this->postJson('/api/tasks/sweep', ['project' => 'ghost-project'])->assertUnprocessable();
    }

    public function test_post_sweep_runs_and_returns_run_with_matches(): void
    {
        $alpha = Project::factory()->create(['slug' => 'alpha', 'local_path' => '/repo/alpha']);
        $t1 = Task::factory()->create(['project_id' => $alpha->id, 'status' => 'todo']);

        Process::fake([
            '*repo/alpha*' => Process::result($this->gitLog([['sha1aaa', 'fix: it', '2026-07-15T10:00:00+06:00']])),
        ]);
        $this->mockCli()->shouldReceive('promptForJsonBatch')->once()
            ->andReturn([$alpha->id => ['ok' => true, 'data' => ['matches' => [
                ['task_id' => $t1->id, 'commit_shas' => ['sha1aaa'], 'confidence' => 'high', 'rationale' => 'yes'],
            ]], 'error' => null]]);

        $this->postJson('/api/tasks/sweep', ['project' => 'alpha', 'window_days' => 14, 'model' => 'haiku'])
            ->assertOk()
            ->assertJsonPath('data.run.window_days', 14)
            ->assertJsonPath('data.run.scope.project', 'alpha')
            ->assertJsonPath('data.matches.0.task_id', $t1->id)
            ->assertJsonPath('data.matches.0.task.id', $t1->id);
    }

    public function test_get_latest_returns_newest_run_or_null(): void
    {
        $this->getJson('/api/tasks/sweep/latest')->assertOk()->assertJsonPath('data', null);

        $older = SweepRun::create(['model' => 'haiku', 'window_days' => 7]);
        $older->forceFill(['created_at' => now()->subHour()])->save();
        $newest = SweepRun::create(['model' => 'sonnet', 'window_days' => 3]);

        $this->getJson('/api/tasks/sweep/latest')
            ->assertOk()
            ->assertJsonPath('data.run.id', $newest->id)
            ->assertJsonPath('data.matches', []);
    }

    public function test_confirm_completes_task_with_evidence_and_actor(): void
    {
        $match = $this->seedPendingMatch();

        $this->patchJson("/api/tasks/sweep/matches/{$match->id}/confirm", [], ['X-Jarvis-Actor' => 'jarvis'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $task = $match->task->fresh();
        $this->assertSame('done', $task->status);

        $event = $task->events()->where('event_type', 'completed')->firstOrFail();
        $this->assertSame('jarvis', $event->actor->value);
        $this->assertSame('commit_sweep', $event->payload['meta']['source']);
        $this->assertSame(['sha1aaa'], $event->payload['meta']['commits']);
        $this->assertSame('in_progress', $match->fresh()->meta['previous_status']);

        // Not pending anymore → second confirm rejected
        $this->patchJson("/api/tasks/sweep/matches/{$match->id}/confirm")->assertUnprocessable();
    }

    public function test_dismiss_and_undo_lifecycle(): void
    {
        $match = $this->seedPendingMatch();

        $this->patchJson("/api/tasks/sweep/matches/{$match->id}/dismiss")
            ->assertOk()->assertJsonPath('data.status', 'dismissed');

        $this->patchJson("/api/tasks/sweep/matches/{$match->id}/undo")
            ->assertOk()->assertJsonPath('data.status', 'pending');

        // confirm, then undo → task reopened to its previous status
        $this->patchJson("/api/tasks/sweep/matches/{$match->id}/confirm", [], ['X-Jarvis-Actor' => 'jarvis']);
        $this->assertSame('done', $match->task->fresh()->status);

        $this->patchJson("/api/tasks/sweep/matches/{$match->id}/undo", [], ['X-Jarvis-Actor' => 'jarvis'])
            ->assertOk()->assertJsonPath('data.status', 'pending');

        $task = $match->task->fresh();
        $this->assertSame('in_progress', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertTrue($task->events()->where('event_type', 'reopened')->exists());

        // already pending → undo rejected
        $this->patchJson("/api/tasks/sweep/matches/{$match->id}/undo")->assertUnprocessable();
    }
}
