<?php

namespace Tests\Feature\Services;

use App\Services\SessionScannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scanner used to read only `<project>/*.jsonl`, so every subagent
 * transcript (`<project>/<session-uuid>/subagents/agent-*.jsonl`) went
 * unindexed — real token spend that no cost or quota figure could see.
 */
class SessionScannerSubagentTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/scanner-' . uniqid();
        mkdir($this->root . '/-home-dev-projects-demo/parent-uuid/subagents', 0777, true);

        putenv('CLAUDE_PROJECTS_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        putenv('CLAUDE_PROJECTS_DIR');
        exec('rm -rf ' . escapeshellarg($this->root));

        parent::tearDown();
    }

    private function line(string $sessionId, string $type, array $extra = []): string
    {
        return json_encode(array_merge([
            'type' => $type,
            'uuid' => uniqid('u', true),
            'parentUuid' => null,
            'sessionId' => $sessionId,
            'timestamp' => now()->toIso8601String(),
            'cwd' => '/home/dev/projects/demo',
            'gitBranch' => 'main',
            'version' => '2.1.0',
            'entrypoint' => 'cli',
        ], $extra)) . "\n";
    }

    private function seedTranscripts(): void
    {
        $projectDir = $this->root . '/-home-dev-projects-demo';

        file_put_contents($projectDir . '/parent-uuid.jsonl',
            $this->line('parent-uuid', 'user', ['message' => ['role' => 'user', 'content' => 'orchestrate this']])
            . $this->line('parent-uuid', 'assistant', ['message' => [
                'role' => 'assistant',
                'model' => 'claude-opus-5',
                'content' => 'on it',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ]])
        );

        // Note the sessionId: a subagent transcript carries the PARENT's id.
        file_put_contents($projectDir . '/parent-uuid/subagents/agent-abc123.jsonl',
            $this->line('parent-uuid', 'user', [
                'isSidechain' => true,
                'agentId' => 'agent-abc123',
                'message' => ['role' => 'user', 'content' => 'do the delegated work'],
            ])
            . $this->line('parent-uuid', 'assistant', [
                'isSidechain' => true,
                'agentId' => 'agent-abc123',
                'message' => [
                    'role' => 'assistant',
                    'model' => 'claude-haiku-4-5-20251001',
                    'content' => 'done',
                    'usage' => ['input_tokens' => 500, 'output_tokens' => 50],
                ],
            ])
        );
    }

    public function test_subagent_transcripts_are_indexed_under_their_agent_id(): void
    {
        $this->seedTranscripts();

        (new SessionScannerService)->scanAll();

        $rows = DB::table('sessions.sessions')->orderBy('kind')->get()->keyBy('kind');

        $this->assertCount(2, $rows, 'the subagent file must produce its own row');
        $this->assertSame('parent-uuid', $rows['main']->session_id);
        $this->assertSame('agent-abc123', $rows['subagent']->session_id);
        $this->assertSame('parent-uuid', $rows['subagent']->parent_session_id);
    }

    /** The parent's own row must not absorb the child's tokens — that would double count. */
    public function test_parent_and_subagent_tokens_stay_on_their_own_rows(): void
    {
        $this->seedTranscripts();

        (new SessionScannerService)->scanAll();

        $rows = DB::table('sessions.sessions')->get()->keyBy('kind');

        $this->assertSame(100, (int) $rows['main']->total_input_tokens);
        $this->assertSame(500, (int) $rows['subagent']->total_input_tokens);
    }

    /**
     * `has_subagents` is derived from the indexed children: the parent
     * transcript carries no agentId, and the child carries its own on every
     * record — which would otherwise flag the leaf, not the parent.
     */
    public function test_has_subagents_is_set_on_the_parent_only(): void
    {
        $this->seedTranscripts();

        (new SessionScannerService)->scanAll();

        $rows = DB::table('sessions.sessions')->get()->keyBy('kind');

        $this->assertTrue((bool) $rows['main']->has_subagents);
        $this->assertFalse((bool) $rows['subagent']->has_subagents);
    }

    /** The SessionStart hook's mapping is stamped onto rows as they are indexed. */
    public function test_account_is_stamped_from_the_hook_mapping(): void
    {
        $this->seedTranscripts();

        DB::table('sessions.session_accounts')->insert([
            'session_id' => 'parent-uuid',
            'account' => 'cl2',
            'first_seen_at' => now(),
        ]);

        (new SessionScannerService)->scanAll();

        $rows = DB::table('sessions.sessions')->get()->keyBy('kind');

        $this->assertSame('cl2', $rows['main']->account);
        $this->assertSame('cl2', $rows['subagent']->account, 'a subagent inherits its parent account');
    }

    public function test_sessions_with_no_mapping_stay_unattributed(): void
    {
        $this->seedTranscripts();

        (new SessionScannerService)->scanAll();

        $this->assertNull(DB::table('sessions.sessions')->where('kind', 'main')->value('account'));
    }

    // -------------------------------------------------------------
    // Part A1 — subagent_type/spawn_depth (sidecar) + entrypoint
    // -------------------------------------------------------------

    public function test_subagent_type_and_spawn_depth_are_read_from_the_sidecar(): void
    {
        $this->seedTranscripts();
        $sidecarPath = $this->root . '/-home-dev-projects-demo/parent-uuid/subagents/agent-abc123.meta.json';
        file_put_contents($sidecarPath, json_encode([
            'agentType' => 'code-reviewer',
            'description' => 'Review the diff',
            'toolUseId' => 'toolu_01abc',
            'spawnDepth' => 1,
        ]));

        (new SessionScannerService)->scanAll();

        $subagent = DB::table('sessions.sessions')->where('kind', 'subagent')->first();
        $this->assertSame('code-reviewer', $subagent->subagent_type);
        $this->assertSame(1, (int) $subagent->spawn_depth);
    }

    public function test_missing_sidecar_leaves_subagent_type_null_not_failing(): void
    {
        $this->seedTranscripts();
        // No sidecar written at all.

        (new SessionScannerService)->scanAll();

        $subagent = DB::table('sessions.sessions')->where('kind', 'subagent')->first();
        $this->assertNull($subagent->subagent_type);
        $this->assertNull($subagent->spawn_depth);
    }

    public function test_entrypoint_is_captured_from_the_transcript(): void
    {
        $projectDir = $this->root . '/-home-dev-projects-demo';

        file_put_contents($projectDir . '/headless-uuid.jsonl',
            $this->line('headless-uuid', 'user', [
                'entrypoint' => 'sdk-cli',
                'message' => ['role' => 'user', 'content' => 'do the recap'],
            ])
            . $this->line('headless-uuid', 'assistant', [
                'entrypoint' => 'sdk-cli',
                'message' => [
                    'role' => 'assistant',
                    'model' => 'claude-haiku-4-5-20251001',
                    'content' => 'done',
                    'usage' => ['input_tokens' => 50, 'output_tokens' => 5],
                ],
            ])
        );

        (new SessionScannerService)->scanAll();

        $this->assertSame('sdk-cli', DB::table('sessions.sessions')->where('session_id', 'headless-uuid')->value('entrypoint'));
    }

    public function test_interactive_session_captures_cli_entrypoint(): void
    {
        $this->seedTranscripts();

        (new SessionScannerService)->scanAll();

        $this->assertSame('cli', DB::table('sessions.sessions')->where('kind', 'main')->value('entrypoint'));
    }

    // -------------------------------------------------------------
    // Part A2 — skill_name capture
    // -------------------------------------------------------------

    public function test_skill_name_is_captured_from_a_skill_tool_use_block(): void
    {
        $projectDir = $this->root . '/-home-dev-projects-demo';

        file_put_contents($projectDir . '/skill-uuid.jsonl',
            $this->line('skill-uuid', 'user', ['message' => ['role' => 'user', 'content' => 'run the chain']])
            . $this->line('skill-uuid', 'assistant', ['message' => [
                'role' => 'assistant',
                'model' => 'claude-opus-5',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Skill', 'input' => ['skill' => 'superpowers:finishing-a-development-branch']],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ]])
        );

        (new SessionScannerService)->scanAll();

        $skillName = DB::table('sessions.messages')
            ->where('session_id', 'skill-uuid')
            ->where('message_type', 'assistant')
            ->value('skill_name');

        $this->assertSame('superpowers:finishing-a-development-branch', $skillName);
    }

    public function test_messages_without_a_skill_tool_use_stay_null(): void
    {
        $this->seedTranscripts();

        (new SessionScannerService)->scanAll();

        $skillNames = DB::table('sessions.messages')->pluck('skill_name')->all();
        $this->assertSame([null, null, null, null], $skillNames);
    }

    // -------------------------------------------------------------
    // Backfill — already-indexed sessions never get re-parsed by a scan
    // -------------------------------------------------------------

    public function test_backfill_attribution_fills_in_already_indexed_sessions(): void
    {
        $this->seedTranscripts();
        (new SessionScannerService)->scanAll();

        // Simulate the pre-A1 state: sidecar + entrypoint on disk, but the row
        // predates the columns being read — exactly what a real backfill fixes.
        DB::table('sessions.sessions')->update(['subagent_type' => null, 'spawn_depth' => null, 'entrypoint' => null]);

        $sidecarPath = $this->root . '/-home-dev-projects-demo/parent-uuid/subagents/agent-abc123.meta.json';
        file_put_contents($sidecarPath, json_encode(['agentType' => 'general-purpose', 'spawnDepth' => 2]));

        $result = (new SessionScannerService)->backfillAttribution();

        $this->assertSame(1, $result['subagent_type_updated']);
        $this->assertSame(2, $result['entrypoint_updated']); // main + subagent transcripts both carry it

        $subagent = DB::table('sessions.sessions')->where('kind', 'subagent')->first();
        $this->assertSame('general-purpose', $subagent->subagent_type);
        $this->assertSame(2, (int) $subagent->spawn_depth);
        $this->assertSame('cli', $subagent->entrypoint);

        $main = DB::table('sessions.sessions')->where('kind', 'main')->first();
        $this->assertSame('cli', $main->entrypoint);
    }
}
