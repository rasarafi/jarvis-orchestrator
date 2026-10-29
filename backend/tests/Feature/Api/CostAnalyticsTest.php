<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CostAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function ccProject(string $decodedPath): int
    {
        return DB::table('sessions.projects')->insertGetId([
            'encoded_path' => str_replace('/', '-', $decodedPath),
            'decoded_path' => $decodedPath,
            'display_name' => basename($decodedPath),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Seed one session plus a matching message so both aggregates see it. */
    private function seedSession(
        int $ccProjectId,
        string $lastActivity,
        int $tokens,
        float $cost,
        string $prompt = 'do the thing',
        ?string $branch = 'main',
        string $model = 'claude-opus-5',
        string $kind = 'main',
        ?string $parentSessionId = null,
        ?string $account = null,
    ): string {
        $sessionId = ($kind === 'subagent' ? 'agent-' : 'sess-') . (++$this->seq);

        DB::table('sessions.sessions')->insert([
            'session_id' => $sessionId,
            'kind' => $kind,
            'parent_session_id' => $parentSessionId,
            'account' => $account,
            'project_id' => $ccProjectId,
            'file_path' => '/tmp/' . $sessionId . '.jsonl',
            'file_size' => 1024,
            'file_mtime' => now()->getTimestampMs(),
            'first_prompt' => $prompt,
            'git_branch' => $branch,
            'first_prompt_timestamp' => $lastActivity,
            'last_activity_timestamp' => $lastActivity,
            'message_count' => 10,
            'total_input_tokens' => $tokens,
            'total_output_tokens' => 0,
            'total_cache_creation_tokens' => 0,
            'total_cache_read_tokens' => 0,
            'estimated_cost_usd' => $cost,
            'indexed_at' => now(),
        ]);

        DB::table('sessions.messages')->insert([
            'session_id' => $sessionId,
            'message_uuid' => 'msg-' . $this->seq,
            'message_type' => 'assistant',
            'timestamp' => $lastActivity,
            'model' => $model,
            'input_tokens' => $tokens,
            'output_tokens' => 0,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => 0,
            'sequence_number' => 1,
        ]);

        return $sessionId;
    }

    public function test_projects_ranks_by_tokens_and_computes_share(): void
    {
        Project::factory()->create(['slug' => 'big', 'name' => 'Big', 'local_path' => '/home/dev/projects/big']);
        Project::factory()->create(['slug' => 'small', 'name' => 'Small', 'local_path' => '/home/dev/projects/small']);

        $this->seedSession($this->ccProject('/home/dev/projects/big'), now()->subHours(2)->toDateTimeString(), 750, 7.5);
        $this->seedSession($this->ccProject('/home/dev/projects/small'), now()->subHours(3)->toDateTimeString(), 250, 2.5);

        $response = $this->getJson('/api/analytics/costs/projects?range=7d')->assertOk();
        $projects = $response->json('data.projects');

        $this->assertSame('big', $projects[0]['slug']);
        $this->assertSame(750, $projects[0]['tokens']);
        $this->assertEquals(75.0, $projects[0]['share_pct']);
        $this->assertSame('small', $projects[1]['slug']);
        $this->assertEquals(25.0, $projects[1]['share_pct']);
        $this->assertSame(1000, $response->json('data.total_tokens'));
    }

    /**
     * Regression: a naive `LIKE local_path || '%'` counts a nested project root
     * under its parent as well as itself. Longest-prefix must win.
     */
    public function test_nested_project_root_is_not_double_counted(): void
    {
        Project::factory()->create(['slug' => 'portfolio', 'name' => 'Portfolio', 'local_path' => '/home/dev/projects/portfolio']);
        Project::factory()->create(['slug' => 'portfolio-app', 'name' => 'Portfolio App', 'local_path' => '/home/dev/projects/portfolio/app']);

        $this->seedSession($this->ccProject('/home/dev/projects/portfolio'), now()->subHour()->toDateTimeString(), 100, 1.0);
        $this->seedSession($this->ccProject('/home/dev/projects/portfolio/app'), now()->subHour()->toDateTimeString(), 400, 4.0);

        $projects = collect($this->getJson('/api/analytics/costs/projects?range=7d')->json('data.projects'))
            ->keyBy('slug');

        $this->assertSame(100, $projects['portfolio']['tokens'], 'parent absorbed the nested project');
        $this->assertSame(400, $projects['portfolio-app']['tokens']);
    }

    public function test_projects_filters_by_group(): void
    {
        Project::factory()->create(['slug' => 'a', 'local_path' => '/home/dev/projects/a', 'group_key' => 'mfb']);
        Project::factory()->create(['slug' => 'b', 'local_path' => '/home/dev/projects/b', 'group_key' => 'personal']);

        $this->seedSession($this->ccProject('/home/dev/projects/a'), now()->subHour()->toDateTimeString(), 100, 1.0);
        $this->seedSession($this->ccProject('/home/dev/projects/b'), now()->subHour()->toDateTimeString(), 100, 1.0);

        $projects = $this->getJson('/api/analytics/costs/projects?range=7d&group=mfb')->json('data.projects');

        $this->assertCount(1, $projects);
        $this->assertSame('a', $projects[0]['slug']);
    }

    public function test_trend_is_null_for_a_project_with_no_previous_period(): void
    {
        Project::factory()->create(['slug' => 'fresh', 'local_path' => '/home/dev/projects/fresh']);
        $this->seedSession($this->ccProject('/home/dev/projects/fresh'), now()->subHour()->toDateTimeString(), 100, 1.0);

        $projects = $this->getJson('/api/analytics/costs/projects?range=24h')->json('data.projects');

        $this->assertNull($projects[0]['trend_pct'], 'no prior activity must read as new, not as an infinite rise');
    }

    public function test_trend_compares_against_the_preceding_equal_period(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');

        $this->seedSession($cc, now()->subHours(2)->toDateTimeString(), 200, 2.0);   // current 24h
        $this->seedSession($cc, now()->subHours(30)->toDateTimeString(), 100, 1.0);  // previous 24h

        $projects = $this->getJson('/api/analytics/costs/projects?range=24h')->json('data.projects');

        $this->assertSame(200, $projects[0]['tokens']);
        $this->assertSame(100, $projects[0]['previous_tokens']);
        $this->assertSame(100, $projects[0]['trend_pct']); // doubled
    }

    public function test_sessions_drilldown_returns_heaviest_first_with_total(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');

        $this->seedSession($cc, now()->subHour()->toDateTimeString(), 100, 1.0, 'light task');
        $this->seedSession($cc, now()->subHour()->toDateTimeString(), 900, 9.0, 'heavy task');
        $this->seedSession($cc, now()->subHour()->toDateTimeString(), 500, 5.0, 'middle task');

        $data = $this->getJson('/api/analytics/costs/projects/p/sessions?range=7d&limit=2')->json('data');

        $this->assertSame(3, $data['total_sessions'], 'total must count every session, not just the returned page');
        $this->assertCount(2, $data['sessions']);
        $this->assertSame(900, $data['sessions'][0]['tokens']);
        $this->assertSame('heavy task', $data['sessions'][0]['first_prompt']);
        $this->assertSame(500, $data['sessions'][1]['tokens']);
    }

    public function test_sessions_condenses_multiline_prompts(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $this->seedSession(
            $this->ccProject('/home/dev/projects/p'),
            now()->subHour()->toDateTimeString(),
            100,
            1.0,
            "line one\n\n   line two\ttabbed",
        );

        $prompt = $this->getJson('/api/analytics/costs/projects/p/sessions?range=7d')->json('data.sessions.0.first_prompt');

        $this->assertSame('line one line two tabbed', $prompt);
    }

    public function test_sessions_404s_for_an_unknown_project(): void
    {
        $this->getJson('/api/analytics/costs/projects/nope/sessions')->assertNotFound();
    }

    public function test_range_must_be_one_of_the_offered_ranges(): void
    {
        $this->getJson('/api/analytics/costs/projects?range=bogus')->assertStatus(422);
    }

    public function test_window_compares_against_the_median_and_never_claims_a_quota_pct(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');

        // Three closed windows well apart, then activity in the open one.
        $this->seedSession($cc, now()->subDays(4)->toDateTimeString(), 100, 1.0);
        $this->seedSession($cc, now()->subDays(3)->toDateTimeString(), 200, 2.0);
        $this->seedSession($cc, now()->subDays(2)->toDateTimeString(), 300, 3.0);
        $this->seedSession($cc, now()->subMinutes(10)->toDateTimeString(), 400, 4.0);

        $data = $this->getJson('/api/analytics/costs/window')->assertOk()->json('data');

        $this->assertTrue($data['open']);
        $this->assertSame(200, $data['median_tokens'], 'median of the CLOSED blocks 100/200/300');
        $this->assertSame(400, $data['tokens']);
        $this->assertEquals(2.0, $data['vs_median']);
        $this->assertSame('local_estimate', $data['provenance']);

        // The whole point of the design decision: no quota percentage is asserted.
        $this->assertArrayNotHasKey('pct_of_quota', $data);
        $this->assertArrayNotHasKey('ceiling_tokens', $data);
    }

    public function test_window_excludes_the_open_block_from_its_own_median(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');

        $this->seedSession($cc, now()->subDays(3)->toDateTimeString(), 100, 1.0);
        // A huge open window must not drag its own baseline up.
        $this->seedSession($cc, now()->subMinutes(5)->toDateTimeString(), 9_000, 9.0);

        $data = $this->getJson('/api/analytics/costs/window')->json('data');

        $this->assertSame(100, $data['median_tokens']);
        $this->assertEquals(90.0, $data['vs_median']);
    }

    // =========================================================
    // Subagents
    // =========================================================

    /**
     * Subagent transcripts are separate files with their own spend. They must
     * roll up into the session that spawned them — including for the ORDERING,
     * which is why the rollup happens in SQL and not after the top-N cut.
     */
    public function test_subagent_tokens_roll_up_into_the_parent_session(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subHour()->toDateTimeString();

        $parent = $this->seedSession($cc, $at, 100, 1.0, 'orchestrator');
        $this->seedSession($cc, $at, 500, 5.0, 'agent work', 'main', 'claude-opus-5', 'subagent', $parent);
        $this->seedSession($cc, $at, 300, 3.0, 'a heavier plain session');

        $data = $this->getJson('/api/analytics/costs/projects/p/sessions?range=7d')->json('data');

        $this->assertSame(2, $data['total_sessions'], 'a subagent is not a session the user started');
        $this->assertSame($parent, $data['sessions'][0]['session_id'], 'rollup must decide the ranking');
        $this->assertSame(600, $data['sessions'][0]['tokens']);
        $this->assertSame(100, $data['sessions'][0]['own_tokens']);
        $this->assertSame(500, $data['sessions'][0]['subagent_tokens']);
        $this->assertSame(1, $data['sessions'][0]['subagent_count']);
        $this->assertEquals(6.0, $data['sessions'][0]['cost_usd']);
        $this->assertSame('agent work', $data['sessions'][0]['subagents'][0]['first_prompt']);
    }

    public function test_subagents_never_appear_as_their_own_session_row(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subHour()->toDateTimeString();

        $parent = $this->seedSession($cc, $at, 10, 0.1);
        $this->seedSession($cc, $at, 900, 9.0, 'huge agent', 'main', 'claude-opus-5', 'subagent', $parent);

        $ids = collect($this->getJson('/api/analytics/costs/projects/p/sessions?range=7d')->json('data.sessions'))
            ->pluck('session_id');

        $this->assertSame([$parent], $ids->all());
    }

    /** Project totals count subagent spend; the session COUNT still counts sessions. */
    public function test_project_totals_include_subagent_tokens_but_not_their_session_count(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subHour()->toDateTimeString();

        $parent = $this->seedSession($cc, $at, 100, 1.0);
        $this->seedSession($cc, $at, 400, 4.0, 'agent', 'main', 'claude-opus-5', 'subagent', $parent);

        $row = $this->getJson('/api/analytics/costs/projects?range=7d')->json('data.projects.0');

        $this->assertSame(500, $row['tokens']);
        $this->assertSame(1, $row['sessions']);
    }

    // =========================================================
    // Model breakdown
    // =========================================================

    public function test_projects_carry_a_model_breakdown(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subHour()->toDateTimeString();

        $this->seedSession($cc, $at, 750, 7.5, 'a', 'main', 'claude-opus-5');
        $this->seedSession($cc, $at, 250, 2.5, 'b', 'main', 'claude-sonnet-5');

        $row = $this->getJson('/api/analytics/costs/projects?range=7d')->json('data.projects.0');

        $this->assertSame('claude-opus-5', $row['models'][0]['model'], 'heaviest model first');
        $this->assertSame(75, $row['models'][0]['pct']);
        $this->assertSame('claude-sonnet-5', $row['models'][1]['model']);
        $this->assertSame(25, $row['models'][1]['pct']);
    }

    public function test_session_model_breakdown_includes_its_subagents(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subHour()->toDateTimeString();

        $parent = $this->seedSession($cc, $at, 100, 1.0, 'orchestrator', 'main', 'claude-opus-5');
        $this->seedSession($cc, $at, 300, 3.0, 'agent', 'main', 'claude-haiku-4-5-20251001', 'subagent', $parent);

        $models = collect($this->getJson('/api/analytics/costs/projects/p/sessions?range=7d')->json('data.sessions.0.models'))
            ->pluck('pct', 'model');

        $this->assertSame(75, $models['claude-haiku-4-5-20251001']);
        $this->assertSame(25, $models['claude-opus-5']);
    }

    // =========================================================
    // Account separation
    // =========================================================

    /** The default view stays merged — account is a filter, never a partition. */
    public function test_account_filter_is_opt_in_and_defaults_to_every_account(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subHour()->toDateTimeString();

        $this->seedSession($cc, $at, 100, 1.0, 'a', 'main', 'claude-opus-5', 'main', null, 'cl');
        $this->seedSession($cc, $at, 400, 4.0, 'b', 'main', 'claude-opus-5', 'main', null, 'cl2');

        $this->assertSame(500, $this->getJson('/api/analytics/costs/projects?range=7d')->json('data.total_tokens'));
        $this->assertSame(100, $this->getJson('/api/analytics/costs/projects?range=7d&account=cl')->json('data.total_tokens'));
        $this->assertSame(400, $this->getJson('/api/analytics/costs/projects?range=7d&account=cl2')->json('data.total_tokens'));
    }

    /** Pre-hook sessions carry no account and are selectable as their own bucket. */
    public function test_unattributed_selects_sessions_with_no_account(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subHour()->toDateTimeString();

        $this->seedSession($cc, $at, 100, 1.0, 'old', 'main', 'claude-opus-5', 'main', null, null);
        $this->seedSession($cc, $at, 400, 4.0, 'new', 'main', 'claude-opus-5', 'main', null, 'cl');

        $this->assertSame(
            100,
            $this->getJson('/api/analytics/costs/projects?range=7d&account=unattributed')->json('data.total_tokens')
        );
    }

    public function test_window_lists_the_accounts_present_in_the_index(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subMinutes(10)->toDateTimeString();

        $this->seedSession($cc, $at, 100, 1.0, 'a', 'main', 'claude-opus-5', 'main', null, 'cl');
        $this->seedSession($cc, $at, 100, 1.0, 'b', 'main', 'claude-opus-5', 'main', null, null);

        $accounts = collect($this->getJson('/api/analytics/costs/window')->json('data.accounts'))->pluck('key');

        $this->assertTrue($accounts->contains('cl'));
        $this->assertTrue($accounts->contains('unattributed'));
    }

    public function test_account_must_be_a_known_key(): void
    {
        $this->getJson('/api/analytics/costs/projects?range=7d&account=nope')->assertStatus(422);
    }

    /**
     * The hook is the only moment account attribution can be captured, and it
     * must stamp rows the scanner already indexed — including the session's
     * subagents — or attribution would wait for the next reindex.
     */
    public function test_session_start_hook_records_and_stamps_the_account(): void
    {
        Project::factory()->create(['slug' => 'p', 'local_path' => '/home/dev/projects/p']);
        $cc = $this->ccProject('/home/dev/projects/p');
        $at = now()->subHour()->toDateTimeString();

        $parent = $this->seedSession($cc, $at, 100, 1.0);
        $agent = $this->seedSession($cc, $at, 50, 0.5, 'agent', 'main', 'claude-opus-5', 'subagent', $parent);

        $this->postJson('/api/sessions/account', [
            'session_id' => $parent,
            'account' => 'cl2',
            'email' => 'someone@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('sessions.session_accounts', ['session_id' => $parent, 'account' => 'cl2']);
        $this->assertSame('cl2', DB::table('sessions.sessions')->where('session_id', $parent)->value('account'));
        $this->assertSame('cl2', DB::table('sessions.sessions')->where('session_id', $agent)->value('account'));
    }
}
