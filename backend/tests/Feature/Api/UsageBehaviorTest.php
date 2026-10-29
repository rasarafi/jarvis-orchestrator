<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsageBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;
    private ?int $projectId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectId = DB::table('sessions.projects')->insertGetId([
            'encoded_path' => '-home-dev-projects-demo',
            'decoded_path' => '/home/dev/projects/demo',
            'display_name' => 'demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return string the session_id */
    private function seedSession(array $overrides = []): string
    {
        $sessionId = $overrides['session_id'] ?? ('sess-' . (++$this->seq));

        DB::table('sessions.sessions')->insert(array_merge([
            'session_id' => $sessionId,
            'kind' => 'main',
            'parent_session_id' => null,
            'account' => null,
            'entrypoint' => 'cli',
            'subagent_type' => null,
            'project_id' => $this->projectId,
            'file_path' => '/tmp/' . $sessionId . '-' . uniqid() . '.jsonl',
            'file_size' => 1024,
            'file_mtime' => now()->getTimestampMs(),
            'first_prompt' => 'do the thing',
            'first_prompt_timestamp' => now()->subMinute(),
            'last_activity_timestamp' => now()->subMinute(),
            'message_count' => 1,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_cache_creation_tokens' => 0,
            'total_cache_read_tokens' => 0,
            'estimated_cost_usd' => 0,
            'indexed_at' => now(),
        ], $overrides));

        return $sessionId;
    }

    private function seedMessage(string $sessionId, array $overrides = []): void
    {
        DB::table('sessions.messages')->insert(array_merge([
            'session_id' => $sessionId,
            'message_uuid' => 'msg-' . (++$this->seq),
            'message_type' => 'assistant',
            'timestamp' => now()->subMinute(),
            'model' => 'claude-opus-5',
            'input_tokens' => 100,
            'output_tokens' => 0,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => 0,
            'skill_name' => null,
            'tool_names' => null,
            'sequence_number' => $this->seq,
        ], $overrides));
    }

    // -------------------------------------------------------------
    // cache_miss / long_context
    // -------------------------------------------------------------

    public function test_cache_miss_share_counts_only_tokens_over_the_threshold(): void
    {
        $s = $this->seedSession();
        $this->seedMessage($s, ['input_tokens' => 900, 'cache_creation_tokens' => 150_000]); // over
        $this->seedMessage($s, ['input_tokens' => 100, 'cache_creation_tokens' => 0]); // under

        $data = $this->getJson('/api/analytics/costs/behaviors?range=7d')->assertOk()->json('data');

        $this->assertSame(150_900, $data['cache_miss']['tokens']);
        $this->assertSame(151_000, $data['cache_miss']['total_tokens']);
    }

    public function test_long_context_share_sums_input_cache_read_and_cache_creation(): void
    {
        $s = $this->seedSession();
        $this->seedMessage($s, ['input_tokens' => 100_000, 'cache_read_tokens' => 60_000, 'cache_creation_tokens' => 0]); // 160k, over
        $this->seedMessage($s, ['input_tokens' => 1_000]); // under

        $data = $this->getJson('/api/analytics/costs/behaviors?range=7d')->assertOk()->json('data');

        $this->assertSame(160_000, $data['long_context']['tokens']);
        $this->assertSame(161_000, $data['long_context']['total_tokens']);
        $this->assertSame(99, $data['long_context']['pct']);
    }

    // -------------------------------------------------------------
    // subagent_heavy
    // -------------------------------------------------------------

    public function test_subagent_heavy_flags_sessions_where_children_exceed_own_tokens(): void
    {
        $heavy = $this->seedSession(['total_input_tokens' => 100]);
        $this->seedSession([
            'session_id' => 'agent-under-heavy',
            'kind' => 'subagent',
            'parent_session_id' => $heavy,
            'total_input_tokens' => 900, // 900 > 100 own -> heavy
        ]);

        $light = $this->seedSession(['total_input_tokens' => 1000]);
        $this->seedSession([
            'session_id' => 'agent-under-light',
            'kind' => 'subagent',
            'parent_session_id' => $light,
            'total_input_tokens' => 10, // 10 < 1000 own -> not heavy
        ]);

        $data = $this->getJson('/api/analytics/costs/behaviors?range=7d')->assertOk()->json('data');

        // heavy session total = 100 + 900 = 1000; light session total = 1000 + 10 = 1010
        $this->assertSame(1000, $data['subagent_heavy']['tokens']);
        $this->assertSame(2010, $data['subagent_heavy']['total_tokens']);
    }

    // -------------------------------------------------------------
    // high_parallel
    // -------------------------------------------------------------

    public function test_high_parallel_reads_off_the_concurrency_table(): void
    {
        $s = $this->seedSession();
        $busyMinute = now()->startOfMinute();
        $quietMinute = now()->copy()->subMinutes(5)->startOfMinute();

        $this->seedMessage($s, ['timestamp' => $busyMinute, 'input_tokens' => 400]);
        $this->seedMessage($s, ['timestamp' => $quietMinute, 'input_tokens' => 600]);

        DB::table('sessions.minute_concurrency')->insert([
            ['minute_bucket' => $busyMinute, 'active_sessions' => 5],
            ['minute_bucket' => $quietMinute, 'active_sessions' => 1],
        ]);

        $data = $this->getJson('/api/analytics/costs/behaviors?range=7d')->assertOk()->json('data');

        $this->assertSame(400, $data['high_parallel']['tokens']);
        $this->assertSame(1000, $data['high_parallel']['total_tokens']);
    }

    public function test_high_parallel_is_zero_when_the_concurrency_table_has_no_matching_bucket(): void
    {
        $s = $this->seedSession();
        $this->seedMessage($s, ['input_tokens' => 500]);
        // No minute_concurrency row at all — an inner join must not crash, just report 0.

        $data = $this->getJson('/api/analytics/costs/behaviors?range=7d')->assertOk()->json('data');

        $this->assertSame(0, $data['high_parallel']['total_tokens']);
    }

    // -------------------------------------------------------------
    // cron
    // -------------------------------------------------------------

    public function test_cron_separates_headless_entrypoints_from_interactive(): void
    {
        $this->seedSession(['entrypoint' => 'sdk-cli', 'total_input_tokens' => 300]);
        $this->seedSession(['entrypoint' => 'cli', 'total_input_tokens' => 700]);

        $data = $this->getJson('/api/analytics/costs/behaviors?range=7d')->assertOk()->json('data');

        $this->assertTrue($data['cron']['available']);
        $this->assertSame(300, $data['cron']['tokens']);
        $this->assertSame(1000, $data['cron']['total_tokens']);
    }

    public function test_cron_is_unavailable_when_no_session_has_a_captured_entrypoint(): void
    {
        $this->seedSession(['entrypoint' => null, 'total_input_tokens' => 300]);

        $data = $this->getJson('/api/analytics/costs/behaviors?range=7d')->assertOk()->json('data');

        $this->assertFalse($data['cron']['available']);
    }

    // -------------------------------------------------------------
    // subagent_type shares
    // -------------------------------------------------------------

    public function test_subagent_type_shares_bucket_null_as_unspecified(): void
    {
        $parent = $this->seedSession();
        $this->seedSession([
            'session_id' => 'agent-typed',
            'kind' => 'subagent',
            'parent_session_id' => $parent,
            'subagent_type' => 'code-reviewer',
            'total_input_tokens' => 300,
        ]);
        $this->seedSession([
            'session_id' => 'agent-untyped',
            'kind' => 'subagent',
            'parent_session_id' => $parent,
            'subagent_type' => null,
            'total_input_tokens' => 700,
        ]);

        $items = collect($this->getJson('/api/analytics/costs/behaviors?range=7d')->json('data.subagent_types.items'))
            ->keyBy('label');

        $this->assertSame(300, $items['code-reviewer']['tokens']);
        $this->assertSame(700, $items['general-purpose (unspecified)']['tokens']);
    }

    // -------------------------------------------------------------
    // skill shares — propagation
    // -------------------------------------------------------------

    public function test_skill_share_propagates_to_later_turns_but_not_the_invoking_message(): void
    {
        $s = $this->seedSession();

        // Turn 1: no skill active yet.
        $this->seedMessage($s, ['sequence_number' => 1, 'input_tokens' => 50, 'skill_name' => null]);
        // Turn 2: invokes the skill — this message's OWN tokens must not count toward it.
        $this->seedMessage($s, ['sequence_number' => 2, 'input_tokens' => 20, 'skill_name' => 'superpowers:brainstorming']);
        // Turn 3+4: after invocation — these DO count toward the skill.
        $this->seedMessage($s, ['sequence_number' => 3, 'input_tokens' => 300, 'skill_name' => null]);
        $this->seedMessage($s, ['sequence_number' => 4, 'input_tokens' => 100, 'skill_name' => null]);

        $items = collect($this->getJson('/api/analytics/costs/behaviors?range=7d')->json('data.skills.items'))
            ->keyBy('label');

        $this->assertSame(400, $items['superpowers:brainstorming']['tokens'], '300 + 100 after invocation, NOT the 20-token invoking turn');
        $this->assertTrue($items['superpowers:brainstorming']['is_plugin']);
    }

    public function test_plugin_share_is_parsed_from_colon_prefixed_skill_names_not_recaptured(): void
    {
        $s = $this->seedSession();
        $this->seedMessage($s, ['sequence_number' => 1, 'input_tokens' => 10, 'skill_name' => 'jarvis-tasks']);
        $this->seedMessage($s, ['sequence_number' => 2, 'input_tokens' => 200]); // attributed to jarvis-tasks
        // This message's OWN 10 tokens still belong to jarvis-tasks (still active going
        // into this turn) — only messages AFTER this invocation belong to the new skill.
        $this->seedMessage($s, ['sequence_number' => 3, 'input_tokens' => 10, 'skill_name' => 'superpowers:brainstorming']);
        $this->seedMessage($s, ['sequence_number' => 4, 'input_tokens' => 300]); // attributed to superpowers:brainstorming

        $plugin = collect($this->getJson('/api/analytics/costs/behaviors?range=7d')->json('data.plugin.items'))
            ->keyBy('label');

        $this->assertSame(300, $plugin['plugin skills']['tokens']);
        $this->assertSame(210, $plugin['project skills']['tokens']);
    }

    // -------------------------------------------------------------
    // mcp_servers — call count, not tokens
    // -------------------------------------------------------------

    public function test_mcp_server_share_counts_calls_not_tokens(): void
    {
        $s = $this->seedSession();
        $this->seedMessage($s, [
            'sequence_number' => 1,
            'input_tokens' => 50_000, // deliberately large, must NOT influence the share
            'tool_names' => '{"mcp__pinecone__search-records","Read"}',
        ]);
        $this->seedMessage($s, [
            'sequence_number' => 2,
            'input_tokens' => 1, // tiny, must count the same as any other call
            'tool_names' => '{"mcp__pinecone__list-indexes"}',
        ]);
        $this->seedMessage($s, [
            'sequence_number' => 3,
            'tool_names' => '{"mcp__mempalace__mempalace_search"}',
        ]);

        $items = collect($this->getJson('/api/analytics/costs/behaviors?range=7d')->json('data.mcp_servers.items'))
            ->keyBy('label');

        $this->assertSame(2, $items['pinecone']['calls']);
        $this->assertSame(1, $items['mempalace']['calls']);
        $this->assertSame(3, $this->getJson('/api/analytics/costs/behaviors?range=7d')->json('data.mcp_servers.total_calls'));
    }

    // -------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------

    public function test_invalid_range_is_rejected(): void
    {
        $this->getJson('/api/analytics/costs/behaviors?range=30d')->assertStatus(422);
    }

    public function test_empty_range_returns_zeroed_behaviors_not_an_error(): void
    {
        $data = $this->getJson('/api/analytics/costs/behaviors?range=24h')->assertOk()->json('data');

        $this->assertSame(0, $data['cache_miss']['total_tokens']);
        $this->assertSame([], $data['skills']['items']);
    }
}
