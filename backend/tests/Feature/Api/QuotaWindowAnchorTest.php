<?php

namespace Tests\Feature\Api;

use App\Enums\QuotaFetchStatus;
use App\Models\QuotaSnapshot;
use App\Services\QuotaWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The open window's boundary comes from Anthropic when we have it.
 *
 * The hour-floor convention is a guess and a measurably wrong one: on
 * 2026-08-19 it put the window end ten minutes before the real reset, skewing
 * every elapsed / burn / projection figure derived from it.
 */
class QuotaWindowAnchorTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function service(): QuotaWindowService
    {
        return app(QuotaWindowService::class);
    }

    /** A reading that says the open window resets at $resetsAt. */
    private function reading(Carbon $resetsAt, QuotaFetchStatus $status = QuotaFetchStatus::OK): void
    {
        QuotaSnapshot::create([
            'fetched_at' => now(),
            'status' => $status,
            'account' => 'cl',
            'five_hour_percent' => 50,
            'five_hour_resets_at' => $resetsAt,
        ]);
    }

    private function message(Carbon $at, int $tokens): void
    {
        $ccProject = DB::table('sessions.projects')->insertGetId([
            'encoded_path' => 'p' . $this->seq, 'decoded_path' => '/p' . $this->seq,
            'display_name' => 'p', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sessionId = 's' . $this->seq++;

        DB::table('sessions.sessions')->insert([
            'session_id' => $sessionId, 'project_id' => $ccProject,
            'file_path' => '/tmp/' . $sessionId, 'file_size' => 1, 'file_mtime' => 1,
            'last_activity_timestamp' => $at, 'indexed_at' => now(),
        ]);
        DB::table('sessions.messages')->insert([
            'session_id' => $sessionId, 'message_uuid' => 'm' . $this->seq,
            'message_type' => 'assistant', 'timestamp' => $at, 'sequence_number' => 1,
            'model' => 'claude-opus-5', 'input_tokens' => $tokens,
            'output_tokens' => 0, 'cache_creation_tokens' => 0, 'cache_read_tokens' => 0,
        ]);
    }

    public function test_it_takes_the_window_start_from_the_real_reset_time(): void
    {
        Carbon::setTestNow('2026-08-19 06:00:00');
        // Real reset at 08:09:59 → the window really opened at 03:09:59, not at
        // the 03:00 hour-floor the local fold would guess.
        $this->reading(Carbon::parse('2026-08-19 08:09:59'));
        $this->message(Carbon::parse('2026-08-19 04:00:00'), 100);

        $block = $this->service()->currentBlock();

        $this->assertSame('2026-08-19 03:09:59', $block['started_at']->format('Y-m-d H:i:s'));
        $this->assertSame('server_anchor', $block['source']);
    }

    public function test_anchored_tokens_exclude_spend_from_before_the_window_opened(): void
    {
        Carbon::setTestNow('2026-08-19 06:00:00');
        $this->reading(Carbon::parse('2026-08-19 08:09:59'));

        // Between the hour-floor guess (03:00) and the true start (03:09:59).
        // Reusing the guessed block's total would count this.
        $this->message(Carbon::parse('2026-08-19 03:05:00'), 999);
        $this->message(Carbon::parse('2026-08-19 04:00:00'), 100);

        $this->assertSame(100, $this->service()->currentBlock()['tokens']);
    }

    public function test_a_reset_time_already_passed_does_not_anchor_anything(): void
    {
        Carbon::setTestNow('2026-08-19 09:00:00');
        // That window closed at 08:09:59; it says nothing about the one open now.
        $this->reading(Carbon::parse('2026-08-19 08:09:59'));
        $this->message(Carbon::parse('2026-08-19 08:30:00'), 100);

        $block = $this->service()->currentBlock();

        $this->assertSame('hour_floor', $block['source']);
        $this->assertSame('2026-08-19 08:00:00', $block['started_at']->format('Y-m-d H:i:s'));
    }

    public function test_a_failed_reading_never_anchors_the_window(): void
    {
        Carbon::setTestNow('2026-08-19 06:00:00');
        // A failed fetch carries no reset time worth trusting.
        $this->reading(Carbon::parse('2026-08-19 08:09:59'), QuotaFetchStatus::UNREACHABLE);
        $this->message(Carbon::parse('2026-08-19 04:00:00'), 100);

        $this->assertSame('hour_floor', $this->service()->currentBlock()['source']);
    }

    public function test_the_window_strip_reports_the_anchored_boundary(): void
    {
        Carbon::setTestNow('2026-08-19 06:00:00');
        $this->reading(Carbon::parse('2026-08-19 08:09:59'));
        $this->message(Carbon::parse('2026-08-19 04:00:00'), 100);

        $data = $this->getJson('/api/analytics/costs/window')->assertOk()->json('data');

        $this->assertSame('server_anchor', $data['boundary_source']);
        // Ends exactly when Anthropic says it resets — the whole point.
        $this->assertSame(
            '2026-08-19T08:09:59+00:00',
            Carbon::parse($data['ends_at'])->utc()->toIso8601String(),
        );
    }

    public function test_it_still_works_with_no_readings_at_all(): void
    {
        Carbon::setTestNow('2026-08-19 06:00:00');
        $this->message(Carbon::parse('2026-08-19 04:00:00'), 100);

        $block = $this->service()->currentBlock();

        $this->assertSame('hour_floor', $block['source']);
        $this->assertSame(100, $block['tokens']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
