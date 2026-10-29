<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuotaWindowTest extends TestCase
{
    use RefreshDatabase;

    private int $ccProjectId;

    private string $ccSessionId = 'sess-quota';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ccProjectId = DB::table('sessions.projects')->insertGetId([
            'encoded_path' => '-home-dev-projects-demo',
            'decoded_path' => '/home/dev/projects/demo',
            'display_name' => 'demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sessions.sessions')->insert([
            'session_id' => $this->ccSessionId,
            'project_id' => $this->ccProjectId,
            'file_path' => '/tmp/sess-quota.jsonl',
            'file_size' => 1,
            'file_mtime' => now()->getTimestampMs(),
            'first_prompt_timestamp' => now()->subHours(2),
            'last_activity_timestamp' => now(),
            'message_count' => 100,
            'indexed_at' => now(),
        ]);
    }

    private function seedMessage(Carbon $at, int $output = 1000, int $input = 100): void
    {
        DB::table('sessions.messages')->insert([
            'session_id' => $this->ccSessionId,
            'message_uuid' => 'msg-' . (++$this->seq) . '-' . uniqid(),
            'message_type' => 'assistant',
            'timestamp' => $at,
            'content_text' => 'x',
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => 0,
            'sequence_number' => $this->seq,
        ]);
    }

    public function test_active_window_computed_from_recent_messages(): void
    {
        // Activity 90 minutes ago opens a block at that hour's floor; more 10 min ago.
        $this->seedMessage(now()->subMinutes(90), 10_000);
        $this->seedMessage(now()->subMinutes(10), 5_000);

        $data = $this->getJson('/api/analytics/quota-window')->assertOk()->json('data');

        $this->assertNotNull($data);
        $this->assertSame(15_200, $data['tokens_used']); // 15000 out + 200 in
        $this->assertSame('local_estimate', $data['provenance']);
        $this->assertGreaterThan(0, $data['elapsed_pct']);
        $this->assertLessThanOrEqual(100, $data['elapsed_pct']);
        // Window start is the hour floor of the first message of the block.
        $expectedStart = now()->subMinutes(90)->startOfHour()->toIso8601String();
        $this->assertSame($expectedStart, $data['window_started_at']);
    }

    public function test_no_open_window_returns_null_data(): void
    {
        // Last activity 6h ago: its block (5h long) is closed.
        $this->seedMessage(now()->subHours(6), 10_000);

        $response = $this->getJson('/api/analytics/quota-window')->assertOk();

        $this->assertNull($response->json('data'));
    }

    public function test_ceiling_is_max_block_total_over_history(): void
    {
        // A heavy closed block ~20h ago (two messages inside one 5h block).
        $this->seedMessage(now()->subHours(20), 90_000);
        $this->seedMessage(now()->subHours(19), 90_000);
        // Current small block.
        $this->seedMessage(now()->subMinutes(10), 1_000);

        $data = $this->getJson('/api/analytics/quota-window')->assertOk()->json('data');

        $this->assertSame(180_200, $data['ceiling_tokens']); // 180k out + 200 in
        $this->assertSame(1, $data['pct_of_ceiling']); // 1100/180200 ≈ 0.6% → rounds to 1
    }

    public function test_current_block_exceeding_ceiling_reports_over_100_pct(): void
    {
        // A closed block ~20h ago totaling 10k tokens.
        $this->seedMessage(now()->subHours(20), 9_900, 100);
        // The current open block breaks the record: 15k tokens.
        $this->seedMessage(now()->subMinutes(10), 14_900, 100);

        $data = $this->getJson('/api/analytics/quota-window')->assertOk()->json('data');

        $this->assertSame(10_000, $data['ceiling_tokens']);
        $this->assertSame(15_000, $data['tokens_used']);
        $this->assertSame(150, $data['pct_of_ceiling']);
    }
}
